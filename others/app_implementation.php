<?php
session_start();
require_once '../includes/UnifiedAuth.php';

/*
 * Two auth modes, one codebase:
 *   Web    -> PHP session (UnifiedAuth).
 *   Mobile -> person + comp_id in the request (?mobile=1), validated against `users`
 *             the same way staffHierarchy.php does. Mobile requests skip the session
 *             login redirect; everything downstream uses $currentUser identically, so
 *             the app-listing / reference-material logic is never duplicated.
 */
$isMobileApi = isset($_REQUEST['mobile']) && $_REQUEST['mobile'] == '1';

if ($isMobileApi) {
    $mPerson = isset($_REQUEST['person']) ? trim($_REQUEST['person']) : '';
    $mComp   = (isset($_REQUEST['comp_id']) && $_REQUEST['comp_id'] !== '')
             ? $_REQUEST['comp_id']
             : (isset($_REQUEST['comid']) ? $_REQUEST['comid'] : '');
    if ($mPerson === '' || $mComp === '') {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => 'person and comp_id are required']);
        exit;
    }
    $mUser = ImplTrackerService::resolveMobileUser($mPerson, (int)$mComp);
    if (!$mUser) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => 'Staff not found or inactive']);
        exit;
    }
    $currentUser = ['person' => $mUser['person'], 'comp_id' => (int)$mUser['comp_id']];
} else {
    if (!UnifiedAuth::isLoggedIn()) {
        header('Location: /accounts/mkPortal/user_validation.php');
        exit;
    }
    $currentUser = UnifiedAuth::getLoggedInUser();
}

class DB
{
    private static $app = null, $staff = null, $eval = null, $glob = null;
    const HOST = 'localhost', PORT = 33060, USER = 'prog4', PASS = 'prog42023';
    public static function app()
    {
        if (!self::$app) self::$app = new PDO("mysql:host=" . self::HOST . ";port=" . self::PORT . ";dbname=application", self::USER, self::PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return self::$app;
    }
    public static function staff()
    {
        if (!self::$staff) self::$staff = new PDO("mysql:host=" . self::HOST . ";port=" . self::PORT . ";dbname=staff_portal2", self::USER, self::PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return self::$staff;
    }
    public static function evaluation()
    {
        if (!self::$eval) self::$eval = new PDO("mysql:host=" . self::HOST . ";port=" . self::PORT . ";dbname=evaluation", self::USER, self::PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return self::$eval;
    }
    // globmy lives on a separate host - used by usage-report providers (supervisor_daily_report, district).
    public static function glob()
    {
        if (!self::$glob) self::$glob = new PDO("mysql:host=175.145.207.141;port=3306;dbname=globmy", 'portal', 'portal2018', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        return self::$glob;
    }
}

class ImplTrackerService
{

    // ── Access ─────────────────────────────────────────────

    /**
     * Mobile auth: validate person+comp_id against the live users table (status=1,
     * deleted=0) — identical trust model to staffHierarchy.php. Returns the row or null.
     * Static so the top-of-file mobile branch can call it before $svc exists.
     */
    public static function resolveMobileUser($person, $compId)
    {
        $stmt = DB::staff()->prepare("SELECT person, comp_id, fullname FROM users WHERE person=? AND comp_id=? AND status='1' AND deleted=0 LIMIT 1");
        $stmt->execute([$person, (int)$compId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getAccessibleCompanyIds($person, $compId)
    {
        $stmt = DB::app()->prepare("SELECT comp_id_allowed FROM app_implementation_admins WHERE person=? AND comp_id=? AND deleted=0 LIMIT 1");
        $stmt->execute([$person, $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return [$compId];
        if (trim($row['comp_id_allowed']) === 'all')
            return DB::staff()->query("SELECT id FROM subsidiaries WHERE weekly_report_status=1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $ids = array_map('intval', array_filter(explode(',', $row['comp_id_allowed'])));
        return $ids ?: [$compId];
    }

    public function isAdmin($person, $compId)
    {
        $stmt = DB::app()->prepare("SELECT id FROM app_implementation_admins WHERE person=? AND comp_id=? AND deleted=0 LIMIT 1");
        $stmt->execute([$person, $compId]);
        return (bool)$stmt->fetch();
    }

    /**
     * Owner/developer (or admin) check. On an all-company application the role rows may be
     * registered under any company, so comp_id is not part of the match there.
     */
    public function canManage($person, $applicationId, $appCompId, $isAdmin)
    {
        if ($isAdmin) return true;
        $scoped = !$this->isAllCompanyApp($applicationId);
        // Table names come from this literal list only - safe to inline.
        foreach (['application_owner', 'application_developer'] as $table) {
            $sql  = "SELECT 1 FROM {$table} WHERE application_id=? AND staff_id=?" . ($scoped ? " AND comp_id=?" : "") . " LIMIT 1";
            $args = $scoped ? [$applicationId, $person, $appCompId] : [$applicationId, $person];
            $stmt = DB::app()->prepare($sql);
            $stmt->execute($args);
            if ($stmt->fetch()) return true;
        }
        return false;
    }

    /**
     * SQL for "tracker t is manageable by :person". Expects aliases `t` (app_impl_tracker)
     * and `a` (application) in the outer query.
     * Binds, in order: t.comp_id, ao.staff_id, ao.comp_id, ad.staff_id, ad.comp_id
     *   -> execute([$compId, $person, $compId, $person, $compId])
     */
    private static function manageableTrackerSql()
    {
        return "t.deleted=0
                    AND (t.comp_id=? OR a.application_all_company=1)
                    AND (
                        EXISTS (SELECT 1 FROM application_owner ao
                                 WHERE ao.application_id=t.application_id AND ao.staff_id=?
                                   AND (a.application_all_company=1 OR ao.comp_id=?))
                        OR EXISTS (SELECT 1 FROM application_developer ad
                                 WHERE ad.application_id=t.application_id AND ad.staff_id=?
                                   AND (a.application_all_company=1 OR ad.comp_id=?))
                    )";
    }

    public function requireManagePermission($person, $compId, $isAdmin, $context)
    {
        $tracker = null;
        if (!empty($context['tracker_id'])) {
            $stmt = DB::app()->prepare("SELECT application_id, comp_id FROM app_impl_tracker WHERE id=? AND deleted=0");
            $stmt->execute([$context['tracker_id']]);
            $tracker = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif (!empty($context['tracker_task_id'])) {
            $stmt = DB::app()->prepare("SELECT t.application_id, t.comp_id FROM app_impl_tracker t JOIN app_impl_tracker_tasks tt ON tt.tracker_id=t.id WHERE tt.id=? AND t.deleted=0");
            $stmt->execute([$context['tracker_task_id']]);
            $tracker = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif (!empty($context['category_id'])) {
            $stmt = DB::app()->prepare("SELECT t.application_id, t.comp_id FROM app_impl_tracker t JOIN app_impl_tracker_tasks tt ON tt.tracker_id=t.id JOIN app_impl_tracker_task_categories tc ON tc.tracker_task_id=tt.id WHERE tc.id=? AND t.deleted=0");
            $stmt->execute([$context['category_id']]);
            $tracker = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif (!empty($context['assignee_id'])) {
            $stmt = DB::app()->prepare("SELECT t.application_id, t.comp_id FROM app_impl_tracker t JOIN app_impl_tracker_tasks tt ON tt.tracker_id=t.id JOIN app_impl_tracker_task_assignees ta ON ta.tracker_task_id=tt.id WHERE ta.id=? AND t.deleted=0");
            $stmt->execute([$context['assignee_id']]);
            $tracker = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$tracker) throw new Exception("Tracker not found");
        if (!$this->canManage($person, $tracker['application_id'], $tracker['comp_id'], $isAdmin))
            throw new Exception("You do not have permission to perform this action");
    }

    // ── Lookups ────────────────────────────────────────────

    public function getCompanies($ids)
    {
        if (empty($ids)) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = DB::staff()->prepare("SELECT id, name FROM subsidiaries WHERE id IN ({$ph}) ORDER BY name");
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCompanyName($compId)
    {
        static $cache = [];
        if (!array_key_exists($compId, $cache)) {
            $stmt = DB::staff()->prepare("SELECT name FROM subsidiaries WHERE id=? LIMIT 1");
            $stmt->execute([$compId]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $cache[$compId] = $r ? $r['name'] : 'Unknown';
        }
        return $cache[$compId];
    }

    /**
     * Display label for a tracker's company. All-company applications are not tied to one
     * company, so they read "All Company". Expects a row joined with application.
     */
    public function getTrackerCompanyLabel(array $row)
    {
        if (!array_key_exists('application_all_company', $row))
            throw new Exception("Tracker row is missing application_all_company");
        return (int)$row['application_all_company'] === 1 ? 'All Company' : $this->getCompanyName($row['comp_id']);
    }

    public function getUserFullname($person, $compId)
    {
        $stmt = DB::staff()->prepare("SELECT fullname FROM users WHERE person=? AND comp_id=? AND status='1' LIMIT 1");
        $stmt->execute([$person, $compId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ? self::formatName($r['fullname']) : $person;
    }

    /**
     * True when the application is flagged for cross-company use. Memoised per request:
     * the manage/report gates hit this repeatedly.
     *
     * The flag means company scoping does not apply to this application. Concretely:
     * application_company rows, and the comp_id on application_owner / application_developer,
     * become informational rather than restrictive.
     */
    public function isAllCompanyApp($applicationId)
    {
        static $cache = [];
        $key = (string)$applicationId;
        if (!array_key_exists($key, $cache)) {
            $stmt = DB::app()->prepare("SELECT application_all_company FROM application WHERE application_id=? LIMIT 1");
            $stmt->execute([$applicationId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception("Application not found");
            $cache[$key] = ((int)$row['application_all_company'] === 1);
        }
        return $cache[$key];
    }

    /**
     * Companies whose staff may be assigned to this application's tracker.
     * application_all_company=1 -> every active subsidiary; otherwise the tracker's own company.
     * Single source of truth for cross-company assignment scope.
     */
    public function getStaffScopeCompIds($applicationId, $compId)
    {
        if (!$this->isAllCompanyApp($applicationId)) return [(int)$compId];

        $ids = array_map('intval', DB::staff()
            ->query("SELECT id FROM subsidiaries WHERE weekly_report_status=1")
            ->fetchAll(PDO::FETCH_COLUMN));
        if (!in_array((int)$compId, $ids, true)) $ids[] = (int)$compId;
        return $ids;
    }

    /** Composite assignee identity. `person` is only unique within a company. */
    public static function staffKey($person, $compId)
    {
        return $person . '|' . (int)$compId;
    }

    /**
     * Collapse a task's categories into a unique person list for display.
     * Pure: no DB, operates on already-loaded categories.
     *
     * A person assigned to several categories is shown once, preferring a
     * non-programmer/owner category so their role reads as a tag rather than a
     * duplicate heading. Categories emptied by the dedupe are dropped entirely.
     *
     * Completion is uniform per person per task (markDone/unmarkDone update every
     * row for that person at once), so the surviving row's flag is authoritative.
     */
    public static function uniqueTeamByCategory($categories)
    {
        $roleCats = ['programmer', 'owner'];
        $isRoleCat = function ($cat) use ($roleCats) {
            return in_array(isset($cat['autofill']) ? $cat['autofill'] : null, $roleCats, true);
        };

        // Decide which category each person belongs to.
        $pick = [];
        foreach ($categories as $ci => $cat) {
            foreach ($cat['assignees'] as $a) {
                $k = self::staffKey($a['person'], $a['comp_id']);
                if (!isset($pick[$k])) {
                    $pick[$k] = $ci;
                } elseif ($isRoleCat($categories[$pick[$k]]) && !$isRoleCat($cat)) {
                    $pick[$k] = $ci; // non-role category wins; otherwise first seen wins
                }
            }
        }

        $out = [];
        foreach ($categories as $ci => $cat) {
            $rows = [];
            foreach ($cat['assignees'] as $a) {
                $k = self::staffKey($a['person'], $a['comp_id']);
                if ($pick[$k] !== $ci || isset($rows[$k])) continue;
                $rows[$k] = $a;
            }
            if (empty($rows)) continue;
            $cat['assignees'] = array_values($rows);
            $out[] = $cat;
        }
        return $out;
    }

    public function getStaffList($compIds)
    {
        $compIds = array_values(array_unique(array_map('intval', (array)$compIds)));
        if (empty($compIds)) throw new Exception("No company scope for staff list");
        $ph = implode(',', array_fill(0, count($compIds), '?'));
        $stmt = DB::staff()->prepare("
            SELECT u.id, u.person, u.comp_id, u.fullname, s.name AS company_name
            FROM users u
            LEFT JOIN subsidiaries s ON s.id = u.comp_id
            WHERE u.comp_id IN ({$ph}) AND u.status='1'
            ORDER BY s.name, u.fullname");
        $stmt->execute($compIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['fullname'] = self::formatName($row['fullname']);
            $row['key']      = self::staffKey($row['person'], $row['comp_id']);
        }
        return $rows;
    }

    private static function formatName($name)
    {
        return ucwords(strtolower(trim($name)));
    }

    /** Autofill sources, scoped to every company the app may draw staff from. Values are staffKey()s. */
    public function getAppRoles($applicationId, $compIds)
    {
        $compIds = array_values(array_unique(array_map('intval', (array)$compIds)));
        if (empty($compIds)) throw new Exception("No company scope for application roles");
        $ph     = implode(',', array_fill(0, count($compIds), '?'));
        $params = array_merge([$applicationId], $compIds);

        $roles  = ['owners' => [], 'programmers' => [], 'users' => []];
        $tables = ['owners' => 'application_owner', 'programmers' => 'application_developer', 'users' => 'application_user'];

        foreach ($tables as $key => $table) {
            $stmt = DB::app()->prepare("SELECT staff_id, comp_id FROM {$table} WHERE application_id=? AND comp_id IN ({$ph})");
            $stmt->execute($params);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($key === 'users' && $r['staff_id'] === 'all') {
                    foreach ($this->getStaffList([$r['comp_id']]) as $s) $roles['users'][] = $s['key'];
                } elseif (!empty($r['staff_id'])) {
                    $roles[$key][] = self::staffKey($r['staff_id'], $r['comp_id']);
                }
            }
        }
        foreach ($roles as &$list) $list = array_values(array_unique($list));
        return $roles;
    }

    /**
     * Application-level role map keyed by staffKey() => 'owner'|'programmer'.
     * Owner wins when a person holds both (owner is queried last, overwriting).
     * Comp-scoped because application_owner/application_developer are comp-scoped:
     * cross-company staff have no rows there and simply return unmapped.
     */
    public function getApplicationRoleMap($applicationId, $compId)
    {
        $map = [];
        $tables = ['programmer' => 'application_developer', 'owner' => 'application_owner'];
        foreach ($tables as $role => $table) {
            $stmt = DB::app()->prepare("SELECT staff_id, comp_id FROM {$table} WHERE application_id=? AND comp_id=?");
            $stmt->execute([$applicationId, (int)$compId]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (empty($r['staff_id']) || $r['staff_id'] === 'all') continue;
                $map[self::staffKey($r['staff_id'], $r['comp_id'])] = $role;
            }
        }
        return $map;
    }

    // ── Assignee scope enforcement ─────────────────────────
    // Assignee identity is (person, comp_id). Never trust the client's comp_id: every
    // write path validates the pair against the application's staff scope.

    /** {application_id, comp_id} for a tracker. */
    private function getTrackerScope($trackerId)
    {
        $stmt = DB::app()->prepare("SELECT application_id, comp_id FROM app_impl_tracker WHERE id=? AND deleted=0");
        $stmt->execute([$trackerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception("Tracker not found");
        return $row;
    }

    /** {application_id, comp_id} for the tracker owning a task. */
    private function getTrackerScopeByTask($trackerTaskId)
    {
        $stmt = DB::app()->prepare("SELECT t.application_id, t.comp_id FROM app_impl_tracker t JOIN app_impl_tracker_tasks tt ON tt.tracker_id=t.id WHERE tt.id=? AND t.deleted=0");
        $stmt->execute([$trackerTaskId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception("Tracker not found");
        return $row;
    }

    /** Flatten every {person, comp_id} pair out of a tasks payload. */
    private function collectTaskPersons(array $tasks)
    {
        $out = [];
        foreach ($tasks as $t) {
            foreach (isset($t['categories']) ? $t['categories'] : [] as $c) {
                foreach (isset($c['persons']) ? $c['persons'] : [] as $p) $out[] = $p;
            }
        }
        return $out;
    }

    /** Throws on the first pair outside the application's staff scope. */
    private function assertAssigneesInScope($applicationId, $compId, array $pairs)
    {
        if (empty($pairs)) return;
        $valid = [];
        foreach ($this->getStaffList($this->getStaffScopeCompIds($applicationId, $compId)) as $s) $valid[$s['key']] = true;
        foreach ($pairs as $p) {
            if (empty($p['person']) || empty($p['comp_id'])) throw new Exception("Assignee is missing person or company");
            if (!isset($valid[self::staffKey($p['person'], $p['comp_id'])]))
                throw new Exception("Staff \"{$p['person']}\" is not permitted on this application");
        }
    }

    // ── Task Masters ───────────────────────────────────────

    public function getTaskMasters()
    {
        $stmt = DB::app()->query("SELECT * FROM app_impl_task_master WHERE status=1 ORDER BY sort_order");
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmtC = DB::app()->prepare("SELECT * FROM app_impl_task_master_categories WHERE task_master_id=? ORDER BY sort_order, id");
        foreach ($tasks as &$t) {
            $stmtC->execute([$t['id']]);
            $t['default_categories'] = $stmtC->fetchAll(PDO::FETCH_ASSOC);
        }
        return $tasks;
    }

    // ── Applications ───────────────────────────────────────

    public function getApplications($compId)
    {
        $stmt = DB::app()->prepare("
            SELECT a.application_id, a.application_section, a.application_title,
                   (a.application_impl_id IS NOT NULL) AS has_impl
            FROM application a
            WHERE a.application_status='1'
              AND (
                a.application_all_company=1
                OR EXISTS (SELECT 1 FROM application_company ac WHERE ac.application_id=a.application_id AND ac.company_id=?)
              )
            ORDER BY a.application_section, a.application_title");
        $stmt->execute([$compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Trackers ───────────────────────────────────────────

    public function getTrackers($compIds, $filters, $person, $compId, $isAdmin)
    {
        $params = [];
        if ($isAdmin) {
            $ph = implode(',', array_fill(0, count($compIds), '?'));
            $sql = "SELECT DISTINCT t.*, a.application_title, a.application_section, a.application_all_company
                    FROM app_impl_tracker t JOIN application a ON a.application_id=t.application_id
                    WHERE t.comp_id IN ({$ph}) AND t.deleted=0";
            $params = $compIds;
        } else {
            $sql = "SELECT DISTINCT t.*, a.application_title, a.application_section, a.application_all_company
                    FROM app_impl_tracker t JOIN application a ON a.application_id=t.application_id
                    WHERE " . self::manageableTrackerSql();
            $params = [$compId, $person, $compId, $person, $compId];
        }
        if (!empty($filters['comp_id'])) {
            $sql .= " AND t.comp_id=?";
            $params[] = $filters['comp_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= " AND t.status=?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (a.application_title LIKE ? OR a.application_section LIKE ?)";
            $params[] = "%{$filters['search']}%";
            $params[] = "%{$filters['search']}%";
        }
        $sql .= " ORDER BY t.created_at DESC";
        $stmt = DB::app()->prepare($sql);
        $stmt->execute($params);
        $trackers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($trackers as &$tr) {
            $tr['company_name'] = $this->getTrackerCompanyLabel($tr);
            $tr['task_progress'] = $this->getTaskProgress($tr['id']);
        }
        return $trackers;
    }

    private function getTaskProgress($trackerId)
    {
        $stmt = DB::app()->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) AS completed FROM app_impl_tracker_tasks WHERE tracker_id=?");
        $stmt->execute([$trackerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getTrackerDetail($trackerId)
    {
        $stmt = DB::app()->prepare("SELECT t.*, a.application_title, a.application_section, a.application_all_company FROM app_impl_tracker t JOIN application a ON a.application_id=t.application_id WHERE t.id=? AND t.deleted=0");
        $stmt->execute([$trackerId]);
        $tracker = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tracker) throw new Exception("Tracker not found");
        $tracker['company_name'] = $this->getTrackerCompanyLabel($tracker);
        $tracker['tasks'] = $this->getTrackerTasks($trackerId);
        return $tracker;
    }

    public function getTrackerTasks($trackerId)
    {
        $stmt = DB::app()->prepare("SELECT * FROM app_impl_tracker_tasks WHERE tracker_id=? ORDER BY task_order");
        $stmt->execute([$trackerId]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tasks as &$task) {
            $task['categories'] = $this->getTaskCategories($task['id']);
            $task['comment_count'] = $this->getCommentCount($task['id']);
        }
        return $tasks;
    }

    private function getTaskCategories($trackerTaskId)
    {
        $stmt = DB::app()->prepare("
            SELECT tc.*, LOWER(TRIM(mc.autofill)) AS autofill
            FROM app_impl_tracker_task_categories tc
            LEFT JOIN app_impl_task_master_categories mc ON mc.id = tc.master_category_id
            WHERE tc.tracker_task_id=?
            ORDER BY tc.sort_order, tc.id");
        $stmt->execute([$trackerTaskId]);
        $cats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt2 = DB::app()->prepare("SELECT * FROM app_impl_tracker_task_assignees WHERE category_id=? ORDER BY created_at");
        foreach ($cats as &$cat) {
            $stmt2->execute([$cat['id']]);
            $cat['assignees'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cat['assignees'] as &$a) {
                $a['fullname']     = $this->getUserFullname($a['person'], $a['comp_id']);
                $a['company_name'] = $this->getCompanyName($a['comp_id']);
            }
        }
        return $cats;
    }

    // ── Create ─────────────────────────────────────────────

    public function createTracker($data, $currentUser)
    {
        $pdo = DB::app();
        $pdo->beginTransaction();
        try {
            if (empty($data['tasks'])) throw new Exception("At least one task is required");
            $this->assertAssigneesInScope($data['application_id'], $data['comp_id'], $this->collectTaskPersons($data['tasks']));
            $stmt = $pdo->prepare("INSERT INTO app_impl_tracker (application_id,comp_id,status,remarks,created_by,created_by_comp_id,created_at) VALUES (?,?,'in_progress',?,?,?,NOW())");
            $stmt->execute([$data['application_id'], $data['comp_id'], isset($data['remarks']) ? $data['remarks'] : '', $currentUser['person'], $currentUser['comp_id']]);
            $trackerId = $pdo->lastInsertId();

            // Claim the application atomically. `IS NULL` is the guard: exactly one create
            // can flip NULL -> trackerId. Any later attempt matches 0 rows and fails loud,
            // rolling back the tracker insert above.
            $claim = $pdo->prepare("UPDATE application SET application_impl_id=? WHERE application_id=? AND application_impl_id IS NULL");
            $claim->execute([$trackerId, $data['application_id']]);
            if ($claim->rowCount() === 0)
                throw new Exception("This application already has an implementation");

            foreach (array_values($data['tasks']) as $order => $task) {
                $this->insertTask($pdo, $trackerId, $order + 1, $task);
            }
            $pdo->commit();
            return ['success' => true, 'tracker_id' => $trackerId, 'message' => 'Implementation created'];
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // Single source of truth for materialising one task into a tracker.
    // Snapshots task_name/task_description onto the row; task_master_id is provenance only (nullable for custom tasks).
    private function insertTask($pdo, $trackerId, $order, $task)
    {
        $name = trim(isset($task['task_name']) ? $task['task_name'] : '');
        if ($name === '') throw new Exception("Each task needs a name");

        $sTask = $pdo->prepare("INSERT INTO app_impl_tracker_tasks (tracker_id,task_master_id,task_name,task_description,task_order,start_date,deadline,status,created_at) VALUES (?,?,?,?,?,?,?,'pending',NOW())");
        $sTask->execute([
            $trackerId,
            !empty($task['task_master_id']) ? $task['task_master_id'] : null,
            $name,
            isset($task['task_description']) ? $task['task_description'] : '',
            $order,
            !empty($task['start_date']) ? $task['start_date'] : null,
            !empty($task['deadline']) ? $task['deadline'] : null,
        ]);
        $taskId = $pdo->lastInsertId();

        $sCat   = $pdo->prepare("INSERT INTO app_impl_tracker_task_categories (tracker_task_id,category_name,master_category_id,sort_order,created_at) VALUES (?,?,?,?,NOW())");
        $sStaff = $pdo->prepare("INSERT INTO app_impl_tracker_task_assignees (category_id,tracker_task_id,person,comp_id,created_at) VALUES (?,?,?,?,NOW())");
        $hasStaff = false;
        foreach (array_values($task['categories']) as $ci => $cat) {
            if (empty($cat['persons'])) continue;
            $hasStaff = true;
            $sCat->execute([$taskId, $cat['name'], !empty($cat['master_category_id']) ? $cat['master_category_id'] : null, $ci]);
            $catId = $pdo->lastInsertId();
            foreach ($cat['persons'] as $p) $sStaff->execute([$catId, $taskId, $p['person'], $p['comp_id']]);
        }
        if (!$hasStaff) throw new Exception("Task \"{$name}\" needs at least one staff member");
        return $taskId;
    }

    // Append a single task to an existing tracker. Reopens a completed tracker.
    public function addTask($trackerId, $task)
    {
        $scope = $this->getTrackerScope($trackerId);
        $this->assertAssigneesInScope($scope['application_id'], $scope['comp_id'], $this->collectTaskPersons([$task]));

        $pdo = DB::app();
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(task_order),0)+1 AS n FROM app_impl_tracker_tasks WHERE tracker_id=?");
        $stmt->execute([$trackerId]);
        $order = (int)$stmt->fetch(PDO::FETCH_ASSOC)['n'];

        $pdo->beginTransaction();
        try {
            $taskId = $this->insertTask($pdo, $trackerId, $order, $task);
            $pdo->prepare("UPDATE app_impl_tracker SET status='in_progress', updated_at=NOW() WHERE id=? AND status='completed'")->execute([$trackerId]);
            $pdo->commit();
            return ['success' => true, 'tracker_task_id' => $taskId, 'message' => 'Task added'];
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // Contiguous renumber. Submitted id set must exactly match the tracker's tasks (fail loud on drift).
    public function reorderTasks($trackerId, $orderedIds)
    {
        $pdo = DB::app();
        $stmt = $pdo->prepare("SELECT id FROM app_impl_tracker_tasks WHERE tracker_id=?");
        $stmt->execute([$trackerId]);
        $existing = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $ordered  = array_map('intval', (array)$orderedIds);

        $a = $existing; $b = $ordered; sort($a); sort($b);
        if ($a !== $b) throw new Exception("Task list is out of sync; reload and try again");

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("UPDATE app_impl_tracker_tasks SET task_order=? WHERE id=? AND tracker_id=?");
            foreach ($ordered as $i => $id) $upd->execute([$i + 1, $id, $trackerId]);
            $pdo->commit();
            return ['success' => true, 'message' => 'Order updated'];
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function deleteTracker($trackerId, $currentUser)
    {
        $pdo = DB::app();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE app_impl_tracker SET deleted=1, updated_by=?, updated_at=NOW() WHERE id=? AND deleted=0");
            $stmt->execute([$currentUser['person'], $trackerId]);
            if ($stmt->rowCount() === 0) throw new Exception("Not found");
            // Release the application; guarded by =? so we never clobber another app's pointer.
            $pdo->prepare("UPDATE application SET application_impl_id=NULL WHERE application_impl_id=?")->execute([$trackerId]);
            $pdo->commit();
            return ['success' => true, 'message' => 'Deleted'];
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function updateTrackerStatus($trackerId, $status, $currentUser)
    {
        DB::app()->prepare("UPDATE app_impl_tracker SET status=?, updated_by=?, updated_at=NOW() WHERE id=? AND deleted=0")->execute([$status, $currentUser['person'], $trackerId]);
        return ['success' => true, 'message' => 'Status updated'];
    }

    // ── Mark Done ──────────────────────────────────────────

    public function markDone($trackerTaskId, $person, $compId)
    {
        $pdo = DB::app();
        $stmt = $pdo->prepare("SELECT id, completed FROM app_impl_tracker_task_assignees WHERE tracker_task_id=? AND person=? AND comp_id=?");
        $stmt->execute([$trackerTaskId, $person, $compId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) throw new Exception("You are not assigned to this task");
        $pending = false;
        foreach ($rows as $r) {
            if (!$r['completed']) $pending = true;
        }
        if (!$pending) throw new Exception("Already marked as done");
        $task = $this->getTaskById($trackerTaskId);
        if ($task['status'] === 'done') throw new Exception("Task already completed");
        $pdo->prepare("UPDATE app_impl_tracker_task_assignees SET completed=1, completed_at=NOW() WHERE tracker_task_id=? AND person=? AND comp_id=? AND completed=0")->execute([$trackerTaskId, $person, $compId]);
        $this->checkTaskCompletion($trackerTaskId);
        return ['success' => true, 'message' => 'Marked as done'];
    }

    private function getTaskById($id)
    {
        $stmt = DB::app()->prepare("SELECT * FROM app_impl_tracker_tasks WHERE id=?");
        $stmt->execute([$id]);
        $t = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$t) throw new Exception("Task not found");
        return $t;
    }

    private function checkTaskCompletion($trackerTaskId)
    {
        $pdo = DB::app();
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(completed) AS done_count FROM app_impl_tracker_task_assignees WHERE tracker_task_id=?");
        $stmt->execute([$trackerTaskId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ((int)$r['total'] > 0 && (int)$r['total'] === (int)$r['done_count']) {
            $pdo->prepare("UPDATE app_impl_tracker_tasks SET status='done', done_at=NOW() WHERE id=?")->execute([$trackerTaskId]);
            $task = $this->getTaskById($trackerTaskId);
            $this->checkTrackerCompletion($task['tracker_id']);
        }
    }

    private function checkTrackerCompletion($trackerId)
    {
        $pdo = DB::app();
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) AS done_count FROM app_impl_tracker_tasks WHERE tracker_id=?");
        $stmt->execute([$trackerId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ((int)$r['total'] > 0 && (int)$r['total'] === (int)$r['done_count'])
            $pdo->prepare("UPDATE app_impl_tracker SET status='completed', updated_at=NOW() WHERE id=?")->execute([$trackerId]);
    }

    public function unmarkDone($trackerTaskId, $person, $compId)
    {
        $pdo = DB::app();
        $stmt = $pdo->prepare("SELECT id, completed FROM app_impl_tracker_task_assignees WHERE tracker_task_id=? AND person=? AND comp_id=?");
        $stmt->execute([$trackerTaskId, $person, $compId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) throw new Exception("You are not assigned to this task");
        $hasDone = false;
        foreach ($rows as $r) { if ($r['completed']) $hasDone = true; }
        if (!$hasDone) throw new Exception("Not marked as done yet");
        $pdo->prepare("UPDATE app_impl_tracker_task_assignees SET completed=0, completed_at=NULL WHERE tracker_task_id=? AND person=? AND comp_id=? AND completed=1")->execute([$trackerTaskId, $person, $compId]);
        $pdo->prepare("UPDATE app_impl_tracker_tasks SET status='pending', done_at=NULL WHERE id=?")->execute([$trackerTaskId]);
        $task = $this->getTaskById($trackerTaskId);
        $pdo->prepare("UPDATE app_impl_tracker SET status='in_progress', updated_at=NOW() WHERE id=? AND status='completed'")->execute([$task['tracker_id']]);
        return ['success' => true, 'message' => 'Marked as undone'];
    }

    // ── Category & Staff ───────────────────────────────────

    public function addCategory($trackerTaskId, $categoryName)
    {
        $pdo = DB::app();
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM app_impl_tracker_task_categories WHERE tracker_task_id=?");
        $stmt->execute([$trackerTaskId]);
        $n = (int)$stmt->fetch(PDO::FETCH_ASSOC)['n'];
        $pdo->prepare("INSERT INTO app_impl_tracker_task_categories (tracker_task_id,category_name,master_category_id,sort_order,created_at) VALUES (?,?,NULL,?,NOW())")->execute([$trackerTaskId, $categoryName, $n]);
        return ['success' => true, 'category_id' => $pdo->lastInsertId(), 'message' => 'Category added'];
    }

    public function removeCategory($categoryId)
    {
        $pdo = DB::app();
        // Block removal of fixed categories
        $stmt = $pdo->prepare("SELECT master_category_id FROM app_impl_tracker_task_categories WHERE id=?");
        $stmt->execute([$categoryId]);
        $cat = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cat && $cat['master_category_id']) throw new Exception("Cannot remove a default category");
        $pdo->prepare("DELETE FROM app_impl_tracker_task_assignees WHERE category_id=?")->execute([$categoryId]);
        $pdo->prepare("DELETE FROM app_impl_tracker_task_categories WHERE id=?")->execute([$categoryId]);
        return ['success' => true, 'message' => 'Category removed'];
    }

    public function addStaff($categoryId, $trackerTaskId, $person, $compId)
    {
        $scope = $this->getTrackerScopeByTask($trackerTaskId);
        $this->assertAssigneesInScope($scope['application_id'], $scope['comp_id'], [['person' => $person, 'comp_id' => $compId]]);

        $stmt = DB::app()->prepare("SELECT id FROM app_impl_tracker_task_assignees WHERE category_id=? AND person=? AND comp_id=?");
        $stmt->execute([$categoryId, $person, $compId]);
        if ($stmt->fetch()) throw new Exception("Already assigned");
        DB::app()->prepare("INSERT INTO app_impl_tracker_task_assignees (category_id,tracker_task_id,person,comp_id,created_at) VALUES (?,?,?,?,NOW())")->execute([$categoryId, $trackerTaskId, $person, $compId]);
        return ['success' => true, 'message' => 'Staff added'];
    }

    public function removeStaff($assigneeId)
    {
        DB::app()->prepare("DELETE FROM app_impl_tracker_task_assignees WHERE id=?")->execute([$assigneeId]);
        return ['success' => true, 'message' => 'Staff removed'];
    }

    // ── Comments ───────────────────────────────────────────

    public function getCommentCount($trackerTaskId)
    {
        $stmt = DB::app()->prepare("SELECT COUNT(*) AS cnt FROM app_impl_tracker_task_comments WHERE tracker_task_id=? AND deleted=0");
        $stmt->execute([$trackerTaskId]);
        return (int)$stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    }

    public function getComments($trackerTaskId)
    {
        $stmt = DB::app()->prepare("SELECT * FROM app_impl_tracker_task_comments WHERE tracker_task_id=? AND deleted=0 ORDER BY created_at ASC");
        $stmt->execute([$trackerTaskId]);
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $byId = [];
        $roots = [];
        foreach ($all as &$c) {
            $c['fullname'] = $this->getUserFullname($c['person'], $c['comp_id']);
            $c['replies'] = [];
            $c['parent_id'] = isset($c['parent_id']) ? $c['parent_id'] : null;
            $byId[$c['id']] = &$c;
        }
        unset($c);
        foreach ($byId as &$c) {
            if ($c['parent_id'] && isset($byId[$c['parent_id']])) {
                $byId[$c['parent_id']]['replies'][] = &$c;
            } else {
                $roots[] = &$c;
            }
        }
        unset($c);
        foreach ($roots as &$r) {
            $r['done_fullname'] = (!empty($r['done_status']) && $r['done_person'])
                ? $this->getUserFullname($r['done_person'], $r['done_comp_id'])
                : null;
        }
        unset($r);
        return $roots;
    }

    public function addComment($trackerTaskId, $person, $compId, $comment, $parentId = null)
    {
        $comment = trim($comment);
        if ($comment === '') throw new Exception("Comment cannot be empty");
        if ($parentId) {
            $chk = DB::app()->prepare("SELECT id FROM app_impl_tracker_task_comments WHERE id=? AND tracker_task_id=? AND deleted=0");
            $chk->execute([$parentId, $trackerTaskId]);
            if (!$chk->fetch()) throw new Exception("Parent comment not found");
        }
        DB::app()->prepare("INSERT INTO app_impl_tracker_task_comments (tracker_task_id,parent_id,person,comp_id,comment,created_at) VALUES (?,?,?,?,?,NOW())")
            ->execute([$trackerTaskId, $parentId, $person, $compId, $comment]);
        return ['success' => true, 'message' => 'Comment added', 'comment_id' => DB::app()->lastInsertId()];
    }

    public function deleteComment($commentId, $person, $compId, $isAdmin)
    {
        $stmt = DB::app()->prepare("SELECT person, comp_id FROM app_impl_tracker_task_comments WHERE id=? AND deleted=0");
        $stmt->execute([$commentId]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$c) throw new Exception("Comment not found");
        if (!$isAdmin && ($c['person'] !== $person || (int)$c['comp_id'] !== (int)$compId))
            throw new Exception("You can only delete your own comments");
        DB::app()->prepare("UPDATE app_impl_tracker_task_comments SET deleted=1 WHERE id=?")->execute([$commentId]);
        return ['success' => true, 'message' => 'Comment deleted'];
    }

    public function toggleCommentDone($commentId, $person, $compId)
    {
        $pdo = DB::app();
        $stmt = $pdo->prepare("SELECT id, parent_id, done_status FROM app_impl_tracker_task_comments WHERE id=? AND deleted=0");
        $stmt->execute([$commentId]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$c) throw new Exception("Comment not found");
        if ($c['parent_id']) throw new Exception("Only top-level comments can be marked done");
        $newStatus = (int)$c['done_status'] ? 0 : 1;
        if ($newStatus) {
            $pdo->prepare("UPDATE app_impl_tracker_task_comments SET done_status=1, done_person=?, done_comp_id=?, done_at=NOW() WHERE id=?")
                ->execute([$person, $compId, $commentId]);
        } else {
            $pdo->prepare("UPDATE app_impl_tracker_task_comments SET done_status=0, done_person=NULL, done_comp_id=NULL, done_at=NULL WHERE id=?")
                ->execute([$commentId]);
        }
        return ['success' => true, 'message' => $newStatus ? 'Marked as resolved' : 'Marked as unresolved'];
    }

    // ── My Tasks ───────────────────────────────────────────

    public function isAppOwnerOrDev($person)
    {
        $stmt = DB::app()->prepare("SELECT 1 FROM application_owner WHERE staff_id=? LIMIT 1");
        $stmt->execute([$person]);
        if ($stmt->fetch()) return true;
        $stmt = DB::app()->prepare("SELECT 1 FROM application_developer WHERE staff_id=? LIMIT 1");
        $stmt->execute([$person]);
        return (bool)$stmt->fetch();
    }

    /** Drives the Management + Management Report tab buttons. */
    public function hasManageableTrackers($person, $compId)
    {
        $stmt = DB::app()->prepare("
            SELECT 1
            FROM app_impl_tracker t
            JOIN application a ON a.application_id=t.application_id
            WHERE " . self::manageableTrackerSql() . "
            LIMIT 1
        ");
        $stmt->execute([$compId, $person, $compId, $person, $compId]);
        return (bool)$stmt->fetch();
    }

    public function getMyTasks($person, $compId)
    {
        $stmt = DB::app()->prepare("
            SELECT ta.id AS assignee_id, ta.completed, ta.completed_at, ta.category_id,
                   tt.id AS task_id, tt.status AS task_status, tt.start_date, tt.deadline, tt.task_order,
                   tm.task_name, tm.task_description,
                   tc.category_name,
                   t.id AS tracker_id, t.status AS tracker_status,
                   a.application_title, a.application_section
            FROM app_impl_tracker_task_assignees ta
            JOIN app_impl_tracker_tasks tt ON tt.id = ta.tracker_task_id
            JOIN app_impl_task_master tm ON tm.id = tt.task_master_id
            JOIN app_impl_tracker_task_categories tc ON tc.id = ta.category_id
            JOIN app_impl_tracker t ON t.id = tt.tracker_id
            JOIN application a ON a.application_id = t.application_id
            WHERE ta.person=? AND ta.comp_id=? AND t.deleted=0
            ORDER BY ta.completed ASC, tt.deadline IS NULL ASC, tt.deadline ASC, t.created_at DESC, tt.task_order ASC
        ");
        $stmt->execute([$person, $compId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $trackers = [];
        foreach ($rows as $row) {
            $tid = $row['tracker_id'];
            if (!isset($trackers[$tid])) {
                $trackers[$tid] = [
                    'tracker_id' => $tid,
                    'tracker_status' => $row['tracker_status'],
                    'application_title' => $row['application_title'],
                    'application_section' => $row['application_section'],
                    'tasks' => []
                ];
            }
            $taskId = $row['task_id'];
            if (!isset($trackers[$tid]['tasks'][$taskId])) {
                $trackers[$tid]['tasks'][$taskId] = [
                    'task_id' => $taskId,
                    'task_name' => $row['task_name'],
                    'task_description' => $row['task_description'],
                    'task_status' => $row['task_status'],
                    'task_order' => $row['task_order'],
                    'start_date' => $row['start_date'],
                    'deadline' => $row['deadline'],
                    'assignments' => []
                ];
            }
            $trackers[$tid]['tasks'][$taskId]['assignments'][] = [
                'assignee_id' => $row['assignee_id'],
                'category_name' => $row['category_name'],
                'completed' => (int)$row['completed'] === 1,
                'completed_at' => $row['completed_at']
            ];
        }

        $result = [];
        foreach ($trackers as $t) {
            $t['tasks'] = array_values($t['tasks']);
            $result[] = $t;
        }
        return $result;
    }

    // ── My App Listing ─────────────────────────────────────

    public function getMyAppListing($person, $compId, $isAdmin)
    {
        // can_manage_impl mirrors canManageApp() in SQL: admin OR owner OR developer.
        // $isAdmin is a bool cast to an int literal — safe to inline.
        $adminFlag = (int)$isAdmin;
        $stmt = DB::app()->prepare("
            SELECT DISTINCT a.application_id, a.application_section, a.application_title, a.application_remarks, a.application_code,
                   a.application_impl_id,
                   CASE
                     WHEN {$adminFlag} = 1 THEN 1
                     WHEN EXISTS (SELECT 1 FROM application_owner ao2 WHERE ao2.application_id = a.application_id AND ao2.comp_id = ? AND ao2.staff_id = ?)
                       OR EXISTS (SELECT 1 FROM application_developer ad2 WHERE ad2.application_id = a.application_id AND ad2.comp_id = ? AND ad2.staff_id = ?)
                     THEN 1 ELSE 0
                   END AS can_manage_impl
            FROM application a
            WHERE a.application_status = '1'
              -- Company scope: registered for this company, OR flagged for every company.
              AND (
                a.application_all_company = 1
                OR EXISTS (SELECT 1 FROM application_company ac WHERE ac.application_id = a.application_id AND ac.company_id = ?)
              )
              AND (
                EXISTS (SELECT 1 FROM application_owner ao WHERE ao.application_id = a.application_id AND ao.comp_id = ? AND ao.staff_id = ?)
                OR EXISTS (SELECT 1 FROM application_developer ad WHERE ad.application_id = a.application_id AND ad.comp_id = ? AND ad.staff_id = ?)
                OR EXISTS (SELECT 1 FROM application_user au WHERE au.application_id = a.application_id AND au.comp_id = ? AND (au.staff_id = ? OR au.staff_id = 'all'))
                -- Any tracker assignment on this app earns the card, whatever the category.
                -- Cross-company staff have no application_owner/developer/user rows in their
                -- own company, so this is their only route in.
                OR EXISTS (
                    SELECT 1
                    FROM app_impl_tracker t
                    JOIN app_impl_tracker_tasks tt           ON tt.tracker_id = t.id
                    JOIN app_impl_tracker_task_categories tc ON tc.tracker_task_id = tt.id
                    JOIN app_impl_tracker_task_assignees ta  ON ta.category_id = tc.id
                    WHERE t.application_id = a.application_id AND t.deleted = 0
                      AND ta.person = ? AND ta.comp_id = ?
                )
              )
            ORDER BY a.application_section, a.application_title
        ");
        $stmt->execute([$compId, $person, $compId, $person, $compId, $compId, $person, $compId, $person, $compId, $person, $person, $compId]);
        $apps = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Attach a compact listing-card status for apps whose provider exposes one.
        // Non-fatal: a broken provider yields null, never breaks the listing.
        require_once __DIR__ . '/app_details/AppDetailProvider.php';
        foreach ($apps as &$app) {
            $app['can_manage_impl'] = ((int)$app['can_manage_impl'] === 1);
            $code = trim((string)$app['application_code']);
            $app['listing_status'] = $code === '' ? null : AppDetail::listingStatus($code, [
                'application_id' => $app['application_id'],
                'comp_id'        => $compId,
                'person'         => $person,
                'app_title'      => $app['application_title'],
                'app_section'    => $app['application_section'],
                'app_remarks'    => $app['application_remarks'],
            ]);
        }
        unset($app);
        return $apps;
    }

    public function getAppDetail($appId, array $currentUser)
    {
        $stmt = DB::app()->prepare("SELECT application_id, application_title, application_section, application_remarks, application_code FROM application WHERE application_id=? AND application_status='1' LIMIT 1");
        $stmt->execute([$appId]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$app) throw new Exception('Application not found.');

        // No slug configured -> generic remarks view (preserves current behaviour).
        if (trim((string)$app['application_code']) === '') {
            return ['success' => true, 'custom' => false, 'app' => [
                'application_title'   => $app['application_title'],
                'application_section' => $app['application_section'],
                'application_remarks' => $app['application_remarks'],
            ]];
        }

        require_once __DIR__ . '/app_details/AppDetailProvider.php';
        $html = AppDetail::resolve($app['application_code'])->render([
            'application_id' => $app['application_id'],
            'comp_id'        => $currentUser['comp_id'],
            'person'         => $currentUser['person'],
            'app_title'      => $app['application_title'],
            'app_section'    => $app['application_section'],
            'app_remarks'    => $app['application_remarks'],
        ]);
        return ['success' => true, 'custom' => true, 'title' => $app['application_title'], 'html' => $html];
    }

    // == Management Report ==================================

    /**
     * Apps that opted into management reports (application_management_report='1'),
     * scoped exactly like the Management tab: admin -> all accessible companies;
     * otherwise apps the user owns or develops in their own company.
     * Flags: has_impl (a tracker exists), has_usage (usage report enabled).
     */
    public function getManagementReportApps($compIds, $person, $compId, $isAdmin)
    {
        $cols = "a.application_id, a.application_section, a.application_title, a.application_code,
                 a.application_impl_id,
                 (a.application_impl_id IS NOT NULL) AS has_impl,
                 (a.application_usage_report='1')    AS has_usage";
        if ($isAdmin) {
            if (empty($compIds)) return [];
            $ph  = implode(',', array_fill(0, count($compIds), '?'));
            $sql = "SELECT DISTINCT {$cols}
                    FROM application a
                    WHERE a.application_status='1' AND a.application_management_report='1'
                      AND (
                        a.application_all_company=1
                        OR EXISTS (SELECT 1 FROM application_company ac WHERE ac.application_id=a.application_id AND ac.company_id IN ({$ph}))
                      )
                    ORDER BY a.application_section, a.application_title";
            $params = $compIds;
        } else {
            $sql = "SELECT DISTINCT {$cols}
                    FROM application a
                    WHERE a.application_status='1' AND a.application_management_report='1'
                      AND (
                        a.application_all_company=1
                        OR EXISTS (SELECT 1 FROM application_company ac WHERE ac.application_id=a.application_id AND ac.company_id=?)
                      )
                      AND (
                        EXISTS (SELECT 1 FROM application_owner ao WHERE ao.application_id=a.application_id AND ao.staff_id=?
                                 AND (a.application_all_company=1 OR ao.comp_id=?))
                        OR EXISTS (SELECT 1 FROM application_developer ad WHERE ad.application_id=a.application_id AND ad.staff_id=?
                                 AND (a.application_all_company=1 OR ad.comp_id=?))
                      )
                    ORDER BY a.application_section, a.application_title";
            $params = [$compId, $person, $compId, $person, $compId];
        }
        $stmt = DB::app()->prepare($sql);
        $stmt->execute($params);
        $apps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($apps as &$a) {
            $a['has_impl']  = (int)$a['has_impl'] === 1;
            $a['has_usage'] = (int)$a['has_usage'] === 1;
        }
        unset($a);
        return $apps;
    }

    /** Owner/developer (or admin) check keyed on the current user's company. */
    public function canManageApp($person, $applicationId, $compId, $isAdmin)
    {
        return $this->canManage($person, $applicationId, $compId, $isAdmin);
    }

    // ── Reference Materials ────────────────────────────────
    // Scope: keyed on application_id (shared across every company that has the app).
    // View: any logged-in user who can reach this page. Upload/Delete: admin OR
    // application_owner OR application_developer OR anyone assigned to a PROGRAMMER/
    // OWNER category on this app's implementation tracker (see canUploadReferenceMaterial).

    /**
     * Upload/delete gate for reference materials. Wider than canManageApp(): it also
     * lets the actual PROGRAMMER/OWNER assignees on the app's tracker upload, since
     * those lists can be edited on the tracker independently of the app-role tables.
     * Kept separate from canManageApp() so tracker create/delete permissions stay narrow.
     */
    public function canUploadReferenceMaterial($person, $applicationId, $compId, $isAdmin)
    {
        if ($this->canManageApp($person, $applicationId, $compId, $isAdmin)) return true;
        return $this->isTrackerProgrammerOrOwner($person, $compId, $applicationId);
    }

    /** True if $person is an assignee in a programmer/owner category of the app's live tracker. */
    private function isTrackerProgrammerOrOwner($person, $compId, $applicationId)
    {
        $stmt = DB::app()->prepare("
            SELECT 1
            FROM app_impl_tracker t
            JOIN app_impl_tracker_tasks tt          ON tt.tracker_id = t.id
            JOIN app_impl_tracker_task_categories tc ON tc.tracker_task_id = tt.id
            JOIN app_impl_task_master_categories mc  ON mc.id = tc.master_category_id
            JOIN app_impl_tracker_task_assignees ta  ON ta.category_id = tc.id
            WHERE t.application_id = ? AND t.deleted = 0
              AND LOWER(TRIM(mc.autofill)) IN ('programmer','owner')
              AND ta.person = ? AND ta.comp_id = ?
            LIMIT 1");
        $stmt->execute([$applicationId, $person, $compId]);
        return (bool)$stmt->fetch();
    }

    private static function refDir()
    {
        return __DIR__ . '/reference_materials';
    }

    /** Extension whitelist -> canonical MIME we control (never trust the browser's). */
    private static function refAllowedExt()
    {
        return [
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt'  => 'text/plain',
            'csv'  => 'text/csv',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'zip'  => 'application/zip',
            'mp4'  => 'video/mp4',
        ];
    }

    const REF_MAX_BYTES = 20971520; // 20 MB

    private static function ensureRefDirProtected($dir)
    {
        // Files are only ever served through streamReferenceMaterial() with a forced
        // download; this .htaccess is defence-in-depth so the raw dir isn't browsable.
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
    }

    private static function sanitizeDownloadName($name)
    {
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '', (string)$name);
        return $name === '' ? 'download' : $name;
    }

    /** Query: list + whether the caller may upload. */
    public function getReferenceMaterials($appId, $person, $compId, $isAdmin)
    {
        $stmt = DB::app()->prepare(
            "SELECT id, application_id, title, description, file_name, mime_type, file_size,
                    uploaded_by, uploaded_by_comp_id, created_at
             FROM app_reference_material
             WHERE application_id=? AND deleted=0
             ORDER BY created_at DESC, id DESC");
        $stmt->execute([$appId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['file_size']        = (int)$r['file_size'];
            $r['uploaded_by_name'] = $this->getUserFullname($r['uploaded_by'], $r['uploaded_by_comp_id']);
        }
        unset($r);
        return [
            'success'    => true,
            'materials'  => $rows,
            'can_upload' => $this->canUploadReferenceMaterial($person, $appId, $compId, $isAdmin),
        ];
    }

    /** Command: validate, store the file, insert the row. */
    public function uploadReferenceMaterial($appId, $title, $description, $file, $person, $compId, $isAdmin)
    {
        if (!$this->canUploadReferenceMaterial($person, $appId, $compId, $isAdmin))
            throw new Exception("You do not have permission to upload reference materials for this application.");

        $chk = DB::app()->prepare("SELECT 1 FROM application WHERE application_id=? AND application_status='1' LIMIT 1");
        $chk->execute([$appId]);
        if (!$chk->fetch()) throw new Exception("Application not found.");

        $title = trim((string)$title);
        if ($title === '') throw new Exception("Title is required.");

        if (!is_array($file) || !isset($file['error']) || is_array($file['error']))
            throw new Exception("No file was uploaded.");
        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new Exception("File exceeds the maximum allowed size.");
            case UPLOAD_ERR_NO_FILE:
                throw new Exception("No file was uploaded.");
            default:
                throw new Exception("Upload failed (code {$file['error']}).");
        }
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name']))
            throw new Exception("Invalid upload source.");
        if ($file['size'] <= 0) throw new Exception("Uploaded file is empty.");
        if ($file['size'] > self::REF_MAX_BYTES) throw new Exception("File exceeds the 20 MB limit.");

        $orig    = preg_replace('/[\x00-\x1F\x7F]/', '', (string)$file['name']);
        $ext     = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $allowed = self::refAllowedExt();
        if (!isset($allowed[$ext])) throw new Exception("File type \".{$ext}\" is not allowed.");
        $mime = $allowed[$ext];

        $dir = self::refDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir))
            throw new Exception("Storage directory could not be created.");
        self::ensureRefDirProtected($dir);

        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest   = $dir . '/' . $stored;
        if (!move_uploaded_file($file['tmp_name'], $dest))
            throw new Exception("Failed to store the uploaded file.");

        try {
            $ins = DB::app()->prepare(
                "INSERT INTO app_reference_material
                 (application_id,title,description,file_name,stored_name,mime_type,file_size,uploaded_by,uploaded_by_comp_id,created_at,deleted)
                 VALUES (?,?,?,?,?,?,?,?,?,NOW(),0)");
            $ins->execute([$appId, $title, trim((string)$description), $orig, $stored, $mime, (int)$file['size'], $person, $compId]);
        } catch (Exception $e) {
            @unlink($dest); // never leave an orphan file if the row insert fails
            throw $e;
        }
        return ['success' => true, 'message' => 'Reference material uploaded.', 'id' => DB::app()->lastInsertId()];
    }

    /** Command: soft-delete the row and remove the physical file. */
    public function deleteReferenceMaterial($materialId, $person, $compId, $isAdmin)
    {
        $stmt = DB::app()->prepare("SELECT application_id, stored_name FROM app_reference_material WHERE id=? AND deleted=0");
        $stmt->execute([$materialId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception("Reference material not found.");
        if (!$this->canUploadReferenceMaterial($person, $row['application_id'], $compId, $isAdmin))
            throw new Exception("You do not have permission to delete this reference material.");

        DB::app()->prepare("UPDATE app_reference_material SET deleted=1 WHERE id=?")->execute([$materialId]);
        $path = self::refDir() . '/' . basename($row['stored_name']);
        if (is_file($path)) @unlink($path);
        return ['success' => true, 'message' => 'Reference material deleted.'];
    }

    /** Streams the file as an attachment. Sends its own headers; must run before the JSON header. */
    public function streamReferenceMaterial($materialId)
    {
        $stmt = DB::app()->prepare("SELECT file_name, stored_name, mime_type, file_size FROM app_reference_material WHERE id=? AND deleted=0");
        $stmt->execute([$materialId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        while (ob_get_level() > 0) ob_end_clean();
        if (!$row) { http_response_code(404); header('Content-Type: text/plain'); echo 'Not found'; return; }
        $path = self::refDir() . '/' . basename($row['stored_name']);
        if (!is_file($path)) { http_response_code(404); header('Content-Type: text/plain'); echo 'File missing'; return; }
        header('Content-Type: ' . $row['mime_type']);
        header('Content-Disposition: attachment; filename="' . self::sanitizeDownloadName($row['file_name']) . '"');
        header('Content-Length: ' . (int)$row['file_size']);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
    }

    /** Implementation Report: uniform across all apps, so a single shared renderer (not a provider). */
    public function getImplementationReport($appId, $person, $compId, $isAdmin)
    {
        $stmt = DB::app()->prepare("SELECT application_id, application_title, application_impl_id, application_management_report FROM application WHERE application_id=? AND application_status='1' LIMIT 1");
        $stmt->execute([$appId]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$app) throw new Exception('Application not found.');
        if ($app['application_management_report'] !== '1') throw new Exception('Management report not enabled for this application.');
        if (empty($app['application_impl_id'])) throw new Exception('No implementation record for this application.');

        $tracker = $this->getTrackerDetail($app['application_impl_id']);
        if (!$this->canManage($person, $tracker['application_id'], $tracker['comp_id'], $isAdmin))
            throw new Exception('You do not have permission to view this report.');

        return ['success' => true, 'title' => $app['application_title'], 'html' => $this->renderImplementationReport($tracker)];
    }

    private function renderImplementationReport(array $tracker)
    {
        $tasks = $tracker['tasks'];
        $total = count($tasks);
        $done  = 0;
        $asgDone  = 0;
        $asgTotal = 0;
        $progress = [];
        foreach ($tasks as $i => $t) {
            if ($t['status'] === 'done') $done++;
            $p = $this->implTaskProgress($t);
            $progress[$i] = $p;
            $asgDone  += $p['done'];
            $asgTotal += $p['total'];
        }
        $pct    = $total ? round($done / $total * 100) : 0;
        $asgPct = $asgTotal ? round($asgDone / $asgTotal * 100) : 0;

        $statusLabel = ucwords(str_replace('_', ' ', $tracker['status']));
        $created     = !empty($tracker['created_at']) ? date('d M Y', strtotime($tracker['created_at'])) : '-';
        $generated   = date('d M Y, g:i A');

        $h  = '<div style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:1rem">';
        $h .=   '<div style="background:linear-gradient(135deg,#0f172a,#1e3a5f);color:#fff;padding:1rem 1.25rem">';
        $h .=     '<div style="font-size:1.05rem;font-weight:700">' . htmlspecialchars($tracker['application_title']) . '</div>';
        $h .=     '<div style="font-size:.78rem;color:#cbd5e1;margin-top:.15rem">' . htmlspecialchars($tracker['application_section']) . ' &middot; ' . htmlspecialchars($tracker['company_name']) . '</div>';
        $h .=   '</div>';
        $h .=   '<div style="display:flex;flex-wrap:wrap;gap:1.25rem;padding:.85rem 1.25rem;background:#f8fafc;font-size:.78rem;color:#475569">';
        $h .=     '<div><span style="color:#94a3b8">Status</span><br><b>' . htmlspecialchars($statusLabel) . '</b></div>';
        $h .=     '<div><span style="color:#94a3b8">Progress</span><br><b>' . $done . ' / ' . $total . ' tasks (' . $pct . '%)</b></div>';
        $h .=     '<div><span style="color:#94a3b8">Started</span><br><b>' . htmlspecialchars($created) . '</b></div>';
        $h .=     '<div style="min-width:200px"><span style="color:#94a3b8">Overall Progress</span>'
            . '<div style="display:flex;align-items:center;gap:.5rem;margin-top:.3rem">'
            . '<div style="flex:1;min-width:90px;height:7px;background:#e2e8f0;border-radius:999px;overflow:hidden">'
            . '<div style="height:100%;width:' . $asgPct . '%;background:linear-gradient(90deg,#10b981,#059669)"></div></div>'
            . '<b style="white-space:nowrap">' . $asgDone . ' / ' . $asgTotal . ' (' . $asgPct . '%)</b></div></div>';
        $h .=     '<div style="margin-left:auto"><span style="color:#94a3b8">Generated</span><br><b>' . htmlspecialchars($generated) . '</b></div>';
        $h .=   '</div>';
        $h .= '</div>';

        if (empty($tasks)) {
            return $h . '<div style="font-size:.85rem;color:#94a3b8;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.9rem;text-align:center">No tasks recorded for this implementation.</div>';
        }

        $rows = '';
        $details = '';
        foreach ($tasks as $i => $t) {
            $rows    .= $this->renderImplTaskRow($i + 1, $t, $progress[$i]);
            $details .= $this->renderImplProgressData($i + 1, $t, $progress[$i]);
        }

        return $h
            . '<div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:8px">'
            . '<table style="border-collapse:collapse;width:100%;font-size:.8rem">'
            . '<thead><tr style="background:#f8fafc;color:#475569;text-align:left">'
            . '<th style="padding:.55rem .6rem;width:34px;border-bottom:1px solid #e2e8f0">#</th>'
            . '<th style="padding:.55rem .6rem;border-bottom:1px solid #e2e8f0">Task</th>'
            . '<th style="padding:.55rem .6rem;min-width:160px;border-bottom:1px solid #e2e8f0">Progress</th>'
            . '<th style="padding:.55rem .6rem;white-space:nowrap;border-bottom:1px solid #e2e8f0">Start</th>'
            . '<th style="padding:.55rem .6rem;white-space:nowrap;border-bottom:1px solid #e2e8f0">End</th>'
            . '<th style="padding:.55rem .6rem;border-bottom:1px solid #e2e8f0">Status</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . $details;
    }

    /** Per-task assignee completion. Pure query: returns counts + completed/pending lists. */
    private function implTaskProgress(array $task)
    {
        $done = 0;
        $total = 0;
        $completed = [];
        $pending = [];
        foreach ($task['categories'] as $cat) {
            foreach ($cat['assignees'] as $a) {
                $total++;
                $entry = [
                    'fullname'     => $a['fullname'],
                    'category'     => $cat['category_name'],
                    'completed_at' => isset($a['completed_at']) ? $a['completed_at'] : null,
                ];
                if (!empty($a['completed'])) {
                    $done++;
                    $completed[] = $entry;
                } else {
                    $pending[] = $entry;
                }
            }
        }
        return ['done' => $done, 'total' => $total, 'completed' => $completed, 'pending' => $pending];
    }

    private function renderImplTaskRow($no, array $task, array $progress)
    {
        $taskId = (int)$task['id'];
        $cDone  = $progress['done'];
        $cTotal = $progress['total'];

        if ($cTotal === 0) {
            $progCell = '<span style="color:#cbd5e1">No staff assigned</span>';
        } else {
            $cPct = round($cDone / $cTotal * 100);
            $full = $cDone === $cTotal ? ' full' : '';
            $progCell = '<div class="ip-cell" onclick="openImplProgress(' . $taskId . ')" title="View who\'s done">'
                . '<div class="ip-bar' . $full . '"><span style="width:' . $cPct . '%"></span></div>'
                . '<span class="ip-count">' . $cDone . ' / ' . $cTotal . '</span>'
                . '<i class="fas fa-chevron-right ip-go"></i></div>';
        }

        $desc     = trim((string)$task['task_description']);
        $descHtml = $desc !== '' ? '<div style="font-size:.72rem;color:#94a3b8;margin-top:.15rem">' . htmlspecialchars($desc) . '</div>' : '';

        $fmt    = function ($d) { return $d ? date('d M Y', strtotime($d)) : '<span style="color:#cbd5e1">&mdash;</span>'; };
        $isDone = $task['status'] === 'done';
        $badge  = '<span style="display:inline-flex;align-items:center;gap:.3rem;font-size:.7rem;font-weight:600;padding:.15rem .5rem;border-radius:999px;'
            . ($isDone
                ? 'color:#047857;background:#ecfdf5;border:1px solid #a7f3d0"><i class="fas fa-circle-check" style="font-size:.62rem"></i> Done'
                : 'color:#92400e;background:#fffbeb;border:1px solid #fde68a"><i class="fas fa-clock" style="font-size:.62rem"></i> Pending')
            . '</span>';

        return '<tr style="border-bottom:1px solid #f1f5f9;vertical-align:top">'
            . '<td style="padding:.55rem .6rem;color:#94a3b8;font-weight:600">' . $no . '</td>'
            . '<td style="padding:.55rem .6rem"><div style="font-weight:600;color:#1e293b">' . htmlspecialchars($task['task_name']) . '</div>' . $descHtml . '</td>'
            . '<td style="padding:.55rem .6rem">' . $progCell . '</td>'
            . '<td style="padding:.55rem .6rem;white-space:nowrap;color:#475569">' . $fmt($task['start_date']) . '</td>'
            . '<td style="padding:.55rem .6rem;white-space:nowrap;color:#475569">' . $fmt($task['deadline']) . '</td>'
            . '<td style="padding:.55rem .6rem">' . $badge . '</td>'
            . '</tr>';
    }

    /** Hidden payload consumed by openImplProgress() to populate the detail modal. */
    private function renderImplProgressData($no, array $task, array $progress)
    {
        if ($progress['total'] === 0) return '';
        $taskId = (int)$task['id'];
        $title  = 'Task ' . $no . ' — ' . $task['task_name'];
        return '<div id="implDetailData-' . $taskId . '" data-title="' . htmlspecialchars($title, ENT_QUOTES) . '" style="display:none">'
            . $this->renderImplProgressDetail($task, $progress)
            . '</div>';
    }

    /** Modal body: summary bar + Completed / Pending split, each grouped by category. */
    private function renderImplProgressDetail(array $task, array $progress)
    {
        $done  = $progress['done'];
        $total = $progress['total'];
        $pct   = $total ? round($done / $total * 100) : 0;

        // Overdue is measured from the deadline and is the same for every pending person on the
        // task, so it is stated once in the column header rather than repeated per row.
        $dts     = !empty($task['deadline']) ? strtotime($task['deadline']) : false;
        $overdue = ($dts !== false) ? (int)floor((time() - $dts) / 86400) : null;
        if ($overdue === null) {
            $pendMeta = '';
        } elseif ($overdue > 0) {
            $pendMeta = '<span class="ipd-meta ipd-over">' . $overdue . ' day' . ($overdue === 1 ? '' : 's') . ' overdue</span>';
        } elseif ($overdue === 0) {
            $pendMeta = '<span class="ipd-meta ipd-over">due today</span>';
        } else {
            $pendMeta = '<span class="ipd-meta">due in ' . abs($overdue) . ' day' . (abs($overdue) === 1 ? '' : 's') . '</span>';
        }

        $fmt   = function ($d) { return $d ? date('d M Y', strtotime($d)) : '&mdash;'; };
        $dates = '<div class="ipd-dates"><span>Start<b>' . $fmt($task['start_date']) . '</b></span>'
            . '<span>End<b>' . $fmt($task['deadline']) . '</b></span></div>';

        $summary = '<div class="ipd-summary">'
            . '<div class="ip-bar' . ($done === $total ? ' full' : '') . '"><span style="width:' . $pct . '%"></span></div>'
            . '<div class="ipd-summary-pct">' . $done . ' / ' . $total . ' &middot; ' . $pct . '%</div></div>';

        $doneCol = '<div class="ipd-col ipd-done">'
            . '<div class="ipd-col-head"><i class="fas fa-circle-check"></i> Completed (' . $done . ')</div>'
            . $this->renderImplProgressGroups($progress['completed'], true) . '</div>';

        $pendCol = '<div class="ipd-col ipd-pending">'
            . '<div class="ipd-col-head"><i class="fas fa-clock"></i> Pending (' . count($progress['pending']) . ')' . $pendMeta . '</div>'
            . $this->renderImplProgressGroups($progress['pending'], false) . '</div>';

        return $dates . $summary . '<div class="ipd-cols">' . $doneCol . $pendCol . '</div>';
    }

    /** Render one column's assignees grouped by category, numbered continuously within the column. */
    private function renderImplProgressGroups(array $list, $showDate)
    {
        if (empty($list)) {
            return '<div class="ipd-empty">' . ($showDate ? 'None yet' : 'All completed') . '</div>';
        }
        $groups = [];
        foreach ($list as $row) $groups[$row['category']][] = $row;

        $html = '';
        $n = 0;
        foreach ($groups as $cat => $rows) {
            $html .= '<div class="ipd-group">' . htmlspecialchars($cat)
                . '<span class="ipd-group-count">' . count($rows) . '</span></div>';
            foreach ($rows as $r) {
                $n++;
                $date = '';
                if ($showDate) {
                    $when = $r['completed_at'] ? date('d M Y', strtotime($r['completed_at'])) : '&mdash;';
                    $date = '<span class="ipd-date">' . $when . '</span>';
                }
                $html .= '<div class="ipd-item"><span class="ipd-num">' . $n . '.</span>'
                    . '<span class="ipd-name">' . htmlspecialchars($r['fullname']) . '</span>' . $date . '</div>';
            }
        }
        return $html;
    }

    /** App Usage Report: per-app format, resolved via the data-driven provider registry. */
    public function getUsageReport($appId, $year, $month, $person, $compId, $isAdmin)
    {
        $stmt = DB::app()->prepare("SELECT application_id, application_title, application_code, application_management_report, application_usage_report FROM application WHERE application_id=? AND application_status='1' LIMIT 1");
        $stmt->execute([$appId]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$app) throw new Exception('Application not found.');
        if ($app['application_management_report'] !== '1' || $app['application_usage_report'] !== '1')
            throw new Exception('Usage report not enabled for this application.');
        if (!$this->canManageApp($person, $app['application_id'], $compId, $isAdmin))
            throw new Exception('You do not have permission to view this report.');
        if (trim((string)$app['application_code']) === '')
            throw new Exception('No usage report handler configured for this application.');

        require_once __DIR__ . '/app_reports/AppDetailProvider.php';
        $provider = AppReport::resolve($app['application_code']);
        $html = $provider->render(
            ['application_id' => $app['application_id'], 'comp_id' => $compId, 'person' => $person, 'app_title' => $app['application_title']],
            ['year' => $year, 'month' => $month]
        );
        return ['success' => true, 'title' => $provider->title(), 'html' => $html];
    }
}

// ── Route ─────────────────────────────────────────────────

$svc = new ImplTrackerService();
$isAdmin = $svc->isAdmin($currentUser['person'], $currentUser['comp_id']);
$accessibleCompIds = $svc->getAccessibleCompanyIds($currentUser['person'], $currentUser['comp_id']);

if (isset($_GET['action'])) {
    // Binary download: streams its own headers, so it runs before the JSON header below.
    if ($_GET['action'] === 'download_reference_material') {
        try {
            $svc->streamReferenceMaterial(isset($_GET['id']) ? (int)$_GET['id'] : 0);
        } catch (Throwable $e) {
            while (ob_get_level() > 0) ob_end_clean();
            if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain'); }
            echo 'Download error';
        }
        exit;
    }
    header('Content-Type: application/json');
    // PHP warnings/notices printed into the body break JSON.parse on the client (the
    // "Failed to load report." fallback). Keep them out of the response; they still log.
    ini_set('display_errors', '0');
    ob_start();
    // require/parse errors are E_COMPILE_ERROR fatals — uncatchable by try/catch. Convert
    // them (and any other fatal) into a JSON error so the client always gets a real message.
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            while (ob_get_level() > 0) ob_end_clean();
            if (!headers_sent()) header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Fatal: ' . $e['message']]);
        }
    });
    try {
        $input = ($_SERVER['REQUEST_METHOD'] === 'POST') ? json_decode(file_get_contents('php://input'), true) : null;
        switch ($_GET['action']) {
            case 'get_companies':
                echo json_encode(['success' => true, 'companies' => $svc->getCompanies($accessibleCompIds), 'is_admin' => $isAdmin]);
                break;
            case 'get_applications':
                echo json_encode(['success' => true, 'applications' => $svc->getApplications(isset($_GET['comp_id']) && $_GET['comp_id'] ? $_GET['comp_id'] : $currentUser['comp_id'])]);
                break;
            case 'get_task_masters':
                echo json_encode(['success' => true, 'task_masters' => $svc->getTaskMasters()]);
                break;
            case 'get_staff_list':
                if (empty($_GET['app_id'])) throw new Exception("app_id is required");
                $scopeComp = !empty($_GET['comp_id']) ? $_GET['comp_id'] : $currentUser['comp_id'];
                echo json_encode(['success' => true, 'staff' => $svc->getStaffList($svc->getStaffScopeCompIds($_GET['app_id'], $scopeComp))]);
                break;
            case 'get_app_roles':
                if (empty($_GET['app_id'])) throw new Exception("app_id is required");
                $scopeComp = !empty($_GET['comp_id']) ? $_GET['comp_id'] : $currentUser['comp_id'];
                echo json_encode(['success' => true, 'roles' => $svc->getAppRoles($_GET['app_id'], $svc->getStaffScopeCompIds($_GET['app_id'], $scopeComp))]);
                break;
            case 'get_trackers':
                $f = ['search' => isset($_GET['search']) ? $_GET['search'] : '', 'status' => isset($_GET['status']) ? $_GET['status'] : '', 'comp_id' => isset($_GET['comp_id']) ? $_GET['comp_id'] : ''];
                echo json_encode(['success' => true, 'trackers' => $svc->getTrackers($accessibleCompIds, $f, $currentUser['person'], $currentUser['comp_id'], $isAdmin)]);
                break;
            case 'get_tracker_detail':
                echo json_encode(['success' => true, 'tracker' => $svc->getTrackerDetail($_GET['tracker_id'])]);
                break;
            case 'create_tracker':
                if (empty($input['application_id']) || empty($input['comp_id']))
                    throw new Exception("Application and company are required");
                if (!$svc->canManage($currentUser['person'], $input['application_id'], $input['comp_id'], $isAdmin))
                    throw new Exception("You do not have permission to create an implementation for this application");
                echo json_encode($svc->createTracker($input, $currentUser));
                break;
            case 'add_task':
                $svc->requireManagePermission($currentUser['person'], $currentUser['comp_id'], $isAdmin, ['tracker_id' => $input['tracker_id']]);
                echo json_encode($svc->addTask($input['tracker_id'], $input['task']));
                break;
            case 'reorder_tasks':
                $svc->requireManagePermission($currentUser['person'], $currentUser['comp_id'], $isAdmin, ['tracker_id' => $input['tracker_id']]);
                echo json_encode($svc->reorderTasks($input['tracker_id'], $input['ordered_ids']));
                break;
            case 'delete_tracker':
                $svc->requireManagePermission($currentUser['person'], $currentUser['comp_id'], $isAdmin, ['tracker_id' => $input['tracker_id']]);
                echo json_encode($svc->deleteTracker($input['tracker_id'], $currentUser));
                break;
            case 'update_tracker_status':
                $svc->requireManagePermission($currentUser['person'], $currentUser['comp_id'], $isAdmin, ['tracker_id' => $input['tracker_id']]);
                echo json_encode($svc->updateTrackerStatus($input['tracker_id'], $input['status'], $currentUser));
                break;
            case 'mark_done':
                echo json_encode($svc->markDone($input['tracker_task_id'], $currentUser['person'], $currentUser['comp_id']));
                break;
            case 'unmark_done':
                echo json_encode($svc->unmarkDone($input['tracker_task_id'], $currentUser['person'], $currentUser['comp_id']));
                break;
            case 'add_category':
                $svc->requireManagePermission($currentUser['person'], $currentUser['comp_id'], $isAdmin, ['tracker_task_id' => $input['tracker_task_id']]);
                echo json_encode($svc->addCategory($input['tracker_task_id'], $input['category_name']));
                break;
            case 'remove_category':
                $svc->requireManagePermission($currentUser['person'], $currentUser['comp_id'], $isAdmin, ['category_id' => $input['category_id']]);
                echo json_encode($svc->removeCategory($input['category_id']));
                break;
            case 'add_staff':
                $svc->requireManagePermission($currentUser['person'], $currentUser['comp_id'], $isAdmin, ['tracker_task_id' => $input['tracker_task_id']]);
                echo json_encode($svc->addStaff($input['category_id'], $input['tracker_task_id'], $input['person'], $input['comp_id']));
                break;
            case 'remove_staff':
                $svc->requireManagePermission($currentUser['person'], $currentUser['comp_id'], $isAdmin, ['assignee_id' => $input['assignee_id']]);
                echo json_encode($svc->removeStaff($input['assignee_id']));
                break;
            case 'get_comments':
                $taskId = $_GET['tracker_task_id'];
                $canManageComments = false;
                $trStmt = DB::app()->prepare("SELECT t.application_id, t.comp_id FROM app_impl_tracker t JOIN app_impl_tracker_tasks tt ON tt.tracker_id=t.id WHERE tt.id=? AND t.deleted=0");
                $trStmt->execute([$taskId]);
                $trRow = $trStmt->fetch(PDO::FETCH_ASSOC);
                if ($trRow) $canManageComments = $svc->canManage($currentUser['person'], $trRow['application_id'], $trRow['comp_id'], $isAdmin);
                echo json_encode(['success' => true, 'comments' => $svc->getComments($taskId), 'can_manage' => $canManageComments]);
                break;
            case 'add_comment':
                $parentId = !empty($input['parent_id']) ? (int)$input['parent_id'] : null;
                echo json_encode($svc->addComment($input['tracker_task_id'], $currentUser['person'], $currentUser['comp_id'], $input['comment'], $parentId));
                break;
            case 'delete_comment':
                echo json_encode($svc->deleteComment($input['comment_id'], $currentUser['person'], $currentUser['comp_id'], $isAdmin));
                break;
            case 'toggle_comment_done':
                $cmtStmt = DB::app()->prepare("SELECT tracker_task_id FROM app_impl_tracker_task_comments WHERE id=? AND deleted=0");
                $cmtStmt->execute([$input['comment_id']]);
                $cmtRow = $cmtStmt->fetch(PDO::FETCH_ASSOC);
                if (!$cmtRow) throw new Exception("Comment not found");
                $svc->requireManagePermission($currentUser['person'], $currentUser['comp_id'], $isAdmin, ['tracker_task_id' => $cmtRow['tracker_task_id']]);
                echo json_encode($svc->toggleCommentDone($input['comment_id'], $currentUser['person'], $currentUser['comp_id']));
                break;
            case 'get_my_tasks':
                echo json_encode(['success' => true, 'data' => $svc->getMyTasks($currentUser['person'], $currentUser['comp_id'])]);
                break;
            case 'get_my_app_listing':
                echo json_encode(['success' => true, 'apps' => $svc->getMyAppListing($currentUser['person'], $currentUser['comp_id'], $isAdmin)]);
                break;
            case 'get_reference_materials':
                echo json_encode($svc->getReferenceMaterials($_GET['app_id'], $currentUser['person'], $currentUser['comp_id'], $isAdmin));
                break;
            case 'upload_reference_material':
                echo json_encode($svc->uploadReferenceMaterial(
                    isset($_POST['app_id']) ? $_POST['app_id'] : '',
                    isset($_POST['title']) ? $_POST['title'] : '',
                    isset($_POST['description']) ? $_POST['description'] : '',
                    isset($_FILES['file']) ? $_FILES['file'] : null,
                    $currentUser['person'], $currentUser['comp_id'], $isAdmin
                ));
                break;
            case 'delete_reference_material':
                echo json_encode($svc->deleteReferenceMaterial($input['material_id'], $currentUser['person'], $currentUser['comp_id'], $isAdmin));
                break;
            case 'get_app_detail':
                echo json_encode($svc->getAppDetail($_GET['app_id'], $currentUser));
                break;
            case 'get_management_report_apps':
                echo json_encode(['success' => true, 'apps' => $svc->getManagementReportApps($accessibleCompIds, $currentUser['person'], $currentUser['comp_id'], $isAdmin)]);
                break;
            case 'get_implementation_report':
                echo json_encode($svc->getImplementationReport($_GET['app_id'], $currentUser['person'], $currentUser['comp_id'], $isAdmin));
                break;
            case 'get_usage_report':
                echo json_encode($svc->getUsageReport(
                    $_GET['app_id'],
                    isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y'),
                    isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n'),
                    $currentUser['person'], $currentUser['comp_id'], $isAdmin
                ));
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (Throwable $e) {
        // Discard anything already buffered so a partial echo can't corrupt the JSON.
        while (ob_get_level() > 0) ob_end_clean();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$companies = $svc->getCompanies($accessibleCompIds);
$showManagement = $isAdmin || $svc->hasManageableTrackers($currentUser['person'], $currentUser['comp_id']);
$view = isset($_GET['view']) ? $_GET['view'] : 'list';
$fromMyTasks = isset($_GET['from']) && $_GET['from'] === 'my';
$trackerDetail = null;
$canManage = false;
$simpleView = false;
if ($view === 'detail' && !empty($_GET['tracker_id'])) {
    try {
        $trackerDetail = $svc->getTrackerDetail($_GET['tracker_id']);
        $canManage = $svc->canManage($currentUser['person'], $trackerDetail['application_id'], $trackerDetail['comp_id'], $isAdmin);
        $simpleView = !$canManage || $fromMyTasks;
    } catch (Exception $e) {
        $view = 'list';
        $errorMessage = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>App Tracker</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            color: #1e293b;
            background: #f1f5f9;
            padding: 20px;
            -webkit-text-size-adjust: 100%
        }

        .main-content {
            transition: margin-left .3s
        }

        @media(min-width:769px) {
            .main-content {
                margin-left: 280px
            }

            .main-content:not(.sidebar-open) {
                margin-left: 0 !important
            }
        }

        @media(max-width:768px) {
            .main-content {
                margin-left: 0 !important
            }

            body {
                padding: 8px
            }
        }

        .container {
            max-width: none;
            margin: 0 20px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .06);
            overflow: hidden
        }

        @media(max-width:768px) {
            .container {
                margin: 0;
                border-radius: 8px
            }
        }

        .header {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
            padding: 1.4rem;
            text-align: center;
            color: #fff
        }

        .header h1 {
            font-size: 1.25rem;
            font-weight: 700
        }

        .header .subtitle {
            font-size: .78rem;
            color: #94a3b8;
            margin-top: .1rem
        }

        @media(max-width:768px) {
            .header {
                padding: 3.2rem 1rem 1rem;
                position: relative
            }

            .header h1 {
                font-size: 1.05rem
            }
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: .85rem 1.25rem;
            border-bottom: 1px solid #e2e8f0;
            flex-wrap: wrap;
            gap: .5rem
        }

        .toolbar-left {
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
            align-items: center;
            flex: 1;
            min-width: 0
        }

        .search-input {
            padding: .5rem .75rem;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: .85rem;
            flex: 1;
            min-width: 120px;
            max-width: 220px
        }

        .search-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, .15)
        }

        @media(max-width:768px) {
            .toolbar {
                flex-direction: column;
                align-items: stretch;
                padding: .55rem .6rem;
                gap: .4rem
            }

            .toolbar-left {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: .4rem
            }

            .toolbar-left .search-input {
                grid-column: 1 / -1;
                max-width: none
            }

            .toolbar .select2-container {
                width: 100% !important
            }

            .toolbar > .btn {
                width: 100%;
                justify-content: center
            }
        }

        .btn {
            padding: .45rem .85rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: .83rem;
            font-weight: 500;
            transition: all .15s;
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            white-space: nowrap
        }

        .btn-primary {
            background: #2563eb;
            color: #fff
        }

        .btn-primary:hover {
            background: #1d4ed8
        }

        .btn-success {
            background: #059669;
            color: #fff
        }

        .btn-success:hover {
            background: #047857
        }

        .btn-warning {
            background: #d97706;
            color: #fff
        }

        .btn-warning:hover {
            background: #b45309
        }

        .btn-danger {
            background: #dc2626;
            color: #fff
        }

        .btn-danger:hover {
            background: #b91c1c
        }

        .btn-secondary {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0
        }

        .btn-secondary:hover {
            background: #e2e8f0
        }

        .btn-sm {
            padding: .3rem .55rem;
            font-size: .78rem
        }

        .btn-xs {
            padding: .2rem .4rem;
            font-size: .73rem
        }

        .btn:disabled {
            opacity: .5;
            cursor: not-allowed
        }

        @media(max-width:768px) {
            .btn {
                padding: .55rem .9rem;
                min-height: 40px
            }

            .btn-sm {
                min-height: 36px
            }
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            color: #2563eb;
            text-decoration: none;
            font-size: .83rem;
            font-weight: 500;
            padding: .85rem 1.25rem;
            border-bottom: 1px solid #e2e8f0
        }

        .back-link:hover {
            color: #1d4ed8
        }

        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: .65rem;
            padding: 1rem 1.25rem
        }

        @media(max-width:768px) {
            .card-grid {
                grid-template-columns: 1fr;
                padding: .6rem;
                gap: .5rem
            }
        }

        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 1rem;
            cursor: pointer;
            transition: all .15s
        }

        .card:hover {
            border-color: #3b82f6;
            box-shadow: 0 4px 12px rgba(59, 130, 246, .08)
        }

        .card:active {
            transform: scale(.99)
        }

        .card-title {
            font-size: .92rem;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: .1rem
        }

        .card-section {
            font-size: .76rem;
            color: #64748b;
            margin-bottom: .5rem
        }

        .card-meta {
            display: flex;
            gap: .6rem;
            flex-wrap: wrap;
            font-size: .76rem;
            color: #64748b;
            margin-bottom: .5rem
        }

        .card-meta span {
            display: flex;
            align-items: center;
            gap: .2rem
        }

        .progress-bar {
            height: 5px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden
        }

        .progress-fill {
            height: 100%;
            border-radius: 3px;
            transition: width .3s
        }

        .pf-green {
            background: #059669
        }

        .pf-blue {
            background: #2563eb
        }

        .pf-amber {
            background: #d97706
        }

        .badge {
            padding: .12rem .45rem;
            border-radius: 999px;
            font-size: .67rem;
            font-weight: 600;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: .03em
        }

        .badge-pending {
            background: #fef3c7;
            color: #92400e
        }

        .badge-in_progress,
        .badge-in-progress {
            background: #dbeafe;
            color: #1e40af
        }

        .badge-completed,
        .badge-done {
            background: #d1fae5;
            color: #065f46
        }

        .badge-on_hold,
        .badge-on-hold {
            background: #fee2e2;
            color: #991b1b
        }

        .detail-header {
            padding: 1.15rem 1.25rem;
            border-bottom: 1px solid #e2e8f0
        }

        .detail-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: #0f172a
        }

        .detail-section {
            font-size: .83rem;
            color: #64748b
        }

        .detail-actions {
            display: flex;
            gap: .4rem;
            margin-top: .6rem;
            flex-wrap: wrap
        }

        @media(max-width:768px) {
            .detail-header {
                padding: .85rem
            }

            .detail-title {
                font-size: 1rem
            }

            .detail-actions {
                width: 100%
            }

            .detail-actions .btn {
                flex: 1;
                justify-content: center
            }
        }

        .summary-bar {
            display: flex;
            gap: .6rem;
            padding: .65rem 1.25rem;
            border-bottom: 1px solid #e2e8f0;
            flex-wrap: wrap;
            align-items: center;
            background: #f8fafc
        }

        .summary-stat {
            font-size: .76rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: .2rem
        }

        .summary-stat strong {
            color: #0f172a;
            font-size: .88rem
        }

        .task-list {
            padding: .85rem 1.25rem;
            display: flex;
            flex-direction: column;
            gap: .65rem
        }

        @media(max-width:768px) {
            .task-list {
                padding: .6rem;
                gap: .5rem
            }
        }

        .task-card {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            transition: all .15s
        }

        .task-card.s-done {
            border-color: #86efac;
            background: #f0fdf4
        }

        .task-card.s-overdue {
            border-color: #fca5a5;
            background: #fef2f2
        }

        .task-card.s-pending {
            border-color: #fcd34d
        }

        .tc-header {
            display: flex;
            align-items: center;
            gap: .55rem;
            padding: .75rem .9rem;
            background: #f8fafc;
            cursor: pointer;
            user-select: none
        }

        .task-card.s-done .tc-header {
            background: #ecfdf5
        }

        .task-card.s-overdue .tc-header {
            background: #fef2f2
        }

        .tc-num {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .72rem;
            font-weight: 700;
            flex-shrink: 0;
            border: 2px solid #cbd5e1;
            background: #fff;
            color: #64748b
        }

        .tc-move {
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex-shrink: 0;
            margin-right: 2px
        }

        .tc-move-btn {
            width: 22px;
            height: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #64748b;
            border-radius: 4px;
            cursor: pointer;
            font-size: .6rem;
            padding: 0;
            line-height: 1
        }

        .tc-move-btn:hover:not(:disabled) {
            background: #f1f5f9;
            color: #2563eb;
            border-color: #93c5fd
        }

        .tc-move-btn:disabled {
            opacity: .35;
            cursor: not-allowed
        }

        .tc-num.pending {
            background: #fef3c7;
            border-color: #f59e0b;
            color: #92400e
        }

        .tc-num.overdue {
            background: #fee2e2;
            border-color: #ef4444;
            color: #991b1b
        }

        .tc-num.done {
            background: #d1fae5;
            border-color: #059669;
            color: #065f46
        }

        .tc-info {
            flex: 1;
            min-width: 0
        }

        .tc-name {
            font-weight: 600;
            font-size: .88rem;
            color: #0f172a
        }

        .tc-desc {
            font-size: .76rem;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis
        }

        @media(max-width:768px) {
            .tc-desc {
                white-space: normal
            }

            .tc-name {
                font-size: .83rem
            }
        }

        .tc-toggle {
            color: #94a3b8;
            transition: transform .2s;
            font-size: .78rem
        }

        .task-card.expanded .tc-toggle {
            transform: rotate(180deg)
        }

        .tc-body {
            padding: .85rem;
            border-top: 1px solid #e2e8f0;
            display: none
        }

        .task-card.expanded .tc-body {
            display: block
        }

        @media(max-width:768px) {
            .tc-body {
                padding: .65rem
            }
        }

        .task-dates {
            display: flex;
            gap: .7rem;
            font-size: .78rem;
            color: #475569;
            margin-bottom: .65rem;
            flex-wrap: wrap
        }

        .deadline-overdue {
            color: #dc2626;
            font-weight: 600
        }

        .deadline-soon {
            color: #d97706;
            font-weight: 500
        }

        .cat-block {
            margin-bottom: .55rem;
            padding: .5rem .65rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px
        }

        .cat-block:last-of-type {
            margin-bottom: 0
        }

        .cat-block.fixed {
            border-left: 3px solid #2563eb
        }

        .cat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: .3rem
        }

        .cat-name {
            font-size: .72rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #475569;
            letter-spacing: .04em;
            display: flex;
            align-items: center;
            gap: .25rem
        }

        .cat-fixed-tag {
            font-size: .58rem;
            background: #dbeafe;
            color: #1e40af;
            padding: 0 4px;
            border-radius: 3px;
            font-weight: 600;
            text-transform: uppercase
        }

        .cat-remove {
            cursor: pointer;
            color: #94a3b8;
            font-size: .75rem;
            padding: 2px 4px;
            border-radius: 4px
        }

        .cat-remove:hover {
            color: #ef4444;
            background: #fef2f2
        }

        .cat-staff {
            display: flex;
            flex-wrap: wrap;
            gap: .25rem;
            align-items: center
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: .2rem;
            padding: .15rem .45rem;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 999px;
            font-size: .72rem;
            color: #475569;
            max-width: 100%;
            min-width: 0;
            overflow-wrap: anywhere;
            text-align: left
        }

        .chip-comp {
            font-size: .6rem;
            font-weight: 600;
            color: #475569;
            background: #e2e8f0;
            border-radius: 3px;
            padding: 0 .25rem;
            margin-left: .25rem;
        }

        .chip > i {
            flex-shrink: 0
        }

        .chip.done {
            background: #d1fae5;
            border-color: #86efac;
            color: #065f46
        }

        .chip.pending {
            background: #fff;
            border-color: #cbd5e1
        }

        .chip .rm {
            cursor: pointer;
            color: #94a3b8;
            margin-left: .1rem;
            font-size: .78rem;
            flex-shrink: 0
        }

        .chip .rm:hover {
            color: #ef4444
        }

        .chip.my-chip {
            border-color: #3b82f6;
            background: #eff6ff;
            animation: pulse 2s ease-in-out infinite
        }

        @keyframes pulse {

            0%,
            100% {
                box-shadow: 0 0 0 0 rgba(59, 130, 246, .3)
            }

            50% {
                box-shadow: 0 0 0 4px rgba(59, 130, 246, .1)
            }
        }

        .mark-done-btn {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            margin-top: .55rem;
            padding: .5rem 1rem;
            border: none;
            border-radius: 6px;
            font-size: .8rem;
            font-weight: 600;
            cursor: pointer;
            background: #059669;
            color: #fff;
            transition: all .15s
        }

        .mark-done-btn:hover {
            background: #047857
        }

        @media(max-width:768px) {
            .mark-done-btn {
                width: 100%;
                justify-content: center;
                min-height: 42px
            }
        }

        .add-cat-row {
            display: flex;
            gap: .4rem;
            margin-top: .5rem;
            align-items: center;
            flex-wrap: wrap
        }

        .add-cat-input {
            padding: .35rem .6rem;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: .8rem;
            flex: 1;
            min-width: 110px;
            max-width: 180px
        }

        .add-cat-input:focus {
            outline: none;
            border-color: #3b82f6
        }

        @media(max-width:768px) {
            .add-cat-input {
                max-width: none
            }
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 99999;
            inset: 0;
            background: rgba(0, 0, 0, .5);
            backdrop-filter: blur(2px);
            overflow: hidden;
            -webkit-overflow-scrolling: touch
        }

        .modal-content {
            background: #fff;
            margin: 2% auto;
            border-radius: 12px;
            width: 94%;
            max-width: 720px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, .2);
            display: flex;
            flex-direction: column;
            max-height: 96vh
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: .9rem 1.15rem;
            border-bottom: 1px solid #e2e8f0;
            background: #fff;
            z-index: 10;
            border-radius: 12px 12px 0 0;
            flex-shrink: 0
        }

        .modal-header h3 {
            font-size: 1rem;
            font-weight: 600
        }

        .modal-close {
            font-size: 1.2rem;
            cursor: pointer;
            color: #64748b;
            background: #f1f5f9;
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center
        }

        .modal-close:hover {
            background: #e2e8f0
        }

        .modal-body {
            padding: 1.15rem;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            flex: 1;
            min-height: 0;
            overscroll-behavior: contain
        }

        .modal-footer {
            display: flex;
            gap: .4rem;
            justify-content: flex-end;
            padding: .7rem 1.15rem;
            border-top: 1px solid #e2e8f0;
            background: #fff;
            border-radius: 0 0 12px 12px;
            flex-shrink: 0
        }

        @media(max-width:768px) {
            .modal-content {
                margin: 0;
                border-radius: 0;
                width: 100%;
                max-height: 100vh;
                height: 100vh;
                max-height: -webkit-fill-available
            }

            .modal-header {
                border-radius: 0;
                flex-shrink: 0;
                padding: .9rem 1rem;
                padding-top: max(.9rem, env(safe-area-inset-top, .9rem))
            }

            .modal-footer {
                border-radius: 0;
                flex-shrink: 0;
                padding-bottom: max(.7rem, env(safe-area-inset-bottom, .7rem))
            }

            .modal-body {
                padding: .85rem
            }

            .modal-footer .btn {
                flex: 1;
                justify-content: center
            }
        }

        /* ── Implementation progress cell + detail modal ──────────── */
        .ip-cell {
            display: flex;
            align-items: center;
            gap: .5rem;
            cursor: pointer;
            padding: .25rem .4rem;
            margin: -.25rem -.4rem;
            border-radius: 7px;
            transition: background .12s
        }

        .ip-cell:hover {
            background: #f1f5f9
        }

        .ip-bar {
            flex: 1;
            min-width: 64px;
            height: 7px;
            background: #e2e8f0;
            border-radius: 999px;
            overflow: hidden
        }

        .ip-bar > span {
            display: block;
            height: 100%;
            border-radius: 999px;
            background: linear-gradient(90deg, #10b981, #059669)
        }

        .ip-bar.full > span {
            background: #059669
        }

        .ip-count {
            font-weight: 700;
            color: #334155;
            white-space: nowrap;
            font-variant-numeric: tabular-nums
        }

        .ip-cell .ip-go {
            font-size: .6rem;
            color: #94a3b8
        }

        .ipd-summary {
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: .8rem .95rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            margin-bottom: 1rem
        }

        .ipd-summary .ip-bar {
            height: 9px
        }

        .ipd-summary-pct {
            font-size: 1.05rem;
            font-weight: 700;
            color: #0f172a;
            white-space: nowrap;
            font-variant-numeric: tabular-nums
        }

        .ipd-cols {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            align-items: start
        }

        .ipd-col {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow: hidden
        }

        .ipd-col-head {
            display: flex;
            align-items: center;
            gap: .4rem;
            padding: .55rem .8rem;
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .03em
        }

        .ipd-done .ipd-col-head {
            background: #ecfdf5;
            color: #047857;
            border-bottom: 1px solid #a7f3d0
        }

        .ipd-pending .ipd-col-head {
            background: #fffbeb;
            color: #b45309;
            border-bottom: 1px solid #fde68a
        }

        .ipd-col-head .ipd-meta {
            margin-left: auto;
            font-weight: 600;
            text-transform: none;
            letter-spacing: 0;
            font-size: .7rem;
            opacity: .9
        }

        .ipd-group {
            display: flex;
            align-items: center;
            gap: .4rem;
            padding: .4rem .8rem;
            font-size: .66rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: #334155;
            background: #e8edf4;
            border-top: 1px solid #d3dbe6;
            border-bottom: 1px solid #d3dbe6;
            box-shadow: inset 3px 0 0 #94a3b8
        }

        .ipd-group-count {
            margin-left: auto;
            font-weight: 700;
            color: #475569;
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 999px;
            padding: 0 .42rem;
            font-size: .62rem;
            line-height: 1.5
        }

        .ipd-item {
            display: flex;
            align-items: baseline;
            gap: .5rem;
            padding: .35rem .8rem;
            font-size: .83rem;
            border-top: 1px solid #f1f5f9
        }

        .ipd-num {
            flex: 0 0 auto;
            min-width: 1.6em;
            text-align: right;
            color: #94a3b8;
            font-size: .74rem;
            font-variant-numeric: tabular-nums
        }

        .ipd-name {
            color: #1e293b
        }

        .ipd-date {
            margin-left: auto;
            font-size: .72rem;
            color: #047857;
            white-space: nowrap
        }

        .ipd-over {
            color: #b91c1c !important
        }

        .ipd-dates {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
            padding: .55rem .95rem;
            margin-bottom: .7rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: .78rem;
            color: #64748b
        }

        .ipd-dates b {
            color: #1e293b;
            margin-left: .35rem
        }

        .ipd-empty {
            padding: .7rem .8rem;
            font-size: .8rem;
            color: #cbd5e1
        }

        @media(max-width:640px) {
            .ipd-cols {
                grid-template-columns: 1fr
            }
        }

        .form-group {
            margin-bottom: .8rem
        }

        .form-label {
            display: block;
            margin-bottom: .2rem;
            font-weight: 500;
            color: #374151;
            font-size: .82rem
        }

        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: .5rem .7rem;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: .85rem;
            background: #fff;
            color: #1e293b;
            font-family: inherit
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, .15)
        }

        .form-textarea {
            min-height: 55px;
            resize: vertical
        }

        @media(max-width:768px) {

            .form-input,
            .form-select,
            .form-textarea {
                font-size: 16px;
                padding: .55rem .7rem
            }
        }

        .task-pick {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: .55rem;
            overflow: hidden
        }

        .tp-header {
            display: flex;
            align-items: center;
            gap: .45rem;
            padding: .6rem .8rem;
            background: #f8fafc;
            cursor: pointer;
            user-select: none
        }

        .tp-header:hover {
            background: #f1f5f9
        }

        .tp-header input[type=checkbox] {
            width: 18px;
            height: 18px;
            flex-shrink: 0
        }

        .tp-body {
            padding: .8rem;
            border-top: 1px solid #e2e8f0;
            display: none
        }

        .task-pick.selected .tp-body {
            display: block
        }

        .tp-title {
            font-weight: 600;
            font-size: .86rem;
            flex: 1;
            min-width: 0
        }

        .tp-desc {
            font-size: .76rem;
            color: #64748b
        }

        .inline-fields {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .55rem;
            margin-bottom: .55rem
        }

        @media(max-width:500px) {
            .inline-fields {
                grid-template-columns: 1fr
            }
        }

        .create-cat {
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: .55rem;
            margin-bottom: .4rem;
            background: #fafbfc
        }

        .create-cat.fixed {
            border-left: 3px solid #2563eb
        }

        .create-cat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: .25rem
        }

        .create-cat-label {
            font-size: .74rem;
            font-weight: 600;
            color: #475569;
            text-transform: uppercase
        }

        .create-cat-remove {
            cursor: pointer;
            color: #94a3b8;
            font-size: .73rem
        }

        .create-cat-remove:hover {
            color: #ef4444
        }

        .autofill-tag {
            display: inline-block;
            font-size: .6rem;
            background: #dbeafe;
            color: #1e40af;
            padding: 0 4px;
            border-radius: 3px;
            margin-left: 3px;
            font-weight: 600;
            vertical-align: middle
        }

        .no-data {
            text-align: center;
            padding: 2rem;
            color: #94a3b8;
            font-size: .83rem
        }

        .view-toggle {
            display: inline-flex;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            overflow: hidden
        }

        .view-btn {
            padding: .4rem .55rem;
            border: none;
            background: #fff;
            color: #64748b;
            cursor: pointer;
            font-size: .83rem;
            transition: all .15s
        }

        .view-btn.active {
            background: #2563eb;
            color: #fff
        }

        .view-btn:hover:not(.active) {
            background: #f1f5f9
        }

        @media(max-width:768px) {
            .view-toggle {
                display: none
            }
        }

        .list-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: .83rem
        }

        .list-table th {
            background: #f8fafc;
            color: #374151;
            font-weight: 600;
            padding: .6rem .8rem;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
            font-size: .76rem;
            text-transform: uppercase;
            letter-spacing: .03em
        }

        .list-table td {
            padding: .6rem .8rem;
            border-bottom: 1px solid #f1f5f9;
            color: #4b5563;
            vertical-align: middle
        }

        .list-table tbody tr {
            cursor: pointer;
            transition: background .1s
        }

        .list-table tbody tr:hover {
            background: #f8fafc
        }

        .list-table-wrap {
            overflow-x: auto;
            padding: 0 1.25rem 1.25rem;
            -webkit-overflow-scrolling: touch
        }

        @media(max-width:768px) {
            .list-table-wrap {
                display: none
            }
        }

        .alert {
            padding: .6rem .8rem;
            border-radius: 6px;
            margin: .6rem 1.25rem;
            font-size: .83rem
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0
        }

        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca
        }

        .select2-container {
            width: 100% !important
        }

        .select2-container--default .select2-selection--single {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            height: 34px;
            font-size: .83rem
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 32px;
            padding-left: 10px;
            color: #1e293b
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 32px
        }

        .select2-container--default .select2-selection--multiple {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 2px 4px;
            min-height: 32px;
            font-size: .83rem;
            overflow: hidden
        }

        .select2-container--default .select2-selection--multiple .select2-search--inline .select2-search__field {
            margin-top: 3px
        }

        .select2-container--default .select2-selection--multiple:focus-within {
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, .15)
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background: #e0e7ff;
            border: 1px solid #c7d2fe;
            color: #3730a3;
            border-radius: 4px;
            padding: 1px 6px;
            font-size: .76rem;
            margin-top: 3px
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
            color: #6366f1;
            margin-right: 3px
        }

        .select2-dropdown {
            border-color: #cbd5e1;
            border-radius: 0 0 6px 6px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, .1);
            font-size: .83rem;
            z-index: 100001
        }

        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background: #eff6ff;
            color: #1e40af
        }

        .select2-container--open .select2-dropdown {
            z-index: 100001
        }

        .modal .select2-container {
            z-index: 100000
        }

        @media(max-width:768px) {
            .select2-container--default .select2-selection--single {
                height: 40px
            }

            .select2-container--default .select2-selection--single .select2-selection__rendered {
                line-height: 38px
            }

            .select2-container--default .select2-selection--single .select2-selection__arrow {
                height: 38px
            }

            .select2-container--default .select2-selection--multiple {
                min-height: 40px
            }
        }

        /* ── Gantt Chart ─────────────────────────────────── */

        .gantt-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: .6rem 1.25rem;
            cursor: pointer;
            user-select: none;
            border-bottom: 1px solid #e2e8f0;
            background: #fff
        }

        .gantt-toggle:hover {
            background: #f8fafc
        }

        .gantt-toggle-label {
            font-size: .8rem;
            font-weight: 600;
            color: #374151;
            display: flex;
            align-items: center;
            gap: .35rem
        }

        .gantt-toggle i.chevron {
            font-size: .7rem;
            color: #94a3b8;
            transition: transform .2s
        }

        .gantt-wrap {
            display: none;
            padding: .5rem 1rem;
            border-bottom: 1px solid #e2e8f0;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch
        }

        .gantt-wrap.open {
            display: block
        }

        .gantt-wrap.open+.gantt-toggle .chevron,
        .gantt-toggle.open .chevron {
            transform: rotate(180deg)
        }

        .gantt-chart {
            min-width: 300px;
            width: 100%;
            position: relative
        }

        @media(max-width:768px) {
            .gantt-wrap {
                padding: .4rem .5rem
            }
        }

        /* Header ticks */
        .gantt-tick-row {
            display: flex;
            align-items: flex-end;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 4px;
            margin-bottom: 0
        }

        .gantt-tick-area {
            flex: 1;
            position: relative;
            height: 16px
        }

        .gantt-tick-label {
            position: absolute;
            bottom: 0;
            font-size: .6rem;
            color: #94a3b8;
            font-weight: 500;
            transform: translateX(-50%);
            white-space: nowrap
        }

        .gantt-tick-label::before {
            content: '';
            position: absolute;
            top: -3px;
            left: 50%;
            width: 1px;
            height: 3px;
            background: #cbd5e1
        }

        @media(max-width:768px) {
            .gantt-tick-label {
                font-size: .55rem
            }
        }

        .gantt-today-label {
            position: absolute;
            bottom: 0;
            font-size: .58rem;
            color: #ef4444;
            font-weight: 700;
            transform: translateX(-50%);
            white-space: nowrap
        }

        .gantt-today-label::before {
            content: '';
            position: absolute;
            top: -3px;
            left: 50%;
            width: 1px;
            height: 3px;
            background: #ef4444
        }

        /* Label column */
        .gantt-label-col {
            width: 90px;
            flex-shrink: 0;
            padding-right: .3rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap
        }

        @media(max-width:768px) {
            .gantt-label-col {
                width: 70px
            }
        }

        /* Grid */
        .gantt-grid-line {
            position: absolute;
            top: 0;
            bottom: 0;
            width: 1px;
            background: #f1f5f9;
            pointer-events: none
        }

        /* Rows */
        .gantt-row {
            display: flex;
            align-items: center;
            height: 36px;
            padding: 0 0 0 0;
            border-bottom: 1px solid #f8fafc
        }

        .gantt-row:nth-child(even) {
            background: #fafbfd
        }

        .gantt-row-label {
            width: 90px;
            flex-shrink: 0;
            font-size: .68rem;
            color: #475569;
            padding-right: .3rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: 500
        }

        @media(max-width:768px) {
            .gantt-row-label {
                width: 70px;
                font-size: .62rem
            }
        }

        .gantt-row-track {
            flex: 1;
            height: 20px;
            position: relative;
            border-radius: 10px;
            overflow: visible
        }

        .gantt-bar {
            position: absolute;
            top: 0;
            height: 100%;
            border-radius: 10px;
            min-width: 8px;
            z-index: 2;
            cursor: pointer;
            transition: filter .15s, box-shadow .15s;
            -webkit-tap-highlight-color: transparent
        }

        .gantt-bar:hover,
        .gantt-bar:active {
            filter: brightness(1.08);
            box-shadow: 0 1px 4px rgba(0,0,0,.15)
        }

        .gantt-bar.gb-pending {
            background: linear-gradient(135deg, #fbbf24, #f59e0b)
        }

        .gantt-bar.gb-overdue {
            background: linear-gradient(135deg, #f87171, #ef4444)
        }

        .gantt-bar.gb-done {
            background: linear-gradient(135deg, #34d399, #10b981)
        }

        /* Today line */
        .gantt-today {
            position: absolute;
            top: 0;
            bottom: 0;
            width: 1.5px;
            background: #ef4444;
            z-index: 3;
            pointer-events: none;
            opacity: .6
        }

        /* Tooltip on tap */
        .gantt-tip {
            position: absolute;
            bottom: calc(100% + 6px);
            left: 50%;
            transform: translateX(-50%);
            background: #1e293b;
            color: #fff;
            font-size: .66rem;
            font-weight: 500;
            padding: 3px 8px;
            border-radius: 5px;
            white-space: nowrap;
            z-index: 20;
            pointer-events: none;
            opacity: 0;
            transition: opacity .15s
        }

        .gantt-tip::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 4px solid transparent;
            border-top-color: #1e293b
        }

        .gantt-bar.active .gantt-tip {
            opacity: 1
        }

        /* Edge-clamp tooltip */
        .gantt-bar.tip-left .gantt-tip {
            left: 0;
            transform: translateX(0)
        }

        .gantt-bar.tip-left .gantt-tip::after {
            left: 12px;
            transform: none
        }

        .gantt-bar.tip-right .gantt-tip {
            left: auto;
            right: 0;
            transform: translateX(0)
        }

        .gantt-bar.tip-right .gantt-tip::after {
            left: auto;
            right: 12px;
            transform: none
        }

        @media(max-width:768px) {
            .gantt-tip {
                font-size: .62rem;
                padding: 3px 6px
            }
        }

        /* Legend */
        .gantt-legend {
            display: flex;
            gap: .5rem;
            margin-top: .45rem;
            padding-top: .35rem;
            border-top: 1px solid #f1f5f9;
            font-size: .6rem;
            color: #94a3b8;
            flex-wrap: wrap
        }

        .gantt-legend span {
            display: flex;
            align-items: center;
            gap: .18rem
        }

        .gantt-legend-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            display: inline-block
        }

        /* ── Comments ───────────────────────────────────── */

        .comment-section {
            margin-top: .65rem;
            padding-top: .55rem;
            border-top: 1px dashed #e2e8f0
        }

        .comment-toggle {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            font-size: .76rem;
            color: #64748b;
            cursor: pointer;
            user-select: none;
            padding: .2rem 0;
            font-weight: 500
        }

        .comment-toggle:hover {
            color: #2563eb
        }

        .comment-toggle .cnt {
            background: #e2e8f0;
            color: #475569;
            padding: 0 5px;
            border-radius: 9px;
            font-size: .65rem;
            font-weight: 600;
            min-width: 18px;
            text-align: center
        }

        .comment-body {
            display: none;
            margin-top: .4rem
        }

        .comment-body.open {
            display: block
        }

        .comment-list {
            display: flex;
            flex-direction: column;
            gap: .35rem;
            margin-bottom: .4rem;
            max-height: 240px;
            overflow-y: auto
        }

        .comment-item {
            padding: .4rem .55rem;
            background: #f8fafc;
            border-radius: 6px;
            border: 1px solid #f1f5f9;
            position: relative
        }

        .comment-item.mine {
            background: #eff6ff;
            border-color: #dbeafe
        }

        .comment-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .3rem;
            margin-bottom: .15rem
        }

        .comment-author {
            font-size: .7rem;
            font-weight: 600;
            color: #374151
        }

        .comment-time {
            font-size: .62rem;
            color: #94a3b8
        }

        .comment-text {
            font-size: .78rem;
            color: #475569;
            line-height: 1.4;
            white-space: pre-wrap;
            word-break: break-word
        }

        .comment-del {
            cursor: pointer;
            color: #94a3b8;
            font-size: .68rem;
            padding: 1px 3px;
            border-radius: 3px
        }

        .comment-del:hover {
            color: #ef4444;
            background: #fef2f2
        }

        .comment-input-row {
            display: flex;
            gap: .35rem;
            align-items: flex-end
        }

        .comment-input {
            flex: 1;
            padding: .4rem .6rem;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: .8rem;
            font-family: inherit;
            resize: none;
            min-height: 34px;
            max-height: 80px;
            line-height: 1.4
        }

        .comment-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, .15)
        }

        @media(max-width:768px) {
            .comment-input {
                font-size: 16px
            }
        }

        .comment-send {
            padding: .4rem .6rem;
            border: none;
            border-radius: 6px;
            background: #2563eb;
            color: #fff;
            cursor: pointer;
            font-size: .78rem;
            flex-shrink: 0;
            height: 34px;
            display: flex;
            align-items: center
        }

        .comment-send:hover {
            background: #1d4ed8
        }

        .comment-send:disabled {
            opacity: .5;
            cursor: not-allowed
        }

        .comment-empty {
            font-size: .74rem;
            color: #94a3b8;
            padding: .3rem 0
        }

        .text-muted {
            color: #94a3b8;
            font-size: .76rem
        }

        /* ── Tab Bar ────────────────────────────────────── */

        .tab-bar {
            display: flex;
            border-bottom: 2px solid #e2e8f0;
            background: #fff;
            padding: 0 1.25rem
        }

        .tab-btn {
            padding: .65rem 1rem;
            border: none;
            background: none;
            color: #64748b;
            font-size: .95rem;
            font-weight: 500;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            transition: all .15s;
            display: flex;
            align-items: center;
            gap: .35rem
        }

        .tab-btn .tab-short {
            display: none
        }

        .tab-btn.active {
            color: #2563eb;
            border-bottom-color: #2563eb;
            font-weight: 600
        }

        .tab-btn:hover:not(.active) {
            color: #374151;
            background: #f8fafc
        }

        @media(max-width:768px) {
            .tab-bar {
                padding: 0 .3rem;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none
            }

            .tab-bar::-webkit-scrollbar {
                display: none
            }

            .tab-btn {
                flex: 1;
                justify-content: center;
                padding: .7rem .35rem;
                font-size: .88rem;
                white-space: nowrap
            }

            .tab-btn .tab-full {
                display: none
            }

            .tab-btn .tab-short {
                display: inline
            }

            .tab-btn i {
                display: none
            }

            #myTasksList {
                padding: .75rem .75rem !important
            }
        }

        /* ── My Tasks ───────────────────────────────────── */

        .my-tasks-summary {
            display: flex;
            gap: .8rem;
            padding: .7rem 1.25rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            flex-wrap: wrap;
            align-items: center
        }

        .my-tasks-stat {
            font-size: .8rem;
            color: #475569;
            display: flex;
            align-items: center;
            gap: .25rem
        }

        .my-tasks-stat strong {
            font-size: .95rem;
            color: #0f172a
        }

        .my-tasks-stat .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block
        }

        @media(max-width:768px) {
            .my-tasks-summary {
                padding: .65rem .85rem;
                gap: .65rem
            }

            .my-tasks-stat {
                font-size: .85rem;
                gap: .3rem
            }

            .my-tasks-stat strong {
                font-size: 1.05rem
            }

            .my-tasks-stat .dot {
                width: 9px;
                height: 9px
            }
        }

        .mt-card {
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: .75rem 1rem;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            margin-bottom: .5rem;
            cursor: pointer;
            transition: all .15s;
            box-shadow: 0 1px 2px rgba(0,0,0,.04)
        }

        .mt-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 2px 8px rgba(0,0,0,.07);
            transform: translateY(-1px)
        }

        .mt-card-index {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .78rem;
            font-weight: 700;
            flex-shrink: 0;
            background: #f1f5f9;
            color: #475569;
            border: 2px solid #e2e8f0
        }

        .mt-card-info {
            flex: 1;
            min-width: 0
        }

        .mt-card-title {
            font-size: .88rem;
            font-weight: 600;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis
        }

        .mt-card-sub {
            font-size: .72rem;
            color: #64748b;
            margin-top: .1rem;
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
            align-items: center
        }

        .mt-card-progress {
            display: flex;
            align-items: center;
            gap: .45rem;
            margin-top: .35rem
        }

        .mt-card-progress-bar {
            flex: 1;
            max-width: 120px;
            height: 5px;
            background: #e2e8f0;
            border-radius: 999px;
            overflow: hidden
        }

        .mt-card-progress-fill {
            height: 100%;
            border-radius: 999px;
            transition: width .3s
        }

        .mt-card-progress-fill.pf-green { background: #059669 }
        .mt-card-progress-fill.pf-amber { background: #d97706 }
        .mt-card-progress-fill.pf-red { background: #dc2626 }

        .mt-card-progress-text {
            font-size: .7rem;
            color: #64748b;
            font-weight: 500;
            white-space: nowrap
        }

        .mt-card-right {
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: .3rem
        }

        .mt-card-overdue {
            font-size: .66rem;
            color: #dc2626;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: .2rem
        }

        @media(max-width:768px) {
            .mt-card {
                padding: .9rem 1rem;
                gap: .7rem;
                border-radius: 12px;
                margin-bottom: .65rem
            }

            .mt-card-title {
                font-size: .95rem;
                white-space: normal
            }

            .mt-card-sub {
                font-size: .78rem;
                margin-top: .2rem;
                gap: .6rem
            }

            .mt-card-index {
                width: 32px;
                height: 32px;
                font-size: .8rem
            }

            .mt-card-progress {
                margin-top: .45rem
            }

            .mt-card-progress-bar {
                max-width: none;
                height: 8px
            }

            .mt-card-progress-text {
                font-size: .78rem;
                font-weight: 600
            }

            .mt-card-right {
                gap: .4rem
            }

            .mt-card-right .badge {
                font-size: .72rem;
                padding: .18rem .55rem
            }

            .mt-card-overdue {
                font-size: .74rem
            }
        }

        .mt-view-link {
            font-size: .72rem;
            color: #2563eb;
            text-decoration: none;
            font-weight: 500;
            white-space: nowrap
        }

        .mt-view-link:hover {
            color: #1d4ed8;
            text-decoration: underline
        }

        /* ── My App Listing ──────────────────────────── */

        .mal-toolbar {
            padding: .7rem 1.25rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0
        }

        .mal-toolbar .search-input {
            max-width: 320px
        }

        @media(max-width:768px) {
            .mal-toolbar {
                padding: .6rem .75rem
            }
            .mal-toolbar .search-input {
                max-width: none;
                width: 100%
            }
        }

        .mal-group-title {
            font-size: .85rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: .04em;
            padding: .7rem 0 .3rem;
            border-bottom: 1px solid #f1f5f9;
            margin-bottom: .5rem
        }

        .mal-card {
            display: flex;
            align-items: center;
            gap: .9rem;
            padding: 1.1rem 1.15rem;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            margin-bottom: .55rem;
            cursor: pointer;
            transition: all .15s
        }

        .mal-card:hover {
            border-color: #3b82f6;
            background: #fafbff
        }

        .mal-card:active {
            transform: scale(.995)
        }

        .mal-card-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            color: #2563eb;
            flex-shrink: 0
        }

        .mal-card-info {
            flex: 1;
            min-width: 0
        }

        .mal-card-title {
            font-size: 1.12rem;
            font-weight: 600;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis
        }

        .mal-card-section {
            font-size: .9rem;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis
        }

        .mal-card-status {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: .25rem;
            flex-shrink: 0
        }

        .mal-badge {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            font-size: .72rem;
            font-weight: 600;
            padding: .15rem .5rem;
            border-radius: 999px;
            white-space: nowrap;
            border: 1px solid transparent
        }

        .mal-badge i {
            font-size: .66rem
        }

        .mal-badge-ok {
            color: #047857;
            background: #ecfdf5;
            border-color: #a7f3d0
        }

        .mal-badge-warn {
            color: #b45309;
            background: #fffbeb;
            border-color: #fde68a
        }

        .mal-badge-none {
            color: #64748b;
            background: #f1f5f9;
            border-color: #e2e8f0
        }

        .mal-card-actions {
            display: flex;
            gap: .4rem;
            flex-wrap: wrap;
            justify-content: flex-end;
            flex-shrink: 0
        }

        @media(max-width:768px) {
            .mal-card {
                gap: .8rem;
                padding: .95rem .9rem;
                flex-wrap: wrap
            }
            .mal-card-actions {
                width: 100%;
                justify-content: flex-start;
                margin-top: .1rem
            }
            .mal-card-actions .btn {
                flex: 1;
                justify-content: center;
                min-width: 0
            }
            .mal-card-icon {
                width: 44px;
                height: 44px;
                font-size: 1.05rem
            }
            .mal-card-title {
                font-size: 1.05rem
            }
            .mal-card-section {
                font-size: .85rem
            }
            .mal-badge {
                font-size: .68rem;
                padding: .12rem .42rem
            }
        }

        .mal-empty {
            text-align: center;
            padding: 2.5rem 1rem;
            color: #94a3b8;
            font-size: .83rem
        }

        .mal-count {
            font-size: .82rem;
            color: #94a3b8;
            padding: .4rem 0 0
        }

        /* Reference materials */
        .rm-upload {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: .9rem;
            margin-bottom: 1rem
        }

        .rm-upload-title {
            font-size: .85rem;
            font-weight: 600;
            color: #334155;
            margin-bottom: .7rem;
            display: flex;
            align-items: center;
            gap: .4rem
        }

        .rm-list {
            display: flex;
            flex-direction: column;
            gap: .55rem
        }

        .rm-item {
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: .7rem .8rem;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px
        }

        .rm-item-icon {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            background: #eff6ff;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0
        }

        .rm-item-info {
            flex: 1;
            min-width: 0
        }

        .rm-item-title {
            font-size: .9rem;
            font-weight: 600;
            color: #0f172a
        }

        .rm-item-desc {
            font-size: .8rem;
            color: #475569;
            margin-top: .1rem;
            white-space: pre-wrap
        }

        .rm-item-meta {
            display: flex;
            flex-direction: column;
            gap: .12rem;
            font-size: .72rem;
            color: #94a3b8;
            margin-top: .25rem;
            word-break: break-word
        }

        .rm-item-added {
            color: #64748b;
            font-weight: 500
        }

        .rm-item-added i {
            margin-right: .3rem
        }

        .rm-item-actions {
            display: flex;
            gap: .35rem;
            flex-shrink: 0
        }

        .rm-item-actions .btn {
            text-decoration: none
        }

        .tab-section {
            display: none
        }

        .tab-section.active {
            display: block
        }

        /* ── Simple User Detail View ────────────────────── */

        .simple-progress {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1.5rem;
            padding: 2rem 1.25rem;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(135deg, #f8fafc 0%, #f0f9ff 100%)
        }

        .sp-ring {
            position: relative;
            width: 130px;
            height: 130px;
            flex-shrink: 0
        }

        .sp-ring svg {
            transform: rotate(-90deg);
            width: 130px;
            height: 130px
        }

        .sp-ring circle {
            fill: none;
            stroke-width: 8;
            stroke-linecap: round
        }

        .sp-ring .sp-bg {
            stroke: #e2e8f0
        }

        .sp-ring .sp-fill {
            transition: stroke-dashoffset .6s ease
        }

        .sp-ring-text {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0
        }

        .sp-ring-pct {
            font-size: 2.1rem;
            font-weight: 900;
            line-height: 1;
            letter-spacing: -.03em
        }

        .sp-ring-pct.clr-green { color: #059669 }
        .sp-ring-pct.clr-blue  { color: #2563eb }
        .sp-ring-pct.clr-amber { color: #d97706 }

        .sp-ring-label {
            font-size: .6rem;
            color: #94a3b8;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: .05em;
            margin-top: 3px
        }

        .sp-detail {
            text-align: left
        }

        .sp-detail-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: .25rem
        }

        .sp-detail-sub {
            font-size: .82rem;
            color: #64748b
        }

        .sp-detail-tally {
            font-size: .95rem;
            font-weight: 700;
            color: #374151;
            margin-top: .35rem
        }

        @media(max-width:768px) {
            .simple-progress {
                padding: 1.4rem .8rem;
                gap: 1.1rem
            }

            .sp-ring {
                width: 110px;
                height: 110px
            }

            .sp-ring svg {
                width: 110px;
                height: 110px
            }

            .sp-ring-pct {
                font-size: 1.75rem
            }

            .sp-detail-title {
                /* font-size: .92rem */
                 font-size: 1.05rem
            }

            .sp-detail-tally {
                font-size: .85rem
            }
        }

        /* Simple task rows */
        .st-list {
            padding: .65rem 1.25rem
        }

        @media(max-width:768px) {
            .st-list {
                padding: .5rem .6rem
            }
        }

        .st-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            margin-bottom: .6rem;
            overflow: hidden;
            transition: all .15s;
            background: #fff
        }

        .st-card.st-completed {
            background: #f0fdf4;
            border-color: #bbf7d0
        }

        .st-card.st-active {
            background: #fff;
            border-left: 4px solid #f59e0b
        }

        .st-card.st-active.st-overdue {
            border-left-color: #ef4444
        }

        /* ── Main row: check + name + due date ── */
        .st-main {
            display: flex;
            align-items: flex-start;
            gap: .75rem;
            padding: 1.1rem 1rem .65rem
        }

        .st-check {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: .05rem;
            font-size: .95rem
        }

        .st-check.chk-done {
            background: #d1fae5;
            color: #059669
        }

        .st-check.chk-pending {
            background: #fff;
            color: #94a3b8;
            border: 2px solid #cbd5e1
        }

        .st-info {
            flex: 1;
            min-width: 0
        }

        .st-name {
            font-size: 1rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.38
        }

        .st-card.st-completed .st-name {
            color: #059669
        }

        /* ── Meta row: start date / deadline ── */
        .st-meta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: .18rem .7rem;
            margin-top: .28rem
        }

        .st-meta-item {
            display: inline-flex;
            align-items: center;
            gap: .28rem;
            font-size: .77rem;
            font-weight: 500;
            color: #94a3b8
        }

        .st-meta-item > i { font-size: .65rem }

        .st-meta-item.deadline-overdue { color: #dc2626 }
        .st-meta-item.deadline-soon    { color: #d97706 }
        .st-meta-item.deadline-ok      { color: #94a3b8 }

        /* ── Card body: description + assigned team ── */
        .st-body {
            padding: 0 1rem .4rem;
            counter-reset: stx
        }

        .st-desc {
            font-size: .89rem;
            color: #475569;
            line-height: 1.55;
            white-space: pre-line;
            background: #f8fafc;
            border: 1px solid #eef2f7;
            border-left: 3px solid #cbd5e1;
            border-radius: 8px;
            padding: .55rem .7rem;
            margin-bottom: .65rem
        }

        .st-card.st-completed .st-desc {
            background: #f7fdfa;
            border-color: #dcfce7;
            border-left-color: #86efac
        }

        /* Long descriptions collapse to a few lines; JS reveals the toggle only when it overflows. */
        .st-desc-wrap {
            margin-bottom: .65rem
        }

        .st-desc-wrap .st-desc {
            margin-bottom: 0
        }

        .st-desc.st-desc-clamp {
            display: -webkit-box;
            -webkit-line-clamp: 8;
            -webkit-box-orient: vertical;
            overflow: hidden
        }

        .st-desc-more {
            display: none;
            align-items: center;
            gap: .3rem;
            margin-top: .35rem;
            padding: .1rem .2rem;
            background: none;
            border: 0;
            font-size: .78rem;
            font-weight: 600;
            color: #2563eb;
            cursor: pointer
        }

        .st-desc-more.show {
            display: inline-flex
        }

        .st-desc-more:hover {
            text-decoration: underline
        }

        .st-desc-more i {
            font-size: .65rem
        }

        .st-team-head {
            display: flex;
            align-items: center;
            gap: .35rem;
            font-size: .75rem;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: #94a3b8;
            margin-bottom: .45rem
        }

        .st-team-tally {
            margin-left: auto;
            text-transform: none;
            letter-spacing: 0;
            font-size: .75rem;
            font-weight: 600;
            color: #475569;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 999px;
            padding: .05rem .45rem
        }

        .st-cat + .st-cat {
            border-top: 1px dashed #e9eef4;
            margin-top: .45rem;
            padding-top: .45rem
        }

        .st-cat-name {
            display: flex;
            align-items: center;
            gap: .35rem;
            font-size: .85rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: .4rem
        }

        .st-cat-name > i {
            font-size: .78rem;
            color: #94a3b8
        }

        .st-staff {
            list-style: none;
            margin: 0;
            padding: 0
        }

        .st-staff-item {
            counter-increment: stx;
            display: flex;
            align-items: center;
            gap: .5rem;
            padding: .42rem .15rem;
            font-size: .92rem;
            color: #475569;
            border-bottom: 1px solid #f4f7fa
        }

        .st-staff-item:last-child { border-bottom: 0 }

        .st-staff-item::before {
            content: counter(stx) ".";
            flex-shrink: 0;
            width: 1.35rem;
            text-align: right;
            font-size: .85rem;
            font-weight: 600;
            color: #94a3b8
        }

        .st-staff-item > i { flex-shrink: 0; font-size: .88rem }

        .st-staff-item.done { color: #065f46 }

        .st-staff-name {
            min-width: 0;
            overflow-wrap: anywhere
        }

        .st-staff-item.is-me .st-staff-name {
            font-weight: 700;
            color: #1d4ed8
        }

        .st-tag {
            flex-shrink: 0;
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .03em;
            line-height: 1.55;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 0 .3rem;
            white-space: nowrap
        }

        .st-tag.t-you   { color: #1d4ed8; background: #eff6ff; border-color: #bfdbfe }

        /* ── Grouped footer: info | feedbacks | action button ── */
        .st-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: .55rem;
            padding: .55rem 1rem .75rem;
            border-top: 1px solid #f1f5f9
        }

        .st-comment-btn {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            padding: 0;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #64748b;
            border-radius: 12px;
            cursor: pointer;
            font-size: 1.35rem;
            line-height: 1;
            transition: all .15s
        }

        .st-comment-btn:hover {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #2563eb
        }

        .st-comment-btn .cnt {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #e2e8f0;
            color: #475569;
            padding: 0 5px;
            border-radius: 9px;
            font-size: .62rem;
            font-weight: 700;
            min-width: 16px;
            height: 16px;
            line-height: 16px;
            text-align: center
        }

        .st-comment-btn .cnt.hide { display: none }

        .st-comment-btn:hover .cnt {
            background: #bfdbfe;
            color: #1d4ed8
        }

        /* ── Tick-only Mark Done button ── */
        .st-mark-btn {
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            background: #059669;
            color: #fff;
            transition: all .2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 1rem;
            box-shadow: 0 2px 8px rgba(5,150,105,.35)
        }

        .st-mark-btn:hover {
            background: #047857;
            transform: scale(1.08);
            box-shadow: 0 3px 12px rgba(5,150,105,.45)
        }

        .st-mark-btn:active { transform: scale(.96) }

        /* ── Done · Undo button ── */
        .st-undo-btn {
            padding: .38rem .75rem;
            border: 1.5px solid #bbf7d0;
            border-radius: 20px;
            font-size: .76rem;
            font-weight: 600;
            cursor: pointer;
            background: #f0fdf4;
            color: #059669;
            transition: all .15s;
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            white-space: nowrap;
            flex-shrink: 0
        }

        .st-undo-btn:hover {
            background: #dcfce7;
            border-color: #86efac;
            color: #047857
        }

        @media(max-width:768px) {
            .st-mark-btn {
                width: 42px;
                height: 42px;
                font-size: 1.05rem
            }
            .st-undo-btn {
                padding: .4rem .8rem
            }
        }

        /* ── Comment Modal (Bottom Sheet) ───────────────── */

        .cm-modal {
            z-index: 100002
        }

        .cm-sheet {
            position: fixed;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 100%;
            max-width: 560px;
            max-height: 85vh;
            background: #fff;
            border-radius: 16px 16px 0 0;
            box-shadow: 0 -8px 40px rgba(0, 0, 0, .15);
            display: flex;
            flex-direction: column;
            animation: cmSlideUp .25s ease-out
        }

        @keyframes cmSlideUp {
            from {
                transform: translateX(-50%) translateY(100%)
            }

            to {
                transform: translateX(-50%) translateY(0)
            }
        }

        @media(min-width:769px) {
            .cm-sheet {
                position: relative;
                bottom: auto;
                left: auto;
                transform: none;
                margin: 4vh auto;
                border-radius: 12px;
                max-height: 80vh;
                animation: cmFadeIn .2s ease-out
            }

            @keyframes cmFadeIn {
                from {
                    opacity: 0;
                    transform: scale(.97)
                }

                to {
                    opacity: 1;
                    transform: scale(1)
                }
            }
        }

        .cm-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: .75rem 1rem;
            border-bottom: 1px solid #e2e8f0;
            flex-shrink: 0;
            background: #fff;
            border-radius: 16px 16px 0 0
        }

        @media(min-width:769px) {
            .cm-header {
                border-radius: 12px 12px 0 0
            }
        }

        .cm-header::before {
            content: '';
            position: absolute;
            top: 6px;
            left: 50%;
            transform: translateX(-50%);
            width: 36px;
            height: 4px;
            border-radius: 2px;
            background: #d1d5db
        }

        @media(min-width:769px) {
            .cm-header::before {
                display: none
            }
        }

        .cm-header-left {
            display: flex;
            align-items: center;
            gap: .5rem;
            flex: 1;
            min-width: 0
        }

        .cm-header-title {
            font-size: .88rem;
            font-weight: 700;
            color: #0f172a
        }

        .cm-header-sub {
            font-size: .7rem;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 260px
        }

        .cm-body {
            flex: 1;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding: .65rem .85rem;
            min-height: 120px;
            overscroll-behavior: contain
        }

        .cm-loading {
            text-align: center;
            padding: 2rem;
            color: #94a3b8;
            font-size: .83rem
        }

        .cm-empty {
            text-align: center;
            padding: 2.5rem 1rem;
            color: #94a3b8
        }

        .cm-empty i {
            font-size: 1.8rem;
            display: block;
            margin-bottom: .5rem;
            color: #cbd5e1
        }

        .cm-empty span {
            font-size: .82rem
        }

        /* ── Comment Items ──────────────────────────────── */

        .cm-item {
            padding: .55rem 0;
            border-bottom: 1px solid #f8fafc
        }

        .cm-item:last-child {
            border-bottom: none
        }

        .cm-item-head {
            display: flex;
            align-items: center;
            gap: .35rem;
            margin-bottom: .2rem
        }

        .cm-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #e0e7ff;
            color: #4338ca;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .6rem;
            font-weight: 700;
            flex-shrink: 0;
            text-transform: uppercase
        }

        .cm-reply .cm-avatar {
            width: 22px;
            height: 22px;
            font-size: .5rem
        }

        .cm-author {
            font-size: .76rem;
            font-weight: 600;
            color: #1e293b
        }

        .cm-time {
            font-size: .62rem;
            color: #94a3b8
        }

        .cm-item-body {
            padding-left: calc(28px + .35rem);
            font-size: .82rem;
            color: #374151;
            line-height: 1.45;
            white-space: pre-wrap;
            word-break: break-word
        }

        .cm-reply .cm-item-body {
            padding-left: calc(22px + .35rem)
        }

        .cm-item-actions {
            padding-left: calc(28px + .35rem);
            display: flex;
            gap: .6rem;
            margin-top: .2rem
        }

        .cm-reply .cm-item-actions {
            padding-left: calc(22px + .35rem)
        }

        .cm-action-btn {
            font-size: .68rem;
            color: #94a3b8;
            cursor: pointer;
            background: none;
            border: none;
            padding: .1rem 0;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: .2rem
        }

        .cm-action-btn:hover {
            color: #2563eb
        }

        .cm-action-btn.del:hover {
            color: #ef4444
        }

        /* Done toggle for top-level comments */
        .cm-done-toggle {
            margin-left: auto;
            cursor: pointer;
            background: none;
            border: 1.5px solid #cbd5e1;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: transparent;
            font-size: .6rem;
            transition: all .15s;
            padding: 0
        }

        .cm-done-toggle:hover {
            border-color: #059669;
            color: #a7f3d0
        }

        .cm-done-toggle.is-done {
            background: #059669;
            border-color: #059669;
            color: #fff
        }

        .cm-done-toggle.is-done:hover {
            background: #047857;
            border-color: #047857
        }

        .cm-done-info {
            padding-left: calc(28px + .35rem);
            font-size: .6rem;
            color: #059669;
            margin-top: .1rem;
            font-weight: 500
        }

        /* Replies */
        .cm-replies {
            margin-left: calc(28px + .35rem);
            padding-left: .6rem;
            border-left: 2px solid #e2e8f0;
            margin-top: .3rem
        }

        .cm-reply {
            padding: .35rem 0
        }

        .cm-reply:last-child {
            border-bottom: none
        }

        .cm-reply .cm-author {
            font-size: .72rem
        }

        .cm-reply .cm-item-body {
            font-size: .78rem
        }

        /* Reply indicator bar */
        .cm-reply-indicator {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: .35rem .85rem;
            background: #eff6ff;
            border-top: 1px solid #dbeafe;
            flex-shrink: 0
        }

        .cm-reply-label {
            font-size: .72rem;
            color: #2563eb;
            display: flex;
            align-items: center;
            gap: .3rem
        }

        .cm-reply-cancel {
            background: none;
            border: none;
            color: #64748b;
            cursor: pointer;
            padding: .15rem .3rem;
            border-radius: 4px;
            font-size: .72rem
        }

        .cm-reply-cancel:hover {
            background: #dbeafe;
            color: #1e40af
        }

        /* Footer input */
        .cm-footer {
            display: flex;
            align-items: flex-end;
            gap: .4rem;
            padding: .55rem .85rem;
            border-top: 1px solid #e2e8f0;
            background: #fff;
            flex-shrink: 0;
            padding-bottom: max(.55rem, env(safe-area-inset-bottom, .55rem))
        }

        .cm-input {
            flex: 1;
            padding: .5rem .7rem;
            border: 1px solid #cbd5e1;
            border-radius: 20px;
            font-size: .83rem;
            font-family: inherit;
            resize: none;
            min-height: 38px;
            max-height: 100px;
            line-height: 1.4;
            background: #f8fafc;
            transition: border-color .15s
        }

        .cm-input:focus {
            outline: none;
            border-color: #3b82f6;
            background: #fff;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, .1)
        }

        @media(max-width:768px) {
            .cm-input {
                font-size: 16px
            }
        }

        .cm-send {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: none;
            background: #2563eb;
            color: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .82rem;
            flex-shrink: 0;
            transition: all .15s
        }

        .cm-send:hover {
            background: #1d4ed8
        }

        .cm-send:disabled {
            opacity: .4;
            cursor: not-allowed
        }

        .cm-item.is-mine .cm-avatar {
            background: #dbeafe;
            color: #1e40af
        }

        .cm-mention {
            color: #2563eb;
            font-weight: 600;
            background: #eff6ff;
            padding: 0 3px;
            border-radius: 3px;
            font-size: .78rem
        }

        .cm-reply .cm-mention {
            font-size: .74rem
        }
    </style>
</head>

<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="container">
            <div class="header">
                <h1><i class="fas fa-clipboard-check"></i> App Tracker</h1>
                <div class="subtitle"><?= ucwords(strtolower($currentUser['fullname'])) ?> &middot; <?= ucwords(strtolower($currentUser['company_name'])) ?></div>
            </div>
            <div id="alertBox"></div>

            <?php if ($view === 'list'): ?>
                <div class="tab-bar">
                     <button class="tab-btn active" id="tabMyApps" onclick="switchTab('apps')"><i class="fas fa-th-large"></i> <span class="tab-full">My App Listing</span><span class="tab-short">My Apps</span></button>
                    <button class="tab-btn" id="tabMyTasks" onclick="switchTab('my')"><i class="fas fa-user-check"></i> <span class="tab-full">My Tasks</span><span class="tab-short">Tasks</span></button>
                    <?php if ($showManagement): ?>
                    <button class="tab-btn" id="tabManage" onclick="switchTab('manage')"><i class="fas fa-cogs"></i> <span class="tab-full">Management</span><span class="tab-short">Manage</span></button>
                    <button class="tab-btn" id="tabMgmtReport" onclick="switchTab('mreport')"><i class="fas fa-file-lines"></i> <span class="tab-full">Management Report</span><span class="tab-short">Report</span></button>
                    <?php endif; ?>
                </div>

                <!-- My App Listing View -->
                <div class="tab-section active" id="sectionMyApps">
                    <div class="mal-toolbar">
                        <input type="text" id="malSearch" class="search-input" placeholder="Search applications..." oninput="filterMyApps()">
                    </div>
                    <div id="myAppsList" style="padding:.85rem 1.25rem"></div>
                </div>

                <!-- My Tasks View -->
                <div class="tab-section" id="sectionMyTasks">
                    <div id="myTasksSummary" class="my-tasks-summary" style="display:none"></div>
                    <div id="myTasksList" style="padding:.85rem 1.25rem"></div>
                </div>

                <!-- Management View -->
                <?php if ($showManagement): ?>
                <div class="tab-section" id="sectionManage">
                    <div class="toolbar">
                        <div class="toolbar-left">
                            <input type="text" id="searchInput" class="search-input" placeholder="Search apps..." oninput="debounce(loadTrackers,'s')">
                            <select id="statusFilter">
                                <option value="">All Statuses</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                                <option value="on_hold">On Hold</option>
                            </select>
                            <?php if ($isAdmin && count($companies) > 1): ?>
                                <select id="compFilter">
                                    <option value="">All Companies</option><?php foreach ($companies as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                            <div class="view-toggle"><button class="view-btn" id="btnTbl" onclick="setView('table')"><i class="fas fa-list"></i></button><button class="view-btn" id="btnCrd" onclick="setView('card')"><i class="fas fa-th-large"></i></button></div>
                        </div>
                        <button class="btn btn-primary" onclick="openCreateModal()"><i class="fas fa-plus"></i> New Implementation</button>
                    </div>
                    <div id="listWrap"></div>
                </div>
                <?php endif; ?>

                <!-- Management Report View -->
                <?php if ($showManagement): ?>
                <div class="tab-section" id="sectionMgmtReport">
                    <div id="mgmtReportList">
                        <div class="mal-toolbar">
                            <input type="text" id="mgmtReportSearch" class="search-input" placeholder="Search applications..." oninput="filterMgmtReportApps()">
                        </div>
                        <div id="mgmtReportApps" style="padding:.85rem 1.25rem"></div>
                    </div>
                    <div id="mgmtReportView" style="display:none">
                        <div style="padding:.85rem 1.25rem">
                            <a href="javascript:void(0)" class="back-link" onclick="closeMgmtReport()"><i class="fas fa-arrow-left"></i> Back to Reports</a>
                            <div id="mgmtReportHeader" style="margin-top:.75rem"></div>
                            <div id="mgmtReportBody"></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            <?php elseif ($view === 'detail' && $trackerDetail): ?>
                <a href="?view=list" class="back-link"><i class="fas fa-arrow-left"></i> Back to List</a>

                <?php
                // ── Compute user-specific data ──
                $myTasks = [];
                $myTotal = 0;
                $myDone = 0;
                foreach ($trackerDetail['tasks'] as $_t) {
                    $assigned = false;
                    $userCompleted = false;
                    foreach ($_t['categories'] as $_c) {
                        foreach ($_c['assignees'] as $_a) {
                            if ($_a['person'] === $currentUser['person'] && (int)$_a['comp_id'] === (int)$currentUser['comp_id']) {
                                $assigned = true;
                                if ($_a['completed']) $userCompleted = true;
                            }
                        }
                    }
                    if ($assigned) {
                        $_t['_user_completed'] = $userCompleted;
                        $myTasks[] = $_t;
                        $myTotal++;
                        if ($userCompleted) $myDone++;
                    }
                }
                $myPct = $myTotal > 0 ? round(($myDone / $myTotal) * 100) : 0;

                $myClr = $myPct === 100 ? 'green' : ($myPct >= 50 ? 'blue' : 'amber');
                $strokeColors = ['green' => '#059669', 'blue' => '#3b82f6', 'amber' => '#d97706'];
                $circumference = 2 * 3.14159 * 43;
                $strokeOffset = $circumference - ($circumference * $myPct / 100);
                ?>

                <?php if ($simpleView): ?>
                <!-- ═══ SIMPLE USER VIEW ═══ -->
                <div class="detail-header">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.4rem">
                        <div style="flex:1;min-width:0">
                            <div class="detail-title"><?= htmlspecialchars($trackerDetail['application_title']) ?></div>
                            <div class="detail-section"><?= htmlspecialchars($trackerDetail['application_section']) ?> &middot; <?= htmlspecialchars($trackerDetail['company_name']) ?></div>
                            <span class="badge badge-<?= $trackerDetail['status'] ?>" style="margin-top:.3rem"><?= ucwords(str_replace('_', ' ', $trackerDetail['status'])) ?></span>
                        </div>
                        <!-- <?php if ($canManage): ?>
                            <a href="?view=detail&tracker_id=<?= $trackerDetail['id'] ?>" class="btn btn-secondary btn-sm"><i class="fas fa-expand"></i> Full View</a>
                        <?php endif; ?> -->
                    </div>
                </div>

                <div class="simple-progress">
                    <div class="sp-ring">
                        <svg viewBox="0 0 100 100">
                            <circle class="sp-bg" cx="50" cy="50" r="43"/>
                            <circle class="sp-fill" cx="50" cy="50" r="43" stroke="<?= $strokeColors[$myClr] ?>" stroke-dasharray="<?= round($circumference, 1) ?>" stroke-dashoffset="<?= round($strokeOffset, 1) ?>"/>
                        </svg>
                        <div class="sp-ring-text">
                            <span class="sp-ring-pct clr-<?= $myClr ?>"><?= $myPct ?>%</span>
                            <span class="sp-ring-label"><?= $myDone ?>/<?= $myTotal ?> done</span>
                        </div>
                    </div>
                    <div class="sp-detail">
                        <div class="sp-detail-title">Your Progress</div>
                        <div class="sp-detail-sub"><?= $myTotal - $myDone ?> task<?= ($myTotal - $myDone) !== 1 ? 's' : '' ?> remaining</div>
                        <div class="sp-detail-tally"><?= $myDone === $myTotal && $myTotal > 0 ? '🎉 All done!' : '' ?></div>
                    </div>
                </div>

                <div class="st-list">
                    <?php if (empty($myTasks)): ?>
                        <div class="no-data">No tasks assigned to you in this implementation.</div>
                    <?php else: ?>
                        <?php foreach ($myTasks as $task):
                            $isDone = $task['_user_completed'];
                            $taskDone = ($task['status'] === 'done');
                            $isActive = ($task['status'] !== 'done');
                            $canDo = !$isDone && $isActive;
                            $isOverdue = (!$isDone && $task['deadline'] && strtotime($task['deadline']) < strtotime('today'));
                            $cardCls = $isDone ? 'st-completed' : 'st-active' . ($isOverdue ? ' st-overdue' : '');
                            // Due date classes
                            $dueCls = 'deadline-ok';
                            $dueLabel = '';
                            if ($task['deadline']) {
                                $dl = strtotime($task['deadline']);
                                $diff = ($dl - strtotime('today')) / 86400;
                                if ($diff < 0 && !$isDone) { $dueCls = 'deadline-overdue'; }
                                elseif ($diff <= 3 && !$isDone) { $dueCls = 'deadline-soon'; }
                                $dueLabel = date('d M Y', $dl);
                            }
                            // Unique person list; tally counts people, not assignment rows.
                            $teamCats = ImplTrackerService::uniqueTeamByCategory($task['categories']);
                            $attTotal = 0;
                            $attDone = 0;
                            foreach ($teamCats as $_cat) {
                                foreach ($_cat['assignees'] as $_asg) {
                                    $attTotal++;
                                    if ($_asg['completed']) $attDone++;
                                }
                            }
                        ?>
                            <div class="st-card <?= $cardCls ?>">
                                <div class="st-main">
                                    <div class="st-check <?= $isDone ? 'chk-done' : 'chk-pending' ?>">
                                        <?php if ($isDone): ?><i class="fas fa-check"></i><?php else: ?><i class="far fa-circle" style="font-size:.7rem"></i><?php endif; ?>
                                    </div>
                                    <div class="st-info">
                                        <div class="st-name"><?= htmlspecialchars($task['task_name']) ?></div>
                                        <?php if ($task['start_date'] || $dueLabel): ?>
                                            <div class="st-meta">
                                                <?php if ($task['start_date']): ?>
                                                    <span class="st-meta-item"><i class="fas fa-calendar-alt"></i> Start <?= date('d M Y', strtotime($task['start_date'])) ?></span>
                                                <?php endif; ?>
                                                <?php if ($dueLabel): ?>
                                                    <span class="st-meta-item <?= $dueCls ?>"><i class="fas fa-flag"></i> Due <?= $dueLabel ?><?= ($isOverdue ? ' · Overdue' : '') ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if (trim($task['task_description']) !== '' || $attTotal > 0): ?>
                                    <div class="st-body">
                                        <?php if (trim($task['task_description']) !== ''): ?>
                                            <div class="st-desc-wrap">
                                                <div class="st-desc"><?= htmlspecialchars($task['task_description']) ?></div>
                                                <button type="button" class="st-desc-more" onclick="toggleDesc(this)"><i class="fas fa-chevron-down"></i> Show more</button>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($attTotal > 0): ?>
                                            <div class="st-team-head">
                                                <i class="fas fa-user-group"></i> Assigned Team
                                                <span class="st-team-tally"><?= $attDone ?>/<?= $attTotal ?> done</span>
                                            </div>
                                            <?php foreach ($teamCats as $cat): ?>
                                                <div class="st-cat">
                                                    <div class="st-cat-name"><i class="fas fa-users"></i> <?= htmlspecialchars($cat['category_name']) ?></div>
                                                    <ol class="st-staff">
                                                        <?php foreach ($cat['assignees'] as $a):
                                                            $isMe = ($a['person'] === $currentUser['person'] && (int)$a['comp_id'] === (int)$currentUser['comp_id']);
                                                        ?>
                                                            <li class="st-staff-item <?= $a['completed'] ? 'done' : 'pending' ?> <?= $isMe ? 'is-me' : '' ?>">
                                                                <?php if ($a['completed']): ?><i class="fas fa-check-circle" style="color:#059669"></i><?php else: ?><i class="far fa-circle" style="color:#cbd5e1"></i><?php endif; ?>
                                                                <span class="st-staff-name"><?= htmlspecialchars($a['fullname']) ?></span>
                                                                <?php if ($isMe): ?><span class="st-tag t-you">YOU</span><?php endif; ?>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ol>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="st-footer">
                                    <button class="st-comment-btn" onclick="openCommentModal(<?= $task['id'] ?>, '<?= htmlspecialchars(addslashes($task['task_name']), ENT_QUOTES) ?>')" title="Feedbacks"><i class="far fa-comment"></i><?php if ($task['comment_count'] > 0): ?><span class="cnt"><?= $task['comment_count'] ?></span><?php endif; ?></button>
                                    <?php if ($isDone): ?>
                                        <button class="st-undo-btn" onclick="event.stopPropagation();toggleTaskDone(<?= $task['id'] ?>, true)" title="Mark as undone"><i class="fas fa-check-circle"></i> Undo</button>
                                    <?php elseif ($canDo): ?>
                                        <button class="st-mark-btn" onclick="event.stopPropagation();toggleTaskDone(<?= $task['id'] ?>, false)" title="Mark Done"><i class="fas fa-check"></i></button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php else: ?>
                <!-- ═══ MANAGER FULL VIEW ═══ -->
                <div class="detail-header">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.6rem">
                        <div style="flex:1;min-width:0">
                            <div class="detail-title"><?= htmlspecialchars($trackerDetail['application_title']) ?></div>
                            <div class="detail-section"><?= htmlspecialchars($trackerDetail['application_section']) ?> &middot; <?= htmlspecialchars($trackerDetail['company_name']) ?></div>
                            <span class="badge badge-<?= $trackerDetail['status'] ?>" style="margin-top:.3rem"><?= ucwords(str_replace('_', ' ', $trackerDetail['status'])) ?></span>
                            <?php if ($trackerDetail['remarks']): ?><div style="font-size:.82rem;color:#64748b;margin-top:.3rem"><?= nl2br(htmlspecialchars($trackerDetail['remarks'])) ?></div><?php endif; ?>
                        </div>
                        <div class="detail-actions">
                            <button class="btn btn-success btn-sm" onclick="openAddTaskModal()"><i class="fas fa-plus"></i> Add Task</button>
                            <?php if ($trackerDetail['status'] !== 'on_hold'): ?>
                                <button class="btn btn-warning btn-sm" onclick="updateStatus(<?= $trackerDetail['id'] ?>,'on_hold')"><i class="fas fa-pause"></i> Hold</button>
                            <?php else: ?>
                                <button class="btn btn-primary btn-sm" onclick="updateStatus(<?= $trackerDetail['id'] ?>,'in_progress')"><i class="fas fa-play"></i> Resume</button>
                            <?php endif; ?>
                            <button class="btn btn-danger btn-sm" onclick="deleteTracker(<?= $trackerDetail['id'] ?>)"><i class="fas fa-trash"></i> Delete</button>
                        </div>
                    </div>
                </div>
                <?php
                $totalT = count($trackerDetail['tasks']);
                $doneT = 0;
                foreach ($trackerDetail['tasks'] as $t) {
                    if ($t['status'] === 'done') $doneT++;
                }
                $pct = $totalT > 0 ? round(($doneT / $totalT) * 100) : 0;
                $fc = $pct === 100 ? 'pf-green' : ($pct >= 50 ? 'pf-blue' : 'pf-amber');
                ?>
                <div class="summary-bar">
                    <div class="summary-stat"><strong><?= $doneT ?></strong>/<?= $totalT ?> tasks done</div>
                    <div style="flex:1;max-width:180px">
                        <div class="progress-bar">
                            <div class="progress-fill <?= $fc ?>" style="width:<?= $pct ?>%"></div>
                        </div>
                    </div>
                </div>

                <div class="gantt-toggle" id="ganttToggle" onclick="toggleGantt()">
                    <span class="gantt-toggle-label"><i class="fas fa-chart-gantt"></i> Timeline</span>
                    <i class="fas fa-chevron-down chevron"></i>
                </div>
                <div class="gantt-wrap" id="ganttWrap"></div>

                <div class="task-list">
                    <?php foreach ($trackerDetail['tasks'] as $idx => $task):
                        $isDone = ($task['status'] === 'done');
                        $isActive = ($task['status'] !== 'done');
                        $isOverdue = (!$isDone && $task['deadline'] && strtotime($task['deadline']) < strtotime('today'));
                        $taskCardCls = $isDone ? 's-done' : ($isOverdue ? 's-overdue' : 's-pending');
                        $tcNumCls = $isDone ? 'done' : ($isOverdue ? 'overdue' : 'pending');
                        $badgeCls = $isDone ? 'done' : ($isOverdue ? 'on_hold' : 'pending');
                        $badgeLabel = $isDone ? 'Done' : ($isOverdue ? 'Overdue' : 'Pending');
                        $myAction = false;
                        foreach ($task['categories'] as $cat) {
                            foreach ($cat['assignees'] as $a) {
                                if ($a['person'] === $currentUser['person'] && (int)$a['comp_id'] === (int)$currentUser['comp_id'] && !$a['completed'] && $isActive) $myAction = true;
                            }
                        }
                    ?>
                        <div class="task-card <?= $taskCardCls ?> <?= !$isDone ? 'expanded' : '' ?>" id="task-<?= $task['id'] ?>" data-task-id="<?= $task['id'] ?>">
                            <div class="tc-header" onclick="this.parentElement.classList.toggle('expanded')">
                                <div class="tc-move" onclick="event.stopPropagation()">
                                    <button class="tc-move-btn" <?= $idx === 0 ? 'disabled' : '' ?> onclick="event.stopPropagation();moveTask(<?= $task['id'] ?>,-1)" title="Move up"><i class="fas fa-chevron-up"></i></button>
                                    <button class="tc-move-btn" <?= $idx === $totalT - 1 ? 'disabled' : '' ?> onclick="event.stopPropagation();moveTask(<?= $task['id'] ?>,1)" title="Move down"><i class="fas fa-chevron-down"></i></button>
                                </div>
                                <div class="tc-num <?= $tcNumCls ?>"><?php if ($isDone): ?><i class="fas fa-check" style="font-size:.6rem"></i><?php else: ?><?= $task['task_order'] ?><?php endif; ?></div>
                                <div class="tc-info">
                                    <div class="tc-name"><?= htmlspecialchars($task['task_name']) ?></div>
                                    <div class="tc-desc"><?= htmlspecialchars($task['task_description']) ?></div>
                                </div>
                                <span class="badge badge-<?= $badgeCls ?>"><?= $badgeLabel ?></span>
                                <i class="fas fa-chevron-down tc-toggle"></i>
                            </div>
                            <div class="tc-body">
                                <?php if ($task['start_date'] || $task['deadline']): ?>
                                    <div class="task-dates">
                                        <?php if ($task['start_date']): ?><span><i class="fas fa-calendar-alt"></i> Start: <?= $task['start_date'] ?></span><?php endif; ?>
                                        <?php if ($task['deadline']):
                                            $dl = strtotime($task['deadline']);
                                            $diff = ($dl - strtotime('today')) / 86400;
                                            $dcls = ($diff < 0 && !$isDone) ? 'deadline-overdue' : (($diff <= 3 && !$isDone) ? 'deadline-soon' : '');
                                        ?><span class="<?= $dcls ?>"><i class="fas fa-flag"></i> Deadline: <?= $task['deadline'] ?></span><?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php foreach ($task['categories'] as $cat):
                                    $isFixed = !empty($cat['master_category_id']);
                                ?>
                                    <div class="cat-block <?= $isFixed ? 'fixed' : '' ?>" id="cat-<?= $cat['id'] ?>">
                                        <div class="cat-header">
                                            <span class="cat-name"><i class="fas fa-users"></i> <?= htmlspecialchars($cat['category_name']) ?>
                                                <?php if ($isFixed): ?><span class="cat-fixed-tag">Default</span><?php endif; ?>
                                            </span>
                                            <?php if (!$isDone && !$isFixed): ?><span class="cat-remove" onclick="event.stopPropagation();removeCategory(<?= $cat['id'] ?>,<?= $trackerDetail['id'] ?>)" title="Remove"><i class="fas fa-times"></i></span><?php endif; ?>
                                        </div>
                                        <div class="cat-staff">
                                            <?php if (empty($cat['assignees'])): ?><span class="text-muted">No staff</span>
                                                <?php else: foreach ($cat['assignees'] as $a):
                                                    $cc = $a['completed'] ? 'done' : 'pending';
                                                    $isMe = ($a['person'] === $currentUser['person'] && (int)$a['comp_id'] === (int)$currentUser['comp_id']);
                                                    $canDo = ($isMe && !$a['completed'] && $isActive);
                                                ?>
                                                    <span class="chip <?= $cc ?> <?= $canDo ? 'my-chip' : '' ?>">
                                                        <?php if ($a['completed']): ?><i class="fas fa-check-circle" style="font-size:.63rem;color:#059669"></i><?php else: ?><i class="far fa-circle" style="font-size:.63rem;color:#94a3b8"></i><?php endif; ?>
                                                        <?= htmlspecialchars($a['fullname']) ?><?php if ((int)$a['comp_id'] !== (int)$trackerDetail['comp_id']): ?><span class="chip-comp"><?= htmlspecialchars($a['company_name']) ?></span><?php endif; ?>
                                                        <?php if (!$isDone): ?><span class="rm" onclick="event.stopPropagation();removeStaff(<?= $a['id'] ?>,<?= $trackerDetail['id'] ?>)">&times;</span><?php endif; ?>
                                                    </span>
                                            <?php endforeach;
                                            endif; ?>
                                            <?php if (!$isDone): ?><button class="btn btn-xs btn-secondary" onclick="event.stopPropagation();openAddStaffModal(<?= $cat['id'] ?>,<?= $task['id'] ?>,<?= $trackerDetail['id'] ?>,'<?= htmlspecialchars($cat['category_name'], ENT_QUOTES) ?>')"><i class="fas fa-plus"></i></button><?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <?php if (!$isDone): ?>
                                    <div class="add-cat-row">
                                        <input type="text" class="add-cat-input" id="newcat-<?= $task['id'] ?>" placeholder="New category name...">
                                        <button class="btn btn-sm btn-secondary" onclick="addCategory(<?= $task['id'] ?>,<?= $trackerDetail['id'] ?>)"><i class="fas fa-plus"></i> Add Category</button>
                                    </div>
                                <?php endif; ?>
                                <?php if ($myAction): ?>
                                    <button class="mark-done-btn" onclick="markDone(<?= $task['id'] ?>)"><i class="fas fa-check"></i> Mark as Done</button>
                                <?php endif; ?>

                                <div class="comment-section">
                                    <div class="comment-trigger" onclick="openCommentModal(<?= $task['id'] ?>, '<?= htmlspecialchars(addslashes($task['task_name']), ENT_QUOTES) ?>')">
                                        <i class="far fa-comment"></i> Feedbacks <span class="cnt"><?= $task['comment_count'] ?></span> <i class="fas fa-chevron-right" style="font-size:.55rem;color:#94a3b8;margin-left:auto"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Create Modal -->
    <?php if ($showManagement): ?>
    <div id="createModal" class="modal" data-static>
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-rocket"></i> New Implementation</h3><button class="modal-close" onclick="closeModal('createModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="step1">
                    <div class="form-group"><label class="form-label">Company</label><select id="createComp" class="form-select"><?php foreach ($companies as $c): ?><option value="<?= $c['id'] ?>" <?= $c['id'] == $currentUser['comp_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label class="form-label">Application *</label><select id="createApp" class="form-select">
                            <option value="">-- Select --</option>
                        </select></div>
                    <div class="form-group"><label class="form-label">Remarks</label><textarea id="createRemarks" class="form-textarea" placeholder="Optional notes..."></textarea></div>
                    <div style="text-align:right"><button class="btn btn-primary" onclick="goStep2()">Next <i class="fas fa-arrow-right"></i></button></div>
                </div>
                <div id="step2" style="display:none">
                    <button class="btn btn-secondary btn-sm" onclick="goStep1()" style="margin-bottom:.6rem"><i class="fas fa-arrow-left"></i> Back</button>
                    <p style="font-size:.8rem;color:#64748b;margin-bottom:.6rem">Select tasks and assign staff. Default categories are pre-filled. You can add more custom categories.</p>
                    <div id="taskPickList"></div>
                    <div style="text-align:right;margin-top:.6rem"><button class="btn btn-success" onclick="submitCreate()"><i class="fas fa-check"></i> Create</button></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Add Task Modal (existing tracker) -->
    <?php if ($trackerDetail): ?>
    <div id="addTaskModal" class="modal" data-static>
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Add Task</h3><button class="modal-close" onclick="closeModal('addTaskModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size:.8rem;color:#64748b;margin-bottom:.6rem">Add one or more tasks to this implementation. Use a template or a custom task. New tasks are appended to the end.</p>
                <div id="addTaskBody"></div>
                <div style="text-align:right;margin-top:.6rem"><button class="btn btn-success" onclick="submitAddTasks()"><i class="fas fa-check"></i> Add</button></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div id="staffModal" class="modal" data-static>
        <div class="modal-content" style="max-width:420px">
            <div class="modal-header">
                <h3 id="staffModalTitle">Add Staff</h3><button class="modal-close" onclick="closeModal('staffModal')">&times;</button>
            </div>
            <div class="modal-body"><input type="hidden" id="smCatId"><input type="hidden" id="smTaskId"><input type="hidden" id="smTrackerId">
                <div class="form-group"><label class="form-label">Select Staff</label><select id="smSelect" style="width:100%"></select></div>
            </div>
            <div class="modal-footer"><button class="btn btn-secondary" onclick="closeModal('staffModal')">Cancel</button><button class="btn btn-primary" onclick="submitStaff()"><i class="fas fa-plus"></i> Add</button></div>
        </div>
    </div>

    <!-- Comment Modal -->
    <div id="commentModal" class="modal cm-modal">
        <div class="cm-sheet">
            <div class="cm-header">
                <div class="cm-header-left">
                    <i class="far fa-comment-dots" style="color:#2563eb"></i>
                    <div>
                        <div class="cm-header-title">Feedbacks</div>
                        <div class="cm-header-sub" id="cmTaskName"></div>
                    </div>
                </div>
                <button class="modal-close" onclick="closeCommentModal()">&times;</button>
            </div>
            <div class="cm-body" id="cmBody">
                <div class="cm-loading"><i class="fas fa-spinner fa-spin"></i> Loading feedbacks...</div>
            </div>
            <div class="cm-reply-indicator" id="cmReplyBar" style="display:none">
                <div class="cm-reply-label"><i class="fas fa-reply"></i> Replying to <strong id="cmReplyName"></strong></div>
                <button class="cm-reply-cancel" onclick="cancelReply()"><i class="fas fa-times"></i></button>
            </div>
            <div class="cm-footer">
                <textarea class="cm-input" id="cmInput" placeholder="Write a feedback..." rows="1"></textarea>
                <button class="cm-send" id="cmSendBtn" onclick="postComment()"><i class="fas fa-paper-plane"></i></button>
            </div>
        </div>
    </div>

    <!-- App Remarks Modal -->
    <div id="appDetailModal" class="modal">
        <div class="modal-content" style="max-width:560px">
            <div class="modal-header">
                <h3 id="appDetailTitle" style="font-size:.92rem"><i class="fas fa-info-circle"></i> Application Details</h3>
                <button class="modal-close" onclick="closeModal('appDetailModal')">&times;</button>
            </div>
            <div class="modal-body" id="appDetailBody"></div>
            <div class="modal-footer"><button class="btn btn-secondary" onclick="closeModal('appDetailModal')">Close</button></div>
        </div>
    </div>

    <div id="refMatModal" class="modal">
        <div class="modal-content" style="max-width:620px">
            <div class="modal-header">
                <h3 id="refMatTitle" style="font-size:.92rem"><i class="fas fa-folder-open"></i> Reference Materials</h3>
                <button class="modal-close" onclick="closeModal('refMatModal')">&times;</button>
            </div>
            <div class="modal-body" id="refMatBody"></div>
            <div class="modal-footer"><button class="btn btn-secondary" onclick="closeModal('refMatModal')">Close</button></div>
        </div>
    </div>

    <div id="implProgressModal" class="modal">
        <div class="modal-content" style="max-width:640px">
            <div class="modal-header">
                <h3 id="implProgressTitle" style="font-size:.92rem"><i class="fas fa-list-check"></i> Task Progress</h3>
                <button class="modal-close" onclick="closeModal('implProgressModal')">&times;</button>
            </div>
            <div class="modal-body" id="implProgressBody"></div>
            <div class="modal-footer"><button class="btn btn-secondary" onclick="closeModal('implProgressModal')">Close</button></div>
        </div>
    </div>

    <script>
        var CU = <?= json_encode(['person' => $currentUser['person'], 'comp_id' => $currentUser['comp_id']]) ?>;
        var IS_ADMIN = <?= json_encode($isAdmin) ?>;
        var SHOW_MANAGEMENT = <?= json_encode(isset($showManagement) ? $showManagement : false) ?>;
        <?php if ($trackerDetail): ?>
        var GANTT_TASKS = <?= json_encode(array_map(function($t) {
            return ['id'=>$t['id'],'name'=>$t['task_name'],'start'=>$t['start_date'],'end'=>$t['deadline'],'status'=>$t['status'],'order'=>$t['task_order']];
        }, $trackerDetail['tasks'])) ?>;
        var TRACKER_ID = <?= json_encode((int)$trackerDetail['id']) ?>;
        var TRACKER_APP_ID = <?= json_encode((int)$trackerDetail['application_id']) ?>;
        var TRACKER_COMP_ID = <?= json_encode((int)$trackerDetail['comp_id']) ?>;
        <?php endif; ?>
        var taskMasters = [],
            staffList = [],
            appRoles = {},
            taskInstances = [],
            pickMode = 'create',
            catCtr = 0;

        function api(a, d, m, p) {
            m = m || 'GET';
            p = p || {};
            var u = '?action=' + a;
            if (m === 'GET')
                for (var k in p) {
                    if (p[k]) u += '&' + k + '=' + encodeURIComponent(p[k]);
                }
            var o = {
                method: m
            };
            if (m === 'POST' && d) {
                o.headers = {
                    'Content-Type': 'application/json'
                };
                o.body = JSON.stringify(d);
            }
            return fetch(u, o).then(function(r) {
                return r.json();
            });
        }

        // Assignee identity is (person, comp_id) - person ids repeat across companies.
        // The server emits the composite key on every staff row; the client only parses it.
        function parseStaffKey(k) {
            var i = k.lastIndexOf('|');
            return { person: k.slice(0, i), comp_id: parseInt(k.slice(i + 1), 10) };
        }

        function showAlert(msg, t) {
            var e = document.getElementById('alertBox');
            e.innerHTML = '<div class="alert alert-' + (t || 'success') + '">' + esc(msg) + '</div>';
            setTimeout(function() {
                e.innerHTML = '';
            }, 5000);
        }

        function openModal(id) {
            document.getElementById(id).style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
            document.body.style.overflow = '';
        }
        // Backdrop dismiss — close only when the press AND release both land on
        // the overlay itself. A drag that starts inside the modal (text selection,
        // select2 option, etc.) and releases on the backdrop must NOT close it.
        // Modals marked data-static never dismiss on backdrop click.
        var _pressedBackdrop = null;
        document.querySelectorAll('.modal').forEach(function(m) {
            m.addEventListener('mousedown', function(e) {
                _pressedBackdrop = (e.target === m) ? m : null;
            });
            m.addEventListener('mouseup', function(e) {
                if (_pressedBackdrop === m && e.target === m && !m.hasAttribute('data-static')) {
                    closeModal(m.id);
                }
                _pressedBackdrop = null;
            });
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') document.querySelectorAll('.modal').forEach(function(m) {
                if (m.style.display === 'block') closeModal(m.id);
            });
        });
        var _t = {};

        function debounce(fn, k, d) {
            d = d || 300;
            clearTimeout(_t[k]);
            _t[k] = setTimeout(fn, d);
        }

        function esc(s) {
            if (!s) return '';
            var d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        function badge(s) {
            return '<span class="badge badge-' + s + '">' + s.replace(/_/g, ' ').replace(/\b\w/g, function(c) {
                return c.toUpperCase();
            }) + '</span>';
        }

        // ── List ─────────────────────────────────────────────────
        var viewMode = window.innerWidth <= 768 ? 'card' : 'table',
            cached = [];

        function setView(m) {
            viewMode = m;
            var a = document.getElementById('btnTbl'),
                b = document.getElementById('btnCrd');
            if (a) a.classList.toggle('active', m === 'table');
            if (b) b.classList.toggle('active', m === 'card');
            doRender(cached);
        }

        function loadTrackers() {
            var f = {
                search: (document.getElementById('searchInput') || {}).value || '',
                status: $('#statusFilter').val() || '',
                comp_id: $('#compFilter').val() || ''
            };
            api('get_trackers', null, 'GET', f).then(function(d) {
                if (!d.success) {
                    showAlert(d.message, 'error');
                    return;
                }
                cached = d.trackers;
                doRender(d.trackers);
            });
        }

        function doRender(l) {
            (window.innerWidth <= 768 || viewMode === 'card') ? renderCards(l): renderTable(l);
        }

        function cardHtml(t) {
            var p = t.task_progress.total > 0 ? Math.round(t.task_progress.completed / t.task_progress.total * 100) : 0;
            var f = p === 100 ? 'pf-green' : p >= 50 ? 'pf-blue' : 'pf-amber';
            return '<div class="card" onclick="location=\'?view=detail&tracker_id=' + t.id + '\'"><div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.4rem"><div class="card-title" style="flex:1;min-width:0">' + esc(t.application_title) + '</div>' + badge(t.status) + '</div><div class="card-section">' + esc(t.application_section) + '</div><div class="card-meta"><span><i class="fas fa-building"></i> ' + esc(t.company_name) + '</span><span><i class="fas fa-tasks"></i> ' + t.task_progress.completed + '/' + t.task_progress.total + '</span><span><i class="fas fa-calendar"></i> ' + (t.created_at || '').substring(0, 10) + '</span></div><div class="progress-bar"><div class="progress-fill ' + f + '" style="width:' + p + '%"></div></div></div>';
        }

        function renderCards(l) {
            var w = document.getElementById('listWrap');
            if (!w) return;
            w.innerHTML = !l.length ? '<div class="no-data">No implementations found.</div>' : '<div class="card-grid">' + l.map(cardHtml).join('') + '</div>';
        }

        function renderTable(l) {
            var w = document.getElementById('listWrap');
            if (!w) return;
            if (!l.length) {
                w.innerHTML = '<div class="no-data">No implementations found.</div>';
                return;
            }
            var rows = l.map(function(t, i) {
                var p = t.task_progress.total > 0 ? Math.round(t.task_progress.completed / t.task_progress.total * 100) : 0;
                var f = p === 100 ? 'pf-green' : p >= 50 ? 'pf-blue' : 'pf-amber';
                return '<tr onclick="location=\'?view=detail&tracker_id=' + t.id + '\'"><td>' + (i + 1) + '</td><td><strong>' + esc(t.application_title) + '</strong><div style="font-size:.72rem;color:#64748b">' + esc(t.application_section) + '</div></td><td>' + esc(t.company_name) + '</td><td>' + badge(t.status) + '</td><td><div style="display:flex;align-items:center;gap:.4rem"><div class="progress-bar" style="width:55px"><div class="progress-fill ' + f + '" style="width:' + p + '%"></div></div><span style="font-size:.76rem">' + t.task_progress.completed + '/' + t.task_progress.total + '</span></div></td><td>' + (t.created_at || '').substring(0, 10) + '</td></tr>';
            }).join('');
            w.innerHTML = '<div class="list-table-wrap"><table class="list-table"><thead><tr><th>#</th><th>Application</th><th>Company</th><th>Status</th><th>Progress</th><th>Created</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
        }

        // ── Create ───────────────────────────────────────────────
        function openCreateModal() {
            document.getElementById('createRemarks').value = '';
            document.getElementById('step1').style.display = '';
            document.getElementById('step2').style.display = 'none';
            taskInstances = [];
            pickMode = 'create';
            catCtr = 0;
            var $c = $('#createComp');
            if (!$c.hasClass('select2-hidden-accessible')) {
                $c.select2({
                    width: '100%',
                    dropdownParent: $('#createModal .modal-body')
                });
                $c.on('change', loadApps);
            }
            var $a = $('#createApp');
            if (!$a.hasClass('select2-hidden-accessible')) $a.select2({
                placeholder: '-- Select --',
                width: '100%',
                dropdownParent: $('#createModal .modal-body')
            });
            loadApps();
            openModal('createModal');
        }

        function loadApps() {
            api('get_applications', null, 'GET', {
                comp_id: $('#createComp').val()
            }).then(function(d) {
                if (!d.success) return;
                var $a = $('#createApp');
                $a.empty().append('<option value="">-- Select --</option>');
                d.applications.forEach(function(a) {
                    var taken = (a.has_impl == 1);
                    var label = '[' + esc(a.application_section) + '] ' + esc(a.application_title) +
                                (taken ? ' — implementation exists' : '');
                    $a.append('<option value="' + a.application_id + '"' + (taken ? ' disabled' : '') + '>' + label + '</option>');
                });
                $a.val(null).trigger('change');
            });
        }

        function goStep1() {
            document.getElementById('step1').style.display = '';
            document.getElementById('step2').style.display = 'none';
        }

        function goStep2() {
            if (!$('#createApp').val()) {
                showAlert('Select an application', 'error');
                return;
            }
            document.getElementById('step1').style.display = 'none';
            document.getElementById('step2').style.display = '';
            pickMode = 'create';
            taskInstances = [];
            loadPickerData($('#createComp').val(), $('#createApp').val(), 'taskPickList');
        }

        // Load templates/staff/roles, then render the instance picker into the given container.
        function loadPickerData(cid, aid, targetId) {
            document.getElementById(targetId).innerHTML = '<div class="no-data"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            Promise.all([
                api('get_task_masters'),
                api('get_staff_list', null, 'GET', { app_id: aid, comp_id: cid }),
                api('get_app_roles', null, 'GET', { app_id: aid, comp_id: cid })
            ]).then(function(r) {
                taskMasters = r[0].success ? r[0].task_masters : [];
                staffList = r[1].success ? r[1].staff : [];
                appRoles = r[2].success ? r[2].roles : { owners: [], programmers: [], users: [] };
                renderPicker(targetId);
            });
        }

        function pickerParent() {
            return pickMode === 'add' ? '#addTaskModal' : '#createModal';
        }

        // The actual scrolling element. Dropdowns must attach here (not the fixed
        // overlay) so they position correctly and never orphan over the modal.
        function pickerScroll() {
            return $(pickerParent() + ' .modal-body');
        }

        // Tear down select2 before re-rendering so no dropdown/box is left orphaned.
        function destroyPickerS2() {
            $(pickerParent() + ' .s2-pick').each(function() {
                if ($(this).hasClass('select2-hidden-accessible')) $(this).select2('destroy');
            });
        }

        // Shared option builder for every staff picker. Optgroups appear only when the
        // scope spans more than one company (all-company applications).
        function sOpts(list, selected) {
            selected = selected || [];
            var groups = {}, order = [];
            list.forEach(function(s) {
                var g = s.company_name || '';
                if (!groups[g]) { groups[g] = []; order.push(g); }
                groups[g].push(s);
            });
            var flat = order.length < 2;
            return order.map(function(g) {
                var opts = groups[g].map(function(s) {
                    var sel = selected.indexOf(s.key) !== -1 ? ' selected' : '';
                    return '<option value="' + esc(s.key) + '"' + sel + '>' + esc(s.fullname) + ' (' + esc(s.person) + ')</option>';
                }).join('');
                return flat ? opts : '<optgroup label="' + esc(g) + '">' + opts + '</optgroup>';
            }).join('');
        }

        function getAF(src) {
            switch (src) {
                case 'programmer': return appRoles.programmers || [];
                case 'owner':      return appRoles.owners || [];
                case 'user':       return appRoles.users || [];
                default:           return []; // trainers/trainees etc. have no role source -> manual fill
            }
        }

        function initS2() {
            var dp = pickerScroll();
            $(pickerParent() + ' .s2-pick').each(function() {
                if (!$(this).hasClass('select2-hidden-accessible')) $(this).select2({
                    placeholder: 'Select staff...',
                    allowClear: true,
                    width: '100%',
                    dropdownParent: dp
                });
            });
        }

        // ── Instance model (shared by create + add-task) ─────────
        function renderPicker(targetId) {
            destroyPickerS2();
            var palette = taskMasters.map(function(tm) {
                return '<button type="button" class="btn btn-sm btn-secondary" onclick="addInstance(' + tm.id + ')"><i class="fas fa-plus"></i> ' + esc(tm.task_name) + '</button>';
            }).join('');
            document.getElementById(targetId).innerHTML =
                '<div class="ti-palette" style="display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.7rem">' + palette + '</div>' +
                '<div id="instanceList" class="instance-list"></div>';
            renderInstances();
        }

        function renderInstances() {
            var list = document.getElementById('instanceList');
            if (!list) return;
            destroyPickerS2();
            if (!taskInstances.length) {
                list.innerHTML = '<div class="no-data" style="padding:1rem">No tasks added yet. Use the buttons above.</div>';
                return;
            }
            list.innerHTML = taskInstances.map(function(inst) {
                return buildInstanceCard(inst);
            }).join('');
            setTimeout(initS2, 30);
        }

        function buildInstanceCard(inst) {
            var cats = inst.cats.map(function(c) { return buildCatSel(inst.iid, c); }).join('');
            return '<div class="task-pick selected" id="ti-' + inst.iid + '" data-iid="' + inst.iid + '">' +
                '<div class="tp-header" style="cursor:default">' +
                    '<div class="tc-move" style="margin-right:.4rem">' +
                        '<button type="button" class="tc-move-btn" onclick="moveInstance(' + inst.iid + ',-1)" title="Move up"><i class="fas fa-chevron-up"></i></button>' +
                        '<button type="button" class="tc-move-btn" onclick="moveInstance(' + inst.iid + ',1)" title="Move down"><i class="fas fa-chevron-down"></i></button>' +
                    '</div>' +
                    '<div style="flex:1;min-width:0">' +
                        '<input type="text" class="form-input ti-name" id="ti-name-' + inst.iid + '" value="' + esc(inst.name) + '" placeholder="Task name *" style="font-weight:600;margin-bottom:.25rem">' +
                        '<input type="text" class="form-input ti-desc" id="ti-desc-' + inst.iid + '" value="' + esc(inst.desc) + '" placeholder="Description (optional)">' +
                    '</div>' +
                    '<span class="create-cat-remove" onclick="removeInstance(' + inst.iid + ')" title="Remove task"><i class="fas fa-times"></i></span>' +
                '</div>' +
                '<div class="tp-body" style="display:block">' +
                    '<div class="inline-fields"><div class="form-group"><label class="form-label">Start Date</label><input type="date" class="form-input" id="ti-start-' + inst.iid + '" value="' + (inst.start || '') + '"></div><div class="form-group"><label class="form-label">Deadline</label><input type="date" class="form-input" id="ti-deadline-' + inst.iid + '" value="' + (inst.deadline || '') + '"></div></div>' +
                    '<div id="ti-cats-' + inst.iid + '">' + cats + '</div>' +
                    '<div class="add-cat-row"><input type="text" class="add-cat-input" id="ti-newcat-' + inst.iid + '" placeholder="New category name..."><button class="btn btn-sm btn-secondary" onclick="addInstCat(' + inst.iid + ')"><i class="fas fa-plus"></i> Add Category</button></div>' +
                '</div>' +
            '</div>';
        }

        function buildCatSel(iid, c) {
            var fixedCls = c.fixed ? 'fixed' : '';
            var at = c.autofill ? ' <span class="autofill-tag">AUTO-FILLED</span>' : '';
            var rmBtn = c.fixed ? '' : '<span class="create-cat-remove" onclick="rmInstCat(' + iid + ',' + c.idx + ')"><i class="fas fa-times"></i></span>';
            return '<div class="create-cat ' + fixedCls + '" id="ccat-' + c.idx + '"><div class="create-cat-header"><span class="create-cat-label">' + esc(c.name) + at + '</span>' + rmBtn + '</div><select multiple id="ccat-sel-' + c.idx + '" class="s2-pick" style="width:100%">' + sOpts(staffList, c.persons) + '</select></div>';
        }

        function findInstance(iid) {
            return taskInstances.find(function(x) { return x.iid === iid; });
        }

        function addInstance(masterId) {
            // Persist any edits already made before re-render.
            syncInstancesFromDom();
            var tm = masterId ? taskMasters.find(function(t) { return t.id == masterId; }) : null;
            var dc = (tm ? tm.default_categories : []) || [];
            taskInstances.push({
                iid: catCtr++,
                task_master_id: tm ? tm.id : null,
                name: tm ? tm.task_name : '',
                desc: tm ? (tm.task_description || '') : '',
                start: null,
                deadline: null,
                cats: dc.map(function(d) {
                    var af = d.autofill || null;
                    return { name: d.category_name, autofill: af, master_category_id: d.id, fixed: true, idx: catCtr++, persons: getAF(af).slice() };
                })
            });
            renderInstances();
        }

        function removeInstance(iid) {
            syncInstancesFromDom();
            taskInstances = taskInstances.filter(function(x) { return x.iid !== iid; });
            renderInstances();
        }

        // Swap an instance with its neighbour (dir = -1 up, +1 down).
        function moveInstance(iid, dir) {
            syncInstancesFromDom();
            var i = taskInstances.findIndex(function(x) { return x.iid === iid; });
            var j = i + dir;
            if (i < 0 || j < 0 || j >= taskInstances.length) return;
            var t = taskInstances[i]; taskInstances[i] = taskInstances[j]; taskInstances[j] = t;
            renderInstances();
        }

        function addInstCat(iid) {
            syncInstancesFromDom();
            var inp = document.getElementById('ti-newcat-' + iid);
            var nm = (inp.value || '').trim();
            if (!nm) { showAlert('Enter a category name', 'error'); return; }
            var inst = findInstance(iid);
            if (!inst) return;
            inst.cats.push({ name: nm, autofill: null, master_category_id: null, fixed: false, idx: catCtr++, persons: [] });
            renderInstances();
        }

        function rmInstCat(iid, idx) {
            syncInstancesFromDom();
            var inst = findInstance(iid);
            if (!inst) return;
            var c = inst.cats.find(function(x) { return x.idx === idx; });
            if (c && c.fixed) { showAlert('Cannot remove a default category', 'error'); return; }
            inst.cats = inst.cats.filter(function(x) { return x.idx !== idx; });
            renderInstances();
        }

        // Pull free-text fields AND staff selections from the DOM into the model so re-renders don't lose them.
        function syncInstancesFromDom() {
            taskInstances.forEach(function(inst) {
                var n = document.getElementById('ti-name-' + inst.iid);
                if (!n) return;
                inst.name = n.value;
                inst.desc = (document.getElementById('ti-desc-' + inst.iid) || {}).value || '';
                inst.start = (document.getElementById('ti-start-' + inst.iid) || {}).value || null;
                inst.deadline = (document.getElementById('ti-deadline-' + inst.iid) || {}).value || null;
                inst.cats.forEach(function(c) {
                    var $s = $('#ccat-sel-' + c.idx);
                    if ($s.length) c.persons = $s.val() || [];
                });
            });
        }

        // Read current DOM order of instances and build the tasks payload. Returns null + alerts on error.
        function collectTasksInOrder() {
            var orderedIids = [].map.call(document.querySelectorAll('#instanceList .task-pick'), function(el) {
                return parseInt(el.dataset.iid);
            });
            var tasks = [];
            for (var i = 0; i < orderedIids.length; i++) {
                var inst = findInstance(orderedIids[i]);
                if (!inst) continue;
                var name = (document.getElementById('ti-name-' + inst.iid).value || '').trim();
                if (!name) { showAlert('Every task needs a name', 'error'); return null; }
                var cd = [];
                inst.cats.forEach(function(c) {
                    var pp = $('#ccat-sel-' + c.idx).val() || [];
                    if (pp.length) cd.push({
                        name: c.name,
                        master_category_id: c.master_category_id,
                        persons: pp.map(parseStaffKey)
                    });
                });
                if (!cd.length) { showAlert('Task "' + name + '" needs at least one category with staff', 'error'); return null; }
                tasks.push({
                    task_master_id: inst.task_master_id,
                    task_name: name,
                    task_description: (document.getElementById('ti-desc-' + inst.iid).value || '').trim(),
                    start_date: document.getElementById('ti-start-' + inst.iid).value || null,
                    deadline: document.getElementById('ti-deadline-' + inst.iid).value || null,
                    categories: cd
                });
            }
            return tasks;
        }

        function submitCreate() {
            var compId = $('#createComp').val();
            if (!taskInstances.length) { showAlert('Add at least one task', 'error'); return; }
            var tasks = collectTasksInOrder();
            if (tasks === null) return;
            api('create_tracker', {
                application_id: parseInt($('#createApp').val()),
                comp_id: parseInt(compId),
                remarks: document.getElementById('createRemarks').value.trim(),
                tasks: tasks
            }, 'POST').then(function(d) {
                if (d.success) {
                    showAlert(d.message);
                    closeModal('createModal');
                    location = '?view=detail&tracker_id=' + d.tracker_id;
                } else showAlert(d.message, 'error');
            });
        }

        // ── Add task(s) to an existing tracker ───────────────────
        function openAddTaskModal() {
            pickMode = 'add';
            taskInstances = [];
            catCtr = catCtr || 0;
            openModal('addTaskModal');
            loadPickerData(TRACKER_COMP_ID, TRACKER_APP_ID, 'addTaskBody');
        }

        function submitAddTasks() {
            if (!taskInstances.length) { showAlert('Add at least one task', 'error'); return; }
            var tasks = collectTasksInOrder();
            if (tasks === null) return;
            // addTask is single-task + single-transaction; post sequentially, stop on first failure.
            var i = 0;
            (function next() {
                if (i >= tasks.length) {
                    showAlert(tasks.length + ' task(s) added');
                    closeModal('addTaskModal');
                    location.reload();
                    return;
                }
                api('add_task', { tracker_id: TRACKER_ID, task: tasks[i] }, 'POST').then(function(d) {
                    if (!d.success) { showAlert(d.message, 'error'); return; }
                    i++;
                    next();
                });
            })();
        }

        // ── Reorder existing tasks (detail page) ─────────────────
        // Swap a task with its neighbour (dir = -1 up, +1 down) and persist the new order.
        function moveTask(taskId, dir) {
            var list = document.querySelector('.task-list');
            if (!list || typeof TRACKER_ID === 'undefined') return;
            var ids = [].map.call(list.querySelectorAll('.task-card'), function(el) {
                return parseInt(el.dataset.taskId);
            });
            var i = ids.indexOf(taskId), j = i + dir;
            if (i < 0 || j < 0 || j >= ids.length) return;
            var t = ids[i]; ids[i] = ids[j]; ids[j] = t;
            api('reorder_tasks', { tracker_id: TRACKER_ID, ordered_ids: ids }, 'POST').then(function(d) {
                if (d.success) location.reload();
                else showAlert(d.message, 'error');
            });
        }

        // ── Detail actions ───────────────────────────────────────
        function updateStatus(id, s) {
            if (!confirm('Change status to ' + s.replace(/_/g, ' ') + '?')) return;
            api('update_tracker_status', {
                tracker_id: id,
                status: s
            }, 'POST').then(function(d) {
                if (d.success) {
                    showAlert(d.message);
                    location.reload();
                } else showAlert(d.message, 'error');
            });
        }

        function deleteTracker(id) {
            if (!confirm('Delete this implementation?')) return;
            api('delete_tracker', {
                tracker_id: id
            }, 'POST').then(function(d) {
                if (d.success) {
                    showAlert(d.message);
                    setTimeout(function() {
                        location = '?view=list';
                    }, 600);
                } else showAlert(d.message, 'error');
            });
        }

        // ── Long task descriptions ────────────────────────
        // Clamp every description first, then keep the toggle only on the ones that
        // actually overflow - a short note should not grow a 'Show more' button.
        function initDescClamp() {
            document.querySelectorAll('.st-desc-wrap').forEach(function(w) {
                var d = w.querySelector('.st-desc'),
                    b = w.querySelector('.st-desc-more');
                if (!d || !b) return;
                if (w.getAttribute('data-expanded') === '1') return; // leave what the user opened
                d.classList.add('st-desc-clamp');
                b.classList.remove('show');
                b.innerHTML = '<i class="fas fa-chevron-down"></i> Show more';
                if (d.scrollHeight > d.clientHeight + 2) b.classList.add('show');
                else d.classList.remove('st-desc-clamp');
            });
        }

        function toggleDesc(btn) {
            var d = btn.parentNode.querySelector('.st-desc');
            if (!d) return;
            var clamped = d.classList.toggle('st-desc-clamp');
            btn.parentNode.setAttribute('data-expanded', clamped ? '0' : '1');
            btn.innerHTML = clamped ?
                '<i class="fas fa-chevron-down"></i> Show more' :
                '<i class="fas fa-chevron-up"></i> Show less';
        }

        document.addEventListener('DOMContentLoaded', initDescClamp);
        // A description that fits on a wide screen can overflow on a narrow one.
        var descClampTimer;
        window.addEventListener('resize', function() {
            clearTimeout(descClampTimer);
            descClampTimer = setTimeout(initDescClamp, 200);
        });

        function toggleTaskDone(taskId, isDone) {
            var msg = isDone ? 'Undo this task and mark it as pending?' : 'Mark your assignment as done?';
            if (!confirm(msg)) return;
            var action = isDone ? 'unmark_done' : 'mark_done';
            api(action, { tracker_task_id: taskId }, 'POST').then(function(d) {
                if (d.success) {
                    showAlert(d.message);
                    location.reload();
                } else showAlert(d.message, 'error');
            });
        }

        // Legacy alias — used by manager view Mark as Done button
        function markDone(taskId) { toggleTaskDone(taskId, false); }

        function addCategory(taskId, trackerId) {
            var inp = document.getElementById('newcat-' + taskId);
            var nm = (inp.value || '').trim();
            if (!nm) {
                showAlert('Enter a category name', 'error');
                return;
            }
            api('add_category', {
                tracker_task_id: taskId,
                category_name: nm
            }, 'POST').then(function(d) {
                if (d.success) {
                    showAlert(d.message);
                    location.reload();
                } else showAlert(d.message, 'error');
            });
        }

        function removeCategory(catId, trackerId) {
            if (!confirm('Remove this category and all its staff?')) return;
            api('remove_category', {
                category_id: catId
            }, 'POST').then(function(d) {
                if (d.success) {
                    showAlert(d.message);
                    location.reload();
                } else showAlert(d.message, 'error');
            });
        }

        function openAddStaffModal(catId, taskId, trackerId, catName) {
            document.getElementById('staffModalTitle').textContent = 'Add Staff to ' + catName;
            document.getElementById('smCatId').value = catId;
            document.getElementById('smTaskId').value = taskId;
            document.getElementById('smTrackerId').value = trackerId;
            var $s = $('#smSelect');
            $s.empty();
            api('get_staff_list', null, 'GET', {
                app_id: TRACKER_APP_ID,
                comp_id: TRACKER_COMP_ID
            }).then(function(d) {
                if (!d.success) { showAlert(d.message || 'Failed to load staff', 'error'); return; }
                $s.append('<option value="">-- Select --</option>' + sOpts(d.staff, []));
                if ($s.hasClass('select2-hidden-accessible')) $s.select2('destroy');
                $s.select2({
                    placeholder: 'Search staff...',
                    width: '100%',
                    dropdownParent: $('#staffModal .modal-body')
                });
                $s.val(null).trigger('change');
            });
            openModal('staffModal');
        }

        function submitStaff() {
            var catId = parseInt(document.getElementById('smCatId').value),
                taskId = parseInt(document.getElementById('smTaskId').value),
                key = $('#smSelect').val();
            if (!key) {
                showAlert('Select a staff member', 'error');
                return;
            }
            var sel = parseStaffKey(key);
            api('add_staff', {
                category_id: catId,
                tracker_task_id: taskId,
                person: sel.person,
                comp_id: sel.comp_id
            }, 'POST').then(function(d) {
                if (d.success) {
                    showAlert(d.message);
                    location.reload();
                } else showAlert(d.message, 'error');
            });
        }

        function removeStaff(id, trackerId) {
            if (!confirm('Remove this staff?')) return;
            api('remove_staff', {
                assignee_id: id
            }, 'POST').then(function(d) {
                if (d.success) {
                    showAlert(d.message);
                    location.reload();
                } else showAlert(d.message, 'error');
            });
        }

        // ── Gantt Chart ───────────────────────────────────────────
        function toggleGantt() {
            var w = document.getElementById('ganttWrap'),
                t = document.getElementById('ganttToggle');
            var opening = !w.classList.contains('open');
            w.classList.toggle('open');
            t.classList.toggle('open');
            if (opening && !w.dataset.rendered) {
                renderGantt();
                w.dataset.rendered = '1';
            }
        }

        function renderGantt() {
            var el = document.getElementById('ganttWrap');
            if (typeof GANTT_TASKS === 'undefined' || !GANTT_TASKS.length) {
                el.innerHTML = '<div class="comment-empty">No tasks with dates</div>';
                return;
            }
            var dated = GANTT_TASKS.filter(function(t) { return t.start || t.end; });
            if (!dated.length) {
                el.innerHTML = '<div class="comment-empty">No tasks with dates</div>';
                return;
            }
            var today = new Date(); today.setHours(0,0,0,0);
            var allDates = [];
            dated.forEach(function(t) {
                if (t.start) allDates.push(new Date(t.start));
                if (t.end) allDates.push(new Date(t.end));
            });
            var minD = new Date(Math.min.apply(null, allDates));
            var maxD = new Date(Math.max.apply(null, allDates));
            minD.setDate(minD.getDate() - 1);
            maxD.setDate(maxD.getDate() + 1);
            var spanMs = maxD - minD;
            if (spanMs <= 0) spanMs = 86400000;
            var spanDays = Math.ceil(spanMs / 86400000);

            function pct(d) { return Math.max(0, Math.min(100, ((d - minD) / spanMs) * 100)); }
            function fmtTick(d) {
                var mn = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                return mn[d.getMonth()] + ' ' + d.getDate();
            }
            function fmtTip(d) {
                var mn = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                return mn[d.getMonth()] + ' ' + d.getDate();
            }

            // Smart tick interval
            var isMob = window.innerWidth <= 768;
            var maxTicks = isMob ? 5 : 8;
            var interval = Math.max(1, Math.ceil(spanDays / maxTicks));
            // Snap to nice intervals
            if (interval <= 1) interval = 1;
            else if (interval <= 2) interval = 2;
            else if (interval <= 4) interval = 3;
            else if (interval <= 6) interval = 5;
            else if (interval <= 10) interval = 7;
            else interval = 14;

            // Generate ticks
            var ticks = [];
            var cursor = new Date(minD);
            cursor.setDate(cursor.getDate() + 1);
            while (cursor <= maxD) {
                ticks.push(new Date(cursor));
                cursor.setDate(cursor.getDate() + interval);
            }

            var todayPct = pct(today);
            var showToday = todayPct > 2 && todayPct < 98;

            // Collision-free tick filtering — drop any tick within 4% of Today
            if (showToday) {
                ticks = ticks.filter(function(tk) {
                    return Math.abs(pct(tk) - todayPct) > 4;
                });
            }

            var html = '<div class="gantt-chart">';

            // ── Header tick row
            html += '<div class="gantt-tick-row"><div class="gantt-label-col"></div><div class="gantt-tick-area">';
            ticks.forEach(function(tk) {
                var p = pct(tk);
                var style = 'left:' + p + '%';
                if (p < 4) style += ';transform:translateX(0);text-align:left';
                else if (p > 96) style += ';transform:translateX(-100%);text-align:right';
                html += '<span class="gantt-tick-label" style="' + style + '">' + fmtTick(tk) + '</span>';
            });
            if (showToday) {
                var ts = 'left:' + todayPct + '%';
                if (todayPct < 4) ts += ';transform:translateX(0)';
                else if (todayPct > 96) ts += ';transform:translateX(-100%)';
                html += '<span class="gantt-today-label" style="' + ts + '">Today</span>';
            }
            html += '</div></div>';

            // ── Task rows
            GANTT_TASKS.forEach(function(t, idx) {
                var s = t.start ? new Date(t.start) : null;
                var e = t.end ? new Date(t.end) : null;
                var hasBar = s || e;
                var left = s ? pct(s) : (e ? Math.max(0, pct(e) - 2) : 0);
                var right = e ? pct(e) : (s ? Math.min(100, pct(s) + 2) : 2);
                if (right <= left) right = left + 1.5;
                var w = Math.max(1.5, right - left);

                // Tooltip text
                var tipText = '';
                if (s && e) tipText = fmtTip(s) + ' → ' + fmtTip(e);
                else if (s) tipText = 'Start: ' + fmtTip(s);
                else if (e) tipText = 'Due: ' + fmtTip(e);

                // Edge-clamp class for tooltip
                var tipCls = '';
                if (left < 15) tipCls = ' tip-left';
                else if ((left + w) > 85) tipCls = ' tip-right';

                var barHtml = '';
                if (hasBar) {
                    var barCls = t.status;
                    if (t.status !== 'done' && e && e < today) barCls = 'overdue';
                    barHtml = '<div class="gantt-bar gb-' + barCls + tipCls + '" data-taskid="' + t.id + '" style="left:' + left + '%;width:' + w + '%">';
                    barHtml += '<div class="gantt-tip">' + tipText + '</div>';
                    barHtml += '</div>';
                }

                var todayLine = showToday ? '<div class="gantt-today" style="left:' + todayPct + '%"></div>' : '';

                html += '<div class="gantt-row">';
                html += '<div class="gantt-row-label" title="' + esc(t.name) + '">' + t.order + '. ' + esc(t.name) + '</div>';
                html += '<div class="gantt-row-track">';
                ticks.forEach(function(tk) {
                    html += '<div class="gantt-grid-line" style="left:' + pct(tk) + '%"></div>';
                });
                if (showToday) html += '<div class="gantt-grid-line" style="left:' + todayPct + '%;background:#fecaca"></div>';
                html += barHtml + todayLine + '</div></div>';
            });

            html += '<div class="gantt-legend">';
            html += '<span><span class="gantt-legend-dot" style="background:#f59e0b"></span> Pending</span>';
            html += '<span><span class="gantt-legend-dot" style="background:#ef4444"></span> Overdue</span>';
            html += '<span><span class="gantt-legend-dot" style="background:#10b981"></span> Done</span>';
            if (showToday) html += '<span><span class="gantt-legend-dot" style="background:#ef4444"></span> Today</span>';
            html += '</div></div>';

            el.innerHTML = html;

            // Tap/click handler for bar tooltips
            el.addEventListener('click', function(ev) {
                var bar = ev.target.closest('.gantt-bar');
                if (!bar) {
                    // Tap outside any bar → close all
                    el.querySelectorAll('.gantt-bar.active').forEach(function(b) { b.classList.remove('active'); });
                    return;
                }
                var wasActive = bar.classList.contains('active');
                el.querySelectorAll('.gantt-bar.active').forEach(function(b) { b.classList.remove('active'); });
                if (!wasActive) bar.classList.add('active');
            });
        }

        // ── Comment Modal ─────────────────────────────────────
        var cmTaskId = null, cmReplyTo = null, cmCanManage = false, commentLoaded = {};

        function openCommentModal(taskId, taskName) {
            cmTaskId = taskId;
            cmReplyTo = null;
            document.getElementById('cmTaskName').textContent = taskName;
            document.getElementById('cmReplyBar').style.display = 'none';
            document.getElementById('cmInput').value = '';
            document.getElementById('cmBody').innerHTML = '<div class="cm-loading"><i class="fas fa-spinner fa-spin"></i> Loading comments...</div>';
            openModal('commentModal');
            loadComments(taskId);
            setTimeout(function() { autoGrow(document.getElementById('cmInput')); }, 100);
        }

        function closeCommentModal() {
            closeModal('commentModal');
            cmTaskId = null;
            cmReplyTo = null;
            cmCanManage = false;
        }

        // Close on backdrop click
        document.addEventListener('click', function(e) {
            var m = document.getElementById('commentModal');
            if (m && m.style.display === 'block' && e.target === m) closeCommentModal();
        });

        function loadComments(taskId) {
            api('get_comments', null, 'GET', { tracker_task_id: taskId }).then(function(d) {
                if (!d.success) return;
                commentLoaded[taskId] = true;
                cmCanManage = !!d.can_manage;
                renderComments(taskId, d.comments);
            });
        }

        function getInitials(name) {
            var parts = (name || '?').split(' ');
            if (parts.length >= 2) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
            return (name || '?').substring(0, 2).toUpperCase();
        }

        function renderCommentItem(c, taskId, isReply) {
            var mine = (c.person === CU.person && parseInt(c.comp_id) === parseInt(CU.comp_id));
            var canDel = mine || IS_ADMIN;
            var ago = timeAgo(c.created_at);
            var isDone = !isReply && parseInt(c.done_status) === 1;
            var cls = (isReply ? 'cm-item cm-reply' : 'cm-item') + (mine ? ' is-mine' : '') + (isDone ? ' cm-resolved' : '');
            var delBtn = canDel ? '<button class="cm-action-btn del" onclick="event.stopPropagation();delComment(' + c.id + ',' + taskId + ')"><i class="fas fa-trash-alt"></i> Delete</button>' : '';
            var replyBtn = '<button class="cm-action-btn" onclick="setReply(' + c.id + ',\'' + esc(c.fullname).replace(/'/g, "\\'") + '\',' + (isReply ? 'true' : 'false') + ')"><i class="fas fa-reply"></i> Reply</button>';

            var doneToggle = '';
            if (!isReply && cmCanManage) {
                var dCls = isDone ? 'cm-done-toggle is-done' : 'cm-done-toggle';
                var dTip = isDone ? 'Mark as unresolved' : 'Mark as resolved';
                doneToggle = '<button class="' + dCls + '" title="' + dTip + '" onclick="event.stopPropagation();toggleCommentDone(' + c.id + ',' + taskId + ')"><i class="fas fa-check" style="font-size:.55rem"></i></button>';
            }

            var html = '<div class="' + cls + '" data-id="' + c.id + '">';
            html += '<div class="cm-item-head">';
            html += '<div class="cm-avatar">' + getInitials(c.fullname) + '</div>';
            html += '<span class="cm-author">' + esc(c.fullname) + '</span>';
            html += '<span class="cm-time">' + ago + '</span>';
            html += doneToggle;
            html += '</div>';
            html += '<div class="cm-item-body">' + formatCommentText(c.comment) + '</div>';
            if (isDone && c.done_fullname) {
                html += '<div class="cm-done-info"><i class="fas fa-check-circle"></i> Resolved by ' + esc(c.done_fullname) + '</div>';
            }
            html += '<div class="cm-item-actions">' + replyBtn + delBtn + '</div>';

            if (!isReply && c.replies && c.replies.length) {
                html += '<div class="cm-replies">';
                c.replies.forEach(function(r) {
                    html += renderCommentItem(r, taskId, true);
                });
                html += '</div>';
            }

            html += '</div>';
            return html;
        }

        function renderComments(taskId, comments) {
            var el = document.getElementById('cmBody');
            // Update count badges on the page
            var totalCount = 0;
            function countAll(list) { list.forEach(function(c) { totalCount++; if (c.replies) countAll(c.replies); }); }
            countAll(comments);

            if (!comments.length) {
                el.innerHTML = '<div class="cm-empty"><i class="far fa-comment-dots"></i><span>No comments yet.<br>Start the conversation!</span></div>';
                return;
            }
            el.innerHTML = comments.map(function(c) { return renderCommentItem(c, taskId, false); }).join('');
            el.scrollTop = el.scrollHeight;
        }

        function formatCommentText(text) {
            var escaped = esc(text);
            return escaped.replace(/@([\w\s]+?)(?=\s|$)/g, '<span class="cm-mention">@$1</span>');
        }

        function setReply(commentId, authorName, isNested) {
            var rootId = commentId;
            if (isNested) {
                var item = document.querySelector('.cm-item[data-id="' + commentId + '"]');
                if (item) {
                    var parentEl = item.closest('.cm-replies');
                    if (parentEl) {
                        var rootItem = parentEl.closest('.cm-item');
                        if (rootItem) rootId = parseInt(rootItem.dataset.id);
                    }
                }
            }
            cmReplyTo = rootId;
            document.getElementById('cmReplyName').textContent = authorName;
            document.getElementById('cmReplyBar').style.display = '';
            var inp = document.getElementById('cmInput');
            inp.placeholder = 'Reply to ' + authorName + '...';
            if (isNested) {
                inp.value = '@' + authorName + ' ';
            } else {
                inp.value = '';
            }
            inp.focus();
            autoGrow(inp);
        }

        function cancelReply() {
            cmReplyTo = null;
            document.getElementById('cmReplyBar').style.display = 'none';
            document.getElementById('cmInput').placeholder = 'Write a feedback...';
        }

        function postComment() {
            var inp = document.getElementById('cmInput');
            var txt = (inp.value || '').trim();
            if (!txt || !cmTaskId) return;
            var btn = document.getElementById('cmSendBtn');
            inp.disabled = true;
            btn.disabled = true;
            var payload = { tracker_task_id: cmTaskId, comment: txt };
            if (cmReplyTo) payload.parent_id = cmReplyTo;
            api('add_comment', payload, 'POST').then(function(d) {
                inp.disabled = false;
                btn.disabled = false;
                if (d.success) {
                    inp.value = '';
                    autoGrow(inp);
                    cancelReply();
                    loadComments(cmTaskId);
                } else showAlert(d.message, 'error');
            });
        }

        function toggleCommentDone(commentId, taskId) {
            api('toggle_comment_done', { comment_id: commentId }, 'POST').then(function(d) {
                if (d.success) loadComments(taskId);
                else showAlert(d.message, 'error');
            });
        }

        function delComment(commentId, taskId) {
            if (!confirm('Delete this comment?')) return;
            api('delete_comment', { comment_id: commentId }, 'POST').then(function(d) {
                if (d.success) loadComments(taskId);
                else showAlert(d.message, 'error');
            });
        }

        // Auto-grow textarea
        function autoGrow(el) {
            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight, 100) + 'px';
        }

        // Wire up Enter to send, Shift+Enter for newline
        document.addEventListener('DOMContentLoaded', function() {
            var cmInp = document.getElementById('cmInput');
            if (cmInp) {
                cmInp.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); postComment(); }
                });
                cmInp.addEventListener('input', function() { autoGrow(this); });
            }
        });

        function timeAgo(dt) {
            var now = new Date(), d = new Date(dt);
            var diff = Math.floor((now - d) / 1000);
            if (diff < 60) return 'just now';
            if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
            if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
            return dt.substring(0, 10);
        }

        // ── Tabs ──────────────────────────────────────────────────
        var activeTab = 'my', myTasksLoaded = false, myAppsLoaded = false;

        function switchTab(tab) {
            activeTab = tab;
            document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
            document.querySelectorAll('.tab-section').forEach(function(s) { s.classList.remove('active'); });
            if (tab === 'apps') {
                setActiveTab('tabMyApps', 'sectionMyApps');
                if (!myAppsLoaded) loadMyAppListing();
            } else if (tab === 'my') {
                setActiveTab('tabMyTasks', 'sectionMyTasks');
                if (!myTasksLoaded) loadMyTasks();
            } else if (tab === 'mreport') {
                setActiveTab('tabMgmtReport', 'sectionMgmtReport');
                if (!mgmtReportLoaded) loadManagementReport();
            } else {
                setActiveTab('tabManage', 'sectionManage');
                if (!cached.length) loadTrackers();
            }
        }

        function setActiveTab(btnId, secId) {
            var b = document.getElementById(btnId); if (b) b.classList.add('active');
            var s = document.getElementById(secId); if (s) s.classList.add('active');
        }

        // -- Management Report -------------------------------------
        var mgmtReportApps = [];
        var mgmtReportLoaded = false;
        var usageReportState = null; // {appId, year, month}

        function loadManagementReport() {
            var el = document.getElementById('mgmtReportApps');
            if (!el) return;
            el.innerHTML = '<div class="no-data"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            api('get_management_report_apps').then(function(d) {
                if (!d.success) { showAlert(d.message, 'error'); return; }
                mgmtReportApps = d.apps;
                mgmtReportLoaded = true;
                renderManagementReport(d.apps);
            });
        }

        function renderManagementReport(apps) {
            var el = document.getElementById('mgmtReportApps');
            if (!el) return;
            if (!apps.length) {
                el.innerHTML = '<div class="mal-empty"><i class="fas fa-box-open" style="font-size:1.5rem;display:block;margin-bottom:.5rem"></i>No applications with management reports.</div>';
                return;
            }
            var groups = {};
            apps.forEach(function(a) {
                var sec = a.application_section || 'Other';
                (groups[sec] = groups[sec] || []).push(a);
            });
            var html = '<div class="mal-count">' + apps.length + ' application' + (apps.length !== 1 ? 's' : '') + '</div>';
            Object.keys(groups).sort().forEach(function(sec) {
                html += '<div class="mal-group-title">' + esc(sec) + '</div>';
                groups[sec].forEach(function(a) {
                    var btns = '';
                    if (a.has_impl)  btns += '<button class="btn btn-secondary" onclick="openImplReport(' + a.application_id + ')"><i class="fas fa-tasks"></i> Implementation</button>';
                    if (a.has_usage) btns += '<button class="btn btn-primary" onclick="openUsageReport(' + a.application_id + ')"><i class="fas fa-chart-column"></i> Usage</button>';
                    if (!btns) btns = '<span style="font-size:.74rem;color:#cbd5e1">No reports available</span>';
                    html += '<div class="mal-card" style="cursor:default">' +
                        '<div class="mal-card-icon"><i class="fas fa-file-lines"></i></div>' +
                        '<div class="mal-card-info">' +
                            '<div class="mal-card-title">' + esc(a.application_title) + '</div>' +
                            '<div class="mal-card-section">' + esc(a.application_section) + '</div>' +
                        '</div>' +
                        '<div class="mal-card-actions">' + btns + '</div>' +
                    '</div>';
                });
            });
            el.innerHTML = html;
        }

        function filterMgmtReportApps() {
            var q = (document.getElementById('mgmtReportSearch').value || '').toLowerCase();
            if (!q) { renderManagementReport(mgmtReportApps); return; }
            renderManagementReport(mgmtReportApps.filter(function(a) {
                return (a.application_title || '').toLowerCase().indexOf(q) !== -1 ||
                       (a.application_section || '').toLowerCase().indexOf(q) !== -1;
            }));
        }

        function showMgmtReportView() {
            document.getElementById('mgmtReportList').style.display = 'none';
            document.getElementById('mgmtReportView').style.display = 'block';
        }
        function closeMgmtReport() {
            usageReportState = null;
            document.getElementById('mgmtReportView').style.display = 'none';
            document.getElementById('mgmtReportList').style.display = 'block';
        }

        function openImplProgress(id) {
            var data = document.getElementById('implDetailData-' + id);
            if (!data) return;
            document.getElementById('implProgressTitle').innerHTML =
                '<i class="fas fa-list-check"></i> ' + (data.getAttribute('data-title') || 'Task Progress');
            document.getElementById('implProgressBody').innerHTML = data.innerHTML;
            openModal('implProgressModal');
        }

        function openImplReport(appId) {
            showMgmtReportView();
            document.getElementById('mgmtReportHeader').innerHTML =
                '<h3 style="font-size:.95rem;margin-bottom:.75rem"><i class="fas fa-tasks"></i> Implementation Report</h3>';
            var body = document.getElementById('mgmtReportBody');
            body.innerHTML = '<div class="no-data"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            api('get_implementation_report', null, 'GET', { app_id: appId }).then(function(d) {
                body.innerHTML = d.success ? d.html : '<div class="no-data">' + esc(d.message || 'Failed to load report.') + '</div>';
            }).catch(function() { body.innerHTML = '<div class="no-data">Failed to load report.</div>'; });
        }

        function openUsageReport(appId) {
            showMgmtReportView();
            var now = new Date();
            usageReportState = { appId: appId, year: now.getFullYear(), month: now.getMonth() + 1 };
            var mm = ('0' + usageReportState.month).slice(-2);
            document.getElementById('mgmtReportHeader').innerHTML =
                '<h3 style="font-size:.95rem;margin-bottom:.6rem"><i class="fas fa-chart-column"></i> App Usage Report</h3>' +
                '<div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.85rem">' +
                    '<button class="btn btn-secondary btn-sm" onclick="usageReportStep(-1)"><i class="fas fa-chevron-left"></i></button>' +
                    '<input type="month" id="urMonth" class="search-input" style="max-width:170px" value="' +
                        usageReportState.year + '-' + mm + '" onchange="usageReportPick(this.value)">' +
                    '<button class="btn btn-secondary btn-sm" onclick="usageReportStep(1)"><i class="fas fa-chevron-right"></i></button>' +
                '</div>';
            document.getElementById('mgmtReportBody').innerHTML = '<div id="usageReportGrid"></div>';
            loadUsageReportGrid();
        }

        function usageReportStep(delta) {
            if (!usageReportState) return;
            var m = usageReportState.month + delta, y = usageReportState.year;
            if (m < 1) { m = 12; y--; } else if (m > 12) { m = 1; y++; }
            usageReportState.month = m; usageReportState.year = y;
            var el = document.getElementById('urMonth');
            if (el) el.value = y + '-' + ('0' + m).slice(-2);
            loadUsageReportGrid();
        }
        function usageReportPick(val) {
            if (!usageReportState || !val) return;
            var parts = val.split('-');
            usageReportState.year = parseInt(parts[0], 10);
            usageReportState.month = parseInt(parts[1], 10);
            loadUsageReportGrid();
        }
        function loadUsageReportGrid() {
            var grid = document.getElementById('usageReportGrid');
            if (!grid || !usageReportState) return;
            grid.innerHTML = '<div class="no-data"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            api('get_usage_report', null, 'GET', {
                app_id: usageReportState.appId,
                year:   usageReportState.year,
                month:  usageReportState.month
            }).then(function(d) {
                grid.innerHTML = d.success ? d.html : '<div class="no-data">' + esc(d.message || 'Failed to load report.') + '</div>';
            }).catch(function() { grid.innerHTML = '<div class="no-data">Failed to load report.</div>'; });
        }

        // ── My Tasks ─────────────────────────────────────────────
        var myTasksData = [];

        function loadMyTasks() {
            var el = document.getElementById('myTasksList');
            if (!el) return;
            el.innerHTML = '<div class="no-data"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            api('get_my_tasks').then(function(d) {
                if (!d.success) { showAlert(d.message, 'error'); return; }
                myTasksData = d.data;
                myTasksLoaded = true;
                renderMyTasks(d.data);
            });
        }

        function renderMyTasks(data) {
            var el = document.getElementById('myTasksList');
            var sumEl = document.getElementById('myTasksSummary');
            if (!el) return;

            // Compute summary
            var totalAssignments = 0, doneAssignments = 0, overdueCount = 0;
            var today = new Date(); today.setHours(0,0,0,0);
            data.forEach(function(tr) {
                tr.tasks.forEach(function(t) {
                    t.assignments.forEach(function(a) {
                        totalAssignments++;
                        if (a.completed) doneAssignments++;
                        else if (t.deadline) {
                            var dl = new Date(t.deadline);
                            if (dl < today) overdueCount++;
                        }
                    });
                });
            });
            var pendingCount = totalAssignments - doneAssignments - overdueCount;

            if (sumEl) {
                sumEl.style.display = '';
                sumEl.innerHTML =
                    '<div class="my-tasks-stat"><span class="dot" style="background:#f59e0b"></span> <strong>' + pendingCount + '</strong> Pending</div>' +
                    '<div class="my-tasks-stat"><span class="dot" style="background:#10b981"></span> <strong>' + doneAssignments + '</strong> Completed</div>' +
                    (overdueCount > 0 ? '<div class="my-tasks-stat"><span class="dot" style="background:#ef4444"></span> <strong>' + overdueCount + '</strong> Overdue</div>' : '');
            }

            if (!data.length) {
                el.innerHTML = '<div class="no-data"><i class="fas fa-check-circle" style="color:#10b981;font-size:1.5rem;margin-bottom:.4rem;display:block"></i>No tasks assigned to you.</div>';
                return;
            }

            el.innerHTML = data.map(function(tr, idx) {
                // Compute per-tracker progress
                var total = tr.tasks.length;
                var done = 0;
                var trOverdue = 0;
                tr.tasks.forEach(function(t) {
                    var allDone = t.assignments.every(function(a) { return a.completed; });
                    if (allDone) done++;
                    else if (t.deadline) {
                        var dl = new Date(t.deadline);
                        if (dl < today) trOverdue++;
                    }
                });
                var pct = total > 0 ? Math.round((done / total) * 100) : 0;
                var fillCls = pct === 100 ? 'pf-green' : (trOverdue > 0 ? 'pf-red' : 'pf-amber');
                // var myStatus = pct === 100 ? 'completed' : (trOverdue > 0 ? 'on_hold' : tr.tracker_status);
                var myStatus = pct === 100 ? 'completed' : tr.tracker_status;


                return '<div class="mt-card" onclick="location=\'?view=detail&tracker_id=' + tr.tracker_id + '&from=my\'">' +
                    '<div class="mt-card-index">' + (idx + 1) + '</div>' +
                    '<div class="mt-card-info">' +
                        '<div class="mt-card-title">' + esc(tr.application_title) + '</div>' +
                        '<div class="mt-card-sub">' +
                            '<span>' + esc(tr.application_section) + '</span>' +
                        '</div>' +
                        '<div class="mt-card-progress">' +
                            '<div class="mt-card-progress-bar"><div class="mt-card-progress-fill ' + fillCls + '" style="width:' + pct + '%"></div></div>' +
                            '<span class="mt-card-progress-text">' + done + '/' + total + ' done</span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="mt-card-right">' +
                        badge(myStatus) +
                        (trOverdue > 0 ? '<span class="mt-card-overdue"><i class="fas fa-exclamation-circle"></i> ' + trOverdue + ' overdue</span>' : '') +
                    '</div>' +
                '</div>';
            }).join('');
        }

        function markDoneMyTasks(taskId) {
            if (!confirm('Mark your assignment as done?')) return;
            api('mark_done', { tracker_task_id: taskId }, 'POST').then(function(d) {
                if (d.success) {
                    showAlert(d.message);
                    myTasksLoaded = false;
                    loadMyTasks();
                } else showAlert(d.message, 'error');
            });
        }

        // ── My App Listing ───────────────────────────────────────
        var myAppsData = [];

        function loadMyAppListing() {
            var el = document.getElementById('myAppsList');
            if (!el) return;
            el.innerHTML = '<div class="no-data"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            api('get_my_app_listing').then(function(d) {
                if (!d.success) { showAlert(d.message, 'error'); return; }
                myAppsData = d.apps;
                myAppsLoaded = true;
                renderMyAppListing(d.apps);
            });
        }

        function renderMyAppListing(apps) {
            var el = document.getElementById('myAppsList');
            if (!el) return;
            if (!apps.length) {
                el.innerHTML = '<div class="mal-empty"><i class="fas fa-box-open" style="font-size:1.5rem;display:block;margin-bottom:.5rem"></i>No applications assigned to you.</div>';
                return;
            }
            // Group by section
            var groups = {};
            apps.forEach(function(a) {
                var sec = a.application_section || 'Other';
                if (!groups[sec]) groups[sec] = [];
                groups[sec].push(a);
            });
            var html = '<div class="mal-count">' + apps.length + ' application' + (apps.length !== 1 ? 's' : '') + '</div>';
            var sections = Object.keys(groups).sort();
            sections.forEach(function(sec) {
                html += '<div class="mal-group-title">' + esc(sec) + '</div>';
                groups[sec].forEach(function(a) {
                    var actions = '';
                    // Implementation menu — only when a tracker exists AND the user can manage it.
                    if (a.application_impl_id && a.can_manage_impl) {
                        actions += '<button class="btn btn-primary btn-sm" onclick="event.stopPropagation();openImplMenu(' + a.application_impl_id + ')"><i class="fas fa-diagram-project"></i> Implementation</button>';
                    }
                    // Reference materials — visible to everyone; upload gated server-side.
                    actions += '<button class="btn btn-secondary btn-sm" onclick="event.stopPropagation();openReferenceMaterials(' + a.application_id + ')"><i class="fas fa-folder-open"></i> Materials</button>';
                    html += '<div class="mal-card" onclick="openAppDetail(' + a.application_id + ')">' +
                        '<div class="mal-card-icon"><i class="fas fa-cube"></i></div>' +
                        '<div class="mal-card-info">' +
                            '<div class="mal-card-title">' + esc(a.application_title) + '</div>' +
                            '<div class="mal-card-section">' + esc(a.application_section) + '</div>' +
                        '</div>' +
                        malStatusBadges(a.listing_status) +
                        '<div class="mal-card-actions">' + actions + '</div>' +
                    '</div>';
                });
            });
            el.innerHTML = html;
        }

        // Compact status badges for a listing card (provider-supplied; optional).
        function malStatusBadges(status) {
            if (!status || !status.badges || !status.badges.length) return '';
            var icons = { ok: 'fa-circle-check', warn: 'fa-triangle-exclamation', none: 'fa-circle-minus' };
            var chips = status.badges.map(function(b) {
                var state = icons[b.state] ? b.state : 'none';
                return '<span class="mal-badge mal-badge-' + state + '">' +
                    '<i class="fas ' + icons[state] + '"></i>' + esc(b.label) + '</span>';
            }).join('');
            return '<div class="mal-card-status">' + chips + '</div>';
        }

        function filterMyApps() {
            var q = (document.getElementById('malSearch').value || '').toLowerCase();
            if (!q) { renderMyAppListing(myAppsData); return; }
            var filtered = myAppsData.filter(function(a) {
                return (a.application_title || '').toLowerCase().indexOf(q) !== -1 ||
                       (a.application_section || '').toLowerCase().indexOf(q) !== -1;
            });
            renderMyAppListing(filtered);
        }

        function openAppDetail(appId) {
            var titleEl = document.getElementById('appDetailTitle');
            var body = document.getElementById('appDetailBody');
            titleEl.innerHTML = '<i class="fas fa-info-circle"></i> Application Details';
            body.innerHTML = '<div class="no-data"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            openModal('appDetailModal');
            api('get_app_detail', null, 'GET', { app_id: appId }).then(function(d) {
                if (!d.success) { body.innerHTML = '<div class="no-data">' + esc(d.message || 'Failed to load details.') + '</div>'; return; }
                if (d.custom) {
                    titleEl.innerHTML = '<i class="fas fa-info-circle"></i> ' + esc(d.title);
                    body.innerHTML = d.html;
                } else {
                    var a = d.app;
                    titleEl.innerHTML = '<i class="fas fa-info-circle"></i> ' + esc(a.application_title);
                    body.innerHTML =
                        '<div style="font-size:.82rem;color:#64748b;margin-bottom:.8rem">' + esc(a.application_section) + '</div>' +
                        '<div style="font-size:.82rem;font-weight:600;color:#475569;margin-bottom:.25rem">Remarks</div>' +
                        '<div style="font-size:.88rem;color:#374151;line-height:1.55;white-space:pre-wrap;background:#f8fafc;padding:.7rem;border-radius:6px;border:1px solid #e2e8f0;max-height:300px;overflow-y:auto">' + esc(a.application_remarks || 'No remarks available.') + '</div>';
                }
            }).catch(function() {
                body.innerHTML = '<div class="no-data">Failed to load details.</div>';
            });
        }

        // Jump straight to the implementation detail page (same target as the Management tab).
        function openImplMenu(implId) {
            window.location.href = '?view=detail&tracker_id=' + implId;
        }

        // ── Reference Materials ──────────────────────────────────
        var refMatState = { appId: null, canUpload: false };

        function openReferenceMaterials(appId) {
            refMatState = { appId: appId, canUpload: false };
            var app = (myAppsData || []).filter(function(a) { return a.application_id == appId; })[0];
            document.getElementById('refMatTitle').innerHTML =
                '<i class="fas fa-folder-open"></i> Reference Materials' +
                (app ? ' \u2013 ' + esc(app.application_title) : '');
            document.getElementById('refMatBody').innerHTML =
                '<div class="no-data"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            openModal('refMatModal');
            loadReferenceMaterials();
        }

        function loadReferenceMaterials() {
            api('get_reference_materials', null, 'GET', { app_id: refMatState.appId }).then(function(d) {
                var body = document.getElementById('refMatBody');
                if (!d.success) { body.innerHTML = '<div class="no-data">' + esc(d.message || 'Failed to load.') + '</div>'; return; }
                refMatState.canUpload = !!d.can_upload;
                renderReferenceMaterials(d.materials);
            }).catch(function() {
                document.getElementById('refMatBody').innerHTML = '<div class="no-data">Failed to load.</div>';
            });
        }

        function renderReferenceMaterials(materials) {
            var body = document.getElementById('refMatBody');
            var html = refMatState.canUpload ? refMatUploadForm() : '';
            if (!materials.length) {
                html += '<div class="no-data" style="padding:1.4rem 1rem"><i class="fas fa-folder-open" style="font-size:1.3rem;display:block;margin-bottom:.4rem"></i>No reference materials yet.</div>';
            } else {
                html += '<div class="rm-list">';
                materials.forEach(function(m) { html += refMatItem(m); });
                html += '</div>';
            }
            body.innerHTML = html;
        }

        function refMatUploadForm() {
            return '<div class="rm-upload">' +
                '<div class="rm-upload-title"><i class="fas fa-cloud-arrow-up"></i> Upload New Material</div>' +
                '<div class="form-group"><label class="form-label">Title *</label><input type="text" id="rmTitle" class="form-input" placeholder="e.g. User Manual v1.2"></div>' +
                '<div class="form-group"><label class="form-label">Description</label><textarea id="rmDesc" class="form-textarea" placeholder="Optional notes..."></textarea></div>' +
                '<div class="form-group"><label class="form-label">File *</label><input type="file" id="rmFile" class="form-input"></div>' +
                '<button class="btn btn-primary" id="rmUploadBtn" onclick="submitReferenceMaterial()"><i class="fas fa-upload"></i> Upload</button>' +
            '</div>';
        }

        function refMatItem(m) {
            var del = refMatState.canUpload
                ? '<button class="btn btn-danger btn-sm" onclick="deleteReferenceMaterial(' + m.id + ')"><i class="fas fa-trash"></i></button>'
                : '';
            return '<div class="rm-item">' +
                '<div class="rm-item-icon"><i class="fas ' + rmFileIcon(m.file_name) + '"></i></div>' +
                '<div class="rm-item-info">' +
                    '<div class="rm-item-title">' + esc(m.title) + '</div>' +
                    (m.description ? '<div class="rm-item-desc">' + esc(m.description) + '</div>' : '') +
                    '<div class="rm-item-meta">' +
                        '<span>' + esc(m.file_name) + ' \u00b7 ' + rmFmtSize(m.file_size) + '</span>' +
                        '<span class="rm-item-added"><i class="fas fa-clock"></i>Added ' + esc(rmFmtDate(m.created_at)) + ' \u00b7 ' + esc(m.uploaded_by_name) + '</span>' +
                    '</div>' +
                '</div>' +
                '<div class="rm-item-actions">' +
                    '<a class="btn btn-secondary btn-sm" href="?action=download_reference_material&id=' + m.id + '"><i class="fas fa-download"></i></a>' +
                    del +
                '</div>' +
            '</div>';
        }

        function submitReferenceMaterial() {
            var title = (document.getElementById('rmTitle').value || '').trim();
            var desc = document.getElementById('rmDesc').value || '';
            var fileEl = document.getElementById('rmFile');
            if (!title) { showAlert('Title is required.', 'error'); return; }
            if (!fileEl.files || !fileEl.files.length) { showAlert('Please choose a file.', 'error'); return; }
            var btn = document.getElementById('rmUploadBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
            var fd = new FormData();
            fd.append('app_id', refMatState.appId);
            fd.append('title', title);
            fd.append('description', desc);
            fd.append('file', fileEl.files[0]);
            fetch('?action=upload_reference_material', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (!d.success) {
                        showAlert(d.message || 'Upload failed.', 'error');
                        btn.disabled = false; btn.innerHTML = '<i class="fas fa-upload"></i> Upload';
                        return;
                    }
                    showAlert('Reference material uploaded.');
                    loadReferenceMaterials();
                })
                .catch(function() {
                    showAlert('Upload failed.', 'error');
                    btn.disabled = false; btn.innerHTML = '<i class="fas fa-upload"></i> Upload';
                });
        }

        function deleteReferenceMaterial(id) {
            if (!confirm('Delete this reference material?')) return;
            api('delete_reference_material', { material_id: id }, 'POST').then(function(d) {
                if (!d.success) { showAlert(d.message || 'Delete failed.', 'error'); return; }
                showAlert('Reference material deleted.');
                loadReferenceMaterials();
            }).catch(function() { showAlert('Delete failed.', 'error'); });
        }

        function rmFmtSize(bytes) {
            bytes = +bytes || 0;
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        }

        // "2026-07-16 08:29:00" -> "16 Jul 2026 · 08:29"
        function rmFmtDate(dt) {
            if (!dt) return '';
            var parts = String(dt).split(' ');
            var d = (parts[0] || '').split('-');
            var t = (parts[1] || '').substring(0, 5);
            var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            var mi = parseInt(d[1], 10) - 1;
            if (d.length < 3 || isNaN(mi) || mi < 0 || mi > 11) return dt;
            return parseInt(d[2], 10) + ' ' + months[mi] + ' ' + d[0] + (t ? ' \u00b7 ' + t : '');
        }

        function rmFileIcon(name) {
            var ext = (name || '').split('.').pop().toLowerCase();
            if (ext === 'pdf') return 'fa-file-pdf';
            if (ext === 'doc' || ext === 'docx') return 'fa-file-word';
            if (ext === 'xls' || ext === 'xlsx' || ext === 'csv') return 'fa-file-excel';
            if (ext === 'ppt' || ext === 'pptx') return 'fa-file-powerpoint';
            if (['png', 'jpg', 'jpeg', 'gif', 'webp'].indexOf(ext) !== -1) return 'fa-file-image';
            if (ext === 'zip') return 'fa-file-zipper';
            if (ext === 'mp4') return 'fa-file-video';
            if (ext === 'txt') return 'fa-file-lines';
            return 'fa-file';
        }

        // ── Init ─────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('sectionMyApps')) {
                switchTab('apps');
            }
            if (document.getElementById('listWrap')) {
                $('#statusFilter').select2({
                    minimumResultsForSearch: -1,
                    width: 'resolve'
                });
                $('#statusFilter').on('change', loadTrackers);
                if ($('#compFilter').length) {
                    $('#compFilter').select2({
                        width: 'resolve'
                    });
                    $('#compFilter').on('change', loadTrackers);
                }
                setView(window.innerWidth <= 768 ? 'card' : 'table');
            }
            var mc = document.querySelector('.main-content'),
                sb = document.querySelector('.sidebar');

            function upd() {
                var mob = window.innerWidth <= 768,
                    closed = sb && sb.classList.contains('closed');
                if (!mob && !closed) mc.classList.add('sidebar-open');
                else mc.classList.remove('sidebar-open');
            }
            var tb = document.querySelector('.sidebar-toggle'),
                cb = document.querySelector('.sidebar-close');
            if (tb) tb.addEventListener('click', function() {
                setTimeout(upd, 50);
            });
            if (cb) cb.addEventListener('click', function() {
                setTimeout(upd, 50);
            });
            window.addEventListener('resize', function() {
                setTimeout(upd, 100);
                if (document.getElementById('listWrap') && cached.length) doRender(cached);
            });
            upd();
        });
    </script>
</body>

</html>