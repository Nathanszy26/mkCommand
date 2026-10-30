<?php
/**
 * API/ro1_work_achievement.php
 *
 * RO1 WORK ACHIEVEMENT — one manager's own districts, for the daily summary on
 * globportal.
 *
 * MUST live at /var/www/html/glob/it/mkPortal/report/API/ on glob.com.my,
 * beside the roN_oe_summary.php endpoints, because it requires
 * oe_api_common.php for the credentials and the shared helpers. URL:
 *
 *     https://glob.com.my/it/mkPortal/report/API/ro1_work_achievement.php
 *
 * ---------------------------------------------------------------------------
 * WHAT IT IS, AND HOW IT DIFFERS FROM ro1_oe_summary.php
 *
 * ro1_oe_summary.php answers for the WHOLE CONTRACT: five lines, every district
 * added together, one column of the weekly management report. This answers the
 * same arithmetic for ONE PERSON'S DISTRICTS, split out district by district
 * with a total under them, because the manager reading it on their phone at
 * 7am is accountable for two districts and not for twenty-eight.
 *
 * It is NOT an oe_summary_items data source. The roN_* endpoints implement
 * OeHttpMetricFetcher's contract — a flat metrics[] list, two columns, no
 * identity — and a table of districts does not fit in it. So this file has its
 * own envelope (see EMIT below) and is called by mkPortal's daily summary,
 * which stores its address in mkPortal.staff_summary_module.api_url.
 *
 * THREE OF ro1_oe_summary.php's FIVE LINES, and they are the same three
 * computations, deliberately:
 *
 *   Work done in m2               its line (a)
 *   Response time within 72 hrs   its line (d)
 *   Outstanding potholes          its line (e)
 *
 * Its lines (b) and (c) — work done as a % of pavement area, and the monthly
 * target — are NOT here. Both are read against the WHOLE contract's pavement
 * area out of progress_summaryRO1 in gsheet_siti, and a per-district share of a
 * contract-wide target is a different, and much more arguable, figure than the
 * one that report prints. When somebody decides what one district's target is,
 * it is a fourth column here and this is the file to add it to. Until then this
 * endpoint never opens GSHEET_DB at all.
 *
 * EVERY RULE BEHIND THE THREE FIGURES IS ro1_oe_summary.php's, down to the
 * BINARY comparisons and the treatment of '0000-00-00'. Read that file's
 * docblock for WHY each one is what it is — it is long and it is all load
 * bearing, and none of it is repeated here. What this file adds is who is
 * asking, which districts are theirs, and the split.
 *
 * ---------------------------------------------------------------------------
 * WHOSE DISTRICTS — THE ONE JOIN THIS FILE OWNS
 *
 *     district_info.managerMail = <this person's email>
 *     AND district_info.expire_date = '2099-12-31'
 *
 * ONE MANAGER, MANY ROWS. district_info is one row per district, so a manager
 * of four districts is four rows and every one of them has to be collected.
 * Carl Semion holds Keningau, Tambunan, Nabawan and Tenom on one address;
 * reading "the" district for an email would silently report a quarter of his
 * work. Nineteen addresses cover twenty-eight districts today.
 *
 * THE expire_date TEST IS EQUALITY, NOT >=, and that is this file's own rule
 * rather than the roster rule the other endpoints use. '2099-12-31' is the
 * sentinel for "live, no end date"; oe_district_roster() asks for
 * expire_date >= $end, which also admits a district with a real future end date
 * on it. For a reporting line — who is answerable for what, today — only the
 * open-ended rows are the answer, and that is what was asked for.
 *
 * MATCHED CASE-INSENSITIVELY AND TRIMMED. The column is free text typed by
 * people; 'AMKK.glob@cityskill.my' and ' amkk.glob@cityskill.my' are the same
 * mailbox and must not be two different answers. district_info is twenty-eight
 * rows, so TRIM(LOWER(...)) losing an index costs nothing.
 *
 * ONLY managerMail. assistantMail, dmMail, regionalMail and DE_email are the
 * other addresses on that table, and each of them would widen the answer to a
 * different accountability. Adding one is a single OR in ro1waDistrictsFor() —
 * and a decision about whose figures these are, which is not this file's to
 * make.
 *
 * NOBODY'S DISTRICTS IS A SUCCESSFUL ANSWER. Fourteen of the thirty-three
 * people on the daily summary list are not area managers and match no district
 * at all. That is not an error, it is the normal case for most of the list, so
 * it comes back ok:true with rows:[] and an empty_reason the summary can print.
 * Failing here would make every non-manager's summary look broken.
 *
 * ---------------------------------------------------------------------------
 * IN (GET or POST JSON). Identity, then the day.
 *
 *   email=     THE ADDRESS, and the fast path. The caller on globportal
 *              already holds it — staffPoolAPI.php publishes `email` on every
 *              staff row — so passing it here costs no network hop.
 *
 *   person=    A LOGIN INSTEAD, resolved by calling staffPoolAPI.php. One
 *   comp_id=   extra HTTP round trip, which is why it is not the path the cron
 *              uses; it exists so this endpoint can be opened in a browser and
 *              checked against a name somebody recognises:
 *
 *                ?person=victorchai&comp_id=1&html=1
 *
 *              comp_id defaults to 1 (Globinaco — the only company with RO1
 *              districts).
 *
 *   date=      the as-at day. Default today. The period is the MONTH THAT DAY
 *              FALLS IN, from the 1st to that day — "for that month", read on
 *              the 30th, means the 1st to the 30th.
 *
 *   html=1     render the table as a web page instead of JSON, for checking it
 *              in a browser. The figures are produced by exactly the same code;
 *              only the output differs.
 *
 * EMIT (JSON, ok:true)
 *
 *   { ok, as_at, period{start,end,label}, compare{end,label},
 *     person{email,person,fullname,comp_id,resolved_by},
 *     columns[{key,label,format,decimals}],
 *     rows[{district,district_code, <col>:{value,delta,...}}],
 *     total{...same shape...},
 *     districts[], empty_reason }
 *
 *   EVERY FIGURE IS AN OBJECT, not a bare number, and it carries its own
 *   delta. A client that wants the arrow has it without computing anything, and
 *   a client that does not can read .value and ignore the rest. delta is null
 *   where there was no earlier day inside the month to compare against — see
 *   THE DELTAS below — and null is NOT zero: zero means "measured, and it did
 *   not move", null means "nothing to measure it against".
 *
 *   FULL PRECISION GOES ON THE WIRE. `decimals` is a DISPLAY instruction and
 *   the client applies it. Rounding here would corrupt the deltas while
 *   leaving the values looking right — ro1_oe_summary.php's emit block has the
 *   worked example of exactly that bug.
 *
 * Failures use oe_fail()'s envelope, { ok:false, error }, like every other
 * endpoint in this folder.
 *
 * ---------------------------------------------------------------------------
 * THE DELTAS — "increased/decreased vs yesterday", on all three columns
 *
 * MEASURED, NOT REMEMBERED. The endpoint reads the SAME month twice in one
 * run — [1st, today] and [1st, yesterday] — and emits the difference. No
 * history is stored, so a first run, a missed cron and a restored database all
 * print the right number. ro1_oe_summary.php's docblock argues this at length
 * and the argument is unchanged.
 *
 * NULL ON THE 1st OF THE MONTH. There is no earlier reading of THIS month to
 * subtract, and reaching back into last month would compare one month against
 * a different one. oe_previous_end() is the rule.
 *
 * WHAT EACH DELTA ACTUALLY MEANS, because two of the three are subtler than
 * they look and a reader is entitled to know:
 *
 *   Work done in m2 — how the FIGURE moved, which is not always "work done
 *   today". The Borang-A rule is not additive: a district whose first Borang-A
 *   row of the month lands today drops that month's accumulated
 *   non-Borang-A area out of the total, so the delta can be NEGATIVE on a day
 *   crews paved. That is honest — the figure genuinely fell — and a delta
 *   computed as "today's rows only" would print a rise beside a total that
 *   went down, which is the one thing nobody can reconcile.
 *
 *   Response time within 72 hrs — the movement of a RATE whose denominator
 *   grows all month, so by the 28th it barely moves whatever happens that day.
 *   Small is normal; it is not a broken reading.
 *
 *   Outstanding potholes — reads as "potholes detected TODAY that are still
 *   open". Both readings ask which potholes are open NOW (jobdone keeps no
 *   history, so "open as at yesterday" is not a question the data can answer —
 *   see ro1_oe_summary.php on line e). A pothole detected on the 20th and
 *   closed today therefore leaves BOTH readings and contributes nothing. So
 *   this delta does not fall when a backlog is cleared, and it is a count of
 *   new unresolved detections rather than a change in the backlog. Printing it
 *   as the movement of the backlog would be wrong; printing it as what it is,
 *   is useful.
 *
 * ---------------------------------------------------------------------------
 * THE TOTAL ROW
 *
 * m2 and the pothole count ADD UP. The Borang-A rule is applied per district
 * per month, so each district's bucket is independent and the total is the
 * plain sum of the rows above it — which is also exactly what
 * ro1_oe_summary.php's whole-contract figure does.
 *
 * THE RESPONSE PERCENTAGE DOES NOT, AND IS NOT AVERAGED. It is recomputed over
 * the POOLED population: every pothole in these districts closed within 72
 * hours, over every pothole detected in them. The mean of the district
 * percentages is a different number and the wrong one — it weighs a district
 * with 6 potholes the same as one with 1,235, so one quiet district having a
 * bad week drags the total away from the figure the districts are actually
 * measured on. For Kota Kinabalu + Putatan on 30 Oct 2026 the pooled answer is
 * 91.95% and the mean of the two is 93.52%; they are a point and a half apart
 * and only one of them is the share of potholes that were dealt with in time.
 * numerator and denominator ride on every response cell so the arithmetic can
 * be checked without re-running anything.
 *
 * ---------------------------------------------------------------------------
 * COST. The work-done read joins pothole_daily to pothole_daily_item on a date
 * expression over submit_on_jan/jobdone, which no index covers (pothole_daily
 * has one on detectDate and one on jobdone, none on district or submit_on_jan).
 * It scans, and takes about two seconds for a month. That is the same scan the
 * reference reports do, so it is a known cost rather than a surprise; a caller
 * looping over nineteen managers should expect tens of seconds, not hundreds of
 * milliseconds, and should call this once per person per morning rather than
 * per page view.
 *
 * One database: GLOB_DB (district_info, pothole_daily, pothole_daily_item,
 * pothole_type). PHP 7.2 compatible.
 */

require_once __DIR__ . '/oe_api_common.php';


/* ========================= Rules carried over ============================= */

/* ALL FOUR ARE ro1_oe_summary.php's, verbatim, and must stay in step with it.
 * They are redeclared rather than shared because that file is a script, not a
 * library — requiring it would run its whole report and emit its own JSON. */

/** The sentinel district_info uses for "live, with no end date". */
define('RO1WA_LIVE_UNTIL', '2099-12-31');

/** "district|YYYY-MM" pairs excluded from work done (mirrors
 *  summary_pavement_workdone.php, via ro1_oe_summary.php). */
$RO1WA_WORKDONE_EXCLUSIONS = array('Kalabakan|2025-01');

/** "Open" per potholeReport.php's empty($row['jobdone']) — NULL or '' ONLY.
 *  '0000-00-00' is done-but-late there, so it must not count as open here. */
define('RO1WA_SQL_STILL_OPEN', " AND (pd.jobdone IS NULL OR pd.jobdone = '')");

/** Closed 0-2 days after detection. DATEDIFF is NULL against '0000-00-00', so
 *  those rows drop out of the numerator exactly as in the reference. */
define('RO1WA_SQL_WITHIN_72H',
    " AND pd.jobdone IS NOT NULL
      AND pd.jobdone <> ''
      AND DATEDIFF(pd.jobdone, pd.detectDate) BETWEEN 0 AND 2");

/** The date a pothole_daily record counts under, for work done. */
define('RO1WA_WORKDONE_DATE',
    "CASE WHEN pd.submit_on_jan IS NULL OR pd.submit_on_jan = '' OR pd.submit_on_jan = '0000-00-00'
          THEN pd.jobdone ELSE pd.submit_on_jan END");

/** Where a login is turned into an email when one was not handed over. */
define('RO1WA_POOL_API',
    'https://globportal.com/accounts/mkPortal/hr/staffPoolAPI.php');

/** Seconds to wait on that lookup. It is a convenience path, not the cron's,
 *  so it gives up quickly rather than holding a page open. */
define('RO1WA_POOL_TIMEOUT', 12);

/**
 * The three columns, named once. The order is the order they are printed, and
 * the keys are what a client reads off every row.
 *
 * EVERY CLIENT DRAWS FROM THIS AND NOTHING ELSE, so a fourth column added here
 * reaches the email, the acknowledgement page, the app and the push with no
 * change on any of them. The fields, and why each exists:
 *
 *   label        the full name, for a page with room for it
 *   short_label  the name on a phone and in a push notification, where
 *                'Response time within 72 hrs' wraps to two lines and pushes
 *                the figure out of sight. Empty means the unit already says
 *                what it is — 'm²' needs no label beside it.
 *   unit         printed straight after the figure. 'percent' format carries
 *                its own %, so only the m² column sets one.
 *   format       number | percent | integer — how to print it
 *   decimals     at what precision
 *
 *   higher_is    'better' | 'worse' | '' — WHICH DIRECTION IS GOOD NEWS, and
 *                the only thing here a renderer cannot work out for itself.
 *                More work done is good; more outstanding potholes is not,
 *                and the two move in the same direction on the page. Without
 *                this the app had to colour every change the same neutral
 *                blue, because colouring it green or red would have been a
 *                judgement nobody had made. The ENDPOINT is the only layer
 *                that knows what its own figures mean, so it is the layer
 *                that says. A column that leaves it empty keeps the neutral
 *                treatment, which is why it is safe to omit.
 */
function ro1waColumns()
{
    return array(
        /* 'Work done in m2' on a page with room for it; 'Work done' on a
         * phone, where the figure beside it already ends in m² and repeating
         * the unit in the label is two words spent saying nothing. */
        array('key' => 'work_done_m2', 'label' => 'Work done in m2',
              'short_label' => 'Work done', 'unit' => ' m²',
              'format' => 'number', 'decimals' => 2, 'higher_is' => 'better'),
        array('key' => 'response_72h', 'label' => 'Response time within 72 hrs',
              'short_label' => 'Within 72 hrs', 'unit' => '',
              'format' => 'percent', 'decimals' => 2, 'higher_is' => 'better'),
        array('key' => 'outstanding_potholes', 'label' => 'Outstanding potholes',
              'short_label' => 'Outstanding', 'unit' => '',
              'format' => 'integer', 'decimals' => 0, 'higher_is' => 'worse'),
    );
}

/**
 * Query: what a PUSH NOTIFICATION calls this module and its columns.
 *
 * A NOTIFICATION IS MEASURED IN CHARACTERS THAT FIT ON A LOCK SCREEN, not in
 * words, so it gets its own shorter labels and its own coarser precision —
 * 1,219m², not 1,219.22. A reader glancing at a shade wants the size of the
 * number and which way it moved; the exact figure is two taps away and is in
 * the mail.
 *
 * TWO FIGURES, NOT THREE, AND THAT IS THE WHOLE POINT OF THIS FUNCTION.
 * All three fitted on paper and did not fit in a notification shade: at about
 * 37 characters Android wraps, and it wraps wherever it likes, so
 *
 *     RO1 1,214 m² ▲16 · 72h 97.8% ▼1.6 · 5
 *     open ▲10
 *
 * split one figure across two lines and read as noise. A push that has to be
 * decoded is worse than a shorter one.
 *
 * THE RESPONSE RATE IS THE ONE THAT GOES, because it is the least useful of
 * the three at 7am: it is a month-to-date rate whose denominator grows all
 * month, so by the 20th it barely moves whatever happened yesterday — see
 * THE DELTAS in the file header. Work done and outstanding potholes both move
 * daily and are both acted on daily. The rate is still in the mail, on the
 * acknowledgement page and in the app, at full precision.
 *
 * TO PUT IT BACK, add its line here — and know that the line will wrap on a
 * narrow handset:
 *
 *     array('key' => 'response_72h', 'label' => '72h', 'decimals' => 1),
 *
 * SUPPLIED BY THE ENDPOINT RATHER THAN DECIDED BY THE CRON, for the same
 * reason higher_is is: this file is the one that knows 'RO1' is the useful
 * abbreviation and which of its own figures is worth the space. A module that
 * sends no push block gets a line built from its title and its columns, which
 * works and is simply longer.
 */
function ro1waPushBlock()
{
    return array(
        'label'   => 'RO1',
        'columns' => array(
            /* No space before the unit, and no decimals: this is the one place
             * '1,219m²' beats '1,219.22 m²', because three characters here are
             * the difference between a line that fits and a line that wraps. */
            array('key' => 'work_done_m2', 'label' => '', 'unit' => 'm²',
                  'decimals' => 0),
            array('key' => 'outstanding_potholes', 'label' => 'open',
                  'decimals' => 0, 'label_after' => true),
        ),
    );
}


/* ========================= 1. Who, and when ============================== */

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { $in = array(); }

/** QUERY: one input, body first, then query string, then a default. */
function ro1waIn($key, $default = '')
{
    global $in;
    if (isset($in[$key]) && $in[$key] !== '') { return $in[$key]; }
    if (isset($_GET[$key]) && $_GET[$key] !== '') { return $_GET[$key]; }
    if (isset($_POST[$key]) && $_POST[$key] !== '') { return $_POST[$key]; }
    return $default;
}

$asHtml = (string)ro1waIn('html', '') === '1';

/* THE DAY, AND THE MONTH IT FALLS IN. "for that month" read on the 30th means
 * the 1st to the 30th, not the whole of October — a month-to-date figure, the
 * same shape as the Month to Date column of the weekly report. */
$rawDate = (string)ro1waIn('date', date('Y-m-d'));
$ts      = strtotime($rawDate);
if ($ts === false) { oe_fail("date '{$rawDate}' is not a date I can read"); }

$asAt = date('Y-m-d', $ts);
$start = date('Y-m-01', $ts);
$end   = $asAt;

/* The same month, read one day earlier. null on the 1st — see THE DELTAS. */
$prevEnd = oe_previous_end($start, $end);

/* IDENTITY. The email is the authority here; a login is a convenience that
 * resolves to one. */
$email      = strtolower(trim((string)ro1waIn('email', '')));
$person     = trim((string)ro1waIn('person', ''));
$compId     = (int)ro1waIn('comp_id', 1);
$fullname   = '';
$resolvedBy = 'email';

if ($email === '' && $person !== '') {
    $found = ro1waResolveEmail($person, $compId);
    if ($found === null) {
        oe_fail("staffPoolAPI.php does not know a person '{$person}' at company "
              . $compId . ', so there is no email to match districts on');
    }
    $email      = strtolower(trim($found['email']));
    $person     = $found['person'];
    $fullname   = $found['fullname'];
    $resolvedBy = 'staffPoolAPI';
}

if ($email === '') {
    oe_fail('email= is required (or person= and comp_id=, which resolves one '
          . 'through staffPoolAPI.php)');
}
/* A SHAPE CHECK, NOT A VALIDATION. The point is to refuse a login or a name
 * sent in the email field by mistake, which would match no district and come
 * back as a cheerful "no districts assigned" — indistinguishable from the
 * real thing, and the hardest kind of wrong answer to notice. */
if (strpos($email, '@') === false) {
    oe_fail("email '{$email}' is not an address — did you mean person=?");
}


/* ========================= 2. Their districts ============================ */

$connGlob = oe_connect(GLOB_DB);

$districts = ro1waDistrictsFor($connGlob, $email);

/* NOBODY'S DISTRICTS IS A SUCCESSFUL ANSWER, and the common one. */
if (empty($districts)) {
    ro1waOut($asHtml, array(
        'ok'       => true,
        'as_at'    => $asAt,
        'period'   => ro1waPeriod($start, $end),
        'compare'  => ro1waCompare($prevEnd),
        'person'   => array('email' => $email, 'person' => $person,
                            'fullname' => $fullname, 'comp_id' => $compId,
                            'resolved_by' => $resolvedBy),
        'columns'  => ro1waColumns(),
        'push'     => ro1waPushBlock(),
        'rows'     => array(),
        'total'    => null,
        'districts' => array(),
        'empty_reason' => 'No RO1 districts are assigned to ' . $email
                        . ' in district_info.',
    ));
}

$names = array();
foreach ($districts as $d) { $names[] = $d['district_name']; }


/* ========================= 3. Work done in m2 ============================ */

/* ONE PASS, TWO BUCKETS — the month as it stands now and the same month as it
 * stood yesterday. Two buckets rather than two queries because the Borang-A
 * rule is not additive (so a second aggregate could not be subtracted from the
 * first anyway) and because this is the heaviest read in the file. */
$bucket     = array();   // ["district|YYYY-MM"] => ['ba' => m2, 'nba' => m2]
$bucketPrev = array();

$sql = "SELECT pd.district AS district,
               DATE(" . RO1WA_WORKDONE_DATE . ") AS d,
               DATE_FORMAT(" . RO1WA_WORKDONE_DATE . ", '%Y-%m') AS ym,
               CASE WHEN pd.borangA IS NOT NULL AND pd.borangA <> '' THEN 1 ELSE 0 END AS has_ba,
               pdi.final_length AS l,
               pdi.final_width  AS w
          FROM pothole_daily pd
          INNER JOIN pothole_daily_item pdi ON pdi.daily_id = pd.id
         WHERE pd.status = '1'
           AND pdi.status = '1'
           AND " . RO1WA_WORKDONE_DATE . " BETWEEN ? AND ?"
     . oe_in_sql($connGlob, 'pd.district', $names);

$stmt = mysqli_prepare($connGlob, $sql);
if ($stmt === false) oe_fail('work done prepare failed: ' . mysqli_error($connGlob));
mysqli_stmt_bind_param($stmt, 'ss', $start, $end);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

while ($row = mysqli_fetch_assoc($res)) {
    $district = trim((string)$row['district']);
    $key      = $district . '|' . $row['ym'];
    if (in_array($key, $RO1WA_WORKDONE_EXCLUSIONS, true)) { continue; }

    $slot = $row['has_ba'] ? 'ba' : 'nba';
    /* PER ITEM, AND JKR ROUNDING — oe_rounding_up()'s $jkr path, which is the
     * house rule summary_pavement_workdone.php uses. Rounding the sum instead
     * of each item gives a different number from the report. */
    $area = oe_rounding_up(floatval($row['l']) * floatval($row['w']), 2, 'JKR');

    if (!isset($bucket[$key])) { $bucket[$key] = array('ba' => 0.0, 'nba' => 0.0); }
    $bucket[$key][$slot] += $area;

    /* The same row, the same rules, one day short. The null guard is
     * insurance: the WHERE above already drops a NULL effective date, but PHP
     * compares null against a date string as '', which sorts before every real
     * date — a null slipping through would join the earlier reading only and
     * print as a permanent phantom movement. */
    if ($prevEnd !== null && $row['d'] !== null && $row['d'] <= $prevEnd) {
        if (!isset($bucketPrev[$key])) { $bucketPrev[$key] = array('ba' => 0.0, 'nba' => 0.0); }
        $bucketPrev[$key][$slot] += $area;
    }
}
mysqli_stmt_close($stmt);


/* ============== 4. Response within 72h, and outstanding ================== */

$potholes = ro1waPotholesByDistrict($connGlob, $start, $end, $prevEnd, $names);


/* ========================= 5. Assemble =================================== */

$rows = array();
$sum  = array('m2' => 0.0, 'm2_prev' => 0.0,
              'within' => 0, 'within_prev' => 0,
              'detected' => 0, 'detected_prev' => 0,
              'open' => 0, 'open_prev' => 0);

foreach ($districts as $d) {
    $name = $d['district_name'];

    $m2     = ro1waBucketTotal($bucket, $name);
    $m2Prev = ro1waBucketTotal($bucketPrev, $name);

    $p = isset($potholes[$name]) ? $potholes[$name] : ro1waEmptyPotholes();

    $sum['m2']            += $m2;
    $sum['m2_prev']       += $m2Prev;
    $sum['within']        += $p['within'];
    $sum['within_prev']   += $p['within_prev'];
    $sum['detected']      += $p['detected'];
    $sum['detected_prev'] += $p['detected_prev'];
    $sum['open']          += $p['open'];
    $sum['open_prev']     += $p['open_prev'];

    $rows[] = array(
        'district'      => $name,
        'district_code' => $d['district_code'],
        'work_done_m2'  => ro1waCell($m2, $prevEnd === null ? null : $m2 - $m2Prev),
        'response_72h'  => ro1waRate($p['within'], $p['detected'],
                                     $p['within_prev'], $p['detected_prev'], $prevEnd),
        'outstanding_potholes' => ro1waCell(
            $p['open'], $prevEnd === null ? null : $p['open'] - $p['open_prev']),
    );
}

/* THE TOTAL. m2 and the count are the sum of the rows; the percentage is
 * recomputed over the pooled population — see THE TOTAL ROW in the docblock
 * for why it is not the mean of the rows above it. */
$total = array(
    'district'      => 'Total',
    'district_code' => '',
    'work_done_m2'  => ro1waCell($sum['m2'],
                           $prevEnd === null ? null : $sum['m2'] - $sum['m2_prev']),
    'response_72h'  => ro1waRate($sum['within'], $sum['detected'],
                                 $sum['within_prev'], $sum['detected_prev'], $prevEnd),
    'outstanding_potholes' => ro1waCell($sum['open'],
                           $prevEnd === null ? null : $sum['open'] - $sum['open_prev']),
);

ro1waOut($asHtml, array(
    'ok'      => true,
    'as_at'   => $asAt,
    'period'  => ro1waPeriod($start, $end),
    'compare' => ro1waCompare($prevEnd),
    'person'  => array('email' => $email, 'person' => $person,
                       'fullname' => $fullname, 'comp_id' => $compId,
                       'resolved_by' => $resolvedBy),
    'columns'   => ro1waColumns(),
    'push'      => ro1waPushBlock(),
    'row_label' => 'Districts',
    'rows'      => $rows,
    'total'     => $total,
    'districts' => $names,
    'empty_reason' => null,
));


/* ============================== helpers ================================== */

/**
 * QUERY: the districts this address is the manager of, in district_code order.
 * @return array list of ['district_code' => ..., 'district_name' => ...]
 *
 * MANY ROWS PER MANAGER IS THE NORMAL CASE — see WHOSE DISTRICTS in the
 * docblock. Nothing here collapses them and nothing picks one.
 *
 * TO WIDEN THIS to assistants or district engineers, add a column to the OR:
 *
 *     AND (TRIM(LOWER(managerMail))   = ?
 *       OR TRIM(LOWER(assistantMail)) = ?)
 *
 * — and bind the address once per column. Know what it changes first: those
 * are different accountabilities, and the figures would stop being "the work
 * this person is answerable for".
 *
 * A BLANK managerMail CANNOT MATCH. The guard looks redundant beside an
 * equality test on a non-empty address, but district_info has rows with an
 * empty manager mail and a caller handing over an empty email is a bug this
 * would otherwise answer with EVERY unassigned district.
 */
function ro1waDistrictsFor($conn, $email)
{
    $want = strtolower(trim((string)$email));
    if ($want === '') { return array(); }

    $stmt = mysqli_prepare($conn,
        "SELECT district_code, district_name
           FROM district_info
          WHERE expire_date = ?
            AND TRIM(IFNULL(managerMail, '')) <> ''
            AND TRIM(LOWER(managerMail)) = ?
          ORDER BY district_code ASC");
    if ($stmt === false) oe_fail('district_info prepare failed: ' . mysqli_error($conn));

    $live = RO1WA_LIVE_UNTIL;
    mysqli_stmt_bind_param($stmt, 'ss', $live, $want);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $out  = array();
    $seen = array();
    while ($row = mysqli_fetch_assoc($res)) {
        $name = trim((string)$row['district_name']);
        if ($name === '' || isset($seen[$name])) { continue; }
        $seen[$name] = true;
        $out[] = array(
            'district_code' => trim((string)$row['district_code']),
            'district_name' => $name,
        );
    }
    mysqli_stmt_close($stmt);
    return $out;
}

/**
 * QUERY: the pothole figures for every district, both readings, in ONE query.
 * @return array district_name => detected, within, open, and the _prev of each
 *
 * BOTH READINGS IN ONE PASS. The earlier reading is the same rows with
 * detectDate <= $prevEnd, so it is a conditional SUM beside the first rather
 * than a second trip over the table. On the 1st of the month $prevEnd is null
 * and a sentinel date no row can match is bound instead — the _prev columns
 * then come back 0 and the caller emits null deltas, which is correct because
 * there is nothing to compare rather than nothing having happened.
 *
 * EVERY POOL RULE IS potholeReport.php's, through ro1_oe_summary.php:
 *   nos AS STORED, live records (status = 1), real detections (timemark > 1),
 *   and pothole_type.group_management = 'pothole'. The type comparison is
 *   BINARY because PHP compares those strings case-sensitively while MySQL's
 *   collation does not — without it a 'Pothole' row would count here and not
 *   there.
 */
function ro1waPotholesByDistrict($conn, $start, $end, $prevEnd, array $names)
{
    $typeSql   = oe_in_sql($conn, 'BINARY TRIM(pd.pothole_type)', ro1waPotholeTypes($conn));
    $rosterSql = oe_in_sql($conn, 'pd.district', $names);

    /* A date no detectDate inside the range can be, so the _prev half of the
     * query contributes nothing on the first of the month. */
    $cut = ($prevEnd === null) ? '0000-00-00' : $prevEnd;

    $sql =
        "SELECT pd.district AS district,
                COALESCE(SUM(pd.nos), 0) AS detected,
                COALESCE(SUM(CASE WHEN pd.detectDate <= ? THEN pd.nos ELSE 0 END), 0)
                    AS detected_prev,
                COALESCE(SUM(CASE WHEN pd.jobdone IS NOT NULL AND pd.jobdone <> ''
                                   AND DATEDIFF(pd.jobdone, pd.detectDate) BETWEEN 0 AND 2
                                  THEN pd.nos ELSE 0 END), 0) AS within72,
                COALESCE(SUM(CASE WHEN pd.jobdone IS NOT NULL AND pd.jobdone <> ''
                                   AND DATEDIFF(pd.jobdone, pd.detectDate) BETWEEN 0 AND 2
                                   AND pd.detectDate <= ?
                                  THEN pd.nos ELSE 0 END), 0) AS within72_prev,
                COALESCE(SUM(CASE WHEN (pd.jobdone IS NULL OR pd.jobdone = '')
                                  THEN pd.nos ELSE 0 END), 0) AS still_open,
                COALESCE(SUM(CASE WHEN (pd.jobdone IS NULL OR pd.jobdone = '')
                                   AND pd.detectDate <= ?
                                  THEN pd.nos ELSE 0 END), 0) AS still_open_prev
           FROM pothole_daily pd
          WHERE pd.detectDate BETWEEN ? AND ?
            AND pd.status = 1
            AND pd.timemark > 1"
        . $rosterSql . $typeSql .
        " GROUP BY pd.district";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt === false) oe_fail('pothole prepare failed: ' . mysqli_error($conn));
    mysqli_stmt_bind_param($stmt, 'sssss', $cut, $cut, $cut, $start, $end);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $out = array();
    while ($row = mysqli_fetch_assoc($res)) {
        $out[trim((string)$row['district'])] = array(
            'detected'      => (int)$row['detected'],
            'detected_prev' => (int)$row['detected_prev'],
            'within'        => (int)$row['within72'],
            'within_prev'   => (int)$row['within72_prev'],
            'open'          => (int)$row['still_open'],
            'open_prev'     => (int)$row['still_open_prev'],
        );
    }
    mysqli_stmt_close($stmt);
    return $out;
}

/** QUERY: a district with no potholes at all this month. Zeroes, not absence:
 *  a district that was quiet still gets a row, because "0 outstanding" is an
 *  answer and a missing row is not. */
function ro1waEmptyPotholes()
{
    return array('detected' => 0, 'detected_prev' => 0,
                 'within' => 0, 'within_prev' => 0,
                 'open' => 0, 'open_prev' => 0);
}

/**
 * QUERY: the pothole_type values potholeReport.php manages as potholes — the
 * same rows its $pTypeArr reads, filtered on the same column.
 *
 * Three qualify today: 'pothole', 'Small Pothole 200mm below' and
 * 'Pothole Temporary'. A silent empty list would render 0 detected and 0.00%,
 * which reads as "nothing was found this month" rather than as a broken query.
 */
function ro1waPotholeTypes($conn)
{
    $res = mysqli_query($conn,
        "SELECT DISTINCT pothole_type FROM pothole_type
          WHERE BINARY group_management = 'pothole'");
    if ($res === false) oe_fail('pothole_type query failed: ' . mysqli_error($conn));

    $types = array();
    while ($row = mysqli_fetch_assoc($res)) { $types[] = $row['pothole_type']; }
    mysqli_free_result($res);

    if (empty($types)) {
        oe_fail("no pothole_type rows with group_management = 'pothole' in " . GLOB_DB);
    }
    return $types;
}

/**
 * QUERY: total m2 for ONE district in a bucket, applying the Borang-A rule.
 *
 * BORANG-A SUPERSEDES NON-BORANG-A PER DISTRICT PER MONTH: if a district filed
 * any Borang-A area in a month, only that area counts and its non-Borang-A area
 * for the same month is discarded entirely. That is what the reference report's
 * monthly TOTAL row does, and a plain SUM would not match it.
 *
 * THE LOOP IS OVER MONTHS, not over districts. The range is inside one month
 * on every normal call, so there is one key — but a caller handing over a range
 * that straddles a month boundary gets the rule applied per month, which is
 * what it means. Nothing here assumes a single month.
 */
function ro1waBucketTotal(array $bucket, $district)
{
    $total  = 0.0;
    $prefix = $district . '|';
    foreach ($bucket as $key => $b) {
        if (strpos($key, $prefix) !== 0) { continue; }
        $total += ($b['ba'] > 0) ? $b['ba'] : $b['nba'];
    }
    return $total;
}

/** QUERY: one figure and its movement. $delta null means "nothing to compare
 *  it against", which is NOT the same as 0 and must not be flattened into it. */
function ro1waCell($value, $delta)
{
    return array('value' => $value, 'delta' => $delta);
}

/**
 * QUERY: a percentage cell — the rate, its movement, and the two counts it was
 * computed from.
 *
 * THE COUNTS RIDE ALONG so the arithmetic is checkable without re-running
 * anything, and so a client can pool rows itself if it ever needs a subtotal
 * this endpoint does not emit.
 *
 * A DENOMINATOR OF ZERO IS 0.00%, matching potholeReport.php. It is reported
 * beside denominator: 0, so "no potholes were detected" and "none of the
 * potholes were dealt with in time" are distinguishable — they look identical
 * in the percentage alone and they could hardly be further apart in meaning.
 *
 * THE DELTA IS THE DIFFERENCE OF THE TWO RATES, each over its own denominator.
 * Not a rate OF the differences: today's six new potholes closed five of six
 * is not the movement of a month-long average, and printing it as one would
 * overstate every daily change by an order of magnitude.
 */
function ro1waRate($within, $detected, $withinPrev, $detectedPrev, $prevEnd)
{
    $pct = ($detected > 0) ? ($within / $detected) * 100.0 : 0.0;

    $delta = null;
    if ($prevEnd !== null) {
        $prevPct = ($detectedPrev > 0) ? ($withinPrev / $detectedPrev) * 100.0 : 0.0;
        /* NO CHIP UNTIL THERE IS A RATE TO HAVE MOVED FROM. With no detections
         * before today the earlier rate is not 0%, it is undefined, and
         * subtracting it would print the whole of today's rate as a rise. */
        $delta = ($detectedPrev > 0) ? $pct - $prevPct : null;
    }

    return array(
        'value'       => $pct,
        'delta'       => $delta,
        'numerator'   => $within,
        'denominator' => $detected,
    );
}

/** QUERY: the period block — the dates and how to say them. */
function ro1waPeriod($start, $end)
{
    return array(
        'start' => $start,
        'end'   => $end,
        'label' => date('F Y', strtotime($start))
                 . ' (1 to ' . (int)date('j', strtotime($end)) . ')',
    );
}

/** QUERY: the comparison block. A null end is the 1st of the month and says
 *  so, rather than leaving a client to guess why every delta is null. */
function ro1waCompare($prevEnd)
{
    if ($prevEnd === null) {
        return array(
            'end'   => null,
            'label' => 'no earlier day in this month to compare with',
        );
    }
    return array('end' => $prevEnd, 'label' => 'vs ' . date('j M', strtotime($prevEnd)));
}

/**
 * QUERY: one person's email, from staffPoolAPI.php.
 * @return array|null person, fullname, email — or null when nothing matched
 *
 * NARROWED WITH q=, NOT THE WHOLE COMPANY. The pool is 680 people and this
 * needs one of them; q is a name-or-code search, so the answer is normally a
 * single row.
 *
 * MATCHED EXACTLY ON person AFTERWARDS, because q is a SEARCH and can return
 * several — 'jack' finds anybody whose name contains it. The login is the
 * identity, so a row whose person is not the login asked for is not the
 * answer, however good a name match it was. Guessing between two people is
 * how a manager ends up reading another manager's districts.
 *
 * A PERSON WITH NO EMAIL IS null, not an empty address. An empty email would
 * fall through to ro1waDistrictsFor() and be refused there, with a message
 * about districts rather than about the missing address.
 */
function ro1waResolveEmail($person, $compId)
{
    $url = RO1WA_POOL_API . '?' . http_build_query(array(
        'comp_id'    => (int)$compId,
        'q'          => $person,
        'staff_type' => 'staff',
        'excluded'   => 0,
    ));

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => RO1WA_POOL_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'ro1_work_achievement',
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code !== 200) {
        oe_fail('staffPoolAPI.php did not answer (HTTP ' . $code
              . ($err !== '' ? ', ' . $err : '') . ') — pass email= instead');
    }

    $json = json_decode((string)$body, true);
    if (!is_array($json) || empty($json['success'])) {
        oe_fail('staffPoolAPI.php refused the lookup — pass email= instead');
    }

    $want = strtolower(trim((string)$person));
    foreach (isset($json['staff']) ? $json['staff'] : array() as $r) {
        if (strtolower(trim((string)$r['person'])) !== $want) { continue; }
        $mail = trim((string)(isset($r['email']) ? $r['email'] : ''));
        if ($mail === '') { return null; }
        return array(
            'person'   => (string)$r['person'],
            'fullname' => (string)$r['fullname'],
            'email'    => $mail,
        );
    }
    return null;
}

/**
 * COMMAND: emit the answer and stop — as JSON, or as the table in a browser.
 *
 * ONE EXIT FOR BOTH, so the two can never be produced from different figures:
 * the HTML path formats the SAME array the JSON path encodes, and there is no
 * second computation behind it.
 *
 * THE JSON KEEPS FULL PRECISION and the HTML applies `decimals`, because that
 * is what the field is for. See EMIT in the docblock.
 */
function ro1waOut($asHtml, array $payload)
{
    if (!$asHtml) {
        /* oe_api_common.php already sent the JSON content type. */
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo ro1waHtml($payload);
    exit;
}

/** QUERY: one figure, printed — the value at its stated precision, and its
 *  movement as a signed chip. A null delta prints nothing at all, because
 *  there is nothing to say; a zero delta prints a dash, because "measured, and
 *  it did not move" is a different fact and worth seeing. */
function ro1waFmt(array $cell, array $col)
{
    $v = number_format((float)$cell['value'], (int)$col['decimals']);
    if ($col['format'] === 'percent') { $v .= '%'; }
    if (!empty($col['unit'])) { $v .= $col['unit']; }

    $d = $cell['delta'];
    if ($d === null) { return array($v, '', ''); }
    if (abs((float)$d) < 0.0000001) { return array($v, '—', 'flat'); }

    $sign = ((float)$d > 0) ? '+' : '−';
    $mag  = number_format(abs((float)$d), (int)$col['decimals']);
    if ($col['format'] === 'percent') { $mag .= '%'; }

    return array($v, $sign . $mag, ((float)$d > 0) ? 'up' : 'down');
}

/** QUERY: the whole page, for ?html=1. A checking view: deliberately plain,
 *  and it names the period, the comparison day and the person at the top so a
 *  screenshot of it is self-explanatory. */
function ro1waHtml(array $p)
{
    $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

    $who = $p['person']['fullname'] !== ''
        ? $p['person']['fullname'] . ' (' . $p['person']['email'] . ')'
        : $p['person']['email'];

    $h = "<!DOCTYPE html>\n<html lang=\"en\"><head><meta charset=\"utf-8\">"
       . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
       . "<title>RO1 Work Achievement</title><style>"
       . "body{margin:0;padding:24px;background:#f1f5f9;color:#1e293b;"
       . "font:14px/1.5 -apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif}"
       . ".wrap{max-width:860px;margin:0 auto}"
       . "h1{font-size:19px;margin:0 0 4px}"
       . ".sub{color:#64748b;font-size:13px;margin-bottom:18px}"
       . ".card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px}"
       . "table{width:100%;border-collapse:collapse}"
       . "th{text-align:right;font-size:11.5px;text-transform:uppercase;letter-spacing:.03em;"
       . "color:#64748b;padding:8px 10px;border-bottom:2px solid #e2e8f0}"
       . "th:first-child{text-align:left}"
       . "td{padding:9px 10px;border-bottom:1px solid #e2e8f0;text-align:right;"
       . "font-variant-numeric:tabular-nums}"
       . "td:first-child{text-align:left}"
       . "tr.total td{font-weight:700;background:#f8fafc;border-top:2px solid #e2e8f0;"
       . "border-bottom:none}"
       . ".chip{display:inline-block;margin-left:7px;font-size:11.5px;font-weight:700}"
       . ".chip.up{color:#dc2626}.chip.down{color:#059669}.chip.flat{color:#94a3b8}"
       . ".note{color:#64748b;font-size:12px;margin-top:14px}"
       . ".empty{color:#64748b;text-align:center;padding:26px 0}"
       . "</style></head><body><div class=\"wrap\">";

    $h .= "<h1>RO1 Work Achievement</h1><div class=\"sub\">"
        . $e($who) . " &middot; " . $e($p['period']['label'])
        . " &middot; as at " . $e($p['as_at'])
        . " &middot; " . $e($p['compare']['label']) . "</div>";

    $h .= "<div class=\"card\">";

    if (empty($p['rows'])) {
        $h .= "<div class=\"empty\">" . $e($p['empty_reason']) . "</div>";
    } else {
        $h .= "<table><thead><tr><th>Districts</th>";
        foreach ($p['columns'] as $col) { $h .= "<th>" . $e($col['label']) . "</th>"; }
        $h .= "</tr></thead><tbody>";

        $all = $p['rows'];
        $all[] = $p['total'] + array('_total' => true);

        foreach ($all as $row) {
            $h .= "<tr" . (isset($row['_total']) ? " class=\"total\"" : "") . ">";
            $h .= "<td>" . $e($row['district']) . "</td>";
            foreach ($p['columns'] as $col) {
                list($v, $chip, $dir) = ro1waFmt($row[$col['key']], $col);
                $h .= "<td>" . $e($v);
                if ($chip !== '') {
                    $h .= "<span class=\"chip " . $e($dir) . "\">" . $e($chip) . "</span>";
                }
                $h .= "</td>";
            }
            $h .= "</tr>";
        }
        $h .= "</tbody></table>";

        /* The two things a reader of these figures has to know, printed where
         * they are read rather than left in a docblock nobody opens. */
        $h .= "<div class=\"note\">The total response rate is every pothole closed "
            . "within 72 hours over every pothole detected in these districts — not "
            . "the average of the rows above, which would weigh a quiet district the "
            . "same as a busy one. The change on Outstanding potholes is potholes "
            . "detected today that are still open; clearing an older backlog does not "
            . "move it.</div>";
    }

    $h .= "</div></div></body></html>";
    return $h;
}
