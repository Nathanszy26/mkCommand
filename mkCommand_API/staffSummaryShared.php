<?php
/**
 * STAFF SUMMARY SHARED — the few things the daily mail and the acknowledgement
 * page must agree about.
 *
 * BOTH DRAW THE SAME TABLE: staffSummaryCron.php puts it in an email, and
 * staffSummaryAck.php puts a real tick box on every row of it hours later. If
 * they read a cell differently, somebody would be ticking a row that does not
 * say what they were sent — so what a cell MEANS lives here, in one place, and
 * neither file owns a copy of it.
 *
 * PLAIN TEXT OUT, MARKUP NOWHERE. Every function here returns words and
 * numbers: no tags, no entities, no colours. Each renderer escapes and styles
 * what it gets, and says which colour a cell is from the flags it is handed.
 *
 * That is not tidiness, it is the fix for a real bug. This file used to return
 * ready-made HTML, which works only as long as every caller prints it raw and
 * never escapes it. One caller escaping it once turned the whole line into
 * visible junk — '&middot;' shown as text, a '<span>' printed at the reader
 * instead of colouring anything. A function that returns text cannot be
 * escaped wrongly, because escaping text is simply correct.
 *
 * NOTHING RUNS WHEN THIS IS INCLUDED. It defines and returns, which is the
 * whole reason it is a third file rather than one including the other: the
 * cron ends by sending mail, and a page that included it to borrow a helper
 * would send the mail too.
 *
 * PHP 7.2 compatible.
 */

if (defined('STAFF_SUMMARY_SHARED_LOADED')) {
    return;
}
define('STAFF_SUMMARY_SHARED_LOADED', true);

/** What a cell shows when there is simply nothing to show. One character, in
 *  one place, so the table does not read as a mix of '-', '–' and ''. */
define('SUM_DASH', '—');

/* ------- emergency leave and leave without pay: when a month stops -------
 * ------------------------- being an accident ---------------------------- */

/**
 * HOW MUCH OF ONE KIND OF LEAVE IS TOO MUCH IN ONE MONTH.
 *
 * THREE DAYS, OF EITHER CODE: three of Emergency Leave, or three of Leave
 * Without Pay. One EL is an emergency; three in the same month is a pattern,
 * and it is the pattern the manager reading this is meant to act on. LWP is
 * the same question asked of a different code — unpaid days keep turning up
 * — so it is flagged the same way and at the same number.
 *
 * THE TWO CODES ARE COUNTED SEPARATELY AND NEVER ADDED. One EL and two LWP is
 * NOT flagged: that is two unremarkable things in one month, not one pattern,
 * and the person has gone over nothing. A code has to reach the line on its
 * own. Adding them would also flag whoever happens to have one of each kind
 * of ordinary absence, which is most of a company.
 *
 * THE COMPARISON IS >=, so "3 or more" means exactly what it says. The codes
 * and the number are here and nowhere else, so the mail's red mark, the
 * acknowledgement page's and the app's badge cannot start disagreeing about
 * who is flagged.
 */
define('SUMMARY_EL_CODE', 'EL');
define('SUMMARY_LWP_CODE', 'LWP');
define('SUMMARY_EL_ALERT', 3);

/** The codes this rule watches, in the order their marks are shown. Named
 *  once so nothing has to repeat the pair. */
function summaryAlertCodes()
{
    return array(SUMMARY_EL_CODE, SUMMARY_LWP_CODE);
}

/**
 * Query: how many days of ONE leave code this person has in the month shown.
 *
 * READ OFF leave_by_code WHEN THE ROW HAS IT, which is the day map counted
 * per code at build time — half days included, exactly as the Taken figure
 * beside it counts them. Eric John's three EL dates are 2.5 days because the
 * 8th was a half, and a badge saying 3 EL beside a figure saying 2.5 was
 * wrong twice over: it disagreed with its own row, and it turned somebody
 * under the line into somebody over it.
 *
 * THE COUNT BELOW IS THE FALLBACK, for a payload written before the day map
 * was carried. It counts whole dates off the applications and cannot see a
 * half day, so it reads 3 where this reads 2.5 — eager, which is the right
 * way round for a warning but not something to live with. A snapshot gets the
 * exact figure the next time the cron writes it.
 *
 * COUNTED OFF THE APPLICATIONS, not off the leave figures, because the
 * figures are every kind of leave added together and this is a question about
 * one kind. Each application of that code contributes the days it covers
 * INSIDE THIS MONTH — a span running into the next month is clipped, so
 * October's figure is October's and November gets the rest.
 *
 * DAYS ARE COUNTED ONCE, WHICH IS WHY THIS COLLECTS DATES RATHER THAN ADDING
 * SPANS UP. Two applications can land on the SAME DAY — a morning and an
 * afternoon filed separately, or a correction filed over the top — and adding
 * their lengths counted that day twice. Axther Adver Anthony had two EL
 * applications both dated 14 Oct 2026 and read as 8 days of EL when leaveAPI,
 * which keys leave by day, said 7. A day somebody was away is one day however
 * many forms it took.
 *
 * IN THE FALLBACK A HALF DAY COUNTS AS A WHOLE ONE, and that is the one place
 * it can differ from leaveAPI's by_type for the code: applications carry a
 * date range and no half flag, so there is nothing to halve and two half days
 * on two dates read as 2 here and 1.0 there. leave_by_code above has no such
 * problem, which is why it is tried first.
 *
 * $monthYm is 'YYYY-MM' — the month the summary is about, which is the
 * notify_date's month and not today's. $code is the short code as leaveAPI
 * prints it: 'EL', 'LWP'.
 */
function summaryLeaveCodeDays($staffRow, $monthYm, $code)
{
    $want = strtoupper(trim((string)$code));
    if ($want === '' || !is_array($staffRow)) {
        return 0;
    }

    /* The day map's answer, if this row carries one. It is already the
     * month's — leaveAPI was read for the month — so there is nothing to
     * clip and nothing to de-duplicate: a day is one entry in it however
     * many forms it took. */
    if (isset($staffRow['leave_by_code']) && is_array($staffRow['leave_by_code'])) {
        return isset($staffRow['leave_by_code'][$want])
             ? (float)$staffRow['leave_by_code'][$want] : 0.0;
    }

    $ym = substr((string)$monthYm, 0, 7);
    if ($ym === '' || empty($staffRow['leaves'])) {
        return 0;
    }

    $first = $ym . '-01';
    $t     = strtotime($first);
    $last  = $t ? date('Y-m-t', $t) : $ym . '-31';

    /* The set of dates, so a date reached twice is still one day. */
    $days = array();
    foreach ($staffRow['leaves'] as $l) {
        $code = strtoupper(trim(isset($l['code']) ? (string)$l['code'] : ''));
        if ($code !== $want) { continue; }

        $from = substr(isset($l['from']) ? (string)$l['from'] : '', 0, 10);
        $to   = substr(isset($l['to']) ? (string)$l['to'] : $from, 0, 10);
        if ($from === '') { continue; }
        if ($to === '' || $to < $from) { $to = $from; }

        /* Clipped to the month before counting, so the figure is about the
         * month this summary covers. */
        if ($from < $first) { $from = $first; }
        if ($to > $last)    { $to = $last; }
        if ($to < $from)    { continue; }

        $a = strtotime($from);
        $b = strtotime($to);
        if ($a === false || $b === false) { continue; }

        /* Walked a day at a time with strtotime('+1 day') rather than by
         * adding 86400: a clock change would make some days 23 hours long and
         * quietly skip one. */
        for ($d = $a; $d <= $b; $d = strtotime('+1 day', $d)) {
            $days[date('Y-m-d', $d)] = true;
        }
    }
    return count($days);
}

/** Query: days of Emergency Leave in the month shown. */
function summaryElDays($staffRow, $monthYm)
{
    return summaryLeaveCodeDays($staffRow, $monthYm, SUMMARY_EL_CODE);
}

/** Query: days of Leave Without Pay in the month shown. */
function summaryLwpDays($staffRow, $monthYm)
{
    return summaryLeaveCodeDays($staffRow, $monthYm, SUMMARY_LWP_CODE);
}

/**
 * Query: every code this person has reached the line on, as a list of
 * array('code' => 'EL', 'days' => 4, 'text' => '4 EL'). EMPTY MEANS NOT
 * FLAGGED, which is the whole test — nothing else needs to know the number.
 *
 * A LIST AND NOT A BOOLEAN-PLUS-A-COUNT, because a person can be over on both
 * codes and the mark has to say which. 'text' is built here so the mail, the
 * page and the app cannot label the same mark three ways.
 */
function summaryLeaveMarks($staffRow, $monthYm)
{
    $marks = array();
    foreach (summaryAlertCodes() as $code) {
        $days = summaryLeaveCodeDays($staffRow, $monthYm, $code);
        if ($days >= SUMMARY_EL_ALERT) {
            $marks[] = array(
                'code' => $code,
                'days' => $days,
                /* summaryNum() because a half day is half a day on the badge
                 * too: '3.5 EL', never '3.5000 EL' and never a silent 4. */
                'text' => summaryNum($days) . ' ' . $code,
            );
        }
    }
    return $marks;
}

/** Query: is this person over the line on EITHER code in the month shown?
 *  The one test the mail, the page and the app all make. */
function summaryElAlert($staffRow, $monthYm)
{
    return summaryLeaveMarks($staffRow, $monthYm) !== array();
}

/** Query: a person key that can be compared. Every API prints `person` as it
 *  is stored, and the same login turns up as 'sooCK' and 'soock' across
 *  tables, so nothing in this family compares them any other way. */
function summaryKey($person)
{
    return strtolower(trim((string)$person));
}

/** Query: text as HTML. */
function summaryEsc($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Query: a date as it reads to a person — "Mon, 27 Oct". */
function summaryDay($iso)
{
    $t = strtotime((string)$iso . ' 00:00:00');
    return $t ? date('D, j M', $t) : (string)$iso;
}

/** Query: the column heading for a day — "Mon 26", and "Tue 27 (Today)" for
 *  the current one. The date is on BOTH columns on purpose: a heading that
 *  just says "Today" leaves the reader working out what the other one is, and
 *  a mail read the next morning has no way to tell them. */
function summaryDayShort($iso, $todayIso)
{
    $t     = strtotime((string)$iso . ' 00:00:00');
    $label = $t ? date('D j', $t) : (string)$iso;
    return ((string)$iso === (string)$todayIso) ? $label . ' (Today)' : $label;
}

/** Query: the month a column covers, as the heading prints it — "2026-10".
 *  Spelled out rather than left as "this month" because the mail is read
 *  beside others and filed; "this month" stops being true the moment it is. */
function summaryMonthLabel($iso)
{
    return substr((string)$iso, 0, 7);
}

/**
 * Query: ONE PERSON'S ONE DAY, as the two lines of a lateness cell.
 *
 *   in / in_late     the 8:00am slot. attendanceAPI.php has already decided
 *                    what is late — status is its word, and the time is never
 *                    re-judged here. in_late is what makes the figure red.
 *   out / out_missing  the 4:30pm slot. A check-out that is not there is the
 *                    thing worth seeing, so it reads 'Missing' and goes red;
 *                    a check-out that IS there is never red, however late it
 *                    was, because nothing at 4:30pm is late — see ATT_PM_FROM
 *                    in attendanceAPI.php.
 *   in_missing       NO CHECK-IN AT ALL. Stated, not left blank — see below.
 *   show_out         whether this column carries a check-out at all. See
 *                    $showOut.
 *   not_read         THE DAY WAS NEVER ASKED ABOUT — a Sunday, a public
 *                    holiday, or an API that did not answer. Shown as a single
 *                    dash and flagging nobody: failing to turn up on a day
 *                    that was not a working day is not a failure.
 *
 * NOTHING IS LEFT AS A BARE DASH WHERE SOMETHING IS WRONG. A person with no
 * check-in used to read '—', which says "no column here" rather than "this
 * person did not check in" — so a whole absent department looked like a
 * rendering fault instead of the loudest thing in the mail. A missing check-in
 * now says 'Missing' and is red, exactly as a missing check-out is.
 *
 * $showOut — DOES THIS COLUMN SHOW A CHECK-OUT? False for today, and that is
 * the whole of the fix for a report sent in the morning: at 9:30 nobody in the
 * company has checked out, so every single person would read red 'Missing' and
 * the column would say nothing except what time the mail went. Worse, every
 * row would then count as an exception, making "25 of 25 need a look" true and
 * useless. Yesterday is a finished day and shows both ends.
 *
 * $row is attendanceAPI.php's daily staff row, or null when the day was not
 * read at all.
 */
function summaryAttCell($row, $showOut = true, $onLeave = false)
{
    $out = array('in' => SUM_DASH, 'in_late' => false, 'in_missing' => false,
                 'out' => SUM_DASH, 'out_missing' => false,
                 'on_leave' => (bool)$onLeave, 'leave_label' => '',
                 'show_out' => (bool)$showOut, 'not_read' => false);

    if ($row === null) {
        $out['not_read'] = true;
        return $out;
    }

    $status = isset($row['status']) ? (string)$row['status'] : 'no_data';
    $pm     = isset($row['pm_status']) ? (string)$row['pm_status'] : 'no_data';

    /* ON LEAVE IS AN EXPLANATION, NOT A FAILURE, and it is checked first
     * because it changes what every other answer MEANS. Somebody approved off
     * work has not "failed to check in": calling them missing, in red, puts a
     * manager on the phone to chase an absence their own signature authorised.
     * It is the one state here that is neither good nor bad, which is why it
     * is neither green nor red but blue, and why it flags nobody.
     *
     * THE CELL BECOMES THE LEAVE, not a check-in that happens to say so. An
     * IN:/OUT: pair against 'on leave' is two labels for a day that has
     * neither — so the whole cell is the one thing that is true about it, and
     * says WHICH leave, because "Annual Leave" and "Sick Leave" are not the
     * same news. */
    if ($onLeave) {
        $out['leave_label'] = is_array($onLeave) && isset($onLeave['label'])
            ? $onLeave['label'] : 'On leave';
        /* Deliberately no in_late either. A half day taken in the morning
         * means an afternoon arrival, and marking that late would be the same
         * mistake one step along. */
        return $out;
    }

    /* NO CHECK-IN IS STATED, NOT LEFT BLANK. A bare dash says nothing — it
     * reads as "no column here" rather than "this person did not check in",
     * which is the loudest fact on the row. It goes red for the same reason a
     * late arrival does. */
    if ($status === 'no_data') {
        $out['in']         = 'Missing';
        $out['in_missing'] = true;
    } else {
        $out['in']      = substr((string)$row['time'], 0, 5);
        $out['in_late'] = ($status === 'late');
    }

    if (!$showOut) {
        return $out;
    }

    /* pm_status is 'has_data' or 'no_data' and never 'late': the afternoon is
     * a presence check, so a check-out is never red for being late. */
    if ($pm === 'has_data') {
        $out['out'] = substr((string)$row['pm_time'], 0, 5);
    } else {
        $out['out']         = 'Missing';
        $out['out_missing'] = true;
    }
    return $out;
}

/**
 * Query: the days of the month this person is on leave, as a set of day
 * numbers — what the lateness columns consult before calling anybody missing.
 *
 * SAME SOURCE AS THE LEAVE COLUMNS. days{} is leaveAPI.php's own day map, so
 * "on leave" in the Lateness column and the figure under Taken can never
 * disagree: both are reading the same days.
 */
function summaryLeaveDaySet($staffRow, array $typeNames = array())
{
    $out = array();
    if (!is_array($staffRow) || !isset($staffRow['days']) || !is_array($staffRow['days'])) {
        return $out;
    }
    foreach ($staffRow['days'] as $dayNo => $cell) {
        $code = (is_array($cell) && isset($cell['leave_type'])) ? (string)$cell['leave_type'] : '';
        $half = (is_array($cell) && isset($cell['half']))       ? (string)$cell['half']       : '';

        /* The readable name when the response gave us one, the stored code
         * otherwise. 'Annual Leave' is what a manager reads; 'AL' is what the
         * table stores, and it is better than nothing. */
        $name = ($code !== '' && isset($typeNames[$code])) ? $typeNames[$code] : $code;
        if ($name === '') { $name = 'On leave'; }

        /* half NAMES THE HALF THAT IS THE LEAVE: leaveAPI.php sets 'pm' when
         * the leave starts that afternoon and 'am' when it ends that morning.
         * So (AM) means the morning is the leave, not the half they worked. */
        if ($half !== '') {
            $name .= ' (' . strtoupper($half) . ')';
        }

        $out[(int)$dayNo] = array('code' => $code, 'label' => $name, 'half' => $half);
    }
    return $out;
}

/**
 * Query: this person's leave APPLICATIONS for the month, oldest first — what
 * the Excessive Leaves section lists under the figures.
 *
 * THE FIGURES SAY HOW MUCH, THESE SAY WHAT AND WHEN. "5 days taken" is a
 * number to compare; "AL 1 Oct to 3 Oct, EL 3 Oct to 5 Oct" is the thing a
 * manager recognises. days{} cannot produce this — it is a day map and has
 * thrown the spans away — so this reads leaves[], which is one entry per
 * application.
 *
 * THE CODE, NOT THE NAME. 'AL' and 'EL' are what the forms and the HR screens
 * say, and a list of them lines up where 'Annual Leave' and 'Emergency Leave'
 * would not. The Lateness section spells the name out instead, because there
 * it is one cell on its own with room for it.
 */
function summaryLeaveEntries($staffRow, $todayIso)
{
    $out = array();
    if (!is_array($staffRow) || !isset($staffRow['leaves']) || !is_array($staffRow['leaves'])) {
        return $out;
    }
    foreach ($staffRow['leaves'] as $l) {
        $from = isset($l['date_from']) ? (string)$l['date_from'] : '';
        $to   = isset($l['date_to'])   ? (string)$l['date_to']   : '';
        if ($from === '' || $to === '') {
            continue;
        }
        $out[] = array(
            'code' => isset($l['leave_type']) ? trim((string)$l['leave_type']) : '',
            'name' => isset($l['leave_type_name']) ? trim((string)$l['leave_type_name']) : '',
            'from' => $from,
            'to'   => $to,
            /* WHICH COLUMN IT BELONGS UNDER. An application that has already
             * started is listed under Taken even when it runs past today —
             * 24 to 28 Oct read on the 27th is leave somebody is in the
             * middle of, not leave they have coming. Only one that has not
             * begun is Upcoming.
             *
             * The DAYS still split at today, which is why a span like that
             * adds to both figures while appearing in one list. The figures
             * answer "how much" and the list answers "what". */
            'when' => ($from > (string)$todayIso) ? 'upcoming' : 'taken',
        );
    }
    usort($out, function ($a, $b) { return strcmp($a['from'], $b['from']); });
    return $out;
}

/** The three sections, in the order they are shown. Named once so the mail,
 *  the page and the tick keys cannot disagree about what a section is called
 *  or which flag decides who is in it. */
function summarySections()
{
    return array('late' => 'in_late', 'leave' => 'in_leave', 'comp' => 'in_comp');
}

/**
 * Query: every tick box on the answer — ONE A SECTION, keyed by its kind:
 * 'late', 'leave', 'comp'.
 *
 * ONE BOX A SECTION, NOT ONE A PERSON. A head confirms they have read the
 * section, which is a single act; a box against each of 140 names asked them
 * to perform it 140 times and turned a reading into a clicking exercise.
 * What is recorded is the same either way — that this person saw this list
 * on this day — and the shorter form is the one people actually complete.
 *
 * A SECTION WITH NOBODY IN IT HAS NO BOX. There is nothing to confirm about
 * an empty list, so it is not counted in the total and cannot hold the
 * acknowledgement up.
 */
function summaryItemKeys(array $groups)
{
    $out = array();
    foreach (summarySections() as $kind => $flag) {
        foreach ($groups as $g) {
            foreach (isset($g['rows']) ? $g['rows'] : array() as $r) {
                if (!empty($r[$flag])) {
                    $out[$kind] = true;
                    continue 3;
                }
            }
        }
    }
    return $out;
}

/**
 * Query: what ticking a section's box says, in the first person.
 *
 * ONE SENTENCE, THE SAME FOR ALL THREE SECTIONS, and used by the mail, the
 * page and the app alike — so what somebody agreed to in the email is word
 * for word what every other surface records them as agreeing to.
 *
 * IT NO LONGER PROMISES AN ACTION. The three used to read "...and will follow
 * it up", "...and will arrange cover", "...and will act on it", which asked a
 * manager to undertake something different in each box and made a tick an
 * undertaking rather than a reading. What is actually being recorded is that
 * this list was seen and understood, so that is what it says, once, in words
 * that work under any of the three headings.
 *
 * $kind is kept in the signature although every section now answers the same:
 * every caller passes it, and a section needing its own wording again would
 * otherwise have to be threaded back through all of them.
 */
function summaryConfirmLine($kind)
{
    return 'I have read and understand the above.';
}

/**
 * Query: is there anything in this row's attendance worth a Lateness line?
 *
 * WHAT PUTS SOMEBODY IN THAT SECTION, and nothing else does: a late check-in,
 * a check-in that never happened, or a check-out that never happened on a day
 * that had one to make. A day nobody worked and a day this person was signed
 * off are neither — summaryAttCell() has already cleared their flags, and this
 * skips them again rather than trusting that.
 */
function summaryHasLateness(array $row)
{
    foreach ($row['att'] as $day) {
        if ($day['not_read'] || $day['on_leave']) { continue; }
        if ($day['in_late'] || $day['in_missing']) { return true; }
        if ($day['show_out'] && $day['out_missing']) { return true; }
    }
    return false;
}

/**
 * Query: leave code => readable name, gathered from the whole response.
 *
 * days{} CARRIES THE CODE AND leaves[] CARRIES THE NAME, so one has to be
 * looked up through the other. Built once across every person rather than per
 * row: the codes are company-wide, and a type somebody else's record names is
 * the same type here.
 */
function summaryLeaveTypeNames($leave)
{
    $out = array();
    if (!is_array($leave) || !isset($leave['staff'])) {
        return $out;
    }
    foreach ($leave['staff'] as $row) {
        foreach (isset($row['leaves']) ? $row['leaves'] : array() as $l) {
            $code = isset($l['leave_type'])      ? trim((string)$l['leave_type'])      : '';
            $name = isset($l['leave_type_name']) ? trim((string)$l['leave_type_name']) : '';
            if ($code !== '' && $name !== '' && !isset($out[$code])) {
                $out[$code] = $name;
            }
        }
    }
    return $out;
}

/**
 * Query: one person's leave THIS MONTH, split at today — taken, still to come,
 * and the two added up.
 *
 * COUNTED OFF days{}, NOT off leaves[]. leaveAPI.php's day map is keyed by day
 * of the month and gives each day to the shortest record covering it, so a day
 * is never counted twice when one application sits inside another. A list of
 * applications cannot be split at today without re-deriving all of that.
 *
 * HALF DAYS COUNT HALF, which is why these come back as floats. `half` on a
 * day is 'am', 'pm' or '' and leaveAPI.php's own day_count weights it the same
 * way — so Taken + Upcoming equals the total that endpoint publishes, and a
 * day and a morning is 1.5 here exactly as it is there.
 *
 * TODAY COUNTS AS TAKEN. Somebody on leave this morning is not "upcoming".
 */
/**
 * Query: the month's leave counted PER CODE off the day map — 'EL' => 2.5,
 * 'AL' => 1 — for the row the snapshot stores.
 *
 * THE SAME COUNT AS summaryLeaveCells(), SPLIT BY CODE. Both walk days{} and
 * both call a half day half a day, so the badge beside a name and the Taken
 * figure under it can never tell different stories about the same person.
 * This is what leaveAPI prints as summary.by_type, arrived at from the same
 * day map rather than trusted from a second field.
 *
 * $leaveRow is leaveAPI's own staff row, NOT the summary row — this is built
 * at cron time, while that is still in hand, because the day map is not
 * stored and the acknowledgement page hours later has only what was.
 *
 * A DAY WITH NO CODE IS COUNTED NOWHERE. It still belongs in the Taken
 * figure, which is about days off whatever they were for, but this answers
 * "how much EL" and an unnamed day is not evidence of any code.
 */
function summaryLeaveCodeTotals($leaveRow)
{
    $out = array();
    if (!is_array($leaveRow) || !isset($leaveRow['days']) || !is_array($leaveRow['days'])) {
        return $out;
    }
    foreach ($leaveRow['days'] as $cell) {
        $code = (is_array($cell) && isset($cell['leave_type']))
              ? strtoupper(trim((string)$cell['leave_type'])) : '';
        if ($code === '') { continue; }
        $worth = (is_array($cell) && isset($cell['half']) && $cell['half'] !== '') ? 0.5 : 1.0;
        $out[$code] = (isset($out[$code]) ? $out[$code] : 0.0) + $worth;
    }
    return $out;
}

function summaryLeaveCells($staffRow, $todayDay)
{
    $out = array('taken' => 0.0, 'upcoming' => 0.0, 'total' => 0.0);
    if (!is_array($staffRow)) {
        return $out;
    }
    $days = isset($staffRow['days']) && is_array($staffRow['days']) ? $staffRow['days'] : array();

    foreach ($days as $dayNo => $cell) {
        $worth = (is_array($cell) && isset($cell['half']) && $cell['half'] !== '') ? 0.5 : 1.0;
        if ((int)$dayNo <= (int)$todayDay) {
            $out['taken'] += $worth;
        } else {
            $out['upcoming'] += $worth;
        }
    }
    $out['total'] = $out['taken'] + $out['upcoming'];
    return $out;
}

/** Query: a leave figure as the table prints it — '' for nothing at all, so a
 *  clean month reads as empty space rather than a wall of zeroes, and '1.5'
 *  rather than '1.50' for a day and a morning. */
function summaryNum($n)
{
    if ($n <= 0) {
        return '';
    }
    return (floor($n) == $n) ? (string)(int)$n : rtrim(rtrim(number_format($n, 1), '0'), '.');
}

/**
 * Query: does this row have anything on it worth a manager's eye?
 *
 * WHAT PUTS A ROW AT THE TOP OF THE TABLE, and what the mail lists first when
 * it cannot list everybody. A late arrival, a missing check-out, any leave,
 * any compliance. Being absent all day counts too — it is the loudest thing on
 * the row even though no single cell is red.
 *
 * A DAY THAT WAS NOT READ IS NOT AN EXCEPTION. Sunday is not a failure to
 * attend, so not_read never counts here.
 */
function summaryRowFlags(array $row)
{
    $n = 0;
    foreach ($row['att'] as $day) {
        /* Neither a day nobody worked nor a day this person was signed off
         * is a failure to attend. summaryAttCell() has already cleared the
         * flags on both, and this says so twice rather than relying on it. */
        if ($day['not_read'] || $day['on_leave']) { continue; }
        if ($day['in_late'])    { $n++; }
        if ($day['in_missing']) { $n++; }
        /* A column that does not show a check-out cannot flag one — today's
         * does not, because the slot has not come round when the mail goes
         * out. See $showOut in summaryAttCell(). */
        if ($day['show_out'] && $day['out_missing']) { $n++; }
    }
    /* LEAVE DOES NOT COUNT. It used to, and it was the single biggest reason
     * "22 of 35 need a look" meant nothing: in a month where most people take
     * a day at some point, most of the department is flagged for having done
     * the approved thing. Leave is reported in its own three columns, which is
     * where somebody reads it — "needs a look" is for what went wrong.
     *
     * This is the same judgement as marking a day's absence 'on leave' in blue
     * rather than 'Missing' in red, one level up: an authorised absence is not
     * an exception, in the cell or in the count. */
    if ($row['comp'] > 0) { $n++; }
    return $n;
}

/* ---------- acknowledging, from wherever ---------------------------------- */

/**
 * Query: the platforms an acknowledgement may come from.
 *
 * THE WHOLE VOCABULARY, IN ONE PLACE. The column is VARCHAR and would take
 * anything; this is what stops three clients inventing three spellings of the
 * same word ('ios', 'iOS', 'mobile-app') and making the log unreportable.
 * Anything not on this list is recorded as 'other' rather than rejected —
 * losing an acknowledgement over a spelling would be the worse failure.
 *
 *   email   a box or the button pressed in the mail itself
 *   web     staffSummaryAck.php, the page the mail's link opens
 *   mobile  the app
 *   other   something that did not say, or said something unknown
 */
function summaryPlatforms()
{
    return array('email', 'web', 'mobile', 'other');
}

/** Query: a platform name as it will be stored — one of summaryPlatforms(),
 *  never anything else. */
function summaryPlatform($raw)
{
    $p = strtolower(trim((string)$raw));
    return in_array($p, summaryPlatforms(), true) ? $p : 'other';
}

/**
 * Query: what is ticked so far on a stored row, filtered against what CAN be
 * ticked — array of key => true.
 *
 * The filter is the point. A row's payload can be refreshed by a re-run while
 * somebody is part-way through, and a key from the older table would otherwise
 * count towards a total it is no longer part of.
 */
function summaryTickedSoFar($ackedItems, array $want)
{
    $out    = array();
    $stored = json_decode((string)$ackedItems, true);
    if (is_array($stored)) {
        foreach ($stored as $k) {
            if (isset($want[(string)$k])) { $out[(string)$k] = true; }
        }
    }
    return $out;
}

/**
 * Query: is this set of ticks the whole of what was asked for?
 *
 * ONE DEFINITION OF "DONE", shared by the page and the API so the mail, the
 * web and the app cannot disagree about whether a morning is finished.
 *
 * $submitted says whether somebody actually pressed something, as opposed to
 * merely arriving. It matters only in the empty case: a day with all three
 * sections empty has nothing to tick, and pressing Acknowledge completes it,
 * while opening the link does not.
 */
function summaryAckComplete(array $want, array $ticked, $submitted)
{
    if (empty($want)) {
        return (bool)$submitted;
    }
    return count($ticked) >= count($want);
}

/**
 * Command: write ticks to a notify row, and the verdict when they complete it.
 * Returns array('ticked', 'complete', 'added', 'removed', 'already').
 *
 * THE ONLY PLACE AN ACKNOWLEDGEMENT IS WRITTEN. staffSummaryAck.php and
 * staffSummaryAPI.php both come here, so the web page, the mail and the app
 * record the same thing in the same way — which is the whole reason the API
 * exists. Add a fourth client and it comes here too.
 *
 * $replace true  the submission IS the answer: ticks not in it are cleared,
 *                so un-ticking a box on a page or in the app means what it
 *                looks like.
 *          false the submission ADDS: used by the one-box-at-a-time route
 *                from the mail, where a link can only ever say yes and
 *                arriving twice must not undo anything.
 *
 * IT CANNOT UN-ACKNOWLEDGE. The UPDATE carries `acked_datetime IS NULL`, so a
 * late submission against an already-acknowledged day changes nothing and says
 * so with 'already' — including when two platforms arrive at once.
 */
function summaryAckWrite(PDO $pdo, array $row, array $want, array $ticks,
                         $platform, $replace, $ip = '', $agent = '')
{
    $platform = summaryPlatform($platform);
    $before   = summaryTickedSoFar($row['acked_items'], $want);

    if (!empty($row['acked_datetime'])) {
        return array('ticked' => $before, 'complete' => true, 'added' => array(),
                     'removed' => array(), 'already' => true);
    }

    /* What is being asked for, filtered to what exists. */
    $asked = array();
    foreach ($ticks as $t) {
        if (isset($want[(string)$t])) { $asked[(string)$t] = true; }
    }

    $after   = $replace ? $asked : ($before + $asked);
    $added   = array_keys(array_diff_key($after, $before));
    $removed = array_keys(array_diff_key($before, $after));

    $complete = summaryAckComplete($want, $after, true);
    $now      = date('Y-m-d H:i:s');

    $sql = 'UPDATE mkPortal.staff_summary_notify
               SET acked_items = :items,
                   acked_datetime = ' . ($complete ? ':now' : 'acked_datetime') . ',
                   acked_platform = ' . ($complete ? ':plat' : 'acked_platform') . ',
                   acked_agent = ' . ($complete ? ':agent' : 'acked_agent') . ',
                   acked_ip = :ip,
                   updated_datetime = :now2
             WHERE id = :id AND acked_datetime IS NULL';

    $args = array(':items' => json_encode(array_keys($after), JSON_UNESCAPED_SLASHES),
                  ':ip'    => substr((string)$ip, 0, 45),
                  ':now2'  => $now,
                  ':id'    => (int)$row['id']);
    if ($complete) {
        $args[':now']   = $now;
        $args[':plat']  = $platform;
        $args[':agent'] = substr((string)$agent, 0, 255);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);

    /* NOTHING UPDATED MEANS SOMEBODY ELSE GOT THERE FIRST — another platform
     * acknowledged between the read and this write. Their answer stands. */
    if ($stmt->rowCount() === 0) {
        $fresh = $pdo->prepare('SELECT acked_items, acked_datetime
                                  FROM mkPortal.staff_summary_notify WHERE id = :id');
        $fresh->execute(array(':id' => (int)$row['id']));
        $got = $fresh->fetch();
        if ($got && !empty($got['acked_datetime'])) {
            return array('ticked' => summaryTickedSoFar($got['acked_items'], $want),
                         'complete' => true, 'added' => array(), 'removed' => array(),
                         'already' => true);
        }
    }

    foreach ($added as $k)   { summaryAckEvent($pdo, $row, 'tick',   $k, $platform, $ip, $agent); }
    foreach ($removed as $k) { summaryAckEvent($pdo, $row, 'untick', $k, $platform, $ip, $agent); }
    if ($complete)           { summaryAckEvent($pdo, $row, 'ack',    '', $platform, $ip, $agent); }

    return array('ticked' => $after, 'complete' => $complete, 'added' => $added,
                 'removed' => $removed, 'already' => false);
}

/**
 * Command: append one line to the acknowledgement log.
 *
 * NEVER FAILS THE THING IT IS LOGGING. The log is a record of an
 * acknowledgement, not part of one: if the table is missing or the insert
 * fails, the acknowledgement above it still stands and the error goes to the
 * PHP log. Losing a line of history is not worth refusing somebody's tick.
 */
function summaryAckEvent(PDO $pdo, array $row, $action, $item, $platform, $ip = '', $agent = '')
{
    try {
        $ins = $pdo->prepare(
            'INSERT INTO mkPortal.staff_summary_ack_event
                 (notify_id, notify_date, person, comp_id, action, item,
                  platform, ip, agent, created_datetime)
             VALUES (:nid, :d, :p, :c, :a, :i, :plat, :ip, :agent, :now)');
        $ins->execute(array(
            ':nid'   => (int)$row['id'],
            ':d'     => $row['notify_date'],
            ':p'     => $row['person'],
            ':c'     => isset($row['comp_id']) ? (int)$row['comp_id'] : 1,
            ':a'     => $action,
            ':i'     => substr((string)$item, 0, 32),
            ':plat'  => summaryPlatform($platform),
            ':ip'    => substr((string)$ip, 0, 45),
            ':agent' => substr((string)$agent, 0, 255),
            ':now'   => date('Y-m-d H:i:s'),
        ));
    } catch (Exception $e) {
        error_log('staffSummary ack log: ' . $e->getMessage());
    }
}

/** Command: note that somebody looked, the first time anybody does. Opening is
 *  not acknowledging and is deliberately a different field — and a different
 *  action in the log — so "read it on a phone, acknowledged at a desk" is
 *  visible rather than inferred. */
function summaryAckOpened(PDO $pdo, array $row, $platform, $ip = '', $agent = '')
{
    if (!empty($row['opened_datetime'])) {
        return;
    }
    $upd = $pdo->prepare(
        'UPDATE mkPortal.staff_summary_notify
            SET opened_datetime = :now, opened_platform = :plat
          WHERE id = :id AND opened_datetime IS NULL');
    $upd->execute(array(':now'  => date('Y-m-d H:i:s'),
                        ':plat' => summaryPlatform($platform),
                        ':id'   => (int)$row['id']));

    if ($upd->rowCount() > 0) {
        summaryAckEvent($pdo, $row, 'open', '', $platform, $ip, $agent);
    }
}
