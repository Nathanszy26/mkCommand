<?php
/**
 * GW Salary Face Identification Proxy (1:N)
 *
 * Deploy to: /var/www/html/glob/it/mkPortal/dailyActivitiesApp/face_services/face_salary_identify.php
 * Public URL: https://glob.com.my/it/mkPortal/dailyActivitiesApp/face_services/face_salary_identify.php
 *
 * Forwards { comp_id, image } to the local Python service /identify on 127.0.0.1:5001.
 * Separate from face_recognize.php so the daily-activity 1:1 flow is untouched.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['matched' => false, 'error' => 'Only POST is allowed']);
    exit;
}

const FACE_SERVICE_URL = 'http://127.0.0.1:5001/identify';
const MATCH_THRESHOLD  = 0.6; // safety floor, mirrors Python MATCH_TOLERANCE

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    http_response_code(400);
    echo json_encode(['matched' => false, 'error' => 'Invalid JSON']);
    exit;
}

// foreach (['comp_id', 'image', 'user_id'] as $field) {
//     if (!isset($data[$field]) || $data[$field] === '') {
//         http_response_code(400);
//         echo json_encode([
//             'matched'       => false,
//             'error'         => "Missing required field: {$field}",
//             'received_keys' => array_keys($data),       // debug: what the proxy actually got
//             'raw_len'       => strlen($raw),            // debug: was the body received at all
//         ]);
//         exit;
//     }
// }

foreach (['comp_id', 'image'] as $field) {
    if (!isset($data[$field]) || $data[$field] === '') {
        http_response_code(400);
        echo json_encode([
            'matched'       => false,
            'error'         => "Missing required field: {$field}",
            'received_keys' => array_keys($data),       // debug: what the proxy actually got
            'raw_len'       => strlen($raw),            // debug: was the body received at all
        ]);
        exit;
    }
}

$image = $data['image'];
if (strpos($image, ',') !== false) {
    $image = explode(',', $image, 2)[1];
}

$payload = json_encode([
    'comp_id' => (string)$data['comp_id'],
    'user_id' => (int)$data['user_id'],
    'image'   => $image,
]);

$ch = curl_init(FACE_SERVICE_URL);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 5,
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    error_log("GW salary identify connection failed: {$curlError}");
    http_response_code(502);
    echo json_encode(['matched' => false, 'error' => 'Face service unavailable']);
    exit;
}
if ($httpCode !== 200) {
    error_log("GW salary identify HTTP {$httpCode}: {$response}");
    http_response_code(502);
    echo json_encode(['matched' => false, 'error' => 'Face service error']);
    exit;
}

$result = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("GW salary identify invalid JSON: {$response}");
    http_response_code(502);
    echo json_encode(['matched' => false, 'error' => 'Invalid face service response']);
    exit;
}

// Safety floor: enforce threshold on PHP side too.
if (!empty($result['matched']) && isset($result['confidence']) && (float)$result['confidence'] < MATCH_THRESHOLD) {
    $result['matched'] = false;
    $result['error'] = 'No confident match. Try again or use manual capture.';
}

echo json_encode($result);