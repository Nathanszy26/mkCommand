<?php
/**
 * Job Spec approval progress report.
 *
 * MUST be hosted on globportal.com, same folder as jobSpecWrite.php and
 * staffPoolCore.php — mkPortal (versions), staff_portal2 (users) and the
 * reporting line are all local there under prog4. The mkcommand <-> globportal
 * 3306 path is firewalled.
 *
 * WHO IS COUNTED is the staff pool — staffPoolCore.php, the same code
 * staffPoolAPI.php and staffHeadcountReport.php count with. Nobody is decided
 * here: if staffPoolAPI.php?comp_id=1 says company_total_staff is 665, this
 * report counts 665 people for company 1, staff and GW together, and everybody
 * an override has taken out of the pool is counted nowhere.
 *
 * ONE ROW PER PERSON, not per version. Each pool member is counted once, by
 * where their CURRENT job spec stands — the newest version, the same one
 * staffOverview.php shows in its Job Spec column:
 *
 *   pending / approved / rejected   the status of that newest version
 *   pending (capability version)    no version at all, but a capability list
 *                                   (portal job descriptions) a superior has
 *                                   to approve — jobSpecWrite.php
 *                                   approve_capability. Read over HTTPS from
 *                                   jobSpecs.php (CapabilityClient).
 *   none                            no version and no capability list
 *
 * so pending + approved + rejected + none is the pool's head count, exactly.
 *
 * Reviewing is still read off the reporting line, by the rules
 * jobSpecWrite.php enforces:
 *   staff (is_gw = 0/NULL) -> evaluation.monthly_assign
 *                             (comp 1: organization_chart_glob — see chartSource)
 *   GW    (is_gw = 1)      -> evaluation.monthly_assign_gw
 * They never mix: a GW's `person` is a monthly_assign_gw_code, which is a
 * different namespace from users.person and can collide with one.
 *
 * TRILLIUM (comp 16) is reported as its own company, as the pool reports it,
 * but its people's versions and chart rows are filed under Globinaco (1) — so
 * every lookup goes through homeCompId() first.
 *
 * Access: any logged-in user may open this, but only active KPI super admins
 * (staff_portal2.kpi_super_admins, status = 1) may choose the company. Everyone
 * else is pinned to their session's own comp_id, on the page and on its AJAX
 * endpoints alike — see canPickCompany() and the $compId block in the
 * controller.
 *
 * Query string:
 *   comp_id  int     one company, or empty for all      (super admins only;
 *                                                        ignored for the rest)
 *   boss     string  boss_id to expand in the detail    (default: none)
 *   gwboss   string  boss_id to expand in the GW detail (default: none)
 *
 * Read-only report — no writes anywhere in this file. session_start() and
 * UnifiedAuth below exist only to drive the shared sidebar, which needs a
 * logged-in $currentUser to render (active link, permissions, user info).
 *
 * PHP 7.2 compatible.
 */

session_start();

require_once '../includes/UnifiedAuth.php';

if (!UnifiedAuth::isLoggedIn()) {
    header('Location: /accounts/mkPortal/user_validation.php');
    exit;
}

$currentUser = UnifiedAuth::getLoggedInUser();

/* The staff pool — who this page counts — plus Db and displayName().
 *
 * On globportal staffPoolCore.php lives in accounts/mkPortal/hr, beside
 * staffPoolAPI.php, while this page lives in accounts/mkPortal/mkCommand —
 * so ../hr is tried first. The second path is the repo layout, where both
 * sit in mkCommand_API/. A missing or out-of-date core says so on the page
 * rather than failing as a blank HTTP 500. */
$poolCore = null;
foreach (array(__DIR__ . '/../hr/staffPoolCore.php', __DIR__ . '/staffPoolCore.php') as $candidate) {
    if (file_exists($candidate)) { $poolCore = $candidate; break; }
}
if ($poolCore !== null) {
    require_once $poolCore;
}
if ($poolCore === null || !function_exists('inPoolRows') || !function_exists('homeCompId')) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<p style="font:14px sans-serif;padding:24px">'
       . ($poolCore === null
            ? 'staffPoolCore.php was not found. Expected it at accounts/mkPortal/hr/staffPoolCore.php.'
            : 'staffPoolCore.php at ' . htmlspecialchars($poolCore, ENT_QUOTES, 'UTF-8')
              . ' is out of date (no inPoolRows / homeCompId). Upload the current one.')
       . '</p>';
    exit;
}

/* ---------- access -------------------------------------------------------- */

/** Query: may this user choose which company to look at?
 *
 *  Only active KPI super admins may. Everyone else is pinned to the comp_id
 *  their session was built with, so the report answers "my company" and
 *  nothing else. The check itself is the pool's isSuperAdmin(), so this page
 *  and the Staff Headcount report agree on who a super admin is.
 *
 *  Fails closed: the caller treats a DB error as "not a super admin". */
function canPickCompany(PDO $pdo, $userId)
{
    return isSuperAdmin($pdo, $userId);
}

/* ---------- capability lists (company portals, over HTTPS) ---------------- */

/**
 * The company portals' job descriptions — a staff member's capability list —
 * live on mkcommand.mkgroup.my, and the DB port between the hosts is
 * firewalled, so they are read from jobSpecs.php action=capability. Same
 * client as jobSpecWrite.php and staffOverview.php.
 */
class CapabilityClient
{
    const URL = 'https://mkcommand.mkgroup.my/API/jobSpecs.php';

    /** Query: holders of one company keyed strtolower(person) . '|' . comp =>
     *  ['person','comp_id','items']. A company jobSpecs.php does not serve has
     *  no holders. Throws when the endpoint is unreachable or answers badly. */
    public static function forCompany($compId)
    {
        $ch = curl_init(self::URL . '?' . http_build_query(
            array('action' => 'capability', 'comp_id' => (int)$compId)));
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
        ));
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('capability unreachable: ' . $err);
        }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 400) {
            return array();   // a company with no portal schema configured there
        }
        $data = json_decode($body, true);
        if ($code < 200 || $code >= 300 || !is_array($data) || empty($data['success'])) {
            throw new RuntimeException('capability bad response (HTTP ' . $code . ')');
        }

        $out = array();
        foreach ($data['capability'] as $c) {
            $out[strtolower($c['person']) . '|' . (int)$c['comp_id']] = $c;
        }
        return $out;
    }
}

/** Query: the key a person is matched by across tables — lower-cased, because
 *  MySQL compared these case-insensitively and a PHP array key does not. Same
 *  shape CapabilityClient keys its holders by. */
function personKey($person, $compId)
{
    return strtolower(trim((string)$person)) . '|' . (int)$compId;
}

/* ---------- repository ---------------------------------------------------- */

/**
 * The reads behind the report: each person's newest version, and who may
 * review it. WHO is counted is not decided here — that is the staff pool.
 *
 * Every method takes STORAGE company ids (homeCompId()), because that is what
 * job_spec_version and the charts hold.
 */
class ApprovalReportRepository
{
    private $db;

    /** Live assignment today. Copied verbatim from jobSpecWrite.php so this
     *  report and the app never disagree about who may approve. */
    const ACTIVE_CLAUSE = "
        (oc.monthly_assign_from IS NULL OR oc.monthly_assign_from = ''
            OR STR_TO_DATE(REPLACE(oc.monthly_assign_from, '-', '/'), '%Y/%m/%d') <= CURDATE())
        AND (oc.monthly_assign_to IS NULL OR oc.monthly_assign_to = ''
            OR STR_TO_DATE(REPLACE(oc.monthly_assign_to, '-', '/'), '%Y/%m/%d') >= CURDATE())";

    /** A blank boss_id is not a superior. Both reporting-line tables hold rows
     *  with an empty boss_id for top-of-chart staff; without this they collapse
     *  into one phantom superior with no name and a "no account" badge, while
     *  the people under it are wrongly kept out of "no superior". */
    const HAS_BOSS = "
        TRIM(IFNULL(oc.boss_id, '')) <> ''";

    /** Comp 1's reporting lines live in organization_chart_glob; every other
     *  company's live in evaluation.monthly_assign. Same rule as
     *  staffHierarchy.php and jobSpecWrite.php: routing is on the STAFF-side
     *  comp_id. */
    const GLOB_COMP_ID = 1;

    /** Who may approve a GW — the clause GwRepository in jobSpecWrite.php
     *  checks. A GW with kpi_scorecard_exclude set IS in the pool (that column
     *  is a flag, not a pool rule) but nobody can approve them in the app, so
     *  they land under "no superior", which is the truth about them. */
    const GW_ACTIVE_CLAUSE = "
        g.monthly_assign_gw_status = '1'
        AND (g.kpi_scorecard_exclude IS NULL OR g.kpi_scorecard_exclude <> '1')";

    /** As HAS_BOSS, for the GW reporting line. */
    const GW_HAS_BOSS = "
        TRIM(IFNULL(g.boss_id, '')) <> ''";

    public function __construct(PDO $db) { $this->db = $db; }

    private function run($sql, array $args = array())
    {
        $stmt = $this->db->prepare($sql);
        foreach ($args as $k => $vv) {
            $stmt->bindValue($k, $vv, is_int($vv) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Inlined int IN() list. Every id has been through (int); '-1' matches
     *  nothing, so an empty list reads nothing rather than everything. */
    private static function intList(array $ids)
    {
        $ints = array_map('intval', $ids);
        return empty($ints) ? '-1' : implode(',', array_unique($ints));
    }

    /**
     * Query: the chart to read, always aliased `oc` so ACTIVE_CLAUSE and
     * HAS_BOSS apply verbatim.
     *
     * One company on one chart reads that table alone and keeps its indexes.
     * Companies on both charts UNION the two on disjoint scopes — which can
     * never double-count, and ignores a comp-1 row left in the old table
     * mid-migration instead of duplicating it.
     */
    private static function chartSource(array $comps)
    {
        $hasGlob  = in_array(self::GLOB_COMP_ID, $comps, true);
        $hasOther = count(array_diff($comps, array(self::GLOB_COMP_ID))) > 0;
        if (!$hasOther) {
            return 'staff_portal2.organization_chart_glob oc';
        }
        if (!$hasGlob) {
            return 'evaluation.monthly_assign oc';
        }
        return "(SELECT staff_id, comp_id, boss_id, boss_comp_id,
                        monthly_assign_from, monthly_assign_to
                 FROM evaluation.monthly_assign
                 WHERE comp_id <> " . self::GLOB_COMP_ID . "
                 UNION ALL
                 SELECT staff_id, comp_id, boss_id, boss_comp_id,
                        monthly_assign_from, monthly_assign_to
                 FROM staff_portal2.organization_chart_glob
                 WHERE comp_id = " . self::GLOB_COMP_ID . ") oc";
    }

    /**
     * Query: each person's NEWEST version, keyed personKey(person, comp).
     *
     * Newest is MAX(id) — the ordering staffOverview.php's Job Spec column and
     * jobSpecWrite.php's latest_approved both use — so a superseded version
     * never counts. $isGw: 0 = staff (is_gw NULL or 0), 1 = GW. Legacy rows
     * predate the column and are NULL, so IFNULL keeps them on the staff side.
     */
    public function latestVersions(array $comps, $isGw)
    {
        if (empty($comps)) { return array(); }
        $rows = $this->run(
            "SELECT v.id AS version_id, v.person, v.comp_id, v.status,
                    v.submitted_by, v.submitted_by_comp_id, v.submitted_at,
                    v.reviewed_at, v.reviewed_by,
                    (SELECT COUNT(*) FROM mkPortal.job_spec_version_item vi
                      WHERE vi.version_id = v.id) AS items
             FROM mkPortal.job_spec_version v
             INNER JOIN (SELECT MAX(x.id) AS id
                           FROM mkPortal.job_spec_version x
                          WHERE IFNULL(x.is_gw, 0) = " . ($isGw ? 1 : 0) . "
                            AND x.comp_id IN (" . self::intList($comps) . ")
                          GROUP BY x.person, x.comp_id) latest
                     ON latest.id = v.id"
        );
        $out = array();
        foreach ($rows as $r) {
            $out[personKey($r['person'], $r['comp_id'])] = $r;
        }
        return $out;
    }

    /** Query: every staff member's live direct superiors in these companies,
     *  personKey(staff) => [bossKey => boss]. */
    public function staffSuperiors(array $comps)
    {
        if (empty($comps)) { return array(); }
        return self::byStaff($this->run(
            "SELECT oc.staff_id, oc.comp_id, oc.boss_id, oc.boss_comp_id,
                    ub.fullname AS boss_name, ub.person IS NULL AS boss_missing
             FROM " . self::chartSource($comps) . "
             LEFT JOIN staff_portal2.users ub
                 ON ub.person = oc.boss_id AND ub.comp_id = oc.boss_comp_id
                AND ub.status = 1 AND IFNULL(ub.deleted, 0) = 0
             WHERE oc.comp_id IN (" . self::intList($comps) . ")
               AND " . self::ACTIVE_CLAUSE . "
               AND " . self::HAS_BOSS
        ));
    }

    /** Query: every GW's live supervisors in these companies, same shape.
     *  comp_id is VARCHAR on monthly_assign_gw, so the list is quoted and the
     *  column is left bare to keep its index. */
    public function gwSuperiors(array $comps)
    {
        if (empty($comps)) { return array(); }
        $list = array();
        foreach ($comps as $c) { $list[] = "'" . (int)$c . "'"; }
        return self::byStaff($this->run(
            "SELECT g.monthly_assign_gw_code AS staff_id, g.comp_id,
                    g.boss_id, g.boss_comp_id,
                    ub.fullname AS boss_name, ub.person IS NULL AS boss_missing
             FROM evaluation.monthly_assign_gw g
             LEFT JOIN staff_portal2.users ub
                 ON ub.person = g.boss_id AND ub.comp_id = g.boss_comp_id
                AND ub.status = 1 AND IFNULL(ub.deleted, 0) = 0
             WHERE g.comp_id IN (" . implode(',', array_unique($list)) . ")
               AND " . self::GW_ACTIVE_CLAUSE . "
               AND " . self::GW_HAS_BOSS
        ));
    }

    /** One entry per (staff, boss) pair. monthly_assign keeps history, so one
     *  reporting line can hold several live rows — that is one superior, not
     *  several, and keying on the pair collapses them. */
    private static function byStaff(array $rows)
    {
        $out = array();
        foreach ($rows as $r) {
            $bossId  = trim((string)$r['boss_id']);
            $bossKey = personKey($bossId, $r['boss_comp_id']);
            $out[personKey($r['staff_id'], $r['comp_id'])][$bossKey] = array(
                'boss_key'     => $bossKey,
                'boss_id'      => $bossId,
                'boss_comp_id' => (int)$r['boss_comp_id'],
                'boss_name'    => (string)$r['boss_name'],     // '' when no account
                'boss_missing' => (int)$r['boss_missing'] === 1,
            );
        }
        return $out;
    }

    /** Query: the task list of one version, in display order.
     *  Not population-specific — job_spec_version_item is keyed on version_id
     *  alone, so staff and GW share this.
     *
     *  $compId joins back to job_spec_version so a pinned viewer cannot read
     *  another company's tasks by guessing a version id: the item table itself
     *  carries no comp_id, and this is the one query reachable by id alone.
     *  It is a STORAGE comp — see homeCompId(). */
    public function itemsFor($versionId, $compId = null)
    {
        $args = array(':v' => (int)$versionId);
        $sql = "SELECT i.sort_order, i.category, i.task
                FROM mkPortal.job_spec_version_item i
                INNER JOIN mkPortal.job_spec_version v ON v.id = i.version_id
                WHERE i.version_id = :v";
        if ($compId !== null) {
            $sql .= " AND v.comp_id = :comp";
            $args[':comp'] = (int)$compId;
        }
        $sql .= " ORDER BY i.sort_order ASC, i.id ASC";
        return $this->run($sql, $args);
    }
}

/* ---------- one population, assembled ------------------------------------- */

/**
 * One population of the report — staff or GW — as one row per pool member.
 *
 * Built in PHP from four reads rather than as one SQL statement: the members
 * come from the pool (whose override rule lives in PHP), the capability lists
 * from another host, and the GW tables are latin1 while job_spec_version is
 * not. Every figure on the page is counted off $rows, so the tiles, the
 * superior table and the "no superior" card cannot disagree about who exists.
 */
class ApprovalPopulation
{
    /** The order a list is read in: what still needs somebody, first. */
    private static $ORDER = array('pending' => 0, 'rejected' => 1, 'approved' => 2, 'none' => 3);

    private $rows = array();

    /**
     * $members    in-pool rows of ONE type, from staffPoolCore.php
     * $latest     ApprovalReportRepository::latestVersions()
     * $holders    capability holders (staff only; empty for GW)
     * $superiors  ApprovalReportRepository::staffSuperiors() / gwSuperiors()
     * $isGw       the GW rule for "has a reviewer": a supervisor with no users
     *             account cannot log in to approve, so they do not count as one
     */
    public function __construct(array $members, array $latest, array $holders,
                                array $superiors, $isGw)
    {
        foreach ($members as $m) {
            $key  = personKey($m['person'], homeCompId($m['comp_id']));
            $spec = self::specFor($key, $latest, $holders);

            /* The reviewers are the live superiors other than whoever submitted
             * the newest version — JobSpecWriteService::review()'s
             * SUBMITTER_CANNOT_REVIEW_OWN. A capability list or no spec at all
             * has no submitter, so every superior is a reviewer. */
            $reviewers = array();
            $covered   = false;
            $all = isset($superiors[$key]) ? $superiors[$key] : array();
            foreach ($all as $bossKey => $b) {
                if ($spec['submitted_by'] !== ''
                    && strcasecmp($b['boss_id'], $spec['submitted_by']) === 0
                    && $b['boss_comp_id'] === (int)$spec['submitted_by_comp_id']) {
                    continue;
                }
                $reviewers[$bossKey] = $b;
                if (!$isGw || !$b['boss_missing']) {
                    $covered = true;
                }
            }

            $this->rows[] = array(
                'person'     => (string)$m['person'],
                'comp_id'    => (int)$m['comp_id'],
                'staff_name' => displayName($m['fullname'], $m['person']),
                'district'   => (string)$m['district'],
                'reviewers'  => $reviewers,
                'covered'    => $covered,
            ) + $spec;
        }
    }

    /** Query: where one person's job spec stands. */
    private static function specFor($key, array $latest, array $holders)
    {
        if (isset($latest[$key])) {
            $v = $latest[$key];
            return array(
                'status'               => (string)$v['status'],
                'version_id'           => (int)$v['version_id'],
                'items'                => (int)$v['items'],
                'capability'           => false,
                'submitted_by'         => trim((string)$v['submitted_by']),
                'submitted_by_comp_id' => (int)$v['submitted_by_comp_id'],
                'reviewed_at'          => $v['reviewed_at'],
                'reviewed_by'          => $v['reviewed_by'],
            );
        }
        $none = array(
            'version_id'           => null,
            'submitted_by'         => '',
            'submitted_by_comp_id' => 0,
            'reviewed_at'          => null,
            'reviewed_by'          => null,
        );
        if (isset($holders[$key])) {
            return array('status' => 'pending', 'capability' => true,
                         'items' => (int)$holders[$key]['items']) + $none;
        }
        return array('status' => 'none', 'capability' => false, 'items' => 0) + $none;
    }

    /** Query: the tiles. total is the pool's head count for this population,
     *  and the four statuses add up to it. */
    public function totals()
    {
        $out = array('total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'none' => 0);
        foreach ($this->rows as $r) {
            $out['total']++;
            if (isset($out[$r['status']])) { $out[$r['status']]++; }
        }
        return $out;
    }

    /** Query: one row per superior, with their people counted by status.
     *  Somebody with two superiors is counted under both — either may act —
     *  so this table is not meant to add up to the tiles; the tiles plus
     *  withoutReviewer() are. */
    public function byBoss()
    {
        $out = array();
        foreach ($this->rows as $r) {
            foreach ($r['reviewers'] as $bossKey => $b) {
                if (!isset($out[$bossKey])) {
                    $out[$bossKey] = $b + array('pending' => 0, 'approved' => 0,
                                                'rejected' => 0, 'none' => 0);
                }
                if (isset($out[$bossKey][$r['status']])) {
                    $out[$bossKey][$r['status']]++;
                }
            }
        }
        $out = array_values($out);
        usort($out, function ($a, $b) {
            $c = strcasecmp(displayName($a['boss_name'], $a['boss_id']),
                            displayName($b['boss_name'], $b['boss_id']));
            return $c !== 0 ? $c : strcasecmp($a['boss_key'], $b['boss_key']);
        });
        return $out;
    }

    /** Query: the people behind one superior's row. Matched on boss_id alone,
     *  which is what the View link carries. */
    public function forBoss($bossId)
    {
        $out = array();
        foreach ($this->rows as $r) {
            foreach ($r['reviewers'] as $b) {
                if (strcasecmp($b['boss_id'], (string)$bossId) === 0) {
                    $out[] = $r;
                    break;
                }
            }
        }
        return self::sorted($out);
    }

    /** Query: the people nobody can review — no live superior other than whoever
     *  submitted their spec. Every status, not just pending: an auto-approved
     *  version lands here by design (jobSpecWrite.php approves on submit exactly
     *  when the submitter is the only possible approver), and without them the
     *  tiles would not reconcile with the table. */
    public function withoutReviewer()
    {
        $out = array();
        foreach ($this->rows as $r) {
            if (!$r['covered']) { $out[] = $r; }
        }
        return self::sorted($out);
    }

    private static function sorted(array $rows)
    {
        $order = self::$ORDER;
        usort($rows, function ($a, $b) use ($order) {
            $oa = isset($order[$a['status']]) ? $order[$a['status']] : 9;
            $ob = isset($order[$b['status']]) ? $order[$b['status']] : 9;
            if ($oa !== $ob) { return $oa - $ob; }
            return strcasecmp($a['staff_name'], $b['staff_name']);
        });
        return $rows;
    }
}

/**
 * Query: the whole report for one company, or for every company in the pool's
 * scope when $compId is null — the same scope staffPoolAPI.php's comp_id=all
 * totals, so "All companies" here is that same group figure.
 *
 * Returns array(
 *   'staff' => ApprovalPopulation, 'gw' => ApprovalPopulation,
 *   'summary' => publicSummary() of the pool, 'capability_down' => bool).
 */
function loadReport(PDO $pdo, StaffPoolRepository $pool, array $poolCompanies, $compId)
{
    $scopeComps = array();
    foreach ($poolCompanies as $c) {
        $scopeComps[] = (int)$c['comp_id'];
    }
    $comps = ($compId !== null) ? array((int)$compId) : $scopeComps;

    /* The pool, exactly as staffPoolAPI.php builds it. */
    $rows      = array_merge($pool->staff($compId, $scopeComps), $pool->gw($compId, $scopeComps));
    $overrides = $pool->overrides($compId);
    $summary   = publicSummary($rows, $overrides);

    $staff = array();
    $gw    = array();
    foreach (inPoolRows($rows, $overrides) as $r) {
        if ($r['person_type'] === 'gw') { $gw[] = $r; } else { $staff[] = $r; }
    }

    /* Where their records are filed: Trillium (16) reads Globinaco's (1). */
    $store   = array_values(array_unique(array_map('homeCompId', $comps)));
    $gwStore = array_values(array_intersect($store, StaffPoolRepository::gwComps()));

    $repo    = new ApprovalReportRepository($pdo);
    $holders = capabilityHolders($store);

    return array(
        'staff' => new ApprovalPopulation(
            $staff,
            $repo->latestVersions($store, 0),
            $holders === null ? array() : $holders,
            $repo->staffSuperiors($store),
            false
        ),
        'gw' => new ApprovalPopulation(
            $gw,
            $repo->latestVersions($gwStore, 1),
            array(),                       // a GW has no capability list
            $repo->gwSuperiors($gwStore),
            true
        ),
        'summary'         => $summary,
        'capability_down' => ($holders === null),
    );
}

/* ---------- view helpers -------------------------------------------------- */

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** "2026-07-16 08:29:00" -> "16 Jul 2026" */
function fmtDate($dt)
{
    if (!$dt) { return '—'; }
    $ts = strtotime($dt);
    return $ts ? date('j M Y', $ts) : $dt;
}

function pct($done, $total)
{
    return $total > 0 ? (int)round(($done / $total) * 100) : 0;
}

function selfUrl(array $overrides)
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') { unset($params[$k]); }
    }
    return basename(__FILE__) . (empty($params) ? '' : '?' . http_build_query($params));
}

/** Query: DOM id for one superior's detail row. Unique per section + boss,
 *  because more than one can be open at a time once JS is driving. */
function detailAnchor($isGw, $bossId)
{
    return ($isGw ? 'gwd-' : 'd-') . preg_replace('/[^A-Za-z0-9_-]/', '_', $bossId);
}

/** Query: AJAX url for one version's task list. Built from scratch rather than
 *  via selfUrl(), which merges $_GET — inside an AJAX response that would carry
 *  the outer request's own ajax params into the link. */
function taskUrl($versionId)
{
    return basename(__FILE__) . '?ajax=tasks&version=' . (int)$versionId;
}

/** Query: $companies with $compId guaranteed present. The pool's companies()
 *  only offers the weekly-reporting group, so a viewer whose own company has
 *  left it would otherwise see "All companies" in the select while the data
 *  is filtered to their company. $name is the session's company_name. */
function withOwnCompany(array $companies, $compId, $name)
{
    if ($compId === null) {
        return $companies;
    }
    foreach ($companies as $c) {
        if ((int)$c['comp_id'] === (int)$compId) {
            return $companies;
        }
    }
    $companies[] = array(
        'comp_id'      => (int)$compId,
        'display_name' => ($name !== null && $name !== '') ? $name : 'Company ' . (int)$compId,
    );
    return $companies;
}

/** Query: is this row a capability list awaiting approval, rather than a
 *  version? */
function isCapabilityRow(array $row)
{
    return !empty($row['capability']);
}

/** Command: echo a row's status pill. A capability stub is pending like any
 *  other, and says so beneath — it has no version for the task count to open. */
function renderStatus(array $row)
{
    $label = ($row['status'] === 'none') ? 'no job spec' : $row['status'];
    echo '<span class="pill ' . h($row['status']) . '">' . h($label) . '</span>';
    if (isCapabilityRow($row)) {
        echo '<div class="muted">capability version</div>';
    }
}

/** Command: echo a row's task count — a link that opens a version's tasks, the
 *  capability list's size, or a dash for somebody with no job spec. */
function renderTaskCount(array $row, $span)
{
    if (isCapabilityRow($row)) {
        echo '<td class="num muted" title="Capability list — not yet approved in MK Command">'
           . (int)$row['items'] . '</td>';
    } elseif ($row['version_id'] === null) {
        echo '<td class="num muted">&mdash;</td>';
    } elseif ((int)$row['items'] > 0) {
        echo '<td class="num"><a class="tasklink" href="' . h(taskUrl($row['version_id'])) . '"'
           . ' data-url="' . h(taskUrl($row['version_id'])) . '"'
           . ' data-span="' . (int)$span . '">' . (int)$row['items'] . '</a></td>';
    } else {
        echo '<td class="num">0</td>';
    }
}

/** Command: echo one version's tasks, inline beneath its row. */
function renderTasks(array $rows, $span, $anchor)
{
    echo '<tr class="taskrow exprow" id="' . h($anchor) . '"><td colspan="' . (int)$span . '">';
    if (empty($rows)) {
        echo '<div class="taskbox"><div class="empty">No tasks in this version.</div></div>';
        echo '</td></tr>';
        return;
    }
    echo '<div class="taskbox"><ol class="tasklist">';
    foreach ($rows as $t) {
        echo '<li>';
        if (trim((string)$t['category']) !== '') {
            echo '<span class="cat">' . h($t['category']) . '</span> ';
        }
        echo h($t['task']) . '</li>';
    }
    echo '</ol></div></td></tr>';
}

/** Command: echo one superior's people as a nested table, inline beneath
 *  their row. Staff and GW differ only by the District column, so one renderer
 *  serves both rather than two near-identical blocks of markup. */
function renderDetail(array $rows, $isGw, $span, $anchor)
{
    echo '<tr class="detailrow exprow" id="' . h($anchor) . '"><td colspan="' . (int)$span . '">';
    echo '<div class="detailbox"><table><thead><tr>';
    echo '<th class="idx">#</th><th>' . ($isGw ? 'GW' : 'Staff') . '</th>';
    if ($isGw) { echo '<th>District</th>'; }
    echo '<th>Status</th><th class="num">Tasks</th><th>Reviewed</th><th>By</th>';
    echo '</tr></thead><tbody>';

    $inner = $isGw ? 7 : 6;
    if (empty($rows)) {
        echo '<tr><td colspan="' . $inner . '" class="empty">Nobody under this superior.</td></tr>';
    }
    $n = 0;
    foreach ($rows as $d) {
        $n++;
        echo '<tr>';
        echo '<td class="idx">' . $n . '</td>';
        echo '<td>' . h($d['staff_name'])
           . '<div class="muted">' . h($d['person']) . '</div></td>';
        if ($isGw) {
            echo '<td class="muted">' . h($d['district'] !== '' ? $d['district'] : '—') . '</td>';
        }
        echo '<td>';
        renderStatus($d);
        echo '</td>';
        renderTaskCount($d, $inner);
        echo '<td>' . h(fmtDate($d['reviewed_at'])) . '</td>';
        echo '<td class="muted">' . h($d['reviewed_by'] ? $d['reviewed_by'] : '—') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div></td></tr>';
}

/** Command: render the "no superior" card for one population. Placed under
 *  that population's table so the two together account for everybody in the
 *  tiles. Warning styling only when something is still pending — an
 *  auto-approved row here is expected, not a problem. */
function renderNoReviewer(array $rows, $isGw)
{
    if (empty($rows)) { return; }

    $pending = 0;
    foreach ($rows as $r) {
        if ($r['status'] === 'pending') { $pending++; }
    }
    $who = $isGw ? 'GW' : 'Staff';
    ?>
    <div class="card <?php echo $pending > 0 ? 'warnbox' : ''; ?>">
        <strong><?php echo count($rows); ?> <?php echo h($who); ?> with no Superior</strong>
        <table style="margin-top:10px">
            <thead>
            <tr><th class="idx">#</th><th><?php echo h($who); ?></th>
                <th>Status</th><th class="num">Tasks</th></tr>
            </thead>
            <tbody>
            <?php $n = 0; foreach ($rows as $o): $n++; ?>
            <tr>
                <td class="idx"><?php echo $n; ?></td>
                <td><?php echo h($o['staff_name']); ?>
                    <div class="muted"><?php echo h($o['person']); ?></div></td>
                <td><?php renderStatus($o); ?></td>
                <?php renderTaskCount($o, 4); ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/** Query: capability holders for some STORAGE companies. Null when the portal
 *  host could not be read: the report then counts those staff under "no job
 *  spec" and says so, rather than silently claiming they have nothing. */
function capabilityHolders(array $compIds)
{
    $out = array();
    try {
        foreach ($compIds as $cid) {
            $out += CapabilityClient::forCompany($cid);
        }
    } catch (Exception $e) {
        error_log('jobSpecApprovalReport capability: ' . $e->getMessage());
        return null;
    }
    return $out;
}

/* ---------- controller ---------------------------------------------------- */

$error = null;
$capabilityDown = false;   // portal capability lists unreadable this load
$poolSummary = null;       // the pool's head count, as staffPoolAPI.php reports it
$emptyTotals = array('total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'none' => 0);
$totals = $emptyTotals;
$bosses = array();
$noReviewer = array();
$companies = array();
$detail = array();

$gwTotals     = $emptyTotals;
$gwBosses     = array();
$gwNoReviewer = array();
$gwDetail     = array();

/** The company the report opens on: the viewer's own, straight off the session.
 *  UnifiedAuth joins users -> subsidiaries at login and stores comp_id
 *  (subsidiaries.id) with the rest of the session data, so there is nothing to
 *  look up here. */
$defaultCompId = isset($currentUser['comp_id']) && $currentUser['comp_id'] !== ''
    ? (int)$currentUser['comp_id']
    : null;

/** Who may change it: active KPI super admins only. The check fails closed, so
 *  a dead database pins everyone rather than opening the report up. */
try {
    $canPick = canPickCompany(
        Db::get(),
        isset($currentUser['id']) ? $currentUser['id'] : null
    );
} catch (Exception $e) {
    error_log('jobSpecApprovalReport super admin check: ' . $e->getMessage());
    $canPick = false;
}

/** For a super admin: absent comp_id in the query string (a bare first load)
 *  falls back to their own company; an EXPLICITLY empty comp_id means "all
 *  companies". The two must stay distinguishable, or picking All companies
 *  would bounce straight back to the default and the option would be
 *  unreachable.
 *
 *  For everyone else the query string is ignored outright — not validated
 *  against a list, just not read — so neither a hand-typed ?comp_id= nor a
 *  bookmarked link can move them off their own company. This runs ahead of the
 *  AJAX branches below, so those inherit the same pin. */
if (!$canPick) {
    $compId = $defaultCompId;
} elseif (!isset($_GET['comp_id'])) {
    $compId = $defaultCompId;
} elseif ($_GET['comp_id'] === '') {
    $compId = null;
} else {
    $compId = (int)$_GET['comp_id'];
}

/** A pinned viewer with no company on their session has no scope to report on.
 *  Leaving $compId null here would hand them every company, which is exactly
 *  what the pin exists to prevent, so the report is withheld instead. */
$noScope = (!$canPick && $defaultCompId === null);
if ($noScope) {
    $error = 'Your account is not linked to a company, so this report has '
           . 'nothing to show. Ask HR to set your company.';
}
$boss   = (isset($_GET['boss']) && $_GET['boss'] !== '') ? trim($_GET['boss']) : null;
$gwBoss = (isset($_GET['gwboss']) && $_GET['gwboss'] !== '') ? trim($_GET['gwboss']) : null;

/* AJAX: one version's tasks. Everything below this block is skipped. */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'tasks') {
    header('Content-Type: text/html; charset=UTF-8');
    $ajaxVersion = isset($_GET['version']) ? (int)$_GET['version'] : 0;
    try {
        if ($noScope) {
            throw new RuntimeException('Viewer has no company');
        }
        if ($ajaxVersion <= 0) {
            throw new RuntimeException('Missing version');
        }
        $ajaxRepo = new ApprovalReportRepository(Db::get());
        renderTasks($ajaxRepo->itemsFor($ajaxVersion, $compId === null ? null : homeCompId($compId)),
                    7, 'v-' . $ajaxVersion);
    } catch (Exception $e) {
        error_log('jobSpecApprovalReport ajax tasks: ' . $e->getMessage());
        http_response_code(500);
        echo '<tr class="taskrow exprow"><td colspan="7"><div class="taskbox">'
           . '<div class="empty">Could not load these tasks.</div></div></td></tr>';
    }
    exit;
}

/* AJAX: the detail row for one superior. Uses the SAME renderDetail() as the
 * full page, so there is one definition of that markup and the JS never has
 * to rebuild it. */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'detail') {
    header('Content-Type: text/html; charset=UTF-8');
    $ajaxIsGw = (isset($_GET['is_gw']) && (int)$_GET['is_gw'] === 1);
    $ajaxBoss = isset($_GET['for']) ? trim($_GET['for']) : '';
    try {
        if ($noScope) {
            throw new RuntimeException('Viewer has no company');
        }
        if ($ajaxBoss === '') {
            throw new RuntimeException('Missing superior');
        }
        $ajaxPool   = new StaffPoolRepository(Db::get());
        $ajaxReport = loadReport(Db::get(), $ajaxPool, $ajaxPool->companies(), $compId);
        $ajaxPop    = $ajaxIsGw ? $ajaxReport['gw'] : $ajaxReport['staff'];
        renderDetail($ajaxPop->forBoss($ajaxBoss), $ajaxIsGw, 8,
                     detailAnchor($ajaxIsGw, $ajaxBoss));
    } catch (Exception $e) {
        error_log('jobSpecApprovalReport ajax: ' . $e->getMessage());
        http_response_code(500);
        echo '<tr class="detailrow"><td colspan="8"><div class="detailbox">'
           . '<div class="empty">Could not load this list.</div></div></td></tr>';
    }
    exit;
}

try {
    if ($noScope) {
        throw new RuntimeException('Viewer has no company');
    }
    $pool          = new StaffPoolRepository(Db::get());
    $poolCompanies = $pool->companies();
    $companies     = withOwnCompany(
        $poolCompanies,
        $defaultCompId,
        isset($currentUser['company_name']) ? $currentUser['company_name'] : null
    );

    $report         = loadReport(Db::get(), $pool, $poolCompanies, $compId);
    $poolSummary    = $report['summary'];
    $capabilityDown = $report['capability_down'];

    $totals     = $report['staff']->totals();
    $bosses     = $report['staff']->byBoss();
    $noReviewer = $report['staff']->withoutReviewer();
    if ($boss !== null) {
        $detail = $report['staff']->forBoss($boss);
    }

    $gwTotals     = $report['gw']->totals();
    $gwBosses     = $report['gw']->byBoss();
    $gwNoReviewer = $report['gw']->withoutReviewer();
    if ($gwBoss !== null) {
        $gwDetail = $report['gw']->forBoss($gwBoss);
    }
} catch (Exception $e) {
    error_log('jobSpecApprovalReport: ' . $e->getMessage());
    /* Keeps a message already set above — the no-company case says
       something useful, the generic one does not. */
    if ($error === null) {
        $error = 'Could not load the report. Check the error log.';
    }
    /* The override tables are the one failure worth naming, as
       staffPoolAPI.php names it: nothing counts until the DDL has run. */
    if (strpos($e->getMessage(), 'staff_flag_override') !== false) {
        $error = 'The staff pool override tables are not installed yet. Run '
               . 'mkCommand_API/sql/staff_flag_override.sql on globportal.';
    }
}

/** Label for the pinned filter: the session's own company name, with the same
 *  fallback withOwnCompany() uses so the two never disagree. */
$ownCompanyLabel = (isset($currentUser['company_name'])
                    && trim((string)$currentUser['company_name']) !== '')
    ? $currentUser['company_name']
    : ($defaultCompId !== null ? 'Company ' . $defaultCompId : '—');

/* "Actioned" is a review question, so it is asked of the people who HAVE a
 * spec to review; those with none are their own tile. */
$withSpec = $totals['pending'] + $totals['approved'] + $totals['rejected'];
$done     = $totals['approved'] + $totals['rejected'];

$gwWithSpec = $gwTotals['pending'] + $gwTotals['approved'] + $gwTotals['rejected'];
$gwDone     = $gwTotals['approved'] + $gwTotals['rejected'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Job Spec Approval Progress</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
    :root {
        --bg:#f1f5f9; --surface:#fff; --primary:#2563eb; --success:#059669;
        --danger:#dc2626; --warn:#b45309; --text:#1e293b; --muted:#64748b; --border:#e2e8f0;
    }
    * { box-sizing:border-box; }
    body { margin:0; padding:24px; background:var(--bg); color:var(--text);
           font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; }
    .wrap { max-width:1100px; margin:0 auto; }
    .main-content { margin-left:260px; transition:margin-left .3s ease; }
    .main-content.sidebar-collapsed { margin-left:60px; }
    @media (max-width:1024px) { .main-content { margin-left:60px; } }
    @media (max-width:768px)  { .main-content { margin-left:0 !important; } body { padding:12px; } }
    h1 { font-size:20px; margin:0 0 4px; }
    .sub { color:var(--muted); font-size:13px; margin-bottom:20px; }
    .card { background:var(--surface); border:1px solid var(--border); border-radius:12px;
            padding:16px; margin-bottom:16px; }
    .filters { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:16px; }
    select, .btn { font:inherit; padding:7px 12px; border-radius:8px;
                   border:1px solid var(--border); background:var(--surface); color:var(--text); }
    .btn { text-decoration:none; display:inline-block; }
    .btn.on { background:var(--primary); border-color:var(--primary); color:#fff; font-weight:600; }
    .tiles { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
    .tile { flex:1; min-width:150px; background:var(--surface); border:1px solid var(--border);
            border-radius:12px; padding:14px; }
    .tile .n { font-size:26px; font-weight:700; line-height:1.1; }
    .tile .l { font-size:12px; color:var(--muted); margin-top:2px; }
    .n.pending  { color:var(--warn); }
    .n.approved { color:var(--success); }
    .n.rejected { color:var(--danger); }
    table { width:100%; border-collapse:collapse; }
    th { text-align:left; font-size:12px; text-transform:uppercase; letter-spacing:.03em;
         color:var(--muted); padding:8px 10px; border-bottom:2px solid var(--border); }
    td { padding:9px 10px; border-bottom:1px solid var(--border); vertical-align:middle; }
    tr:last-child td { border-bottom:none; }
    .num { text-align:right; font-variant-numeric:tabular-nums; }
    .bar { height:6px; background:var(--border); border-radius:3px; overflow:hidden; min-width:90px; }
    .bar > i { display:block; height:100%; background:var(--success); }
    .pill { display:inline-block; padding:2px 9px; border-radius:99px; font-size:11.5px; font-weight:700; }
    .pill.pending  { background:rgba(180,83,9,.10);  color:var(--warn); }
    .pill.approved { background:rgba(5,150,105,.10); color:var(--success); }
    .pill.rejected { background:rgba(220,38,38,.08); color:var(--danger); }
    .pill.none     { background:rgba(100,116,139,.12); color:var(--muted); }
    .n.none { color:var(--muted); }
    .poolline { display:flex; flex-wrap:wrap; gap:6px 18px; align-items:baseline; }
    .poolline .big { font-size:20px; font-weight:700; }
    .warnbox { border-left:4px solid var(--danger); background:rgba(220,38,38,.06); }
    .empty { color:var(--muted); text-align:center; padding:26px 0; }
    a { color:var(--primary); }
    .muted { color:var(--muted); font-size:12px; }
    /* inline expanded detail */
    .rowopen > td { background:rgba(37,99,235,.04); }
    .detailrow > td { background:rgba(37,99,235,.04); padding:0 10px 12px;
                      border-bottom:1px solid var(--border); }
    .detailbox { border:1px solid var(--border); border-radius:10px;
                 background:var(--surface); padding:4px 10px; }
    .detailbox th { font-size:11px; padding:6px 8px; }
    .detailbox td { padding:7px 8px; }
    .idx { color:var(--muted); font-variant-numeric:tabular-nums; width:38px; }
    tr[hidden] { display:none; }
    .tasklink { font-weight:600; text-decoration:none; border-bottom:1px dashed var(--primary); }
    .tasklink.open { color:var(--text); border-bottom-color:var(--muted); }
    .taskrow > td { background:rgba(37,99,235,.04); padding:0 10px 12px; }
    .taskbox { border:1px solid var(--border); border-radius:10px; background:var(--surface);
               padding:8px 10px; }
    .tasklist { margin:0; padding-left:22px; }
    .tasklist li { padding:4px 0; border-bottom:1px solid var(--border); }
    .tasklist li:last-child { border-bottom:none; }
    .cat { display:inline-block; padding:1px 7px; margin-right:6px; border-radius:99px;
           background:rgba(37,99,235,.08); color:var(--primary); font-size:11px; font-weight:700; }
    .loading { color:var(--muted); font-size:12px; padding:10px; text-align:center; }
    /* GW section divider */
    .sectionhead { display:flex; align-items:baseline; gap:10px; margin:30px 0 4px;
                   padding-top:22px; border-top:2px solid var(--border); }
    .sectionhead h2 { font-size:18px; margin:0; }
    .gwtag { display:inline-block; padding:2px 9px; border-radius:99px; font-size:11.5px;
             font-weight:700; background:rgba(202,138,4,.12); color:#ca8a04; }
    @media print { body { padding:0; background:#fff; } .filters, .btn { display:none; } }
</style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
<div class="wrap">

<h1>Job Spec Approval Progress</h1>
<div class="sub">
    Who still owes a review.
    Generated <?php echo h(date('j M Y, H:i')); ?>.
</div>

<?php if ($error !== null): ?>
    <div class="card warnbox"><?php echo h($error); ?></div>
<?php else: ?>

<div class="filters">
    <?php if ($canPick): ?>
    <form method="get" style="display:inline">
        <select name="comp_id" onchange="this.form.submit()">
            <option value="" <?php echo ($compId === null ? 'selected' : ''); ?>>All companies</option>
            <?php foreach ($companies as $c): ?>
                <option value="<?php echo (int)$c['comp_id']; ?>"
                    <?php echo ($compId === (int)$c['comp_id'] ? 'selected' : ''); ?>>
                    <?php echo h($c['display_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php else: ?>
    <!-- Pinned viewer: a disabled select keeps the filter row looking the same
         while making it plain the company is not theirs to change. -->
    <select disabled title="Only KPI super admins can change the company">
        <option><?php echo h($ownCompanyLabel); ?></option>
    </select>
    <?php endif; ?>
</div>

<?php if ($capabilityDown): ?>
    <div class="card warnbox">
        Capability lists could not be read from the company portals, so staff still on
        their capability version are counted under "No job spec" below instead of
        "Awaiting approval".
    </div>
<?php endif; ?>

<?php if ($poolSummary !== null): ?>
<!-- The pool's own head count — the figure staffPoolAPI.php and the Staff
     Headcount report give for this filter. The two sections below add up to it. -->
<div class="card poolline">
    <span><span class="big"><?php echo (int)$poolSummary['company_total_staff']; ?></span>
        staff in the pool</span>
    <span class="muted">
        <?php echo (int)$poolSummary['company_total_by_type']['staff']; ?> staff
        + <?php echo (int)$poolSummary['company_total_by_type']['gw']; ?> general workers
        <?php if ((int)$poolSummary['company_excluded_staff'] > 0): ?>
            &middot; <?php echo (int)$poolSummary['company_excluded_staff']; ?> taken out of the pool, not counted
        <?php endif; ?>
    </span>
</div>
<?php endif; ?>

<div class="tiles">
    <div class="tile"><div class="n"><?php echo (int)$totals['total']; ?></div>
        <div class="l">Staff</div></div>
    <div class="tile"><div class="n pending"><?php echo (int)$totals['pending']; ?></div>
        <div class="l">Awaiting approval</div></div>
    <div class="tile"><div class="n approved"><?php echo (int)$totals['approved']; ?></div>
        <div class="l">Approved</div></div>
    <div class="tile"><div class="n rejected"><?php echo (int)$totals['rejected']; ?></div>
        <div class="l">Rejected</div></div>
    <div class="tile"><div class="n none"><?php echo (int)$totals['none']; ?></div>
        <div class="l">No job spec</div></div>
    <div class="tile"><div class="n"><?php echo pct($done, $withSpec); ?>%</div>
        <div class="l"><?php echo (int)$done; ?> of <?php echo (int)$withSpec; ?> actioned</div></div>
</div>

<div class="card">
    <table>
        <thead>
        <tr>
            <th class="idx">#</th>
            <th>Superior</th>
            <th class="num">Pending</th>
            <th class="num">Approved</th>
            <th class="num">Rejected</th>
            <th class="num">No spec</th>
            <th>Progress</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($bosses)): ?>
            <tr><td colspan="8" class="empty">Nothing to show for this filter.</td></tr>
        <?php endif; ?>
        <?php $i = 0; foreach ($bosses as $b):
            $i++;
            $tot  = (int)$b['pending'] + (int)$b['approved'] + (int)$b['rejected'];
            $dn   = (int)$b['approved'] + (int)$b['rejected'];
            $isOpen = ($boss !== null && $boss === $b['boss_id']);
        ?>
        <tr<?php echo $isOpen ? ' class="rowopen"' : ''; ?>>
            <td class="idx"><?php echo $i; ?></td>
            <td>
                <?php echo h(displayName($b['boss_name'], $b['boss_id'])); ?>
                <?php if (!empty($b['boss_missing'])): ?>
                    <span class="pill rejected" title="No active users record">no account</span>
                <?php endif; ?>
                <div class="muted"><?php echo h($b['boss_id']); ?></div>
            </td>
            <td class="num"><?php echo (int)$b['pending'] ? '<strong>' . (int)$b['pending'] . '</strong>' : '0'; ?></td>
            <td class="num"><?php echo (int)$b['approved']; ?></td>
            <td class="num"><?php echo (int)$b['rejected']; ?></td>
            <td class="num muted"><?php echo (int)$b['none']; ?></td>
            <td>
                <div class="bar"><i style="width:<?php echo pct($dn, $tot); ?>%"></i></div>
                <div class="muted"><?php echo $dn; ?>/<?php echo $tot; ?></div>
            </td>
            <td class="num">
                <a href="<?php echo h(selfUrl(array('boss' => $isOpen ? null : $b['boss_id']))); ?>#<?php echo h(detailAnchor(false, $b['boss_id'])); ?>"
                   data-boss="<?php echo h($b['boss_id']); ?>"
                   data-span="8"
                   data-url="<?php echo h(selfUrl(array('ajax' => 'detail', 'is_gw' => 0, 'for' => $b['boss_id'], 'boss' => null, 'gwboss' => null))); ?>">
                    <?php echo $isOpen ? 'Hide' : 'View'; ?>
                </a>
            </td>
        </tr>
        <?php if ($isOpen) { renderDetail($detail, false, 8, detailAnchor(false, $b['boss_id'])); } ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php renderNoReviewer($noReviewer, false); ?>

<!-- ============================ GW ==================================== -->

<div class="sectionhead">
    <h2>General Workers</h2>
    <span class="gwtag">GW</span>
</div>
<!-- <div class="sub">
    Reporting line comes from monthly_assign_gw, not the organization chart.
</div> -->

<div class="tiles">
    <div class="tile"><div class="n"><?php echo (int)$gwTotals['total']; ?></div>
        <div class="l">General workers</div></div>
    <div class="tile"><div class="n pending"><?php echo (int)$gwTotals['pending']; ?></div>
        <div class="l">Awaiting approval</div></div>
    <div class="tile"><div class="n approved"><?php echo (int)$gwTotals['approved']; ?></div>
        <div class="l">Approved</div></div>
    <div class="tile"><div class="n rejected"><?php echo (int)$gwTotals['rejected']; ?></div>
        <div class="l">Rejected</div></div>
    <div class="tile"><div class="n none"><?php echo (int)$gwTotals['none']; ?></div>
        <div class="l">No job spec</div></div>
    <div class="tile"><div class="n"><?php echo pct($gwDone, $gwWithSpec); ?>%</div>
        <div class="l"><?php echo (int)$gwDone; ?> of <?php echo (int)$gwWithSpec; ?> actioned</div></div>
</div>

<div class="card">
    <table>
        <thead>
        <tr>
            <th class="idx">#</th>
            <th>Supervisor</th>
            <th class="num">Pending</th>
            <th class="num">Approved</th>
            <th class="num">Rejected</th>
            <th class="num">No spec</th>
            <th>Progress</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($gwBosses)): ?>
            <tr><td colspan="8" class="empty">No supervisors to show for this filter.</td></tr>
        <?php endif; ?>
        <?php $i = 0; foreach ($gwBosses as $b):
            $i++;
            $tot  = (int)$b['pending'] + (int)$b['approved'] + (int)$b['rejected'];
            $dn   = (int)$b['approved'] + (int)$b['rejected'];
            $isOpen = ($gwBoss !== null && $gwBoss === $b['boss_id']);
        ?>
        <tr<?php echo $isOpen ? ' class="rowopen"' : ''; ?>>
            <td class="idx"><?php echo $i; ?></td>
            <td>
                <?php echo h(displayName($b['boss_name'], $b['boss_id'])); ?>
                <?php if (!empty($b['boss_missing'])): ?>
                    <span class="pill rejected" title="No active users record">no account</span>
                <?php endif; ?>
                <div class="muted"><?php echo h($b['boss_id']); ?></div>
            </td>
            <td class="num"><?php echo (int)$b['pending'] ? '<strong>' . (int)$b['pending'] . '</strong>' : '0'; ?></td>
            <td class="num"><?php echo (int)$b['approved']; ?></td>
            <td class="num"><?php echo (int)$b['rejected']; ?></td>
            <td class="num muted"><?php echo (int)$b['none']; ?></td>
            <td>
                <div class="bar"><i style="width:<?php echo pct($dn, $tot); ?>%"></i></div>
                <div class="muted"><?php echo $dn; ?>/<?php echo $tot; ?></div>
            </td>
            <td class="num">
                <a href="<?php echo h(selfUrl(array('gwboss' => $isOpen ? null : $b['boss_id']))); ?>#<?php echo h(detailAnchor(true, $b['boss_id'])); ?>"
                   data-boss="<?php echo h($b['boss_id']); ?>"
                   data-span="8"
                   data-url="<?php echo h(selfUrl(array('ajax' => 'detail', 'is_gw' => 1, 'for' => $b['boss_id'], 'boss' => null, 'gwboss' => null))); ?>">
                    <?php echo $isOpen ? 'Hide' : 'View'; ?>
                </a>
            </td>
        </tr>
        <?php if ($isOpen) { renderDetail($gwDetail, true, 8, detailAnchor(true, $b['boss_id'])); } ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php renderNoReviewer($gwNoReviewer, true); ?>

<div class="muted">
    <!-- A GW with two live assignment rows is counted under both supervisors — either
    can approve, and the first to act takes it. -->
</div>

<?php endif; ?>
</div>
</div><!-- /main-content -->

<script>
/* Progressive enhancement. Without JS every View link is an ordinary link that
 * reloads with ?boss= and the server renders the same row — so nothing here is
 * load-bearing. With JS we fetch that one row instead of the whole page.
 *
 * The fetched HTML comes from renderDetail() on the server, so this file never
 * rebuilds that markup in two places. Once loaded a row is kept and toggled
 * rather than refetched. */
(function () {
    'use strict';
    if (!window.fetch || !document.documentElement.closest) { return; }

    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[data-url]');
        if (!link) { return; }
        e.preventDefault();
        toggle(link);
    });

    /* Two kinds of expandable link share this. A superior's View/Hide swaps its
     * own label; a task count must keep its number, so it marks state with a
     * class instead. */
    function isLabelled(link) { return link.hasAttribute('data-boss'); }

    function setOpen(row, link, open) {
        row.classList.toggle('rowopen', open);
        if (isLabelled(link)) { link.textContent = open ? 'Hide' : 'View'; }
        else { link.classList.toggle('open', open); }
    }

    function toggle(link) {
        var row  = link.closest('tr');
        var next = row.nextElementSibling;

        // Already fetched: just show or hide it.
        if (next && next.classList.contains('exprow')) {
            var willOpen = next.hasAttribute('hidden');
            if (willOpen) { next.removeAttribute('hidden'); }
            else { next.setAttribute('hidden', ''); }
            setOpen(row, link, willOpen);
            return;
        }

        if (link.dataset.loading === '1') { return; }
        link.dataset.loading = '1';
        if (isLabelled(link)) { link.textContent = 'Loading…'; }

        fetch(link.getAttribute('data-url'), { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                return res.text();
            })
            .then(function (html) {
                row.insertAdjacentHTML('afterend', html);
                setOpen(row, link, true);
            })
            .catch(function (err) {
                // Fail loud: say so in place, and leave the link retryable.
                var span = link.getAttribute('data-span') || '7';
                row.insertAdjacentHTML('afterend',
                    '<tr class="exprow"><td colspan="' + span + '"><div class="detailbox">' +
                    '<div class="loading">Could not load. ' +
                    String(err.message) + '</div></div></td></tr>');
                setOpen(row, link, true);
                if (isLabelled(link)) { link.textContent = 'Retry'; }
            })
            .then(function () { link.dataset.loading = ''; });
    }
}());
</script>
</body>
</html>