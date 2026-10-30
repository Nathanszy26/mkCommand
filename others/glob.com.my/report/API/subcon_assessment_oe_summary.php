<?php
/**
 * API/subcon_assessment_oe_summary.php
 *
 * "Routine Subcon Assessment (RO3, RO5 & RO7)" data source for the Summary O&E
 * Performance report. Wire its oe_summary_items row to this file with
 * column_mode = 'month_last_prev' (Last Month | Previous Month) — that mode was
 * added to ColumnPairResolver for this item; both its columns are WHOLE, CLOSED
 * months, which matters here because subconAssignment is keyed by yearmonth and
 * a half-finished month would report a provisional mark as if it were final.
 *
 * NOT NAMED ro6_*. There IS a real r06 (Drain, pre-2015 — see the category
 * dropdown in subconRoutinePerformance.php, which only offers r06 when the year
 * is <= 2014 and r07 after). r06_total is a live column in the same table and is
 * DELIBERATELY EXCLUDED here. Naming this file after the report's line number
 * would invite someone to "fix" it by adding r06 back in.
 *
 * WHAT ONE COUNT IS: ONE SUBCON, ONE COUNT.
 * For each (district, subcon, month) the RO3, RO5 and RO7 marks that were
 * actually filed are averaged, and that ONE average lands in ONE band. Assessed
 * on all three routines -> divide by 3; on RO3 and RO5 only -> divide by 2; on a
 * single routine -> divide by 1. A subcon with none of the three filed is not
 * counted at all, however many other routines it was marked on. Across a
 * multi-month range each month is averaged on its own, so a subcon assessed in
 * three months contributes three counts — that is what makes the same code serve
 * one-month columns and any wider range unchanged.
 *
 * WHERE A MARK COMES FROM:
 * timelines + performance + quality + safety + commitment for that routine,
 * summed exactly as subconRoutineAssessmentReport.php does. The stored rXX_total
 * columns are NOT read: the only statement in the reference that would maintain
 * them is commented out, and it never covered r07_total at all, so they cannot
 * be assumed to agree with the components they are supposed to total.
 *
 * Only rows with a non-empty issuedBy are read, matching the reference's query.
 * An assessment nobody issued is not an assessment.
 *
 * The five lines are the mark bands, counted over RO3 + RO5 + RO7 only:
 *
 *   a. Below 6 marks      mark <  6
 *   b. 6 - 6.9 marks      6 <= mark < 7
 *   c. 7 - 7.9 marks      7 <= mark < 8
 *   d. 8 - 8.9 marks      8 <= mark < 9
 *   e. 9 - 10 marks       9 <= mark <= 10
 *
 * ...followed by a Total line: how many assessments were counted at all. It is
 * the sum of the five bands by construction, not a second query, so the two can
 * never disagree. It is worth showing because the bands alone cannot distinguish
 * "marks got worse" from "fewer subcons were assessed this month" — only the
 * total moving tells you which happened.
 *
 * Bands are half-open on the upper edge, and the AVERAGE is banded exactly as
 * computed — never re-rounded first. A 6.95 belongs in "6 - 6.9", which is the
 * whole 6.x range; reading that label as an inclusive 6.00-6.90 would silently
 * drop every mark between 6.91 and 6.99 from all five bands, and rounding the
 * average to 1 dp beforehand would push that same 6.95 into "7 - 7.9".
 *
 * NOT ASSESSED IS NOT A ZERO. subconRoutinePerformance.php renders any total
 * below 0.001 as blank, i.e. "no assessment filed", not "scored zero". Those are
 * excluded from every band. Counting them would dump the entire unassessed
 * population into "Below 6 marks" and make line (a) meaningless.
 *
 * Two databases:
 *   GLOB_DB (district_info — the roster, and the pre-2024 shortname rule)
 *   AK_DB   (subconAssignment — the marks)
 */

require_once __DIR__ . '/oe_api_common.php';


/* ---- Rules carried over from subconRoutinePerformance.php ---------------- */

// Only these three. r01/r02/r04 are other report lines; r06 is the pre-2015
// Drain routine superseded by r07 — see the docblock. Keyed by COLUMN PREFIX:
// each routine's mark is summed from the five parts below, not read from
// rXX_total.
$ASSESSED_ROUTINES = array(
    'r03' => 'RO3',
    'r05' => 'RO5',
    'r07' => 'RO7',
);

// Below this, the reference prints a blank cell: no assessment filed.
define('SUBCON_MARK_EPSILON', 0.001);

// The scheme is out of 10 ("Total (10 marks)" in the reference's header).
define('SUBCON_MARK_MAX', 10.0);

// district_info.second_shortname is the pre-rename district code. The reference
// swaps it in for any yearmonth before this one.
define('SUBCON_RENAME_BEFORE', '2024-01');


/* ========================= 1. Resolve the range =========================== */

$range = oe_request_range();
$start = $range['start'];
$end   = $range['end'];


/* ========================= 2. District roster ============================ */

// Read once; the rename rule is applied per month against this one snapshot.
// Not oe_district_roster_codes(): that helper returns shortname only, and this
// report needs second_shortname too.
$connGlob  = oe_connect(GLOB_DB);
$districts = subconAssessmentDistricts($connGlob, $end);


/* ========================= 3. Count the bands ============================= */

$connAk    = oe_connect(AK_DB);
$counts    = array_fill_keys(array_keys(subconAssessmentBands()), 0);
$conflicts = array();
$invalid   = array();

foreach (oe_month_span($start, $end) as $m) {
    $yearmonth = sprintf('%04d-%02d', $m['year'], $m['month']);
    $codes     = subconAssessmentCodesFor($districts, $yearmonth);
    if (empty($codes)) oe_fail('no district codes resolved for ' . $yearmonth);

    $sql = "SELECT shortname, subconname, " . implode(', ', subconAssessmentColumns(array_keys($ASSESSED_ROUTINES)))
         . " FROM subconAssignment
            WHERE yearmonth = ?
              AND issuedBy <> ''"
         . oe_in_sql($connAk, 'shortname', $codes);

    $stmt = mysqli_prepare($connAk, $sql);
    if ($stmt === false) oe_fail('subconAssignment prepare failed: ' . mysqli_error($connAk));
    mysqli_stmt_bind_param($stmt, 's', $yearmonth);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    // subconAssignment is not guaranteed unique on (yearmonth, shortname,
    // subconname) — the reference loops its result set and keeps whatever row
    // came last, so its figures depend on MySQL's return order. Same rule as
    // RO1's pavement_area: identical duplicates are cosmetic and collapse to one
    // count; disagreeing duplicates have no principled winner, so stop rather
    // than guess which mark was real.
    $seen = array();

    while ($row = mysqli_fetch_assoc($res)) {
        $key = $row['shortname'] . '|' . $row['subconname'];

        $marks = array();
        foreach ($ASSESSED_ROUTINES as $prefix => $routine) {
            $marks[$prefix] = subconAssessmentMark($row, $prefix);
        }

        if (isset($seen[$key])) {
            foreach ($marks as $prefix => $mark) {
                if (abs($seen[$key][$prefix] - $mark) > SUBCON_MARK_EPSILON) {
                    $conflicts[] = sprintf('%s %s %s (%s: %.2f vs %.2f)',
                        $yearmonth, $row['shortname'], $row['subconname'],
                        $ASSESSED_ROUTINES[$prefix], $seen[$key][$prefix], $mark);
                }
            }
            continue;   // counted already on the first row for this key
        }
        $seen[$key] = $marks;

        // Collect only the routines actually filed, then average THOSE. The
        // divisor is how many were filed, never a fixed 3 — dividing a single
        // RO3 mark by 3 would drag every partly-assessed subcon into band (a).
        $filed = array();
        foreach ($marks as $prefix => $mark) {
            if ($mark < 0 || $mark > SUBCON_MARK_MAX + SUBCON_MARK_EPSILON) {
                $invalid[] = sprintf('%s %s %s %s = %.2f',
                    $yearmonth, $row['shortname'], $row['subconname'],
                    $ASSESSED_ROUTINES[$prefix], $mark);
                continue;
            }
            if ($mark < SUBCON_MARK_EPSILON) continue;   // not assessed, not a zero
            $filed[] = $mark;
        }

        // None of RO3/RO5/RO7 filed: not an assessment, not a band, not a count.
        if (empty($filed)) continue;

        $counts[subconAssessmentBandKey(array_sum($filed) / count($filed))]++;
    }
    mysqli_stmt_close($stmt);
}

// Fail loud on both, and name every offender: these are data faults someone has
// to go and fix, and a band chart that silently omits or misfiles them still
// looks entirely plausible.
if (!empty($invalid)) {
    oe_fail('subconAssignment holds marks outside 0-' . SUBCON_MARK_MAX . ' — ' . implode('; ', $invalid));
}
if (!empty($conflicts)) {
    oe_fail('subconAssignment has disagreeing duplicate rows — ' . implode('; ', $conflicts));
}


/* ========================= 4. Emit ======================================== */

$metrics = array();
foreach (subconAssessmentBands() as $key => $label) {
    $metrics[] = array(
        'key'      => $key,
        'label'    => $label,
        'value'    => $counts[$key],
        'format'   => 'integer',
        'decimals' => 0,
    );
}

// Summed from the same $counts the bands were emitted from — one source, so the
// total cannot drift from the lines above it. 'emphasis' asks the renderer to
// rule it off and set it in heavier type instead of printing a sixth band.
$metrics[] = array(
    'key'      => 'assessments_total',
    'label'    => 'Total',
    'value'    => array_sum($counts),
    'format'   => 'integer',
    'decimals' => 0,
    'emphasis' => true,
);

oe_emit($metrics);


/* ============================== helpers ============================== */

/**
 * QUERY: the five mark bands, in report order, as key => label.
 * One definition drives the band keys, the emitted labels and the zeroed
 * counter array, so a band cannot exist in one and be missing from another.
 */
function subconAssessmentBands()
{
    return array(
        'marks_below_6' => 'a. Below 6 marks',
        'marks_6_to_69' => 'b. 6 - 6.9 marks',
        'marks_7_to_79' => 'c. 7 - 7.9 marks',
        'marks_8_to_89' => 'd. 8 - 8.9 marks',
        'marks_9_to_10' => 'e. 9 - 10 marks',
    );
}

/**
 * QUERY: the five parts every routine's mark is made of, in the reference's own
 * order. One definition drives both the SELECT list and the summing, so a part
 * cannot be fetched without being counted or counted without being fetched.
 * @return string[]
 */
function subconAssessmentParts()
{
    return array('timelines', 'performance', 'quality', 'safety', 'commitment');
}

/**
 * QUERY: the subconAssignment columns holding the marks for $prefixes.
 * @param string[] $prefixes e.g. array('r03', 'r05', 'r07')
 * @return string[]          e.g. array('r03_timelines', ... , 'r07_commitment')
 */
function subconAssessmentColumns(array $prefixes)
{
    $columns = array();
    foreach ($prefixes as $prefix) {
        foreach (subconAssessmentParts() as $part) {
            $columns[] = $prefix . '_' . $part;
        }
    }
    return $columns;
}

/**
 * QUERY: one routine's mark for one subconAssignment row, out of 10.
 * Summed from the five parts, exactly as subconRoutineAssessmentReport.php does
 * — see the docblock for why rXX_total is not read instead.
 */
function subconAssessmentMark(array $row, $prefix)
{
    $mark = 0.0;
    foreach (subconAssessmentParts() as $part) {
        $mark += floatval($row[$prefix . '_' . $part]);
    }
    return $mark;
}

/** QUERY: which band a mark falls in. Callers must exclude unassessed marks first. */
function subconAssessmentBandKey($mark)
{
    if ($mark < 6.0) return 'marks_below_6';
    if ($mark < 7.0) return 'marks_6_to_69';
    if ($mark < 8.0) return 'marks_7_to_79';
    if ($mark < 9.0) return 'marks_8_to_89';
    return 'marks_9_to_10';
}

/**
 * QUERY: active districts with both the current and pre-rename codes.
 * @return array list of ['shortname' => ..., 'second_shortname' => ...]
 */
function subconAssessmentDistricts($conn, $end)
{
    $out  = array();
    $stmt = mysqli_prepare($conn,
        "SELECT shortname, second_shortname
         FROM district_info
         WHERE expire_date >= ?
         ORDER BY shortname ASC");
    if ($stmt === false) oe_fail('district_info prepare failed: ' . mysqli_error($conn));
    mysqli_stmt_bind_param($stmt, 's', $end);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $short = trim((string)$row['shortname']);
        if ($short === '') continue;
        $out[] = array(
            'shortname'        => $short,
            'second_shortname' => trim((string)$row['second_shortname']),
        );
    }
    mysqli_stmt_close($stmt);
    if (empty($out)) oe_fail('no active districts in district_info for ' . $end);
    return $out;
}

/**
 * QUERY: the district codes subconAssignment files $yearmonth under.
 * Pure mapping over the roster — before 2024-01 the reference reads the
 * pre-rename second_shortname instead, so a backfilled older month resolves to
 * the codes its rows were actually written with rather than matching nothing.
 * @return string[]
 */
function subconAssessmentCodesFor(array $districts, $yearmonth)
{
    $useSecond = ($yearmonth < SUBCON_RENAME_BEFORE);
    $codes     = array();
    foreach ($districts as $d) {
        $codes[] = ($useSecond && $d['second_shortname'] !== '')
            ? $d['second_shortname']
            : $d['shortname'];
    }
    return array_values(array_unique($codes));
}