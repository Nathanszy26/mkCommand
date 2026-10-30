<?php
/**
 * API/ro1_oe_summary.php
 *
 * Routine RO1 (Pavement Maintenance) data source for the Summary O&E Performance
 * report. Wire an oe_summary_items row to this file with column_mode = 'mtd_ytd'.
 *
 * Plumbing (envelope, credentials, range parsing, SQL helpers) lives in
 * oe_api_common.php. This file is only RO1's four lines.
 *
 * The endpoint is range-driven, not month-driven: it answers for whatever
 * [period_start, period_end] it is handed, so the SAME code serves both columns
 * (Month to Date and Year to Date) and would serve any other range unchanged.
 *
 * The four lines, and where each comes from:
 *
 *   a. Work done in m2
 *      summary_pavement_workdone.php's logic, totalled across all districts.
 *      Per item: area = roundingUp(final_length * final_width, 2, 'JKR').
 *      Date used = submit_on_jan when set, else jobdone (same as the reference).
 *      Per (district, month): if the district has ANY Borang-A area that month,
 *      ONLY the Borang-A area counts; otherwise the non-Borang-A area counts.
 *      That per-district-per-month rule is what the reference's monthly TOTAL row
 *      does — a plain SUM of everything would NOT match the existing report.
 *
 *   b. Work done in % against 0.6% yearly target
 *      work_done_m2 / total pavement_area (progress_summaryRO1) * 100. The 0.6%
 *      is the yearly target the figure is read against, not part of the formula
 *      — same as the reference.
 *
 *      Lines (a) and (b) carry an EXPLICIT change figure, measured in this file:
 *      the endpoint reads its own range twice against the source tables — once
 *      for [start, end] and once for [start, end minus one day] — and emits the
 *      difference. On 03 Aug that is (01-03 Aug) minus (01-02 Aug) for Month to
 *      Date, and (01 Jan-03 Aug) minus (01 Jan-02 Aug) for Year to Date. Each
 *      column is measured against its OWN earlier reading, so the two are
 *      independent and both are correct.
 *
 *      They used to emit delta_mode 'mirror' — column B taking column A's chip
 *      verbatim — on the premise that two cumulative sums ending today grow by
 *      the same increment. That premise fails twice, which is why it is gone:
 *      on the 1st of a month column A's period key is brand new and has no
 *      chip, and mirror copied that emptiness over a Year to Date chip that was
 *      perfectly computable; and a record backdated into an earlier month moves
 *      Year to Date without moving Month to Date at all.
 *
 *      Measuring beats remembering. The default rule would have subtracted
 *      yesterday's STORED snapshot, which is the previous GENERATION DAY rather
 *      than yesterday — so a missed 06:00 cron silently produced a two-day chip
 *      labelled as one day's movement. Recomputing needs no history, so a first
 *      run, a missed day and a restored database all print the right number.
 *
 *      On 01 Aug, Month to Date covers 01-01 Aug and correctly carries NO chip;
 *      Year to Date covers 01 Jan-01 Aug, its earlier reading (01 Jan-31 Jul)
 *      exists, and it still carries one. Both fall silent only on 01 January.
 *
 *      Lines (d) and (e) emit delta_mode 'none' and carry no change figure at
 *      all — see the note at the emit for why neither a mirrored nor a
 *      day-over-day reading is meaningful on them.
 *
 *   c. Monthly target
 *      Hard-coded per calendar month (see ro1MonthlyTargetPct). Summed over every
 *      month the requested range touches, so MTD July = 0.050% and YTD Jan-Jul =
 *      0.350%. Replace the function body when the real per-month targets land.
 *
 *      Lines (a) and (b) are TINTED against it — green at or above target, red
 *      below. See section 6 for why one test colours both.
 *
 *   d. Response time within 72 hours
 *      potholeReport.php's "Within 72 hours" line, verbatim:
 *          numerator   = grantDay0 + grantDay1to3
 *          denominator = grantTotal
 *      Of every pothole DETECTED in the range, the share closed 0-2 days after
 *      detection. Still-open potholes sit in the denominator only.
 *
 *      Deliberately NOT report_ro1_pavement.php's fetchResponseWithin72Hours:
 *      that is a different, narrower pool (temporary_work = 'Patching (Cut)',
 *      plus the duplicate / AM-verified / DECLINE exclusions). potholeReport.php
 *      applies neither, so carrying them here made this line read several points
 *      away from the number the districts are actually measured on.
 *
 *   e. Outstanding potholes
 *      Potholes DETECTED in the range that are still open — potholeReport.php's
 *      grantPending, the "Pending Rectification" row. Range-driven like every
 *      other line here, so MTD and YTD differ.
 *
 *      NOT its "Total Nos Pothole Pending" row, which is grantPending PLUS a
 *      carry-forward of everything open back to 2022-07-01. Adding that here
 *      would make MTD and YTD print the same number for any two ranges sharing
 *      an end date, because the two halves would always cover the same span.
 *
 *      Consequence worth knowing: the YTD cell equals the report's Total Nos
 *      Pothole Pending only while the whole open backlog was detected this year.
 *      As at 2026-07-30 that holds (nothing open predates 2026-01-01), so YTD
 *      reads 454 against the report's 454. It is a property of the data, not an
 *      identity — the first defect to stay open across a year boundary breaks
 *      it, and the YTD cell will then read LOWER than the report. That is
 *      correct for a year-to-date figure; do not "fix" it by re-adding the
 *      carry-forward.
 *
 *      NOTE: this is an "as of now" figure, not "as of period end". jobdone has
 *      no history, so re-running an old range returns fewer open potholes than
 *      it did at the time. Same property as the existing pothole report.
 *
 *   Pool rules for (d) and (e), both taken from potholeReport.php:
 *
 *     Unit — pothole_daily.nos AS STORED, which is what the reference sums for
 *     everything detected in range: it reads the column and leaves it alone (its
 *     resync is commented out in the in-range loop).
 *
 *     Open — jobdone NULL or ''. '0000-00-00' is NOT open: the reference reads
 *     it as done-but-late (it lands in Exceed 72 hours), so it must be excluded
 *     here too, or the two reports differ by exactly those records.
 *
 *     Type — pothole_type.group_management = 'pothole'. Three types qualify:
 *     'pothole', 'Small Pothole 200mm below', 'Pothole Temporary'. This line no
 *     longer touches potholeReport.php's carried-forward half, so its raw-column
 *     bug (getDistrictData() compares pothole_type instead of the $qpotholeType
 *     it just computed) can no longer reach these figures. It still misstates
 *     that report's own Total Nos row and is worth fixing there.
 *
 *     Case — PHP compares these strings case-sensitively while MySQL's default
 *     collation does not, so every type comparison below is BINARY. Without it
 *     a 'Pothole' row would count here and not there.
 *
 * Two databases, mirroring the reference pages' connections:
 *   GLOB_DB   (district_info, pothole_daily, pothole_daily_item)
 *   GSHEET_DB (progress_summaryRO1)
 */

require_once __DIR__ . '/oe_api_common.php';

/* ---- Data rules carried over from the reference reports ------------------ */

// progress_summaryRO1 uses pre-2024 district names. Applies from 2024-01 onward.
$DISTRICT_RENAMES = array(
    'Pensiangan'  => 'Nabawan',
    'Labuk Sugut' => 'Beluran',
);

// "district|YYYY-MM" pairs excluded from work done (mirrors summary_pavement_workdone.php).
$WORKDONE_EXCLUSIONS = array('Kalabakan|2025-01');

// "Open" per potholeReport.php's empty($row['jobdone']) — NULL or '' only.
define('SQL_STILL_OPEN', " AND (pd.jobdone IS NULL OR pd.jobdone = '')");

// Closed 0-2 days after detection. DATEDIFF is NULL against '0000-00-00', so
// those rows drop out of the numerator here exactly as they do in the reference.
define('SQL_CLOSED_WITHIN_72H',
    " AND pd.jobdone IS NOT NULL
      AND pd.jobdone <> ''
      AND DATEDIFF(pd.jobdone, pd.detectDate) BETWEEN 0 AND 2");

// Tolerance when checking whether duplicate pavement_area rows agree. Areas are
// stored to 2 dp, so anything under half a cent of a square metre is float noise.
define('AREA_EPSILON', 0.005);

// The date a pothole_daily record counts under, for work done.
define('WORKDONE_DATE_EXPR',
    "CASE WHEN pd.submit_on_jan IS NULL OR pd.submit_on_jan = '' OR pd.submit_on_jan = '0000-00-00'
          THEN pd.jobdone ELSE pd.submit_on_jan END");


/* ========================= 1. Resolve the range =========================== */

$range = oe_request_range();
$start = $range['start'];
$end   = $range['end'];
$year  = $range['year'];


/* ========================= 2. Districts + pavement area =================== */

$connGlob = oe_connect(GLOB_DB);

// Roster: the districts still live at the end of the range.
$roster = array();
$stmt = mysqli_prepare($connGlob,
    "SELECT district_name FROM district_info WHERE expire_date >= ? ORDER BY district_code ASC");
if ($stmt === false) oe_fail('district_info prepare failed: ' . mysqli_error($connGlob));
mysqli_stmt_bind_param($stmt, 's', $end);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) {
    $name = trim((string)$row['district_name']);
    if ($name !== '') $roster[$name] = true;
}
mysqli_stmt_close($stmt);
if (empty($roster)) oe_fail('no active districts in district_info for ' . $end);

// Pavement area per district (the denominator of line b).
//
// progress_summaryRO1 holds MORE THAN ONE row per district per year. Reading it
// row-by-row and assigning would be last-write-wins, i.e. the denominator would
// depend on the order MySQL happens to return rows in — a silently unstable
// number underneath every percentage on line (b). So collapse in SQL instead and
// compare the extremes: if a district's rows agree, the value is unambiguous and
// duplication is cosmetic; if they disagree, there is no principled way to pick
// one, and guessing is worse than stopping.
$connGsheet = oe_connect(GSHEET_DB);
$areaYear   = ($year <= 2023) ? 2023 : $year;   // reference: everything <= 2023 reads the 2023 row set

$areaByDistrict = array();
$conflicts      = array();

$stmt = mysqli_prepare($connGsheet,
    "SELECT district, COUNT(*) AS n,
            MIN(pavement_area) AS min_area,
            MAX(pavement_area) AS max_area
     FROM progress_summaryRO1
     WHERE year = ?
     GROUP BY district");
if ($stmt === false) oe_fail('progress_summaryRO1 prepare failed (' . GSHEET_DB . '): ' . mysqli_error($connGsheet));
mysqli_stmt_bind_param($stmt, 'i', $areaYear);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) {
    $district = trim((string)$row['district']);
    if ($year >= 2024 && isset($DISTRICT_RENAMES[$district])) {
        $district = $DISTRICT_RENAMES[$district];
    }
    if (!isset($roster[$district])) continue;              // ignore rows for retired districts

    $min = floatval($row['min_area']);
    $max = floatval($row['max_area']);

    if (abs($max - $min) > AREA_EPSILON) {
        $conflicts[] = sprintf('%s (%d rows disagree: %.2f vs %.2f)', $district, (int)$row['n'], $min, $max);
        continue;
    }
    // A rename can fold two source districts onto one roster name. Same rule:
    // consistent values are fine, conflicting ones are not silently resolvable.
    if (isset($areaByDistrict[$district]) && abs($areaByDistrict[$district] - $max) > AREA_EPSILON) {
        $conflicts[] = sprintf('%s (rename collision: %.2f vs %.2f)', $district, $areaByDistrict[$district], $max);
        continue;
    }
    $areaByDistrict[$district] = $max;
}
mysqli_stmt_close($stmt);

if (!empty($conflicts)) {
    oe_fail('progress_summaryRO1 pavement_area is ambiguous for year ' . $areaYear
        . ' in ' . GSHEET_DB . ' — ' . implode('; ', $conflicts));
}

$totalPavementArea = array_sum($areaByDistrict);
// Fail loud: a missing denominator would silently render 0.000% and read as
// "we did no work", which is a materially wrong number to email out.
if ($totalPavementArea <= 0) {
    oe_fail('total pavement_area is 0 for year ' . $areaYear . ' — check progress_summaryRO1 in ' . GSHEET_DB);
}


/* ========================= 3. Line a — work done in m2 =================== */

// ONE pass over the range filling TWO buckets: the range as it stands now, and
// the same range as it stood yesterday. The gap between their totals is the
// change figure emitted for lines (a) and (b).
//
// Two buckets rather than two queries for two reasons. The Borang-A rule is not
// additive — see ro1BucketTotal() — so a second aggregate could not simply be
// subtracted from the first anyway; and re-running this join, the heaviest read
// in the endpoint, would double the cost of a number already in hand.
//
// The ROSTER and the PAVEMENT AREA above are deliberately NOT recomputed for
// the earlier reading. Both are resolved as at the report's own end date and
// shared by the two readings, so the chip measures work, not roster churn: a
// district expiring today would otherwise leave the denominator between the two
// readings and print its whole year's area as a change.
$prevEnd = oe_previous_end($start, $end);

$expr  = WORKDONE_DATE_EXPR;
$query =
    "SELECT pd.district AS district,
            DATE({$expr}) AS d,
            DATE_FORMAT({$expr}, '%Y-%m') AS ym,
            CASE WHEN pd.borangA IS NOT NULL AND pd.borangA <> '' THEN 1 ELSE 0 END AS has_ba,
            pdi.final_length AS l,
            pdi.final_width  AS w
     FROM pothole_daily pd
     INNER JOIN pothole_daily_item pdi ON pdi.daily_id = pd.id
     WHERE pd.status = '1'
       AND pdi.status = '1'
       AND {$expr} BETWEEN ? AND ?";

$stmt = mysqli_prepare($connGlob, $query);
if ($stmt === false) oe_fail('work done prepare failed: ' . mysqli_error($connGlob));
mysqli_stmt_bind_param($stmt, 'ss', $start, $end);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

// bucket["district|YYYY-MM"] = ['ba' => area, 'nba' => area]
$bucket     = array();
$bucketPrev = array();
while ($row = mysqli_fetch_assoc($res)) {
    $district = trim((string)$row['district']);
    if (!isset($roster[$district])) continue;

    $key = $district . '|' . $row['ym'];
    if (in_array($key, $WORKDONE_EXCLUSIONS, true)) continue;

    $slot = $row['has_ba'] ? 'ba' : 'nba';
    $area = roundingUp(floatval($row['l']) * floatval($row['w']), 2, 'JKR');

    if (!isset($bucket[$key])) $bucket[$key] = array('ba' => 0.0, 'nba' => 0.0);
    $bucket[$key][$slot] += $area;

    // Same row, same rules, one day short.
    //
    // The null check is insurance, not a live case: the WHERE clause above
    // already drops any row whose effective date is NULL or '0000-00-00',
    // because neither satisfies BETWEEN. It stays because PHP 8 compares null
    // against a date string by coercing it to '', which sorts BEFORE every real
    // date — so a null slipping through would join the earlier reading and not
    // the current one, and print as a permanent phantom change on every run.
    if ($prevEnd !== null && $row['d'] !== null && $row['d'] <= $prevEnd) {
        if (!isset($bucketPrev[$key])) $bucketPrev[$key] = array('ba' => 0.0, 'nba' => 0.0);
        $bucketPrev[$key][$slot] += $area;
    }
}
mysqli_stmt_close($stmt);

$workDoneM2  = ro1BucketTotal($bucket);
$workDonePct = ($workDoneM2 / $totalPavementArea) * 100.0;

// MEASURED, not remembered: yesterday's figure for this same period, recomputed
// from the same rows in this same run. null on the first day of a period —
// see oe_previous_end() for why that is correct rather than missing.
$deltaM2 = ($prevEnd === null) ? null : $workDoneM2 - ro1BucketTotal($bucketPrev);

// Line (b)'s movement is line (a)'s movement over the SHARED denominator, not
// the difference of two separately computed percentages. Identical arithmetic,
// one operation, and it cannot drift from the m2 chip by float noise.
$deltaPct = ($deltaM2 === null) ? null : ($deltaM2 / $totalPavementArea) * 100.0;


/* ========================= 4. Line c — monthly target ==================== */

$targetPct = 0.0;
foreach (oe_month_span($start, $end) as $m) {
    $targetPct += ro1MonthlyTargetPct($m['month']);
}


/* ============ 5. Lines d & e — potholeReport.php's pothole pool =========== */

$rosterSql = oe_in_sql($connGlob, 'pd.district', array_keys($roster));
$typeSql   = oe_in_sql($connGlob, 'BINARY TRIM(pd.pothole_type)', potholeManagedTypes($connGlob));

$detected = countPotholesDetected($connGlob, $start, $end, $rosterSql, $typeSql);
$within72 = countPotholesDetected($connGlob, $start, $end, $rosterSql, $typeSql, SQL_CLOSED_WITHIN_72H);
$openInRange = countPotholesDetected($connGlob, $start, $end, $rosterSql, $typeSql, SQL_STILL_OPEN);

$responsePct = ($detected > 0) ? ($within72 / $detected) * 100.0 : 0.0;
$outstanding = $openInRange;


/* ============ 6. Achievement colour — lines a and b ====================== */

// ONE test, both lines. Line (b) IS line (a) divided by the pavement area, and
// the target is stated as a percentage of that same area, so testing (a)
// separately means converting the target back to m2 — the identical inequality
// with the denominator cancelled. Two independently written tests could only
// ever differ by float noise, and then the report would print a green m2 beside
// a red percentage on the same routine.
//
// Compared at the PRINTED precision (3 dp), not raw. A month sitting on
// 0.0496% prints as 0.050% against a target printed as 0.050%; colouring that
// red is indistinguishable from a bug to anyone reading the sheet. The tint has
// to agree with the two figures it sits between.
$achievedColor = (round($workDonePct, 3) >= round($targetPct, 3)) ? 'green' : 'red';


/* ========================= 7. Emit ======================================= */

// EMIT FULL PRECISION — the pre-rounding that used to sit here is removed, for
// the reason set out at length in ro2_oe_summary.php's emit block. 'decimals' is
// a DISPLAY instruction and OeSummaryRenderer already applies it, so rounding
// here changed nothing on the page while corrupting every change chip.
//
// It was doing visible damage on line (b). On 31 Jul 2026 both columns moved by
// the same true 0.000741%, but stored at 3 dp the Month to Date pair rounded to
// 0.065 / 0.065 (chip suppressed as zero) while Year to Date rounded to 0.404 /
// 0.403 (chip +0.001%). One event, two different answers, decided purely by
// which side of a rounding boundary each value happened to fall on.
//
// That reasoning now matters MORE, not less. The chips below are computed in
// this file from full-precision figures, so re-introducing a round() at this
// emit would corrupt only the VALUE while the chip stayed exact, and the two
// would visibly disagree on the page.
oe_emit(array(
    oe_metric_delta(
        array('key' => 'work_done_m2',   'label' => 'a. Work done in m2',
              'value' => $workDoneM2,  'format' => 'number',  'decimals' => 2,
              'color' => $achievedColor),
        $deltaM2
    ),
    oe_metric_delta(
        array('key' => 'work_done_pct',  'label' => 'b. Work done in % against 0.6% yearly target',
              'value' => $workDonePct, 'format' => 'percent', 'decimals' => 3,
              'color' => $achievedColor),
        $deltaPct
    ),
    array('key' => 'monthly_target', 'label' => 'c. Monthly target',
          'value' => $targetPct,   'format' => 'percent', 'decimals' => 3,
          'delta_mode' => 'none'),
    // NO CHANGE FIGURE on (d) or (e), and 'mirror' would not have been right
    // either. (d) is a RATE and (e) a filtered COUNT, so the two columns measure
    // different populations and move by different amounts — mirroring would
    // print the month's movement beside the year's figure, which is false.
    //
    // Their own day-over-day readings are true but not informative. (d) is a
    // ratio whose denominator grows with the range, so the year-to-date figure
    // is anchored by seven months of history and barely moves whatever happens
    // today; the two columns' movements are not comparable to each other and
    // neither is comparable to the target above. (e) is an "as of now" backlog
    // with no history behind it (see the docblock on jobdone), so its daily
    // swing reflects when clerks closed records as much as when crews filled
    // potholes. Both lines are read as levels, not as movements.
    array('key' => 'response_72h',   'label' => 'd. Response time within 72 hours',
          'value' => $responsePct, 'format' => 'percent', 'decimals' => 2,
          'delta_mode' => 'none'),
    array('key' => 'outstanding_potholes', 'label' => 'e. Outstanding potholes',
          'value' => $outstanding, 'format' => 'integer', 'decimals' => 0,
          'delta_mode' => 'none'),
));


/* ============================== helpers ============================== */

/**
 * QUERY: total m2 in a work-done bucket, applying the Borang-A rule.
 *
 * Borang-A supersedes non-Borang-A PER DISTRICT PER MONTH: if a district filed
 * any Borang-A area in a month, only that area counts and its non-Borang-A area
 * for the same month is discarded entirely. That is what the reference report's
 * monthly TOTAL row does.
 *
 * The rule is NOT ADDITIVE, and that is why both readings fold through this one
 * function instead of one being derived from the other. A district whose FIRST
 * Borang-A row of the month lands today drops that month's accumulated
 * non-Borang-A area out of the total, so the change figure for that day can be
 * NEGATIVE even though work was done. The chip is honest about that: it reports
 * how the FIGURE moved, and the figure genuinely fell. A chip computed instead
 * as "today's rows only" would print a positive number beside a total that went
 * down, which is the one thing a reader cannot reconcile.
 */
function ro1BucketTotal(array $bucket)
{
    $total = 0.0;
    foreach ($bucket as $b) {
        $total += ($b['ba'] > 0) ? $b['ba'] : $b['nba'];
    }
    return $total;
}

/** Monthly target (%) — hard-coded, one place to edit when the real figures land. */
function ro1MonthlyTargetPct($month)
{
    $targets = array(
        1 => 0.050,  2 => 0.050,  3 => 0.050,  4 => 0.050,
        5 => 0.050,  6 => 0.050,  7 => 0.050,  8 => 0.050,
        9 => 0.050, 10 => 0.050, 11 => 0.050, 12 => 0.050,
    );
    return isset($targets[$month]) ? $targets[$month] : 0.0;
}

/**
 * QUERY: the pothole_type values potholeReport.php manages as potholes — the
 * same rows its $pTypeArr reads, filtered on the same column.
 */
function potholeManagedTypes($conn)
{
    $sql = "SELECT DISTINCT pothole_type FROM pothole_type WHERE BINARY group_management = 'pothole'";
    $res = mysqli_query($conn, $sql);
    if ($res === false) oe_fail('pothole_type query failed: ' . mysqli_error($conn));

    $types = array();
    while ($row = mysqli_fetch_assoc($res)) {
        $types[] = $row['pothole_type'];
    }
    mysqli_free_result($res);

    // A silent empty list would render 0 detected and 0.00% — wrong, and wrong
    // in the direction that reads as "nothing was found this month".
    if (empty($types)) {
        oe_fail("no pothole_type rows with group_management = 'pothole' in " . GLOB_DB);
    }
    return $types;
}

/**
 * QUERY: potholes DETECTED in [start, end], counted the way potholeReport.php
 * counts them — pothole_daily.nos as stored, live records, real detections,
 * roster districts, pothole-managed types. $extraSql narrows it further.
 *
 * Lines (d) and (e) count off this one base, so they can never disagree about
 * what "a pothole" is; only the narrowing clause differs.
 */
function countPotholesDetected($conn, $start, $end, $rosterSql, $typeSql, $extraSql = '')
{
    $sql =
        "SELECT COALESCE(SUM(pd.nos), 0) AS n
         FROM pothole_daily pd
         WHERE pd.detectDate BETWEEN ? AND ?
           AND pd.status = 1
           AND pd.timemark > 1"
        . $rosterSql . $typeSql . $extraSql;

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) oe_fail('pothole count prepare failed: ' . mysqli_error($conn));
    mysqli_stmt_bind_param($stmt, 'ss', $start, $end);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return ($row === null) ? 0 : intval($row['n']);
}

/** Verbatim from summary_pavement_workdone.php — JKR rounding for patched area. */
function roundingUp($value = 0, $precision = 2, $jkr = '')
{
    $amt = $value;
    $cAmt = $value;
    $IsNumber = false;
    if (is_int($value)) $IsNumber = true;
    if ($IsNumber) {
        if (is_string($value)) $IsNumber = false;
    }

    if ($jkr == 'JKR') {
        $tprecision = $precision + 2;
        $cAmt = (string) $value;          // explicit cast: same result, PHP 8 safe
        $nPos = strpos($cAmt, '.');
        if ($nPos != false) {
            $cAmt = substr($cAmt, 0, $nPos + $tprecision);
        }
        $IsNumber = false;
    }
    if ($IsNumber == false) {
        $amt = floatval($cAmt);
    }

    $precision = ($precision == 0 ? 1 : $precision);
    $pow = pow(10, $precision);
    $ceil = ceil($amt * $pow) / $pow;
    $floor = floor($amt * $pow) / $pow;
    $pow = pow(10, $precision + 1);
    $diffCeil  = $pow * ($ceil - $amt);
    $diffFloor = $pow * ($amt - $floor) + ($amt < 0 ? -1 : 1);
    if ($diffCeil >= $diffFloor) return $floor;
    else return $ceil;
}