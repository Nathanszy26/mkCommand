<?php
/**
 * Staff Face Identification Proxy (1:N)
 *
 * Deploy to: /var/www/html/glob/it/mkPortal/site_checkin/face_services/staff_identify.php
 * Public URL: https://glob.com.my/it/mkPortal/site_checkin/face_services/staff_identify.php
 *
 * Forwards { image, comp_id?, include_gw? } to the local Python service
 * /identify on 127.0.0.1:5000 — the same service face_recognize.php already
 * proxies to, on a DIFFERENT endpoint. face_recognize.php and the 1:1 check-in
 * flow behind it are untouched; this file is additive, exactly as
 * face_salary_identify.php is for the GW salary scan.
 *
 * Called by MK Command's "Staff Search (By Photo)".
 *
 * Replies with the IDENTITY only — (person, comp_id, is_gw) — because the app
 * already has a staff directory for names, photos and departments. Nothing here
 * reads staff_portal2.
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

const FACE_SERVICE_URL = 'http://127.0.0.1:5000/identify';

/** Safety floor, mirroring Python's MATCH_TOLERANCE of 0.6 (confidence = 1 - distance).
 *  Enforced here as well so a service misconfigured to be more lenient cannot
 *  put the wrong name in front of someone. */
const MATCH_THRESHOLD = 0.4;

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    http_response_code(400);
    echo json_encode(['matched' => false, 'error' => 'Invalid JSON']);
    exit;
}

if (!isset($data['image']) || $data['image'] === '') {
    http_response_code(400);
    echo json_encode([
        'matched'       => false,
        'error'         => 'Missing required field: image',
        'received_keys' => array_keys($data),
        'raw_len'       => strlen($raw),
    ]);
    exit;
}

// Strip the data-URI prefix if the client sent one; Python handles either, but
// sending the bare payload keeps the request smaller.
$image = $data['image'];
if (strpos($image, ',') !== false) {
    $image = explode(',', $image, 2)[1];
}

$payload = ['image' => $image];

// Optional company scope. Absent means "search every company", which is what
// MK Command wants: committees and reporting lines both cross subsidiaries.
if (isset($data['comp_id']) && $data['comp_id'] !== '') {
    $payload['comp_id'] = (string)$data['comp_id'];
}
if (isset($data['include_gw'])) {
    $payload['include_gw'] = (bool)$data['include_gw'];
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

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    error_log("Staff identify connection failed: {$curlError}");
    http_response_code(503);
    echo json_encode(['matched' => false, 'error' => 'Face recognition service unavailable.']);
    exit;
}

if ($httpCode !== 200) {
    error_log("Staff identify HTTP {$httpCode}: {$response}");
    http_response_code(502);
    echo json_encode(['matched' => false, 'error' => 'Face service error']);
    exit;
}

$result = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("Staff identify invalid JSON: {$response}");
    http_response_code(502);
    echo json_encode(['matched' => false, 'error' => 'Invalid face service response']);
    exit;
}

// A match the service was not confident about is reported as no match: naming
// the wrong colleague is worse than naming nobody.
if (!empty($result['matched'])
    && isset($result['confidence'])
    && (float)$result['confidence'] < MATCH_THRESHOLD) {
    $result['matched'] = false;
    $result['error']   = 'No confident match. Try a clearer photo.';
}

echo json_encode($result);
