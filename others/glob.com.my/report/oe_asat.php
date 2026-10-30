<?php
// report/oe_asat.php — back-dated render of the O&E Summary, for verification.
// TEMPORARY: delete once the RO3/RO4/RO5&7 week fix is confirmed. Every load
// re-queries the source databases and writes to oe_summary_results.
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/oe_summary_lib.php';
require_once __DIR__ . '/report_transport.php';

const OE_ASAT_TITLE = 'Weekly O&E Summary Report';
const OE_ASAT_STEM  = 'weekly-oe-summary-report';

// Strict: createFromFormat alone accepts 2026-13-45 and rolls it over, so the
// round-trip check is what actually rejects a bad date. '!' zeroes the time
// fields, otherwise the current clock leaks in and $today carries an hour.
$raw  = isset($_GET['asat']) ? (string)$_GET['asat'] : '';
$asAt = DateTime::createFromFormat('!Y-m-d', $raw);
if (!$asAt || $asAt->format('Y-m-d') !== $raw) {
    http_response_code(400);
    exit('asat must be YYYY-MM-DD');
}

// A future date resolves a week that has not happened and refresh() would file
// snapshots for it. A far-past date walks the fetchers over dead ranges for no
// gain. Bound both ends and fail loud rather than let either write.
$today = new DateTime('today');
if ($asAt > $today || $asAt < (clone $today)->modify('-180 days')) {
    http_response_code(400);
    exit('asat must fall within the last 180 days and not be in the future');
}

$service = oe_build_service(1, null, $asAt);
$service->refresh();
$body = OeSummaryRenderer::render($service->buildReport(), OE_ASAT_TITLE);

$self = '?asat=' . $asAt->format('Y-m-d');
report_render_web_page(
    $body,
    OE_ASAT_TITLE . ' — as at ' . $asAt->format('d M Y'),
    OE_ASAT_STEM . '-asat-' . $asAt->format('Y-m-d'),
    ['refresh_url' => $self]
);