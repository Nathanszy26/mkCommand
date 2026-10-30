<?php
/**
 * oe_summary_report.php — the one entry point for the Summary O&E Performance report.
 *
 *   CLI (cron)        => LIVE : refresh both columns of every routine, then email
 *                              with the PDF attached.
 *   Browser (default) => VIEW : the same report as a web page (no email).
 *   Browser + ?test=1 => TEST : same page + the refresh log + the email preview.
 *   Browser + ?pdf=1  => PDF  : the same body, downloaded.
 *
 * All four share OeSummaryRenderer::render(), so the REPORT is identical by
 * construction. The email adds one thing on top of it — a salutation and an
 * as-at line, built by report_email_body() — because a letter has a greeting and
 * a web page does not. That preamble is the only difference between the four,
 * and it lives in the transport layer so the renderer stays shared verbatim.
 *
 * Cron — the LIVE schedule (crontab, confirmed 02 Aug 2026). Weekly, Monday 09:00.
 * NOTE the salutation prints $report['generated_at'], i.e. the time this actually
 * runs — move the schedule to change it, do not hardcode a time into the intro:
 *   0 9 * * 1  /usr/bin/php /var/www/html/glob/it/mkPortal/report/oe_summary_report.php > /var/log/php_cron/oe_summary_report.log 2>&1
 *
 * Layout this file expects (require_once uses __DIR__, so these must be siblings):
 *   report/oe_summary_report.php   <- this file
 *   report/oe_summary_lib.php
 *   report/report_transport.php
 *   report/dompdf/                 <- bundled dompdf release (REPORT_VENDOR_AUTOLOAD)
 *   report/API/ro1_oe_summary.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Dompdf lives in ./dompdf (the bundled release zip), not in ./vendor. Set
// BEFORE the require_once below, because report_transport.php reads it.
// Composer instead? Delete this line — ./vendor/autoload.php is the default.
define('REPORT_VENDOR_AUTOLOAD', __DIR__ . '/dompdf/autoload.inc.php');

require_once __DIR__ . '/oe_summary_lib.php';
require_once __DIR__ . '/report_transport.php';

// Lets the PDF path discard any stray notice before sending headers.
ob_start();

$isCli      = (php_sapi_name() === 'cli');
$isTestMode = isset($_GET['test']) && $_GET['test'] == 1;
$isPdf      = isset($_GET['pdf'])  && $_GET['pdf']  == 1;
$isIntranet = isset($_GET['intranet']) && $_GET['intranet'] == 1;

// ─── Report identity — rename here and every surface follows. ────────────────
const OE_TITLE     = 'Weekly O&E Summary Report';
const OE_FILESTEM  = 'weekly-oe-summary-report';
const OE_BACK_URL  = 'https://glob.com.my/oneMenu.php';

// ─── Email recipients — edit these arrays. ───────────────────────────────────
// $emailConfig = [
//     'from_name'  => OE_TITLE,
//     'from_email' => 'noreply@cityskill.my',
//     'subject'    => OE_TITLE,
//     'to' => [
//         ['name' => 'Programmer- Soo Zhao Yuan Nathan', 'email' => 'prog4.glob@cityskill.my'],
//         ['name' => 'DCEO - Jeffrey Soo', 'email' => 'nathanszy26@gmail.com'],
//     ],
//     'cc' => [
//         // ['name' => 'CC Name', 'email' => 'cc@cityskill.my'],
//         ['name' => 'IT Manager - Kevin Chua', 'email' => 'x3unknown26@gmail.com'],
//     ],
// ];

$emailConfig = [
    'from_name'  => OE_TITLE,
    'from_email' => 'noreply@cityskill.my',
    'subject'    => OE_TITLE,
    'to' => [
        ['name' => 'DCEO - Jeffrey Soo', 'email' => 'jeffreysoo79@gmail.com'],
        ['name' => 'IT Manager - Kevin Chua', 'email' => 'itm.glob@cityskill.my'],
        ['name' => 'Programmer- Soo Zhao Yuan Nathan', 'email' => 'prog4.glob@cityskill.my'],
    ],
    'cc' => [
        // ['name' => 'CC Name', 'email' => 'cc@cityskill.my'],
    ],
];


// ─── Shared pipeline (identical for web, email and PDF). ─────────────────────
$compId  = 1;
$service = oe_build_service($compId);
$log     = $service->refresh();
$report  = $service->buildReport();
$body    = OeSummaryRenderer::render($report, OE_TITLE);

// ─── Email-only preamble. ────────────────────────────────────────────────────
// Deliberately NOT in OeSummaryRenderer: the web page and the PDF share that
// renderer verbatim, so a salutation added there would print on both.
//
// The timestamp is $report['generated_at'], the same string the masthead prints
// as "As at". Calling date() again here would let the two disagree by a minute
// whenever a run straddles the boundary — and the salutation sits directly above
// the masthead, where that is visible.
$emailConfig['font']  = OeSummaryRenderer::FONT;
$emailConfig['width'] = array_sum(OeSummaryRenderer::columnWidths());
$emailConfig['intro'] = [
    'Dear All,',
    OE_TITLE . ' as at ' . $report['generated_at'] . ':',
];

// ─── Dispatch. ───────────────────────────────────────────────────────────────
if ($isCli) {
    // A missing PDF is a degraded email; a skipped send is a missing report. So
    // the mail goes out either way, the reason lands in the log, and the exit
    // code is non-zero so cron's MAILTO reports it rather than it passing silently.
    $files = [];
    try {
        $files[] = report_pdf_attachment($body, OeSummaryRenderer::columnWidths(), OE_FILESTEM);
    } catch (Throwable $e) {
        $log[] = ['type' => 'error', 'message' => 'PDF NOT ATTACHED: ' . $e->getMessage()];
    }

    // COVERING NOTE ONLY when the PDF is attached. The report is in the
    // attachment, and Gmail collapses a body this long behind a "..." toggle —
    // so the inline copy was being hidden anyway, and two copies of the same
    // figures in one message is one too many.
    //
    // When the PDF could NOT be built, the inline report goes back in. A note
    // with no numbers beside no attachment is a mail that says nothing, and the
    // reader should not be the one who discovers the render failed.
    $result = report_send_email($emailConfig, $files ? '' : $body, $files);
    foreach ($log as $e) {
        echo '[' . strtoupper($e['type']) . '] ' . $e['message'] . "\n";
    }
    echo ($result['sent'] ? 'Email SENT' : 'Email NOT sent (' . $result['reason'] . ')')
       . ($files ? ' (PDF attached)' : ' (no PDF — report sent inline)') . "\n";
    exit(($result['sent'] && $files) ? 0 : 1);
}

if ($isPdf) {
    report_stream_pdf($body, OeSummaryRenderer::columnWidths(), OE_FILESTEM);
    exit(0);
}

if ($isTestMode) {
    // The BODY AS SENT on a successful run: the covering note alone, because
    // the report travels as the attached PDF. The report itself is one click
    // away on the normal web view and at ?pdf=1 — previewing it here instead
    // would show a message that is not the one that goes out.
    report_render_test_page($log, report_email_body($emailConfig, ''), $emailConfig, OE_TITLE);
    exit(0);
}

report_render_web_page($body, OE_TITLE, OE_FILESTEM, [
    'back_url'    => $isIntranet ? OE_BACK_URL : null,
    'refresh_url' => $isIntranet ? '?intranet=1' : '?',
]);
exit(0);