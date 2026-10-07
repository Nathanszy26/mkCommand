<?php
/**
 * Group Face Identification Proxy (1:N, many faces)
 *
 * Deploy to: /var/www/html/glob/it/mkPortal/site_checkin/face_services/group_identify.php
 * Public URL: https://glob.com.my/it/mkPortal/site_checkin/face_services/group_identify.php
 *
 * Forwards { image, comp_id?, include_gw? } to the local Python service
 * /identify_group on 127.0.0.1:5000 — the same service staff_identify.php
 * proxies to, on a DIFFERENT endpoint. /recognize, /identify and the check-in
 * flows behind them are untouched; this file is additive, exactly as
 * staff_identify.php was.
 *
 * Called by gwAttendanceIdentify.php on globportal.com, which owns the photo
 * and resolves the returned identities to names.
 *
 * Replies with one entry per detected face — (person, comp_id, is_gw) only.
 * No name lookup here: the caller already has the directory.
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
    echo json_encode(['success' => false, 'error' => 'Only POST is allowed']);
    exit;
}

const FACE_SERVICE_URL = 'http://127.0.0.1:5000/identify_group';

/** Safety floors mirroring Python's POOL_TOLERANCE / OUTSIDE_TOLERANCE
 *  (confidence = 1 - distance). Enforced here too so a service tuned more
 *  leniently cannot put the wrong name against a worker. Both are stricter than
 *  staff_identify.php's 0.4 on purpose: nobody confirms a group listing face by
 *  face, so a wrong name in it is simply believed.
 *
 *  The outside floor is the higher of the two because naming someone who is not
 *  on this mandor's roster is the stronger claim, drawn from a far larger pool
 *  of lookalikes. */
const POOL_THRESHOLD    = 0.50;   // 1 - 0.50
const OUTSIDE_THRESHOLD = 0.55;   // 1 - 0.45

/** A group photo is a much bigger job than a single face: 1600px, upsampled
 *  detection, then one distance sweep per face. 30s (staff_identify.php's
 *  timeout) cuts off a large muster shot mid-scan. */
const CURL_TIMEOUT = 120;

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

if (!isset($data['image']) || $data['image'] === '') {
    http_response_code(400);
    echo json_encode([
        'success'       => false,
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

// The caller's attendance pool: the GW assigned or allocated to this mandor on
// this date, plus the staff with them. Passed straight through — the service
// matches against it FIRST and only then widens the search. This is what keeps
// a GW from another district out of a Papar road crew's photo.
if (isset($data['pool']) && is_array($data['pool'])) {
    $pool = [];
    if (!empty($data['pool']['gw_codes']) && is_array($data['pool']['gw_codes'])) {
        $pool['gw_codes'] = array_values(array_filter(
            array_map('strval', $data['pool']['gw_codes']),
            function ($c) { return $c !== ''; }
        ));
    }
    if (!empty($data['pool']['staff']) && is_array($data['pool']['staff'])) {
        $staff = [];
        foreach ($data['pool']['staff'] as $s) {
            if (is_array($s) && isset($s['person']) && $s['person'] !== '') {
                $staff[] = [
                    'person'  => (string)$s['person'],
                    'comp_id' => isset($s['comp_id']) ? (string)$s['comp_id'] : '1',
                ];
            }
        }
        if ($staff) { $pool['staff'] = $staff; }
    }
    if ($pool) { $payload['pool'] = $pool; }
}

// Whether a face the pool could not place may be looked for in the wider
// company-1 gallery. Default (absent) is yes.
if (isset($data['search_outside'])) {
    $payload['search_outside'] = (bool)$data['search_outside'];
}

$ch = curl_init(FACE_SERVICE_URL);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => CURL_TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => 10,
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    error_log("Group identify connection failed: {$curlError}");
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Face recognition service unavailable.']);
    exit;
}

if ($httpCode !== 200) {
    error_log("Group identify HTTP {$httpCode}: {$response}");
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Face service error']);
    exit;
}

$result = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("Group identify invalid JSON: {$response}");
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Invalid face service response']);
    exit;
}

// Safety floor, applied per face against the floor for the stage that produced
// it. A face demoted here becomes an unmatched face rather than disappearing,
// so the counts still describe the photo.
if (!empty($result['results']) && is_array($result['results'])) {
    $matchedCount = 0;
    $poolCount    = 0;

    foreach ($result['results'] as $i => $face) {
        $floor = !empty($face['in_pool']) ? POOL_THRESHOLD : OUTSIDE_THRESHOLD;

        if (!empty($face['matched'])
            && isset($face['confidence'])
            && (float)$face['confidence'] < $floor) {
            $result['results'][$i]['matched'] = false;
            $result['results'][$i]['in_pool'] = false;
            $result['results'][$i]['person']  = null;
            $result['results'][$i]['comp_id'] = null;
            $result['results'][$i]['is_gw']   = null;
            $result['results'][$i]['mymk_id'] = null;
        }

        if (!empty($result['results'][$i]['matched'])) {
            $matchedCount++;
            if (!empty($result['results'][$i]['in_pool'])) { $poolCount++; }
        }
    }

    $result['matched_count']         = $matchedCount;
    $result['pool_matched_count']    = $poolCount;
    $result['outside_matched_count'] = $matchedCount - $poolCount;
    $result['unmatched_count']       = count($result['results']) - $matchedCount;
}

echo json_encode($result);
