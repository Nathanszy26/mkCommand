<?php
/**
 * API/ro4_oe_summary.php
 *
 * Routine RO4 (Road Furniture) data source for the Summary O&E Performance
 * report. Wire its oe_summary_items row to this file with
 * column_mode = 'ro3week_last_prev' (Reporting Week | Previous Week).
 *
 * The change figure beside column A is the gap to column B (week over week),
 * not a day-over-day movement — see DELTA_PEER in ColumnPairResolver. Both lines
 * emit 'lower_is_better', so a fall in delay is chipped green and a rise red.
 *
 * Only $end comes from the resolver: 'ro3week_last_prev' already resolves to
 * RO3-calendar-week boundaries, and report_ro4_furniture.php's own
 * getRO4WeeksInMonth files weeks the same way, so $end lands on a report_week
 * boundary and needs no extra week math.
 *
 * Both figures are CUMULATIVE CYCLE-TO-DATE, not per-week. RO4's cycle is the
 * half year (Jan-Jun / Jul-Dec), NOT the month RO3 uses — that is what
 * report_ro4_furniture.php's "Cumulative Jan 1 / Jul 1 - <week end>" column
 * reports and what this report has to agree with, so the window opens at
 * oe_cycle_start($end) and fetchRO4CumulativeData() does the same.
 *
 * Consequence, and the reason the window was widened: anchoring on
 * $range['start'] read the reporting week ALONE, so a district that skipped
 * that week dropped out of the figure entirely instead of carrying its last
 * submission forward. Cycle-to-date carries it forward, matching RO3.
 *
 * The two lines, sourced from report_ro4_furniture.php's fetchRO4CumulativeData:
 *
 *   a. Total delay in km
 *      weekly_am_reports.ro4_delayed_work over the window, per district.
 *      UNLIKE RO3/RO7, RO4 has no separate detail table in the reference —
 *      weekly_am_reports is the only source — so this is a single-tier read
 *      (no $detailTable passed to oe_ro_delay_metrics()).
 *
 *      CONTRACT oe_ro_delay_metrics() MUST HONOUR, same as RO3: the
 *      weekly_am_reports read takes the LATEST ro4_delayed_work in the window
 *      (ORDER BY report_week DESC LIMIT 1), never a SUM. The AM re-enters the
 *      whole-cycle total on every weekly save ("Cumulative From 1st Day Of
 *      Cycle"), so the value is already a running total and summing it
 *      multiplies it by the number of weeks submitted. Harmless while the
 *      window was one week wide; not harmless now.
 *
 *   b. Number of districts showing delay
 *      Count of districts (from the roster below) whose total from (a) is
 *      greater than 0.
 *
 *   c. Incomplete districts
 *      Roster districts with NO submission in the column's own week. Note the
 *      window: lines (a) and (b) are cumulative and open at the cycle start, (c)
 *      opens at $range['start']. Submission is a per-week event — carry it
 *      forward like the delay figure and one submission in the cycle would mark a
 *      district complete for the rest of it. A district that submitted ZERO
 *      delay is complete, not incomplete: it reported, it just had nothing to
 *      report. See oe_ro_missing_districts().
 *
 * Two databases:
 *   GLOB_DB   (district_info — the roster)
 *   GLOBMY_DB (weekly_am_reports — see the ASSUMPTION note on GLOBMY_DB in
 *              oe_api_common.php)
 */

require_once __DIR__ . '/oe_api_common.php';

const RO4_FALLBACK_COL = 'ro4_delayed_work';


/* ========================= 1. Resolve the range =========================== */

$range = oe_request_range();
$end   = $range['end'];

// The column's own week, for line (c) only. Kept separate from $start below:
// (a)/(b) are cycle-to-date, (c) is a per-week presence test.
$weekStart = $range['start'];

// Cycle-to-date, per the note above: the floor is the 1st day of the half year
// holding $end (Jan 1 or Jul 1), exactly as fetchRO4CumulativeData() computes
// it. oe_cycle_start() lives in oe_api_common.php — RO3 uses the monthly
// date('Y-m-01') floor instead because report_ro3_grass.php is month-based.
$start = oe_cycle_start($end);


/* ========================= 2. District roster ============================ */

$connGlob = oe_connect(GLOB_DB);
$roster   = oe_district_roster_codes($connGlob, $end);


/* ========================= 3. Delay metrics =============================== */

$connGlobmy = oe_connect(GLOBMY_DB);
$metrics    = oe_ro_delay_metrics(
    $connGlobmy,
    $start,
    $end,
    $roster,
    RO4_FALLBACK_COL
    // no detail table — weekly_am_reports is the only source for RO4
);


// No detail table, same as (a): report_ro4_furniture.php's per-week presence
// test reads weekly_am_reports alone (fetchRO4DelayData).
$incomplete = oe_ro_missing_districts(
    $connGlobmy,
    $weekStart,
    $end,
    $roster,
    RO4_FALLBACK_COL
);


/* ========================= 4. Emit ========================================= */

// 'lower_is_better': every line here is bad when it rises — delay, districts
// delayed, districts that failed to report — so a week-over-week fall is the
// good outcome and the change chip is coloured accordingly. Without it the
// renderer's default (up is good) prints 19km of extra delay in green.
oe_emit([
    ['key' => 'total_delay_km',      'label' => 'a. Total delay in km',
     'value' => round($metrics['total_delay'], 3),    'format' => 'number',  'decimals' => 3,
     'lower_is_better' => true],
    ['key' => 'districts_delayed',   'label' => 'b. Number of district showing delay works',
     'value' => $metrics['district_count'],           'format' => 'integer', 'decimals' => 0,
     'lower_is_better' => true],
    ['key' => 'incomplete_districts', 'label' => 'c. Incomplete submission',
     'value' => $incomplete,                          'format' => 'integer', 'decimals' => 0,
     'lower_is_better' => true],
]);