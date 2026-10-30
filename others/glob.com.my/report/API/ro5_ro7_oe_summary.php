<?php
/**
 * API/ro5_ro7_oe_summary.php
 *
 * Routine "RO5 & RO7 Delay" (Bridge, Culvert & Drain) data source for the
 * Summary O&E Performance report. Wire its oe_summary_items row to this file
 * with column_mode = 'ro3week_last_prev' (Reporting Week | Previous Week).
 *
 * The change figure beside column A is the gap to column B (week over week),
 * not a day-over-day movement — see DELTA_PEER in ColumnPairResolver. Both lines
 * emit 'lower_is_better', so a fall in delay is chipped green and a rise red.
 *
 * WHY ONE ENDPOINT FOR TWO ROUTINES, NOT TWO:
 * The reference system already treats RO5 and RO7 as a single reporting
 * stream — routineWorksReport.php's RO5 fetch/render calls are commented out
 * (no RO5-specific table exists), and report_ro7_drain.php's own Tier-4
 * screen labels its data "RO5 & RO7 (Bridge, Culvert & Drain)". RO7's delay
 * columns (weekly_am_report_ro7_delays / weekly_am_reports.ro7_delayed_work)
 * are the combined Bridge + Culvert (RO5) + Drain (RO7) figure. So this
 * endpoint reuses that same source as-is; there is no separate RO5 delay
 * table to add in. If a genuinely separate RO5 delay table gets built later,
 * this is the one file to extend — add its SUM into $metrics before line (a).
 *
 * Only $end comes from the resolver: 'ro3week_last_prev' already resolves to
 * RO3-calendar-week boundaries, matching report_ro7_drain.php's own
 * getRO7WeeksInMonth, so $end lands on a report_week boundary and needs no
 * extra week math. Note this deliberately does NOT reuse routine 7's own
 * historical column_mode ('ro3week_this_last' / This Week-Last Week, per
 * ColumnPairResolver) — this is a distinct summary item and the user wants
 * Reporting Week / Previous Week here, same as RO3 and RO4.
 *
 * Both figures are CUMULATIVE CYCLE-TO-DATE, not per-week. RO7's cycle is the
 * half year (Jan-Jun / Jul-Dec), NOT the month RO3 uses — that is what
 * report_ro7_drain.php's "Cumulative Jan 1 / Jul 1 - <week end>" column
 * reports and what this report has to agree with, so the window opens at
 * oe_cycle_start($end); fetchRO7CumulativeData() and the tier 3/4 fetchers
 * (getRO7CycleWindow) all resolve the same window.
 *
 * Consequence, and the reason the window was widened: anchoring on
 * $range['start'] read the reporting week ALONE, so a district that skipped
 * that week dropped out of the figure entirely instead of carrying its last
 * submission forward. Cycle-to-date carries it forward, matching RO3.
 *
 * The two lines, sourced from report_ro7_drain.php's fetchRO7CumulativeData:
 *
 *   a. Total delay in km
 *      The LATEST weekly_am_reports.ro7_delayed_work in the window, per
 *      district — never a SUM. The AM re-enters the whole-cycle total on every
 *      weekly save ("Cumulative From 1st Day Of Cycle"), so the newest row in
 *      the window already IS the cycle figure; summing multiplies it by the
 *      number of weeks submitted.
 *
 *      DELIBERATELY SINGLE-TIER — no $detailTable is passed, so this reads the
 *      same one source fetchRO7CumulativeData() reads, and nothing else. Adding
 *      weekly_am_report_ro7_delays as a first tier read 24.407 km over 1-31 Jul
 *      2026 where report_ro7_drain.php's "Total Cumulative Delay (km)" printed
 *      19.666. Both figures are defensible in isolation; only one of them is
 *      the number on the page this endpoint exists to agree with.
 *
 *      NOTE the drill-downs disagree with the tier 1 total by design, and that
 *      predates this file: report_ro7_drain.php's tier 3/4 screens
 *      (fetchRO7SubconDataForTier3, fetchRO7RoadDataForTier4) SUM
 *      weekly_am_report_ro7_delays over the same cycle window, while its tier
 *      1/2 cumulative column reads weekly_am_reports alone. If that is ever
 *      reconciled in the reference page, pass RO7_DETAIL_TABLE / RO7_DETAIL_COL
 *      back into the call below and the two move together again.
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

// Kept named, though only the weekly_am_reports column is read today — see the
// NOTE above for the one condition under which the detail table comes back in.
const RO7_DETAIL_TABLE = 'weekly_am_report_ro7_delays';
const RO7_DETAIL_COL   = 'delay_length';
const RO7_FALLBACK_COL = 'ro7_delayed_work';


/* ========================= 1. Resolve the range =========================== */

$range = oe_request_range();
$end   = $range['end'];

// The column's own week, for line (c) only. Kept separate from $start below:
// (a)/(b) are cycle-to-date, (c) is a per-week presence test.
$weekStart = $range['start'];

// Cycle-to-date, per the note above: the floor is the 1st day of the half year
// holding $end (Jan 1 or Jul 1), exactly as fetchRO7CumulativeData() computes
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
    RO7_FALLBACK_COL
    // no detail table — fetchRO7CumulativeData() reads weekly_am_reports alone,
    // and this figure has to be the one that page prints. See the NOTE above.
);


// No detail table, same as (a): report_ro7_drain.php's per-week presence test
// reads weekly_am_reports alone (fetchRO7DelayData).
$incomplete = oe_ro_missing_districts(
    $connGlobmy,
    $weekStart,
    $end,
    $roster,
    RO7_FALLBACK_COL
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