<?php
header('Content-Type: application/json; charset=UTF-8');

/**
 * ATTENDANCE API — did this person turn up, when, and where do we know it from.
 *
 * MEANT TO BE READ BESIDE staffPoolAPI.php, not instead of it. That endpoint
 * owns who exists and what the org looks like — the pool, hod[], am[], the
 * reporting line. This one answers the two attendance slots per person and
 * nothing else — 8:00am and 4:30pm — keyed by the SAME identity it prints:
 *
 *     person + comp_id + staff_type
 *
 * so a caller holding both joins them on that and has names, positions,
 * departments and attendance together. Describing the hierarchy here as well
 * would be a second copy of it, free to drift the day somebody reorganises.
 *
 * MUST be hosted on globportal.com beside staffPoolAPI.php: staff_portal2,
 * evaluation and mkPortal are all local there under prog4.
 *
 * Read-only. Every query in this file is a SELECT.
 *
 * IN (GET or POST):
 *   comp_id   int     company 1 only for now — see SUPPORTED_COMPS.
 *
 *   -- WHEN. One of these two, and daily is the default --
 *   daily     1       one day: today, or the day `date` names.
 *   date      Y-m-d   which day, with daily. Default today.
 *   year      int     with month: the whole month, the 1st to its last day.
 *   month     1-12
 *
 *   -- WHO. Everybody in the company unless you narrow it --
 *   staff     string  that person AND everybody under them in the reporting
 *                     line, at any depth. The org is read from
 *                     staffPoolTree.php, so "under them" means exactly what
 *                     staffPoolAPI.php's tree means by it — including the
 *                     placements written by hand there. No depth limit
 *                     applies: staff=jeffrey is the whole company.
 *
 * OUT:
 *   { success, comp_id, company_name, generated_at, mode, rules{}, scope{},
 *     holidays{}, counts{}, staff[] }
 *
 *   mode  "daily" | "month", with the dates it covers beside it.
 *
 *   holidays{}  the calendar for the range — { source, days{} } — with days
 *               keyed by Y-m-d EVEN IN MONTH MODE, where staff[].days is
 *               keyed by day of the month. One entry per day in the range,
 *               holiday or not: { is_public_holiday, titles[] }. A holiday is
 *               company-wide, so it is published here once instead of on 668
 *               people. In daily mode the one day's flag is repeated at the
 *               top level as is_public_holiday.
 *
 *   staff[] is one row per person, each ONCE:
 *     person, comp_id, staff_type       the key to join staffPoolAPI.php on
 *     fullname, position, department, district, hod_department_name
 *                                       enough to read this answer on its own
 *     hod_person, hod_unit              WHICH DEPARTMENT THEY BELONG TO: the
 *     am_person,  am_unit               nearest department head above them,
 *                                       and the nearest sub-department head.
 *                                       Themselves, when they are one.
 *     schedule{}                        the week they are SUPPOSED to work —
 *                                       see SCHEDULES below
 *     daily:  time, status, source, late_from      the 8:00am slot
 *             pm_time, pm_status, pm_source        the 4:30pm slot
 *             is_public_holiday
 *             is_off_day, shift_start, shift_end
 *     month:  summary{on_time,late,no_data}        the 8:00am slot
 *             pm_summary{has_data,no_data}         the 4:30pm slot
 *             days{ "1": {time,status,source,late_from,
 *                         pm_time,pm_status,pm_source,is_public_holiday,
 *                         is_off_day,shift_start,shift_end} }
 *
 *   TWO SLOTS, ONE CELL. The unprefixed fields are the morning and keep the
 *   names and the meanings they have always had, so a caller written before
 *   the afternoon existed reads exactly what it used to. The pm_ ones are the
 *   afternoon, and they are a DIFFERENT QUESTION with a different vocabulary:
 *   has_data / no_data, never on_time or late. Do not add a pm_summary to a
 *   summary — each covers every day already, so the sum would double-count.
 *
 *   GROUP A REPORT BY hod_unit AND am_unit, NOT BY A BRANCH. Each person has
 *   exactly one of each, so grouping covers the company once. The branches in
 *   staffPoolAPI.php overlap by design — everybody appears under every manager
 *   above them — and a report grouped by those would count people repeatedly.
 *   attendanceBoard.php groups by these two.
 *
 * THE RULES — two slots, 8:00am and 4:30pm, and nothing else is judged.
 *
 *   8:00am — PUNCTUALITY. Were they on time?
 *     status     on_time   checked in before late_from
 *                late      late_from or after
 *                no_data   no source has them that day
 *     source     attendance_app | site_check_in | face_log | "" when no_data
 *     late_from  the line THIS person was judged against THIS day
 *
 *   LATE IS MEASURED FROM THEIR OWN SHIFT, NOT FROM 8:01. It is that day's
 *   shift_start plus one minute, so somebody on the 7:00am schedule is late
 *   from 07:01:00 while most of the company is late from 08:01:00. The slot
 *   is still called 8AM — that is the app's period code, not a time — and
 *   SCHEDULES below says where each person's start comes from.
 *
 *   THE WHOLE STARTING MINUTE IS ON TIME. On an 8:00am shift 08:00:04 has
 *   not reached 8:01, so it is not late, and 08:01:00 is the first late
 *   second; on a 7:00am shift the same rule lands on 07:01:00. Every cell
 *   publishes its own late_from, so a caller applying it with < gets exactly
 *   what this file got. rules.periods.am.late_from is only the fallback for
 *   a day with no shift to read.
 *
 *   4:30pm — PRESENCE. Did they check in at all?
 *     pm_status   has_data   a source has them in that slot that day
 *                 no_data    none does
 *     pm_source   attendance_app | site_check_in | face_log | "" when no_data
 *     pm_time     the check-in, still published — worth reading even though
 *                 nothing is being marked against it
 *
 *   NOTHING AT 4:30PM IS LATE, AND THERE IS NO pm_late_from. The slot opens
 *   at 16:30 and people submit it across the rest of the evening, so a
 *   lateness line there would mark the diligent and the departed alike. The
 *   legacy report takes the same position —
 *   $ATT_PERIOD_LATE_CHECK['4.30PM'] is false in attendanceReportShared.php,
 *   which shows the 4:30pm check-in but never calls it LATE.
 *
 *   BOTH SLOTS ARE FOUND THE SAME WAY — the source chain below is one rule
 *   applied twice, with each read scoped to its own slot. What differs is
 *   only what is made of a hit.
 *
 *   A person is looked for in this order, and the FIRST hit wins:
 *     1. the attendance app — evaluation.staff_attendance for staff,
 *        evaluation.gw_attendance for a GW, at period 8AM for the morning and
 *        4.30PM for the afternoon, as staffAttendanceReport.php and
 *        gwAttendanceReport.php read them (self check-in, submitted from the
 *        app). The period codes are the app's own strings; the dot in
 *        '4.30PM' is part of the stored value.
 *     2. site check-in — mkPortal.staff_attendance_logs, as
 *        siteCheckInReport.php's STAFF report reads it. Lunch-break rows are
 *        not arrivals and are left out. Staff only: a GW has no users row and
 *        so never appears there.
 *
 *        THIS TABLE HAS NO SLOT OF ITS OWN — a row is a check-in at a moment
 *        — so the slot is a time window instead. The morning takes the
 *        earliest check-in of the day; the afternoon takes the earliest from
 *        16:30 onwards, which is when the app itself opens the 4.30PM slot.
 *        Without that window, a 7:40am arrival would be counted as the
 *        afternoon too, and every one of these thirty-odd people would read
 *        has_data every day.
 *     3. the face log — evaluation.staff_attendance_face_log, the HQ door
 *        terminals' own record, loaded from a spreadsheet one row per person
 *        per day. first_in is the morning and last_out the afternoon.
 *
 *        MATCHED THROUGH evaluation.staff_attendance_device, which says who
 *        a given number on a given terminal is: a reading's person_id only
 *        means something next to the device its upload header names, and
 *        HQ1,3 and HQ2 number their people differently. The face id on the
 *        users row is HQ1,3's numbering, and stands in as a fallback for that
 *        one terminal; faceLog() has the whole of it. Either way the answer
 *        is a users.id, so this arm is staff only: a GW has no users row and no
 *        terminal enrolment. It is LAST because it is a machine's record of a
 *        door and not a person's statement about their day — where somebody
 *        both submitted the app and walked past the reader, the submission is
 *        the answer.
 *
 *        last_out IS TAKEN AS IT STANDS, with no 16:30 window — unlike site
 *        check-in, which has one. A face-log row holds one first_in and one
 *        last_out for a date and there is nothing to mistake for a departure;
 *        and the hour means little anyway, since a file exported at 10am
 *        records everybody's last_out as a morning one.
 *     4. nothing: no_data.
 *
 *   The order is the point. On 20 Oct 2026 the app had 505 of Globinaco's
 *   people and site check-in had 30, with almost nobody in both; of the 104
 *   people the face log matched on 23 Oct 2026, 5 had also submitted the app.
 *   These are three populations doing three jobs, so each is a fallback for
 *   the one before it and never a tie-break.
 *
 *   EARLIEST WINS within a source and within the slot's window. Somebody who
 *   starts six patrol rounds in a day is judged on the first one, and a
 *   person with two app submissions on the same slot is judged on the
 *   earlier. Anything else would let a late second entry erase an on-time
 *   first one. For 4:30pm, where nothing is scored, it simply means the time
 *   shown is the check-in nearest the slot.
 *
 * SCHEDULES — WHICH DAYS THEY OWE, AND UNTIL WHEN.
 *   evaluation.staff_schedule_assign names a schedule_type for a person;
 *   evaluation.schedule_type holds the five of them, as a Mon-Fri column and
 *   a Saturday column of human strings — "8:00am - 4:30pm", or "Off".
 *
 *   ON THE PERSON, once:
 *     schedule{ schedule_type_id, schedule_source,
 *               mon_fri{is_off_day,start,end,label},
 *               saturday{…} }
 *
 *   ON EACH DAY, from whichever of those two patterns the date falls under:
 *     is_off_day   '1' nobody expected them in — Sunday, or a Saturday their
 *                      schedule has off
 *                  '0' they were due in, and the two below say when
 *     shift_start  'HH:MM:SS', or '' on an off day
 *     shift_end    'HH:MM:SS', or '' on an off day
 *
 *   A STRING, AND '0' IS TRUTHY IN JAVASCRIPT — test is_off_day as === '1',
 *   exactly like is_public_holiday.
 *
 *   schedule_source IS THE FIELD TO READ BEFORE TRUSTING THE HOURS:
 *     assigned   staff_schedule_assign names this person's schedule
 *     default    it does not, so ATT_SCHEDULE_DEFAULT_STAFF (2) or
 *                ATT_SCHEDULE_DEFAULT_GW (3) applied — a guess about them,
 *                and it says so rather than reading like a fact. At comp 1
 *                on 27 Oct 2026 that was 36 of 347 staff and 6 of 353 GW.
 *
 *   WHO IS LOOKED UP HOW. A staff member by users.id against
 *   staff_schedule_assign.staff_id, which resolves all 311 staff rows on its
 *   own; a GW by monthly_assign_gw_code, which is the same string the pool
 *   prints as their `person`. gw_staff_id is not used for it — three rows
 *   carry 0 there, so it is not an identity.
 *
 *   THE START SETS THE MORNING'S LINE. shift_start plus one minute is that
 *   cell's late_from, so the schedule decides punctuality: a 7:00am person
 *   is late from 07:01:00, an 8:00am person from 08:01:00. Change a
 *   schedule_type row and the verdicts move with it, which is the point of
 *   reading the table rather than hard-coding a time.
 *
 *   IT EXCUSES NOTHING, like the holiday flag beside it. A no_data on
 *   somebody's day off is still a no_data, and 4:30pm on a 12:30pm Saturday
 *   is still no_data: this endpoint judges the two slots, and whether a day
 *   off excuses an absence is a policy question the CALLER owns. Reports are
 *   expected to drop or grey the off days; counts.off_day says how many
 *   person-days that is, and those days are still inside the six status
 *   figures beside it.
 *
 *   AN OFF DAY HAS NO START, so its late_from falls back to ATT_LATE_FROM
 *   rather than being blank. Nothing is scored on those days anyway; the
 *   fallback is there so the field always holds a comparable time.
 *
 *   SUNDAY IS OFF FOR EVERYBODY, because schedule_type has no Sunday column.
 *   That is the shape of the table, not a rule invented here.
 *
 *   shift_end DOES NOT SCOPE THE 4:30PM READ. Site check-in is still windowed
 *   from ATT_PM_FROM for everyone — see ATT_PM_FROM — so somebody whose
 *   Saturday ends at 12:30pm has their afternoon looked for from 16:30
 *   regardless. Marking the schedule and reading by it are two changes, and
 *   this is the first.
 *
 * PUBLIC HOLIDAYS — MARKED, NEVER SCORED.
 *   is_public_holiday  '1'  that calendar day is a public holiday
 *                      '0'  it is not
 *                      ''   the holiday calendar could not be read at all
 *
 *   A STRING, AND '0' IS TRUTHY IN JAVASCRIPT — test it as === '1', never as
 *   if (cell.is_public_holiday). Three values rather than a boolean because
 *   "nobody could ask" is a different answer from "no", the same way
 *   source = '' is different from a source.
 *
 *   IT DOES NOT MOVE EITHER status. A no_data on Deepavali is still a
 *   no_data, morning and afternoon alike: this endpoint judges the two slots
 *   and nothing else, and whether a company holiday excuses an absence is a
 *   policy question the CALLER owns. The flag
 *   is here so a report can grey that column out, or leave those days out of
 *   its totals, with this file taking no position on which.
 *
 *   COMPANY-WIDE, like the table — public_holiday has no comp_id, and its
 *   State rows are Sabah, which is where company 1 is. The table's type
 *   (Federal/State) and pay_rate are deliberately not published: those are
 *   payroll's business, not attendance's.
 *
 *   hr.public_holiday ON 175.145.207.141 IS THE ONLY SOURCE. HR maintains
 *   that table, so this file asks HR and nobody else — the one answer to
 *   "which day is a holiday" rather than a choice of answers. It is the only
 *   read in this file that leaves the local box; everything else here is
 *   local under prog4, and ATT_HR_DSN is the one place that address lives.
 *
 *   A HOLIDAY IS AN ANNOTATION, SO LOSING IT IS NOT A FAILURE. If that host
 *   is unreachable or refuses the credentials, every flag is '' and
 *   holidays.source reads "unavailable" — the attendance in the same
 *   response is unaffected, because what the caller actually asked for is who
 *   turned up. Check holidays.source before trusting a column of '0's.
 *
 *   Copies of this table exist elsewhere — gas_usage.public_holiday is one,
 *   and the legacy report (attHolidaysByDay() in
 *   attendance/includes/attendanceReportShared.php) reads that one, one query
 *   per day. This file deliberately does not, so a copy drifting from HR's
 *   cannot quietly change this answer. It is worth knowing if a figure here
 *   ever disagrees with that report.
 *
 * PHP 7.2 compatible.
 */

require_once __DIR__ . '/staffPoolCore.php';

/* ---------- the rules ----------------------------------------------------- */

/** THE TWO SLOTS THIS ENDPOINT JUDGES. The app writes these exact strings
 *  into attendance_period / gw_attendance_period — see $ATT_PERIODS in
 *  attendance/includes/attendanceReportShared.php, which is where the codes
 *  come from. The dot in '4.30PM' is part of the stored code, not a typo.
 *  Change these or change that, never one of them. */
define('ATT_PERIOD_AM', '8AM');
define('ATT_PERIOD_PM', '4.30PM');

/**
 * HOW LONG THE STARTING MINUTE LASTS. Anything before start + this is on
 * time, so the whole of the starting minute counts: on an 8:00am shift
 * 08:00:04 has not reached 8:01 and is on time, and 08:01:00 is the first
 * late second. On a 7:00am shift the same rule puts it at 07:01:00.
 *
 * Expressed as the start of lateness rather than as a last-on-time value
 * because that is the rule in one unambiguous number: there is no "is the
 * boundary inclusive" question to get wrong. Compared as a string, which is
 * safe because both sides are zero-padded HH:MM:SS.
 */
define('ATT_LATE_GRACE_MINUTES', 1);

/**
 * THE MOMENT THE MORNING BECOMES LATE WHEN THERE IS NO SHIFT TO ASK.
 *
 * NOT THE COMPANY-WIDE LATENESS LINE ANY MORE — the line is each person's
 * own shift_start plus ATT_LATE_GRACE_MINUTES, worked out per person per day
 * by attLateFrom(). This is only the fallback for a day with no start time
 * to add to: an off day, or a schedule_type cell nobody can parse. It is
 * 08:01:00 because that is what a type-2 8:00am shift comes to, which is
 * what most of the company is on.
 *
 * Every cell publishes the line it was actually judged against, as
 * late_from, so no caller has to work out which of the two applied.
 *
 * THERE IS DELIBERATELY NO SUCH NUMBER FOR THE AFTERNOON. 4:30pm is a
 * presence check and not a punctuality one — has_data or no_data — which is
 * the legacy report's rule too: $ATT_PERIOD_LATE_CHECK['4.30PM'] is false in
 * attendanceReportShared.php, so a 4:30pm check-in is shown there but never
 * marked LATE.
 */
define('ATT_LATE_FROM', '08:01:00');

/**
 * WHEN THE AFTERNOON SLOT OPENS, and the only thing this time is used for:
 * deciding which site check-ins could be evidence of it. The app will not
 * submit a 4.30PM row before this — the earliest in October 2026 is 16:30:07
 * — so the fallback is held to the same line. Without it, somebody's 7am
 * arrival at a site would be read as them doing the 4:30pm slot.
 *
 * NOT a lateness boundary. Nothing at 4:30pm is late; see ATT_LATE_FROM.
 */
define('ATT_PM_FROM', '16:30:00');

/**
 * WHICH FACE TERMINAL users.attendance_face_id IS THE NUMBERING OF.
 *
 * evaluation.staff_attendance_device_type id 1 is HQ1,3, the iVMS-4200 — the
 * only door there was when attendance_face_id was filled in, and the reason
 * that column exists. For all 91 people HQ1,3 has in staff_attendance_device,
 * attendance_face_id and staff_device_id are the same number.
 *
 * NOT A SECOND WAY TO MATCH ANYBODY, and not a default. Every terminal has
 * its own numbering — on HQ2 (id 2, a ZKBio) the same numbers are different
 * people — so staff_attendance_device is what resolves a reading, and this is
 * only who HQ1,3's unmapped numbers mean. faceLog() applies it to that
 * device_id and no other; see the long note there for why.
 *
 * It becomes dead weight the day staff_attendance_device has a row for every
 * number HQ1,3 exports, and can be dropped then.
 */
define('ATT_FACE_DEVICE_LEGACY', 1);

/* ---------- the working week ---------------------------------------------- */

/**
 * WHICH SCHEDULE SOMEBODY IS ON WHEN NOBODY HAS SAID.
 *
 * evaluation.staff_schedule_assign names a schedule for most of the company
 * but not all of it: at comp 1 on 27 Oct 2026 it covers 311 of 347 staff and
 * 347 of 353 GW. The rest fall back to these, rather than to no schedule at
 * all — an unassigned person still works a week, and guessing nothing would
 * mean every Saturday reads as a working day for them.
 *
 * Staff default to 2 (Sat half day) and a GW to 3 (Sat full day), which is
 * what each group is mostly assigned to where it IS assigned.
 */
define('ATT_SCHEDULE_DEFAULT_STAFF', 2);
define('ATT_SCHEDULE_DEFAULT_GW',    3);

/**
 * THE DAY NOBODY HAS A SCHEDULE FOR. evaluation.schedule_type has a Mon-Fri
 * column and a Saturday column and no third one, so Sunday is off for
 * everybody by the shape of the table — not by a rule written here.
 */
define('ATT_SCHEDULE_SUNDAY_OFF', true);

/* ---------- the calendar -------------------------------------------------- */

/** HR's host, which holds the holiday table — the same host and credentials
 *  attHrDb() uses in attendance/includes/attendanceReportShared.php. The ONLY
 *  non-local read in this file, and the only place this address is written.
 *  Read-only, like everything else here. */
define('ATT_HR_DSN',  'mysql:host=175.145.207.141;port=3306;dbname=hr;charset=utf8mb4');
define('ATT_HR_USER', 'portal');
define('ATT_HR_PASS', 'portal2018');

/** The holiday table, and the only one consulted. Schema-qualified so it is
 *  unambiguous which copy this is: others exist and are not read. See PUBLIC
 *  HOLIDAYS at the top. */
define('ATT_HOLIDAY_TABLE_HR', 'hr.public_holiday');

/** Companies this endpoint answers for. The same single company
 *  staffPoolTree.php draws, and for the same reason — the org it scopes by is
 *  only built for Globinaco today. */
function attSupportedComps()
{
    return array(1);
}

/* ---------- plumbing ------------------------------------------------------ */

if (!function_exists('respond')) {
    function respond(array $payload, $status = 200)
    {
        http_response_code($status);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            /* The GW tables are latin1, so an invalid byte is a real
             * possibility; a partial answer naming the problem beats an empty
             * body and silence. */
            error_log('attendanceAPI json: ' . json_last_error_msg());
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                          | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($json === false) {
                http_response_code(500);
                $json = '{"success":false,"message":"Could not encode the answer."}';
            }
        }
        echo $json;
        exit;
    }
}

if (!function_exists('fail')) {
    function fail($message, $status = 400)
    {
        respond(array('success' => false, 'message' => $message), $status);
    }
}

if (!function_exists('input')) {
    function input($keys, $default = null)
    {
        foreach ((array)$keys as $key) {
            if (isset($_POST[$key]) && $_POST[$key] !== '') { return trim((string)$_POST[$key]); }
            if (isset($_GET[$key])  && $_GET[$key]  !== '') { return trim((string)$_GET[$key]); }
        }
        foreach ((array)$keys as $key) {
            if (isset($_POST[$key]) || isset($_GET[$key])) { return ''; }
        }
        return $default;
    }
}

/* ---------- the reads ----------------------------------------------------- */

/**
 * Query: a stored date as Y-m-d, or null when it is not a date at all. Both
 * stored formats are accepted — 'Y/m/d' and 'Y-m-d' are each in these tables —
 * because a row carrying neither is not worth guessing about.
 *
 * THE ONE DEFINITION. The attendance reads and the holiday read both come
 * here, so a date this file accepts is the same date in every answer it gives.
 */
function attIsoDate($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $iso = str_replace('/', '-', substr($value, 0, 10));
    $d   = DateTime::createFromFormat('Y-m-d', $iso);
    return ($d && $d->format('Y-m-d') === $iso) ? $iso : null;
}

/**
 * Query: one cell of evaluation.schedule_type read as a shift —
 * array('is_off_day', 'start', 'end', 'label').
 *
 * The column holds a human string: "8:00am - 5:30pm", or "Off". That is the
 * stored form, so it is parsed here ONCE rather than compared as text
 * anywhere else, and the raw string is handed back as `label` so a caller can
 * print what the table actually says.
 *
 *   is_off_day  '1' no working hours that day — the cell reads Off, or is
 *                   blank, or is something this parser does not recognise
 *               '0' they are due in, and start/end say when
 *   start, end  'HH:MM:SS', or '' on an off day
 *
 * A STRING, LIKE is_public_holiday, AND FOR THE SAME REASON: '0' is truthy
 * in JavaScript. Test it as === '1'.
 *
 * AN UNREADABLE CELL READS AS OFF, which is the quiet direction: it leaves a
 * day unjudged rather than inventing hours nobody works. label still carries
 * the original text, so a cell the parser cannot read is visible rather than
 * silently normal.
 */
function attShift($value)
{
    $label = trim((string)$value);
    $off   = array('is_off_day' => '1', 'start' => '', 'end' => '', 'label' => $label);

    if ($label === '' || strcasecmp($label, 'Off') === 0) {
        return $off;
    }

    /* "8:00am - 12:30pm", and tolerant about the separator and the spacing
     * around it, because this column is typed by hand. */
    if (!preg_match('/^\s*(\d{1,2})\s*[:.]\s*(\d{2})\s*([ap])\.?m\.?\s*[-–—to]+\s*'
                  . '(\d{1,2})\s*[:.]\s*(\d{2})\s*([ap])\.?m\.?\s*$/i', $label, $m)) {
        return $off;
    }

    $start = attClock24($m[1], $m[2], $m[3]);
    $end   = attClock24($m[4], $m[5], $m[6]);
    if ($start === null || $end === null) {
        return $off;
    }

    return array('is_off_day' => '0', 'start' => $start, 'end' => $end,
                 'label' => $label);
}

/**
 * Query: a 12-hour clock as 'HH:MM:SS', or null when the hour or minute is
 * not one. 12am is 00:xx and 12pm is 12:xx — the two the arithmetic would
 * otherwise get backwards.
 */
function attClock24($hour, $minute, $meridiem)
{
    $h = (int)$hour;
    $i = (int)$minute;
    if ($h < 1 || $h > 12 || $i < 0 || $i > 59) {
        return null;
    }
    if (strtolower($meridiem) === 'p') {
        $h = ($h === 12) ? 12 : $h + 12;
    } else {
        $h = ($h === 12) ? 0 : $h;
    }
    return sprintf('%02d:%02d:00', $h, $i);
}

/**
 * Every attendance read, and nothing else. Each one covers the WHOLE range for
 * the WHOLE company in a single query — a per-person query would be 668 of
 * them for a day and 20,000 for a month.
 *
 * All three return the same shape: key => date => 'HH:MM:SS', earliest first,
 * so composing them is one rule applied three times and not three rules.
 */
class AttendanceRepository
{
    /* TWO DATE FORMATS LIVE IN THESE TABLES, and the caller passes whichever
     * a read needs. staff_attendance and gw_attendance store the date as a
     * 'Y/m/d' STRING, so their range is compared as a string — zero-padded,
     * so it still orders correctly. staff_attendance_logs holds a real
     * datetime and is compared as one. isoDate() below accepts either on the
     * way back out, so everything after the reads is Y-m-d. */

    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Query: the staff app's check-ins for ONE slot, as the staff report reads
     * them. $period is ATT_PERIOD_AM or ATT_PERIOD_PM — the slot is a
     * parameter and not a constant in the SQL, so the morning and the
     * afternoon are read by the same code and cannot drift apart.
     *
     * Indexed BOTH ways on purpose. That report matches a person on
     * (attendance_by_person AND attendance_by_comp_id) OR mymk_id, because the
     * app has written both over the years; this returns both keys so the
     * caller can try the pool's person first and its mk_id second, and find
     * the same rows that report finds.
     *
     * MIN(time) per day: see EARLIEST WINS at the top of this file.
     */
    public function staffApp($compId, $fromYmd, $toYmd, $period)
    {
        $sql = "SELECT a.attendance_by_person AS person,
                       a.attendance_by_comp_id AS comp_id,
                       a.mymk_id,
                       a.attendance_by_date AS d,
                       MIN(a.attendance_by_time) AS t
                  FROM evaluation.staff_attendance a
                 WHERE a.attendance_period = :period
                   AND a.attendance_self_checkin = 1
                   AND a.attendance_submittedFrom = '1'
                   AND a.attendance_by_date BETWEEN :f AND :t
                   AND (a.attendance_by_comp_id = :c OR a.attendance_by_comp_id IS NULL
                        OR a.attendance_by_comp_id = '')
                 GROUP BY a.attendance_by_person, a.attendance_by_comp_id,
                          a.mymk_id, a.attendance_by_date";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array(':period' => $period, ':f' => $fromYmd,
                             ':t' => $toYmd, ':c' => (string)(int)$compId));

        $byPerson = array();
        $byMkId   = array();
        foreach ($stmt->fetchAll() as $r) {
            $date = self::isoDate($r['d']);
            $time = self::clockTime($r['t']);
            if ($date === null || $time === null) {
                continue;
            }
            $person = strtolower(trim((string)$r['person']));
            if ($person !== '') {
                self::keepEarliest($byPerson, $person, $date, $time);
            }
            if ((int)$r['mymk_id'] > 0) {
                self::keepEarliest($byMkId, (int)$r['mymk_id'], $date, $time);
            }
        }
        return array('by_person' => $byPerson, 'by_mk_id' => $byMkId);
    }

    /**
     * Query: the GW app's check-ins for ONE slot, as the GW report reads them
     * — keyed on monthly_assign_gw_code, which is the pool's `person` for a
     * GW. $period is ATT_PERIOD_AM or ATT_PERIOD_PM, as in staffApp().
     *
     * A row with no code identifies nobody and is dropped: the table carries
     * plenty of them from submissions that never named a worker.
     *
     * NOT scoped by company, unlike the other two. A GW code is unique across
     * the group, and the caller only ever looks up codes the pool handed it —
     * so a company filter here could only ever remove a row that was about to
     * be ignored anyway.
     */
    public function gwApp($fromYmd, $toYmd, $period)
    {
        $sql = "SELECT g.monthly_assign_gw_code AS person,
                       g.gw_attendance_by_date AS d,
                       MIN(g.gw_attendance_by_time) AS t
                  FROM evaluation.gw_attendance g
                 WHERE g.gw_attendance_period = :period
                   AND g.gw_attendance_self_checkin = 1
                   AND g.gw_attendance_submittedFrom = '1'
                   AND g.gw_attendance_by_date BETWEEN :f AND :t
                   AND g.monthly_assign_gw_code IS NOT NULL
                   AND TRIM(g.monthly_assign_gw_code) <> ''
                 GROUP BY g.monthly_assign_gw_code, g.gw_attendance_by_date";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array(':period' => $period, ':f' => $fromYmd, ':t' => $toYmd));

        $out = array();
        foreach ($stmt->fetchAll() as $r) {
            $date = self::isoDate($r['d']);
            $time = self::clockTime($r['t']);
            if ($date !== null && $time !== null) {
                self::keepEarliest($out, strtolower(trim((string)$r['person'])), $date, $time);
            }
        }
        return $out;
    }

    /**
     * Query: the earliest site check-in each person made each day — the table
     * siteCheckInReport.php's STAFF report reads, mkPortal.staff_attendance_logs.
     *
     * NOT site_check_in_rounds. That table is patrol rounds: a security round
     * started at a site, several a day per guard, and it covered 3 or 4 people
     * at Globinaco. This one is the face check-in a staff member makes when
     * they arrive at a site, and it covers about thirty — which is the
     * population this fallback is for.
     *
     * $sinceTime NARROWS IT TO ONE SLOT, and is the whole difference between
     * the morning read and the afternoon one. This table has no slot column:
     * a row is a check-in at a moment, and the same thirty-odd people check
     * in morning, midday and late afternoon (448 rows from 16:30 onwards in
     * October 2026). Passed null — the morning — every row counts and the
     * earliest is the arrival. Passed ATT_PM_FROM, only rows from 16:30
     * onwards count, so a person who was at a site at 07:40 and nowhere near
     * one at 4:30pm is no_data for the afternoon instead of being credited
     * with it. MIN() still applies, so what comes back is the check-in
     * NEAREST the slot rather than whatever they did last.
     *
     * LUNCH IS NOT AN ARRIVAL. is_lunch_break marks the out/in pair somebody
     * logs at midday; counting one would turn a lunch break into a very late
     * start on any day whose arrival row is missing. Dropped here so the
     * earliest row left is an arrival. It stays dropped for the afternoon
     * too, where a midday row is out of the window anyway.
     *
     * Indexed by person AND by mymk_id, exactly as staffApp() is and for the
     * same reason — this table carries both, and the pool's mk_id matches the
     * second when a login has been renamed.
     *
     * Deleted rows are retractions and stay out.
     */
    public function siteCheckIn($compId, $fromYmd, $toYmd, $sinceTime = null)
    {
        $sql = "SELECT l.person, l.mymk_id, DATE(l.checked_in_at) AS d,
                       MIN(TIME(l.checked_in_at)) AS t
                  FROM mkPortal.staff_attendance_logs l
                 WHERE l.deleted = '0'
                   AND l.comp_id = :c
                   AND IFNULL(l.is_lunch_break, 0) <> 1
                   AND l.checked_in_at IS NOT NULL
                   AND DATE(l.checked_in_at) BETWEEN :f AND :t";

        /* comp_id is VARCHAR here, so it binds as a string. */
        $args = array(':c' => (string)(int)$compId, ':f' => $fromYmd, ':t' => $toYmd);

        /* Bound only when there is a window, so the morning read stays the
         * query it always was. */
        if ($sinceTime !== null) {
            $sql .= "\n                   AND TIME(l.checked_in_at) >= :since";
            $args[':since'] = $sinceTime;
        }

        $sql .= "\n                 GROUP BY l.person, l.mymk_id, DATE(l.checked_in_at)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);

        $byPerson = array();
        $byMkId   = array();
        foreach ($stmt->fetchAll() as $r) {
            $date = self::isoDate($r['d']);
            $time = self::clockTime($r['t']);
            if ($date === null || $time === null) {
                continue;
            }
            $person = strtolower(trim((string)$r['person']));
            if ($person !== '') {
                self::keepEarliest($byPerson, $person, $date, $time);
            }
            if ((int)$r['mymk_id'] > 0) {
                self::keepEarliest($byMkId, (int)$r['mymk_id'], $date, $time);
            }
        }
        return array('by_person' => $byPerson, 'by_mk_id' => $byMkId);
    }

    /**
     * Query: the face terminals' day, keyed by mk_id — the HQ door readings,
     * evaluation.staff_attendance_face_log.
     *
     * NOT A CHECK-IN ANYBODY MAKES. The other two sources are somebody
     * deciding to submit; this one is a machine export, loaded from a
     * spreadsheet, one row per person per day with the first time they were
     * seen and the last. It is the only source here nobody can forget to use,
     * which is also why it is the only one that can show a person who never
     * touched the app.
     *
     * THERE IS MORE THAN ONE TERMINAL, AND person_id IS ONLY A NUMBER ON ONE
     * OF THEM. That is the whole of the difficulty here. Four tables carry an
     * upload:
     *
     *   staff_attendance_device_type  one row per terminal — HQ1,3 (an
     *                                 iVMS-4200) is id 1, HQ2 (a ZKBio) is 2
     *   staff_attendance_face         the upload header: one row per terminal
     *                                 per date, with the file and who loaded it
     *   staff_attendance_face_log     the readings, face_id -> face.id
     *   staff_attendance_device       what a terminal's numbers mean:
     *                                 (device_id, staff_device_id) -> staff_id
     *
     * A reading is identified by the PAIR — which terminal, and what number on
     * it — and the terminal is on the header, not on the reading:
     * face_log.face_id -> face.id -> face.device_id. person_id by itself is
     * not a person. Number 1 is Elien on HQ2 and Jackson Chan on HQ1,3; number
     * 96 is Vincent Ng on HQ2 and Samantha Chu on HQ1,3. The two terminals
     * were enrolled separately and their numbering has nothing in common.
     *
     * users.attendance_face_id IS HQ1,3's NUMBERING AND NOTHING ELSE. For all
     * 91 people HQ1,3 has in staff_attendance_device the two agree exactly,
     * which is what makes it usable at all; for every one of the 16 on HQ2
     * they differ. Joining person_id straight to attendance_face_id — what
     * this read used to do, when HQ1,3 was the only terminal — therefore gave
     * HQ2's readings to whichever HQ1,3 person held that number. Over 23-28
     * Oct 2026 that moved 41 people's first_in and 15 people's last_out onto
     * the wrong person, and because MIN() takes the earlier of the two, a
     * 14:18 arrival was reported as 06:45 — a late morning read as on time.
     *
     * SO THE MAPPING TABLE DECIDES, AND attendance_face_id IS A FALLBACK FOR
     * ONE TERMINAL. staff_attendance_device is the authority wherever it has a
     * row for (device_id, person_id). Where it has none and the reading came
     * from ATT_FACE_DEVICE_LEGACY, attendance_face_id answers instead: 13
     * people a day are enrolled on that terminal and carry a face id but have
     * no mapping row yet, and dropping them would report them absent. The
     * fallback is scoped to that one device_id on purpose — on any other
     * terminal the same number is a different person, so there it would not be
     * a weaker answer but a wrong one. As HR fills the mapping table in, the
     * fallback quietly stops mattering.
     *
     * A READING NEITHER SOURCE CAN NAME IS DROPPED, which is the only honest
     * thing to do with it: with no staff_id there is nobody to report it
     * against. 35 to 48 readings a day are interns, trainees and visitors with
     * no users row at all.
     *
     * staff_id IS users.id — the pool's mk_id — so this stays staff only, as
     * it was before: a GW has neither a users row nor a terminal enrolment. It
     * also settles who a reading belongs to where somebody has two users rows:
     * HQ1,3 number 97 is Bayo Kleden, whose face id sits on his old, resigned
     * row (409) while the mapping table points at the live one (4262). He was
     * being credited to a row the pool excludes, so the face log showed him
     * absent every day of the range.
     *
     * THE MAPPING IS READ AT status = 1, the enrolment admin's own live flag.
     * A terminal number is reassigned when somebody leaves, so honouring a
     * retired mapping would let one number resolve to two people and credit
     * both. The cost is that retiring a mapping takes that person's past days
     * with it, which is the lesser of the two.
     *
     * TWO INDEXES OUT OF ONE ROW, because the row holds both ends of the day:
     *   first_in   the morning, judged against the person's own late_from
     *              like any other arrival
     *   last_out   the afternoon, WHATEVER THE HOUR
     *
     * NO WINDOW ON last_out, and that is deliberate. It used to be ignored
     * unless it fell at 16:30 or later, on the reasoning that "the last time
     * the terminal saw somebody" is not "the time they went home". The data
     * says otherwise: a file is exported whenever somebody gets round to it,
     * often the same morning, and then last_out is only "last seen by export
     * time". The HQ1,3 file for 28 Oct 2026 was loaded at 10:49am and not one
     * of its 44 last_out readings was at or after 16:30; on 24 Oct only 5 of
     * 23 were. Holding them to that line reported a whole company as never
     * having checked out.
     *
     * A last_out IS the check-out this row recorded. Reading it as anything
     * else is this file inventing a rule the terminal never applied. The site
     * check-in read still has its window, because that table is a stream of
     * check-ins where a morning arrival really would be mistaken for a
     * departure; a face-log row has one first_in and one last_out and no such
     * ambiguity.
     *
     * EITHER END CAN BE BLANK and they are kept apart for that reason — 47 of
     * the 156 readings on 23 Oct 2026 had no first_in and 64 no last_out. A
     * person carrying one and not the other is found for that slot and no_data
     * for the other, which is what the terminal actually knows about them.
     *
     * MIN(first_in) / MAX(last_out) because the name of each column is the
     * rule: first in, last out. One reading per person per terminal per day,
     * so within a terminal the grouping changes nothing — but somebody
     * enrolled on both doors now falls in one group, and first in and last out
     * across the two is exactly the answer wanted.
     */
    public function faceLog($fromIso, $toIso)
    {
        /* Cross-schema, like the reads above — both are local under prog4.
         * NULLIF(TRIM(...)) first so a blank cell cannot win a MIN(): '' sorts
         * before every real time.
         *
         * The header join is an INNER one: the device is the half of the key
         * that says what person_id means, so a reading with no header is a
         * number nobody can resolve rather than a reading to keep. All 731
         * rows have one today.
         *
         * COALESCE appears in the SELECT, the GROUP BY and the WHERE because
         * MySQL 5.6 has no column alias to reuse — it is one rule written
         * three times: the mapping table if it knows this number, else the
         * legacy terminal's face id, else nobody and the row goes. */
        $sql = "SELECT COALESCE(dev.staff_id, u.id) AS mk_id,
                       l.attendance_date AS d,
                       MIN(NULLIF(TRIM(l.first_in), '')) AS first_in,
                       MAX(NULLIF(TRIM(l.last_out), '')) AS last_out
                  FROM evaluation.staff_attendance_face_log l
                  JOIN evaluation.staff_attendance_face h
                    ON h.id = l.face_id
                  LEFT JOIN evaluation.staff_attendance_device dev
                    ON dev.device_id = h.device_id
                   AND dev.staff_device_id = l.person_id
                   AND dev.status = 1
                  LEFT JOIN staff_portal2.users u
                    ON dev.staff_id IS NULL
                   AND h.device_id = " . ATT_FACE_DEVICE_LEGACY . "
                   AND u.attendance_face_id = l.person_id
                   AND u.attendance_face_id > 0
                 WHERE l.attendance_date BETWEEN :f AND :t
                   AND COALESCE(dev.staff_id, u.id) IS NOT NULL
                 GROUP BY COALESCE(dev.staff_id, u.id), l.attendance_date";

        /* No company predicate and no status predicate on users: the key is
         * users.id, which the pool already handed us for people it decided
         * are in. Filtering again here could only lose somebody the pool
         * kept — a Trillium row, say, which is stored under company 1. */
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array(':f' => $fromIso, ':t' => $toIso));

        $firstIn = array();
        $lastOut = array();
        foreach ($stmt->fetchAll() as $r) {
            $date = self::isoDate($r['d']);
            $mkId = (int)$r['mk_id'];
            if ($date === null || $mkId <= 0) {
                continue;
            }

            $in = self::clockTime($r['first_in']);
            if ($in !== null) {
                self::keepEarliest($firstIn, $mkId, $date, $in);
            }

            $out = self::clockTime($r['last_out']);
            if ($out !== null) {
                self::keepLatest($lastOut, $mkId, $date, $out);
            }
        }
        return array('first_in' => array('by_mk_id' => $firstIn),
                     'last_out' => array('by_mk_id' => $lastOut));
    }

    /**
     * Query: the five schedules, as attShift() has already read them —
     * schedule_type_id => array('mon_fri' => shift, 'saturday' => shift).
     *
     * Five rows, read once for the whole answer. The column names are
     * hyphenated and so are back-quoted: `Mon-Fri` without them is Mon MINUS
     * Fri, which MySQL would try to evaluate.
     */
    public function scheduleTypes()
    {
        $sql = "SELECT t.schedule_type_id AS id,
                       t.`Mon-Fri`  AS mon_fri,
                       t.`Saturday` AS saturday
                  FROM evaluation.schedule_type t";

        $types = array();
        foreach ($this->db->query($sql)->fetchAll() as $r) {
            $id = (int)$r['id'];
            if ($id <= 0) {
                continue;
            }
            $types[$id] = array('mon_fri'  => attShift($r['mon_fri']),
                                'saturday' => attShift($r['saturday']));
        }
        return $types;
    }

    /**
     * Query: who is on which schedule — array('by_mk_id' => …, 'by_person' =>
     * …), each mapping to a schedule_type_id.
     *
     * TWO INDEXES BECAUSE THERE ARE TWO KINDS OF PERSON, and the table says
     * so itself with is_gw. A staff row carries staff_id (users.id) and a GW
     * row carries monthly_assign_gw_code — which is exactly the key the pool
     * prints as `person` for a GW, so the codes go in by_person and the staff
     * ids in by_mk_id, and neither can answer for the other.
     *
     * gw_staff_id is deliberately NOT used: three rows carry 0 for it, so it
     * is not an identity, while the code is unique across all 347 GW rows.
     * staff_id is used rather than the staff row's own `person` because it
     * resolves all 311 of them on its own and cannot be spelled two ways.
     *
     * No comp predicate. The table is comp 1 only today, and keying on it
     * would lose Trillium, whose pool rows read comp 16 while their users row
     * — and so any schedule assigned to them — is stored under comp 1.
     */
    public function scheduleAssign()
    {
        $sql = "SELECT a.is_gw, a.staff_id, a.monthly_assign_gw_code AS code,
                       a.schedule_type_id AS type_id
                  FROM evaluation.staff_schedule_assign a
                 WHERE a.schedule_type_id IS NOT NULL";

        $byMkId  = array();
        $byPerson = array();
        foreach ($this->db->query($sql)->fetchAll() as $r) {
            $type = (int)$r['type_id'];
            if ($type <= 0) {
                continue;
            }
            if ((int)$r['is_gw'] === 1) {
                $code = strtolower(trim((string)$r['code']));
                if ($code !== '') {
                    $byPerson[$code] = $type;
                }
                continue;
            }
            $mkId = (int)$r['staff_id'];
            if ($mkId > 0) {
                $byMkId[$mkId] = $type;
            }
        }
        return array('by_mk_id' => $byMkId, 'by_person' => $byPerson);
    }

    /** Command: keep the earliest time seen for this key and day. The GROUP BY
     *  already does it per row set; this holds when one person's rows arrive
     *  under two spellings of the same key. */
    private static function keepEarliest(array &$index, $key, $date, $time)
    {
        if (!isset($index[$key][$date]) || $time < $index[$key][$date]) {
            $index[$key][$date] = $time;
        }
    }

    /** Command: keep the LATEST time seen for this key and day — keepEarliest()
     *  the other way round, for last_out, where the late reading is the one
     *  that means anything. */
    private static function keepLatest(array &$index, $key, $date, $time)
    {
        if (!isset($index[$key][$date]) || $time > $index[$key][$date]) {
            $index[$key][$date] = $time;
        }
    }

    /** Query: a stored date as Y-m-d, or null. attIsoDate() is the definition;
     *  this is kept so the class still reads as its own thing. */
    private static function isoDate($value)
    {
        return attIsoDate($value);
    }

    /** Query: a stored time as HH:MM:SS, or null. Padded so that comparing it
     *  against a late_from as a string is the same as comparing clocks. */
    private static function clockTime($value)
    {
        $value = trim((string)$value);
        if ($value === '' || !preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
            return null;
        }
        $h = (int)$m[1];
        if ($h > 23 || (int)$m[2] > 59) {
            return null;
        }
        return sprintf('%02d:%02d:%02d', $h, (int)$m[2], isset($m[3]) ? (int)$m[3] : 0);
    }
}

/**
 * Query: the HR host's PDO, opened at most once per request, or null when it
 * cannot be reached.
 *
 * NEVER THROWS. A holiday flag is an annotation on the answer; losing it must
 * not cost the caller the attendance they actually asked for. A failure is
 * logged, null comes back, and attHolidayBlock() reads the local copy instead.
 */
function attHolidayHrDb()
{
    /* false = not tried yet, null = tried and failed, PDO = open. The three
     * states matter: without them a dead host is retried on every call. */
    static $db = false;
    if ($db !== false) {
        return $db;
    }
    try {
        $db = new PDO(ATT_HR_DSN, ATT_HR_USER, ATT_HR_PASS, array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            /* Short on purpose. This host is across a link that may be
             * firewalled, and the local copy of the same table is one query
             * away — so waiting on it is worse than falling back. */
            PDO::ATTR_TIMEOUT            => 5,
        ));
    } catch (Exception $e) {
        error_log('attendanceAPI holiday hr connect: ' . $e->getMessage());
        $db = null;
    }
    return $db;
}

/**
 * The holiday calendar, whichever copy of the table it is read from.
 *
 * ONE QUERY FOR THE WHOLE RANGE, like the attendance reads above. The legacy
 * attHolidaysByDay() asks once per day, which is 31 round trips for a month
 * and arrives at the same answer.
 */
class PublicHolidayRepository
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Query: every holiday in the range, as date => array of titles. A date
     * present in the answer IS a holiday, titles or not — see below.
     *
     * year, month and day are three VARCHARs in this table and not a date, so
     * the range is matched on them pasted back together and compared as a
     * string. LPAD because of that comparison: all 462 rows are zero-padded
     * today, and a sloppily inserted '1' for January would otherwise sort
     * nowhere near '01'. attIsoDate() then checks what came back is a real
     * date, so a row with a junk year is dropped rather than guessed at.
     *
     * A DAY CAN HOLD MORE THAN ONE HOLIDAY — 31 May 2026 is both Hari Wesak
     * and the Harvest Festival — so titles is a list. It is deduplicated
     * because this table has carried the same title twice on one day before
     * (1 June 2009, "Replacement Holiday"), and the date key is created
     * BEFORE the title is looked at, so a row with a blank title still marks
     * the day a holiday instead of silently leaving it a working one.
     */
    public function range($fromIso, $toIso)
    {
        $ymd = "CONCAT(LPAD(h.year,4,'0'),'-',LPAD(h.month,2,'0'),'-',"
             . "LPAD(h.day,2,'0'))";

        /* The table name is a constant, never input. */
        $sql = "SELECT " . $ymd . " AS d, h.title AS title
                  FROM " . ATT_HOLIDAY_TABLE_HR . " h
                 WHERE " . $ymd . " BETWEEN :f AND :t
                 ORDER BY d, h.id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array(':f' => $fromIso, ':t' => $toIso));

        $out = array();
        foreach ($stmt->fetchAll() as $r) {
            $date = attIsoDate($r['d']);
            if ($date === null) {
                continue;
            }
            if (!isset($out[$date])) {
                $out[$date] = array();
            }
            /* Titles carry stray leading TABS here, not only spaces — six
             * rows do — and a tab in a report heading is invisible mess. */
            $title = trim((string)$r['title']);
            if ($title !== '' && !in_array($title, $out[$date], true)) {
                $out[$date][] = $title;
            }
        }
        return $out;
    }
}

/**
 * Query: the holidays block the answer publishes — ONE ENTRY PER DAY in the
 * range, holiday or not, so a caller rendering a month grid has a cell for
 * every column it is about to draw.
 *
 * ONE SOURCE: hr.public_holiday on HR's host, and no second opinion.
 *
 * NEVER THROWS, which is the point of catching here. A holiday is an
 * annotation on the answer, so a dead host or a refused login leaves every
 * flag '' — unknown, which is honest — with source "unavailable" saying so,
 * and the attendance in the same response untouched. See PUBLIC HOLIDAYS at
 * the top of this file.
 */
function attHolidayBlock($fromIso, $toIso, array $days)
{
    $byDate = null;
    $source = 'unavailable';

    $hr = attHolidayHrDb();
    if ($hr !== null) {
        try {
            $repo   = new PublicHolidayRepository($hr);
            $byDate = $repo->range($fromIso, $toIso);
            $source = ATT_HOLIDAY_TABLE_HR;
        } catch (Exception $e) {
            error_log('attendanceAPI holiday read: ' . $e->getMessage());
            $byDate = null;
        }
    }

    $out = array();
    foreach ($days as $day) {
        if ($byDate === null) {
            $out[$day] = array('is_public_holiday' => '', 'titles' => array());
            continue;
        }
        /* PRESENCE of the key is the flag, not whether it carries titles. */
        $isHoliday = isset($byDate[$day]);
        $out[$day] = array(
            'is_public_holiday' => $isHoliday ? '1' : '0',
            'titles'            => $isHoliday ? $byDate[$day] : array(),
        );
    }

    return array('source' => $source, 'days' => $out);
}

/** Query: the flag one cell prints for one day. '' when the calendar could
 *  not be read at all — which is not the same answer as '0'. */
function attHolidayFlag(array $holidays, $date)
{
    return isset($holidays[$date]['is_public_holiday'])
        ? $holidays[$date]['is_public_holiday']
        : '';
}

/* ---------- composing ----------------------------------------------------- */

/**
 * Query: one person's day, as the answer prints it — BOTH SLOTS and the
 * holiday, in one cell.
 *
 * $am and $pm are each the three reads for one slot, as attFind() wants them.
 * The morning fields keep the names they have always had (time, status,
 * source) and the afternoon's are prefixed pm_, so a caller written before
 * the afternoon existed still reads exactly what it used to.
 *
 * The holiday is a fact about the DAY and not about this person, and it DOES
 * NOT move either status: see PUBLIC HOLIDAYS at the top of this file. It is
 * attached here, in the one place every cell of both modes passes through, so
 * no caller has to remember which mode carries it.
 *
 * $schedule is this person's, already resolved by attSchedule() — once per
 * person, not once per day, because a schedule is a fact about them and only
 * WHICH of its two patterns applies changes with the date.
 */
function attCell(array $row, $date, array $am, array $pm, array $holidays,
                 array $schedule)
{
    /* THE SHIFT FIRST, because the morning is judged against it: lateness is
     * this person's own start plus the grace, not one company-wide time.
     * is_off_day still scores nothing — see SCHEDULES at the top. */
    $shift = attShiftOn($schedule, $date);

    $cell = attArrival($row, $date, $am, attLateFrom($shift));

    $afternoon         = attPresence($row, $date, $pm);
    $cell['pm_time']   = $afternoon['time'];
    $cell['pm_status'] = $afternoon['status'];
    $cell['pm_source'] = $afternoon['source'];

    $cell['is_public_holiday'] = attHolidayFlag($holidays, $date);

    $cell['is_off_day']  = $shift['is_off_day'];
    $cell['shift_start'] = $shift['start'];
    $cell['shift_end']   = $shift['end'];
    return $cell;
}

/**
 * Query: which schedule this person is on —
 * array('schedule_type_id', 'schedule_source', 'mon_fri', 'saturday').
 *
 * schedule_source says where the id came from and is the field to read before
 * trusting the hours:
 *   assigned  staff_schedule_assign names it for this person
 *   default   nobody has assigned them one, so ATT_SCHEDULE_DEFAULT_STAFF or
 *             ATT_SCHEDULE_DEFAULT_GW applied — a guess, and it says so
 *
 * A GW is looked up by code and a staff member by users.id, which is what
 * each half of the assignment table is keyed on; see scheduleAssign().
 */
function attSchedule(array $row, array $assign, array $types)
{
    $isGw = ($row['person_type'] === 'gw');

    $typeId = 0;
    if ($isGw) {
        $person = strtolower(trim((string)$row['person']));
        if ($person !== '' && isset($assign['by_person'][$person])) {
            $typeId = (int)$assign['by_person'][$person];
        }
    } else {
        $mkId = isset($row['mk_id']) ? (int)$row['mk_id'] : 0;
        if ($mkId > 0 && isset($assign['by_mk_id'][$mkId])) {
            $typeId = (int)$assign['by_mk_id'][$mkId];
        }
    }

    $source = 'assigned';
    if ($typeId <= 0 || !isset($types[$typeId])) {
        /* Also the path for an assignment pointing at a schedule_type row
         * that is not there — an id nobody can read is no better than none. */
        $typeId = $isGw ? ATT_SCHEDULE_DEFAULT_GW : ATT_SCHEDULE_DEFAULT_STAFF;
        $source = 'default';
    }

    /* Even the default can be missing, if schedule_type itself could not be
     * read. Every day then reads as an off day, which attShift() already
     * calls the quiet direction. */
    $blank = attShift('');
    $type  = isset($types[$typeId]) ? $types[$typeId]
                                    : array('mon_fri' => $blank, 'saturday' => $blank);

    return array(
        'schedule_type_id' => $typeId,
        'schedule_source'  => $source,
        'mon_fri'          => $type['mon_fri'],
        'saturday'         => $type['saturday'],
    );
}

/**
 * Query: the shift a schedule puts on ONE date — the array attShift() makes.
 *
 * Sunday is off for everybody: schedule_type has a Mon-Fri column and a
 * Saturday column and no third one, so there are no Sunday hours to read.
 * See ATT_SCHEDULE_SUNDAY_OFF.
 */
function attShiftOn(array $schedule, $date)
{
    $day = (int)date('N', strtotime($date));   /* 1 Mon … 6 Sat, 7 Sun */

    if ($day === 7 && ATT_SCHEDULE_SUNDAY_OFF) {
        return attShift('');
    }
    return ($day === 6) ? $schedule['saturday'] : $schedule['mon_fri'];
}

/**
 * Query: the first source that has this person in this slot on this day —
 * array('time','source') — or null when none of them do.
 *
 * THE ORDER IS THE RULE. App first, site check-in second, the face log third,
 * nothing fourth; see the chain at the top of this file. Written once, here,
 * so the two slots, the daily answer and every cell of a monthly one all find
 * a person the same way. WHAT IS MADE OF A HIT is the only thing that differs
 * between slots — attArrival() for the morning, attPresence() for the
 * afternoon — and that difference is one line each, below.
 *
 * $reads is the four indexes for ONE slot:
 *   array('staff_app' => …, 'gw_app' => …, 'site' => …, 'face' => …)
 */
function attFind(array $row, $date, array $reads)
{
    $person   = strtolower(trim((string)$row['person']));
    $isGw     = ($row['person_type'] === 'gw');
    $staffApp = $reads['staff_app'];
    $gwApp    = $reads['gw_app'];
    $site     = $reads['site'];
    $face     = $reads['face'];

    if ($isGw) {
        if (isset($gwApp[$person][$date])) {
            return array('time' => $gwApp[$person][$date], 'source' => 'attendance_app');
        }
        /* No site-check-in arm for a GW on purpose: that table is keyed on
         * users.person, which a GW does not have, so a code landing on
         * somebody's login there would be a different person. No face-log arm
         * either, for the same reason one step further back — a face reading
         * resolves to a users.id, through the terminal's enrolment table, and
         * a GW has neither a users row nor an enrolment on a door. The app is
         * the whole of a GW's answer. */
        return null;
    }

    if (isset($staffApp['by_person'][$person][$date])) {
        return array('time' => $staffApp['by_person'][$person][$date],
                     'source' => 'attendance_app');
    }
    $mkId = isset($row['mk_id']) ? (int)$row['mk_id'] : 0;
    if ($mkId > 0 && isset($staffApp['by_mk_id'][$mkId][$date])) {
        return array('time' => $staffApp['by_mk_id'][$mkId][$date],
                     'source' => 'attendance_app');
    }
    if (isset($site['by_person'][$person][$date])) {
        return array('time' => $site['by_person'][$person][$date],
                     'source' => 'site_check_in');
    }
    if ($mkId > 0 && isset($site['by_mk_id'][$mkId][$date])) {
        return array('time' => $site['by_mk_id'][$mkId][$date],
                     'source' => 'site_check_in');
    }
    /* By mk_id ONLY. faceLog() has already turned (terminal, person_id) into
     * a users.id, so a hit here is already a hit on a users row — there is no
     * second, by-name arm to try, and nothing to spell wrong. */
    if ($mkId > 0 && isset($face['by_mk_id'][$mkId][$date])) {
        return array('time' => $face['by_mk_id'][$mkId][$date],
                     'source' => 'face_log');
    }
    return null;
}

/**
 * Query: the MORNING cell — a punctuality judgement, on_time or late,
 * against $lateFrom, which is THIS person's line on THIS day. attLateFrom()
 * works it out; see there for where it comes from.
 */
function attArrival(array $row, $date, array $reads, $lateFrom)
{
    $hit = attFind($row, $date, $reads);
    return ($hit === null)
        ? attJudge(null, '', $lateFrom)
        : attJudge($hit['time'], $hit['source'], $lateFrom);
}

/**
 * Query: the moment THIS shift becomes late — 'HH:MM:SS'.
 *
 * The shift's own start plus ATT_LATE_GRACE_MINUTES, so a 7:00am shift is
 * late from 07:01:00 and an 8:00am one from 08:01:00. The grace is the whole
 * starting minute, the rule the 8:00am line has always applied; what has
 * changed is that the start is read from the person's schedule rather than
 * assumed.
 *
 * ATT_LATE_FROM WHEN THERE IS NO START TO ADD TO — an off day, or a
 * schedule_type cell attShift() could not read. Those days score nothing
 * anyway, by policy; the fallback is there so the field is never empty and a
 * caller comparing against it never has to handle ''.
 */
function attLateFrom(array $shift)
{
    if ($shift['is_off_day'] === '1' || $shift['start'] === '') {
        return ATT_LATE_FROM;
    }

    /* Modulo a day: a start late enough that the grace would roll past
     * midnight wraps rather than reading 24:00:xx. No schedule does that
     * today — every start is a morning — but a time is only ever HH:MM:SS. */
    $parts   = explode(':', $shift['start']);
    $minutes = ((int)$parts[0] * 60) + (int)$parts[1] + ATT_LATE_GRACE_MINUTES;
    $minutes = (($minutes % 1440) + 1440) % 1440;

    return sprintf('%02d:%02d:%02d', intdiv($minutes, 60), $minutes % 60,
                   isset($parts[2]) ? (int)$parts[2] : 0);
}

/**
 * Query: the AFTERNOON cell — a presence check, has_data or no_data, and no
 * third answer.
 *
 * NOTHING AT 4:30PM IS LATE. The slot opens at 16:30 and people submit it
 * across the rest of the evening; a "late" there would mean nothing, which is
 * why the legacy report does not score it either (ATT_LATE_FROM has the
 * reference). The time is still published, because when somebody checked in
 * is worth reading even when it is not being marked.
 */
function attPresence(array $row, $date, array $reads)
{
    $hit = attFind($row, $date, $reads);
    if ($hit === null) {
        return array('time' => '', 'status' => 'no_data', 'source' => '');
    }
    return array('time' => $hit['time'], 'status' => 'has_data', 'source' => $hit['source']);
}

/**
 * Query: a morning time and source as the answer prints them, judged against
 * $lateFrom — the line attLateFrom() made for this person on this day.
 *
 * The line is published beside the verdict, on every cell including a
 * no_data one, so a caller can see what was applied and reproduce it with <
 * exactly as this file did.
 */
function attJudge($time, $source, $lateFrom)
{
    if ($time === null) {
        return array('time' => '', 'status' => 'no_data', 'source' => '',
                     'late_from' => $lateFrom);
    }
    return array(
        'time'      => $time,
        'status'    => ($time < $lateFrom) ? 'on_time' : 'late',
        'source'    => $source,
        'late_from' => $lateFrom,
    );
}

/** Query: the days a range covers, as Y-m-d. */
function attDays($fromIso, $toIso)
{
    $days = array();
    $d    = new DateTime($fromIso);
    $end  = new DateTime($toIso);
    while ($d <= $end) {
        $days[] = $d->format('Y-m-d');
        $d->modify('+1 day');
    }
    return $days;
}

/* ---------- the request --------------------------------------------------- */

try {
    $pdo = Db::get();
} catch (Exception $e) {
    error_log('attendanceAPI db: ' . $e->getMessage());
    fail('Could not reach the database.', 503);
}

try {
    /* ---- when ---- */
    $year  = input('year');
    $month = input('month');

    if ($year !== null && $year !== '' && $month !== null && $month !== '') {
        $year  = (int)$year;
        $month = (int)$month;
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            fail('year must be a four-digit year and month must be 1 to 12.');
        }
        $mode    = 'month';
        $fromIso = sprintf('%04d-%02d-01', $year, $month);
        $toIso   = date('Y-m-t', strtotime($fromIso));
    } else {
        if (($year !== null && $year !== '') || ($month !== null && $month !== '')) {
            fail('year and month go together. Pass both, or neither for a day.');
        }
        $mode = 'daily';
        $date = input('date');
        if ($date === null || $date === '') {
            $fromIso = date('Y-m-d');
        } else {
            $d = DateTime::createFromFormat('Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date) {
                fail('date must be a calendar date in YYYY-MM-DD form.');
            }
            $fromIso = $date;
        }
        $toIso = $fromIso;
    }

    $days = attDays($fromIso, $toIso);

    /* ---- which company ---- */
    $rawComp = input('comp_id');
    $compId  = ($rawComp === null || $rawComp === '') ? 0 : (int)$rawComp;
    if (!in_array($compId, attSupportedComps(), true)) {
        fail('Attendance is only built for company '
           . implode(' and ', attSupportedComps())
           . ' at the moment. Pass comp_id=1.', 400);
    }

    /* ---- who ---- */
    $repo      = new StaffPoolRepository($pdo);
    $companies = $repo->companies();
    $compNames = array();
    foreach ($companies as $c) {
        $compNames[(int)$c['comp_id']] = $c['display_name'];
    }
    $companyName = isset($compNames[$compId]) ? $compNames[$compId] : 'Company ' . $compId;

    /* The pool, exactly as staffPoolAPI.php builds it, so the two endpoints
     * are answering about the same people. withGwEmails() is skipped: this
     * answer carries no contacts. */
    $pool      = array_merge($repo->staff($compId), $repo->gw($compId));
    $overrides = $repo->overrides($compId);

    /* The reporting line, for scoping and for the HOD label. The same file
     * staffPoolAPI.php draws its tree with, so "under nathan" means the same
     * thing in both answers — hand-written placements included. */
    $treeFile = __DIR__ . '/staffPoolTree.php';
    if (!file_exists($treeFile)) {
        fail('staffPoolTree.php is not on this server yet. Upload it beside '
           . 'attendanceAPI.php.', 500);
    }
    require_once $treeFile;
    $tree = new StaffPoolTree($pdo, $pool, $overrides, $compNames);

    $wantStaff = input(array('staff', 'person'));
    $scope     = array('staff' => null, 'people' => 'company');

    if ($wantStaff !== null && $wantStaff !== '') {
        $found = $tree->candidates($wantStaff);
        if (empty($found)) {
            fail('No such person in the staff pool for this company: '
               . $wantStaff . '.', 404);
        }
        if (count($found) > 1) {
            $who = array();
            foreach ($found as $key) { $who[] = $tree->describe($key); }
            fail('More than one person is called ' . $wantStaff . ': '
               . implode('; ', $who) . '.', 409);
        }
        $rows  = $tree->branch($found[0]);
        $scope = array('staff' => $wantStaff, 'people' => 'branch');
    } else {
        $rows = $pool;
    }

    /* ---- the reads: the sources, once each, for the whole range ---- */
    /* Seven queries for the whole company and the whole range, not seven per
     * person. The slot is a parameter to the two app reads, so the afternoon
     * is read exactly as the morning is and the two cannot drift.
     *
     * THE OTHER TWO SOURCES HAVE NO SLOT OF THEIR OWN, and they answer that
     * differently. Site check-in is a stream of check-ins with nothing to say
     * which is a departure, so ATT_PM_FROM scopes it to 16:30 onwards. The
     * face log needs no such window: one row carries BOTH ends of a person's
     * day — first_in for the morning, last_out for the afternoon — so it is
     * ONE query for both slots and the last_out stands as recorded. */
    $att      = new AttendanceRepository($pdo);
    $appFrom  = str_replace('-', '/', $fromIso);
    $appTo    = str_replace('-', '/', $toIso);

    $face = $att->faceLog($fromIso, $toIso);

    $am = array(
        'staff_app' => $att->staffApp($compId, $appFrom, $appTo, ATT_PERIOD_AM),
        'gw_app'    => $att->gwApp($appFrom, $appTo, ATT_PERIOD_AM),
        'site'      => $att->siteCheckIn($compId, $fromIso, $toIso),
        'face'      => $face['first_in'],
    );
    $pm = array(
        'staff_app' => $att->staffApp($compId, $appFrom, $appTo, ATT_PERIOD_PM),
        'gw_app'    => $att->gwApp($appFrom, $appTo, ATT_PERIOD_PM),
        'site'      => $att->siteCheckIn($compId, $fromIso, $toIso, ATT_PM_FROM),
        'face'      => $face['last_out'],
    );

    /* ---- and the working week ---- */
    /* Two more queries for the whole company, not two per person: five
     * schedules and one row per person who has been assigned one. Which of a
     * schedule's two patterns applies is worked out per day, in attShiftOn(),
     * from the date alone. */
    $scheduleTypes  = $att->scheduleTypes();
    $scheduleAssign = $att->scheduleAssign();

    /* ---- and the calendar, once ---- */
    /* A holiday is company-wide, so it is read for the range and not per
     * person. attHolidayBlock() never throws: losing the calendar must not
     * cost the caller the attendance they asked for. */
    $holidayBlock = attHolidayBlock($fromIso, $toIso, $days);
    $holidays     = $holidayBlock['days'];

    $holidayCount = 0;
    foreach ($holidays as $h) {
        if ($h['is_public_holiday'] === '1') {
            $holidayCount++;
        }
    }

    /* ---- compose ---- */
    $out      = array();
    $totals   = array('on_time' => 0, 'late' => 0, 'no_data' => 0);
    /* The afternoon is counted separately because it is counted in different
     * words: has_data / no_data, with no on_time or late to add to. */
    $pmTotals = array('has_data' => 0, 'no_data' => 0);
    /* Counted on its own and added to NEITHER set above: an off day still has
     * a morning status and an afternoon one, so it is already inside both. */
    $offDays  = 0;

    foreach ($rows as $row) {
        $public = publicRow($row, $overrides, $compNames);

        /* Which department this person BELONGS to — the nearest head above
         * them, not every head above them. One hod unit and at most one am
         * unit each, so grouping a report by these covers everybody once.
         * See StaffPoolTree::placement(). */
        $where = $tree->placement($row['person'], $row['comp_id'], $row['person_type']);

        /* Once per person. Which pattern a given date falls under is the only
         * part that varies, and attCell() asks that per day. */
        $schedule = attSchedule($row, $scheduleAssign, $scheduleTypes);

        $entry = array(
            /* The join key, first and in the order staffPoolAPI.php prints
             * it. Everything after it is here so this answer reads on its own
             * and is not a second definition of anybody. */
            'person'              => $public['person'],
            'comp_id'             => $public['comp_id'],
            'staff_type'          => $public['staff_type'],
            'fullname'            => $public['fullname'],
            'position'            => $public['position'],
            'department'          => $public['department'],
            'district'            => $public['district'],
            'hod_department_name' => $tree->hodLabel($row['person'], $row['comp_id']),
            /* 1 for somebody in the pool, 0 for somebody an override has
             * taken out of it — the same field publicRow() prints, and the
             * one that says which of staffPoolAPI.php's two lists this person
             * is in. They are all here either way: attendance is a fact about
             * a person who turned up, not about whether they are counted. */
            'total_staff'         => $public['total_staff'],
            /* THE WEEK THEY ARE SUPPOSED TO WORK, on the person and not on
             * the day, because that is what it is a fact about. The per-day
             * cells carry which of its two patterns applied. */
            'schedule'            => $schedule,
        ) + $where;

        if ($mode === 'daily') {
            $cell  = attCell($row, $fromIso, $am, $pm, $holidays, $schedule);
            $entry = $entry + $cell;
            $totals[$cell['status']]++;
            $pmTotals[$cell['pm_status']]++;
            if ($cell['is_off_day'] === '1') {
                $offDays++;
            }
        } else {
            $byDay     = array();
            $summary   = array('on_time' => 0, 'late' => 0, 'no_data' => 0);
            $pmSummary = array('has_data' => 0, 'no_data' => 0);
            foreach ($days as $day) {
                $cell = attCell($row, $day, $am, $pm, $holidays, $schedule);
                if ($cell['is_off_day'] === '1') {
                    $offDays++;
                }
                /* Keyed by day OF THE MONTH, which is what a month grid is
                 * indexed by on screen; the full date is derivable from
                 * year + month and is not repeated 31 times per person. */
                $byDay[(string)(int)substr($day, 8, 2)] = $cell;
                $summary[$cell['status']]++;
                $pmSummary[$cell['pm_status']]++;
            }
            $entry['summary']    = $summary;
            /* Beside it, not merged into it: the two slots are counted in
             * different words, and a single no_data covering both would say
             * neither. */
            $entry['pm_summary'] = $pmSummary;
            $entry['days']       = $byDay;
            foreach ($summary as $k => $n)   { $totals[$k]   += $n; }
            foreach ($pmSummary as $k => $n) { $pmTotals[$k] += $n; }
        }

        $out[] = $entry;
    }

    $payload = array(
        'success'      => true,
        'comp_id'      => $compId,
        'company_name' => $companyName,
        'generated_at' => date('Y-m-d H:i:s'),
        'mode'         => $mode,
        'from'         => $fromIso,
        'to'           => $toIso,
        'rules'        => array(
            /* TWO SLOTS, JUDGED DIFFERENTLY, and each one says which fields
             * on a staff row carry it — so a caller reads the rule rather
             * than inferring it from a field name. */
            'periods'   => array(
                'am' => array(
                    'period'    => ATT_PERIOD_AM,
                    'judges'    => 'punctuality',
                    /* PER PERSON PER DAY, and every cell carries the line it
                     * was judged against as late_from. A caller comparing a
                     * time against that uses <, the same way this file does,
                     * and gets the same answer — which is why the rule is
                     * published as one number per cell and not as a boundary
                     * somebody has to guess the inclusiveness of. */
                    'late_from_rule'    => 'shift_start + '
                                         . ATT_LATE_GRACE_MINUTES . ' minute',
                    'late_from_default' => ATT_LATE_FROM,
                    'grace_minutes'     => ATT_LATE_GRACE_MINUTES,
                    /* The default under its old name, for a caller written
                     * when this was the one company-wide line. Read the
                     * cell's own late_from instead: on a 7:00am shift this
                     * number is an hour out. */
                    'late_from' => ATT_LATE_FROM,
                    'statuses'  => array('on_time', 'late', 'no_data'),
                    'fields'    => array('time', 'status', 'source', 'late_from'),
                    'summary'   => 'summary',
                ),
                'pm' => array(
                    'period'    => ATT_PERIOD_PM,
                    /* Presence, not punctuality: there is no late_from here
                     * because nothing at 4:30pm is late. */
                    'judges'    => 'presence',
                    'opens_at'  => ATT_PM_FROM,
                    'statuses'  => array('has_data', 'no_data'),
                    'fields'    => array('pm_time', 'pm_status', 'pm_source'),
                    'summary'   => 'pm_summary',
                ),
            ),
            /* In the order they are tried, which is the order a tie would be
             * broken in if the sources ever overlapped much. They barely do
             * — see the chain at the top of this file. */
            'sources'   => array('attendance_app', 'site_check_in', 'face_log'),
            /* The three below are the morning's rule under the names it was
             * published with before the afternoon slot existed, kept so a
             * caller written against that answer still reads the same thing.
             * rules.periods.am is the same rule and is where to read it.
             *
             * late_from HERE IS NOW ONLY THE FALLBACK. It was the one line
             * for the whole company; lateness is each person's shift_start
             * plus the grace, and the cell's own late_from is the number
             * that was applied to it. */
            'period'    => ATT_PERIOD_AM,
            'late_from' => ATT_LATE_FROM,
            'statuses'  => array('on_time', 'late', 'no_data'),
            /* THE WORKING WEEK, published so a caller can see which schedule
             * a person's hours came from rather than inferring it. The five
             * are the whole of evaluation.schedule_type, parsed exactly as
             * every staff[].schedule was. */
            'schedule'  => array(
                'fields'      => array('is_off_day', 'shift_start', 'shift_end'),
                'defaults'    => array('staff' => ATT_SCHEDULE_DEFAULT_STAFF,
                                       'gw'    => ATT_SCHEDULE_DEFAULT_GW),
                'sunday_off'  => ATT_SCHEDULE_SUNDAY_OFF ? '1' : '0',
                /* WHAT THE SCHEDULE DECIDES, and what it does not. It sets
                 * the morning's line — shift_start + the grace — so a 7:00am
                 * person is late an hour before an 8:00am one. It does NOT
                 * excuse an off day, scope the afternoon, or touch pm_status;
                 * those stay the caller's to apply. */
                'scores'      => 'late_from',
                'excuses'     => 'nothing',
                'types'       => $scheduleTypes,
            ),
        ),
        'scope'        => $scope,
        /* The calendar for the range, keyed by Y-m-d EVEN IN MONTH MODE,
         * where staff[].days is keyed by day of the month. Published once
         * here rather than on every person, because a holiday is
         * company-wide, and source names the copy that actually answered. */
        'holidays'     => $holidayBlock,
        'counts'       => array(
            'people' => count($out),
            'days'   => count($days),
            /* Over every person AND every day, so in month mode these add up
             * to people x days and not to people. The first three are the
             * 8am slot, the pm_ pair the 4:30pm one; each set adds up to
             * people x days on its own, and adding one set to the other
             * would count every cell twice. */
            'on_time'     => $totals['on_time'],
            'late'        => $totals['late'],
            'no_data'     => $totals['no_data'],
            'pm_has_data' => $pmTotals['has_data'],
            'pm_no_data'  => $pmTotals['no_data'],
            /* Person-days nobody was due in for — Sundays, and whichever
             * Saturdays each schedule has off. Inside the six figures above
             * rather than beside them: an off day is still counted as
             * whatever its two statuses were, because this endpoint marks
             * the schedule and scores nothing by it. */
            'off_day'     => $offDays,
            /* Days in the range, NOT people x days — a holiday is one fact
             * about the company. 0 when the calendar could not be read, which
             * holidays.source says plainly rather than implying here. */
            'public_holidays' => $holidayCount,
        ),
        'staff'        => $out,
    );

    if ($mode === 'month') {
        $payload['year']  = (int)$year;
        $payload['month'] = (int)$month;
    } else {
        /* One day, so the company-wide flag is readable without walking into
         * holidays.days — the same value every staff row in this answer
         * carries, for a caller drawing a single day. */
        $payload['is_public_holiday'] = attHolidayFlag($holidays, $fromIso);
    }

    respond($payload);
} catch (Exception $e) {
    error_log('attendanceAPI: ' . $e->getMessage());
    /* Longest name first, because these are prefixes of each other:
     * 'staff_attendance' opens three of the others, and 'staff_attendance_face'
     * opens 'staff_attendance_face_log'. Testing a short one first would blame
     * the app for a site check-in failure, or the readings for a bad upload
     * header. */
    foreach (array('staff_attendance_face_log' => 'the face log',
                   'staff_attendance_device'   => 'the face terminal enrolment',
                   'staff_attendance_face'     => 'the face log uploads',
                   'staff_attendance_logs'     => 'site check-in',
                   'gw_attendance'             => 'the GW attendance app',
                   'staff_attendance'          => 'the staff attendance app',
                   'organization_chart_glob'   => 'the reporting line',
                   'department_head'           => 'the reporting line') as $table => $what) {
        if (strpos($e->getMessage(), $table) !== false) {
            fail('Could not read ' . $what . ' (' . $table . '). Check the '
               . 'error log.', 500);
        }
    }
    fail('Could not build the attendance report. Check the error log.', 500);
}
