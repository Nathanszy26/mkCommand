<?php
session_start();

// Check if user is logged in
require_once '../includes/UnifiedAuth.php';

if (!UnifiedAuth::isLoggedIn()) {
    header('Location: /accounts/mkPortal/user_validation.php');
    exit;
}

$currentUser = UnifiedAuth::getLoggedInUser();

// Permission check functions
function isSuperAdmin($person, $compId)
{
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM site_check_in_admins 
            WHERE person = ? AND comp_id = ? AND deleted = 0
        ");
        $stmt->execute([$person, $compId]);
        $result = $stmt->fetch();
        return $result['count'] > 0;
    } catch (Exception $e) {
        error_log("Super admin check failed: " . $e->getMessage());
        return false;
    }
}

function isCompanyAdmin($person, $compId)
{
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM site_check_in_company_admins 
            WHERE person = ? AND comp_id = ? AND deleted = 0
        ");
        $stmt->execute([$person, $compId]);
        $result = $stmt->fetch();
        return $result['count'] > 0;
    } catch (Exception $e) {
        error_log("Company admin check failed: " . $e->getMessage());
        return false;
    }
}

function getFaceAdminInfo($person, $compId)
{
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT all_companies, assigned_companies
            FROM face_checkIn_admins
            WHERE person = ? AND comp_id = ? AND status = '1'
            LIMIT 1
        ");
        $stmt->execute([$person, $compId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        error_log("Face admin check failed: " . $e->getMessage());
        return null;
    }
}

// Lunch break presentation helpers (shared by table + CSV export)
function lunchLabel(array $log)
{
    if (empty($log['is_lunch_break']) || $log['is_lunch_break'] != 1) {
        return '';
    }
    $seq = isset($log['lunch_seq']) ? $log['lunch_seq'] : null;
    if ($seq === 'out') return 'Lunch Out';
    if ($seq === 'in')  return 'Lunch In';
    return 'Lunch';
}

function lunchDurationText(array $log)
{
    $m = isset($log['lunch_duration_minutes']) ? $log['lunch_duration_minutes'] : null;
    if ($m === null) return '';
    $h = intdiv($m, 60);
    $r = $m % 60;
    return $h > 0 ? "{$h}h {$r}m" : "{$r}m";
}

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

// Staff Portal Database for user names
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

$isSuperAdmin = isSuperAdmin($currentUser['person'], $currentUser['comp_id']);
$isCompanyAdmin = isCompanyAdmin($currentUser['person'], $currentUser['comp_id']);

$faceAdminInfo = getFaceAdminInfo($currentUser['person'], $currentUser['comp_id']);
$isFaceAdmin = $faceAdminInfo !== null;
$faceAllCompanies = $isFaceAdmin && $faceAdminInfo['all_companies'] == '1';
$faceAssignedCompanyIds = [];
if ($isFaceAdmin && !$faceAllCompanies) {
    $faceAssignedCompanyIds = array_values(array_filter(array_map('intval', explode(',', $faceAdminInfo['assigned_companies'] ?? ''))));
}

class SiteCheckInReportService
{
    private $pdo;
    private $staffPdo;
    private $currentUser;
    private $isSuperAdmin;
    private $isCompanyAdmin;
    private $isFaceAdmin;
    private $faceAllCompanies;
    private $faceAssignedCompanyIds;

    public function __construct($currentUser, $isSuperAdmin, $isCompanyAdmin, $isFaceAdmin = false, $faceAllCompanies = false, $faceAssignedCompanyIds = [])
    {
        $this->pdo = Database::getConnection();
        $this->staffPdo = StaffPortalDatabase::getConnection();
        $this->currentUser = $currentUser;
        $this->isSuperAdmin = $isSuperAdmin;
        $this->isCompanyAdmin = $isCompanyAdmin;
        $this->isFaceAdmin = $isFaceAdmin;
        $this->faceAllCompanies = $faceAllCompanies;
        $this->faceAssignedCompanyIds = $faceAssignedCompanyIds;
    }

    public function getCompanies(array $limitToIds = [])
    {
        if (empty($limitToIds)) {
            $stmt = $this->staffPdo->prepare("SELECT id, name FROM subsidiaries WHERE status = 1 ORDER BY name");
            $stmt->execute();
        } else {
            $placeholders = implode(',', array_fill(0, count($limitToIds), '?'));
            $stmt = $this->staffPdo->prepare("SELECT id, name FROM subsidiaries WHERE status = 1 AND id IN ($placeholders) ORDER BY name");
            $stmt->execute($limitToIds);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSites($compId)
    {
        $stmt = $this->pdo->prepare("
            SELECT id, site_name, district, address
            FROM site_check_in_sites 
            WHERE comp_id = ? AND deleted = '0'
            ORDER BY site_name
        ");
        $stmt->execute([$compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStaffList($compId)
    {
        $stmt = $this->staffPdo->prepare("
        SELECT DISTINCT person, fullname 
        FROM users 
        WHERE comp_id = ? AND status = '1' AND deleted = '0'
        ORDER BY fullname
    ");
        $stmt->execute([$compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function generateReport($filters)
    {
        $sql = "
            SELECT 
                r.id,
                r.round_code,
                r.site_id,
                r.person,
                r.comp_id,
                r.check_in_status,
                r.total_points,
                r.completed_points,
                r.started_at,
                r.completed_at,
                r.duration_minutes,
                r.cancel_reason,
                r.updated_at,
                s.site_name,
                s.district
            FROM site_check_in_rounds r
            LEFT JOIN site_check_in_sites s ON r.site_id = s.id AND s.deleted = '0'
            WHERE r.deleted = '0'
                AND s.id IS NOT NULL
    ";

        $params = [];

        // Permission-based filtering
        if ($this->isSuperAdmin || ($this->isFaceAdmin && $this->faceAllCompanies)) {
            if (!empty($filters['comp_id'])) {
                $sql .= " AND r.comp_id = ?";
                $params[] = $filters['comp_id'];
            }
        } elseif ($this->isFaceAdmin && !$this->faceAllCompanies) {
            if (!empty($filters['comp_id']) && in_array((int)$filters['comp_id'], $this->faceAssignedCompanyIds)) {
                $sql .= " AND r.comp_id = ?";
                $params[] = $filters['comp_id'];
            } elseif (!empty($this->faceAssignedCompanyIds)) {
                $placeholders = implode(',', array_fill(0, count($this->faceAssignedCompanyIds), '?'));
                $sql .= " AND r.comp_id IN ($placeholders)";
                $params = array_merge($params, $this->faceAssignedCompanyIds);
            } else {
                $sql .= " AND 1=0";
            }
        } elseif ($this->isCompanyAdmin) {
            $sql .= " AND r.comp_id = ?";
            $params[] = $this->currentUser['comp_id'];
        } else {
            $sql .= " AND r.person = ? AND r.comp_id = ?";
            $params[] = $this->currentUser['person'];
            $params[] = $this->currentUser['comp_id'];
        }

        // Site filter
        if (!empty($filters['site_id'])) {
            $sql .= " AND r.site_id = ?";
            $params[] = $filters['site_id'];
        }

        // Staff filter
        if (!empty($filters['staff'])) {
            $sql .= " AND r.person = ?";
            $params[] = $filters['staff'];
        }

        // Status filter
        if (!empty($filters['status'])) {
            $sql .= " AND r.check_in_status = ?";
            $params[] = $filters['status'];
        }

        // Date range filter
        $dateFilter = $this->buildDateFilter($filters['date'], $filters['range'], $filters['date_to'] ?? null);
        if ($dateFilter['sql']) {
            $sql .= " " . $dateFilter['sql'];
            $params = array_merge($params, $dateFilter['params']);
        }

        $sql .= " ORDER BY s.site_name ASC, r.started_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Resolve user names
        if ($this->isCompanyAdmin || $this->isSuperAdmin || $this->isFaceAdmin) {
            foreach ($results as &$row) {
                if (!empty($row['person']) && !empty($row['comp_id'])) {
                    $userStmt = $this->staffPdo->prepare("SELECT fullname FROM users WHERE person = ? AND comp_id = ? AND status = '1'");
                    $userStmt->execute([$row['person'], $row['comp_id']]);
                    $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
                    $row['user_fullname'] = $userData ? $userData['fullname'] : 'Unknown User';
                } else {
                    $row['user_fullname'] = 'Unknown User';
                }
            }
        }

        // Get checkpoint logs for all rounds
        if (!empty($results)) {
            $roundIds = array_column($results, 'id');
            $checkpointLogs = $this->getCheckpointLogsForRounds($roundIds);

            foreach ($results as &$row) {
                $row['checkpoint_logs'] = isset($checkpointLogs[$row['id']]) ? $checkpointLogs[$row['id']] : [];
            }
        }

        return $this->groupBySite($results);
    }

    private function getCheckpointLogsForRounds($roundIds)
    {
        if (empty($roundIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($roundIds), '?'));

        $stmt = $this->pdo->prepare("
            SELECT 
                l.round_id,
                l.point_id,
                l.id as log_id,
                l.checked_in_at,
                l.distance_meters,
                l.remarks,
                l.modified_by,
                l.modified_by_comp_id,
                l.modified_by_datetime,
                l.modified_by_action,
                p.point_name,
                p.point_order,
                (SELECT COUNT(*) FROM site_check_in_photos WHERE log_id = l.id AND deleted = '0') as photo_count
            FROM site_check_in_logs l
            LEFT JOIN site_check_in_points p ON l.point_id = p.id
            WHERE l.round_id IN ($placeholders) AND l.deleted = '0'
            ORDER BY p.point_order ASC, l.checked_in_at ASC
        ");

        $stmt->execute($roundIds);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group logs by round_id
        $grouped = [];
        foreach ($logs as $log) {
            $roundId = $log['round_id'];
            if (!isset($grouped[$roundId])) {
                $grouped[$roundId] = [];
            }
            $grouped[$roundId][$log['point_id']] = $log;
        }

        return $grouped;
    }

    private function buildDateFilter($date, $range, $dateTo = null)
    {
        $sql = "";
        $params = [];

        switch ($range) {
            case 'today':
                $sql = "AND DATE(r.started_at) = ?";
                $params[] = $date;
                break;

            case 'week':
                $startOfWeek = date('Y-m-d', strtotime('monday this week', strtotime($date)));
                $endOfWeek = date('Y-m-d', strtotime('sunday this week', strtotime($date)));
                $sql = "AND DATE(r.started_at) BETWEEN ? AND ?";
                $params[] = $startOfWeek;
                $params[] = $endOfWeek;
                break;

            case 'month':
                $startOfMonth = date('Y-m-01', strtotime($date));
                $endOfMonth = date('Y-m-t', strtotime($date));
                $sql = "AND DATE(r.started_at) BETWEEN ? AND ?";
                $params[] = $startOfMonth;
                $params[] = $endOfMonth;
                break;

            case 'year':
                $startOfYear = date('Y-01-01', strtotime($date));
                $endOfYear = date('Y-12-31', strtotime($date));
                $sql = "AND DATE(r.started_at) BETWEEN ? AND ?";
                $params[] = $startOfYear;
                $params[] = $endOfYear;
                break;

            case 'custom':
                if (!empty($dateTo) && $dateTo !== $date) {
                    $from = $date;
                    $to = $dateTo;
                    if (strtotime($to) < strtotime($from)) {
                        $tmp = $from;
                        $from = $to;
                        $to = $tmp;
                    }
                    $sql = "AND DATE(r.started_at) BETWEEN ? AND ?";
                    $params[] = $from;
                    $params[] = $to;
                } else {
                    $sql = "AND DATE(r.started_at) = ?";
                    $params[] = $date;
                }
                break;
        }

        return ['sql' => $sql, 'params' => $params];
    }

    private function groupBySite($results)
    {
        $grouped = [];
        foreach ($results as $row) {
            $siteKey = $row['site_id'] . '_' . (isset($row['site_name']) ? $row['site_name'] : 'Unknown Site');
            if (!isset($grouped[$siteKey])) {
                // Get checkpoints for this site
                $checkpoints = $this->getCheckpointsForSite($row['site_id']);

                $grouped[$siteKey] = [
                    'site_id' => $row['site_id'],
                    'site_name' => isset($row['site_name']) ? $row['site_name'] : 'Unknown Site',
                    'district' => isset($row['district']) ? $row['district'] : '',
                    'checkpoints' => $checkpoints,
                    'rounds' => []
                ];
            }
            $grouped[$siteKey]['rounds'][] = $row;
        }
        return $grouped;
    }

    public function getRoundDetails($roundId)
    {
        // Check permissions
        $permissionSql = "SELECT * FROM site_check_in_rounds WHERE id = ?";
        $params = [$roundId];

        if (!$this->isSuperAdmin && !$this->isCompanyAdmin && !$this->isFaceAdmin) {
            $permissionSql .= " AND person = ? AND comp_id = ?";
            $params[] = $this->currentUser['person'];
            $params[] = $this->currentUser['comp_id'];
        } elseif ($this->isCompanyAdmin && !$this->isSuperAdmin && !$this->isFaceAdmin) {
            $permissionSql .= " AND comp_id = ?";
            $params[] = $this->currentUser['comp_id'];
        }

        $stmt = $this->pdo->prepare($permissionSql);
        $stmt->execute($params);
        $round = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$round) {
            throw new Exception("Round not found or access denied");
        }

        // Get checkpoint logs with point information
        $logsStmt = $this->pdo->prepare("
            SELECT 
                l.id,
                l.round_id,
                l.point_id,
                l.checked_in_at,
                l.latitude,
                l.longitude,
                l.distance_meters,
                l.remarks,
                p.point_name,
                p.point_order,
                p.description as point_description,
                (SELECT COUNT(*) FROM site_check_in_photos WHERE log_id = l.id AND deleted = '0') as photo_count
            FROM site_check_in_logs l
            LEFT JOIN site_check_in_points p ON l.point_id = p.id
            WHERE l.round_id = ? AND l.deleted = '0'
            ORDER BY l.checked_in_at ASC
        ");
        $logsStmt->execute([$roundId]);
        $logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get photos
        $photosStmt = $this->pdo->prepare("
            SELECT 
                ph.id,
                ph.log_id,
                ph.document_file_name,
                ph.original_file_name,
                ph.file_size,
                ph.upload_timestamp
            FROM site_check_in_photos ph
            INNER JOIN site_check_in_logs l ON ph.log_id = l.id
            WHERE l.round_id = ? AND ph.deleted = '0'
            ORDER BY ph.upload_timestamp ASC
        ");
        $photosStmt->execute([$roundId]);
        $photos = $photosStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'round' => $round,
            'logs' => $logs,
            'photos' => $photos
        ];
    }

    public function getCheckpointsForSite($siteId)
    {
        $stmt = $this->pdo->prepare("
            SELECT id, point_name, point_order, description
            FROM site_check_in_points
            WHERE site_id = ? AND deleted = '0'
            ORDER BY point_order ASC
        ");
        $stmt->execute([$siteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCheckpointDetails($logId)
    {
        // Check permissions - get the round first
        $stmt = $this->pdo->prepare("
            SELECT r.* 
            FROM site_check_in_logs l
            JOIN site_check_in_rounds r ON l.round_id = r.id
            WHERE l.id = ? AND l.deleted = '0'
        ");
        $stmt->execute([$logId]);
        $round = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$round) {
            throw new Exception("Checkpoint not found");
        }

        // Verify permissions
        if (!$this->isSuperAdmin && !$this->isCompanyAdmin && !$this->isFaceAdmin) {
            if ($round['person'] !== $this->currentUser['person'] || $round['comp_id'] != $this->currentUser['comp_id']) {
                throw new Exception("Access denied");
            }
        } elseif ($this->isCompanyAdmin && !$this->isSuperAdmin && !$this->isFaceAdmin) {
            if ($round['comp_id'] != $this->currentUser['comp_id']) {
                throw new Exception("Access denied");
            }
        }

        // Get checkpoint log details with modification data and modifier name
        $logStmt = $this->pdo->prepare("
            SELECT 
                l.*,
                p.point_name,
                p.point_order,
                p.description as point_description,
                u.fullname as modifier_name
            FROM site_check_in_logs l
            LEFT JOIN site_check_in_points p ON l.point_id = p.id
            LEFT JOIN staff_portal2.users u ON l.modified_by = u.person 
                AND l.modified_by_comp_id = u.comp_id 
                AND u.status = '1'
            WHERE l.id = ? AND l.deleted = '0'
        ");
        $logStmt->execute([$logId]);
        $log = $logStmt->fetch(PDO::FETCH_ASSOC);

        // Get photos for this checkpoint
        $photosStmt = $this->pdo->prepare("
            SELECT 
                id,
                log_id,
                document_file_name,
                original_file_name,
                file_size,
                upload_timestamp
            FROM site_check_in_photos
            WHERE log_id = ? AND deleted = '0'
            ORDER BY upload_timestamp ASC
        ");
        $photosStmt->execute([$logId]);
        $photos = $photosStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'log' => $log,
            'photos' => $photos
        ];
    }

    // ==================== STAFF ATTENDANCE METHODS ====================

    public function getStaffSites($compId)
    {
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT s.id, s.site_name, s.district
            FROM staff_attendance_sites s
            INNER JOIN staff_attendance_logs l ON l.site_id = s.id AND l.deleted = '0'
            WHERE l.comp_id = ? AND s.deleted = '0'
            ORDER BY s.site_name
        ");
        $stmt->execute([$compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStaffProfilesList($compId)
    {
        $stmt = $this->staffPdo->prepare("
            SELECT DISTINCT person, comp_id, fullname
            FROM users
            WHERE comp_id = ? AND status = '1' AND deleted = '0'
            ORDER BY fullname
        ");
        $stmt->execute([$compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function generateStaffReport($filters)
    {
        $sql = "
            SELECT 
                l.id,
                l.mymk_id,
                l.site_id,
                l.point_id,
                l.person,
                l.comp_id,
                l.check_in_type,
                l.latitude,
                l.longitude,
                l.distance_meters,
                l.checked_in_at,
                l.created_at,
                l.is_lunch_break,
                p.point_name,
                s.site_name,
                s.district
            FROM staff_attendance_logs l
            LEFT JOIN staff_attendance_points p ON l.point_id = p.id
            LEFT JOIN staff_attendance_sites s ON l.site_id = s.id
            WHERE l.deleted = '0'
                AND s.id IS NOT NULL
        ";

        $params = [];

        // Permission-based filtering
        if ($this->isSuperAdmin || ($this->isFaceAdmin && $this->faceAllCompanies)) {
            if (!empty($filters['comp_id'])) {
                $sql .= " AND l.comp_id = ?";
                $params[] = $filters['comp_id'];
            }
        } elseif ($this->isFaceAdmin && !$this->faceAllCompanies) {
            if (!empty($filters['comp_id']) && in_array((int)$filters['comp_id'], $this->faceAssignedCompanyIds)) {
                $sql .= " AND l.comp_id = ?";
                $params[] = $filters['comp_id'];
            } elseif (!empty($this->faceAssignedCompanyIds)) {
                $placeholders = implode(',', array_fill(0, count($this->faceAssignedCompanyIds), '?'));
                $sql .= " AND l.comp_id IN ($placeholders)";
                $params = array_merge($params, $this->faceAssignedCompanyIds);
            } else {
                $sql .= " AND 1=0";
            }
        } elseif ($this->isCompanyAdmin) {
            $sql .= " AND l.comp_id = ?";
            $params[] = $this->currentUser['comp_id'];
        } else {
            $sql .= " AND l.person = ? AND l.comp_id = ?";
            $params[] = $this->currentUser['person'];
            $params[] = $this->currentUser['comp_id'];
        }

        // Site filter
        if (!empty($filters['staff_site_id'])) {
            $sql .= " AND l.site_id = ?";
            $params[] = $filters['staff_site_id'];
        }

        // Staff filter
        if (!empty($filters['staff_person'])) {
            $sql .= " AND l.person = ?";
            $params[] = $filters['staff_person'];
        }

        // Check-in type filter
        if (!empty($filters['check_in_type'])) {
            $sql .= " AND l.check_in_type = ?";
            $params[] = $filters['check_in_type'];
        }

        // Date range filter
        $dateFilter = $this->buildStaffDateFilter($filters['date'], $filters['range'], $filters['date_to'] ?? null);
        if ($dateFilter['sql']) {
            $sql .= " " . $dateFilter['sql'];
            $params = array_merge($params, $dateFilter['params']);
        }

        $sql .= " ORDER BY s.site_name ASC, l.checked_in_at DESC LIMIT 500";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Resolve user names
        if ($this->isCompanyAdmin || $this->isSuperAdmin || $this->isFaceAdmin) {
            foreach ($results as &$row) {
                if (!empty($row['person']) && !empty($row['comp_id'])) {
                    $userStmt = $this->staffPdo->prepare("SELECT fullname FROM users WHERE person = ? AND comp_id = ? AND status = '1'");
                    $userStmt->execute([$row['person'], $row['comp_id']]);
                    $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
                    $row['user_fullname'] = $userData ? $userData['fullname'] : 'Unknown User';
                } else {
                    $row['user_fullname'] = 'Unknown User';
                }
            }
        }

        // Fetch all photos for these logs in one query
        if (!empty($results)) {
            $logIds      = array_column($results, 'id');
            $photosByLog = $this->fetchStaffPhotosByLogIds($logIds);

            foreach ($results as &$row) {
                $row['photos'] = isset($photosByLog[$row['id']]) ? $photosByLog[$row['id']] : [];
            }
            unset($row); // kill the dangling reference before reassigning $results
        }

        $results = $this->annotateLunchPairs($results);

        return $this->groupStaffBySite($results);
    }

    /**
     * Pairs same-day lunch check-ins per staff (oldest = out, next = in) and
     * attaches lunch_pair_id, lunch_seq and lunch_duration_minutes to each row.
     * An unmatched OUT is flagged via lunch_incomplete.
     */
    private function annotateLunchPairs(array $logs)
    {
        $byStaffDay = [];
        foreach ($logs as $i => $row) {
            if (empty($row['is_lunch_break']) || $row['is_lunch_break'] != 1) {
                continue;
            }
            $key = $row['person'] . '|' . $row['comp_id'] . '|' . date('Y-m-d', strtotime($row['checked_in_at']));
            $byStaffDay[$key][] = $i;
        }

        foreach ($byStaffDay as $key => $indexes) {
            usort($indexes, function ($a, $b) use ($logs) {
                return strtotime($logs[$a]['checked_in_at']) - strtotime($logs[$b]['checked_in_at']);
            });

            foreach (array_chunk($indexes, 2) as $pairNo => $pair) {
                $pairId = substr(md5($key), 0, 8) . '-' . ($pairNo + 1);
                $outIdx = $pair[0];
                $inIdx  = isset($pair[1]) ? $pair[1] : null;

                $logs[$outIdx]['lunch_pair_id']          = $pairId;
                $logs[$outIdx]['lunch_seq']              = 'out';
                $logs[$outIdx]['lunch_duration_minutes'] = null;

                if ($inIdx === null) {
                    $logs[$outIdx]['lunch_incomplete'] = true;
                    continue;
                }

                $minutes = (int) round(
                    (strtotime($logs[$inIdx]['checked_in_at']) - strtotime($logs[$outIdx]['checked_in_at'])) / 60
                );

                $logs[$outIdx]['lunch_duration_minutes'] = $minutes;
                $logs[$inIdx]['lunch_pair_id']           = $pairId;
                $logs[$inIdx]['lunch_seq']               = 'in';
                $logs[$inIdx]['lunch_duration_minutes']  = $minutes;
            }
        }

        return $logs;
    }

    private function groupStaffBySite($results)
    {
        $grouped = [];
        foreach ($results as $row) {
            $siteKey = $row['site_id'] . '_' . (isset($row['site_name']) ? $row['site_name'] : 'Unknown Site');
            if (!isset($grouped[$siteKey])) {
                $grouped[$siteKey] = [
                    'site_id' => $row['site_id'],
                    'site_name' => isset($row['site_name']) ? $row['site_name'] : 'Unknown Site',
                    'district' => isset($row['district']) ? $row['district'] : '',
                    'logs' => []
                ];
            }
            $grouped[$siteKey]['logs'][] = $row;
        }
        return $grouped;
    }

    private function fetchStaffPhotosByLogIds(array $logIds)
    {
        if (empty($logIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($logIds), '?'));

        $stmt = $this->pdo->prepare("
            SELECT log_id, file_path
            FROM staff_attendance_photos
            WHERE log_id IN ({$placeholders})
            AND deleted = '0'
            ORDER BY log_id, id ASC
        ");
        $stmt->execute($logIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['log_id']][] = $row['file_path'];
        }

        return $grouped;
    }

    private function buildStaffDateFilter($date, $range, $dateTo = null)
    {
        $sql = "";
        $params = [];

        switch ($range) {
            case 'today':
                $sql = "AND DATE(l.checked_in_at) = ?";
                $params[] = $date;
                break;
            case 'custom':
                if (!empty($dateTo) && $dateTo !== $date) {
                    $from = $date;
                    $to = $dateTo;
                    if (strtotime($to) < strtotime($from)) {
                        $tmp = $from;
                        $from = $to;
                        $to = $tmp;
                    }
                    $sql = "AND DATE(l.checked_in_at) BETWEEN ? AND ?";
                    $params[] = $from;
                    $params[] = $to;
                } else {
                    $sql = "AND DATE(l.checked_in_at) = ?";
                    $params[] = $date;
                }
                break;
            case 'week':
                $sql = "AND DATE(l.checked_in_at) BETWEEN ? AND ?";
                $params[] = date('Y-m-d', strtotime('monday this week', strtotime($date)));
                $params[] = date('Y-m-d', strtotime('sunday this week', strtotime($date)));
                break;
            case 'month':
                $sql = "AND DATE(l.checked_in_at) BETWEEN ? AND ?";
                $params[] = date('Y-m-01', strtotime($date));
                $params[] = date('Y-m-t', strtotime($date));
                break;
            case 'year':
                $sql = "AND DATE(l.checked_in_at) BETWEEN ? AND ?";
                $params[] = date('Y-01-01', strtotime($date));
                $params[] = date('Y-12-31', strtotime($date));
                break;
        }

        return ['sql' => $sql, 'params' => $params];
    }

    public function getStaffCheckInDetail($logId)
    {
        // Verify the log exists and check permissions
        $stmt = $this->pdo->prepare("
            SELECT l.*, p.point_name, s.site_name
            FROM staff_attendance_logs l
            LEFT JOIN staff_attendance_points p ON l.point_id = p.id
            LEFT JOIN staff_attendance_sites s ON l.site_id = s.id
            WHERE l.id = ? AND l.deleted = '0'
        ");
        $stmt->execute([$logId]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$log) {
            throw new Exception("Check-in record not found");
        }

        // Permission check
        if (!$this->isSuperAdmin && !$this->isCompanyAdmin && !$this->isFaceAdmin) {
            if ($log['person'] !== $this->currentUser['person'] || $log['comp_id'] != $this->currentUser['comp_id']) {
                throw new Exception("Access denied");
            }
        } elseif ($this->isCompanyAdmin && !$this->isSuperAdmin && !$this->isFaceAdmin) {
            if ($log['comp_id'] != $this->currentUser['comp_id']) {
                throw new Exception("Access denied");
            }
        }

        // Resolve staff name
        $userStmt = $this->staffPdo->prepare("SELECT fullname FROM users WHERE person = ? AND comp_id = ? AND status = '1'");
        $userStmt->execute([$log['person'], $log['comp_id']]);
        $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
        $log['user_fullname'] = $userData ? $userData['fullname'] : 'Unknown User';

        // Fetch photos from staff_attendance_photos
        $photosStmt = $this->pdo->prepare("
            SELECT file_path
            FROM staff_attendance_photos
            WHERE log_id = ? AND deleted = '0'
            ORDER BY id ASC
        ");
        $photosStmt->execute([$logId]);
        $log['photos'] = $photosStmt->fetchAll(PDO::FETCH_COLUMN);

        return $log;
    }

    // ==================== STAFF ATTENDANCE EXPORT ====================

    public function exportStaffReportCsv($staffReportData)
    {
        $filename = 'staff_attendance_' . date('Ymd_His') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders names/unicode correctly

        fputcsv($out, [
            'No.', 'Site', 'District', 'Date / Time', 'Staff',
            'Point', 'Type', 'Lunch', 'Lunch Duration', 'Latitude', 'Longitude'
        ]);

        $i = 1;
        foreach ($staffReportData as $siteGroup) {
            foreach ($siteGroup['logs'] as $log) {
                $staffName = !empty($log['user_fullname'])
                    ? ucwords(strtolower($log['user_fullname']))
                    : ucwords(strtolower($this->currentUser['fullname'] ?? 'Unknown'));

                fputcsv($out, [
                    $i++,
                    $siteGroup['site_name'],
                    $siteGroup['district'],
                    date('Y-m-d H:i:s', strtotime($log['checked_in_at'])),
                    $staffName,
                    $log['point_name'] ?? 'N/A',
                    $log['check_in_type'] === 'face' ? 'Face Recognition' : 'Photo',
                    lunchLabel($log),
                    (isset($log['lunch_seq']) && $log['lunch_seq'] === 'in') ? lunchDurationText($log) : '',
                    $log['latitude'] ?? '',
                    $log['longitude'] ?? '',
                ]);
            }
        }

        fclose($out);
    }
}

// Initialize service
$reportService = new SiteCheckInReportService($currentUser, $isSuperAdmin, $isCompanyAdmin, $isFaceAdmin, $faceAllCompanies, $faceAssignedCompanyIds);

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'get_round_details':
                if (!isset($_GET['round_id'])) {
                    throw new Exception("Round ID required");
                }
                $details = $reportService->getRoundDetails($_GET['round_id']);
                echo json_encode(['success' => true, 'details' => $details]);
                break;

            case 'get_sites':
                if (!isset($_GET['comp_id'])) {
                    throw new Exception("Company ID required");
                }
                $sites = $reportService->getSites($_GET['comp_id']);
                echo json_encode(['success' => true, 'sites' => $sites]);
                break;

            case 'get_checkpoint_details':
                if (!isset($_GET['log_id'])) {
                    throw new Exception("Log ID required");
                }
                $details = $reportService->getCheckpointDetails($_GET['log_id']);
                echo json_encode(['success' => true, 'details' => $details]);
                break;

            case 'get_staff':
                if (!isset($_GET['comp_id'])) {
                    throw new Exception("Company ID required");
                }
                $staff = $reportService->getStaffList($_GET['comp_id']);
                echo json_encode(['success' => true, 'staff' => $staff]);
                break;

            case 'get_staff_sites':
                if (!isset($_GET['comp_id'])) {
                    throw new Exception("Company ID required");
                }
                $staffSites = $reportService->getStaffSites($_GET['comp_id']);
                echo json_encode(['success' => true, 'sites' => $staffSites]);
                break;

            case 'get_staff_profiles':
                if (!isset($_GET['comp_id'])) {
                    throw new Exception("Company ID required");
                }
                $profiles = $reportService->getStaffProfilesList($_GET['comp_id']);
                echo json_encode(['success' => true, 'profiles' => $profiles]);
                break;

            case 'get_staff_checkin_detail':
                if (!isset($_GET['log_id'])) {
                    throw new Exception("Log ID required");
                }
                $detail = $reportService->getStaffCheckInDetail($_GET['log_id']);
                echo json_encode(['success' => true, 'detail' => $detail]);
                break;

            default:
                throw new Exception("Invalid action");
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Process filters
$filters = [
    'comp_id' => '',
    'mode' => isset($_POST['mode']) ? $_POST['mode'] : 'security',
    'site_id' => isset($_POST['site_id']) ? $_POST['site_id'] : '',
    'staff' => isset($_POST['staff']) ? $_POST['staff'] : '',
    'status' => isset($_POST['status']) ? $_POST['status'] : '',
    'date' => isset($_POST['date']) ? $_POST['date'] : date('Y-m-d'),
    'date_to' => isset($_POST['date_to']) ? $_POST['date_to'] : '',
    'range' => isset($_POST['range']) ? $_POST['range'] : 'today',
    // Staff-specific filters
    'staff_site_id' => isset($_POST['staff_site_id']) ? $_POST['staff_site_id'] : '',
    'staff_person' => isset($_POST['staff_person']) ? $_POST['staff_person'] : '',
    'check_in_type' => isset($_POST['check_in_type']) ? $_POST['check_in_type'] : '',
];


// Set company filter based on user type
if ($isSuperAdmin || ($isFaceAdmin && $faceAllCompanies)) {
    $filters['comp_id'] = isset($_POST['comp_id']) ? $_POST['comp_id'] : $currentUser['comp_id'];
} elseif ($isFaceAdmin && !$faceAllCompanies) {
    if (!empty($_POST['comp_id']) && in_array((int)$_POST['comp_id'], $faceAssignedCompanyIds)) {
        $filters['comp_id'] = $_POST['comp_id'];
    } else {
        $filters['comp_id'] = !empty($faceAssignedCompanyIds) ? $faceAssignedCompanyIds[0] : $currentUser['comp_id'];
    }
} else {
    $filters['comp_id'] = $currentUser['comp_id'];
}

// Get filter data
if ($isSuperAdmin || ($isFaceAdmin && $faceAllCompanies)) {
    $companies = $reportService->getCompanies();
} elseif ($isFaceAdmin && !$faceAllCompanies) {
    $companies = $reportService->getCompanies($faceAssignedCompanyIds);
} else {
    $companies = [];
}
$sites = [];
$staffList = [];
$staffSites = [];
$staffProfiles = [];
$activeMode = $filters['mode'];

if (!empty($filters['comp_id'])) {
    $sites = $reportService->getSites($filters['comp_id']);
    $staffSites = $reportService->getStaffSites($filters['comp_id']);

    // Get staff list for company admins, super admins, and face admins
    if ($isCompanyAdmin || $isSuperAdmin || $isFaceAdmin) {
        $staffList = $reportService->getStaffList($filters['comp_id']);
        $staffProfiles = $reportService->getStaffProfilesList($filters['comp_id']);
    }
}

// Generate report based on mode
$reportData = [];
$staffReportData = [];
$showReport = !empty($filters['comp_id']);

if ($showReport) {
    try {
        if ($activeMode === 'staff') {
            $staffReportData = $reportService->generateStaffReport($filters);

            // Excel export (Staff Attendance, no photos) — same filtered dataset as the on-screen report
            if (isset($_POST['export']) && $_POST['export'] === 'staff_excel') {
                $reportService->exportStaffReportCsv($staffReportData);
                exit;
            }
        } else {
            $reportData = $reportService->generateReport($filters);
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Dashboard metrics - Security
$dashboardMetrics = [
    'total_sites' => count($reportData),
    'total_rounds' => 0,
    'completed_rounds' => 0,
    'in_progress_rounds' => 0
];

foreach ($reportData as $siteGroup) {
    $dashboardMetrics['total_rounds'] += count($siteGroup['rounds']);
    foreach ($siteGroup['rounds'] as $round) {
        if ($round['check_in_status'] === 'completed') {
            $dashboardMetrics['completed_rounds']++;
        } elseif (in_array($round['check_in_status'], ['in_progress', 'started'])) {
            $dashboardMetrics['in_progress_rounds']++;
        }
    }
}

// Dashboard metrics - Staff
$staffMetrics = [
    'total_checkins' => 0,
    'total_sites' => count($staffReportData),
    'normal_checkins' => 0,
    'face_checkins' => 0,
];

foreach ($staffReportData as $siteGroup) {
    $staffMetrics['total_checkins'] += count($siteGroup['logs']);
    foreach ($siteGroup['logs'] as $log) {
        if ($log['check_in_type'] === 'normal') {
            $staffMetrics['normal_checkins']++;
        } else {
            $staffMetrics['face_checkins']++;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Site Check-In Report</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
            background: #fafafa;
            color: #1a1a1a;
            padding: 12px;
            font-size: 13px;
            line-height: 1.5;
        }

        .main-content {
            margin-left: 260px;
            transition: margin-left 0.3s ease;
        }

        .main-content.sidebar-collapsed {
            margin-left: 60px;
        }

        @media (max-width: 1024px) {
            .main-content {
                margin-left: 60px;
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 8px;
            }

            .main-content {
                margin-left: 0 !important;
            }
        }

        .container {
            max-width: none;
            margin: 0;
            background: white;
            border: 1px solid #e0e0e0;
            overflow: hidden;
        }

        .header {
            background: #fff;
            border-bottom: 1px solid #e0e0e0;
            padding: 14px 18px;
        }

        .header h1 {
            font-size: 18px;
            font-weight: 600;
            color: #1a1a1a;
            letter-spacing: -0.2px;
            margin-bottom: 6px;
        }

        .user-info {
            font-size: 11px;
            color: #666;
        }

        .dashboard-section {
            background: white;
            border-bottom: 1px solid #e0e0e0;
            padding: 14px 18px;
        }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .dashboard-title {
            font-size: 13px;
            font-weight: 600;
            color: #1a1a1a;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .toggle-filters-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            background: #fff;
            border: 1px solid #d0d0d0;
            color: #1a1a1a;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.2s ease;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .toggle-filters-btn:hover {
            background: #fafafa;
            border-color: #1a1a1a;
        }

        .toggle-icon {
            font-size: 10px;
            transition: transform 0.2s ease;
        }

        .toggle-filters-btn.active .toggle-icon {
            transform: rotate(180deg);
        }

        .dashboard-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 10px;
        }

        .metric-card {
            background: #fafafa;
            border: 1px solid #e0e0e0;
            padding: 12px;
            text-align: center;
            transition: background 0.2s ease;
        }

        .metric-card:hover {
            background: #f5f5f5;
        }

        .metric-value {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 4px;
            line-height: 1;
        }

        .metric-label {
            font-size: 11px;
            font-weight: 500;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .filters-section {
            padding: 14px 18px;
            border-bottom: 1px solid #e0e0e0;
            background: #fafafa;
            transition: all 0.3s ease;
            overflow: hidden;
            opacity: 1;
            max-height: 1000px;
        }

        .filters-section.hidden {
            max-height: 0;
            opacity: 0;
            padding-top: 0;
            padding-bottom: 0;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
            margin-bottom: 10px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            font-weight: 500;
            margin-bottom: 4px;
            color: #666;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .filter-input,
        .filter-select {
            padding: 6px 10px;
            border: 1px solid #d0d0d0;
            font-size: 13px;
            transition: border 0.2s ease;
            background: white;
            color: #1a1a1a;
        }

        .filter-input:focus,
        .filter-select:focus {
            outline: none;
            border-color: #1a1a1a;
        }

        .filter-btn {
            background: #1a1a1a;
            color: white;
            padding: 6px 14px;
            border: none;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            transition: background 0.2s ease;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .filter-btn:hover {
            background: #333;
        }

        .report-section {
            padding: 0;
        }

        .site-section {
            margin-bottom: 0;
            border-bottom: 1px solid #e0e0e0;
        }

        .site-section:last-child {
            border-bottom: none;
        }

        .site-header {
            background: #f5f5f5;
            border-bottom: 1px solid #e0e0e0;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .site-badge {
            background: #1a1a1a;
            color: white;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 11px;
            flex-shrink: 0;
        }

        .site-info {
            flex: 1;
        }

        .site-name {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
            color: #1a1a1a;
        }

        .site-district {
            font-size: 11px;
            color: #999;
            font-weight: 400;
            margin-top: 2px;
        }

        .table-container {
            overflow-x: auto;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            min-width: 800px;
        }

        .report-table th {
            background: #f5f5f5;
            color: #1a1a1a;
            font-weight: 600;
            padding: 8px 10px;
            text-align: left;
            border-bottom: 2px solid #e0e0e0;
            border-right: 1px solid #e0e0e0;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .report-table th:last-child {
            border-right: none;
        }

        .report-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #e0e0e0;
            border-right: 1px solid #e0e0e0;
            color: #1a1a1a;
            vertical-align: middle;
        }

        .report-table td:last-child {
            border-right: none;
        }

        .report-table tbody tr {
            transition: background-color 0.1s ease;
        }

        .report-table tbody tr:hover {
            background: #fafafa;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border: 1px solid;
        }

        .status-completed {
            background: #e8f5e9;
            color: #2e7d32;
            border-color: #a5d6a7;
        }

        .status-in_progress,
        .status-started {
            background: #e3f2fd;
            color: #1565c0;
            border-color: #90caf9;
        }

        .status-incomplete {
            background: #fff3e0;
            color: #e65100;
            border-color: #ffb74d;
        }

        .status-cancelled {
            background: #ffebee;
            color: #c62828;
            border-color: #ef9a9a;
        }

        .checkpoint-cell {
            text-align: center;
            vertical-align: middle;
            padding: 6px 4px !important;
        }

        .checkpoint-cell>div {
            transition: all 0.2s ease;
            padding: 4px;
        }

        .checkpoint-cell>div:hover {
            background: #fafafa;
        }

        .checkpoint-icon {
            font-size: 16px;
            display: block;
            margin-bottom: 2px;
        }

        .checkpoint-completed {
            color: #2e7d32;
        }

        .checkpoint-pending {
            color: #d0d0d0;
            font-size: 14px;
        }

        .checkpoint-time {
            display: block;
            font-size: 10px;
            color: #666;
            font-weight: 600;
        }

        .no-data {
            text-align: center;
            padding: 30px;
            color: #999;
            font-size: 13px;
            background: #fafafa;
            margin: 18px;
            border: 1px dashed #d0d0d0;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .modal-content {
            background: white;
            padding: 0;
            width: 100%;
            max-width: 700px;
            max-height: 85vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }

        .modal-header {
            padding: 14px 18px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f5f5f5;
        }

        .modal-title {
            font-size: 15px;
            font-weight: 600;
            color: #1a1a1a;
        }

        .modal-close {
            cursor: pointer;
            font-size: 20px;
            color: #999;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .modal-close:hover {
            color: #1a1a1a;
            background: #e0e0e0;
        }

        .modal-body {
            padding: 18px;
            overflow-y: auto;
            flex: 1;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f5f5f5;
            font-size: 12px;
        }

        .detail-label {
            font-weight: 500;
            color: #666;
        }

        .detail-value {
            font-weight: 600;
            color: #1a1a1a;
        }

        .checkpoint-details {
            margin-top: 18px;
        }

        .checkpoint-card {
            background: #fafafa;
            border: 1px solid #e0e0e0;
            padding: 12px;
            margin-bottom: 10px;
        }

        .checkpoint-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }

        .checkpoint-number {
            width: 26px;
            height: 26px;
            background: #1a1a1a;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 11px;
        }

        .checkpoint-name {
            font-weight: 600;
            color: #1a1a1a;
            flex: 1;
            font-size: 13px;
        }

        .checkpoint-info {
            font-size: 12px;
            color: #666;
            margin-bottom: 4px;
        }

        .photo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 8px;
            margin-top: 10px;
        }

        .photo-item {
            position: relative;
            aspect-ratio: 1;
            overflow: hidden;
            cursor: pointer;
            background: #f5f5f5;
            border: 1px solid #e0e0e0;
        }

        .photo-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .photo-item:hover::after {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.2);
        }

        .error-message {
            margin: 18px;
            padding: 12px 16px;
            background: #fff3e0;
            border: 1px solid #ffb74d;
            border-left: 3px solid #f57c00;
            color: #e65100;
            font-size: 12px;
        }

        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f5f5f5;
        }

        ::-webkit-scrollbar-thumb {
            background: #d0d0d0;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #bbb;
        }

        @media (max-width: 768px) {
            .filters-grid {
                grid-template-columns: 1fr;
            }

            .dashboard-metrics {
                grid-template-columns: 1fr;
            }

            .modal-content {
                width: 95%;
                margin: 20px auto;
            }
        }

        @media print {
            body {
                margin: 0;
                padding: 0;
            }

            .dashboard-section,
            .filters-section {
                display: none;
            }

            .checkpoint-cell>div:hover {
                background: transparent;
            }
        }

        /* Select2 customization */
        .select2-container--default .select2-selection--single {
            border: 1px solid #d0d0d0;
            height: auto;
            padding: 6px 10px;
            font-size: 13px;
            background: white;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: normal;
            padding: 0;
            color: #1a1a1a;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 100%;
        }

        .select2-container--default.select2-container--focus .select2-selection--single {
            border-color: #1a1a1a;
            outline: none;
        }

        .select2-dropdown {
            border: 1px solid #d0d0d0;
            font-size: 13px;
        }

        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: #1a1a1a;
        }

        .select2-container--default .select2-search--dropdown .select2-search__field {
            border: 1px solid #d0d0d0;
            padding: 6px 10px;
            font-size: 13px;
        }

        .select2-container--default .select2-search--dropdown .select2-search__field:focus {
            border-color: #1a1a1a;
            outline: none;
        }

        .select2-container {
            width: 100% !important;
        }

        .status-badge.status-cancelled:hover {
            background: #fecaca;
            border-color: #f87171;
            transform: scale(1.02);
            transition: all 0.2s ease;
        }

        .checkpoint-action-btn {
            background: #f5f5f5;
            border: 1px solid #d0d0d0;
            color: #666;
            padding: 4px 8px;
            font-size: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-weight: 500;
        }

        .checkpoint-action-btn:hover {
            background: #1a1a1a;
            color: white;
            border-color: #1a1a1a;
        }

        .checkpoint-action-edit:hover {
            background: #2196F3;
            border-color: #2196F3;
        }

        .checkpoint-action-add {
            background: #4CAF50;
            color: white;
            border-color: #4CAF50;
        }

        .checkpoint-action-add:hover {
            background: #45a049;
            border-color: #45a049;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.25rem;
            font-size: 12px;
            color: #374151;
        }

        .form-input,
        .form-textarea {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #d0d0d0;
            font-size: 13px;
            background: white;
        }

        .form-input:focus,
        .form-textarea:focus {
            outline: none;
            border-color: #1a1a1a;
        }

        .form-textarea {
            min-height: 80px;
            resize: vertical;
        }

        .photo-upload-container {
            border: 2px dashed #d0d0d0;
            padding: 1rem;
            text-align: center;
            background: #fafafa;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .photo-upload-container:hover {
            border-color: #1a1a1a;
            background: #f5f5f5;
        }

        .photo-upload-container.has-photos {
            border-style: solid;
            border-color: #4CAF50;
            background: #f1f8f4;
        }

        .photo-preview {
            display: flex;
            gap: 8px;
            margin-top: 8px;
            flex-wrap: wrap;
        }

        .photo-preview-item {
            position: relative;
            width: 80px;
            height: 80px;
        }

        .photo-preview-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border: 1px solid #e0e0e0;
        }

        .photo-preview-item .remove-photo {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #f44336;
            color: white;
            border: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .photo-preview-item.marked-for-deletion {
            opacity: 0.5;
            position: relative;
            filter: grayscale(50%);
        }

        .photo-preview-item.marked-for-deletion::after {
            content: 'WILL BE REMOVED';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(244, 67, 54, 0.95);
            color: white;
            padding: 6px 10px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
            border-radius: 4px;
            pointer-events: none;
            letter-spacing: 0.5px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .photo-preview-item.marked-for-deletion .remove-photo {
            background: #4CAF50;
            border: 2px solid white;
        }

        .photo-preview-item.marked-for-deletion .remove-photo:hover {
            background: #45a049;
            transform: scale(1.1);
        }

        .btn-primary {
            background: #1a1a1a;
            color: white;
            padding: 8px 16px;
            border: none;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .btn-primary:hover {
            background: #333;
        }

        .btn-primary:disabled {
            background: #bdbdbd;
            cursor: not-allowed;
        }

        .btn-secondary {
            background: #f5f5f5;
            color: #666;
            padding: 8px 16px;
            border: 1px solid #d0d0d0;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .btn-secondary:hover {
            background: #e0e0e0;
        }

        /* Mode Toggle */
        .mode-toggle-bar {
            display: flex;
            border-bottom: 1px solid #e0e0e0;
            background: #fafafa;
        }

        .mode-toggle-btn {
            flex: 1;
            padding: 10px 16px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            cursor: pointer;
            border: none;
            background: transparent;
            color: #999;
            border-bottom: 2px solid transparent;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            transition: all 0.15s ease;
        }

        .mode-toggle-btn:hover {
            color: #666;
            background: #f5f5f5;
        }

        .mode-toggle-btn.active {
            color: #1a1a1a;
            border-bottom-color: #1a1a1a;
            background: #fff;
        }

        .staff-type-badge {
            display: inline-block;
            padding: 3px 8px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border: 1px solid;
        }

        .staff-type-face {
            background: #f3e5f5;
            color: #7b1fa2;
            border-color: #ce93d8;
        }

        .staff-type-normal {
            background: #e3f2fd;
            color: #1565c0;
            border-color: #90caf9;
        }

        .staff-type-lunch {
            background: #fff3e0;
            color: #e65100;
            border-color: #ffcc80;
        }

        .lunch-row td {
            background: #fffdf6;
        }

        .lunch-row td:first-child {
            box-shadow: inset 3px 0 0 #fb8c00;
        }

        .lunch-row.lunch-pair-hover td {
            background: #fff3e0;
        }

        .lunch-duration-badge,
        .lunch-missing-badge {
            display: inline-block;
            margin-left: 4px;
            padding: 3px 8px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.3px;
            border: 1px solid;
        }

        .lunch-duration-badge {
            background: #ede7f6;
            color: #4527a0;
            border-color: #b39ddb;
        }

        .lunch-missing-badge {
            background: #ffebee;
            color: #c62828;
            border-color: #ef9a9a;
        }

        .staff-photo-thumb {
            width: 40px;
            height: 40px;
            object-fit: cover;
            cursor: pointer;
            border: 1px solid #e0e0e0;
            transition: border-color 0.2s ease;
        }

        .staff-photo-thumb:hover {
            border-color: #1a1a1a;
        }

        .staff-photo-placeholder {
            width: 40px;
            height: 40px;
            background: #f5f5f5;
            border: 1px solid #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #bdbdbd;
            font-size: 16px;
        }

        .mode-section {
            display: none;
        }

        .mode-section.active {
            display: block;
        }
    </style>
</head>

<body>
    <?php include '../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="container">
            <div class="header">
                <h1>🏢 Site Check-In Report</h1>
                <div class="user-info">
                    <?php if ($isSuperAdmin): ?>
                        <?= ucwords(strtolower($currentUser['fullname'])) ?> (<?= $currentUser['id'] ?>) - <?= ucwords(strtolower($currentUser['company_name'])) ?> (<strong>Super Admin</strong>)
                    <?php elseif ($isCompanyAdmin): ?>
                        <?= ucwords(strtolower($currentUser['fullname'])) ?> (<?= $currentUser['id'] ?>) - <?= ucwords(strtolower($currentUser['company_name'])) ?> (<strong>Company Admin</strong>)
                    <?php else: ?>
                        <?= ucwords(strtolower($currentUser['fullname'])) ?> (<?= $currentUser['id'] ?>) - <?= ucwords(strtolower($currentUser['company_name'])) ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Mode Toggle -->
            <div class="mode-toggle-bar">
                <button type="button" class="mode-toggle-btn <?= $activeMode === 'security' ? 'active' : '' ?>" onclick="switchReportMode('security')">
                    <i class="fas fa-shield-alt"></i> Security Patrol
                </button>
                <button type="button" class="mode-toggle-btn <?= $activeMode === 'staff' ? 'active' : '' ?>" onclick="switchReportMode('staff')">
                    <i class="fas fa-user-check"></i> Staff Attendance
                </button>
            </div>

            <!-- ==================== SECURITY MODE ==================== -->
            <div class="mode-section <?= $activeMode === 'security' ? 'active' : '' ?>" id="securitySection">

                <div class="dashboard-section">
                    <div class="dashboard-header">
                        <h2 class="dashboard-title">📊 Dashboard Overview</h2>
                        <button type="button" id="toggleFilters" class="toggle-filters-btn">
                            <span class="toggle-icon">▼</span>
                            <span class="toggle-text">Show Filters</span>
                        </button>
                    </div>

                    <div class="dashboard-metrics">
                        <div class="metric-card">
                            <div class="metric-value"><?= number_format($dashboardMetrics['total_rounds']) ?></div>
                            <div class="metric-label">Total Rounds</div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-value"><?= number_format($dashboardMetrics['completed_rounds']) ?></div>
                            <div class="metric-label">Completed</div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-value"><?= number_format($dashboardMetrics['in_progress_rounds']) ?></div>
                            <div class="metric-label">In Progress</div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-value"><?= number_format($dashboardMetrics['total_sites']) ?></div>
                            <div class="metric-label">Sites</div>
                        </div>
                    </div>
                </div>

                <form method="POST" class="filters-section hidden" id="filtersSection">
                    <input type="hidden" name="mode" value="security">

                    <div class="filters-grid">
                        <?php if ($isSuperAdmin || $isFaceAdmin): ?>
                            <div class="filter-group">
                                <label class="filter-label">Company *</label>
                                <select name="comp_id" id="companySelect" class="filter-select" required onchange="loadSites(this.value)">
                                    <option value="">Select Company</option>
                                    <?php foreach ($companies as $company): ?>
                                        <option value="<?= $company['id'] ?>"
                                            <?= ($filters['comp_id'] == $company['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($company['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="comp_id" value="<?= htmlspecialchars($currentUser['comp_id']) ?>">
                        <?php endif; ?>

                        <div class="filter-group">
                            <label class="filter-label">Site</label>
                            <select name="site_id" id="siteSelect" class="filter-select">
                                <option value="">All Sites</option>
                                <?php foreach ($sites as $site): ?>
                                    <option value="<?= $site['id'] ?>"
                                        <?= ($filters['site_id'] == $site['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($site['site_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($isCompanyAdmin || $isSuperAdmin || $isFaceAdmin): ?>
                            <div class="filter-group">
                                <label class="filter-label">Staff</label>
                                <select name="staff" id="staffSelect" class="filter-select">
                                    <option value="">All Staff</option>
                                    <?php foreach ($staffList as $staff): ?>
                                        <option value="<?= htmlspecialchars($staff['person']) ?>"
                                            <?= ($filters['staff'] == $staff['person']) ? 'selected' : '' ?>>
                                            <?= ucwords(strtolower(htmlspecialchars($staff['fullname']))) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="filter-group">
                            <label class="filter-label">Status</label>
                            <select name="status" class="filter-select">
                                <option value="">All Statuses</option>
                                <option value="in_progress" <?= ($filters['status'] == 'in_progress') ? 'selected' : '' ?>>In Progress</option>
                                <option value="completed" <?= ($filters['status'] == 'completed') ? 'selected' : '' ?>>Completed</option>
                                <option value="cancelled" <?= ($filters['status'] == 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label class="filter-label">Date Range</label>
                            <select name="range" class="filter-select" onchange="toggleDateInput(this.value)">
                                <option value="today" <?= ($filters['range'] == 'today') ? 'selected' : '' ?>>Today</option>
                                <option value="week" <?= ($filters['range'] == 'week') ? 'selected' : '' ?>>This Week</option>
                                <option value="month" <?= ($filters['range'] == 'month') ? 'selected' : '' ?>>This Month</option>
                                <option value="year" <?= ($filters['range'] == 'year') ? 'selected' : '' ?>>This Year</option>
                                <option value="custom" <?= ($filters['range'] == 'custom') ? 'selected' : '' ?>>Custom Date</option>
                            </select>
                        </div>

                        <div class="filter-group" id="dateInputGroup" style="<?= ($filters['range'] == 'custom') ? '' : 'display: none;' ?>">
                            <label class="filter-label">Date From</label>
                            <input type="date" name="date" class="filter-input" value="<?= htmlspecialchars($filters['date']) ?>">
                        </div>

                        <div class="filter-group" id="dateToInputGroup" style="<?= ($filters['range'] == 'custom') ? '' : 'display: none;' ?>">
                            <label class="filter-label">Date To</label>
                            <input type="date" name="date_to" class="filter-input" value="<?= htmlspecialchars($filters['date_to']) ?>">
                        </div>

                        <div class="filter-group" style="display: flex; align-items: end;">
                            <button type="submit" class="filter-btn">Generate Report</button>
                        </div>
                    </div>
                </form>

                <div class="report-section">
                    <?php if (isset($error_message)): ?>
                        <div class="error-message" style="margin: 2rem; padding: 1rem; background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 8px;">
                            Error: <?= htmlspecialchars($error_message) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($showReport): ?>
                        <?php if (empty($reportData)): ?>
                            <div class="no-data">
                                No check-in rounds found for the selected filters.
                            </div>
                        <?php else: ?>
                            <?php $siteIndex = 1; ?>
                            <?php foreach ($reportData as $siteGroup): ?>
                                <div class="site-section">
                                    <div class="site-header">
                                        <div class="site-badge"><?= $siteIndex ?></div>
                                        <div class="site-info">
                                            <h3 class="site-name"><?= htmlspecialchars($siteGroup['site_name']) ?></h3>
                                        </div>
                                    </div>

                                    <div class="table-container">
                                        <table class="report-table">
                                            <thead>
                                                <tr>
                                                    <th>No.</th>
                                                    <th>Round ID</th>
                                                    <?php if ($isCompanyAdmin || $isSuperAdmin || $isFaceAdmin): ?>
                                                        <th>By</th>
                                                    <?php endif; ?>
                                                    <th>Start Date/Time</th>
                                                    <th>End Date/Time</th>
                                                    <th>Duration</th>
                                                    <th>Status</th>
                                                    <?php foreach ($siteGroup['checkpoints'] as $checkpoint): ?>
                                                        <th style="text-align: center; min-width: 100px;">
                                                            <?= htmlspecialchars($checkpoint['point_name']) ?>
                                                        </th>
                                                    <?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $roundIndex = 1; ?>
                                                <?php foreach ($siteGroup['rounds'] as $round): ?>
                                                    <tr>
                                                        <td><?= $roundIndex ?>.</td>
                                                        <td><strong><?= htmlspecialchars($round['round_code']) ?></strong></td>
                                                        <?php if ($isCompanyAdmin || $isSuperAdmin || $isFaceAdmin): ?>
                                                            <td><?= ucwords(strtolower(htmlspecialchars(isset($round['user_fullname']) ? $round['user_fullname'] : 'Unknown'))) ?></td>
                                                        <?php endif; ?>
                                                        <td><?= date('Y-m-d H:i:s', strtotime($round['started_at'])) ?></td>
                                                        <td>
                                                            <?php if ($round['completed_at']): ?>
                                                                <?= date('Y-m-d H:i:s', strtotime($round['completed_at'])) ?>
                                                            <?php else: ?>
                                                                <span style="color: #9ca3af;">-</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $minutes = isset($round['duration_minutes']) ? $round['duration_minutes'] : 0;
                                                            $hours = floor($minutes / 60);
                                                            $mins = $minutes % 60;
                                                            if ($hours > 0) {
                                                                echo "{$hours}h {$mins}m";
                                                            } else {
                                                                echo "{$mins}m";
                                                            }
                                                            ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($round['check_in_status'] === 'cancelled'): ?>
                                                                <div style="display: flex; flex-direction: column; gap: 4px;">
                                                                    <span class="status-badge status-cancelled"
                                                                        style="cursor: pointer;"
                                                                        onclick="showCancelReason('<?= htmlspecialchars($round['round_code']) ?>', '<?= htmlspecialchars($round['cancel_reason'] ?? 'No reason provided') ?>', '<?= htmlspecialchars($round['updated_at']) ?>')"
                                                                        title="Click to view cancel reason">
                                                                        CANCELLED
                                                                    </span>
                                                                    <span style="font-size: 10px;">
                                                                        <?= date('Y-m-d H:i', strtotime($round['updated_at'])) ?>
                                                                    </span>
                                                                </div>
                                                            <?php else: ?>
                                                                <span class="status-badge status-<?= $round['check_in_status'] ?>">
                                                                    <?= str_replace('_', ' ', $round['check_in_status']) ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <?php foreach ($siteGroup['checkpoints'] as $checkpoint): ?>
                                                            <td class="checkpoint-cell" style="text-align: center;">
                                                                <?php
                                                                $checkpointLog = isset($round['checkpoint_logs'][$checkpoint['id']]) ? $round['checkpoint_logs'][$checkpoint['id']] : null;
                                                                $canModify = in_array($round['check_in_status'], ['in_progress', 'cancelled', 'completed']);

                                                                if ($checkpointLog):
                                                                    $isModified = !empty($checkpointLog['modified_by']);
                                                                ?>
                                                                    <div style="cursor: pointer; padding: 0.5rem; position: relative;"
                                                                        title="Click to view details">
                                                                        <i class="fas fa-check-circle checkpoint-icon checkpoint-completed"></i>
                                                                        <span class="checkpoint-time">
                                                                            <?= date('H:i', strtotime($checkpointLog['checked_in_at'])) ?>
                                                                        </span>
                                                                        <?php if ($isModified): ?>
                                                                            <span style="display: block; font-size: 9px; color: #ff9800; font-weight: 600; margin-top: 2px;">
                                                                                <i class="fas fa-pencil-alt"></i> <?= strtoupper($checkpointLog['modified_by_action']) ?>
                                                                            </span>
                                                                        <?php endif; ?>
                                                                        <div style="display: flex; gap: 4px; margin-top: 4px; justify-content: center;">
                                                                            <button onclick="viewCheckpointDetails(<?= $checkpointLog['log_id'] ?>)"
                                                                                class="checkpoint-action-btn"
                                                                                title="View details">
                                                                                <i class="fas fa-eye"></i>
                                                                            </button>
                                                                            <?php if ($canModify): ?>
                                                                                <button onclick="editCheckpoint(<?= $round['id'] ?>, <?= $checkpoint['id'] ?>, <?= $checkpointLog['log_id'] ?>)"
                                                                                    class="checkpoint-action-btn checkpoint-action-edit"
                                                                                    title="Edit checkpoint">
                                                                                    <i class="fas fa-edit"></i>
                                                                                </button>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                <?php else: ?>
                                                                    <div style="padding: 0.5rem;">
                                                                        <i class="fas fa-circle checkpoint-icon checkpoint-pending"
                                                                            title="Not completed"></i>
                                                                        <?php if ($canModify): ?>
                                                                            <button onclick="addCheckpoint(<?= $round['id'] ?>, <?= $checkpoint['id'] ?>, '<?= htmlspecialchars($checkpoint['point_name']) ?>')"
                                                                                class="checkpoint-action-btn checkpoint-action-add"
                                                                                style="margin-top: 4px;"
                                                                                title="Add checkpoint">
                                                                                <i class="fas fa-plus"></i> Add
                                                                            </button>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                    <?php $roundIndex++; ?>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php $siteIndex++; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="no-data">
                            Please select filters to generate the report.
                        </div>
                    <?php endif; ?>
                </div>

            </div><!-- end securitySection -->

            <!-- ==================== STAFF ATTENDANCE MODE ==================== -->
            <div class="mode-section <?= $activeMode === 'staff' ? 'active' : '' ?>" id="staffSection">

                <div class="dashboard-section">
                    <div class="dashboard-header">
                        <h2 class="dashboard-title">📊 Staff Attendance Overview</h2>
                        <button type="button" id="toggleStaffFilters" class="toggle-filters-btn">
                            <span class="toggle-icon">▼</span>
                            <span class="toggle-text">Show Filters</span>
                        </button>
                    </div>

                    <div class="dashboard-metrics">
                        <div class="metric-card">
                            <div class="metric-value"><?= number_format($staffMetrics['total_checkins']) ?></div>
                            <div class="metric-label">Total Check-Ins</div>
                        </div>
                        <!-- <div class="metric-card">
                        <div class="metric-value"><?= number_format($staffMetrics['normal_checkins']) ?></div>
                        <div class="metric-label">Normal</div>
                    </div> -->
                        <!-- <div class="metric-card">
                        <div class="metric-value"><?= number_format($staffMetrics['face_checkins']) ?></div>
                        <div class="metric-label">Face Recognition</div>
                    </div> -->
                        <div class="metric-card">
                            <div class="metric-value"><?= number_format($staffMetrics['total_sites']) ?></div>
                            <div class="metric-label">Sites</div>
                        </div>
                    </div>
                </div>

                <form method="POST" class="filters-section hidden" id="staffFiltersSection">
                    <input type="hidden" name="mode" value="staff">

                    <div class="filters-grid">
                        <?php if ($isSuperAdmin || $isFaceAdmin): ?>
                            <div class="filter-group">
                                <label class="filter-label">Company *</label>
                                <select name="comp_id" id="staffCompanySelect" class="filter-select" required onchange="loadStaffFilterData(this.value)">
                                    <option value="">Select Company</option>
                                    <?php foreach ($companies as $company): ?>
                                        <option value="<?= $company['id'] ?>"
                                            <?= ($filters['comp_id'] == $company['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($company['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="comp_id" value="<?= htmlspecialchars($currentUser['comp_id']) ?>">
                        <?php endif; ?>

                        <div class="filter-group">
                            <label class="filter-label">Site</label>
                            <select name="staff_site_id" id="staffSiteSelect" class="filter-select">
                                <option value="">All Sites</option>
                                <?php foreach ($staffSites as $site): ?>
                                    <option value="<?= $site['id'] ?>"
                                        <?= ($filters['staff_site_id'] == $site['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($site['site_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($isCompanyAdmin || $isSuperAdmin || $isFaceAdmin): ?>
                            <div class="filter-group">
                                <label class="filter-label">Staff</label>
                                <select name="staff_person" id="staffPersonSelect" class="filter-select">
                                    <option value="">All Staff</option>
                                    <?php foreach ($staffProfiles as $profile): ?>
                                        <option value="<?= htmlspecialchars($profile['person']) ?>"
                                            <?= ($filters['staff_person'] == $profile['person']) ? 'selected' : '' ?>>
                                            <?= ucwords(strtolower(htmlspecialchars($profile['fullname'] ?? 'Unknown'))) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="filter-group">
                            <label class="filter-label">Check-In Type</label>
                            <select name="check_in_type" class="filter-select">
                                <option value="">All Types</option>
                                <option value="normal" <?= ($filters['check_in_type'] == 'normal') ? 'selected' : '' ?>>Photo</option>
                                <option value="face" <?= ($filters['check_in_type'] == 'face') ? 'selected' : '' ?>>Face Recognition</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label class="filter-label">Date Range</label>
                            <select name="range" class="filter-select" onchange="toggleStaffDateInput(this.value)">
                                <option value="today" <?= ($activeMode === 'staff' && $filters['range'] == 'today') ? 'selected' : '' ?>>Today</option>
                                <option value="week" <?= ($activeMode === 'staff' && $filters['range'] == 'week') ? 'selected' : '' ?>>This Week</option>
                                <option value="month" <?= ($activeMode === 'staff' && $filters['range'] == 'month') ? 'selected' : '' ?>>This Month</option>
                                <option value="year" <?= ($activeMode === 'staff' && $filters['range'] == 'year') ? 'selected' : '' ?>>This Year</option>
                                <option value="custom" <?= ($activeMode === 'staff' && $filters['range'] == 'custom') ? 'selected' : '' ?>>Custom Date</option>
                            </select>
                        </div>

                        <div class="filter-group" id="staffDateInputGroup" style="<?= ($activeMode === 'staff' && $filters['range'] == 'custom') ? '' : 'display: none;' ?>">
                            <label class="filter-label">Date From</label>
                            <input type="date" name="date" class="filter-input" value="<?= htmlspecialchars($filters['date']) ?>">
                        </div>

                        <div class="filter-group" id="staffDateToInputGroup" style="<?= ($activeMode === 'staff' && $filters['range'] == 'custom') ? '' : 'display: none;' ?>">
                            <label class="filter-label">Date To</label>
                            <input type="date" name="date_to" class="filter-input" value="<?= htmlspecialchars($filters['date_to']) ?>">
                        </div>

                        <div class="filter-group" style="display: flex; align-items: end; gap: 8px;">
                            <button type="submit" class="filter-btn">Generate Report</button>
                            <button type="submit" name="export" value="staff_excel" class="filter-btn" style="background:#1d6f42; white-space:nowrap;">
                                <i class="fas fa-file-excel"></i> Download Excel
                            </button>
                        </div>
                    </div>
                </form>

                <div class="report-section">
                    <?php if (isset($error_message) && $activeMode === 'staff'): ?>
                        <div class="error-message" style="margin: 2rem; padding: 1rem; background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 8px;">
                            Error: <?= htmlspecialchars($error_message) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($showReport && $activeMode === 'staff'): ?>
                        <?php if (empty($staffReportData)): ?>
                            <div class="no-data">
                                No staff check-ins found for the selected filters.
                            </div>
                        <?php else: ?>
                            <?php $siteIndex = 1; ?>
                            <?php foreach ($staffReportData as $siteGroup): ?>
                                <div class="site-section">
                                    <div class="site-header">
                                        <div class="site-badge"><?= $siteIndex ?></div>
                                        <div class="site-info">
                                            <h3 class="site-name"><?= htmlspecialchars($siteGroup['site_name']) ?></h3>
                                            <?php if (!empty($siteGroup['district'])): ?>
                                                <div class="site-district"><?= htmlspecialchars($siteGroup['district']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <span style="font-size: 11px; color: #999; font-weight: 500;"><?= count($siteGroup['logs']) ?> check-in<?= count($siteGroup['logs']) !== 1 ? 's' : '' ?></span>
                                    </div>

                                    <div class="table-container">
                                        <table class="report-table">
                                            <thead>
                                                <tr>
                                                    <th>No.</th>
                                                    <th>Date / Time</th>
                                                    <?php if ($isCompanyAdmin || $isSuperAdmin || $isFaceAdmin): ?>
                                                        <th>Staff</th>
                                                    <?php endif; ?>
                                                    <th>Point</th>
                                                    <th>Type</th>
                                                    <th style="text-align: center;">Photo</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $logIndex = 1; ?>
                                                <?php foreach ($siteGroup['logs'] as $log): ?>
                                                    <tr <?= !empty($log['lunch_pair_id']) ? 'class="lunch-row" data-lunch-pair="' . htmlspecialchars($log['lunch_pair_id']) . '"' : '' ?>>
                                                        <td><?= $logIndex ?>.</td>
                                                        <td><?= date('Y-m-d H:i:s', strtotime($log['checked_in_at'])) ?></td>
                                                        <?php if ($isCompanyAdmin || $isSuperAdmin || $isFaceAdmin): ?>
                                                            <td><strong><?= ucwords(strtolower(htmlspecialchars(isset($log['user_fullname']) ? $log['user_fullname'] : 'Unknown'))) ?></strong></td>
                                                        <?php endif; ?>
                                                        <td><?= htmlspecialchars($log['point_name'] ?? 'N/A') ?></td>
                                                        <td>
                                                            <span class="staff-type-badge staff-type-<?= $log['check_in_type'] ?>">
                                                                <?= $log['check_in_type'] === 'face' ? 'Face' : 'Photo' ?>
                                                            </span>
                                                            <?php if (!empty($log['is_lunch_break']) && $log['is_lunch_break'] == 1): ?>
                                                                <span class="staff-type-badge staff-type-lunch" style="margin-left: 4px;">
                                                                    🍴 <?= strtoupper(lunchLabel($log)) ?>
                                                                </span>
                                                                <?php if (isset($log['lunch_seq']) && $log['lunch_seq'] === 'in' && lunchDurationText($log) !== ''): ?>
                                                                    <span class="lunch-duration-badge" title="Time between lunch out and lunch in">
                                                                        <i class="fas fa-hourglass-half"></i> <?= lunchDurationText($log) ?>
                                                                    </span>
                                                                <?php elseif (!empty($log['lunch_incomplete'])): ?>
                                                                    <span class="lunch-missing-badge" title="No matching return check-in for this day">
                                                                        <i class="fas fa-exclamation-triangle"></i> NO RETURN
                                                                    </span>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td style="text-align: center;">
                                                            <?php if (!empty($log['photos'])): ?>
                                                                <?php $firstPhoto = $log['photos'][0]; ?>
                                                                <img src="/accounts/mkPortal/site_checkin/API/<?= htmlspecialchars($firstPhoto) ?>"
                                                                    class="staff-photo-thumb"
                                                                    onclick="viewStaffCheckInDetail(<?= $log['id'] ?>)"
                                                                    onerror="this.outerHTML='<div class=\'staff-photo-placeholder\'><i class=\'fas fa-image\'></i></div>'"
                                                                    alt="Check-in photo"
                                                                    title="Click to view details (<?= count($log['photos']) ?> photo<?= count($log['photos']) > 1 ? 's' : '' ?>)">
                                                            <?php else: ?>
                                                                <div class="staff-photo-placeholder">
                                                                    <i class="fas fa-image"></i>
                                                                </div>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                    <?php $logIndex++; ?>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php $siteIndex++; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php elseif ($activeMode === 'staff'): ?>
                        <div class="no-data">
                            Please select filters to generate the report.
                        </div>
                    <?php endif; ?>
                </div>

            </div><!-- end staffSection -->

        </div>
    </div>

    <!-- Edit/Add Checkpoint Modal -->
    <div id="modifyCheckpointModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modifyModalTitle">Add Checkpoint</h3>
                <button class="modal-close" onclick="closeModifyModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="modifyCheckpointForm" enctype="multipart/form-data">
                    <input type="hidden" id="modify_round_id" name="round_id">
                    <input type="hidden" id="modify_point_id" name="point_id">
                    <input type="hidden" id="modify_log_id" name="log_id">
                    <input type="hidden" id="modify_action" name="action">

                    <div class="form-group">
                        <label class="form-label">Checkpoint Name</label>
                        <input type="text" id="modify_point_name" class="form-input" readonly>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label">Date</label>
                            <input type="date" id="modify_date" name="date" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Time</label>
                            <input type="time" id="modify_time" name="time" class="form-input" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label">Latitude</label>
                            <input type="text" id="modify_latitude" name="latitude" class="form-input" step="any">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Longitude</label>
                            <input type="text" id="modify_longitude" name="longitude" class="form-input" step="any">
                        </div>
                    </div>

                    <!-- <div class="form-group">
                        <label class="form-label">Distance from Checkpoint (meters)</label>
                        <input type="number" id="modify_distance" name="distance_meters" class="form-input" step="0.01" min="0">
                    </div> -->

                    <div class="form-group">
                        <label class="form-label">Remarks (Optional)</label>
                        <textarea id="modify_remarks" name="remarks" class="form-textarea"></textarea>
                    </div>

                    <div class="form-group" id="existing_photos_container" style="display: none;">
                        <label class="form-label">Existing Photos</label>
                        <div class="photo-preview" id="existing_photos_grid"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <span id="new_photos_label">Photos</span>
                            <span id="photo_required_label">(Required)</span>
                        </label>
                        <div class="photo-upload-container" id="photoUploadContainer" onclick="document.getElementById('photo_input').click()">
                            <input type="file" id="photo_input" name="photos[]" accept="image/*" multiple style="display: none;" onchange="handlePhotoSelect(event)">
                            <i class="fas fa-camera" style="font-size: 2rem; color: #666; margin-bottom: 0.5rem;"></i>
                            <p style="color: #666; font-size: 13px; margin: 0;">Click to select photos</p>
                            <p style="color: #999; font-size: 11px; margin-top: 0.25rem;">At least one photo</p>
                        </div>
                        <div class="photo-preview" id="photoPreview"></div>
                    </div>

                    <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.5rem;">
                        <button type="button" class="btn-secondary" onclick="closeModifyModal()">Cancel</button>
                        <button type="submit" class="btn-primary" id="submitModifyBtn">
                            <i class="fas fa-save"></i> Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Round Details</h3>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="modalBody">
                <div style="text-align: center; padding: 2rem;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #667eea;"></i>
                    <p style="margin-top: 1rem; color: #6b7280;">Loading details...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Staff Check-In Detail Modal -->
    <div id="staffDetailModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Check-In Details</h3>
                <button class="modal-close" onclick="closeStaffDetailModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="staffDetailBody">
                <div style="text-align: center; padding: 2rem;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #667eea;"></i>
                    <p style="margin-top: 1rem; color: #6b7280;">Loading details...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // ==================== MODE SWITCHING ====================
        function switchReportMode(mode) {
            var form;
            if (mode === 'staff') {
                form = document.getElementById('staffFiltersSection');
            } else {
                form = document.getElementById('filtersSection');
            }
            // Ensure the mode hidden input is set, then submit
            var modeInput = form.querySelector('input[name="mode"]');
            if (modeInput) {
                modeInput.value = mode;
            }
            form.submit();
        }

        // ==================== SECURITY FILTERS TOGGLE ====================
        document.getElementById('toggleFilters').addEventListener('click', function() {
            const filtersSection = document.getElementById('filtersSection');
            const toggleText = this.querySelector('.toggle-text');

            if (filtersSection.classList.contains('hidden')) {
                filtersSection.classList.remove('hidden');
                toggleText.textContent = 'Hide Filters';
                this.classList.add('active');
            } else {
                filtersSection.classList.add('hidden');
                toggleText.textContent = 'Show Filters';
                this.classList.remove('active');
            }
        });

        // ==================== STAFF FILTERS TOGGLE ====================
        document.getElementById('toggleStaffFilters').addEventListener('click', function() {
            const filtersSection = document.getElementById('staffFiltersSection');
            const toggleText = this.querySelector('.toggle-text');

            if (filtersSection.classList.contains('hidden')) {
                filtersSection.classList.remove('hidden');
                toggleText.textContent = 'Hide Filters';
                this.classList.add('active');
            } else {
                filtersSection.classList.add('hidden');
                toggleText.textContent = 'Show Filters';
                this.classList.remove('active');
            }
        });

        function toggleStaffDateInput(range) {
            var show = (range === 'custom') ? 'flex' : 'none';
            document.getElementById('staffDateInputGroup').style.display = show;
            document.getElementById('staffDateToInputGroup').style.display = show;
        }

        // Load staff filter data when super admin changes company
        function loadStaffFilterData(compId) {
            if (!compId) return;

            // Load staff sites
            fetch('?action=get_staff_sites&comp_id=' + compId)
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    if (data.success) {
                        var sel = document.getElementById('staffSiteSelect');
                        sel.innerHTML = '<option value="">All Sites</option>';
                        data.sites.forEach(function(s) {
                            var opt = document.createElement('option');
                            opt.value = s.id;
                            opt.textContent = s.site_name;
                            sel.appendChild(opt);
                        });
                        initializeSelect2();
                    }
                });

            // Load staff profiles
            fetch('?action=get_staff_profiles&comp_id=' + compId)
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    if (data.success) {
                        var sel = document.getElementById('staffPersonSelect');
                        if (sel) {
                            sel.innerHTML = '<option value="">All Staff</option>';
                            data.profiles.forEach(function(p) {
                                var opt = document.createElement('option');
                                opt.value = p.person;
                                var name = (p.fullname || 'Unknown').split(' ').map(function(w) {
                                    return w.charAt(0).toUpperCase() + w.slice(1).toLowerCase();
                                }).join(' ');
                                opt.textContent = name;
                                sel.appendChild(opt);
                            });
                            initializeSelect2();
                        }
                    }
                });
        }

        // ==================== STAFF CHECK-IN DETAIL ====================
        function viewStaffCheckInDetail(logId) {
            var modal = document.getElementById('staffDetailModal');
            var body = document.getElementById('staffDetailBody');

            modal.style.display = 'flex';
            body.innerHTML = '<div style="text-align:center;padding:2rem;"><i class="fas fa-spinner fa-spin" style="font-size:2rem;color:#667eea;"></i><p style="margin-top:1rem;color:#6b7280;">Loading details...</p></div>';

            fetch('?action=get_staff_checkin_detail&log_id=' + logId)
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    if (data.success) {
                        renderStaffCheckInDetail(data.detail);
                    } else {
                        body.innerHTML = '<div style="text-align:center;padding:2rem;color:#ef4444;"><i class="fas fa-exclamation-circle" style="font-size:2rem;"></i><p style="margin-top:1rem;">' + escapeHtml(data.message) + '</p></div>';
                    }
                })
                .catch(function() {
                    body.innerHTML = '<div style="text-align:center;padding:2rem;color:#ef4444;"><i class="fas fa-exclamation-circle" style="font-size:2rem;"></i><p style="margin-top:1rem;">Error loading details</p></div>';
                });
        }

        function renderStaffCheckInDetail(detail) {
            var body = document.getElementById('staffDetailBody');
            var typeBadgeClass = detail.check_in_type === 'face' ? 'staff-type-face' : 'staff-type-normal';
            var typeLabel = detail.check_in_type === 'face' ? 'FACE RECOGNITION' : 'NORMAL';
            var staffName = detail.user_fullname ? detail.user_fullname.split(' ').map(function(w) {
                return w.charAt(0).toUpperCase() + w.slice(1).toLowerCase();
            }).join(' ') : 'Unknown';

            var html = '<div style="margin-bottom:2rem;">' +
                '<h4 style="font-size:1rem;font-weight:600;color:#374151;margin-bottom:1rem;">Check-In Information</h4>' +
                '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:1rem;">' +
                '<div class="detail-row"><span class="detail-label">Date / Time</span><span class="detail-value">' + formatDateTime(detail.checked_in_at) + '</span></div>' +
                '<div class="detail-row"><span class="detail-label">Staff</span><span class="detail-value">' + escapeHtml(staffName) + '</span></div>' +
                '<div class="detail-row"><span class="detail-label">Site</span><span class="detail-value">' + escapeHtml(detail.site_name || 'N/A') + '</span></div>' +
                // '<div class="detail-row"><span class="detail-label">Point</span><span class="detail-value">' + escapeHtml(detail.point_name || 'N/A') + '</span></div>' +
                '<div class="detail-row"><span class="detail-label">Type</span><span class="staff-type-badge ' + typeBadgeClass + '">' + typeLabel + '</span></div>' +
                '<div class="detail-row"><span class="detail-label">Lunch Break</span><span class="staff-type-badge ' + (detail.is_lunch_break == 1 ? 'staff-type-lunch' : '') + '" style="' + (detail.is_lunch_break != 1 ? 'background:#f3f4f6;color:#9ca3af;border-color:#e5e7eb;' : '') + '">' + (detail.is_lunch_break == 1 ? '🍴 YES' : 'NO') + '</span></div>' +
                '<div class="detail-row"><span class="detail-label">Latitude</span><span class="detail-value">' + (detail.latitude || 'N/A') + '</span></div>' +
                '<div class="detail-row" style="border-bottom:none;"><span class="detail-label">Longitude</span><span class="detail-value">' + (detail.longitude || 'N/A') + '</span></div>' +
                '</div></div>';

            // Photos
            var photos = detail.photos || [];
            if (photos.length > 0) {
                html += '<div><h4 style="font-size:1rem;font-weight:600;color:#374151;margin-bottom:1rem;"><i class="fas fa-camera"></i> Photos (' + photos.length + ')</h4><div class="photo-grid">';
                photos.forEach(function(photoPath) {
                    var url = '/accounts/mkPortal/site_checkin/API/' + photoPath;
                    html += '<div class="photo-item" onclick="window.open(\'' + url + '\',\'_blank\')">' +
                        '<img src="' + url + '" alt="Check-in photo" onerror="this.src=\'data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><rect fill=%22%23e5e7eb%22 width=%22100%22 height=%22100%22/><text x=%2250%22 y=%2250%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%239ca3af%22 font-size=%2214%22>No Image</text></svg>\'">' +
                        '</div>';
                });
                html += '</div></div>';
            } else {
                html += '<div style="text-align:center;padding:2rem;background:#f9fafb;border:1px dashed #d1d5db;border-radius:8px;"><i class="fas fa-camera" style="font-size:2rem;color:#d1d5db;margin-bottom:0.5rem;"></i><p style="color:#6b7280;font-size:0.875rem;">No photos available</p></div>';
            }

            body.innerHTML = html;
        }

        function closeStaffDetailModal() {
            document.getElementById('staffDetailModal').style.display = 'none';
        }

        // Toggle date input based on range selection
        function toggleDateInput(range) {
            const show = (range === 'custom') ? 'flex' : 'none';
            document.getElementById('dateInputGroup').style.display = show;
            document.getElementById('dateToInputGroup').style.display = show;
        }

        // Initialize Select2 — idempotent, applies to all long-list dropdowns
        function initializeSelect2() {
            var configs = [
                { sel: '#staffSelect',         placeholder: 'All Staff',       allowClear: true  },
                { sel: '#siteSelect',          placeholder: 'All Sites',       allowClear: true  },
                { sel: '#staffPersonSelect',   placeholder: 'All Staff',       allowClear: true  },
                { sel: '#staffSiteSelect',     placeholder: 'All Sites',       allowClear: true  },
                { sel: '#companySelect',       placeholder: 'Select Company',  allowClear: false },
                { sel: '#staffCompanySelect',  placeholder: 'Select Company',  allowClear: false }
            ];
            configs.forEach(function(c) {
                var $el = $(c.sel);
                if (!$el.length) return;
                if ($el.hasClass('select2-hidden-accessible')) {
                    $el.select2('destroy');
                }
                $el.select2({
                    placeholder: c.placeholder,
                    allowClear: c.allowClear,
                    width: '100%',
                    theme: 'default'
                });
            });
        }

        function loadSites(compId) {
            if (!compId) {
                document.getElementById('siteSelect').innerHTML = '<option value="">All Sites</option>';
                const staffSelect = document.getElementById('staffSelect');
                if (staffSelect) {
                    staffSelect.innerHTML = '<option value="">All Staff</option>';
                }
                initializeSelect2();
                return;
            }

            // Load sites
            fetch(`?action=get_sites&comp_id=${compId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const siteSelect = document.getElementById('siteSelect');
                        siteSelect.innerHTML = '<option value="">All Sites</option>';
                        data.sites.forEach(site => {
                            const option = document.createElement('option');
                            option.value = site.id;
                            option.textContent = site.site_name;
                            siteSelect.appendChild(option);
                        });
                        initializeSelect2();
                    }
                })
                .catch(error => console.error('Error loading sites:', error));

            // Load staff list
            const staffSelect = document.getElementById('staffSelect');
            if (staffSelect) {
                fetch(`?action=get_staff&comp_id=${compId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            staffSelect.innerHTML = '<option value="">All Staff</option>';
                            data.staff.forEach(person => {
                                const option = document.createElement('option');
                                option.value = person.person;
                                option.textContent = person.fullname.split(' ').map(w =>
                                    w.charAt(0).toUpperCase() + w.slice(1).toLowerCase()
                                ).join(' ');
                                staffSelect.appendChild(option);
                            });
                            initializeSelect2();
                        }
                    })
                    .catch(error => console.error('Error loading staff:', error));
            }
        }

        // Update DOMContentLoaded section
        document.addEventListener('DOMContentLoaded', function() {
            const mainContent = document.querySelector('.main-content');
            const sidebar = document.querySelector('.sidebar');

            // Initialize Select2 on page load (handles existence checks per-selector)
            initializeSelect2();

            function updateMainContentLayout() {
                const isMobile = window.innerWidth <= 768;
                const sidebarClosed = sidebar && sidebar.classList.contains('closed');

                if (!isMobile && !sidebarClosed) {
                    mainContent.classList.add('sidebar-open');
                } else {
                    mainContent.classList.remove('sidebar-open');
                }
            }

            updateMainContentLayout();
            window.addEventListener('resize', updateMainContentLayout);

            // Show filters if there are active filters
            const hasActiveFilters = <?= json_encode(!empty($filters['site_id']) || $filters['range'] !== 'today') ?>;
            if (hasActiveFilters) {
                setTimeout(() => {
                    document.getElementById('toggleFilters').click();
                }, 100);
            }
        });

        // View round details
        function viewRoundDetails(roundId) {
            const modal = document.getElementById('detailsModal');
            const modalBody = document.getElementById('modalBody');
            const modalTitle = document.querySelector('#detailsModal .modal-title');

            modalTitle.textContent = 'Round Details';
            modal.style.display = 'flex';
            modalBody.innerHTML = `
                <div style="text-align: center; padding: 2rem;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #667eea;"></i>
                    <p style="margin-top: 1rem; color: #6b7280;">Loading details...</p>
                </div>
            `;

            fetch(`?action=get_round_details&round_id=${roundId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderRoundDetails(data.details);
                    } else {
                        modalBody.innerHTML = `
                            <div style="text-align: center; padding: 2rem; color: #ef4444;">
                                <i class="fas fa-exclamation-circle" style="font-size: 2rem;"></i>
                                <p style="margin-top: 1rem;">${data.message}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    modalBody.innerHTML = `
                        <div style="text-align: center; padding: 2rem; color: #ef4444;">
                            <i class="fas fa-exclamation-circle" style="font-size: 2rem;"></i>
                            <p style="margin-top: 1rem;">Error loading details</p>
                        </div>
                    `;
                });
        }

        // View checkpoint details
        function viewCheckpointDetails(logId) {
            const modal = document.getElementById('detailsModal');
            const modalBody = document.getElementById('modalBody');
            const modalTitle = document.querySelector('#detailsModal .modal-title');

            modalTitle.textContent = 'Checkpoint Details';
            modal.style.display = 'flex';
            modalBody.innerHTML = `
                <div style="text-align: center; padding: 2rem;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #667eea;"></i>
                    <p style="margin-top: 1rem; color: #6b7280;">Loading checkpoint details...</p>
                </div>
            `;

            fetch(`?action=get_checkpoint_details&log_id=${logId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderCheckpointDetails(data.details);
                    } else {
                        modalBody.innerHTML = `
                            <div style="text-align: center; padding: 2rem; color: #ef4444;">
                                <i class="fas fa-exclamation-circle" style="font-size: 2rem;"></i>
                                <p style="margin-top: 1rem;">${data.message}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    modalBody.innerHTML = `
                        <div style="text-align: center; padding: 2rem; color: #ef4444;">
                            <i class="fas fa-exclamation-circle" style="font-size: 2rem;"></i>
                            <p style="margin-top: 1rem;">Error loading checkpoint details</p>
                        </div>
                    `;
                });
        }

        // Render checkpoint details
        function renderCheckpointDetails(details) {
            const modalBody = document.getElementById('modalBody');
            const {
                log,
                photos
            } = details;

            let modifierName = log.modifier_name ?
                log.modifier_name.split(' ').map(w => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase()).join(' ') :
                'Unknown User';

            let html = `
                <div style="margin-bottom: 2rem;">
                    <h4 style="font-size: 1rem; font-weight: 600; color: #374151; margin-bottom: 1rem;">Checkpoint Information</h4>
                    
                    ${log.modified_by ? `
                    <div style="background: #fff3e0; border: 1px solid #ffb74d; border-left: 3px solid #ff9800; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
                            <i class="fas fa-pencil-alt" style="color: #ff9800;"></i>
                            <span style="font-weight: 600; color: #e65100; font-size: 0.9rem;">Modified Checkpoint</span>
                        </div>
                        <div style="font-size: 0.85rem; color: #666; margin-top: 0.5rem;">
                            <div>Action: <strong>${log.modified_by_action.toUpperCase()}</strong></div>
                            <div>Modified by: <strong>${modifierName}</strong></div>
                            <div>Modified at: <strong>${formatDateTime(log.modified_by_datetime)}</strong></div>
                        </div>
                    </div>
                    ` : ''}
                    
                    <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 1rem;">
                        <div class="detail-row">
                            <span class="detail-label">Checkpoint Name</span>
                            <span class="detail-value">${escapeHtml(log.point_name)}</span>
                        </div>
                        ${log.point_description ? `
                        <div class="detail-row">
                            <span class="detail-label">Description</span>
                            <span class="detail-value">${escapeHtml(log.point_description)}</span>
                        </div>
                        ` : ''}
                        <div class="detail-row">
                            <span class="detail-label">Checked In At</span>
                            <span class="detail-value">${formatDateTime(log.checked_in_at)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Distance from Point</span>
                            <span class="detail-value">${log.distance_meters ? `${log.distance_meters}m` : ''}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Latitude</span>
                            <span class="detail-value">${log.latitude}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Longitude</span>
                            <span class="detail-value">${log.longitude}</span>
                        </div>
                        ${log.remarks ? `
                        <div class="detail-row" style="border-bottom: none;">
                            <span class="detail-label">Remarks</span>
                            <span class="detail-value">${escapeHtml(log.remarks)}</span>
                        </div>
                        ` : '<div style="border-bottom: none;"></div>'}
                    </div>
                </div>
            `;

            if (photos.length > 0) {
                html += `
                    <div>
                        <h4 style="font-size: 1rem; font-weight: 600; color: #374151; margin-bottom: 1rem;">
                            <i class="fas fa-camera"></i> Photos (${photos.length})
                        </h4>
                        <div class="photo-grid">
                            ${photos.map(photo => `
                                <div class="photo-item" onclick="window.open('/accounts/mkPortal/site_checkin/API/photos/${photo.document_file_name}', '_blank')">
                                    <img src="/accounts/mkPortal/site_checkin/API/photos/${photo.document_file_name}" 
                                         alt="Checkpoint photo"
                                         onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><rect fill=%22%23e5e7eb%22 width=%22100%22 height=%22100%22/><text x=%2250%22 y=%2250%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%239ca3af%22 font-size=%2214%22>No Image</text></svg>'">
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            } else {
                html += `
                    <div style="text-align: center; padding: 2rem; background: #f9fafb; border: 1px dashed #d1d5db; border-radius: 8px;">
                        <i class="fas fa-camera" style="font-size: 2rem; color: #d1d5db; margin-bottom: 0.5rem;"></i>
                        <p style="color: #6b7280; font-size: 0.875rem;">No photos available for this checkpoint</p>
                    </div>
                `;
            }

            modalBody.innerHTML = html;
        }

        // Render round details
        function renderRoundDetails(details) {
            const modalBody = document.getElementById('modalBody');
            const {
                round,
                logs,
                photos
            } = details;

            let html = `
                <div style="margin-bottom: 2rem;">
                    <h4 style="font-size: 1rem; font-weight: 600; color: #374151; margin-bottom: 1rem;">Round Information</h4>
                    <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 1rem;">
                        <div class="detail-row">
                            <span class="detail-label">Round Code</span>
                            <span class="detail-value">${escapeHtml(round.round_code)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Status</span>
                            <span class="status-badge status-${round.check_in_status}">
                                ${round.check_in_status.replace('_', ' ')}
                            </span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Started</span>
                            <span class="detail-value">${formatDateTime(round.started_at)}</span>
                        </div>
                        ${round.completed_at ? `
                        <div class="detail-row">
                            <span class="detail-label">Completed</span>
                            <span class="detail-value">${formatDateTime(round.completed_at)}</span>
                        </div>
                        ` : ''}
                        <div class="detail-row">
                            <span class="detail-label">Duration</span>
                            <span class="detail-value">${formatDuration(round.duration_minutes)}</span>
                        </div>
                        <div class="detail-row" style="border-bottom: none;">
                            <span class="detail-label">Progress</span>
                            <span class="detail-value">${round.completed_points} / ${round.total_points} checkpoints</span>
                        </div>
                    </div>
                </div>
            `;

            if (logs.length > 0) {
                html += `
                    <div class="checkpoint-details">
                        <h4 style="font-size: 1rem; font-weight: 600; color: #374151; margin-bottom: 1rem;">
                            Checkpoints (${logs.length})
                        </h4>
                `;

                logs.forEach((log, index) => {
                    const logPhotos = photos.filter(p => p.log_id === log.id);

                    html += `
                        <div class="checkpoint-card">
                            <div class="checkpoint-header">
                                <div class="checkpoint-number">${index + 1}</div>
                                <div class="checkpoint-name">${escapeHtml(log.point_name)}</div>
                                <i class="fas fa-check-circle" style="color: #059669; font-size: 1.25rem;"></i>
                            </div>
                            <div class="checkpoint-info">
                                <i class="fas fa-clock"></i> ${formatDateTime(log.checked_in_at)}
                            </div>
                            <div class="checkpoint-info">
                                <i class="fas fa-map-marker-alt"></i> Distance: ${parseFloat(log.distance_meters).toFixed(1)}m
                            </div>
                            ${log.remarks ? `
                            <div class="checkpoint-info">
                                <i class="fas fa-comment"></i> ${escapeHtml(log.remarks)}
                            </div>
                            ` : ''}
                            ${logPhotos.length > 0 ? `
                            <div style="margin-top: 1rem;">
                                <div style="font-size: 0.875rem; font-weight: 600; color: #374151; margin-bottom: 0.5rem;">
                                    <i class="fas fa-camera"></i> Photos (${logPhotos.length})
                                </div>
                                <div class="photo-grid">
                                    ${logPhotos.map(photo => `
                                        <div class="photo-item" onclick="window.open('/accounts/mkPortal/site_checkin/API/photos/${photo.document_file_name}', '_blank')">
                                            <img src="/accounts/mkPortal/site_checkin/API/photos/${photo.document_file_name}" 
                                                 alt="Checkpoint photo"
                                                 onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><rect fill=%22%23e5e7eb%22 width=%22100%22 height=%22100%22/><text x=%2250%22 y=%2250%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%239ca3af%22 font-size=%2214%22>No Image</text></svg>'">
                                        </div>
                                    `).join('')}
                                </div>
                            </div>
                            ` : ''}
                        </div>
                    `;
                });

                html += `</div>`;
            }

            modalBody.innerHTML = html;
        }

        // Close modal
        function closeModal() {
            document.getElementById('detailsModal').style.display = 'none';
        }

        // Utility functions
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatDateTime(datetime) {
            const date = new Date(datetime);
            return date.toLocaleString('en-MY', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            });
        }

        function formatDuration(minutes) {
            if (!minutes) return 'N/A';
            const hours = Math.floor(minutes / 60);
            const mins = minutes % 60;
            if (hours > 0) {
                return `${hours}h ${mins}m`;
            }
            return `${mins}m`;
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('detailsModal');
            if (event.target === modal) {
                closeModal();
            }
        }

        // Sidebar layout management
        document.addEventListener('DOMContentLoaded', function() {
            const mainContent = document.querySelector('.main-content');
            const sidebar = document.querySelector('.sidebar');

            function updateMainContentLayout() {
                const isMobile = window.innerWidth <= 768;
                const sidebarClosed = sidebar && sidebar.classList.contains('closed');

                if (!isMobile && !sidebarClosed) {
                    mainContent.classList.add('sidebar-open');
                } else {
                    mainContent.classList.remove('sidebar-open');
                }
            }

            updateMainContentLayout();
            window.addEventListener('resize', updateMainContentLayout);

            // Show filters if there are active filters
            const hasActiveFilters2 = <?= json_encode(!empty($filters['site_id']) || !empty($filters['staff_site_id']) || !empty($filters['staff_person']) || !empty($filters['check_in_type']) || $filters['range'] !== 'today') ?>;
            const currentMode = '<?= $activeMode ?>';
            if (hasActiveFilters2) {
                setTimeout(function() {
                    if (currentMode === 'staff') {
                        document.getElementById('toggleStaffFilters').click();
                    } else {
                        document.getElementById('toggleFilters').click();
                    }
                }, 100);
            }
        });

        // Show cancel reason modal
        function showCancelReason(roundCode, cancelReason, cancelledAt) {
            const modal = document.getElementById('detailsModal');
            const modalBody = document.getElementById('modalBody');
            const modalTitle = document.querySelector('#detailsModal .modal-title');

            modalTitle.innerHTML = '<i class="fas fa-times-circle"></i> Round Cancelled';
            modal.style.display = 'flex';

            const formattedDate = new Date(cancelledAt).toLocaleString('en-MY', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            });

            modalBody.innerHTML = `
                    <div style="margin-bottom: 1.5rem;">
                        <div style="background: #ffebee; border: 1px solid #ef9a9a; border-left: 3px solid #c62828; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                                <i class="fas fa-exclamation-triangle" style="color: #c62828; font-size: 1.25rem;"></i>
                                <span style="font-weight: 600; color: #c62828; font-size: 0.95rem;">This round was cancelled</span>
                            </div>
                        </div>

                        <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 1rem;">
                            <div class="detail-row">
                                <span class="detail-label">Round Code</span>
                                <span class="detail-value">${escapeHtml(roundCode)}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Cancelled At</span>
                                <span class="detail-value">${formattedDate}</span>
                            </div>
                            <div class="detail-row" style="border-bottom: none; flex-direction: column; align-items: flex-start;">
                                <span class="detail-label" style="margin-bottom: 0.5rem;">Cancel Reason</span>
                                <div style="background: white; border: 1px solid #e5e7eb; padding: 0.75rem; border-radius: 6px; width: 100%; white-space: pre-wrap; word-break: break-word; color: #374151; line-height: 1.5;">
                                    ${escapeHtml(cancelReason)}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
        }

        // Checkpoint modification functions
        let selectedPhotos = [];
        let existingPhotos = [];
        let photosToDelete = [];

        function addCheckpoint(roundId, pointId, pointName) {
            selectedPhotos = [];
            existingPhotos = [];
            photosToDelete = [];

            const modal = document.getElementById('modifyCheckpointModal');
            const form = document.getElementById('modifyCheckpointForm');
            const title = document.getElementById('modifyModalTitle');

            title.innerHTML = '<i class="fas fa-plus-circle"></i> Add Checkpoint';
            document.getElementById('photo_required_label').style.display = 'inline';
            document.getElementById('photo_input').required = true;
            document.getElementById('existing_photos_container').style.display = 'none';

            form.reset();
            document.getElementById('modify_round_id').value = roundId;
            document.getElementById('modify_point_id').value = pointId;
            document.getElementById('modify_action').value = 'add';
            document.getElementById('modify_point_name').value = pointName;

            // Set current date and time
            const now = new Date();
            document.getElementById('modify_date').value = now.toISOString().split('T')[0];
            document.getElementById('modify_time').value = now.toTimeString().slice(0, 5);

            document.getElementById('photoPreview').innerHTML = '';
            document.getElementById('photoUploadContainer').classList.remove('has-photos');

            modal.style.display = 'flex';
        }

        function editCheckpoint(roundId, pointId, logId) {
            selectedPhotos = [];
            existingPhotos = [];
            photosToDelete = [];

            const modal = document.getElementById('modifyCheckpointModal');
            const title = document.getElementById('modifyModalTitle');

            title.innerHTML = '<i class="fas fa-edit"></i> Edit Checkpoint';
            document.getElementById('photo_required_label').style.display = 'none';
            document.getElementById('photo_input').required = false;

            // Fetch checkpoint details
            fetch(`?action=get_checkpoint_details&log_id=${logId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const log = data.details.log;
                        const photos = data.details.photos;
                        const form = document.getElementById('modifyCheckpointForm');

                        form.reset();
                        document.getElementById('modify_round_id').value = roundId;
                        document.getElementById('modify_point_id').value = pointId;
                        document.getElementById('modify_log_id').value = logId;
                        document.getElementById('modify_action').value = 'edit';
                        document.getElementById('modify_point_name').value = log.point_name;

                        // Parse datetime
                        const datetime = new Date(log.checked_in_at);
                        document.getElementById('modify_date').value = datetime.toISOString().split('T')[0];
                        document.getElementById('modify_time').value = datetime.toTimeString().slice(0, 5);

                        document.getElementById('modify_latitude').value = log.latitude;
                        document.getElementById('modify_longitude').value = log.longitude;
                        // document.getElementById('modify_distance').value = parseFloat(log.distance_meters);
                        document.getElementById('modify_remarks').value = log.remarks || '';

                        // Load existing photos
                        existingPhotos = photos;
                        renderExistingPhotos();

                        document.getElementById('photoPreview').innerHTML = '';
                        document.getElementById('photoUploadContainer').classList.remove('has-photos');

                        modal.style.display = 'flex';
                    }
                })
                .catch(error => {
                    alert('Failed to load checkpoint details');
                    console.error(error);
                });
        }

        function closeModifyModal() {
            document.getElementById('modifyCheckpointModal').style.display = 'none';
            selectedPhotos = [];
            existingPhotos = [];
            photosToDelete = [];
        }

        function renderExistingPhotos() {
            const container = document.getElementById('existing_photos_container');
            const grid = document.getElementById('existing_photos_grid');
            const label = container.querySelector('.form-label');

            if (existingPhotos.length === 0) {
                container.style.display = 'none';
                return;
            }

            container.style.display = 'block';

            // Update label with deletion count
            const deletionCount = photosToDelete.length;
            const remainingCount = existingPhotos.length - deletionCount;
            let labelText = 'Existing Photos';

            if (deletionCount > 0) {
                labelText += ` <span style="color: #f44336; font-weight: 700;">(${deletionCount} will be removed, ${remainingCount} will remain)</span>`;
            }

            if (label) {
                label.innerHTML = labelText;
            }

            grid.innerHTML = existingPhotos.map(photo => {
                const isMarkedForDeletion = photosToDelete.includes(photo.id);
                const buttonTitle = isMarkedForDeletion ? 'Click to undo removal' : 'Click to remove photo';
                const markedClass = isMarkedForDeletion ? 'marked-for-deletion' : '';

                return `
                    <div class="photo-preview-item ${markedClass}">
                        <img src="/accounts/mkPortal/site_checkin/API/photos/${photo.document_file_name}" 
                             alt="Existing photo"
                             onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><rect fill=%22%23e5e7eb%22 width=%22100%22 height=%22100%22/><text x=%2250%22 y=%2250%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%239ca3af%22 font-size=%2214%22>Error</text></svg>'">
                        <button type="button" 
                                class="remove-photo" 
                                onclick="${isMarkedForDeletion ? 'undoPhotoRemoval' : 'markPhotoForDeletion'}(${photo.id})" 
                                title="${buttonTitle}">
                            <i class="fas ${isMarkedForDeletion ? 'fa-undo' : 'fa-times'}"></i>
                        </button>
                    </div>
                `;
            }).join('');
        }

        function markPhotoForDeletion(photoId) {
            if (!photosToDelete.includes(photoId)) {
                if (confirm('Mark this photo for removal? You can undo this before saving.')) {
                    photosToDelete.push(photoId);
                    renderExistingPhotos();
                }
            }
        }

        function undoPhotoRemoval(photoId) {
            const index = photosToDelete.indexOf(photoId);
            if (index > -1) {
                photosToDelete.splice(index, 1);
                renderExistingPhotos();
            }
        }

        function handlePhotoSelect(event) {
            const files = Array.from(event.target.files);
            const maxPhotos = 5;
            const maxSize = 10 * 1024 * 1024; // 10MB

            // Filter valid files
            const validFiles = files.filter(file => {
                if (file.size > maxSize) {
                    alert(`${file.name} is too large. Max size is 10MB.`);
                    return false;
                }
                return true;
            });

            // Calculate available slots (existing + new - deleted)
            const activeExistingPhotos = existingPhotos.length - photosToDelete.length;
            const totalPhotos = activeExistingPhotos + selectedPhotos.length;
            const availableSlots = maxPhotos - totalPhotos;

            if (availableSlots <= 0) {
                alert('Maximum 5 photos allowed. Please remove existing photos first.');
                return;
            }

            const filesToAdd = validFiles.slice(0, availableSlots);
            selectedPhotos = [...selectedPhotos, ...filesToAdd];

            // Update preview
            renderPhotoPreview();
        }

        function renderPhotoPreview() {
            const preview = document.getElementById('photoPreview');
            const container = document.getElementById('photoUploadContainer');

            if (selectedPhotos.length > 0) {
                container.classList.add('has-photos');
            } else {
                container.classList.remove('has-photos');
            }

            preview.innerHTML = selectedPhotos.map((file, index) => `
                <div class="photo-preview-item">
                    <img src="${URL.createObjectURL(file)}" alt="Preview">
                    <button type="button" class="remove-photo" onclick="removePhoto(${index})">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `).join('');
        }

        function removePhoto(index) {
            selectedPhotos.splice(index, 1);
            renderPhotoPreview();
        }

        // Form submission
        document.getElementById('modifyCheckpointForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const action = document.getElementById('modify_action').value;
            const submitBtn = document.getElementById('submitModifyBtn');

            // Validate photos for new checkpoints
            if (action === 'add' && selectedPhotos.length === 0) {
                alert('Please add at least one photo');
                return;
            }

            // Check if editing and all photos are deleted without adding new ones
            if (action === 'edit') {
                const activeExistingPhotos = existingPhotos.length - photosToDelete.length;
                const totalPhotos = activeExistingPhotos + selectedPhotos.length;

                if (totalPhotos === 0) {
                    alert('At least one photo is required. Please add photos or keep existing ones.');
                    return;
                }
            }

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            try {
                const formData = new FormData();

                // Add form fields
                formData.append('round_id', document.getElementById('modify_round_id').value);
                formData.append('point_id', document.getElementById('modify_point_id').value);
                formData.append('action', action);

                if (action === 'edit') {
                    formData.append('log_id', document.getElementById('modify_log_id').value);

                    // Add photos to delete
                    if (photosToDelete.length > 0) {
                        formData.append('delete_photos', photosToDelete.join(','));
                    }
                }

                // Combine date and time
                const date = document.getElementById('modify_date').value;
                const time = document.getElementById('modify_time').value;
                formData.append('checked_in_at', `${date} ${time}:00`);

                formData.append('latitude', document.getElementById('modify_latitude').value);
                formData.append('longitude', document.getElementById('modify_longitude').value);
                // formData.append('distance_meters', document.getElementById('modify_distance').value);
                formData.append('remarks', document.getElementById('modify_remarks').value);

                // Add photos
                selectedPhotos.forEach(file => {
                    formData.append('photos[]', file);
                });

                const response = await fetch('/accounts/mkPortal/site_checkin/API/modifyCheckpoint.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    alert(data.message);
                    closeModifyModal();
                    location.reload(); // Reload to show changes
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                alert('Failed to save checkpoint. Please try again.');
                console.error(error);
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-save"></i> Save';
            }
        });

        // ==================== LUNCH PAIR HOVER LINK ====================
        (function() {
            function togglePair(pairId, on) {
                var rows = document.querySelectorAll('tr[data-lunch-pair="' + pairId + '"]');
                Array.prototype.forEach.call(rows, function(tr) {
                    tr.classList.toggle('lunch-pair-hover', on);
                });
            }

            function handle(e, on) {
                if (!e.target || !e.target.closest) return;
                var tr = e.target.closest('tr[data-lunch-pair]');
                if (tr) togglePair(tr.getAttribute('data-lunch-pair'), on);
            }

            document.addEventListener('mouseover', function(e) {
                handle(e, true);
            });
            document.addEventListener('mouseout', function(e) {
                handle(e, false);
            });
        })();

        // Close modals when clicking outside
        window.onclick = function(event) {
            const detailsModal = document.getElementById('detailsModal');
            const modifyModal = document.getElementById('modifyCheckpointModal');
            const staffModal = document.getElementById('staffDetailModal');

            if (event.target === detailsModal) {
                closeModal();
            }
            if (event.target === modifyModal) {
                closeModifyModal();
            }
            if (event.target === staffModal) {
                closeStaffDetailModal();
            }
        }
    </script>
</body>

</html>