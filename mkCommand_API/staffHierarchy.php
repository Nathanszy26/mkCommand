<?php
header('Content-Type: application/json; charset=UTF-8');

/**
 * Staff hierarchy endpoint.
 *
 * IN  : person, comp_id (alias: comid)
 * OUT : { success, staff, superiors[], summary{}, subordinates[], committees[] }
 *
 * subordinates[] is a nested tree; every node carries total_subordinates
 * (all descendants, not just direct children).
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
 * the bucket root; the app only ever gets the finished absolute URL.
 *
 * Mirrors staffDirectory.php's class of the same name — these endpoints are
 * deliberately self-contained, as DatabaseConfig already is.
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
 * All reporting-line reads. Nothing here knows about HTTP.
 */
class HierarchyRepository
{
    private $db;

    /** Assignment is live today. Empty / NULL bounds mean "unbounded". */
    const ACTIVE_CLAUSE = "
        (oc.monthly_assign_from IS NULL OR oc.monthly_assign_from = ''
            OR STR_TO_DATE(REPLACE(oc.monthly_assign_from, '-', '/'), '%Y/%m/%d') <= CURDATE())
        AND (oc.monthly_assign_to IS NULL OR oc.monthly_assign_to = ''
            OR STR_TO_DATE(REPLACE(oc.monthly_assign_to, '-', '/'), '%Y/%m/%d') >= CURDATE())";

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
     * mirrors enroll_admin.php and staffDirectory.php, so one person shows the
     * same face on the Staff tab, in the directory, and in face enrolment:
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
     * Link 3 cannot use an index, but COALESCE stops at the first non-NULL, so
     * it only ever runs for someone links 1 and 2 could not resolve, and it is
     * scoped to one company's profiles.
     *
     * staff_profile lives in this same schema, so no second connection.
     */
    const PHOTO_SUBQUERY = "COALESCE(
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

    /** Every staff query selects the same columns, so adding one here reaches
     *  the self card, the superior ladder and every node of the tree at once. */
    const USER_FIELDS = "u.id, u.person, u.comp_id, u.fullname, u.department, u.position, s.name AS company_name,
                         " . self::PHOTO_SUBQUERY . " AS profile_pic";

    /** A user is visible to MK Command at all. mk_command_excluded = 1 hides
     *  them everywhere in this app — hierarchy, job specs, the lot. NULL means
     *  never set, which is not excluded. */
    const USER_VISIBLE = "u.status = 1 AND IFNULL(u.deleted, 0) = 0
                          AND IFNULL(u.mk_command_excluded, 0) <> 1";

    /** Comp 1's reporting lines live in organization_chart_glob; every other
     *  company's live in evaluation.monthly_assign, the table the KPI pages
     *  maintain. Routing is on the STAFF-side comp_id (the oc.comp_id column)
     *  — that column owns the row. A boss may therefore have children in both
     *  tables, so the reads below UNION them.
     *
     *  The two carry the same columns, so ACTIVE_CLAUSE applies to either
     *  unchanged. They differ in one way that matters: monthly_assign keeps history, so one
     *  reporting line can hold several live rows.
     *  Both walks below key their nodes by (person, comp_id) and skip a key
     *  they have already placed, so the extra rows collapse there rather than
     *  being deduplicated in SQL. */
    const GLOB_COMP_ID = 1;

    /**
     * [table, staff-side scope] for every chart. The scopes are disjoint, so
     * UNION ALL can never double-count, and a comp-1 row left behind in the old
     * table during migration is ignored rather than silently duplicated.
     *
     * Top-level UNION ALL (not a derived table) on purpose: MySQL materializes
     * a UNION derived table instead of merging it, which would push the
     * (boss_id, boss_comp_id) predicate outside the branches and force a full
     * scan of both charts on every BFS level.
     */
    private static function chartSources()
    {
        return array(
            array('evaluation.monthly_assign',    'oc.comp_id <> ' . self::GLOB_COMP_ID),
            array('organization_chart_glob', 'oc.comp_id = '  . self::GLOB_COMP_ID),
        );
    }

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Command: bind the same (person, comp_id) list once per UNION branch.
     * Positional placeholders — a named placeholder repeated across branches is
     * not reliably bindable under PDO's emulated prepares.
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

    public function findStaff($person, $compId)
    {
        $sql = "SELECT u.id, u.person, u.comp_id, u.fullname, u.department, u.position, u.email,
                       s.name AS company_name,
                       " . self::PHOTO_SUBQUERY . " AS profile_pic
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
     * Direct bosses of MANY staff in one round-trip (mirror of findSubordinatesOf).
     * $staff: [['person' => ..., 'comp_id' => ...], ...]
     * u is the boss; sub_person / sub_comp_id keep who they supervise.
     */
    public function findSuperiorsOf(array $staff)
    {
        if (empty($staff)) {
            return [];
        }

        $tuples   = implode(',', array_fill(0, count($staff), '(?, ?)'));
        $branches = array();

        foreach (self::chartSources() as $source) {
            list($table, $scope) = $source;
            $branches[] = "SELECT " . self::USER_FIELDS . ",
                       oc.staff_id AS sub_person, oc.comp_id AS sub_comp_id,
                       oc.monthly_assign_from, oc.monthly_assign_to
                FROM {$table} oc
                INNER JOIN users u
                    ON u.person = oc.boss_id AND u.comp_id = oc.boss_comp_id
                   AND " . self::USER_VISIBLE . "
                LEFT JOIN subsidiaries s ON s.id = u.comp_id
                WHERE {$scope}
                  AND (oc.staff_id, oc.comp_id) IN ({$tuples})
                  AND " . self::ACTIVE_CLAUSE;
        }

        // ORDER BY applies to the whole UNION, so it names the output column.
        $sql = implode("\nUNION ALL\n", $branches) . "\nORDER BY fullname ASC";

        $stmt = $this->db->prepare($sql);
        $this->bindPairsPerBranch($stmt, $staff, count($branches));
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Direct subordinates of MANY bosses in one round-trip.
     * $bosses: [['person' => ..., 'comp_id' => ...], ...]
     * Each returned row keeps boss_id / boss_comp_id so the caller can re-parent it.
     */
    public function findSubordinatesOf(array $bosses)
    {
        if (empty($bosses)) {
            return [];
        }

        $tuples   = implode(',', array_fill(0, count($bosses), '(?, ?)'));
        $branches = array();

        // A boss's comp_id says nothing about their children's comp_id, so both
        // charts are read — still ONE round-trip per BFS level.
        foreach (self::chartSources() as $source) {
            list($table, $scope) = $source;
            $branches[] = "SELECT " . self::USER_FIELDS . ",
                       oc.boss_id, oc.boss_comp_id,
                       oc.monthly_assign_from, oc.monthly_assign_to
                FROM {$table} oc
                INNER JOIN users u
                    ON u.person = oc.staff_id AND u.comp_id = oc.comp_id
                   AND " . self::USER_VISIBLE . "
                LEFT JOIN subsidiaries s ON s.id = u.comp_id
                WHERE {$scope}
                  AND (oc.boss_id, oc.boss_comp_id) IN ({$tuples})
                  AND " . self::ACTIVE_CLAUSE;
        }

        $sql = implode("\nUNION ALL\n", $branches) . "\nORDER BY fullname ASC";

        $stmt = $this->db->prepare($sql);
        $this->bindPairsPerBranch($stmt, $bosses, count($branches));
        $stmt->execute();

        return $stmt->fetchAll();
    }
}

/**
 * Yearly evaluation scores. evaluation2017.eval_submissions sits on the same
 * MySQL instance as staff_portal2, so it's reachable through the same PDO
 * connection via a fully-qualified table name (no second connection).
 * Requires the connecting user to have SELECT on evaluation2017.
 */
class EvaluationRepository
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Yearly average for MANY staff in one round-trip.
     * Mirrors the Yearly Report rule: each month = mean of that month's
     * submissions; the year = mean of ONLY the months that have a result
     * (empty months are skipped, never counted as zero).
     *
     * $staff: [['person' => ..., 'comp_id' => ...], ...]
     * Returns: [ 'person|comp_id' => float, ... ] — only staff with >=1 result.
     */
    public function yearlyAveragesFor(array $staff, $year)
    {
        if (empty($staff)) {
            return [];
        }

        $tuples = implode(',', array_fill(0, count($staff), '(?, ?)'));

        $sql = "SELECT m.comp_id, m.person, AVG(m.month_avg) AS yearly_avg
                FROM (
                    SELECT eval_submission_comp_id  AS comp_id,
                           eval_submission_staff_id AS person,
                           AVG(CAST(eval_submission_result AS DECIMAL(7,2))) AS month_avg
                    FROM evaluation2017.eval_submissions
                    WHERE eval_submission_year    = ?
                      AND eval_submission_deleted = '0'
                      AND (eval_submission_staff_id, eval_submission_comp_id) IN ({$tuples})
                    GROUP BY eval_submission_comp_id, eval_submission_staff_id, eval_submission_month
                ) AS m
                GROUP BY m.comp_id, m.person";

        $stmt = $this->db->prepare($sql);

        $i = 1;
        $stmt->bindValue($i++, (string)$year, PDO::PARAM_STR);
        foreach ($staff as $member) {
            $stmt->bindValue($i++, $member['person'], PDO::PARAM_STR);
            $stmt->bindValue($i++, (int)$member['comp_id'], PDO::PARAM_INT);
        }
        $stmt->execute();

        $scores = array();
        foreach ($stmt->fetchAll() as $row) {
            $key = $row['person'] . '|' . (int)$row['comp_id'];
            $scores[$key] = round((float)$row['yearly_avg'], 2);
        }
        return $scores;
    }
}

/**
 * GW (guest worker) subordinates — evaluation.monthly_assign_gw.
 *
 * Same MySQL instance as staff_portal2, so it's reachable through the same PDO
 * connection via a fully-qualified table name (identical to EvaluationRepository).
 * Requires the connecting user to have SELECT on `evaluation`.
 *
 * GW are leaves: they never supervise anyone, so ONE batched query covers the
 * whole tree — no extra BFS level.
 *
 * Identity of a GW = (monthly_assign_gw_code, comp_id) with is_gw = 1.
 */
class GwRepository
{
    private $db;

    /** Live, scorecard-included GW inside comp 1 only. */
    const ACTIVE_CLAUSE = "
        mag.comp_id = '1'
        AND mag.monthly_assign_gw_status = '1'
        AND (mag.kpi_scorecard_exclude IS NULL OR mag.kpi_scorecard_exclude <> '1')";

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Direct GW of MANY bosses in one round-trip (mirror of findSubordinatesOf),
     * each carrying its evaluation score for ONE month.
     *
     * Score rule, taken from the legacy GW Result page (gwresult.php): the row in
     * monthly_evaluation_gw keyed on (monthly_assign_gw_id, evaluator, year,
     * month). AVG() guards against the same boss submitting more than once in a
     * month — same rule EvaluationRepository applies to staff. NULL = not
     * evaluated that month; it is never treated as zero.
     *
     * $bosses: [['person' => ..., 'comp_id' => ...], ...] — comp 1 bosses only.
     * $month : '01'..'12'.
     */
    public function findGwOf(array $bosses, $year, $month)
    {
        if (empty($bosses)) {
            return array();
        }

        $tuples = implode(',', array_fill(0, count($bosses), '(?, ?)'));

        $sql = "SELECT mag.monthly_assign_gw_code     AS person,
                       mag.comp_id                    AS comp_id,
                       mag.monthly_assign_gw_fullname AS fullname,
                       mag.monthly_assign_gw_district AS district,
                       mag.monthly_assign_gw_mobile_no AS mobile_no,
                       mag.boss_id, mag.boss_comp_id,
                       AVG(CAST(meg.monthly_evaluation_result AS DECIMAL(7,2))) AS score
                FROM evaluation.monthly_assign_gw mag
                LEFT JOIN evaluation2015.monthly_evaluation_gw meg
                       ON meg.monthly_assign_gw_id      = mag.monthly_assign_gw_id
                      AND meg.monthly_evaluation_by      = mag.boss_id
                      AND meg.monthly_evaluation_by_comp = mag.boss_comp_id
                      AND meg.monthly_evaluation_year    = ?
                      AND meg.monthly_evaluation_month   = ?
                WHERE (mag.boss_id, mag.boss_comp_id) IN ({$tuples})
                  AND " . self::ACTIVE_CLAUSE . "
                GROUP BY mag.monthly_assign_gw_id
                ORDER BY mag.monthly_assign_gw_fullname ASC";

        $stmt = $this->db->prepare($sql);

        $i = 1;
        $stmt->bindValue($i++, (string)$year, PDO::PARAM_STR);
        $stmt->bindValue($i++, (string)$month, PDO::PARAM_STR);
        foreach ($bosses as $boss) {
            // boss_id / boss_comp_id are varchar in this table — bind as strings.
            $stmt->bindValue($i++, (string)$boss['person'], PDO::PARAM_STR);
            $stmt->bindValue($i++, (string)$boss['comp_id'], PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * One company's GW photos, keyed by digit-only phone number.
     *
     * gw_profile has no GW code to join on — it is keyed on the phone number
     * the worker signed up with, and `person` holds that number rather than a
     * code. Comparing those means stripping separators off both sides, which
     * defeats every index, so this is read in one go (259 rows company-wide)
     * and matched in PHP rather than per node.
     */
    public function gwPhotoIndex($compId)
    {
        $sql = "SELECT p.person, p.mobile, p.profile_pic
                FROM staff_portal2.gw_profile p
                WHERE p.comp_id = :comp_id AND p.deleted_at IS NULL
                  AND p.profile_pic IS NOT NULL AND p.profile_pic <> ''";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':comp_id', (int)$compId, PDO::PARAM_INT);
        $stmt->execute();

        $index = array();
        foreach ($stmt->fetchAll() as $r) {
            // `person` wins over `mobile` when they disagree: it is the column
            // the signup writes.
            $byMobile = preg_replace('/\D/', '', (string)$r['mobile']);
            if ($byMobile !== '' && !isset($index[$byMobile])) {
                $index[$byMobile] = $r['profile_pic'];
            }
            $byPerson = preg_replace('/\D/', '', (string)$r['person']);
            if ($byPerson !== '') {
                $index[$byPerson] = $r['profile_pic'];
            }
        }
        return $index;
    }
}

/**
 * Committees - mkPortal.mk_committee + mk_committee_member.
 *
 * mkPortal is MK Command's own schema (job_spec_version lives there too) and
 * sits on the same MySQL instance as staff_portal2, so it is reachable through
 * this connection fully-qualified - identical to how EvaluationRepository
 * reaches evaluation2017. Requires SELECT on `mkPortal`.
 *
 * A committee is NOT confined to one company. Seats are keyed on
 * (person, comp_id) and joined to users on that pair, so one committee can hold
 * staff of several subsidiaries and every member carries its own company_name.
 *
 * mk_committee.comp_id is a comma-separated list of the companies allowed to
 * SEE the committee in staffDirectory.php's Committees list - NULL or empty
 * means every company (see committee_visibility.sql). It still limits nothing
 * about membership, and this file does not filter on it AT ALL: the question
 * here is "which committees is this person on", and that is answered by their
 * seat. Hiding a committee somebody actually sits on, because their company is
 * not on the viewer list, would be a lie rather than a permission.
 *
 * Staff only - a GW code lives in a different namespace that can collide with a
 * users.person, so GW are not seatable. See committee.sql.
 */
class CommitteeRepository
{
    private $db;

    const COMMITTEE = 'mkPortal.mk_committee';
    const MEMBER    = 'mkPortal.mk_committee_member';

    /** The committee itself is live today. Empty bounds mean "unbounded",
     *  the same rule ACTIVE_CLAUSE applies to a reporting line. */
    const COMMITTEE_LIVE = "c.status = '1' AND c.deleted_at IS NULL
        AND (c.date_from IS NULL OR c.date_from <= CURDATE())
        AND (c.date_to   IS NULL OR c.date_to   >= CURDATE())";

    /** A seat on a committee is live today. Past members keep their row and
     *  simply stop being returned, so the history survives. */
    const MEMBER_LIVE = "m.status = '1' AND m.deleted_at IS NULL
        AND (m.date_from IS NULL OR m.date_from <= CURDATE())
        AND (m.date_to   IS NULL OR m.date_to   >= CURDATE())";

    /** Resolved once per request by installed(); null until then. */
    private $installed = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Are the committee tables actually there right now?
     *
     * Committees are optional by design: committee.sql can be run before or
     * after this file is uploaded and the Staff tab keeps working either way -
     * it just reports no committees until the tables exist. Without this probe
     * a deploy in the wrong order takes the WHOLE hierarchy down with an SQL
     * error, over a feature nobody is using yet. Same contract staffDirectory
     * has with its tag columns.
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
            // No information_schema grant: behave as if committees are not
            // installed rather than failing the hierarchy over an add-on.
            error_log('staffHierarchy committee schema probe failed: ' . $e->getMessage());
        }

        return $this->installed;
    }

    /**
     * Query: the live committees ONE staff sits on, with that staff's own role.
     * Empty when they sit on none - which is how the app decides not to draw
     * the section at all.
     */
    public function committeesOf($person, $compId)
    {
        // No join to subsidiaries on c.comp_id: it holds a LIST now, and
        // `s.id = '1,2,3'` does not fail in MySQL - it coerces to 1 and
        // quietly names the wrong company. The ids are parsed in PHP instead.
        $sql = "SELECT c.id, c.name, c.code, c.description, c.comp_id,
                       m.committee_role AS role
                FROM " . self::MEMBER . " m
                INNER JOIN " . self::COMMITTEE . " c ON c.id = m.committee_id
                WHERE m.person = :person AND m.comp_id = :comp_id
                  AND " . self::MEMBER_LIVE . "
                  AND " . self::COMMITTEE_LIVE . "
                ORDER BY c.name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':person', $person, PDO::PARAM_STR);
        $stmt->bindValue(':comp_id', (int)$compId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Query: every live member of MANY committees in one round-trip, so opening
     * a committee in the app costs nothing - the membership is already there.
     *
     * The join to users is the cross-company part: it matches on the seat's own
     * (person, comp_id), never on the committee's company, and USER_FIELDS hands
     * back each member's own company_name, department, position and photo.
     */
    public function membersOf(array $committeeIds)
    {
        if (empty($committeeIds)) {
            return array();
        }

        $place = implode(',', array_fill(0, count($committeeIds), '?'));

        $sql = "SELECT m.committee_id, m.committee_role AS role,
                       " . HierarchyRepository::USER_FIELDS . "
                FROM " . self::MEMBER . " m
                INNER JOIN users u
                    ON u.person = m.person AND u.comp_id = m.comp_id
                   AND " . HierarchyRepository::USER_VISIBLE . "
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
 * Committees of one staff, each carrying its full membership.
 *
 * Two round-trips no matter how many committees there are, and the whole thing
 * degrades to [] rather than failing: the Staff tab is a reporting-line screen
 * first, and losing the committee card is a far smaller fault than losing the
 * tree. (Contrast HierarchyService::attachGw, which deliberately does NOT catch
 * - dropping subordinates there would be an invisible correctness bug.)
 */
class CommitteeService
{
    private $repo;

    public function __construct(CommitteeRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * '1, 2,3' -> array(1, 2, 3). NULL or empty -> array(), meaning "every
     * company". Mirrors staffDirectory.php's CommitteeService::parseCompIds -
     * two files read the same hand-typed column, so they must read it the
     * same way. Change one, change the other.
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

    public function committeesFor($person, $compId)
    {
        if (!$this->repo->installed()) {
            return array();
        }

        try {
            $committees = $this->repo->committeesOf($person, $compId);
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
                    'id'           => (int)$row['id'],
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
                    // Which companies may see this in the Committees list. []
                    // = all of them. Reported for parity with staffDirectory's
                    // committees action; it does not gate anything here.
                    'visible_to'   => self::parseCompIds($row['comp_id']),
                    'my_role'      => $row['role'],
                    'member_count' => count($members),
                    'members'      => $members,
                );
            }

            return $out;
        } catch (Exception $e) {
            error_log('staffHierarchy committee lookup failed: ' . $e->getMessage());
            return array();
        }
    }
}

/**
 * Turns flat assignment rows into the nested tree + totals.
 */
class HierarchyService
{
    const MAX_DEPTH = 10;

    /** GW only exist for comp 1 staff, and only as comp 1 GW. */
    const GW_COMP_ID = 1;

    private $repo;
    private $evalRepo;
    private $gwRepo;
    private $year;
    private $gwMonth;

    /** $year drives the staff yearly average; ($year, $gwMonth) the GW monthly score. */
    public function __construct(
        HierarchyRepository $repo,
        EvaluationRepository $evalRepo,
        GwRepository $gwRepo,
        $year,
        $gwMonth
    ) {
        $this->repo     = $repo;
        $this->evalRepo = $evalRepo;
        $this->gwRepo   = $gwRepo;
        $this->year     = $year;
        $this->gwMonth  = $gwMonth;
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
     * BFS one level at a time: 1 query per depth, not per node.
     * Visited-set makes cycles (A -> B -> A) impossible.
     */
    public function buildSubordinateTree($person, $compId)
    {
        $rootKey    = self::key($person, $compId);
        $frontier   = [['person' => $person, 'comp_id' => (int)$compId]];
        $visited    = [$rootKey => true];
        $nodes      = [];              // key => node payload
        $childKeys  = [$rootKey => []]; // key => [child keys]
        $depth      = 0;

        while (!empty($frontier) && $depth < self::MAX_DEPTH) {
            $rows  = $this->repo->findSubordinatesOf($frontier);
            $next  = [];

            foreach ($rows as $row) {
                $key = self::key($row['person'], $row['comp_id']);
                if (isset($visited[$key])) {
                    continue; // already placed elsewhere in the tree
                }
                $visited[$key] = true;

                $nodes[$key] = [
                    'id'                  => (int)$row['id'],
                    'person'              => $row['person'],
                    'comp_id'             => (int)$row['comp_id'],
                    'fullname'            => $row['fullname'],
                    'department'          => $row['department'],
                    'position'            => $row['position'],
                    'company_name'        => $row['company_name'],
                    'monthly_assign_from' => $row['monthly_assign_from'],
                    'monthly_assign_to'   => $row['monthly_assign_to'],
                    'is_gw'               => 0,
                    'photo_url'           => ProfilePhoto::url($row['profile_pic']),
                ];
                $childKeys[$key] = [];

                $parentKey = self::key($row['boss_id'], $row['boss_comp_id']);
                $childKeys[$parentKey][] = $key;

                $next[] = ['person' => $row['person'], 'comp_id' => (int)$row['comp_id']];
            }

            $frontier = $next;
            $depth++;
        }

        // GW are leaves — attached after the BFS in one batched query, so they
        // are counted by both `total` and `direct` below.
        $this->attachGw($person, $compId, $nodes, $childKeys);

        $nodes = $this->scoreNodes($nodes);
        $tree  = $this->assemble($rootKey, $nodes, $childKeys);
        $total = count($nodes); // every descendant, all levels

        return [
            'tree'   => $tree,
            'total'  => $total,
            'direct' => count($childKeys[$rootKey]),
        ];
    }

    /**
     * Command: attach GW as leaf nodes under every comp-1 boss already in the
     * tree (root included). Mutates $nodes / $childKeys in place.
     *
     * Deliberately NOT wrapped in try/catch: a missing SELECT grant on
     * `evaluation` must fail loudly at deploy time, because silently dropping
     * subordinates is an invisible correctness bug.
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
        if (empty($bosses)) {
            return;
        }

        // One lookup for the whole batch; GW only exist in comp 1 here.
        $photos = $this->gwRepo->gwPhotoIndex(self::GW_COMP_ID);

        foreach ($this->gwRepo->findGwOf($bosses, $this->year, $this->gwMonth) as $row) {
            $key = self::gwKey($row['person'], $row['comp_id']);
            if (isset($nodes[$key])) {
                continue; // already placed under another boss in this tree
            }
            $parentKey = self::key($row['boss_id'], $row['boss_comp_id']);
            if (!isset($childKeys[$parentKey])) {
                continue; // boss isn't part of this tree
            }

            $nodes[$key] = array(
                'id'                  => null, // monthly_assign_gw_id would collide with users.id
                'person'              => $row['person'],   // monthly_assign_gw_code
                'comp_id'             => (int)$row['comp_id'],
                'fullname'            => $row['fullname'],
                'department'          => $row['district'],
                'position'            => 'General Worker',
                'company_name'        => null,
                'monthly_assign_from' => null,
                'monthly_assign_to'   => null,
                'is_gw'               => 1,
                // Monthly GW score (scoreNodes leaves GW alone). null = not evaluated.
                'score'               => $row['score'] === null ? null : round((float)$row['score'], 2),
                'photo_url'           => self::gwPhotoFor($row['mobile_no'], $photos),
            );
            $childKeys[$key]         = array();
            $childKeys[$parentKey][] = $key;
        }
    }

    /** One GW's photo URL from the batched index, or null. Their phone number
     *  is the only link to gw_profile, so it is matched on digits alone. */
    private static function gwPhotoFor($mobileNo, array $photos)
    {
        $digits = preg_replace('/\D/', '', (string)$mobileNo);
        return ($digits !== '' && isset($photos[$digits]))
            ? ProfilePhoto::url($photos[$digits])
            : null;
    }

    /**
     * Full superior chain to the top: BFS upward, 1 query per level.
     * Returns a flat list ordered nearest-first; each entry carries `level`
     * (1 = direct superior) and `is_top` (true when that person has no active
     * superior of their own). Visited-set keeps the shortest distance and makes
     * cycles impossible; `direct` counts the level-1 entries.
     */
    public function buildSuperiorChain($person, $compId)
    {
        $rootKey  = self::key($person, $compId);
        $frontier = [['person' => $person, 'comp_id' => (int)$compId]];
        $visited  = [$rootKey => true];
        $chain    = [];
        $index    = [];   // key => position in $chain (to backfill is_top)
        $direct   = 0;
        $level    = 0;

        while (!empty($frontier) && $level < self::MAX_DEPTH) {
            $rows = $this->repo->findSuperiorsOf($frontier);

            // A frontier member is a "top" if the fetch found no superior for it.
            $hasBoss = [];
            foreach ($rows as $row) {
                $hasBoss[self::key($row['sub_person'], $row['sub_comp_id'])] = true;
            }
            foreach ($frontier as $member) {
                $mKey = self::key($member['person'], $member['comp_id']);
                if ($mKey !== $rootKey && !isset($hasBoss[$mKey]) && isset($index[$mKey])) {
                    $chain[$index[$mKey]]['is_top'] = true;
                }
            }

            $next = [];
            foreach ($rows as $row) {
                $key = self::key($row['person'], $row['comp_id']);
                if (isset($visited[$key])) {
                    continue; // already placed at an equal-or-nearer level
                }
                $visited[$key] = true;

                $chain[] = [
                    'id'           => (int)$row['id'],
                    'person'       => $row['person'],
                    'comp_id'      => (int)$row['comp_id'],
                    'fullname'     => $row['fullname'],
                    'department'   => $row['department'],
                    'position'     => $row['position'],
                    'company_name' => $row['company_name'],
                    'level'        => $level + 1,
                    'is_top'       => false,
                    'photo_url'    => ProfilePhoto::url($row['profile_pic']),
                ];
                $index[$key] = count($chain) - 1;

                if ($level === 0) {
                    $direct++;
                }

                $next[] = ['person' => $row['person'], 'comp_id' => (int)$row['comp_id']];
            }

            $frontier = $next;
            $level++;
        }

        return ['chain' => $chain, 'direct' => $direct];
    }

    /**
     * Batched score enrichment: one query for the whole tree. Attaches a
     * `score` (yearly evaluation average) or null to every node. Score-lookup
     * failure degrades to null scores — it never fails the hierarchy.
     */
    private function scoreNodes(array $nodes)
    {
        if (empty($nodes)) {
            return $nodes;
        }

        // GW are excluded: eval_submissions is keyed on users.person, so a GW
        // code would either miss or (worse) collide with a real staff record.
        $staffList = array();
        foreach ($nodes as $node) {
            if (!empty($node['is_gw'])) {
                continue;
            }
            $staffList[] = array('person' => $node['person'], 'comp_id' => $node['comp_id']);
        }

        try {
            $scores = $this->evalRepo->yearlyAveragesFor($staffList, $this->year);
        } catch (Exception $e) {
            error_log('staffHierarchy score lookup failed: ' . $e->getMessage());
            $scores = array();
        }

        foreach ($nodes as $key => $node) {
            if (!empty($node['is_gw'])) {
                continue; // already scored by attachGw, from a different table
            }
            $nodes[$key]['score'] = isset($scores[$key]) ? $scores[$key] : null;
        }
        return $nodes;
    }

    /** Depth-first assembly; total_subordinates is computed on the way back up. */
    private function assemble($key, array $nodes, array $childKeys)
    {
        $children = [];

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
    $compId = input(['comp_id', 'comid']);

    if ($person === null || $compId === null) {
        respond(['success' => false, 'message' => 'person and comp_id are required'], 400);
    }

    $repo      = new HierarchyRepository(Database::getConnection());
    $evalRepo  = new EvaluationRepository(Database::getConnection());
    $gwRepo    = new GwRepository(Database::getConnection());
    $committee = new CommitteeService(new CommitteeRepository(Database::getConnection()));

    $year = input('year');
    if ($year === null || $year === '') {
        $year = date('Y', strtotime('-1 month')); // same default as yearly_evaluation.php
    }

    // GW are scored per month, not per year: default to the last completed month
    // (same reference point as $year's default above, so the pair stays coherent).
    $gwMonth = input('month');
    if ($gwMonth === null || $gwMonth === '') {
        $gwMonth = date('m', strtotime('-1 month'));
    }
    $gwMonth = str_pad((string)(int)$gwMonth, 2, '0', STR_PAD_LEFT);

    $service = new HierarchyService($repo, $evalRepo, $gwRepo, $year, $gwMonth);

    $staff = $repo->findStaff($person, $compId);
    if (!$staff) {
        respond(['success' => false, 'message' => 'Staff not found or inactive'], 404);
    }
    // The raw path is an internal detail; the app only ever sees a usable URL.
    $staff['photo_url'] = ProfilePhoto::url($staff['profile_pic']);
    unset($staff['profile_pic']);

    $superiorChain = $service->buildSuperiorChain($staff['person'], $staff['comp_id']);
    $result        = $service->buildSubordinateTree($staff['person'], $staff['comp_id']);
    // [] when this staff sits on none, and until committee.sql has been run.
    // The app draws the section only when this comes back non-empty.
    $committees    = $committee->committeesFor($staff['person'], $staff['comp_id']);

    respond([
        'success'      => true,
        'staff'        => $staff,
        'superiors'    => $superiorChain['chain'],
        'summary'      => [
            'total_subordinates'  => $result['total'],
            'direct_subordinates' => $result['direct'],
            'total_superiors'     => count($superiorChain['chain']),
            'direct_superiors'    => $superiorChain['direct'],
        ],
        'subordinates' => $result['tree'],
        'committees'   => $committees,
    ]);
} catch (Exception $e) {
    error_log('staffHierarchy error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'System error occurred'], 500);
}
