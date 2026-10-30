<?php
/**
 * API/jkr_ncr_probe.php — throwaway diagnostic for jkr_ncr_oe_summary.php.
 *
 * Answers the two questions that decide every remaining failure mode, in one
 * page load:
 *   1. Can THIS SERVER reach the JKR inspection API at all?
 *   2. What fields does an item actually carry — i.e. are NCR_ISSUED_DATE_FIELD
 *      and NCR_CLOSED_DATE_FIELD named correctly?
 *
 * ONE district, ONE month, plain text, errors displayed. Nothing is cached and
 * nothing is written. Delete it once the endpoint is green.
 *
 *   .../API/jkr_ncr_probe.php                       first roster district, this month
 *   .../API/jkr_ncr_probe.php?district=19&month=07&year=2026
 */

header('Content-Type: text/plain; charset=UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "PHP " . PHP_VERSION . "\n";
echo "curl      : " . (function_exists('curl_init') ? 'yes' : 'NO — ext-curl missing') . "\n";
echo "curl_multi: " . (function_exists('curl_multi_init') ? 'yes' : 'NO — the endpoint cannot run') . "\n";
echo "simplexml : " . (function_exists('simplexml_load_string') ? 'yes' : 'NO — XML replies cannot be parsed') . "\n";
echo str_repeat('-', 72) . "\n";

/* ---- 1. district ------------------------------------------------------- */

$district = isset($_GET['district']) ? trim($_GET['district']) : '';
$month    = isset($_GET['month'])    ? sprintf('%02d', (int)$_GET['month']) : date('m');
$year     = isset($_GET['year'])     ? sprintf('%04d', (int)$_GET['year'])  : date('Y');

if ($district === '') {
    // Proves the require path and the roster read at the same time — if either
    // is broken the endpoint fails here too, before it ever reaches JKR.
    $common = __DIR__ . '/oe_api_common.php';
    if (!is_readable($common)) {
        exit("STOP: oe_api_common.php is not beside this file ({$common}).\n"
           . "     The endpoint's require_once fails the same way, which emits a PHP\n"
           . "     fatal instead of JSON and shows up as \"returned non-JSON\".\n");
    }
    require_once $common;

    $roster = oe_district_roster(oe_connect(GLOB_DB), date('Y-m-d'));
    echo "roster: " . count($roster) . " active districts\n";

    $codes = array();
    foreach ($roster as $d) {
        if ($d['district_code'] !== '') $codes[] = $d['district_code'];
    }
    echo "district_code values: " . (empty($codes) ? '(NONE — this alone fails the endpoint)' : implode(', ', $codes)) . "\n";
    if (empty($codes)) exit;

    $district = $codes[0];
}

$url = 'https://roadmaintenance.jkr.sabah.gov.my/inspection-api/report/globinaco'
     . '?' . http_build_query(array('month' => $month, 'year' => $year, 'district' => $district));

echo str_repeat('-', 72) . "\n";
echo "GET {$url}\n";

/* ---- 2. transport ------------------------------------------------------ */

$t  = microtime(true);
$ch = curl_init($url);
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_ENCODING       => '',
    CURLOPT_HTTPHEADER     => array('Accept: application/xml, text/xml, application/json'),
));
$body   = curl_exec($ch);
$errNo  = curl_errno($ch);
$errMsg = curl_error($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype  = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

printf("took %.2fs | HTTP %d | Content-Type: %s\n", microtime(true) - $t, $status, $ctype);

if ($errNo !== 0) {
    echo "\nCURL ERROR {$errNo}: {$errMsg}\n\n";
    echo "  errno 6  — DNS: the server cannot resolve the JKR host.\n";
    echo "  errno 7  — refused/filtered: outbound HTTPS to that host is blocked.\n";
    echo "  errno 28 — timeout: reachable but slow; the report will not survive this.\n";
    echo "  errno 60 — TLS: the CA bundle cannot verify JKR's certificate.\n";
    echo "\nAll four are server/network problems, not code. The endpoint reports each\n";
    echo "of them verbatim rather than printing a plausible zero.\n";
    exit;
}
if ($status !== 200) {
    echo "\nBody (first 1000 chars):\n" . substr($body, 0, 1000) . "\n";
    exit;
}

echo "body length: " . strlen($body) . " bytes\n";
echo str_repeat('-', 72) . "\n";
echo "RAW BODY (first 2000 chars)\n" . substr($body, 0, 2000) . "\n";
echo str_repeat('-', 72) . "\n";

/* ---- 3. the field names ------------------------------------------------ */

$trimmed = ltrim((string)$body, " \t\n\r\0\x0B");
if (substr($trimmed, 0, 3) === "\xEF\xBB\xBF") $trimmed = substr($trimmed, 3);
$trimmed = ltrim($trimmed, " \t\n\r\0\x0B");

$total = null;
$first = array();
$count = 0;

if (isset($trimmed[0]) && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
    $d = json_decode($trimmed, true);
    if (!is_array($d)) exit("Body is not parseable JSON: " . json_last_error_msg() . "\n");
    echo "format: JSON\n";
    $total = isset($d['total']) ? (int)$d['total'] : null;
    $items = (isset($d['data']) && is_array($d['data'])) ? $d['data'] : array();
    $count = count($items);
    if ($count > 0 && is_array($items[0])) $first = $items[0];
} elseif (isset($trimmed[0]) && $trimmed[0] === '<') {
    $xml = simplexml_load_string($trimmed);
    if ($xml === false) exit("Body is not parseable XML.\n");
    echo "format: XML\n";
    $total = isset($xml->total) ? (int)$xml->total : null;
    if (isset($xml->data->item)) {
        foreach ($xml->data->item as $item) {
            $count++;
            if ($count === 1) {
                foreach ($item->children() as $name => $child) {
                    $first[(string)$name] = ($child->count() > 0) ? '(nested list)' : (string)$child;
                }
            }
        }
    }
} else {
    exit("Body is neither JSON nor XML — see the raw body above.\n");
}

echo "total (reported): " . ($total === null ? '(absent — the endpoint stops here)' : $total) . "\n";
echo "items (returned): {$count}\n";

if ($total !== null && $count !== $total) {
    echo "\n*** PAGINATED: returned {$count} of {$total}. The endpoint refuses to count a\n";
    echo "    short page, because every line would be understated. ***\n";
}

if ($count === 0) {
    echo "\nNo items for this district-month. That is a legitimate zero, not an error —\n";
    echo "try another district or an earlier month to see the field names.\n";
    exit;
}

echo str_repeat('-', 72) . "\n";
echo "FIELDS ON ITEM[0] — these are what the two switch points must name:\n\n";
foreach ($first as $k => $v) {
    $val = is_scalar($v) ? (string)$v : '(' . gettype($v) . ')';
    if (strlen($val) > 60) $val = substr($val, 0, 60) . '...';
    printf("  %-28s = %s\n", $k, $val);
}

echo "\n" . str_repeat('-', 72) . "\n";
$keys = array_keys($first);
echo "status field present : " . (in_array('status', $keys, true) ? 'yes' : 'NO') . "\n";
echo "NCR_ISSUED_DATE_FIELD: set this to whichever field above is the ISSUE date\n";
echo "NCR_CLOSED_DATE_FIELD: set this to whichever is the CLOSING date\n";
echo "                       (expect it NULL/empty on New and Acknowledged rows)\n";