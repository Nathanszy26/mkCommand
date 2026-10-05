<?php
/**
 * Face Enrollment Admin Page (Revamped)
 *
 * URL: enroll_admin.php?person=nathan&comp_id=1
 *
 * Auth: checks face_checkIn_admins for person+comp_id+status=1
 * Two tabs: Enroll (register faces) | Manage (view/delete enrolled photos)
 *
 * PHP 7.2 compatible
 */

// ─── DB Config ───
define('DB_HOST', 'globportal.com');
define('DB_PORT', 33060);
define('DB_USER', 'prog3');
define('DB_PASS', 'prog3@2023');
define('DB_NAME', 'mkPortal'); // face_checkIn_admins + staff_face_encodings live here

define('FACE_SERVICE_URL', 'http://127.0.0.1:5000/enroll');
define('PHOTO_BASE_DIR', '/var/www/html/glob/it/mkPortal/site_checkin/face_services/photo/');
define('PHOTO_WEB_BASE', '/it/mkPortal/site_checkin/face_services/photo/');

// GW Face Service (dailyActivitiesApp — port 5001)
define('GW_FACE_SERVICE_BASE', 'http://127.0.0.1:5001');
define('GW_SERVICE_DIR', '/var/www/html/glob/it/mkPortal/dailyActivitiesApp/face_services');

// ─── Staff profile photos (myMK app) ───
// Schema of staff_profile that matters here:
//   person + comid  (unique key)  -> the primary link to staff_portal2.users
//   mymkid                        -> fallback link (= users.id)
//   profile_pic                   -> path under PROFILE_PIC_URL_BASE
//   deleted_at                    -> soft delete
//
// ⚠ CONFIRM the schema staff_profile lives in, then fix this one line:
//     SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME = 'staff_profile';
//   A wrong value throws a loud SQL error — it will not silently report "no profile photo".
define('PROFILE_DB', 'staff_portal2');
define('PROFILE_PIC_URL_BASE', 'https://app.globportal.com/mymk/storage/');

// ─── DB Connection ───
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME,
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

// ─── Auth: validate admin from query params ───
function authenticateAdmin() {
    $person = isset($_GET['person']) ? trim($_GET['person']) : '';
    $compId = isset($_GET['comp_id']) ? intval($_GET['comp_id']) : 0;

    if ($person === '' || $compId <= 0) {
        return null;
    }

    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, person, comp_id, all_companies, assigned_companies
        FROM face_checkIn_admins
        WHERE person = :person AND comp_id = :comp_id AND status = '1'
        LIMIT 1
    ");
    $stmt->execute([':person' => $person, ':comp_id' => $compId]);
    return $stmt->fetch() ?: null;
}

// ─── Get allowed companies for this admin ───
function getAdminCompanies($admin) {
    $db = getDB();

    if ($admin['all_companies'] === '1') {
        $stmt = $db->query("SELECT id, code, name FROM staff_portal2.subsidiaries WHERE status = '1' ORDER BY name");
        return $stmt->fetchAll();
    }

    $ids = array_filter(array_map('intval', explode(',', $admin['assigned_companies'] ?? '')));
    if (empty($ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, code, name FROM staff_portal2.subsidiaries WHERE status = '1' AND id IN ($placeholders) ORDER BY name");
    $stmt->execute($ids);
    return $stmt->fetchAll();
}

// ─── AJAX handler ───
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    $admin = authenticateAdmin();
    if (!$admin) {
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $allowedCompanyIds = array_column(getAdminCompanies($admin), 'id');
    $action = $_GET['ajax'];

    // --- Get staff for a company ---
    if ($action === 'get_staff') {
        $compId = intval($_GET['company_id'] ?? 0);
        if (!in_array($compId, $allowedCompanyIds)) {
            echo json_encode(['error' => 'Company not allowed']);
            exit;
        }

        $db = getDB();
        $stmt = $db->prepare("
            SELECT id, person, comp_id, fullname, department, position, mobile_no
            FROM staff_portal2.users
            WHERE comp_id = :comp_id AND status = 1 AND deleted = 0
            ORDER BY fullname
        ");
        $stmt->execute([':comp_id' => $compId]);
        echo json_encode(['staff' => $stmt->fetchAll()]);
        exit;
    }

    // --- Enroll face ---
    if ($action === 'enroll' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = json_decode(file_get_contents('php://input'), true);

        $userId   = intval($raw['user_id'] ?? 0);
        $images   = $raw['images'] ?? [];

        if ($userId <= 0 || empty($images)) {
            echo json_encode(['success' => false, 'message' => 'Missing user or images']);
            exit;
        }

        // Verify user belongs to allowed company
        $db = getDB();
        $stmt = $db->prepare("SELECT id, person, comp_id FROM staff_portal2.users WHERE id = :id AND status = 1 AND deleted = 0 LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user || !in_array(intval($user['comp_id']), $allowedCompanyIds)) {
            echo json_encode(['success' => false, 'message' => 'User not found or not allowed']);
            exit;
        }

        $results = [];
        foreach ($images as $idx => $base64Image) {
            // Strip data URI prefix
            if (strpos($base64Image, ',') !== false) {
                $base64Image = explode(',', $base64Image, 2)[1];
            }

            // Shared with encode_profile (DRY) — one definition of the face-service call.
            $decoded = callFaceService($base64Image, $user, null);
            if (isset($decoded['_error'])) {
                $results[] = ['index' => $idx, 'success' => false, 'message' => $decoded['_error']];
                continue;
            }

            // Save photo to disk if enrollment succeeded
            if (!empty($decoded['success']) && !empty($decoded['encoding_id'])) {
                $photoPath = saveEnrollmentPhoto($base64Image, $user['id'], $decoded['encoding_id']);
                if ($photoPath) {
                    // Update encoding record with photo path
                    $db->prepare("UPDATE staff_face_encodings SET photo_path = :path, photo_name = :name WHERE id = :id")
                       ->execute([':path' => $photoPath['path'], ':name' => $photoPath['name'], ':id' => $decoded['encoding_id']]);
                }
            }

            $results[] = array_merge(['index' => $idx], $decoded);
        }

        echo json_encode(['success' => true, 'results' => $results]);
        exit;
    }

    // --- Get enrolled faces for a user ---
    if ($action === 'get_enrolled') {
        $userId = intval($_GET['user_id'] ?? 0);

        $db = getDB();
        $stmt = $db->prepare("SELECT id, person, comp_id FROM staff_portal2.users WHERE id = :id AND status = 1 AND deleted = 0 LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user || !in_array(intval($user['comp_id']), $allowedCompanyIds)) {
            echo json_encode(['error' => 'Not allowed']);
            exit;
        }

        $stmt = $db->prepare("
            SELECT id, photo_path, photo_name, created_at
            FROM staff_face_encodings
            WHERE person = :person AND comp_id = :comp_id AND deleted = '0'
            ORDER BY created_at DESC
        ");
        $stmt->execute([':person' => $user['person'], ':comp_id' => $user['comp_id']]);
        echo json_encode(['encodings' => $stmt->fetchAll()]);
        exit;
    }

    // --- Get all staff with enrollment count for a company ---
    if ($action === 'get_staff_with_status') {
        $compId = intval($_GET['company_id'] ?? 0);
        if (!in_array($compId, $allowedCompanyIds)) {
            echo json_encode(['error' => 'Company not allowed']);
            exit;
        }

        echo json_encode(['staff' => fetchStaffWithStatus($compId)]);
        exit;
    }

    // --- Convert ONE staff's profile photo into a face encoding ---
    // One staff per request by design: a company-wide loop inside a single request would
    // blow PHP's execution limit (remote image download + encoding, per staff). The client
    // drives the batch, so it stays resumable and can report which staff failed.
    if ($action === 'encode_profile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw    = json_decode(file_get_contents('php://input'), true);
        $userId = intval($raw['user_id'] ?? 0);

        if ($userId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Missing user']);
            exit;
        }

        $db   = getDB();
        $stmt = $db->prepare("SELECT id, person, comp_id, fullname FROM staff_portal2.users WHERE id = :id AND status = 1 AND deleted = 0 LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user || !in_array(intval($user['comp_id']), $allowedCompanyIds)) {
            echo json_encode(['success' => false, 'message' => 'User not found or not allowed']);
            exit;
        }

        // Same rule the listing uses (one query, one source of truth — the badge the admin
        // clicked and the work this does can never disagree).
        $rows = fetchStaffWithStatus(intval($user['comp_id']), $userId);
        $row  = $rows ? $rows[0] : null;
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Staff not found']);
            exit;
        }

        $pic = $row['profile_pic'];

        if ($row['profile_status'] === 'no_photo') {
            echo json_encode([
                'success'        => false,
                'status'         => 'no_photo',
                'encoding_count' => intval($row['encoding_count']),
                'message'        => 'No profile photo uploaded',
            ]);
            exit;
        }

        // Already converted from this exact photo -> nothing to do (batch re-runs are free).
        if ($row['profile_status'] === 'encoded') {
            echo json_encode([
                'success'        => true,
                'skipped'        => true,
                'status'         => 'encoded',
                'encoding_count' => intval($row['encoding_count']),
                'message'        => 'Already converted',
            ]);
            exit;
        }

        // Fetch the photo from the myMK app.
        $bytes = fetchProfilePicBytes($pic);
        if ($bytes === null) {
            echo json_encode(['success' => false, 'status' => $row['profile_status'], 'message' => 'Could not download the profile photo']);
            exit;
        }
        if (@getimagesizefromstring($bytes) === false) {
            echo json_encode(['success' => false, 'status' => $row['profile_status'], 'message' => 'Profile file is not a readable image']);
            exit;
        }

        $b64     = base64_encode($bytes);
        $decoded = callFaceService($b64, $user, 'profile');

        if (isset($decoded['_error'])) {
            echo json_encode(['success' => false, 'status' => $row['profile_status'], 'message' => $decoded['_error']]);
            exit;
        }
        if (empty($decoded['success']) || empty($decoded['encoding_id'])) {
            $msg = isset($decoded['message']) ? $decoded['message'] : 'No face detected in the profile photo';
            echo json_encode(['success' => false, 'status' => $row['profile_status'], 'message' => $msg]);
            exit;
        }

        $encId = intval($decoded['encoding_id']);

        // Stamp the source photo onto the new encoding. The Python service is untouched —
        // it inserts the row, we mark it, exactly as the manual enroll path already does.
        $photo = saveEnrollmentPhoto($b64, $user['id'], $encId);
        $db->prepare("
            UPDATE staff_face_encodings
               SET profile_pic = :pic,
                   source_note = 'profile',
                   photo_path  = :path,
                   photo_name  = :name
             WHERE id = :id
        ")->execute([
            ':pic'  => $pic,
            ':path' => $photo ? $photo['path'] : null,
            ':name' => $photo ? $photo['name'] : null,
            ':id'   => $encId,
        ]);

        // Exactly ONE profile-derived encoding per account: retire any earlier one (i.e. the
        // stale encoding of a profile photo they have since replaced). Manually enrolled
        // photos carry profile_pic = NULL and are never touched.
        $db->prepare("
            UPDATE staff_face_encodings
               SET deleted = '1'
             WHERE ((person = :person AND comp_id = :comp_id) OR mymk_id = :mymk_id)
               AND profile_pic IS NOT NULL AND profile_pic <> ''
               AND id <> :id
               AND deleted = '0'
        ")->execute([
            ':person'  => $user['person'],
            ':comp_id' => $user['comp_id'],
            ':mymk_id' => intval($user['id']),
            ':id'      => $encId,
        ]);

        $fresh = fetchStaffWithStatus(intval($user['comp_id']), $userId);
        echo json_encode([
            'success'        => true,
            'skipped'        => false,
            'status'         => $fresh ? $fresh[0]['profile_status'] : 'encoded',
            'encoding_count' => $fresh ? intval($fresh[0]['encoding_count']) : 0,
            'encoding_id'    => $encId,
        ]);
        exit;
    }

    // --- Delete an encoding ---
    if ($action === 'delete_encoding' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = json_decode(file_get_contents('php://input'), true);
        $encodingId = intval($raw['encoding_id'] ?? 0);

        if ($encodingId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
            exit;
        }

        $db = getDB();
        // Verify ownership via comp_id
        $stmt = $db->prepare("SELECT id, comp_id FROM staff_face_encodings WHERE id = :id AND deleted = '0' LIMIT 1");
        $stmt->execute([':id' => $encodingId]);
        $enc = $stmt->fetch();

        if (!$enc || !in_array(intval($enc['comp_id']), $allowedCompanyIds)) {
            echo json_encode(['success' => false, 'message' => 'Not found or not allowed']);
            exit;
        }

        $db->prepare("UPDATE staff_face_encodings SET deleted = '1' WHERE id = :id")->execute([':id' => $encodingId]);
        echo json_encode(['success' => true]);
        exit;
    }

    // --- Get GW workers for a company ---
    if ($action === 'get_gw') {
        $compId = intval($_GET['company_id'] ?? 0);
        if (!in_array($compId, $allowedCompanyIds)) {
            echo json_encode(['error' => 'Company not allowed']);
            exit;
        }

        $db = getDB();
        $stmt = $db->prepare("
            SELECT MIN(monthly_assign_gw_id) AS id,
                   monthly_assign_gw_code AS code,
                   MAX(monthly_assign_gw_fullname) AS fullname,
                   MAX(monthly_assign_gw_status) AS status,
                   MAX(mymk_daily_check_in_default) AS checkin_default,
                   MAX(mymk_daily_check_in_face) AS checkin_face
            FROM evaluation.monthly_assign_gw
            WHERE comp_id = :comp_id AND monthly_assign_gw_status = '1'
            GROUP BY monthly_assign_gw_code
            ORDER BY fullname
        ");
        $stmt->execute([':comp_id' => $compId]);
        echo json_encode(['gw' => $stmt->fetchAll()]);
        exit;
    }

    // --- Enroll GW face (via dailyActivitiesApp service on port 5001) ---
    if ($action === 'enroll_gw' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = json_decode(file_get_contents('php://input'), true);

        $gwId   = intval($raw['gw_id'] ?? 0);
        $images = $raw['images'] ?? [];

        if ($gwId <= 0 || empty($images)) {
            echo json_encode(['success' => false, 'message' => 'Missing GW or images']);
            exit;
        }

        // Verify GW exists and belongs to an allowed company
        $db = getDB();
        $stmt = $db->prepare("
            SELECT monthly_assign_gw_id AS id, comp_id, monthly_assign_gw_code AS code
            FROM evaluation.monthly_assign_gw
            WHERE monthly_assign_gw_id = :id LIMIT 1
        ");
        $stmt->execute([':id' => $gwId]);
        $gw = $stmt->fetch();

        if (!$gw || !in_array(intval($gw['comp_id']), $allowedCompanyIds)) {
            echo json_encode(['success' => false, 'message' => 'GW not found or not allowed']);
            exit;
        }

        $results = [];
        foreach ($images as $idx => $base64Image) {
            if (strpos($base64Image, ',') !== false) {
                $base64Image = explode(',', $base64Image, 2)[1];
            }

            $payload = json_encode([
                'gw_id' => $gwId,
                'image' => $base64Image,
            ]);

            $ch = curl_init(GW_FACE_SERVICE_BASE . '/enroll');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($resp === false) {
                $results[] = ['index' => $idx, 'success' => false, 'message' => 'GW Face service unavailable: ' . $err];
                continue;
            }

            $decoded = json_decode($resp, true);
            if (!$decoded) {
                $results[] = ['index' => $idx, 'success' => false, 'message' => 'Invalid GW face service response'];
                continue;
            }

            $results[] = array_merge(['index' => $idx], $decoded);
        }

        // Auto-enable face check-in if at least one photo enrolled successfully
        $anySuccess = false;
        foreach ($results as $r) {
            if (!empty($r['success'])) { $anySuccess = true; break; }
        }
        if ($anySuccess) {
            $db->prepare("UPDATE evaluation.monthly_assign_gw SET mymk_daily_check_in_face = '1' WHERE monthly_assign_gw_code = :code")
               ->execute([':code' => $gw['code']]);
        }

        echo json_encode(['success' => true, 'results' => $results]);
        exit;
    }

    // --- Get enrolled GW faces ---
    if ($action === 'get_gw_enrolled') {
        $gwId = intval($_GET['gw_id'] ?? 0);
        if ($gwId <= 0) {
            echo json_encode(['error' => 'Invalid gw_id']);
            exit;
        }

        $db = getDB();
        $stmt = $db->prepare("
            SELECT e.id, e.photo_path, e.photo_name, e.created_at
            FROM gw_face_encodings e
            JOIN evaluation.monthly_assign_gw g
              ON g.monthly_assign_gw_id = :gw_id
            WHERE e.monthly_assign_gw_code = g.monthly_assign_gw_code
              AND e.deleted = '0'
            ORDER BY e.created_at DESC
        ");
        $stmt->execute([':gw_id' => $gwId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            if ($row['created_at'] instanceof \DateTime) {
                $row['created_at'] = $row['created_at']->format('Y-m-d H:i:s');
            }
        }
        unset($row);
        echo json_encode(['encodings' => $rows]);
        exit;
    }

    // --- Get GW workers with enrollment count for manage tab ---
    if ($action === 'get_gw_with_status') {
        $compId = intval($_GET['company_id'] ?? 0);
        if (!in_array($compId, $allowedCompanyIds)) {
            echo json_encode(['error' => 'Company not allowed']);
            exit;
        }

        $db = getDB();
        $stmt = $db->prepare("
            SELECT MIN(g.monthly_assign_gw_id) AS id,
                   g.monthly_assign_gw_code AS code,
                   MAX(g.monthly_assign_gw_fullname) AS fullname,
                   MAX(g.monthly_assign_gw_nric) AS nric,
                   MAX(g.monthly_assign_gw_mobile_no) AS mobile_no,
                   MAX(g.mymk_daily_check_in_default) AS checkin_default,
                   MAX(g.mymk_daily_check_in_face) AS checkin_face,
                   (SELECT COUNT(*) FROM gw_face_encodings e
                     WHERE e.monthly_assign_gw_code = g.monthly_assign_gw_code
                       AND e.deleted = '0') AS encoding_count
            FROM evaluation.monthly_assign_gw g
            WHERE g.comp_id = :comp_id AND g.monthly_assign_gw_status = '1'
            GROUP BY g.monthly_assign_gw_code
            ORDER BY fullname
        ");
        $stmt->execute([':comp_id' => $compId]);
        $gwList = $stmt->fetchAll();

        // Batch phone lookup: match monthly_assign_gw_mobile_no against
        // mkmembers.member_registrations.phone_no (formats already match — no normalization)
        $phoneMap = []; // phone => [gwList indices]
        foreach ($gwList as $idx => $gw) {
            $phone = isset($gw['mobile_no']) ? trim($gw['mobile_no']) : '';
            if ($phone !== '') {
                $phoneMap[$phone][] = $idx;
            } else {
                $gwList[$idx]['phone_status'] = 'not_found';
            }
        }

        if (!empty($phoneMap)) {
            $phones = array_keys($phoneMap);

            // Query helper: batch-resolve phones against $table.$phoneCol, returning
            // [phone => ['cnt'=>int, 'ids'=>string]]. $table/$phoneCol/$extraWhere are
            // fixed literals (never user input); phone values are bound parameters.
            $matchPhones = function (PDO $db, array $phones, $table, $phoneCol, $extraWhere) {
                if (empty($phones)) return [];
                $ph = implode(',', array_fill(0, count($phones), '?'));
                $stmt = $db->prepare(
                    "SELECT $phoneCol AS phone, COUNT(*) AS cnt, GROUP_CONCAT(id ORDER BY id) AS ids
                     FROM $table
                     WHERE $phoneCol IN ($ph) $extraWhere
                     GROUP BY $phoneCol"
                );
                $stmt->execute(array_values($phones));
                $out = [];
                foreach ($stmt->fetchAll() as $m) {
                    $out[$m['phone']] = ['cnt' => intval($m['cnt']), 'ids' => $m['ids']];
                }
                return $out;
            };

            // Pass 1: mkmembers.member_registrations
            $memberMap = $matchPhones($db, $phones, 'mkmembers.member_registrations', 'phone_no', 'AND deleted = 0');

            // Pass 2: phones unmatched above fall back to active GW staff in
            // staff_portal2.users. Any match here is reported as 'found' (myMK Found).
            $unresolved = array_values(array_filter($phones, function ($p) use ($memberMap) {
                return !isset($memberMap[$p]);
            }));
            $staffMap = $matchPhones($db, $unresolved, 'staff_portal2.users', 'mobile_no', "AND status = '1' AND is_gw = '1'");

            foreach ($phoneMap as $phone => $indices) {
                if (isset($memberMap[$phone])) {
                    $ids    = $memberMap[$phone]['ids'];
                    $status = ($memberMap[$phone]['cnt'] === 1) ? 'found' : 'error';
                } elseif (isset($staffMap[$phone])) {
                    $ids    = $staffMap[$phone]['ids'];
                    $status = 'found';
                } else {
                    $ids    = '';
                    $status = 'not_found';
                }
                foreach ($indices as $idx) {
                    $gwList[$idx]['phone_status']     = $status;
                    $gwList[$idx]['phone_member_ids'] = $ids;
                }
            }
        }

        echo json_encode(['gw' => $gwList]);
        exit;
    }

    // --- Delete GW encoding (via port 5001) ---
    if ($action === 'delete_gw_encoding' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = json_decode(file_get_contents('php://input'), true);
        $encodingId = intval($raw['encoding_id'] ?? 0);

        if ($encodingId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
            exit;
        }

        $payload = json_encode(['id' => $encodingId]);
        $ch = curl_init(GW_FACE_SERVICE_BASE . '/delete');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            echo json_encode(['success' => false, 'message' => 'GW face service unavailable: ' . $err]);
            exit;
        }

        $decoded = json_decode($resp, true);
        echo json_encode($decoded ? $decoded : ['success' => false, 'message' => 'Invalid response']);
        exit;
    }

    // --- Toggle GW check-in method (default / face) ---
    if ($action === 'toggle_gw_checkin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = json_decode(file_get_contents('php://input'), true);
        $gwId  = intval($raw['gw_id'] ?? 0);
        $field = isset($raw['field']) ? $raw['field'] : '';
        $value = isset($raw['value']) ? $raw['value'] : '';

        $allowedFields = ['mymk_daily_check_in_default', 'mymk_daily_check_in_face'];
        if ($gwId <= 0 || !in_array($field, $allowedFields) || !in_array($value, ['0', '1'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }

        // Verify GW belongs to an allowed company
        $db = getDB();
        $stmt = $db->prepare("SELECT monthly_assign_gw_id, comp_id, monthly_assign_gw_code AS code FROM evaluation.monthly_assign_gw WHERE monthly_assign_gw_id = :id LIMIT 1");
        $stmt->execute([':id' => $gwId]);
        $gw = $stmt->fetch();

        if (!$gw || !in_array(intval($gw['comp_id']), $allowedCompanyIds)) {
            echo json_encode(['success' => false, 'message' => 'GW not found or not allowed']);
            exit;
        }

        // Use whitelisted field name directly in query (safe — validated above)
        $db->prepare("UPDATE evaluation.monthly_assign_gw SET {$field} = :val WHERE monthly_assign_gw_code = :code")
           ->execute([':val' => $value, ':code' => $gw['code']]);
        echo json_encode(['success' => true]);
        exit;
    }

    // --- Serve GW enrollment photo ---
    if ($action === 'gw_photo') {
        $encId = intval($_GET['enc_id'] ?? 0);
        if ($encId <= 0) { http_response_code(400); exit; }

        $db = getDB();
        $stmt = $db->prepare("SELECT photo_path FROM gw_face_encodings WHERE id = :id AND deleted = '0' LIMIT 1");
        $stmt->execute([':id' => $encId]);
        $row = $stmt->fetch();
        if (!$row || empty($row['photo_path'])) { http_response_code(404); exit; }

        $abs  = realpath(GW_SERVICE_DIR . '/' . $row['photo_path']);
        $base = realpath(GW_SERVICE_DIR);
        if ($abs === false || $base === false || strpos($abs, $base . DIRECTORY_SEPARATOR) !== 0) {
            http_response_code(404); exit;
        }
        if (!is_file($abs)) { http_response_code(404); exit; }

        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($abs));
        header('Cache-Control: private, max-age=300');
        readfile($abs);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// ─── Helper: POST one base64 image to the staff face service ───
// Single definition of the enroll wire call, shared by the manual `enroll` action and
// `encode_profile` (DRY). Returns the decoded service response, or ['_error' => msg].
function callFaceService($base64Image, $user, $sourceNote = null) {
    $payload = [
        'image'            => $base64Image,
        'staff_profile_id' => intval($user['id']),
        'person'           => $user['person'],
        'comp_id'          => (string)$user['comp_id'],
        'mymk_id'          => intval($user['id']),
    ];
    if ($sourceNote !== null) {
        $payload['source_note'] = $sourceNote;
    }

    $ch = curl_init(FACE_SERVICE_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false)  { return ['_error' => 'Service unavailable: ' . $err]; }
    $decoded = json_decode($resp, true);
    if (!$decoded)        { return ['_error' => 'Invalid service response']; }
    return $decoded;
}

// ─── Helper: absolute URL of a staff_profile.profile_pic ───
function profilePicUrl($pic) {
    $segments = array_map('rawurlencode', explode('/', ltrim($pic, '/')));
    return PROFILE_PIC_URL_BASE . implode('/', $segments);
}

// ─── Helper: download a profile photo; null on any failure (fail loud upstream) ───
function fetchProfilePicBytes($pic) {
    if ($pic === null || $pic === '') { return null; }

    $ch = curl_init(profilePicUrl($pic));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code !== 200 || $body === '') { return null; }
    return $body;
}

// ─── Staff listing + profile-photo status (single source of truth) ───
// Used by get_staff_with_status (whole company) and encode_profile (one staff, $userId),
// so the badge the admin sees and the work the server does are computed by the same rule.
//
// profile_pic link priority mirrors checkFaceEncodings:
//   1. staff_profile.person + comid  ==  users.person + comp_id
//   2. fallback: staff_profile.mymkid == users.id
function fetchStaffWithStatus($compId, $userId = 0) {
    $db = getDB();

    $sql = "
        SELECT u.id, u.person, u.comp_id, u.fullname,
               COALESCE(NULLIF(TRIM(u.department), ''), 'No Department') AS department,
               u.position,
               COUNT(sfe.id) AS encoding_count,
               COALESCE(
                   (SELECT p.profile_pic FROM " . PROFILE_DB . ".staff_profile p
                     WHERE p.person = u.person AND p.comid = u.comp_id
                       AND p.deleted_at IS NULL
                       AND p.profile_pic IS NOT NULL AND p.profile_pic <> ''
                     LIMIT 1),
                   (SELECT p.profile_pic FROM " . PROFILE_DB . ".staff_profile p
                     WHERE p.mymkid = u.id
                       AND p.deleted_at IS NULL
                       AND p.profile_pic IS NOT NULL AND p.profile_pic <> ''
                     LIMIT 1)
               ) AS profile_pic,
               (SELECT e.profile_pic FROM staff_face_encodings e
                 WHERE ((e.person = u.person AND e.comp_id = u.comp_id) OR e.mymk_id = u.id)
                   AND e.deleted = '0'
                   AND e.profile_pic IS NOT NULL AND e.profile_pic <> ''
                 ORDER BY e.id DESC LIMIT 1) AS encoded_profile_pic
        FROM staff_portal2.users u
        LEFT JOIN staff_face_encodings sfe
               ON sfe.person = u.person
              AND sfe.comp_id = u.comp_id
              AND sfe.deleted = '0'
        WHERE u.comp_id = :comp_id AND u.status = 1 AND u.deleted = 0
    ";
    $params = [':comp_id' => $compId];

    if ($userId > 0) {
        $sql .= " AND u.id = :user_id ";
        $params[':user_id'] = $userId;
    }

    $sql .= "
        GROUP BY u.id
        ORDER BY (u.department IS NULL OR TRIM(u.department) = ''), u.department, u.fullname
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r['profile_status'] = profileStatus($r['profile_pic'], $r['encoded_profile_pic']);
    }
    unset($r);

    return $rows;
}

// ─── Derive the profile-photo status for one staff row ───
//   no_photo -> nothing uploaded, nothing to convert
//   pending  -> has a profile photo, never converted
//   outdated -> converted, but they have since replaced the photo (encoding is of an old face)
//   encoded  -> converted from exactly the photo currently on their profile
function profileStatus($profilePic, $encodedProfilePic) {
    if ($profilePic === null || $profilePic === '') {
        return 'no_photo';
    }
    if ($encodedProfilePic !== null && $encodedProfilePic !== '') {
        return ($encodedProfilePic === $profilePic) ? 'encoded' : 'outdated';
    }
    return 'pending';
}

// ─── Helper: save enrollment photo to disk ───
function saveEnrollmentPhoto($base64, $userId, $encodingId) {
    $data = base64_decode($base64);
    if ($data === false) return null;

    $dateFolder = date('Y/m/d');
    $dir = PHOTO_BASE_DIR . $dateFolder . '/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $name = 'enroll_' . $userId . '_' . $encodingId . '_' . time() . '.jpg';
    if (file_put_contents($dir . $name, $data) !== false) {
        return ['path' => $dateFolder . '/' . $name, 'name' => $name];
    }
    return null;
}

// ─── Page render ───
$admin = authenticateAdmin();
if (!$admin) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Access Denied</title>
    <style>body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;background:#fafafa;color:#333}
    .box{text-align:center;padding:40px}.box h1{font-size:64px;margin:0}.box p{color:#757575;margin-top:12px}</style></head>
    <body><div class="box"><h1>🔒</h1><p>No Access.</p></div></body></html>';
    exit;
}

$companies = getAdminCompanies($admin);
$companiesJson = json_encode($companies);
$adminPerson = htmlspecialchars($admin['person']);
$adminCompId = intval($admin['comp_id']);
$baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
$queryBase = "person=" . urlencode($_GET['person']) . "&comp_id=" . urlencode($_GET['comp_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<title>HR Face Registration</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.4/jquery.min.js"></script>
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<style>
/* ── Reset & Base ── */
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
html{height:100%;-webkit-text-size-adjust:100%}
body{
    font-family:'Outfit',sans-serif;
    background:#F4F6FB;
    color:#0F1729;
    min-height:100%;
    min-height:100dvh;
    padding-bottom:calc(72px + env(safe-area-inset-bottom));
    overscroll-behavior:none;
}

/* ── Design Tokens ── */
:root{
    --brand:#2547F5;
    --brand-dark:#1A35CC;
    --brand-light:#EEF1FE;
    --surface:#FFFFFF;
    --surface2:#F4F6FB;
    --surface3:#E8ECF6;
    --border:#DDE2F0;
    --text:#0F1729;
    --text2:#5A6478;
    --text3:#9AA0B4;
    --green:#16A34A;
    --green-bg:#DCFCE7;
    --red:#DC2626;
    --red-bg:#FEE2E2;
    --amber:#D97706;
    --amber-bg:#FEF3C7;
    --radius:14px;
    --radius-sm:10px;
    --radius-xs:7px;
    --shadow:0 1px 3px rgba(15,23,41,.07),0 1px 2px rgba(15,23,41,.04);
    --shadow-md:0 4px 12px rgba(15,23,41,.10),0 2px 4px rgba(15,23,41,.06);
    --shadow-lg:0 16px 40px rgba(15,23,41,.14);
}

/* ── App Header ── */
.app-header{
    position:sticky;top:0;z-index:200;
    background:var(--surface);
    border-bottom:1px solid var(--border);
    padding:0 16px;
    padding-top:env(safe-area-inset-top);
    display:flex;align-items:center;justify-content:space-between;
    height:calc(52px + env(safe-area-inset-top));
}
.app-header-title{font-size:17px;font-weight:700;letter-spacing:-.3px;color:var(--text)}
.app-header-badge{
    font-size:12px;font-weight:600;
    background:var(--brand-light);color:var(--brand);
    padding:4px 10px;border-radius:20px;
    max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}

/* ── Bottom Navigation ── */
.bottom-nav{
    position:fixed;bottom:0;left:0;right:0;z-index:200;
    background:var(--surface);
    border-top:1px solid var(--border);
    display:flex;
    padding-bottom:env(safe-area-inset-bottom);
    box-shadow:0 -2px 12px rgba(15,23,41,.06);
}
.bottom-nav-btn{
    flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
    gap:3px;padding:10px 0;border:none;background:none;cursor:pointer;
    color:var(--text3);font-family:'Outfit',sans-serif;font-size:11px;font-weight:600;
    transition:color .15s;
    -webkit-tap-highlight-color:transparent;
    position:relative;
}
.bottom-nav-btn .nav-icon{font-size:22px;line-height:1;transition:transform .15s}
.bottom-nav-btn.active{color:var(--brand)}
.bottom-nav-btn.active .nav-icon{transform:scale(1.1)}
.bottom-nav-btn::after{
    content:'';position:absolute;top:0;left:50%;transform:translateX(-50%);
    width:0;height:3px;background:var(--brand);border-radius:0 0 3px 3px;
    transition:width .2s;
}
.bottom-nav-btn.active::after{width:32px}

/* ── Tab Panels ── */
.tab-panel{display:none}
.tab-panel.active{display:block}

/* ── Screen Container ── */
.screen{padding:16px;max-width:600px;margin:0 auto}

/* ── Section Card ── */
.card{
    background:var(--surface);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    overflow:hidden;
    margin-bottom:12px;
}
.card-header{
    padding:14px 16px 12px;
    border-bottom:1px solid var(--border);
    display:flex;align-items:center;gap:10px;
}
.card-header-icon{
    width:34px;height:34px;border-radius:9px;
    background:var(--brand-light);color:var(--brand);
    display:flex;align-items:center;justify-content:center;
    font-size:17px;flex-shrink:0;
}
.card-header-text{}
.card-header-title{font-size:14px;font-weight:700;color:var(--text);letter-spacing:-.2px}
.card-header-sub{font-size:12px;color:var(--text3);margin-top:1px}
.card-body{padding:14px 16px}

/* ── Step Wizard ── */
.step-bar{display:flex;align-items:center;gap:0;padding:14px 16px;background:var(--surface);border-bottom:1px solid var(--border);position:sticky;top:calc(52px + env(safe-area-inset-top));z-index:100}
.step-item{display:flex;flex-direction:column;align-items:center;gap:3px;flex:1;position:relative}
.step-item:not(:last-child)::after{
    content:'';position:absolute;top:14px;left:calc(50% + 16px);right:calc(-50% + 16px);
    height:2px;background:var(--border);z-index:0;
}
.step-item.done:not(:last-child)::after{background:var(--brand)}
.step-dot{
    width:28px;height:28px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-size:12px;font-weight:700;
    background:var(--border);color:var(--text3);
    z-index:1;position:relative;transition:.2s;
    flex-shrink:0;
}
.step-item.active .step-dot{background:var(--brand);color:#fff;box-shadow:0 0 0 4px rgba(37,71,245,.15)}
.step-item.done .step-dot{background:var(--brand);color:#fff}
.step-label{font-size:10px;font-weight:600;color:var(--text3);letter-spacing:.3px;text-transform:uppercase}
.step-item.active .step-label,.step-item.done .step-label{color:var(--brand)}

/* ── Field Row ── */
.field{margin-bottom:14px}
.field:last-child{margin-bottom:0}
.field label{
    display:block;font-size:12px;font-weight:700;
    color:var(--text2);text-transform:uppercase;letter-spacing:.4px;
    margin-bottom:6px;
}
.field label .req{color:var(--red)}

/* ── Select ── */
.sel-wrapper{position:relative}
.sel-wrapper::after{
    content:'▾';position:absolute;right:14px;top:50%;transform:translateY(-50%);
    color:var(--text3);font-size:14px;pointer-events:none;
}
select.native-sel{
    width:100%;height:50px;padding:0 38px 0 14px;
    border:1.5px solid var(--border);border-radius:var(--radius-sm);
    font-family:'Outfit',sans-serif;font-size:15px;font-weight:500;color:var(--text);
    background:var(--surface);outline:none;cursor:pointer;
    appearance:none;-webkit-appearance:none;
    transition:border-color .15s,box-shadow .15s;
}
select.native-sel:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(37,71,245,.1)}
select.native-sel:disabled{color:var(--text3);background:var(--surface2)}

/* ── Photo Capture Area ── */
.photo-actions{display:flex;gap:10px;margin-bottom:12px}
.photo-action-btn{
    flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
    gap:6px;padding:18px 10px;
    border:1.5px dashed var(--border);border-radius:var(--radius);
    background:var(--surface2);cursor:pointer;
    font-family:'Outfit',sans-serif;font-size:13px;font-weight:600;color:var(--text2);
    transition:.15s;position:relative;overflow:hidden;
}
.photo-action-btn input{position:absolute;inset:0;opacity:0;cursor:pointer}
.photo-action-btn:active{background:var(--brand-light);border-color:var(--brand)}
.photo-action-btn .btn-icon{font-size:28px}

.photo-counter{
    display:flex;align-items:center;justify-content:space-between;
    margin-bottom:10px;
}
.photo-counter-label{font-size:13px;font-weight:600;color:var(--text2)}
.photo-counter-pips{display:flex;gap:5px}
.photo-pip{width:28px;height:5px;border-radius:3px;background:var(--border);transition:.2s}
.photo-pip.filled{background:var(--brand)}

.preview-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}
.preview-item{
    position:relative;aspect-ratio:1;border-radius:var(--radius-xs);overflow:hidden;
    background:var(--surface3);
}
.preview-item img{width:100%;height:100%;object-fit:cover}
.preview-item .remove{
    position:absolute;top:3px;right:3px;
    width:22px;height:22px;border-radius:50%;
    background:rgba(0,0,0,.65);color:#fff;border:none;cursor:pointer;
    font-size:11px;display:flex;align-items:center;justify-content:center;
    font-family:'Outfit',sans-serif;
}

/* ── Enroll Type Switcher ── */
.type-switch{display:flex;gap:0;border-radius:var(--radius-sm);background:var(--surface3);padding:3px;margin-bottom:14px}
.type-switch-btn{
    flex:1;padding:9px 8px;border:none;border-radius:9px;
    font-family:'Outfit',sans-serif;font-size:13px;font-weight:600;
    background:transparent;color:var(--text3);cursor:pointer;transition:.15s;
}
.type-switch-btn.active{background:var(--surface);color:var(--brand);box-shadow:var(--shadow)}

/* ── Primary Button ── */
.btn-primary{
    display:flex;align-items:center;justify-content:center;gap:8px;
    width:100%;padding:15px;border:none;border-radius:var(--radius-sm);
    background:var(--brand);color:#fff;
    font-family:'Outfit',sans-serif;font-size:16px;font-weight:700;letter-spacing:-.2px;
    cursor:pointer;transition:.15s;
}
.btn-primary:active{background:var(--brand-dark);transform:scale(.98)}
.btn-primary:disabled{background:#B8C0D4;cursor:not-allowed;transform:none}
.btn-primary .spinner{
    width:18px;height:18px;border:2.5px solid rgba(255,255,255,.3);
    border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite;
    flex-shrink:0;
}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Danger Button ── */
.btn-danger{
    padding:7px 14px;border:none;border-radius:var(--radius-xs);
    background:var(--red-bg);color:var(--red);
    font-family:'Outfit',sans-serif;font-size:13px;font-weight:700;
    cursor:pointer;transition:.15s;flex-shrink:0;
}
.btn-danger:active{background:var(--red);color:#fff}

/* ── Tips Card ── */
.tips-card{
    border-radius:var(--radius);background:var(--brand-light);
    padding:12px 14px;margin-bottom:12px;
}
.tips-card p{font-size:13px;color:var(--brand-dark);line-height:1.6}

/* ── Toast ── */
.toast-container{position:fixed;bottom:calc(80px + env(safe-area-inset-bottom));left:50%;transform:translateX(-50%);z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none;min-width:280px;max-width:calc(100vw - 32px)}
.toast{
    display:flex;align-items:center;gap:10px;
    padding:12px 16px;border-radius:12px;
    background:#1A2035;color:#fff;
    font-size:14px;font-weight:500;font-family:'Outfit',sans-serif;
    box-shadow:var(--shadow-lg);
    animation:toastIn .25s cubic-bezier(.34,1.56,.64,1) forwards;
    pointer-events:auto;
}
.toast.success .toast-icon::after{content:'✓';display:block;width:22px;height:22px;border-radius:50%;background:var(--green);color:#fff;text-align:center;line-height:22px;font-size:13px;font-weight:800}
.toast.error .toast-icon::after{content:'✕';display:block;width:22px;height:22px;border-radius:50%;background:var(--red);color:#fff;text-align:center;line-height:22px;font-size:13px;font-weight:800}
.toast.removing{animation:toastOut .2s ease forwards}
@keyframes toastIn{from{opacity:0;transform:translateY(16px) scale(.92)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes toastOut{from{opacity:1;transform:translateY(0) scale(1)}to{opacity:0;transform:translateY(8px) scale(.95)}}

/* ── Search Bar ── */
.search-bar{
    display:flex;align-items:center;gap:8px;
    padding:0 14px;
    background:var(--surface);
    border:1.5px solid var(--border);border-radius:var(--radius-sm);
    height:44px;
}
.search-bar input{flex:1;border:none;outline:none;font-family:'Outfit',sans-serif;font-size:15px;color:var(--text);background:transparent}
.search-bar input::placeholder{color:var(--text3)}
.search-bar .search-icon{color:var(--text3);font-size:16px;flex-shrink:0}

/* ── Dept Pills ── */
.dept-pills{display:flex;gap:6px;overflow-x:auto;padding-bottom:4px;-webkit-overflow-scrolling:touch;scrollbar-width:none}
.dept-pills::-webkit-scrollbar{display:none}

/* ── Manage Filter Selects ── */
#nameSearchWrap .select2-container--default .select2-selection--single,
#deptSelectWrap .select2-container--default .select2-selection--single,
#photoFilterWrap .select2-container--default .select2-selection--single{height:42px}
#nameSearchWrap .select2-container--default .select2-selection--single .select2-selection__rendered,
#deptSelectWrap .select2-container--default .select2-selection--single .select2-selection__rendered,
#photoFilterWrap .select2-container--default .select2-selection--single .select2-selection__rendered{line-height:42px;font-size:13px}
#nameSearchWrap .select2-container--default .select2-selection--single .select2-selection__arrow,
#deptSelectWrap .select2-container--default .select2-selection--single .select2-selection__arrow,
#photoFilterWrap .select2-container--default .select2-selection--single .select2-selection__arrow{height:42px}

/* ── Select2 Clear (×) Button ── */
.select2-container--default .select2-selection--single .select2-selection__clear{
    cursor:pointer;float:right;
    font-weight:400;font-size:14px;
    color:var(--text3);
    margin-right:2px;padding:0 4px;
    line-height:inherit;
    background:none!important;border:none!important;
    outline:none!important;box-shadow:none!important;
    -webkit-tap-highlight-color:transparent;
    -webkit-appearance:none;appearance:none;
}
.select2-container--default .select2-selection--single .select2-selection__clear:hover{color:var(--red)}
.select2-container--default .select2-selection--single .select2-selection__clear:focus{
    outline:none!important;box-shadow:none!important;
}
.dept-pill{
    flex-shrink:0;padding:6px 14px;border-radius:20px;
    font-family:'Outfit',sans-serif;font-size:12px;font-weight:700;
    background:var(--surface);border:1.5px solid var(--border);color:var(--text2);
    cursor:pointer;white-space:nowrap;transition:.15s;
}
.dept-pill.active{background:var(--brand);border-color:var(--brand);color:#fff}

/* ── Staff List Row ── */
.staff-row{
    display:flex;align-items:center;gap:12px;
    padding:12px 16px;border-bottom:1px solid var(--border);
    cursor:pointer;transition:background .12s;
    -webkit-tap-highlight-color:transparent;
}
.staff-row:last-child{border-bottom:none}
.staff-row:active{background:var(--surface2)}
.staff-row.expanded{background:var(--brand-light)}
.s-avatar{
    width:40px;height:40px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-size:16px;font-weight:800;color:#fff;flex-shrink:0;
    background:var(--brand);
}
.s-avatar.gw{background:#E67E22}
.s-info{flex:1;min-width:0}
.s-name{font-size:14px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.s-sub{font-size:12px;color:var(--text3);margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.s-right{display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0}

/* ── Enroll Badge ── */
.badge-pill{font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap}
.badge-pill.enrolled{background:var(--green-bg);color:var(--green)}
.badge-pill.none{background:var(--surface3);color:var(--text3)}
.badge-pill.nric-found{background:var(--green-bg);color:var(--green);font-size:10px;padding:2px 7px;margin-left:4px;vertical-align:middle}
.badge-pill.nric-none{background:var(--surface3);color:var(--text3);font-size:10px;padding:2px 7px;margin-left:4px;vertical-align:middle}
.badge-pill.nric-error{background:var(--red-bg);color:var(--red);font-size:10px;padding:2px 7px;margin-left:4px;vertical-align:middle}

/* ── Profile-photo status (staff only; GWs have no staff_profile row) ── */
.badge-pill.p-ok{background:var(--green-bg);color:var(--green);font-size:10px;padding:2px 7px}
.badge-pill.p-pending{background:#FEF3C7;color:#92400E;font-size:10px;padding:2px 7px}
.badge-pill.p-outdated{background:#FFEDD5;color:#9A3412;font-size:10px;padding:2px 7px}
.badge-pill.p-nophoto{background:var(--surface3);color:var(--text3);font-size:10px;padding:2px 7px}
.btn-convert{
    background:#2547F5;color:#fff;border:0;border-radius:8px;
    font-size:11px;font-weight:700;padding:4px 10px;cursor:pointer;white-space:nowrap;
}
.btn-convert:disabled{background:#A7B0C4;cursor:default}

/* ── Batch convert bar ── */
.batch-bar{
    display:flex;align-items:center;gap:10px;background:#EEF1FE;border:1px solid #D5DDFB;
    border-radius:12px;padding:10px 12px;margin-bottom:10px;
}
.batch-text{flex:1;font-size:12px;font-weight:600;color:#2547F5;line-height:1.35}
.btn-batch{
    background:#2547F5;color:#fff;border:0;border-radius:9px;
    font-size:12px;font-weight:700;padding:9px 14px;cursor:pointer;white-space:nowrap;
}
.btn-batch:disabled{background:#A7B0C4;cursor:default}
.batch-prog{height:6px;background:#D5DDFB;border-radius:99px;overflow:hidden;margin-top:8px}
.batch-fill{height:100%;width:0;background:#2547F5;border-radius:99px;transition:width .2s}
.s-nric{font-size:11px;color:var(--text3);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* ── Chevron ── */
.chevron{font-size:12px;color:var(--text3);transition:transform .2s}
.staff-row.expanded .chevron{transform:rotate(90deg)}

/* ── Dept Section Header ── */
.dept-header{
    padding:10px 16px 6px;
    font-size:11px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;
    color:var(--text3);background:var(--surface2);
    border-bottom:1px solid var(--border);
}

/* ── Inline Enrolled Panel ── */
.enrolled-panel{
    background:var(--surface2);border-top:1px solid var(--border);
    overflow:hidden;max-height:0;transition:max-height .3s ease;
}
.enrolled-panel.open{max-height:9999px}
.enrolled-panel-inner{padding:12px 16px 14px}

.enc-row{
    display:flex;align-items:center;gap:12px;
    padding:10px;background:var(--surface);
    border-radius:var(--radius-sm);margin-bottom:8px;
    box-shadow:var(--shadow);
}
.enc-row:last-child{margin-bottom:0}
.enc-row img,.enc-row .enc-no-photo{
    width:60px;height:60px;border-radius:8px;
    object-fit:cover;flex-shrink:0;background:var(--surface3);
    display:flex;align-items:center;justify-content:center;
    color:var(--text3);font-size:24px;
}
.enc-info{flex:1;min-width:0}
.enc-date{font-size:13px;font-weight:600;color:var(--text)}
.enc-id{font-size:11px;color:var(--text3);margin-top:2px}

/* ── GW Toggle Row ── */
.gw-toggle-area{padding:10px 16px 12px;background:var(--surface2);border-top:1px solid var(--border)}
.toggle-row{display:flex;align-items:center;justify-content:space-between;padding:8px 0}
.toggle-row .toggle-label{font-size:13px;font-weight:600;color:var(--text)}
.toggle-switch{position:relative;width:46px;height:26px;flex-shrink:0}
.toggle-switch input{opacity:0;width:0;height:0}
.toggle-switch .slider{position:absolute;inset:0;background:#CBD1E0;border-radius:26px;cursor:pointer;transition:.2s}
.toggle-switch .slider::before{content:'';position:absolute;width:20px;height:20px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.toggle-switch input:checked+.slider{background:var(--green)}
.toggle-switch input:checked+.slider::before{transform:translateX(20px)}

/* ── Empty State ── */
.empty-state{text-align:center;padding:32px 16px;color:var(--text3)}
.empty-state .e-icon{font-size:44px;margin-bottom:8px}
.empty-state p{font-size:14px;font-weight:500}

/* ── Loading Spinner (centered) ── */
.loading-center{display:flex;align-items:center;justify-content:center;padding:32px;color:var(--text3);gap:10px;font-size:14px;font-weight:500}
.loading-center .spinner{width:22px;height:22px;border:2.5px solid var(--border);border-top-color:var(--brand);border-radius:50%;animation:spin .6s linear infinite}

/* ── GW Divider ── */
.gw-divider{display:flex;align-items:center;gap:10px;padding:16px 16px 0}
.gw-divider-line{flex:1;height:1px;background:var(--border)}
.gw-divider-label{font-size:11px;font-weight:800;color:var(--text3);letter-spacing:.5px;text-transform:uppercase;white-space:nowrap}

/* ── Manage Add-Face inline form ── */
.add-face-section{border-top:1px solid var(--border);margin-top:12px;padding-top:12px}
.add-face-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.add-face-title{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text2)}
.add-face-actions{display:flex;gap:8px;margin-bottom:10px}
.add-face-btn{
    flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
    gap:4px;padding:12px 8px;
    border:1.5px dashed var(--border);border-radius:var(--radius-sm);
    background:var(--surface);cursor:pointer;
    font-family:'Outfit',sans-serif;font-size:12px;font-weight:600;color:var(--text2);
    position:relative;overflow:hidden;transition:.15s;
}
.add-face-btn input{position:absolute;inset:0;opacity:0;cursor:pointer}
.add-face-btn:active{background:var(--brand-light);border-color:var(--brand)}
.add-face-btn .af-icon{font-size:22px}
.manage-preview-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:6px;margin-bottom:10px}
.manage-preview-grid:empty{display:none}
.btn-submit-add{
    display:flex;align-items:center;justify-content:center;gap:6px;
    width:100%;padding:11px;border:none;border-radius:var(--radius-sm);
    background:var(--brand);color:#fff;
    font-family:'Outfit',sans-serif;font-size:14px;font-weight:700;
    cursor:pointer;transition:.15s;
}
.btn-submit-add:disabled{background:#B8C0D4;cursor:not-allowed}
.btn-submit-add:active{background:var(--brand-dark)}

/* ── Camera Modal ── */
.camera-modal{
    position:fixed;inset:0;z-index:9999;
    background:#000;display:flex;flex-direction:column;
    overscroll-behavior:none;
}
.camera-header{
    display:flex;align-items:center;justify-content:space-between;
    padding:14px 16px;padding-top:max(14px,env(safe-area-inset-top));
    position:absolute;top:0;left:0;right:0;z-index:10;
    background:linear-gradient(to bottom,rgba(0,0,0,.7) 0%,transparent 100%);
}
.camera-header-title{font-size:16px;font-weight:700;color:#fff}
.camera-close-btn{
    width:36px;height:36px;border-radius:50%;border:none;
    background:rgba(255,255,255,.15);color:#fff;cursor:pointer;
    font-size:16px;display:flex;align-items:center;justify-content:center;
    backdrop-filter:blur(8px);
}
.camera-body{flex:1;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:center}
.camera-body video{
    width:100%;height:100%;object-fit:cover;
}
/* Face guide overlay — centers inside visible area (accounts for fixed header+footer) */
.face-guide-overlay{
    position:absolute;inset:0;
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    padding-top:68px;padding-bottom:118px;
    pointer-events:none;gap:14px;
}
.face-guide-oval{
    width:min(60vw,225px);height:min(78vw,295px);
    border:2.5px solid rgba(255,255,255,.85);border-radius:50%;
    box-shadow:0 0 0 9999px rgba(0,0,0,.52);
    animation:pulseGuide 2.5s ease-in-out infinite;
    flex-shrink:0;
}
@keyframes pulseGuide{
    0%,100%{border-color:rgba(255,255,255,.55);box-shadow:0 0 0 9999px rgba(0,0,0,.52)}
    50%{border-color:#fff;box-shadow:0 0 0 9999px rgba(0,0,0,.52),0 0 24px rgba(255,255,255,.15)}
}
.camera-guide-text{
    font-size:13px;font-weight:600;color:rgba(255,255,255,.9);
    background:rgba(0,0,0,.3);padding:5px 15px;border-radius:20px;
    backdrop-filter:blur(4px);text-align:center;letter-spacing:.2px;
}
.camera-flash{position:absolute;inset:0;background:#fff;opacity:0;pointer-events:none;transition:opacity .1s}
.camera-footer{
    display:flex;align-items:center;justify-content:center;
    gap:32px;
    padding:20px 16px;padding-bottom:max(24px,env(safe-area-inset-bottom));
    background:linear-gradient(to top,rgba(0,0,0,.7) 0%,transparent 100%);
    position:absolute;bottom:0;left:0;right:0;
}
.camera-photo-count{
    width:44px;height:44px;border-radius:50%;
    background:rgba(255,255,255,.15);color:#fff;
    display:flex;align-items:center;justify-content:center;
    font-size:16px;font-weight:800;backdrop-filter:blur(8px);
}
.camera-shutter{
    width:72px;height:72px;border-radius:50%;
    border:4px solid #fff;background:transparent;cursor:pointer;
    position:relative;transition:.1s;
    -webkit-tap-highlight-color:transparent;
}
.camera-shutter:active{transform:scale(.88)}
.camera-shutter::after{content:'';position:absolute;inset:5px;border-radius:50%;background:#fff}
.camera-switch{
    width:44px;height:44px;border-radius:50%;border:none;
    background:rgba(255,255,255,.15);color:#fff;font-size:20px;
    cursor:pointer;display:flex;align-items:center;justify-content:center;
    backdrop-filter:blur(8px);
}
canvas#cameraCanvas{display:none}

/* ── Select2 Overrides ── */
.select2-container{width:100%!important}
.select2-container--default .select2-selection--single{
    height:50px;
    border:1.5px solid var(--border);
    border-radius:var(--radius-sm);
    background:var(--surface);
    display:flex;align-items:center;
    transition:border-color .15s,box-shadow .15s;
}
.select2-container--default.select2-container--open .select2-selection--single,
.select2-container--default.select2-container--focus .select2-selection--single{
    border-color:var(--brand);
    box-shadow:0 0 0 3px rgba(37,71,245,.10);
    outline:none;
}
.select2-container--default .select2-selection--single .select2-selection__rendered{
    font-family:'Outfit',sans-serif;font-size:15px;font-weight:500;
    color:var(--text);line-height:50px;padding:0 38px 0 14px;
}
.select2-container--default .select2-selection--single .select2-selection__placeholder{
    color:var(--text3);
}
.select2-container--default .select2-selection--single .select2-selection__arrow{
    height:50px;right:10px;
}
.select2-container--default .select2-selection--single .select2-selection__arrow b{
    border-color:var(--text3) transparent transparent transparent;
}
.select2-container--default.select2-container--open .select2-selection--single .select2-selection__arrow b{
    border-color:transparent transparent var(--text3) transparent;
}
.select2-dropdown{
    border:1.5px solid var(--border);
    border-radius:var(--radius-sm);
    box-shadow:var(--shadow-md);
    background:var(--surface);
    overflow:hidden;
    font-family:'Outfit',sans-serif;
    z-index:9999;
}
.select2-container--open .select2-dropdown--below{margin-top:4px}
.select2-container--open .select2-dropdown--above{margin-bottom:4px}
.select2-search--dropdown{padding:8px 10px;border-bottom:1px solid var(--border)}
.select2-search--dropdown .select2-search__field{
    border:1.5px solid var(--border);
    border-radius:var(--radius-xs);
    padding:8px 12px;
    font-family:'Outfit',sans-serif;font-size:14px;
    color:var(--text);width:100%;outline:none;
    transition:border-color .15s;
}
.select2-search--dropdown .select2-search__field:focus{
    border-color:var(--brand);
}
.select2-results__options{max-height:260px;overflow-y:auto}
.select2-results__option{
    font-family:'Outfit',sans-serif;font-size:14px;font-weight:500;
    color:var(--text);padding:11px 14px;cursor:pointer;
}
.select2-results__option--highlighted{
    background:var(--brand)!important;color:#fff!important;
}
.select2-results__option[aria-selected=true]{
    background:var(--brand-light);color:var(--brand);font-weight:700;
}
.select2-results__option[aria-disabled=true]{color:var(--text3)}

/* ── Responsive ── */
@media(min-width:600px){
    .screen{padding:20px}
    .preview-grid{grid-template-columns:repeat(5,1fr)}
}
@media(max-width:360px){
    .preview-grid{grid-template-columns:repeat(4,1fr)}
}

/* ── Header Dropdown ── */
.header-menu{position:relative;display:inline-block}
.header-menu-badge{
    display:flex;align-items:center;gap:6px;
    font-size:12px;font-weight:600;
    background:var(--brand-light);color:var(--brand);
    padding:4px 10px;border-radius:20px;
    cursor:pointer;user-select:none;
    border:1.5px solid transparent;
    transition:border-color .15s;
}
.header-menu-badge:hover{border-color:var(--brand)}
.header-menu-badge .chevron{font-size:9px;transition:transform .2s}
.header-menu.open .header-menu-badge .chevron{transform:rotate(180deg)}
.header-dropdown{
    display:none;position:absolute;top:calc(100% + 8px);right:0;
    background:var(--surface);border:1.5px solid var(--border);
    border-radius:var(--radius-sm);box-shadow:var(--shadow-md);
    min-width:180px;z-index:9999;overflow:hidden;
}
.header-menu.open .header-dropdown{display:block}
.header-dropdown a{
    display:flex;align-items:center;gap:10px;
    padding:11px 16px;font-size:13px;font-weight:500;
    color:var(--text);text-decoration:none;
    transition:background .15s;
}
.header-dropdown a:hover{background:var(--brand-light);color:var(--brand)}
.header-dropdown-divider{height:1px;background:var(--border)}
</style>
</head>
<body>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<!-- App Header -->
<div class="app-header">
    <span class="app-header-title">HR Face Registration</span>
    <div class="header-menu" id="headerMenu">
        <div class="header-menu-badge" onclick="document.getElementById('headerMenu').classList.toggle('open')">
            <span><?php echo $adminPerson; ?></span>
            <span class="chevron">▼</span>
        </div>
        <div class="header-dropdown">
            <div class="header-dropdown-divider"></div>
            <a href="https://globportal.com/accounts/mkPortal/site_checkin/siteCheckInReport.php">
                ← Back to MK Portal
            </a>
            <!-- <div class="header-dropdown-divider"></div>
            <a href="https://globportal.com/accounts/mkPortal/weeklyDailyEntry.php">
                ← Back to Glob Portal
            </a> -->
        </div>
    </div>
</div>

<!-- ==================== ENROLL TAB ==================== -->
<div id="tab-enroll" class="tab-panel active">

    <!-- Step Bar -->
    <div class="step-bar" id="stepBar">
        <div class="step-item active" id="step-1">
            <div class="step-dot">1</div>
            <span class="step-label">Company</span>
        </div>
        <div class="step-item" id="step-2">
            <div class="step-dot">2</div>
            <span class="step-label">Person</span>
        </div>
        <div class="step-item" id="step-3">
            <div class="step-dot">3</div>
            <span class="step-label">Photos</span>
        </div>
        <div class="step-item" id="step-4">
            <div class="step-dot">4</div>
            <span class="step-label">Submit</span>
        </div>
    </div>

    <div class="screen">

        <!-- Step 1: Company -->
        <div id="enrollStep1">
            <div class="card">
                <div class="card-header">
                    <div class="card-header-icon">🏢</div>
                    <div class="card-header-text">
                        <div class="card-header-title">Select Company</div>
                        <div class="card-header-sub">Choose the employee's company</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="field">
                        <label>Company <span class="req">*</span></label>
                        <div class="sel-wrapper">
                            <select id="companySelect" class="native-sel" onchange="onEnrollCompanyChange(this.value)">
                                <option value="">Select company…</option>
                                <?php foreach ($companies as $c): ?>
                                <option value="<?php echo intval($c['id']); ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <button class="btn-primary" id="step1Next" disabled onclick="goToStep(2)">Continue →</button>
                </div>
            </div>
        </div>

        <!-- Step 2: Person -->
        <div id="enrollStep2" style="display:none">
            <div class="card">
                <div class="card-header">
                    <div class="card-header-icon">👤</div>
                    <div class="card-header-text">
                        <div class="card-header-title">Select Person</div>
                        <div class="card-header-sub" id="step2CompName">—</div>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Type switcher (only when GW available) -->
                    <div class="type-switch" id="enrollTypeTabs" style="display:none">
                        <button class="type-switch-btn active" onclick="setEnrollType('staff')">👤 Staff</button>
                        <button class="type-switch-btn" onclick="setEnrollType('gw')">🔧 General Worker</button>
                    </div>

                    <div class="field" id="staffGroup">
                        <label>Staff Member <span class="req">*</span></label>
                        <div class="sel-wrapper">
                            <select id="staffSelect" class="native-sel">
                                <option value="">Select staff member…</option>
                            </select>
                        </div>
                    </div>

                    <div class="field" id="gwGroup" style="display:none">
                        <label>General Worker <span class="req">*</span></label>
                        <div class="sel-wrapper">
                            <select id="gwSelect" class="native-sel">
                                <option value="">Select general worker…</option>
                            </select>
                        </div>
                    </div>

                    <div style="display:flex;gap:10px">
                        <button class="btn-primary" style="background:var(--surface3);color:var(--text2)" onclick="goToStep(1)">← Back</button>
                        <button class="btn-primary" id="step2Next" disabled onclick="goToStep(3)">Continue →</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 3: Photos -->
        <div id="enrollStep3" style="display:none">
            <div class="card">
                <div class="card-header">
                    <div class="card-header-icon">📷</div>
                    <div class="card-header-text">
                        <div class="card-header-title">Capture Face Photos</div>
                        <div class="card-header-sub" id="step3PersonName">—</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="photo-counter">
                        <span class="photo-counter-label" id="photoCountLabel">0 / 5 photos</span>
                        <div class="photo-counter-pips" id="photoPips">
                            <div class="photo-pip" id="pip0"></div>
                            <div class="photo-pip" id="pip1"></div>
                            <div class="photo-pip" id="pip2"></div>
                            <div class="photo-pip" id="pip3"></div>
                            <div class="photo-pip" id="pip4"></div>
                        </div>
                    </div>

                    <div class="photo-actions">
                        <div class="photo-action-btn" onclick="openCamera()">
                            <span class="btn-icon">📸</span>
                            <span>Camera</span>
                        </div>
                        <div class="photo-action-btn">
                            <input type="file" id="photoInput" accept="image/*" multiple onchange="handleFiles(this.files);this.value=''">
                            <span class="btn-icon">🖼️</span>
                            <span>Upload</span>
                        </div>
                    </div>

                    <div class="preview-grid" id="previewGrid"></div>

                    <div style="display:flex;gap:10px;margin-top:14px">
                        <button class="btn-primary" style="background:var(--surface3);color:var(--text2)" onclick="goToStep(2)">← Back</button>
                        <button class="btn-primary" id="step3Next" disabled onclick="goToStep(4)">Review →</button>
                    </div>
                </div>
            </div>

            <div class="tips-card">
                <p>💡 <strong>Tips:</strong> Clear, well-lit photo · face fully visible · one face per photo · enroll 2–3 photos for better accuracy · avoid sunglasses or face coverings</p>
            </div>
        </div>

        <!-- Step 4: Submit -->
        <div id="enrollStep4" style="display:none">
            <div class="card">
                <div class="card-header">
                    <div class="card-header-icon">✅</div>
                    <div class="card-header-text">
                        <div class="card-header-title">Ready to Enroll</div>
                        <div class="card-header-sub" id="step4Summary">—</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="preview-grid" id="previewGrid4" style="margin-bottom:16px"></div>
                    <div style="display:flex;gap:10px">
                        <button class="btn-primary" style="background:var(--surface3);color:var(--text2)" onclick="goToStep(3)">← Back</button>
                        <button class="btn-primary" id="enrollBtn" onclick="submitEnrollment()">🚀 Enroll Now</button>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- .screen -->
</div>

<!-- ==================== MANAGE TAB ==================== -->
<div id="tab-manage" class="tab-panel">
    <div class="screen" style="padding-bottom:8px">
        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">🏢</div>
                <div class="card-header-text">
                    <div class="card-header-title">Manage Enrolled Faces</div>
                    <div class="card-header-sub">View, delete, and configure face data</div>
                </div>
            </div>
            <div class="card-body">
                <div class="field" style="margin-bottom:0">
                    <label>Company</label>
                    <div class="sel-wrapper">
                        <select id="manageCompanySelect" class="native-sel" onchange="loadStaffListing(this.value)">
                            <option value="">Select company…</option>
                            <?php foreach ($companies as $c): ?>
                            <option value="<?php echo intval($c['id']); ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters + List (shown after company selected) -->
    <div id="manageListPanel" style="display:none">
        <div style="padding:0 16px 10px;max-width:600px;margin:0 auto">
            <div style="margin-bottom:10px" id="nameSearchWrap">
                <select id="staffSearch" class="native-sel" style="height:42px;font-size:13px">
                    <option value="">Search by name…</option>
                </select>
            </div>
            <div style="display:flex;gap:8px;margin-bottom:4px">
                <div style="flex:1" id="deptSelectWrap">
                    <select id="manageDeptSelect" class="native-sel" style="height:42px;font-size:13px">
                        <option value="">All Departments</option>
                    </select>
                </div>
                <div style="flex:1" id="photoFilterWrap">
                    <select id="managePhotoFilter" class="native-sel" style="height:42px;font-size:13px">
                        <option value="">All Photos</option>
                        <option value="none">No Photos</option>
                        <option value="has">Has Photos</option>
                        <option value="profile_pending">Profile Not Converted</option>
                        <option value="profile_encoded">Profile Converted</option>
                    </select>
                </div>
            </div>

            <!-- Batch convert (staff only) -->
            <div id="batchWrap" style="display:none;margin-top:10px">
                <div class="batch-bar">
                    <div class="batch-text" id="batchText"></div>
                    <button class="btn-batch" id="batchBtn" onclick="batchConvertProfiles()">⚡ Convert All</button>
                </div>
                <div class="batch-prog" id="batchProgWrap" style="display:none">
                    <div class="batch-fill" id="batchFill"></div>
                </div>
            </div>
        </div>

        <div class="card" style="border-radius:var(--radius);overflow:hidden;margin:0 16px 16px;max-width:calc(600px - 32px);margin-left:auto;margin-right:auto" id="staffListCard">
            <div class="loading-center"><div class="spinner"></div>Loading…</div>
        </div>
    </div>
</div>

<!-- ==================== CAMERA MODAL ==================== -->
<div id="cameraModal" class="camera-modal" style="display:none">
    <div class="camera-header">
        <span class="camera-header-title">Take Photo <span id="cameraCount" style="opacity:.7"></span></span>
        <button class="camera-close-btn" onclick="closeCamera()">✕</button>
    </div>
    <div class="camera-body">
        <video id="cameraVideo" autoplay playsinline muted webkit-playsinline></video>
        <canvas id="cameraCanvas"></canvas>
        <div class="face-guide-overlay">
            <div class="face-guide-oval"></div>
            <p class="camera-guide-text">Center your face inside the oval</p>
        </div>
        <div id="cameraFlash" class="camera-flash"></div>
    </div>
    <div class="camera-footer">
        <button class="camera-switch" id="switchCamBtn" onclick="switchCamera()" style="display:none">🔄</button>
        <button class="camera-shutter" onclick="capturePhoto()"></button>
        <div class="camera-photo-count" id="camPhotoCount">0</div>
    </div>
</div>

<!-- ==================== BOTTOM NAV ==================== -->
<nav class="bottom-nav">
    <button class="bottom-nav-btn active" id="navEnroll" onclick="switchTab('enroll')">
        <span class="nav-icon">📸</span>
        <span>Enroll</span>
    </button>
    <button class="bottom-nav-btn" id="navManage" onclick="switchTab('manage')">
        <span class="nav-icon">👥</span>
        <span>Manage</span>
    </button>
</nav>

<script>
/* ── Constants ── */
var BASE = '<?php echo $baseUrl . '?' . $queryBase; ?>';
var PHOTO_WEB_BASE = '<?php echo PHOTO_WEB_BASE; ?>';

// Company ids whose General Worker (GW) selection is enabled. Add ids here as needed.
var GW_COMPANIES = ['1', '4'];
function gwEnabled(compId) { return GW_COMPANIES.indexOf(String(compId)) !== -1; }

/* ── State ── */
var enrollPhotos  = [];   // [{file, dataUrl}]
var enrollType    = 'staff'; // 'staff' | 'gw'
var currentStep   = 1;
var allManageStaff = [];
var allManageGw    = [];
var activeDept     = '';
var activePhotoFilter = ''; // '' | 'none' | 'has'
var activeSearchId    = ''; // '' | 'staff-{id}' | 'gw-{id}'
var expandedId     = null; // currently expanded staff/gw row id
var expandedType   = null; // 'staff' | 'gw'
var currentManageCompId = '';
var cameraMode     = 'enroll'; // 'enroll' | 'manage'
var managePhotos   = [];   // photos staged for manage-tab enrollment
var manageTarget   = null; // {id, type} when adding from manage tab

/* ── Toast ── */
function toast(type, msg) {
    var box = document.createElement('div');
    box.className = 'toast ' + type;
    box.innerHTML = '<div class="toast-icon"></div><span>' + msg + '</span>';
    var container = document.getElementById('toastContainer');
    container.appendChild(box);
    setTimeout(function() {
        box.classList.add('removing');
        setTimeout(function(){ if(box.parentNode) box.parentNode.removeChild(box); }, 220);
    }, 3500);
}

/* ── Tab Switching ── */
function switchTab(tab) {
    document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
    document.getElementById('tab-' + tab).classList.add('active');
    document.querySelectorAll('.bottom-nav-btn').forEach(function(b){ b.classList.remove('active'); });
    document.getElementById('nav' + tab.charAt(0).toUpperCase() + tab.slice(1)).classList.add('active');
}

/* ── Step Wizard ── */
function updateStepBar(step) {
    for (var i = 1; i <= 4; i++) {
        var el = document.getElementById('step-' + i);
        el.classList.remove('active', 'done');
        if (i < step)  el.classList.add('done');
        if (i === step) el.classList.add('active');
    }
}

function goToStep(step) {
    currentStep = step;
    document.getElementById('enrollStep1').style.display = (step === 1) ? '' : 'none';
    document.getElementById('enrollStep2').style.display = (step === 2) ? '' : 'none';
    document.getElementById('enrollStep3').style.display = (step === 3) ? '' : 'none';
    document.getElementById('enrollStep4').style.display = (step === 4) ? '' : 'none';
    updateStepBar(step);

    if (step === 4) buildStep4Summary();
    window.scrollTo({top: 0, behavior: 'smooth'});
}

function buildStep4Summary() {
    var personName = '';
    if (enrollType === 'gw') {
        var opt = document.getElementById('gwSelect').selectedOptions[0];
        personName = opt ? opt.text : '';
    } else {
        var opt = document.getElementById('staffSelect').selectedOptions[0];
        personName = opt ? opt.text : '';
    }
    document.getElementById('step4Summary').textContent = enrollPhotos.length + ' photo' + (enrollPhotos.length > 1 ? 's' : '') + ' · ' + personName;

    // Mirror preview
    var grid = document.getElementById('previewGrid4');
    grid.innerHTML = '';
    enrollPhotos.forEach(function(p, i) {
        var div = document.createElement('div');
        div.className = 'preview-item';
        div.innerHTML = '<img src="' + p.dataUrl + '">';
        grid.appendChild(div);
    });
}

/* ── Enroll Company Change ── */
function onEnrollCompanyChange(compId) {
    document.getElementById('step1Next').disabled = !compId;
    if (!compId) return;

    loadStaff(compId, document.getElementById('staffSelect'));

    if (gwEnabled(compId)) {
        document.getElementById('enrollTypeTabs').style.display = '';
        loadGwList(compId);
    } else {
        document.getElementById('enrollTypeTabs').style.display = 'none';
        if (enrollType === 'gw') setEnrollType('staff');
    }

    // Update step 2 subtitle
    var opt = document.getElementById('companySelect').selectedOptions[0];
    document.getElementById('step2CompName').textContent = opt ? opt.text : '';
}

function loadStaff(companyId, selectEl) {
    // Destroy existing Select2 before touching innerHTML
    if ($(selectEl).data('select2')) { $(selectEl).select2('destroy'); }
    selectEl.innerHTML = '<option value="">Loading…</option>';
    selectEl.disabled = true;
    if (!companyId) {
        selectEl.innerHTML = '<option value="">Select company first…</option>';
        initSelect2Staff();
        return;
    }
    fetch(BASE + '&ajax=get_staff&company_id=' + companyId)
        .then(function(r){ return r.json(); })
        .then(function(res) {
            selectEl.innerHTML = '<option value="">Select staff member…</option>';
            (res.staff || []).forEach(function(s) {
                var o = document.createElement('option');
                o.value = s.id;
                o.textContent = s.fullname;
                selectEl.appendChild(o);
            });
            selectEl.disabled = false;
            initSelect2Staff();
        })
        .catch(function() {
            selectEl.innerHTML = '<option value="">Failed to load</option>';
            initSelect2Staff();
        });
}

function loadGwList(companyId) {
    var sel = document.getElementById('gwSelect');
    // Destroy existing Select2 before touching innerHTML
    if ($('#gwSelect').data('select2')) { $('#gwSelect').select2('destroy'); }
    sel.innerHTML = '<option value="">Loading…</option>';
    sel.disabled = true;
    fetch(BASE + '&ajax=get_gw&company_id=' + companyId)
        .then(function(r){ return r.json(); })
        .then(function(res) {
            sel.innerHTML = '<option value="">Select general worker…</option>';
            (res.gw || []).forEach(function(g) {
                var o = document.createElement('option');
                o.value = g.id;
                o.textContent = g.fullname + (g.code ? ' (' + g.code + ')' : '');
                sel.appendChild(o);
            });
            sel.disabled = false;
            initSelect2Gw();
        })
        .catch(function() {
            sel.innerHTML = '<option value="">Failed to load</option>';
            initSelect2Gw();
        });
}

/* ── Select2 init helpers ── */
function initSelect2Staff() {
    $('#staffSelect').select2({
        placeholder: 'Search staff…',
        allowClear: false,
        width: '100%',
        dropdownParent: $('#staffGroup')
    }).off('change.s2staff').on('change.s2staff', validateStep2);
}

function initSelect2Gw() {
    $('#gwSelect').select2({
        placeholder: 'Search general worker…',
        allowClear: false,
        width: '100%',
        dropdownParent: $('#gwGroup')
    }).off('change.s2gw').on('change.s2gw', validateStep2);
}

/* ── Enroll Type Switch ── */
function setEnrollType(type) {
    enrollType = type;
    document.querySelectorAll('.type-switch-btn').forEach(function(b, i){
        b.classList.toggle('active', (i === 0 && type === 'staff') || (i === 1 && type === 'gw'));
    });
    document.getElementById('staffGroup').style.display = (type === 'staff') ? '' : 'none';
    document.getElementById('gwGroup').style.display   = (type === 'gw')    ? '' : 'none';
    validateStep2();
}

function validateStep2() {
    var val = enrollType === 'gw'
        ? document.getElementById('gwSelect').value
        : document.getElementById('staffSelect').value;
    document.getElementById('step2Next').disabled = !val;

    // Update step 3 person name
    if (val) {
        var sel = enrollType === 'gw' ? document.getElementById('gwSelect') : document.getElementById('staffSelect');
        var opt = sel.selectedOptions[0];
        document.getElementById('step3PersonName').textContent = opt ? opt.text : '';
    }
}

/* ── Photos ── */
function handleFiles(files) {
    Array.from(files).forEach(function(f) {
        if (enrollPhotos.length >= 5 || !f.type.startsWith('image/')) return;
        var reader = new FileReader();
        reader.onload = function(e) {
            enrollPhotos.push({file: f, dataUrl: e.target.result});
            renderPreviews();
        };
        reader.readAsDataURL(f);
    });
}

function renderPreviews() {
    var grid = document.getElementById('previewGrid');
    grid.innerHTML = '';
    enrollPhotos.forEach(function(p, i) {
        var div = document.createElement('div');
        div.className = 'preview-item';
        div.innerHTML = '<img src="' + p.dataUrl + '"><button class="remove" onclick="removePhoto(' + i + ')">✕</button>';
        grid.appendChild(div);
    });
    // Update pips
    for (var j = 0; j < 5; j++) {
        document.getElementById('pip' + j).className = 'photo-pip' + (j < enrollPhotos.length ? ' filled' : '');
    }
    document.getElementById('photoCountLabel').textContent = enrollPhotos.length + ' / 5 photos';
    document.getElementById('camPhotoCount').textContent = enrollPhotos.length;
    document.getElementById('cameraCount').textContent = enrollPhotos.length > 0 ? '(' + enrollPhotos.length + '/5)' : '';
    document.getElementById('step3Next').disabled = enrollPhotos.length === 0;
}

function removePhoto(i) {
    enrollPhotos.splice(i, 1);
    renderPreviews();
}

/* ── Submit Enrollment ── */
function submitEnrollment() {
    var images = enrollPhotos.map(function(p){ return p.dataUrl.split(',')[1]; });
    if (!images.length) return;

    var ajaxUrl, payload;
    if (enrollType === 'gw') {
        var gwId = document.getElementById('gwSelect').value;
        if (!gwId) return;
        ajaxUrl = BASE + '&ajax=enroll_gw';
        payload = {gw_id: parseInt(gwId), images: images};
    } else {
        var userId = document.getElementById('staffSelect').value;
        if (!userId) return;
        ajaxUrl = BASE + '&ajax=enroll';
        payload = {user_id: parseInt(userId), images: images};
    }

    var btn = document.getElementById('enrollBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Enrolling…';

    fetch(ajaxUrl, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)})
        .then(function(r){ return r.json(); })
        .then(function(res) {
            btn.innerHTML = '🚀 Enroll Now';
            if (res.success && res.results) {
                var ok   = res.results.filter(function(r){ return r.success; }).length;
                var fail = res.results.length - ok;
                if (ok > 0) {
                    toast('success', ok + ' photo' + (ok > 1 ? 's' : '') + ' enrolled!' + (fail > 0 ? ' ' + fail + ' failed.' : ''));
                    enrollPhotos = [];
                    goToStep(1);
                    document.getElementById('companySelect').value = '';
                    document.getElementById('step1Next').disabled = true;
                } else {
                    var errMsg = res.results.map(function(r){ return r.message || r.error || 'Unknown error'; }).join(', ');
                    toast('error', 'Enrollment failed: ' + errMsg);
                }
            } else {
                toast('error', res.message || res.error || 'Enrollment failed');
            }
            btn.disabled = false;
        })
        .catch(function() {
            btn.innerHTML = '🚀 Enroll Now';
            btn.disabled = false;
            toast('error', 'Network error. Please try again.');
        });
}

/* ── Manage: load staff listing ── */
function loadStaffListing(compId) {
    if (!compId) { document.getElementById('manageListPanel').style.display = 'none'; return; }
    currentManageCompId = compId;
    expandedId = null; expandedType = null;
    document.getElementById('manageListPanel').style.display = '';
    document.getElementById('staffListCard').innerHTML = '<div class="loading-center"><div class="spinner"></div>Loading…</div>';
    activeDept = '';
    activePhotoFilter = '';
    activeSearchId = '';
    if ($('#staffSearch').data('select2')) { $('#staffSearch').select2('destroy'); }
    document.getElementById('staffSearch').innerHTML = '<option value="">Search by name…</option>';
    if ($('#manageDeptSelect').data('select2')) { $('#manageDeptSelect').select2('destroy'); }
    document.getElementById('manageDeptSelect').innerHTML = '<option value="">All Departments</option>';
    if ($('#managePhotoFilter').data('select2')) { $('#managePhotoFilter').select2('destroy'); }
    $('#managePhotoFilter').val('').trigger('change.select2');

    var loadGw = gwEnabled(compId);
    var staffDone = false, gwDone = false;

    fetch(BASE + '&ajax=get_staff_with_status&company_id=' + compId)
        .then(function(r){ return r.json(); })
        .then(function(res) { allManageStaff = (res && res.staff) ? res.staff : []; staffDone = true; if (gwDone) buildManageView(); })
        .catch(function() { allManageStaff = []; staffDone = true; if (gwDone) buildManageView(); });

    if (loadGw) {
        fetch(BASE + '&ajax=get_gw_with_status&company_id=' + compId)
            .then(function(r){ return r.json(); })
            .then(function(res) { allManageGw = (res && res.gw) ? res.gw : []; gwDone = true; if (staffDone) buildManageView(); })
            .catch(function() { allManageGw = []; gwDone = true; if (staffDone) buildManageView(); });
    } else {
        allManageGw = []; gwDone = true;
    }
}

function buildManageView() {
    if (!allManageStaff.length && !allManageGw.length) {
        document.getElementById('staffListCard').innerHTML = '<div class="empty-state"><div class="e-icon">⚠️</div><p>No staff found</p></div>';
        return;
    }

    // Build dept list
    var depts = [], seen = {};
    allManageStaff.forEach(function(s) {
        if (!seen[s.department]) { seen[s.department] = true; depts.push(s.department); }
    });
    depts.sort(function(a, b) {
        if (a === 'No Department') return 1;
        if (b === 'No Department') return -1;
        return a.localeCompare(b);
    });

    // Populate department Select2
    var sel = document.getElementById('manageDeptSelect');
    if ($('#manageDeptSelect').data('select2')) { $('#manageDeptSelect').select2('destroy'); }
    sel.innerHTML = '<option value="">All Departments</option>';
    depts.forEach(function(d) {
        var o = document.createElement('option');
        o.value = d;
        o.textContent = d;
        if (d === activeDept) o.selected = true;
        sel.appendChild(o);
    });
    if (allManageGw.length > 0) {
        var o = document.createElement('option');
        o.value = '__gw__';
        o.textContent = '🔧 General Workers';
        if (activeDept === '__gw__') o.selected = true;
        sel.appendChild(o);
    }
    initManageDeptSelect2();

    // Populate name search Select2
    var nameSel = document.getElementById('staffSearch');
    if ($('#staffSearch').data('select2')) { $('#staffSearch').select2('destroy'); }
    nameSel.innerHTML = '<option value="">Search by name…</option>';
    allManageStaff.forEach(function(s) {
        var o = document.createElement('option');
        o.value = 'staff-' + s.id;
        o.textContent = toTitleCase(s.fullname) + ' — ' + (s.department || '');
        nameSel.appendChild(o);
    });
    allManageGw.forEach(function(g) {
        var o = document.createElement('option');
        o.value = 'gw-' + g.id;
        o.textContent = toTitleCase(g.fullname) + (g.code ? ' (' + g.code + ')' : '') + ' — GW';
        nameSel.appendChild(o);
    });
    initManageNameSearch();

    // Init photo filter Select2
    initManagePhotoFilter();

    applyManageFilters();
}

function toTitleCase(str) {
    if (!str) return '';
    return str.replace(/\S+/g, function(w){ return w.charAt(0).toUpperCase() + w.slice(1).toLowerCase(); });
}

/* ── Manage Filter Helpers ── */
function initManageNameSearch() {
    $('#staffSearch').select2({
        placeholder: 'Search by name…',
        allowClear: true,
        width: '100%',
        minimumResultsForSearch: 0,
        dropdownParent: $('#nameSearchWrap')
    }).off('change.namesearch').on('change.namesearch', function() {
        activeSearchId = $(this).val() || '';
        applyManageFilters();
    });
}

function initManageDeptSelect2() {
    $('#manageDeptSelect').select2({
        placeholder: 'All Departments',
        allowClear: true,
        width: '100%',
        minimumResultsForSearch: 6,
        dropdownParent: $('#deptSelectWrap')
    }).off('change.dept').on('change.dept', function() {
        activeDept = $(this).val() || '';
        applyManageFilters();
    });
}

function initManagePhotoFilter() {
    if ($('#managePhotoFilter').data('select2')) return;
    $('#managePhotoFilter').select2({
        placeholder: 'All Photos',
        allowClear: true,
        width: '100%',
        minimumResultsForSearch: Infinity,
        dropdownParent: $('#photoFilterWrap')
    }).off('change.photo').on('change.photo', function() {
        activePhotoFilter = $(this).val() || '';
        applyManageFilters();
    });
}

function applyManageFilters() {
    renderStaffGroups();
}

function renderStaffGroups() {
    var deptFilter = activeDept;
    var searchId = activeSearchId; // 'staff-{id}' | 'gw-{id}' | ''
    var photoFilter = activePhotoFilter;
    var showGwOnly = (deptFilter === '__gw__');

    var filtered = showGwOnly ? [] : allManageStaff.filter(function(s) {
        var deptMatch = !deptFilter || s.department === deptFilter;
        var nameMatch = !searchId || searchId === ('staff-' + s.id);
        var photoMatch = !photoFilter
            || (photoFilter === 'none' && parseInt(s.encoding_count) === 0)
            || (photoFilter === 'has' && parseInt(s.encoding_count) > 0)
            || (photoFilter === 'profile_pending' && (s.profile_status === 'pending' || s.profile_status === 'outdated'))
            || (photoFilter === 'profile_encoded' && s.profile_status === 'encoded');
        return deptMatch && nameMatch && photoMatch;
    });

    // Profile-photo filters are a staff-only concept (GWs have no staff_profile row),
    // so selecting one hides the GW section entirely rather than showing it unfiltered.
    var isProfileFilter = (photoFilter === 'profile_pending' || photoFilter === 'profile_encoded');
    var showGw = (showGwOnly || !deptFilter) && !isProfileFilter;
    var filteredGw = showGw ? allManageGw.filter(function(g) {
        var nameMatch = !searchId || searchId === ('gw-' + g.id);
        var photoMatch = !photoFilter
            || (photoFilter === 'none' && parseInt(g.encoding_count) === 0)
            || (photoFilter === 'has' && parseInt(g.encoding_count) > 0);
        return nameMatch && photoMatch;
    }) : [];

    var card = document.getElementById('staffListCard');

    if (!filtered.length && !filteredGw.length) {
        card.innerHTML = '<div class="empty-state"><div class="e-icon">😶</div><p>No results found</p></div>';
        return;
    }

    // Group staff by dept
    var groups = {};
    filtered.forEach(function(s) {
        if (!groups[s.department]) groups[s.department] = [];
        groups[s.department].push(s);
    });
    var deptKeys = Object.keys(groups).sort(function(a, b) {
        if (a === 'No Department') return 1;
        if (b === 'No Department') return -1;
        return a.localeCompare(b);
    });

    var html = '';

    deptKeys.forEach(function(dept) {
        html += '<div class="dept-header">' + dept.toUpperCase() + ' <span style="font-weight:400">(' + groups[dept].length + ')</span></div>';
        groups[dept].forEach(function(s) {
            var name = toTitleCase(s.fullname);
            var init = (name.charAt(0) || '?').toUpperCase();
            var bc = s.encoding_count > 0 ? 'enrolled' : 'none';
            var bt = s.encoding_count > 0 ? s.encoding_count + ' photo' + (s.encoding_count > 1 ? 's' : '') : 'None';
            var isExpanded = (expandedType === 'staff' && expandedId == s.id);
            html += '<div class="staff-row' + (isExpanded ? ' expanded' : '') + '" id="srow-' + s.id + '" onclick="toggleStaff(' + s.id + ')">';
            html += '<div class="s-avatar">' + init + '</div>';
            html += '<div class="s-info"><div class="s-name">' + name + '</div><div class="s-sub">' + toTitleCase(s.position || s.person) + '</div></div>';

            var pm = PROFILE_BADGE[s.profile_status] || PROFILE_BADGE.no_photo;
            var needsConvert = (s.profile_status === 'pending' || s.profile_status === 'outdated');

            html += '<div class="s-right">';
            html += '<span class="badge-pill ' + bc + '">' + bt + '</span>';
            html += '<span class="badge-pill ' + pm.cls + '">' + pm.txt + '</span>';
            if (needsConvert) {
                html += '<button class="btn-convert" id="conv-' + s.id + '" onclick="event.stopPropagation();convertProfile(' + s.id + ')">⚡ Convert</button>';
            }
            html += '<span class="chevron">›</span>';
            html += '</div>';
            html += '</div>';
            html += '<div class="enrolled-panel' + (isExpanded ? ' open' : '') + '" id="epanel-' + s.id + '">';
            if (isExpanded) {
                html += '<div class="enrolled-panel-inner" id="epanel-inner-' + s.id + '"><div class="loading-center"><div class="spinner"></div></div></div>';
            } else {
                html += '<div class="enrolled-panel-inner" id="epanel-inner-' + s.id + '"></div>';
            }
            html += '</div>';
        });
    });

    if (filteredGw.length) {
        html += '<div class="gw-divider"><div class="gw-divider-line"></div><div class="gw-divider-label">🔧 General Workers</div><div class="gw-divider-line"></div></div>';
        filteredGw.forEach(function(g) {
            var name = toTitleCase(g.fullname);
            var init = (name.charAt(0) || '?').toUpperCase();
            var bc = g.encoding_count > 0 ? 'enrolled' : 'none';
            var bt = g.encoding_count > 0 ? g.encoding_count + ' photo' + (g.encoding_count > 1 ? 's' : '') : 'None';
            var isExpanded = (expandedType === 'gw' && expandedId == g.id);
            var faceChecked = String(g.checkin_face) === '1' ? 'checked' : '';

            html += '<div>';
            html += '<div class="staff-row' + (isExpanded ? ' expanded' : '') + '" id="grow-' + g.id + '" onclick="toggleGw(' + g.id + ')">';
            html += '<div class="s-avatar gw">' + init + '</div>';
            html += '<div class="s-info"><div class="s-name">' + name + '</div><div class="s-sub">' + (g.code || 'GW #' + g.id) + '</div>';
            // NRIC line (plain) + Mobile line with member match badge
            var nricDisplay = g.nric || '—';
            html += '<div class="s-nric">NRIC: ' + nricDisplay + '</div>';

            var phoneDisplay = g.mobile_no || '—';
            var phoneBadgeCls = 'nric-none', phoneBadgeTxt = 'myMK Not Found';
            if (g.phone_status === 'found')      { phoneBadgeCls = 'nric-found'; phoneBadgeTxt = 'myMK Found: ' + (g.phone_member_ids || ''); }
            else if (g.phone_status === 'error') { phoneBadgeCls = 'nric-error'; phoneBadgeTxt = 'Error > 1: ' + (g.phone_member_ids || ''); }
            html += '<div class="s-nric">Mobile: ' + phoneDisplay + ' <span class="badge-pill ' + phoneBadgeCls + '">' + phoneBadgeTxt + '</span></div>';
            html += '</div>';
            html += '<div class="s-right"><span class="badge-pill ' + bc + '">' + bt + '</span><span class="chevron">›</span></div>';
            html += '</div>';

            // GW toggle row (always visible below the row)
            html += '<div class="gw-toggle-area" id="gw-toggle-' + g.id + '">';
            html += '<div class="toggle-row">';
            html += '<span class="toggle-label">Face Check-in</span>';
            html += '<label class="toggle-switch" onclick="event.stopPropagation()"><input type="checkbox" ' + faceChecked + ' onchange="toggleGwCheckin(' + g.id + ',\'mymk_daily_check_in_face\',this.checked)"><span class="slider"></span></label>';
            html += '</div>';
            html += '</div>';

            html += '<div class="enrolled-panel' + (isExpanded ? ' open' : '') + '" id="gw-epanel-' + g.id + '">';
            if (isExpanded) {
                html += '<div class="enrolled-panel-inner" id="gw-epanel-inner-' + g.id + '"><div class="loading-center"><div class="spinner"></div></div></div>';
            } else {
                html += '<div class="enrolled-panel-inner" id="gw-epanel-inner-' + g.id + '"></div>';
            }
            html += '</div>';
            html += '</div>';
        });
    }

    card.innerHTML = html;
    updateBatchBar();

    // Load data for expanded row
    if (expandedType === 'staff' && expandedId) {
        fetchEnrolled(expandedId);
    } else if (expandedType === 'gw' && expandedId) {
        fetchGwEnrolled(expandedId);
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   Profile photo -> face encoding  (STAFF ONLY — GWs have no staff_profile row)

   Status comes from the server on get_staff_with_status:
     no_photo  nothing uploaded            -> nothing to do
     pending   uploaded, never converted   -> Convert
     outdated  photo replaced since        -> Convert (retires the stale encoding)
     encoded   converted from this photo   -> skipped by the batch
   ══════════════════════════════════════════════════════════════════════════ */
var PROFILE_BADGE = {
    encoded:  {cls: 'p-ok',       txt: 'Profile ✓'},
    pending:  {cls: 'p-pending',  txt: 'Not converted'},
    outdated: {cls: 'p-outdated', txt: 'Photo changed'},
    no_photo: {cls: 'p-nophoto',  txt: 'No profile pic'}
};

function profileTargets() {
    return allManageStaff.filter(function(s) {
        return s.profile_status === 'pending' || s.profile_status === 'outdated';
    });
}

/* Query -> command boundary: this ONE function owns the wire call and the local model
   update. The single Convert button and the batch loop both go through it (DRY), so they
   can never drift apart. It never toasts — the caller decides how to report. */
function convertProfileRequest(id) {
    return fetch(BASE + '&ajax=encode_profile', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({user_id: parseInt(id)})
    })
    .then(function(r){ return r.json(); })
    .then(function(res) {
        if (res.success) {
            for (var i = 0; i < allManageStaff.length; i++) {
                if (allManageStaff[i].id == id) {
                    allManageStaff[i].profile_status = res.status || 'encoded';
                    // Server returns the recomputed count — an 'outdated' convert retires the
                    // stale encoding as it adds the new one, so the count does not just +1.
                    if (typeof res.encoding_count !== 'undefined') {
                        allManageStaff[i].encoding_count = parseInt(res.encoding_count);
                    }
                    break;
                }
            }
            return {ok: true, skipped: !!res.skipped};
        }
        return {ok: false, message: res.message || 'Convert failed'};
    })
    .catch(function() {
        return {ok: false, message: 'Network error'};
    });
}

/* Single row button */
function convertProfile(id) {
    var btn = document.getElementById('conv-' + id);
    if (btn) { btn.disabled = true; btn.textContent = '…'; }

    convertProfileRequest(id).then(function(res) {
        if (res.ok) {
            toast('success', res.skipped ? 'Already converted.' : 'Profile photo converted.');
        } else {
            toast('error', res.message);
        }
        renderStaffGroups();
    });
}

/* Batch: sequential, one staff per request. A single company-wide request would exceed
   PHP's execution limit (download + encode, per staff), so the client drives the loop —
   it stays resumable, shows live progress, and names the staff that failed. */
function batchConvertProfiles() {
    var targets = profileTargets();
    if (!targets.length) return;

    if (!confirm('Convert ' + targets.length + ' profile photo(s) into face encodings?\n\nStaff already converted are skipped.')) return;

    var btn      = document.getElementById('batchBtn');
    var text     = document.getElementById('batchText');
    var progWrap = document.getElementById('batchProgWrap');
    var fill     = document.getElementById('batchFill');

    btn.disabled = true;
    progWrap.style.display = '';
    fill.style.width = '0';

    var done = 0, ok = 0, failed = [];

    function step(i) {
        if (i >= targets.length) { finish(); return; }
        var s = targets[i];
        text.textContent = 'Converting ' + toTitleCase(s.fullname) + '… (' + (i + 1) + ' of ' + targets.length + ')';

        convertProfileRequest(s.id).then(function(res) {
            done++;
            if (res.ok) { ok++; }
            else { failed.push(toTitleCase(s.fullname) + ' — ' + res.message); }
            fill.style.width = Math.round((done / targets.length) * 100) + '%';
            step(i + 1);
        });
    }

    function finish() {
        btn.disabled = false;
        progWrap.style.display = 'none';

        if (ok > 0) { toast('success', ok + ' profile photo(s) converted.'); }
        if (failed.length) {
            toast('error', failed.length + ' failed — see list.');
            console.warn('Profile convert failures:\n' + failed.join('\n'));
        }
        renderStaffGroups();   // repaints rows + calls updateBatchBar()
    }

    step(0);
}

/* Batch bar visibility + label. Recomputed on every render so it always reflects truth. */
function updateBatchBar() {
    var wrap = document.getElementById('batchWrap');
    var text = document.getElementById('batchText');
    var btn  = document.getElementById('batchBtn');
    if (!wrap || !text || !btn) return;

    if (!allManageStaff.length) { wrap.style.display = 'none'; return; }

    var pending = profileTargets().length;
    var encoded = allManageStaff.filter(function(s){ return s.profile_status === 'encoded'; }).length;
    var noPhoto = allManageStaff.filter(function(s){ return s.profile_status === 'no_photo'; }).length;

    wrap.style.display = '';

    if (pending === 0) {
        text.innerHTML = '✅ All profile photos converted &nbsp;·&nbsp; ' + encoded + ' encoded, ' + noPhoto + ' with no profile pic';
        btn.style.display = 'none';
    } else {
        text.innerHTML = '⚡ ' + pending + ' staff have a profile photo that is not converted &nbsp;·&nbsp; ' + encoded + ' done, ' + noPhoto + ' with no profile pic';
        btn.style.display = '';
        btn.textContent = '⚡ Convert All (' + pending + ')';
    }
}

/* ── Toggle expand staff row ── */
function toggleStaff(staffId) {
    var wasExpanded = (expandedType === 'staff' && expandedId == staffId);
    if (wasExpanded) {
        expandedId = null; expandedType = null;
    } else {
        expandedId = staffId; expandedType = 'staff';
    }
    renderStaffGroups();
    if (!wasExpanded) fetchEnrolled(staffId);
}

function toggleGw(gwId) {
    var wasExpanded = (expandedType === 'gw' && expandedId == gwId);
    if (wasExpanded) {
        expandedId = null; expandedType = null;
    } else {
        expandedId = gwId; expandedType = 'gw';
    }
    renderStaffGroups();
    if (!wasExpanded) fetchGwEnrolled(gwId);
}

/* ── Fetch enrolled faces ── */
function fetchEnrolled(userId) {
    var inner = document.getElementById('epanel-inner-' + userId);
    if (!inner) return;
    inner.innerHTML = '<div class="loading-center"><div class="spinner"></div></div>';

    fetch(BASE + '&ajax=get_enrolled&user_id=' + userId)
        .then(function(r){ return r.json(); })
        .then(function(res) {
            if (!res.encodings || !res.encodings.length) {
                inner.innerHTML = '<div class="empty-state" style="padding:16px"><div class="e-icon">😶</div><p>No enrolled faces yet</p></div>' + buildAddFaceHTML(userId, 'staff');
                return;
            }
            inner.innerHTML = res.encodings.map(function(e) {
                var src = e.photo_path ? PHOTO_WEB_BASE + e.photo_path : '';
                return '<div class="enc-row" id="enc-' + e.id + '">' +
                    (src ? '<img src="' + src + '" onerror="this.style.display=\'none\';this.nextSibling.style.display=\'flex\'" alt="face"><div class="enc-no-photo" style="display:none">👤</div>'
                         : '<div class="enc-no-photo">👤</div>') +
                    '<div class="enc-info"><div class="enc-date">' + (e.created_at || '') + '</div><div class="enc-id">ID: ' + e.id + '</div></div>' +
                    '<button class="btn-danger" onclick="deleteEncoding(' + e.id + ',' + userId + ',event)">Delete</button>' +
                    '</div>';
            }).join('') + buildAddFaceHTML(userId, 'staff');
        })
        .catch(function() {
            if (inner) inner.innerHTML = '<div class="empty-state" style="padding:16px"><div class="e-icon">⚠️</div><p>Failed to load</p></div>';
        });
}

function deleteEncoding(id, userId, ev) {
    if (ev) ev.stopPropagation();
    if (!confirm('Delete this enrolled face? This cannot be undone.')) return;

    fetch(BASE + '&ajax=delete_encoding', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({encoding_id: id})})
        .then(function(r){ return r.json(); })
        .then(function(res) {
            if (res.success) {
                var el = document.getElementById('enc-' + id);
                if (el) el.remove();
                // Update badge
                for (var i = 0; i < allManageStaff.length; i++) {
                    if (allManageStaff[i].id == userId) {
                        allManageStaff[i].encoding_count = Math.max(0, parseInt(allManageStaff[i].encoding_count) - 1);
                        updateStaffBadge(userId, allManageStaff[i].encoding_count, 'staff');
                        break;
                    }
                }
                toast('success', 'Face deleted');
            } else {
                toast('error', res.message || 'Delete failed');
            }
        })
        .catch(function(){ toast('error', 'Network error'); });
}

function fetchGwEnrolled(gwId) {
    var inner = document.getElementById('gw-epanel-inner-' + gwId);
    if (!inner) return;
    inner.innerHTML = '<div class="loading-center"><div class="spinner"></div></div>';

    fetch(BASE + '&ajax=get_gw_enrolled&gw_id=' + gwId)
        .then(function(r){ return r.json(); })
        .then(function(res) {
            if (!res.encodings || !res.encodings.length) {
                inner.innerHTML = '<div class="empty-state" style="padding:16px"><div class="e-icon">😶</div><p>No enrolled faces yet</p></div>' + buildAddFaceHTML(gwId, 'gw');
                return;
            }
            inner.innerHTML = res.encodings.map(function(e) {
                var src = BASE + '&ajax=gw_photo&enc_id=' + e.id;
                return '<div class="enc-row" id="gw-enc-' + e.id + '">' +
                    '<img src="' + src + '" onerror="this.style.display=\'none\';this.nextSibling.style.display=\'flex\'" alt="face">' +
                    '<div class="enc-no-photo" style="display:none">👤</div>' +
                    '<div class="enc-info"><div class="enc-date">' + (e.created_at || '') + '</div><div class="enc-id">ID: ' + e.id + '</div></div>' +
                    '<button class="btn-danger" onclick="deleteGwEncoding(' + e.id + ',' + gwId + ',event)">Delete</button>' +
                    '</div>';
            }).join('') + buildAddFaceHTML(gwId, 'gw');
        })
        .catch(function() {
            if (inner) inner.innerHTML = '<div class="empty-state" style="padding:16px"><div class="e-icon">⚠️</div><p>Failed to load</p></div>';
        });
}

function deleteGwEncoding(id, gwId, ev) {
    if (ev) ev.stopPropagation();
    if (!confirm('Delete this GW enrolled face? This cannot be undone.')) return;

    fetch(BASE + '&ajax=delete_gw_encoding', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({encoding_id: id})})
        .then(function(r){ return r.json(); })
        .then(function(res) {
            if (res.success) {
                var el = document.getElementById('gw-enc-' + id);
                if (el) el.remove();
                for (var i = 0; i < allManageGw.length; i++) {
                    if (allManageGw[i].id == gwId) {
                        allManageGw[i].encoding_count = Math.max(0, parseInt(allManageGw[i].encoding_count) - 1);
                        updateStaffBadge(gwId, allManageGw[i].encoding_count, 'gw');
                        break;
                    }
                }
                toast('success', 'Face deleted');
            } else {
                toast('error', res.message || 'Delete failed');
            }
        })
        .catch(function(){ toast('error', 'Network error'); });
}

function updateStaffBadge(id, count, type) {
    var prefix = type === 'gw' ? 'grow-' : 'srow-';
    var row = document.getElementById(prefix + id);
    if (!row) return;
    var badge = row.querySelector('.badge-pill');
    if (badge) {
        badge.className = 'badge-pill ' + (count > 0 ? 'enrolled' : 'none');
        badge.textContent = count > 0 ? count + ' photo' + (count > 1 ? 's' : '') : 'None';
    }
}

function toggleGwCheckin(gwId, field, checked) {
    var value = checked ? '1' : '0';
    fetch(BASE + '&ajax=toggle_gw_checkin', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({gw_id:gwId,field:field,value:value})})
        .then(function(r){ return r.json(); })
        .then(function(res) {
            if (res.success) {
                for (var i = 0; i < allManageGw.length; i++) {
                    if (allManageGw[i].id == gwId) {
                        if (field === 'mymk_daily_check_in_face') allManageGw[i].checkin_face = value;
                        break;
                    }
                }
                toast('success', 'Check-in updated');
            } else {
                toast('error', res.message || 'Update failed');
            }
        })
        .catch(function(){ toast('error', 'Network error'); });
}

/* ── Camera ── */
var cameraStream = null;
var facingMode = 'user';

function openCamera() {
    if (enrollPhotos.length >= 5) { toast('error', 'Maximum 5 photos reached'); return; }
    cameraMode = 'enroll';
    manageTarget = null;
    _openCameraModal();
}

function openCameraForManage(id, type) {
    var photos = managePhotos.filter(function(p){ return p.targetId == id && p.targetType == type; });
    if (photos.length >= 5) { toast('error', 'Maximum 5 photos reached'); return; }
    cameraMode = 'manage';
    manageTarget = {id: id, type: type};
    _openCameraModal();
}

function _openCameraModal() {
    document.getElementById('cameraModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
    startCamera();
}

function closeCamera() {
    stopCamera();
    document.getElementById('cameraModal').style.display = 'none';
    document.body.style.overflow = '';
}

function startCamera() {
    var video = document.getElementById('cameraVideo');
    var constraints = {video:{facingMode:{ideal:facingMode}},audio:false};

    navigator.mediaDevices.getUserMedia(constraints)
        .catch(function(){ return navigator.mediaDevices.getUserMedia({video:true,audio:false}); })
        .then(function(stream) {
            cameraStream = stream;
            video.srcObject = stream;
            return video.play();
        })
        .then(function() {
            var isMobile = /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
            document.getElementById('switchCamBtn').style.display = isMobile ? 'flex' : 'none';
            video.style.transform = (facingMode === 'user') ? 'scaleX(-1)' : 'scaleX(1)';
        })
        .catch(function(err) {
            toast('error', 'Camera unavailable: ' + (err.message || String(err)));
            closeCamera();
        });
}

function stopCamera() {
    if (cameraStream) { cameraStream.getTracks().forEach(function(t){ t.stop(); }); cameraStream = null; }
    var video = document.getElementById('cameraVideo');
    video.srcObject = null;
}

function switchCamera() {
    facingMode = (facingMode === 'user') ? 'environment' : 'user';
    stopCamera();
    startCamera();
}

function capturePhoto() {
    var isManage = (cameraMode === 'manage' && manageTarget);
    var currentCount = isManage
        ? managePhotos.filter(function(p){ return p.targetId == manageTarget.id && p.targetType == manageTarget.type; }).length
        : enrollPhotos.length;

    if (currentCount >= 5) { closeCamera(); return; }

    var video  = document.getElementById('cameraVideo');
    var canvas = document.getElementById('cameraCanvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    var ctx = canvas.getContext('2d');
    if (facingMode === 'user') { ctx.translate(canvas.width, 0); ctx.scale(-1, 1); }
    ctx.drawImage(video, 0, 0);
    ctx.setTransform(1, 0, 0, 1, 0, 0);

    var flash = document.getElementById('cameraFlash');
    flash.style.opacity = '1';
    setTimeout(function(){ flash.style.opacity = '0'; }, 120);

    var dataUrl = canvas.toDataURL('image/jpeg', 0.85);

    if (isManage) {
        managePhotos.push({targetId: manageTarget.id, targetType: manageTarget.type, dataUrl: dataUrl});
        renderManagePreviews(manageTarget.id, manageTarget.type);
        var newCount = managePhotos.filter(function(p){ return p.targetId == manageTarget.id && p.targetType == manageTarget.type; }).length;
        if (newCount >= 5) { setTimeout(closeCamera, 250); }
    } else {
        enrollPhotos.push({file: null, dataUrl: dataUrl});
        renderPreviews();
        if (enrollPhotos.length >= 5) { setTimeout(closeCamera, 250); }
    }
}

/* ── Manage Tab: Add Face helpers ── */
function handleManageFiles(files, id, type) {
    var existing = managePhotos.filter(function(p){ return p.targetId == id && p.targetType == type; });
    Array.from(files).forEach(function(f) {
        if (existing.length >= 5 || !f.type.startsWith('image/')) return;
        var reader = new FileReader();
        reader.onload = function(e) {
            managePhotos.push({targetId: id, targetType: type, dataUrl: e.target.result});
            existing = managePhotos.filter(function(p){ return p.targetId == id && p.targetType == type; });
            renderManagePreviews(id, type);
        };
        reader.readAsDataURL(f);
    });
}

function renderManagePreviews(id, type) {
    var panelId = (type === 'gw') ? 'gw-epanel-inner-' + id : 'epanel-inner-' + id;
    var grid = document.getElementById('mgrid-' + type + '-' + id);
    var submitBtn = document.getElementById('msubmit-' + type + '-' + id);
    if (!grid || !submitBtn) return;

    var photos = managePhotos.filter(function(p){ return p.targetId == id && p.targetType == type; });
    grid.innerHTML = photos.map(function(p, i) {
        return '<div class="preview-item">' +
            '<img src="' + p.dataUrl + '">' +
            '<button class="remove" onclick="removeManagePhoto(' + i + ',\'' + id + '\',\'' + type + '\')">✕</button>' +
            '</div>';
    }).join('');
    submitBtn.disabled = photos.length === 0;
}

function removeManagePhoto(localIdx, id, type) {
    // localIdx is relative to this target's photos
    var targetPhotos = managePhotos.filter(function(p){ return p.targetId == id && p.targetType == type; });
    var toRemove = targetPhotos[localIdx];
    if (!toRemove) return;
    var globalIdx = managePhotos.indexOf(toRemove);
    if (globalIdx !== -1) managePhotos.splice(globalIdx, 1);
    renderManagePreviews(id, type);
}

function submitManageEnroll(id, type) {
    var photos = managePhotos.filter(function(p){ return p.targetId == id && p.targetType == type; });
    if (!photos.length) return;

    var images = photos.map(function(p){ return p.dataUrl.split(',')[1]; });
    var ajaxUrl = (type === 'gw') ? BASE + '&ajax=enroll_gw' : BASE + '&ajax=enroll';
    var payload  = (type === 'gw')
        ? {gw_id: parseInt(id), images: images}
        : {user_id: parseInt(id), images: images};

    var btn = document.getElementById('msubmit-' + type + '-' + id);
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner" style="width:16px;height:16px;border-width:2px"></span> Enrolling…'; }

    fetch(ajaxUrl, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)})
        .then(function(r){ return r.json(); })
        .then(function(res) {
            if (res.success && res.results) {
                var ok   = res.results.filter(function(r){ return r.success; }).length;
                var fail = res.results.length - ok;
                if (ok > 0) {
                    // Remove submitted photos from managePhotos
                    managePhotos = managePhotos.filter(function(p){ return !(p.targetId == id && p.targetType == type); });
                    toast('success', ok + ' photo' + (ok > 1 ? 's' : '') + ' enrolled!');
                    // Update badge count in list
                    if (type === 'gw') {
                        for (var i = 0; i < allManageGw.length; i++) {
                            if (allManageGw[i].id == id) { allManageGw[i].encoding_count = parseInt(allManageGw[i].encoding_count) + ok; updateStaffBadge(id, allManageGw[i].encoding_count, 'gw'); break; }
                        }
                        fetchGwEnrolled(id);
                    } else {
                        for (var i = 0; i < allManageStaff.length; i++) {
                            if (allManageStaff[i].id == id) { allManageStaff[i].encoding_count = parseInt(allManageStaff[i].encoding_count) + ok; updateStaffBadge(id, allManageStaff[i].encoding_count, 'staff'); break; }
                        }
                        fetchEnrolled(id);
                    }
                    if (fail > 0) toast('error', fail + ' photo(s) failed to enroll.');
                } else {
                    var errMsg = res.results.map(function(r){ return r.message || r.error || 'Unknown error'; }).join(', ');
                    toast('error', 'Failed: ' + errMsg);
                    if (btn) { btn.disabled = false; btn.innerHTML = '📤 Submit Photos'; }
                }
            } else {
                toast('error', res.message || res.error || 'Enrollment failed');
                if (btn) { btn.disabled = false; btn.innerHTML = '📤 Submit Photos'; }
            }
        })
        .catch(function() {
            toast('error', 'Network error. Please try again.');
            if (btn) { btn.disabled = false; btn.innerHTML = '📤 Submit Photos'; }
        });
}

/* ── Inline add-face HTML builder ── */
function buildAddFaceHTML(id, type) {
    var fileInputId = 'mfile-' + type + '-' + id;
    return '<div class="add-face-section">' +
        '<div class="add-face-header">' +
            '<span class="add-face-title">➕ Add New Photo</span>' +
        '</div>' +
        '<div class="add-face-actions">' +
            '<div class="add-face-btn" onclick="openCameraForManage(\'' + id + '\',\'' + type + '\')">' +
                '<span class="af-icon">📸</span><span>Camera</span>' +
            '</div>' +
            '<label class="add-face-btn">' +
                '<input type="file" id="' + fileInputId + '" accept="image/*" multiple onchange="handleManageFiles(this.files,\'' + id + '\',\'' + type + '\');this.value=\'\'">' +
                '<span class="af-icon">🖼️</span><span>Upload</span>' +
            '</label>' +
        '</div>' +
        '<div class="manage-preview-grid" id="mgrid-' + type + '-' + id + '"></div>' +
        '<button class="btn-submit-add" id="msubmit-' + type + '-' + id + '" disabled onclick="submitManageEnroll(\'' + id + '\',\'' + type + '\')">📤 Submit Photos</button>' +
    '</div>';
}

/* ── Init: auto-select first company ── */
(function() {
    // Initialize Select2 on staff/gw selects immediately (empty state)
    initSelect2Staff();
    initSelect2Gw();

    var comp = document.getElementById('companySelect');
    var manage = document.getElementById('manageCompanySelect');
    var firstVal = comp.options.length > 1 ? comp.options[1].value : '';
    if (firstVal) {
        comp.value = firstVal;
        onEnrollCompanyChange(firstVal);
        manage.value = firstVal;
        loadStaffListing(firstVal);
    }
})();

// Close header dropdown when clicking outside
document.addEventListener('click', function(e) {
    var menu = document.getElementById('headerMenu');
    if (menu && !menu.contains(e.target)) {
        menu.classList.remove('open');
    }
});
</script>
</body>
</html>