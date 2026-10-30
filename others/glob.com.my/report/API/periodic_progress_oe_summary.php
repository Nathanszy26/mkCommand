<?php
/**
 * API/periodic_progress_oe_summary.php
 *
 * "Periodic Progress" (report line 7) data source for the Summary O&E
 * Performance report. Wire its oe_summary_items row to this file with
 * column_mode = 'sunweek_this_last' (This Week | Last Week, Sun-Sat).
 *
 * IT MUST BE THAT MODE, not 'week_this_last'. pmw_progress.cDate carries a
 * SUNDAY date — a week's claims are filed against the Sunday that opens the
 * week, which is why worksProgramSummary.php anchors both its bars on Sunday
 * ("As at 24 Jul" vs "Last week 18 Jul", the preceding Saturday). On ISO
 * Mon-Sun weeks the week IN PROGRESS never reaches its own Sunday, so every
 * claim falls into the previous column and line (b) reads 0.00% on every
 * weekday — a number that looks like "no work done this week" rather than like
 * a mis-set boundary.
 *
 * Range-driven like every other endpoint here: it answers for whatever
 * [period_start, period_end] it is handed.
 *
 * The two lines, sourced from worksProgramSummary.php's "Total" row:
 *
 *   a. Cumulative work done in %
 *      SUM(pmw_progress.amt_Achievement) for every claim dated ON OR BEFORE
 *      period_end, over the total works value of the year's periodic programme.
 *      This is the report's Total row percentage, evaluated as at period_end
 *      instead of "everything in the table".
 *
 *   b. Incremental progress in %
 *      The same figure at period_end MINUS the same figure as at the day before
 *      period_start, i.e. the progress claimed inside the period, expressed
 *      against the SAME denominator. So (b) is exactly the gap between this
 *      column's (a) and the previous column's (a) — which is what the reference's
 *      two-bar chart ("As at" vs "Last week") draws per district.
 *
 * ONE FUNCTION PRODUCES BOTH. periodicAchievedAsAt() is called twice with two
 * cut-off dates; the increment is their difference. The increment therefore
 * cannot drift from the cumulative, and cannot exceed it, by construction — two
 * separately-written queries could do both.
 *
 * WHAT COUNTS AS THE PROGRAMME (all carried over verbatim from the reference):
 *   - Denominator = district works value + SP works value.
 *       District items: pmw rows whose item_no prefix is an active district
 *       shortname, district_name <> 'SP', category in Pavement / NonPavement /
 *       Bridge, item_no NOT containing '_ER'. Works value = amt_budget, except
 *       that an amt_budget below 0.01 is replaced by MAX(amt_worksValue) from
 *       pmw_progress for that item when that is larger — the reference's
 *       "budget not entered yet, fall back to the claimed works value" rule.
 *       SP items: pmw rows with district_name = 'SP' in the same three
 *       categories. The reference applies NEITHER the zero-budget fallback NOR
 *       the _ER exclusion to SP, and neither does this.
 *   - Numerator = amt_Achievement from pmw_progress, in the same three
 *       categories, for either (i) an item in the eligible district set above,
 *       or (ii) an SP item (item_no starting 'SP_' or containing '_SP_'). SP
 *       claims are NOT checked against a budgeted item, exactly as in the
 *       reference. A progress row in any OTHER category is dropped: the
 *       reference accumulates those into $amtX, which is never added to
 *       $grantAmt.
 *
 * ROUNDING IS PART OF THE SOURCE, not a display detail. Both lines go through
 * oe_rounding_up(), the port of the PMS roundingUp(). Using round() puts this
 * endpoint one hundredth below the report it is checked against on ~4.6% of
 * values. Line (b) is the difference of the two ROUNDED cumulatives so that it
 * reconciles with the (a) figures printed either side of it.
 *
 * NOT THE "GRAND TOTAL" ROW. The reference prints a second, lower figure that
 * adds RESERVE_SP and SAVING to the denominator. Those are unallocated money,
 * not works: including them measures how much of the envelope has been spent,
 * not how much of the programme has been built. "Work done in %" is the Total
 * row. To switch, add those two amt_estimate values to $worksValue below.
 *
 * ALL PHASES, NOT ONE. The reference is a per-phase page (its default is P1).
 * A single summary line cannot be per-phase, so PERIODIC_PHASES sums P1-P4 into
 * one programme; ER is excluded, matching the reference, which keeps ER out of
 * its Total row and offers it as a separate selection. If the business tracks
 * only the live phase, edit that one array — it is the only place the phase set
 * is stated.
 *
 * AS AT period_end, NOT "EVERYTHING". The reference's current figure applies no
 * date filter at all, so a claim dated in the FUTURE is already in its Total row.
 * This endpoint cuts at period_end. If the two disagree on the cumulative line
 * and nothing else explains it, look for rows with cDate > today:
 *   SELECT phase, item_no, cDate, amt_Achievement FROM pmw_progress
 *    WHERE cyear = '2026' AND cDate > CURDATE();
 *
 * KNOWN, DELIBERATE DIVERGENCE: worksProgramSummary.php skips its whole SP block
 * when cyear is 2020. That guard is not reproduced here. 'sunweek_this_last' can
 * never resolve to 2020, and threading a dead flag through two function
 * signatures costs more than the parity is worth. If 2020 is ever backfilled,
 * this endpoint will read higher than the reference for that year alone.
 *
 * Two databases:
 *   GLOB_DB (district_info — the roster that defines the item_no prefixes)
 *   PMS_DB  (pmw, pmw_progress)
 */

require_once __DIR__ . '/oe_api_common.php';


/* ---- Rules carried over from worksProgramSummary.php --------------------- */

// The programme this line reports on. ER is not part of it — see the docblock.
$PERIODIC_PHASES = array('P1', 'P2', 'P3', 'P4');

// The only categories that reach the reference's Total row. Anything else is
// counted into $amtX there and never added to the totals.
$PERIODIC_CATEGORIES = array('Pavement', 'NonPavement', 'Bridge');

// The reference skips this district outright.
define('PERIODIC_EXCLUDED_SHORTNAME', 'LBN');

// Below this, amt_budget is treated as "not entered" and the claimed works value
// stands in for it.
define('PERIODIC_BUDGET_EPSILON', 0.01);

// district_info.second_shortname is the pre-rename code. pmw item_no prefixes for
// programme years before this one were written with it.
define('PERIODIC_RENAME_BEFORE_YEAR', 2024);

// The district_name that carries the centrally-held (non-district) works.
define('PERIODIC_SP_DISTRICT', 'SP');


/* ========================= 1. Resolve the range =========================== */

$range    = oe_request_range();
$start    = $range['start'];
$end      = $range['end'];
$year     = $range['year'];
$yearStr  = (string)$year;

// The cumulative baseline the increment is measured from: everything already
// claimed before this period opened.
$baseline = date('Y-m-d', strtotime($start . ' -1 day'));

// oe_in_sql() returns an empty fragment for an empty list, which would silently
// drop the filter and widen the programme to every phase and category in the
// table. Both lists are hard-coded above, so an empty one is an edit mistake.
if (empty($PERIODIC_PHASES))     oe_fail('PERIODIC_PHASES is empty — refusing to read every phase');
if (empty($PERIODIC_CATEGORIES)) oe_fail('PERIODIC_CATEGORIES is empty — refusing to read every category');


/* ========================= 2. District roster ============================= */

$connGlob = oe_connect(GLOB_DB);
$prefixes = periodicRosterPrefixes($connGlob, $end, $year);


/* ========================= 3. Denominator — works value =================== */

$connPms  = oe_connect(PMS_DB);
$fallback = periodicClaimedWorksValue($connPms, $yearStr, $PERIODIC_PHASES);
$district = periodicDistrictBudget($connPms, $yearStr, $PERIODIC_PHASES, $PERIODIC_CATEGORIES, $prefixes, $fallback);
$spValue  = periodicSpBudget($connPms, $yearStr, $PERIODIC_PHASES, $PERIODIC_CATEGORIES);

$worksValue = $district['total'] + $spValue;

// Fail loud: a zero denominator renders as 0.00% on both lines, which reads as
// "nothing has been built this year" rather than "the programme is not loaded".
if ($worksValue <= 0) {
    oe_fail('total periodic works value is 0 for cyear ' . $yearStr
        . ' phases ' . implode('/', $PERIODIC_PHASES) . ' — check pmw in ' . PMS_DB);
}


/* ========================= 4. Numerators — two cut-offs =================== */

$achievedToEnd      = periodicAchievedAsAt($connPms, $yearStr, $PERIODIC_PHASES, $PERIODIC_CATEGORIES, $district['eligible'], $end);
$achievedToBaseline = periodicAchievedAsAt($connPms, $yearStr, $PERIODIC_PHASES, $PERIODIC_CATEGORIES, $district['eligible'], $baseline);


/* ========================= 5. Emit ======================================== */

// oe_rounding_up(), NOT round(). worksProgramSummary.php prints every one of its
// percentages through roundingUp() (line 376 onward), which lifts once the
// remainder past the second decimal exceeds 0.0045 where round() waits for
// 0.0050. On the same money that is a 0.01 disagreement: 30,301,000 of
// 37,000,000 is 81.8945946%, which the source prints as 81.90 and round() as
// 81.89. The two disagree on ~4.6% of values, so this is not a one-off.
$cumulativePct = oe_rounding_up(($achievedToEnd / $worksValue) * 100.0, 2);
$baselinePct   = oe_rounding_up(($achievedToBaseline / $worksValue) * 100.0, 2);

// (b) is the difference of the two ROUNDED cumulatives, not the rounding of the
// raw difference. Both this column's (a) and the next column's (a) are printed
// side by side, so a reader can subtract them on the page; rounding (b)
// independently would sometimes land 0.01 off that subtraction and read as an
// arithmetic error in the report. round() here only clears binary-float noise
// from subtracting two 2dp values (81.9 - 79.58 = 2.3199999999999932) — it
// cannot move the hundredth.
$incrementalPct = round($cumulativePct - $baselinePct, 2);

oe_emit(array(
    array('key' => 'cumulative_work_pct',     'label' => 'a. Cumulative work done in %',
          'value' => $cumulativePct,  'format' => 'percent', 'decimals' => 2),
    array('key' => 'incremental_progress_pct', 'label' => 'b. Incremental progress in %',
          'value' => $incrementalPct, 'format' => 'percent', 'decimals' => 2),
));


/* ============================== helpers ============================== */

/**
 * QUERY: the item_no prefixes the programme is filed under, as a lookup set.
 * @return array prefix => true
 *
 * A set rather than a list because every pmw and pmw_progress row is tested
 * against it; a list would make that an in_array() scan per row.
 */
function periodicRosterPrefixes($conn, $end, $year)
{
    $useSecond = ($year < PERIODIC_RENAME_BEFORE_YEAR);
    $prefixes  = array();

    foreach (oe_district_roster_pairs($conn, $end) as $d) {
        if ($d['shortname'] === PERIODIC_EXCLUDED_SHORTNAME) continue;
        $code = ($useSecond && $d['second_shortname'] !== '')
            ? $d['second_shortname']
            : $d['shortname'];
        $prefixes[$code] = true;
    }

    if (empty($prefixes)) oe_fail('no usable district prefixes resolved for ' . $end);
    return $prefixes;
}

/**
 * QUERY: the largest works value ever CLAIMED per item, as a lookup map.
 * @return array "phase|item_no" => float
 *
 * Only consulted for items whose amt_budget has not been entered yet. Read in
 * one grouped query instead of the reference's per-item sub-query, which fires
 * once per zero-budget row.
 */
function periodicClaimedWorksValue($conn, $yearStr, array $phases)
{
    $sql = "SELECT phase, item_no, MAX(amt_worksValue) AS max_works_value
            FROM pmw_progress
            WHERE cyear = ?"
        . oe_in_sql($conn, 'phase', $phases)
        . " GROUP BY phase, item_no";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) oe_fail('pmw_progress works-value prepare failed: ' . mysqli_error($conn));
    mysqli_stmt_bind_param($stmt, 's', $yearStr);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $out = array();
    while ($row = mysqli_fetch_assoc($res)) {
        $out[periodicItemKey($row['phase'], $row['item_no'])] = floatval($row['max_works_value']);
    }
    mysqli_stmt_close($stmt);

    return $out;
}

/**
 * QUERY: the district half of the denominator, plus the set of items whose
 * progress claims are allowed to count towards the numerator.
 * @return array ['total' => float, 'eligible' => array "phase|item_no" => true]
 *
 * The two come back together because they are one rule read once: an item counts
 * in the numerator if and only if it carried works value in the denominator.
 * Deriving the set from a second query could let the two disagree.
 */
function periodicDistrictBudget($conn, $yearStr, array $phases, array $categories, array $prefixes, array $fallback)
{
    // COALESCE, not a plain <>: a NULL district_name is a district row in the
    // reference (it tests != 'SP' in PHP), and SQL's <> would silently drop it.
    $sql = "SELECT phase, item_no, amt_budget
            FROM pmw
            WHERE cyear = ?
              AND COALESCE(district_name, '') <> '" . PERIODIC_SP_DISTRICT . "'"
        . oe_in_sql($conn, 'phase', $phases)
        . oe_in_sql($conn, 'category', $categories);

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) oe_fail('pmw district budget prepare failed: ' . mysqli_error($conn));
    mysqli_stmt_bind_param($stmt, 's', $yearStr);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $total    = 0.0;
    $eligible = array();

    while ($row = mysqli_fetch_assoc($res)) {
        $itemNo = trim((string)$row['item_no']);

        // Not one of this year's live districts (also drops RESERVE_*, SAVING).
        if (!isset($prefixes[periodicItemPrefix($itemNo)])) continue;

        // Emergency Response works are a separate programme, excluded from the
        // reference's Total row wherever '_ER' appears in the item number.
        if (strpos($itemNo, '_ER') !== false) continue;

        $key   = periodicItemKey($row['phase'], $itemNo);
        $value = floatval($row['amt_budget']);

        // Budget not entered yet: stand the largest claimed works value in for it,
        // but only when that is actually larger — same test as the reference.
        if ($value < PERIODIC_BUDGET_EPSILON
            && isset($fallback[$key]) && $fallback[$key] > $value) {
            $value = $fallback[$key];
        }

        $total += $value;
        $eligible[$key] = true;
    }
    mysqli_stmt_close($stmt);

    return array('total' => $total, 'eligible' => $eligible);
}

/**
 * QUERY: the SP half of the denominator.
 *
 * Deliberately NOT sharing periodicDistrictBudget(): the reference applies
 * neither the zero-budget fallback nor the _ER exclusion to SP, and it produces
 * no eligible set because SP progress claims are never membership-checked.
 * Folding the two together would mean a flag per difference.
 */
function periodicSpBudget($conn, $yearStr, array $phases, array $categories)
{
    $sql = "SELECT SUM(amt_budget) AS total
            FROM pmw
            WHERE cyear = ?
              AND district_name = '" . PERIODIC_SP_DISTRICT . "'"
        . oe_in_sql($conn, 'phase', $phases)
        . oe_in_sql($conn, 'category', $categories);

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) oe_fail('pmw SP budget prepare failed: ' . mysqli_error($conn));
    mysqli_stmt_bind_param($stmt, 's', $yearStr);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return ($row === null) ? 0.0 : floatval($row['total']);
}

/**
 * QUERY: total amt_Achievement claimed on or before $asAt.
 *
 * The ONE numerator. Line (a) is this at period_end; line (b) is this at
 * period_end minus this at the day before period_start. Because it is the same
 * query with a wider bound, the period figure can never exceed the cumulative
 * one and the two can never be computed on different rules.
 */
function periodicAchievedAsAt($conn, $yearStr, array $phases, array $categories, array $eligible, $asAt)
{
    // "< next day" rather than "<= $asAt": correct whether cDate is stored as a
    // DATE or a DATETIME, and still able to use an index on cDate.
    $sql = "SELECT phase, item_no, amt_Achievement
            FROM pmw_progress
            WHERE cyear = ?
              AND cDate < DATE_ADD(?, INTERVAL 1 DAY)"
        . oe_in_sql($conn, 'phase', $phases)
        . oe_in_sql($conn, 'category', $categories);

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) oe_fail('pmw_progress achievement prepare failed: ' . mysqli_error($conn));
    mysqli_stmt_bind_param($stmt, 'ss', $yearStr, $asAt);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $total = 0.0;
    while ($row = mysqli_fetch_assoc($res)) {
        $itemNo = trim((string)$row['item_no']);

        // SP first, and exclusively: the reference skips '_SP_' items in its
        // district loop and picks them up in its SP block, unfiltered.
        if (periodicIsSpItem($itemNo)) {
            $total += floatval($row['amt_Achievement']);
            continue;
        }

        // Otherwise the claim only counts if the item carried works value.
        // That set already excludes '_ER' items, so no second test is needed.
        if (isset($eligible[periodicItemKey($row['phase'], $itemNo)])) {
            $total += floatval($row['amt_Achievement']);
        }
    }
    mysqli_stmt_close($stmt);

    return $total;
}

/** QUERY: the key an item is identified by. Phase-qualified — the same item_no
 *  can exist in more than one phase, and each phase carries its own budget. */
function periodicItemKey($phase, $itemNo)
{
    return trim((string)$phase) . '|' . trim((string)$itemNo);
}

/** QUERY: the district shortname an item_no is filed under ('KMU_P1_03' -> 'KMU'),
 *  or '' for an item with no prefix at all (SAVING), which matches no district. */
function periodicItemPrefix($itemNo)
{
    $pos = strpos($itemNo, '_');
    return ($pos === false) ? '' : substr($itemNo, 0, $pos);
}

/** QUERY: whether a progress row belongs to the SP pool, using the reference's
 *  own two patterns (LEFT(item_no,3)='SP_' OR item_no LIKE '%_SP_%'). */
function periodicIsSpItem($itemNo)
{
    return (strpos($itemNo, PERIODIC_SP_DISTRICT . '_') === 0)
        || (strpos($itemNo, '_' . PERIODIC_SP_DISTRICT . '_') !== false);
}