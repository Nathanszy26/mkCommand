<?php
/**
 * Face Recognize Proxy
 * 
 * Lives on glob.com.my — forwards requests to the local Python face service.
 * Called by staffFaceCheckIn.php on globportal.com.
 * 
 * URL: https://glob.com.my/it/mkPortal/site_checkin/face_services/face_recognize.php
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
    echo json_encode(['error' => 'Only POST requests allowed']);
    exit;
}

$rawData = file_get_contents('php://input');

$ch = curl_init('http://127.0.0.1:5000/recognize');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $rawData,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    error_log("Face service proxy error: " . $curlError);
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'matched' => false,
        'error' => 'Face recognition service unavailable.'
    ]);
    exit;
}

http_response_code($httpCode);
echo $response;