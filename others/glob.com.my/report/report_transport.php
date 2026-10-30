<?php
/**
 * report_transport.php — shared delivery layer for the scorecard-family reports.
 *
 * Every report in this folder produces ONE thing: an email-safe HTML body. What
 * happens to that body afterwards (mail it, show it, print it, PDF it) is
 * identical for all of them, so it lives here once instead of being copy-pasted
 * into each report's entry point.
 *
 * Nothing here knows what a scorecard, a routine or a metric is — it only takes
 * (title, body, config). Pure transport, no domain logic.
 *
 * heatmap_report.php still carries its own private copies of these four
 * functions. They are byte-for-byte the same behaviour; deleting them there and
 * calling these instead is a safe 4-line change whenever you want to make it.
 * I have deliberately NOT touched that live file in this change.
 */


/* ─── Email ─────────────────────────────────────────────────────────────── */

/**
 * COMMAND: mail the body, optionally with attachments.
 *
 * $cfg   = from_name, from_email, subject, to[], cc[], intro[], font, width
 *          where to/cc are lists of ['name'=>..,'email'=>..].
 * $files = list of ['filename'=>.., 'mime'=>.., 'data'=>raw bytes]
 *          — build one with report_pdf_attachment(). Omit it and the message
 *          is the plain single-part HTML mail it has always been.
 *
 * @return array ['sent'=>bool, 'reason'=>string]
 */
function report_send_email(array $cfg, $htmlBody, array $files = [])
{
    if (empty($cfg['to'])) {
        return ['sent' => false, 'reason' => 'no recipients configured'];
    }
    $to = report_format_recipients($cfg['to']);
    $cc = report_format_recipients(isset($cfg['cc']) ? $cfg['cc'] : []);

    $headers  = 'From: ' . $cfg['from_name'] . ' <' . $cfg['from_email'] . '>' . "\r\n";
    $headers .= 'Reply-To: ' . $cfg['from_email'] . "\r\n";
    if ($cc !== '') $headers .= 'Cc: ' . $cc . "\r\n";
    $headers .= 'MIME-Version: 1.0' . "\r\n";

    $html = report_email_body($cfg, $htmlBody);

    if (empty($files)) {
        $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        $headers .= 'Content-Transfer-Encoding: quoted-printable' . "\r\n";
        $message  = quoted_printable_encode($html);
    } else {
        // Random boundary, so it can never collide with anything in the body or
        // inside the base64 payload. A fixed string would only have to be
        // unlucky once, and the failure is a mail that renders as raw MIME.
        $boundary = '=_oe_' . bin2hex(random_bytes(16));
        $headers .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . "\r\n";
        $message  = report_mime_multipart($boundary, $html, $files);
    }

    $sent = mail($to, $cfg['subject'], $message, $headers);
    return ['sent' => $sent, 'reason' => $sent ? 'ok' : 'mail() failed'];
}

/**
 * QUERY: the exact HTML that goes out as the email body — the covering note,
 * then whatever report HTML the caller passed. No intro configured, no change
 * at all; an empty $htmlBody yields the note on its own, which is what a report
 * delivered as an attachment wants.
 *
 * This is here and NOT in the report's renderer because it is the one part of
 * the message that is not the report. A salutation belongs in a letter, not on
 * a web page and not inside a printed PDF, and those three share that renderer
 * verbatim — putting it there would print "Dear All," on all of them.
 *
 * $cfg['intro'] is a list of lines, printed in order. $cfg['font'] is the face
 * to set them in; $cfg['width'] caps the line length only. The note is LEFT
 * aligned — it is correspondence, and a greeting centred over a table reads as
 * a caption for it rather than as the start of a message.
 */
function report_email_body(array $cfg, $htmlBody)
{
    if (empty($cfg['intro'])) return $htmlBody;

    $font  = isset($cfg['font'])  ? $cfg['font']  : 'Arial,sans-serif';
    $width = isset($cfg['width']) ? (int)$cfg['width'] : 994;

    $out = '<div style="font-family:' . $font . ';font-size:13px;color:#1f2937;'
         . 'line-height:1.6;max-width:' . $width . 'px;margin:0 0 18px;padding:0 6px;">';
    foreach ($cfg['intro'] as $line) {
        $out .= '<div style="margin:0 0 6px;">'
              . htmlspecialchars((string)$line, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    return $out . '</div>' . $htmlBody;
}

/**
 * QUERY: a multipart/mixed message — the HTML part, then one base64 part per
 * attachment.
 *
 * Throws on a zero-byte attachment rather than sending it. A 0-byte PDF opens
 * as a corrupt file, which reads as "the report is broken" to whoever receives
 * it; no attachment at least reads as "the attachment is missing".
 */
function report_mime_multipart($boundary, $html, array $files)
{
    $eol = "\r\n";
    $m   = 'This is a multi-part message in MIME format.' . $eol . $eol;

    $m .= '--' . $boundary . $eol;
    $m .= 'Content-Type: text/html; charset=UTF-8' . $eol;
    $m .= 'Content-Transfer-Encoding: quoted-printable' . $eol . $eol;
    $m .= quoted_printable_encode($html) . $eol . $eol;

    foreach ($files as $f) {
        $name = isset($f['filename'])
            ? str_replace(['"', "\r", "\n"], '', $f['filename'])
            : 'attachment.bin';
        if (!isset($f['data']) || $f['data'] === '') {
            throw new RuntimeException("report_mime_multipart: attachment '{$name}' carries no bytes");
        }
        $mime = isset($f['mime']) ? $f['mime'] : 'application/octet-stream';

        $m .= '--' . $boundary . $eol;
        $m .= 'Content-Type: ' . $mime . '; name="' . $name . '"' . $eol;
        $m .= 'Content-Transfer-Encoding: base64' . $eol;
        $m .= 'Content-Disposition: attachment; filename="' . $name . '"' . $eol . $eol;
        $m .= chunk_split(base64_encode($f['data']), 76, $eol) . $eol;
    }

    return $m . '--' . $boundary . '--' . $eol;
}

/** QUERY: "Name <addr>, Name <addr>" */
function report_format_recipients(array $list)
{
    $parts = [];
    foreach ($list as $r) $parts[] = $r['name'] . ' <' . $r['email'] . '>';
    return implode(', ', $parts);
}


/* ─── PDF ───────────────────────────────────────────────────────────────── */

/**
 * QUERY: render an email-safe body to PDF bytes, reusing the exact HTML the web
 * view and the email use — one layout, three outputs.
 *
 * $colWidths is the report's own column width list (px, left to right). Dompdf
 * ignores <colgroup>, and under fixed layout it takes column widths from the
 * FIRST ROW of cells — which in these reports is a colspan title row, so without
 * help it splits every column evenly and pushes the value columns off the page.
 * We pin the outer table to fixed layout and inject a zero-height sizing row.
 *
 * The outer table is found by id="report-root", NOT by its font or width. Anchor
 * on styling and a restyle silently breaks the PDF while web and email still look
 * fine — the worst kind of coupling. A missing anchor throws rather than quietly
 * producing a mangled document.
 *
 * Requires Dompdf, via either:
 *   composer require dompdf/dompdf          -> ./vendor/autoload.php (the default)
 *   the bundled release zip                 -> ./dompdf/autoload.inc.php, which
 *                                              needs REPORT_VENDOR_AUTOLOAD set
 *                                              by the caller.
 */
function report_render_pdf($body, array $colWidths, $paper = 'A4', $orientation = 'landscape')
{
    // Deployment owns this path: define REPORT_VENDOR_AUTOLOAD before including
    // this file when the autoloader is not at ./vendor/autoload.php — the
    // bundled dompdf release ships ./dompdf/autoload.inc.php instead, and it
    // carries its own NESTED vendor/, so pointing at that inner one loads the
    // dependencies but not Dompdf itself.
    //
    // The error names the path actually tried. "Not installed" pointing at a
    // path you never configured is the kind of message that costs an afternoon.
    $autoload = defined('REPORT_VENDOR_AUTOLOAD')
        ? REPORT_VENDOR_AUTOLOAD
        : __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Dompdf autoloader not found at ' . $autoload
            . ' — install it (composer require dompdf/dompdf) or define REPORT_VENDOR_AUTOLOAD.');
    }
    require_once $autoload;

    // Widths go in as PERCENTAGES, never as the caller's pixels.
    //
    // Dompdf resolves an HTML width="994" to 994 POINTS, not to 994 CSS px at
    // 96dpi (745pt). The report is 994px wide, so on A4 landscape — 841.89pt,
    // 811pt inside the margins — the table was laid out 1009pt wide and the
    // last ~170pt fell off the right edge. Column B and the "as at" stamp were
    // rendered and then clipped, so the PDF looked like a one-column report
    // while the web view was correct. Setting Options' 'dpi' does NOT fix this;
    // it does not apply to width attributes.
    //
    // Percentages sidestep the unit question entirely and survive a change of
    // paper size or orientation, which a hardcoded pt conversion would not.
    // The caller still passes pixels — the ratios are all this needs.
    $total = array_sum(array_map('intval', $colWidths));
    if ($total <= 0) {
        throw new RuntimeException('report_render_pdf: column widths must sum to more than zero.');
    }

    if (!preg_match('/\bid="report-root"/i', $body)) {
        throw new RuntimeException('report_render_pdf: no id="report-root" in the body — '
            . 'the renderer must mark its outer element so the layout can be pinned.');
    }

    // EVERY table, not just the anchored one. The renderer emits one table per
    // routine so a page break cannot land inside a group; all of them must be
    // pinned, or the unpinned ones size themselves from their own first row and
    // the columns stop lining up between routines.
    $body = preg_replace_callback(
        '/<table\b[^>]*>/i',
        function ($m) {
            $tag = preg_replace('/\bstyle="/i', 'style="table-layout:fixed;width:100%;', $m[0], 1);
            // Drop the pixel width attribute as well; left in place it is a
            // second, contradicting instruction in the units that caused this.
            return preg_replace('/\swidth="\d+"/i', '', $tag, 1);
        },
        $body
    );

    $sizingCells = '';
    foreach ($colWidths as $w) {
        $pct = round((int)$w / $total * 100, 4);
        $sizingCells .= '<td width="' . $pct . '%" style="padding:0;height:0;line-height:0;font-size:0;"></td>';
    }
    $body = str_replace('</colgroup>', '</colgroup><tr>' . $sizingCells . '</tr>', $body);

    // Force DejaVu Sans over the body's inline Arial so arrows/stars have glyphs
    // (PDF-standard Helvetica lacks them).
    $html =
        '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
        . '@page { margin: 16px 20px; }'
        . '* { font-family: "DejaVu Sans", sans-serif !important; }'
        . 'body { margin: 0; background: #ffffff; }'
        . 'td, th { word-wrap: break-word; overflow-wrap: break-word; }'
        // A routine is one block: it moves to the next page whole rather than
        // leaving its trailing lines under no heading and no column captions.
        . '.scb-group { page-break-inside: avoid; }'
        . '</style></head><body>' . $body . '</body></html>';

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper($paper, $orientation);
    $dompdf->render();
    return $dompdf->output();
}

/**
 * QUERY: the filename a report's PDF carries. The ONE copy, so the browser
 * download and the mailed attachment are always the same name — two literals
 * would drift the first time either is edited alone.
 */
function report_pdf_filename($filenameStem)
{
    return $filenameStem . '-' . date('Y-m-d') . '.pdf';
}

/**
 * QUERY: the report as an attachment record, ready for report_send_email().
 * Same body, same column widths and therefore the same document the ?pdf=1
 * download produces — the mail cannot disagree with the page it came from.
 */
function report_pdf_attachment($body, array $colWidths, $filenameStem)
{
    return [
        'filename' => report_pdf_filename($filenameStem),
        'mime'     => 'application/pdf',
        'data'     => report_render_pdf($body, $colWidths),
    ];
}

/** COMMAND: stream the PDF as a download. Thin wrapper over report_render_pdf. */
function report_stream_pdf($body, array $colWidths, $filenameStem)
{
    $pdf = report_render_pdf($body, $colWidths);      // build BEFORE emitting anything

    while (ob_get_level() > 0) ob_end_clean();        // PDF must be the only output
    $filename = report_pdf_filename($filenameStem);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf;
}


/* ─── Browser views ─────────────────────────────────────────────────────── */

/**
 * COMMAND: the normal web page. Document title doubles as the Save-as-PDF name.
 *
 * $chrome (optional) controls the top bar; the caller owns request state:
 *   back_url    - when set, shows a "Back to Glob Intranet" button.
 *   refresh_url - target of the Refresh link. Defaults to '?'.
 */
function report_render_web_page($body, $heading, $filenameStem, array $chrome = [])
{
    $docTitle   = $filenameStem . '-' . date('Y-m-d');
    $backUrl    = isset($chrome['back_url'])    ? $chrome['back_url']    : null;
    $refreshUrl = isset($chrome['refresh_url']) ? $chrome['refresh_url'] : '?';

    $backLink = $backUrl === null ? '' :
        '<a href="' . htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') . '" class="back">'
      . '&#8592; Back to Glob Intranet</a>';

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<title>' . htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8') . '</title>'
       . '<style>body{margin:0;background:#f3f4f6;}'
       . '.page{max-width:1120px;margin:0 auto;padding:20px;}'
       . '.card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:24px;}'
       . '.bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;font-family:Arial;}'
       . '.bar h1{font-size:16px;margin:0;color:#111827;}.bar a{font-size:12px;color:#2563eb;text-decoration:none;}'
       . '.bar a.back{display:inline-block;margin-right:16px;padding:5px 12px;border:1px solid #2563eb;'
       . 'border-radius:4px;background:#eff6ff;}'
       . '.bar a.back:hover{background:#2563eb;color:#fff;}'
       . '@page{size:A4 landscape;margin:10mm;}'
       . '@media print{'
       . 'body{background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
       . '.bar{display:none;}'
       . '.page{max-width:none;padding:0;}'
       . '.card{border:none;border-radius:0;padding:0;}'
       . 'tr,td{page-break-inside:avoid;break-inside:avoid;}'
       . '.scb-group{page-break-inside:avoid;break-inside:avoid;}'
       . '.scb-band{page-break-after:avoid;break-after:avoid;}'
       . '}'
       . '</style></head><body><div class="page">'
       . '<div class="bar"><h1>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h1>'
       . '<span>' . $backLink
       . '<a href="javascript:window.print()" style="margin-right:16px;">&#128424; Print / Save as PDF</a>'
       . '<a href="' . htmlspecialchars($refreshUrl, ENT_QUOTES, 'UTF-8') . '">&#8635; Refresh</a></span></div>'
       . '<div class="card">' . $body . '</div></div></body></html>';
}

/** COMMAND: ?test=1 — the refresh log plus a preview of the exact email body. */
function report_render_test_page(array $log, $body, array $cfg, $heading)
{
    $reason = empty($cfg['to']) ? 'no recipients configured' : 'test mode — not sent';
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>'
       . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . ' (Test)</title>'
       . '<style>body{font-family:Arial;background:#1e1e1e;color:#ddd;margin:0;padding:20px;}'
       . '.wrap{max-width:1120px;margin:0 auto;}'
       . '.log{background:#252526;border-radius:6px;padding:12px;margin-bottom:20px;font-family:monospace;font-size:12px;}'
       . '.log div{padding:3px 6px;border-left:3px solid #666;margin:2px 0;}'
       . '.success{border-left-color:#10b981;color:#6ee7b7;}.error{border-left-color:#ef4444;color:#fca5a5;}'
       . '.skip{border-left-color:#f59e0b;color:#fbbf24;}.info{border-left-color:#3b82f6;color:#93c5fd;}'
       . '.preview{background:#fff;color:#000;border-radius:6px;padding:20px;}</style></head><body><div class="wrap">'
       . '<h2 style="color:#93c5fd;">Cron Test — ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '</h2><div class="log">';
    foreach ($log as $e) {
        echo '<div class="' . htmlspecialchars($e['type'], ENT_QUOTES, 'UTF-8') . '">'
           . htmlspecialchars($e['message'], ENT_QUOTES, 'UTF-8') . '</div>';
    }
    echo '</div><h3 style="color:#93c5fd;">Email preview (exact body as sent)</h3>'
       . '<div class="preview">' . $body . '</div></div></body></html>';
}