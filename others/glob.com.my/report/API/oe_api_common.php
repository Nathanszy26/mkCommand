<?php
/**
 * API/oe_api_common.php — shared plumbing for every roN_oe_summary.php endpoint.
 *
 * Each routine endpoint owns exactly one thing: the SQL that turns
 * [period_start, period_end] into its own metric lines. Everything AROUND that —
 * the JSON envelope, the credentials, parsing the request, escaping an IN list,
 * walking the months a range touches — is identical for all eight and lives here
 * once.
 *
 * Nothing in this file knows what a pothole, a shoulder or a district is. Pure
 * plumbing, no domain logic; the domain stays in the endpoint that owns it.
 *
 * Contract implemented here (called by OeHttpMetricFetcher in oe_summary_lib.php):
 *   POST JSON in : { comp_id, item_id, routine_code, column_mode, column_slot,
 *                    period_key, period_start, period_end }
 *   Success      : { "ok":true, "metrics":[ {key,label,value,format,decimals}, ... ] }
 *   Failure      : { "ok":false, "error":"..." }  -> fetcher logs it and the
 *                  column renders "No data yet" WITH the reason in ?test=1.
 *
 * GET is accepted for the same two range fields so an endpoint can be tested in
 * isolation from a browser:
 *   ...?period_start=2026-07-01&period_end=2026-07-23
 */

header('Content-Type: application/json; charset=UTF-8');

/* ---- DB config — the one copy. -------------------------------------------
 * Same credentials heatmap_lib.php / oe_summary_lib.php use. Endpoints must not
 * redefine these; they require this file and use the constants.
 * ------------------------------------------------------------------------- */
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'kRXvkeUGyi5er7');
define('GLOB_DB',   'glob');                 // district_info, pothole_daily, shoulder_daily, shoulder_record
define('GSHEET_DB', 'gsheet_siti');          // progress_summaryRO1 (confirmed against the live DB)

// weekly_am_reports, weekly_am_report_ro3_delays, weekly_am_report_ro7_delays.
// The reference pages (report_ro3_grass.php, report_ro4_furniture.php,
// report_ro7_drain.php) read these through a SECOND connection they call
// $conn_globmy, distinct from $conn/GLOB_DB. Named to match that convention.
// ASSUMPTION, NOT CONFIRMED against a live schema dump — if $conn_globmy in
// your environment actually points at 'glob' (i.e. it's the same database,
// just a second handle to it), change this one line to 'glob' and every
// RO3/RO4/RO5&7 endpoint below is fixed with it.
define('GLOBMY_DB', 'globmy');

// subconAssignment — the monthly subcon performance marks (r03_total, r05_total,
// r07_total ...). CONFIRMED against mysqli_connect.php: subconRoutinePerformance.php
// reads it through isetConnection('ak'), and iasignConnection() passes its argument
// straight through as the database name, with AK_USER/AK_PASSWORD identical to the
// DB_USER/DB_PASS above.
define('AK_DB', 'ak');

// pmw (periodic works budget) and pmw_progress (the dated achievement claims).
// CONFIRMED against mysqli_simple.php: worksProgramSummary.php reads both through
// isetConnection('pms'), which connects to the database literally named 'pms' with
// the same host/user/password as above.
define('PMS_DB', 'pms');

// complaints — the public complaint channel, on a DIFFERENT SERVER from every
// other constant above: its own host, port, user and password. Values copied
// verbatim from emergency_heatmap.php, which is the only page that reads it.
// Reached through oe_connect_amc(), never through oe_connect(AMC_DB).
define('AMC_HOST', '60.51.93.177');
define('AMC_PORT', 33060);
define('AMC_USER', 'prog3');
define('AMC_PASS', 'prog3@2023!!');
define('AMC_DB',   'sabahroads');


/* ─── Envelope ─────────────────────────────────────────────────────────── */

/**
 * COMMAND: emit the failure envelope and stop. Every abort in every endpoint
 * goes through here, so a failure can never fall through into a half-built
 * response — a partially computed metric list is worse than no metric list,
 * because it renders as a plausible number.
 */
function oe_fail($msg)
{
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/** COMMAND: emit the success envelope and stop. */
function oe_emit(array $metrics)
{
    echo json_encode(['ok' => true, 'metrics' => $metrics], JSON_UNESCAPED_UNICODE);
    exit;
}


/* ─── Request ──────────────────────────────────────────────────────────── */

/**
 * QUERY: the requested range, normalised.
 * @return array ['start'=>'Y-m-d', 'end'=>'Y-m-d', 'year'=>int]  (year = year of END)
 *
 * Endpoints must be RANGE-driven, never month-driven: answer for whatever range
 * you are handed and the same code serves both columns (Month to Date and Year
 * to Date) and any future column_mode unchanged.
 */
function oe_request_range()
{
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];

    $rawStart = oe_pick($in, 'period_start', isset($_GET['period_start']) ? $_GET['period_start'] : '');
    $rawEnd   = oe_pick($in, 'period_end',   isset($_GET['period_end'])   ? $_GET['period_end']   : '');

    if (strtotime($rawStart) === false || strtotime($rawEnd) === false) {
        oe_fail('period_start / period_end missing or unparseable');
    }
    $start = date('Y-m-d', strtotime($rawStart));
    $end   = date('Y-m-d', strtotime($rawEnd));
    if ($start > $end) oe_fail("period_start ({$start}) is after period_end ({$end})");

    return ['start' => $start, 'end' => $end, 'year' => (int)date('Y', strtotime($end))];
}

/** QUERY: $a[$k] when it is present and non-empty, else $default. */
function oe_pick(array $a, $k, $default)
{
    return (isset($a[$k]) && $a[$k] !== '') ? $a[$k] : $default;
}

/**
 * QUERY: the first day of the half-year CYCLE containing $date — 'Y-01-01' for
 * Jan-Jun, 'Y-07-01' for Jul-Dec.
 *
 * For endpoints whose figure is CUMULATIVE CYCLE-TO-DATE rather than a total
 * for the range it was handed. RO4 and RO5&7 are: report_ro4_furniture.php and
 * report_ro7_drain.php head their column "Cumulative Jan 1 / Jul 1 - <week
 * end>" and getRO4CycleStart() / getRO7CycleWindow() resolve the same floor, so
 * an endpoint that agrees with those pages must open its window here and take
 * only $end from the resolver.
 *
 * Deliberately NOT applied to $end, and deliberately not the default for
 * oe_request_range(): a range-driven endpoint stays range-driven. Cumulative is
 * a property of the METRIC, so the endpoint that owns the metric opts in.
 *
 * RO3 is the near miss — cumulative too, but MONTH-to-date, because
 * report_ro3_grass.php is month-based. It uses date('Y-m-01', ...) instead;
 * do not route it through here.
 */
function oe_cycle_start($date)
{
    $ts = strtotime($date);
    if ($ts === false) oe_fail("oe_cycle_start: unparseable date '{$date}'");

    return date(intval(date('n', $ts)) <= 6 ? 'Y-01-01' : 'Y-07-01', $ts);
}


/* ─── Database ─────────────────────────────────────────────────────────── */

/**
 * QUERY: a mysqli handle, or a hard stop. Never returns a dead connection.
 *
 * The four overrides default to the local server every other database here
 * lives on, so oe_connect('glob') is unchanged. They exist for the one source
 * that is remote — see oe_connect_amc() — rather than as an invitation to pass
 * credentials around: a caller that needs a different server should add a named
 * wrapper next to that one, so the credentials stay in this file.
 */
function oe_connect($dbname, $host = DB_HOST, $user = DB_USER, $pass = DB_PASS, $port = null)
{
    $c = ($port === null)
        ? @mysqli_connect($host, $user, $pass, $dbname)
        : @mysqli_connect($host, $user, $pass, $dbname, (int)$port);
    if (!$c) oe_fail("DB connect failed ({$dbname}@{$host}): " . mysqli_connect_error());
    mysqli_set_charset($c, 'utf8mb4');
    return $c;
}

/** QUERY: a handle on the remote AMC server that holds `complaints`. */
function oe_connect_amc()
{
    return oe_connect(AMC_DB, AMC_HOST, AMC_USER, AMC_PASS, AMC_PORT);
}


/* ─── SQL fragment helpers ─────────────────────────────────────────────── */

/**
 * QUERY: " AND {$expr} IN ('a','b')", or '' when the list is empty.
 *
 * Empty means NO FILTER, which for a district roster would silently widen the
 * result set. Callers that need the filter must fail loud on an empty list
 * BEFORE calling this — every endpoint here does.
 */
function oe_in_sql($conn, $expr, array $values)
{
    if (empty($values)) return '';
    return ' AND ' . $expr . ' IN (' . oe_escaped_list($conn, $values) . ')';
}

/** QUERY: " AND {$expr} NOT IN ('a','b')", or '' when the list is empty. */
function oe_not_in_sql($conn, $expr, array $values)
{
    if (empty($values)) return '';
    return ' AND ' . $expr . ' NOT IN (' . oe_escaped_list($conn, $values) . ')';
}

/** QUERY: "'a','b','c'", each value escaped against $conn. */
function oe_escaped_list($conn, array $values)
{
    $out = [];
    foreach ($values as $v) $out[] = "'" . mysqli_real_escape_string($conn, (string)$v) . "'";
    return implode(',', $out);
}


/* ─── Calendar ─────────────────────────────────────────────────────────── */

/**
 * QUERY: one entry per calendar month the range touches, in order.
 * @return array list of ['year'=>int, 'month'=>int]
 *
 * This is what makes the "monthly target" lines range-driven: July MTD touches
 * one month, Jan-Jul YTD touches seven, and the same loop sums both.
 */
function oe_month_span($start, $end)
{
    $out    = [];
    $cursor = strtotime(date('Y-m-01', strtotime($start)));
    $last   = strtotime(date('Y-m-01', strtotime($end)));
    while ($cursor <= $last) {
        $out[]  = ['year' => (int)date('Y', $cursor), 'month' => (int)date('n', $cursor)];
        $cursor = strtotime('+1 month', $cursor);
    }
    return $out;
}


/* ─── Day-over-day, measured rather than remembered ────────────────────────
 * A change chip can be produced two ways: by subtracting yesterday's STORED
 * snapshot of the same period (what OeSummaryService::baseline() does by
 * default), or by asking the source tables for the same period twice in the
 * same run. These two helpers are the second way.
 *
 * The second way is the honest one wherever it is available. The stored
 * baseline is the previous GENERATION DAY, not literally yesterday, so a missed
 * cron run, a restored database or a first run all produce a chip that silently
 * spans two days or is silently absent. A figure recomputed from the source
 * tables is correct on every one of those days and needs no history at all.
 * ------------------------------------------------------------------------- */

/**
 * QUERY: the end date of the SAME period read one day earlier, or null when
 * there is no earlier reading inside that period.
 *
 * Pair it with a range-driven endpoint and the change figure falls out:
 * answer for [start, end], answer again for [start, oe_previous_end(...)], and
 * subtract. On 03 Aug that is (01-03 Aug) minus (01-02 Aug) for Month to Date
 * and (01 Jan-03 Aug) minus (01 Jan-02 Aug) for Year to Date.
 *
 * null on the FIRST day of a period is the whole special case, and it is not an
 * error. On 01 Aug the Month to Date column covers 01-01 Aug; there is no
 * earlier reading of THAT period to subtract, and reaching back into July would
 * be comparing this month against a different month. Year to Date on the same
 * morning covers 01 Jan-01 Aug, its previous reading (01 Jan-31 Jul) exists,
 * and it still carries a chip. Both columns fall silent together only on
 * 01 January, which is correct for the same reason.
 *
 * $start is whichever date actually bounds the DATA, which is not always the
 * range's own start — see ro2_oe_summary.php, which passes the system era's
 * opening date so its manual opening balance is never asked to split.
 */
function oe_previous_end($start, $end)
{
    $prev = date('Y-m-d', strtotime($end . ' -1 day'));
    return ($prev < $start) ? null : $prev;
}

/**
 * QUERY: $metric carrying its change figure — an explicit 'delta' when one was
 * measured, 'delta_mode' => 'none' when there was none to measure.
 *
 * The two fields are mutually exclusive by contract (OeHttpMetricFetcher
 * ::normalise() rejects a metric declaring both), and the null case has to be
 * STATED rather than left off: a metric carrying neither field falls back to
 * the column pair's own rule and prints a chip derived from yesterday's stored
 * snapshot — exactly the figure these helpers exist to replace. Leaving it off
 * would therefore not mean "no chip", it would mean "the old chip, silently".
 */
function oe_metric_delta(array $metric, $delta)
{
    if ($delta === null) {
        $metric['delta_mode'] = 'none';
    } else {
        $metric['delta'] = $delta;
    }
    return $metric;
}


/* ─── Shared district-roster + delay-metric helpers ────────────────────────
 * RO3, RO4, and RO7 (which "RO5 & 7 Delay" reuses — see the docblock in
 * ro5_ro7_oe_summary.php) all key their delay tables off district_info's
 * shortname column, not district_name (that split is what RO1/RO2 use
 * instead). Same roster rule and same two-tier delay read in all three, so it
 * lives here once rather than being pasted three times.
 * ------------------------------------------------------------------------ */

/**
 * QUERY: active district shortname codes, districts still live at $end.
 * Fails loud on an empty roster — every caller needs a non-empty list before
 * it can safely build an IN(...) filter.
 */
function oe_district_roster_codes($conn, $end)
{
    $codes = [];
    foreach (oe_district_roster($conn, $end) as $d) {
        if ($d['shortname'] !== '') $codes[] = $d['shortname'];
    }
    if (empty($codes)) oe_fail('no active district shortname in district_info for ' . $end);
    return $codes;
}

/**
 * QUERY: the active roster itself — one entry per district still live at $end,
 * carrying every identifier column the endpoints key on.
 * @return array list of ['district_code','district_name','shortname','second_shortname']
 *
 * The ONE read of district_info. The two projections below are views onto it,
 * because three copies of the same WHERE clause is three places for the roster
 * rule to drift. Endpoints that need only one identifier should keep calling
 * those; an endpoint whose two data sources file cases under DIFFERENT
 * identifiers (emergency_progress_oe_summary.php keys complaints on
 * district_code and supervisor reports on district_name) needs this.
 */
function oe_district_roster($conn, $end)
{
    $out  = [];
    $stmt = mysqli_prepare($conn,
        "SELECT district_code, district_name, shortname, second_shortname
         FROM district_info
         WHERE expire_date >= ?
         ORDER BY district_code ASC");
    if ($stmt === false) oe_fail('district_info prepare failed: ' . mysqli_error($conn));
    mysqli_stmt_bind_param($stmt, 's', $end);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $out[] = [
            'district_code'    => trim((string)$row['district_code']),
            'district_name'    => trim((string)$row['district_name']),
            'shortname'        => trim((string)$row['shortname']),
            'second_shortname' => trim((string)$row['second_shortname']),
        ];
    }
    mysqli_stmt_close($stmt);
    if (empty($out)) oe_fail('no active districts in district_info for ' . $end);
    return $out;
}

/**
 * QUERY: active districts as (current code, pre-rename code) pairs, districts
 * still live at $end.
 *
 * Same roster rule as oe_district_roster_codes(); it exists separately because
 * district_info.second_shortname is the code the OLDER rows were written with,
 * and any endpoint that can be handed a pre-2024 range has to resolve which of
 * the two its source table actually filed under. Endpoints that never look back
 * that far should keep using oe_district_roster_codes().
 *
 * @return array list of ['shortname' => ..., 'second_shortname' => ...]
 */
function oe_district_roster_pairs($conn, $end)
{
    $out = [];
    foreach (oe_district_roster($conn, $end) as $d) {
        if ($d['shortname'] === '') continue;
        $out[] = [
            'shortname'        => $d['shortname'],
            'second_shortname' => $d['second_shortname'],
        ];
    }
    if (empty($out)) oe_fail('no active district shortname in district_info for ' . $end);
    return $out;
}

/**
 * QUERY: the PMS house rounding. Port of roundingUp() in mysqli_simple.php.
 *
 * NOT round(). It biases the tie toward the ceiling: with $precision = 2 it
 * lifts once the remainder past the second decimal exceeds 0.0045, where
 * round() waits for 0.0050. Over that 0.0005 band the two disagree by 0.01 —
 * which is exactly how a percentage here ends up one hundredth below the
 * figure the source report prints for the same money.
 *
 * Any endpoint whose number is checked against a PMS page must round with this
 * and not with round(). ro1_oe_summary.php carries its own local copy from
 * before this existed; it can be deleted in favour of this one, the $jkr path
 * included, whenever that file is next touched.
 *
 * @param float|int|string $value
 * @param int              $precision decimals; 0 is treated as 1, as in the original
 * @param string           $jkr       'JKR' truncates to $precision + 2 decimals first
 */
function oe_rounding_up($value = 0, $precision = 2, $jkr = '')
{
    $amt = floatval($value);

    if ($jkr === 'JKR') {
        $cAmt = (string)$value;
        $nPos = strpos($cAmt, '.');
        if ($nPos !== false) $cAmt = substr($cAmt, 0, $nPos + $precision + 2);
        $amt = floatval($cAmt);
    }

    if ($precision == 0) $precision = 1;

    $pow   = pow(10, $precision);
    $ceil  = ceil($amt * $pow) / $pow;
    $floor = floor($amt * $pow) / $pow;

    // The +1 (sign-following) is the bias. Without it this is plain round-half-up.
    $scale     = pow(10, $precision + 1);
    $diffCeil  = $scale * ($ceil - $amt);
    $diffFloor = $scale * ($amt - $floor) + ($amt < 0 ? -1 : 1);

    return ($diffCeil >= $diffFloor) ? $floor : $ceil;
}

/**
 * QUERY: total delay (km) + number of districts showing delay, over
 * [start, end], for a given roster of district codes.
 *
 * Two-tier read, exactly mirroring the reference pages' fetchRO{3,7}CumulativeData:
 *   1. If $detailTable is given: SUM($detailCol) per district_code from it
 *      (status = 1, deleted = 0, report_week BETWEEN start/end).
 *   2. Any roster district NOT answered by the detail table falls back to the
 *      LATEST $fallbackCol in weekly_am_reports over the same range — never a
 *      SUM. That column is a RUNNING TOTAL: the RO delay modal writes its
 *      whole-cycle figure into it on every weekly save (ro3-delay-manager.js),
 *      so the newest row in the window already IS the period figure.
 * A detail-table row of exactly 0 counts as "answered" (submitted, no delay)
 * and is NOT re-queried in step 2 — same rule the reference pages use, so a
 * district that reported zero delay isn't double-counted or re-summed.
 *
 * "Showing delay" (line b) = districts whose summed delay is > 0, i.e. a
 * district that submitted a zero doesn't count as delayed, but does count
 * toward the roster the total is measured against.
 *
 * @param string[] $rosterCodes non-empty list of district_code values
 * @return array ['total_delay' => float, 'district_count' => int]
 */
/**
 * QUERY: how many roster districts filed NOTHING for [start, end].
 *
 * WINDOW IS THE COLUMN'S OWN WEEK, never the cumulative floor
 * oe_ro_delay_metrics() is handed. Submission is a per-week event: widen it to
 * cycle-to-date and every district that reported once in July counts as
 * complete for the rest of the half year. Endpoints therefore pass
 * $range['start'] here and oe_cycle_start()/month-start there — the two lines
 * answer different questions about the same column and cannot share a floor.
 *
 * "Submitted" mirrors the reference pages' per-week presence test exactly: a
 * row in $detailTable (status = 1, deleted = 0) OR a non-null $fallbackCol row
 * in weekly_am_reports. A submitted ZERO is a submission — the district
 * reported, it simply had no delay — so it is complete, not incomplete.
 * Pass $detailTable only where the reference page checks it: RO3 does
 * (fetchRO3DelayData runs both presence queries), RO4 and RO7 do not
 * (fetchRO{4,7}DelayData read weekly_am_reports alone).
 *
 * @param string[] $rosterCodes non-empty list of district_code values
 * @return int districts in the roster with no submission in the window
 */
function oe_ro_missing_districts($conn, $start, $end, array $rosterCodes, $fallbackCol, $detailTable = null)
{
    if (empty($rosterCodes)) oe_fail('empty district roster passed to oe_ro_missing_districts');

    $roster    = array_unique($rosterCodes);
    $submitted = [];
    $queries   = [];

    if ($detailTable !== null) {
        $queries[] = "SELECT DISTINCT district_code
                      FROM {$detailTable}
                      WHERE report_week >= ? AND report_week <= ?
                        AND status = 1 AND deleted = 0"
            . oe_in_sql($conn, 'district_code', $roster);
    }

    $queries[] = "SELECT DISTINCT district_code
                  FROM weekly_am_reports
                  WHERE report_week >= ? AND report_week <= ?
                    AND {$fallbackCol} IS NOT NULL"
        . oe_in_sql($conn, 'district_code', $roster);

    foreach ($queries as $sql) {
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt === false) oe_fail('presence query prepare failed: ' . mysqli_error($conn));
        mysqli_stmt_bind_param($stmt, 'ss', $start, $end);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $submitted[$row['district_code']] = true;
        }
        mysqli_stmt_close($stmt);
    }

    return count($roster) - count($submitted);
}

function oe_ro_delay_metrics($conn, $start, $end, array $rosterCodes, $fallbackCol, $detailTable = null, $detailCol = null)
{
    if (empty($rosterCodes)) oe_fail('empty district roster passed to oe_ro_delay_metrics');

    $byDistrict = [];   // district_code => float delay
    $answered   = [];   // codes already answered by the detail table

    if ($detailTable !== null) {
        $sql = "SELECT district_code, SUM({$detailCol}) AS total_delay
                FROM {$detailTable}
                WHERE report_week >= ? AND report_week <= ?
                  AND status = 1 AND deleted = 0"
            . oe_in_sql($conn, 'district_code', $rosterCodes)
            . " GROUP BY district_code";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt === false) oe_fail("{$detailTable} prepare failed: " . mysqli_error($conn));
        mysqli_stmt_bind_param($stmt, 'ss', $start, $end);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            if ($row['total_delay'] !== null) {
                $code = $row['district_code'];
                $byDistrict[$code] = floatval($row['total_delay']);
                $answered[$code]   = true;
            }
        }
        mysqli_stmt_close($stmt);
    }

    $remaining = array_values(array_diff($rosterCodes, array_keys($answered)));
    if (!empty($remaining)) {
        // Summing a running total multiplied it by the number of weeks submitted
        // AND resurrected retracted figures: over 1-21 Jul 2026, KDT summed to
        // 12.294 after its detail rows were deleted and its latest submission
        // reset to 0. Zero was the truth. Same rule as report_ro3_grass.php
        // lines 908-920.
        //
        // Join-to-MAX rather than ROW_NUMBER: MySQL 5.7 has no window functions.
        // A district with no row in the window still returns nothing and stays
        // out of $byDistrict, exactly as the SUM version left it. Two rows tying
        // on the newest report_week resolve to one of them, which is what the
        // reference page's ORDER BY ... LIMIT 1 does too.
        $sql = "SELECT w.district_code, w.{$fallbackCol} AS total_delay
                FROM weekly_am_reports w
                JOIN (
                    SELECT district_code, MAX(report_week) AS report_week
                    FROM weekly_am_reports
                    WHERE report_week >= ? AND report_week <= ?
                      AND {$fallbackCol} IS NOT NULL"
            . oe_in_sql($conn, 'district_code', $remaining)
            . "     GROUP BY district_code
                ) latest ON latest.district_code = w.district_code
                        AND latest.report_week   = w.report_week
                WHERE w.{$fallbackCol} IS NOT NULL";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt === false) oe_fail('weekly_am_reports prepare failed: ' . mysqli_error($conn));
        mysqli_stmt_bind_param($stmt, 'ss', $start, $end);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            if ($row['total_delay'] !== null) {
                $byDistrict[$row['district_code']] = floatval($row['total_delay']);
            }
        }
        mysqli_stmt_close($stmt);
    }

    $districtCount = 0;
    foreach ($byDistrict as $delay) {
        if ($delay > 0) $districtCount++;
    }

    return [
        'total_delay'     => array_sum($byDistrict),
        'district_count'  => $districtCount,
    ];
}