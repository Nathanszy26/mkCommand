<?php
session_start();

require_once '../includes/UnifiedAuth.php';

if (!UnifiedAuth::isLoggedIn()) {
    header('Location: /accounts/mkPortal/user_validation.php');
    exit;
}

$currentUser = UnifiedAuth::getLoggedInUser();

// Database Configuration
class DatabaseConfig
{
    const HOST = 'localhost';
    const PORT = 33060;
    const USERNAME = 'prog4';
    const PASSWORD = 'prog42023';
    const DATABASE = 'mkPortal';
}

class Database
{
    private static $connection = null;

    public static function getConnection()
    {
        if (self::$connection === null) {
            try {
                $dsn = "mysql:host=" . DatabaseConfig::HOST . ";port=" . DatabaseConfig::PORT . ";dbname=" . DatabaseConfig::DATABASE;
                self::$connection = new PDO($dsn, DatabaseConfig::USERNAME, DatabaseConfig::PASSWORD);
                self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (PDOException $e) {
                throw new Exception("Database connection failed: " . $e->getMessage());
            }
        }
        return self::$connection;
    }
}

class StaffPortalDatabase
{
    private static $connection = null;

    public static function getConnection()
    {
        if (self::$connection === null) {
            try {
                $dsn = "mysql:host=" . DatabaseConfig::HOST . ";port=" . DatabaseConfig::PORT . ";dbname=staff_portal2";
                self::$connection = new PDO($dsn, DatabaseConfig::USERNAME, DatabaseConfig::PASSWORD);
                self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (PDOException $e) {
                throw new Exception("Staff portal database connection failed: " . $e->getMessage());
            }
        }
        return self::$connection;
    }
}

/**
 * THE ACTUAL STAFF POOL, read through the API's own code.
 *
 * "How many staff does this company have" has two answers on this page, and
 * they are not the same question:
 *
 *   this report's roster   ManpowerRepository::activeUser() — who the CHART
 *                          walk is allowed to count: every live users row not
 *                          excluded from MK Command.
 *   the staff pool         staffPoolCore.php — who the company HAS. This is
 *                          what staffPoolAPI.php publishes and what every
 *                          other application reads:
 *                            /accounts/mkPortal/hr/staffPoolAPI.php?comp_id=1
 *
 * The roster is the wider of the two, and every bit of the difference is data
 * hygiene rather than people. Measured today:
 *
 *   Globinaco    352 roster vs 328 pool — 21 Trillium people, whom the pool
 *                reports as company 16 so Globinaco is not credited with them
 *                twice, and 3 test accounts.
 *   Arus Sawit    91 roster vs  73 pool — 18 GW LOGIN accounts, whose holders
 *                the pool already counts on its GW side, and 2 test accounts.
 *   TP Group      68 roster vs  67 pool — 1 test account.
 *   Everyone else the two agree exactly.
 *
 * So the pool is the honest "total staff", and this class is how the report
 * gets it.
 *
 * IT IS THE SAME CODE, NOT THE SAME IDEA. staffPoolCore.php is included and
 * its StaffPoolRepository and publicSummary() do the counting, so this figure
 * and the endpoint's company_total_staff cannot drift apart — there is no
 * second implementation here to keep in step with that one.
 * staffHeadcountReport.php counts its tiles off that same file.
 *
 * FAILS SOFT, ALWAYS. The pool is two extra tiles on a reconciliation strip,
 * not the report. If staffPoolCore.php has not been uploaded yet, or a grant
 * is missing, available() goes false, those tiles are simply not printed, and
 * every other figure on this page is exactly what it was.
 */
class StaffPoolView
{
    const CORE_FILE = 'staffPoolCore.php';

    private $loaded = null;        // null = not tried yet
    private $pdo    = null;
    private $repo   = null;
    private $cache  = [];          // comp_id => the answer below
    private $error  = '';

    /** Query: can the pool be read at all? Tried once per request. */
    public function available()
    {
        return $this->load();
    }

    /** Query: why not. Kept beside available() because a caller that wants to
     *  say more than "no figure" needs it; the log gets it either way. */
    public function error()
    {
        return $this->error;
    }

    /**
     * Query: the company Trillium's people are STORED at.
     *
     * Trillium Group has no users rows of its own — its people sit under
     * Globinaco carrying users.is_trillium = 1, and the pool lifts them out
     * and reports them as company 16 so neither company is credited twice.
     * Both ids come from staffPoolCore.php rather than being written again
     * here, so there is one definition of that arrangement.
     *
     * null when the pool could not be read.
     */
    public function trilliumHomeCompId()
    {
        return $this->load() ? (int)StaffPoolRepository::TRILLIUM_HOME_COMP_ID : null;
    }

    /**
     * Query: Trillium's own pool, but ONLY while looking at the company its
     * people are stored at.
     *
     * Anywhere else it is not a figure that belongs on the page: Trillium is
     * reported as its own company, and a Trillium tile on Arus Sawit's report
     * would be a number about somebody else's staff.
     *
     * The shape is forCompany()'s, so a caller treats it exactly like the
     * company's own pool.
     */
    public function trilliumFor($compId)
    {
        if (!$this->load() || (int)$compId !== (int)StaffPoolRepository::TRILLIUM_HOME_COMP_ID) {
            return self::emptySummary();
        }
        return $this->forCompany(StaffPoolRepository::TRILLIUM_COMP_ID);
    }

    /** The shape forCompany() always returns, read or not. */
    public static function emptySummary()
    {
        return ['available' => false, 'total' => 0, 'staff' => 0, 'gw' => 0,
                'excluded' => 0, 'rows' => []];
    }

    /**
     * Its own connection, deliberately.
     *
     * staffPoolCore.php's Db::get() is not used — it dials the default port
     * while this page is on DatabaseConfig::PORT — and the report's own handle
     * is not borrowed either: that file expects PDO::FETCH_ASSOC as the
     * default, and switching that on a shared connection to suit one strip is
     * how a page breaks somewhere else entirely. So host, port and credentials
     * come from this file, and the attributes and charset from that one.
     *
     * Read-only: every query behind it is a SELECT.
     */
    private function load()
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }
        $this->loaded = false;

        $file = __DIR__ . '/' . self::CORE_FILE;
        if (!file_exists($file)) {
            $this->error = self::CORE_FILE . ' is not on this server yet.';
            return false;
        }

        try {
            require_once $file;
            if (!class_exists('StaffPoolRepository') || !function_exists('publicSummary')) {
                $this->error = self::CORE_FILE . ' loaded but is not the expected version.';
                return false;
            }

            $dsn = 'mysql:host=' . DatabaseConfig::HOST
                 . ';port=' . DatabaseConfig::PORT . ';charset=utf8mb4';
            $this->pdo = new PDO($dsn, DatabaseConfig::USERNAME, DatabaseConfig::PASSWORD,
                                 [PDO::ATTR_TIMEOUT => 5]);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            $this->repo   = new StaffPoolRepository($this->pdo);
            $this->loaded = true;
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            error_log('Manpower summary: staff pool unavailable: ' . $e->getMessage());
        }

        return $this->loaded;
    }

    /**
     * Query: one company's pool — the figures the endpoint publishes, and the
     * people behind them.
     *
     * `rows` are the ones `total` counted. publicSummary() counts only people
     * IN the pool, so anybody an override has taken out is left out of both,
     * and a tile can never disagree with the panel it opens. `excluded` says
     * how many that was.
     *
     * withGwEmails() is deliberately skipped: it matches GW mobiles against
     * myMK to find an address, this report shows no contacts, and skipping it
     * drops a dependency on a schema this page otherwise never touches. It
     * does not touch a single count.
     *
     * One read of users and one of monthly_assign_gw per company, memoised for
     * the request — the drill-down panels ask again and must not pay twice.
     */
    public function forCompany($compId)
    {
        $compId = (int)$compId;
        if (isset($this->cache[$compId])) {
            return $this->cache[$compId];
        }
        if (!$this->load()) {
            return self::emptySummary();
        }

        $out = self::emptySummary();
        try {
            $rows      = array_merge($this->repo->staff($compId), $this->repo->gw($compId));
            $overrides = $this->repo->overrides($compId);
            $summary   = publicSummary($rows, $overrides);

            $inPool = [];
            foreach ($rows as $row) {
                $public = publicRow($row, $overrides);
                if ($public['total_staff'] === 1) {
                    $inPool[] = $public;
                }
            }

            $out = [
                'available' => true,
                'total'     => (int)$summary['company_total_staff'],
                'staff'     => (int)$summary['company_total_by_type']['staff'],
                'gw'        => (int)$summary['company_total_by_type']['gw'],
                'excluded'  => (int)$summary['company_excluded_staff'],
                'rows'      => $inPool,
            ];
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            error_log('Manpower summary: staff pool failed for company ' . $compId
                    . ': ' . $e->getMessage());
        }

        $this->cache[$compId] = $out;
        return $out;
    }
}

/**
 * Who may see which companies. Single place to change the admin source table.
 */
class AccessPolicy
{
    const SUPER_ADMIN_TABLE = 'site_check_in_admins';

    private $isSuperAdmin = false;
    private $allCompanies = false;
    private $companyIds   = [];
    private $currentUser;

    public function __construct(array $currentUser)
    {
        $this->currentUser = $currentUser;

        try {
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM " . self::SUPER_ADMIN_TABLE . "
                                   WHERE person = ? AND comp_id = ? AND deleted = 0");
            $stmt->execute([$currentUser['person'], $currentUser['comp_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->isSuperAdmin = $row && $row['c'] > 0;

            if (!$this->isSuperAdmin) {
                $stmt = $pdo->prepare("SELECT all_companies, assigned_companies FROM face_checkIn_admins
                                       WHERE person = ? AND comp_id = ? AND status = '1' LIMIT 1");
                $stmt->execute([$currentUser['person'], $currentUser['comp_id']]);
                $face = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($face) {
                    $this->allCompanies = ($face['all_companies'] == '1');
                    if (!$this->allCompanies) {
                        $this->companyIds = array_values(array_filter(array_map(
                            'intval',
                            explode(',', isset($face['assigned_companies']) ? $face['assigned_companies'] : '')
                        )));
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Manpower access check failed: ' . $e->getMessage());
        }
    }

    public function canSeeAllCompanies()
    {
        return $this->isSuperAdmin || $this->allCompanies;
    }

    public function isRestrictedAdmin()
    {
        return !$this->canSeeAllCompanies() && !empty($this->companyIds);
    }

    public function allowedCompanyIds()
    {
        return $this->companyIds;
    }

    public function isCompanyPermitted($compId)
    {
        $compId = (int)$compId;

        if ($this->canSeeAllCompanies()) return true;
        if ($compId === (int)$this->currentUser['comp_id']) return true;
        return in_array($compId, $this->companyIds, true);
    }

    /** Resolves the company actually reported on, never trusting raw input. */
    public function resolveCompanyId($requested)
    {
        $requested = (int)$requested;

        if ($this->canSeeAllCompanies()) {
            return $requested > 0 ? $requested : (int)$this->currentUser['comp_id'];
        }
        if ($this->isRestrictedAdmin()) {
            return in_array($requested, $this->companyIds, true) ? $requested : $this->companyIds[0];
        }
        return (int)$this->currentUser['comp_id'];
    }

    /** Writing to the chart is an admin action; a plain viewer stays read-only. */
    public function canEdit()
    {
        return $this->canSeeAllCompanies() || $this->isRestrictedAdmin();
    }

    public function roleLabel()
    {
        if ($this->isSuperAdmin) return 'Super admin';
        if ($this->allCompanies) return 'All-company admin';
        if ($this->isRestrictedAdmin()) return 'Company admin';
        return '';
    }
}

/**
 * Every read against staff_portal2. Knows nothing about HTTP or presentation.
 */
class ManpowerRepository
{
    /** Single point of change if the org chart table is renamed again. */
    const ORG_CHART_TABLE = 'organization_chart_glob';

    /** Assignment is live today. Empty / NULL bounds mean "unbounded". */
    const ACTIVE_CLAUSE = "
        (oc.monthly_assign_from IS NULL OR oc.monthly_assign_from = ''
            OR STR_TO_DATE(REPLACE(oc.monthly_assign_from, '-', '/'), '%Y/%m/%d') <= CURDATE())
        AND (oc.monthly_assign_to IS NULL OR oc.monthly_assign_to = ''
            OR STR_TO_DATE(REPLACE(oc.monthly_assign_to, '-', '/'), '%Y/%m/%d') >= CURDATE())";

    const USER_FIELDS = "u.id, u.person, u.comp_id, u.fullname, u.department, u.district, u.position";

    /**
     * Who counts as a person in this report, for ANY alias. Written once because
     * the roster, the walk, the pickers and the writer must all agree: exclude
     * somebody from the roster but still walk them and `headcount` outgrows
     * `active_staff`, which silently clamps `unplaced` to zero and hides the very
     * gaps this report exists to show.
     *
     * mk_command_excluded = '1' is a hard exclusion, the staff mirror of
     * GW_ACTIVE_CLAUSE's kpi_scorecard_exclude. NULL means never set, which is
     * not excluded. Drop that line here and the exclusion is gone everywhere.
     */
    private static function activeUser($alias)
    {
        return "{$alias}.status = 1 AND {$alias}.deleted = 0
                AND ({$alias}.mk_command_excluded IS NULL OR {$alias}.mk_command_excluded <> '1')";
    }

    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getCompanies(array $limitToIds = [])
    {
        if (empty($limitToIds)) {
            $stmt = $this->db->prepare("SELECT id, name FROM subsidiaries WHERE status = 1 ORDER BY name");
            $stmt->execute();
        } else {
            $placeholders = implode(',', array_fill(0, count($limitToIds), '?'));
            $stmt = $this->db->prepare("SELECT id, name FROM subsidiaries WHERE status = 1 AND id IN ($placeholders) ORDER BY name");
            $stmt->execute($limitToIds);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCompanyName($compId)
    {
        $stmt = $this->db->prepare("SELECT name FROM subsidiaries WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['name'] : '';
    }

    /**
     * Every active department_head row for a company — departments (parent_id IS NULL)
     * and sub dept heads (parent_id = the department's id) in one round-trip.
     * Ordered so parents always arrive before their children.
     */
    public function getDepartmentRecords($compId)
    {
        $sql = "SELECT dh.id, dh.parent_id, dh.staff_id, dh.comp_id, dh.dept_name,
                       dh.`order`, dh.is_trillium,
                       u.fullname AS head_fullname, u.position AS head_position
                FROM department_head dh
                LEFT JOIN users u
                    ON u.person = dh.staff_id AND u.comp_id = dh.comp_id
                   AND " . self::activeUser('u') . "
                WHERE dh.comp_id = ? AND dh.status = '1'
                ORDER BY (dh.parent_id IS NOT NULL) ASC,
                         dh.is_trillium ASC,
                         CAST(dh.`order` AS UNSIGNED) ASC,
                         dh.dept_name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([(int)$compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findDepartment($deptId)
    {
        $stmt = $this->db->prepare("SELECT id, parent_id, staff_id, comp_id, dept_name
                                    FROM department_head
                                    WHERE id = ? AND status = '1' LIMIT 1");
        $stmt->execute([(int)$deptId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Direct subordinates of MANY bosses in one round-trip (one query per tree level).
     * $bosses: [['person' => ..., 'comp_id' => ...], ...]
     * department_head_id is only meaningful on level-1 edges; deeper levels inherit.
     */
    public function findSubordinatesOf(array $bosses)
    {
        if (empty($bosses)) {
            return [];
        }

        $tuples = implode(',', array_fill(0, count($bosses), '(?, ?)'));

        $sql = "SELECT " . self::USER_FIELDS . ",
                       oc.boss_id, oc.boss_comp_id, oc.department_head_id
                FROM " . self::ORG_CHART_TABLE . " oc
                INNER JOIN users u
                    ON u.person = oc.staff_id AND u.comp_id = oc.comp_id
                   AND " . self::activeUser('u') . "
                WHERE (oc.boss_id, oc.boss_comp_id) IN ({$tuples})
                  AND " . self::ACTIVE_CLAUSE . "
                ORDER BY u.fullname ASC";

        $stmt = $this->db->prepare($sql);

        $i = 1;
        foreach ($bosses as $boss) {
            $stmt->bindValue($i++, $boss['person'], PDO::PARAM_STR);
            $stmt->bindValue($i++, (int)$boss['comp_id'], PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * One active user, or null. Both pickers and every write validate through
     * this, so "who is a real person here" is decided in exactly one place.
     */
    public function findUser($person, $compId)
    {
        $stmt = $this->db->prepare("SELECT " . self::USER_FIELDS . "
                                    FROM users u
                                    WHERE u.person = ? AND u.comp_id = ?
                                      AND " . self::activeUser('u') . "
                                    LIMIT 1");
        $stmt->execute([(string)$person, (int)$compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** The company + term filter the staff page and its count both run on. */
    private static function staffFilter($compId, $term)
    {
        $sql  = " FROM users u
                  WHERE u.comp_id = ? AND " . self::activeUser('u');
        $args = [(int)$compId];

        if ($term !== '') {
            $sql   .= " AND (u.fullname LIKE ? OR u.person LIKE ?)";
            $args[] = '%' . $term . '%';
            $args[] = $term . '%';
        }

        return ['sql' => $sql, 'args' => $args];
    }

    /**
     * One page of active users for the pickers, plus how many actually match, so
     * the panel can say it is showing a slice instead of silently truncating.
     * The count query only runs when the page came back full — a narrowed search
     * costs one round-trip.
     */
    public function searchStaff($compId, $term, $limit = 60)
    {
        $limit  = (int)$limit;
        $filter = self::staffFilter($compId, $term);

        $stmt = $this->db->prepare("SELECT " . self::USER_FIELDS . $filter['sql']
                                 . " ORDER BY u.fullname ASC LIMIT " . $limit);
        $stmt->execute($filter['args']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) < $limit) {
            return ['rows' => $rows, 'total' => count($rows)];
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) AS c" . $filter['sql']);
        $stmt->execute($filter['args']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return ['rows' => $rows, 'total' => $row ? (int)$row['c'] : count($rows)];
    }

    /**
     * Direct bosses of MANY staff in one round-trip — the mirror of
     * findSubordinatesOf, walked upwards so a new assignment can be checked for
     * a loop before it is written.
     */
    public function findBossesOf(array $staff)
    {
        if (empty($staff)) {
            return [];
        }

        $tuples = implode(',', array_fill(0, count($staff), '(?, ?)'));

        $sql = "SELECT oc.staff_id, oc.comp_id, oc.boss_id, oc.boss_comp_id
                FROM " . self::ORG_CHART_TABLE . " oc
                WHERE (oc.staff_id, oc.comp_id) IN ({$tuples})
                  AND " . self::ACTIVE_CLAUSE;

        $stmt = $this->db->prepare($sql);

        $i = 1;
        foreach ($staff as $person) {
            $stmt->bindValue($i++, (string)$person['person'], PDO::PARAM_STR);
            $stmt->bindValue($i++, (int)$person['comp_id'], PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Which of these staff already hold a live row under this boss. Keyed by staff code. */
    public function existingAssignees(array $persons, $compId, $bossPerson, $bossCompId)
    {
        if (empty($persons)) {
            return [];
        }

        $in  = implode(',', array_fill(0, count($persons), '?'));
        $sql = "SELECT oc.staff_id
                FROM " . self::ORG_CHART_TABLE . " oc
                WHERE oc.comp_id = ? AND oc.boss_id = ? AND oc.boss_comp_id = ?
                  AND oc.staff_id IN ({$in})
                  AND " . self::ACTIVE_CLAUSE;

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge(
            [(int)$compId, (string)$bossPerson, (int)$bossCompId],
            array_values($persons)
        ));

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['staff_id']] = true;
        }
        return $out;
    }

    /**
     * Departments — never sub departments — this person heads. Drives the
     * department_head_id tag a new assignment carries, so the summary places the
     * row without guessing.
     */
    public function departmentsHeadedBy($person, $compId)
    {
        $stmt = $this->db->prepare("SELECT id, dept_name
                                    FROM department_head
                                    WHERE staff_id = ? AND comp_id = ? AND status = '1'
                                      AND (parent_id IS NULL OR parent_id = '')
                                    ORDER BY CAST(`order` AS UNSIGNED) ASC, dept_name ASC");
        $stmt->execute([(string)$person, (int)$compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countActiveStaff($compId)
    {
        $filter = self::staffFilter($compId, '');
        $stmt   = $this->db->prepare("SELECT COUNT(*) AS c" . $filter['sql']);
        $stmt->execute($filter['args']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['c'] : 0;
    }

    /**
     * The same population as a list. `unplaced` is measured against this figure,
     * so the number and the names come from one WHERE clause and can never
     * disagree.
     */
    public function listActiveStaff($compId)
    {
        $filter = self::staffFilter($compId, '');
        $stmt   = $this->db->prepare("SELECT " . self::USER_FIELDS . $filter['sql']
                                   . " ORDER BY u.fullname ASC");
        $stmt->execute($filter['args']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Staff holding more than one live row in the chart today. Two bosses and
     * the same boss twice are both flagged — both inflate the numbers above, and
     * both are data to fix.
     */
    public function duplicateAssignments($compId)
    {
        $sql = "SELECT oc.staff_id AS person, oc.comp_id,
                       u.fullname, u.position, u.department, u.district,
                       COUNT(*) AS rows_live,
                       COUNT(DISTINCT CONCAT(oc.boss_id, '|', oc.boss_comp_id)) AS boss_count,
                       GROUP_CONCAT(DISTINCT COALESCE(b.fullname, CONCAT('Unknown (', oc.boss_id, ')'))
                                    SEPARATOR ', ') AS bosses
                FROM " . self::ORG_CHART_TABLE . " oc
                INNER JOIN users u
                    ON u.person = oc.staff_id AND u.comp_id = oc.comp_id
                   AND " . self::activeUser('u') . "
                -- A label join, so it stays permissive: activeUser() would blank
                -- out an excluded boss's name, and knowing WHO the duplicate rows
                -- point at is the whole value of this list.
                LEFT JOIN users b
                    ON b.person = oc.boss_id AND b.comp_id = oc.boss_comp_id
                   AND b.status = 1 AND b.deleted = 0
                WHERE oc.comp_id = ? AND " . self::ACTIVE_CLAUSE . "
                GROUP BY oc.staff_id, oc.comp_id, u.fullname, u.position, u.department, u.district
                HAVING COUNT(*) > 1
                ORDER BY COUNT(*) DESC, u.fullname ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([(int)$compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The live GW population. kpi_scorecard_exclude = '1' is a hard exclusion:
     * those GW are counted nowhere in this report, exactly as staffHierarchy.php
     * and jobSpecApprovalReport.php exclude them. NULL means never set, which is
     * not excluded.
     *
     * Deliberately NOT pinned to comp 1: the boss tuple already scopes the read,
     * so this keeps working the day GW appear in another subsidiary.
     */
    const GW_ACTIVE_CLAUSE = "
        mag.monthly_assign_gw_status = '1'
        AND (mag.kpi_scorecard_exclude IS NULL OR mag.kpi_scorecard_exclude <> '1')";

    /**
     * Direct GW of MANY bosses in one round-trip (mirror of findSubordinatesOf).
     * GW never supervise anyone, so ONE query covers a whole tree — no extra
     * BFS level.
     *
     * `evaluation` is on the same mysqld as staff_portal2, so a fully-qualified
     * name reaches it on this connection — the same approach staffHierarchy.php
     * uses. Requires SELECT on `evaluation`.
     *
     * A GW with two live assignment rows comes back under BOTH supervisors; the
     * caller de-duplicates, exactly as it does for shared staff.
     */
    public function findGwOf(array $bosses)
    {
        if (empty($bosses)) {
            return [];
        }

        $tuples = implode(',', array_fill(0, count($bosses), '(?, ?)'));

        $sql = "SELECT mag.monthly_assign_gw_code     AS person,
                       mag.comp_id                    AS comp_id,
                       mag.monthly_assign_gw_fullname AS fullname,
                       mag.monthly_assign_gw_district AS district,
                       mag.boss_id, mag.boss_comp_id
                FROM evaluation.monthly_assign_gw mag
                WHERE (mag.boss_id, mag.boss_comp_id) IN ({$tuples})
                  AND " . self::GW_ACTIVE_CLAUSE . "
                ORDER BY mag.monthly_assign_gw_fullname ASC";

        $stmt = $this->db->prepare($sql);

        $i = 1;
        foreach ($bosses as $boss) {
            // boss_id / boss_comp_id are VARCHAR in this table — bind as strings
            // so the index stays usable.
            $stmt->bindValue($i++, (string)$boss['person'], PDO::PARAM_STR);
            $stmt->bindValue($i++, (string)$boss['comp_id'], PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The live GW of ONE company — the population `gw_unplaced` is measured
     * against. comp_id binds as a string for the same reason boss_comp_id does.
     */
    private static function gwFilter($compId)
    {
        return [
            'sql'  => " FROM evaluation.monthly_assign_gw mag
                        WHERE mag.comp_id = ? AND " . self::GW_ACTIVE_CLAUSE,
            'args' => [(string)$compId],
        ];
    }

    /** Distinct live GW: one with two assignment rows is still one worker. */
    public function countActiveGw($compId)
    {
        $filter = self::gwFilter($compId);
        $stmt   = $this->db->prepare("SELECT COUNT(DISTINCT mag.monthly_assign_gw_code) AS c" . $filter['sql']);
        $stmt->execute($filter['args']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['c'] : 0;
    }

    /** The same population as a list, collapsed to one row per GW code. */
    public function listActiveGw($compId)
    {
        $filter = self::gwFilter($compId);
        $stmt   = $this->db->prepare(
            "SELECT mag.monthly_assign_gw_code AS person,
                    mag.comp_id                AS comp_id,
                    MAX(mag.monthly_assign_gw_fullname) AS fullname,
                    MAX(mag.monthly_assign_gw_district) AS district"
            . $filter['sql'] .
            " GROUP BY mag.monthly_assign_gw_code, mag.comp_id
              ORDER BY fullname ASC");
        $stmt->execute($filter['args']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The GW mirror of duplicateAssignments(). */
    public function duplicateGwAssignments($compId)
    {
        $filter = self::gwFilter($compId);
        $stmt   = $this->db->prepare(
            "SELECT mag.monthly_assign_gw_code AS person,
                    mag.comp_id                AS comp_id,
                    MAX(mag.monthly_assign_gw_fullname) AS fullname,
                    MAX(mag.monthly_assign_gw_district) AS district,
                    COUNT(*) AS rows_live,
                    COUNT(DISTINCT CONCAT(mag.boss_id, '|', mag.boss_comp_id)) AS boss_count,
                    GROUP_CONCAT(DISTINCT COALESCE(b.fullname, CONCAT('Unknown (', mag.boss_id, ')'))
                                 SEPARATOR ', ') AS bosses
             FROM evaluation.monthly_assign_gw mag
             -- Label join, permissive for the same reason as above.
             LEFT JOIN users b
                 ON b.person = mag.boss_id AND b.comp_id = mag.boss_comp_id
                AND b.status = 1 AND b.deleted = 0
             WHERE mag.comp_id = ? AND " . self::GW_ACTIVE_CLAUSE . "
             GROUP BY mag.monthly_assign_gw_code, mag.comp_id
             HAVING COUNT(*) > 1
             ORDER BY COUNT(*) DESC, fullname ASC");
        $stmt->execute($filter['args']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * Builds the summary table and the drill-down forests.
 *
 * The table mirrors the manpower workbook. A department prints one row per
 * reporting line, and the columns are mutually exclusive:
 *   Count (dept head)   = the head + every direct report who is NOT a sub dept head
 *   Sub Dept Head       = one line per direct report registered in department_head
 *                         with parent_id = this department; each counts 1
 *   Subordinates        = everyone below a line's leader, at any depth, labelled
 *                         with that line's unit (the sub department's name, or the
 *                         leader's own unit for a plain direct report)
 *   Grand Total         = Count + Sub Dept Head + Subordinates, printed once per block
 *
 * Department heads are pre-visited, so a head never lands inside another head's
 * subtree. De-duplication is per department, not global: a person reporting to
 * two bosses is counted once in each of their departments, exactly as the
 * drill-down shows them. `headcount` on the report stays a distinct company
 * count, so `unplaced` is unaffected.
 */
class ManpowerSummaryService
{
    const MAX_DEPTH = 10;

    /**
     * The reconciliation lists, in the order the strip prints them. Adding a key
     * here adds a tile, a drill-down and a CSV section — nothing else to touch.
     */
    const AUDIT_LABELS = [
        'pool_staff'      => 'Total staff in the pool',
        'pool_gw'         => 'Total general workers in the pool',
        'trillium_staff'  => 'Trillium staff in the pool',
        'trillium_placed' => 'Trillium staff assigned in the chart',
        'placed_staff'   => 'Total unique staff assigned',
        'placed_gw'      => 'Total unique general workers assigned',
        'unplaced_staff' => 'Staff not on any chart',
        'unplaced_gw'    => 'General workers not on any chart',
        'dup_staff'      => 'Staff assigned more than once',
        'dup_gw'         => 'General workers assigned more than once',
    ];

    const AUDIT_HINTS = [
        'pool_staff'      => 'Every staff member staffPoolAPI.php reports for this company, each counted once',
        'pool_gw'         => 'Every general worker staffPoolAPI.php reports for this company, each counted once',
        'trillium_staff'  => 'Reported as its own company, so they are not in the figures above',
        'trillium_placed' => 'Trillium people counted on a department tree today',
        'placed_staff'   => 'Distinct people counted on a department tree today',
        'placed_gw'      => 'Distinct general workers counted on a department tree today',
        'unplaced_staff' => 'Active today, but on no reporting line below a department head',
        'unplaced_gw'    => 'Live today, but their supervisor is on no department tree',
        'dup_staff'      => 'More than one live row in the organisation chart',
        'dup_gw'         => 'More than one live monthly assignment row',
    ];

    /**
     * How the strip groups those lists into columns. One list prints as a plain
     * tile; several print as ONE tile with a breakdown line each, so related
     * figures share a column instead of competing for two.
     *
     * Grouping and short labels only — the lists themselves are unchanged, a
     * single-list tile still takes its heading from AUDIT_LABELS, and every
     * breakdown number still opens its own panel. Move a key between tiles to
     * relayout the strip; nothing else reads this.
     *
     * THE FIRST TWO TILES ARE THE PAIR TO READ TOGETHER: how many people the
     * company has, and how many of them the chart accounts for. Both count
     * each person exactly once — which is why they say so — and they are
     * deliberately side by side rather than one above the other, because the
     * question is always "assigned out of how many".
     *
     * AND THEY SUBTRACT. Every figure on this strip counts the same
     * population — see reconciliationLists() — so the first tile is exactly
     * the second plus the `unplaced` tiles that follow it, per type and in
     * total. Nothing on the strip explains itself in prose, so that has to
     * hold on its own: a reader WILL subtract these numbers, and the arithmetic
     * is what tells them they are reading one population rather than two.
     *
     * TRILLIUM IS ITS OWN PAIR, and sits outside the arithmetic above. Its
     * people are stored under Globinaco but the pool reports them as company
     * 16, so they are in none of the four figures before them — their total
     * and their assigned count are printed separately rather than folded in,
     * which is the same separation the table makes with its TRILLIUM block.
     * The two tiles appear only on the company Trillium's people are stored
     * at, and only while it has any.
     *
     * needs_pool / needs_trillium drop a tile when the figures behind it could
     * not be read, or do not apply to this company. A tile of zeros would read
     * as "this company has no staff", which is a worse answer than no tile —
     * and without the pool the strip falls back to the roster, where the same
     * three figures still reconcile among themselves.
     */
    const AUDIT_TILES = [
        ['title' => 'Total staff (unique)',
         'parts' => ['pool_staff' => 'Staff', 'pool_gw' => 'GW'],
         'needs_pool' => true],
        ['title' => 'Total assigned in the chart (unique)',
         'parts' => ['placed_staff' => 'Staff', 'placed_gw' => 'GW']],
        ['title' => 'Trillium total staff (unique)',
         'parts' => ['trillium_staff' => null],
         'needs_trillium' => true],
        ['title' => 'Trillium assigned in the chart (unique)',
         'parts' => ['trillium_placed' => null],
         'needs_trillium' => true],
        ['title' => null, 'parts' => ['unplaced_staff' => null]],
        ['title' => null, 'parts' => ['unplaced_gw'    => null]],
        ['title' => null, 'parts' => ['dup_staff'      => null]],
        ['title' => null, 'parts' => ['dup_gw'         => null]],
    ];

    private $repo;
    private $pool;

    public function __construct(ManpowerRepository $repo, StaffPoolView $pool = null)
    {
        $this->repo = $repo;
        $this->pool = $pool !== null ? $pool : new StaffPoolView();
    }

    private static function key($person, $compId)
    {
        return $person . '|' . (int)$compId;
    }

    private static function unitLabel(array $row)
    {
        if (!empty($row['department'])) return $row['department'];
        if (!empty($row['district']))   return $row['district'];
        return 'Unassigned unit';
    }

    /** GW live in their own key space: a GW code may collide with a users.person. */
    private static function gwKey($person, $compId)
    {
        return 'gw|' . $person . '|' . (int)$compId;
    }

    /** A GW has no users record: district stands in for the unit and the
     *  position is fixed. Same shape staffHierarchy.php gives them. */
    private static function gwNode(array $row)
    {
        return [
            'person'   => $row['person'],          // monthly_assign_gw_code
            'comp_id'  => (int)$row['comp_id'],
            'fullname' => $row['fullname'],
            'position' => 'General Worker',
            'unit'     => !empty($row['district']) ? $row['district'] : 'Unassigned unit',
            'is_gw'    => true,
        ];
    }

    /**
     * Splits department_head records into departments and their sub dept heads.
     * Shared by build() and the drill-downs so both agree on who sits where.
     */
    private function loadStructure($compId)
    {
        $departments = [];   // deptId => department
        $subHeads    = [];   // deptId => [nodeKey => sub dept head]
        $subHeadOf   = [];   // nodeKey => deptId
        $orphanSubs  = [];   // sub dept heads whose parent department is gone

        foreach ($this->repo->getDepartmentRecords($compId) as $rec) {
            $id      = (int)$rec['id'];
            $nodeKey = self::key($rec['staff_id'], $rec['comp_id']);

            if ($rec['parent_id'] === null || $rec['parent_id'] === '') {
                $departments[$id] = [
                    'id'            => $id,
                    'dept_name'     => $rec['dept_name'],
                    'is_trillium'   => ($rec['is_trillium'] === '1'),
                    'head_key'      => $nodeKey,
                    'head_person'   => $rec['staff_id'],
                    'head_comp_id'  => (int)$rec['comp_id'],
                    'head_fullname' => !empty($rec['head_fullname']) ? $rec['head_fullname'] : null,
                    'head_position' => $rec['head_position'],
                    'subhead_count' => 0,
                    'sub_count'     => 0,
                    'lead_count'    => !empty($rec['head_fullname']) ? 1 : 0,
                    'headcount'     => !empty($rec['head_fullname']) ? 1 : 0,
                    'untagged'      => 0,
                    'lines'         => [],
                ];
                continue;
            }

            $parentId = (int)$rec['parent_id'];
            if (!isset($departments[$parentId])) {
                $orphanSubs[] = $rec['dept_name'] . ' (' . $rec['staff_id'] . ')';
                continue;
            }

            $subHeads[$parentId][$nodeKey] = [
                'id'        => $id,
                'unit_name' => $rec['dept_name'],
                'person'    => $rec['staff_id'],
                'comp_id'   => (int)$rec['comp_id'],
                'fullname'  => !empty($rec['head_fullname']) ? $rec['head_fullname'] : null,
                'position'  => $rec['head_position'],
            ];
            $subHeadOf[$nodeKey] = $parentId;
        }

        return [
            'departments' => $departments,
            'sub_heads'   => $subHeads,
            'sub_head_of' => $subHeadOf,
            'orphan_subs' => $orphanSubs,
        ];
    }

    /**
     * Decides which department a level-1 edge belongs to.
     * Registration as a sub dept head wins over the organization_chart_glob tag,
     * because it is the more explicit statement. Returns null when ambiguous.
     */
    private static function resolveDepartmentId($nodeKey, $tagId, array $subHeadOf, array $candidateIds)
    {
        if (isset($subHeadOf[$nodeKey]) && in_array($subHeadOf[$nodeKey], $candidateIds, true)) {
            return $subHeadOf[$nodeKey];
        }
        if ($tagId !== null && $tagId !== '' && (int)$tagId > 0) {
            // A tag pointing outside this boss's departments is corrupt data — surface it.
            return in_array((int)$tagId, $candidateIds, true) ? (int)$tagId : null;
        }
        return count($candidateIds) === 1 ? $candidateIds[0] : null;
    }

    /** Table data for one company. */
    public function build($compId)
    {
        $compId    = (int)$compId;
        $structure = $this->loadStructure($compId);

        $report = array_merge(self::emptyReport(), [
            'departments'   => $structure['departments'],
            'orphan_subs'   => $structure['orphan_subs'],
            'active_staff'  => $this->repo->countActiveStaff($compId),
        ]);

        if (empty($report['departments'])) {
            // Nothing to walk, so nobody is placed — every active head still counts
            // as unplaced, which is exactly what the reconciliation should say.
            $this->reconcile($report, $compId, []);
            return $report;
        }

        $candidatesByHead = [];   // headKey => [deptId, ...]
        $headTuples       = [];   // headKey => boss tuple (also "is this person a department head?")
        $seen             = [];   // deptId  => [nodeKey => true]   per-department de-dup
        $rootOwner        = [];   // nodeKey => [rootKey => true]   level-1 lines this node hangs off
        $lines            = [];   // rootKey => reporting line
        $counted          = [];   // nodeKey => true                distinct company headcount
        $frontier         = [];   // nodeKey => boss tuple
        $nodeTuples       = [];   // nodeKey => tuple    every placed non-head node

        foreach ($report['departments'] as $deptId => $dept) {
            $candidatesByHead[$dept['head_key']][] = $deptId;
            $headTuples[$dept['head_key']] = ['person' => $dept['head_person'], 'comp_id' => $dept['head_comp_id']];

            if ($dept['head_fullname'] === null) {
                $report['missing_heads'][] = $dept['dept_name'] . ' (' . $dept['head_person'] . ')';
            } elseif ($dept['head_comp_id'] === $compId && !isset($counted[$dept['head_key']])) {
                $counted[$dept['head_key']] = true;
                $report['headcount']++;
            }
        }

        // ---- Level 1: split into the two count columns ----
        foreach ($this->repo->findSubordinatesOf(array_values($headTuples)) as $row) {
            $nodeKey = self::key($row['person'], $row['comp_id']);
            if (isset($headTuples[$nodeKey])) {
                continue;   // a department head never lands inside another head's tree
            }

            $headKey    = self::key($row['boss_id'], $row['boss_comp_id']);
            $candidates = isset($candidatesByHead[$headKey]) ? $candidatesByHead[$headKey] : [];
            if (empty($candidates)) {
                continue;
            }

            $deptId     = self::resolveDepartmentId($nodeKey, $row['department_head_id'], $structure['sub_head_of'], $candidates);
            $isUntagged = ($deptId === null);
            if ($isUntagged) {
                // Ambiguous: counted in the head's first department but flagged loudly.
                $deptId = $candidates[0];
            }

            if (isset($seen[$deptId][$nodeKey])) {
                continue;   // same person, same department, twice — count once
            }
            $seen[$deptId][$nodeKey] = true;

            if ($isUntagged) {
                $report['departments'][$deptId]['untagged']++;
                $report['untagged']++;
            }

            $isSubHead = isset($structure['sub_heads'][$deptId][$nodeKey]);
            $report['departments'][$deptId][$isSubHead ? 'subhead_count' : 'lead_count']++;
            $report['departments'][$deptId]['headcount']++;

            $rootKey = $deptId . '@' . $nodeKey;   // the same person may lead a line in two departments
            $lines[$rootKey] = [
                'dept_id'    => $deptId,
                'person'     => $row['person'],
                'comp_id'    => (int)$row['comp_id'],
                'fullname'   => $row['fullname'],
                'position'   => $row['position'],
                'unit'       => $isSubHead
                    ? $structure['sub_heads'][$deptId][$nodeKey]['unit_name']
                    : self::unitLabel($row),
                'is_subhead' => $isSubHead,
                'sub_count'  => 0,
            ];
            $rootOwner[$nodeKey][$rootKey] = true;

            if ((int)$row['comp_id'] === $compId && !isset($counted[$nodeKey])) {
                $counted[$nodeKey] = true;
                $report['headcount']++;
            }

            $frontier[$nodeKey] = $nodeTuples[$nodeKey] = ['person' => $row['person'], 'comp_id' => (int)$row['comp_id']];
        }

        // ---- Level 2+: headcount only; a node lands on every line its boss belongs to ----
        $depth = 1;
        while (!empty($frontier) && $depth < self::MAX_DEPTH) {
            $next = [];

            foreach ($this->repo->findSubordinatesOf(array_values($frontier)) as $row) {
                $nodeKey = self::key($row['person'], $row['comp_id']);
                if (isset($headTuples[$nodeKey])) {
                    continue;
                }

                $bossKey = self::key($row['boss_id'], $row['boss_comp_id']);
                if (!isset($rootOwner[$bossKey])) {
                    continue;
                }

                $placed = false;
                foreach (array_keys($rootOwner[$bossKey]) as $rootKey) {
                    $deptId = $lines[$rootKey]['dept_id'];
                    if (isset($seen[$deptId][$nodeKey])) {
                        continue;
                    }
                    $seen[$deptId][$nodeKey] = true;
                    $lines[$rootKey]['sub_count']++;
                    $report['departments'][$deptId]['sub_count']++;
                    $report['departments'][$deptId]['headcount']++;
                    $rootOwner[$nodeKey][$rootKey] = true;
                    $placed = true;
                }

                if (!$placed) {
                    continue;   // already accounted for in every department this branch reaches
                }

                if ((int)$row['comp_id'] === $compId && !isset($counted[$nodeKey])) {
                    $counted[$nodeKey] = true;
                    $report['headcount']++;
                }

                $next[$nodeKey] = $nodeTuples[$nodeKey] = ['person' => $row['person'], 'comp_id' => (int)$row['comp_id']];
            }

            $frontier = $next;
            $depth++;
        }

        // GW are leaves — one batched query for every boss in the company, after
        // the staff walk, so a line's Subordinates count is final before
        // attachLines() decides which lines print.
        $this->applyGw(
            array_merge(array_values($headTuples), array_values($nodeTuples)),
            $candidatesByHead,
            $report, $lines, $seen, $rootOwner
        );

        $this->attachLines($report['departments'], $lines);

        $this->reconcile($report, $compId, $counted);

        return $report;
    }

    /** The shape every consumer of a report can rely on, built or not. */
    public static function emptyReport()
    {
        return [
            'departments'   => [],
            'missing_heads' => [],
            'orphan_subs'   => [],
            'untagged'      => 0,
            'headcount'     => 0,
            'active_staff'  => 0,
            'unplaced'      => 0,
            'active_gw'     => 0,
            'gw_count'      => 0,
            'gw_placed'     => 0,
            'gw_unplaced'   => 0,
            'dup_staff'     => 0,
            'dup_gw'        => 0,
            'placed_staff'  => [],
            'placed_gw'     => [],
            // The company's actual head count, from staffPoolCore.php. See
            // StaffPoolView: 'available' false means it could not be read, and
            // the tile it feeds is then not printed at all.
            'pool'          => StaffPoolView::emptySummary(),
            // Trillium's own pool, filled only on the company its people are
            // stored at. See StaffPoolView::trilliumFor().
            'trillium'      => StaffPoolView::emptySummary(),
            // What the reconciliation strip prints. See stripFigures().
            'strip'         => self::emptyStrip(),
        ];
    }

    /**
     * Turns the sets build() placed into the figures the strip prints. Every
     * count here is the size of a set the audit lists re-derive from the same
     * two reads, so a tile and the panel it opens can never disagree.
     */
    private function reconcile(array &$report, $compId, array $countedStaff)
    {
        $report['placed_staff'] = $countedStaff;

        $placedGwHere = 0;
        foreach ($report['placed_gw'] as $gwCompId) {
            if ((int)$gwCompId === (int)$compId) {
                $placedGwHere++;
            }
        }

        $report['active_gw']   = $this->repo->countActiveGw($compId);
        $report['unplaced']    = max(0, $report['active_staff'] - count($countedStaff));
        // Kept, not just consumed: this is the figure the tile prints, and it is
        // the exact complement of `gw_unplaced` against ONE company's roster.
        // `gw_count` is the wider number — every GW the walk placed, including
        // any hanging off a boss in another subsidiary — so it does NOT
        // reconcile here and must never drive the tile.
        $report['gw_placed']   = $placedGwHere;
        $report['gw_unplaced'] = max(0, $report['active_gw'] - $placedGwHere);
        $report['dup_staff']   = count($this->repo->duplicateAssignments($compId));
        $report['dup_gw']      = count($this->repo->duplicateGwAssignments($compId));

        /* The company's actual head count, counted by the API's own code and
         * not by anything in this file. Trillium is read separately and only
         * where it belongs — see StaffPoolView::trilliumFor(). */
        $report['pool']     = $this->pool->forCompany($compId);
        $report['trillium'] = $this->pool->trilliumFor($compId);

        /* The strip's figures, on the pool when there is one. Everything above
         * is left exactly as it was: `headcount`, `active_staff`, `unplaced`
         * and the department cells are still the roster's own numbers, which
         * is what the table, the CSV body and the hidden figures strip read.
         * Only the reconciliation strip moves onto the pool. */
        $report['strip'] = $this->stripFigures($compId, $report);
    }

    /**
     * Folds GW into the counts the table prints. Placement mirrors the staff
     * rules exactly:
     *   boss is a department head -> the department Count cell, department
     *                                resolved by the same rule and flagged
     *                                untagged the same way when that head owns
     *                                more than one department (a GW row carries
     *                                no department_head_id to disambiguate)
     *   boss is anyone else       -> the Subordinates cell of every line that
     *                                boss belongs to, once per department
     *
     * GW keys are namespaced, so a GW code colliding with a users.person can
     * never displace that person. They are NOT added to $report['headcount']:
     * that figure reconciles against COUNT(*) FROM users, which GW are not in,
     * so `unplaced` stays a pure staff number. Their own total is `gw_count`.
     */
    private function applyGw(array $bosses, array $candidatesByHead,
                             array &$report, array &$lines, array &$seen, array &$rootOwner)
    {
        $countedGw = [];

        foreach ($this->repo->findGwOf($bosses) as $row) {
            $nodeKey = self::gwKey($row['person'], $row['comp_id']);
            $bossKey = self::key($row['boss_id'], $row['boss_comp_id']);
            $placed  = false;

            if (isset($candidatesByHead[$bossKey])) {
                $candidates = $candidatesByHead[$bossKey];
                $deptId     = self::resolveDepartmentId($nodeKey, null, [], $candidates);
                $isUntagged = ($deptId === null);
                if ($isUntagged) {
                    $deptId = $candidates[0];
                }
                if (isset($seen[$deptId][$nodeKey])) {
                    continue;
                }
                $seen[$deptId][$nodeKey] = true;

                if ($isUntagged) {
                    $report['departments'][$deptId]['untagged']++;
                    $report['untagged']++;
                }
                $report['departments'][$deptId]['lead_count']++;
                $report['departments'][$deptId]['headcount']++;
                $placed = true;

            } elseif (isset($rootOwner[$bossKey])) {
                foreach (array_keys($rootOwner[$bossKey]) as $rootKey) {
                    $deptId = $lines[$rootKey]['dept_id'];
                    if (isset($seen[$deptId][$nodeKey])) {
                        continue;
                    }
                    $seen[$deptId][$nodeKey] = true;
                    $lines[$rootKey]['sub_count']++;
                    $report['departments'][$deptId]['sub_count']++;
                    $report['departments'][$deptId]['headcount']++;
                    $placed = true;
                }
            }

            if ($placed) {
                // The company is kept, not just a flag: `gw_unplaced` reconciles
                // against ONE company's roster, and a GW may hang off a boss in
                // another subsidiary.
                $countedGw[$nodeKey] = (int)$row['comp_id'];
            }
        }

        $report['placed_gw'] = $countedGw;
        $report['gw_count']  = count($countedGw);
    }

    /**
     * Files each level-1 line under its department, plain direct reports first,
     * so the table reads the way the workbook does. A sub dept head always prints
     * a line, with their unit shown even when nobody sits below them. A plain
     * direct report with nobody below them is already inside the department Count
     * and prints no line at all.
     *
     * Plain lines sharing a unit label collapse into ONE row whose count is the
     * sum: two leaders of "O&E - HQ Technical Team" print once as 2. Summing is
     * safe because $seen is per department, so nobody is on two lines of the same
     * department. `members` carries the leaders behind the row, so the drill-down
     * opens exactly the lines that produced the number.
     */
    private function attachLines(array &$departments, array $lines)
    {
        $plain = [];   // deptId => unit => merged line
        $subs  = [];   // deptId => [line, ...]

        foreach ($lines as $line) {
            $deptId = $line['dept_id'];
            $member = ['person' => $line['person'], 'comp_id' => $line['comp_id']];

            if ($line['is_subhead']) {
                unset($line['dept_id']);
                $line['members'] = [$member];
                $subs[$deptId][] = $line;
                continue;
            }

            if ($line['sub_count'] < 1) {
                continue;   // already counted inside the department Count cell
            }

            $unit = $line['unit'];
            if (!isset($plain[$deptId][$unit])) {
                $plain[$deptId][$unit] = [
                    'unit'       => $unit,
                    'fullname'   => null,
                    'position'   => null,
                    'is_subhead' => false,
                    'sub_count'  => 0,
                    'members'    => [],
                ];
            }
            $plain[$deptId][$unit]['sub_count'] += $line['sub_count'];
            $plain[$deptId][$unit]['members'][]  = $member;
        }

        foreach ($departments as $deptId => $dept) {
            $departments[$deptId]['lines'] = array_merge(
                isset($plain[$deptId]) ? array_values($plain[$deptId]) : [],
                isset($subs[$deptId])  ? $subs[$deptId]  : []
            );
        }
    }

    /**
     * The level-1 people of one department, attributed by exactly the rules
     * build() uses, so a modal can never disagree with the number that opened it.
     * Returns the department, its level-1 roots keyed by node, and the visited
     * set (every department head) that seeds a forest walk.
     */
    private function levelOne(array $structure, $deptId)
    {
        if (!isset($structure['departments'][$deptId])) {
            throw new Exception('Department not found for this company');
        }

        $dept       = $structure['departments'][$deptId];
        $candidates = [];
        $visited    = [];

        foreach ($structure['departments'] as $id => $candidate) {
            if ($candidate['head_key'] === $dept['head_key']) {
                $candidates[] = $id;
            }
            $visited[$candidate['head_key']] = true;
        }

        $roots = [];
        $rows  = $this->repo->findSubordinatesOf([
            ['person' => $dept['head_person'], 'comp_id' => $dept['head_comp_id']],
        ]);

        foreach ($rows as $row) {
            $nodeKey = self::key($row['person'], $row['comp_id']);
            if (isset($visited[$nodeKey]) || isset($roots[$nodeKey])) {
                continue;
            }

            $ownerId = self::resolveDepartmentId($nodeKey, $row['department_head_id'], $structure['sub_head_of'], $candidates);
            if ($ownerId === null) {
                $ownerId = $candidates[0];
            }
            if ($ownerId !== $deptId) {
                continue;
            }

            $isSubHead = isset($structure['sub_heads'][$deptId][$nodeKey]);
            $roots[$nodeKey] = [
                'person'     => $row['person'],
                'comp_id'    => (int)$row['comp_id'],
                'fullname'   => $row['fullname'],
                'position'   => $row['position'],
                'unit'       => $isSubHead
                    ? $structure['sub_heads'][$deptId][$nodeKey]['unit_name']
                    : self::unitLabel($row),
                'is_subhead' => $isSubHead,
            ];
        }

        return ['dept' => $dept, 'roots' => $roots, 'visited' => $visited, 'candidates' => $candidates];
    }

    /** The people behind the department Count cell: the head, then their plain direct reports. */
    public function headBranch($compId, $deptId)
    {
        $level = $this->levelOne($this->loadStructure((int)$compId), (int)$deptId);
        $dept  = $level['dept'];

        $people = [];

        if ($dept['head_fullname'] !== null) {
            $people[] = self::flatNode([
                'fullname' => $dept['head_fullname'],
                'position' => $dept['head_position'],
                'unit'     => $dept['dept_name'],
            ], true);
        }

        foreach ($level['roots'] as $root) {
            if (!$root['is_subhead']) {
                $people[] = self::flatNode($root, false);
            }
        }

        foreach ($this->gwUnderHead($dept, $level['candidates'], (int)$deptId) as $gw) {
            $people[] = self::flatNode($gw, false);
        }

        return [
            'dept_name' => $dept['dept_name'],
            'line_name' => $dept['head_fullname'],
            'unit'      => $dept['dept_name'],
            'people'    => $people,
        ];
    }

    /**
     * Query: the GW hanging directly off one department head, narrowed to the
     * department applyGw() put them in — the same rule, so the list can never
     * disagree with the Count cell that opened it.
     */
    private function gwUnderHead(array $dept, array $candidates, $deptId)
    {
        $rows = $this->repo->findGwOf([
            ['person' => $dept['head_person'], 'comp_id' => $dept['head_comp_id']],
        ]);

        $out  = [];
        $seen = [];

        foreach ($rows as $row) {
            $nodeKey = self::gwKey($row['person'], $row['comp_id']);
            if (isset($seen[$nodeKey])) {
                continue;
            }
            $seen[$nodeKey] = true;

            $ownerId = self::resolveDepartmentId($nodeKey, null, [], $candidates);
            if ($ownerId === null) {
                $ownerId = $candidates[0];
            }
            if ($ownerId !== (int)$deptId) {
                continue;
            }
            $out[] = self::gwNode($row);
        }
        return $out;
    }

    /**
     * The trees under one printed line. A merged row hands back several leaders,
     * so the whole department forest is grown once and every requested root is
     * returned as its own group — the modal shows the same people the summed
     * count is made of.
     */
    public function unitBranch($compId, $deptId, array $members)
    {
        $level  = $this->levelOne($this->loadStructure((int)$compId), (int)$deptId);
        $wanted = [];

        foreach ($members as $member) {
            $key = self::key($member['person'], $member['comp_id']);
            if (!isset($level['roots'][$key])) {
                throw new Exception('That reporting line is not in this department');
            }
            $wanted[$key] = true;
        }

        $visited = $level['visited'];
        foreach (array_keys($level['roots']) as $rootKey) {
            $visited[$rootKey] = true;
        }

        $forest = $this->growForest($level['roots'], $visited);
        $people = [];
        $unit   = null;

        foreach (array_keys($wanted) as $key) {
            $node               = $forest[$key];
            $node['is_group']   = true;
            $node['is_subhead'] = $level['roots'][$key]['is_subhead'];
            $node['unit']       = $level['roots'][$key]['unit'];

            if ($unit === null) {
                $unit = $node['unit'];
            }
            $people[] = $node;
        }

        return [
            'dept_name' => $level['dept']['dept_name'],
            'line_name' => count($people) === 1 ? $people[0]['fullname'] : null,
            'unit'      => $unit,
            'people'    => $people,
        ];
    }

    // ---- Reconciliation: who the table does not account for ----

    /**
     * One normalised row for every audit list, so the CSV and the drill-down
     * render the same facts without either knowing where they came from.
     */
    private static function auditRow($person, $fullname, $position, $unit, $detail, $isGw)
    {
        return [
            'person'   => (string)$person,
            'fullname' => (string)$fullname,
            'position' => (string)$position,
            'unit'     => (string)$unit,
            'detail'   => (string)$detail,
            'is_gw'    => (bool)$isGw,
        ];
    }

    /**
     * Both rosters active today, each split by the report's OWN placed set.
     * Diffed against those sets, not against the org chart table: someone filed
     * under a boss who hangs off no department head is on the chart and still
     * missing from every count, which is precisely the case worth seeing.
     *
     * Each roster is read ONCE and split, so placed and unplaced can never drift
     * apart or double-count anybody. Both reads are scoped to this company, so
     * the GW halves sum to its roster even when a GW reports to a boss in
     * another subsidiary.
     */
    public function rosterLists($compId, array $report)
    {
        $compId      = (int)$compId;
        $placed      = [];
        $unplaced    = [];
        $placedGw    = [];
        $unplacedGw  = [];

        foreach ($this->repo->listActiveStaff($compId) as $row) {
            $entry = self::auditRow($row['person'], $row['fullname'], $row['position'],
                                    self::unitLabel($row), '', false);

            if (isset($report['placed_staff'][self::key($row['person'], $row['comp_id'])])) {
                $placed[] = $entry;
            } else {
                $unplaced[] = $entry;
            }
        }

        foreach ($this->repo->listActiveGw($compId) as $row) {
            $node  = self::gwNode($row);
            $entry = self::auditRow($node['person'], $node['fullname'], $node['position'],
                                    $node['unit'], '', true);

            if (isset($report['placed_gw'][self::gwKey($row['person'], $row['comp_id'])])) {
                $placedGw[] = $entry;
            } else {
                $unplacedGw[] = $entry;
            }
        }

        return [
            'placed_staff'   => $placed,
            'placed_gw'      => $placedGw,
            'unplaced_staff' => $unplaced,
            'unplaced_gw'    => $unplacedGw,
        ];
    }

    /**
     * THE STRIP'S POPULATION. One decision, made here.
     *
     * The staff pool when it can be read, this report's own roster when it
     * cannot. Every tile, every panel it opens and every CSV section comes
     * through this method, so the six figures can never end up on two
     * different bases — which is the one way a reconciliation strip becomes
     * worse than no strip at all.
     */
    public function reconciliationLists($compId, array $report)
    {
        return !empty($report['pool']['available'])
            ? $this->poolSplitLists($compId, $report)
            : $this->rosterLists($compId, $report);
    }

    /**
     * The staff pool, split by what the chart walk accounted for.
     *
     * This is the pair the strip leads with: how many people the company HAS,
     * and how many of them today's reporting line reaches. Split here and
     * nowhere else, so placed + unplaced == total by construction rather than
     * by two queries agreeing.
     *
     * TRILLIUM IS WHY THIS IS NOT rosterLists(). Trillium's 21 people are
     * stored under Globinaco, so the walk legitimately draws them on
     * Globinaco's chart — but the pool reports them as company 16, because
     * they are not Globinaco's head count. Splitting the ROSTER therefore
     * counted them as assigned Globinaco staff and made "assigned" come out
     * LARGER than "total staff" — 674 against 668, measured — which is not a
     * reconciliation anybody can read. Splitting the POOL cannot produce that:
     * both sides of the sum are drawn from one list.
     *
     * The walk itself is untouched by any of this. The table still draws every
     * person on the chart, Trillium included, exactly as it did.
     */
    private function poolSplitLists($compId, array $report)
    {
        $pool       = [];
        $poolGw     = [];
        $placed     = [];
        $unplaced   = [];
        $placedGw   = [];
        $unplacedGw = [];

        foreach ($report['pool']['rows'] as $row) {
            // A GW has no department, so their district is the unit — the same
            // choice unitLabel() makes for a staff row with no department.
            $unit = $row['department'] !== '' ? $row['department']
                  : ($row['district'] !== '' ? $row['district'] : 'Unassigned unit');

            $isGw  = ($row['staff_type'] === 'gw');
            $entry = self::auditRow($row['person'], $row['fullname'], $row['position'],
                                    $unit, '', $isGw);

            if ($isGw) {
                $poolGw[] = $entry;
                if (isset($report['placed_gw'][self::gwKey($row['person'], $row['comp_id'])])) {
                    $placedGw[] = $entry;
                } else {
                    $unplacedGw[] = $entry;
                }
                continue;
            }

            $pool[] = $entry;
            if (isset($report['placed_staff'][self::key($row['person'], $row['comp_id'])])) {
                $placed[] = $entry;
            } else {
                $unplaced[] = $entry;
            }
        }

        return [
            'pool_staff'     => $pool,
            'pool_gw'        => $poolGw,
            'placed_staff'   => $placed,
            'placed_gw'      => $placedGw,
            'unplaced_staff' => $unplaced,
            'unplaced_gw'    => $unplacedGw,
        ] + $this->trilliumLists($report);
    }

    /**
     * Trillium's own pair: how many people it has, and how many of them the
     * chart accounts for.
     *
     * Kept apart from the four figures above because the pool keeps them
     * apart — Trillium is reported as company 16, so none of its people are in
     * this company's total and adding them in would be the double-count the
     * pool exists to prevent. The table makes the same separation with its
     * TRILLIUM block.
     *
     * THE TWO SIDES ARE KEYED DIFFERENTLY and that is the whole subtlety here.
     * A Trillium person's pool row says company 16, but their users row — and
     * so every key the chart walk made — says Globinaco. So the pool row is
     * looked up under the HOME company, never under 16, or every one of them
     * would come back unassigned.
     */
    private function trilliumLists(array $report)
    {
        if (empty($report['trillium']['available'])) {
            return [];
        }
        $homeComp = $this->pool->trilliumHomeCompId();
        if ($homeComp === null) {
            return [];
        }

        $all    = [];
        $placed = [];

        foreach ($report['trillium']['rows'] as $row) {
            $unit = $row['department'] !== '' ? $row['department']
                  : ($row['district'] !== '' ? $row['district'] : 'Unassigned unit');

            $entry = self::auditRow($row['person'], $row['fullname'], $row['position'],
                                    $unit, '', false);
            $all[] = $entry;

            if (isset($report['placed_staff'][self::key($row['person'], $homeComp)])) {
                $placed[] = $entry;
            }
        }

        return ['trillium_staff' => $all, 'trillium_placed' => $placed];
    }

    /**
     * The six reconciliation figures, counted off the lists the panels print.
     *
     * Counted, not computed: the tile and the panel behind it are the same
     * pass over the same rows, so a figure can never be a number nobody can
     * open. pool_staff / pool_gw are absent on the roster basis, and their
     * tile is dropped rather than printed as zeros.
     */
    private function stripFigures($compId, array $report)
    {
        $lists = $this->reconciliationLists($compId, $report);

        $size = function ($key) use ($lists) {
            return isset($lists[$key]) ? count($lists[$key]) : 0;
        };

        return [
            'pool_staff'      => $size('pool_staff'),
            'pool_gw'         => $size('pool_gw'),
            'trillium_staff'  => $size('trillium_staff'),
            'trillium_placed' => $size('trillium_placed'),
            'placed_staff'    => $size('placed_staff'),
            'placed_gw'       => $size('placed_gw'),
            'unplaced_staff'  => $size('unplaced_staff'),
            'unplaced_gw'     => $size('unplaced_gw'),
            'dup_staff'       => (int)$report['dup_staff'],
            'dup_gw'          => (int)$report['dup_gw'],
        ];
    }

    /** The zeroed shape of stripFigures(), for a report that was never built. */
    public static function emptyStrip()
    {
        return ['pool_staff' => 0, 'pool_gw' => 0,
                'trillium_staff' => 0, 'trillium_placed' => 0,
                'placed_staff' => 0, 'placed_gw' => 0,
                'unplaced_staff' => 0, 'unplaced_gw' => 0,
                'dup_staff' => 0, 'dup_gw' => 0];
    }

    /** Everyone holding more than one live assignment row. Needs no report. */
    public function duplicateLists($compId)
    {
        $compId = (int)$compId;
        $staff  = [];
        $gw     = [];

        foreach ($this->repo->duplicateAssignments($compId) as $row) {
            $staff[] = self::auditRow($row['person'], $row['fullname'], $row['position'],
                                      self::unitLabel($row), self::duplicateDetail($row), false);
        }

        foreach ($this->repo->duplicateGwAssignments($compId) as $row) {
            $node = self::gwNode($row);
            $gw[] = self::auditRow($node['person'], $node['fullname'], $node['position'],
                                   $node['unit'], self::duplicateDetail($row), true);
        }

        return ['dup_staff' => $staff, 'dup_gw' => $gw];
    }

    /** Why a row is flagged, and who to talk to about it. */
    private static function duplicateDetail(array $row)
    {
        $rows   = (int)$row['rows_live'];
        $bosses = (int)$row['boss_count'];

        $reason = $bosses > 1
            ? $rows . ' live rows under ' . $bosses . ' bosses'
            : $rows . ' live rows under the same boss';

        return $reason . ': ' . titleCase($row['bosses']);
    }

    /** Every list at once, for the CSV. */
    public function auditLists($compId, array $report)
    {
        return array_merge(
            $this->reconciliationLists($compId, $report),
            $this->duplicateLists($compId)
        );
    }

    /** What an empty panel says. A roster total and an exception list that
     *  came back empty mean opposite things, so they do not share a sentence. */
    private function emptyAuditMessage($listName)
    {
        if (strpos($listName, 'pool_') === 0) {
            return 'Nobody is in the staff pool for this company.';
        }
        if ($listName === 'trillium_staff') {
            return 'Trillium has nobody on file.';
        }
        if (strpos($listName, 'placed_') === 0 || $listName === 'trillium_placed') {
            return 'Nobody is on a department tree yet.';
        }
        return 'Nothing to flag here.';
    }

    /**
     * One audit list in the shape the drill-down panel already renders. Only the
     * roster lists need a report, so a duplicates panel costs two queries
     * instead of a whole rebuild.
     */
    public function auditBranch($compId, $listName)
    {
        if (!isset(self::AUDIT_LABELS[$listName])) {
            throw new Exception('Unknown audit list');
        }

        $compId = (int)$compId;
        $lists  = strpos($listName, 'dup_') === 0
            ? $this->duplicateLists($compId)
            : $this->reconciliationLists($compId, $this->build($compId));

        if (!isset($lists[$listName])) {
            // Asked for the pool while the pool cannot be read. The tile is
            // not printed in that state, so this is only reachable by hand.
            throw new Exception('That list is not available for this company');
        }

        $people = [];
        foreach ($lists[$listName] as $row) {
            $people[] = self::flatNode([
                'fullname' => $row['fullname'],
                'position' => $row['position'],
                'unit'     => implode(' · ', array_filter([$row['person'], $row['unit'], $row['detail']], 'strlen')),
                'is_gw'    => $row['is_gw'],
            ], false);
        }

        return [
            'dept_name' => self::AUDIT_LABELS[$listName],
            'line_name' => null,
            'unit'      => self::AUDIT_HINTS[$listName],
            'people'    => $people,
            'empty'     => $this->emptyAuditMessage($listName),
        ];
    }

    /** A leaf-shaped node for the flat lists the Count cell shows. */
    private static function flatNode(array $row, $isHead)
    {
        return [
            'fullname' => $row['fullname'],
            'position' => $row['position'],
            'unit'     => $row['unit'],
            'is_head'  => $isHead,
            'is_gw'    => !empty($row['is_gw']),
            'children' => [],
            'direct'   => 0,
            'total'    => 0,
        ];
    }

    /**
     * Level-by-level expansion of many roots at once, then depth-first assembly.
     * $visited is shared across the whole forest so nobody is listed twice.
     */
    private function growForest(array $roots, array $visited)
    {
        $nodes     = $roots;
        $childKeys = [];
        $frontier  = [];

        foreach ($roots as $rootKey => $root) {
            $childKeys[$rootKey] = [];
            $frontier[] = ['person' => $root['person'], 'comp_id' => $root['comp_id']];
        }

        $depth = 0;
        while (!empty($frontier) && $depth < self::MAX_DEPTH) {
            $next = [];

            foreach ($this->repo->findSubordinatesOf($frontier) as $row) {
                $nodeKey = self::key($row['person'], $row['comp_id']);
                if (isset($visited[$nodeKey])) {
                    continue;
                }
                $visited[$nodeKey] = true;

                $bossKey = self::key($row['boss_id'], $row['boss_comp_id']);
                if (!isset($childKeys[$bossKey])) {
                    continue;
                }

                $nodes[$nodeKey] = [
                    'person'   => $row['person'],
                    'comp_id'  => (int)$row['comp_id'],
                    'fullname' => $row['fullname'],
                    'position' => $row['position'],
                    'unit'     => self::unitLabel($row),
                ];
                $childKeys[$nodeKey]   = [];
                $childKeys[$bossKey][] = $nodeKey;

                $next[] = ['person' => $row['person'], 'comp_id' => (int)$row['comp_id']];
            }

            $frontier = $next;
            $depth++;
        }

        // GW are leaves — attached after the BFS in one batched query, so
        // assemble() counts them in both `direct` and `total`. A GW is never put
        // on the frontier: a GW code can collide with a users.person, and walking
        // one as a boss would pull the wrong subtree.
        $gwBosses = [];
        foreach ($nodes as $node) {
            $gwBosses[] = ['person' => $node['person'], 'comp_id' => $node['comp_id']];
        }

        foreach ($this->repo->findGwOf($gwBosses) as $row) {
            $nodeKey = self::gwKey($row['person'], $row['comp_id']);
            if (isset($visited[$nodeKey])) {
                continue;
            }
            $bossKey = self::key($row['boss_id'], $row['boss_comp_id']);
            if (!isset($childKeys[$bossKey])) {
                continue;
            }
            $visited[$nodeKey] = true;

            $nodes[$nodeKey]       = self::gwNode($row);
            $childKeys[$nodeKey]   = [];
            $childKeys[$bossKey][] = $nodeKey;
        }

        $forest = [];
        foreach (array_keys($roots) as $rootKey) {
            $forest[$rootKey] = $this->assemble($rootKey, $nodes, $childKeys);
        }
        return $forest;
    }

    /** Depth-first assembly; `total` is every descendant, computed on the way back up. */
    private function assemble($nodeKey, array $nodes, array $childKeys)
    {
        $node     = $nodes[$nodeKey];
        $children = [];
        $total    = 0;

        foreach ($childKeys[$nodeKey] as $childKey) {
            $child      = $this->assemble($childKey, $nodes, $childKeys);
            $total     += 1 + $child['total'];
            $children[] = $child;
        }

        $node['children'] = $children;
        $node['direct']   = count($children);
        $node['total']    = $total;

        return $node;
    }
}

/**
 * Every write against organization_chart_glob. All validation lives here, so the
 * endpoint stays a thin translator and the rules can never drift from the report:
 * same table, same active-window semantics, same department tag the summary reads
 * back.
 *
 * Both sides of an assignment are scoped to the selected company — the pickers
 * only offer that company's users, and the writer refuses anything else.
 *
 * General workers are OUT OF SCOPE by construction, not by a guard: they live in
 * evaluation.monthly_assign_gw, which this file only ever reads, and they hold no
 * users row, so searchStaff() cannot surface one and findUser() rejects a GW code
 * outright. Nothing here writes to monthly_assign_gw. Moving GW assignments stays
 * the job of whatever owns that table — do not widen this class to cover them.
 */
class OrgChartWriter
{
    /** How far up the chart a loop check walks. Matches the report's own ceiling. */
    const MAX_DEPTH = ManpowerSummaryService::MAX_DEPTH;

    /**
     * Columns beyond the seven this file reads. organization_chart_glob is only
     * ever read here, so if your table carries further NOT NULL columns without a
     * default, name them once — ['created_by' => '1'] — and every INSERT picks
     * them up. Left empty, the INSERT names exactly the seven the report uses.
     */
    const EXTRA_COLUMNS = [];

    const BASE_COLUMNS = [
        'staff_id', 'comp_id', 'boss_id', 'boss_comp_id',
        'department_head_id', 'monthly_assign_from', 'monthly_assign_to',
    ];

    private $db;
    private $repo;

    public function __construct(PDO $db, ManpowerRepository $repo)
    {
        $this->db   = $db;
        $this->repo = $repo;
    }

    /**
     * Files staff under a boss. Returns what it did — how many rows landed and
     * who was skipped — because the caller has no other way to see inside a
     * transaction that has already closed.
     *
     * Anything invalid aborts the whole batch: a half-written reporting line is
     * worse than none.
     */
    public function assign($compId, $bossPerson, array $staffPersons, $deptHeadId, $from, $to)
    {
        $compId = (int)$compId;
        $boss   = $this->repo->findUser($bossPerson, $compId);

        if (!$boss) {
            throw new Exception('That boss is not an active user of this company');
        }

        $staffPersons = self::cleanList($staffPersons);
        if (empty($staffPersons)) {
            throw new Exception('Pick at least one person to assign');
        }

        $from = self::normaliseDate($from, 'The start date');
        $to   = self::normaliseDate($to, 'The end date');
        if ($from !== '' && $to !== '' && $to < $from) {
            throw new Exception('The end date cannot fall before the start date');
        }

        $deptHeadId = $this->resolveTag($boss['person'], $compId, $deptHeadId);
        $existing   = $this->repo->existingAssignees($staffPersons, $compId, $boss['person'], $compId);

        $added   = [];
        $skipped = [];

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare($this->insertSql());

            foreach ($staffPersons as $person) {
                if ($person === (string)$boss['person']) {
                    throw new Exception('Somebody cannot report to themselves');
                }

                $staff = $this->repo->findUser($person, $compId);
                if (!$staff) {
                    throw new Exception('Not an active user of this company: ' . $person);
                }

                if (isset($existing[$person])) {
                    $skipped[] = titleCase($staff['fullname']) . ' already reports to this boss';
                    continue;
                }

                if ($this->wouldCycle($person, $compId, $boss['person'], $compId)) {
                    throw new Exception(titleCase($staff['fullname'])
                        . ' already sits above this boss — that assignment would loop the chart');
                }

                $stmt->execute($this->insertValues($person, $compId, $boss['person'], $deptHeadId, $from, $to));
                $added[] = titleCase($staff['fullname']);
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /** Trimmed, de-duplicated, blanks dropped. */
    private static function cleanList(array $values)
    {
        $clean = array_filter(array_map('trim', array_map('strval', $values)), 'strlen');
        return array_values(array_unique($clean));
    }

    /** '' means unbounded, exactly as ACTIVE_CLAUSE reads it. Anything else must be Y-m-d. */
    private static function normaliseDate($value, $label)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        $date = DateTime::createFromFormat('Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new Exception($label . ' must be a calendar date in YYYY-MM-DD form');
        }
        return $value;
    }

    /**
     * The department_head_id the summary will read back. A boss heading exactly
     * one department is tagged automatically; heading several, the caller must
     * say which — otherwise the row lands in the report's "untagged" bucket the
     * moment it is written. A boss who heads nothing carries no tag.
     */
    private function resolveTag($bossPerson, $compId, $requested)
    {
        $ids = [];
        foreach ($this->repo->departmentsHeadedBy($bossPerson, $compId) as $dept) {
            $ids[] = (int)$dept['id'];
        }

        if (empty($ids)) {
            return null;
        }
        if (count($ids) === 1) {
            return $ids[0];
        }

        $requested = (int)$requested;
        if (!in_array($requested, $ids, true)) {
            throw new Exception('This boss heads more than one department — say which one the assignment belongs to');
        }
        return $requested;
    }

    /** True when putting this person under this boss would close a loop. */
    private function wouldCycle($staffPerson, $staffCompId, $bossPerson, $bossCompId)
    {
        $target   = $staffPerson . '|' . (int)$staffCompId;
        $frontier = [['person' => $bossPerson, 'comp_id' => (int)$bossCompId]];
        $seen     = [];
        $depth    = 0;

        while (!empty($frontier) && $depth < self::MAX_DEPTH) {
            $next = [];

            foreach ($this->repo->findBossesOf($frontier) as $row) {
                $key = $row['boss_id'] . '|' . (int)$row['boss_comp_id'];
                if ($key === $target) {
                    return true;
                }
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $next[] = ['person' => $row['boss_id'], 'comp_id' => (int)$row['boss_comp_id']];
            }

            $frontier = $next;
            $depth++;
        }

        return false;
    }

    private function insertSql()
    {
        $columns = array_merge(self::BASE_COLUMNS, array_keys(self::EXTRA_COLUMNS));

        return "INSERT INTO " . ManpowerRepository::ORG_CHART_TABLE
             . " (`" . implode('`, `', $columns) . "`)"
             . " VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")";
    }

    private function insertValues($person, $compId, $bossPerson, $deptHeadId, $from, $to)
    {
        return array_merge(
            [(string)$person, (int)$compId, (string)$bossPerson, (int)$compId, $deptHeadId, $from, $to],
            array_values(self::EXTRA_COLUMNS)
        );
    }
}

// ==================== Presentation helpers ====================

function splitByBlock(array $departments)
{
    $blocks = ['main' => [], 'trillium' => []];
    foreach ($departments as $dept) {
        $blocks[$dept['is_trillium'] ? 'trillium' : 'main'][] = $dept;
    }
    return $blocks;
}

function blockTotals(array $departments)
{
    $totals = ['lead' => 0, 'subhead' => 0, 'sub' => 0, 'headcount' => 0];
    foreach ($departments as $dept) {
        $totals['lead']      += $dept['lead_count'];
        $totals['subhead']   += $dept['subhead_count'];
        $totals['sub']       += $dept['sub_count'];
        $totals['headcount'] += $dept['headcount'];
    }
    return $totals;
}

/**
 * The reconciliation strip. Labels come from the service, so a tile can never
 * be titled differently from the panel it opens, and the columns follow
 * AUDIT_TILES rather than a second layout decision made here.
 */
function auditTiles(array $report)
{
    /* Already counted, off the very lists the panels print — see
     * ManpowerSummaryService::stripFigures(). Nothing is re-derived here,
     * because a second derivation is a second chance to disagree. */
    $counts = $report['strip'];

    $tiles = [];
    foreach (ManpowerSummaryService::AUDIT_TILES as $spec) {
        // A tile whose source could not be read, or does not apply to this
        // company, is dropped rather than printed as zeros: "0 staff" is a
        // claim about the company, not about a file.
        if (!empty($spec['needs_pool']) && empty($report['pool']['available'])) {
            continue;
        }
        if (!empty($spec['needs_trillium'])
            && (empty($report['trillium']['available']) || $report['trillium']['total'] < 1)) {
            continue;
        }

        $parts = [];
        $total = 0;

        foreach ($spec['parts'] as $list => $shortLabel) {
            $count  = (int)$counts[$list];
            $total += $count;

            $parts[] = [
                'list'  => $list,
                'label' => $shortLabel !== null ? $shortLabel : ManpowerSummaryService::AUDIT_LABELS[$list],
                'count' => $count,
                // A head count is a figure, not a fault — only the exception
                // lists wear the amber flag.
                'tone'  => (strpos($list, 'placed_') === 0 || strpos($list, 'pool_') === 0
                            || strpos($list, 'trillium_') === 0)
                    ? 'direct' : 'flag',
            ];
        }

        $tiles[] = [
            'title'    => $spec['title'] !== null ? $spec['title'] : $parts[0]['label'],
            'total'    => $total,
            'parts'    => $parts,
            'is_group' => count($parts) > 1,
        ];
    }
    return $tiles;
}

/**
 * The clickable number on a strip tile. One definition for both the plain tiles
 * and the breakdown lines, so a figure never becomes un-clickable by sitting in
 * a different slot. The aria-label always uses the full list label, so "GW"
 * still announces what it opens.
 */
function auditCountHtml(array $part)
{
    if ($part['count'] < 1) {
        return '<span class="count-zero">0</span>';
    }

    $label = strtolower(ManpowerSummaryService::AUDIT_LABELS[$part['list']]);

    return '<button type="button" class="count count-' . $part['tone'] . '"'
         . ' data-action="get_audit"'
         . ' data-list="' . htmlspecialchars($part['list'], ENT_QUOTES) . '"'
         . ' aria-label="List the ' . htmlspecialchars($label, ENT_QUOTES) . '">'
         . number_format($part['count'])
         . '</button>';
}

/** How many table rows a department occupies — one per reporting line, minimum one. */
function deptRowSpan(array $dept)
{
    return max(1, count($dept['lines']));
}

/** The leaders behind one printed line, as the compact [person, comp_id] pairs the drill-down takes back. */
function lineMembersJson(array $line)
{
    $pairs = [];
    foreach ($line['members'] as $member) {
        $pairs[] = [$member['person'], (int)$member['comp_id']];
    }
    return json_encode($pairs);
}

/** Parses that same payload on the way in. Malformed input is an error, never an empty list. */
function parseMembersJson($raw)
{
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || empty($decoded)) {
        throw new Exception('members must be a non-empty JSON array of [person, comp_id] pairs');
    }

    $members = [];
    foreach ($decoded as $pair) {
        if (!is_array($pair) || count($pair) !== 2 || $pair[0] === null || $pair[0] === '') {
            throw new Exception('members must be a non-empty JSON array of [person, comp_id] pairs');
        }
        $members[] = ['person' => (string)$pair[0], 'comp_id' => (int)$pair[1]];
    }
    return $members;
}

function titleCase($value)
{
    return ucwords(strtolower((string)$value));
}

function exportCsv(array $report, $companyName, array $audit)
{
    $filename = 'manpower_summary_' . preg_replace('/[^A-Za-z0-9]+/', '_', $companyName) . '_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders names correctly

    fputcsv($out, ['No.', 'Dept', 'Dept Head', 'Count', 'Sub Dept Head', 'Count', 'Subordinates', 'Count', 'Grand Total']);

    foreach (splitByBlock($report['departments']) as $blockName => $departments) {
        if (empty($departments)) {
            continue;
        }

        if ($blockName === 'trillium') {
            fputcsv($out, []);
            fputcsv($out, ['TRILLIUM']);
        }

        $no = 1;
        foreach ($departments as $dept) {
            $head  = $dept['head_fullname'] ? titleCase($dept['head_fullname']) : 'NOT FOUND (' . $dept['head_person'] . ')';
            $lines = $dept['lines'];

            if (empty($lines)) {
                fputcsv($out, [$no++, $dept['dept_name'], $head, $dept['lead_count'], '', '', '', '', '']);
                continue;
            }

            $first = true;
            foreach ($lines as $line) {
                fputcsv($out, [
                    $first ? $no : '',
                    $first ? $dept['dept_name'] : '',
                    $first ? $head : '',
                    $first ? $dept['lead_count'] : '',
                    $line['is_subhead'] ? titleCase($line['fullname']) : '',
                    $line['is_subhead'] ? 1 : '',
                    $line['unit'],
                    $line['sub_count'],
                    '',
                ]);
                $first = false;
            }
            $no++;
        }

        $totals = blockTotals($departments);
        fputcsv($out, ['', 'TOTAL', '', $totals['lead'], '', $totals['subhead'], '', $totals['sub'], $totals['headcount']]);
    }

    // Reconciliation. An empty list still prints its heading, so a clean company
    // reads as "checked, nothing found" instead of "nobody ran the check" — but
    // a list that could not be RUN (the pool, when staffPoolCore.php is not
    // there) is left out entirely, because "0 / None" would be a claim about
    // the company rather than about a missing file.
    foreach (ManpowerSummaryService::AUDIT_LABELS as $listName => $label) {
        if (!isset($audit[$listName])) {
            continue;
        }
        $rows = $audit[$listName];

        fputcsv($out, []);
        fputcsv($out, [strtoupper($label), count($rows)]);

        if (empty($rows)) {
            fputcsv($out, ['None']);
            continue;
        }

        fputcsv($out, ['Staff code', 'Name', 'Position', 'Unit', 'Note']);
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['person'],
                titleCase($row['fullname']),
                titleCase($row['position']),
                $row['unit'],
                $row['detail'],
            ]);
        }
    }

    fclose($out);
}

// ==================== Bootstrap ====================

$access  = new AccessPolicy($currentUser);
$repo    = new ManpowerRepository(StaffPortalDatabase::getConnection());
$service = new ManpowerSummaryService($repo);
$writer  = new OrgChartWriter(StaffPortalDatabase::getConnection(), $repo);

if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'get_head':
                if (empty($_GET['dept_id']) || empty($_GET['comp_id'])) {
                    throw new Exception('dept_id and comp_id are required');
                }
                if (!$access->isCompanyPermitted($_GET['comp_id'])) {
                    throw new Exception('You do not have access to this company');
                }
                echo json_encode([
                    'success' => true,
                    'branch'  => $service->headBranch($_GET['comp_id'], $_GET['dept_id']),
                ]);
                break;

            case 'get_line':
                if (empty($_GET['dept_id']) || empty($_GET['comp_id']) || empty($_GET['members'])) {
                    throw new Exception('dept_id, comp_id and members are required');
                }
                if (!$access->isCompanyPermitted($_GET['comp_id'])) {
                    throw new Exception('You do not have access to this company');
                }
                echo json_encode([
                    'success' => true,
                    'branch'  => $service->unitBranch($_GET['comp_id'], $_GET['dept_id'], parseMembersJson($_GET['members'])),
                ]);
                break;

            case 'get_audit':
                if (empty($_GET['comp_id']) || empty($_GET['list'])) {
                    throw new Exception('comp_id and list are required');
                }
                if (!$access->isCompanyPermitted($_GET['comp_id'])) {
                    throw new Exception('You do not have access to this company');
                }
                echo json_encode([
                    'success' => true,
                    'branch'  => $service->auditBranch($_GET['comp_id'], $_GET['list']),
                ]);
                break;

            case 'search_staff':
                if (empty($_GET['comp_id'])) {
                    throw new Exception('comp_id is required');
                }
                if (!$access->isCompanyPermitted($_GET['comp_id'])) {
                    throw new Exception('You do not have access to this company');
                }
                $page = $repo->searchStaff($_GET['comp_id'], isset($_GET['q']) ? trim($_GET['q']) : '');
                echo json_encode([
                    'success' => true,
                    'staff'   => $page['rows'],
                    'total'   => $page['total'],
                ]);
                break;

            case 'boss_context':
                if (empty($_GET['comp_id']) || empty($_GET['person'])) {
                    throw new Exception('comp_id and person are required');
                }
                if (!$access->isCompanyPermitted($_GET['comp_id'])) {
                    throw new Exception('You do not have access to this company');
                }
                echo json_encode([
                    'success'     => true,
                    'departments' => $repo->departmentsHeadedBy($_GET['person'], $_GET['comp_id']),
                ]);
                break;

            case 'assign':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    throw new Exception('Assignments must be posted');
                }
                if (!$access->canEdit()) {
                    throw new Exception('Your account cannot change the organisation chart');
                }
                if (empty($_POST['comp_id']) || empty($_POST['boss']) || empty($_POST['staff']) || !is_array($_POST['staff'])) {
                    throw new Exception('comp_id, boss and at least one staff member are required');
                }
                if (!$access->isCompanyPermitted($_POST['comp_id'])) {
                    throw new Exception('You do not have access to this company');
                }
                echo json_encode(array_merge(
                    ['success' => true],
                    $writer->assign(
                        $_POST['comp_id'],
                        $_POST['boss'],
                        $_POST['staff'],
                        isset($_POST['dept_head_id']) ? $_POST['dept_head_id'] : null,
                        isset($_POST['from']) ? $_POST['from'] : '',
                        isset($_POST['to']) ? $_POST['to'] : ''
                    )
                ));
                break;

            default:
                throw new Exception('Unknown action');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$selectedCompId = $access->resolveCompanyId(isset($_POST['comp_id']) ? $_POST['comp_id'] : $currentUser['comp_id']);

if ($access->canSeeAllCompanies()) {
    $companies = $repo->getCompanies();
} elseif ($access->isRestrictedAdmin()) {
    $companies = $repo->getCompanies($access->allowedCompanyIds());
} else {
    $companies = [];
}

$error_message = '';
$companyName   = '';
$report        = ManpowerSummaryService::emptyReport();

try {
    $report      = $service->build($selectedCompId);
    $companyName = $repo->getCompanyName($selectedCompId);

    if (isset($_POST['export']) && $_POST['export'] === 'csv') {
        // The report is already built, so the lists are a diff plus two grouped
        // reads — never a second walk of the chart.
        exportCsv($report, $companyName, $service->auditLists($selectedCompId, $report));
        exit;
    }
} catch (Exception $e) {
    error_log('Manpower summary failed: ' . $e->getMessage());
    $error_message = $e->getMessage();
}

$blocks = splitByBlock($report['departments']);
$grand  = blockTotals($report['departments']);

// Summary strip above the table. Set to true to bring it back.
$showFigures = false;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manpower Summary</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #0F2137;
            --ink-2: #3D5570;
            --ink-3: #7B8FA6;
            --paper: #EEF1F5;
            --surface: #FFFFFF;
            --rule: #DBE2EA;
            --rule-soft: #EDF1F5;
            --direct: #0E7490;
            --direct-bg: #E2F2F5;
            --subhead: #6D28D9;
            --subhead-bg: #EEE9FB;
            --flag: #A2570C;
            --flag-bg: #FDF3E3;
            --sans: 'IBM Plex Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --cond: 'IBM Plex Sans Condensed', 'IBM Plex Sans', sans-serif;
            --mono: 'IBM Plex Mono', ui-monospace, monospace;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--sans);
            background: var(--paper);
            color: var(--ink);
            padding: 14px;
            font-size: 13px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .main-content {
            margin-left: 260px;
            transition: margin-left .3s ease;
        }

        .main-content.sidebar-collapsed {
            margin-left: 60px;
        }

        @media (max-width:1024px) {
            .main-content {
                margin-left: 60px;
            }
        }

        @media (max-width:768px) {
            body {
                padding: 8px;
            }

            .main-content {
                margin-left: 0 !important;
            }
        }

        .sheet {
            background: var(--surface);
            border: 1px solid var(--rule);
            border-radius: 2px;
        }

        /* --- masthead --- */
        .masthead {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: flex-end;
            justify-content: space-between;
            padding: 20px 22px 18px;
            border-bottom: 1px solid var(--rule);
        }

        .masthead h1 {
            font-family: var(--cond);
            font-size: 26px;
            font-weight: 700;
            letter-spacing: -.2px;
            line-height: 1.1;
        }

        .masthead .eyebrow {
            font-family: var(--cond);
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: var(--direct);
            margin-bottom: 4px;
        }

        .masthead .byline {
            font-size: 12px;
            color: var(--ink-3);
            margin-top: 6px;
        }

        .toolbar {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .btn {
            font-family: var(--cond);
            font-size: 12px;
            font-weight: 600;
            letter-spacing: .6px;
            text-transform: uppercase;
            padding: 8px 14px;
            border: 1px solid var(--rule);
            border-radius: 2px;
            background: var(--surface);
            color: var(--ink-2);
            cursor: pointer;
            transition: border-color .15s, color .15s, background .15s;
        }

        .btn:hover {
            border-color: var(--ink-3);
            color: var(--ink);
        }

        .btn-primary {
            background: var(--ink);
            border-color: var(--ink);
            color: #fff;
        }

        .btn-primary:hover {
            background: #1B3450;
            color: #fff;
        }

        :focus-visible {
            outline: 2px solid var(--direct);
            outline-offset: 2px;
        }

        /* --- figures strip --- */
        .figures {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            border-bottom: 1px solid var(--rule);
        }

        .figure {
            padding: 14px 22px;
            border-right: 1px solid var(--rule-soft);
        }

        .figure:last-child {
            border-right: none;
        }

        .figure-value {
            font-family: var(--mono);
            font-variant-numeric: tabular-nums;
            font-size: 22px;
            font-weight: 500;
            letter-spacing: -.5px;
        }

        .figure-label {
            font-family: var(--cond);
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 1.1px;
            text-transform: uppercase;
            color: var(--ink-3);
            margin-top: 2px;
        }

        .figure-direct .figure-value {
            color: var(--direct);
        }

        .figure-subhead .figure-value {
            color: var(--subhead);
        }

        .figure-flag .figure-value {
            color: var(--flag);
        }

        /* --- reconciliation strip --- */
        /* Reuses the figures grid; only the clickable value differs. */
        .checks .figure-value {
            margin-left: -8px;
        }

        .checks .count,
        .checks .count-zero {
            font-size: 22px;
            font-weight: 500;
            letter-spacing: -.5px;
            padding: 0 8px;
        }

        /* A grouped tile's sum is not a click target, so it carries the pills'
           padding itself to stay on their optical left edge. */
        .checks .figure-sum {
            display: inline-block;
            padding: 0 8px;
            color: var(--direct);
        }

        .figure-parts {
            list-style: none;
            display: grid;
            gap: 1px;
            margin-top: 7px;
        }

        .figure-part {
            display: flex;
            align-items: baseline;
            gap: 4px;
            font-family: var(--cond);
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 1.1px;
            text-transform: uppercase;
            color: var(--ink-3);
        }

        /* Outranks the 22px strip rule above, so a breakdown number reads as a
           detail while staying the same click target. */
        .checks .figure-part .count,
        .checks .figure-part .count-zero {
            min-width: 0;
            font-size: 13px;
            letter-spacing: 0;
            padding: 0 4px;
        }

        .count-flag {
            color: var(--flag);
        }

        .count-flag:hover,
        .count-flag:focus-visible {
            background: var(--flag-bg);
            border-color: #E6C79A;
        }

        /* --- filters --- */
        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            align-items: flex-end;
            padding: 14px 22px;
            border-bottom: 1px solid var(--rule);
            background: #FAFBFC;
        }

        .filters.is-hidden {
            display: none;
        }

        .field-label {
            display: block;
            font-family: var(--cond);
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 1.1px;
            text-transform: uppercase;
            color: var(--ink-3);
            margin-bottom: 5px;
        }

        .field-select {
            min-width: 240px;
            padding: 8px 10px;
            font-family: var(--sans);
            font-size: 13px;
            color: var(--ink);
            background: var(--surface);
            border: 1px solid var(--rule);
            border-radius: 2px;
        }

        /* --- notices --- */
        .notice {
            margin: 14px 22px 0;
            padding: 11px 13px;
            border: 1px solid;
            border-left-width: 3px;
            border-radius: 2px;
            font-size: 12.5px;
        }

        .notice-flag {
            background: var(--flag-bg);
            border-color: #E6C79A;
            border-left-color: var(--flag);
            color: #6B3A05;
        }

        .notice-error {
            background: #FCEDEC;
            border-color: #E9B4B0;
            border-left-color: #B3261E;
            color: #7A1C16;
        }

        .notice code {
            font-family: var(--mono);
            font-size: 11.5px;
        }

        /* --- table --- */
        .block-label {
            font-family: var(--cond);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.6px;
            text-transform: uppercase;
            color: var(--ink-3);
            padding: 26px 22px 0;
            border-top: 1px solid var(--rule-soft);
            margin-top: 12px;
        }

        .table-wrap {
            overflow-x: auto;
            padding: 10px 0 6px;
        }

        table.ledger {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            min-width: 1080px;
        }

        /* One place to tune the column rhythm; the percentages add up to 100. */
        .ledger col.c-no      { width: 4%; }
        .ledger col.c-dept    { width: 16%; }
        .ledger col.c-head    { width: 20%; }
        .ledger col.c-subhead { width: 17%; }
        .ledger col.c-unit    { width: 12%; }
        .ledger col.c-count   { width: 7%; }
        .ledger col.c-total   { width: 10%; }

        .ledger th,
        .ledger td {
            overflow-wrap: break-word;
        }

        .ledger th {
            font-family: var(--cond);
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 1.1px;
            text-transform: uppercase;
            color: var(--ink-3);
            text-align: left;
            padding: 8px 10px;
            border-bottom: 1px solid var(--rule);
            background: var(--surface);
            position: sticky;
            top: 0;
            z-index: 5;
            white-space: nowrap;
        }

        .ledger th.num,
        .ledger td.num {
            text-align: center;
        }

        .ledger td {
            padding: 11px 10px;
            border-bottom: 1px solid var(--rule-soft);
            vertical-align: middle;
        }

        .ledger tbody tr:hover td {
            background: #FAFBFC;
        }

        /* a department owns a run of rows; only the first carries its cells */
        .ledger tbody tr.dept-first td {
            border-top: 1px solid var(--rule);
        }

        .ledger td[rowspan] {
            vertical-align: top;
        }

        .ledger tbody td {
            vertical-align: top;
        }

        .cell-unit {
            font-family: var(--mono);
            font-size: 12px;
            color: var(--ink-2);
        }

        .cell-subhead .head-name {
            font-size: 12.5px;
        }

        .cell-no {
            font-family: var(--mono);
            font-size: 12px;
            color: var(--ink-3);
            text-align: center;
        }

        .dept-name {
            font-family: var(--cond);
            font-size: 15px;
            font-weight: 600;
            letter-spacing: .1px;
        }

        .dept-flag {
            display: inline-block;
            font-family: var(--cond);
            font-size: 10px;
            font-weight: 600;
            letter-spacing: .8px;
            text-transform: uppercase;
            color: var(--flag);
            background: var(--flag-bg);
            border: 1px solid #E6C79A;
            border-radius: 2px;
            padding: 1px 6px;
            margin-top: 5px;
        }

        .head-name {
            font-weight: 500;
        }

        .head-role {
            display: block;
            font-size: 11.5px;
            color: var(--ink-3);
            margin-top: 2px;
        }

        .head-missing {
            color: #B3261E;
            font-weight: 500;
        }

        /* count pills — the click target */
        /* Every number in a count column shares this box so the data rows and the
           TOTAL row line up on the same centre. */
        .count,
        .count-zero,
        .count-plain {
            display: inline-block;
            min-width: 34px;
            font-family: var(--mono);
            font-variant-numeric: tabular-nums;
            font-size: 15px;
            font-weight: 500;
            line-height: 1.3;
            padding: 3px 8px;
            border: 1px solid transparent;
            border-radius: 2px;
            background: transparent;
            text-align: center;
        }

        .count {
            cursor: pointer;
            transition: background .15s, border-color .15s;
        }

        .count-direct {
            color: var(--direct);
        }

        .count-direct:hover,
        .count-direct:focus-visible {
            background: var(--direct-bg);
            border-color: #B6DBE2;
        }

        .count-plain {
            color: var(--subhead);
        }

        .count-sub {
            color: var(--ink-2);
        }

        .count-sub:hover,
        .count-sub:focus-visible {
            background: #E9EEF3;
            border-color: #C6D1DC;
        }

        td.cell-total {
            font-family: var(--mono);
            font-variant-numeric: tabular-nums;
            font-size: 15px;
            font-weight: 600;
            color: var(--ink);
            background: #F5F7F9;
        }

        /* Grand total prints once per block, so its body cells stay empty. */
        td.cell-blank {
            background: transparent;
        }

        .count-zero {
            color: #C3CDD8;
        }

        tr.totals td {
            border-top: 2px solid var(--ink);
            border-bottom: none;
            padding: 14px 10px 16px;
            font-family: var(--mono);
            font-variant-numeric: tabular-nums;
            font-size: 15px;
            font-weight: 600;
        }

        tr.totals td.label {
            font-family: var(--cond);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            text-align: right;
            color: var(--ink-3);
        }

        .empty {
            margin: 22px;
            padding: 40px 24px;
            text-align: center;
            border: 1px dashed var(--rule);
            border-radius: 2px;
            color: var(--ink-3);
        }

        .empty strong {
            display: block;
            font-family: var(--cond);
            font-size: 16px;
            font-weight: 600;
            color: var(--ink-2);
            margin-bottom: 4px;
        }

        /* --- drill-down --- */
        .overlay {
            display: none;
            position: fixed;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            z-index: 9999;
            background: rgba(15, 33, 55, .45);
            align-items: center;
            justify-content: center;
            padding: 18px;
        }

        .overlay.is-open {
            display: flex;
        }

        .panel {
            background: var(--surface);
            border-radius: 2px;
            width: 100%;
            max-width: 620px;
            max-height: 86vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 18px 48px rgba(15, 33, 55, .22);
            animation: rise .18s ease-out;
        }

        @keyframes rise {
            from {
                opacity: 0;
                transform: translateY(8px);
            }
        }

        @media (prefers-reduced-motion:reduce) {
            .panel {
                animation: none;
            }

            * {
                transition: none !important;
            }
        }

        .panel-head {
            padding: 16px 20px;
            border-bottom: 1px solid var(--rule);
            border-top: 3px solid var(--direct);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }

        .panel-head.is-sub {
            border-top-color: var(--ink-2);
        }

        .panel-eyebrow {
            font-family: var(--cond);
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 1.3px;
            text-transform: uppercase;
            color: var(--direct);
        }

        .panel-head.is-sub .panel-eyebrow {
            color: var(--ink-2);
        }

        .panel-title {
            font-family: var(--cond);
            font-size: 19px;
            font-weight: 600;
            margin-top: 2px;
        }

        .panel-sub {
            font-size: 12px;
            color: var(--ink-3);
            margin-top: 2px;
        }

        .panel-close {
            background: none;
            border: none;
            font-size: 22px;
            line-height: 1;
            color: var(--ink-3);
            cursor: pointer;
            padding: 2px 4px;
        }

        .panel-close:hover {
            color: var(--ink);
        }

        .panel-body {
            padding: 6px 20px 18px;
            overflow-y: auto;
        }

        /* org tree with connector rails */
        .tree>ul {
            margin-left: 0;
            padding-left: 0;
            border-left: none;
        }

        .tree>ul>li::before {
            display: none;
        }

        .tree ul {
            list-style: none;
            margin-left: 13px;
            padding-left: 22px;
            border-left: 1px solid #C6D1DC;
        }

        .tree li {
            position: relative;
        }

        .tree ul>li::before {
            content: '';
            position: absolute;
            left: -22px;
            top: 21px;
            width: 18px;
            border-top: 1px solid #C6D1DC;
        }

        .node {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 9px 0;
            border-bottom: 1px solid var(--rule-soft);
        }

        .node-index {
            flex: 0 0 auto;
            min-width: 26px;
            font-family: var(--mono);
            font-variant-numeric: tabular-nums;
            font-size: 10.5px;
            color: var(--ink-3);
            text-align: right;
            padding-top: 3px;
        }

        .node-toggle {
            flex: 0 0 18px;
            width: 18px;
            height: 18px;
            margin-top: 1px;
            border: 1px solid var(--rule);
            border-radius: 2px;
            background: var(--surface);
            color: var(--ink-2);
            font-family: var(--mono);
            font-size: 11px;
            line-height: 1;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .node-toggle:hover {
            border-color: var(--ink-3);
        }

        .node-toggle.is-leaf {
            border-color: transparent;
            background: none;
            color: #C3CDD8;
            cursor: default;
        }

        .node-body {
            flex: 1 1 auto;
            min-width: 0;
        }

        .node-name {
            display: block;
            font-size: 13px;
            font-weight: 500;
            line-height: 1.35;
        }

        .node-meta {
            display: block;
            font-size: 11px;
            color: var(--ink-3);
            line-height: 1.4;
            margin-top: 1px;
        }

        .node-count {
            flex: 0 0 auto;
            font-family: var(--mono);
            font-variant-numeric: tabular-nums;
            font-size: 10.5px;
            color: var(--ink-3);
            white-space: nowrap;
            padding-top: 3px;
        }

        .node-count b {
            color: var(--ink-2);
            font-weight: 500;
        }

        /* group header: the level-1 person a set of subordinates reports through */
        .node-group {
            align-items: center;
            margin-top: 14px;
            padding: 7px 10px;
            background: #F2F5F8;
            border: 1px solid var(--rule);
            border-bottom-color: var(--rule);
            border-radius: 2px;
        }

        .tree>ul>li:first-child .node-group {
            margin-top: 2px;
        }

        .node-group .node-name {
            font-family: var(--cond);
            font-size: 13.5px;
            font-weight: 600;
        }

        .node-tag {
            display: inline-block;
            font-family: var(--cond);
            font-size: 9px;
            font-weight: 600;
            letter-spacing: .8px;
            text-transform: uppercase;
            border-radius: 2px;
            padding: 1px 5px;
            margin-left: 7px;
            vertical-align: 1px;
        }

        .node-tag.is-direct {
            color: var(--direct);
            background: var(--direct-bg);
        }

        .node-tag.is-subhead {
            color: var(--subhead);
            background: var(--subhead-bg);
        }

        .node-tag.is-gw {
            color: #15803D;
            background: #E7F6EC;
        }

        .node-tag.is-head {
            color: var(--flag);
            background: var(--flag-bg);
        }

        /* --- assignment panel --- */
        .panel-foot {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding: 13px 20px;
            border-top: 1px solid var(--rule);
            background: #FAFBFC;
        }

        .form-row {
            padding: 14px 0 0;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            padding-top: 14px;
        }

        .field-input {
            width: 100%;
            padding: 8px 10px;
            font-family: var(--sans);
            font-size: 13px;
            color: var(--ink);
            background: var(--surface);
            border: 1px solid var(--rule);
            border-radius: 2px;
        }

        .field-hint {
            font-size: 11.5px;
            color: var(--ink-3);
            margin-top: 5px;
        }

        .picker {
            max-height: 260px;
            overflow-y: auto;
            border: 1px solid var(--rule);
            border-top: none;
            border-radius: 0 0 2px 2px;
        }

        .picker:empty {
            display: none;
        }

        .picker-item {
            display: block;
            width: 100%;
            text-align: left;
            padding: 7px 10px;
            border: none;
            border-bottom: 1px solid var(--rule-soft);
            background: var(--surface);
            cursor: pointer;
        }

        .picker-item:last-child {
            border-bottom: none;
        }

        .picker-item:hover,
        .picker-item.is-on {
            background: var(--direct-bg);
        }

        .picker-name {
            display: block;
            font-family: var(--sans);
            font-size: 13px;
            font-weight: 500;
            color: var(--ink);
        }

        .picker-meta {
            display: block;
            font-family: var(--mono);
            font-size: 10.5px;
            color: var(--ink-3);
            margin-top: 1px;
        }

        .picker-empty {
            padding: 10px;
            font-size: 12px;
            color: var(--ink-3);
        }

        .chips {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 9px;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--ink-2);
            background: #F2F5F8;
            border: 1px solid var(--rule);
            border-radius: 2px;
            padding: 3px 4px 3px 8px;
        }

        .chip button {
            border: none;
            background: none;
            color: var(--ink-3);
            font-size: 14px;
            line-height: 1;
            padding: 0 3px;
            cursor: pointer;
        }

        .chip button:hover {
            color: #B3261E;
        }

        .form-note {
            margin-top: 14px;
            padding: 10px 12px;
            border: 1px solid;
            border-left-width: 3px;
            border-radius: 2px;
            font-size: 12.5px;
        }

        .form-note.is-error {
            background: #FCEDEC;
            border-color: #E9B4B0;
            border-left-color: #B3261E;
            color: #7A1C16;
        }

        .form-note.is-ok {
            background: #E7F6EC;
            border-color: #B7DFC4;
            border-left-color: #15803D;
            color: #14532D;
        }

        .panel-msg {
            padding: 26px 0;
            text-align: center;
            color: var(--ink-3);
            font-size: 12.5px;
        }

        @media print {

            .filters,
            .toolbar,
            .overlay {
                display: none !important;
            }

            .main-content {
                margin-left: 0 !important;
            }

            body {
                background: #fff;
                padding: 0;
            }
        }
    </style>
</head>

<body>
    <?php include '../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="sheet">

            <div class="masthead">
                <div>
                    <div class="eyebrow">Organisation &middot; Headcount</div>
                    <h1>Manpower Summary</h1>
                    <div class="byline">
                        <?= htmlspecialchars(titleCase($companyName ? $companyName : 'No company selected')) ?>
                        &middot; <?= date('j F Y') ?>
                        <?php if ($access->roleLabel()): ?> &middot; <?= $access->roleLabel() ?><?php endif; ?>
                    </div>
                </div>
                <div class="toolbar">
                    <button type="button" class="btn" id="filtersToggle" aria-expanded="false" aria-controls="filters">Change company</button>
                    <?php if ($access->canEdit()): ?>
                        <button type="button" class="btn" id="assignOpen">Add subordinates</button>
                    <?php endif; ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="comp_id" value="<?= (int)$selectedCompId ?>">
                        <input type="hidden" name="export" value="csv">
                        <button type="submit" class="btn btn-primary">Download CSV</button>
                    </form>
                </div>
            </div>

            <?php if ($showFigures): ?>
                <div class="figures">
                <div class="figure">
                    <div class="figure-value"><?= number_format(count($report['departments'])) ?></div>
                    <div class="figure-label">Departments</div>
                </div>
                <div class="figure figure-direct">
                    <div class="figure-value"><?= number_format($grand['lead']) ?></div>
                    <div class="figure-label">Head &amp; direct</div>
                </div>
                <div class="figure figure-subhead">
                    <div class="figure-value"><?= number_format($grand['subhead']) ?></div>
                    <div class="figure-label">Sub dept heads</div>
                </div>
                <div class="figure">
                    <div class="figure-value"><?= number_format($grand['sub']) ?></div>
                    <div class="figure-label">Subordinates</div>
                </div>
                <div class="figure">
                    <div class="figure-value"><?= number_format($grand['headcount']) ?></div>
                    <div class="figure-label">Grand total</div>
                </div>
                <div class="figure">
                    <div class="figure-value"><?= number_format($report['gw_count']) ?></div>
                    <div class="figure-label">General workers</div>
                </div>
                <?php /* The strip's own figure, not $report['unplaced'] — this
                         strip is hidden today, and the day somebody sets
                         $showFigures it must not print a different number from
                         the reconciliation strip below it. */ ?>
                <div class="figure <?= $report['strip']['unplaced_staff'] > 0 ? 'figure-flag' : '' ?>">
                    <div class="figure-value"><?= number_format($report['strip']['unplaced_staff']) ?></div>
                    <div class="figure-label">Not on any chart</div>
                </div>
                </div>
            <?php endif; ?>

            <form method="POST" class="filters is-hidden" id="filters">
                <?php if ($access->canSeeAllCompanies() || $access->isRestrictedAdmin()): ?>
                    <div>
                        <label class="field-label" for="companySelect">Company</label>
                        <select name="comp_id" id="companySelect" class="field-select" onchange="this.form.submit()">
                            <?php foreach ($companies as $company): ?>
                                <option value="<?= (int)$company['id'] ?>" <?= ((int)$selectedCompId === (int)$company['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($company['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn">Show manpower</button>
                <?php else: ?>
                    <input type="hidden" name="comp_id" value="<?= (int)$selectedCompId ?>">
                    <div class="field-label">Your account is fixed to one company.</div>
                <?php endif; ?>
            </form>

            <?php if (!$error_message): ?>
                <div class="figures checks">
                    <?php foreach (auditTiles($report) as $tile): ?>
                        <div class="figure">
                            <?php if ($tile['is_group']): ?>
                                <!-- The sum spans two panels, so it is not a click
                                     target; each breakdown number opens its own. -->
                                <div class="figure-value"><span class="figure-sum"><?= number_format($tile['total']) ?></span></div>
                                <div class="figure-label"><?= htmlspecialchars($tile['title']) ?></div>
                                <ul class="figure-parts">
                                    <?php foreach ($tile['parts'] as $part): ?>
                                        <li class="figure-part">
                                            <span><?= htmlspecialchars($part['label']) ?></span>
                                            <?= auditCountHtml($part) ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <div class="figure-value"><?= auditCountHtml($tile['parts'][0]) ?></div>
                                <div class="figure-label"><?= htmlspecialchars($tile['title']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="notice notice-error">
                    <strong>The report could not be built.</strong> <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <?php if ($report['untagged'] > 0): ?>
                <div class="notice notice-flag">
                    <strong><?= number_format($report['untagged']) ?></strong> direct report<?= $report['untagged'] === 1 ? '' : 's' ?>
                    could not be placed: their boss heads more than one department and the assignment has no
                    <code>organization_chart_glob.department_head_id</code>. They are counted in that head's first department for now —
                    tag them to move them.
                </div>
            <?php endif; ?>

            <?php if (!empty($report['missing_heads'])): ?>
                <div class="notice notice-flag">
                    <strong>No active staff record for these department heads:</strong>
                    <?= htmlspecialchars(implode(', ', $report['missing_heads'])) ?>. Reactivate the user or reassign the department.
                </div>
            <?php endif; ?>

            <?php if (!empty($report['orphan_subs'])): ?>
                <div class="notice notice-flag">
                    <strong>Sub dept heads pointing at a missing department:</strong>
                    <?= htmlspecialchars(implode(', ', $report['orphan_subs'])) ?>. They are left out of every count until
                    <code>parent_id</code> points at an active department.
                </div>
            <?php endif; ?>

            <?php if (empty($report['departments'])): ?>
                <div class="empty">
                    <strong>Nothing to summarise yet</strong>
                    Add a department in Department Head for this company, then come back.
                </div>
            <?php else: ?>
                <?php foreach ($blocks as $blockName => $departments): ?>
                    <?php if (empty($departments)) continue; ?>

                    <?php if ($blockName === 'trillium'): ?>
                        <div class="block-label">Trillium</div>
                    <?php endif; ?>

                    <div class="table-wrap">
                        <table class="ledger">
                            <colgroup>
                                <col class="c-no">
                                <col class="c-dept">
                                <col class="c-head">
                                <col class="c-count">
                                <col class="c-subhead">
                                <col class="c-count">
                                <col class="c-unit">
                                <col class="c-count">
                                <col class="c-total">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th class="num">No</th>
                                    <th>Dept</th>
                                    <th>Dept head</th>
                                    <th class="num">Count</th>
                                    <th>Sub dept head</th>
                                    <th class="num">Count</th>
                                    <th>Subordinates</th>
                                    <th class="num">Count</th>
                                    <th class="num">Grand total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; ?>
                                <?php foreach ($departments as $dept): ?>
                                    <?php
                                    $span  = deptRowSpan($dept);
                                    $lines = !empty($dept['lines']) ? $dept['lines'] : [null];
                                    ?>
                                    <?php foreach ($lines as $i => $line): ?>
                                        <tr class="<?= $i === 0 ? 'dept-first' : '' ?>">
                                            <?php if ($i === 0): ?>
                                                <td class="cell-no" rowspan="<?= $span ?>"><?= $no++ ?></td>

                                                <td rowspan="<?= $span ?>">
                                                    <div class="dept-name"><?= htmlspecialchars($dept['dept_name']) ?></div>
                                                    <?php if ($dept['untagged'] > 0): ?>
                                                        <span class="dept-flag"><?= (int)$dept['untagged'] ?> untagged</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td rowspan="<?= $span ?>">
                                                    <?php if ($dept['head_fullname'] !== null): ?>
                                                        <span class="head-name"><?= htmlspecialchars(titleCase($dept['head_fullname'])) ?></span>
                                                        <?php if (!empty($dept['head_position'])): ?>
                                                            <span class="head-role"><?= htmlspecialchars(titleCase($dept['head_position'])) ?></span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="head-missing">Not found (<?= htmlspecialchars($dept['head_person']) ?>)</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td class="num" rowspan="<?= $span ?>">
                                                    <?php if ($dept['lead_count'] > 0): ?>
                                                        <button type="button" class="count count-direct"
                                                            data-action="get_head"
                                                            data-dept="<?= (int)$dept['id'] ?>"
                                                            aria-label="View the head and direct subordinates of <?= htmlspecialchars($dept['dept_name']) ?>">
                                                            <?= number_format($dept['lead_count']) ?>
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="count-zero">0</span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>

                                            <td class="cell-subhead">
                                                <?php if ($line && $line['is_subhead']): ?>
                                                    <span class="head-name"><?= htmlspecialchars(titleCase($line['fullname'])) ?></span>
                                                    <?php if (!empty($line['position'])): ?>
                                                        <span class="head-role"><?= htmlspecialchars(titleCase($line['position'])) ?></span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>

                                            <td class="num">
                                                <?php if ($line && $line['is_subhead']): ?>
                                                    <span class="count-plain">1</span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="cell-unit">
                                                <?php if ($line): ?>
                                                    <?= htmlspecialchars($line['unit']) ?>
                                                <?php endif; ?>
                                            </td>

                                            <td class="num">
                                                <?php if ($line && $line['sub_count'] > 0): ?>
                                                    <button type="button" class="count count-sub"
                                                        data-action="get_line"
                                                        data-dept="<?= (int)$dept['id'] ?>"
                                                        data-members="<?= htmlspecialchars(lineMembersJson($line), ENT_QUOTES) ?>"
                                                        aria-label="View <?= (int)$line['sub_count'] ?> subordinates in <?= htmlspecialchars($line['unit']) ?>">
                                                        <?= number_format($line['sub_count']) ?>
                                                    </button>
                                                <?php elseif ($line): ?>
                                                    <span class="count-zero">0</span>
                                                <?php endif; ?>
                                            </td>

                                            <?php if ($i === 0): ?>
                                                <td class="cell-blank" rowspan="<?= $span ?>"></td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>

                                <?php $totals = blockTotals($departments); ?>
                                <tr class="totals">
                                    <td colspan="3" class="label">Total</td>
                                    <td class="num"><?= number_format($totals['lead']) ?></td>
                                    <td></td>
                                    <td class="num"><?= number_format($totals['subhead']) ?></td>
                                    <td></td>
                                    <td class="num"><?= number_format($totals['sub']) ?></td>
                                    <td class="num cell-total"><?= number_format($totals['headcount']) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>
    </div>

    <div class="overlay" id="drill" role="dialog" aria-modal="true" aria-labelledby="drillTitle">
        <div class="panel">
            <div class="panel-head" id="drillHead">
                <div>
                    <div class="panel-eyebrow" id="drillEyebrow">Direct subordinates</div>
                    <div class="panel-title" id="drillTitle">&nbsp;</div>
                    <div class="panel-sub" id="drillSub"></div>
                </div>
                <button type="button" class="panel-close" id="drillClose" aria-label="Close">&times;</button>
            </div>
            <div class="panel-body" id="drillBody"></div>
        </div>
    </div>

    <?php if ($access->canEdit()): ?>
        <div class="overlay" id="assign" role="dialog" aria-modal="true" aria-labelledby="assignTitle">
            <div class="panel">
                <div class="panel-head is-sub">
                    <div>
                        <div class="panel-eyebrow">Organisation chart</div>
                        <div class="panel-title" id="assignTitle">Add subordinates</div>
                        <div class="panel-sub"><?= htmlspecialchars(titleCase($companyName)) ?></div>
                    </div>
                    <button type="button" class="panel-close" id="assignClose" aria-label="Close">&times;</button>
                </div>

                <div class="panel-body">
                    <div class="form-row">
                        <label class="field-label" for="bossSearch">Reports to</label>
                        <input type="text" class="field-input" id="bossSearch" autocomplete="off"
                            placeholder="Search a name or staff code">
                        <div class="picker" id="bossResults"></div>
                        <div class="chips" id="bossChosen"></div>
                    </div>

                    <div class="form-row" id="deptRow" hidden>
                        <label class="field-label" for="deptSelect">Which department</label>
                        <select class="field-input" id="deptSelect"></select>
                        <div class="field-hint">This boss heads more than one department, so the assignment has to say which.</div>
                    </div>

                    <div class="form-row">
                        <label class="field-label" for="staffSearch">People to assign</label>
                        <input type="text" class="field-input" id="staffSearch" autocomplete="off"
                            placeholder="Search a name or staff code">
                        <div class="picker" id="staffResults"></div>
                        <div class="chips" id="staffChosen"></div>
                    </div>

                    <div class="form-grid">
                        <div>
                            <label class="field-label" for="assignFrom">Effective from</label>
                            <!-- Assignments are read monthly, so the sane default is the
                                 first of the current month, not today. -->
                            <input type="date" class="field-input" id="assignFrom" value="<?= date('Y-m-01') ?>">
                        </div>
                        <div>
                            <label class="field-label" for="assignTo">Until</label>
                            <input type="date" class="field-input" id="assignTo">
                        </div>
                    </div>
                    <div class="field-hint">Leave <strong>Until</strong> blank for an open-ended assignment. A future start date
                        means the line only appears in this report once that date arrives.</div>

                    <div class="form-note" id="assignNote" hidden></div>
                </div>

                <div class="panel-foot">
                    <button type="button" class="btn" id="assignCancel">Cancel</button>
                    <button type="button" class="btn btn-primary" id="assignSave" disabled>Add to chart</button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        /* Shared by every panel on this page — one definition, no drift. */
        var MPUtil = (function() {
            'use strict';

            function escapeHtml(value) {
                return String(value == null ? '' : value)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }

            function titleCase(value) {
                return String(value || '').toLowerCase().replace(/\b\w/g, function(c) {
                    return c.toUpperCase();
                });
            }

            return {
                escapeHtml: escapeHtml,
                titleCase: titleCase
            };
        })();
    </script>

    <script>
        (function() {
            'use strict';

            var COMP_ID = <?= (int)$selectedCompId ?>;

            /* ---- filters ---- */
            var filters = document.getElementById('filters');
            var filtersToggle = document.getElementById('filtersToggle');

            filtersToggle.addEventListener('click', function() {
                var open = !filters.classList.toggle('is-hidden');
                filtersToggle.setAttribute('aria-expanded', String(open));
                if (open) {
                    var select = document.getElementById('companySelect');
                    if (select) select.focus();
                }
            });

            /* ---- drill-down ---- */
            var overlay = document.getElementById('drill');
            var head = document.getElementById('drillHead');
            var eyebrow = document.getElementById('drillEyebrow');
            var title = document.getElementById('drillTitle');
            var sub = document.getElementById('drillSub');
            var body = document.getElementById('drillBody');
            var lastTrigger = null;

            var escapeHtml = MPUtil.escapeHtml;
            var titleCase = MPUtil.titleCase;

            function closeDrill() {
                overlay.classList.remove('is-open');
                if (lastTrigger) lastTrigger.focus();
            }

            document.getElementById('drillClose').addEventListener('click', closeDrill);
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) closeDrill();
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeDrill();
            });

            /* Recursive tree markup. Numbering is hierarchical — 1, 1.1, 1.1.1 —
               so a row's own label tells you who it hangs off. Depth 0 and group
               headers start expanded; deeper levels start closed. */
            function renderNodes(nodes, depth, prefix) {
                return '<ul>' + nodes.map(function(node, i) {
                    var index = prefix ? prefix + '.' + (i + 1) : String(i + 1);
                    return '<li>' + renderNode(node, depth, index) + '</li>';
                }).join('') + '</ul>';
            }

            function renderNode(node, depth, index) {
                var hasKids = node.children && node.children.length > 0;
                var isGroup = node.is_group === true;
                var open = depth === 0 || isGroup;

                var toggle = hasKids ?
                    '<button type="button" class="node-toggle" aria-expanded="' + open + '">' + (open ? '\u2212' : '+') + '</button>' :
                    '<span class="node-toggle is-leaf" aria-hidden="true">\u00b7</span>';

                var tag = '';
                if (node.is_head === true) {
                    tag = '<span class="node-tag is-head">Dept head</span>';
                } else if (isGroup) {
                    tag = '<span class="node-tag ' + (node.is_subhead ? 'is-subhead' : 'is-direct') + '">' +
                        (node.is_subhead ? 'Sub dept head' : 'Direct') + '</span>';
                } else if (node.is_gw === true) {
                    tag = '<span class="node-tag is-gw">GW</span>';
                }

                var count = hasKids ?
                    '<span class="node-count"><b>' + node.direct + '</b> direct \u00b7 <b>' + node.total + '</b> total</span>' :
                    '';

                var meta = [node.position ? titleCase(node.position) : '', node.unit || ''].filter(Boolean).join(' \u00b7 ');

                // A group header labels a set, so it carries no index of its own.
                var gutter = isGroup ? '' : '<span class="node-index">' + index + '</span>';

                var html = '<div class="node' + (isGroup ? ' node-group' : '') + '">' +
                    gutter + toggle +
                    '<span class="node-body">' +
                    '<span class="node-name">' + escapeHtml(titleCase(node.fullname)) + tag + '</span>' +
                    (meta ? '<span class="node-meta">' + escapeHtml(meta) + '</span>' : '') +
                    '</span>' + count + '</div>';

                if (hasKids) {
                    // Children of a group header restart at 1; everyone else extends the parent's number.
                    var childPrefix = isGroup ? '' : index;
                    html += '<div class="node-kids"' + (open ? '' : ' hidden') + '>' +
                        renderNodes(node.children, depth + 1, childPrefix) + '</div>';
                }
                return html;
            }

            body.addEventListener('click', function(e) {
                var toggle = e.target.closest('.node-toggle');
                if (!toggle || toggle.classList.contains('is-leaf')) return;

                var kids = toggle.closest('.node').nextElementSibling;
                if (!kids) return;

                var open = kids.hidden;
                kids.hidden = !open;
                toggle.textContent = open ? '\u2212' : '+';
                toggle.setAttribute('aria-expanded', String(open));
            });

            /* One panel, three sources. Adding a data-action means adding a line
               to each map — never another click handler. */
            var EYEBROW = {
                get_head: 'Dept head & direct subordinates',
                get_line: 'Subordinates',
                get_audit: 'Reconciliation'
            };

            var SUBTITLE = {
                get_head: function(branch) {
                    return branch.people.length + ' listed \u00b7 head and direct subordinates';
                },
                get_line: function(branch) {
                    return (branch.line_name ? titleCase(branch.line_name) :
                        branch.people.length + ' reporting lines') + ' \u00b7 ' + branch.unit;
                },
                get_audit: function(branch) {
                    return branch.people.length + ' listed \u00b7 ' + branch.unit;
                }
            };

            /* Only the keys a trigger carries are sent, so one builder serves
               every action. */
            function drillUrl(trigger) {
                var params = new URLSearchParams({
                    action: trigger.dataset.action,
                    comp_id: COMP_ID
                });
                if (trigger.dataset.dept) params.set('dept_id', trigger.dataset.dept);
                if (trigger.dataset.members) params.set('members', trigger.dataset.members);
                if (trigger.dataset.list) params.set('list', trigger.dataset.list);
                return '?' + params.toString();
            }

            document.addEventListener('click', function(e) {
                var trigger = e.target.closest('.count');
                if (!trigger) return;

                var action = trigger.dataset.action;
                if (!Object.prototype.hasOwnProperty.call(EYEBROW, action)) return;
                lastTrigger = trigger;

                head.classList.toggle('is-sub', action !== 'get_head');
                eyebrow.textContent = EYEBROW[action];
                title.textContent = '\u2026';
                sub.textContent = '';
                body.innerHTML = '<div class="panel-msg">Loading\u2026</div>';
                overlay.classList.add('is-open');
                document.getElementById('drillClose').focus();

                fetch(drillUrl(trigger), {
                        credentials: 'same-origin'
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        if (!data.success) throw new Error(data.message || 'The list could not be loaded.');

                        var branch = data.branch;

                        title.textContent = branch.dept_name;
                        sub.textContent = SUBTITLE[action](branch);

                        body.innerHTML = branch.people.length ?
                            '<div class="tree">' + renderNodes(branch.people, 0, '') + '</div>' :
                            '<div class="panel-msg">' +
                            escapeHtml(branch.empty || 'Nobody is assigned here yet.') + '</div>';
                    })
                    .catch(function(err) {
                        title.textContent = 'Could not load';
                        body.innerHTML = '<div class="panel-msg">' + escapeHtml(err.message) + '</div>';
                    });
            });

        })();
    </script>

    <script>
        /* Add-subordinates panel. Present only for accounts allowed to write, so
           its absence is the read-only state — there is no disabled mode to keep
           in sync. */
        (function() {
            'use strict';

            var opener = document.getElementById('assignOpen');
            if (!opener) return;

            var COMP_ID = <?= (int)$selectedCompId ?>;
            var escapeHtml = MPUtil.escapeHtml;
            var titleCase = MPUtil.titleCase;

            var overlay = document.getElementById('assign');
            var deptRow = document.getElementById('deptRow');
            var deptSelect = document.getElementById('deptSelect');
            var bossChosen = document.getElementById('bossChosen');
            var staffChosen = document.getElementById('staffChosen');
            var fromInput = document.getElementById('assignFrom');
            var toInput = document.getElementById('assignTo');
            var note = document.getElementById('assignNote');
            var save = document.getElementById('assignSave');

            var boss = null;
            var staff = {}; // person => row

            function get(url) {
                return fetch(url, {
                        credentials: 'same-origin'
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        if (!data.success) throw new Error(data.message || 'The request failed.');
                        return data;
                    });
            }

            function say(message, ok) {
                note.className = 'form-note ' + (ok ? 'is-ok' : 'is-error');
                note.innerHTML = message;
                note.hidden = false;
            }

            function clearNote() {
                note.hidden = true;
                note.innerHTML = '';
            }

            function personLabel(row) {
                return [row.person, titleCase(row.position || ''), row.department || row.district || '']
                    .filter(Boolean).join(' \u00b7 ');
            }

            /* One picker implementation, two instances: the boss field takes a
               single pick, the staff field accumulates. */
            function attachPicker(inputId, resultsId, onPick, isOn) {
                var input = document.getElementById(inputId);
                var results = document.getElementById(resultsId);
                var rows = [];
                var timer = null;

                isOn = isOn || function() {
                    return false;
                };

                function run() {
                    var q = input.value.trim();
                    get('?action=search_staff&comp_id=' + COMP_ID + '&q=' + encodeURIComponent(q))
                        .then(function(data) {
                            rows = data.staff;
                            if (!rows.length) {
                                results.innerHTML = '<div class="picker-empty">Nobody matches that.</div>';
                                return;
                            }

                            var list = rows.map(function(row) {
                                return '<button type="button" class="picker-item" data-person="' +
                                    escapeHtml(row.person) + '">' +
                                    '<span class="picker-name">' + escapeHtml(titleCase(row.fullname)) + '</span>' +
                                    '<span class="picker-meta">' + escapeHtml(personLabel(row)) + '</span></button>';
                            }).join('');

                            // Never truncate in silence — say what is being held back.
                            if (data.total > rows.length) {
                                list += '<div class="picker-empty">Showing ' + rows.length + ' of ' +
                                    data.total + ' active staff — type more to narrow the list.</div>';
                            }

                            results.innerHTML = list;
                            paint();
                        })
                        .catch(function(err) {
                            results.innerHTML = '<div class="picker-empty">' + escapeHtml(err.message) + '</div>';
                        });
                }

                function paint() {
                    Array.prototype.forEach.call(results.querySelectorAll('.picker-item'), function(item) {
                        item.classList.toggle('is-on', isOn(item.dataset.person));
                    });
                }

                input.addEventListener('input', function() {
                    clearTimeout(timer);
                    timer = setTimeout(run, 220);
                });
                input.addEventListener('focus', run);

                results.addEventListener('click', function(e) {
                    var item = e.target.closest('.picker-item');
                    if (!item) return;

                    var picked = rows.filter(function(row) {
                        return String(row.person) === item.dataset.person;
                    })[0];
                    if (picked) onPick(picked);
                    paint();
                });

                return {
                    reset: function() {
                        input.value = '';
                        results.innerHTML = '';
                        rows = [];
                    },
                    repaint: paint
                };
            }

            function renderBoss() {
                bossChosen.innerHTML = boss ?
                    '<span class="chip">' + escapeHtml(titleCase(boss.fullname)) +
                    '<button type="button" data-clear="boss" aria-label="Clear the boss">&times;</button></span>' :
                    '';
            }

            function renderStaff() {
                var persons = Object.keys(staff);
                staffChosen.innerHTML = persons.map(function(person) {
                    return '<span class="chip">' + escapeHtml(titleCase(staff[person].fullname)) +
                        '<button type="button" data-drop="' + escapeHtml(person) +
                        '" aria-label="Remove this person">&times;</button></span>';
                }).join('');
                staffPicker.repaint();
            }

            function refreshSave() {
                save.disabled = !boss || Object.keys(staff).length === 0;
            }

            /* A boss heading several departments has to be tagged, or the row
               lands in the report's untagged bucket the moment it is written. */
            function loadDepartments() {
                deptRow.hidden = true;
                deptSelect.innerHTML = '';
                if (!boss) return;

                get('?action=boss_context&comp_id=' + COMP_ID + '&person=' + encodeURIComponent(boss.person))
                    .then(function(data) {
                        if (data.departments.length < 2) return;
                        deptSelect.innerHTML = data.departments.map(function(dept) {
                            return '<option value="' + Number(dept.id) + '">' + escapeHtml(dept.dept_name) + '</option>';
                        }).join('');
                        deptRow.hidden = false;
                    })
                    .catch(function(err) {
                        say(escapeHtml(err.message), false);
                    });
            }

            var bossPicker = attachPicker('bossSearch', 'bossResults', function(row) {
                boss = row;
                bossPicker.reset();
                renderBoss();
                loadDepartments();
                refreshSave();
                clearNote();
            });

            var staffPicker = attachPicker('staffSearch', 'staffResults', function(row) {
                if (Object.prototype.hasOwnProperty.call(staff, row.person)) {
                    delete staff[row.person];
                } else {
                    staff[row.person] = row;
                }
                renderStaff();
                refreshSave();
                clearNote();
            }, function(person) {
                return Object.prototype.hasOwnProperty.call(staff, person);
            });

            bossChosen.addEventListener('click', function(e) {
                if (!e.target.closest('[data-clear]')) return;
                boss = null;
                renderBoss();
                loadDepartments();
                refreshSave();
            });

            staffChosen.addEventListener('click', function(e) {
                var drop = e.target.closest('[data-drop]');
                if (!drop) return;
                delete staff[drop.dataset.drop];
                renderStaff();
                refreshSave();
            });

            /* Re-post the company so the table redraws with the new counts —
               a plain reload would re-submit whatever POST put us here. */
            function reloadReport() {
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = window.location.pathname;

                var field = document.createElement('input');
                field.type = 'hidden';
                field.name = 'comp_id';
                field.value = COMP_ID;

                form.appendChild(field);
                document.body.appendChild(form);
                form.submit();
            }

            save.addEventListener('click', function() {
                var body = new URLSearchParams();
                body.append('comp_id', COMP_ID);
                body.append('boss', boss.person);
                body.append('from', fromInput.value);
                body.append('to', toInput.value);
                if (!deptRow.hidden) body.append('dept_head_id', deptSelect.value);
                Object.keys(staff).forEach(function(person) {
                    body.append('staff[]', person);
                });

                save.disabled = true;
                clearNote();

                fetch('?action=assign', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                        },
                        body: body.toString()
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        if (!data.success) throw new Error(data.message || 'Nothing was saved.');

                        var lines = [];
                        if (data.added.length) {
                            lines.push('<strong>Added ' + data.added.length + ':</strong> ' +
                                escapeHtml(data.added.join(', ')));
                        }
                        if (data.skipped.length) {
                            lines.push('<strong>Skipped:</strong> ' + escapeHtml(data.skipped.join('; ')));
                        }
                        say(lines.join('<br>') || 'Nothing to add.', data.added.length > 0);

                        if (data.added.length) {
                            setTimeout(reloadReport, 900);
                        } else {
                            save.disabled = false;
                        }
                    })
                    .catch(function(err) {
                        say(escapeHtml(err.message), false);
                        save.disabled = false;
                    });
            });

            function close() {
                overlay.classList.remove('is-open');
                opener.focus();
            }

            opener.addEventListener('click', function() {
                overlay.classList.add('is-open');
                document.getElementById('bossSearch').focus();
            });

            document.getElementById('assignClose').addEventListener('click', close);
            document.getElementById('assignCancel').addEventListener('click', close);
            // No backdrop-click handler here, unlike the drill-down: this panel holds
            // unsaved input, so it closes only on Cancel, the × or Escape.
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && overlay.classList.contains('is-open')) close();
            });

            refreshSave();
        })();
    </script>
</body>

</html>