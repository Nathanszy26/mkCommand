<?php
/**
 * API/emergency_progress_oe_summary.php
 *
 * "Emergency Progress" data source for the Summary O&E Performance report.
 * Wire its oe_summary_items row to this file with
 *   column_mode = 'day_today_mtd'   (Today | Month to Date)
 *
 * Nine figures in three groups — one group per question, the same three JKR
 * categories under each. The group captions are sent as heading lines
 * ('heading' => true): a label, no value, no cells.
 *
 *   New ET detected    a. TMP  b. Landslide  c. Fallen Tree
 *     Cases DETECTED inside the range, whatever their status is now. No status
 *     filter — a case raised today counts as detected today even if it closes
 *     tomorrow, and one raised today and closed today counts here AND in ET
 *     Closed. The three groups answer three questions; they are not a partition.
 *
 *   ET Closed          a. TMP (Within 1 hour)
 *                      b. Landslide (Within 24 hours)
 *                      c. Fallen Tree (Within 2 hours)
 *     Cases CLOSED inside [period_start, period_end] that met their category's
 *     requirement. Same rule the single line carried before, split three ways;
 *     the parenthetical names the clock each count was judged against.
 *
 *   Outstanding        a. TMP  b. Landslide  c. Fallen Tree
 *     Of the cases detected inside the range, the ones STILL OPEN at
 *     period_end. Scoped to the range, not the whole backlog: both columns of
 *     day_today_mtd end today, so a whole-backlog snapshot would print the
 *     identical number in Today and Month to Date on all three lines and say
 *     nothing. Range-scoped, the group reads against the one above it — of what
 *     was detected, this many are not closed yet.
 *     To switch to the whole backlog instead, delete the lower bound
 *     (`complaint_datetime_created >= ?` / `sea.detect_date >= ?`) from the two
 *     outstanding queries and drop $start from their bind lists. Nothing else
 *     changes.
 *
 * Range-driven like every other endpoint here: it answers for whatever
 * [period_start, period_end] it is handed, so the same code serves the daily
 * column, the month-to-date column and any wider column_mode later.
 *
 * TWO SOURCES, ADDED — exactly as emergency_heatmap.php adds them:
 *   complaints              (AMC / sabahroads, remote host)  — hour thresholds
 *   supervisor_daily_report (GLOBMY_DB)                      — one-day threshold
 * A case can be raised through either channel; the heatmap's district totals
 * are the sum of both, and so is every line here.
 *
 * THE REQUIREMENT, per category (emergency_heatmap.php getCategoryConfig()):
 *   TMP          defect types 16,17,18,19          within  1 hour
 *   Landslide    defect types 14,15                within 24 hours
 *   Fallen tree  defect type  22                   within  2 hours
 * Supervisor rows carry no per-category clock in the reference — all three
 * natures are judged on DATEDIFF(done_date, detect_date) > 1 — so the 'hours'
 * value is used for complaints only. The 'nature' lists are what scope which
 * supervisor rows are in the programme at all, and what sort them into the
 * three categories here.
 *
 * ONLY those three categories count, on every line. "Within JKR requirements"
 * is only answerable where a requirement is defined, and the reference defines
 * one for these three and nothing else. Detected and Outstanding use the same
 * scope so the three groups are about the same population.
 *
 * THREE LINES, THREE ANCHORS — deliberately. Each group is scoped by the
 * timestamp of the event it names, because scoping it by anything else answers
 * a different question:
 *   New ET detected  complaint_datetime_created / sea.detect_date (the raising)
 *   ET Closed        complaint_datetime_action / sea.done_date   (the closing)
 *   Outstanding      detected in range, minus closed by period_end
 *
 * The closing anchor is the one divergence from emergency_heatmap.php, which
 * scopes its MONTH by complaint_datetime_created / sdr.report_date. At a
 * month's length that is a boundary rounding error. On a SINGLE DAY it is
 * broken: a landslide raised at 09:00 has 24 hours to be closed, so scoped by
 * its raising it is still open when the day it belongs to is counted, gets
 * dropped by complaint_status = '4' / sea.status = 'close', and the figure
 * prints low then climbs for two more days. Anchored on the closing timestamp
 * a closed-count is final at midnight and never moves again — which is what a
 * stored daily snapshot has to be. Nothing about "late" is redefined here;
 * only which day a closed case lands in.
 *
 * COLUMN A IS A DAY IN PROGRESS. Under day_today_mtd, Today's counts climb
 * until midnight and the report shows whatever they read when refresh() ran.
 * The 06:00 cron mail therefore shows 06:00's figures; the web and ?test=1
 * paths refresh on load and show the moment you opened them. That is inherent
 * to a live column, not a defect in these queries.
 *
 * THE REFERENCE'S SUMMARY-VIEW LATE TEST IS NOT REPRODUCED — IT IS WRONG.
 * emergency_heatmap.php line 94 (the top-of-file $complaintQuery) reads:
 *     OR (complaint_defect_type NOT IN (16,17,18,19) AND TIMESTAMPDIFF(...) > 1)
 * ORed against the 24-hour and 2-hour clauses. A landslide closed in 5 hours is
 * inside its 24-hour requirement, but 5 > 1 and 14 is not in (16,17,18,19), so
 * that third clause fires and the case is counted LATE anyway. The 24-hour and
 * 2-hour thresholds above it can never save a row; they are dead. Its DASHBOARD
 * query (line 221) has the same three thresholds written as a proper partition —
 * every eligible type is matched by exactly one clause — and that is the one
 * carried over here. If the summary view is ever corrected, its numbers will
 * move toward this endpoint's, not away from them.
 *
 * A SUPERVISOR ROW WITH NO detect_date IS NOT COUNTED, on any line. The
 * reference counts on-time as total-minus-late and its late test needs both
 * dates, so a row missing detect_date lands in its compliant column by default.
 * A case whose detection time is unknown cannot be shown to have met a
 * requirement measured from detection, and ET Closed is a count OF demonstrated
 * compliance, so it is excluded rather than assumed good. Detected and
 * Outstanding are anchored ON detect_date, so such rows fall out of those two
 * by construction. If the figures ever disagree with the heatmap by a handful
 * of cases, this is the first thing to check:
 *   SELECT COUNT(*) FROM supervisor_emergency_attended
 *    WHERE deleted = '0' AND status = 'close' AND detect_date IS NULL;
 *
 * A SUPERVISOR ROW WITH submit_to_ebencana = '1' IS NOT COUNTED, on any line.
 * The case was handed to eBencana and is answered for there; counting it here
 * as well would show the same emergency twice across two systems. This is a
 * filter the reference does not apply — emergency_heatmap.php's totals include
 * these rows, so its district counts will read slightly higher than this
 * endpoint's wherever a district has handed cases off.
 *
 * Two reference filters are dropped as provably redundant, not as a change of
 * rule: complaint_defect_type != '24' (24 is not in the eligible type list) and
 * sea.nature_of_emergency != 'Other Defects That Endangers Road Users' (that
 * string is in none of the three nature lists).
 *
 * Three databases:
 *   GLOB_DB   (district_info — the roster both sources are scoped to)
 *   GLOBMY_DB (supervisor_daily_report, supervisor_emergency_attended)
 *   AMC       (complaints — a DIFFERENT SERVER, see oe_connect_amc())
 */

require_once __DIR__ . '/oe_api_common.php';


/* ---- The requirement, carried over from emergency_heatmap.php ------------- */

$EMERGENCY_CATEGORIES = array(
    'tmp' => array(
        'label'        => 'TMP',
        'hours'        => 1,
        'defect_types' => array(16, 17, 18, 19),
        'nature'       => array(
            'Failure/Collapse Of Bridges And Their Related Components',
            'Collapse Of Culvers & Drainage Structures',
            'Flooding',
            'Closure Of Roads Due To Spillage Of Chemical, Hazardous, Toxic Or Inflammable Materials',
        ),
    ),
    'landslide' => array(
        'label'        => 'Landslide',
        'hours'        => 24,
        'defect_types' => array(14, 15),
        'nature'       => array(
            'Landslide',
            'Embankment Failures & Road Subsidence',
        ),
    ),
    'fallen_tree' => array(
        'label'        => 'Fallen Tree',
        'hours'        => 2,
        'defect_types' => array(22),
        'nature'       => array(
            'Closure Of Roads Due To Fallen Trees',
        ),
    ),
);

// The complaint columns that say a case is an emergency and that it is closed.
define('EMERGENCY_COMPLAINT_CATEGORY', '3');
define('EMERGENCY_COMPLAINT_CLOSED',   '4');

// The supervisor join, written once — three queries below read from it.
define('EMERGENCY_SUPERVISOR_FROM',
    "FROM supervisor_daily_report sdr
     INNER JOIN supervisor_emergency_attended sea ON sea.supervisor_report_id = sdr.id");


/* ========================= 1. Resolve the range =========================== */

$range = oe_request_range();
$start = $range['start'];
$end   = $range['end'];

// oe_in_sql() returns an EMPTY fragment for an empty list, which drops the
// filter instead of matching nothing — it would widen these from "the three
// categories with a JKR requirement" to "every complaint in the table". Both
// lists are hard-coded above, so an empty one is an edit mistake, and it has to
// stop here rather than print a plausible, far too large number.
if (empty(emergencyDefectTypes($EMERGENCY_CATEGORIES))) {
    oe_fail('EMERGENCY_CATEGORIES declares no defect types — refusing to count every complaint');
}
if (empty(emergencyNatures($EMERGENCY_CATEGORIES))) {
    oe_fail('EMERGENCY_CATEGORIES declares no natures — refusing to count every supervisor emergency');
}


/* ========================= 2. District roster ============================= */

// One read, two keys: complaints file a case under district_info.district_code,
// supervisor reports under district_info.district_name. Same roster either way —
// districts still live at period_end, which is how the reference scopes "all
// districts" (it drops any row whose district is not in that list).
$roster = oe_district_roster(oe_connect(GLOB_DB), $end);

$districtCodes = array();
$districtNames = array();
foreach ($roster as $d) {
    if ($d['district_code'] !== '') $districtCodes[] = $d['district_code'];
    if ($d['district_name'] !== '') $districtNames[] = $d['district_name'];
}
if (empty($districtCodes)) oe_fail('no active district_code in district_info for ' . $end);
if (empty($districtNames)) oe_fail('no active district_name in district_info for ' . $end);


/* ========================= 3. Count both channels ========================= */

$amc    = oe_connect_amc();
$globmy = oe_connect(GLOBMY_DB);

$detected = emergencyAddTallies(
    emergencyComplaintsDetected($amc, $start, $end, $districtCodes, $EMERGENCY_CATEGORIES),
    emergencySupervisorDetected($globmy, $start, $end, $districtNames, $EMERGENCY_CATEGORIES)
);

$closed = emergencyAddTallies(
    emergencyComplaintsClosedOnTime($amc, $start, $end, $districtCodes, $EMERGENCY_CATEGORIES),
    emergencySupervisorClosedOnTime($globmy, $start, $end, $districtNames, $EMERGENCY_CATEGORIES)
);

$outstanding = emergencyAddTallies(
    emergencyComplaintsOutstanding($amc, $start, $end, $districtCodes, $EMERGENCY_CATEGORIES),
    emergencySupervisorOutstanding($globmy, $start, $end, $districtNames, $EMERGENCY_CATEGORIES)
);


/* ========================= 4. Emit ======================================== */

// Detected, then Closed, then Outstanding: what came in, what went out, what is
// left. Column A defines row order in OeSummaryService::mergeRows(), so this
// array IS the printed order — there is no second place to change it.
oe_emit(array_merge(
    emergencyGroupLines('detected', 'New ET detected', $detected, $EMERGENCY_CATEGORIES, false),
    emergencyGroupLines('closed', 'ET Closed', $closed, $EMERGENCY_CATEGORIES, true),
    emergencyGroupLines('outstanding', 'Outstanding', $outstanding, $EMERGENCY_CATEGORIES, false)
));


/* ============================== complaints ============================== */

/**
 * QUERY: complaints closed inside the range that met their category's clock,
 * per category.
 *
 * NOT (overdue) rather than a list of "within" clauses: the overdue expression
 * is the ONE statement of the thresholds, so on-time is its exact complement and
 * the two can never be edited apart. Every eligible type is matched by exactly
 * one clause of it, so the complement is total.
 *
 * complaint_datetime_action >= ? already excludes a NULL action timestamp, so
 * the reference's explicit IS NOT NULL test is carried by the range bound. A row
 * with a NULL complaint_datetime_created makes TIMESTAMPDIFF NULL, so NOT(...)
 * is NULL and the row drops out — correct, since compliance measured from a
 * detection time that isn't recorded cannot be demonstrated.
 */
function emergencyComplaintsClosedOnTime($conn, $start, $end, array $districtCodes, array $categories)
{
    $sql = "SELECT " . emergencyComplaintCategorySql($categories) . " AS category, COUNT(*) AS c
            FROM complaints
            WHERE complaint_datetime_action >= ?
              AND complaint_datetime_action < DATE_ADD(?, INTERVAL 1 DAY)
              AND complaint_status = '" . EMERGENCY_COMPLAINT_CLOSED . "'
              AND NOT " . emergencyOverdueSql($categories)
        . emergencyComplaintScope($conn, $categories, $districtCodes)
        . " GROUP BY category";

    return emergencyTally($conn, $sql, array($start, $end), $categories, 'complaints closed');
}

/** QUERY: complaints RAISED inside the range, per category, any status. */
function emergencyComplaintsDetected($conn, $start, $end, array $districtCodes, array $categories)
{
    $sql = "SELECT " . emergencyComplaintCategorySql($categories) . " AS category, COUNT(*) AS c
            FROM complaints
            WHERE complaint_datetime_created >= ?
              AND complaint_datetime_created < DATE_ADD(?, INTERVAL 1 DAY)"
        . emergencyComplaintScope($conn, $categories, $districtCodes)
        . " GROUP BY category";

    return emergencyTally($conn, $sql, array($start, $end), $categories, 'complaints detected');
}

/**
 * QUERY: complaints raised inside the range and still open AT period_end.
 *
 * "Open at period_end", not "open now": a case closed after the period ended was
 * outstanding when the period closed, and re-running an old range has to give
 * the same answer it gave then. For a range ending today the two readings are
 * the same thing.
 *
 * A row with status = closed and a NULL action timestamp is treated as CLOSED
 * (NULL >= x is NULL, so the OR cannot rescue it). The alternative leaves it
 * outstanding forever on the strength of a missing timestamp.
 */
function emergencyComplaintsOutstanding($conn, $start, $end, array $districtCodes, array $categories)
{
    $sql = "SELECT " . emergencyComplaintCategorySql($categories) . " AS category, COUNT(*) AS c
            FROM complaints
            WHERE complaint_datetime_created >= ?
              AND complaint_datetime_created < DATE_ADD(?, INTERVAL 1 DAY)
              AND (complaint_status <> '" . EMERGENCY_COMPLAINT_CLOSED . "'
                   OR complaint_datetime_action >= DATE_ADD(?, INTERVAL 1 DAY))"
        . emergencyComplaintScope($conn, $categories, $districtCodes)
        . " GROUP BY category";

    return emergencyTally($conn, $sql, array($start, $end, $end), $categories, 'complaints outstanding');
}

/** QUERY: what makes a complaint row this report's business at all — emergency
 *  category, a defect type that carries a requirement, an active district. */
function emergencyComplaintScope($conn, array $categories, array $districtCodes)
{
    if (empty($districtCodes)) oe_fail('empty district roster passed to emergencyComplaintScope');

    return " AND complaint_defect_category = '" . EMERGENCY_COMPLAINT_CATEGORY . "'"
        . " AND complaint_defect_type IN (" . emergencyIntList(emergencyDefectTypes($categories)) . ")"
        . oe_in_sql($conn, 'complaint_district', $districtCodes);
}


/* ============================ supervisor rows ============================ */

/**
 * QUERY: supervisor-reported emergencies closed inside the range within a day,
 * per category.
 *
 * "< next day" on done_date rather than "<= end": correct whether the column is
 * stored as a DATE or a DATETIME, and still able to use an index on it.
 * DATEDIFF <= 1 is the exact complement of the reference's > 1 late test.
 */
function emergencySupervisorClosedOnTime($conn, $start, $end, array $districtNames, array $categories)
{
    $sql = "SELECT " . emergencySupervisorCategorySql($conn, $categories) . " AS category, COUNT(*) AS c
            " . EMERGENCY_SUPERVISOR_FROM . "
            WHERE sea.done_date >= ?
              AND sea.done_date < DATE_ADD(?, INTERVAL 1 DAY)
              AND sea.status = 'close'
              AND sea.detect_date IS NOT NULL
              AND DATEDIFF(sea.done_date, sea.detect_date) <= 1"
        . emergencySupervisorScope($conn, $categories, $districtNames)
        . " GROUP BY category";

    return emergencyTally($conn, $sql, array($start, $end), $categories, 'supervisor closed');
}

/** QUERY: supervisor emergencies DETECTED inside the range, per category, any
 *  status. Anchored on detect_date, so a row without one is out by
 *  construction. */
function emergencySupervisorDetected($conn, $start, $end, array $districtNames, array $categories)
{
    $sql = "SELECT " . emergencySupervisorCategorySql($conn, $categories) . " AS category, COUNT(*) AS c
            " . EMERGENCY_SUPERVISOR_FROM . "
            WHERE sea.detect_date >= ?
              AND sea.detect_date < DATE_ADD(?, INTERVAL 1 DAY)"
        . emergencySupervisorScope($conn, $categories, $districtNames)
        . " GROUP BY category";

    return emergencyTally($conn, $sql, array($start, $end), $categories, 'supervisor detected');
}

/** QUERY: supervisor emergencies detected inside the range and still open at
 *  period_end. A row marked close with no done_date counts as closed — same
 *  rule as the complaint side, for the same reason. */
function emergencySupervisorOutstanding($conn, $start, $end, array $districtNames, array $categories)
{
    $sql = "SELECT " . emergencySupervisorCategorySql($conn, $categories) . " AS category, COUNT(*) AS c
            " . EMERGENCY_SUPERVISOR_FROM . "
            WHERE sea.detect_date >= ?
              AND sea.detect_date < DATE_ADD(?, INTERVAL 1 DAY)
              AND (sea.status <> 'close'
                   OR sea.done_date >= DATE_ADD(?, INTERVAL 1 DAY))"
        . emergencySupervisorScope($conn, $categories, $districtNames)
        . " GROUP BY category";

    return emergencyTally($conn, $sql, array($start, $end, $end), $categories, 'supervisor outstanding');
}

/** QUERY: what makes a supervisor row this report's business at all — not
 *  deleted on either side, not handed off to eBencana, a nature that carries a
 *  requirement, an active district.
 *
 *  submit_to_ebencana = '1' means the case was passed to eBencana and is no
 *  longer this programme's to answer for. It is excluded from ALL THREE groups,
 *  not just from Outstanding: counting it as detected or closed here while its
 *  outcome is recorded elsewhere double-counts the case across two systems.
 *
 *  The NULL test is not decoration. `submit_to_ebencana <> '1'` alone evaluates
 *  to NULL for a row that never had the flag set, and NULL is not true, so every
 *  such row would drop out of the count silently — the opposite of the intent on
 *  what is almost certainly the majority of the table. */
function emergencySupervisorScope($conn, array $categories, array $districtNames)
{
    if (empty($districtNames)) oe_fail('empty district roster passed to emergencySupervisorScope');

    return " AND sdr.deleted = '0' AND sea.deleted = '0'"
        . " AND (sea.submit_to_ebencana IS NULL OR sea.submit_to_ebencana <> '1')"
        . oe_in_sql($conn, 'sea.nature_of_emergency', emergencyNatures($categories))
        . oe_in_sql($conn, 'sdr.district_Name', $districtNames);
}


/* ============================== plumbing ============================== */

/**
 * QUERY: run a category-grouped COUNT and return a tally keyed by category,
 * zero-filled.
 *
 * Zero-filled because a category with no cases returns NO ROW, and a missing
 * line is not the same as a line reading 0 — the report must print all three
 * every day. Every query above prepares, binds date strings, reads and closes
 * through here, so only the SQL differs and no channel can grow its own error
 * handling.
 */
function emergencyTally($conn, $sql, array $params, array $categories, $what)
{
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) oe_fail($what . ' prepare failed: ' . mysqli_error($conn));
    mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $out = emergencyZeroTally($categories);
    while ($row = mysqli_fetch_assoc($res)) {
        $cat = $row['category'];
        // NULL category = a row no clause matched. The scope fragments make that
        // impossible; if it ever happens the row is not counted anywhere rather
        // than silently swelling one of the three.
        if ($cat !== null && isset($out[$cat])) $out[$cat] += (int)$row['c'];
    }
    mysqli_stmt_close($stmt);

    return $out;
}

/**
 * QUERY: the SQL CASE that sorts a complaint row into one of the three
 * categories, built from the same array the thresholds come from.
 * Category keys are our own literals, so they need no escaping.
 */
function emergencyComplaintCategorySql(array $categories)
{
    $sql = 'CASE';
    foreach ($categories as $key => $cat) {
        $sql .= " WHEN complaint_defect_type IN (" . emergencyIntList($cat['defect_types']) . ")"
            . " THEN '" . $key . "'";
    }
    return $sql . ' END';
}

/** QUERY: the same sort for a supervisor row, on nature_of_emergency. */
function emergencySupervisorCategorySql($conn, array $categories)
{
    $sql = 'CASE';
    foreach ($categories as $key => $cat) {
        $sql .= " WHEN sea.nature_of_emergency IN (" . oe_escaped_list($conn, $cat['nature']) . ")"
            . " THEN '" . $key . "'";
    }
    return $sql . ' END';
}

/**
 * QUERY: the SQL predicate for "this complaint missed its requirement".
 *
 * The single source of truth for the thresholds. One clause per category, and
 * the categories partition the eligible type list, so exactly one clause can
 * apply to any given row — which is what makes NOT(this) a safe test for
 * on-time. See the docblock on why the reference's summary-view version, whose
 * clauses overlap, must not be used.
 */
function emergencyOverdueSql(array $categories)
{
    $clauses = array();
    foreach ($categories as $cat) {
        $clauses[] = '(complaint_defect_type IN (' . emergencyIntList($cat['defect_types']) . ')'
            . ' AND TIMESTAMPDIFF(HOUR, complaint_datetime_created, complaint_datetime_action) > '
            . (int)$cat['hours'] . ')';
    }
    return '(' . implode(' OR ', $clauses) . ')';
}

/**
 * QUERY: one group's metric lines — the heading, then a./b./c. in the order the
 * categories are declared.
 *
 * @param bool $withThreshold append "(Within N hours)" to each category label.
 *        True for ET Closed, where the count IS "met this clock", and false for
 *        the other two, where the clock is not what is being counted and would
 *        read as a filter that isn't there.
 */
function emergencyGroupLines($groupKey, $groupLabel, array $tally, array $categories, $withThreshold)
{
    $lines = array(array(
        'key'     => $groupKey,
        'label'   => $groupLabel,
        'heading' => true,
    ));

    $i = 0;
    foreach ($categories as $catKey => $cat) {
        $label = chr(97 + $i++) . '. ' . $cat['label'];
        if ($withThreshold) $label .= ' (' . emergencyThresholdText($cat['hours']) . ')';

        $lines[] = array(
            'key'      => $groupKey . '_' . $catKey,
            'label'    => $label,
            'value'    => $tally[$catKey],
            'format'   => 'integer',
            'decimals' => 0,
        );
    }
    return $lines;
}

/** QUERY: "Within 1 hour" / "Within 24 hours". */
function emergencyThresholdText($hours)
{
    $h = (int)$hours;
    return 'Within ' . $h . ($h === 1 ? ' hour' : ' hours');
}

/** QUERY: the two channels' tallies summed category by category. */
function emergencyAddTallies(array $a, array $b)
{
    foreach ($b as $key => $count) {
        $a[$key] = (isset($a[$key]) ? $a[$key] : 0) + $count;
    }
    return $a;
}

/** QUERY: every category at zero — the shape every tally starts from. */
function emergencyZeroTally(array $categories)
{
    $out = array();
    foreach ($categories as $key => $cat) $out[$key] = 0;
    return $out;
}

/** QUERY: every defect type that carries a requirement, flattened. */
function emergencyDefectTypes(array $categories)
{
    $out = array();
    foreach ($categories as $cat) {
        foreach ($cat['defect_types'] as $type) $out[] = (int)$type;
    }
    return $out;
}

/** QUERY: every nature of emergency that carries a requirement, flattened. */
function emergencyNatures(array $categories)
{
    $out = array();
    foreach ($categories as $cat) {
        foreach ($cat['nature'] as $nature) $out[] = $nature;
    }
    return $out;
}

/** QUERY: "16,17,18,19" — integer-cast, so it needs no escaping and no quotes
 *  around values compared against a numeric column. */
function emergencyIntList(array $values)
{
    return implode(',', array_map('intval', $values));
}