<?php
/**
 * API/ro3_oe_summary.php
 *
 * Routine RO3 (Grass Cutting) data source for the Summary O&E Performance
 * report. Wire its oe_summary_items row to this file with
 * column_mode = 'ro3week_last_prev' (Reporting Week | Previous Week).
 *
 * The change figure beside column A is the gap to column B (week over week),
 * not a day-over-day movement — see DELTA_PEER in ColumnPairResolver. Both lines
 * emit 'lower_is_better', so a fall in delay is chipped green and a rise red.
 *
 * Both figures are CUMULATIVE MONTH-TO-DATE, not per-week. That is what
 * report_ro3_grass.php's "Total Cumulative Delay (km)" column reports and what
 * this report has to agree with, so the window opens at the 1st of the month
 * containing the column's last day — fetchRO3CumulativeData() does the same.
 * Only $end comes from the resolver: 'ro3week_last_prev' lands it on an RO3
 * calendar-week boundary (1-7 / 8-14 / 15-21 / 22-end — see
 * ColumnPairResolver::ro3Week), which is exactly how report_week is filed, so
 * no extra week math is needed here.
 *
 * Consequence of a cumulative metric: inside a month the two columns can only
 * rise, and at week 1 the pair compares a fresh month-to-date against the whole
 * of the month before. Inherent to the figure, not an artefact of this file.
 *
 * The two lines, sourced from report_ro3_grass.php's fetchRO3CumulativeData:
 *
 *   a. Total delay in km
 *      SUM(delay_length) from weekly_am_report_ro3_delays over the window, per
 *      district; any district with no row there falls back to weekly_am_reports.
 *      A detail-table row of 0 stands (submitted, no delay) and is not
 *      re-queried in the fallback — see oe_ro_delay_metrics().
 *
 *      CONTRACT oe_ro_delay_metrics() MUST HONOUR: the fallback takes the LATEST
 *      ro3_delayed_work in the window (ORDER BY report_week DESC LIMIT 1), never
 *      a SUM. ro3-delay-manager.js writes the modal's whole-cycle total into that
 *      column on every weekly save, so the value is already a running total and
 *      summing it multiplies it by the number of weeks submitted. Harmless while
 *      the window was one week wide; not harmless now. report_ro3_grass.php
 *      lines 908-920 states the same rule.
 *
 *   b. Number of districts showing delay
 *      Count of districts (from the roster below) whose total from (a) is
 *      greater than 0.
 *
 *   c. Incomplete districts
 *      Roster districts with NO submission in the column's own week. Note the
 *      window: lines (a) and (b) are cumulative and open at the 1st of the month, (c)
 *      opens at $range['start']. Submission is a per-week event — carry it
 *      forward like the delay figure and one submission in the month would mark a
 *      district complete for the rest of it. A district that submitted ZERO
 *      delay is complete, not incomplete: it reported, it just had nothing to
 *      report. See oe_ro_missing_districts().
 *
 * Two databases:
 *   GLOB_DB   (district_info — the roster)
 *   GLOBMY_DB (weekly_am_report_ro3_delays, weekly_am_reports — see the
 *              ASSUMPTION note on GLOBMY_DB in oe_api_common.php)
 */

require_once __DIR__ . '/oe_api_common.php';

const RO3_DETAIL_TABLE = 'weekly_am_report_ro3_delays';
const RO3_DETAIL_COL   = 'delay_length';
const RO3_FALLBACK_COL = 'ro3_delayed_work';


/* ========================= 1. Resolve the range =========================== */

$range = oe_request_range();
$end   = $range['end'];

// The column's own week, for line (c) only. Kept separate from $start below:
// (a)/(b) are month-to-date, (c) is a per-week presence test.
$weekStart = $range['start'];

// Month-to-date, per the note above: the floor is the 1st of the month holding
// $end, exactly as fetchRO3CumulativeData() computes it. Anchoring on
// $range['start'] (the 15th, for week 3) filed ONE week's delays under a
// cumulative heading — 44.124 where report_ro3_grass.php showed 60.312.
$start = date('Y-m-01', strtotime($end));


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
    RO3_FALLBACK_COL,
    RO3_DETAIL_TABLE,
    RO3_DETAIL_COL
);


$incomplete = oe_ro_missing_districts(
    $connGlobmy,
    $weekStart,
    $end,
    $roster,
    RO3_FALLBACK_COL,
    RO3_DETAIL_TABLE
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