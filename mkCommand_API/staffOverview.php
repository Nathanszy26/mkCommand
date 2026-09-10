<?php
/**
 * MK Command — Staff Overview (admin / HR).
 *
 * The mobile app answers "what is MY MK Command record?" one staff at a time.
 * This page answers the HR question: "show me everybody, and let me open any
 * one of them and read the same five sections the app would show them."
 *
 *   Staff          staff_portal2.users + organization_chart (reporting line)
 *   Job Spec       mkPortal.job_spec_version (+ _item, _item_app)
 *   App            application.application via owner / developer / user
 *   Memo           staff_profile.staff_memo (+ attachments)
 *   Merit/Demerit  staff_profile.staff_performance (+ attachments)
 *
 * MUST be hosted on globportal.com, same folder as jobspecReport.php — every
 * schema above is local there under prog4, on one mysqld, so a single PDO with
 * fully-qualified table names serves the whole page.
 *
 * ACCESS — mkPortal.mk_command_access, keyed on (person, comp_id):
 *   status              '1' or the row does not count. No row at all = no page.
 *   access              'admin' | 'hr'. Both open this page; the badge shows
 *                       which, so the column is ready if the two ever diverge.
 *   all_companies '1'   every weekly-reporting subsidiary
 *                       (subsidiaries.weekly_report_status = 1).
 *   assigned_companies  otherwise, a CSV of comp_ids ("1,2,3,4" or "3"). Named
 *                       explicitly, a company is covered whether or not it
 *                       reports weekly — this is how Eaton or Ming Kiang are
 *                       reached. Blank with all_companies '0' falls back to the
 *                       grantee's OWN company — never to everybody.
 *   assigned_categories CSV of the sections above (staff, jobspec, app, memo,
 *                       merit). Blank or 'all' means all five. Whatever is not
 *                       granted is absent from the list columns AND from the
 *                       detail page, not merely hidden in the markup.
 *
 * POPULATION — the same one MK Command itself shows: active, undeleted users
 * with mk_command_excluded <> 1. General Workers are deliberately NOT here:
 * a GW is a monthly_assign_gw code, has no users.id, and therefore has no memo,
 * merit or app record to read — four of the five sections would be empty by
 * construction. GW job specs are reported by jobspecReport.php instead.
 *
 * Read-only. Every query in this file is a SELECT.
 *
 * Query string:
 *   comp_id  int     restrict the directory to one company. Absent means the
 *                    VIEWER'S OWN company, which is what the page opens on;
 *                    'all' is the explicit every-granted-company opt-out.
 *   q        string  search name / login / department / position
 *   sort     string  name | pending | demerits | memos
 *   person   string  open one staff's detail page — with scomp, below
 *   scomp    int     that staff's comp_id. Separate from comp_id so the
 *                    directory filter survives a trip into a detail page and
 *                    back out again.
 *   year     int     Merit/Demerit year, on the directory AND the detail
 *                    page. Absent means the CURRENT year, which is what both
 *                    open on; 'all' is the explicit every-year opt-out.
 *
 * PHP 7.2 compatible.
 */

session_start();

/**
 * A fatal raised while the markup is being written truncates the response
 * mid-tag. The browser then shows a half-built page carrying no error at all,
 * which is indistinguishable from "this staff list is empty" — so say it out
 * loud instead, and log it.
 *
 * The leading `">` closes whatever attribute the output died inside; on a page
 * that did not die it would be two stray characters, but this only ever runs
 * once the page has already failed.
 */
register_shutdown_function(function () {
    $fatal = error_get_last();
    $kinds = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    if ($fatal === null || !in_array($fatal['type'], $kinds, true)) {
        return;
    }

    error_log('mkCommand staffOverview fatal: ' . $fatal['message']
            . ' at ' . $fatal['file'] . ':' . $fatal['line']);

    // Same debug=1 convention staffRecords.php uses: the detail is one query
    // string away for the admin looking at it, and never on by default.
    $detail = (isset($_GET['debug']) && $_GET['debug'] === '1')
        ? $fatal['message'] . ' (' . basename($fatal['file']) . ':' . $fatal['line'] . ')'
        : 'The error log has the detail — or add &debug=1 to this url to see it here.';

    echo '">' . "\n</td></tr></tbody></table></div>\n"
       . '<div class="card warnbox" style="margin:16px">'
       . '<strong>This page stopped part-way through.</strong>'
       . '<div class="muted" style="margin-top:6px">'
       . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8')
       . '</div></div>';
});

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

    /** One shared PDO. Every schema this page reads is on this host. */
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

/** Shared bits of every repository below: bind-and-run, and IN() lists. */
abstract class Repository
{
    protected $db;

    public function __construct(PDO $db) { $this->db = $db; }

    protected function run($sql, array $args = array())
    {
        $stmt = $this->db->prepare($sql);
        foreach ($args as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Query: an inlined integer IN() list.
     *
     * Inlined rather than bound because these are comp_ids and users.id values
     * this file produced itself — every one of them has been through (int) — and
     * a bound list would mean a placeholder count that varies per request, which
     * defeats the statement cache for no gain in safety.
     */
    protected static function intList(array $ids)
    {
        $ints = array_map('intval', $ids);
        return empty($ints) ? '-1' : implode(',', array_unique($ints));
    }
}

/* ---------- access -------------------------------------------------------- */

/** The five sections, in the order they appear everywhere on this page. */
$SECTIONS = array(
    'staff'   => 'Staff',
    'jobspec' => 'Job Spec',
    'app'     => 'App',
    'memo'    => 'Memo',
    'merit'   => 'Merit / Demerit',
);

/**
 * What assigned_categories may be spelled as. The column has no comment and no
 * rows to learn from yet, so every reasonable spelling of a section maps to its
 * canonical key rather than silently granting nothing.
 */
$SECTION_ALIASES = array(
    'staff' => 'staff', 'profile' => 'staff', 'hierarchy' => 'staff',
    'jobspec' => 'jobspec', 'job_spec' => 'jobspec', 'job spec' => 'jobspec',
    'jobspecs' => 'jobspec', 'spec' => 'jobspec',
    'app' => 'app', 'apps' => 'app', 'application' => 'app', 'applications' => 'app',
    'memo' => 'memo', 'memos' => 'memo',
    'merit' => 'merit', 'demerit' => 'merit', 'merit/demerit' => 'merit',
    'merit_demerit' => 'merit', 'meritdemerit' => 'merit', 'performance' => 'merit',
);

class AccessRepository extends Repository
{
    /**
     * Query: this viewer's live grant, or null.
     *
     * person is matched case-insensitively — the session carries whatever the
     * login form was typed as, while the grant is typed by hand into a config
     * page. comp_id is part of the identity: (person, comp_id) is what
     * identifies a user everywhere else in MK Command.
     */
    public function grantFor($person, $compId)
    {
        $rows = $this->run(
            "SELECT person, comp_id, all_companies, assigned_companies,
                    assigned_categories, access
             FROM mkPortal.mk_command_access
             WHERE LOWER(TRIM(person)) = LOWER(TRIM(:p))
               AND comp_id = :c
               AND status = '1'
             LIMIT 1",
            array(':p' => (string)$person, ':c' => (int)$compId)
        );
        return empty($rows) ? null : $rows[0];
    }

    /**
     * Query: every subsidiary, flagged with whether it is in the group this page
     * covers by default.
     *
     * That group is weekly_report_status — the weekly-reporting companies — not
     * subsidiaries.status. The two disagree in both directions: Eaton and Ming
     * Kiang are status '1' but outside the weekly group, while Elegant Aspire is
     * inside it with status '0'.
     *
     * The whole table is read, not just that group: only the group expands
     * all_companies, but a company named explicitly in assigned_companies is
     * honoured whether or not it reports weekly, and it should then read as
     * "Eaton" rather than "Company 11".
     */
    public function companies()
    {
        return $this->run(
            "SELECT s.id AS comp_id,
                    COALESCE(NULLIF(TRIM(s.name), ''),
                             NULLIF(TRIM(s.code), ''),
                             CONCAT('Company ', s.id)) AS display_name,
                    (s.weekly_report_status = 1) AS is_live
             FROM staff_portal2.subsidiaries s
             ORDER BY display_name"
        );
    }
}

/**
 * One grant, resolved: which companies, which sections.
 *
 * Built once in the controller and consulted everywhere else, so a company or a
 * section can never be enforced in one place and forgotten in another.
 */
class AccessScope
{
    private $role;
    private $compIds;    // int[] — always explicit, never "null means all"
    private $sections;   // canonical key => true

    private function __construct($role, array $compIds, array $sections)
    {
        $this->role     = $role;
        $this->compIds  = $compIds;
        $this->sections = $sections;
    }

    /**
     * $liveCompIds is the full list of live subsidiaries, used to expand
     * all_companies and to drop an assigned company that no longer exists.
     */
    public static function fromGrant(array $grant, array $liveCompIds, array $aliases, array $sectionKeys)
    {
        if ((string)$grant['all_companies'] === '1') {
            $comps = $liveCompIds;
        } else {
            $comps = array();
            foreach (explode(',', (string)$grant['assigned_companies']) as $tok) {
                $tok = trim($tok);
                if ($tok !== '' && ctype_digit($tok)) {
                    $comps[] = (int)$tok;
                }
            }
            // A grant that names no company is a grant over the grantee's own
            // company. Widening it to everybody would turn a mis-typed row into
            // a group-wide leak.
            if (empty($comps)) {
                $comps = array((int)$grant['comp_id']);
            }
        }
        $comps = array_values(array_unique(array_map('intval', $comps)));

        $sections = array();
        $raw = strtolower(trim((string)$grant['assigned_categories']));
        if ($raw === '' || $raw === 'all') {
            foreach ($sectionKeys as $k) { $sections[$k] = true; }
        } else {
            foreach (explode(',', $raw) as $tok) {
                $tok = trim($tok);
                if (isset($aliases[$tok])) {
                    $sections[$aliases[$tok]] = true;
                }
            }
            // Every token was unrecognised: fall back to all five rather than
            // serving a page with no sections on it at all.
            if (empty($sections)) {
                foreach ($sectionKeys as $k) { $sections[$k] = true; }
            }
        }

        return new self((string)$grant['access'], $comps, $sections);
    }

    public function role()             { return $this->role; }
    public function compIds()          { return $this->compIds; }
    public function sees($section)     { return isset($this->sections[$section]); }
    /**
     * Strict on its own terms. Callers already screen the query string, but this
     * is the access predicate: "3x" must not pass as company 3 merely because
     * PHP would cast it that way, no matter who ends up calling it.
     */
    public function coversCompany($id)
    {
        if (is_string($id)) { $id = trim($id); }
        if (!is_int($id) && !(is_string($id) && $id !== '' && ctype_digit($id))) {
            return false;
        }
        return in_array((int)$id, $this->compIds, true);
    }
}

/* ---------- directory ----------------------------------------------------- */

class DirectoryRepository extends Repository
{
    /** A user is visible to MK Command at all — identical to staffHierarchy.php,
     *  so this page and the app never disagree about who exists. */
    const USER_VISIBLE = "u.status = 1 AND IFNULL(u.deleted, 0) = 0
                          AND IFNULL(u.mk_command_excluded, 0) <> 1";

    /** Query: the staff directory for a set of companies, optionally searched. */
    public function staff(array $compIds, $search)
    {
        $args = array();
        $sql = "SELECT u.id, u.person, u.comp_id, u.fullname, u.department, u.position,
                       u.email, u.mobile_no, u.join_date,
                       COALESCE(NULLIF(TRIM(s.name), ''), CONCAT('Company ', u.comp_id)) AS company_name
                FROM staff_portal2.users u
                LEFT JOIN staff_portal2.subsidiaries s ON s.id = u.comp_id
                WHERE u.comp_id IN (" . self::intList($compIds) . ")
                  AND " . self::USER_VISIBLE;

        if ($search !== null && $search !== '') {
            $sql .= " AND (u.fullname LIKE :q OR u.person LIKE :q
                        OR u.department LIKE :q OR u.position LIKE :q)";
            $args[':q'] = '%' . $search . '%';
        }

        $sql .= " ORDER BY u.fullname ASC";
        return $this->run($sql, $args);
    }

    /**
     * Query: each staff member's CURRENT job spec, keyed 'person|comp_id'.
     *
     * The newest version, not a tally of every version. A count answered a
     * question nobody asks — "1 pending" stays true of somebody whose spec was
     * approved months ago and superseded twice since — while what the column is
     * read for is where this person's spec stands today.
     *
     * Newest is MAX(v.id), the same ordering versions() gives the detail page,
     * so the directory row and the staff's own page can never name a different
     * version as the current one.
     *
     * Two round-trips rather than one: the task count has to come from a JOIN on
     * job_spec_version_item, and joining it before the GROUP BY would count
     * items across every version the staff member has. The second query is an
     * IN() on a handful of primary keys.
     *
     * Returns ['person|comp' => ['status' => string|null, 'items' => int]].
     */
    public function latestJobSpecs(array $compIds, $isGw = 0)
    {
        $rows = $this->run(
            "SELECT v.person, v.comp_id, MAX(v.id) AS latest_id
             FROM mkPortal.job_spec_version v
             WHERE IFNULL(v.is_gw, 0) = " . ((int)$isGw ? 1 : 0) . "
               AND v.comp_id IN (" . self::intList($compIds) . ")
             GROUP BY v.person, v.comp_id"
        );
        if (empty($rows)) { return array(); }

        $ids = array();
        foreach ($rows as $r) { $ids[] = (int)$r['latest_id']; }

        $summaries = array();
        foreach ($this->run(
            "SELECT v.id, v.status, COUNT(i.id) AS items
             FROM mkPortal.job_spec_version v
             LEFT JOIN mkPortal.job_spec_version_item i ON i.version_id = v.id
             WHERE v.id IN (" . self::intList($ids) . ")
             GROUP BY v.id, v.status"
        ) as $r) {
            $summaries[(int)$r['id']] = array(
                'status' => (string)$r['status'],
                'items'  => (int)$r['items'],
            );
        }

        $out = array();
        foreach ($rows as $r) {
            $id  = (int)$r['latest_id'];
            $out[$r['person'] . '|' . (int)$r['comp_id']] = isset($summaries[$id])
                ? $summaries[$id]
                : self::NO_JOB_SPEC;
        }
        return $out;
    }

    /** What a staff member with no job spec row at all reads as. */
    const NO_JOB_SPEC = array('status' => null, 'items' => 0);

    /**
     * Query: which applications each staff is attached to.
     *
     * Two populations, because application_user carries a company-wide sentinel:
     * a row with staff_id 'all' means every staff of that comp_id is a user of
     * that app (Leave System, Bulletin Board and the like — 60-odd rows), exactly
     * like staff_memo's 'all' recipient. Counting only the named rows would
     * report "no applications" for most of the group.
     *
     * Returns array('personal' => ['person|comp' => int[]], 'company' => [comp => int[]]) —
     * ids rather than counts, so the caller can union the two without
     * double-counting an app a staff holds personally AND company-wide.
     *
     * comp_id and application_id are VARCHAR on the three role tables and INT on
     * `application`, so the varchar side is CAST — the same direction
     * jobspecReport.php casts, keeping the int column's index usable.
     */
    public function appCounts(array $compIds)
    {
        $scope = self::intList($compIds);

        $personal = array();
        $rows = $this->run(
            "SELECT DISTINCT r.staff_id AS person, CAST(r.comp_id AS UNSIGNED) AS comp_id,
                    a.application_id
             FROM (
                 SELECT application_id, comp_id, staff_id FROM application.application_owner
                 UNION ALL
                 SELECT application_id, comp_id, staff_id FROM application.application_developer
                 UNION ALL
                 SELECT application_id, comp_id, staff_id FROM application.application_user
             ) r
             INNER JOIN application.application a
                 ON a.application_id = CAST(r.application_id AS UNSIGNED)
             WHERE CAST(r.comp_id AS UNSIGNED) IN ({$scope})
               AND TRIM(r.staff_id) <> '' AND LOWER(TRIM(r.staff_id)) <> 'all'"
        );
        foreach ($rows as $r) {
            $personal[$r['person'] . '|' . (int)$r['comp_id']][] = (int)$r['application_id'];
        }

        $company = array();
        $rows = $this->run(
            "SELECT DISTINCT CAST(au.comp_id AS UNSIGNED) AS comp_id, a.application_id
             FROM application.application_user au
             INNER JOIN application.application a
                 ON a.application_id = CAST(au.application_id AS UNSIGNED)
             WHERE CAST(au.comp_id AS UNSIGNED) IN ({$scope})
               AND LOWER(TRIM(au.staff_id)) = 'all'"
        );
        foreach ($rows as $r) {
            $company[(int)$r['comp_id']][] = (int)$r['application_id'];
        }

        return array('personal' => $personal, 'company' => $company);
    }

    /**
     * Query: memo counts, keyed by users.id, plus the broadcast total.
     *
     * staff_memo addresses a CSV of users.id (or the literal 'all'), so there is
     * no per-recipient row to GROUP BY. The whole live table is a few dozen rows,
     * so it is expanded here in one pass rather than run as a FIND_IN_SET per
     * staff member — which would be one query per row of the directory.
     *
     * Returns array('per' => [uid => n], 'broadcast' => n). A staff member's
     * total is per[uid] + broadcast, matching what the app's Memo tab lists.
     */
    public function memoCounts()
    {
        $rows = $this->run(
            "SELECT staff_memo_to_staff_id
             FROM staff_profile.staff_memo
             WHERE staff_memo_deleted = '0'"
        );

        $per = array();
        $broadcast = 0;
        foreach ($rows as $r) {
            $seen = array();
            foreach (explode(',', (string)$r['staff_memo_to_staff_id']) as $tok) {
                $tok = trim($tok);
                if ($tok === '' || isset($seen[$tok])) { continue; }
                $seen[$tok] = true;
                if (strtolower($tok) === 'all') {
                    $broadcast++;
                    continue;
                }
                $uid = (int)$tok;
                $per[$uid] = isset($per[$uid]) ? $per[$uid] + 1 : 1;
            }
        }
        return array('per' => $per, 'broadcast' => $broadcast);
    }

    /**
     * Query: the years these companies have merit/demerit records in, newest
     * first — the options behind the directory's year picker.
     *
     * Driven from the data rather than from a fixed range so the picker never
     * offers a year that would come back empty for everybody. The caller unions
     * the selected year in, since the current year is offered before anyone has
     * earned a record in it.
     */
    public function performanceYears(array $compIds)
    {
        $rows = $this->run(
            "SELECT DISTINCT LEFT(p.staff_performance_date, 4) AS y
             FROM staff_profile.staff_performance p
             INNER JOIN staff_portal2.users u ON u.id = p.staff_performance_staff_id
             WHERE p.staff_performance_deleted = 0
               AND u.comp_id IN (" . self::intList($compIds) . ")
             ORDER BY y DESC"
        );
        $out = array();
        foreach ($rows as $r) {
            if (ctype_digit((string)$r['y'])) { $out[] = $r['y']; }
        }
        return $out;
    }

    /**
     * Query: merit/demerit totals per users.id — record COUNTS as well as points.
     *
     * The counts are the headline, not the points: most rows in this table carry
     * a blank staff_performance_type_points (a warning letter is issued as a
     * record, not as a score), so a points-only column would report "0" for a
     * staff with seven warning letters and read as a clean record.
     *
     * merit_demerit is free text, and points is a varchar, so both are
     * normalised the way the app's Merit/Demerit screen does it: anything
     * containing "demerit" is a demerit, everything else is a merit; a blank
     * points value casts to 0 and simply adds nothing.
     *
     * $year scopes the tally ('' = every year). staff_performance_date is
     * year-first for every row in the table, so a LIKE prefix is the whole
     * filter — the same one performanceFor() applies on the detail page, so the
     * directory row and the staff's own page always report the same numbers.
     */
    public function meritTotals(array $compIds, $year)
    {
        $args = array();
        $yearClause = '';
        if ($year !== '') {
            $yearClause = " AND p.staff_performance_date LIKE :y";
            $args[':y'] = $year . '%';
        }

        $rows = $this->run(
            "SELECT p.staff_performance_staff_id AS uid,
                    SUM(LOWER(p.staff_performance_type_merit_demerit) NOT LIKE '%demerit%') AS merits,
                    SUM(LOWER(p.staff_performance_type_merit_demerit) LIKE '%demerit%')     AS demerits,
                    SUM(CASE WHEN LOWER(p.staff_performance_type_merit_demerit) LIKE '%demerit%'
                             THEN 0 ELSE CAST(p.staff_performance_type_points AS SIGNED) END) AS merit_points,
                    SUM(CASE WHEN LOWER(p.staff_performance_type_merit_demerit) LIKE '%demerit%'
                             THEN CAST(p.staff_performance_type_points AS SIGNED) ELSE 0 END) AS demerit_points
             FROM staff_profile.staff_performance p
             INNER JOIN staff_portal2.users u ON u.id = p.staff_performance_staff_id
             WHERE p.staff_performance_deleted = 0
               AND u.comp_id IN (" . self::intList($compIds) . ")
               {$yearClause}
             GROUP BY p.staff_performance_staff_id",
            $args
        );
        $out = array();
        foreach ($rows as $r) {
            $out[(int)$r['uid']] = array(
                'merits'         => (int)$r['merits'],
                'demerits'       => (int)$r['demerits'],
                'merit_points'   => (int)$r['merit_points'],
                'demerit_points' => (int)$r['demerit_points'],
            );
        }
        return $out;
    }
}

/* ---------- one staff ----------------------------------------------------- */

class StaffRepository extends Repository
{
    /** Live assignment today — copied verbatim from staffHierarchy.php. */
    const ACTIVE_CLAUSE = "
        (oc.monthly_assign_from IS NULL OR oc.monthly_assign_from = ''
            OR STR_TO_DATE(REPLACE(oc.monthly_assign_from, '-', '/'), '%Y/%m/%d') <= CURDATE())
        AND (oc.monthly_assign_to IS NULL OR oc.monthly_assign_to = ''
            OR STR_TO_DATE(REPLACE(oc.monthly_assign_to, '-', '/'), '%Y/%m/%d') >= CURDATE())";

    const USER_VISIBLE = DirectoryRepository::USER_VISIBLE;

    /** Comp 1's reporting lines live in organization_chart_glob; everyone else
     *  stays in organization_chart. Routing is on the STAFF-side comp_id — the
     *  same rule staffHierarchy.php and jobSpecWrite.php apply. */
    const GLOB_COMP_ID = 1;

    /** Query: one staff member, or null. Scope is enforced by the caller. */
    public function profile($person, $compId)
    {
        $rows = $this->run(
            "SELECT u.id, u.person, u.comp_id, u.fullname, u.department, u.position,
                    u.email, u.personal_email, u.mobile_no, u.office_no, u.district,
                    u.join_date, u.last_login,
                    COALESCE(NULLIF(TRIM(s.name), ''), CONCAT('Company ', u.comp_id)) AS company_name
             FROM staff_portal2.users u
             LEFT JOIN staff_portal2.subsidiaries s ON s.id = u.comp_id
             WHERE u.person = :p AND u.comp_id = :c
               AND " . self::USER_VISIBLE . "
             LIMIT 1",
            array(':p' => (string)$person, ':c' => (int)$compId)
        );
        return empty($rows) ? null : $rows[0];
    }

    /**
     * [table, staff-side scope] for every chart. The scopes are disjoint, so a
     * UNION can never double-count, and a comp-1 row left behind in the old
     * table during migration is ignored rather than silently duplicated.
     *
     * Top-level UNION ALL rather than a derived table, for the reason
     * staffHierarchy.php gives: MySQL materializes a UNION derived table instead
     * of merging it, which pushes the (boss_id, boss_comp_id) predicate outside
     * the branches and forces a full scan of both charts on every level.
     */
    private static function chartSources()
    {
        return array(
            array('staff_portal2.organization_chart',      'oc.comp_id <> ' . self::GLOB_COMP_ID),
            array('staff_portal2.organization_chart_glob', 'oc.comp_id =  ' . self::GLOB_COMP_ID),
        );
    }

    /**
     * Command: bind the same (person, comp_id) list once per UNION branch.
     *
     * Positional placeholders, because a named placeholder repeated across
     * branches is not reliably bindable under PDO's emulated prepares — the
     * same reason staffHierarchy.php binds this way.
     */
    private function bindPairsPerBranch($stmt, array $pairs, $branchCount)
    {
        $i = 1;
        for ($b = 0; $b < $branchCount; $b++) {
            foreach ($pairs as $pair) {
                $stmt->bindValue($i++, $pair['person'], PDO::PARAM_STR);
                $stmt->bindValue($i++, (int)$pair['comp_id'], PDO::PARAM_INT);
            }
        }
    }

    const NODE_FIELDS = "u.id, u.person, u.comp_id, u.fullname, u.department, u.position,
                         COALESCE(NULLIF(TRIM(s.name), ''), CONCAT('Company ', u.comp_id)) AS company_name";

    /**
     * Query: the direct superiors of MANY staff in one round-trip — one query
     * per level of the chain, not one per person.
     *
     * u is the superior; sub_person / sub_comp_id say who they supervise, which
     * is what lets the caller tell which frontier members came back empty and
     * are therefore the top of their line.
     */
    public function superiorsOf(array $staff)
    {
        if (empty($staff)) { return array(); }

        $tuples = implode(',', array_fill(0, count($staff), '(?, ?)'));
        $parts  = array();
        foreach (self::chartSources() as $source) {
            list($table, $scope) = $source;
            $parts[] = "SELECT " . self::NODE_FIELDS . ",
                               oc.staff_id AS sub_person, oc.comp_id AS sub_comp_id
                        FROM {$table} oc
                        INNER JOIN staff_portal2.users u
                            ON u.person = oc.boss_id AND u.comp_id = oc.boss_comp_id
                           AND " . self::USER_VISIBLE . "
                        LEFT JOIN staff_portal2.subsidiaries s ON s.id = u.comp_id
                        WHERE {$scope}
                          AND (oc.staff_id, oc.comp_id) IN ({$tuples})
                          AND " . self::ACTIVE_CLAUSE;
        }
        $sql = implode("\nUNION ALL\n", $parts) . "\nORDER BY fullname ASC";

        $stmt = $this->db->prepare($sql);
        $this->bindPairsPerBranch($stmt, $staff, count($parts));
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Query: the direct subordinates of MANY bosses in one round-trip.
     *
     * A boss's own comp_id says nothing about their reports' comp_id, so both
     * charts are read — still ONE round-trip per level of the tree.
     */
    public function subordinatesOf(array $bosses)
    {
        if (empty($bosses)) { return array(); }

        $tuples = implode(',', array_fill(0, count($bosses), '(?, ?)'));
        $parts  = array();
        foreach (self::chartSources() as $source) {
            list($table, $scope) = $source;
            $parts[] = "SELECT " . self::NODE_FIELDS . ",
                               oc.boss_id, oc.boss_comp_id
                        FROM {$table} oc
                        INNER JOIN staff_portal2.users u
                            ON u.person = oc.staff_id AND u.comp_id = oc.comp_id
                           AND " . self::USER_VISIBLE . "
                        LEFT JOIN staff_portal2.subsidiaries s ON s.id = u.comp_id
                        WHERE {$scope}
                          AND (oc.boss_id, oc.boss_comp_id) IN ({$tuples})
                          AND " . self::ACTIVE_CLAUSE;
        }
        $sql = implode("\nUNION ALL\n", $parts) . "\nORDER BY fullname ASC";

        $stmt = $this->db->prepare($sql);
        $this->bindPairsPerBranch($stmt, $bosses, count($parts));
        $stmt->execute();
        return $stmt->fetchAll();
    }

}

/**
 * Everything read from evaluation.monthly_assign_gw.
 *
 * A General Worker is not a `users` row: their identity is
 * (monthly_assign_gw_code, comp_id), a namespace of its own that can collide
 * with a users.person, and they have no users.id. That is why they carry no
 * memo, merit or application record — those tables all address a users.id — and
 * why every read here lives apart from StaffRepository rather than being bolted
 * onto it.
 *
 * `evaluation` is on this same mysqld, so a fully-qualified name reaches it on
 * the shared connection. Requires SELECT on `evaluation`.
 */
class GwRepository extends Repository
{
    /** GW only exist at comp 1 today; the column is still matched explicitly so
     *  the day that changes, this reads the data rather than an assumption. */
    const COMP_ID = 1;

    /** The live GW population — the clause jobspecReport.php, staffHierarchy.php
     *  and manpower_summary.php all apply, so every page counts the same people. */
    const ACTIVE_CLAUSE = "
        mag.monthly_assign_gw_status = '1'
        AND (mag.kpi_scorecard_exclude IS NULL OR mag.kpi_scorecard_exclude <> '1')";

    /**
     * Query: the GW directory for whichever of these companies has any.
     *
     * Grouped by code, not by row: a GW assigned to two supervisors has two
     * monthly_assign_gw rows (13 of them do), and listing that person twice
     * would double them in the count as well as on screen. MIN() over the
     * grouped columns just picks one row's copy of what is the same value.
     */
    public function directory(array $compIds, $search)
    {
        $comps = self::intList($compIds);
        $args  = array();

        $sql = "SELECT mag.monthly_assign_gw_code AS person,
                       CAST(mag.comp_id AS UNSIGNED) AS comp_id,
                       MIN(mag.monthly_assign_gw_id) AS id,
                       MIN(mag.monthly_assign_gw_fullname) AS fullname,
                       MIN(mag.monthly_assign_gw_district) AS district,
                       COALESCE(NULLIF(TRIM(MIN(s.name)), ''),
                                CONCAT('Company ', CAST(mag.comp_id AS UNSIGNED))) AS company_name
                FROM evaluation.monthly_assign_gw mag
                LEFT JOIN staff_portal2.subsidiaries s
                       ON s.id = CAST(mag.comp_id AS UNSIGNED)
                WHERE CAST(mag.comp_id AS UNSIGNED) IN ({$comps})
                  AND " . self::ACTIVE_CLAUSE;

        if ($search !== null && $search !== '') {
            $sql .= " AND (mag.monthly_assign_gw_fullname LIKE :q
                        OR mag.monthly_assign_gw_code LIKE :q
                        OR mag.monthly_assign_gw_district LIKE :q)";
            $args[':q'] = '%' . $search . '%';
        }

        $sql .= " GROUP BY mag.monthly_assign_gw_code, CAST(mag.comp_id AS UNSIGNED)
                  ORDER BY fullname ASC";
        return $this->run($sql, $args);
    }

    /** Query: one GW, or null. Same grouping rule as directory(). */
    public function profile($code, $compId)
    {
        $rows = $this->run(
            "SELECT mag.monthly_assign_gw_code AS person,
                    CAST(mag.comp_id AS UNSIGNED) AS comp_id,
                    MIN(mag.monthly_assign_gw_id) AS id,
                    MIN(mag.monthly_assign_gw_fullname) AS fullname,
                    MIN(mag.monthly_assign_gw_district) AS district,
                    MIN(mag.monthly_assign_gw_mobile_no) AS mobile_no,
                    MIN(mag.monthly_assign_gw_joined_date) AS joined_date,
                    COUNT(*) AS assignments,
                    COALESCE(NULLIF(TRIM(MIN(s.name)), ''),
                             CONCAT('Company ', CAST(mag.comp_id AS UNSIGNED))) AS company_name
             FROM evaluation.monthly_assign_gw mag
             LEFT JOIN staff_portal2.subsidiaries s
                    ON s.id = CAST(mag.comp_id AS UNSIGNED)
             WHERE mag.monthly_assign_gw_code = :p
               AND CAST(mag.comp_id AS UNSIGNED) = :c
               AND " . self::ACTIVE_CLAUSE . "
             GROUP BY mag.monthly_assign_gw_code, CAST(mag.comp_id AS UNSIGNED)",
            array(':p' => (string)$code, ':c' => (int)$compId)
        );
        return empty($rows) ? null : $rows[0];
    }

    /**
     * Query: this GW's supervisors, resolved to their staff records.
     *
     * Plural on purpose: a GW with two live assignment rows genuinely answers to
     * two supervisors, and either may approve their job spec — the same rule
     * jobspecReport.php reports by.
     */
    public function supervisorsOf($code, $compId)
    {
        return $this->run(
            "SELECT u.person, u.comp_id, u.fullname, u.department, u.position,
                    COALESCE(NULLIF(TRIM(s.name), ''), CONCAT('Company ', u.comp_id)) AS company_name
             FROM evaluation.monthly_assign_gw mag
             INNER JOIN staff_portal2.users u
                     ON u.person = mag.boss_id AND u.comp_id = mag.boss_comp_id
                    AND " . DirectoryRepository::USER_VISIBLE . "
             LEFT JOIN staff_portal2.subsidiaries s ON s.id = u.comp_id
             WHERE mag.monthly_assign_gw_code = :p
               AND CAST(mag.comp_id AS UNSIGNED) = :c
               AND " . self::ACTIVE_CLAUSE . "
             GROUP BY u.person, u.comp_id, u.fullname, u.department, u.position, s.name
             ORDER BY u.fullname",
            array(':p' => (string)$code, ':c' => (int)$compId)
        );
    }

    /**
     * Query: the General Workers reporting to MANY bosses, in one round-trip.
     *
     * GW are leaves — they never supervise anyone — so one query covers them no
     * matter how deep the tree goes.
     *
     * boss_id / boss_comp_id are VARCHAR here while the chart holds INT, so the
     * pairs bind as strings. Deliberately NOT wrapped in a try/catch: a missing
     * SELECT grant on `evaluation` must fail loudly, because silently dropping
     * subordinates is an invisible correctness bug.
     */
    public function gwOf(array $bosses)
    {
        if (empty($bosses)) { return array(); }

        $tuples = implode(',', array_fill(0, count($bosses), '(?, ?)'));
        $sql = "SELECT mag.monthly_assign_gw_code     AS person,
                       mag.comp_id                    AS comp_id,
                       mag.monthly_assign_gw_fullname AS fullname,
                       mag.monthly_assign_gw_district AS district,
                       mag.boss_id, mag.boss_comp_id
                FROM evaluation.monthly_assign_gw mag
                WHERE (mag.boss_id, mag.boss_comp_id) IN ({$tuples})
                  AND mag.comp_id = '" . self::COMP_ID . "'
                  AND " . self::ACTIVE_CLAUSE . "
                GROUP BY mag.monthly_assign_gw_id
                ORDER BY mag.monthly_assign_gw_fullname ASC";

        $stmt = $this->db->prepare($sql);
        $i = 1;
        foreach ($bosses as $boss) {
            $stmt->bindValue($i++, (string)$boss['person'], PDO::PARAM_STR);
            $stmt->bindValue($i++, (string)$boss['comp_id'], PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

/**
 * Turns the flat assignment rows into the chain above and the tree below — the
 * same shapes staffHierarchy.php serves the mobile Staff tab, so the two
 * screens can never disagree about who reports to whom.
 *
 * Scores are the one thing left out: they belong to the evaluation module, and
 * this page is about MK Command records.
 */
class HierarchyService
{
    /** A cycle in the chart is impossible to walk out of; this is the backstop
     *  on top of the visited-set, and matches staffHierarchy.php. */
    const MAX_DEPTH = 10;

    /** GW only exist at comp 1, and only as comp 1 GW. */
    const GW_COMP_ID = GwRepository::COMP_ID;

    private $repo;
    private $gwRepo;

    public function __construct(StaffRepository $repo, GwRepository $gwRepo)
    {
        $this->repo   = $repo;
        $this->gwRepo = $gwRepo;
    }

    private static function key($person, $compId)
    {
        return $person . '|' . (int)$compId;
    }

    /** GW live in their own key space: a GW code may collide with a users.person. */
    private static function gwKey($person, $compId)
    {
        return 'gw|' . $person . '|' . (int)$compId;
    }

    /**
     * The full superior chain to the top: BFS upward, one query per level.
     *
     * Flat and nearest-first; each entry carries `level` (1 = direct superior)
     * and `is_top` (that person has no live superior of their own). The
     * visited-set keeps the shortest distance to each person and makes a cycle
     * impossible.
     */
    public function superiorChain($person, $compId)
    {
        $rootKey  = self::key($person, $compId);
        $frontier = array(array('person' => $person, 'comp_id' => (int)$compId));
        $visited  = array($rootKey => true);
        $chain    = array();
        $index    = array();   // key => position in $chain, to backfill is_top
        $direct   = 0;
        $level    = 0;

        while (!empty($frontier) && $level < self::MAX_DEPTH) {
            $rows = $this->repo->superiorsOf($frontier);

            // A frontier member nobody came back for is the top of its line.
            $hasBoss = array();
            foreach ($rows as $row) {
                $hasBoss[self::key($row['sub_person'], $row['sub_comp_id'])] = true;
            }
            foreach ($frontier as $member) {
                $mKey = self::key($member['person'], $member['comp_id']);
                if ($mKey !== $rootKey && !isset($hasBoss[$mKey]) && isset($index[$mKey])) {
                    $chain[$index[$mKey]]['is_top'] = true;
                }
            }

            $next = array();
            foreach ($rows as $row) {
                $key = self::key($row['person'], $row['comp_id']);
                if (isset($visited[$key])) {
                    continue;  // already placed at an equal-or-nearer level
                }
                $visited[$key] = true;

                $chain[] = array(
                    'person'       => $row['person'],
                    'comp_id'      => (int)$row['comp_id'],
                    'fullname'     => $row['fullname'],
                    'department'   => $row['department'],
                    'position'     => $row['position'],
                    'company_name' => $row['company_name'],
                    'level'        => $level + 1,
                    'is_top'       => false,
                    'is_gw'        => 0,
                );
                $index[$key] = count($chain) - 1;
                if ($level === 0) { $direct++; }

                $next[] = array('person' => $row['person'], 'comp_id' => (int)$row['comp_id']);
            }

            $frontier = $next;
            $level++;
        }

        return array('chain' => $chain, 'direct' => $direct);
    }

    /**
     * The whole team below: BFS downward one level at a time, so it is one
     * query per depth rather than one per node.
     */
    public function subordinateTree($person, $compId)
    {
        $rootKey   = self::key($person, $compId);
        $frontier  = array(array('person' => $person, 'comp_id' => (int)$compId));
        $visited   = array($rootKey => true);
        $nodes     = array();                 // key => node
        $childKeys = array($rootKey => array());
        $depth     = 0;

        while (!empty($frontier) && $depth < self::MAX_DEPTH) {
            $next = array();
            foreach ($this->repo->subordinatesOf($frontier) as $row) {
                $key = self::key($row['person'], $row['comp_id']);
                if (isset($visited[$key])) {
                    continue;  // already placed elsewhere in the tree
                }
                $visited[$key] = true;

                $nodes[$key] = array(
                    'person'       => $row['person'],
                    'comp_id'      => (int)$row['comp_id'],
                    'fullname'     => $row['fullname'],
                    'department'   => $row['department'],
                    'position'     => $row['position'],
                    'company_name' => $row['company_name'],
                    'is_gw'        => 0,
                );
                $childKeys[$key] = array();
                $childKeys[self::key($row['boss_id'], $row['boss_comp_id'])][] = $key;

                $next[] = array('person' => $row['person'], 'comp_id' => (int)$row['comp_id']);
            }
            $frontier = $next;
            $depth++;
        }

        $this->attachGw($person, $compId, $nodes, $childKeys);

        return array(
            'tree'   => $this->assemble($rootKey, $nodes, $childKeys),
            'total'  => count($nodes),                  // every descendant, all levels
            'direct' => count($childKeys[$rootKey]),
        );
    }

    /**
     * Command: hang the General Workers off every comp-1 boss already in the
     * tree, the root included. Mutates $nodes / $childKeys in place.
     *
     * One batched query after the BFS rather than a branch inside it, because
     * GW are leaves. A GW assigned to two bosses is placed once, under whichever
     * is reached first — the same rule the mobile applies, so the two screens
     * report the same totals.
     */
    private function attachGw($rootPerson, $rootCompId, array &$nodes, array &$childKeys)
    {
        $bosses = array();
        if ((int)$rootCompId === self::GW_COMP_ID) {
            $bosses[] = array('person' => $rootPerson, 'comp_id' => (int)$rootCompId);
        }
        foreach ($nodes as $node) {
            if ((int)$node['comp_id'] === self::GW_COMP_ID) {
                $bosses[] = array('person' => $node['person'], 'comp_id' => $node['comp_id']);
            }
        }
        if (empty($bosses)) { return; }

        foreach ($this->gwRepo->gwOf($bosses) as $row) {
            $key = self::gwKey($row['person'], $row['comp_id']);
            if (isset($nodes[$key])) {
                continue;  // already placed under another boss in this tree
            }
            $parentKey = self::key($row['boss_id'], $row['boss_comp_id']);
            if (!isset($childKeys[$parentKey])) {
                continue;  // that boss is not part of this tree
            }

            $nodes[$key] = array(
                'person'       => $row['person'],       // a monthly_assign_gw_code
                'comp_id'      => (int)$row['comp_id'],
                'fullname'     => $row['fullname'],
                'department'   => $row['district'],
                'position'     => 'General Worker',
                'company_name' => null,
                'is_gw'        => 1,
            );
            $childKeys[$key]         = array();
            $childKeys[$parentKey][] = $key;
        }
    }

    /** Depth-first assembly; the subtree size is counted on the way back up. */
    private function assemble($key, array $nodes, array $childKeys)
    {
        $children = array();
        foreach ($childKeys[$key] as $childKey) {
            $node = $nodes[$childKey];
            $node['children'] = $this->assemble($childKey, $nodes, $childKeys);

            $total = 0;
            foreach ($node['children'] as $grandChild) {
                $total += 1 + $grandChild['total_subordinates'];
            }
            $node['total_subordinates']  = $total;
            $node['direct_subordinates'] = count($node['children']);

            $children[] = $node;
        }
        return $children;
    }
}

class JobSpecRepository extends Repository
{
    /** Query: every job spec version this staff has, newest first. */
    public function versions($person, $compId, $isGw = 0)
    {
        return $this->run(
            // review_note is deliberately not read: it carries import bookkeeping
            // ("Backfilled from hardcopy") rather than anything HR acts on.
            "SELECT v.id, v.status, v.submitted_at, v.submitted_by,
                    v.reviewed_at, v.reviewed_by,
                    (SELECT COUNT(*) FROM mkPortal.job_spec_version_item vi
                     WHERE vi.version_id = v.id) AS items
             FROM mkPortal.job_spec_version v
             WHERE v.person = :p AND v.comp_id = :c
               AND IFNULL(v.is_gw, 0) = " . ((int)$isGw ? 1 : 0) . "
             ORDER BY v.id DESC",
            array(':p' => (string)$person, ':c' => (int)$compId)
        );
    }

    /** Query: one version's owner, so an AJAX task request can be scope-checked
     *  before its tasks are rendered. */
    public function versionOwner($versionId)
    {
        $rows = $this->run(
            "SELECT person, comp_id, IFNULL(is_gw, 0) AS is_gw
             FROM mkPortal.job_spec_version WHERE id = :v LIMIT 1",
            array(':v' => (int)$versionId)
        );
        return empty($rows) ? null : $rows[0];
    }

    /** Query: one version's tasks in display order, each with the applications
     *  tagged on it by the Job Spec editor. */
    public function itemsFor($versionId)
    {
        return $this->run(
            "SELECT i.id, i.sort_order, i.category, i.task,
                    GROUP_CONCAT(DISTINCT a.application_title
                                 ORDER BY a.application_title SEPARATOR ', ') AS apps
             FROM mkPortal.job_spec_version_item i
             LEFT JOIN mkPortal.job_spec_version_item_app ia ON ia.item_id = i.id
             LEFT JOIN application.application a
                    ON a.application_id = ia.application_id
             WHERE i.version_id = :v
             GROUP BY i.id, i.sort_order, i.category, i.task
             ORDER BY i.sort_order ASC, i.id ASC",
            array(':v' => (int)$versionId)
        );
    }
}

class AppRepository extends Repository
{
    /**
     * Query: the applications this staff is attached to, with every role they
     * hold on each.
     *
     * The company-wide rows (application_user.staff_id = 'all') are included —
     * they are what put Leave System and the like on everybody's list — but come
     * back flagged rather than folded into `roles`, so the page can say WHY an
     * app is listed instead of implying it was assigned by name.
     *
     * Inactive applications (application_status '0') are listed with a badge
     * rather than dropped: an HR record that quietly omits rows is worse than
     * one that labels them. The directory count is built from the same two
     * populations, so the two always agree.
     */
    public function appsFor($person, $compId)
    {
        return $this->run(
            "SELECT a.application_id, a.application_title, a.application_section,
                    a.application_status,
                    GROUP_CONCAT(DISTINCT CASE WHEN LOWER(TRIM(r.staff_id)) <> 'all'
                                               THEN r.role END
                                 ORDER BY 1 SEPARATOR ',') AS roles,
                    MAX(LOWER(TRIM(r.staff_id)) = 'all') AS company_wide
             FROM (
                 SELECT application_id, comp_id, staff_id, 'owner'     AS role FROM application.application_owner
                 UNION ALL
                 SELECT application_id, comp_id, staff_id, 'developer' AS role FROM application.application_developer
                 UNION ALL
                 SELECT application_id, comp_id, staff_id, 'user'      AS role FROM application.application_user
             ) r
             INNER JOIN application.application a
                 ON a.application_id = CAST(r.application_id AS UNSIGNED)
             WHERE CAST(r.comp_id AS UNSIGNED) = :c
               AND (r.staff_id = :p OR LOWER(TRIM(r.staff_id)) = 'all')
             GROUP BY a.application_id, a.application_title,
                      a.application_section, a.application_status
             ORDER BY a.application_section, a.application_title",
            array(':p' => (string)$person, ':c' => (int)$compId)
        );
    }
}

class RecordsRepository extends Repository
{
    /** Where StaffMemo_AttachmentAdd and its performance twin write, relative to
     *  the web root — same constants staffRecords.php builds its URLs from. */
    const MEMO_PATH        = '/accounts/staffProfile/attachments/staff_memo/';
    const PERFORMANCE_PATH = '/accounts/staffProfile/attachments/staff_performance/';

    /** Rendered inline; everything else opens in the browser's own viewer. */
    const IMAGE_EXT = 'jpg,jpeg,png,gif,webp,heic,heif';

    public static function isImage($filename)
    {
        $ext = strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION));
        return in_array($ext, explode(',', self::IMAGE_EXT), true);
    }

    /** Query: memos addressed to this staff or to 'all', newest first.
     *  The visibility clause is the one staffRecords.php serves the app, so HR
     *  reads exactly the list the staff member sees. */
    public function memosFor($userId)
    {
        return $this->run(
            "SELECT staff_memo_id, staff_memo_ref, staff_memo_date,
                    staff_memo_to_staff_id, staff_memo_cc_staff_id,
                    staff_memo_from, staff_memo_subject
             FROM staff_profile.staff_memo
             WHERE (FIND_IN_SET('all', staff_memo_to_staff_id) <> 0
                 OR FIND_IN_SET(:id, staff_memo_to_staff_id) <> 0)
               AND staff_memo_deleted = '0'
             ORDER BY staff_memo_id DESC",
            array(':id' => (int)$userId)
        );
    }

    /** Query: one memo with its body — but only if it is in that staff's list,
     *  so an id typed into the query string cannot probe the memo table. */
    public function memoFor($userId, $memoId)
    {
        $rows = $this->run(
            "SELECT staff_memo_id, staff_memo_ref, staff_memo_date,
                    staff_memo_to_staff_id, staff_memo_cc_staff_id,
                    staff_memo_from, staff_memo_subject, staff_memo_content
             FROM staff_profile.staff_memo
             WHERE staff_memo_id = :mid
               AND (FIND_IN_SET('all', staff_memo_to_staff_id) <> 0
                 OR FIND_IN_SET(:id, staff_memo_to_staff_id) <> 0)
               AND staff_memo_deleted = '0'
             LIMIT 1",
            array(':mid' => (int)$memoId, ':id' => (int)$userId)
        );
        return empty($rows) ? null : $rows[0];
    }

    /** Query: a memo's live attachments. A flagged row points at a file that was
     *  moved into /deleted, so filtering here is what stops dead links. */
    public function memoAttachments($memoId)
    {
        return $this->run(
            "SELECT staff_memo_attachment_id AS id,
                    staff_memo_attachment_original_filename AS name,
                    staff_memo_attachment_filename AS stored
             FROM staff_profile.staff_memo_attachments
             WHERE staff_memo_id = :mid
               AND IFNULL(staff_memo_attachment_deleted, '0') <> '1'
               AND IFNULL(staff_memo_attachment_filename, '') <> ''
             ORDER BY staff_memo_attachment_id",
            array(':mid' => (int)$memoId)
        );
    }

    /** Query: merit/demerit records for this staff, newest first, optional year. */
    public function performanceFor($userId, $year)
    {
        $args = array(':id' => (int)$userId);
        $sql = "SELECT staff_performance_id AS id, staff_performance_ref AS ref,
                       staff_performance_date AS date,
                       staff_performance_type_title AS title,
                       staff_performance_type_merit_demerit AS merit_demerit,
                       staff_performance_type_points AS points,
                       staff_performance_uploaded_by AS issued_by
                FROM staff_profile.staff_performance
                WHERE staff_performance_staff_id = :id
                  AND staff_performance_deleted = 0";
        if ($year !== null && $year !== '') {
            $sql .= " AND staff_performance_date LIKE :y";
            $args[':y'] = $year . '%';
        }
        $sql .= " ORDER BY staff_performance_id DESC";
        return $this->run($sql, $args);
    }

    /** Query: the years this staff actually has records in, for the filter. */
    public function performanceYears($userId)
    {
        $rows = $this->run(
            "SELECT DISTINCT LEFT(staff_performance_date, 4) AS y
             FROM staff_profile.staff_performance
             WHERE staff_performance_staff_id = :id
               AND staff_performance_deleted = 0
             ORDER BY y DESC",
            array(':id' => (int)$userId)
        );
        $out = array();
        foreach ($rows as $r) {
            if (ctype_digit((string)$r['y'])) { $out[] = $r['y']; }
        }
        return $out;
    }

    /**
     * Query: one performance record's live attachments — but only if the record
     * belongs to this staff.
     *
     * The ownership join is the access check, exactly as memoFor() embeds its
     * visibility clause: a record id typed into the query string cannot pull
     * files off somebody else's warning letter.
     */
    public function performanceAttachmentsFor($userId, $recordId)
    {
        return $this->run(
            "SELECT a.staff_performance_attachment_id AS id,
                    a.staff_performance_attachment_original_filename AS name,
                    a.staff_performance_attachment_filename AS stored
             FROM staff_profile.staff_performance_attachments a
             INNER JOIN staff_profile.staff_performance p
                     ON p.staff_performance_id = a.staff_performance_id
                    AND p.staff_performance_staff_id = :uid
                    AND p.staff_performance_deleted = 0
             WHERE a.staff_performance_id = :rid
               AND IFNULL(a.staff_performance_attachment_deleted, '0') <> '1'
               AND IFNULL(a.staff_performance_attachment_filename, '') <> ''
             ORDER BY a.staff_performance_attachment_id",
            // :rid stays a string so the varchar column keeps its index.
            array(':uid' => (int)$userId, ':rid' => (string)(int)$recordId)
        );
    }

    /** Query: live attachment counts for a set of performance records. */
    public function performanceAttachmentCounts(array $recordIds)
    {
        if (empty($recordIds)) { return array(); }
        $rows = $this->run(
            "SELECT staff_performance_id AS rid, COUNT(*) AS n
             FROM staff_profile.staff_performance_attachments
             WHERE staff_performance_id IN (" . self::intList($recordIds) . ")
               AND IFNULL(staff_performance_attachment_deleted, '0') <> '1'
               AND IFNULL(staff_performance_attachment_filename, '') <> ''
             GROUP BY staff_performance_id"
        );
        $out = array();
        foreach ($rows as $r) { $out[(int)$r['rid']] = (int)$r['n']; }
        return $out;
    }

    /** Query: users.id => fullname for a set of ids, in one round-trip. */
    public function namesByIds(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) { return array(); }
        $rows = $this->run(
            "SELECT id, fullname FROM staff_portal2.users
             WHERE id IN (" . self::intList($ids) . ")"
        );
        $out = array();
        foreach ($rows as $r) { $out[(int)$r['id']] = $r['fullname']; }
        return $out;
    }
}

/* ---------- view helpers -------------------------------------------------- */

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** "SOO ZHAO YUAN NATHAN" -> "Soo Zhao Yuan Nathan". \p{L} with /u keeps
 *  accented letters intact; strtolower only touches A-Z bytes, so a UTF-8
 *  sequence passes through instead of being corrupted (mbstring is not
 *  guaranteed on this host). */
function titleCase($value)
{
    $value = trim((string)$value);
    if ($value === '') { return ''; }
    return preg_replace_callback(
        '/\b\p{L}/u',
        function ($m) { return strtoupper($m[0]); },
        strtolower($value)
    );
}

/** Display a name, but leave a bare login alone — title-casing a COALESCE that
 *  fell through to the login turns "sooCK" into "Soock". A blank fullname falls
 *  back to the login too: this name is the link text on every row, and an empty
 *  one is a link nobody can click. */
function displayName($name, $code)
{
    $name = trim((string)$name);
    if ($name === '') { return (string)$code; }
    return ($name === (string)$code) ? $name : titleCase($name);
}

/** "2026-07-16 08:29:00" -> "16 Jul 2026" */
function fmtDate($dt)
{
    if (!$dt || $dt === '0000-00-00' || $dt === '0000-00-00 00:00:00') { return '—'; }
    $ts = strtotime($dt);
    return $ts ? date('j M Y', $ts) : $dt;
}

/**
 * Render one of the varchar date columns as "16 Jul 2026".
 *
 * A four-digit head is always the year. Everything else is decided by
 * $dayFirst, and that flag differs PER COLUMN — read the wrong way round, every
 * ambiguous date (06/04/2024) silently comes out with its day and month
 * swapped, which is worse than not formatting it at all:
 *
 *   staff_performance_date          Y/m/d for every row in the table.
 *   staff_memo_date                 d/m/Y as typed into the web form
 *                                   ("19/12/2024"), with a few Y/m/d rows.
 *   monthly_assign_gw_joined_date   d/m/Y, no year-first rows at all.
 *
 * Anything that does not parse is echoed back untouched rather than guessed at,
 * including a two-digit year ("28/07/26"): the century is not in the data, and
 * inventing one is worse than showing what was recorded.
 */
function fmtLooseDate($value, $dayFirst)
{
    $value = trim((string)$value);
    if ($value === '') { return '—'; }

    $head  = explode(' ', $value);
    $parts = preg_split('/[-\/]/', $head[0]);
    if (count($parts) < 3) { return $value; }

    if (strlen($parts[0]) === 4) {
        $ordered = array($parts[0], $parts[1], $parts[2]);            // Y/m/d
    } elseif ($dayFirst) {
        $ordered = array($parts[2], $parts[1], $parts[0]);            // d/m/Y
    } else {
        $ordered = array($parts[2], $parts[0], $parts[1]);            // m/d/Y
    }

    if (strlen($ordered[0]) !== 4) { return $value; }   // no century to work from
    $y = (int)$ordered[0]; $m = (int)$ordered[1]; $d = (int)$ordered[2];
    if (!$y || $m < 1 || $m > 12 || !$d || $d > 31) { return $value; }
    return $d . ' ' . date('M', mktime(0, 0, 0, $m, 1, $y)) . ' ' . $y;
}

/** staff_memo_date, monthly_assign_gw_joined_date. */
function fmtDayFirst($value) { return fmtLooseDate($value, true); }

/** staff_performance_date. */
function fmtYearFirst($value) { return fmtLooseDate($value, false); }

function selfUrl(array $overrides)
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') { unset($params[$k]); }
    }
    return basename(__FILE__) . (empty($params) ? '' : '?' . http_build_query($params));
}

/** A url built from scratch, NOT via selfUrl(): inside an AJAX response,
 *  merging $_GET would carry the outer request's own ajax params into the link. */
function ajaxUrl(array $params)
{
    return basename(__FILE__) . '?' . http_build_query($params);
}

/**
 * Stable colour per name, so monograms read as distinct people — the same
 * palette as the app's Memo screen.
 *
 * The accumulator is bounded at 2^24, NOT at the JS version's 2^32. On a 32-bit
 * PHP build the literal 4294967296 is larger than PHP_INT_MAX and is therefore
 * a float; `%` then casts both operands to int, the divisor lands on 0, and
 * "Modulo by zero" is a fatal — which, from inside an HTML attribute, truncates
 * the whole page mid-tag. Seven ASCII characters are enough to get there.
 *
 * Under 2^24 the multiply tops out below 2^29, so the arithmetic stays integer
 * on any build, and the colour is spread just as well.
 */
function colorFor($name)
{
    $palette = array('#2563eb', '#0891b2', '#7c3aed', '#db2777', '#ea580c',
                     '#059669', '#ca8a04', '#4f46e5', '#0d9488', '#be123c');
    $s = (string)$name;
    $hash = 0;
    for ($i = 0, $len = strlen($s); $i < $len; $i++) {
        $hash = ($hash * 31 + ord($s[$i])) % 16777216;
    }
    return $palette[$hash % count($palette)];
}

/** 2 -> "2nd", 3 -> "3rd", 4 -> "4th" ... — the same ladder labels the mobile
 *  Staff tab uses for the superior chain. */
function ordinal($n)
{
    $suffix = array('th', 'st', 'nd', 'rd');
    $v = $n % 100;
    $key = (($v - 20) % 10);
    if (!isset($suffix[$key])) { $key = isset($suffix[$v]) ? $v : 0; }
    return $n . $suffix[$key];
}

function initialsOf($name)
{
    $words = preg_split('/\s+/', trim(titleCase($name)));
    $out = '';
    foreach (array_slice($words, 0, 2) as $w) {
        if ($w !== '') { $out .= strtoupper(substr($w, 0, 1)); }
    }
    return $out === '' ? '#' : $out;
}

/** True when the record is a demerit — the app's rule: the free-text
 *  merit_demerit column merely has to contain the word. */
function isDemerit($value)
{
    return strpos(strtolower(trim((string)$value)), 'demerit') !== false;
}

/** Turn a memo's raw *_staff_id (a CSV of users.id, or the literal 'all') into
 *  a readable recipient list. */
function recipientList($raw, array $names)
{
    $raw = trim((string)$raw);
    if ($raw === '') { return '—'; }
    if (strtolower($raw) === 'all') { return 'ALL STAFF'; }
    $out = array();
    foreach (array_filter(array_map('trim', explode(',', $raw))) as $tok) {
        if (strtolower($tok) === 'all') { $out[] = 'ALL STAFF'; continue; }
        $out[] = isset($names[(int)$tok]) ? titleCase($names[(int)$tok]) : $tok;
    }
    return implode(', ', $out);
}

/** Every users.id referenced by a set of memo rows, across the given columns. */
function collectRecipientIds(array $rows, array $fields)
{
    $ids = array();
    foreach ($rows as $r) {
        foreach ($fields as $f) {
            if (!isset($r[$f])) { continue; }
            foreach (explode(',', (string)$r[$f]) as $tok) {
                $tok = trim($tok);
                if ($tok !== '' && strtolower($tok) !== 'all') { $ids[] = (int)$tok; }
            }
        }
    }
    return $ids;
}

/**
 * staff_performance_uploaded_by holds a users.id, not a name — rendering it raw
 * puts "3424" in front of HR. Anything non-numeric (older rows wrote a login)
 * is passed through as typed rather than dropped.
 */
function issuerName($raw, array $names)
{
    $raw = trim((string)$raw);
    if ($raw === '') { return '—'; }
    if (ctype_digit($raw) && isset($names[(int)$raw])) { return titleCase($names[(int)$raw]); }
    return $raw;
}

/**
 * The year options for a picker: the years present in the data, plus whichever
 * year is selected, newest first.
 *
 * The union is the point. The current year is the default before anyone has a
 * record in it, and a <select> whose selected value is not among its options
 * silently highlights the first one instead — so the page would claim to be
 * showing all years while the table showed one.
 */
function yearOptions(array $present, $selected)
{
    $years = $present;
    if ($selected !== '') { $years[] = $selected; }
    $years = array_unique($years);
    rsort($years, SORT_STRING);
    return array_values($years);
}

function absoluteBase()
{
    $secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'globportal.com';
    return ($secure ? 'https' : 'http') . '://' . $host;
}

/* ---------- inline renderers ---------------------------------------------- */
/* Shared by the full page and by the AJAX responses, so each block of markup
 * has exactly one definition and the JS never rebuilds it. */

/**
 * Command: echo the Job Spec cell — the state of the staff member's current
 * (newest) version.
 *
 * The task count rides along on an approved spec only: on an approved document
 * it is the one number that says whether the thing HR signed off is a real
 * spec or a stub, while on a pending one it is a detail of a draft that has not
 * been agreed to yet.
 *
 * The status doubles as the pill class, exactly as the detail page's version
 * table does it, so the three states are coloured from one set of rules.
 */
function renderJobSpecCell(array $spec)
{
    $status = isset($spec['status']) ? trim((string)$spec['status']) : '';
    if ($status === '') {
        echo '<span class="muted">none</span>';
        return;
    }

    $label = ucfirst($status);
    if ($status === 'approved') {
        $label .= ' - ' . (int)$spec['items'];
    }
    echo '<span class="pill ' . h($status) . '">' . h($label) . '</span>';
}

/**
 * Command: echo one merit/demerit tally as a pill.
 *
 * Zero is printed rather than left out. Both counts are meaningful for every
 * staff member — "0 demerit" is a fact about them, not an absence of one — and
 * a cell that renders only what is non-zero makes the reader work out which of
 * the two a lone pill is before they can read it.
 *
 * A zero takes the neutral style instead of the green or red one: down 300 rows
 * a column of coloured pills is a column of noise, and colour has to keep
 * meaning "this person has a record".
 */
function renderTally($count, $kind)
{
    $count = (int)$count;
    echo '<span class="pill ' . ($count ? $kind : 'off') . '">'
       . $count . ' ' . $kind . '</span> ';
}

/** Command: echo one job spec version's tasks, inline beneath its row. */
function renderTasks(array $rows, $span)
{
    echo '<tr class="exprow"><td colspan="' . (int)$span . '">';
    if (empty($rows)) {
        echo '<div class="taskbox"><div class="empty">No tasks in this version.</div></div></td></tr>';
        return;
    }
    echo '<div class="taskbox"><ol class="tasklist">';
    foreach ($rows as $t) {
        echo '<li>';
        if (trim((string)$t['category']) !== '') {
            echo '<span class="cat">' . h($t['category']) . '</span> ';
        }
        echo h($t['task']);
        if (!empty($t['apps'])) {
            echo '<div class="muted">Apps: ' . h($t['apps']) . '</div>';
        }
        echo '</li>';
    }
    echo '</ol></div></td></tr>';
}

/**
 * Command: echo one person as a row in a numbered hierarchy list.
 *
 * Spans only, no divs: a row is also the content of a <summary>, whose content
 * model is phrasing content.
 *
 * A General Worker links to their own page, carrying gw=1: a GW code lives in
 * a different namespace from users.person and can collide with one, so the
 * population has to travel with the link. They get a neutral monogram and drop
 * "General Worker" from the meta line — the GW pill already says that, and a
 * column of identical job titles in identical bright colours is what made a
 * team of 36 unreadable.
 */
function renderPersonRow(array $node, $pill = null, $hasChildren = false)
{
    $isGw = !empty($node['is_gw']);
    $name = displayName($node['fullname'], $node['person']);

    echo '<span class="treerow"><span class="treenum"></span>';
    echo $isGw
        ? '<span class="mono sm gwmono">' . h(initialsOf($node['fullname'])) . '</span>'
        : '<span class="mono sm" style="background:' . h(colorFor($node['fullname'])) . '">'
          . h(initialsOf($node['fullname'])) . '</span>';

    echo '<span class="treetext">';
    echo '<a class="treename" href="'
       . h(selfUrl(array('person' => $node['person'], 'scomp' => $node['comp_id'],
                         'gw' => $isGw ? 1 : null)))
       . '">' . h($name) . '</a>';

    $meta = array();
    if ($isGw) {
        // A GW's "department" is a district code (LSG(BELURAN), PPR). Title-casing
        // an acronym only damages it, so it is printed as recorded — and when
        // there is none, the row simply has no second line rather than an empty
        // one holding open a blank space.
        if (trim((string)$node['department']) !== '') { $meta[] = $node['department']; }
    } else {
        if (trim((string)$node['position']) !== '')   { $meta[] = titleCase($node['position']); }
        if (trim((string)$node['department']) !== '') { $meta[] = titleCase($node['department']); }
        if (empty($meta)) { $meta[] = 'No position'; }
    }
    if (!empty($meta)) {
        // Double-quoted: \u{} is only an escape there, and the separator has to be
        // a real bullet because h() would turn an HTML entity back into text.
        echo '<span class="treemeta">' . h(implode(" \u{2022} ", $meta)) . '</span>';
    }
    echo '</span>';

    echo '<span class="treepills">';
    if ($isGw) {
        echo '<span class="pill gw">GW</span>';
    }
    if (!empty($node['total_subordinates'])) {
        echo '<span class="pill team">' . (int)$node['total_subordinates'] . '</span>';
    }
    if ($pill !== null) {
        echo $pill;
    }
    echo '</span>';

    // Drawn even on leaves, as an empty box, so every name in the column starts
    // at the same x whether or not it has a team to open.
    echo '<span class="caret">' . ($hasChildren ? '&#9656;' : '') . '</span>';
    echo '</span>';
}

/**
 * Command: echo the subordinate tree, nesting as deep as the chart goes.
 *
 * Nested <ol> rather than a flattened list with padding: the numbering (1, 1.1,
 * 1.2.1) is then the browser's job through CSS counters, and it says both where
 * somebody sits and who they sit under.
 */
function renderSubordinateTree(array $nodes, $depth = 0)
{
    // Only the outermost list carries the class; the nested ones are styled as
    // .tree ol, so the indent rule cannot end up competing with itself.
    echo $depth === 0 ? '<ol class="tree">' : '<ol>';
    foreach ($nodes as $node) {
        echo '<li>';
        if (empty($node['children'])) {
            renderPersonRow($node);
        } else {
            // <details> rather than a JS toggle: it starts closed on its own, so
            // the page opens on the direct reports instead of every descendant,
            // and it keeps working — keyboard included — with scripting off.
            echo '<details><summary>';
            renderPersonRow($node, null, true);
            echo '</summary>';
            renderSubordinateTree($node['children'], $depth + 1);
            echo '</details>';
        }
        echo '</li>';
    }
    echo '</ol>';
}

/**
 * Command: echo the superior chain, furthest-up first.
 *
 * Ordered the way the mobile Staff tab orders it — highest level first, then by
 * name — so #1 is the top of the reporting line and the last entry is the
 * person's own manager.
 */
function renderSuperiorChain(array $chain)
{
    usort($chain, function ($a, $b) {
        if ($a['level'] !== $b['level']) { return $b['level'] - $a['level']; }
        return strcasecmp($a['fullname'], $b['fullname']);
    });

    echo '<ol class="tree">';
    foreach ($chain as $person) {
        $isDirect = ((int)$person['level'] === 1);
        $isTop    = (!$isDirect && !empty($person['is_top']));
        if ($isDirect) {
            $pill = '<span class="pill approved">Direct</span>';
        } elseif ($isTop) {
            $pill = '<span class="pill top">Top level</span>';
        } else {
            $pill = '<span class="pill role">' . h(ordinal((int)$person['level'])) . ' level up</span>';
        }
        echo '<li>';
        renderPersonRow($person, $pill);
        echo '</li>';
    }
    echo '</ol>';
}

/**
 * Command: echo a set of attachments.
 *
 * Images are previewed rather than named. These files are overwhelmingly photos
 * uploaded straight off a phone, so the original filename is "1.jpeg" — a list
 * of those tells the reader nothing, while five thumbnails tell them everything.
 * Anything else stays a labelled chip.
 */
function renderFiles(array $files, $basePath)
{
    if (empty($files)) {
        echo '<div class="empty">No attachments on this record.</div>';
        return;
    }

    $base = absoluteBase() . $basePath;
    echo '<div class="files">';
    foreach ($files as $f) {
        $url = $base . rawurlencode($f['stored']);
        if (RecordsRepository::isImage($f['stored'])) {
            echo '<a class="filethumb" target="_blank" rel="noopener" href="' . h($url) . '"'
               . ' title="' . h($f['name']) . '">'
               . '<img src="' . h($url) . '" alt="' . h($f['name']) . '" loading="lazy"></a>';
        } else {
            echo '<a class="file" target="_blank" rel="noopener" href="' . h($url) . '">'
               . '<span class="fileglyph">FILE</span>' . h($f['name']) . '</a>';
        }
    }
    echo '</div>';
}

/** Command: echo one performance record's attachments, inline beneath its row. */
function renderPerformanceFiles(array $files, $span)
{
    echo '<tr class="exprow"><td colspan="' . (int)$span . '"><div class="taskbox">';
    renderFiles($files, RecordsRepository::PERFORMANCE_PATH);
    echo '</div></td></tr>';
}

/** Command: echo one memo's body and attachments, inline beneath its row. */
function renderMemoBody($memo, array $attachments, array $names, $span)
{
    echo '<tr class="exprow"><td colspan="' . (int)$span . '">';
    if ($memo === null) {
        echo '<div class="taskbox"><div class="empty">This memo is not available.</div></div></td></tr>';
        return;
    }
    echo '<div class="taskbox">';
    echo '<div class="memometa"><span class="k">CC</span> '
       . h(recipientList($memo['staff_memo_cc_staff_id'], $names)) . '</div>';
    echo '<div class="memobody">' . nl2br(h($memo['staff_memo_content'])) . '</div>';

    if (!empty($attachments)) {
        renderFiles($attachments, RecordsRepository::MEMO_PATH);
    }
    echo '</div></td></tr>';
}

/* ---------- controller ---------------------------------------------------- */

/** Query: one query-string value, as a trimmed string.
 *  `?q[]=x` arrives as an array and every read below assumes a scalar, so
 *  anything that is not a string is simply absent. */
function param($key)
{
    return (isset($_GET[$key]) && is_string($_GET[$key])) ? trim($_GET[$key]) : '';
}

$error = null;

$viewerPerson = '';
foreach (array('person', 'username') as $k) {
    if (isset($currentUser[$k]) && $currentUser[$k] !== '') { $viewerPerson = $currentUser[$k]; break; }
}
$viewerCompId = (isset($currentUser['comp_id']) && $currentUser['comp_id'] !== '')
    ? (int)$currentUser['comp_id'] : 0;

/** @var AccessScope|null Resolved once; every read below is scoped through it. */
$scope     = null;
$companies = array();      // comp_id => display name, granted companies only

try {
    $accessRepo = new AccessRepository(Db::get());
    $grant      = $accessRepo->grantFor($viewerPerson, $viewerCompId);

    if ($grant !== null) {
        $liveIds = array();
        $names   = array();
        foreach ($accessRepo->companies() as $c) {
            $names[(int)$c['comp_id']] = $c['display_name'];
            if ((int)$c['is_live'] === 1) { $liveIds[] = (int)$c['comp_id']; }
        }

        $scope = AccessScope::fromGrant($grant, $liveIds, $SECTION_ALIASES, array_keys($SECTIONS));

        foreach ($scope->compIds() as $id) {
            $companies[$id] = isset($names[$id]) ? $names[$id] : ('Company ' . $id);
        }
    }
} catch (Exception $e) {
    error_log('mkCommand staffOverview access: ' . $e->getMessage());
    $error = 'Could not check your access. Check the error log.';
}

/* Not granted: say so and stop. Nothing below this point runs a data query. */
if ($scope === null || empty($companies)) {
    $denied = ($error === null);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>MK Command — Staff Overview</title>
        <style>
            body { margin:0; padding:24px; background:#f1f5f9; color:#1e293b;
                   font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; }
            .main-content { margin-left:260px; }
            @media (max-width:768px) { .main-content { margin-left:0; } }
            .card { max-width:640px; margin:0 auto; background:#fff; border:1px solid #e2e8f0;
                    border-radius:12px; padding:20px; border-left:4px solid #dc2626; }
            h1 { font-size:18px; margin:0 0 8px; }
            p { margin:0; color:#64748b; }
        </style>
    </head>
    <body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="card">
            <h1><?php echo $denied ? 'No access to this page' : 'Something went wrong'; ?></h1>
            <p><?php echo $denied
                ? 'MK Command Staff Overview is limited to admin and HR. Ask an administrator to add you to mk_command_access.'
                : h($error); ?></p>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

/* --- request ------------------------------------------------------------- */

$grantedIds = $scope->compIds();

/**
 * Which company the directory is filtered to. null means every granted company.
 *
 * The default is the viewer's OWN company: an admin over seven subsidiaries
 * opens on the several hundred people they actually work with rather than on
 * two thousand rows they have to filter down before the page is of any use.
 *
 * 'all' is the explicit opt-out, spelled out for the same reason the year
 * parameter spells it out: the empty string cannot mean "all companies" here,
 * because selfUrl() drops empty parameters and the <select> submits an empty
 * value — so an empty comp_id would come back as "absent", fall through to this
 * default, and the option could never be chosen at all.
 *
 * A comp_id outside the grant is not honoured; nor is the viewer's own company
 * when the grant does not cover it (a grant may name other subsidiaries and not
 * the grantee's own). Either falls back to the whole grant.
 */
$compParam = param('comp_id');
if ($compParam === 'all') {
    $compId = null;
} elseif (ctype_digit($compParam) && $scope->coversCompany($compParam)) {
    $compId = (int)$compParam;
} elseif ($scope->coversCompany($viewerCompId)) {
    $compId = $viewerCompId;
} else {
    $compId = null;
}
$activeCompIds = ($compId === null) ? $grantedIds : array($compId);

$search = param('q');
$sort   = param('sort') !== '' ? param('sort') : 'name';

$targetPerson = param('person');
$targetComp   = ctype_digit(param('scomp')) ? (int)param('scomp') : null;
/* A GW code lives in a different namespace from users.person and can collide
 * with one, so which population is being opened has to be said explicitly
 * rather than guessed from the code. */
$targetIsGw   = (param('gw') === '1');
/* Merit/Demerit is read a year at a time, and the year defaults to the current
 * one — matching the app's Merit/Demerit screen, which opens on it too. 'all' is
 * the explicit opt-out; an absent or unparseable value is never silently taken
 * as "all", because an all-time demerit tally reads very differently from this
 * year's and HR would have no way to tell which they were looking at. */
$yearParam = param('year');
if ($yearParam === 'all') {
    $year = '';
} elseif (preg_match('/^\d{4}$/', $yearParam)) {
    $year = $yearParam;
} else {
    $year = date('Y');
}
$yearLabel = ($year === '') ? 'all-time' : $year;

/* --- AJAX ---------------------------------------------------------------- */
/* Both branches re-check scope from scratch. An expandable row is a request like
 * any other: it must not be readable just because the id was guessed. */

if (param('ajax') !== '') {
    header('Content-Type: text/html; charset=UTF-8');
    try {
        if (param('ajax') === 'tasks' && $scope->sees('jobspec')) {
            $versionId = (int)param('version');
            $jobSpec = new JobSpecRepository(Db::get());
            $owner   = $versionId > 0 ? $jobSpec->versionOwner($versionId) : null;
            if ($owner === null || !$scope->coversCompany($owner['comp_id'])) {
                throw new RuntimeException('Out of scope');
            }
            renderTasks($jobSpec->itemsFor($versionId), 7);
            exit;
        }

        if (param('ajax') === 'memo' && $scope->sees('memo')) {
            $memoId = (int)param('memo');
            $staffRepo = new StaffRepository(Db::get());
            $staff = ($targetPerson !== '' && $targetComp !== null && $scope->coversCompany($targetComp))
                ? $staffRepo->profile($targetPerson, $targetComp)
                : null;
            if ($staff === null || $memoId <= 0) {
                throw new RuntimeException('Out of scope');
            }
            $records = new RecordsRepository(Db::get());
            $memo    = $records->memoFor($staff['id'], $memoId);
            if ($memo === null) {
                throw new RuntimeException('Not addressed to this staff');
            }
            $ccNames = $records->namesByIds(
                collectRecipientIds(array($memo), array('staff_memo_cc_staff_id'))
            );
            renderMemoBody($memo, $records->memoAttachments($memoId), $ccNames, 6);
            exit;
        }

        if (param('ajax') === 'files' && $scope->sees('merit')) {
            $recordId  = (int)param('record');
            $staffRepo = new StaffRepository(Db::get());
            $staff = ($targetPerson !== '' && $targetComp !== null && $scope->coversCompany($targetComp))
                ? $staffRepo->profile($targetPerson, $targetComp)
                : null;
            if ($staff === null || $recordId <= 0) {
                throw new RuntimeException('Out of scope');
            }
            $records = new RecordsRepository(Db::get());
            renderPerformanceFiles($records->performanceAttachmentsFor($staff['id'], $recordId), 7);
            exit;
        }

        throw new RuntimeException('Unknown request');
    } catch (Exception $e) {
        error_log('mkCommand staffOverview ajax: ' . $e->getMessage());
        http_response_code(400);
        echo '<tr class="exprow"><td colspan="7"><div class="taskbox">'
           . '<div class="empty">Could not load this.</div></div></td></tr>';
        exit;
    }
}

/* --- page data ----------------------------------------------------------- */

$staff        = null;   // the open staff member, when there is one
$superiors    = array();
$subordinates = array();
$hierarchy    = array('total_superiors' => 0, 'direct_superiors' => 0,
                      'total_subordinates' => 0, 'direct_subordinates' => 0);
$versions     = array();
$apps         = array();
$memos        = array();
$memoNames    = array();
$performance  = array();
$perfYears    = array();
$perfFiles    = array();
$issuerNames  = array();
$directoryYears = array();
$people       = array();   // the directory: staff
$gwPeople     = array();   // the directory: General Workers, listed after them
$supervisors  = array();   // a GW's supervisors (they have no chart of their own)

try {
    /* A General Worker's page: everything MK Command holds on them is their
     * assignment record and their job spec. Memo, merit and application records
     * all address a users.id, which a GW does not have, so those sections are
     * absent here rather than rendered empty. */
    if ($targetIsGw && $targetPerson !== '' && $targetComp !== null
        && $scope->coversCompany($targetComp)) {

        $gwRepo = new GwRepository(Db::get());
        $staff  = $gwRepo->profile($targetPerson, $targetComp);

        if ($staff !== null) {
            if ($scope->sees('staff')) {
                $supervisors = $gwRepo->supervisorsOf($targetPerson, $targetComp);
                $hierarchy['total_superiors']  = count($supervisors);
                $hierarchy['direct_superiors'] = count($supervisors);
            }
            if ($scope->sees('jobspec')) {
                $versions = (new JobSpecRepository(Db::get()))
                    ->versions($targetPerson, $targetComp, 1);
            }
        }
    } elseif ($targetPerson !== '' && $targetComp !== null && $scope->coversCompany($targetComp)) {
        $staffRepo = new StaffRepository(Db::get());
        $staff     = $staffRepo->profile($targetPerson, $targetComp);

        if ($staff !== null) {
            if ($scope->sees('staff')) {
                $hier  = new HierarchyService($staffRepo, new GwRepository(Db::get()));
                $up    = $hier->superiorChain($targetPerson, $targetComp);
                $down  = $hier->subordinateTree($targetPerson, $targetComp);

                $superiors    = $up['chain'];
                $subordinates = $down['tree'];
                $hierarchy = array(
                    'total_superiors'     => count($up['chain']),
                    'direct_superiors'    => $up['direct'],
                    'total_subordinates'  => $down['total'],
                    'direct_subordinates' => $down['direct'],
                );
            }
            if ($scope->sees('jobspec')) {
                $versions = (new JobSpecRepository(Db::get()))->versions($targetPerson, $targetComp);
            }
            if ($scope->sees('app')) {
                $apps = (new AppRepository(Db::get()))->appsFor($targetPerson, $targetComp);
            }
            if ($scope->sees('memo') || $scope->sees('merit')) {
                $records = new RecordsRepository(Db::get());
                if ($scope->sees('memo')) {
                    $memos = $records->memosFor($staff['id']);
                    $memoNames = $records->namesByIds(
                        collectRecipientIds($memos, array('staff_memo_to_staff_id'))
                    );
                }
                if ($scope->sees('merit')) {
                    $perfYears   = yearOptions($records->performanceYears($staff['id']), $year);
                    $performance = $records->performanceFor($staff['id'], $year);
                    $ids = array();
                    $issuers = array();
                    foreach ($performance as $p) {
                        $ids[] = (int)$p['id'];
                        if (ctype_digit(trim((string)$p['issued_by']))) {
                            $issuers[] = (int)$p['issued_by'];
                        }
                    }
                    $perfFiles   = $records->performanceAttachmentCounts($ids);
                    $issuerNames = $records->namesByIds($issuers);
                }
            }
        }
    }

    if ($staff === null) {
        $dir    = new DirectoryRepository(Db::get());
        $people = $dir->staff($activeCompIds, $search);

        $specs  = $scope->sees('jobspec') ? $dir->latestJobSpecs($activeCompIds) : array();
        $appN   = $scope->sees('app')     ? $dir->appCounts($activeCompIds)
                                      : array('personal' => array(), 'company' => array());
        $memoN  = $scope->sees('memo')    ? $dir->memoCounts()                  : array('per' => array(), 'broadcast' => 0);
        $merits = $scope->sees('merit')   ? $dir->meritTotals($activeCompIds, $year) : array();
        if ($scope->sees('merit')) {
            $directoryYears = yearOptions($dir->performanceYears($activeCompIds), $year);
        }

        foreach ($people as $i => $p) {
            $key = $p['person'] . '|' . (int)$p['comp_id'];
            $uid = (int)$p['id'];

            $people[$i]['spec'] = isset($specs[$key])
                ? $specs[$key]
                : DirectoryRepository::NO_JOB_SPEC;
            // Personal assignments unioned with the company-wide ones, so an app
            // held both ways counts once.
            $mine = isset($appN['personal'][$key]) ? $appN['personal'][$key] : array();
            $ours = isset($appN['company'][(int)$p['comp_id']]) ? $appN['company'][(int)$p['comp_id']] : array();
            $people[$i]['apps'] = count(array_unique(array_merge($mine, $ours)));
            $people[$i]['memos'] = (isset($memoN['per'][$uid]) ? $memoN['per'][$uid] : 0) + $memoN['broadcast'];

            $m = isset($merits[$uid])
                ? $merits[$uid]
                : array('merits' => 0, 'demerits' => 0, 'merit_points' => 0, 'demerit_points' => 0);
            $people[$i]['merits']   = $m['merits'];
            $people[$i]['demerits'] = $m['demerits'];
            $people[$i]['net']      = $m['merit_points'] - $m['demerit_points'];
        }

        /* General Workers, listed after the staff rather than mixed in with
         * them: their record is a different shape (a job spec and nothing else),
         * and running the two populations together would imply four columns of
         * zeroes mean the same thing for both. */
        $gwSpecs = array();
        if (in_array(GwRepository::COMP_ID, $activeCompIds, true)) {
            $gwPeople = (new GwRepository(Db::get()))->directory(
                array(GwRepository::COMP_ID), $search);
            $gwSpecs = $scope->sees('jobspec')
                ? $dir->latestJobSpecs(array(GwRepository::COMP_ID), 1)
                : array();
        }
        foreach ($gwPeople as $i => $g) {
            $key = $g['person'] . '|' . (int)$g['comp_id'];
            $gwPeople[$i]['spec'] = isset($gwSpecs[$key])
                ? $gwSpecs[$key]
                : DirectoryRepository::NO_JOB_SPEC;
        }

        // Sorted here rather than in SQL: three of the four keys are assembled in
        // PHP from separate aggregates, so one comparator covers all of them and
        // the directory can never sort by a column it did not build.
        if ($sort !== 'name') {
            $bySort = function ($a, $b) use ($sort) {
                // Sorts on what the column now shows: whose CURRENT spec is
                // waiting on somebody. A tally of historic pending versions
                // would order the list by a number that is no longer on screen.
                if ($sort === 'pending') {
                    $isPending = function ($row) {
                        return (isset($row['spec']['status']) && $row['spec']['status'] === 'pending') ? 1 : 0;
                    };
                    $d = $isPending($b) - $isPending($a);
                }
                elseif ($sort === 'demerits') {
                    // GW carry no merit record at all, so the key may be absent.
                    $d = (isset($b['demerits']) ? $b['demerits'] : 0)
                       - (isset($a['demerits']) ? $a['demerits'] : 0);
                } elseif ($sort === 'memos') {
                    $d = (isset($b['memos']) ? $b['memos'] : 0)
                       - (isset($a['memos']) ? $a['memos'] : 0);
                } else { $d = 0; }
                return $d !== 0 ? $d : strcasecmp($a['fullname'], $b['fullname']);
            };
            usort($people, $bySort);
            usort($gwPeople, $bySort);
        }
    }
} catch (Exception $e) {
    error_log('mkCommand staffOverview: ' . $e->getMessage());
    $error = 'Could not load this page. Check the error log.';
}

/* Detail totals, for the tiles at the top of the staff page. */
$specTotals = array('pending' => 0, 'approved' => 0, 'rejected' => 0);
foreach ($versions as $v) { $specTotals[$v['status']]++; }

$meritCount = 0;
$demeritCount = 0;
$meritPoints = 0;
$demeritPoints = 0;
foreach ($performance as $p) {
    $pts = (int)$p['points'];   // blank points cast to 0 and add nothing
    if (isDemerit($p['merit_demerit'])) { $demeritCount++; $demeritPoints += $pts; }
    else                                { $meritCount++;   $meritPoints   += $pts; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MK Command — Staff Overview</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
    :root {
        --bg:#f1f5f9; --surface:#fff; --primary:#2563eb; --success:#059669;
        --danger:#dc2626; --warn:#b45309; --text:#1e293b; --muted:#64748b; --border:#e2e8f0;
    }
    * { box-sizing:border-box; }
    body { margin:0; padding:24px; background:var(--bg); color:var(--text);
           font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; }
    .wrap { max-width:1180px; margin:0 auto; }
    .main-content { margin-left:260px; transition:margin-left .3s ease; }
    .main-content.sidebar-collapsed { margin-left:60px; }
    @media (max-width:1024px) { .main-content { margin-left:60px; } }
    @media (max-width:768px)  { .main-content { margin-left:0 !important; } body { padding:12px; } }
    h1 { font-size:20px; margin:0 0 4px; }
    h2 { font-size:16px; margin:0; }
    .sub { color:var(--muted); font-size:13px; margin-bottom:20px; }
    .card { background:var(--surface); border:1px solid var(--border); border-radius:12px;
            padding:16px; margin-bottom:16px; }
    .filters { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:16px; }
    select, input[type=search], .btn { font:inherit; padding:7px 12px; border-radius:8px;
                   border:1px solid var(--border); background:var(--surface); color:var(--text); }
    input[type=search] { min-width:240px; }
    .btn { text-decoration:none; display:inline-block; cursor:pointer; }
    .btn.on { background:var(--primary); border-color:var(--primary); color:#fff; font-weight:600; }
    .tiles { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
    .tile { flex:1; min-width:140px; background:var(--surface); border:1px solid var(--border);
            border-radius:12px; padding:14px; }
    .tile .n { font-size:26px; font-weight:700; line-height:1.1; }
    .tile .l { font-size:12px; color:var(--muted); margin-top:2px; }
    .n.pending  { color:var(--warn); }
    .n.approved { color:var(--success); }
    .n.rejected { color:var(--danger); }
    .n.good { color:var(--success); }
    .n.bad  { color:var(--danger); }
    table { width:100%; border-collapse:collapse; }
    th { text-align:left; font-size:12px; text-transform:uppercase; letter-spacing:.03em;
         color:var(--muted); padding:8px 10px; border-bottom:2px solid var(--border); }
    td { padding:9px 10px; border-bottom:1px solid var(--border); vertical-align:middle; }
    tr:last-child td { border-bottom:none; }
    .num { text-align:right; font-variant-numeric:tabular-nums; }
    .idx { color:var(--muted); font-variant-numeric:tabular-nums; width:38px; }
    .pill { display:inline-block; padding:2px 9px; border-radius:99px; font-size:11.5px; font-weight:700; }
    .pill.pending  { background:rgba(180,83,9,.10);  color:var(--warn); }
    .pill.approved { background:rgba(5,150,105,.10); color:var(--success); }
    .pill.rejected { background:rgba(220,38,38,.08); color:var(--danger); }
    .pill.merit    { background:rgba(5,150,105,.10); color:var(--success); }
    .pill.demerit  { background:rgba(220,38,38,.08); color:var(--danger); }
    .pill.role     { background:rgba(37,99,235,.08); color:var(--primary); margin-right:4px; }
    .pill.off      { background:rgba(100,116,139,.12); color:var(--muted); }
    .pill.grant    { background:rgba(124,58,237,.10); color:#7c3aed; }
    .warnbox { border-left:4px solid var(--danger); background:rgba(220,38,38,.06); }
    .empty { color:var(--muted); text-align:center; padding:26px 0; }
    a { color:var(--primary); }
    .muted { color:var(--muted); font-size:12px; }
    .rowlink { cursor:pointer; }
    .rowlink:hover > td { background:rgba(37,99,235,.04); }
    .who { display:flex; align-items:center; gap:10px; }
    .mono { width:34px; height:34px; border-radius:10px; flex:none; color:#fff;
            font-weight:800; font-size:12.5px; display:flex; align-items:center; justify-content:center; }
    .mono.lg { width:56px; height:56px; border-radius:14px; font-size:19px; }
    .name { font-weight:600; }

    /* detail */
    .backbar { display:flex; align-items:center; gap:10px; margin-bottom:14px; }
    .staffhead { display:flex; align-items:center; gap:14px; margin-bottom:6px; }
    .staffhead h1 { margin:0; }
    .navbar { display:flex; gap:8px; flex-wrap:wrap; margin:0 0 18px; }
    .navbar a { text-decoration:none; font-size:13px; font-weight:600; padding:6px 12px;
                border:1px solid var(--border); border-radius:99px; background:var(--surface); }
    .sectionhead { display:flex; align-items:baseline; gap:10px; margin:26px 0 10px;
                   padding-top:18px; border-top:2px solid var(--border); }
    .sectionhead .count { color:var(--muted); font-size:12.5px; }
    .fields { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:14px 20px; }
    .field .k { font-size:10.5px; font-weight:800; letter-spacing:.06em; color:var(--muted);
                text-transform:uppercase; }
    .field .v { font-size:14px; word-break:break-word; }
    .people { display:flex; flex-wrap:wrap; gap:10px; }
    .person { display:flex; align-items:center; gap:9px; border:1px solid var(--border);
              border-radius:10px; padding:8px 12px; text-decoration:none; color:inherit;
              background:var(--surface); }
    .person:hover { border-color:var(--primary); }
    /* numbered hierarchy listing; the browser owns the 1 / 1.1 / 1.2.1 numbering */
    .cardhead { display:flex; align-items:center; justify-content:space-between; gap:12px; }
    .grouphead > td { background:var(--bg); font-size:12px; font-weight:800;
                      letter-spacing:.04em; text-transform:uppercase; color:var(--muted);
                      padding:8px 10px; }
    .grouphead .muted { text-transform:none; letter-spacing:0; font-weight:500; }
    .tree { counter-reset:node; list-style:none; margin:10px 0 0; padding:0; }
    .tree ol { counter-reset:node; list-style:none; margin:0 0 4px; padding:0 0 0 12px;
               margin-left:19px; border-left:1px solid var(--border); }
    .tree li { counter-increment:node; }
    .tree summary { list-style:none; cursor:pointer; }
    .tree summary::-webkit-details-marker { display:none; }
    .treerow { display:flex; align-items:center; gap:10px; padding:5px 8px;
               border-radius:8px; }
    .treerow:hover { background:rgba(37,99,235,.05); }
    .treenum::before { content:counters(node, ".") "."; }
    .treenum { color:var(--muted); font-size:11.5px; font-variant-numeric:tabular-nums;
               min-width:40px; text-align:right; flex:none; }
    .treetext { flex:1; min-width:0; }
    .treename { font-weight:600; color:var(--text); text-decoration:none; }
    a.treename:hover { text-decoration:underline; }
    .treemeta { display:block; color:var(--muted); font-size:12px;
                white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .treepills { display:flex; gap:6px; align-items:center; flex:none; }
    .caret { flex:none; width:12px; text-align:center; color:var(--muted); font-size:10px;
             transition:transform .15s ease; }
    details[open] > summary .caret { transform:rotate(90deg); }
    .mono.sm { width:30px; height:30px; border-radius:9px; font-size:11px; }
    .mono.gwmono { background:#e2e8f0; color:#64748b; }
    .pill.top  { background:rgba(15,23,42,.85); color:#fff; }
    .pill.gw   { background:rgba(202,138,4,.12); color:#ca8a04; }
    .pill.team { background:rgba(37,99,235,.08); color:var(--primary);
                 min-width:22px; text-align:center; }

    /* inline expansion */
    .rowopen > td { background:rgba(37,99,235,.04); }
    .exprow > td { background:rgba(37,99,235,.04); padding:0 10px 12px; }
    .taskbox { border:1px solid var(--border); border-radius:10px; background:var(--surface);
               padding:10px 12px; }
    .tasklist { margin:0; padding-left:22px; }
    .tasklist li { padding:5px 0; border-bottom:1px solid var(--border); }
    .tasklist li:last-child { border-bottom:none; }
    .cat { display:inline-block; padding:1px 7px; margin-right:6px; border-radius:99px;
           background:rgba(37,99,235,.08); color:var(--primary); font-size:11px; font-weight:700; }
    .explink { font-weight:600; text-decoration:none; border-bottom:1px dashed var(--primary); }
    .explink.open { color:var(--text); border-bottom-color:var(--muted); }
    .memometa { font-size:12.5px; color:var(--muted); margin-bottom:8px; }
    .memometa .k { font-weight:800; letter-spacing:.06em; margin-right:6px; }
    .memobody { white-space:normal; line-height:1.6; }
    .files { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
    .file { display:inline-flex; align-items:center; gap:8px; border:1px solid var(--border);
            border-radius:8px; padding:6px 10px; font-size:12.5px; text-decoration:none; }
    .fileglyph { font-size:10px; font-weight:800; letter-spacing:.04em; color:var(--muted); }
    .filethumb { display:block; width:104px; height:104px; border:1px solid var(--border);
                 border-radius:8px; overflow:hidden; background:var(--bg); }
    .filethumb img { width:100%; height:100%; object-fit:cover; display:block; }
    .filethumb:hover { border-color:var(--primary); }
    @media print {
        /* A folded team is folded on screen only — a printed record has to carry
           the whole chart. */
        .tree details > *:not(summary) { display:block !important; }
        .caret { display:none; }
        body { padding:0; background:#fff; }
        .main-content { margin-left:0 !important; }
        .filters, .navbar, .backbar, .btn { display:none; }
    }
</style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
<div class="wrap">

<?php if ($error !== null): ?>
    <div class="card warnbox"><?php echo h($error); ?></div>

<?php elseif ($staff !== null): /* ============ ONE STAFF ==================== */
    /* A GW has no users.id, so the three record sections that address one do not
     * exist for them. Deciding it once here keeps the nav, the tiles and the
     * sections from ever disagreeing about which of them are on the page. */
    $sections = $targetIsGw
        ? array('staff' => 'General Worker', 'jobspec' => $SECTIONS['jobspec'])
        : $SECTIONS;
?>

    <div class="backbar">
        <a class="btn" href="<?php echo h(selfUrl(array('person' => null, 'scomp' => null, 'gw' => null))); ?>">&larr; All staff</a>
        <span class="muted">MK Command record</span>
    </div>

    <div class="staffhead">
        <?php if ($targetIsGw): ?>
            <div class="mono lg gwmono"><?php echo h(initialsOf($staff['fullname'])); ?></div>
        <?php else: ?>
            <div class="mono lg" style="background:<?php echo h(colorFor($staff['fullname'])); ?>">
                <?php echo h(initialsOf($staff['fullname'])); ?>
            </div>
        <?php endif; ?>
        <div>
            <h1><?php echo h(displayName($staff['fullname'], $staff['person'])); ?>
                <?php if ($targetIsGw): ?><span class="pill gw">GW</span><?php endif; ?></h1>
            <div class="sub" style="margin:0">
                <?php echo h($staff['person']); ?> &middot;
                <?php echo h($staff['company_name']); ?>
                <?php if ($targetIsGw): ?>
                    &middot; General Worker
                <?php elseif (!empty($staff['position'])): ?>
                    &middot; <?php echo h(titleCase($staff['position'])); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="navbar">
        <?php foreach ($sections as $key => $label): ?>
            <?php if ($scope->sees($key)): ?>
                <a href="#sec-<?php echo h($key); ?>"><?php echo h($label); ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="tiles">
        <?php if ($scope->sees('jobspec')): ?>
        <div class="tile"><div class="n <?php echo $specTotals['pending'] ? 'pending' : ''; ?>"><?php echo (int)$specTotals['pending']; ?></div>
            <div class="l">Job specs pending</div></div>
        <div class="tile"><div class="n approved"><?php echo (int)$specTotals['approved']; ?></div>
            <div class="l">Job specs approved</div></div>
        <?php endif; ?>
        <?php if (!$targetIsGw && $scope->sees('app')): ?>
        <div class="tile"><div class="n"><?php echo count($apps); ?></div>
            <div class="l">Applications</div></div>
        <?php endif; ?>
        <?php if (!$targetIsGw && $scope->sees('memo')): ?>
        <div class="tile"><div class="n"><?php echo count($memos); ?></div>
            <div class="l">Memos received</div></div>
        <?php endif; ?>
        <?php /* These two are year-scoped while the tiles beside them are not,
                 so each says which year it is counting — a bare "1 Demerit"
                 next to an all-time job spec count invites the wrong reading. */ ?>
        <?php if (!$targetIsGw && $scope->sees('merit')): ?>
        <div class="tile"><div class="n good"><?php echo (int)$meritCount; ?></div>
            <div class="l">Merits <?php echo h($yearLabel); ?><?php echo $meritPoints ? ' (+' . (int)$meritPoints . ' pts)' : ''; ?></div></div>
        <div class="tile"><div class="n <?php echo $demeritCount ? 'bad' : ''; ?>"><?php echo (int)$demeritCount; ?></div>
            <div class="l">Demerits <?php echo h($yearLabel); ?><?php echo $demeritPoints ? ' (-' . (int)$demeritPoints . ' pts)' : ''; ?></div></div>
        <?php endif; ?>
    </div>

    <?php /* ---- 1. Staff ---- */ if ($scope->sees('staff')): ?>
    <div class="sectionhead" id="sec-staff">
        <h2><?php echo $targetIsGw ? 'General Worker' : 'Staff'; ?></h2>
        <span class="count">Profile and reporting line</span>
    </div>

    <?php if ($targetIsGw): ?>
        <div class="card">
            <div class="fields">
                <?php
                $fields = array(
                    'ID'         => $staff['id'],          // monthly_assign_gw_id
                    'Code'       => $staff['person'],      // monthly_assign_gw_code
                    'Full name'  => titleCase($staff['fullname']),
                    'Company'    => $staff['company_name'],
                    'District'   => $staff['district'],    // a code; printed as recorded
                    'Mobile'     => $staff['mobile_no'],
                    'Joined'     => fmtDayFirst($staff['joined_date']),
                );
                foreach ($fields as $k => $v): ?>
                    <div class="field">
                        <div class="k"><?php echo h($k); ?></div>
                        <div class="v"><?php echo ($v === null || trim((string)$v) === '') ? '—' : h($v); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="muted" style="margin-top:14px">
                A General Worker has no staff account, so MK Command holds no memo,
                merit/demerit or application record for them — only this assignment
                and their job spec.
            </div>
        </div>

        <div class="card">
            <h2 style="font-size:14px">Supervisors
                <span class="muted">(<?php echo count($supervisors); ?>)</span></h2>
            <?php if (empty($supervisors)): ?>
                <div class="muted" style="margin-top:10px">No live supervisor on the assignment record.</div>
            <?php else: ?>
                <?php /* Plural on purpose: a GW with two live assignment rows
                         answers to both, and either may approve their job spec. */ ?>
                <ol class="tree">
                    <?php foreach ($supervisors as $sv): ?>
                        <li><?php renderPersonRow($sv); ?></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    <?php else: ?>

    <div class="card">
        <div class="fields">
            <?php
            $fields = array(
                // users.id — what staff_memo and staff_performance address their
                // rows to, so it is the number to quote when tracing a record.
                'ID'                => $staff['id'],
                'Person'            => $staff['person'],
                'Full name'         => titleCase($staff['fullname']),
                'Company'           => $staff['company_name'],
                'Department'        => titleCase($staff['department']),
                'Position'          => titleCase($staff['position']),
                'District'          => $staff['district'],
                'Work email'        => $staff['email'],
                'Personal email'    => $staff['personal_email'],
                'Mobile'            => $staff['mobile_no'],
                'Office'            => $staff['office_no'],
                'Join date'         => fmtDate($staff['join_date']),
                'Last login'        => fmtDate($staff['last_login']),
            );
            foreach ($fields as $k => $v): ?>
                <div class="field">
                    <div class="k"><?php echo h($k); ?></div>
                    <div class="v"><?php echo ($v === null || trim((string)$v) === '') ? '—' : h($v); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="tiles">
        <div class="tile"><div class="n"><?php echo (int)$hierarchy['total_superiors']; ?></div>
            <div class="l">Superiors</div></div>
        <div class="tile"><div class="n approved"><?php echo (int)$hierarchy['direct_subordinates']; ?></div>
            <div class="l">Direct subordinates</div></div>
        <div class="tile"><div class="n"><?php echo (int)$hierarchy['total_subordinates']; ?></div>
            <div class="l">Total subordinates</div></div>
    </div>

    <div class="card">
        <h2 style="font-size:14px">Superiors
            <span class="muted">(<?php echo (int)$hierarchy['total_superiors']; ?><?php
                echo $hierarchy['direct_superiors'] ? ', ' . (int)$hierarchy['direct_superiors'] . ' direct' : ''; ?>)</span></h2>
        <?php if (empty($superiors)): ?>
            <div class="muted" style="margin-top:10px">No live superior on the organization chart.</div>
        <?php else: renderSuperiorChain($superiors); endif; ?>
    </div>

    <div class="card">
        <div class="cardhead">
            <h2 style="font-size:14px">Subordinates
                <span class="muted">(<?php echo (int)$hierarchy['total_subordinates']; ?> total,
                    <?php echo (int)$hierarchy['direct_subordinates']; ?> direct)</span></h2>
            <?php if ((int)$hierarchy['total_subordinates'] > (int)$hierarchy['direct_subordinates']): ?>
                <button class="btn" type="button" data-tree-toggle hidden>Expand all</button>
            <?php endif; ?>
        </div>
        <?php if (empty($subordinates)): ?>
            <div class="muted" style="margin-top:10px">Nobody reports to this staff.</div>
        <?php else: renderSubordinateTree($subordinates); endif; ?>
    </div>
    <?php endif; /* GW / staff flavour */ ?>
    <?php endif; /* sees('staff') */ ?>

    <?php /* ---- 2. Job Spec ---- */ if ($scope->sees('jobspec')): ?>
    <div class="sectionhead" id="sec-jobspec">
        <h2>Job Spec</h2>
        <span class="count"><?php echo count($versions); ?> version<?php echo count($versions) === 1 ? '' : 's'; ?></span>
    </div>
    <div class="card">
        <table>
            <thead>
            <tr><th class="idx">#</th><th>Status</th><th class="num">Tasks</th>
                <th>Submitted</th><th>By</th><th>Reviewed</th><th>By</th></tr>
            </thead>
            <tbody>
            <?php if (empty($versions)): ?>
                <tr><td colspan="7" class="empty">No job spec submitted yet.</td></tr>
            <?php endif; ?>
            <?php $n = 0; foreach ($versions as $v): $n++; ?>
                <tr>
                    <td class="idx"><?php echo $n; ?></td>
                    <td>
                        <span class="pill <?php echo h($v['status']); ?>"><?php echo h($v['status']); ?></span>
                    </td>
                    <td class="num">
                        <?php if ((int)$v['items'] > 0): ?>
                            <a class="explink" href="#"
                               data-span="7"
                               data-url="<?php echo h(ajaxUrl(array('ajax' => 'tasks', 'version' => (int)$v['id']))); ?>"><?php echo (int)$v['items']; ?></a>
                        <?php else: ?>0<?php endif; ?>
                    </td>
                    <td><?php echo h(fmtDate($v['submitted_at'])); ?></td>
                    <td class="muted"><?php echo h($v['submitted_by'] ? $v['submitted_by'] : '—'); ?></td>
                    <td><?php echo h(fmtDate($v['reviewed_at'])); ?></td>
                    <td class="muted"><?php echo h($v['reviewed_by'] ? $v['reviewed_by'] : '—'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php /* ---- 3. App ---- */ if (!$targetIsGw && $scope->sees('app')): ?>
    <div class="sectionhead" id="sec-app">
        <h2>App</h2>
        <span class="count"><?php echo count($apps); ?> application<?php echo count($apps) === 1 ? '' : 's'; ?></span>
    </div>
    <div class="card">
        <table>
            <thead>
            <tr><th class="idx">#</th><th>Application</th><th>Section</th><th>Role</th></tr>
            </thead>
            <tbody>
            <?php if (empty($apps)): ?>
                <tr><td colspan="4" class="empty">No applications assigned.</td></tr>
            <?php endif; ?>
            <?php $n = 0; foreach ($apps as $a): $n++; ?>
                <tr>
                    <td class="idx"><?php echo $n; ?></td>
                    <td>
                        <span class="name"><?php echo h($a['application_title']); ?></span>
                        <?php if ((string)$a['application_status'] === '0'): ?>
                            <span class="pill off" title="application_status = 0">inactive</span>
                        <?php endif; ?>
                    </td>
                    <td class="muted"><?php echo h($a['application_section'] ? $a['application_section'] : 'Other'); ?></td>
                    <td>
                        <?php foreach (array_filter(explode(',', (string)$a['roles'])) as $role): ?>
                            <span class="pill role"><?php echo h($role); ?></span>
                        <?php endforeach; ?>
                        <?php if ((int)$a['company_wide'] === 1): ?>
                            <span class="pill off"
                                  title="application_user.staff_id = 'all' — assigned to the whole company">all staff</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php /* ---- 4. Memo ---- */ if (!$targetIsGw && $scope->sees('memo')): ?>
    <div class="sectionhead" id="sec-memo">
        <h2>Memo</h2>
        <span class="count"><?php echo count($memos); ?> memo<?php echo count($memos) === 1 ? '' : 's'; ?> received</span>
    </div>
    <div class="card">
        <table>
            <thead>
            <tr><th class="idx">#</th><th>Subject</th><th>From</th><th>To</th><th>Date</th><th>Ref</th></tr>
            </thead>
            <tbody>
            <?php if (empty($memos)): ?>
                <tr><td colspan="6" class="empty">No memos addressed to this staff.</td></tr>
            <?php endif; ?>
            <?php $n = 0; foreach ($memos as $m): $n++; ?>
                <tr>
                    <td class="idx"><?php echo $n; ?></td>
                    <td>
                        <a class="explink" href="#"
                           data-span="6"
                           data-url="<?php echo h(ajaxUrl(array(
                               'ajax'   => 'memo',
                               'memo'   => (int)$m['staff_memo_id'],
                               'person' => $staff['person'],
                               'scomp'  => (int)$staff['comp_id'],
                           ))); ?>"><?php echo h($m['staff_memo_subject'] ? $m['staff_memo_subject'] : '(No subject)'); ?></a>
                    </td>
                    <td class="muted"><?php echo h($m['staff_memo_from'] ? $m['staff_memo_from'] : '—'); ?></td>
                    <td class="muted"><?php echo h(recipientList($m['staff_memo_to_staff_id'], $memoNames)); ?></td>
                    <td><?php echo h(fmtDayFirst($m['staff_memo_date'])); ?></td>
                    <td class="muted"><?php echo h($m['staff_memo_ref'] ? $m['staff_memo_ref'] : '—'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php /* ---- 5. Merit / Demerit ---- */ if (!$targetIsGw && $scope->sees('merit')): ?>
    <div class="sectionhead" id="sec-merit">
        <h2>Merit / Demerit</h2>
        <span class="count"><?php echo count($performance); ?> record<?php echo count($performance) === 1 ? '' : 's'; ?></span>
    </div>

    <div class="filters">
        <form method="get">
            <?php /* Everything else about the current view rides along, so
                      changing the year does not silently drop the company
                      filter, the search or the sort on the way back out. */ ?>
            <?php foreach (array('person' => $staff['person'], 'scomp' => (int)$staff['comp_id'],
                                 // 'all' rather than null: an absent comp_id now
                                 // means "the viewer's own company", so dropping
                                 // it here would quietly re-filter the directory
                                 // on the way back out.
                                 'comp_id' => ($compId === null ? 'all' : $compId),
                                 'q' => $search, 'sort' => $sort) as $k => $v): ?>
                <?php if ($v !== null && $v !== '') : ?>
                    <input type="hidden" name="<?php echo h($k); ?>" value="<?php echo h($v); ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <select name="year" onchange="this.form.submit()">
                <option value="all" <?php echo ($year === '' ? 'selected' : ''); ?>>All years</option>
                <?php foreach ($perfYears as $y): ?>
                    <option value="<?php echo h($y); ?>" <?php echo ($year === $y ? 'selected' : ''); ?>><?php echo h($y); ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <span class="muted">
            <?php echo (int)$meritCount; ?> merit &middot; <?php echo (int)$demeritCount; ?> demerit
            <?php if ($meritPoints || $demeritPoints): ?>
                &middot; net <?php echo ($meritPoints - $demeritPoints > 0 ? '+' : '') . ($meritPoints - $demeritPoints); ?> pts
            <?php endif; ?>
        </span>
    </div>

    <div class="card">
        <table>
            <thead>
            <tr><th class="idx">#</th><th>Title</th><th>Type</th><th class="num">Points</th>
                <th>Date</th><th>Issued by</th><th class="num">Files</th></tr>
            </thead>
            <tbody>
            <?php if (empty($performance)): ?>
                <tr><td colspan="7" class="empty">No merit or demerit records<?php echo $year !== '' ? ' for ' . h($year) : ''; ?>.</td></tr>
            <?php endif; ?>
            <?php $n = 0; foreach ($performance as $p): $n++; $dem = isDemerit($p['merit_demerit']); ?>
                <tr>
                    <td class="idx"><?php echo $n; ?></td>
                    <td><span class="name"><?php echo h($p['title'] ? $p['title'] : '(Untitled)'); ?></span>
                        <?php if (!empty($p['ref'])): ?><div class="muted"><?php echo h($p['ref']); ?></div><?php endif; ?></td>
                    <td><span class="pill <?php echo $dem ? 'demerit' : 'merit'; ?>"><?php echo $dem ? 'Demerit' : 'Merit'; ?></span></td>
                    <td class="num" style="color:<?php echo $dem ? 'var(--danger)' : 'var(--success)'; ?>">
                        <?php /* A blank points column means the record carries no score —
                                 showing it as 0 would read as "scored, and scored nothing". */ ?>
                        <?php echo trim((string)$p['points']) === ''
                            ? '<span class="muted">—</span>'
                            : h(($dem ? '-' : '+') . (int)$p['points']); ?></td>
                    <td><?php echo h(fmtYearFirst($p['date'])); ?></td>
                    <td class="muted"><?php echo h(issuerName($p['issued_by'], $issuerNames)); ?></td>
                    <?php $files = isset($perfFiles[(int)$p['id']]) ? (int)$perfFiles[(int)$p['id']] : 0; ?>
                    <td class="num">
                        <?php if ($files > 0): ?>
                            <a class="explink" href="#"
                               data-span="7"
                               data-url="<?php echo h(ajaxUrl(array(
                                   'ajax'   => 'files',
                                   'record' => (int)$p['id'],
                                   'person' => $staff['person'],
                                   'scomp'  => (int)$staff['comp_id'],
                               ))); ?>"><?php echo $files; ?></a>
                        <?php else: ?>0<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

<?php elseif ($targetPerson !== ''): /* asked for somebody out of scope or gone */ ?>

    <div class="card warnbox">
        <strong>Staff not available.</strong>
        <div class="muted" style="margin-top:6px">
            They are outside the companies assigned to you, or no longer active in MK Command.
        </div>
        <p><a href="<?php echo h(selfUrl(array('person' => null, 'scomp' => null))); ?>">Back to all staff</a></p>
    </div>

<?php else: /* ================= DIRECTORY ================================== */ ?>

    <h1>MK Command — Staff Overview</h1>
    <div class="sub">
        Every staff member you cover, with their MK Command record. Open one to read it section by section.
        <span class="pill grant"><?php echo h(strtoupper($scope->role())); ?></span>
        <span class="pill grant"><?php echo count($companies); ?> compan<?php echo count($companies) === 1 ? 'y' : 'ies'; ?></span>
    </div>

    <div class="filters">
        <form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center">
            <?php if (count($companies) > 1): ?>
                <select name="comp_id" onchange="this.form.submit()">
                    <option value="all" <?php echo ($compId === null ? 'selected' : ''); ?>>All my companies</option>
                    <?php foreach ($companies as $id => $name): ?>
                        <option value="<?php echo (int)$id; ?>" <?php echo ($compId === (int)$id ? 'selected' : ''); ?>>
                            <?php echo h($name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <input type="search" name="q" value="<?php echo h($search); ?>"
                   placeholder="Search name, login, department or position">
            <?php if ($scope->sees('merit')): ?>
                <select name="year" onchange="this.form.submit()">
                    <?php foreach ($directoryYears as $y): ?>
                        <option value="<?php echo h($y); ?>" <?php echo ($year === $y ? 'selected' : ''); ?>>
                            Merit/Demerit: <?php echo h($y); ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="all" <?php echo ($year === '' ? 'selected' : ''); ?>>Merit/Demerit: all years</option>
                </select>
            <?php endif; ?>
            <select name="sort" onchange="this.form.submit()">
                <option value="name"    <?php echo $sort === 'name'    ? 'selected' : ''; ?>>Sort: name</option>
                <?php if ($scope->sees('jobspec')): ?>
                    <option value="pending" <?php echo $sort === 'pending' ? 'selected' : ''; ?>>Sort: job specs pending</option>
                <?php endif; ?>
                <?php if ($scope->sees('merit')): ?>
                    <option value="demerits" <?php echo $sort === 'demerits' ? 'selected' : ''; ?>>Sort: most demerits</option>
                <?php endif; ?>
                <?php if ($scope->sees('memo')): ?>
                    <option value="memos"   <?php echo $sort === 'memos'   ? 'selected' : ''; ?>>Sort: memos received</option>
                <?php endif; ?>
            </select>
            <button class="btn on" type="submit">Search</button>
            <?php if ($search !== ''): ?>
                <a class="btn" href="<?php echo h(selfUrl(array('q' => null))); ?>">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <table>
            <thead>
            <?php
            /* Counted rather than hard-coded: the columns depend on the grant,
               and a colspan that drifts from them leaves a banded row hanging
               short of the table's edge. */
            $columns = 3   // #, Staff, View
                + (count($companies) > 1 ? 1 : 0)
                + ($scope->sees('jobspec') ? 1 : 0)
                + ($scope->sees('app') ? 1 : 0)
                + ($scope->sees('memo') ? 1 : 0)
                + ($scope->sees('merit') ? 1 : 0);
            ?>
            <tr>
                <th class="idx">#</th>
                <th>Staff</th>
                <?php if (count($companies) > 1): ?><th>Company</th><?php endif; ?>
                <?php if ($scope->sees('jobspec')): ?><th>Job Spec</th><?php endif; ?>
                <?php if ($scope->sees('app')): ?><th class="num">Apps</th><?php endif; ?>
                <?php if ($scope->sees('memo')): ?><th class="num">Memos</th><?php endif; ?>
                <?php if ($scope->sees('merit')): ?><th>Merit / Demerit &middot; <?php echo h($yearLabel); ?></th><?php endif; ?>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($people) && empty($gwPeople)): ?>
                <tr><td colspan="<?php echo (int)$columns; ?>" class="empty">
                    <?php echo $search !== '' ? 'Nobody matches that search.' : 'No staff in this company.'; ?>
                </td></tr>
            <?php endif; ?>
            <?php $n = 0; foreach ($people as $p): $n++;
                $url = selfUrl(array('person' => $p['person'], 'scomp' => (int)$p['comp_id']));
            ?>
                <tr class="rowlink" data-href="<?php echo h($url); ?>">
                    <td class="idx"><?php echo $n; ?></td>
                    <td>
                        <div class="who">
                            <span class="mono" style="background:<?php echo h(colorFor($p['fullname'])); ?>"><?php echo h(initialsOf($p['fullname'])); ?></span>
                            <span>
                                <a class="name" href="<?php echo h($url); ?>"><?php echo h(displayName($p['fullname'], $p['person'])); ?></a>
                                <div class="muted">
                                    <?php echo h($p['person']); ?>
                                    <?php if (!empty($p['position'])): ?> &middot; <?php echo h(titleCase($p['position'])); ?><?php endif; ?>
                                    <?php if (!empty($p['department'])): ?> &middot; <?php echo h(titleCase($p['department'])); ?><?php endif; ?>
                                </div>
                            </span>
                        </div>
                    </td>
                    <?php if (count($companies) > 1): ?>
                        <td class="muted"><?php echo h($p['company_name']); ?></td>
                    <?php endif; ?>
                    <?php if ($scope->sees('jobspec')): ?>
                        <td><?php renderJobSpecCell($p['spec']); ?></td>
                    <?php endif; ?>
                    <?php if ($scope->sees('app')): ?>
                        <td class="num"><?php echo (int)$p['apps']; ?></td>
                    <?php endif; ?>
                    <?php if ($scope->sees('memo')): ?>
                        <td class="num"><?php echo (int)$p['memos']; ?></td>
                    <?php endif; ?>
                    <?php if ($scope->sees('merit')): ?>
                        <td>
                            <?php renderTally($p['merits'], 'merit'); ?>
                            <?php renderTally($p['demerits'], 'demerit'); ?>
                            <?php if ((int)$p['net'] !== 0): ?>
                                <div class="muted"><?php echo ($p['net'] > 0 ? '+' : '') . (int)$p['net']; ?> pts</div>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td class="num"><a href="<?php echo h($url); ?>">View</a></td>
                </tr>
            <?php endforeach; ?>

            <?php /* General Workers, after the staff and behind their own band.
                      Their record is a job spec and nothing else — App, Memos and
                      Merit read "—" rather than 0, because a zero would claim
                      the record exists and is empty. The dash is the same
                      "nothing here" this page uses everywhere else. */ ?>
            <?php if (!empty($gwPeople)): ?>
                <tr class="grouphead">
                    <td colspan="<?php echo (int)$columns; ?>">
                        General Workers &middot; <?php echo count($gwPeople); ?>
                        <span class="muted">&mdash; no staff account, so no memo, merit or app record</span>
                    </td>
                </tr>
                <?php foreach ($gwPeople as $g): $n++;
                    $gurl = selfUrl(array('person' => $g['person'], 'scomp' => (int)$g['comp_id'], 'gw' => 1));
                ?>
                    <tr class="rowlink" data-href="<?php echo h($gurl); ?>">
                        <td class="idx"><?php echo $n; ?></td>
                        <td>
                            <div class="who">
                                <span class="mono gwmono"><?php echo h(initialsOf($g['fullname'])); ?></span>
                                <span>
                                    <a class="name" href="<?php echo h($gurl); ?>"><?php echo h(displayName($g['fullname'], $g['person'])); ?></a>
                                    <span class="pill gw">GW</span>
                                    <div class="muted">
                                        <?php echo h($g['person']); ?>
                                        <?php if (!empty($g['district'])): ?> &middot; <?php echo h($g['district']); ?><?php endif; ?>
                                    </div>
                                </span>
                            </div>
                        </td>
                        <?php if (count($companies) > 1): ?>
                            <td class="muted"><?php echo h($g['company_name']); ?></td>
                        <?php endif; ?>
                        <?php if ($scope->sees('jobspec')): ?>
                            <td><?php renderJobSpecCell($g['spec']); ?></td>
                        <?php endif; ?>
                        <?php if ($scope->sees('app')): ?><td class="num muted">&mdash;</td><?php endif; ?>
                        <?php if ($scope->sees('memo')): ?><td class="num muted">&mdash;</td><?php endif; ?>
                        <?php if ($scope->sees('merit')): ?><td class="muted">&mdash;</td><?php endif; ?>
                        <td class="num"><a href="<?php echo h($gurl); ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="muted">
        <?php echo count($people); ?> staff shown<?php
            echo $gwPeople ? ', plus ' . count($gwPeople) . ' General Workers' : ''; ?>.
        <?php if (!in_array(GwRepository::COMP_ID, $activeCompIds, true)): ?>
            General Workers exist at Globinaco only, so none are listed under this filter.
        <?php endif; ?>
    </div>

<?php endif; ?>

</div>
</div><!-- /main-content -->

<script>
/* Progressive enhancement only.
 *
 * Directory rows: the name and the View link are ordinary links, so the row
 * click is a convenience, never the only way in.
 *
 * Expandable cells (a version's tasks, a memo's body): the fetched HTML comes
 * from the same renderer the server uses, so this file never rebuilds that
 * markup in two places. Once fetched, a row is toggled rather than refetched. */
(function () {
    'use strict';
    if (!window.fetch || !document.documentElement.closest) { return; }

    document.addEventListener('click', function (e) {
        var exp = e.target.closest('a[data-url]');
        if (exp) { e.preventDefault(); toggle(exp); return; }

        // Ignore clicks that were already on a link or a form control.
        if (e.target.closest('a, button, input, select')) { return; }
        var row = e.target.closest('tr.rowlink');
        if (row && row.dataset.href) { window.location = row.dataset.href; }
    });

    /* Expand/collapse all. The button ships hidden and is revealed here: with no
     * JS it would be a control that does nothing, while the tree itself still
     * folds on its own through <details>. */
    var treeToggle = document.querySelector('[data-tree-toggle]');
    if (treeToggle) {
        treeToggle.hidden = false;
        treeToggle.addEventListener('click', function () {
            var open = treeToggle.getAttribute('data-open') !== '1';
            var all = document.querySelectorAll('.tree details');
            for (var i = 0; i < all.length; i++) { all[i].open = open; }
            treeToggle.setAttribute('data-open', open ? '1' : '0');
            treeToggle.textContent = open ? 'Collapse all' : 'Expand all';
        });
    }

    function toggle(link) {
        var row  = link.closest('tr');
        var next = row.nextElementSibling;

        if (next && next.classList.contains('exprow')) {
            var willOpen = next.hasAttribute('hidden');
            if (willOpen) { next.removeAttribute('hidden'); }
            else { next.setAttribute('hidden', ''); }
            row.classList.toggle('rowopen', willOpen);
            link.classList.toggle('open', willOpen);
            return;
        }

        if (link.dataset.loading === '1') { return; }
        link.dataset.loading = '1';

        fetch(link.getAttribute('data-url'), { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                return res.text();
            })
            .then(function (html) {
                row.insertAdjacentHTML('afterend', html);
                row.classList.add('rowopen');
                link.classList.add('open');
            })
            .catch(function (err) {
                // Fail loud, in place, and leave the link retryable.
                var span = link.getAttribute('data-span') || '7';
                row.insertAdjacentHTML('afterend',
                    '<tr class="exprow"><td colspan="' + span + '"><div class="taskbox">' +
                    '<div class="empty">Could not load. ' + String(err.message) + '</div>' +
                    '</div></td></tr>');
                row.classList.add('rowopen');
            })
            .then(function () { link.dataset.loading = ''; });
    }
}());
</script>
</body>
</html>