<?php
/**
 * STAFF SUMMARY CRON — the morning mail to every HOD and Area Manager: their
 * department in three sections, a row a person, and one tick box closing
 * each section.
 *
 * TRIGGERED BY A URL, not by PHP CLI:
 *
 *     https://globportal.com/accounts/mkPortal/hr/staffSummaryCron.php?key=...
 *
 * so the cron entry is a curl/wget line and the same URL opened in a browser
 * does the same thing and prints what it did. The key is the whole of the
 * guard — see SUMMARY_CRON_KEY — because this address has to be reachable
 * without a session for cron to use it.
 *
 * IT OWNS NO FACTS. Every figure comes from the four APIs beside it, over
 * HTTP, exactly as staffSummary.php's page gets them:
 *
 *     staffPoolAPI.php    who the HODs and Area Managers are, and which
 *                         departments each may see (access[])
 *     attendanceAPI.php   check-in and check-out, and the placement
 *                         (hod_person / am_person) the table is grouped by
 *     leaveAPI.php        the month's leave, day by day
 *     performanceAPI.php  the month's compliance
 *
 * AND, SINCE 30 OCT 2026, WHATEVER THE MODULE CATALOGUE NAMES. The three
 * sections above were constants in this file; they are now rows in
 * mkPortal.staff_summary_module, ticked per person on
 * staffSummaryModuleReport.php. A module marked NOT built-in is FETCHED per
 * person from the address stored beside it — RO1 Work Achievement lives on
 * glob.com.my, which is a different server with its own database, so there is
 * no filename here that could reach it. See staffSummaryModules.php; it fails
 * open to the original three, so a morning before the DDL is run is a morning
 * exactly like last week's.
 *
 * THAT IS THE POINT OF THE HTTP CALLS. Reading the tables directly here would
 * be a second definition of "late", of "on leave" and of "who reports to
 * whom", free to drift from the APIs the day somebody changes one. Change the
 * lateness rule in attendanceAPI.php and this mail changes with it, with
 * nothing to update twice. The cost is five local HTTP requests a day.
 *
 * FIVE CALLS, NOT FIVE PER PERSON. Each API answers for the whole company in
 * a single request — attendanceAPI.php without `staff`, performanceAPI.php
 * without `mk_id`, leaveAPI.php without `person` — so the run is the same
 * size whatever the head count. The page beside this one asks per person
 * because it fills a screen as you open it; a cron has no such excuse.
 *
 * A ROW A PERSON, AND EVERYBODY IS ON IT. The table lists the recipient's
 * whole department — the people with nothing to report as well as the ones
 * with something, and the head of the department themselves. That is what
 * makes it a review rather than an exception report: a manager can see at a
 * glance that the quiet rows really are quiet, which an exception list cannot
 * show by construction. Rows with something on them sort to the top.
 *
 * ONE TICK A SECTION. The acknowledgement is per section, not per person and
 * not per event: each of the three ends in a single box, under a sentence
 * saying what ticking it means. A head reads a list once and says so once.
 *
 * WHAT THE COLUMNS ARE:
 *   Lateness     yesterday and today, each showing the 8:00am check-in and the
 *                4:30pm check-out. A late check-in is red; a check-out that is
 *                not there reads 'missing' and is red. A check-out is never
 *                red for being late, because nothing at 4:30pm is late — see
 *                attendanceAPI.php.
 *   Leave        this calendar month, split at today: taken, upcoming, total.
 *   Compliance   this calendar month.
 *
 * WHO SEES WHAT — the one rule:
 *   An HOD sees their own department. An Area Manager sees their own area.
 *   Somebody with a department grant sees that department too.
 *
 * attendanceAPI.php publishes hod_person and am_person on EVERY staff row —
 * the nearest department head above them and the nearest sub-department head
 * — so this file asks it rather than walking a tree of its own. The grants are
 * staffPoolTree::departmentAccess(), published as access[], which is where
 * Jeffrey (the whole company), Soo Chee Kiong (eight units) and Rino (Fleet
 * and Workshop) are written down. THEY ARE NOT REPEATED HERE: add somebody
 * there and they are covered here the next morning.
 *
 * NOT ON SUNDAYS, NOT ON PUBLIC HOLIDAYS. Sunday is the calendar; the holiday
 * comes from attendanceAPI.php's own holidays block, which reads HR's table.
 * If that calendar cannot be read, THE MAIL GOES OUT: a silent skip would be
 * indistinguishable from a broken cron.
 *
 * THREE TICK BOXES, ONE A SECTION, EACH UNDER THE TABLE IT CLOSES. Beside
 * every box is the sentence it stands for — "I have read the lateness above
 * and will follow it up." — and the same sentence appears on the
 * page, so what somebody agreed to in the mail and what the record says they
 * agreed to are one text. summaryConfirmLine() holds the wording.
 *
 * A SECTION WITH NOBODY IN IT HAS NO BOX, because there is nothing to have
 * understood; it is left out of the count and cannot hold the
 * acknowledgement up.
 *
 * WHETHER THE BOXES SURVIVE IS THE MAIL CLIENT'S DECISION. Gmail and Outlook
 * strip <form> and every <input> inside it, so those readers see the
 * sentences and the Acknowledge LINK and do their ticking on the page; Apple
 * Mail and iOS render the boxes and submit them. Both routes end at
 * staffSummaryAck.php, which is where the record is made, and the sentences
 * are printed either way so nobody presses anything without having read what
 * it means.
 *
 * Read-only against every API. The only thing it writes is its own row in
 * mkPortal.staff_summary_notify — see sql/staff_summary_notify.sql.
 *
 * PHP 7.2 compatible.
 */

/* staffPoolCore.php is the DB handle and nothing else here. This file lives in
 * hr/ beside the APIs, so the local name is the normal path; the ../hr/ form
 * is kept for the day somebody moves it to mkCommand/. */
$summaryCore = __DIR__ . '/staffPoolCore.php';
if (!file_exists($summaryCore)) {
    $summaryCore = __DIR__ . '/../hr/staffPoolCore.php';
}
require_once $summaryCore;

/* How a cell is read, shared with staffSummaryAck.php so the mail and the
 * page cannot disagree about what a column means. It defines and returns and
 * runs nothing — see the note at the top of it. */
require_once __DIR__ . '/staffSummaryShared.php';

/* WHICH SECTIONS EACH PERSON GETS, AND WHERE THE FETCHED ONES COME FROM.
 * The three built-in sections used to be a constant in this file; they are
 * now rows in mkPortal.staff_summary_module, together with any module that
 * lives on ANOTHER SERVER and so has to be fetched by address rather than
 * named by filename. Ticked per person on staffSummaryModuleReport.php.
 *
 * It fails open to the original three, every one of them shown and required,
 * so a morning before the DDL is run is a morning exactly like last week's. */
require_once __DIR__ . '/staffSummaryModules.php';

/* ---------- configuration ------------------------------------------------- */

/** THE GUARD. This URL must answer without a session for cron to call it, so
 *  the key is all that stands between it and the open internet. A request
 *  without it is refused and nothing is sent. */
define('SUMMARY_CRON_KEY', 'mK7s2Qv9XpR4tLdN6wBzYe3hJ8aCfU5g');

/** WHERE THE FOUR APIS ARE. Empty means "the folder this file is in", which is
 *  the normal case and needs no editing. */
define('SUMMARY_API_BASE', '');

/** WHERE THE ACKNOWLEDGEMENT PAGE IS, as the mail must link to it: absolute,
 *  because a relative link in an email goes nowhere.
 *
 *  THIS IS hr/, NOT mkCommand/. These three files live beside the APIs they
 *  read, and the folder in this URL has to be the folder they were uploaded
 *  to or every link in every mail is a 404. */
define('SUMMARY_ACK_URL',
       'https://globportal.com/accounts/mkPortal/hr/staffSummaryAck.php');

/** WHILE THIS IS SET, EVERY MAIL GOES HERE and nobody else is written to. The
 *  real recipient is still worked out, still logged in email_intended, and
 *  still named at the top of the mail. Emptying this string is the whole of
 *  "go live". */
define('SUMMARY_EMAIL_OVERRIDE', 'prog4.glob@cityskill.my');

/** The From, matching staffNotify.php's. */
define('SUMMARY_EMAIL_FROM', 'MK Staff Portal <noreply@glob.com.my>');

/** THE PUSH, ON OR OFF. Off means the morning runs exactly as it did before
 *  this existed: the mail goes, nothing is pushed and nothing is logged. It
 *  is a constant rather than a URL parameter so a misbehaving endpoint can be
 *  taken out of the morning without anybody being able to put it back by
 *  typing in a browser. */
define('SUMMARY_PUSH', true);

/** The endpoint that turns a staff_portal2.users.id into FCM sends — the same
 *  one staffNotify.php and mrf_notify.php post to. It holds the tokens, fans
 *  one id out to every device that id has registered, and answers with the
 *  list it sent to. That answer is the only place the token and the platform
 *  exist, which is why summaryPushDevices() unpacks it. */
define('SUMMARY_PUSH_URL',     'http://app.globportal.com/mymk/api/firebase/fcm/custom');
define('SUMMARY_PUSH_TIMEOUT', 8);

/** WHILE THIS IS SET, EVERY PUSH GOES TO THIS users.id and no manager is
 *  notified. The real recipient is still worked out, still named in the log's
 *  user_id_intended, and the body is still that person's own morning — only
 *  the handset is somebody else's.
 *
 *  IT IS THE PAIR OF SUMMARY_EMAIL_OVERRIDE and they come off together:
 *  emptying one and not the other means the mail is live while the push is
 *  still landing on a test phone, or the other way round. '' = push to the
 *  managers themselves. */
define('SUMMARY_PUSH_TEST_USER_ID', '3946');

/**
 * HOW MANY ROWS THE MAIL ITSELF SHOWS. The rest are counted, not listed, with
 * a line saying so; the acknowledgement page always shows every one.
 *
 * THIS IS NOT TIDINESS, IT IS GMAIL. Gmail stops rendering at about 102KB and
 * hides the remainder behind "View entire message" — and what is at the bottom
 * of this mail is the Acknowledge button. A row a person is a much longer
 * table than an exception list was: somebody who can see the whole company has
 * close to 700 of them, which is far past the limit.
 *
 * MEASURED, NOT GUESSED, AND MEASURED ON THE WIRE. The mail is 6.0KB of HTML
 * before any rows and 1,497 bytes a row — but what Gmail weighs is the encoded
 * message, and summarySend() base64s the body to survive SMTP's line limit.
 * Base64 is four bytes out for every three in, so every figure below is the
 * HTML plus a third:
 *
 *     30 rows → 50KB html →  68KB sent
 *     35 rows → 57KB html →  78KB sent
 *     40 rows → 65KB html →  88KB sent   ← renders, with little to spare
 *     50 rows → 79KB html → 108KB sent   ← clipped
 *
 * 35 IS THE WHOLE MAIL'S BUDGET, SHARED BY THE THREE SECTIONS — not a cap on
 * the people list, which is what it used to be and what made Jeffrey's
 * Compliance read 0 while 515 people were waiting on the page. The three
 * sections now count their own people first and the heading prints that
 * figure; the budget only decides how many rows each one gets to show. See
 * summarySectionCaps().
 *
 * EVERY NON-EMPTY SECTION IS GUARANTEED SUMMARY_MAIL_MIN_ROWS, so a long
 * Lateness list can no longer crowd Compliance out of its own mail, and what
 * is left over goes to whichever sections still want it.
 *
 * It fits a whole district either way: the real ones run to the high twenties
 * and low thirties — TWU is 35 — so an Area Manager or an HOD with one
 * department still gets their entire list and never sees a cap. It only
 * bites for somebody who can see several at once, which is the case no mail
 * could hold anyway.
 *
 * Dropping the pink row tint is what paid for it: the row was 2,133 bytes
 * with a bgcolor on every cell and is 1,497 without.
 *
 * ROWS WITH SOMETHING ON THEM COME FIRST within each department, so a capped
 * table still holds everything that needed looking at, each section says how
 * many it left out, and the page holds the whole list whatever happens.
 */
define('SUMMARY_MAIL_MAX_ROWS', 35);

/** The floor under each non-empty section — see SUMMARY_MAIL_MAX_ROWS. Eight
 *  is enough to show that a section has something in it and roughly what,
 *  which is all a mail can honestly do for somebody who sees 550 people;
 *  three sections at the floor is 24 rows, well inside the budget. */
define('SUMMARY_MAIL_MIN_ROWS', 8);

/** The one company this is built for. attendanceAPI.php and leaveAPI.php
 *  answer for company 1 only. */
define('SUMMARY_COMP_ID', 1);

/** How long to wait on one API call. */
define('SUMMARY_HTTP_TIMEOUT', 120);

/**
 * HOW LONG A PUSH LINE MAY BE BEFORE IT STARTS LOSING FIGURES.
 *
 * Measured in characters, because that is what a notification shade is
 * measured in. Forty is the observed wrap point on a mid-size Android handset
 * at the default font size, and 38 leaves a little room for a wider one. The
 * existing staff line sits at 33 and has never wrapped.
 *
 * IT IS A CEILING FOR THE FETCHED MODULE LINES AND NOTHING ELSE. The staff
 * line is three fixed figures and cannot grow; a module's line is whatever its
 * endpoint describes, so that is the one that needs a limit.
 *
 * Raise it and lines wrap; lower it and figures are dropped sooner. Neither is
 * an error — see the note in summaryPushModuleLine() for what dropping means.
 */
define('SUMMARY_PUSH_LINE_MAX', 38);

/* ---------- the palette ---------------------------------------------------
 *
 * Named once, used throughout the mail, and matched by staffSummaryAck.php so
 * the page looks like the mail it came from. An HTML mail cannot have a
 * stylesheet worth relying on, so these are pasted into style attributes —
 * which is exactly why they are constants: the same grey typed thirty times is
 * how a layout drifts.
 */
define('SUM_INK',    '#0f172a');   /* headings                          */
define('SUM_BODY',   '#334155');   /* ordinary text                     */
define('SUM_MUTED',  '#64748b');   /* labels, units, secondary          */
define('SUM_FAINT',  '#94a3b8');   /* the quietest text there is        */
define('SUM_GHOST',  '#cbd5e1');   /* a dash, an empty box              */
define('SUM_LINE',   '#e2e8f0');   /* borders                           */
define('SUM_HAIR',   '#f1f5f9');   /* the line between two rows         */
define('SUM_WASH',   '#f8fafc');   /* a panel, a table head             */
define('SUM_BLUE',   '#2563eb');
define('SUM_RED',    '#dc2626');
define('SUM_GREEN',  '#059669');   /* a change that is good news        */
define('SUM_AMBER',  '#b45309');
define('SUM_TINT',   '#fef2f2');   /* the wash behind a row with something */

/* ---------- plumbing ------------------------------------------------------ */

/** Command: print a line of the run report — the only output this file has,
 *  read in a browser by whoever triggered it and captured in the cron log
 *  otherwise. */
function summaryLog($line)
{
    echo htmlspecialchars((string)$line, ENT_QUOTES, 'UTF-8') . "\n";
    if (function_exists('ob_flush')) { @ob_flush(); }
    @flush();
}

/** Command: stop, having said why. */
function summaryStop($line, $status = 200)
{
    http_response_code($status);
    summaryLog($line);
    echo "</pre></body></html>\n";
    exit;
}

/** Query: the URL of the folder this file is in, which is where the four APIs
 *  sit. Worked out from the request so the script needs no configuring. */
function summaryOwnBase()
{
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'globportal.com';
    $dir    = rtrim(str_replace('\\', '/', dirname(
        isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '')), '/');
    return $scheme . '://' . $host . $dir;
}

/**
 * Query: one of the four APIs, decoded, or null when it could not be read.
 *
 * NEVER THROWS, deliberately: one dead API should cost the mail one column,
 * not the whole morning. The caller decides what a null means — see the run,
 * where a missing attendance read is fatal (it carries the placement the whole
 * table is built from) and a missing leave read is not.
 */
function summaryApi($file, array $params)
{
    $base = SUMMARY_API_BASE !== '' ? rtrim(SUMMARY_API_BASE, '/') : summaryOwnBase();
    $url  = $base . '/' . $file . '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => SUMMARY_HTTP_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => true,
        /* The call is to this same host. Going out to the public name and back
         * in is what makes it the SAME answer the browser gets. */
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'staffSummaryCron',
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code !== 200) {
        error_log('staffSummaryCron ' . $file . ': HTTP ' . $code . ' ' . $err);
        summaryLog('  ! ' . $file . ' did not answer (HTTP ' . $code
                 . ($err !== '' ? ', ' . $err : '') . ')');
        return null;
    }
    $json = json_decode((string)$body, true);
    if (!is_array($json) || empty($json['success'])) {
        $why = is_array($json) && isset($json['message']) ? $json['message'] : 'no success flag';
        error_log('staffSummaryCron ' . $file . ': ' . $why);
        summaryLog('  ! ' . $file . ' refused: ' . $why);
        return null;
    }
    return $json;
}

/* summaryKey(), summaryDay(), summaryEsc(), summaryAttCell(), summaryLeaveCells(),
 * summaryNum() and summaryRowFlags() come from staffSummaryShared.php, which
 * the acknowledgement page reads too. */

/* ---------- who the mail is about ----------------------------------------- */

/**
 * Query: every person in the company, keyed by person, carrying the placement
 * the table is grouped by.
 *
 * THE PLACEMENT COMES FROM attendanceAPI.php AND NOWHERE ELSE. It answers for
 * the whole company in one call and prints hod_person, hod_unit, am_person and
 * am_unit on every row — the nearest department head above somebody and the
 * nearest sub-department head. Each person has exactly one of each, so a table
 * built from them covers a manager's department once and never twice, which is
 * what the branches in staffPoolAPI.php would do.
 *
 * TODAY'S READ WINS for anybody in both days: somebody who moved department
 * yesterday is listed where they are now, not where they were.
 */
function summaryPeople(array $attendanceDays)
{
    $out = array();
    foreach ($attendanceDays as $attendance) {
        if ($attendance === null) { continue; }
        foreach (isset($attendance['staff']) ? $attendance['staff'] : array() as $row) {
            $key = summaryKey(isset($row['person']) ? $row['person'] : '');
            if ($key === '' || isset($out[$key])) { continue; }

            /* THE POOL IS THE HEAD COUNT, and only the pool. attendanceAPI.php
             * lists everybody it has attendance for, including the people an
             * override has taken OUT of the pool — it says so itself:
             * "attendance is a fact about a person, not about whether they are
             * counted". staffPoolAPI.php files those same people under
             * excluded_staff[] rather than staff[], so leaving them in here is
             * what put names on this mail that the staff tree does not list.
             * total_staff is 1 for somebody in the pool and 0 for somebody
             * out of it. */
            if (isset($row['total_staff']) && (int)$row['total_staff'] !== 1) {
                continue;
            }

            $out[$key] = array(
                'person'     => isset($row['person'])     ? $row['person']     : '',
                'fullname'   => isset($row['fullname'])   ? $row['fullname']   : '',
                'position'   => isset($row['position'])   ? $row['position']   : '',
                'staff_type' => isset($row['staff_type']) ? $row['staff_type'] : 'staff',
                'hod_person' => summaryKey(isset($row['hod_person']) ? $row['hod_person'] : ''),
                'hod_unit'   => isset($row['hod_unit'])   ? $row['hod_unit']   : '',
                'am_person'  => summaryKey(isset($row['am_person'])  ? $row['am_person']  : ''),
                'am_unit'    => isset($row['am_unit'])    ? $row['am_unit']    : '',
            );
        }
    }
    return $out;
}

/** Query: the people this mail goes to — every HOD and every Area Manager,
 *  each ONCE, with what they may see attached.
 *
 *  ONCE IS THE WORD. Somebody who heads two departments is in hod[] twice, and
 *  somebody who is both an HOD and an Area Manager is in both lists; mailing
 *  them per entry would send the same person three overlapping tables.
 *
 *  THE GRANTS ARE READ, NOT REPEATED — access[] is departmentAccess() as the
 *  API publishes it, with each department's sub-departments collected beside
 *  it, because access to a department is access to what is inside it. */
function summaryRecipients(array $tree)
{
    $sections = isset($tree['staff_tree']) ? $tree['staff_tree'] : $tree;
    $out      = array();

    foreach (array('hod', 'am') as $section) {
        foreach (isset($sections[$section]) ? $sections[$section] : array() as $entry) {
            $key = summaryKey(isset($entry['person']) ? $entry['person'] : '');
            if ($key === '') { continue; }
            if (!isset($out[$key])) { $out[$key] = summaryBlankRecipient($entry); }
            $out[$key]['roles'][$section] = true;
            if (!empty($entry['department_name'])) {
                $out[$key]['units'][$entry['department_name']] = true;
            }
            if ($out[$key]['email'] === '' && !empty($entry['email'])) {
                $out[$key]['email'] = trim((string)$entry['email']);
            }
        }
    }

    $access = isset($sections['access']) ? $sections['access']
            : (isset($tree['access']) ? $tree['access'] : array());

    foreach ($access as $grant) {
        $key = summaryKey(isset($grant['person']) ? $grant['person'] : '');
        if ($key === '') { continue; }
        if (!isset($out[$key])) { $out[$key] = summaryBlankRecipient($grant); }
        $out[$key]['roles']['grant'] = true;

        if (isset($grant['scope']) && $grant['scope'] === 'all') {
            $out[$key]['see_all'] = true;
        }
        foreach (isset($grant['departments']) ? $grant['departments'] : array() as $dept) {
            if (!empty($dept['department_name'])) {
                $out[$key]['depts'][strtolower($dept['department_name'])] = true;
                $out[$key]['units'][$dept['department_name']] = true;
            }
            foreach (isset($dept['sub_departments']) ? $dept['sub_departments'] : array() as $sub) {
                if (!empty($sub['department_name'])) {
                    $out[$key]['depts'][strtolower($sub['department_name'])] = true;
                }
            }
        }
    }

    foreach ($out as $key => $r) {
        $roles = array();
        if (isset($r['roles']['hod']))   { $roles[] = 'hod'; }
        if (isset($r['roles']['am']))    { $roles[] = 'am'; }
        if (isset($r['roles']['grant'])) { $roles[] = 'grant'; }
        $out[$key]['role'] = implode('+', $roles);
    }
    return $out;
}

/**
 * Query: what each unit IS — 'hod' for a department, 'am' for a district.
 *
 * WHICH LIST IT CAME FROM IS THE ANSWER, and the only one available: nothing
 * on a unit itself says whether it is a department or a district. A unit in
 * hod[] is a Department run by a Department Head; one in am[] is a District
 * run by an Area Manager, and those read very differently to the person
 * receiving the mail.
 *
 * hod[] IS READ SECOND AND WINS, for the handful of units that appear in both:
 * being a department is the stronger statement, and a department with a
 * district inside it is still a department.
 */
function summaryUnitKinds(array $tree)
{
    $sections = isset($tree['staff_tree']) ? $tree['staff_tree'] : $tree;
    $out      = array();
    foreach (array('am', 'hod') as $section) {
        foreach (isset($sections[$section]) ? $sections[$section] : array() as $entry) {
            if (!empty($entry['department_name'])) {
                $out[$entry['department_name']] = $section;
            }
        }
    }
    return $out;
}

/**
 * Query: unit name => the person who heads it, FROM THE TREE.
 *
 * NOT WORKED OUT FROM THE ROWS, and that is the fix for a wrong name rather
 * than a tidy-up. Reading it off the staff rows means asking "who is the head
 * above the people in this unit", and a department head's OWN row answers
 * with the head above THEM — so whichever row came first decided it, and
 * Fleet was headed by Jeffrey as often as by Rino.
 *
 * hod[] and am[] each say, for one unit, who its head is. There is nothing to
 * infer.
 */
function summaryUnitHeads(array $tree)
{
    $sections = isset($tree['staff_tree']) ? $tree['staff_tree'] : $tree;
    $out      = array();
    foreach (array('am', 'hod') as $section) {
        foreach (isset($sections[$section]) ? $sections[$section] : array() as $entry) {
            if (!empty($entry['department_name']) && !empty($entry['person'])) {
                $out[$entry['department_name']] = summaryKey($entry['person']);
            }
        }
    }
    return $out;
}

/** Query->display: what to call a unit and its head, as the mail labels them.
 *  Two words that have to move together, so they are chosen in one place. */
function summaryUnitWords($kind)
{
    return ($kind === 'am')
        ? array('unit' => 'District',  'head' => 'Area Manager')
        : array('unit' => 'Department', 'head' => 'Department Head');
}

/** Query: a recipient with nothing filled in but their name. One shape, built
 *  in one place, so the two loops above cannot disagree. */
function summaryBlankRecipient(array $entry)
{
    return array(
        'person'   => isset($entry['person'])   ? $entry['person']   : '',
        'fullname' => isset($entry['fullname']) ? $entry['fullname'] : '',
        'position' => isset($entry['position']) ? $entry['position'] : '',
        'email'    => isset($entry['email'])    ? trim((string)$entry['email']) : '',
        'roles'    => array(),
        'units'    => array(),
        'see_all'  => false,
        'depts'    => array(),
    );
}

/**
 * Query: is this person on this recipient's table?
 *
 * THE WHOLE VISIBILITY RULE, in one place. In order of how often it decides:
 *   1. a grant of 'all' sees everybody;
 *   2. the recipient themselves — THE HEAD IS ON THEIR OWN LIST, because the
 *      table is a department roster and a department includes the person who
 *      runs it;
 *   3. the person's own department head, or their own sub-department head;
 *   4. a granted department, matched on the unit NAME the person belongs to.
 */
function summaryCanSee(array $recipient, array $person, $recipientKey)
{
    if (!empty($recipient['see_all'])) {
        return true;
    }
    if (summaryKey($person['person']) === $recipientKey) {
        return true;
    }
    if ($person['hod_person'] !== '' && $person['hod_person'] === $recipientKey) {
        return true;
    }
    if ($person['am_person'] !== '' && $person['am_person'] === $recipientKey) {
        return true;
    }
    if (!empty($recipient['depts'])) {
        if ($person['hod_unit'] !== ''
            && isset($recipient['depts'][strtolower($person['hod_unit'])])) {
            return true;
        }
        if ($person['am_unit'] !== ''
            && isset($recipient['depts'][strtolower($person['am_unit'])])) {
            return true;
        }
    }
    return false;
}

/* ---------- the table ----------------------------------------------------- */

/**
 * Query: one row per person in the company — every column of the table, ready
 * to be filtered per recipient and rendered.
 *
 * BUILT ONCE FOR EVERYBODY, not once per recipient. The same person appears on
 * their HOD's table, their Area Manager's and Jeffrey's; working their row out
 * three times would be three chances to differ.
 *
 * $attByDay is date => the daily answer, NEWEST LAST so the columns read
 * yesterday-then-today, which is the order a person reads dates in.
 */
function summaryStaffRows(array $people, array $attByDay, $leave, $performance, $todayIso)
{
    /* The two month reads, indexed by person once rather than searched per
     * row — 670 people against 670 rows is a lot of linear scanning. */
    $leaveBy = array();
    if ($leave !== null) {
        foreach (isset($leave['staff']) ? $leave['staff'] : array() as $r) {
            $k = summaryKey(isset($r['person']) ? $r['person'] : '');
            if ($k !== '') { $leaveBy[$k] = $r; }
        }
    }

    /* days{} carries the leave CODE and leaves[] carries its name, so the
     * two are joined once for the whole company — see summaryLeaveTypeNames(). */
    $leaveTypeNames = summaryLeaveTypeNames($leave);

    /* THE LEAVE READ COVERS ONE MONTH, and the lateness columns can reach
     * back into the one before it — on the 1st, "yesterday" is last month.
     * A day outside the month that was read is simply not known about, so it
     * is treated as not-on-leave rather than guessed at. */
    $leaveMonth = substr($todayIso, 0, 7);
    $compBy = array();
    if ($performance !== null) {
        foreach (isset($performance['staff']) ? $performance['staff'] : array() as $r) {
            $k = summaryKey(isset($r['person']) ? $r['person'] : '');
            if ($k === '') { continue; }
            /* performance=demerit was asked for, so every record here is one.
             * records[] is already clipped to the month by the API — kept
             * whole, not counted, because the Compliance section lists them. */
            $recs = array();
            foreach (isset($r['records']) ? $r['records'] : array() as $rec) {
                $recs[] = array(
                    'date'  => isset($rec['date'])  ? (string)$rec['date']  : '',
                    'title' => isset($rec['title']) ? (string)$rec['title'] : '',
                    'type'  => isset($rec['type'])  ? (string)$rec['type']  : '',
                );
            }
            /* OLDEST FIRST. The API prints newest first, which is right for a
             * feed and wrong for a list somebody reads down. */
            usort($recs, function ($a, $b) { return strcmp($a['date'], $b['date']); });
            $compBy[$k] = $recs;
        }
    }

    /* attendanceAPI.php's rows, indexed the same way, per day. */
    $attBy = array();
    foreach ($attByDay as $dayIso => $answer) {
        $attBy[$dayIso] = array();
        if ($answer === null) { continue; }
        foreach (isset($answer['staff']) ? $answer['staff'] : array() as $r) {
            $k = summaryKey(isset($r['person']) ? $r['person'] : '');
            if ($k !== '') { $attBy[$dayIso][$k] = $r; }
        }
    }

    $todayDay = (int)substr($todayIso, 8, 2);
    $out      = array();

    foreach ($people as $key => $person) {
        $onLeaveDays = summaryLeaveDaySet(isset($leaveBy[$key]) ? $leaveBy[$key] : null,
                                         $leaveTypeNames);

        $att = array();
        foreach ($attByDay as $dayIso => $answer) {
            /* Was this person signed off on this day? Asked before the cell is
             * judged, because it changes what every answer in it means — see
             * $onLeave in summaryAttCell(). */
            $dayNo   = (int)substr((string)$dayIso, 8, 2);
            $onLeave = (substr((string)$dayIso, 0, 7) === $leaveMonth
                        && isset($onLeaveDays[$dayNo]))
                     ? $onLeaveDays[$dayNo] : false;

            /* null for a day that was never asked about — a Sunday, a public
             * holiday — which summaryAttCell() shows differently from somebody
             * who simply did not appear.
             *
             * The check-out is shown for a day that is OVER. Today's is not:
             * at 9:30am nobody has checked out, so the column would be a page
             * of red saying only what time the mail went. */
            $att[$dayIso] = ($answer === null)
                ? summaryAttCell(null)
                : summaryAttCell(
                    isset($attBy[$dayIso][$key]) ? $attBy[$dayIso][$key] : array(),
                    (string)$dayIso !== (string)$todayIso,
                    $onLeave);
        }

        $row = array(
            'person'   => $key,
            'fullname' => $person['fullname'] !== '' ? $person['fullname'] : $person['person'],
            'position' => $person['position'],
            'unit'     => $person['am_unit'] !== '' ? $person['am_unit'] : $person['hod_unit'],
            'att'      => $att,
            'leave'    => summaryLeaveCells(isset($leaveBy[$key]) ? $leaveBy[$key] : null, $todayDay),
            /* The applications themselves, listed under the figures in the
             * Excessive Leaves section — "5 days taken" says how much, and
             * these say what and when. */
            'leaves'   => summaryLeaveEntries(isset($leaveBy[$key]) ? $leaveBy[$key] : null,
                                              $todayIso),
            /* The month's leave per code, counted off the day map while it is
             * still in hand. The day map itself is not stored — it is big and
             * nothing renders it — but the EL / LWP mark needs HALF DAYS,
             * which only it knows about, and the acknowledgement page reads
             * this row hours later with no leaveAPI in reach. */
            'leave_by_code' => summaryLeaveCodeTotals(isset($leaveBy[$key]) ? $leaveBy[$key] : null),
            'comp_recs' => isset($compBy[$key]) ? $compBy[$key] : array(),
            'comp'     => isset($compBy[$key]) ? count($compBy[$key]) : 0,
        );
        $row['flags'] = summaryRowFlags($row);
        /* WHICH SECTIONS THIS PERSON APPEARS IN. Worked out once, here, so
         * the three tables and the counts beside them cannot disagree about
         * who belongs where. */
        $row['in_late']  = summaryHasLateness($row);
        $row['in_leave'] = ($row['leave']['total'] > 0);
        $row['in_comp']  = ($row['comp'] > 0);
        $out[$key]       = $row;
    }
    return $out;
}

/**
 * Query: this recipient's table, BROKEN INTO DEPARTMENTS — their own first,
 * then the others.
 *
 * WHY GROUPS AND NOT ONE LONG LIST. Most managers run one unit and will not
 * notice the difference. Jeffrey, Soo Chee Kiong and Rino see several, and a
 * single list sorted by name interleaves them — Fleet, Workshop, Fleet, Fleet,
 * Workshop — so there is no way to read one department's morning without
 * reading all of them. Grouped, each department is a block with its head named
 * above it, which is the thing the recipient is actually accountable for.
 *
 * THE UNIT IS THE NARROWEST ONE somebody belongs to: their sub-department when
 * they have one, their department otherwise. That is what makes the headings
 * read "BLR", "KDT", "IT" rather than the division above them, and it is the
 * same choice the unit column on each row makes.
 *
 * ORDER, and all three parts of it matter:
 *   groups  the recipient's own first — the unit they head — then the rest by
 *           name. Somebody who heads two is given both before anybody else's.
 *   rows    THE HEAD OF THAT UNIT FIRST, whatever their own morning looks
 *           like: the list is theirs, and a manager who was late is the first
 *           thing their own manager should see. Then the rows with something
 *           on them, then the quiet ones, names ordering each.
 */
function summaryGroupsFor(array $allRows, array $recipient, $recipientKey, array $people,
                          array $unitKinds, array $headOf)
{
    $groups = array();
    foreach ($allRows as $key => $row) {
        if (!isset($people[$key]) || !summaryCanSee($recipient, $people[$key], $recipientKey)) {
            continue;
        }
        /* WHICH HEADING THIS PERSON GOES UNDER, and the one rule that decides
         * it: the DEPARTMENT, unless this recipient is the area manager of
         * the district they are in.
         *
         * Somebody who can see several departments — Jeffrey, Soo Chee Kiong,
         * Rino — was getting a band per district, so one department arrived as
         * five or six headings with a handful of names under each and no way
         * to read it as a department at all. Grouped at department level it is
         * one band, which is the level their access is granted at.
         *
         * An Area Manager still gets their own district as the heading,
         * because that IS their unit and the department above it is somebody
         * else's.
         */
        $p    = $people[$key];
        $unit = ($p['am_unit'] !== '' && $p['am_person'] === $recipientKey)
              ? $p['am_unit']
              : ($p['hod_unit'] !== ''
                  ? $p['hod_unit']
                  : ($p['am_unit'] !== '' ? $p['am_unit'] : 'No department'));

        if (!isset($groups[$unit])) {
            $head = isset($headOf[$unit]) ? $headOf[$unit] : '';
            $kind  = isset($unitKinds[$unit]) ? $unitKinds[$unit] : 'hod';
            $words = summaryUnitWords($kind);
            $groups[$unit] = array(
                'unit'      => $unit,
                'kind'      => $kind,
                /* 'Department'/'Department Head' or 'District'/'Area Manager',
                 * carried on the group so the mail and the page label it the
                 * same way without either working it out again. */
                'unit_word' => $words['unit'],
                'head_word' => $words['head'],
                'head'      => $head,
                'head_name' => ($head !== '' && isset($people[$head]))
                    ? $people[$head]['fullname'] : '',
                /* Theirs when they are the head of it. That is what puts
                 * Rino's Fleet above Rino's view of anybody else. */
                'is_own'    => ($head !== '' && $head === $recipientKey),
                'rows'      => array(),
                'flagged'   => 0,
            );
        }
        $groups[$unit]['rows'][] = $row;
        if ($row['flags'] > 0) { $groups[$unit]['flagged']++; }
    }

    foreach ($groups as $unit => $g) {
        usort($groups[$unit]['rows'], function ($a, $b) use ($g) {
            $aHead = ($g['head'] !== '' && $a['person'] === $g['head']);
            $bHead = ($g['head'] !== '' && $b['person'] === $g['head']);
            if ($aHead !== $bHead)           { return $aHead ? -1 : 1; }
            if ($a['flags'] !== $b['flags']) { return $b['flags'] - $a['flags']; }
            return strcasecmp($a['fullname'], $b['fullname']);
        });
    }

    $out = array_values($groups);
    usort($out, function ($a, $b) {
        if ($a['is_own'] !== $b['is_own']) { return $a['is_own'] ? -1 : 1; }
        return strcasecmp($a['unit'], $b['unit']);
    });
    return $out;
}

/**
 * Query: the same groups with only the people at least one section is about —
 * anybody late or missing, on leave, or carrying compliance.
 *
 * A DEPARTMENT WITH NOBODY LEFT DISAPPEARS, which is the point: Lateness
 * should not name a unit that had none, and a recipient with nothing in any
 * of the three gets no mail at all.
 */
function summaryRelevantOnly(array $groups)
{
    $out = array();
    foreach ($groups as $g) {
        $keep = array();
        foreach ($g['rows'] as $r) {
            if (!empty($r['in_late']) || !empty($r['in_leave']) || !empty($r['in_comp'])) {
                $keep[] = $r;
            }
        }
        if (!empty($keep)) {
            $g['rows'] = $keep;
            $out[]     = $g;
        }
    }
    return $out;
}

/** Query: every row of a grouped table, flattened back out — for counting,
 *  for the snapshot's item keys, and for the row numbers that run straight
 *  through the table rather than restarting at each department. */
function summaryFlatten(array $groups)
{
    $out = array();
    foreach ($groups as $g) {
        foreach ($g['rows'] as $r) { $out[] = $r; }
    }
    return $out;
}

/**
 * Query: how many rows each section may print — array('late'=>n, 'leave'=>n,
 * 'comp'=>n), given how many it HAS.
 *
 * WHY THIS EXISTS. The cap used to be applied to the people list before the
 * sections split it, which meant the three sections shared whatever 35
 * people happened to sort first. For somebody who sees the whole company
 * that was a lottery: on 28 Oct 2026 Jeffrey's mail read "3. Compliance 0"
 * — not because nobody had any, but because nobody in the first 35 did, and
 * every person who did had already been cut. A section's figure has to be
 * the truth about that section, so the cap is now per section and the
 * heading counts BEFORE it.
 *
 * EVERY NON-EMPTY SECTION IS GUARANTEED $min ROWS, so none of them can be
 * squeezed to nothing by a long one above it. What is left of $budget is
 * then handed round the sections that still want more, a row at a time, so
 * a quiet section gives its unused share to a busy one instead of wasting
 * it. The budget is a whole-mail figure because what has to stay under
 * Gmail's clipping threshold is the message, not any one table.
 */
/** Query: how many people each section is about — the true figures, before
 *  any cap, which is what a heading must print and what the budget is shared
 *  out against. */
function summarySectionCounts(array $groups)
{
    $counts = array();
    foreach (summarySections() as $kind => $flag) {
        $n = 0;
        foreach ($groups as $g) {
            foreach (isset($g['rows']) ? $g['rows'] : array() as $r) {
                if (!empty($r[$flag])) { $n++; }
            }
        }
        $counts[$kind] = $n;
    }
    return $counts;
}

function summarySectionCaps(array $counts, $budget, $min)
{
    $caps = array();
    $left = (int)$budget;

    foreach ($counts as $kind => $n) {
        $caps[$kind] = 0;
        if ($n <= 0) { continue; }
        $caps[$kind] = min((int)$n, (int)$min);
        $left       -= $caps[$kind];
    }

    /* A row at a time round the sections that still want more, so the
     * remainder is shared rather than swallowed by whichever is listed
     * first. Stops when the budget runs out or nobody wants anything. */
    while ($left > 0) {
        $gave = false;
        foreach ($counts as $kind => $n) {
            if ($left <= 0) { break; }
            if ($caps[$kind] < $n) {
                $caps[$kind]++;
                $left--;
                $gave = true;
            }
        }
        if (!$gave) { break; }
    }
    return $caps;
}

/** Command: cut a grouped table down to $max rows in total, dropping whole
 *  rows from the end and reporting how many went. The groups keep their order
 *  — the recipient's own department is never the one that gets cut. */
function summaryCapGroups(array $groups, $max)
{
    $kept = 0;
    $out  = array();
    foreach ($groups as $g) {
        if ($kept >= $max) { break; }
        $room = $max - $kept;
        if (count($g['rows']) > $room) {
            $g['rows'] = array_slice($g['rows'], 0, $room);
        }
        $kept   += count($g['rows']);
        $out[]   = $g;
    }
    return $out;
}

/* ---------- the mail ------------------------------------------------------ */

/**
 * Query: the HTML of one person's mail — THREE SECTIONS, each its own table,
 * each listing only the people it is about.
 *
 * WHY NOT ONE TABLE ANY MORE. A single roster put every name in every column,
 * so a department of thirty showed thirty rows of dashes to say that
 * twenty-six of them were fine. Each section now answers one question about
 * the people it applies to and says nothing about anybody else — which is why
 * a person can appear in one section, three, or none at all.
 *
 * TABLES AND INLINE STYLE, which is not how the rest of this family is
 * written, because an HTML mail is not a web page: Outlook renders it with
 * Word, which supports neither flexbox nor a <style> block it can be relied on
 * to keep, and Gmail discards <style> on some clients too. Everything here is
 * 2002-era HTML on purpose — nested tables, width and bgcolor attributes
 * beside the CSS, and not one class name.
 */
function summaryHtml(array $recipient, array $groups, $todayIso, $ackUrl, array $dayCols,
                     array $modShown = null, array $modFetched = array(),
                     array $modCatalogue = array(), array $modBoxes = null)
{
    /* THE OLD SIGNATURE STILL WORKS. Called with five arguments this prints
     * the three original sections, all of them required — which is what every
     * caller wanted before the catalogue existed, and what a fallback morning
     * still wants. */
    if ($modShown === null)  { $modShown  = array('late', 'leave', 'comp'); }
    if ($modBoxes === null)  { $modBoxes  = $modShown; }
    $font = 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,Helvetica,sans-serif;';
    $rows = summaryFlatten($groups);
    $ym   = summaryMonthLabel($todayIso);

    /* The recipient's own units, which the header leads with, and the words
     * that go with them — Department/Department Head for an HOD,
     * District/Area Manager for an Area Manager. */
    $ownUnits = array();
    $words    = summaryUnitWords('hod');
    foreach ($groups as $g) {
        if ($g['is_own']) {
            $ownUnits[] = $g['unit'];
            $words      = array('unit' => $g['unit_word'], 'head' => $g['head_word']);
        }
    }
    if (empty($ownUnits)) {
        $ownUnits = array_keys($recipient['units']);
        if (isset($recipient['roles']['am']) && !isset($recipient['roles']['hod'])) {
            $words = summaryUnitWords('am');
        }
    }

    $h = '<!DOCTYPE html><html><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Daily Staff Summary</title></head>'
       . '<body style="margin:0;padding:0;background:' . SUM_HAIR . ';">'

       /* The grey line a phone shows under the subject. Hidden in the body
        * itself, which is the only way to control it. */
       . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">'
       . summaryEsc(count($rows) . ' staff on this list')
       . '</div>'

       /* THE FORM IS REAL, and so are the boxes inside it. See the note on
        * summaryTickHtml() for exactly which mail clients will honour it and
        * which will quietly drop the whole thing — the Acknowledge link at
        * the foot is there for the ones that do. GET and not POST because a
        * mail client that submits at all submits by opening a browser, and a
        * POST would arrive as a blank page nobody can bookmark or re-run. */
       . '<form method="get" action="' . summaryEsc(summaryAckBase($ackUrl)) . '">'
       . '<input type="hidden" name="t" value="' . summaryEsc(summaryAckToken($ackUrl)) . '">'

       . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
       . 'style="background:' . SUM_HAIR . ';padding:24px 8px;"><tr><td align="center">'
       . '<table role="presentation" width="1080" cellpadding="0" cellspacing="0" border="0" '
       . 'style="width:1080px;max-width:100%;background:#ffffff;border:1px solid ' . SUM_LINE . ';'
       . 'border-radius:14px;overflow:hidden;' . $font . 'color:' . SUM_BODY . ';">';

    /* ---- header ---- */
    $lbl = 'font-size:12px;color:' . SUM_MUTED . ';';
    $val = 'font-size:15px;font-weight:bold;color:' . SUM_INK . ';';

    /* CENTRED, TITLE AND ALL. align="center" on the cell for the clients that
     * only honour the attribute, margin:0 auto on the label table for the
     * ones that only honour the style — both, because this has to land the
     * same way in Gmail and in Outlook's Word renderer. */
    $h .= '<tr><td align="center" style="padding:22px 24px 18px;text-align:center;'
        . 'border-bottom:1px solid ' . SUM_LINE . ';">'
        . '<div style="font-size:24px;font-weight:bold;color:' . SUM_BLUE . ';'
        . 'line-height:1.25;">'
        . summaryEsc('Staff Summary ' . $todayIso) . '</div>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" '
        . 'align="center" style="margin:12px auto 0;"><tr>'
        . '<td align="right" style="' . $lbl . 'padding:0 8px 2px 0;white-space:nowrap;">'
        . summaryEsc($words['unit']) . ':</td>'
        . '<td align="left" style="' . $val . 'padding:0 0 2px;">'
        . summaryEsc(!empty($ownUnits) ? implode(' · ', array_slice($ownUnits, 0, 3)) : '—')
        . '</td></tr><tr>'
        . '<td align="right" style="' . $lbl . 'padding:0 8px 0 0;white-space:nowrap;">'
        . summaryEsc($words['head']) . ':</td>'
        . '<td align="left" style="' . $val . '">'
        . summaryEsc($recipient['fullname'] !== '' ? $recipient['fullname'] : $recipient['person'])
        . '</td></tr></table></td></tr>';

    /* ---- the sections this person is shown ----
     *
     * THE CATALOGUE DECIDES, NOT THIS FILE. $modShown is what
     * staffSummaryModuleReport.php has them ticked for, already in display
     * order; the numbers are 1, 2, 3… off that list, so a person without RO1
     * Work Achievement reads 1-3 and a person with it reads 1-4, rather than
     * the fourth sliding into a gap.
     *
     * A ROW BUDGET SHARED BETWEEN THE PEOPLE-LISTING SECTIONS, not a cap on
     * any one list. Each counts its own people first, then prints as many as
     * its share allows and says how many it left out. A section this person is
     * NOT shown is zeroed before the share-out, so it cannot hold budget the
     * other sections could have used. See summarySectionCaps().
     *
     * A FETCHED SECTION IS NOT IN THE BUDGET AT ALL. It is a handful of
     * district rows, not a department, so it cannot be the thing that pushes a
     * mail past Gmail's clipping threshold. */
    $counts = summarySectionCounts($groups);
    foreach ($counts as $kind => $n) {
        if (!in_array($kind, $modShown, true)) { $counts[$kind] = 0; }
    }
    $caps = summarySectionCaps($counts, SUMMARY_MAIL_MAX_ROWS, SUMMARY_MAIL_MIN_ROWS);

    $no = 0;
    foreach ($modShown as $kind) {
        $no++;
        $mod = isset($modCatalogue[$kind]) ? $modCatalogue[$kind] : array();

        /* THE HEADING AND THE EMPTY LINE COME FROM THE TABLE, with the words
         * that were hard-coded here as the fallback — so a morning before the
         * DDL is run reads exactly as it always did. The month is appended to
         * everything except Lateness, which is about today and not the month. */
        $title = (isset($mod['title']) && $mod['title'] !== '')
            ? $mod['title'] : summaryFallbackTitle($kind);
        $empty = (isset($mod['empty_line']) && $mod['empty_line'] !== '')
            ? $mod['empty_line'] : summaryFallbackEmpty($kind);
        $heading = ($kind === 'late') ? $title : $title . ' (' . $ym . ')';

        /* THE BOX IS PRINTED ONLY WHERE THERE IS ONE. $modBoxes already says
         * which sections are allowed, have content AND are must-acknowledge.
         * An EMPTY section never had a box — summarySectionHtml() returns
         * before the box when its total is 0, and the server has never counted
         * one. What is new is the third test: a section somebody is shown for
         * information only prints in full and ends without a box, which is the
         * whole of what "must acknowledge: no" means to a reader. */
        $withBox = in_array($kind, $modBoxes, true);

        if (empty($mod) || !empty($mod['built_in'])) {
            $h .= summarySectionHtml((string)$no, $heading, $kind, $groups, $dayCols, $ym,
                                     $empty,
                                     isset($caps[$kind]) ? $caps[$kind] : 0,
                                     $ackUrl, $withBox);
            continue;
        }

        $h .= summaryModuleSectionHtml((string)$no, $heading, $kind, $mod, $empty,
                                       isset($modFetched[$kind]) ? $modFetched[$kind] : null,
                                       $withBox);
    }

    /* ---- the way back ---- */
    $h .= '<tr><td align="center" style="padding:20px 24px 24px;">'
        /* The submit button, for a client that kept the form. It is an
         * <input> and not a styled <a> so that the tick boxes above actually
         * go somewhere; the link beneath it is what the rest get. */
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" '
        . 'align="center" style="margin:0 auto;"><tr><td align="center" '
        . 'bgcolor="' . SUM_BLUE . '" style="border-radius:12px;">'
        . '<input type="submit" value="Acknowledge" '
        . 'style="' . $font . 'font-size:28px;font-weight:bold;color:#ffffff;'
        . 'background:' . SUM_BLUE . ';border:0;border-radius:12px;padding:24px 72px;'
        . 'cursor:pointer;">'
        . '</td></tr></table>'
        /* NOTHING UNDER THE BUTTON ANY MORE. It used to carry "Acknowledge
         * records my name and the time for <date>", which told somebody what
         * the record keeps rather than what they are saying — and the tick
         * boxes above already say that, in the first person. The record is
         * unchanged: the name and the time are still written, and the event
         * log still has the platform and the address. */
        . '<div style="padding-top:12px;">'
        . '<a href="' . summaryEsc($ackUrl) . '" style="font-size:13px;font-weight:bold;'
        . 'color:' . SUM_BLUE . ';text-decoration:underline;">'
        . 'Open the list to tick and acknowledge</a></div>'
        . '</td></tr>';

    $h .= '<tr><td style="padding:14px 24px 18px;background:' . SUM_WASH . ';'
        . 'border-top:1px solid ' . SUM_LINE . ';">'
        . '<div style="font-size:11px;color:' . SUM_FAINT . ';line-height:1.6;">'
        . 'Sent automatically every day except Sundays and public holidays.'
        . '</div></td></tr>';

    $h .= '</table></td></tr></table></form></body></html>';
    return $h;
}

/** Query: the acknowledgement URL with its query string stripped off — the
 *  action a GET form needs, since the token goes in as a hidden field and a
 *  form would otherwise drop everything after the '?'. */
function summaryAckBase($ackUrl)
{
    $q = strpos($ackUrl, '?');
    return ($q === false) ? $ackUrl : substr($ackUrl, 0, $q);
}

/** Query: just the token out of that URL. */
function summaryAckToken($ackUrl)
{
    $q = strpos($ackUrl, '?');
    if ($q === false) { return ''; }
    parse_str(substr($ackUrl, $q + 1), $args);
    return isset($args['t']) ? $args['t'] : '';
}

/**
 * Query: one section — its heading, and a table of only the people it is
 * about, still grouped by department.
 *
 * $kind is 'late', 'leave' or 'comp', and picks both the filter and the
 * columns. The three are one function because the frame around them is
 * identical and the differences are two lines each; three near-copies would
 * drift the first time somebody adjusted a padding.
 */
function summarySectionHtml($no, $heading, $kind, array $groups, array $dayCols, $ym,
                           $emptyLine, $cap = 0, $ackUrl = '', $withBox = true)
{
    $flag = ($kind === 'late') ? 'in_late' : (($kind === 'leave') ? 'in_leave' : 'in_comp');

    /* Only the groups that have anybody in this section, and only those
     * people. A department with nothing late is simply not named under
     * Lateness. */
    $shown = array();
    $total = 0;
    foreach ($groups as $g) {
        $keep = array();
        foreach ($g['rows'] as $r) {
            if (!empty($r[$flag])) { $keep[] = $r; }
        }
        if (!empty($keep)) {
            $g['rows'] = $keep;
            $shown[]   = $g;
            $total    += count($keep);
        }
    }

    /* THE HEADING COUNTS BEFORE THE CAP. $total is how many people this
     * section is about; $cap is only how many of them fit in a mail. The
     * two were once the same number, and the figure lied whenever they
     * were not — see summarySectionCaps(). */
    $hidden = 0;
    if ($cap > 0 && $total > $cap) {
        $shown  = summaryCapGroups($shown, $cap);
        $hidden = $total - count(summaryFlatten($shown));
    }

    /* THE HEADING, LOUD ENOUGH TO SPLIT THE MAIL INTO THREE. The old one was
     * 15px over a hairline and read as another table caption; three of those
     * down a long mail left no sense of where one section ended and the next
     * began. The number is a filled blue square — the one thing on the page
     * that cannot be mistaken for a row — and the rule under it is the
     * section's own colour, not the table's grey. */
    $h = '<tr><td style="padding:30px 24px 0;">'
       . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" '
       . 'width="100%" style="border-bottom:3px solid ' . SUM_BLUE . ';">'
       . '<tr>'
       . '<td width="40" valign="middle" style="width:40px;padding:0 12px 11px 0;">'
       . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" '
       . 'width="34" style="width:34px;"><tr>'
       . '<td align="center" height="34" bgcolor="' . SUM_BLUE . '" '
       . 'style="height:34px;border-radius:8px;background:' . SUM_BLUE . ';'
       . 'font-size:19px;font-weight:bold;color:#ffffff;line-height:34px;">'
       . summaryEsc($no) . '</td></tr></table></td>'
       . '<td valign="middle" style="padding:0 0 11px;font-size:22px;font-weight:bold;'
       . 'color:' . SUM_INK . ';line-height:1.25;">'
       . summaryEsc($heading) . '</td>'
       /* The figure on the right, where the eye lands after the title, and
        * big enough to be read as the section's count rather than a footnote
        * to it. It is the TRUE total — see above. */
       . '<td align="right" valign="middle" style="padding:0 0 11px;font-size:22px;'
       . 'font-weight:bold;color:' . SUM_BLUE . ';white-space:nowrap;">'
       . $total . '</td>'
       . '</tr></table>';

    if ($total === 0) {
        return $h . '<div style="font-size:12.5px;color:' . SUM_FAINT . ';padding:12px 0 2px;">'
             . summaryEsc($emptyLine) . '</div></td></tr>';
    }

    $cols = ($kind === 'late') ? 2 + count($dayCols) : (($kind === 'leave') ? 4 : 3);

    $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
        . 'style="margin-top:12px;border:1px solid ' . SUM_LINE . ';border-radius:10px;'
        . 'border-collapse:separate;border-spacing:0;font-size:12px;">'
        . summarySectionHeadHtml($kind, $dayCols);

    $n = 0;
    foreach ($shown as $g) {
        $h .= summaryGroupHtml($g, $cols);
        foreach ($g['rows'] as $row) {
            $h .= summarySectionRowHtml($row, $kind, $dayCols, ++$n, $ym);
        }
    }
    $h .= '</table>';

    /* WHAT IS NOT IN THE MAIL, SAID UNDER THE TABLE IT BELONGS TO. One
     * notice at the foot of the mail could not say which section it was
     * about, and sat under Compliance reading as if that section had 515
     * more — while its own heading said 0. */
    if ($hidden > 0) {
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            . 'border="0" style="margin-top:8px;"><tr><td align="center" '
            . 'style="padding:10px 12px;font-size:15px;font-weight:bold;'
            . 'text-align:center;">'
            . '<a href="' . summaryEsc($ackUrl) . '" style="color:' . SUM_BLUE . ';'
            . 'text-decoration:underline;">'
            . '+ ' . (int)$hidden . ' more in this section &mdash; open the full list</a>'
            . '</td></tr></table>';
    }

    /* NO BOX WHERE THIS PERSON IS NOT HELD TO THE SECTION. $withBox is
     * dsModBoxKeys()'s answer, threaded in rather than re-derived, so the
     * mail, the acknowledgement page and the API cannot end up disagreeing
     * about which boxes exist. An empty section has already returned above. */
    return $h . ($withBox ? summaryConfirmHtml($kind) : '') . '</td></tr>';
}

/** Query: the heading a built-in section had before the catalogue existed.
 *  Used only when the module tables are missing, so a fallback morning reads
 *  exactly as last week's did. */
function summaryFallbackTitle($kind)
{
    $t = array('late' => 'Lateness', 'leave' => 'Excessive Leaves',
               'comp' => 'Compliance');
    return isset($t[$kind]) ? $t[$kind] : $kind;
}

/** Query: the empty line a built-in section had before the catalogue existed. */
function summaryFallbackEmpty($kind)
{
    $t = array('late' => 'Nobody was late or missing a check-in.',
               'leave' => 'No leave taken or booked this month.',
               'comp'  => 'No compliance recorded this month.');
    return isset($t[$kind]) ? $t[$kind] : 'Nothing to show.';
}

/**
 * Command: one FETCHED section — a module whose figures came from an endpoint
 * rather than from the snapshot this cron builds.
 *
 * NOT A LIST OF PEOPLE, WHICH IS WHY IT IS NOT summarySectionHtml(). The three
 * built-in sections all answer "which of your staff", and share a shape: a
 * department, then names, then day columns. RO1 Work Achievement answers
 * "which of your districts", and its rows are places with figures against
 * them. Forcing it through the people renderer would have meant inventing a
 * fake person per district.
 *
 * IT DRAWS WHATEVER THE ENDPOINT DESCRIBED. columns[] carries the key, label,
 * format and decimals for each column and rows[] carries a cell per key, so
 * this file holds no knowledge of what RO1 measures — a fifth column added at
 * the endpoint appears here with no change on this side. That is the whole
 * point of the contract: the module owns its figures, the cron owns the
 * envelope.
 *
 * A FAILED FETCH SAYS SO, in the section, in plain words. Printing nothing
 * would read as a quiet month, which is the one thing a manager must not
 * conclude from an endpoint that was down.
 *
 * THE CHANGE CHIP IS COLOURED BY DIRECTION AND NOT BY GOODNESS. Up is blue,
 * down is grey, and neither is red or green — because "work done up" is good
 * and "outstanding potholes up" is not, and this renderer does not know which
 * column it is drawing. The endpoint could say one day; until it does, a
 * neutral chip is honest and a guessed colour would not be.
 */
function summaryModuleSectionHtml($no, $heading, $kind, array $mod, $emptyLine,
                                  $fetched, $withBox)
{
    $ok   = is_array($fetched) && !empty($fetched['ok']) && is_array($fetched['data']);
    $data = $ok ? $fetched['data'] : array();
    $rows = ($ok && isset($data['rows']) && is_array($data['rows'])) ? $data['rows'] : array();
    $cols = ($ok && isset($data['columns']) && is_array($data['columns']))
        ? $data['columns'] : array();

    /* The heading figure is how many ROWS the section is about — districts
     * here — which is the same promise the people sections make with their
     * own count. */
    $h = '<tr><td style="padding:30px 24px 0;">'
       . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" '
       . 'width="100%" style="border-bottom:3px solid ' . SUM_BLUE . ';">'
       . '<tr>'
       . '<td width="40" valign="middle" style="width:40px;padding:0 12px 11px 0;">'
       . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" '
       . 'width="34" style="width:34px;"><tr>'
       . '<td align="center" height="34" bgcolor="' . SUM_BLUE . '" '
       . 'style="height:34px;border-radius:8px;background:' . SUM_BLUE . ';'
       . 'font-size:19px;font-weight:bold;color:#ffffff;line-height:34px;">'
       . summaryEsc($no) . '</td></tr></table></td>'
       . '<td valign="middle" style="padding:0 0 11px;font-size:22px;font-weight:bold;'
       . 'color:' . SUM_INK . ';line-height:1.25;">'
       . summaryEsc($heading) . '</td>'
       . '<td align="right" valign="middle" style="padding:0 0 11px;font-size:22px;'
       . 'font-weight:bold;color:' . SUM_BLUE . ';white-space:nowrap;">'
       . count($rows) . '</td>'
       . '</tr></table>';

    if (!$ok) {
        return $h . '<div style="font-size:12.5px;color:' . SUM_FAINT . ';padding:12px 0 2px;">'
             . summaryEsc('These figures could not be read this morning'
                 . (is_array($fetched) && $fetched['error'] !== ''
                        ? ' (' . $fetched['error'] . ')' : '')
                 . '. Nothing is being asked of you about them.')
             . '</div></td></tr>';
    }

    if (empty($rows) || empty($cols)) {
        return $h . '<div style="font-size:12.5px;color:' . SUM_FAINT . ';padding:12px 0 2px;">'
             . summaryEsc(isset($data['empty_reason']) && $data['empty_reason'] !== ''
                   ? $data['empty_reason'] : $emptyLine)
             . '</div></td></tr>';
    }

    $th = 'padding:9px 10px;font-size:11px;font-weight:bold;letter-spacing:.04em;'
        . 'text-transform:uppercase;color:' . SUM_MUTED . ';background:' . SUM_WASH . ';'
        . 'border-bottom:1px solid ' . SUM_LINE . ';';

    $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
        . 'style="margin-top:12px;border:1px solid ' . SUM_LINE . ';border-radius:10px;'
        . 'border-collapse:separate;border-spacing:0;font-size:12px;">'
        . '<tr><td align="left" bgcolor="' . SUM_WASH . '" style="' . $th . '">'
        . summaryEsc(isset($data['row_label']) ? $data['row_label'] : 'Districts')
        . '</td>';
    foreach ($cols as $c) {
        $h .= '<td align="right" bgcolor="' . SUM_WASH . '" style="' . $th . '">'
            . summaryEsc(isset($c['label']) ? $c['label'] : $c['key']) . '</td>';
    }
    $h .= '</tr>';

    /* The total rides in its own field rather than as a last row, so it can be
     * drawn as a total — heavier, ruled off — instead of looking like one more
     * district. A module with nothing to total simply omits it. */
    $all = $rows;
    if (isset($data['total']) && is_array($data['total'])) {
        $all[] = $data['total'] + array('_is_total' => true);
    }

    foreach ($all as $r) {
        $isTotal = !empty($r['_is_total']);
        $td = 'padding:10px;border-top:1px solid ' . SUM_LINE . ';'
            . ($isTotal ? 'font-weight:bold;background:' . SUM_WASH . ';' : '');

        $h .= '<tr><td align="left" style="' . $td . 'color:' . SUM_INK . ';">'
            . summaryEsc(isset($r['district']) ? $r['district'] : '') . '</td>';
        foreach ($cols as $c) {
            $key  = isset($c['key']) ? $c['key'] : '';
            $cell = (isset($r[$key]) && is_array($r[$key])) ? $r[$key] : null;
            $h   .= '<td align="right" style="' . $td . 'color:' . SUM_BODY . ';'
                  . 'white-space:nowrap;">'
                  . summaryModuleCellHtml($cell, $c) . '</td>';
        }
        $h .= '</tr>';
    }
    $h .= '</table>';

    /* WHAT THE PERIOD AND THE COMPARISON WERE, under the table they describe.
     * A figure labelled only "Work done" invites the reader to assume it is
     * today's; saying "October 2026 (1 to 30), vs 29 Oct" costs one line and
     * removes the assumption. */
    $note = array();
    if (isset($data['period']['label']))  { $note[] = $data['period']['label']; }
    if (isset($data['compare']['label'])) { $note[] = $data['compare']['label']; }
    if (!empty($note)) {
        $h .= '<div style="font-size:11.5px;color:' . SUM_FAINT . ';padding:8px 2px 0;">'
            . summaryEsc(implode(' · ', $note)) . '</div>';
    }

    return $h . ($withBox ? summaryConfirmHtml($kind) : '') . '</td></tr>';
}

/**
 * Query: one figure in a fetched module's table — the value at the precision
 * the endpoint asked for, and its change as a chip.
 *
 * A NULL CHANGE PRINTS NOTHING AND A ZERO CHANGE PRINTS A DASH. They are
 * different facts: null is "there was nothing to compare this against" (the
 * first of the month), zero is "measured, and it did not move". Flattening
 * them into one would make a quiet day and a first day look identical.
 */
function summaryModuleCellHtml($cell, array $col)
{
    if (!is_array($cell) || !array_key_exists('value', $cell)) {
        return summaryEsc(SUM_DASH);
    }

    $dp  = isset($col['decimals']) ? (int)$col['decimals'] : 0;
    $out = number_format((float)$cell['value'], $dp);
    if (isset($col['format']) && $col['format'] === 'percent') { $out .= '%'; }
    if (!empty($col['unit'])) { $out .= $col['unit']; }
    $out = summaryEsc($out);

    $d = array_key_exists('delta', $cell) ? $cell['delta'] : null;
    if ($d === null) { return $out; }

    /* AT THE PRINTED PRECISION. A change of 0.004 shown to 0 dp is '+0',
     * which contradicts the figure beside it; rounded first it is a dash. */
    $r = round((float)$d, $dp);
    if (abs($r) < 0.0000001) {
        return $out . ' <span style="font-size:11px;color:' . SUM_FAINT . ';">'
             . summaryEsc(SUM_DASH) . '</span>';
    }

    return $out . ' <span style="font-size:11px;font-weight:bold;color:'
         . summaryModuleChipColour($col, $r > 0) . ';">'
         /* NO UNIT ON THE CHANGE — the figure it follows carries one, and
          * '91.73% −0.64%' says '%' twice. +/− rather than the ▲/▼ the app
          * and the push use: this is read on a desktop beside a table, where
          * a sign is the ordinary notation and has room to be one. */
         . summaryEsc(($r > 0 ? '+' : '−') . number_format(abs($r), $dp))
         . '</span>';
}

/**
 * Query: the colour a change is printed in.
 *
 * BY MEANING, NOW THAT THE ENDPOINT SAYS WHAT ITS FIGURES MEAN. higher_is is
 * 'better' for work done and 'worse' for outstanding potholes, so a rise is
 * green on one and red on the other — which is the whole point of colouring
 * it. Before the field existed every rise had to be the same neutral blue,
 * because green or red would have been a judgement nobody had made.
 *
 * A COLUMN THAT SAYS NOTHING KEEPS THE NEUTRAL TREATMENT, so an endpoint
 * written before higher_is existed is not quietly given an opinion.
 */
function summaryModuleChipColour(array $col, $up)
{
    $sense = isset($col['higher_is']) ? $col['higher_is'] : '';
    if ($sense === 'better') { return $up ? SUM_GREEN : SUM_RED; }
    if ($sense === 'worse')  { return $up ? SUM_RED   : SUM_GREEN; }
    return $up ? SUM_BLUE : SUM_MUTED;
}

/**
 * Query: the one tick box that closes a section — the box, then the sentence
 * it stands for.
 *
 * ONE A SECTION, UNDER THE TABLE IT IS ABOUT. summaryItemKeys() has the
 * reasoning; summaryConfirmLine() has the words, shared with the page so
 * both record the same undertaking.
 *
 * Whether the box appears at all is the mail client's decision, not this
 * file's — see the note on summaryTickHtml(). The sentence is printed either
 * way, so a Gmail reader still sees what they will be agreeing to when they
 * follow the link.
 */
function summaryConfirmHtml($kind)
{
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
         . 'border="0" style="margin-top:10px;border:1px solid ' . SUM_LINE . ';'
         . 'border-radius:10px;background:' . SUM_WASH . ';border-collapse:separate;">'
         . '<tr>'
         . '<td width="78" align="center" valign="middle" style="width:78px;padding:26px 0 26px 22px;">'
         . summaryTickHtml($kind)
         . '</td>'
         . '<td align="left" valign="middle" style="padding:26px 24px 26px 10px;font-size:25px;'
         . 'font-weight:bold;color:' . SUM_BLUE . ';line-height:1.4;">'
         . summaryEsc(summaryConfirmLine($kind))
         . '</td></tr></table>';
}

/** Query: the header row for one section's table. */
function summarySectionHeadHtml($kind, array $dayCols)
{
    $th = 'padding:9px 6px;font-size:11px;font-weight:bold;letter-spacing:.04em;'
        . 'text-transform:uppercase;color:' . SUM_MUTED . ';background:' . SUM_WASH . ';'
        . 'border-bottom:1px solid ' . SUM_LINE . ';';
    $sub = 'text-transform:none;font-size:12.5px;color:' . SUM_BODY . ';';

    $h = '<tr><td width="44" align="center" bgcolor="' . SUM_WASH . '" style="width:44px;'
       . $th . '">No</td>'
       . '<td align="left" bgcolor="' . SUM_WASH . '" style="' . $th . '">Staff</td>';

    if ($kind === 'late') {
        foreach ($dayCols as $col) {
            $h .= '<td width="130" align="center" bgcolor="' . SUM_WASH . '" style="width:130px;'
                . $th . 'border-left:1px solid ' . SUM_LINE . ';' . $sub . '">'
                . summaryEsc($col['label']) . '</td>';
        }
    } elseif ($kind === 'leave') {
        foreach (array(array('Taken', 230), array('Upcoming', 230)) as $c) {
            $h .= '<td width="' . $c[1] . '" align="center" bgcolor="' . SUM_WASH . '" '
                . 'style="width:' . $c[1] . 'px;' . $th . 'border-left:1px solid ' . SUM_LINE . ';'
                . $sub . '">' . $c[0] . '</td>';
        }
    } else {
        $h .= '<td width="100" align="center" bgcolor="' . SUM_WASH . '" style="width:100px;'
            . $th . 'border-left:1px solid ' . SUM_LINE . ';' . $sub . '">Records</td>';
    }
    return $h . '</tr>';
}

/** Query: the band that opens a department inside a section — the unit, then
 *  who runs it. */
function summaryGroupHtml(array $g, $cols)
{
    return '<tr><td colspan="' . (int)$cols . '" bgcolor="' . SUM_WASH . '" '
         . 'style="padding:8px 10px;background:' . SUM_WASH . ';'
         . 'border-top:1px solid ' . SUM_LINE . ';border-bottom:1px solid ' . SUM_LINE . ';">'
         . '<span style="font-size:11px;color:' . SUM_MUTED . ';">'
         . summaryEsc($g['unit_word']) . ': </span>'
         . '<span style="font-size:14px;font-weight:bold;color:' . SUM_INK . ';">'
         . summaryEsc($g['unit']) . '</span>'
         . ($g['head_name'] !== ''
             ? '<span style="font-size:11px;color:' . SUM_MUTED . ';"> &nbsp;&nbsp;'
               . summaryEsc($g['head_word']) . ': </span>'
               . '<span style="font-size:13px;color:' . SUM_BODY . ';">'
               . summaryEsc($g['head_name']) . '</span>'
             : '')
         . '</td></tr>';
}

/** Query: one person's row in one section, plus the sub-row of entries that
 *  Leaves and Compliance list beneath the figures.
 *
 *  $ym is the month the section covers, and it is here for one reason: the
 *  Emergency Leave mark below. */
function summarySectionRowHtml(array $row, $kind, array $dayCols, $no, $ym = '')
{
    /* THREE DAYS OF EMERGENCY LEAVE — OR THREE OF LEAVE WITHOUT PAY — IN ONE
     * MONTH IS A PATTERN, and it is the one thing in this table a manager is
     * meant to act on rather than note. The mark rides beside the name — not
     * in a column — because it is a fact about the person and the columns are
     * about the month, and THE WHOLE ROW IS WASHED RED so it is found while
     * scrolling a section of eighty-six and not only while reading one.
     * summaryLeaveMarks() is the rule, shared with the page and the app so
     * all three flag the same people; ONE PILL A CODE, so somebody over on
     * both reads '3 EL  4 LWP' rather than one number standing for two
     * different things. */
    $elMarks = ($kind === 'leave' && $ym !== '') ? summaryLeaveMarks($row, $ym) : array();
    $elRow   = !empty($elMarks);

    $td = 'padding:9px 6px;border-top:1px solid ' . SUM_HAIR . ';'
        . ($elRow ? 'background:' . SUM_TINT . ';' : '');
    $lt = $td . 'border-left:1px solid ' . SUM_HAIR . ';';

    $elMark = '';
    foreach ($elMarks as $m) {
        $elMark .= '<span style="display:inline-block;margin-left:6px;padding:1px 6px;'
                 . 'border-radius:9px;background:' . SUM_TINT . ';color:' . SUM_RED . ';'
                 . 'font-size:11px;font-weight:bold;white-space:nowrap;">'
                 . summaryEsc($m['text']) . '</span>';
    }

    $h = '<tr>'
       . '<td align="center" style="' . $td . 'font-size:12px;color:' . SUM_FAINT . '">'
       . (int)$no . '</td>'
       . '<td style="' . $td . '">'
       . '<div style="font-size:14px;font-weight:bold;color:' . SUM_INK . '">'
       . summaryEsc($row['fullname']) . $elMark . '</div></td>';

    if ($kind === 'late') {
        foreach ($dayCols as $col) {
            $h .= '<td align="center" style="' . $lt . '">'
                . summaryAttCellHtml($row['att'][$col['date']]) . '</td>';
        }
        return $h . '</tr>';
    }

    if ($kind === 'leave') {
        foreach (array($row['leave']['taken'], $row['leave']['upcoming']) as $n) {
            $txt = summaryNum($n);
            $h .= '<td align="center" style="' . $lt . 'font-size:14px;font-weight:bold;color:'
                . ($txt === '' ? SUM_GHOST : SUM_INK) . '">'
                . ($txt === '' ? SUM_DASH : summaryEsc($txt)) . '</td>';
        }
        $h .= '</tr>';

        /* The applications themselves, EACH BENEATH THE FIGURE IT BELONGS TO
         * — the ones already begun under Taken, the ones still to come under
         * Upcoming. Listed in one block under both, the reader had to work
         * out which was which by reading the dates against today; split, the
         * column answers it. */
        $taken = $coming = array();
        foreach ($row['leaves'] as $l) {
            $line = ($l['code'] !== '' ? $l['code'] : 'Leave')
                  . ' - ' . $l['from'] . ' to ' . $l['to'];
            if ($l['when'] === 'upcoming') { $coming[] = $line; } else { $taken[] = $line; }
        }
        /* The entries carry the same wash, so a flagged person reads as one
         * block rather than a red row with a white tail. */
        return $h . summaryLeaveEntriesHtml($taken, $coming, $elRow);
    }

    /* compliance */
    $h .= '<td align="center" style="' . $lt . 'font-size:14px;font-weight:bold;color:'
        . SUM_RED . '">' . (int)$row['comp'] . '</td></tr>';

    /* Compliance has one figure and a long description, so its entries run
     * from the name across: squeezed under a 108px count they would be one
     * word a line. */
    $items = array();
    foreach ($row['comp_recs'] as $i => $r) {
        $items[] = ($i + 1) . '. ' . $r['date']
                 . ' - ' . ($r['title'] !== '' ? $r['title'] : $r['type']);
    }
    return $h . summaryEntriesHtml($items, 1, 2);
}

/** Query: the leave sub-row — the taken applications under Taken, the
 *  upcoming ones under Upcoming, each numbered from 1 within its own column
 *  because they are two lists and not one split in half. */
function summaryLeaveEntriesHtml(array $taken, array $coming, $tinted = false)
{
    if (empty($taken) && empty($coming)) {
        return '';
    }
    $wash = $tinted ? 'background:' . SUM_TINT . ';' : '';
    $cell = function (array $items) use ($wash) {
        if (empty($items)) {
            return '<td style="' . $wash . '"></td>';
        }
        /* CENTRED, under the figure each list belongs to. Left-aligned they
         * hung off the side of a centred number and read as a separate
         * column. The compliance list below is still left — it runs the
         * width of the table and is prose, not dates. */
        $h = '<td align="center" valign="top" style="padding:1px 10px 11px;font-size:12px;'
           . 'color:' . SUM_MUTED . ';line-height:1.75;text-align:center;' . $wash . '">';
        foreach ($items as $i => $line) {
            $h .= '<div>' . ($i + 1) . '. ' . summaryEsc($line) . '</div>';
        }
        return $h . '</td>';
    };
    return '<tr><td style="' . $wash . '"></td><td style="' . $wash . '"></td>'
         . $cell($taken) . $cell($coming) . '</tr>';
}

/** Query: the sub-row that lists a person's entries under their figures. One
 *  per line, numbered, quiet enough not to compete with the row above. */
function summaryEntriesHtml(array $items, $skip, $span)
{
    if (empty($items)) {
        return '';
    }
    /* $skip empty cells, then the list across $span of them — which is how
     * the list lands under the columns it belongs to. */
    $h = '<tr>' . str_repeat('<td></td>', (int)$skip)
       . '<td colspan="' . (int)$span . '" align="left" '
       . 'style="padding:1px 10px 11px;font-size:12px;color:' . SUM_MUTED . ';'
       . 'line-height:1.75;">';
    foreach ($items as $line) {
        $h .= '<div>' . summaryEsc($line) . '</div>';
    }
    return $h . '</td></tr>';
}

/**
 * Query: the tick box that closes one section.
 *
 * A REAL <input type="checkbox">, INSIDE A REAL <form>. Whether it does
 * anything depends entirely on the mail client, and the split is not close:
 *
 *   Apple Mail, iOS Mail   renders it, ticks it, and submits the form —
 *                          this works end to end.
 *   Outlook.com, Yahoo     usually renders and ticks; submitting varies.
 *   GMAIL                  strips <form> and every <input> inside it. The
 *                          box does not appear at all.
 *   Outlook desktop        Word does not render form controls either.
 *
 * There is no HTML that changes the Gmail half. A radio button is the same
 * element family and is stripped by the same rule; the "interactive email"
 * demos that do work in Gmail are either AMP for Email — a separate MIME part
 * that needs the sending domain registered with Google — or the CSS
 * :checked trick, which toggles a picture and submits nothing.
 *
 * So the box is here for the clients that honour it, and the Acknowledge LINK
 * under the table is what the rest use. Nothing is lost either way: both
 * routes end at staffSummaryAck.php, which is where the record is made.
 */
function summaryTickHtml($kind)
{
    /* Big, because it is now the only one in the section and it is being
     * pressed on a phone as often as on a desk. */
    return '<input type="checkbox" name="items[]" '
         . 'value="' . summaryEsc($kind) . '" '
         . 'style="width:44px;height:44px;margin:0;">';
}

/** Query: the two lines of one lateness cell — the check-in over the
 *  check-out. The colours come from summaryAttCell()'s flags and are decided
 *  nowhere else; a line that is neither late nor missing names no colour at
 *  all and inherits the table's. */
function summaryAttCellHtml(array $cell)
{
    if ($cell['not_read']) {
        return '<span style="color:' . SUM_GHOST . '">' . SUM_DASH . '</span>';
    }

    /* $tone: '' plain, 'bad' red. */
    $line = function ($label, $value, $tone) {
        return '<div style="font-size:13.5px'
             . ($tone === 'bad' ? ';color:' . SUM_RED . ';font-weight:bold' : '') . '">'
             . '<span style="font-size:10.5px;color:' . SUM_FAINT . ';font-weight:normal">'
             . $label . ' </span>'
             . ($value === SUM_DASH
                 ? '<span style="color:' . SUM_GHOST . '">' . SUM_DASH . '</span>'
                 : summaryEsc($value))
             . '</div>';
    };

    /* A day off is one thing, said once: the leave and which leave it was.
     * No IN:/OUT: either side of it — they are labels for a day that had
     * neither. See $onLeave in summaryAttCell(). */
    if ($cell['on_leave']) {
        return '<div style="font-size:13px;color:' . SUM_BLUE . ';font-weight:bold">'
             . summaryEsc($cell['leave_label']) . '</div>';
    }

    /* TODAY SHOWS THE CHECK-IN AND NOTHING ELSE. The 4:30pm slot has not
     * happened when the mail goes out, so the column is left off rather than
     * filled with a verdict on something nobody could have done yet — see
     * $showOut in summaryAttCell(). */
    return $line('IN:', $cell['in'], ($cell['in_late'] || $cell['in_missing']) ? 'bad' : '')
         . ($cell['show_out']
             ? $line('OUT:', $cell['out'], $cell['out_missing'] ? 'bad' : '')
             : '');
}

/**
 * Command: one mail on the wire.
 *
 * BASE64, AND NOT BECAUSE IT IS TIDIER. SMTP allows a line of at most 998
 * characters (RFC 5321). This table is built as one unbroken string — a 70KB
 * line — and an MTA handed that will wrap it itself, wherever it happens to
 * land. It lands in the middle of tags, and the mail arrives with
 *
 *     < td bgcolor="#fef2f2" style="padding:7px 5px;...">
 *
 * printed at the reader as text, the table falling apart below it, and an
 * em dash shredded into a diamond because the break fell between two bytes of
 * one UTF-8 character. Every one of those is the same fault.
 *
 * Encoding the body fixes it at the source: base64 is 7-bit, chunk_split()
 * holds every line to 76 characters, and no MTA has any reason to touch it.
 * The alternative — threading newlines through the generated HTML and hoping
 * none of the gaps is ever more than 998 characters apart — is a rule nobody
 * can keep while editing a table.
 *
 * The subject is encoded-word for the same family of reasons: a raw 8-bit
 * subject header is what makes a subject line arrive as mojibake.
 */
function summarySend($to, $subject, $html)
{
    $headers = "MIME-Version: 1.0\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: base64\r\n"
             . 'From: ' . SUMMARY_EMAIL_FROM . "\r\n"
             . 'Reply-To: ' . SUMMARY_EMAIL_FROM;

    /* 76 characters a line, CRLF between them — what every mail client
     * expects of base64 and what keeps the longest line a quarter of the
     * limit. */
    $body    = chunk_split(base64_encode($html), 76, "\r\n");
    $encoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $sent = @mail($to, $encoded, $body, $headers);

    return array('ok' => (bool)$sent,
                 'response' => $sent ? 'accepted by MTA' : 'mail() refused the message');
}

/* ---------- the push -------------------------------------------------------
 *
 * THE SAME MORNING, ON THE HANDSET. The mail is the record and the push is
 * the nudge: the three figures and what they did since the last summarised
 * day, so somebody can tell from a lock screen whether this morning needs
 * them before breakfast or after.
 *
 * A DIFFERENT LIST FROM THE MAIL'S, AND DELIBERATELY SO. The mail goes to
 * every HOD and Area Manager the staff tree knows about. The push goes to
 * staff_portal2.users.daily_summary_report = 1 — the list HR keeps by hand on
 * staffHeadcountReport.php, which is also the list the app stops at Staff
 * Utility. Being pushed and being stopped are the same question, so they are
 * the same tick: nobody is buzzed about a screen that will not ask them for
 * anything, and nobody is stopped by a screen they were never told about.
 *
 * THE TWO LISTS LOOK IDENTICAL TODAY — 33 people each — because the column
 * was seeded from a morning's mail (see sql/daily_summary_report.sql). They
 * are not the same list, and unticking somebody is what proves it: their mail
 * carries on exactly as it was and only the phone goes quiet.
 *
 * IT FOLLOWS THE MAIL AND NEVER REPLACES IT. The push is sent from inside the
 * same loop, after the mail for the same person, and the mail's own outcome
 * makes no difference to it: a manager with no address on file is exactly the
 * person a push is for. Everything that stops the mail stops the push too,
 * because it is the same loop — a clean department, an acknowledgement
 * already made, dry_run, rebuild.
 *
 * WHERE IT LANDS. data.screen = 'StaffUtility', which fcm.ts has routed since
 * long before this feature: it brings the Account stack up, then Staff
 * Utility, which asks staffSummaryAPI.php for the day as it mounts and raises
 * the gate itself. NOTHING IN THE APP HAD TO CHANGE for this, and nothing in
 * the app decides who is stopped — the column does, on both sides.
 *
 * TEXT, NOT TILES. A notification is drawn by the operating system. The tiled
 * layout this was first drawn as needs a RemoteViews XML layout on Android,
 * which every OEM skin then restyles, and a Notification Content Extension
 * target on iOS that only appears on a pull-down. Three numbers are not worth
 * a native build on two platforms.
 */

/**
 * Query: who is on the Daily Summary Report list, as person => users row.
 *
 * ONE QUERY FOR THE COMPANY, not one per recipient: the loop below asks this
 * of every manager it mails, and the answer does not change while it runs.
 *
 * users.id IS WHAT THE ENDPOINT WANTS. It keeps the tokens, not this
 * database, and it is addressed by account id — so the id is read here, with
 * the list, rather than looked up again at send time.
 *
 * IT FAILS CLOSED, which is the opposite of what staffSummaryAPI.php does
 * with the same column and right for the opposite reason. There, an
 * unreadable column must not stop somebody using the app, so nobody is
 * gated. Here, an unreadable column could only mean pushing to everybody or
 * to nobody, and nobody is the one that cannot be wrong.
 */
function summaryPushList(PDO $pdo, $compId)
{
    $out = array();
    try {
        $stmt = $pdo->prepare(
            "SELECT u.id, u.person, u.fullname
               FROM staff_portal2.users u
              WHERE u.comp_id = :c
                AND IFNULL(u.daily_summary_report, 0) = 1
                AND u.status = '1' AND u.deleted = '0'");
        $stmt->execute(array(':c' => (int)$compId));

        foreach ($stmt->fetchAll() as $row) {
            $key = summaryKey(isset($row['person']) ? $row['person'] : '');
            if ($key === '' || (int)$row['id'] <= 0) { continue; }
            $out[$key] = array('user_id'  => (int)$row['id'],
                               'fullname' => (string)$row['fullname']);
        }
    } catch (PDOException $e) {
        error_log('staffSummaryCron push list: ' . $e->getMessage());
        summaryLog('  ! the Daily Summary Report list could not be read — nothing was pushed');
        return array();
    }
    return $out;
}

/**
 * Query: the day this morning is compared against — the most recent
 * summarised day BEFORE today for the same person — or null.
 *
 * THE DAY BEFORE, NOT YESTERDAY. Sunday has no run, nor does a public
 * holiday, nor does a morning the cron missed; comparing Monday against a
 * Sunday that was never summarised would show every figure as new. The last
 * day this person was actually told about is the only honest baseline, and
 * the push prints its date rather than the word "yesterday" so the reader
 * knows which day the arrows are against.
 *
 * ANY ROW, NOT ONLY A SENT ONE. A mail that failed still wrote its figures
 * and still put the day in front of that person in the app, so it is still
 * the day this one follows.
 */
function summaryPushPrevious(PDO $pdo, $person, $compId, $todayIso)
{
    static $stmt = null;

    try {
        if ($stmt === null) {
            $stmt = $pdo->prepare(
                'SELECT notify_date, late_count, leave_count, compliance_count
                   FROM mkPortal.staff_summary_notify
                  WHERE person = :p AND comp_id = :c AND notify_date < :d
                  ORDER BY notify_date DESC
                  LIMIT 1');
        }
        $stmt->execute(array(':p' => (string)$person, ':c' => (int)$compId,
                             ':d' => $todayIso));
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    } catch (PDOException $e) {
        error_log('staffSummaryCron push previous: ' . $e->getMessage());
        return null;   /* no baseline: the push shows bare numbers */
    }
}

/**
 * Query: what a figure did — '▲8', '▼2', '—' for no change, or '' when there
 * is nothing to compare it against.
 *
 * UP IS ALWAYS THE BAD DIRECTION here. All three figures count people with
 * something wrong, so there is no row in this push where a rise is good news
 * and no need for an arrow to mean different things in different lines.
 *
 * THE ARROW IS NEVER THE ONLY SIGNAL: the figure is printed beside it, so the
 * push still says what it means in greyscale, to a colourblind reader and
 * through a screen reader. A dash is "no change" and is PRINTED rather than
 * left out, so a figure with nothing after it can mean one thing only — no
 * earlier day to compare it with.
 *
 * NO EARLIER DAY MEANS NO ARROW. A Sunday, a holiday, a cron that did not
 * run, somebody ticked onto the list this morning — a baseline of zero would
 * report a jump of 28 that nobody made.
 */
function summaryPushDelta($now, $was)
{
    if ($was === null) {
        return '';
    }

    $diff = (int)$now - (int)$was;
    if ($diff > 0) { return '▲' . $diff; }
    if ($diff < 0) { return '▼' . abs($diff); }

    return '—';
}

/** Query: ' ▲8', or '' — the delta as it reads after a figure. */
function summaryPushTail($delta)
{
    return $delta === '' ? '' : ' ' . $delta;
}

/**
 * Query: a FETCHED module as one line of a push notification, or '' when it
 * has nothing to say.
 *
 * THE SAME GRAMMAR AS THE STAFF LINE ABOVE IT — figure, then arrow, then a
 * middot — so the two read as one message and not as two notifications that
 * happen to have arrived together. A reader who has learnt what '▲1' means on
 * line one does not have to learn anything new on line two.
 *
 * IT IS THE TOTAL ROW AND ONLY THE TOTAL ROW. A manager of four districts
 * would otherwise get four lines in a shade that shows two, and the line that
 * answers "how are we doing" is the one that adds them up. The breakdown is
 * one tap away, in the section this line is advertising.
 *
 * THE ARROWS ARE THE ENDPOINT'S OWN DELTAS, carried on each cell. Nothing here
 * subtracts anything or reads a baseline out of staff_summary_notify: the
 * endpoint measured the same month twice in one run and already knows. A null
 * delta prints no arrow at all — on the first of the month there is nothing to
 * compare against, and a bare figure says exactly that.
 *
 * COARSER THAN THE TABLE, DELIBERATELY: 2,099 m² and 91.7%, not 2,098.94 and
 * 91.73%. The endpoint's push block says at what precision; see
 * ro1waPushBlock() for why the endpoint is the layer that decides.
 *
 * A MODULE THAT SENDS NO PUSH BLOCK still gets a line, built from its title
 * and its ordinary columns. Longer, and correct.
 */
function summaryPushModuleLine(array $mod, $fetched)
{
    if (!is_array($fetched) || empty($fetched['ok']) || !is_array($fetched['data'])) {
        return '';
    }
    $data  = $fetched['data'];
    $total = isset($data['total']) && is_array($data['total']) ? $data['total'] : null;
    if ($total === null || empty($data['rows'])) {
        return '';
    }

    $push = (isset($data['push']) && is_array($data['push'])) ? $data['push'] : array();

    /* WHICH LIST OF COLUMNS THIS LINE IS BUILT FROM, and it changes how a
     * column is NAMED. A push column's label is taken exactly as given,
     * EMPTY INCLUDED — 'm²' says what the figure is, so the work-done column
     * deliberately has no label at all. An ordinary column has always got a
     * label, so "take it when it is set" would never reach short_label; the
     * two cases have to be told apart rather than merged. */
    $fromPush = (isset($push['columns']) && is_array($push['columns'])
                 && !empty($push['columns']));
    $cols = $fromPush
        ? $push['columns']
        : (isset($data['columns']) && is_array($data['columns']) ? $data['columns'] : array());
    if (empty($cols)) { return ''; }

    /* The module's own short name, then its title's FIRST WORD as the
     * fallback — 'RO1 Work Achievement' gives 'RO1', which is what anybody
     * would have abbreviated it to anyway. */
    $label = isset($push['label']) && $push['label'] !== '' ? $push['label'] : '';
    if ($label === '') {
        $words = preg_split('/\s+/', trim((string)$mod['title']));
        $label = isset($words[0]) ? $words[0] : '';
    }

    /* The full column list, to borrow format and unit from — the push block
     * says what to CALL a column and at what precision, not what it is. */
    $full = array();
    foreach ((isset($data['columns']) && is_array($data['columns'])) ? $data['columns'] : array() as $c) {
        if (isset($c['key'])) { $full[$c['key']] = $c; }
    }

    $parts = array();
    foreach ($cols as $c) {
        $key = isset($c['key']) ? $c['key'] : '';
        if ($key === '' || !isset($total[$key]) || !is_array($total[$key])) { continue; }

        $base = isset($full[$key]) ? $full[$key] : array();
        $dp   = isset($c['decimals']) ? (int)$c['decimals']
              : (isset($base['decimals']) ? (int)$base['decimals'] : 0);
        $fmt  = isset($base['format']) ? $base['format'] : 'number';
        /* The push may override the unit as well as the precision: the table
         * wants '1,219.22 m²' and the shade wants '1,219m²', and the space is
         * worth having in one place and not the other. */
        $unit = isset($c['unit']) ? $c['unit']
              : (isset($base['unit']) ? $base['unit'] : '');

        $cell = $total[$key];
        $text = number_format((float)$cell['value'], $dp);
        if ($fmt === 'percent') { $text .= '%'; }
        if ($unit !== '')       { $text .= $unit; }

        /* The label goes BEFORE the figure, as 'Late 5' does — or after it,
         * for a word that reads as a noun rather than a heading ('102 open').
         * label_after is how a column says which it is.
         *
         * WITHOUT A PUSH BLOCK the ordinary short_label stands in, and the
         * full label only as a last resort. A module that describes neither
         * gets a long line, which the trim at the end will then shorten by
         * dropping figures — it is still right, and the fix is one field. */
        if ($fromPush) {
            $name = isset($c['label']) ? trim((string)$c['label']) : '';
        } else {
            $name = (isset($base['short_label']) && $base['short_label'] !== '')
                ? $base['short_label']
                : (isset($base['label']) ? (string)$base['label'] : '');
        }
        if ($name !== '') {
            $text = !empty($c['label_after']) ? $text . ' ' . $name : $name . ' ' . $text;
        }

        $parts[] = $text . summaryPushTail(summaryPushCellDelta($cell, $dp, $fmt));
    }

    if (empty($parts)) { return ''; }

    /* A LAST LINE OF DEFENCE AGAINST A WRAPPED LINE.
     *
     * A notification shade is about forty characters wide and Android wraps
     * wherever it likes — in the middle of '5 open ▲10' if that is where the
     * edge falls, which reads as noise rather than as a figure. The endpoint's
     * push block is what keeps a line short (see ro1waPushBlock(), which drops
     * one of its three figures for exactly this reason), but a module that
     * sends no push block, or a district with unusually large numbers, can
     * still overflow.
     *
     * So the line gives up its LAST figure rather than its shape, one at a
     * time, until it fits. Dropping from the end is the only honest direction:
     * the push block lists its figures in the order they matter, so the one
     * that goes is the one its own author ranked last. One figure fewer is a
     * loss; a figure cut in half is a mess, and the whole line is then worth
     * less than the figures it still shows.
     *
     * The first figure is never dropped, however long it is — a line reading
     * 'RO1' and nothing else would say less than no line at all. */
    $head = ($label !== '' ? $label . ' ' : '');
    while (count($parts) > 1
           && mb_strlen($head . implode(' · ', $parts), 'UTF-8') > SUMMARY_PUSH_LINE_MAX) {
        array_pop($parts);
    }

    return $head . implode(' · ', $parts);
}

/**
 * Query: '▲10', '▼0.6', '—' or '' — one fetched cell's own change, printed
 * the way summaryPushDelta() prints a head count's.
 *
 * '' AND '—' ARE DIFFERENT ANSWERS and the difference matters on exactly one
 * morning a month: '' is "there was no earlier day in this month to compare
 * against", which is the 1st, and '—' is "measured, and it did not move". A
 * bare figure on the 1st is therefore correct and not a missing arrow.
 */
function summaryPushCellDelta(array $cell, $dp, $format)
{
    if (!array_key_exists('delta', $cell) || $cell['delta'] === null) { return ''; }

    $d = (float)$cell['delta'];
    /* AT THE PRINTED PRECISION, not the raw value. A change of 0.004 shown to
     * 0 dp is '▲0', which reads as a contradiction; rounded first it is '—',
     * which agrees with the figure beside it. The same rule the report's
     * colour test uses. */
    $r = round($d, $dp);
    if (abs($r) < 0.0000001) { return '—'; }

    /* NO UNIT ON THE ARROW, not even on a percentage. The figure it follows
     * already carries one — '91.7% ▼0.6%' says '%' twice in nine characters,
     * on the line where characters are scarcest — and the staff line above
     * prints a bare '▲1' for the same reason. */
    return ($r > 0 ? '▲' : '▼') . number_format(abs($r), $dp);
}

/**
 * Query: ' vs 24 Oct' — which day the arrows are measured against — or '',
 * when that day is simply the one before and saying so is noise.
 *
 * THE ARROWS ARE READ AS "SINCE YESTERDAY" BY ANYBODY WHO READS THEM, so the
 * line only speaks up when that reading would be wrong: after a Sunday, a
 * public holiday, or a morning the cron missed, where the last summarised day
 * may be several back.
 */
function summaryPushAgainst($prev, $todayIso)
{
    if ($prev === null) {
        return '';   /* no baseline at all — there are no arrows to explain */
    }

    $was = substr((string)$prev['notify_date'], 0, 10);
    if ($was === date('Y-m-d', strtotime($todayIso . ' -1 day'))) {
        return '';
    }

    return ' vs ' . date('j M', strtotime($was));
}

/**
 * Query: the recipient's own units, as one short phrase.
 *
 * TWO NAMES AT MOST, and the overflow as '+3' rather than 'and 3 more'. This
 * phrase opens the second line of a notification and everything after it is
 * cut when the line runs out, so the words it spends are taken from
 * 'not acknowledged'.
 */
function summaryPushUnits(array $units, $max = 2)
{
    $units = array_values(array_filter(array_map('trim', $units), 'strlen'));
    if (!$units) {
        return '';
    }
    if (count($units) > $max) {
        return implode(', ', array_slice($units, 0, $max)) . ' +' . (count($units) - $max);
    }
    if (count($units) === 1) {
        return $units[0];
    }

    $last = array_pop($units);
    return implode(', ', $units) . ' & ' . $last;
}

/**
 * Query: one person's notification — title, body and data block.
 *
 * TWO LINES, AND NOTHING SAID TWICE. The first draft printed the one-line
 * summary and then the same three figures again as a labelled table, on the
 * theory that a notification shows one line collapsed and several expanded.
 * On a real handset both forms are on screen at once, one under the other,
 * and it reads as a stutter — so there is one statement of the figures now,
 * and a quiet second line saying whose morning it is and what is wanted.
 *
 * THE TABLE IS GONE FOR A SECOND REASON: notification text is not monospaced
 * on either platform, so str_pad() lines figures up in the source and not on
 * the screen. A column that cannot be relied on to be a column is worse than
 * no column at all.
 *
 * THE FIGURES COME FIRST because a notification is cut off at the right and
 * dropped from the bottom. Everything that has to survive being cut is at the
 * start of line one; the unit leads line two for the same reason.
 *
 * EVERY DATA VALUE IS A STRING, because FCM data values are strings on the
 * wire whatever is put in them, and a number read back as a number on one
 * platform and a string on the other is a bug waiting for a release.
 */
function summaryPushMessage(array $recipient, array $counts, $prev, $todayIso, $unitLine,
                           array $modLines = array(), array $modData = array())
{
    $wasLate  = $prev === null ? null : (int)$prev['late_count'];
    $wasLeave = $prev === null ? null : (int)$prev['leave_count'];
    $wasComp  = $prev === null ? null : (int)$prev['compliance_count'];

    /* Line one: the morning. Short labels, because this line is measured in
     * characters that fit on a lock screen rather than in words. */
    $figures = 'Late '    . (int)$counts['late']
             . summaryPushTail(summaryPushDelta($counts['late'], $wasLate))
             . ' · Leave ' . (int)$counts['leave']
             . summaryPushTail(summaryPushDelta($counts['leave'], $wasLeave))
             . ' · Comp '  . (int)$counts['comp']
             . summaryPushTail(summaryPushDelta($counts['comp'], $wasComp));

    /* Line two: whose morning, what is wanted, and which morning — in that
     * order, because that is the order they would be missed in if the line
     * were cut. The date is last and nearly free: the notification already
     * carries the time it arrived, and it only has anything to add on the
     * morning after a gap, when it says which day the arrows are against. */
    $context = array();
    if ($unitLine !== '') {
        $context[] = $unitLine;
    }
    $context[] = 'not acknowledged';
    $context[] = date('j M', strtotime($todayIso)) . summaryPushAgainst($prev, $todayIso);

    /* THE FIGURE LINES STAY TOGETHER AND THE CONTEXT LINE GOES LAST. A fetched
     * module's line is built to the same grammar as the staff line — figure,
     * arrow, middot — so the two read as one message. Putting the unit and the
     * date between them would split one statement into two that merely arrived
     * together.
     *
     * It costs the context line a place further down, which is the right
     * trade: "not acknowledged" is also what the notification's existence
     * says, and the date is nearly free because the shade already shows the
     * time it arrived. */
    $lines = array($figures);
    foreach ($modLines as $line) {
        if ($line !== '') { $lines[] = $line; }
    }
    $lines[] = implode(' · ', $context);

    return array(
        /* A SHORT TITLE. The long one wrapped to two lines in the shade and
         * was cut mid-word in the list — and the words being cut were the
         * ones saying what to do about it. They are on line two now, where
         * there is room for them. */
        'title' => 'Daily Staff Summary',
        'body'  => implode("\n", $lines),
        'data'  => array(
            'screen'   => 'StaffUtility',
            'category' => 'daily_summary',
            'date'     => (string)$todayIso,
            'late'     => (string)(int)$counts['late'],
            'leave'    => (string)(int)$counts['leave'],
            'comp'     => (string)(int)$counts['comp'],
        /* A fetched module's line rides along under its own key, so the app
         * could act on it without opening the summary. Nothing reads these
         * yet; they cost a few bytes in a payload already being sent. */
        ) + $modData,
    );
}

/**
 * Query: the devices the endpoint says it sent to, out of its own answer.
 *
 * It replies {"success":true,"message":"fcm sent to NAME","data":[{id, rowid,
 * token, name, platform, group, ...}]} — one entry per registration it holds
 * for that account. THE SAME TOKEN CAN APPEAR TWICE under two ids: the
 * registry keeps a row per registration, not per handset, and a reinstall
 * leaves the old one behind. Both are recorded, because both are what the
 * endpoint sent to.
 *
 * A reply in any other shape yields no devices, which the caller reads as
 * "reached nobody" — never as an error.
 */
function summaryPushDevices($json)
{
    $out = array();
    if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
        return $out;
    }

    foreach ($json['data'] as $row) {
        if (!is_array($row)) { continue; }
        $out[] = array(
            'device_id' => isset($row['id'])       ? (int)$row['id']          : 0,
            'user_id'   => isset($row['rowid'])    ? (int)$row['rowid']       : 0,
            'token'     => isset($row['token'])    ? (string)$row['token']    : '',
            'platform'  => isset($row['platform']) ? (string)$row['platform'] : '',
            'group'     => isset($row['group'])    ? (string)$row['group']    : '',
            'name'      => isset($row['name'])     ? (string)$row['name']     : '',
        );
    }
    return $out;
}

/**
 * Command: one push on the wire. Returns ok, the HTTP code, the raw answer
 * and the devices it named.
 *
 * NON-ASCII GOES AS \uXXXX, which is the one deliberate difference from
 * staffNotify.php's otherwise identical sender. The arrows are the whole
 * signal in this body, and an escaped sequence cannot be mangled by a latin1
 * step anywhere between here and the handset; JSON_UNESCAPED_UNICODE would
 * put the raw bytes on the wire and trust every hop with them.
 *
 * success:true IS THE ENDPOINT'S OWN VERDICT and HTTP 200 alone is not
 * enough: it answers 200 with success:false when it holds no token for the
 * id, which is the normal state for somebody who never installed the app.
 */
function summaryPushSend($userId, $title, $body, array $data)
{
    $payload = json_encode(
        array('id' => (int)$userId, 'title' => $title, 'body' => $body, 'data' => $data),
        JSON_UNESCAPED_SLASHES
    );

    if ($payload === false) {
        return array('ok' => false, 'code' => 0, 'devices' => array(),
                     'response' => 'payload is not encodable as JSON');
    }

    $ch = curl_init(SUMMARY_PUSH_URL);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => SUMMARY_PUSH_TIMEOUT,
    ));
    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return array('ok' => false, 'code' => $code, 'devices' => array(),
                     'response' => 'curl: ' . $error);
    }

    $json = json_decode((string)$response, true);

    return array(
        'ok'       => ($code === 200 && is_array($json) && !empty($json['success'])),
        'code'     => $code,
        'devices'  => summaryPushDevices($json),
        'response' => 'HTTP ' . $code . ' ' . substr((string)$response, 0, 900),
    );
}

/**
 * Command: one person's push, and one log row per handset it reached.
 *
 * NEVER THROWS. The mail is already out by the time this runs, and a dead
 * endpoint or a missing log table must not cost the rest of the list their
 * morning — the error_log line is the loud failure.
 *
 * A SEND THAT REACHED NOBODY STILL GETS A ROW, with device_id 0. Somebody on
 * the list who never installed the app, or whose token was cleared, is a fact
 * worth being able to look up: silence and success must not look the same in
 * this table.
 */
function summaryPushOne(PDO $pdo, PDOStatement $logStmt, array $recipient, $userId,
                        array $counts, $prev, $unitLine, $todayIso, $now,
                        array $modLines = array(), array $modData = array())
{
    $message = summaryPushMessage($recipient, $counts, $prev, $todayIso, $unitLine,
                                  $modLines, $modData);

    /* The test handset, if one is set — see SUMMARY_PUSH_TEST_USER_ID. The
     * body is still this manager's morning; only the handset is borrowed. */
    $test   = (int)SUMMARY_PUSH_TEST_USER_ID;
    $sendTo = $test > 0 ? $test : (int)$userId;

    if ($sendTo <= 0) {
        $result = array('ok' => false, 'code' => 0, 'devices' => array(),
                        'response' => 'no users.id to push to');
    } else {
        $result = summaryPushSend($sendTo, $message['title'], $message['body'],
                                  $message['data']);
    }

    $devices = $result['devices'];
    if (!$devices) {
        $devices = array(array('device_id' => 0, 'token' => '', 'platform' => '',
                               'group' => '', 'name' => ''));
        $status  = ($result['ok'] || $result['code'] === 200) ? 'no_device' : 'failed';
    } else {
        $status  = $result['ok'] ? 'sent' : 'failed';
    }

    $payload = json_encode($message['data'], JSON_UNESCAPED_SLASHES);

    foreach ($devices as $device) {
        try {
            $logStmt->execute(array(
                ':d'        => $todayIso,
                ':p'        => $recipient['person'],
                ':c'        => SUMMARY_COMP_ID,
                ':fullname' => $recipient['fullname'],
                ':intended' => (int)$userId,
                ':sent_to'  => $sendTo,
                ':title'    => $message['title'],
                ':body'     => $message['body'],
                ':payload'  => $payload === false ? '' : $payload,
                ':late'     => (int)$counts['late'],
                ':leave'    => (int)$counts['leave'],
                ':comp'     => (int)$counts['comp'],
                ':cmp_date' => $prev === null ? null : $prev['notify_date'],
                ':late_d'   => $prev === null ? null : (int)$counts['late']  - (int)$prev['late_count'],
                ':leave_d'  => $prev === null ? null : (int)$counts['leave'] - (int)$prev['leave_count'],
                ':comp_d'   => $prev === null ? null : (int)$counts['comp']  - (int)$prev['compliance_count'],
                ':dev_id'   => (int)$device['device_id'],
                ':token'    => substr((string)$device['token'], 0, 255),
                ':platform' => substr((string)$device['platform'], 0, 32),
                ':dev_group'=> substr((string)$device['group'], 0, 32),
                ':dev_name' => substr((string)$device['name'], 0, 150),
                ':status'   => $status,
                ':code'     => (int)$result['code'],
                ':response' => substr((string)$result['response'], 0, 1000),
                ':sent_at'  => $status === 'sent' ? $now : null,
                ':now'      => $now,
                ':now2'     => $now,
            ));
        } catch (PDOException $e) {
            error_log('staffSummaryCron push log ' . $recipient['person'] . ': '
                    . $e->getMessage());
        }
    }

    return array('status'   => $status,
                 'to'       => $sendTo,
                 'devices'  => count($result['devices']),
                 'response' => $result['response']);
}

/* ---------- the run ------------------------------------------------------- */

header('Content-Type: text/html; charset=UTF-8');
echo "<!DOCTYPE html><html><head><meta charset=\"utf-8\">"
   . "<title>Staff Summary Cron</title></head><body>"
   . "<pre style=\"font:13px/1.6 ui-monospace,Menlo,Consolas,monospace;"
   . "padding:20px;background:#0f172a;color:#e2e8f0;margin:0;min-height:100vh\">\n";

$startedAt = microtime(true);

/* ---- the guard ---- */
$key = isset($_GET['key']) ? (string)$_GET['key'] : (isset($_POST['key']) ? (string)$_POST['key'] : '');
if (!hash_equals(SUMMARY_CRON_KEY, $key)) {
    summaryStop('Refused: pass ?key=... . Nothing was sent.', 403);
}

$dryRun = !empty($_GET['dry_run']);
$force  = !empty($_GET['force']);

/**
 * rebuild=1 — REWRITE TODAY'S SNAPSHOTS, SEND NOTHING.
 *
 * The payload a person's app and acknowledgement page read is written once,
 * when the mail goes out, and never looked at again. So a rule that changes
 * during the day leaves every stored day behind it: the 29th of October was
 * written at 09:25 counting whole dates, and no amount of correct code could
 * make it say 2.5 EL afterwards, because the half day was never in it.
 *
 * dry_run cannot do this — it deliberately skips the save — and re-running the
 * mail to fix a badge means mailing a hundred people twice. This writes the
 * payload and nothing else.
 *
 * ONLY ROWS THAT ALREADY EXIST, AND ONLY UNACKNOWLEDGED ONES. A person with
 * no row today was not mailed, and inventing one here would hand out a
 * working acknowledgement link for a mail that was never sent. A person who
 * has already acknowledged keeps what they acknowledged — their record is of
 * what they were shown, and this is not entitled to rewrite it. The sent
 * status, the response and the send time are carried over untouched, so a row
 * still reads as the mail that was actually sent this morning.
 */
$rebuild = !empty($_GET['rebuild']);

/* ---- which day ---- */
$todayIso = isset($_GET['date']) ? trim((string)$_GET['date']) : date('Y-m-d');
$d = DateTime::createFromFormat('Y-m-d', $todayIso);
if (!$d || $d->format('Y-m-d') !== $todayIso) {
    summaryStop('date must be a calendar date in YYYY-MM-DD form. Nothing was sent.', 400);
}
$yesterdayIso = date('Y-m-d', strtotime($todayIso . ' -1 day'));

summaryLog('Staff Summary — ' . summaryDay($todayIso) . ' (' . $todayIso . ')'
         . ($dryRun ? '   [DRY RUN — nothing will be sent]' : '')
         . ($rebuild && !$dryRun ? '   [REBUILD — snapshots rewritten, nothing sent]' : ''));
summaryLog(str_repeat('-', 72));

if ((int)date('w', strtotime($todayIso)) === 0 && !$force) {
    summaryStop('Sunday — no summary is sent. Nothing was done.');
}

/* ---- the reads ---- */
summaryLog('Reading the APIs...');

$attToday = summaryApi('attendanceAPI.php', array(
    'comp_id' => SUMMARY_COMP_ID, 'daily' => 1, 'date' => $todayIso));
if ($attToday === null) {
    summaryStop('Attendance could not be read, and it carries the reporting line '
              . 'the whole table is built from. Nothing was sent.', 503);
}

/* ---- not on public holidays ---- */
$phFlag   = isset($attToday['is_public_holiday']) ? (string)$attToday['is_public_holiday'] : '';
$phSource = isset($attToday['holidays']['source']) ? $attToday['holidays']['source'] : 'unknown';

if ($phFlag === '1' && !$force) {
    $titles = isset($attToday['holidays']['days'][$todayIso]['titles'])
        ? $attToday['holidays']['days'][$todayIso]['titles'] : array();
    summaryStop('Public holiday' . (!empty($titles) ? ' (' . implode('; ', $titles) . ')' : '')
              . ' — no summary is sent. Nothing was done.');
}
if ($phFlag === '') {
    summaryLog('  ! The holiday calendar could not be read (' . $phSource . '). '
             . 'Sending anyway — a silent skip is indistinguishable from a broken cron.');
}

/* Yesterday, for its own column — unless nobody worked it. A Sunday or a
 * public holiday is read as "not a working day" and the column shows a dash
 * rather than a department of absentees. */
$attYesterday = null;
if ((int)date('w', strtotime($yesterdayIso)) === 0) {
    summaryLog('  yesterday (' . $yesterdayIso . ') was a Sunday — not read');
} else {
    $attYesterday = summaryApi('attendanceAPI.php', array(
        'comp_id' => SUMMARY_COMP_ID, 'daily' => 1, 'date' => $yesterdayIso));
    if ($attYesterday !== null && isset($attYesterday['is_public_holiday'])
        && (string)$attYesterday['is_public_holiday'] === '1') {
        summaryLog('  yesterday (' . $yesterdayIso . ') was a public holiday — not reported');
        $attYesterday = null;
    }
}

/* Yesterday then today, which is the order the columns read. */
$attByDay = array($yesterdayIso => $attYesterday, $todayIso => $attToday);
/* BOTH COLUMNS CARRY THEIR DATE. Today's used to read just "Today", which
 * leaves the reader working out what the other one is — and a mail opened the
 * next morning no way at all. summaryDayShort() adds "(Today)" to the current
 * one rather than replacing the date with it. */
$dayCols  = array(
    array('date' => $yesterdayIso, 'label' => summaryDayShort($yesterdayIso, $todayIso)),
    array('date' => $todayIso,     'label' => summaryDayShort($todayIso, $todayIso)),
);

$people = summaryPeople(array($attToday, $attYesterday));
summaryLog('  attendanceAPI.php   ' . count($people) . ' people, late_from '
         . (isset($attToday['rules']['late_from']) ? $attToday['rules']['late_from'] : '?'));

$tree = summaryApi('staffPoolAPI.php', array(
    'comp_id' => SUMMARY_COMP_ID, 'staff_tree' => 'all'));
if ($tree === null) {
    summaryStop('The staff tree could not be read, so there is nobody to send to. '
              . 'Nothing was sent.', 503);
}
$recipients = summaryRecipients($tree);
$unitKinds  = summaryUnitKinds($tree);
$unitHeads  = summaryUnitHeads($tree);
summaryLog('  staffPoolAPI.php    ' . count($recipients) . ' managers to mail');

/* The month, for the leave and compliance columns. One call each: both
 * columns are "this calendar month", so there is no second month to fetch. */
$leave = summaryApi('leaveAPI.php', array(
    'comp_id' => SUMMARY_COMP_ID,
    'year'    => (int)substr($todayIso, 0, 4),
    'month'   => (int)substr($todayIso, 5, 2)));
summaryLog('  leaveAPI.php        ' . ($leave === null ? 'unavailable' : 'read'));

$performance = summaryApi('performanceAPI.php', array(
    'comp_id'     => SUMMARY_COMP_ID,
    'year'        => (int)substr($todayIso, 0, 4),
    'month'       => (int)substr($todayIso, 5, 2),
    'performance' => 'demerit'));
summaryLog('  performanceAPI.php  ' . ($performance === null ? 'unavailable' : 'read'));

/* ---- every row, built once ---- */
$allRows = summaryStaffRows($people, $attByDay, $leave, $performance, $todayIso);
$withAny = 0;
foreach ($allRows as $r) { if ($r['flags'] > 0) { $withAny++; } }

summaryLog('');
summaryLog('Company-wide: ' . count($allRows) . ' staff, ' . $withAny . ' with something on them');
summaryLog(str_repeat('-', 72));

/* ---- one mail each ---- */
try {
    $pdo = Db::get();
} catch (Exception $e) {
    error_log('staffSummaryCron db: ' . $e->getMessage());
    summaryStop('Could not reach the database to record the run. Nothing was sent.', 503);
}

$now  = date('Y-m-d H:i:s');
$sent = 0; $skipped = 0; $failed = 0; $rebuilt = 0;
$pushed = 0; $pushNone = 0; $pushFailed = 0;

$doneStmt = $pdo->prepare(
    'SELECT token, acked_datetime, status, response, sent_datetime
       FROM mkPortal.staff_summary_notify
      WHERE notify_date = :d AND person = :p AND comp_id = :c');

$saveStmt = $pdo->prepare(
    'INSERT INTO mkPortal.staff_summary_notify
       (notify_date, person, comp_id, fullname, role, email_intended, email_sent_to,
        payload, late_count, leave_count, compliance_count, item_count, token,
        status, response, sent_datetime, created_datetime, updated_datetime)
     VALUES
       (:d, :p, :c, :fullname, :role, :intended, :sent_to,
        :payload, :late, :leave, :comp, :items, :token,
        :status, :response, :sent_at, :now, :now2)
     ON DUPLICATE KEY UPDATE
        fullname = VALUES(fullname), role = VALUES(role),
        email_intended = VALUES(email_intended), email_sent_to = VALUES(email_sent_to),
        payload = VALUES(payload), late_count = VALUES(late_count),
        leave_count = VALUES(leave_count), compliance_count = VALUES(compliance_count),
        item_count = VALUES(item_count), status = VALUES(status),
        response = VALUES(response), sent_datetime = VALUES(sent_datetime),
        updated_datetime = VALUES(updated_datetime)');

/* ---- the push list, and the log it writes ----
 *
 * READ ONCE, FOR A RUN THAT WILL ACTUALLY SEND. dry_run and rebuild send
 * nothing, so they ask the endpoint nothing and write no log rows — a
 * rehearsal that buzzed thirty handsets would not be a rehearsal.
 *
 * $pushReady IS THE GATE, and it is false until both halves are in place:
 * somebody on the list, and a table to write the attempt to. A missing
 * sql/staff_summary_push.sql therefore costs the morning its push and not
 * its mail. */
$pushList  = array();
$pushStmt  = null;
$pushReady = false;

if (SUMMARY_PUSH && !$dryRun && !$rebuild) {
    $pushList = summaryPushList($pdo, SUMMARY_COMP_ID);
    summaryLog('  daily_summary_report  ' . count($pushList) . ' on the push list');

    if ($pushList) {
        /* A RE-RUN REACHES THE SAME HANDSETS, so the four columns that
         * identify a send update the row rather than adding one, and
         * attempts is what says the morning went twice. The first
         * successful send keeps its sent_datetime. */
        try {
            $pushStmt = $pdo->prepare(
                'INSERT INTO mkPortal.staff_summary_push
                   (notify_date, person, comp_id, fullname,
                    user_id_intended, user_id_sent_to,
                    title, body, payload,
                    late_count, leave_count, compliance_count,
                    compare_date, late_delta, leave_delta, compliance_delta,
                    device_id, device_token, device_platform, device_group, device_name,
                    status, http_code, response, attempts, sent_datetime,
                    created_datetime, updated_datetime)
                 VALUES
                   (:d, :p, :c, :fullname,
                    :intended, :sent_to,
                    :title, :body, :payload,
                    :late, :leave, :comp,
                    :cmp_date, :late_d, :leave_d, :comp_d,
                    :dev_id, :token, :platform, :dev_group, :dev_name,
                    :status, :code, :response, 1, :sent_at,
                    :now, :now2)
                 ON DUPLICATE KEY UPDATE
                    fullname = VALUES(fullname),
                    user_id_sent_to = VALUES(user_id_sent_to),
                    title = VALUES(title), body = VALUES(body),
                    payload = VALUES(payload),
                    late_count = VALUES(late_count), leave_count = VALUES(leave_count),
                    compliance_count = VALUES(compliance_count),
                    compare_date = VALUES(compare_date),
                    late_delta = VALUES(late_delta), leave_delta = VALUES(leave_delta),
                    compliance_delta = VALUES(compliance_delta),
                    device_token = VALUES(device_token),
                    device_platform = VALUES(device_platform),
                    device_group = VALUES(device_group),
                    device_name = VALUES(device_name),
                    status = VALUES(status), http_code = VALUES(http_code),
                    response = VALUES(response),
                    attempts = attempts + 1,
                    sent_datetime = IFNULL(VALUES(sent_datetime), sent_datetime),
                    updated_datetime = VALUES(updated_datetime)');
            $pushReady = true;
        } catch (PDOException $e) {
            error_log('staffSummaryCron push table: ' . $e->getMessage());
            summaryLog('  ! mkPortal.staff_summary_push could not be written to — '
                     . 'nothing will be pushed (run sql/staff_summary_push.sql)');
        }
    }
}

/* ---------- which sections, for whom ------------------------------------- *
 *
 * READ ONCE FOR THE WHOLE RUN, not once per recipient: four catalogue rows and
 * a handful of exceptions, asked for inside a loop over thirty-odd people,
 * would be seventy queries for an answer that cannot change mid-run.
 *
 * A module that is NOT built in is FETCHED PER PERSON further down, because
 * its figures are about that one person — RO1 Work Achievement answers for
 * the districts one manager holds, so there is nothing to read once and share.
 */
$modCatalogue = dsModCatalogue(Db::get());
$modStaffRows = dsModStaffRows(Db::get(), SUMMARY_COMP_ID);

summaryLog('  modules             ' . count($modCatalogue) . ' active'
         . (dsModInstalled()
              ? ''
              : ' (the module tables are NOT installed — falling back to the '
                . 'original three; run sql/staff_summary_module.sql)'));

foreach ($recipients as $key => $recipient) {
    /* ONLY THE PEOPLE A SECTION IS ABOUT. The three sections each list their
     * own, so somebody who was on time, took no leave and collected nothing
     * is in none of them — and carrying them this far only to print a row of
     * dashes is what made the old single table so long. in_late, in_leave and
     * in_comp were decided once, in summaryStaffRows(). */
    $groups = summaryRelevantOnly(
        summaryGroupsFor($allRows, $recipient, $key, $people, $unitKinds, $unitHeads));
    $rows   = summaryFlatten($groups);

    /* NOTHING TO LOOK AT, NOTHING SENT. A department with a clean sheet would
     * otherwise get a daily all-clear — the mail people learn to delete
     * unread, and an acknowledgement of nothing is worth nothing. */
    if (empty($rows)) {
        $skipped++;
        continue;
    }
    $flagged = count($rows);

    $doneStmt->execute(array(':d' => $todayIso, ':p' => $recipient['person'],
                             ':c' => SUMMARY_COMP_ID));
    $existing = $doneStmt->fetch();
    if ($existing && !empty($existing['acked_datetime'])) {
        summaryLog(sprintf('  %-20s already acknowledged at %s — not re-sent',
                           $recipient['person'], $existing['acked_datetime']));
        $skipped++;
        continue;
    }

    /* The token survives a re-run, so a link already in somebody's inbox keeps
     * working when the morning's run is repeated. random_bytes() and not
     * anything cheaper: this string is the whole authority the acknowledgement
     * page asks for, so it has to be unguessable rather than merely unlikely. */
    $token = ($existing && !empty($existing['token']))
        ? $existing['token'] : bin2hex(random_bytes(32));

    $ackUrl   = SUMMARY_ACK_URL . '?t=' . urlencode($token);
    $intended = $recipient['email'];
    $to       = SUMMARY_EMAIL_OVERRIDE !== '' ? SUMMARY_EMAIL_OVERRIDE : $intended;

    /* NO CAP HERE ANY MORE. summaryHtml() gives the whole list to each
     * section, which counts its own people and then shows as many as the
     * budget allows — see SUMMARY_MAIL_MAX_ROWS. Capping first was what made
     * a section's heading disagree with the section. */

    /* ---- this person's sections ----
     *
     * AFTER THE SKIP CHECKS, DELIBERATELY. Somebody with a clean department or
     * an acknowledgement already in is never reached here, so no endpoint is
     * called on their behalf — which is the difference between four HTTP
     * requests this morning and thirty-three.
     *
     * A FETCHED MODULE IS ONLY CALLED FOR SOMEBODY WHO IS SHOWN IT. Nobody has
     * RO1 Work Achievement until they are ticked for it, so on the morning
     * this first runs $modFetched is empty for all thirty-three and the run is
     * byte for byte the run it was yesterday.
     *
     * THE DAY ASKED FOR IS $todayIso, NOT date('Y-m-d'). A re-run of an
     * earlier day must ask the endpoint for THAT day, or the snapshot would
     * carry this morning's figures under yesterday's heading. */
    $modPersonKey = dsModKey(SUMMARY_COMP_ID, $recipient['person']);
    $modShown     = dsModAllowedKeys($modPersonKey, $modStaffRows, $modCatalogue);
    $modFetched   = array();

    foreach ($modShown as $modKey) {
        if (!empty($modCatalogue[$modKey]['built_in'])) { continue; }
        $modFetched[$modKey] = dsModFetch(
            $modCatalogue[$modKey],
            array('email'   => $recipient['email'],
                  'person'  => $recipient['person'],
                  'comp_id' => SUMMARY_COMP_ID),
            $todayIso
        );
        summaryLog(sprintf('  %-20s module %-10s %s',
            $recipient['person'], $modKey,
            $modFetched[$modKey]['ok']
                ? 'read ' . count($modFetched[$modKey]['data']['rows']) . ' row(s)'
                : 'NOT read: ' . $modFetched[$modKey]['error']));
    }

    /* WHAT MUST BE TICKED — allowed, has something in it, and must-acknowledge.
     * It replaces summaryItemKeys() wherever a day is judged complete: that
     * function tests only whether a section has content, which was the whole
     * rule while everybody got every section and had to tick all three. */
    $modBoxes = dsModBoxKeys($modPersonKey, $modStaffRows, $modCatalogue,
                             $groups, $modFetched);

    /* The snapshot is EVERY group, not the shown ones: the page is where the
     * ticking happens and it must hold the whole department.
     *
     * THE FETCHED MODULES ARE STORED WITH IT, answer and all. The page, the
     * API and the app read this and never call the endpoint themselves — the
     * same rule the three built-in sections live under, so what somebody
     * acknowledges is what they were sent. dsModFetch() has the reasoning.
     *
     * module_keys AND ack_keys ARE WRITTEN DOWN rather than recomputed by each
     * reader. They are what THIS MORNING decided; a tick changed at noon must
     * not retrospectively add a section to a summary already in somebody's
     * inbox, or move the finishing line on a day half acknowledged. */
    $payload = json_encode(array(
        'date'      => $todayIso,
        'recipient' => array(
            'person'   => $recipient['person'],
            'fullname' => $recipient['fullname'],
            'position' => $recipient['position'],
            'role'     => $recipient['role'],
            'units'    => array_keys($recipient['units']),
        ),
        'day_cols'    => $dayCols,
        'groups'      => $groups,
        'module_keys' => $modShown,
        'module_meta' => dsModMeta($modShown, $modCatalogue),
        'ack_keys'    => $modBoxes,
        DS_MOD_PAYLOAD_KEY => $modFetched,
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    /* The three figures the row keeps are the DEPARTMENT's totals, so a report
     * over the table can ask "how big was that morning" without opening the
     * payload. */
    /* The three figures the row keeps are how many PEOPLE each section
     * listed, which is what the table shows and what a report over this log
     * would want to compare. */
    $lateN = 0; $leaveN = 0; $compN = 0;
    foreach ($rows as $r) {
        if (!empty($r['in_late']))  { $lateN++; }
        if (!empty($r['in_leave'])) { $leaveN++; }
        if (!empty($r['in_comp']))  { $compN++; }
    }

    /* The unit leads the subject too, for the same reason it leads the mail:
     * three of these in one inbox are told apart by the department. */
    $own = array();
    foreach ($groups as $g) { if ($g['is_own']) { $own[] = $g['unit']; } }
    /* THE DATE IN FULL, Y-m-d. "27 Oct" reads fine on the day and badly a
     * month later in a search result; the subject is what somebody scrolls
     * an inbox against. */
    $subject = (!empty($own) ? implode(' · ', array_slice($own, 0, 2)) . ' — ' : '')
             . 'Staff Summary ' . $todayIso;

    $html = summaryHtml($recipient, $groups, $todayIso, $ackUrl, $dayCols,
                        $modShown, $modFetched, $modCatalogue, $modBoxes);

    if ($dryRun) {
        summaryLog(sprintf('  %-20s %3d staff, %3d flagged   -> %s  [dry run]',
            $recipient['person'], count($rows), $flagged,
            ($to !== '' ? $to : '(no address)')));
        $skipped++;
        continue;
    }

    /* The snapshot, rewritten in place — see rebuild=1 at the top. Nothing is
     * sent and nothing but the table itself changes: the token, the sent
     * status and the send time are the ones already in the row, so the link
     * in somebody's inbox still opens and the row still records the mail that
     * actually went this morning. */
    if ($rebuild) {
        if (!$existing) {
            summaryLog(sprintf('  %-20s no row today — not created by a rebuild',
                               $recipient['person']));
            $skipped++;
            continue;
        }
        $saveStmt->execute(array(
            ':d' => $todayIso, ':p' => $recipient['person'], ':c' => SUMMARY_COMP_ID,
            ':fullname' => $recipient['fullname'], ':role' => $recipient['role'],
            ':intended' => $intended, ':sent_to' => $to,
            ':payload'  => $payload,
            ':late'     => $lateN,
            ':leave'    => $leaveN,
            ':comp'     => $compN,
            ':items'    => count($modBoxes),
            ':token'    => $token,
            ':status'   => $existing['status'],
            ':response' => (string)$existing['response'],
            ':sent_at'  => $existing['sent_datetime'],
            ':now'      => $now, ':now2' => $now,
        ));
        $rebuilt++;
        summaryLog(sprintf('  %-20s %3d staff, %3d flagged   -> snapshot rewritten',
                           $recipient['person'], count($rows), $flagged));
        continue;
    }

    $result = ($to === '')
        ? array('ok' => false, 'response' => 'no email address on file')
        : summarySend($to, $subject, $html);

    $saveStmt->execute(array(
        ':d' => $todayIso, ':p' => $recipient['person'], ':c' => SUMMARY_COMP_ID,
        ':fullname' => $recipient['fullname'], ':role' => $recipient['role'],
        ':intended' => $intended, ':sent_to' => $to,
        ':payload'  => $payload,
        ':late'     => $lateN,
        ':leave'    => $leaveN,
        ':comp'     => $compN,
        /* item_count is what must be TICKED: one box per SECTION this person
         * is shown, has something in and is held to. Somebody late and on
         * leave counts twice; a section they are shown for information only
         * counts not at all. dsModBoxKeys() is the rule. */
        ':items'    => count($modBoxes),
        ':token'    => $token,
        ':status'   => $result['ok'] ? 'sent' : 'failed',
        ':response' => substr($result['response'], 0, 255),
        ':sent_at'  => $result['ok'] ? $now : null,
        ':now'      => $now, ':now2' => $now,
    ));

    if ($result['ok']) { $sent++; } else { $failed++; }

    summaryLog(sprintf('  %-20s %3d staff, %3d flagged   -> %-32s %s',
        $recipient['person'], count($rows), $flagged,
        ($to !== '' ? $to : '(no address)'),
        $result['ok'] ? 'sent' : 'FAILED: ' . $result['response']));

    /* ---- and the push, for whoever is also on the push list ----
     *
     * AFTER THE MAIL, AND WHATEVER THE MAIL DID. $result is not consulted:
     * the mail failing is a reason to push, not a reason not to.
     *
     * The baseline is read here, one query per person, rather than in a pass
     * of its own — only the people being pushed need one, and they are the
     * short list. */
    if ($pushReady && isset($pushList[$key])) {
        /* ONE LINE PER FETCHED MODULE THIS PERSON WAS SENT, in section order,
         * so the push is ordered like the summary it is advertising. Built
         * here rather than inside summaryPushOne() because $modFetched belongs
         * to this loop, and threading the catalogue two levels down to reach
         * it would buy nothing. */
        $pushModLines = array();
        $pushModData  = array();
        foreach ($modShown as $mk) {
            if (empty($modCatalogue[$mk]) || !empty($modCatalogue[$mk]['built_in'])) { continue; }
            $line = summaryPushModuleLine($modCatalogue[$mk],
                        isset($modFetched[$mk]) ? $modFetched[$mk] : null);
            if ($line !== '') {
                $pushModLines[]   = $line;
                $pushModData[$mk] = $line;
            }
        }

        $push = summaryPushOne(
            $pdo, $pushStmt, $recipient, $pushList[$key]['user_id'],
            array('late' => $lateN, 'leave' => $leaveN, 'comp' => $compN),
            summaryPushPrevious($pdo, $recipient['person'], SUMMARY_COMP_ID, $todayIso),
            summaryPushUnits($own), $todayIso, $now,
            $pushModLines, $pushModData);

        if ($push['status'] === 'sent') {
            $pushed++;
        } elseif ($push['status'] === 'no_device') {
            $pushNone++;
        } else {
            $pushFailed++;
        }

        $note = $push['status'] === 'sent'
            ? ('sent to ' . $push['devices'] . ' device' . ($push['devices'] === 1 ? '' : 's'))
            : ($push['status'] === 'no_device'
                ? 'no device registered'
                : 'FAILED: ' . $push['response']);

        summaryLog(sprintf('  %-20s push -> users.id %-6d %s', '', $push['to'], $note));
    }
}

summaryLog(str_repeat('-', 72));
summaryLog($rebuild
    ? sprintf('%d snapshots rewritten, %d skipped (nothing flagged, already acknowledged, '
              . 'or no row today). Nothing was sent.', $rebuilt, $skipped)
    : sprintf('%d sent, %d skipped (nothing flagged, already acknowledged, or dry run), %d failed',
              $sent, $skipped, $failed));
if (SUMMARY_PUSH && !$dryRun && !$rebuild) {
    summaryLog(sprintf('%d pushed, %d with no device registered, %d push failures',
                       $pushed, $pushNone, $pushFailed));
    if ((int)SUMMARY_PUSH_TEST_USER_ID > 0) {
        summaryLog('Every push went to users.id ' . (int)SUMMARY_PUSH_TEST_USER_ID
                 . ' — SUMMARY_PUSH_TEST_USER_ID is set. Empty it, with '
                 . 'SUMMARY_EMAIL_OVERRIDE, to notify the managers themselves.');
    }
}
if (SUMMARY_EMAIL_OVERRIDE !== '' && !$dryRun && !$rebuild) {
    summaryLog('Every mail went to ' . SUMMARY_EMAIL_OVERRIDE
             . ' — SUMMARY_EMAIL_OVERRIDE is set. Empty it to mail the managers themselves.');
}
summaryLog(sprintf('Done in %.1fs.', microtime(true) - $startedAt));

echo "</pre></body></html>\n";
