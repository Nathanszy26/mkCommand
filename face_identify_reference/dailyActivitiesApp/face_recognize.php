<?php
/**
 * GW Face Recognition Proxy
 *
 * Deploy to: /var/www/html/glob/it/mkPortal/dailyActivitiesApp/face_services/face_recognize.php
 * Public URL: https://glob.com.my/it/mkPortal/dailyActivitiesApp/face_services/face_recognize.php
 *
 * Responsibility: accept RN request, forward to local Python service on 127.0.0.1:5001, return response.
 * No business logic here. Matching + DB access live in face_service.py.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['matched' => false, 'error' => 'Only POST is allowed']);
    exit;
}

const FACE_SERVICE_URL = 'http://127.0.0.1:5001/recognize';
const MATCH_THRESHOLD  = 0.6; // mirror of Python MATCH_TOLERANCE, used as a safety floor

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    http_response_code(400);
    echo json_encode(['matched' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Fail fast on missing fields
foreach (['gw_id', 'image'] as $field) {
    if (!isset($data[$field]) || $data[$field] === '') {
        http_response_code(400);
        echo json_encode(['matched' => false, 'error' => "Missing required field: {$field}"]);
        exit;
    }
}

$gwId  = (int)$data['gw_id'];
$image = $data['image'];

// Strip data URI prefix if present
if (strpos($image, ',') !== false) {
    $image = explode(',', $image, 2)[1];
}

$payload = json_encode([
    'gw_id' => $gwId,
    'image' => $image,
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
    error_log("GW face service connection failed: {$curlError}");
    http_response_code(502);
    echo json_encode(['matched' => false, 'error' => 'Face service unavailable']);
    exit;
}

if ($httpCode !== 200) {
    error_log("GW face service HTTP {$httpCode}: {$response}");
    http_response_code(502);
    echo json_encode(['matched' => false, 'error' => 'Face service error']);
    exit;
}

$result = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("GW face service invalid JSON: {$response}");
    http_response_code(502);
    echo json_encode(['matched' => false, 'error' => 'Invalid face service response']);
    exit;
}

// Safety floor: enforce threshold on PHP side too in case Python is more lenient
if (!empty($result['matched']) && isset($result['confidence']) && (float)$result['confidence'] < MATCH_THRESHOLD) {
    $result['matched'] = false;
}

echo json_encode($result);