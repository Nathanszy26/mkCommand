<?php
header('Content-Type: application/json; charset=UTF-8');

/**
 * Staff directory endpoint — backs the "Staff Directory Search" sheet on the
 * MK Command Staff tab.
 *
 * IN  : person, comp_id (alias: comid)   the CALLER's session, always required
 *       action = companies | departments | roots | committees | search |
 *                browse | detail                    (default: search)
 *       departments : [target_comp_id]
 *       roots       : [target_comp_id]   - the org navigator's top level
 *       committees  : [target_comp_id]   - the committees that company may see,
 *                                          each with its full membership
 *       search      : q, [target_comp_id], [department], [limit]
 *       browse      : [target_comp_id], [department]   — whole company, no text
 *       detail      : target_person, [target_comp_id], [is_gw]
 *
 *       `department` absent means every department; the `no_department` token
 *       returned by the departments action selects the people who have none.
 *
 * OUT : { success, ... } — see each action below.
 *
 * MUST be hosted on globportal.com: prog4@localhost reaches staff_portal2
 * (users, subsidiaries, staff_profile, gw_profile, organization_chart_glob),
 * mkPortal (job spec versions) and evaluation (monthly_assign,
 * monthly_assign_gw) on the SAME mysqld, so all three are read through one
 * connection via fully-qualified table names.
 *
 * Unlike staffHierarchy.php this is deliberately NOT scoped to the caller's
 * own reporting line: a directory is a company phone book, so any logged-in
 * staff may look up any company. The caller is still authenticated, so the
 * endpoint cannot be read by an anonymous request.
 *
 * PHP 7.2 compatible (no arrow fns, no null coalescing on arrays).
 */

class DatabaseConfig
{
    const HOST     = "localhost";
    const USERNAME = "prog4";
    const PASSWORD = "prog42023";
    const DATABASE = "staff_portal2";
    const CHARSET  = "utf8";
}

class Database
{
    private static $connection = null;

    public static function getConnection()
    {
        if (self::$connection === null) {
            $dsn = "mysql:host=" . DatabaseConfig::HOST
                . ";dbname=" . DatabaseConfig::DATABASE
                . ";charset=" . DatabaseConfig::CHARSET;

            self::$connection = new PDO($dsn, DatabaseConfig::USERNAME, DatabaseConfig::PASSWORD);
            self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }
        return self::$connection;
    }
}

/**
 * Profile photos live in the myMK app's storage bucket, not on this host.
 * staff_profile.profile_pic / gw_profile.profile_pic hold a path relative to
 * the bucket root; the app only ever gets the finished absolute URL, so the
 * base is owned here and nowhere else.
 */
class ProfilePhoto
{
    const URL_BASE = 'https://app.globportal.com/mymk/storage/';

    /** Absolute URL of a stored path, or null when there is no photo on file.
     *  Each path segment is encoded separately so the slashes survive. */
    public static function url($pic)
    {
        if ($pic === null || trim($pic) === '') {
            return null;
        }
        $segments = array_map('rawurlencode', explode('/', ltrim(trim($pic), '/')));
        return self::URL_BASE . implode('/', $segments);
    }
}

/**
 * All directory reads. Nothing here knows about HTTP.
 */
class DirectoryRepository
{
    private $db;

    /** Same visibility rule as staffHierarchy.php / jobspecReport.php, so the
     *  directory lists exactly the people the rest of MK Command can see. */
    const USER_VISIBLE = "u.status = 1 AND IFNULL(u.deleted, 0) = 0
                          AND IFNULL(u.mk_command_excluded, 0) <> 1";

    /**
     * Where a company's reporting line lives.
     *
     * Comp 1 keeps its own chart in staff_portal2.organization_chart_glob, as
     * manpower_summary.php walks it. Every other company's line is
     * evaluation.monthly_assign — the table kpiReport.php and the KPI
     * assignment screen read, and the one their supervisors actually maintain.
     * staff_portal2.organization_chart is no longer consulted for them.
     *
     * The two tables carry the same columns (staff_id, comp_id, boss_id,
     * boss_comp_id, monthly_assign_from, monthly_assign_to), so each is
     * aliased `oc` and ACTIVE_CLAUSE applies to either unchanged. Routing is on
     * the STAFF-side comp_id.
     *
     * One difference matters: monthly_assign keeps history, so a person can
     * hold several live rows for the same boss. Every read of it is therefore
     * DISTINCT — without that, one superior is listed as many.
     */
    const GLOB_COMP_ID = 1;

    const GLOB_CHART_TABLE = 'organization_chart_glob';
    const ASSIGN_TABLE     = 'evaluation.monthly_assign';

    /**
     * The department filter's value for "people with no department on file".
     * 107 of Globinaco's 352 staff are in that state, so it cannot just be an
     * unlabelled gap — it is a bucket you can select like any other. A literal
     * token rather than '' so an absent filter and an empty-string filter stay
     * distinguishable in a query string.
     */
    const NO_DEPARTMENT = '__none__';

    /** Phone numbers are stored two ways and compared as one. staff_profile
     *  holds bare digits ('60128629616' or '0128629616'); users.mobile_no
     *  carries separators ('016-8023754 '). Reducing both to digits and then
     *  stripping the 60/0 trunk prefix leaves a single comparable number. */
    const PHONE_PROFILE = "TRIM(LEADING '0' FROM TRIM(LEADING '60' FROM
        REPLACE(REPLACE(REPLACE(p.person, '-', ''), ' ', ''), '+', '')))";
    const PHONE_USER    = "TRIM(LEADING '0' FROM TRIM(LEADING '60' FROM
        REPLACE(REPLACE(REPLACE(u.mobile_no, '-', ''), ' ', ''), '+', '')))";

    /** Enough digits to be a real number. Without this guard the 494
     *  staff_profile rows holding a USERNAME in `person` and the 13 live users
     *  with no usable mobile_no would reduce to '' and match each other. */
    const PHONE_MIN_DIGITS = "LENGTH(REPLACE(REPLACE(REPLACE(u.mobile_no, '-', ''), ' ', ''), '+', '')) >= 9";

    /**
     * A staff member's profile photo path, correlated to `u`. Link priority
     * mirrors enroll_admin.php's checkFaceEncodings, so the directory shows the
     * same face the face-enrolment admin sees:
     *   1. staff_profile.person + comid  ==  users.person + comp_id
     *   2. fallback: staff_profile.mymkid == users.id
     *   3. fallback: the phone number, reduced to digits on both sides
     *
     * Link 3 exists because comp 8 (TP Group) enrolled through a different
     * path: their staff_profile.person holds the PHONE NUMBER rather than the
     * username, and their mymkid is a myMK account id rather than users.id, so
     * links 1 and 2 both miss and every one of them fell back to initials.
     *
     * Verified on live data before it was written: no two profiles in one
     * company reduce to the same number, so this cannot return the wrong face;
     * comp 8 is the ONLY company whose photos change (66 of its 69 live staff
     * gain one, the other 3 have no profile row at all); and matching
     * p.mobile as well adds nobody, so it deliberately does not.
     *
     * Links 1 and 2 are indexed, so this stays cheap even over a whole company.
     * Link 3 cannot use an index, but COALESCE stops at the first non-NULL, so
     * it only ever runs for someone links 1 and 2 could not resolve, and it is
     * scoped to one company's profiles.
     */
    const STAFF_PHOTO_SUBQUERY = "COALESCE(
        (SELECT p.profile_pic FROM staff_profile p
          WHERE p.person = u.person AND p.comid = u.comp_id
            AND p.deleted_at IS NULL
            AND p.profile_pic IS NOT NULL AND p.profile_pic <> ''
          LIMIT 1),
        (SELECT p.profile_pic FROM staff_profile p
          WHERE p.mymkid = u.id
            AND p.deleted_at IS NULL
            AND p.profile_pic IS NOT NULL AND p.profile_pic <> ''
          LIMIT 1),
        (SELECT p.profile_pic FROM staff_profile p
          WHERE p.comid = u.comp_id
            AND p.deleted_at IS NULL
            AND p.profile_pic IS NOT NULL AND p.profile_pic <> ''
            AND " . self::PHONE_MIN_DIGITS . "
            AND " . self::PHONE_PROFILE . " = " . self::PHONE_USER . "
          LIMIT 1)
    )";

    /**
     * A staff member's myMK membership number, correlated to `u`.
     * mkmembers.membership_level.user_id IS staff_portal2.users.id when
     * user_source = 'staff' (verified: 673 such rows, none duplicated per
     * user). GW live in the same table under member_type = 'gw', but keyed on
     * gw_profile.mymkid instead — see gwDetail.
     *
     * mkmembers sits on the same mysqld as staff_portal2, so it is reachable
     * through this one connection; the DB user needs SELECT on it.
     */
    const STAFF_MEMBERSHIP_SUBQUERY = "(SELECT ml.membership_no
        FROM mkmembers.membership_level ml
        WHERE ml.user_id = u.id AND ml.user_source = 'staff'
          AND ml.membership_no IS NOT NULL AND ml.membership_no <> ''
        ORDER BY ml.id DESC LIMIT 1)";

    /**
     * The one company whose directory is scoped to the manpower chart.
     *
     * Globinaco is the only subsidiary with department_head rows, so it is the
     * only one where "assigned in the chart" means anything — everywhere else
     * the roster IS the directory, and those companies are left alone.
     */
    const MANPOWER_COMP_ID = 1;

    /**
     * The person who governs every department at comp 1.
     *
     * Globinaco's top level comes from department_head, which names 18
     * departments and their heads but nobody above them — the company has no
     * single root in the data the way the other companies do. In the org chart
     * everyone drawn here answers to one person, so the list says so.
     *
     * He is named here rather than derived because there is nothing to derive
     * him FROM: no department_head row, no chart row above the heads. That
     * makes this a standing claim about the company, so it is written where it
     * can be read and changed, not buried in a query.
     *
     * Only the identity is fixed. The name, position and photo on the card are
     * read from his users row like anybody else's, so a change there follows
     * through — and if that row stops being visible, the card simply does not
     * appear.
     *
     * MANPOWER_TOP_ENABLED = false turns it off: comp 1 then opens straight on
     * its 18 departments, exactly as it did before.
     */
    const MANPOWER_TOP_ENABLED = true;
    const MANPOWER_TOP_PERSON  = 'lawrence';   // staff_portal2.users.id 442, comp_id 1

    /**
     * Everyone manpower_summary.php counts as placed on the chart, as a join
     * target. Verified against that report: 332 staff for Globinaco.
     *
     * Two ways to be placed, exactly as its walk decides:
     *   - you hold a live assignment row in organization_chart_glob, or
     *   - you are the head of a live top-level department.
     * A department head needs no chart row of their own; they ARE a root.
     *
     * Materialised as a derived table and JOINed rather than tested with a
     * correlated EXISTS: organization_chart_glob carries only a PRIMARY key, so
     * a correlated form re-scans all 5,023 rows per candidate — 6.2s for one
     * company against 24ms this way.
     */
    const PLACED_STAFF_JOIN = "
        INNER JOIN (
            SELECT DISTINCT oc.staff_id AS person, oc.comp_id AS comp_id
              FROM organization_chart_glob oc
             WHERE (oc.monthly_assign_from IS NULL OR oc.monthly_assign_from = ''
                    OR STR_TO_DATE(REPLACE(oc.monthly_assign_from, '-', '/'), '%Y/%m/%d') <= CURDATE())
               AND (oc.monthly_assign_to IS NULL OR oc.monthly_assign_to = ''
                    OR STR_TO_DATE(REPLACE(oc.monthly_assign_to, '-', '/'), '%Y/%m/%d') >= CURDATE())
            UNION
            SELECT dh.staff_id, dh.comp_id
              FROM department_head dh
             WHERE dh.comp_id = " . self::MANPOWER_COMP_ID . " AND dh.status = '1'
               AND (dh.parent_id IS NULL OR dh.parent_id = '')
        ) placed ON placed.person = u.person AND placed.comp_id = u.comp_id";

    /**
     * The bosses a GW has to hang off to be counted placed — the same walk seen
     * from below. Verified: 347 GW for Globinaco, so 332 + 347 = 679, the
     * figure the report's "Total assigned in the chart" prints.
     *
     * The chart side requires an ACTIVE user (an assignment row pointing at a
     * resigned supervisor does not place their GW; that one filter is the whole
     * difference between 347 and 348). Department heads need no such check:
     * manpower walks from every live head whether or not their user row
     * resolves, so this mirrors that.
     */
    const PLACED_GW_JOIN = "
        INNER JOIN (
            SELECT DISTINCT oc.staff_id AS person, oc.comp_id AS comp_id
              FROM organization_chart_glob oc
              INNER JOIN users uu
                      ON uu.person = oc.staff_id AND uu.comp_id = oc.comp_id
                     AND uu.status = 1 AND IFNULL(uu.deleted, 0) = 0
                     AND IFNULL(uu.mk_command_excluded, 0) <> 1
             WHERE (oc.monthly_assign_from IS NULL OR oc.monthly_assign_from = ''
                    OR STR_TO_DATE(REPLACE(oc.monthly_assign_from, '-', '/'), '%Y/%m/%d') <= CURDATE())
               AND (oc.monthly_assign_to IS NULL OR oc.monthly_assign_to = ''
                    OR STR_TO_DATE(REPLACE(oc.monthly_assign_to, '-', '/'), '%Y/%m/%d') >= CURDATE())
            UNION
            SELECT dh.staff_id, dh.comp_id
              FROM department_head dh
             WHERE dh.comp_id = " . self::MANPOWER_COMP_ID . " AND dh.status = '1'
               AND (dh.parent_id IS NULL OR dh.parent_id = '')
        ) placed_boss ON placed_boss.person = g.boss_id
                     AND placed_boss.comp_id = g.boss_comp_id";

    /** manpower_summary.php's GW_ACTIVE_CLAUSE. The NULL arm is required: a
     *  bare `<> '1'` drops every row that never had the flag set. */
    const PLACED_GW_WHERE = "
        AND (g.kpi_scorecard_exclude IS NULL OR g.kpi_scorecard_exclude <> '1')";

    /** Assignment is live today. Empty / NULL bounds mean "unbounded". */
    const ACTIVE_CLAUSE = "
        (oc.monthly_assign_from IS NULL OR oc.monthly_assign_from = ''
            OR STR_TO_DATE(REPLACE(oc.monthly_assign_from, '-', '/'), '%Y/%m/%d') <= CURDATE())
        AND (oc.monthly_assign_to IS NULL OR oc.monthly_assign_to = ''
            OR STR_TO_DATE(REPLACE(oc.monthly_assign_to, '-', '/'), '%Y/%m/%d') >= CURDATE())";

    /** comp_id => [digit-only phone => profile_pic], filled by gwPhotoIndex. */
    private $gwPhotos = array();

    /** Resolved once per request by tagSchema(); null until then. */
    private $tagSchema = null;

    /**
     * Which halves of the tag schema actually exist right now.
     *
     * Tags are optional by design: staffDirectory_tags.sql adds one column to
     * users and one to monthly_assign_gw, and can be run before or after this
     * file is uploaded. Either way the directory keeps working — it just does
     * not match on tags until the columns are there. Without this probe a deploy
     * in the wrong order takes the whole sheet down with an SQL error, over a
     * feature nobody is using yet.
     *
     * One information_schema read, memoised for the request.
     */
    private function tagSchema()
    {
        if ($this->tagSchema !== null) {
            return $this->tagSchema;
        }

        $this->tagSchema = array('staff' => false, 'gw' => false);

        try {
            $sql = "SELECT
                      (SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = 'staff_portal2' AND TABLE_NAME = 'users'
                          AND COLUMN_NAME = 'tags') AS has_staff,
                      (SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = 'evaluation' AND TABLE_NAME = 'monthly_assign_gw'
                          AND COLUMN_NAME = 'monthly_assign_gw_tags') AS has_gw";
            $row = $this->db->query($sql)->fetch();
            if ($row) {
                $this->tagSchema['staff'] = ((int)$row['has_staff'] > 0);
                $this->tagSchema['gw']    = ((int)$row['has_gw'] > 0);
            }
        } catch (Exception $e) {
            // No information_schema grant: behave as if tags are not installed
            // rather than failing a search over an optional feature.
            error_log('staffDirectory tag schema probe failed: ' . $e->getMessage());
        }

        return $this->tagSchema;
    }


    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Phone numbers are entered free-hand ("+60 11-6191 7636", "010-5871960",
     * "010214 4330", "-"), so neither a stored value nor a typed one can be
     * compared as-is. Both sides are reduced to digits before matching. MySQL
     * 5.6 has no REGEXP_REPLACE, hence the nested REPLACE ladder.
     */
    private static function digitsOnly($expr)
    {
        $strip = array('+', ' ', '-', '(', ')', '.', '/');
        $out   = $expr;
        foreach ($strip as $ch) {
            $out = "REPLACE({$out}, '{$ch}', '')";
        }
        return $out;
    }

    /**
     * SQL for the department filter, or '' when no department was chosen.
     * $column is whichever column holds it on this side: users.department for
     * staff, monthly_assign_gw_district for GW. Binds :dept when it returns a
     * comparison, so callers bind that only when this returned non-empty.
     */
    private static function departmentClause($department, $column)
    {
        if ($department === null) {
            return '';
        }
        if ($department === self::NO_DEPARTMENT) {
            return " AND TRIM(IFNULL({$column}, '')) = '' ";
        }
        return " AND TRIM(IFNULL({$column}, '')) = :dept ";
    }

    /** The table holding one company's reporting line. One place decides, so
     *  a superior, a subordinate and a root can never disagree about it. */
    private static function chartTable($compId)
    {
        return (int)$compId === self::GLOB_COMP_ID
            ? self::GLOB_CHART_TABLE
            : self::ASSIGN_TABLE;
    }

    /**
     * [table, staff-side scope] for every reporting line, for the reads that
     * start from a BOSS — their comp_id says nothing about their reports', so
     * both tables are read and UNIONed. The scopes are disjoint, so UNION ALL
     * can never double-count.
     */
    private static function chartSources()
    {
        return array(
            array(self::ASSIGN_TABLE,     'oc.comp_id <> ' . self::GLOB_COMP_ID),
            array(self::GLOB_CHART_TABLE, 'oc.comp_id =  ' . self::GLOB_COMP_ID),
        );
    }

    /** The placed-staff join for this company, or '' where the filter does not
     *  apply. One place decides, so every count and every list agree. */
    private static function placedStaff($compId)
    {
        return (int)$compId === self::MANPOWER_COMP_ID ? self::PLACED_STAFF_JOIN : '';
    }

    /** As above for GW: the join, and the extra WHERE that goes with it. */
    private static function placedGwJoin($compId)
    {
        return (int)$compId === self::MANPOWER_COMP_ID ? self::PLACED_GW_JOIN : '';
    }

    private static function placedGwWhere($compId)
    {
        return (int)$compId === self::MANPOWER_COMP_ID ? self::PLACED_GW_WHERE : '';
    }

    /** The newest APPROVED job spec version for a staff/GW, as a scalar
     *  subquery correlated to the given person/comp columns. Draft and
     *  rejected versions are not part of the directory. */
    private static function latestApprovedVersion($personCol, $compCol, $isGw)
    {
        return "(SELECT v.id FROM mkPortal.job_spec_version v
                  WHERE v.person = {$personCol} AND v.comp_id = {$compCol}
                    AND IFNULL(v.is_gw, 0) = " . (int)$isGw . "
                    AND v.status = 'approved'
                  ORDER BY v.id DESC LIMIT 1)";
    }

    /** The caller. Null when they are not a visible staff member — which is the
     *  only authentication this endpoint performs. */
    public function findCaller($person, $compId)
    {
        $sql = "SELECT u.person, u.comp_id, u.fullname, s.name AS company_name
                FROM users u
                LEFT JOIN subsidiaries s ON s.id = u.comp_id
                WHERE u.person = :person AND u.comp_id = :comp_id
                  AND " . self::USER_VISIBLE . "
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':person', $person, PDO::PARAM_STR);
        $stmt->bindValue(':comp_id', (int)$compId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * The companies worth offering in the picker.
     *
     * Two filters, and they do different jobs:
     *  - weekly_report_status = 1 is the group MK Command actually covers, the
     *    same one staffOverview.php and jobspecReport.php work from. It is NOT
     *    subsidiaries.status: the two disagree in both directions (Eaton and
     *    Ming Kiang are status 1 but outside the weekly group; Elegant Aspire
     *    is inside it with status 0), so reading the wrong one offers companies
     *    this feature is not for and hides one it is.
     *  - a subsidiary with no visible staff and no active GW is dropped rather
     *    than offered as an option that opens an empty list.
     *
     * The caller's own company is kept whatever its flag says, because it is
     * what the search opens on — filtering it out would leave the picker
     * showing a company that is not in its own list.
     */
    public function companies($ownCompId = 0)
    {
        // The chart filter is per company, so comp 1's two counts are their own
        // subqueries rather than a CASE inside the shared ones.
        $placedStaff = self::PLACED_STAFF_JOIN;
        $placedGwJ   = self::PLACED_GW_JOIN;
        $placedGwW   = self::PLACED_GW_WHERE;
        $manpower    = self::MANPOWER_COMP_ID;
        $own         = (int)$ownCompId;

        $sql = "SELECT s.id AS comp_id, s.name,
                       CASE WHEN s.id = {$manpower} THEN
                           (SELECT COUNT(*) FROM users u
                                   {$placedStaff}
                             WHERE u.comp_id = s.id AND " . self::USER_VISIBLE . ")
                       ELSE
                           (SELECT COUNT(*) FROM users u
                             WHERE u.comp_id = s.id AND " . self::USER_VISIBLE . ")
                       END AS staff_count,
                       CASE WHEN s.id = {$manpower} THEN
                           (SELECT COUNT(DISTINCT g.monthly_assign_gw_code)
                              FROM evaluation.monthly_assign_gw g
                                   {$placedGwJ}
                             WHERE g.comp_id = s.id
                               AND g.monthly_assign_gw_status = '1' {$placedGwW})
                       ELSE
                           (SELECT COUNT(DISTINCT g.monthly_assign_gw_code)
                              FROM evaluation.monthly_assign_gw g
                             WHERE g.comp_id = s.id
                               AND g.monthly_assign_gw_status = '1')
                       END AS gw_count
                FROM subsidiaries s
                WHERE (s.weekly_report_status = 1 OR s.id = {$own})
                HAVING staff_count > 0 OR gw_count > 0
                ORDER BY s.name ASC";

        $rows = $this->db->query($sql)->fetchAll();

        $out = array();
        foreach ($rows as $r) {
            $out[] = array(
                'comp_id'     => (int)$r['comp_id'],
                'name'        => $r['name'],
                'staff_count' => (int)$r['staff_count'],
                'gw_count'    => (int)$r['gw_count'],
            );
        }
        return $out;
    }

    /**
     * Every department worth offering in the filter for one company, with how
     * many people sit in each.
     *
     * Staff and GW are kept as separate groups rather than merged into one
     * list: `users.department` holds a department ("ACCOUNTS") while
     * `monthly_assign_gw_district` holds a geographic district ("KK/PTN"), and
     * presenting a district as a department would misrepresent both. They share
     * one filter because selecting either narrows the same result list — a
     * value that only exists on one side simply returns nothing from the other.
     */
    public function departments($compId)
    {
        $staffSql = "SELECT TRIM(IFNULL(u.department, '')) AS name, COUNT(*) AS n
                     FROM users u
                          " . self::placedStaff($compId) . "
                     WHERE u.comp_id = :comp_id AND " . self::USER_VISIBLE . "
                     GROUP BY name
                     ORDER BY name ASC";
        $stmt = $this->db->prepare($staffSql);
        $stmt->bindValue(':comp_id', (int)$compId, PDO::PARAM_INT);
        $stmt->execute();

        $staff = array();
        foreach ($stmt->fetchAll() as $r) {
            $staff[] = array(
                'name'  => $r['name'] === '' ? null : $r['name'],
                'value' => $r['name'] === '' ? self::NO_DEPARTMENT : $r['name'],
                'count' => (int)$r['n'],
            );
        }

        $gwSql = "SELECT TRIM(IFNULL(g.monthly_assign_gw_district, '')) AS name,
                         COUNT(DISTINCT g.monthly_assign_gw_code) AS n
                  FROM evaluation.monthly_assign_gw g
                       " . self::placedGwJoin($compId) . "
                  WHERE g.comp_id = :comp_id AND g.monthly_assign_gw_status = '1'
                        " . self::placedGwWhere($compId) . "
                  GROUP BY name
                  ORDER BY name ASC";
        $stmt = $this->db->prepare($gwSql);
        $stmt->bindValue(':comp_id', (string)(int)$compId, PDO::PARAM_STR);
        $stmt->execute();

        $gw = array();
        foreach ($stmt->fetchAll() as $r) {
            $gw[] = array(
                'name'  => $r['name'] === '' ? null : $r['name'],
                'value' => $r['name'] === '' ? self::NO_DEPARTMENT : $r['name'],
                'count' => (int)$r['n'],
            );
        }

        return array('departments' => $staff, 'gw_districts' => $gw);
    }

    /**
     * Staff matching $q inside one company.
     *
     * Searchable: fullname, email (work + personal), mobile_no (work +
     * personal, digit-normalised), department, position, and the task text of
     * their latest approved job spec. A job-spec hit also returns the task that
     * matched, so a result that looks unrelated to what was typed still says
     * why it is there.
     *
     * $q = null is BROWSE: no text filter at all, every visible staff member in
     * the company (narrowed by $department, if given). $department = null means
     * every department. The two filters are independent, so all four
     * combinations are one query rather than four.
     */
    public function searchStaff($compId, $q, $department, $limit)
    {
        $browsing = ($q === null);
        $like     = '%' . $q . '%';
        $digits   = $browsing ? '' : preg_replace('/\D/', '', $q);
        $mobile   = self::digitsOnly('u.mobile_no');
        $mobile2  = self::digitsOnly('u.personal_mobile_no');
        $version  = self::latestApprovedVersion('u.person', 'u.comp_id', 0);

        // A numeric-only term is matched against phone numbers as digits; a
        // term with letters in it never is, so "6" does not sweep the company.
        $mobileClause = ($digits !== '')
            ? " OR {$mobile} LIKE :digits OR {$mobile2} LIKE :digits "
            : '';

        $deptClause = self::departmentClause($department, 'u.department');

        // Tags are an optional add-on; everything below degrades to the old
        // behaviour until users.tags exists.
        $hasTags = $this->tagSchema();
        $hasTags = $hasTags['staff'];

        $tagMatch = $hasTags ? " OR u.tags LIKE :like " : '';
        $tagList  = $hasTags ? "u.tags AS tags" : "NULL AS tags";

        // Browsing has no :like to bind, so the columns whose only job is to
        // explain a text match are dropped with it.
        $matchedTask = $browsing
            ? "NULL AS matched_task"
            : "(SELECT i.task FROM mkPortal.job_spec_version_item i
                 WHERE i.version_id = {$version} AND i.task LIKE :like
                 ORDER BY i.sort_order ASC, i.id ASC LIMIT 1) AS matched_task";

        $textClause = $browsing ? '' : "
                  AND (
                        u.fullname LIKE :like
                     OR u.person LIKE :like
                     OR u.email LIKE :like
                     OR u.personal_email LIKE :like
                     OR u.department LIKE :like
                     OR u.position LIKE :like
                     {$mobileClause}
                     OR EXISTS (SELECT 1 FROM mkPortal.job_spec_version_item i
                                 WHERE i.version_id = {$version}
                                   AND i.task LIKE :like)
                     {$tagMatch}
                  )";

        $sql = "SELECT u.id, u.person, u.comp_id, u.fullname,
                       u.department, u.position, u.mobile_no, u.email,
                       s.name AS company_name,
                       " . self::STAFF_PHOTO_SUBQUERY . " AS profile_pic,
                       {$tagList},
                       {$matchedTask}
                FROM users u
                LEFT JOIN subsidiaries s ON s.id = u.comp_id
                " . self::placedStaff($compId) . "
                WHERE u.comp_id = :comp_id
                  AND " . self::USER_VISIBLE . "
                  {$deptClause}
                  {$textClause}
                ORDER BY u.fullname ASC
                LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':comp_id', (int)$compId, PDO::PARAM_INT);
        if (!$browsing) {
            $stmt->bindValue(':like', $like, PDO::PARAM_STR);
        }
        if ($digits !== '') {
            $stmt->bindValue(':digits', '%' . $digits . '%', PDO::PARAM_STR);
        }
        if ($department !== null && $department !== self::NO_DEPARTMENT) {
            $stmt->bindValue(':dept', $department, PDO::PARAM_STR);
        }
        $stmt->execute();

        $out = array();
        foreach ($stmt->fetchAll() as $r) {
            $out[] = $this->shapeStaffHit($r, $q);
        }
        return $out;
    }

    /**
     * Active GW matching $q inside one company.
     *
     * GW are keyed on monthly_assign_gw_code, and the table holds one row per
     * code PER MONTH — so rows are collapsed to one record per code before
     * anything else, exactly as enroll_admin.php's GW listing does.
     *
     * Their photo lives in gw_profile, which has no GW code to join on: it is
     * keyed on the phone number the worker signed up with (gw_profile.person
     * is that number). The join is therefore mobile-to-mobile, digit-normalised
     * on both sides, and scoped to the same company.
     */
    public function searchGw($compId, $q, $department, $limit)
    {
        $browsing = ($q === null);
        $like     = '%' . $q . '%';
        $digits   = $browsing ? '' : preg_replace('/\D/', '', $q);
        $gwMobile = self::digitsOnly('g.monthly_assign_gw_mobile_no');
        $version  = self::latestApprovedVersion('g.monthly_assign_gw_code', 'g.comp_id', 1);

        $mobileClause = ($digits !== '') ? " OR {$gwMobile} LIKE :digits " : '';

        // A GW's "department" is their district — the column the filter targets.
        $deptClause = self::departmentClause($department, 'g.monthly_assign_gw_district');

        // Optional, exactly as for staff: no column, no tag matching.
        $schema  = $this->tagSchema();
        $hasTags = $schema['gw'];

        $tagMatch = $hasTags ? " OR g.monthly_assign_gw_tags LIKE :like " : '';
        // A code can own more than one row (24 active ones do), so the tags of
        // all its rows are merged rather than MAX()'d — MAX would pick one row's
        // list and silently drop the others'.
        $tagList  = $hasTags
            ? "GROUP_CONCAT(DISTINCT g.monthly_assign_gw_tags SEPARATOR ',') AS tags"
            : "NULL AS tags";

        // matched_task is resolved OUTSIDE the derived table: the version lookup
        // correlates on the GW code, and a correlated subquery wrapped in an
        // aggregate alongside GROUP BY is not something MySQL 5.6 evaluates
        // dependably. Out here each row already has its single code.
        $versionOut = self::latestApprovedVersion('t.person', 't.comp_id', 1);

        $matchedTask = $browsing
            ? "NULL AS matched_task"
            : "(SELECT i.task FROM mkPortal.job_spec_version_item i
                 WHERE i.version_id = {$versionOut} AND i.task LIKE :like
                 ORDER BY i.sort_order ASC, i.id ASC LIMIT 1) AS matched_task";

        $textClause = $browsing ? '' : "
                      AND (
                            g.monthly_assign_gw_fullname LIKE :like
                         OR g.monthly_assign_gw_code LIKE :like
                         OR g.monthly_assign_gw_district LIKE :like
                         {$mobileClause}
                         {$tagMatch}
                         OR EXISTS (SELECT 1 FROM mkPortal.job_spec_version_item i
                                     WHERE i.version_id = {$version}
                                       AND i.task LIKE :like)
                      )";

        // The photo is NOT joined here. Matching gw_profile means comparing
        // digit-stripped phone numbers, which no index can serve, so as a
        // correlated subquery it re-scanned the table once per row — 1.05s to
        // browse Arus Sawit's 608 GW. gw_profile is 259 rows in total, so it is
        // fetched whole, once, and mapped in PHP instead (see attachGwPhotos).
        $sql = "SELECT t.*,
                       {$matchedTask}
                FROM (
                    SELECT g.monthly_assign_gw_code AS person,
                           g.comp_id,
                           MAX(g.monthly_assign_gw_fullname) AS fullname,
                           MAX(g.monthly_assign_gw_mobile_no) AS mobile_no,
                           MAX(g.monthly_assign_gw_district) AS department,
                           " . self::digitsOnly('MAX(g.monthly_assign_gw_mobile_no)') . " AS mobile_digits,
                           MAX(s.name) AS company_name,
                           {$tagList}
                    FROM evaluation.monthly_assign_gw g
                    LEFT JOIN subsidiaries s ON s.id = g.comp_id
                    " . self::placedGwJoin($compId) . "
                    WHERE g.comp_id = :comp_id
                      AND g.monthly_assign_gw_status = '1'
                      " . self::placedGwWhere($compId) . "
                      {$deptClause}
                      {$textClause}
                    GROUP BY g.monthly_assign_gw_code
                    ORDER BY fullname ASC
                    LIMIT {$limit}
                ) t";

        $stmt = $this->db->prepare($sql);
        // monthly_assign_gw types comp_id as VARCHAR, so it is bound as a string
        // here — an int binding makes MySQL cast the column and skip its index.
        $stmt->bindValue(':comp_id', (string)(int)$compId, PDO::PARAM_STR);
        if (!$browsing) {
            $stmt->bindValue(':like', $like, PDO::PARAM_STR);
        }
        if ($digits !== '') {
            $stmt->bindValue(':digits', '%' . $digits . '%', PDO::PARAM_STR);
        }
        if ($department !== null && $department !== self::NO_DEPARTMENT) {
            $stmt->bindValue(':dept', $department, PDO::PARAM_STR);
        }
        $stmt->execute();

        $rows   = $stmt->fetchAll();
        $photos = $this->gwPhotoIndex($compId);

        $out = array();
        foreach ($rows as $r) {
            $key = $r['mobile_digits'];
            $out[] = array(
                'person'       => $r['person'],
                'comp_id'      => (int)$r['comp_id'],
                'is_gw'        => 1,
                'fullname'     => $r['fullname'],
                'department'   => $r['department'],
                'position'     => 'General Worker',
                'mobile_no'    => $r['mobile_no'],
                'email'        => null,
                'company_name' => $r['company_name'],
                'photo_url'    => ($key !== '' && isset($photos[$key]))
                    ? ProfilePhoto::url($photos[$key])
                    : null,
                'tags'         => self::splitTags($r['tags']),
                'matched_task' => $r['matched_task'],
                // No per-tag subquery: the list is already here, so the tag that
                // matched is found in PHP rather than with a second read.
                'matched_tag'  => $browsing ? null : self::firstMatchingTag($r['tags'], $q),
            );
        }
        return $out;
    }

    /**
     * One company's GW photos, keyed by digit-only phone number.
     *
     * gw_profile has no GW code to join on — it is keyed on the phone number
     * the worker signed up with, and `person` holds that number rather than a
     * code. Comparing those means stripping separators off both sides, which
     * defeats every index, so this is read in one go (259 rows company-wide)
     * and matched in PHP rather than per result row.
     *
     * `person` wins over `mobile` when they disagree: it is the column the
     * signup writes, and the memoised result keeps repeat calls free.
     */
    private function gwPhotoIndex($compId)
    {
        $compId = (int)$compId;
        if (isset($this->gwPhotos[$compId])) {
            return $this->gwPhotos[$compId];
        }

        $sql = "SELECT p.person, p.mobile, p.profile_pic
                FROM gw_profile p
                WHERE p.comp_id = :comp_id AND p.deleted_at IS NULL
                  AND p.profile_pic IS NOT NULL AND p.profile_pic <> ''";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':comp_id', $compId, PDO::PARAM_INT);
        $stmt->execute();

        $index = array();
        foreach ($stmt->fetchAll() as $r) {
            $byMobile = preg_replace('/\D/', '', (string)$r['mobile']);
            if ($byMobile !== '' && !isset($index[$byMobile])) {
                $index[$byMobile] = $r['profile_pic'];
            }
            $byPerson = preg_replace('/\D/', '', (string)$r['person']);
            if ($byPerson !== '') {
                $index[$byPerson] = $r['profile_pic'];
            }
        }

        $this->gwPhotos[$compId] = $index;
        return $index;
    }

    /** One staff's full record, or null. Same photo resolution as the search. */
    public function staffDetail($person, $compId)
    {
        $schema  = $this->tagSchema();
        $tagList = $schema['staff'] ? "u.tags AS tags" : "NULL AS tags";

        $sql = "SELECT u.id, u.person, u.comp_id, u.fullname,
                       u.department, u.position,
                       u.mobile_no, u.personal_mobile_no,
                       u.email, u.personal_email,
                       s.name AS company_name,
                       " . self::STAFF_PHOTO_SUBQUERY . " AS profile_pic,
                       " . self::STAFF_MEMBERSHIP_SUBQUERY . " AS membership_no,
                       {$tagList}
                FROM users u
                LEFT JOIN subsidiaries s ON s.id = u.comp_id
                WHERE u.person = :person AND u.comp_id = :comp_id
                  AND " . self::USER_VISIBLE . "
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':person', $person, PDO::PARAM_STR);
        $stmt->bindValue(':comp_id', (int)$compId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $detail = $this->shapeStaffRow($row);
        $detail['personal_mobile_no'] = $row['personal_mobile_no'];
        $detail['personal_email']     = $row['personal_email'];
        $detail['membership_no']      = $row['membership_no'];
        return $detail;
    }

    /** One GW's full record, or null. */
    public function gwDetail($code, $compId)
    {
        $schema  = $this->tagSchema();
        $tagList = $schema['gw']
            ? "GROUP_CONCAT(DISTINCT g.monthly_assign_gw_tags SEPARATOR ',') AS tags"
            : "NULL AS tags";

        $sql = "SELECT t.*,
                       (SELECT p.profile_pic FROM gw_profile p
                         WHERE p.comp_id = t.comp_id AND p.deleted_at IS NULL
                           AND p.profile_pic IS NOT NULL AND p.profile_pic <> ''
                           AND (" . self::digitsOnly('p.person') . " = t.mobile_digits
                                OR " . self::digitsOnly('p.mobile') . " = t.mobile_digits)
                         LIMIT 1) AS profile_pic,
                       (SELECT ml.membership_no
                          FROM gw_profile p
                          JOIN mkmembers.membership_level ml
                            ON ml.user_id = p.mymkid AND ml.member_type = 'gw'
                         WHERE p.comp_id = t.comp_id AND p.deleted_at IS NULL
                           AND ml.membership_no IS NOT NULL AND ml.membership_no <> ''
                           AND (" . self::digitsOnly('p.person') . " = t.mobile_digits
                                OR " . self::digitsOnly('p.mobile') . " = t.mobile_digits)
                         LIMIT 1) AS membership_no
                FROM (
                    SELECT g.monthly_assign_gw_code AS person,
                           g.comp_id,
                           MAX(g.monthly_assign_gw_fullname) AS fullname,
                           MAX(g.monthly_assign_gw_mobile_no) AS mobile_no,
                           MAX(g.monthly_assign_gw_district) AS department,
                           MAX(g.monthly_assign_gw_nric) AS nric,
                           MAX(g.monthly_assign_gw_joined_date) AS joined_date,
                           MAX(g.boss_id) AS boss_id,
                           MAX(g.boss_comp_id) AS boss_comp_id,
                           " . self::digitsOnly('MAX(g.monthly_assign_gw_mobile_no)') . " AS mobile_digits,
                           MAX(s.name) AS company_name,
                           {$tagList}
                    FROM evaluation.monthly_assign_gw g
                    LEFT JOIN subsidiaries s ON s.id = g.comp_id
                    WHERE g.monthly_assign_gw_code = :code
                      AND g.comp_id = :comp_id
                      AND g.monthly_assign_gw_status = '1'
                    GROUP BY g.monthly_assign_gw_code
                ) t";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':code', $code, PDO::PARAM_STR);
        $stmt->bindValue(':comp_id', (string)(int)$compId, PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return array(
            'person'             => $row['person'],
            'comp_id'            => (int)$row['comp_id'],
            'is_gw'              => 1,
            'fullname'           => $row['fullname'],
            'department'          => $row['department'],
            'position'           => 'General Worker',
            'mobile_no'          => $row['mobile_no'],
            'personal_mobile_no' => null,
            'email'              => null,
            'personal_email'     => null,
            'company_name'       => $row['company_name'],
            'nric'               => $row['nric'],
            'joined_date'        => $row['joined_date'],
            'membership_no'      => $row['membership_no'],
            'tags'               => self::splitTags($row['tags']),
            'photo_url'          => ProfilePhoto::url($row['profile_pic']),
            'boss'               => array(
                'person'  => $row['boss_id'],
                'comp_id' => $row['boss_comp_id'] === null ? null : (int)$row['boss_comp_id'],
            ),
        );
    }

    /**
     * Direct superiors of one staff member, from whichever table owns their
     * reporting line: organization_chart_glob for comp 1, monthly_assign for
     * everyone else.
     */
    public function directSuperiors($person, $compId)
    {
        $table = self::chartTable($compId);

        // DISTINCT because monthly_assign keeps history: two live rows naming
        // the same boss are one superior, not two.
        $sql = "SELECT DISTINCT
                       u.person, u.comp_id, u.fullname, u.department, u.position,
                       s.name AS company_name,
                       " . self::STAFF_PHOTO_SUBQUERY . " AS profile_pic
                FROM {$table} oc
                INNER JOIN users u
                    ON u.person = oc.boss_id AND u.comp_id = oc.boss_comp_id
                   AND " . self::USER_VISIBLE . "
                LEFT JOIN subsidiaries s ON s.id = u.comp_id
                WHERE oc.staff_id = :person AND oc.comp_id = :comp_id
                  AND " . self::ACTIVE_CLAUSE . "
                ORDER BY u.fullname ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':person', $person, PDO::PARAM_STR);
        $stmt->bindValue(':comp_id', (int)$compId, PDO::PARAM_INT);
        $stmt->execute();

        return $this->shapeRelatives($stmt->fetchAll());
    }

    /**
     * The people who report DIRECTLY to this person — staff from both
     * reporting-line tables, plus their GW. One level only: the detail card
     * answers "who works for them", not "what does their whole org look like",
     * which is what the Staff tab's tree is for.
     *
     * A boss in one company can own rows naming staff in the other, so both are
     * read: monthly_assign for reports outside comp 1, the glob chart for
     * reports inside it.
     */
    public function directSubordinates($person, $compId)
    {
        // DISTINCT per arm: monthly_assign keeps history, so one reporting
        // line can hold several live rows, and they are one subordinate.
        $arms = array();
        $n    = 0;
        foreach (self::chartSources() as $source) {
            list($table, $scope) = $source;
            $arms[] = "SELECT DISTINCT
                       u.person, u.comp_id, u.fullname, u.department, u.position,
                       s.name AS company_name,
                       " . self::STAFF_PHOTO_SUBQUERY . " AS profile_pic
                FROM {$table} oc
                INNER JOIN users u
                    ON u.person = oc.staff_id AND u.comp_id = oc.comp_id
                   AND " . self::USER_VISIBLE . "
                LEFT JOIN subsidiaries s ON s.id = u.comp_id
                WHERE {$scope}
                  AND oc.boss_id = :p{$n} AND oc.boss_comp_id = :c{$n}
                  AND " . self::ACTIVE_CLAUSE;
            $n++;
        }

        // ORDER BY applies to the whole UNION, so it names the output column.
        $sql = implode("\nUNION ALL\n", $arms) . "\nORDER BY fullname ASC";

        $stmt = $this->db->prepare($sql);
        // A named placeholder repeated across branches is not reliably bindable
        // under PDO's emulated prepares, so each branch gets its own pair.
        for ($b = 0; $b < $n; $b++) {
            $stmt->bindValue(':p' . $b, $person, PDO::PARAM_STR);
            $stmt->bindValue(':c' . $b, (int)$compId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return array_merge(
            $this->shapeRelatives($stmt->fetchAll()),
            $this->directGwSubordinates($person, $compId)
        );
    }

    /** The GW reporting directly to this person. Their photo comes from
     *  gw_profile via the batched phone-number index, not from staff_profile. */
    private function directGwSubordinates($person, $compId)
    {
        $sql = "SELECT g.monthly_assign_gw_code AS person,
                       g.comp_id,
                       MAX(g.monthly_assign_gw_fullname) AS fullname,
                       MAX(g.monthly_assign_gw_district) AS department,
                       " . self::digitsOnly('MAX(g.monthly_assign_gw_mobile_no)') . " AS mobile_digits,
                       MAX(s.name) AS company_name
                FROM evaluation.monthly_assign_gw g
                LEFT JOIN subsidiaries s ON s.id = g.comp_id
                WHERE g.boss_id = :person AND g.boss_comp_id = :comp_id
                  AND g.monthly_assign_gw_status = '1'
                GROUP BY g.monthly_assign_gw_code
                ORDER BY fullname ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':person', $person, PDO::PARAM_STR);
        $stmt->bindValue(':comp_id', (string)(int)$compId, PDO::PARAM_STR);
        $stmt->execute();

        $rows   = $stmt->fetchAll();
        if (empty($rows)) {
            return array();
        }
        $photos = $this->gwPhotoIndex($compId);

        $out = array();
        foreach ($rows as $r) {
            $key = $r['mobile_digits'];
            $out[] = array(
                'person'       => $r['person'],
                'comp_id'      => (int)$r['comp_id'],
                'is_gw'        => 1,
                'fullname'     => $r['fullname'],
                'department'   => $r['department'],
                'position'     => 'General Worker',
                'company_name' => $r['company_name'],
                'photo_url'    => ($key !== '' && isset($photos[$key]))
                    ? ProfilePhoto::url($photos[$key])
                    : null,
            );
        }
        return $out;
    }

    /**
     * The top of the company - the "root folder" the org navigator opens on.
     *
     * comp 1 takes its roots from department_head exactly as
     * manpower_summary.php does: one entry per live top-level department
     * (parent_id IS NULL), carrying both the department name and its head. A
     * department whose head is no longer a visible user still appears, flagged
     * vacant, rather than silently dropping a whole branch of the company.
     */
    public function departmentRoots($compId)
    {
        $sql = "SELECT dh.dept_name,
                       dh.staff_id, dh.comp_id,
                       u.person, u.fullname, u.department, u.position,
                       s.name AS company_name,
                       " . self::STAFF_PHOTO_SUBQUERY . " AS profile_pic
                FROM department_head dh
                LEFT JOIN users u
                       ON u.person = dh.staff_id AND u.comp_id = dh.comp_id
                      AND " . self::USER_VISIBLE . "
                LEFT JOIN subsidiaries s ON s.id = dh.comp_id
                WHERE dh.comp_id = :comp_id AND dh.status = '1'
                  AND (dh.parent_id IS NULL OR dh.parent_id = '')
                ORDER BY CAST(dh.`order` AS UNSIGNED) ASC, dh.dept_name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':comp_id', (int)$compId, PDO::PARAM_INT);
        $stmt->execute();

        $out = array();
        foreach ($stmt->fetchAll() as $r) {
            $out[] = array(
                'person'       => $r['person'] !== null ? $r['person'] : $r['staff_id'],
                'comp_id'      => (int)$r['comp_id'],
                'is_gw'        => 0,
                'fullname'     => $r['fullname'],
                'department'   => $r['department'],
                'position'     => $r['position'],
                'company_name' => $r['company_name'],
                'photo_url'    => ProfilePhoto::url($r['profile_pic']),
                'dept_name'    => $r['dept_name'],
                'vacant'       => $r['person'] === null,
                // Globinaco's roots are departments, side by side — there is no
                // one person above them to draw the rest hanging off.
                'is_top'       => false,
            );
        }
        return $out;
    }

    /**
     * The same top level for a company with no department_head rows, read from
     * its monthly_assign line.
     *
     * TWO rungs, not one: whoever sits in the chart with nobody visible above
     * them, AND the people reporting straight to them. Globinaco opens on its
     * departments because department_head names them; everywhere else that rung
     * exists only as "the GM's direct reports", and a root list holding one
     * General Manager tells you nothing about the company and makes you tap
     * through him to reach anything. His reports ARE the departments, so they
     * are listed as departments - the unit on top, the person under it - which
     * is how Globinaco's roots already read.
     *
     * Computed in PHP from two flat reads rather than a correlated NOT EXISTS.
     * Neither chart table carries an index beyond its primary key, so the
     * correlated form costs a full scan per candidate - it measured 13.9s
     * across the group, against a few milliseconds this way.
     */
    public function chartRoots($compId)
    {
        $table = self::chartTable($compId);

        // DISTINCT for monthly_assign's history again: the same edge written
        // for six months running is still one edge.
        $edgeSql = "SELECT DISTINCT oc.staff_id, oc.boss_id, oc.boss_comp_id
                    FROM {$table} oc
                    WHERE oc.comp_id = :comp_id AND " . self::ACTIVE_CLAUSE;
        $stmt = $this->db->prepare($edgeSql);
        $stmt->bindValue(':comp_id', (int)$compId, PDO::PARAM_INT);
        $stmt->execute();
        $edges = $stmt->fetchAll();

        if (empty($edges)) {
            return array();
        }

        $peopleSql = "SELECT u.id, u.person, u.comp_id, u.fullname, u.department, u.position,
                             s.name AS company_name,
                             " . self::STAFF_PHOTO_SUBQUERY . " AS profile_pic
                      FROM users u
                      LEFT JOIN subsidiaries s ON s.id = u.comp_id
                      WHERE u.comp_id = :comp_id AND " . self::USER_VISIBLE . "
                      ORDER BY u.fullname ASC";
        $stmt = $this->db->prepare($peopleSql);
        $stmt->bindValue(':comp_id', (int)$compId, PDO::PARAM_INT);
        $stmt->execute();

        $people = array();
        foreach ($stmt->fetchAll() as $r) {
            $people[$r['person']] = $r;
        }

        $inChart = array();
        $hasBoss = array();
        foreach ($edges as $e) {
            if (isset($people[$e['staff_id']])) {
                $inChart[$e['staff_id']] = true;
                // Only a boss who is themselves visible counts: an edge to
                // somebody who has left leaves this person at the top.
                if ((int)$e['boss_comp_id'] === (int)$compId && isset($people[$e['boss_id']])) {
                    $hasBoss[$e['staff_id']] = true;
                }
            }
            if (isset($people[$e['boss_id']])) {
                $inChart[$e['boss_id']] = true;
            }
        }

        $tops = array();
        foreach (array_keys($inChart) as $person) {
            if (!isset($hasBoss[$person])) {
                $tops[$person] = true;
            }
        }

        // The rung below: everyone reporting straight to a top. Deduplicated,
        // because one person can hold a row under two of them, and a top is
        // never also listed here - they have no boss, so they cannot be.
        $heads = array();
        foreach ($edges as $e) {
            if ((int)$e['boss_comp_id'] !== (int)$compId) {
                continue;
            }
            if (!isset($tops[$e['boss_id']]) || !isset($people[$e['staff_id']])) {
                continue;
            }
            if (isset($tops[$e['staff_id']])) {
                continue;
            }
            $heads[$e['staff_id']] = true;
        }

        // A department this person heads, when there is one on file. Without it
        // the row falls back to reading as a person, rather than inventing a
        // unit name out of their position.
        // is_top is explicit rather than inferred from dept_name being null:
        // a direct report with no department on file also has no unit name, and
        // the app must not draw them as the person everyone else reports to.
        $row = function ($person, $asUnit) use ($people) {
            $r    = $people[$person];
            $unit = trim((string)$r['department']);
            return array(
                'person'       => $r['person'],
                'comp_id'      => (int)$r['comp_id'],
                'is_gw'        => 0,
                'fullname'     => $r['fullname'],
                'department'   => $r['department'],
                'position'     => $r['position'],
                'company_name' => $r['company_name'],
                'photo_url'    => ProfilePhoto::url($r['profile_pic']),
                'dept_name'    => ($asUnit && $unit !== '') ? $unit : null,
                'vacant'       => false,
                'is_top'       => !$asUnit,
            );
        };

        $byName = function ($a, $b) {
            return strcasecmp((string)$a['fullname'], (string)$b['fullname']);
        };

        $topRows = array();
        foreach (array_keys($tops) as $person) {
            $topRows[] = $row($person, false);
        }
        usort($topRows, $byName);

        $headRows = array();
        foreach (array_keys($heads) as $person) {
            $headRows[] = $row($person, true);
        }
        // Departments A-Z, with anyone who has none on file after them, so the
        // list reads as units first and loose people last.
        usort($headRows, function ($a, $b) use ($byName) {
            $da = (string)$a['dept_name'];
            $db = (string)$b['dept_name'];
            if (($da === '') !== ($db === '')) {
                return $da === '' ? 1 : -1;
            }
            $byDept = strcasecmp($da, $db);
            return $byDept !== 0 ? $byDept : $byName($a, $b);
        });

        return array_merge($topRows, $headRows);
    }

    /**
     * The person governing comp 1's departments, as a root row — or null when
     * the switch is off, the company is not comp 1, or he is no longer a
     * visible user.
     *
     * dept_name is null because he is not a department: the app draws a row
     * with no unit name as a person, which is what puts him at the head of the
     * list rather than among the folders.
     */
    public function manpowerTop($compId)
    {
        if (!self::MANPOWER_TOP_ENABLED || (int)$compId !== self::MANPOWER_COMP_ID) {
            return null;
        }

        $top = $this->findRelative(self::MANPOWER_TOP_PERSON, $compId);
        if (!$top) {
            return null;
        }

        $top['dept_name'] = null;
        $top['vacant']    = false;
        $top['is_top']    = true;
        return $top;
    }

    /**
     * How many people report directly to each of these, keyed "person|comp".
     *
     * ONE round trip for the whole list, not one per person: the chart tables
     * carry no index beyond their primary key, so a per-row count would scan
     * them once per row — the same trap the placed-set joins were rewritten to
     * avoid. A tuple IN scans each table once however long the list is.
     *
     * Staff and GW are counted together, because the app shows them in one
     * list and the number has to match what opening the row produces.
     *
     * Pairs absent from the result have no reports; the caller reads a missing
     * key as 0, so no row is returned for them.
     */
    public function subordinateCounts(array $pairs)
    {
        if (empty($pairs)) {
            return array();
        }

        $tuples = implode(',', array_fill(0, count($pairs), '(?, ?)'));
        $counts = array();

        // --- staff, from both reporting-line tables -----------------------
        // DISTINCT inside each arm, because monthly_assign keeps history: the
        // same person under the same boss on six live rows is one report. The
        // arms are disjoint by comp_id, so nobody is counted twice across them.
        $arms = array();
        foreach (self::chartSources() as $source) {
            list($table, $scope) = $source;
            $arms[] = "SELECT DISTINCT oc.boss_id, oc.boss_comp_id, oc.staff_id, oc.comp_id
                       FROM {$table} oc
                       INNER JOIN users u
                           ON u.person = oc.staff_id AND u.comp_id = oc.comp_id
                          AND " . self::USER_VISIBLE . "
                       WHERE {$scope}
                         AND (oc.boss_id, oc.boss_comp_id) IN ({$tuples})
                         AND " . self::ACTIVE_CLAUSE;
        }

        $sql = "SELECT t.boss_id AS p, t.boss_comp_id AS c, COUNT(*) AS n
                FROM (" . implode("\nUNION ALL\n", $arms) . ") t
                GROUP BY t.boss_id, t.boss_comp_id";

        $stmt = $this->db->prepare($sql);
        $i = 1;
        for ($b = 0; $b < count($arms); $b++) {
            foreach ($pairs as $pair) {
                $stmt->bindValue($i++, (string)$pair['person'], PDO::PARAM_STR);
                $stmt->bindValue($i++, (int)$pair['comp_id'], PDO::PARAM_INT);
            }
        }
        $stmt->execute();

        foreach ($stmt->fetchAll() as $r) {
            $counts[$r['p'] . '|' . (int)$r['c']] = (int)$r['n'];
        }

        // --- their GW ------------------------------------------------------
        $gwSql = "SELECT g.boss_id AS p, g.boss_comp_id AS c,
                         COUNT(DISTINCT g.monthly_assign_gw_code) AS n
                  FROM evaluation.monthly_assign_gw g
                  WHERE (g.boss_id, g.boss_comp_id) IN ({$tuples})
                    AND g.monthly_assign_gw_status = '1'
                  GROUP BY g.boss_id, g.boss_comp_id";

        $stmt = $this->db->prepare($gwSql);
        $i = 1;
        foreach ($pairs as $pair) {
            // boss_comp_id is varchar in this table — bound as a string so the
            // comparison does not cast the column and lose its index.
            $stmt->bindValue($i++, (string)$pair['person'], PDO::PARAM_STR);
            $stmt->bindValue($i++, (string)(int)$pair['comp_id'], PDO::PARAM_STR);
        }
        $stmt->execute();

        foreach ($stmt->fetchAll() as $r) {
            $key = $r['p'] . '|' . (int)$r['c'];
            $counts[$key] = (isset($counts[$key]) ? $counts[$key] : 0) + (int)$r['n'];
        }

        return $counts;
    }

    /** One person in the relatives shape, used to seat a GW's boss at the foot
     *  of their superior line. Null when they are not visible. */
    public function findRelative($person, $compId)
    {
        $sql = "SELECT u.person, u.comp_id, u.fullname, u.department, u.position,
                       s.name AS company_name,
                       " . self::STAFF_PHOTO_SUBQUERY . " AS profile_pic
                FROM users u
                LEFT JOIN subsidiaries s ON s.id = u.comp_id
                WHERE u.person = :person AND u.comp_id = :comp_id
                  AND " . self::USER_VISIBLE . "
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':person', $person, PDO::PARAM_STR);
        $stmt->bindValue(':comp_id', (int)$compId, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $this->shapeRelatives($stmt->fetchAll());
        return empty($rows) ? null : $rows[0];
    }

    /** The shape a superior or subordinate is listed in — enough to render a
     *  row with a face on it, and to open that person's own detail card. */
    private function shapeRelatives($rows)
    {
        $out = array();
        foreach ($rows as $r) {
            $out[] = array(
                'person'       => $r['person'],
                'comp_id'      => (int)$r['comp_id'],
                'is_gw'        => 0,
                'fullname'     => $r['fullname'],
                'department'   => $r['department'],
                'position'     => $r['position'],
                'company_name' => $r['company_name'],
                'photo_url'    => ProfilePhoto::url($r['profile_pic']),
            );
        }
        return $out;
    }

    /** The first tag containing the search term, or null — the GW mirror of the
     *  staff matched_tag subquery, done in PHP because the list is already here. */
    private static function firstMatchingTag($raw, $q)
    {
        $needle = strtolower((string)$q);
        if ($needle === '') {
            return null;
        }
        foreach (self::splitTags($raw) as $tag) {
            if (strpos(strtolower($tag), $needle) !== false) {
                return $tag;
            }
        }
        return null;
    }

    /** A stored tag list -> a clean array. Both populations keep tags the same
     *  way — a comma-separated column, users.tags and monthly_assign_gw_tags —
     *  so one splitter serves both. Blanks, duplicates and stray whitespace are
     *  dropped so the app never renders an empty or repeated chip. */
    private static function splitTags($raw)
    {
        if ($raw === null || trim($raw) === '') {
            return array();
        }
        $out = array();
        foreach (explode(',', $raw) as $tag) {
            $tag = trim($tag);
            if ($tag !== '' && !in_array($tag, $out, true)) {
                $out[] = $tag;
            }
        }
        return $out;
    }

    /** One shape for a staff row, used by both search and detail so the app
     *  renders a list hit and a detail header from identical fields. */
    private function shapeStaffRow($r)
    {
        return array(
            'person'       => $r['person'],
            'comp_id'      => (int)$r['comp_id'],
            'is_gw'        => 0,
            'fullname'     => $r['fullname'],
            'department'   => $r['department'],
            'position'     => $r['position'],
            'mobile_no'    => $r['mobile_no'],
            'email'        => $r['email'],
            'company_name' => $r['company_name'],
            'photo_url'    => ProfilePhoto::url($r['profile_pic']),
            'tags'         => self::splitTags(isset($r['tags']) ? $r['tags'] : null),
            'matched_task' => isset($r['matched_task']) ? $r['matched_task'] : null,
            'matched_tag'  => isset($r['matched_tag']) ? $r['matched_tag'] : null,
        );
    }

    /** shapeStaffRow, plus the tag that explains a text hit. Resolved here
     *  rather than with another subquery: the list is already in the row. */
    private function shapeStaffHit($r, $q)
    {
        $row = $this->shapeStaffRow($r);
        $row['matched_tag'] = ($q === null) ? null : self::firstMatchingTag($r['tags'], $q);
        return $row;
    }
}

/**
 * Committees a COMPANY may see - mkPortal.mk_committee + mk_committee_member.
 *
 * staffHierarchy.php answers "which committees is this person on", starting
 * from their seat. This answers the other question: "which committees does
 * this company have", starting from the company. Same two tables, opposite
 * direction, so the two readers live in different files on purpose.
 *
 * ---- Visibility ----------------------------------------------------------
 * mk_committee.comp_id is a comma-separated list of the companies allowed to
 * SEE the committee (see committee_visibility.sql):
 *
 *     NULL or ''   every company
 *     '1'          comp 1 only
 *     '1,2,3'      comps 1, 2 and 3
 *
 * It does NOT limit who may sit on one. A committee only comp 1 can see may
 * seat staff of comps 3 and 8, and whoever can see it gets the full
 * membership - the seats are keyed on (person, comp_id) and joined to users on
 * that pair, never on this column.
 *
 * FIND_IN_SET is the match, over REPLACE(..., ' ', '') so a hand-typed
 * '1, 2, 3' behaves like '1,2,3'. The column is small, hand-maintained and
 * read without an index either way - there is one live committee today.
 *
 * Staff only. A GW code lives in a different namespace that can collide with a
 * users.person, so GW are not seatable. See committee.sql.
 */
class CommitteeRepository
{
    private $db;

    const COMMITTEE = 'mkPortal.mk_committee';
    const MEMBER    = 'mkPortal.mk_committee_member';

    /** The committee itself is live today. Empty bounds mean "unbounded" -
     *  the same rule the reporting-line tables use for a date range. */
    const COMMITTEE_LIVE = "c.status = '1' AND c.deleted_at IS NULL
        AND (c.date_from IS NULL OR c.date_from <= CURDATE())
        AND (c.date_to   IS NULL OR c.date_to   >= CURDATE())";

    /** A seat is live today. Past members keep their row and simply stop being
     *  returned, so the history survives. */
    const MEMBER_LIVE = "m.status = '1' AND m.deleted_at IS NULL
        AND (m.date_from IS NULL OR m.date_from <= CURDATE())
        AND (m.date_to   IS NULL OR m.date_to   >= CURDATE())";

    /** Empty list = everyone, otherwise the asking company must be in it. */
    const VISIBLE_TO_COMP = "(c.comp_id IS NULL OR TRIM(c.comp_id) = ''
        OR FIND_IN_SET(:vis_comp_id, REPLACE(c.comp_id, ' ', '')) > 0)";

    /** Resolved once per request by installed(); null until then. */
    private $installed = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Are the committee tables actually there right now?
     *
     * Committees are optional by design - committee.sql may not have been run
     * on this host yet. Without this probe, a deploy in the wrong order turns
     * the Committees tab into a 500 instead of an empty list. Same contract
     * staffHierarchy.php has with the same two tables.
     *
     * One information_schema read, memoised for the request.
     */
    public function installed()
    {
        if ($this->installed !== null) {
            return $this->installed;
        }

        $this->installed = false;

        try {
            $sql = "SELECT COUNT(*) AS n FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = 'mkPortal'
                       AND TABLE_NAME IN ('mk_committee', 'mk_committee_member')";
            $row = $this->db->query($sql)->fetch();
            $this->installed = ($row && (int)$row['n'] === 2);
        } catch (Exception $e) {
            error_log('staffDirectory committee schema probe failed: ' . $e->getMessage());
        }

        return $this->installed;
    }

    /**
     * Query: every live committee this company is allowed to see.
     *
     * No join to subsidiaries on c.comp_id any more - it is a list now, and
     * s.id = '1,2,3' would silently resolve to subsidiary 1 rather than
     * failing. The company names belong to whoever is reading the list, so
     * CommitteeService resolves them from the parsed ids instead.
     */
    public function visibleTo($compId)
    {
        $sql = "SELECT c.id, c.name, c.code, c.description, c.comp_id,
                       c.date_from, c.date_to
                FROM " . self::COMMITTEE . " c
                WHERE " . self::COMMITTEE_LIVE . "
                  AND " . self::VISIBLE_TO_COMP . "
                ORDER BY c.name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':vis_comp_id', (string)(int)$compId, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Query: every live member of MANY committees in one round-trip, so the
     * list arrives with its people already in it and opening one costs nothing.
     *
     * The join to users is the cross-company part: it matches the seat's own
     * (person, comp_id), never the committee's visibility list, and hands back
     * each member's own company_name, department, position and photo.
     */
    public function membersOf(array $committeeIds)
    {
        if (empty($committeeIds)) {
            return array();
        }

        $place = implode(',', array_fill(0, count($committeeIds), '?'));

        $sql = "SELECT m.committee_id, m.committee_role AS role,
                       u.person, u.comp_id, u.fullname, u.department, u.position,
                       s.name AS company_name,
                       " . DirectoryRepository::STAFF_PHOTO_SUBQUERY . " AS profile_pic
                FROM " . self::MEMBER . " m
                INNER JOIN users u
                    ON u.person = m.person AND u.comp_id = m.comp_id
                   AND " . DirectoryRepository::USER_VISIBLE . "
                LEFT JOIN subsidiaries s ON s.id = u.comp_id
                WHERE m.committee_id IN ({$place})
                  AND " . self::MEMBER_LIVE . "
                ORDER BY m.sort_order ASC, u.fullname ASC";

        $stmt = $this->db->prepare($sql);
        $i = 1;
        foreach ($committeeIds as $id) {
            $stmt->bindValue($i++, (int)$id, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

}

/**
 * The company's committees, each carrying its full membership.
 *
 * Two round-trips no matter how many committees there are: the list, and every
 * member of all of them at once. Degrades to an empty list rather than failing
 * - the Committees view is a second face on a screen whose first job is the org
 * chart, and a fault there should not be able to take the chart down with it.
 */
class CommitteeService
{
    private $repo;

    public function __construct(CommitteeRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * '1, 2,3' -> array(1, 2, 3). NULL or empty -> array(), which means "every
     * company" everywhere this value is read.
     *
     * Non-numeric junk is dropped rather than coerced: the column is typed by
     * hand, and a stray word there should narrow nothing and widen nothing.
     */
    public static function parseCompIds($raw)
    {
        if ($raw === null || trim((string)$raw) === '') {
            return array();
        }

        $out = array();
        foreach (explode(',', (string)$raw) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $out[] = (int)$part;
            }
        }
        return array_values(array_unique($out));
    }

    public function forCompany($compId)
    {
        if (!$this->repo->installed()) {
            return array();
        }

        try {
            $committees = $this->repo->visibleTo($compId);
            if (empty($committees)) {
                return array();
            }

            $ids = array();
            foreach ($committees as $row) {
                $ids[] = (int)$row['id'];
            }

            $byCommittee = array();
            foreach ($this->repo->membersOf($ids) as $row) {
                $byCommittee[(int)$row['committee_id']][] = array(
                    'person'       => $row['person'],
                    'comp_id'      => (int)$row['comp_id'],
                    'fullname'     => $row['fullname'],
                    'department'   => $row['department'],
                    'position'     => $row['position'],
                    'company_name' => $row['company_name'],
                    'role'         => $row['role'],
                    // Always a staff record here, but the app keys people on
                    // (person, comp_id, is_gw) everywhere - so say so, and a
                    // committee member row works with the rest of the screen.
                    'is_gw'        => 0,
                    'photo_url'    => ProfilePhoto::url($row['profile_pic']),
                );
            }

            $out = array();
            foreach ($committees as $row) {
                $id      = (int)$row['id'];
                $members = isset($byCommittee[$id]) ? $byCommittee[$id] : array();

                $out[] = array(
                    'id'           => $id,
                    'name'         => $row['name'],
                    'code'         => $row['code'],
                    'description'  => $row['description'],
                    // Which companies may see this; [] = every company. Nothing
                    // on screen draws it - the filtering is already done by the
                    // time the list gets here - but it is the rule this row was
                    // selected by, and it costs only parsing a column already
                    // fetched. Worth having when a committee turns up somewhere
                    // it was not expected.
                    'visible_to'   => self::parseCompIds($row['comp_id']),
                    'member_count' => count($members),
                    'members'      => $members,
                );
            }

            return $out;
        } catch (Exception $e) {
            error_log('staffDirectory committee lookup failed: ' . $e->getMessage());
            return array();
        }
    }
}

/**
 * Orchestration: one directory question per method.
 */
class DirectoryService
{
    /** A one-character query would return most of a company; two is the floor. */
    const MIN_QUERY_LENGTH = 2;
    const MAX_RESULTS      = 40;

    /**
     * Browse returns a whole company, so its cap is per SIDE and far higher
     * than a search's: Arus Sawit alone is 91 staff + 608 GW. 1000 clears every
     * company on file with room to spare, and still bounds the response rather
     * than trusting the data to stay small.
     */
    const MAX_BROWSE = 1000;

    /** Same ceiling staffHierarchy.php walks to. A visited-set already makes
     *  cycles impossible; this bounds a pathologically deep chart. */
    const MAX_SUPERIOR_DEPTH = 10;

    private $repo;

    public function __construct(DirectoryRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Staff and GW matching $q in one company, merged into one list ordered by
     * name. Each side is capped at $limit, so a company with many GW cannot
     * push its staff out of the results.
     */
    public function search($compId, $q, $department, $limit)
    {
        $limit = max(1, min((int)$limit, self::MAX_RESULTS));

        $results = array_merge(
            $this->repo->searchStaff($compId, $q, $department, $limit),
            $this->repo->searchGw($compId, $q, $department, $limit)
        );

        usort($results, function ($a, $b) {
            return strcasecmp($a['fullname'], $b['fullname']);
        });

        return $results;
    }

    /**
     * The whole company (optionally one department), ordered so the client can
     * build its department sections in a single pass: department A-Z with the
     * no-department bucket last, then staff before GW within a department, then
     * name. GW districts and staff departments can collide as strings, so the
     * is_gw tiebreak keeps a mixed section from interleaving the two.
     */
    public function browse($compId, $department)
    {
        $results = array_merge(
            $this->repo->searchStaff($compId, null, $department, self::MAX_BROWSE),
            $this->repo->searchGw($compId, null, $department, self::MAX_BROWSE)
        );

        usort($results, function ($a, $b) {
            $da = trim((string)$a['department']);
            $db = trim((string)$b['department']);

            // "No department" is a real bucket, but it sorts last rather than
            // first — an empty string would otherwise head the whole list.
            if (($da === '') !== ($db === '')) {
                return $da === '' ? 1 : -1;
            }
            // Case-insensitive, so two spellings of one department land next to
            // each other and the client can fold them into a single section.
            $byDept = strcasecmp($da, $db);
            if ($byDept !== 0) {
                return $byDept;
            }
            // ...and a case-SENSITIVE tiebreak so rows sharing a spelling stay
            // together rather than interleaving by whatever order they arrived.
            $byExact = strcmp($da, $db);
            if ($byExact !== 0) {
                return $byExact;
            }
            if ($a['is_gw'] !== $b['is_gw']) {
                return $a['is_gw'] < $b['is_gw'] ? -1 : 1;
            }
            return strcasecmp($a['fullname'], $b['fullname']);
        });

        return $results;
    }

    /**
     * The whole superior line above someone, nearest first — not just their
     * direct boss. Mirrors staffHierarchy.php's buildSuperiorChain so the Staff
     * tab and this card label the same person the same way: each entry carries
     * `level` (1 = direct superior) and `is_top` (true when that person has no
     * active superior of their own).
     *
     * One query per person per level rather than staffHierarchy's batched BFS:
     * a chain is a handful of people, and this runs once when a card opens.
     * The visited-set keeps the shortest distance and makes cycles impossible.
     */
    public function superiorChain($person, $compId, $startLevel = 0)
    {
        $visited  = array($person . '|' . (int)$compId => true);
        $chain    = array();
        $index     = array();  // key => position in $chain, to backfill is_top
        $frontier = array(array('person' => $person, 'comp_id' => (int)$compId));
        $level    = $startLevel;

        while (!empty($frontier) && $level < self::MAX_SUPERIOR_DEPTH) {
            $next = array();

            foreach ($frontier as $member) {
                $memberKey = $member['person'] . '|' . (int)$member['comp_id'];
                $bosses    = $this->repo->directSuperiors($member['person'], $member['comp_id']);

                // Nobody above them: they are the top of this line. The person
                // the chain was built FOR is not in it, so they are never marked.
                if (empty($bosses) && isset($index[$memberKey])) {
                    $chain[$index[$memberKey]]['is_top'] = true;
                }

                foreach ($bosses as $boss) {
                    $key = $boss['person'] . '|' . (int)$boss['comp_id'];
                    if (isset($visited[$key])) {
                        continue; // already placed at an equal-or-nearer level
                    }
                    $visited[$key] = true;

                    $boss['level']  = $level + 1;
                    $boss['is_top'] = false;
                    $chain[]        = $boss;
                    $index[$key]    = count($chain) - 1;

                    $next[] = array('person' => $boss['person'], 'comp_id' => $boss['comp_id']);
                }
            }

            $frontier = $next;
            $level++;
        }

        return $chain;
    }

    /**
     * The org navigator's top level. department_head decides it wherever that
     * table has rows - only comp 1 does - and the reporting line decides it
     * everywhere else, which is the same split manpower_summary.php makes.
     * `source` names which rule produced the list.
     */
    public function roots($compId)
    {
        $roots = $this->repo->departmentRoots($compId);
        if (!empty($roots)) {
            // Prepended, not merged into the list: he governs the departments
            // rather than being one of them, and the app reads the first row
            // with is_top set as the head of the tree it draws.
            //
            // He is NOT removed from the departments if he also heads one —
            // dropping that row would hide a whole department, and heading a
            // team while running the company is not a contradiction worth
            // hiding.
            $top = $this->repo->manpowerTop($compId);
            if ($top) {
                array_unshift($roots, $top);
            }
            return array(
                'source' => 'department_head',
                'roots'  => $this->withSubordinateCounts($roots),
            );
        }
        return array(
            'source' => 'monthly_assign',
            'roots'  => $this->withSubordinateCounts($this->repo->chartRoots($compId)),
        );
    }

    /**
     * Command: stamp each row with how many people report to it.
     *
     * The app draws an expander only where this is above zero, so a row that
     * cannot open never offers to — the alternative is a caret on every leaf
     * and a round trip to find out it had nothing behind it.
     *
     * GW are skipped: nobody reports to a GW, so they are leaves by definition
     * and asking would be a query with a known answer.
     */
    private function withSubordinateCounts(array $rows)
    {
        $pairs = array();
        foreach ($rows as $r) {
            if (empty($r['is_gw'])) {
                $pairs[] = array('person' => $r['person'], 'comp_id' => $r['comp_id']);
            }
        }

        $counts = $this->repo->subordinateCounts($pairs);

        $out = array();
        foreach ($rows as $r) {
            $key = $r['person'] . '|' . (int)$r['comp_id'];
            $r['direct_subordinates'] = empty($r['is_gw']) && isset($counts[$key])
                ? $counts[$key]
                : 0;
            $out[] = $r;
        }
        return $out;
    }

    /** One person's detail card: identity, contacts, reporting line. */
    public function detail($person, $compId, $isGw)
    {
        if ($isGw) {
            $detail = $this->repo->gwDetail($person, $compId);
            if (!$detail) {
                return null;
            }
            // A GW's boss is a column on their own row, not an org-chart entry,
            // so their line is seeded by hand and then walked up the chart from
            // that boss — a GW gets the same full ladder as anyone else.
            $detail['superiors'] = array();
            if (!empty($detail['boss']['person']) && !empty($detail['boss']['comp_id'])) {
                $boss = $this->repo->findRelative($detail['boss']['person'], $detail['boss']['comp_id']);
                if ($boss) {
                    $boss['level']  = 1;
                    $boss['is_top'] = false;

                    $above = $this->superiorChain($boss['person'], $boss['comp_id'], 1);
                    // Nobody above the boss means the boss tops this line.
                    $boss['is_top'] = empty($above);

                    $detail['superiors'] = array_merge(array($boss), $above);
                }
            }
            unset($detail['boss']);
            // GW are leaves of the org chart — nobody reports to a GW.
            $detail['subordinates'] = array();
        } else {
            $detail = $this->repo->staffDetail($person, $compId);
            if (!$detail) {
                return null;
            }
            $detail['superiors']    = $this->superiorChain($person, $compId);
            $detail['subordinates'] = $this->withSubordinateCounts(
                $this->repo->directSubordinates($person, $compId)
            );
        }

        $detail['direct_subordinates'] = count($detail['subordinates']);
        return $detail;
    }
}

/** HTTP layer only: read input, delegate, respond. */
function respond($payload, $httpStatus = 200)
{
    http_response_code($httpStatus);
    echo json_encode($payload);
    exit;
}

function input($keys)
{
    foreach ((array)$keys as $key) {
        if (isset($_POST[$key]) && $_POST[$key] !== '') {
            return trim($_POST[$key]);
        }
        if (isset($_GET[$key]) && $_GET[$key] !== '') {
            return trim($_GET[$key]);
        }
    }
    return null;
}

try {
    $person = input('person');
    $compId = input(array('comp_id', 'comid'));

    if ($person === null || $compId === null) {
        respond(array('success' => false, 'message' => 'person and comp_id are required'), 400);
    }

    $repo   = new DirectoryRepository(Database::getConnection());
    $caller = $repo->findCaller($person, $compId);
    if (!$caller) {
        respond(array('success' => false, 'message' => 'Staff not found or inactive'), 404);
    }

    $service = new DirectoryService($repo);

    // Built eagerly but costs nothing until asked: the schema probe inside it
    // is lazy, so a request that never touches committees never runs it.
    $committeeRepo    = new CommitteeRepository(Database::getConnection());
    $committeeService = new CommitteeService($committeeRepo);

    $action = input('action');
    if ($action === null) {
        $action = 'search';
    }

    if ($action === 'companies') {
        respond(array(
            'success'      => true,
            'own_comp_id'  => (int)$caller['comp_id'],
            'companies'    => $repo->companies((int)$caller['comp_id']),
        ));
    }

    // Any company may be browsed, but the DEFAULT is always the caller's own —
    // so the sheet opens on the company they actually work in.
    $targetComp = input('target_comp_id');
    if ($targetComp === null) {
        $targetComp = $caller['comp_id'];
    }

    // Absent = every department. Present = exactly that one, with the
    // NO_DEPARTMENT token selecting the people who have none on file.
    $department = input('department');

    if ($action === 'departments') {
        $lists = $repo->departments($targetComp);
        respond(array(
            'success'       => true,
            'comp_id'       => (int)$targetComp,
            'no_department' => DirectoryRepository::NO_DEPARTMENT,
            'departments'   => $lists['departments'],
            'gw_districts'  => $lists['gw_districts'],
        ));
    }

    if ($action === 'roots') {
        $result = $service->roots($targetComp);
        respond(array(
            'success' => true,
            'comp_id' => (int)$targetComp,
            'source'  => $result['source'],
            'count'   => count($result['roots']),
            'roots'   => $result['roots'],
        ));
    }

    // The company's committees rather than its chart - the other way of
    // reading "who is in this company", and the Staff tab's top level offers
    // both. Visibility is mk_committee.comp_id (see committee_visibility.sql);
    // membership is not filtered by it, so a committee this company may see is
    // returned with every one of its members, whatever company they are from.
    if ($action === 'committees') {
        $committees = $committeeService->forCompany($targetComp);
        respond(array(
            'success'    => true,
            'comp_id'    => (int)$targetComp,
            // false when committee.sql has not been run on this host. The app
            // says so rather than drawing an empty list that looks like a
            // company with no committees.
            'installed'  => $committeeRepo->installed(),
            'count'      => count($committees),
            'committees' => $committees,
        ));
    }

    if ($action === 'browse') {
        $results = $service->browse($targetComp, $department);
        respond(array(
            'success'    => true,
            'comp_id'    => (int)$targetComp,
            'department' => $department,
            'count'      => count($results),
            // Says so rather than silently showing a partial company.
            'truncated'  => count($results) >= DirectoryService::MAX_BROWSE,
            'results'    => $results,
        ));
    }

    if ($action === 'detail') {
        $targetPerson = input('target_person');
        if ($targetPerson === null) {
            respond(array('success' => false, 'message' => 'target_person is required'), 400);
        }
        $isGw = (int)input('is_gw') === 1;

        $detail = $service->detail($targetPerson, $targetComp, $isGw);
        if (!$detail) {
            respond(array('success' => false, 'message' => 'Staff not found or inactive'), 404);
        }
        respond(array('success' => true, 'staff' => $detail));
    }

    if ($action === 'search') {
        $q = input('q');
        if ($q === null || strlen($q) < DirectoryService::MIN_QUERY_LENGTH) {
            respond(array(
                'success' => true,
                'comp_id' => (int)$targetComp,
                'query'   => $q === null ? '' : $q,
                'results' => array(),
                'message' => 'Type at least ' . DirectoryService::MIN_QUERY_LENGTH . ' characters.',
            ));
        }

        $limit   = input('limit');
        $results = $service->search(
            $targetComp,
            $q,
            $department,
            $limit === null ? DirectoryService::MAX_RESULTS : $limit
        );

        respond(array(
            'success'    => true,
            'comp_id'    => (int)$targetComp,
            'department' => $department,
            'query'      => $q,
            'count'      => count($results),
            'results'    => $results,
        ));
    }

    respond(array('success' => false, 'message' => 'Unknown action'), 400);
} catch (Exception $e) {
    error_log('staffDirectory error: ' . $e->getMessage());
    respond(array('success' => false, 'message' => 'System error occurred'), 500);
}
