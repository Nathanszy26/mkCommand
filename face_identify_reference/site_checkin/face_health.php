<?php
/**
 * Face Service Health Check Proxy
 * 
 * Test URL: https://glob.com.my/it/mkPortal/site_checkin/face_services/face_health.php
 * You can open this in your browser to check if the Python service is running.
 */

header('Content-Type: application/json');

$ch = curl_init('http://127.0.0.1:5000/health');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_CONNECTTIMEOUT => 3,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(503);
    echo json_encode([
        'status' => 'error',
        'message' => 'Face recognition service is NOT running.',
        'error' => $curlError,
        'help' => 'SSH into server and run: sudo systemctl start face-service'
    ]);
    exit;
}

echo $response;