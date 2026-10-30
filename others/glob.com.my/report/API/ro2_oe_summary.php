<?php

/**
 * API/ro2_oe_summary.php
 *
 * Routine RO2 (Shoulder) data source for the Summary O&E Performance report.
 * Wire its oe_summary_items row to this file with column_mode = 'mtd_ytd'.
 *
 * Range-driven: it answers for whatever [period_start, period_end] it is handed,
 * so the SAME code serves column A (Month to Date) and column B (Year to Date).
 *
 * The three lines, and where each comes from — all of it is shoulderReport.php's
 * TOTAL row, restated for a date range instead of a month picker:
 *
 *   a. Work done in m2
 *      SUM(area_left + area_right) from shoulder_daily over rows whose doneDate
 *      falls inside the range.
 *
 *      GRADING ONLY. This is the one deliberate divergence from the reference:
 *      shoulderReport.php counts BOTH 'Grading' and 'Filling' (it skips only
 *      'Topping Up'), so its figures WILL be higher than this line. That is
 *      intended, not a bug — do not "fix" it by widening the filter.
 *
 *      TEMPORARY — MANUAL OPENING BALANCE. shoulder_daily does not tie out to
 *      the manual record for the first half of 2026, so work done up to
 *      30 Jun 2026 is taken as a fixed figure (RO2_BASELINE_M2) and the system
 *      is only read from 1 Jul 2026 onward. July MTD is therefore pure system
 *      data; YTD is manual + July system. See the constants below for how to
 *      remove this once shoulder_daily is complete.
 *
 *   b. Work done in % against 8% yearly target
 *      work_done_m2 / total RO2 shoulder area of ALL districts * 100. The 8% is
 *      the yearly target the figure is read against, not part of the formula —
 *      same shape as RO1's 0.6% line.
 *
 *      Both lines carry an EXPLICIT change figure measured in this file, the
 *      same way RO1's (a) and (b) do and for the same reasons — read the range
 *      twice, once to $end and once to the day before, and emit the difference.
 *      On 03 Aug: Month to Date is (01-03 Aug) minus (01-02 Aug), Year to Date
 *      is (01 Jan-03 Aug) minus (01 Jan-02 Aug). Each column measures against
 *      its own earlier reading. On the 1st of a month Month to Date carries no
 *      chip, because it covers a single day and has nothing earlier inside it,
 *      while Year to Date still does. The full argument, including why the old
 *      delta_mode 'mirror' was wrong and why measuring beats reading yesterday's
 *      stored snapshot, is in ro1_oe_summary.php's docblock; it is not repeated
 *      here.
 *
 *      Section 4 carries the one thing that is specific to RO2: the earlier
 *      reading is bounded by the SYSTEM ERA's start, not the range's, so the
 *      manual opening balance is never asked to split.
 *
 *   c. Monthly target
 *      0.670% per calendar month (8% / 12), summed over every month the range
 *      touches: July MTD = 0.670%, Jan-Jul YTD = 4.690%.
 *
 *      Lines (a) and (b) are TINTED against it — green at or above target, red
 *      below. See section 6 for why one test colours both.
 *
 * One database: GLOB_DB (district_info, shoulder_record, shoulder_daily).
 */

require_once __DIR__ . '/oe_api_common.php';


/* ---- Rules carried over from shoulderReport.php -------------------------- */

// See the docblock: grading only, unlike the reference page's Grading+Filling.
define('RO2_SHOULDER_TYPE', 'Grading');

/* ---- TEMPORARY: manual opening balance ----------------------------------
 * The window below is served by the manual total instead of shoulder_daily.
 * Anything after it is read from the system as usual. Two rules make this safe:
 * the system query is clamped to start the day AFTER the window closes, so no
 * day is ever counted twice; and a range covering only PART of the window is a
 * hard error, because one manual lump sum cannot be apportioned.
 *
 * TO REMOVE once shoulder_daily is complete: delete these three constants and
 * ro2OpeningBalanceM2(), then set $workDoneM2 = ro2SystemWorkDoneM2(...) with
 * $start in section 4. Nothing else depends on them.
 */
define('RO2_BASELINE_START', '2026-01-01');   // first day the manual total covers
define('RO2_BASELINE_END',   '2026-06-30');   // last day the manual total covers
define('RO2_BASELINE_M2',    1179171.95);     // manual work done over that window, m2

/**
 * QUERY: this month's share of the yearly target, in percent.
 * 8% / 12 = 0.6667, filed as 0.670. $month is carried so real per-month figures
 * can replace the flat rate without touching a caller — same shape as
 * ro1MonthlyTargetPct().
 */
function ro2MonthlyTargetPct($month)
{
    $targets = [
        1 => 0.670,
        2 => 0.670,
        3 => 0.670,
        4 => 0.670,
        5 => 0.670,
        6 => 0.670,
        7 => 0.670,
        8 => 0.670,
        9 => 0.670,
        10 => 0.670,
        11 => 0.670,
        12 => 0.670,
    ];
    return isset($targets[$month]) ? $targets[$month] : 0.0;
}

/**
 * QUERY: the manual opening balance applicable to [start, end], in m2.
 *
 * Three cases, and only three:
 *   no overlap with the manual window        -> 0
 *   range covers the whole manual window     -> the full manual total
 *   range covers PART of the manual window   -> hard error
 * The third case has no honest answer. Splitting one manual total across a
 * partial range would invent a number that looks exactly as authoritative as a
 * measured one, so it stops instead and says why.
 */
function ro2OpeningBalanceM2($start, $end)
{
    if ($end < RO2_BASELINE_START || $start > RO2_BASELINE_END) return 0.0;
    if ($start <= RO2_BASELINE_START && $end >= RO2_BASELINE_END) return RO2_BASELINE_M2;

    oe_fail('range ' . $start . ' to ' . $end . ' covers only part of the manual RO2 window ('
        . RO2_BASELINE_START . ' to ' . RO2_BASELINE_END . '). That window is a single manual'
        . ' total and cannot be apportioned across a partial range.');
}

/**
 * QUERY: system-recorded grading area over [start, end], in m2.
 *
 * cyear is bounded by the range's own years rather than hard-coded, so a range
 * crossing 31 December still reads both years' rows. doneDate is the field that
 * actually decides which period a row lands in — cyear is only a partition
 * guard, mirroring the reference's own pair of conditions.
 */
function ro2SystemWorkDoneM2($conn, $start, $end, array $districts)
{
    $startYear   = (string)(int)date('Y', strtotime($start));
    $endYear     = (string)(int)date('Y', strtotime($end));
    $shoulderTyp = RO2_SHOULDER_TYPE;

    $sql =
        "SELECT COALESCE(SUM(COALESCE(sd.area_left, 0) + COALESCE(sd.area_right, 0)), 0) AS area
         FROM shoulder_daily sd
         WHERE sd.status = 1
           AND sd.shoulder_type = ?
           AND sd.doneDate BETWEEN ? AND ?
           AND sd.cyear BETWEEN ? AND ?"
        . oe_in_sql($conn, 'sd.district', $districts);

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) oe_fail('shoulder_daily prepare failed: ' . mysqli_error($conn));
    mysqli_stmt_bind_param($stmt, 'sssss', $shoulderTyp, $start, $end, $startYear, $endYear);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return ($row === null) ? 0.0 : floatval($row['area']);
}


/* ========================= 1. Resolve the range =========================== */

$range = oe_request_range();
$start = $range['start'];
$end   = $range['end'];
$year  = $range['year'];

$conn = oe_connect(GLOB_DB);


/* ========================= 2. District roster ============================ */

// shoulderReport.php swaps in second_name for years before 2024 — those are the
// pre-rename district names the older shoulder rows are filed under.
$useSecondName = ($year < 2024);

$roster = [];
$stmt = mysqli_prepare(
    $conn,
    "SELECT district_name, second_name
     FROM district_info
     WHERE expire_date >= ?
     ORDER BY district_code ASC"
);
if ($stmt === false) oe_fail('district_info prepare failed: ' . mysqli_error($conn));
mysqli_stmt_bind_param($stmt, 's', $end);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) {
    $name   = trim((string)$row['district_name']);
    $second = trim((string)$row['second_name']);
    if ($useSecondName && $second !== '') $name = $second;
    if ($name !== '') $roster[$name] = true;
}
mysqli_stmt_close($stmt);
if (empty($roster)) oe_fail('no active districts in district_info for ' . $end);


/* ========================= 3. Shoulder area (denominator of line b) ====== */

// shoulderReport.php's rule, verbatim in shape: find the LATEST month that has
// records for the year, then take the NEWEST row per district in that month.
// The area is therefore a single point-in-time state, shared by both columns —
// MTD and YTD are measured against the same denominator, which is what makes
// them comparable.
$cyear = (string)$year;

$stmt = mysqli_prepare(
    $conn,
    "SELECT MAX(cmonth) AS latest_month FROM shoulder_record WHERE cyear = ? AND status = '1'"
);
if ($stmt === false) oe_fail('shoulder_record prepare failed: ' . mysqli_error($conn));
mysqli_stmt_bind_param($stmt, 's', $cyear);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

$latestMonth = ($row === null) ? null : $row['latest_month'];
if ($latestMonth === null || $latestMonth === '') {
    oe_fail("no shoulder_record rows with status='1' for year {$cyear} — RO2_area is unknown");
}

// NOTE: the reference does NOT filter status inside this ranked query, only in
// the MAX(cmonth) lookup above. Kept identical so the denominator ties out with
// the existing page; widening it here would silently move every percentage.
$areaByDistrict = [];
$stmt = mysqli_prepare(
    $conn,
    "SELECT district, RO2_area
     FROM (
         SELECT district, RO2_area,
                ROW_NUMBER() OVER (PARTITION BY district ORDER BY id DESC) AS rn
         FROM shoulder_record
         WHERE cyear = ? AND cmonth = ?
     ) ranked
     WHERE rn = 1"
);
if ($stmt === false) oe_fail('shoulder_record ranked prepare failed: ' . mysqli_error($conn));
mysqli_stmt_bind_param($stmt, 'ss', $cyear, $latestMonth);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) {
    $district = trim((string)$row['district']);
    if (!isset($roster[$district])) continue;          // ignore rows for retired districts
    $areaByDistrict[$district] = floatval($row['RO2_area']);
}
mysqli_stmt_close($stmt);

// Fail loud on a short denominator. Line (b) is explicitly "against the shoulder
// area of the WHOLE district set", so a district with no RO2_area silently
// shrinks the denominator and INFLATES the percentage that gets emailed out —
// the same reasoning as RO1's zero-denominator guard. The reference page
// tolerates this and quietly scores the district 0; it is a data hole either
// way, and a named error is the only version anyone will act on.
// To tolerate it instead, delete this block: array_sum() already ignores the
// missing districts.
$missing = [];
foreach (array_keys($roster) as $district) {
    if (!isset($areaByDistrict[$district]) || $areaByDistrict[$district] <= 0) {
        $missing[] = $district;
    }
}
if (!empty($missing)) {
    oe_fail('shoulder_record has no usable RO2_area for ' . $cyear . '-' . $latestMonth
        . ' in: ' . implode(', ', $missing)
        . ' — line (b) is measured against the whole-state shoulder area, so a short'
        . ' denominator would overstate it');
}

$totalShoulderArea = array_sum($areaByDistrict);


/* ========================= 4. Line a — work done in m2 =================== */

// Manual era first, system era second, never both for the same day: when the
// opening balance applies, the system query is clamped to the day after the
// manual window closes.
$openingM2 = ro2OpeningBalanceM2($start, $end);
$sysStart  = ($openingM2 > 0.0)
    ? date('Y-m-d', strtotime(RO2_BASELINE_END . ' +1 day'))
    : $start;

$districts = array_keys($roster);

$systemM2 = ($sysStart > $end)
    ? 0.0
    : ro2SystemWorkDoneM2($conn, $sysStart, $end, $districts);

$workDoneM2  = $openingM2 + $systemM2;
$workDonePct = ($workDoneM2 / $totalShoulderArea) * 100.0;

// Yesterday's figure for this SAME period, read from shoulder_daily in this run
// rather than from a stored snapshot. Unlike RO1 this is a one-row aggregate
// over a single table, so a second call is cheaper than materialising rows to
// fold twice — and ro2SystemWorkDoneM2() already exists to be called.
//
// $sysStart bounds the earlier reading, NOT $start, and that is what keeps the
// manual opening balance out of it. That balance is one lump over a CLOSED
// window which cannot be apportioned — ro2OpeningBalanceM2() stops rather than
// invent a split — so asking for [start, end-1] would hard-fail the endpoint on
// the single day the earlier reading falls inside that window. Measuring the
// system era only is also the correct answer: both readings carry the identical
// lump in full, so it cancels out of the subtraction.
//
// The roster and $totalShoulderArea are shared by both readings, resolved once
// as at the report's own end date, so the chip measures shoulder work rather
// than roster or survey churn.
$prevEnd = oe_previous_end($sysStart, $end);
$deltaM2 = ($prevEnd === null)
    ? null
    : $systemM2 - ro2SystemWorkDoneM2($conn, $sysStart, $prevEnd, $districts);

// Line (b)'s movement is line (a)'s over the SHARED denominator, not the
// difference of two separately computed percentages — one operation, and it
// cannot drift from the m2 chip by float noise.
$deltaPct = ($deltaM2 === null) ? null : ($deltaM2 / $totalShoulderArea) * 100.0;


/* ========================= 5. Line c — monthly target ==================== */

$targetPct = 0.0;
foreach (oe_month_span($start, $end) as $m) {
    $targetPct += ro2MonthlyTargetPct($m['month']);
}


/* ============ 6. Achievement colour — lines a and b ====================== */

// ONE test, both lines, and compared at the printed precision. Same reasoning as
// ro1_oe_summary.php section 6: (b) is (a) over $totalShoulderArea and the
// target is a percentage of that same area, so a separate m2 test is the same
// inequality with the denominator cancelled; and a figure that PRINTS equal to
// the target must not print red beside it.
//
// This is the only place the emitted value is rounded. The values themselves
// stay full-precision below, for the reason set out there — rounding is a
// display decision, and the colour is display.
$achievedColor = (round($workDonePct, 3) >= round($targetPct, 3)) ? 'green' : 'red';


/* ========================= 7. Emit ======================================= */

// EMIT FULL PRECISION. 'decimals' is a DISPLAY instruction — OeSummaryRenderer
// already rounds to it for the printed figure — so pre-rounding here buys
// nothing and actively corrupts the change chip beside each value.
//
// The chip is computed in OeSummaryService::cell() as
//     today's stored value MINUS yesterday's stored value
// so if both sides are stored already rounded to 3dp, each carries up to
// 0.0005 of rounding error and their DIFFERENCE carries up to 0.001 — a full
// unit in the last displayed place. Column A can round down while column B
// rounds up, and one identical underlying change then prints as two different
// numbers: MTD +0.016% beside YTD +0.017% on 24 Jul 2026, where the true
// change was +0.016363% on both (same numerator delta over the same
// denominator — $totalShoulderArea is shared by the two columns by design).
//
// Storing the unrounded value costs nothing: the displayed figures are
// identical, because the renderer rounds them anyway.
oe_emit([
    oe_metric_delta([
        'key' => 'work_done_m2',
        'label' => 'a. Work done in m2',
        'value' => $workDoneM2,
        'format' => 'number',
        'decimals' => 2,
        'color' => $achievedColor,
    ], $deltaM2),
    oe_metric_delta([
        'key' => 'work_done_pct',
        'label' => 'b. Work done in % against 8% yearly target',
        'value' => $workDonePct,
        'format' => 'percent',
        'decimals' => 3,
        'color' => $achievedColor,
    ], $deltaPct),
    [
        'key' => 'monthly_target',
        'label' => 'c. Monthly target',
        'value' => $targetPct,
        'format' => 'percent',
        'decimals' => 3,
        'delta_mode' => 'none'
    ],
]);