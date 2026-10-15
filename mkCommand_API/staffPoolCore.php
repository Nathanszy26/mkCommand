<?php
/**
 * STAFF POOL CORE — the one definition of "who is on the staff list".
 *
 * Nothing in this file prints, redirects or reads $_GET. It defines the pool,
 * the four flags and the counting rules, and both of its callers include it:
 *
 *   staffHeadcountReport.php   the report page and its tick boxes
 *   staffPoolAPI.php           the JSON endpoint other applications read
 *
 * That is the whole point. A change to the pool, to a flag or to the way a
 * head count is counted happens HERE, once, and the report and every
 * application reading the API move together. Neither caller is allowed its own
 * copy of a rule in this file — if a rule has to differ per caller, it gets a
 * parameter here rather than a second implementation there.
 *
 * MUST be hosted on globportal.com, same folder as jobspecReport.php —
 * staff_portal2 (users), evaluation (monthly_assign_gw) and mkmembers
 * (member_registrations) are all local there under prog4. The
 * mkcommand <-> globportal 3306 path is firewalled.
 *
 * THE POOL (the row set — see StaffPoolRepository)
 *   staff: staff_portal2.users, status = '1' AND deleted = '0'
 *   GW:    evaluation.monthly_assign_gw, monthly_assign_gw_status = '1',
 *          comp_id 1 and 4 only — GW exist nowhere else. ONE ROW PER CODE:
 *          that table stores a row per reporting line, so a GW under two
 *          superiors appears twice and would be counted twice.
 *   test_user = 1 is dropped from both. A test account is not a head count.
 *   users.is_gw = 1 is dropped from the staff side: those 31 rows are GW
 *   login accounts, and the GW half already counts that population from
 *   monthly_assign_gw. Leaving them in counted them twice.
 *
 * TRILLIUM (comp_id 16)
 *   Trillium Group has no users rows of its own. Its people are stored under
 *   Globinaco (comp_id 1) and marked users.is_trillium = 1. The pool lifts
 *   them out of Globinaco's figures and reports them as company 16, so the
 *   two never double-count, and keys their overrides under 16 as well.
 *   Anyone who may see or edit Globinaco may see or edit Trillium — see
 *   withTrillium() — and nobody is granted Trillium on its own.
 *
 * TWO POPULATIONS, reported separately, exactly as jobspecReport.php does:
 * a GW's code is a monthly_assign_gw_code, a different namespace from
 * users.person, and the two CAN collide. They never share a table or a key.
 *
 * THE FLAGS — READ THIS BEFORE CHANGING ANYTHING
 *   Nothing here writes to users or monthly_assign_gw. Those stay the source
 *   of every value. A tick on the report writes to
 *   staff_portal2.staff_flag_override instead (see sql/staff_flag_override.sql),
 *   and the value both the report and the API report is:
 *
 *       effective = override row, if one exists
 *                   otherwise the source column
 *
 *   So an override, once set, PINS that person's flag: a later edit to
 *   users.kpi_excluded will not move it. That is the point — the report is the
 *   authority once someone has touched a row — and it is why effective()
 *   also answers whether the value disagrees with its source.
 *
 * CONTACT DETAILS
 *   Staff carry users.email and users.mobile_no. A GW record has a mobile but
 *   no email column, so gwEmails() matches the mobile against myMK and the
 *   GW login accounts — see that method. The report does not show contacts and
 *   does not call it; the API does.
 *
 * PHP 7.2 compatible.
 */

/* ---------- DB ------------------------------------------------------------ */

class Db
{
    private static $pdo = null;

    /** One shared PDO. Both schemas are on this host. */
    public static function get()
    {
        if (self::$pdo === null) {
            $pdo = new PDO(
                'mysql:host=localhost;charset=utf8mb4',
                'prog4',
                'prog42023',
                array(PDO::ATTR_TIMEOUT => 5)
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }
}

/* ---------- access -------------------------------------------------------- */

/** Query: is this user an active KPI super admin?
 *  kpi_super_admins.user_id is staff_portal2.users.id — the same id
 *  UnifiedAuth puts in the session. status is TINYINT, 1 = active. */
function isSuperAdmin(PDO $pdo, $userId)
{
    if ($userId === null || $userId === '') {
        return false;
    }
    $stmt = $pdo->prepare(
        "SELECT 1 FROM staff_portal2.kpi_super_admins
          WHERE user_id = :uid AND status = 1 LIMIT 1"
    );
    $stmt->bindValue(':uid', (int)$userId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

/** Query: the companies this user administers, as ints.
 *  kpi_company_admins carries its OWN comp_id, which is not always the admin's
 *  own company — so this is read from that column and never from the session.
 *  A user may hold several rows. */
function companyAdminComps(PDO $pdo, $userId)
{
    if ($userId === null || $userId === '') {
        return array();
    }
    $stmt = $pdo->prepare(
        "SELECT DISTINCT comp_id FROM staff_portal2.kpi_company_admins
          WHERE user_id = :uid AND status = 1"
    );
    $stmt->bindValue(':uid', (int)$userId, PDO::PARAM_INT);
    $stmt->execute();
    $out = array();
    foreach ($stmt->fetchAll() as $r) {
        $out[] = (int)$r['comp_id'];
    }
    return $out;
}

/** Query: the same list with Trillium added if Globinaco is on it.
 *
 *  Trillium's people ARE Globinaco's people under another banner — they are
 *  stored under comp_id 1 and only marked is_trillium — so the right to see
 *  or change Globinaco carries to Trillium. Nobody is ever granted Trillium
 *  on its own, and kpi_company_admins needs no row for comp 16. */
function withTrillium(array $comps)
{
    $home = StaffPoolRepository::TRILLIUM_HOME_COMP_ID;
    $t    = StaffPoolRepository::TRILLIUM_COMP_ID;
    if (in_array($home, $comps, true) && !in_array($t, $comps, true)) {
        $comps[] = $t;
    }
    return $comps;
}

/* ---------- the four flags ------------------------------------------------ */

/** The column set, in display order. `key` is what the override table stores,
 *  `base` is the row key the repositories put the source value under. */
function flagDefs()
{
    return array(
        'in_pool'    => array('label' => 'Total Staff',             'base' => 'base_in_pool'),
        'kpi'        => array('label' => 'KPI/Scorecard Exempted',  'base' => 'base_kpi'),
        'mk_command' => array('label' => 'MK Command Exempted',     'base' => 'base_mk_command'),
        'mk_academy' => array('label' => 'MK Academy Exempted',     'base' => 'base_mk_academy'),
    );
}

/** The key one override row is stored under in the in-memory map. Unit
 *  separator, because a department or a code could contain anything printable
 *  but never this. */
function overrideKey($type, $compId, $person, $flag)
{
    return $type . "\x1f" . (int)$compId . "\x1f" . $person . "\x1f" . $flag;
}

/* ---------- repository ---------------------------------------------------- */

class StaffPoolRepository
{
    /** Trillium Group is reported as its own company although it has no users
     *  rows of its own: its people are stored under Globinaco and marked with
     *  users.is_trillium = 1. So the two ids travel together everywhere — the
     *  rows come out of HOME, are relabelled as COMP, and are taken out of
     *  HOME's own figures so nobody is counted twice. */
    const TRILLIUM_COMP_ID = 16;
    const TRILLIUM_HOME_COMP_ID = 1;

    /** monthly_assign_gw has no position column — every row in it is a general
     *  worker by definition — so the two populations are given the same shape
     *  by stamping the title here rather than leaving a hole for each caller to
     *  fill in its own words. */
    const GW_POSITION = 'General Worker';

    /** GW companies. monthly_assign_gw holds nobody else, and saying so here
     *  keeps gw() and any caller that wants to know from guessing. */
    public static function gwComps()
    {
        return array(1, 4);
    }

    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private function bind($sql, array $args)
    {
        $stmt = $this->db->prepare($sql);
        foreach ($args as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt;
    }

    private function run($sql, array $args)
    {
        return $this->bind($sql, $args)->fetchAll();
    }

    /** Writes go here, not through run(): fetchAll() on an INSERT or a DELETE
     *  has no result set to read and is not worth depending on. */
    private function exec($sql, array $args)
    {
        return $this->bind($sql, $args)->rowCount();
    }

    /** Query: the companies this report covers — the weekly-reporting group
     *  (subsidiaries.weekly_report_status = 1), and only those of them that
     *  actually hold pool rows.
     *
     *  This list is the report's scope, not just the filter's options: it is
     *  also what "All companies" totals up, so the group figure is always the
     *  sum of what the dropdown offers. A company outside the group is still
     *  reachable by an explicit comp_id — someone whose own company has left
     *  the group keeps seeing their own head count — it is simply never
     *  offered in the list and never folded into the group total. */
    public function companies()
    {
        $sql = "SELECT u.comp_id,
                       COALESCE(NULLIF(TRIM(s.name), ''),
                                NULLIF(TRIM(s.code), ''),
                                CONCAT('Company ', u.comp_id)) AS display_name
                FROM staff_portal2.users u
                INNER JOIN staff_portal2.subsidiaries s ON s.id = u.comp_id
                WHERE u.status = '1' AND u.deleted = '0'
                  AND IFNULL(u.test_user, 0) <> 1
                  AND IFNULL(u.is_gw, 0) <> 1
                  AND IFNULL(u.is_trillium, 0) <> 1
                  AND s.weekly_report_status = 1
                GROUP BY u.comp_id, display_name
                ORDER BY display_name";
        $rows = $this->run($sql, array());

        /* Trillium cannot come out of that query — subsidiaries row 16 is
         * inactive and files no weekly report, and no users row carries
         * comp_id 16 — so it is added by hand, and only while it actually has
         * people. Its name still comes from subsidiaries, with the same
         * fallbacks, so it reads like every other entry. */
        $t = $this->run(
            "SELECT (SELECT COUNT(*)
                       FROM staff_portal2.users u
                      WHERE u.status = '1' AND u.deleted = '0'
                        AND IFNULL(u.test_user, 0) <> 1
                        AND IFNULL(u.is_gw, 0) <> 1
                        AND u.comp_id = :home
                        AND IFNULL(u.is_trillium, 0) = 1) AS n,
                    (SELECT COALESCE(NULLIF(TRIM(s.name), ''),
                                     NULLIF(TRIM(s.code), ''),
                                     CONCAT('Company ', :id2))
                       FROM staff_portal2.subsidiaries s
                      WHERE s.id = :id) AS display_name",
            array(
                ':home' => (int)self::TRILLIUM_HOME_COMP_ID,
                ':id'   => (int)self::TRILLIUM_COMP_ID,
                ':id2'  => (int)self::TRILLIUM_COMP_ID,
            )
        );

        if (!empty($t) && (int)$t[0]['n'] > 0) {
            $name = $t[0]['display_name'];
            $rows[] = array(
                'comp_id'      => self::TRILLIUM_COMP_ID,
                'display_name' => ($name === null || trim((string)$name) === '')
                    ? 'Company ' . self::TRILLIUM_COMP_ID
                    : $name,
            );
            usort($rows, function ($a, $b) {
                return strcasecmp($a['display_name'], $b['display_name']);
            });
        }
        return $rows;
    }

    /** Query: an IN list for the "all companies" case, or '' when there is
     *  nothing to limit. Ids come from companies() — they are already ints out
     *  of the database — so they inline safely and keep the two populations
     *  from each needing their own bound list. */
    private function scopeIn($column, array $comps = null)
    {
        if ($comps === null || empty($comps)) {
            return '';
        }
        $ids = array();
        foreach ($comps as $c) {
            $ids[] = (int)$c;
        }
        return " AND " . $column . " IN (" . implode(',', $ids) . ")";
    }

    /** The columns every staff row is built from. $compExpr is what the row
     *  reports as its company: normally u.comp_id, but a literal for Trillium,
     *  whose people are stored under Globinaco. */
    private function staffSelect($compExpr)
    {
        return "SELECT u.person,
                       {$compExpr} AS comp_id,
                       u.fullname,
                       u.department,
                       u.position,
                       u.email,
                       u.mobile_no,
                       (IFNULL(u.kpi_excluded, '0') = '1')   AS base_kpi,
                       (IFNULL(u.mk_command_excluded, 0) = 1) AS base_mk_command,
                       (IFNULL(u.mk_academy_excluded, 0) = 1) AS base_mk_academy
                FROM staff_portal2.users u
                WHERE u.status = '1' AND u.deleted = '0'
                  AND IFNULL(u.test_user, 0) <> 1
                  AND IFNULL(u.is_gw, 0) <> 1";
    }

    /** The leading term is what puts "No department" at the BOTTOM: it is 1
     *  for a blank or NULL department and 0 for a real one, so the named
     *  departments come first and the unassigned trail them. */
    private function staffOrder()
    {
        return " ORDER BY (TRIM(IFNULL(u.department, '')) = '') ASC,
                          u.department, u.fullname, u.person";
    }

    /** Query: ordinary staff — everyone except the Trillium block, which is
     *  reported under its own company and would otherwise appear twice. */
    private function plainStaff($compId, array $scopeComps = null)
    {
        $args = array();
        $sql  = $this->staffSelect('u.comp_id')
              . " AND IFNULL(u.is_trillium, 0) <> 1";
        if ($compId !== null) {
            $sql .= " AND u.comp_id = :comp";
            $args[':comp'] = (int)$compId;
        } else {
            $sql .= $this->scopeIn('u.comp_id', $scopeComps);
        }
        return $this->decorate($this->run($sql . $this->staffOrder(), $args), 'staff');
    }

    /** Query: the Trillium block, relabelled as its own company.
     *  The relabelling is not cosmetic — comp_id is part of an override's key,
     *  so these people are keyed under 16 and can never collide with a
     *  Globinaco row for the same person. */
    private function trilliumStaff()
    {
        $sql = $this->staffSelect((int)self::TRILLIUM_COMP_ID)
             . " AND u.comp_id = " . (int)self::TRILLIUM_HOME_COMP_ID
             . " AND IFNULL(u.is_trillium, 0) = 1"
             . $this->staffOrder();
        return $this->decorate($this->run($sql, array()), 'staff');
    }

    /** Query: the staff half of the pool.
     *
     *  No search or department predicate here on purpose. The whole company is
     *  loaded once, the summary tiles are counted off the whole of it, and the
     *  filters are applied in PHP for display — otherwise the tiles would move
     *  every time someone typed in the search box and stop being a head count.
     *  Worst case (All companies) is well under 1,000 rows. */
    public function staff($compId, array $scopeComps = null)
    {
        if ($compId !== null) {
            return (int)$compId === self::TRILLIUM_COMP_ID
                ? $this->trilliumStaff()
                : $this->plainStaff((int)$compId);
        }

        /* All companies: the plain rows, then the Trillium block appended as
         * its own company — the same shape the GW block has. */
        $rows = $this->plainStaff(null, $scopeComps);
        if ($scopeComps === null
            || in_array(self::TRILLIUM_COMP_ID, $scopeComps, true)) {
            $rows = array_merge($rows, $this->trilliumStaff());
        }
        return $rows;
    }

    /** Query: the GW half of the pool.
     *
     *  comp_id is VARCHAR on monthly_assign_gw, so it binds as a string — the
     *  same quirk jobspecReport.php works around with CAST. GW exist only at
     *  Globinaco (1) and Arus Sawit (4); the IN clause says so rather than
     *  leaving it to the data. */
    public function gw($compId, array $scopeComps = null)
    {
        if ($compId !== null && !in_array((int)$compId, self::gwComps(), true)) {
            return array();
        }
        $args = array();
        /* ONE ROW PER CODE. monthly_assign_gw holds a row per GW per REPORTING
         * LINE, so a worker assigned under two superiors is in there twice —
         * 17 of them at Globinaco today. The code is what identifies the
         * person, so the group is (comp_id, code) and the duplicates collapse.
         *
         * MAX() on the three flags means "exempted if any row says so", which
         * is the safe direction for an exemption. All 17 groups currently
         * agree on name, district and every flag — only boss_id differs — so
         * this changes the count, not anybody's values. */
        $sql = "SELECT g.monthly_assign_gw_code            AS person,
                       g.comp_id,
                       MAX(g.monthly_assign_gw_fullname)   AS fullname,
                       MAX(g.monthly_assign_gw_district)   AS department,
                       '" . self::GW_POSITION . "' AS position,
                       '' AS email,
                       MAX(g.monthly_assign_gw_mobile_no)  AS mobile_no,
                       MAX(IFNULL(g.kpi_scorecard_exclude, '0') = '1') AS base_kpi,
                       MAX(IFNULL(g.mk_command_excluded, 0) = 1)       AS base_mk_command,
                       MAX(IFNULL(g.mk_academy_excluded, 0) = 1)       AS base_mk_academy
                FROM evaluation.monthly_assign_gw g
                WHERE g.monthly_assign_gw_status = '1'
                  AND IFNULL(g.test_user, 0) <> 1
                  AND g.comp_id IN ('1', '4')";
        if ($compId !== null) {
            $sql .= " AND g.comp_id = :comp";
            $args[':comp'] = (string)(int)$compId;
        } else {
            /* comp_id is VARCHAR here, but every id is numeric and MySQL
             * compares the two sides numerically, so the inlined int list is
             * still the right filter. */
            $sql .= $this->scopeIn('g.comp_id', $scopeComps);
        }
        /* The aggregates are REPEATED in ORDER BY rather than referred to by
         * their aliases: MySQL 5.6 rejects an aggregate alias used inside an
         * expression there ("Reference 'department' not supported (reference
         * to group function)"), which is what a plain ORDER BY department
         * would hide until the TRIM wrapper went round it. */
        $sql .= " GROUP BY g.comp_id, g.monthly_assign_gw_code
                  ORDER BY (TRIM(IFNULL(MAX(g.monthly_assign_gw_district), '')) = '') ASC,
                           MAX(g.monthly_assign_gw_district),
                           MAX(g.monthly_assign_gw_fullname),
                           g.monthly_assign_gw_code";
        return $this->decorate($this->run($sql, $args), 'gw');
    }

    /** Query: an address for each mobile given, as mobile => email.
     *
     *  monthly_assign_gw has no email column, so a GW's mobile is the only
     *  thing their record and an account have in common. Two passes, the same
     *  two assigngw.php matches its "myMK Found" badge with, in that order:
     *
     *    1. mkmembers.member_registrations.phone_no, deleted = 0 — the myMK
     *       registration.
     *    2. whatever pass 1 did not match, against staff_portal2.users where
     *       is_gw = '1' — the GW login accounts. The pool itself drops those
     *       rows (is_gw <> 1) because the GW half already counts that
     *       population, but the account still carries an address for the same
     *       person.
     *
     *  A mobile that matches MORE THAN ONE live row is skipped, not guessed:
     *  assigngw.php shows that case as an error, and handing out one of two
     *  people's addresses is worse than handing out none.
     *
     *  Formats already match on both sides, so nothing is normalised — that is
     *  assigngw.php's finding, and this follows it rather than inventing a
     *  second rule that would disagree with the badges on that page.
     *
     *  Looked up in PHP and not joined to the pool: monthly_assign_gw is
     *  latin1 while both account tables are utf8, which is an illegal mix of
     *  collations on 5.6 — the same reason overrides() merges in PHP.
     *
     *  Non-fatal on purpose: a failure here leaves the GW without an address
     *  and the head count still right, exactly as assigngw.php drops its
     *  badges rather than its page. */
    public function gwEmails(array $mobiles)
    {
        $want = array();
        foreach ($mobiles as $m) {
            $m = trim((string)$m);
            if ($m !== '') { $want[$m] = true; }
        }
        if (empty($want)) {
            return array();
        }

        $found = array();
        try {
            /* Chunked, and the chunks are bound: these come from a VARCHAR
             * column, and a company's worth of GW is a few hundred of them. */
            $pending = array_keys($want);
            $seen    = array();

            foreach (array_chunk($pending, 400) as $chunk) {
                foreach ($this->emailsByPhone(
                    "SELECT m.phone_no AS k, COUNT(*) AS cnt,
                            MAX(TRIM(IFNULL(m.email, ''))) AS email
                       FROM mkmembers.member_registrations m
                      WHERE m.deleted = 0 AND m.phone_no IN (%s)
                      GROUP BY m.phone_no",
                    $chunk
                ) as $k => $row) {
                    $seen[$k] = true;                     // matched, ambiguous or not
                    if ($row['cnt'] === 1 && $row['email'] !== '') {
                        $found[$k] = $row['email'];
                    }
                }
            }

            $leftover = array();
            foreach ($pending as $m) {
                if (!isset($seen[$m])) { $leftover[] = $m; }
            }
            foreach (array_chunk($leftover, 400) as $chunk) {
                foreach ($this->emailsByPhone(
                    "SELECT u.mobile_no AS k, COUNT(*) AS cnt,
                            MAX(TRIM(IFNULL(u.email, ''))) AS email
                       FROM staff_portal2.users u
                      WHERE u.status = '1' AND u.is_gw = '1'
                        AND u.mobile_no IN (%s)
                      GROUP BY u.mobile_no",
                    $chunk
                ) as $k => $row) {
                    if ($row['cnt'] === 1 && $row['email'] !== '') {
                        $found[$k] = $row['email'];
                    }
                }
            }
        } catch (Exception $e) {
            error_log('staffPoolCore gwEmails: ' . $e->getMessage());
        }
        return $found;
    }

    /** Query: one chunk of the lookup above. %s in $sqlTemplate is where the
     *  placeholders go, so the two passes differ only in their table. */
    private function emailsByPhone($sqlTemplate, array $chunk)
    {
        $names = array();
        $args  = array();
        foreach ($chunk as $i => $m) {
            $names[]            = ':m' . $i;
            $args[':m' . $i]    = (string)$m;
        }
        $sql = sprintf($sqlTemplate, implode(',', $names));

        $out = array();
        foreach ($this->run($sql, $args) as $r) {
            $out[(string)$r['k']] = array(
                'cnt'   => (int)$r['cnt'],
                'email' => (string)$r['email'],
            );
        }
        return $out;
    }

    /** Command: fill in the email of every GW row in $rows, in place of the
     *  empty string gw() selects. Staff rows already carry users.email and are
     *  left exactly as they are.
     *
     *  One batched lookup for the whole set, never one per row — the API hands
     *  it a company at a time, which is up to ~700 mobiles. */
    public function withGwEmails(array $rows)
    {
        $mobiles = array();
        foreach ($rows as $r) {
            if ($r['person_type'] === 'gw' && trim((string)$r['mobile_no']) !== '') {
                $mobiles[] = trim((string)$r['mobile_no']);
            }
        }
        if (empty($mobiles)) {
            return $rows;
        }

        $byMobile = $this->gwEmails($mobiles);
        foreach ($rows as &$r) {
            if ($r['person_type'] !== 'gw') { continue; }
            $m = trim((string)$r['mobile_no']);
            if ($m !== '' && isset($byMobile[$m])) {
                $r['email'] = $byMobile[$m];
            }
        }
        unset($r);
        return $rows;
    }

    /** Everything in the pool is in the pool — base_in_pool is 1 by
     *  construction, and only an override can take someone out of it. Stamping
     *  it here keeps the four flags uniform from this point on. */
    private function decorate(array $rows, $type)
    {
        foreach ($rows as &$r) {
            $r['person_type']     = $type;
            $r['comp_id']         = (int)$r['comp_id'];
            /* Both columns are nullable on users, and gw() selects the email
             * as a placeholder — so they are settled here and every row from
             * this point on has all of them, as a string. */
            $r['email']           = trim((string)$r['email']);
            $r['mobile_no']       = trim((string)$r['mobile_no']);
            $r['position']        = trim((string)$r['position']);
            $r['base_in_pool']    = 1;
            $r['base_kpi']        = (int)$r['base_kpi'];
            $r['base_mk_command'] = (int)$r['base_mk_command'];
            $r['base_mk_academy'] = (int)$r['base_mk_academy'];
        }
        unset($r);
        return $rows;
    }

    /** Query: every override in scope, as a flat map.
     *  Merged in PHP rather than joined: users is utf8, monthly_assign_gw is
     *  latin1, and joining a utf8 override table to both would hit an illegal
     *  mix of collations on 5.6. The map is tens of rows per company. */
    public function overrides($compId)
    {
        $args = array();
        $sql = "SELECT person_type, comp_id, person, flag, value,
                       updated_by, updated_date
                FROM staff_portal2.staff_flag_override";
        if ($compId !== null) {
            $sql .= " WHERE comp_id = :comp";
            $args[':comp'] = (int)$compId;
        }
        $map = array();
        foreach ($this->run($sql, $args) as $r) {
            $map[overrideKey($r['person_type'], $r['comp_id'], $r['person'], $r['flag'])] = array(
                'value'        => (int)$r['value'],
                'updated_by'   => $r['updated_by'],
                'updated_date' => $r['updated_date'],
            );
        }
        return $map;
    }

    /** Query: the change log, newest first. */
    public function log($compId, $limit = 200)
    {
        $args = array();
        $sql = "SELECT person_type, comp_id, person, person_name, flag,
                       old_value, new_value, changed_by, changed_date
                FROM staff_portal2.staff_flag_override_log";
        if ($compId !== null) {
            $sql .= " WHERE comp_id = :comp";
            $args[':comp'] = (int)$compId;
        }
        $sql .= " ORDER BY changed_date DESC, id DESC LIMIT " . (int)$limit;
        return $this->run($sql, $args);
    }

    /** Command: apply one tick.
     *
     *  $value null clears the override, putting the cell back under its source
     *  column — that is what Undo does when the log says there was no override
     *  before. Anything else upserts.
     *
     *  State and log are written together: the log row is the record of who
     *  did it, and a state change without one would be unattributable. */
    public function applyChange($type, $compId, $person, $personName, $flag, $value,
                                $byName, $byId)
    {
        $key = array(
            ':t' => $type,
            ':c' => (int)$compId,
            ':p' => (string)$person,
            ':f' => $flag,
        );

        $stmt = $this->db->prepare(
            "SELECT value FROM staff_portal2.staff_flag_override
              WHERE person_type = :t AND comp_id = :c AND person = :p AND flag = :f"
        );
        foreach ($key as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $found = $stmt->fetchColumn();
        $old   = ($found === false) ? null : (int)$found;

        if ($value === null && $old === null) {
            return $old;                       // nothing to clear, nothing to log
        }
        if ($value !== null && $old === (int)$value) {
            return $old;                       // already there; no phantom log row
        }

        $this->db->beginTransaction();
        try {
            if ($value === null) {
                $this->exec(
                    "DELETE FROM staff_portal2.staff_flag_override
                      WHERE person_type = :t AND comp_id = :c AND person = :p AND flag = :f",
                    $key
                );
            } else {
                $this->exec(
                    "INSERT INTO staff_portal2.staff_flag_override
                            (person_type, comp_id, person, flag, value, updated_by, updated_by_id)
                     VALUES (:t, :c, :p, :f, :v, :by, :byid)
                     ON DUPLICATE KEY UPDATE value = VALUES(value),
                                             updated_by = VALUES(updated_by),
                                             updated_by_id = VALUES(updated_by_id),
                                             updated_date = CURRENT_TIMESTAMP",
                    $key + array(
                        ':v'    => (int)$value,
                        ':by'   => (string)$byName,
                        ':byid' => (int)$byId,
                    )
                );
            }

            /* new_value on a clear records what the cell fell back to, so the
             * log reads as a value and not as a hole. is_clear tells the two
             * apart for the reader. */
            $this->exec(
                "INSERT INTO staff_portal2.staff_flag_override_log
                        (person_type, comp_id, person, person_name, flag,
                         old_value, new_value, changed_by, changed_by_id)
                 VALUES (:t, :c, :p, :pn, :f, :ov, :nv, :by, :byid)",
                $key + array(
                    ':pn'   => (string)$personName,
                    ':ov'   => $old,
                    ':nv'   => ($value === null ? -1 : (int)$value),
                    ':by'   => (string)$byName,
                    ':byid' => (int)$byId,
                )
            );
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $old;
    }
}

/** Query: a name in Title Case, however it was typed.
 *
 *  The source records are inconsistent — every one of the 959 GW names is
 *  upper case, staff names run from "BOON SHU HARN" to "chen kin yee" — and
 *  this only changes how they READ. Nothing is written back to users or
 *  monthly_assign_gw.
 *
 *  Two kinds of word are left exactly as typed, because title case would
 *  damage them:
 *    - anything bracketed, e.g. "(QMR)", "(ISO22000-DCC)" — a qualifier, not
 *      a name, and "(Qmr)" would be wrong
 *    - anything containing a digit
 *
 *  Everything else is lower-cased and then capitalised at the start of each
 *  run of letters, so the separators inside a word carry too:
 *    O'BRIEN -> O'Brien,  AL-NAJIR -> Al-Najir,  AZMAN B. FAIZAL -> Azman B. Faizal
 *
 *  Only ASCII letter runs are touched, which leaves any UTF-8 byte sequence
 *  untouched without needing mbstring. Whitespace is preserved as typed. */
function normaliseName($name)
{
    /* Nine GW records carry a non-breaking space (U+00A0) where a plain one
     * was meant — a paste artefact, e.g. monthly_assign_gw_code C2:544. It
     * is a space, so it is treated as one here: left alone it neither splits
     * two words nor trims, and those names render joined up or indented. */
    $name = str_replace("\xc2\xa0", ' ', (string)$name);

    $parts = preg_split('/(\s+)/', $name, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $i => $part) {
        if ($part === '' || preg_match('/^\s+$/', $part)) {
            continue;
        }
        if (preg_match('/[0-9()\[\]]/', $part)) {
            continue;
        }
        $parts[$i] = preg_replace_callback(
            '/[A-Za-z]+/',
            function ($m) { return ucfirst(strtolower($m[0])); },
            $part
        );
    }
    return implode('', $parts);
}

/** Query: the name to print, with the code as the fallback. The code is a
 *  login or a GW code, not a name, so it is printed as it is stored. */
function displayName($name, $code)
{
    /* Normalise first, then trim: that is what lets the trim reach a leading
     * non-breaking space, which normaliseName has by then turned into a
     * plain one. */
    $name = trim(normaliseName($name));
    return $name !== '' ? $name : (string)$code;
}

/** Query: a blank department is still a group — it just needs a name. */
function deptLabel($dept)
{
    $dept = trim((string)$dept);
    return $dept === '' ? 'No department' : $dept;
}

function fmtDateTime($v)
{
    if ($v === null || $v === '' || $v === '0000-00-00 00:00:00') { return '—'; }
    $t = strtotime($v);
    return $t ? date('j M Y, H:i', $t) : (string)$v;
}

/** Query: the head count over a set of rows — what the tiles show.
 *  Someone taken out of the pool counts nowhere: an exemption only means
 *  anything for a person who is in the pool to begin with. */
function summarise(array $rows, array $overrides)
{
    $out = array('total' => 0, 'kpi' => 0, 'mk_command' => 0, 'mk_academy' => 0,
                 'staff' => 0, 'gw' => 0, 'removed' => 0);
    foreach ($rows as $row) {
        list($inPool) = effective($row, 'in_pool', $overrides);
        if (!$inPool) { $out['removed']++; continue; }
        $out['total']++;
        $out[$row['person_type'] === 'gw' ? 'gw' : 'staff']++;
        foreach (array('kpi', 'mk_command', 'mk_academy') as $flag) {
            list($v) = effective($row, $flag, $overrides);
            if ($v) { $out[$flag]++; }
        }
    }
    return $out;
}

/** Query: the per-column totals for the table foot. Same counting rule as
 *  summarise(), keyed by column instead. */
function footTotals(array $rows, array $overrides)
{
    $sum = summarise($rows, $overrides);
    return array(
        'in_pool'    => $sum['total'],
        'kpi'        => $sum['kpi'],
        'mk_command' => $sum['mk_command'],
        'mk_academy' => $sum['mk_academy'],
    );
}

/** Query: the effective value of one flag, and where it came from.
 *  Returns array(value, pinned) — pinned means an override exists AND it
 *  disagrees with the source column, which is the only case worth marking. */
function effective(array $row, $flag, array $overrides)
{
    $defs = flagDefs();
    $base = (int)$row[$defs[$flag]['base']];
    $key  = overrideKey($row['person_type'], $row['comp_id'], $row['person'], $flag);
    if (!isset($overrides[$key])) {
        return array($base, false, null);
    }
    $o = $overrides[$key];
    return array((int)$o['value'], (int)$o['value'] !== $base, $o);
}

/** Query: the set of values ?flag= may take — each column, and each column
 *  negated. "not_kpi" is the people the "after exemption" figure counts. */
function flagFilterValues()
{
    $out = array();
    foreach (array_keys(flagDefs()) as $f) {
        $out[] = $f;
        $out[] = 'not_' . $f;
    }
    return $out;
}

/** Query: how an active ?flag= reads on screen, or null if there is none. */
function flagFilterLabel($flagWant)
{
    if ($flagWant === '') {
        return null;
    }
    $negate = strpos($flagWant, 'not_') === 0;
    $base   = $negate ? substr($flagWant, 4) : $flagWant;
    $defs   = flagDefs();
    if (!isset($defs[$base])) {
        return null;
    }
    if ($base === 'in_pool') {
        return $negate ? 'Taken out of the pool' : 'In the staff pool';
    }
    $what = trim(str_replace('Exempted', '', $defs[$base]['label']));
    return $negate ? 'Not exempted — ' . $what : $defs[$base]['label'];
}

/** Query: does this row belong to the tile that was clicked?
 *
 *  Every tile counts only people who are in the pool, so this filter does too
 *  — otherwise clicking "13" could show 14 rows. The one exception is
 *  "not_in_pool", which is how the people taken OUT of the pool are listed. */
function matchesFlagFilter(array $row, $flagWant, array $overrides)
{
    if ($flagWant === '') {
        return true;
    }
    $negate = strpos($flagWant, 'not_') === 0;
    $base   = $negate ? substr($flagWant, 4) : $flagWant;
    $defs   = flagDefs();
    if (!isset($defs[$base])) {
        return true;
    }

    list($inPool) = effective($row, 'in_pool', $overrides);
    if ($base === 'in_pool') {
        return $negate ? !$inPool : (bool)$inPool;
    }
    if (!$inPool) {
        return false;
    }
    list($v) = effective($row, $base, $overrides);
    return $negate ? !$v : (bool)$v;
}

/** Query: does this row differ from what users / monthly_assign_gw say? */
function rowIsChanged(array $row, array $overrides)
{
    foreach (array_keys(flagDefs()) as $flag) {
        list($v, $pinned) = effective($row, $flag, $overrides);
        if ($pinned) { return true; }
    }
    return false;
}

/** Query: may this user tick a row belonging to $compId? */
function canEditComp($compId, $isSuper, array $adminComps)
{
    if ($isSuper) { return true; }
    return in_array((int)$compId, $adminComps, true);
}

/** Query: what one user may see and what they may change, as
 *  array(is_super, admin_comps, view_comps).
 *
 *  Viewable: their own company, plus anything they administer. Editable:
 *  administered companies only — being able to see a company is not being
 *  able to change it, and a company admin's own company is not automatically
 *  theirs to edit unless kpi_company_admins says so.
 *
 *  Fails closed: a dead database makes the caller a read-only viewer of their
 *  own company rather than an editor of all of them.
 *
 *  Shared so the API cannot drift into answering for a company the report
 *  would not show the same person. */
function viewScope(PDO $pdo, $userId, $ownCompId)
{
    $isSuper    = false;
    $adminComps = array();
    try {
        $isSuper    = isSuperAdmin($pdo, $userId);
        $adminComps = withTrillium(companyAdminComps($pdo, $userId));
    } catch (Exception $e) {
        error_log('staffPoolCore viewScope: ' . $e->getMessage());
    }

    $viewComps = $adminComps;
    if ($ownCompId !== null && $ownCompId !== ''
        && !in_array((int)$ownCompId, $viewComps, true)) {
        $viewComps[] = (int)$ownCompId;
    }
    $viewComps = withTrillium($viewComps);
    sort($viewComps);

    return array(
        'is_super'    => $isSuper,
        'admin_comps' => $adminComps,
        'view_comps'  => $viewComps,
    );
}

/** Query: which company a request is actually answered for.
 *
 *  $raw is the comp_id as asked for — null when it was not asked for at all,
 *  '' for "every company". A super admin may ask for any of them, or for all;
 *  everyone else is held to $viewComps, and an unrecognised or forbidden
 *  comp_id falls back to their own rather than erroring, so a stale bookmark
 *  or another application's hard-coded id still answers something true.
 *
 *  Returns an int, or null for "every company". */
function resolveCompId($raw, $isSuper, array $viewComps, $defaultCompId)
{
    if ($raw === null) {
        return $defaultCompId;
    }
    if ($raw === '') {
        return $isSuper ? null : $defaultCompId;
    }
    $want = (int)$raw;
    if ($isSuper || in_array($want, $viewComps, true)) {
        return $want;
    }
    return $defaultCompId;
}

/** Command: apply the on-screen filters to one population. */
function filterRows(array $rows, $search, $deptWant, $onlyChanged, array $overrides,
                    $flagWant = '')
{
    $needle = $search === '' ? null : $search;
    $out = array();
    foreach ($rows as $row) {
        if ($deptWant !== '' && deptLabel($row['department']) !== $deptWant) {
            continue;
        }
        if ($needle !== null) {
            /* stripos, not mb_*: no extension to depend on, and these are
             * names and codes, not text that needs locale-aware folding. */
            $hay = $row['fullname'] . ' ' . $row['person'];
            if (stripos($hay, $needle) === false) { continue; }
        }
        if ($onlyChanged && !rowIsChanged($row, $overrides)) {
            continue;
        }
        if (!matchesFlagFilter($row, $flagWant, $overrides)) {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

/* ---------- the shape every caller outside this folder sees --------------- */

/** Query: one pool row as the API publishes it.
 *
 *  This is the contract other applications read, and it is defined here, next
 *  to the counting rules, so a new field or a renamed one cannot drift away
 *  from what the report shows.
 *
 *  Every flag is the EFFECTIVE value — override first, source column second,
 *  exactly the value the report's check box for that person is ticked to.
 *  total_staff is the "Total Staff" column: 1 for somebody in the pool, 0 for
 *  somebody an override has taken out of it.
 *
 *  The exemption flags are reported as they stand even on a row whose
 *  total_staff is 0. They are that person's ticks, and blanking them would
 *  disagree with the report's own cells. The SUMMARY is where the pool rule
 *  applies: publicSummary() counts an exemption only for somebody who is in
 *  the pool, so summing a field over the in-pool list and reading the matching
 *  company_total_* give the same number.
 *
 *  department is the label the report groups by, so a blank one reads
 *  "No department" rather than coming back empty. */
function publicRow(array $row, array $overrides, array $compNames = array())
{
    list($inPool)   = effective($row, 'in_pool', $overrides);
    list($kpi)      = effective($row, 'kpi', $overrides);
    list($command)  = effective($row, 'mk_command', $overrides);
    list($academy)  = effective($row, 'mk_academy', $overrides);

    $compId = (int)$row['comp_id'];

    return array(
        'comp_id'                => $compId,
        'company_name'           => isset($compNames[$compId])
            ? $compNames[$compId]
            : 'Company ' . $compId,
        'person'                 => (string)$row['person'],
        'fullname'               => displayName($row['fullname'], $row['person']),
        'email'                  => (string)$row['email'],
        'mobile_no'              => (string)$row['mobile_no'],
        'position'               => (string)$row['position'],
        'department'             => deptLabel($row['department']),
        'staff_type'             => $row['person_type'] === 'gw' ? 'gw' : 'staff',
        'total_staff'            => $inPool ? 1 : 0,
        'kpi_scorecard_exempted' => $kpi ? 1 : 0,
        'mk_command_exempted'    => $command ? 1 : 0,
        'mk_academy_exempted'    => $academy ? 1 : 0,
    );
}

/** Query: the head count over $rows under the names the API publishes.
 *
 *  summarise() is the one that counts — this only renames its answer — so the
 *  API's totals and the report's tiles are the same figures by construction
 *  and not by two people keeping two functions in step. */
function publicSummary(array $rows, array $overrides)
{
    $s = summarise($rows, $overrides);
    return array(
        'company_total_staff'                  => (int)$s['total'],
        'company_total_kpi_scorecard_exempted' => (int)$s['kpi'],
        'company_total_mk_command_exempted'    => (int)$s['mk_command'],
        'company_total_mk_academy_exempted'    => (int)$s['mk_academy'],
        'company_excluded_staff'               => (int)$s['removed'],
        /* The two populations the report splits its table into. */
        'company_total_by_type'                => array(
            'staff' => (int)$s['staff'],
            'gw'    => (int)$s['gw'],
        ),
    );
}
