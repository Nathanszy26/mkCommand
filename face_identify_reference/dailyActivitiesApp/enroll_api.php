<?php
/**
 * GW Face Enrollment API
 *
 * Deploy to: /var/www/html/glob/it/mkPortal/dailyActivitiesApp/face_services/enroll_api.php
 * Public URL: https://glob.com.my/it/mkPortal/dailyActivitiesApp/face_services/enroll_api.php
 *
 * Actions (GET or POST with action=...):
 *   lookup     GET  ?gw_id=<int>                       -> {id, fullname, ...}
 *   enroll     POST {gw_id, image(base64)}             -> proxy to Python /enroll
 *   list       GET  ?gw_id=<int>                       -> proxy to Python /list
 *   delete     POST {id}                               -> proxy to Python /delete
 *   photo      GET  ?enc_id=<int>                      -> stream photo bytes
 *
 * Note: No authentication. Restrict access at the web-server or network layer if required.
 */

header('X-Content-Type-Options: nosniff');

// -------------------- CONFIG --------------------

const FACE_SERVICE_BASE = 'http://127.0.0.1:5001';
const SERVICE_DIR       = '/var/www/html/glob/it/mkPortal/dailyActivitiesApp/face_services';

// Primary DB = evaluation (where monthly_assign_gw lives).
// gw_face_encodings is referenced cross-DB as `mkPortal.gw_face_encodings`.
// prog3 must have SELECT/INSERT/UPDATE on both databases.
const DB_HOST = 'globportal.com';
const DB_PORT = 33060;
const DB_USER = 'prog3';
const DB_PASS = 'prog3@2023';
const DB_NAME = 'evaluation';

// -------------------- HELPERS --------------------

function json_response($arr, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($arr);
    exit;
}

function post_json() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function forward_to_face_service($path, $method, $payload = null) {
    $ch = curl_init(FACE_SERVICE_BASE . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 5,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload ?: []);
        $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        error_log("Face service call failed ({$path}): {$err}");
        json_response(['success' => false, 'error' => 'Face service unavailable'], 502);
    }
    $decoded = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Face service bad JSON ({$path}): {$body}");
        json_response(['success' => false, 'error' => 'Invalid face service response'], 502);
    }
    json_response($decoded, $code);
}

function db_connect() {
    try {
        return new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (PDOException $e) {
        error_log('DB connect failed: ' . $e->getMessage());
        json_response(['success' => false, 'error' => 'Database unavailable'], 500);
    }
}

// -------------------- ROUTE --------------------

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    case 'lookup': {
        $gwId = (int)($_GET['gw_id'] ?? 0);
        if ($gwId <= 0) json_response(['success' => false, 'error' => 'Invalid gw_id'], 400);

        $pdo = db_connect();
        // monthly_assign_gw lives in evaluation (the default DB for this connection).
        $stmt = $pdo->prepare(
            'SELECT monthly_assign_gw_id AS id,
                    monthly_assign_gw_fullname AS fullname,
                    monthly_assign_gw_code AS code,
                    monthly_assign_gw_status AS status,
                    mymk_daily_check_in_default AS checkin_default,
                    mymk_daily_check_in_face AS checkin_face
             FROM monthly_assign_gw
             WHERE monthly_assign_gw_id = ? LIMIT 1'
        );
        $stmt->execute([$gwId]);
        $row = $stmt->fetch();
        if (!$row) json_response(['success' => false, 'error' => 'GW not found'], 404);
        json_response(['success' => true, 'gw' => $row]);
    }

    case 'enroll': {
        $data = post_json();
        if (empty($data['gw_id']) || empty($data['image'])) {
            json_response(['success' => false, 'error' => 'Missing gw_id or image'], 400);
        }
        forward_to_face_service('/enroll', 'POST', [
            'gw_id' => (int)$data['gw_id'],
            'image' => $data['image'],
        ]);
    }

    case 'list': {
        $gwId = (int)($_GET['gw_id'] ?? 0);
        if ($gwId <= 0) json_response(['success' => false, 'error' => 'Invalid gw_id'], 400);
        forward_to_face_service('/list?gw_id=' . $gwId, 'GET');
    }

    case 'delete': {
        $data = post_json();
        if (empty($data['id'])) {
            json_response(['success' => false, 'error' => 'Missing id'], 400);
        }
        forward_to_face_service('/delete', 'POST', ['id' => (int)$data['id']]);
    }

    case 'photo': {
        $encId = (int)($_GET['enc_id'] ?? 0);
        if ($encId <= 0) { http_response_code(400); exit; }

        $pdo = db_connect();
        // gw_face_encodings lives in mkPortal — cross-DB read from evaluation default.
        $stmt = $pdo->prepare("SELECT photo_path FROM mkPortal.gw_face_encodings WHERE id = ? AND deleted = '0' LIMIT 1");
        $stmt->execute([$encId]);
        $row = $stmt->fetch();
        if (!$row || empty($row['photo_path'])) { http_response_code(404); exit; }

        // Confine to SERVICE_DIR to prevent traversal.
        $abs = realpath(SERVICE_DIR . '/' . $row['photo_path']);
        $base = realpath(SERVICE_DIR);
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

    default:
        json_response(['success' => false, 'error' => 'Unknown action'], 400);
}