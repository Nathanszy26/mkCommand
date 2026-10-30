<?php
/**
 * API/jkr_ncr_oe_summary.php
 *
 * "JKR NCR" data source for the Summary O&E Performance report (section 9).
 * Wire its oe_summary_items row to this file with
 *   column_mode = 'mtd_ytd'   (Month to Date | Year to Date)
 *
 * Three lines over ONE population — the NCRs RAISED inside [period_start,
 * period_end] — split by their current status:
 *   a. NCR Issued    every NCR raised in the range
 *   b. NCR Closed    of those, the ones now Completed or Verified
 *   c. Outstanding   of those, the ones still New or Acknowledged
 *
 * So (b) + (c) = (a) by construction, on every column, every day. They are not
 * three independent queries that could drift apart; they are one count
 * partitioned by status.
 *
 * ── WHY RAISE-SCOPED, AND NOT ANCHORED ON THE CLOSING DATE ───────────────────
 * emergency_progress_oe_summary.php anchors each of its groups on the timestamp
 * of the event it names, and that is the better rule WHERE THE SOURCE SUPPORTS
 * IT. This source does not, and the difference is not stylistic.
 *
 * The JKR API is keyed on a RAISE month: one call returns the NCRs raised in
 * that district-month. A closing-date-anchored "NCR Closed" would therefore need
 * every month back to the beginning of time on every run, because an NCR raised
 * in March and closed today belongs in today's count. Fetching only the months
 * the range spans — which is all any bounded endpoint can do — would silently
 * MISS those, and Month to Date would undercount every closure of an older NCR.
 * A number that is quietly short is the one failure a reader cannot catch.
 *
 * Raise-scoped is also what WeeklyReportController::getMonthlyNcrAuditQueries()
 * reports (ncr_received / ncr_closed for one report month), so these figures
 * reconcile against the weekly AM report rather than disagreeing with it.
 *
 * The cost is worth stating: line (b) is "raised in this period AND closed by
 * now", so a period's closed count keeps rising after the period ends. Under
 * mtd_ytd both columns end today and are re-read on every run, so what is
 * printed is always "as at now" — which is what the masthead already says.
 *
 * ── THE CHANGE FIGURE ────────────────────────────────────────────────────────
 * This endpoint supplies its own change figure and does NOT use the report's
 * day-over-day baseline. Requirement: the chip must read "how many did this move
 * TODAY", not "how does today's stored row differ from yesterday's stored row".
 *
 * The two are not the same number:
 *   - If the 06:00 cron missed a day (or a weekend), yesterday's row is three
 *     days old and the chip prints a three-day movement labelled as one day's.
 *   - On the 1st of a month, Month to Date's period key is brand new and has no
 *     previous row, so the chip is blank on the one day it matters most.
 *   - The web path refreshes on load, so "yesterday's row" is whatever time of
 *     day the page happened to be opened.
 *
 * Derived from the item timestamps instead, "today" means today on every path
 * and every date, whether or not the report ran yesterday:
 *   issued today       an NCR whose raise date is the delta day
 *   closed today       an NCR of this population that ENTERED a closed status
 *                      on the delta day
 *   outstanding today  issued today minus closed today — what arrived, less
 *                      what left. Signed, so a day of catching up reads negative.
 * The three movements sum exactly as the three lines do.
 *
 * The delta day is period_end, which under mtd_ytd IS today. Anchoring it on the
 * range rather than on date('Y-m-d') keeps the endpoint range-driven: hand it a
 * historical range and it reports that range's last day rather than a flat zero.
 *
 * Requires the `delta` field on the metric contract — see OeHttpMetricFetcher in
 * oe_summary_lib.php. Without it the framework recomputes value-minus-baseline
 * and this whole section is wrong.
 *
 * NOTE that the two columns do NOT carry the same change figure, and must not be
 * made to. Issued moves identically in both (an NCR raised today is in the month
 * and in the year), but Closed does not: Year to Date counts today's closures of
 * anything raised since January, Month to Date only of what was raised this
 * month. delta_mode 'mirror' would flatten that into a wrong number.
 *
 * ── THE SOURCE ───────────────────────────────────────────────────────────────
 *   GET https://roadmaintenance.jkr.sabah.gov.my/inspection-api/report/globinaco
 *       ?month=MM&year=YYYY&district=<district_info.district_code>
 *
 * One district and one month per call, so a range fans out to (districts x
 * months): 28 x 1 for Month to Date, 28 x 12 for a December Year to Date. They
 * are issued through curl_multi in bounded rounds — measured at ~0.27s per
 * request, a December Year to Date completes in about four seconds, where
 * serially it would not finish inside the fetcher's 30s budget.
 *
 * ANY failed request aborts the whole response. A dropped district is not a
 * smaller number, it is a wrong one that looks plausible on a printed sheet.
 *
 * Verified against the live feed: <total> matches the returned item count, and
 * nested <entries><item> are defect lines within an audit, not audits.
 */

require_once __DIR__ . '/oe_api_common.php';


/* ================== SWITCH POINTS — the only judgement calls ==============
 * Each is stated once. Nothing below re-decides any of them.
 * ========================================================================= */

/**
 * The statuses that count as a CLOSED NCR, each mapped to the field carrying
 * the moment it reached that status. Matched case-insensitively against
 * <status>; the other statuses in the feed are 'New' and 'Acknowledged', both
 * still open.
 *
 * ONE map rather than a status list plus a separate date-field constant,
 * because the two cannot be allowed to disagree: adding a closing status
 * without saying when it happened would silently drop that status out of every
 * change figure while still counting it as closed.
 *
 * DECLARED IN LIFECYCLE ORDER, EARLIEST FIRST, and read in that order. The feed
 * carries BOTH completed_datetime and verified_datetime on a Verified item
 * (10 Jul 14:17 and 10 Jul 16:04 on the sample), because Verified is reached
 * THROUGH Completed. Since Completed already counts as closed, the NCR left the
 * outstanding pile at completed_datetime and the later verification is a
 * confirmation, not a second closure. Reading the current status's own
 * timestamp instead would re-report a closure on the day it was verified —
 * weeks after it actually closed, and after it had already been counted.
 */
$NCR_CLOSED_STATUSES = array(
    'Completed' => 'completed_datetime',
    'Verified'  => 'verified_datetime',
);

/**
 * The field carrying WHEN THE NCR WAS RAISED. 'datetime' on the live feed —
 * <datetime>2026-07-04 14:15:21</datetime> — with acknowledge_datetime,
 * completed_datetime and verified_datetime marking the later transitions.
 *
 * A missing key fails loud listing every field the item actually carries, so a
 * feed change names its own fix rather than quietly shifting the counts.
 */
define('NCR_ISSUED_DATE_FIELD', 'datetime');

/**
 * Which direction is the good one, per line. Read by the renderer's change chip
 * only — it colours the movement, it never changes the sign.
 *
 * An NCR is a non-conformance raised by JKR AGAINST the contractor, so more of
 * them is worse: Issued and Outstanding are lower-is-better. Closed is the
 * remedial action, so more is better.
 */
define('NCR_ISSUED_LOWER_IS_BETTER',      true);
define('NCR_CLOSED_LOWER_IS_BETTER',      false);
define('NCR_OUTSTANDING_LOWER_IS_BETTER', true);

/* ---- Transport. Timeouts are per REQUEST; concurrency bounds the fan-out.
 * Raise the concurrency before raising the timeout: the timeout is the one
 * thing protecting the report from a hung JKR server. */
define('NCR_API_URL',             'https://roadmaintenance.jkr.sabah.gov.my/inspection-api/report/globinaco');
define('NCR_API_TIMEOUT',         15);
define('NCR_API_CONNECT_TIMEOUT', 10);
define('NCR_CONCURRENCY',         24);


/* ========================= 1. Resolve the range =========================== */

$range    = oe_request_range();
$start    = $range['start'];
$end      = $range['end'];
$deltaDay = $end;   // under mtd_ytd this IS today — see the docblock.


/* ========================= 2. District roster ============================= */

// The JKR API keys on district_info.district_code (the zero-padded codes '01'
// through '30'), NOT the shortname the RO endpoints use — see the note on
// WeeklyReportController::fetchNcrAuditResponse(). Same roster rule as every
// other endpoint here: districts still live at period_end.
$districtCodes = array();
foreach (oe_district_roster(oe_connect(GLOB_DB), $end) as $d) {
    if ($d['district_code'] !== '') $districtCodes[] = $d['district_code'];
}
if (empty($districtCodes)) oe_fail('no active district_code in district_info for ' . $end);


/* ==================== 3. Fetch every district-month ======================= */

$rows = ncrFetchRows($districtCodes, oe_month_span($start, $end), $NCR_CLOSED_STATUSES);


/* ========================= 4. Count ======================================= */

$tally = ncrTally($rows, $start, $end, $deltaDay);


/* ========================= 5. Emit ======================================== */

// Issued, then Closed, then Outstanding: what came in, what went out, what is
// left. Column A defines row order in OeSummaryService::mergeRows(), so this
// array IS the printed order — there is no second place to change it.
oe_emit(array(
    ncrLine('ncr_issued', 'a. NCR Issued',
            $tally['issued'], $tally['issued_delta'], NCR_ISSUED_LOWER_IS_BETTER),
    ncrLine('ncr_closed', 'b. NCR Closed',
            $tally['closed'], $tally['closed_delta'], NCR_CLOSED_LOWER_IS_BETTER),
    ncrLine('ncr_outstanding', 'c. Outstanding',
            $tally['outstanding'], $tally['outstanding_delta'], NCR_OUTSTANDING_LOWER_IS_BETTER),
));


/* ============================== counting ================================== */

/**
 * QUERY: the three figures and the three movements, in one pass.
 *
 * ONE scope test, in one place, at the top of the loop: every line below it is
 * about the NCRs raised inside the range, so no line can be scoped differently
 * from its neighbours by accident.
 *
 * @param array  $rows     normalised items: issued, is_closed, closed
 * @param string $deltaDay the day the change figures are measured over
 */
function ncrTally(array $rows, $start, $end, $deltaDay)
{
    $issued = $closed = $outstanding = 0;
    $issuedToday = $closedToday = 0;

    foreach ($rows as $row) {
        if ($row['issued'] < $start || $row['issued'] > $end) continue;

        $issued++;
        if ($row['is_closed']) $closed++;
        else                   $outstanding++;

        if ($row['issued'] === $deltaDay) $issuedToday++;

        // 'closed' is the moment it ENTERED a closed status, which is null on an
        // open NCR and on the rare closed one carrying no timestamp at all. The
        // latter still counts as closed above — the count is right — it simply
        // cannot be placed on a day, so it never contributes to a movement.
        if ($row['is_closed'] && $row['closed'] === $deltaDay) $closedToday++;
    }

    return array(
        'issued'      => $issued,
        'closed'      => $closed,
        'outstanding' => $outstanding,
        'issued_delta' => $issuedToday,
        'closed_delta' => $closedToday,
        // What arrived today, less what left today. Signed: a day spent closing
        // more than was raised reads negative, which is the good direction here.
        'outstanding_delta' => $issuedToday - $closedToday,
    );
}

/**
 * QUERY: one metric line carrying its own change figure.
 *
 * 'delta' is sent instead of relying on the column pair's day-over-day rule.
 * Never send both 'delta' and 'delta_mode' — the fetcher rejects that pair
 * rather than resolving it by a precedence nobody can see from here.
 */
function ncrLine($key, $label, $value, $delta, $lowerIsBetter)
{
    return array(
        'key'             => $key,
        'label'           => $label,
        'value'           => (int)$value,
        'delta'           => (int)$delta,
        'format'          => 'integer',
        'decimals'        => 0,
        'lower_is_better' => (bool)$lowerIsBetter,
    );
}


/* ============================== fetching ================================== */

/**
 * QUERY: every NCR across the given districts and months, normalised.
 *
 * Fans out to (districts x months) requests and returns one flat list. Any
 * transport failure, non-200 or unparseable body aborts through oe_fail().
 */
function ncrFetchRows(array $districtCodes, array $months, array $closedStatuses)
{
    $jobs = array();
    foreach ($districtCodes as $district) {
        foreach ($months as $m) {
            $jobs[] = array(
                'district' => $district,
                'year'     => sprintf('%04d', $m['year']),
                // Zero-padded to match the proven call shape: the reference
                // splits a 'YYYY-MM' string, so it has always sent '07'.
                'month'    => sprintf('%02d', $m['month']),
            );
        }
    }
    if (empty($jobs)) oe_fail('no district-months to query for the requested range');

    $rows = array();
    foreach (array_chunk($jobs, NCR_CONCURRENCY) as $batch) {
        foreach (ncrRunBatch($batch) as $result) {
            $what = 'district ' . $result['job']['district']
                  . ' ' . $result['job']['year'] . '-' . $result['job']['month'];
            foreach (ncrParseItems($result['body'], $result['content_type'], $what, $closedStatuses) as $item) {
                $rows[] = $item;
            }
        }
    }
    return $rows;
}

/**
 * QUERY: run one bounded batch of requests in parallel.
 *
 * Fixed-size batches rather than a sliding window: a window is a little faster
 * and a lot more state, and the batch size already bounds the fan-out. Every
 * handle is inspected individually, so one bad district names itself.
 *
 * @return array list of ['job' => ..., 'body' => ..., 'content_type' => ...]
 */
function ncrRunBatch(array $batch)
{
    $mh      = curl_multi_init();
    $handles = array();

    foreach ($batch as $i => $job) {
        $ch = curl_init(NCR_API_URL . '?' . http_build_query(array(
            'month'    => $job['month'],
            'year'     => $job['year'],
            'district' => $job['district'],
        )));
        if ($ch === false) oe_fail('failed to initialise a request to the JKR NCR API');

        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => NCR_API_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => NCR_API_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING       => '',   // advertise + auto-decompress gzip
            // The API content-negotiates — it answers this with XML. Asked for
            // explicitly rather than left to a default; the parser handles JSON
            // too, in case that ever changes.
            CURLOPT_HTTPHEADER     => array('Accept: application/xml, text/xml, application/json'),
        ));

        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running > 0) curl_multi_select($mh, 1.0);
    } while ($running > 0);

    $out = array();
    foreach ($handles as $i => $ch) {
        $job    = $batch[$i];
        $where  = 'district ' . $job['district'] . ' ' . $job['year'] . '-' . $job['month'];
        $body   = curl_multi_getcontent($ch);
        $errNo  = curl_errno($ch);
        $errMsg = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype  = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        if ($errNo !== 0) {
            curl_multi_close($mh);
            oe_fail("JKR NCR API unreachable for {$where}: {$errMsg}");
        }
        if ($status !== 200) {
            curl_multi_close($mh);
            oe_fail("JKR NCR API returned HTTP {$status} for {$where}");
        }
        if ($body === false || trim((string)$body) === '') {
            curl_multi_close($mh);
            oe_fail("JKR NCR API returned an empty body for {$where}");
        }

        $out[] = array('job' => $job, 'body' => $body, 'content_type' => $ctype);
    }

    curl_multi_close($mh);
    return $out;
}


/* ============================== parsing =================================== */

/**
 * QUERY: one response body -> normalised items, whichever format it arrived in.
 * Port of WeeklyReportController::parseNcrAuditResponse(), failing through
 * oe_fail() instead of throwing so the reason reaches the ?test=1 refresh log.
 */
function ncrParseItems($body, $contentType, $what, array $closedStatuses)
{
    // A UTF-8 BOM alone is enough to make libxml report "Start tag expected".
    $trimmed = ltrim((string)$body, " \t\n\r\0\x0B");
    if (substr($trimmed, 0, 3) === "\xEF\xBB\xBF") $trimmed = substr($trimmed, 3);
    $trimmed = ltrim($trimmed, " \t\n\r\0\x0B");

    if ($trimmed === '') oe_fail("JKR NCR API returned an empty body for {$what}");

    $first = $trimmed[0];
    if ($first === '<')                   return ncrParseXml($trimmed, $what, $closedStatuses);
    if ($first === '{' || $first === '[') return ncrParseJson($trimmed, $what, $closedStatuses);

    oe_fail("JKR NCR API returned neither XML nor JSON for {$what}"
        . ($contentType === '' ? '' : " (Content-Type: {$contentType})")
        . '. Body starts: ' . ncrExcerpt($trimmed));
}

/**
 * QUERY: parse an XML response — the format the live feed actually returns.
 * Nested <entries><item> are defect lines within an audit, not audits, so only
 * the direct children of <data> are read.
 */
function ncrParseXml($xmlBody, $what, array $closedStatuses)
{
    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $xml = simplexml_load_string($xmlBody);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($xml === false) {
        oe_fail("JKR NCR API returned unparseable XML for {$what}"
            . (empty($errors) ? '' : ': ' . trim($errors[0]->message))
            . '. Body starts: ' . ncrExcerpt($xmlBody));
    }
    if (!isset($xml->total)) {
        oe_fail("JKR NCR API XML has no <total> element for {$what}. Body starts: "
            . ncrExcerpt($xmlBody));
    }

    $raw = array();
    if (isset($xml->data->item)) {
        foreach ($xml->data->item as $item) $raw[] = ncrXmlItemToRow($item);
    }
    return ncrNormaliseItems($raw, (int)$xml->total, $what, $closedStatuses);
}

/** QUERY: parse a JSON response — same shape, a total plus a data list. */
function ncrParseJson($jsonBody, $what, array $closedStatuses)
{
    $decoded = json_decode($jsonBody, true);

    if (!is_array($decoded)) {
        oe_fail("JKR NCR API returned unparseable JSON for {$what}: " . json_last_error_msg()
            . '. Body starts: ' . ncrExcerpt($jsonBody));
    }
    if (!isset($decoded['total'])) {
        oe_fail("JKR NCR API JSON has no \"total\" key for {$what}. Body starts: "
            . ncrExcerpt($jsonBody));
    }

    $raw = array();
    if (isset($decoded['data']) && is_array($decoded['data'])) {
        foreach ($decoded['data'] as $item) {
            if (is_array($item)) $raw[] = $item;
        }
    }
    return ncrNormaliseItems($raw, (int)$decoded['total'], $what, $closedStatuses);
}

/** QUERY: one <item> as a flat field map. A node with children is a nested list
 *  (<entries>), not a field, so it is carried as null rather than as the empty
 *  string a cast would produce — that keeps the key list honest for the
 *  diagnostic in ncrRowDate(). */
function ncrXmlItemToRow(SimpleXMLElement $item)
{
    $row = array();
    foreach ($item->children() as $name => $child) {
        $row[(string)$name] = ($child->count() > 0) ? null : (string)$child;
    }
    return $row;
}

/**
 * QUERY: raw items -> the three facts this endpoint reads.
 *
 * Aborts if the API paginated — a short page would understate every line while
 * still looking like a valid answer.
 */
function ncrNormaliseItems(array $raw, $total, $what, array $closedStatuses)
{
    $returned = count($raw);

    // The API reports "returned in page" separately from "total" (its own
    // message says so), so it CAN paginate. Carried over from
    // WeeklyReportController::ncrCounts().
    if ($returned !== $total) {
        oe_fail("JKR NCR API returned {$returned} of {$total} items for {$what} (paginated);"
            . ' every line would be understated');
    }

    $out = array();
    foreach ($raw as $row) {
        $status   = isset($row['status']) ? trim((string)$row['status']) : '';
        $isClosed = ncrIsClosed($status, $closedStatuses);

        $out[] = array(
            'status'    => $status,
            'is_closed' => $isClosed,
            'issued'    => ncrRowDate($row, NCR_ISSUED_DATE_FIELD, $what, true),
            'closed'    => $isClosed ? ncrClosedAt($row, $closedStatuses, $what) : null,
        );
    }
    return $out;
}

/** QUERY: is this status one of the closed ones? Case-insensitive, matching
 *  WeeklyReportController::ncrCounts(). */
function ncrIsClosed($status, array $closedStatuses)
{
    $wanted = array_map('strtolower', array_keys($closedStatuses));
    return in_array(strtolower(trim((string)$status)), $wanted, true);
}

/**
 * QUERY: the day this NCR ENTERED a closed status, or null if it carries no
 * usable timestamp.
 *
 * The map is walked in declaration order — earliest lifecycle stage first — and
 * the FIRST populated timestamp wins. A Verified NCR carries both
 * completed_datetime and verified_datetime; it stopped being outstanding at the
 * former, and reporting the latter would re-announce a closure weeks after it
 * happened. See the map's docblock.
 *
 * Returns null rather than failing when a closed NCR has no timestamp at all:
 * the item is still counted as closed, it simply cannot be placed on a day. A
 * missing FIELD is different and does fail, because that means the map names a
 * column the feed does not have.
 */
function ncrClosedAt(array $row, array $closedStatuses, $what)
{
    foreach ($closedStatuses as $field) {
        $date = ncrRowDate($row, $field, $what, false);
        if ($date !== null) return $date;
    }
    return null;
}

/**
 * QUERY: one date field off an item as 'Y-m-d', or null when it is legitimately
 * empty.
 *
 * A MISSING KEY is never legitimate and stops the response, naming the fields
 * the item actually carries — that message is what turns the switch points at
 * the top of this file from a guess into a one-line correction.
 *
 * @param bool $required an NCR with no raise date cannot be placed in a period,
 *        so it would drop out of line (a) and understate a compliance figure.
 *        That fails loud. An empty CLOSING timestamp is the normal state of an
 *        open NCR and returns null.
 */
function ncrRowDate(array $row, $field, $what, $required)
{
    if (!array_key_exists($field, $row)) {
        oe_fail("JKR NCR item for {$what} has no '{$field}' field — the item carries: "
            . (empty($row) ? '(no fields at all)' : implode(', ', array_keys($row)))
            . '. Correct the field name at the top of jkr_ncr_oe_summary.php.');
    }

    $value = trim((string)$row[$field]);
    if ($value === '' || $value === 'null' || $value === '0000-00-00'
        || $value === '0000-00-00 00:00:00') {
        if ($required) {
            oe_fail("JKR NCR item for {$what} has an empty '{$field}' and cannot be placed"
                . ' in a period');
        }
        return null;
    }

    $ts = strtotime($value);
    if ($ts === false) {
        oe_fail("JKR NCR item for {$what} has an unparseable '{$field}': " . ncrExcerpt($value, 40));
    }
    return date('Y-m-d', $ts);
}

/** QUERY: a short, flattened excerpt of a body for error messages. Without it an
 *  unexpected payload reads "not XML", which says nothing about what came back. */
function ncrExcerpt($body, $limit = 180)
{
    $flat = preg_replace('/\s+/', ' ', trim((string)$body));
    return (strlen($flat) > $limit) ? substr($flat, 0, $limit) . '...' : $flat;
}