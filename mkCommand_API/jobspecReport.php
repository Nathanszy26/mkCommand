<?php
/**
 * Job Spec approval progress report.
 *
 * MUST be hosted on globportal.com, same folder as jobSpecWrite.php —
 * mkPortal (versions) and staff_portal2 (organization_chart, users) are both
 * local there under prog4. The mkcommand <-> globportal 3306 path is firewalled.
 *
 * Reads job_spec_version directly, NOT the stg_jobspec_* staging tables: those
 * are dropped after an import, and the report must also cover specs submitted
 * through the app.
 *
 * Both schemas live on the SAME mysqld, so one connection with fully-qualified
 * table names serves the whole report. jobSpecWrite.php uses two PDO handles
 * for its own reasons (pooling per logical repository); here a single handle is
 * simpler and lets the boss/version join happen in the database.
 *
 * TWO POPULATIONS, reported separately:
 *   staff (is_gw = 0/NULL) -> superior comes from staff_portal2.organization_chart
 *                             (comp 1: organization_chart_glob — see chartSource)
 *   GW    (is_gw = 1)      -> superior comes from evaluation.monthly_assign_gw
 * They never mix: a GW's `person` is a monthly_assign_gw_code, which is a
 * different namespace from users.person and can collide with one.
 *
 * Query string:
 *   comp_id  int     restrict to one company            (default: all)
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

/* ---------- repository ---------------------------------------------------- */

class ApprovalReportRepository
{
    protected $db;

    /** Live assignment today. Copied verbatim from jobSpecWrite.php so this
     *  report and the app never disagree about who may approve. */
    const ACTIVE_CLAUSE = "
        (oc.monthly_assign_from IS NULL OR oc.monthly_assign_from = ''
            OR STR_TO_DATE(REPLACE(oc.monthly_assign_from, '-', '/'), '%Y/%m/%d') <= CURDATE())
        AND (oc.monthly_assign_to IS NULL OR oc.monthly_assign_to = ''
            OR STR_TO_DATE(REPLACE(oc.monthly_assign_to, '-', '/'), '%Y/%m/%d') >= CURDATE())";

    /** A version is reviewable by a boss only if that boss did not submit it —
     *  mirrors JobSpecWriteService::review()'s SUBMITTER_CANNOT_REVIEW_OWN. */
    const NOT_SUBMITTER = "
        NOT (oc.boss_id = v.submitted_by AND oc.boss_comp_id = v.submitted_by_comp_id)";

    /** A blank boss_id is not a superior. organization_chart holds rows with an
     *  empty boss_id for top-of-chart staff; they pass ACTIVE_CLAUSE, and pass
     *  NOT_SUBMITTER because '' never equals a real submitted_by. Without this
     *  they INNER JOIN into byBoss() and collapse into one phantom superior with
     *  no name and a "no account" badge — while being wrongly excluded from
     *  withoutReviewer(), which is where they belong. */
    const HAS_BOSS = "
        TRIM(IFNULL(oc.boss_id, '')) <> ''";

    /** Comp 1's reporting lines live in organization_chart_glob; every other
     *  company stays in organization_chart. Same rule as staffHierarchy.php and
     *  jobSpecWrite.php: routing is on the STAFF-side comp_id. */
    const GLOB_COMP_ID = 1;

    public function __construct(PDO $db) { $this->db = $db; }

    /**
     * Query: the chart to read, always aliased `oc` so ACTIVE_CLAUSE and
     * NOT_SUBMITTER above apply verbatim and are written once.
     *
     * A company filter pins the staff-side comp, so exactly one chart is read
     * and the join keeps its indexes. Unfiltered, the report spans companies on
     * both charts, so the two are UNIONed on disjoint scopes — which can never
     * double-count, and ignores a comp-1 row left in the old table mid-migration
     * instead of duplicating it. The union sits INSIDE the join source, not at
     * the top level: byBoss() groups by boss, and a boss with staff in comp 1
     * and elsewhere would otherwise split into two half-counted rows.
     */
    protected static function chartSource($compId)
    {
        if ($compId !== null) {
            return ((int)$compId === self::GLOB_COMP_ID)
                ? 'staff_portal2.organization_chart_glob oc'
                : 'staff_portal2.organization_chart oc';
        }
        return "(SELECT staff_id, comp_id, boss_id, boss_comp_id,
                        monthly_assign_from, monthly_assign_to
                 FROM staff_portal2.organization_chart
                 WHERE comp_id <> " . self::GLOB_COMP_ID . "
                 UNION ALL
                 SELECT staff_id, comp_id, boss_id, boss_comp_id,
                        monthly_assign_from, monthly_assign_to
                 FROM staff_portal2.organization_chart_glob
                 WHERE comp_id = " . self::GLOB_COMP_ID . ") oc";
    }

    /** Build the company + population predicate and its bindings.
     *  Shared by every query here and by the GW subclass (DRY).
     *  $isGw: 0 = staff (is_gw NULL or 0), 1 = GW. Legacy rows predate the
     *  column and are NULL, so IFNULL keeps them on the staff side. */
    protected function scopeSql($compId, $isGw = 0)
    {
        $sql = " WHERE IFNULL(v.is_gw, 0) = :isgw ";
        $args = array(':isgw' => (int)$isGw);
        if ($compId !== null) {
            $sql .= " AND v.comp_id = :comp ";
            $args[':comp'] = (int)$compId;
        }
        return array($sql, $args);
    }

    protected function run($sql, array $args)
    {
        $stmt = $this->db->prepare($sql);
        foreach ($args as $k => $vv) {
            $stmt->bindValue($k, $vv, is_int($vv) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Query: overall counts by status. */
    public function totals($compId)
    {
        list($where, $args) = $this->scopeSql($compId);
        $sql = "SELECT v.status, COUNT(*) AS n
                FROM mkPortal.job_spec_version v
                {$where}
                GROUP BY v.status";
        $out = array('pending' => 0, 'approved' => 0, 'rejected' => 0);
        foreach ($this->run($sql, $args) as $r) {
            $out[$r['status']] = (int)$r['n'];
        }
        return $out;
    }

    /** Query: one row per superior who owes a review, newest-pending first. */
    public function byBoss($compId)
    {
        list($where, $args) = $this->scopeSql($compId);
        $sql = "SELECT oc.boss_id, oc.boss_comp_id,
                       COALESCE(ub.fullname, oc.boss_id) AS boss_name,
                       ub.person IS NULL AS boss_missing,
                       COUNT(DISTINCT CASE WHEN v.status = 'pending'  THEN v.id END) AS pending,
                       COUNT(DISTINCT CASE WHEN v.status = 'approved' THEN v.id END) AS approved,
                       COUNT(DISTINCT CASE WHEN v.status = 'rejected' THEN v.id END) AS rejected
                FROM mkPortal.job_spec_version v
                INNER JOIN " . self::chartSource($compId) . "
                    ON oc.staff_id = v.person AND oc.comp_id = v.comp_id
                   AND " . self::ACTIVE_CLAUSE . "
                   AND " . self::NOT_SUBMITTER . "
                   AND " . self::HAS_BOSS . "
                LEFT JOIN staff_portal2.users ub
                    ON ub.person = oc.boss_id AND ub.comp_id = oc.boss_comp_id
                   AND ub.status = 1 AND IFNULL(ub.deleted, 0) = 0
                {$where}
                GROUP BY oc.boss_id, oc.boss_comp_id, ub.fullname, ub.person
                ORDER BY boss_name ASC";
        return $this->run($sql, $args);
    }

    /** Query: the staff behind one superior's row. */
    public function staffForBoss($compId, $bossId)
    {
        list($where, $args) = $this->scopeSql($compId);
        $args[':boss'] = $bossId;
        $sql = "SELECT v.id AS version_id, v.person, v.comp_id, v.status,
                       v.submitted_at, v.reviewed_at, v.reviewed_by,
                       COALESCE(us.fullname, v.person) AS staff_name,
                       (SELECT COUNT(*) FROM mkPortal.job_spec_version_item vi
                        WHERE vi.version_id = v.id) AS items
                FROM mkPortal.job_spec_version v
                INNER JOIN " . self::chartSource($compId) . "
                    ON oc.staff_id = v.person AND oc.comp_id = v.comp_id
                   AND " . self::ACTIVE_CLAUSE . "
                   AND " . self::NOT_SUBMITTER . "
                   AND " . self::HAS_BOSS . "
                LEFT JOIN staff_portal2.users us
                    ON us.person = v.person AND us.comp_id = v.comp_id
                {$where}
                  AND oc.boss_id = :boss
                GROUP BY v.id, v.person, v.comp_id, v.status,
                         v.reviewed_at, v.reviewed_by, us.fullname
                ORDER BY FIELD(v.status,'pending','rejected','approved'), staff_name";
        return $this->run($sql, $args);
    }

    /** Query: versions with no eligible reviewer — no live direct superior
     *  other than whoever submitted it.
     *
     *  Deliberately NOT limited to pending. An auto-approved version lands here
     *  too: jobSpecWrite.php approves on submit precisely when the submitter is
     *  the only possible approver ("Auto-approved (sole approver)"). Those rows
     *  are also invisible in byBoss(), which INNER JOINs the same condition — so
     *  without this list the status tiles would not reconcile with the table.
     *
     *  HAS_BOSS is applied here too, and must stay in step with byBoss(): a
     *  staff member whose only chart row has a blank boss_id has no reviewer,
     *  so the NOT EXISTS has to ignore that row rather than treat it as cover. */
    public function withoutReviewer($compId)
    {
        list($where, $args) = $this->scopeSql($compId);
        $sql = "SELECT v.id AS version_id, v.person, v.comp_id, v.status,
                       v.submitted_at, v.submitted_by, v.reviewed_at, v.reviewed_by,
                       COALESCE(us.fullname, v.person) AS staff_name,
                       (SELECT COUNT(*) FROM mkPortal.job_spec_version_item vi
                        WHERE vi.version_id = v.id) AS items
                FROM mkPortal.job_spec_version v
                LEFT JOIN staff_portal2.users us
                    ON us.person = v.person AND us.comp_id = v.comp_id
                {$where}
                  AND NOT EXISTS (
                      SELECT 1 FROM " . self::chartSource($compId) . "
                      WHERE oc.staff_id = v.person AND oc.comp_id = v.comp_id
                        AND " . self::ACTIVE_CLAUSE . "
                        AND " . self::NOT_SUBMITTER . "
                        AND " . self::HAS_BOSS . ")
                ORDER BY FIELD(v.status,'pending','rejected','approved'), staff_name";
        return $this->run($sql, $args);
    }

    /** Query: the task list of one version, in display order.
     *  Not population-specific — job_spec_version_item is keyed on version_id
     *  alone, so staff and GW share this. */
    public function itemsFor($versionId)
    {
        $sql = "SELECT sort_order, category, task
                FROM mkPortal.job_spec_version_item
                WHERE version_id = :v
                ORDER BY sort_order ASC, id ASC";
        return $this->run($sql, array(':v' => (int)$versionId));
    }

    /** Query: companies present in the data, for the filter.
     *  Driven from job_spec_version, not subsidiaries, so a comp_id with no
     *  subsidiaries row still appears. display_name is not reliably populated,
     *  hence the fallback chain down to a plain id label. */
    public function companies()
    {
        $sql = "SELECT c.comp_id,
                       COALESCE(NULLIF(TRIM(s.display_name), ''),
                                NULLIF(TRIM(s.name), ''),
                                NULLIF(TRIM(s.code), ''),
                                CONCAT('Company ', c.comp_id)) AS display_name
                FROM (SELECT DISTINCT comp_id FROM mkPortal.job_spec_version) c
                LEFT JOIN staff_portal2.subsidiaries s ON s.id = c.comp_id
                ORDER BY display_name";
        return $this->run($sql, array());
    }
}

/* ---------- GW repository ------------------------------------------------- */

/**
 * The same report, for the GW population.
 *
 * Identical question, different reporting line: a GW's superior is
 * (boss_id, boss_comp_id) on evaluation.monthly_assign_gw, not a row in
 * organization_chart. Extends the staff repository purely to reuse scopeSql()
 * and run(); every query below is its own, and nothing here touches the staff
 * side's results. chartSource() is inherited but never called — GW never read
 * an organization chart, so the comp-1 split does not apply to them.
 *
 * evaluation is on this same mysqld, so a fully-qualified name reaches it on
 * the shared connection — same approach staffHierarchy.php uses. Requires
 * SELECT on `evaluation`.
 *
 * monthly_assign_gw stores comp_id and boss_comp_id as VARCHAR while
 * job_spec_version stores them as INT, so the int side is CAST to CHAR. Casting
 * that way round leaves the varchar column bare and keeps its index usable.
 */
class GwApprovalReportRepository extends ApprovalReportRepository
{
    /** The live GW population — matches GwRepository in jobSpecWrite.php and
     *  staffHierarchy.php, so all three agree on who exists. */
    const GW_ACTIVE_CLAUSE = "
        g.monthly_assign_gw_status = '1'
        AND (g.kpi_scorecard_exclude IS NULL OR g.kpi_scorecard_exclude <> '1')";

    /** Mirrors SUBMITTER_CANNOT_REVIEW_OWN for the GW reporting line. */
    const GW_NOT_SUBMITTER = "
        NOT (g.boss_id = v.submitted_by
             AND g.boss_comp_id = CAST(v.submitted_by_comp_id AS CHAR))";

    /** As HAS_BOSS, for the GW reporting line: an assignment row with a blank
     *  boss_id names no supervisor, so it must not stand in for one. */
    const GW_HAS_BOSS = "
        TRIM(IFNULL(g.boss_id, '')) <> ''";

    /** A version belongs to the live GW population at all.
     *
     *  GW_ACTIVE_CLAUSE only bites where monthly_assign_gw is joined, so before
     *  this predicate an excluded GW (kpi_scorecard_exclude = '1') still counted
     *  in the tiles and surfaced in the "no reviewer" card. Both now drop them,
     *  and the whole GW section counts exactly the population staffHierarchy.php
     *  and manpower_summary.php count.
     *
     *  Correlated on v, so it applies to queries that never touch the chart. */
    const GW_IN_POPULATION = "
        EXISTS (SELECT 1 FROM evaluation.monthly_assign_gw gp
                WHERE gp.monthly_assign_gw_code = v.person
                  AND gp.comp_id = CAST(v.comp_id AS CHAR)
                  AND gp.monthly_assign_gw_status = '1'
                  AND (gp.kpi_scorecard_exclude IS NULL
                       OR gp.kpi_scorecard_exclude <> '1'))";

    /** Join predicate from a version to its GW record. */
    const GW_JOIN = "
        g.monthly_assign_gw_code = v.person
        AND g.comp_id = CAST(v.comp_id AS CHAR)";

    /** The GW flavour of scopeSql(): company + population, one definition,
     *  used by every query below so the tiles, the table and the no-reviewer
     *  card can never disagree about who exists. */
    private function gwScope($compId)
    {
        list($sql, $args) = $this->scopeSql($compId, 1);
        return array($sql . " AND " . self::GW_IN_POPULATION . " ", $args);
    }

    public function totals($compId)
    {
        list($where, $args) = $this->gwScope($compId);
        $sql = "SELECT v.status, COUNT(*) AS n
                FROM mkPortal.job_spec_version v
                {$where}
                GROUP BY v.status";
        $out = array('pending' => 0, 'approved' => 0, 'rejected' => 0);
        foreach ($this->run($sql, $args) as $r) {
            $out[$r['status']] = (int)$r['n'];
        }
        return $out;
    }

    /** Query: one row per GW supervisor who owes a review.
     *  A GW with two live assignment rows is counted under BOTH supervisors —
     *  either may approve, and the first to act takes it. */
    public function byBoss($compId)
    {
        list($where, $args) = $this->gwScope($compId);
        $sql = "SELECT g.boss_id, g.boss_comp_id,
                       COALESCE(ub.fullname, g.boss_id) AS boss_name,
                       ub.person IS NULL AS boss_missing,
                       COUNT(DISTINCT CASE WHEN v.status = 'pending'  THEN v.id END) AS pending,
                       COUNT(DISTINCT CASE WHEN v.status = 'approved' THEN v.id END) AS approved,
                       COUNT(DISTINCT CASE WHEN v.status = 'rejected' THEN v.id END) AS rejected
                FROM mkPortal.job_spec_version v
                INNER JOIN evaluation.monthly_assign_gw g
                    ON " . self::GW_JOIN . "
                   AND " . self::GW_ACTIVE_CLAUSE . "
                   AND " . self::GW_NOT_SUBMITTER . "
                   AND " . self::GW_HAS_BOSS . "
                LEFT JOIN staff_portal2.users ub
                    ON ub.person = g.boss_id AND ub.comp_id = g.boss_comp_id
                   AND ub.status = 1 AND IFNULL(ub.deleted, 0) = 0
                {$where}
                GROUP BY g.boss_id, g.boss_comp_id, ub.fullname, ub.person
                ORDER BY boss_name ASC";
        return $this->run($sql, $args);
    }

    /** Query: the GW behind one supervisor's row. Name comes from
     *  monthly_assign_gw — a GW has no users record to fall back on. */
    public function staffForBoss($compId, $bossId)
    {
        list($where, $args) = $this->gwScope($compId);
        $args[':boss'] = $bossId;
        $sql = "SELECT v.id AS version_id, v.person, v.comp_id, v.status,
                       v.submitted_at, v.reviewed_at, v.reviewed_by,
                       COALESCE(MAX(g.monthly_assign_gw_fullname), v.person) AS staff_name,
                       MAX(g.monthly_assign_gw_district) AS district,
                       (SELECT COUNT(*) FROM mkPortal.job_spec_version_item vi
                        WHERE vi.version_id = v.id) AS items
                FROM mkPortal.job_spec_version v
                INNER JOIN evaluation.monthly_assign_gw g
                    ON " . self::GW_JOIN . "
                   AND " . self::GW_ACTIVE_CLAUSE . "
                   AND " . self::GW_NOT_SUBMITTER . "
                   AND " . self::GW_HAS_BOSS . "
                {$where}
                  AND g.boss_id = :boss
                GROUP BY v.id, v.person, v.comp_id, v.status,
                         v.submitted_at, v.reviewed_at, v.reviewed_by
                ORDER BY FIELD(v.status,'pending','rejected','approved'), staff_name";
        return $this->run($sql, $args);
    }

    /** Query: GW versions with no eligible reviewer. A blank boss_id, a
     *  supervisor with no users account, or a version the supervisor submitted
     *  themselves (auto-approved) all land here. Same reasoning as the staff
     *  version: every status, so the tiles reconcile with the table.
     *
     *  A GW outside the live population no longer lands here — gwScope() drops
     *  them from this query and from totals() together, so the tiles still
     *  reconcile. */
    public function withoutReviewer($compId)
    {
        list($where, $args) = $this->gwScope($compId);
        $sql = "SELECT v.id AS version_id, v.person, v.comp_id, v.status,
                       v.submitted_at, v.submitted_by, v.reviewed_at, v.reviewed_by,
                       COALESCE((SELECT MAX(g2.monthly_assign_gw_fullname)
                                 FROM evaluation.monthly_assign_gw g2
                                 WHERE g2.monthly_assign_gw_code = v.person
                                   AND g2.comp_id = CAST(v.comp_id AS CHAR)),
                                v.person) AS staff_name,
                       (SELECT COUNT(*) FROM mkPortal.job_spec_version_item vi
                        WHERE vi.version_id = v.id) AS items
                FROM mkPortal.job_spec_version v
                {$where}
                  AND NOT EXISTS (
                      SELECT 1 FROM evaluation.monthly_assign_gw g
                      INNER JOIN staff_portal2.users ub
                          ON ub.person = g.boss_id AND ub.comp_id = g.boss_comp_id
                         AND ub.status = 1 AND IFNULL(ub.deleted, 0) = 0
                      WHERE " . self::GW_JOIN . "
                        AND " . self::GW_ACTIVE_CLAUSE . "
                        AND " . self::GW_NOT_SUBMITTER . "
                        AND " . self::GW_HAS_BOSS . ")
                ORDER BY FIELD(v.status,'pending','rejected','approved'), staff_name";
        return $this->run($sql, $args);
    }
}

/* ---------- view helpers -------------------------------------------------- */

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** "SOO ZHAO YUAN NATHAN" / "soo zhao yuan nathan" -> "Soo Zhao Yuan Nathan".
 *  Capitalises the first letter of every word. \p{L} with /u keeps accented
 *  letters intact, and the word boundary means "Mohd.Yuzaiman" and
 *  "Chu Chee Cheng @ Mohd.Yuzaiman" both come out right. */
function titleCase($value)
{
    $value = trim((string)$value);
    if ($value === '') { return ''; }
    // strtolower/strtoupper rather than the mb_* pair: mbstring is not
    // guaranteed on this host, and these only touch A-Z bytes, so a UTF-8
    // multibyte sequence passes through untouched instead of being corrupted.
    return preg_replace_callback(
        '/\b\p{L}/u',
        function ($m) { return strtoupper($m[0]); },
        strtolower($value)
    );
}

/** Display a person's name, but leave a bare login/code alone.
 *  Both name columns COALESCE down to the id when there is no record to read a
 *  full name from — title-casing that would turn "sooCK" into "Soock". */
function displayName($name, $code)
{
    return ((string)$name === (string)$code) ? (string)$name : titleCase($name);
}

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

/** Query: $companies with $compId guaranteed present. companies() is driven by
 *  job_spec_version, so a company with no versions yet is missing from it — and
 *  the select would then render "All companies" while the data is filtered to
 *  that company. $name is the session's company_name, from the same
 *  users -> subsidiaries join that produced comp_id. */
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

/** Command: echo one superior's versions as a nested table, inline beneath
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

    if (empty($rows)) {
        $inner = $isGw ? 7 : 6;
        echo '<tr><td colspan="' . $inner . '" class="empty">No versions for this superior.</td></tr>';
    }
    $n = 0;
    foreach ($rows as $d) {
        $n++;
        echo '<tr>';
        echo '<td class="idx">' . $n . '</td>';
        echo '<td>' . h(displayName($d['staff_name'], $d['person']))
           . '<div class="muted">' . h($d['person']) . '</div></td>';
        if ($isGw) {
            echo '<td class="muted">' . h(!empty($d['district']) ? $d['district'] : '—') . '</td>';
        }
        echo '<td><span class="pill ' . h($d['status']) . '">' . h($d['status']) . '</span></td>';
        $inner = $isGw ? 7 : 6;
        if ((int)$d['items'] > 0) {
            echo '<td class="num"><a class="tasklink" href="' . h(taskUrl($d['version_id'])) . '"'
               . ' data-url="' . h(taskUrl($d['version_id'])) . '"'
               . ' data-span="' . $inner . '">' . (int)$d['items'] . '</a></td>';
        } else {
            echo '<td class="num">0</td>';
        }
        echo '<td>' . h(fmtDate($d['reviewed_at'])) . '</td>';
        echo '<td class="muted">' . h($d['reviewed_by'] ? $d['reviewed_by'] : '—') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div></td></tr>';
}

/** Command: render the "no reviewer" card for one population. Placed under
 *  that population's table so the two together account for every version in
 *  the tiles. Warning styling only when something is still pending — an
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
        <strong><?php echo count($rows); ?> Staff(s) with no Superior</strong>
        <table style="margin-top:10px">
            <thead>
            <tr><th class="idx">#</th><th><?php echo h($who); ?></th>
                <th>Status</th><th class="num">Tasks</th></tr>
            </thead>
            <tbody>
            <?php $n = 0; foreach ($rows as $o): $n++; ?>
            <tr>
                <td class="idx"><?php echo $n; ?></td>
                <td><?php echo h(displayName($o['staff_name'], $o['person'])); ?>
                    <div class="muted"><?php echo h($o['person']); ?></div></td>
                <td><span class="pill <?php echo h($o['status']); ?>"><?php echo h($o['status']); ?></span></td>
                <td class="num"><?php echo (int)$o['items']; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/* ---------- controller ---------------------------------------------------- */

$error = null;
$totals = array('pending' => 0, 'approved' => 0, 'rejected' => 0);
$bosses = array();
$noReviewer = array();
$companies = array();
$detail = array();

$gwTotals  = array('pending' => 0, 'approved' => 0, 'rejected' => 0);
$gwBosses     = array();
$gwNoReviewer = array();
$gwDetail  = array();

/** The company the report opens on: the viewer's own, straight off the session.
 *  UnifiedAuth joins users -> subsidiaries at login and stores comp_id
 *  (subsidiaries.id) with the rest of the session data, so there is nothing to
 *  look up here.
 *
 *  Absent comp_id in the query string (a bare first load) falls back to that;
 *  an EXPLICITLY empty comp_id means "all companies". The two must stay
 *  distinguishable, or picking All companies would bounce straight back to the
 *  default and the option would be unreachable. */
$defaultCompId = isset($currentUser['comp_id']) && $currentUser['comp_id'] !== ''
    ? (int)$currentUser['comp_id']
    : null;

if (!isset($_GET['comp_id'])) {
    $compId = $defaultCompId;
} elseif ($_GET['comp_id'] === '') {
    $compId = null;
} else {
    $compId = (int)$_GET['comp_id'];
}
$boss   = (isset($_GET['boss']) && $_GET['boss'] !== '') ? trim($_GET['boss']) : null;
$gwBoss = (isset($_GET['gwboss']) && $_GET['gwboss'] !== '') ? trim($_GET['gwboss']) : null;

/* AJAX: return just the detail row for one superior. Uses the SAME
 * renderDetail() as the full page, so there is one definition of that markup
 * and the JS never has to rebuild it. Everything below this block is skipped. */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'tasks') {
    header('Content-Type: text/html; charset=UTF-8');
    $ajaxVersion = isset($_GET['version']) ? (int)$_GET['version'] : 0;
    try {
        if ($ajaxVersion <= 0) {
            throw new RuntimeException('Missing version');
        }
        $ajaxRepo = new ApprovalReportRepository(Db::get());
        renderTasks($ajaxRepo->itemsFor($ajaxVersion), 7, 'v-' . $ajaxVersion);
    } catch (Exception $e) {
        error_log('jobSpecApprovalReport ajax tasks: ' . $e->getMessage());
        http_response_code(500);
        echo '<tr class="taskrow exprow"><td colspan="7"><div class="taskbox">'
           . '<div class="empty">Could not load these tasks.</div></div></td></tr>';
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'detail') {
    header('Content-Type: text/html; charset=UTF-8');
    $ajaxIsGw = (isset($_GET['is_gw']) && (int)$_GET['is_gw'] === 1);
    $ajaxBoss = isset($_GET['for']) ? trim($_GET['for']) : '';
    try {
        if ($ajaxBoss === '') {
            throw new RuntimeException('Missing superior');
        }
        $ajaxRepo = $ajaxIsGw
            ? new GwApprovalReportRepository(Db::get())
            : new ApprovalReportRepository(Db::get());
        renderDetail($ajaxRepo->staffForBoss($compId, $ajaxBoss), $ajaxIsGw, 7,
                     detailAnchor($ajaxIsGw, $ajaxBoss));
    } catch (Exception $e) {
        error_log('jobSpecApprovalReport ajax: ' . $e->getMessage());
        http_response_code(500);
        echo '<tr class="detailrow"><td colspan="7"><div class="detailbox">'
           . '<div class="empty">Could not load this list.</div></div></td></tr>';
    }
    exit;
}

try {
    $repo      = new ApprovalReportRepository(Db::get());
    $companies = withOwnCompany(
        $repo->companies(),
        $defaultCompId,
        isset($currentUser['company_name']) ? $currentUser['company_name'] : null
    );
    $totals    = $repo->totals($compId);
    $bosses    = $repo->byBoss($compId);
    $noReviewer = $repo->withoutReviewer($compId);
    if ($boss !== null) {
        $detail = $repo->staffForBoss($compId, $boss);
    }

    $gwRepo    = new GwApprovalReportRepository(Db::get());
    $gwTotals  = $gwRepo->totals($compId);
    $gwBosses  = $gwRepo->byBoss($compId);
    $gwNoReviewer = $gwRepo->withoutReviewer($compId);
    if ($gwBoss !== null) {
        $gwDetail = $gwRepo->staffForBoss($compId, $gwBoss);
    }
} catch (Exception $e) {
    error_log('jobSpecApprovalReport: ' . $e->getMessage());
    $error = 'Could not load the report. Check the error log.';
}

$totalVersions = $totals['pending'] + $totals['approved'] + $totals['rejected'];
$done          = $totals['approved'] + $totals['rejected'];

$gwTotalVersions = $gwTotals['pending'] + $gwTotals['approved'] + $gwTotals['rejected'];
$gwDone          = $gwTotals['approved'] + $gwTotals['rejected'];
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
</div>

<div class="tiles">
    <div class="tile"><div class="n pending"><?php echo (int)$totals['pending']; ?></div>
        <div class="l">Awaiting approval</div></div>
    <div class="tile"><div class="n approved"><?php echo (int)$totals['approved']; ?></div>
        <div class="l">Approved</div></div>
    <div class="tile"><div class="n rejected"><?php echo (int)$totals['rejected']; ?></div>
        <div class="l">Rejected</div></div>
    <div class="tile"><div class="n"><?php echo pct($done, $totalVersions); ?>%</div>
        <div class="l"><?php echo (int)$done; ?> of <?php echo (int)$totalVersions; ?> actioned</div></div>
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
            <th>Progress</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($bosses)): ?>
            <tr><td colspan="7" class="empty">Nothing to show for this filter.</td></tr>
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
            <td>
                <div class="bar"><i style="width:<?php echo pct($dn, $tot); ?>%"></i></div>
                <div class="muted"><?php echo $dn; ?>/<?php echo $tot; ?></div>
            </td>
            <td class="num">
                <a href="<?php echo h(selfUrl(array('boss' => $isOpen ? null : $b['boss_id']))); ?>#<?php echo h(detailAnchor(false, $b['boss_id'])); ?>"
                   data-boss="<?php echo h($b['boss_id']); ?>"
                   data-span="7"
                   data-url="<?php echo h(selfUrl(array('ajax' => 'detail', 'is_gw' => 0, 'for' => $b['boss_id'], 'boss' => null, 'gwboss' => null))); ?>">
                    <?php echo $isOpen ? 'Hide' : 'View'; ?>
                </a>
            </td>
        </tr>
        <?php if ($isOpen) { renderDetail($detail, false, 7, detailAnchor(false, $b['boss_id'])); } ?>
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
    <div class="tile"><div class="n pending"><?php echo (int)$gwTotals['pending']; ?></div>
        <div class="l">Awaiting approval</div></div>
    <div class="tile"><div class="n approved"><?php echo (int)$gwTotals['approved']; ?></div>
        <div class="l">Approved</div></div>
    <div class="tile"><div class="n rejected"><?php echo (int)$gwTotals['rejected']; ?></div>
        <div class="l">Rejected</div></div>
    <div class="tile"><div class="n"><?php echo pct($gwDone, $gwTotalVersions); ?>%</div>
        <div class="l"><?php echo (int)$gwDone; ?> of <?php echo (int)$gwTotalVersions; ?> actioned</div></div>
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
            <th>Progress</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($gwBosses)): ?>
            <tr><td colspan="7" class="empty">No GW job specs for this filter.</td></tr>
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
            <td>
                <div class="bar"><i style="width:<?php echo pct($dn, $tot); ?>%"></i></div>
                <div class="muted"><?php echo $dn; ?>/<?php echo $tot; ?></div>
            </td>
            <td class="num">
                <a href="<?php echo h(selfUrl(array('gwboss' => $isOpen ? null : $b['boss_id']))); ?>#<?php echo h(detailAnchor(true, $b['boss_id'])); ?>"
                   data-boss="<?php echo h($b['boss_id']); ?>"
                   data-span="7"
                   data-url="<?php echo h(selfUrl(array('ajax' => 'detail', 'is_gw' => 1, 'for' => $b['boss_id'], 'boss' => null, 'gwboss' => null))); ?>">
                    <?php echo $isOpen ? 'Hide' : 'View'; ?>
                </a>
            </td>
        </tr>
        <?php if ($isOpen) { renderDetail($gwDetail, true, 7, detailAnchor(true, $b['boss_id'])); } ?>
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