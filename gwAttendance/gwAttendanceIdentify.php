<?php
/**
 * GW Attendance — "Who is in this photo?" (AJAX endpoint)
 *
 * Deploy to: globportal.com/accounts/gwAttendance/gwAttendanceIdentify.php
 * Called by: gwAttendanceReport.php, automatically for every attachment on the
 *            page — there is no button, so this runs unprompted and MUST be
 *            cheap on repeat views. See "Caching" below.
 *
 * Why this file sits here and not on glob.com.my: the photo is a local file in
 * this folder, and the roster and names live in the databases this host already
 * talks to. The face service is reachable only from glob.com.my, so the one
 * thing that has to cross hosts is the image itself:
 *
 *   browser -> gwAttendanceIdentify.php (globportal.com, this file)
 *           -> group_identify.php       (glob.com.my, proxy)
 *           -> 127.0.0.1:5000/identify_group  (Python, face matching)
 *
 * Pooled matching
 * ---------------
 * This file sends the mandor's attendance pool with the image: the GW assigned
 * or allocated to them on the photo's date, plus the mandor themselves. The
 * service matches against that handful of people FIRST and only widens the
 * search for faces it could not place.
 *
 * That ordering is the single biggest accuracy win available here. Searching all
 * ~2,800 enrolled company-1 faces for a 40px face in a sunlit group shot
 * returned GWs from Beaufort and Sipitang standing in a Papar road crew. A pool
 * is ~12 encodings — around 200x smaller — so there is barely room for a
 * stranger to win, and anyone named from outside it had to clear a higher bar.
 *
 * Caching
 * -------
 * Results are cached per attachment in gw_photo_scan / gw_photo_face (see
 * gw_photo_identify_tables.sql). A photo's pixels never change, so a scan is
 * valid forever; SCANNER_VERSION invalidates everything at once when the
 * matching rules change.
 *
 * Request  (POST, JSON or form-encoded):
 *   { attachment_id: <gw_attendance_attachment_id>, force: 0|1 }
 * Response (JSON):
 *   {
 *     success, attachment_id, cached,
 *     faces_detected, pool_matched, outside_matched, unmatched,
 *     people: [ { type:'gw'|'staff', name, code, district, gw_id, comp_id,
 *                 mymk_id, confidence, in_pool, resolved } ]
 *   }
 */

// Any stray output from the includes (a notice, a stray newline) would land in
// front of the JSON and break the parse on the other end, so buffer it away.
ob_start();
include_once("includes/db.php");
include_once("includes/access.php");
ob_end_clean();

header('Content-Type: application/json');

/** The proxy on glob.com.my that can reach the Python service. */
define('GROUP_IDENTIFY_URL', 'https://glob.com.my/it/mkPortal/site_checkin/face_services/group_identify.php');

/** Bump whenever the matching rules change (thresholds, pool rules, detector).
 *  Cached rows written by an older version are ignored and re-scanned. */
define('SCANNER_VERSION', 'pool-1');

/** How confident an OFF-ROSTER match must be before it is shown at all.
 *
 *  A pool match is a weak claim: ~12 candidates, all of them people the mandor
 *  is supposed to have with them. Naming someone from outside that roster is
 *  drawn from ~2,800 enrolled faces, so a lookalike is far likelier, and the
 *  claim itself is much stronger — "a person who should not be here was here".
 *  The service already applies 0.45 (55% confidence); this raises the bar again
 *  for the one place those names surface.
 *
 *  Applied at DISPLAY time and NOT when caching, so changing it never requires
 *  re-scanning a photo — the raw matches stay in gw_photo_face either way. */
define('OUTSIDE_DISPLAY_THRESHOLD', 0.65);

/** Where this page's attachments live on disk, relative to this file.
 *  Only this one folder: the attachment id is looked up in
 *  gw_attendance_attachments, and every such photo — mandor-recorded and GW
 *  self check-in alike — is written here. */
define('ATTACHMENT_DIR', 'attachments');

/** A group photo takes real time to scan. Must stay above the proxy's own
 *  timeout or this side gives up while the answer is still coming. */
define('IDENTIFY_TIMEOUT', 150);

/** Fail as JSON, always — the caller only ever parses JSON. */
function identifyFail($message, $httpCode = 400, $extra = array())
{
    http_response_code($httpCode);
    echo json_encode(array_merge(array('success' => false, 'error' => $message), $extra));
    exit;
}

// ──────────────────────────────────────────────────────────────────────────
// 1. Input
// ──────────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { identifyFail('Only POST is allowed', 405); }

// Accept either a JSON body or an ordinary form post.
$input = array();
$raw = file_get_contents('php://input');
if ($raw !== "" && $raw !== false)
{
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) { $input = $decoded; }
}
if (!$input && !empty($_POST)) { $input = $_POST; }

$attachmentId = isset($input['attachment_id']) ? (int)$input['attachment_id'] : 0;
$force        = !empty($input['force']);

if ($attachmentId <= 0) { identifyFail('Missing or invalid attachment_id'); }

// ──────────────────────────────────────────────────────────────────────────
// 2. The attachment, which is also the authorisation check: an id that is not
//    a live attachment row never reaches the filesystem.
// ──────────────────────────────────────────────────────────────────────────

$queryAtt = "SELECT gw_attendance_attachment_id   AS id,
                    gw_attendance_attachment_filename AS filename,
                    gw_attendance_attachment_by   AS staff_id,
                    gw_attendance_attachment_date AS date
             FROM gw_attendance_attachments
             WHERE gw_attendance_attachment_id = '$attachmentId'
               AND gw_attendance_attachment_deleted = '0'
             LIMIT 1";
$resultAtt = mysql_query($queryAtt) or identifyFail('Database error', 500);
if (mysql_num_rows($resultAtt) == 0) { identifyFail('Unknown attachment', 404); }
$attachment = mysql_fetch_array($resultAtt);

// ──────────────────────────────────────────────────────────────────────────
// 3. Cache
// ──────────────────────────────────────────────────────────────────────────

/**
 * Query only: a previous scan of this attachment, or null.
 * A row written by an older SCANNER_VERSION counts as a miss, so changing the
 * matching rules silently re-scans everything without any manual cache clearing.
 */
function readScanCache($attachmentId)
{
    $version = mysql_real_escape_string(SCANNER_VERSION);
    $sql = "SELECT faces_detected, pool_matched, outside_matched, unmatched
            FROM gw_photo_scan
            WHERE gw_attendance_attachment_id = '" . (int)$attachmentId . "'
              AND scanner_version = '$version'
            LIMIT 1";
    $res = mysql_query($sql);
    if (!$res || mysql_num_rows($res) == 0) { return null; }
    $head = mysql_fetch_array($res);

    $people = array();
    $sqlFaces = "SELECT person, comp_id, is_gw, mymk_id, in_pool, confidence
                 FROM gw_photo_face
                 WHERE gw_attendance_attachment_id = '" . (int)$attachmentId . "'
                 ORDER BY confidence DESC";
    $resFaces = mysql_query($sqlFaces);
    if ($resFaces)
    {
        while ($row = mysql_fetch_array($resFaces)) { $people[] = $row; }
    }

    return array('head' => $head, 'people' => $people);
}

/** Command: replace this attachment's cached scan. */
function writeScanCache($attachmentId, $summary, $people)
{
    $id = (int)$attachmentId;

    mysql_query("DELETE FROM gw_photo_face WHERE gw_attendance_attachment_id = '$id'");
    mysql_query("DELETE FROM gw_photo_scan WHERE gw_attendance_attachment_id = '$id'");

    $version = mysql_real_escape_string(SCANNER_VERSION);
    mysql_query("INSERT INTO gw_photo_scan
                 (gw_attendance_attachment_id, faces_detected, pool_matched,
                  outside_matched, unmatched, pool_size, scanner_version, scanned_at)
                 VALUES ('$id', '" . (int)$summary['faces_detected'] . "',
                         '" . (int)$summary['pool_matched'] . "',
                         '" . (int)$summary['outside_matched'] . "',
                         '" . (int)$summary['unmatched'] . "',
                         '" . (int)$summary['pool_size'] . "',
                         '$version', '" . date("Y-m-d H:i:s") . "')");

    foreach ($people as $p)
    {
        $mymk = ($p['mymk_id'] === null || $p['mymk_id'] === "") ? "NULL" : "'" . (int)$p['mymk_id'] . "'";
        mysql_query("INSERT INTO gw_photo_face
                     (gw_attendance_attachment_id, person, comp_id, is_gw, mymk_id, in_pool, confidence)
                     VALUES ('$id',
                             '" . mysql_real_escape_string($p['person']) . "',
                             '" . mysql_real_escape_string($p['comp_id']) . "',
                             '" . (int)$p['is_gw'] . "',
                             $mymk,
                             '" . (int)$p['in_pool'] . "',
                             '" . (float)$p['confidence'] . "')");
    }
}

// ──────────────────────────────────────────────────────────────────────────
// 4. Names. Shared by the cached and freshly-scanned paths so both describe a
//    person identically.
// ──────────────────────────────────────────────────────────────────────────

/**
 * Query only: one GW row by an arbitrary predicate. Split out so the id lookup
 * and the code lookup below cannot drift in the columns they select.
 */
function lookupGwWhere($where, $order = "")
{
    $sql = "SELECT monthly_assign_gw_id       AS gw_id,
                   monthly_assign_gw_code     AS code,
                   monthly_assign_gw_fullname AS fullname,
                   monthly_assign_gw_district AS district
            FROM monthly_assign_gw
            WHERE $where
            $order
            LIMIT 1";
    $res = mysql_query($sql);
    if (!$res || mysql_num_rows($res) == 0) { return null; }
    return mysql_fetch_array($res);
}

/**
 * Query only: display details for one identified GW.
 *
 * The id (monthly_assign_gw_id, carried on the encoding) is tried first, because
 * a code can be reissued to a different person and the id cannot.
 *
 * But it is only a preference, not the whole answer: 125 live enrolments across
 * 38 codes point at a monthly_assign_gw_id that no longer exists, because a GW
 * re-created under a new id keeps their old code. Giving up there caused two
 * visible faults at once — the person rendered as a bare code instead of a name,
 * and, since the stale id matched no row in the matrix, they were ALSO listed as
 * an extra person standing beside themselves. So a failed id lookup falls back
 * to the code, which recovers the GW for 15 of those 38.
 *
 * The remaining 23 codes have no monthly_assign_gw row at all; those really are
 * gone, and showing the bare code is then the honest answer.
 */
function lookupGw($person, $mymkId)
{
    if ($mymkId !== null && $mymkId !== "" && (int)$mymkId > 0)
    {
        $row = lookupGwWhere("monthly_assign_gw_id = '" . (int)$mymkId . "'");
        if ($row) { return $row; }
    }

    if (trim($person) !== "")
    {
        // One code can span several rows once it has been reissued. Prefer a
        // currently-active GW, then the most recently created, so the name shown
        // is the person working today rather than whoever held the code first.
        return lookupGwWhere(
            "monthly_assign_gw_code = '" . mysql_real_escape_string(trim($person)) . "'",
            "ORDER BY (monthly_assign_gw_status = '1') DESC, monthly_assign_gw_id DESC"
        );
    }

    return null;
}

/**
 * Query only: display details for one identified staff member.
 * (person, comp_id) is the staff key across this system.
 */
function lookupStaff($person, $compId)
{
    $sql = "SELECT id, fullname
            FROM staff_portal2.users
            WHERE person  = '" . mysql_real_escape_string($person) . "'
              AND comp_id = '" . mysql_real_escape_string($compId) . "'
            LIMIT 1";
    $res = mysql_query($sql);
    if (!$res || mysql_num_rows($res) == 0) { return null; }
    return mysql_fetch_array($res);
}

/** Query->view mapper: one identified person, as the report renders them. */
function describePerson($person, $compId, $isGw, $mymkId, $confidence, $inPool)
{
    if ($isGw)
    {
        $row = lookupGw($person, $mymkId);
        // An enrolled face whose GW record is gone is still a real detection —
        // report the code rather than dropping the person from the count.
        return array(
            'type'       => 'gw',
            'name'       => $row ? $row['fullname'] : $person,
            'code'       => $row ? $row['code']     : $person,
            'district'   => $row ? $row['district'] : "",
            'gw_id'      => $row ? (int)$row['gw_id'] : (int)$mymkId,
            'comp_id'    => $compId,
            'mymk_id'    => $mymkId,
            'confidence' => (float)$confidence,
            'in_pool'    => $inPool ? true : false,
            'resolved'   => $row ? true : false,
        );
    }

    $row = lookupStaff($person, $compId);
    return array(
        'type'       => 'staff',
        'name'       => $row ? $row['fullname'] : $person,
        'code'       => $person,
        'district'   => "",
        'gw_id'      => 0,
        'comp_id'    => $compId,
        'mymk_id'    => $row ? $row['id'] : $mymkId,
        'confidence' => (float)$confidence,
        'in_pool'    => $inPool ? true : false,
        'resolved'   => $row ? true : false,
    );
}

/** Sort helper: higher confidence first. */
function compareConfidenceDesc($a, $b)
{
    if ($a['confidence'] == $b['confidence']) { return 0; }
    return ($a['confidence'] > $b['confidence']) ? -1 : 1;
}

/** Command: emit the response both paths share, then stop. */
function respond($attachmentId, $cached, $summary, $people)
{
    usort($people, 'compareConfidenceDesc');

    // Off-roster names below the display floor are dropped here rather than at
    // scan time, so the cache keeps them and the floor can be retuned freely.
    // A face hidden this way is still a face that matched nobody the report
    // will admit to, so it is counted back as unmatched instead of vanishing.
    $shown  = array();
    $hidden = 0;
    foreach ($people as $p)
    {
        if (empty($p['in_pool']) && $p['confidence'] < OUTSIDE_DISPLAY_THRESHOLD) { $hidden++; continue; }
        $shown[] = $p;
    }

    $outsideShown = 0;
    foreach ($shown as $p) { if (empty($p['in_pool'])) { $outsideShown++; } }

    echo json_encode(array(
        'success'         => true,
        'attachment_id'   => (int)$attachmentId,
        'cached'          => $cached ? true : false,
        'faces_detected'  => (int)$summary['faces_detected'],
        'pool_matched'    => (int)$summary['pool_matched'],
        'outside_matched' => $outsideShown,
        'outside_hidden'  => $hidden,
        'unmatched'       => (int)$summary['unmatched'] + $hidden,
        'people'          => array_values($shown),
    ));
    exit;
}

// ── Serve from cache when we can ──
if (!$force)
{
    $cache = readScanCache($attachmentId);
    if ($cache !== null)
    {
        $people = array();
        foreach ($cache['people'] as $row)
        {
            $people[] = describePerson($row['person'], $row['comp_id'], (int)$row['is_gw'],
                                       $row['mymk_id'], $row['confidence'], (int)$row['in_pool']);
        }
        respond($attachmentId, true, array(
            'faces_detected'  => $cache['head']['faces_detected'],
            'pool_matched'    => $cache['head']['pool_matched'],
            'outside_matched' => $cache['head']['outside_matched'],
            'unmatched'       => $cache['head']['unmatched'],
        ), $people);
    }
}

// ──────────────────────────────────────────────────────────────────────────
// 5. The mandor's attendance pool for this date.
//    Deliberately mirrors gwAttendanceReport.php's own roster queries, so the
//    people the report lists as rows and the people the matcher looks for
//    cannot drift apart.
// ──────────────────────────────────────────────────────────────────────────

$staffId = (int)$attachment['staff_id'];

$queryMandor = "SELECT person, comp_id FROM staff_portal2.users WHERE id = '$staffId' LIMIT 1";
$resultMandor = mysql_query($queryMandor);
if (!$resultMandor || mysql_num_rows($resultMandor) == 0) { identifyFail('Mandor not found for this photo', 404); }
$mandor = mysql_fetch_array($resultMandor);

// The mandor is usually standing in their own muster photo.
$poolStaff = array(array('person' => $mandor['person'], 'comp_id' => (string)$mandor['comp_id']));

$poolCodes = array();
$seenGwIds = array();

// 1. Permanently assigned GW (monthly_assign_gw.boss_id is the mandor's person).
$queryPerm = "SELECT monthly_assign_gw_id AS gw_id, monthly_assign_gw_code AS code
              FROM monthly_assign_gw
              WHERE boss_id = '" . mysql_real_escape_string($mandor['person']) . "'";
$resultPerm = mysql_query($queryPerm) or identifyFail('Database error', 500);
while ($row = mysql_fetch_array($resultPerm))
{
    if (trim($row['code']) === "") { continue; }
    $poolCodes[trim($row['code'])] = true;
    $seenGwIds[$row['gw_id']] = true;
}

// 2. GW temporarily allocated to this mandor on the photo's date.
//    gw_allocations stores Y-m-d; the attachment date is Y/m/d.
$periodYmd = date('Y-m-d', strtotime($attachment['date']));
$queryAlloc = "SELECT gw.monthly_assign_gw_id AS gw_id, gw.monthly_assign_gw_code AS code
               FROM gw_allocations a
               INNER JOIN monthly_assign_gw gw
                       ON a.monthly_assign_gw_id = gw.monthly_assign_gw_id
               WHERE a.mandor_id = '$staffId'
                 AND a.deleted = 0
                 AND a.date_from <= '$periodYmd'
                 AND a.date_to   >= '$periodYmd'
                 AND gw.monthly_assign_gw_status = '1'";
$resultAlloc = mysql_query($queryAlloc) or identifyFail('Database error', 500);
while ($row = mysql_fetch_array($resultAlloc))
{
    if (isset($seenGwIds[$row['gw_id']])) { continue; }
    if (trim($row['code']) === "") { continue; }
    $poolCodes[trim($row['code'])] = true;
    $seenGwIds[$row['gw_id']] = true;
}

// ──────────────────────────────────────────────────────────────────────────
// 6. Read the photo and ask the face service who is in it
// ──────────────────────────────────────────────────────────────────────────

$filename = basename($attachment['filename']);
$path = __DIR__ . DIRECTORY_SEPARATOR . ATTACHMENT_DIR . DIRECTORY_SEPARATOR . $filename;
if (!is_file($path)) { identifyFail('Photo file not found on the server', 404); }

$bytes = file_get_contents($path);
if ($bytes === false || $bytes === "") { identifyFail('Could not read the photo file', 500); }

$payload = json_encode(array(
    'image' => base64_encode($bytes),
    'pool'  => array(
        'gw_codes' => array_keys($poolCodes),
        'staff'    => $poolStaff,
    ),
    'search_outside' => true,
));

$ch = curl_init(GROUP_IDENTIFY_URL);
curl_setopt_array($ch, array(
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => IDENTIFY_TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => 10,
));
$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false)
{
    error_log("gwAttendanceIdentify connection failed: {$curlError}");
    identifyFail('Face recognition service unavailable.', 503);
}
if ($httpCode !== 200)
{
    error_log("gwAttendanceIdentify HTTP {$httpCode}: {$response}");
    identifyFail('Face service error', 502);
}

$result = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($result))
{
    error_log("gwAttendanceIdentify invalid JSON: {$response}");
    identifyFail('Invalid face service response', 502);
}
if (empty($result['success']))
{
    identifyFail(isset($result['error']) ? $result['error'] : 'Face service could not read this photo', 200);
}

// ──────────────────────────────────────────────────────────────────────────
// 7. Store and reply
// ──────────────────────────────────────────────────────────────────────────

$faces = isset($result['results']) && is_array($result['results']) ? $result['results'] : array();

$cacheRows = array();   // what gets stored
$people    = array();   // what gets returned
foreach ($faces as $face)
{
    if (empty($face['matched'])) { continue; }

    $person = isset($face['person'])  ? $face['person']  : "";
    $compId = isset($face['comp_id']) ? $face['comp_id'] : "";
    $isGw   = !empty($face['is_gw']) ? 1 : 0;
    $mymkId = isset($face['mymk_id']) ? $face['mymk_id'] : null;
    $conf   = isset($face['confidence']) ? (float)$face['confidence'] : 0;
    $inPool = !empty($face['in_pool']) ? 1 : 0;

    $cacheRows[] = array('person' => $person, 'comp_id' => $compId, 'is_gw' => $isGw,
                         'mymk_id' => $mymkId, 'in_pool' => $inPool, 'confidence' => $conf);
    $people[] = describePerson($person, $compId, $isGw, $mymkId, $conf, $inPool);
}

$summary = array(
    'faces_detected'  => isset($result['faces_detected']) ? (int)$result['faces_detected'] : count($faces),
    'pool_matched'    => isset($result['pool_matched_count']) ? (int)$result['pool_matched_count'] : 0,
    'outside_matched' => isset($result['outside_matched_count']) ? (int)$result['outside_matched_count'] : 0,
    'unmatched'       => isset($result['unmatched_count']) ? (int)$result['unmatched_count'] : 0,
    'pool_size'       => isset($result['pool_size']) ? (int)$result['pool_size'] : 0,
);

writeScanCache($attachmentId, $summary, $cacheRows);

respond($attachmentId, false, $summary, $people);
