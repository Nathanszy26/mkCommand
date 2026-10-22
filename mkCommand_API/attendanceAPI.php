<?php
header('Content-Type: application/json; charset=UTF-8');

/**
 * ATTENDANCE API — did this person turn up, when, and where do we know it from.
 *
 * MEANT TO BE READ BESIDE staffPoolAPI.php, not instead of it. That endpoint
 * owns who exists and what the org looks like — the pool, hod[], am[], the
 * reporting line. This one answers one question per person and nothing else,
 * keyed by the SAME identity it prints:
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
 *     daily:  time, status, source, is_public_holiday
 *     month:  summary{on_time,late,no_data},
 *             days{ "1": {time,status,source,is_public_holiday} }
 *
 *   GROUP A REPORT BY hod_unit AND am_unit, NOT BY A BRANCH. Each person has
 *   exactly one of each, so grouping covers the company once. The branches in
 *   staffPoolAPI.php overlap by design — everybody appears under every manager
 *   above them — and a report grouped by those would count people repeatedly.
 *   attendanceBoard.php groups by these two.
 *
 * THE RULE — one slot, 8:00am, and nothing else is judged.
 *   status   on_time   checked in before 08:01:00
 *            late      08:01:00 or after
 *            no_data   neither source has them that day
 *   source   attendance_app | site_check_in | "" when no_data
 *
 *   THE WHOLE 8:00 MINUTE IS ON TIME. 08:00:04 has not reached 8:01, so it is
 *   not late; 08:01:00 is the first late second. rules.late_from publishes
 *   that one number, so a caller applying it with < gets exactly what this
 *   file got.
 *
 *   A person is looked for in this order, and the FIRST hit wins:
 *     1. the attendance app — evaluation.staff_attendance for staff,
 *        evaluation.gw_attendance for a GW, both at period 8AM, as
 *        staffAttendanceReport.php and gwAttendanceReport.php read them
 *        (self check-in, submitted from the app).
 *     2. site check-in — mkPortal.staff_attendance_logs, the earliest check-in
 *        they made that day, as siteCheckInReport.php's STAFF report reads
 *        it. Lunch-break rows are not arrivals and are left out. Staff only:
 *        a GW has no users row and so never appears there.
 *     3. nothing: no_data.
 *
 *   The order is the point. On 20 Oct 2026 the app had 505 of Globinaco's
 *   people and site check-in had 30, with almost nobody in both. They are two
 *   populations doing two jobs, so this is a fallback and never a tie-break.
 *
 *   EARLIEST WINS within a source. Somebody who starts six patrol rounds in a
 *   day is judged on the first one, and a person with two app submissions on
 *   the same slot is judged on the earlier. Anything else would let a late
 *   second entry erase an on-time first one.
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
 *   IT DOES NOT MOVE status. A no_data on Deepavali is still a no_data: this
 *   endpoint judges the 8am slot and nothing else, and whether a company
 *   holiday excuses an absence is a policy question the CALLER owns. The flag
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

/* ---------- the rule ------------------------------------------------------ */

/** The one slot this endpoint judges. The app writes this exact string into
 *  attendance_period / gw_attendance_period — see $ATT_PERIODS in
 *  attendance/includes/attendanceReportShared.php, which is where the code
 *  comes from. Change both or neither. */
define('ATT_PERIOD', '8AM');

/**
 * THE MOMENT IT BECOMES LATE. Anything before this is on time, so the whole
 * of the 8:00 minute counts — 08:00:04 has not reached 8:01 and is on time,
 * 08:01:00 is the first late second.
 *
 * Expressed as the start of lateness rather than as a last-on-time value
 * because that is the rule in one unambiguous number: there is no "is the
 * boundary inclusive" question to get wrong. Compared as a string, which is
 * safe because both sides are zero-padded HH:MM:SS.
 */
define('ATT_LATE_FROM', '08:01:00');

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
     * Query: the staff app's 8am check-ins, as the staff report reads them.
     *
     * Indexed BOTH ways on purpose. That report matches a person on
     * (attendance_by_person AND attendance_by_comp_id) OR mymk_id, because the
     * app has written both over the years; this returns both keys so the
     * caller can try the pool's person first and its mk_id second, and find
     * the same rows that report finds.
     *
     * MIN(time) per day: see EARLIEST WINS at the top of this file.
     */
    public function staffApp($compId, $fromYmd, $toYmd)
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
        $stmt->execute(array(':period' => ATT_PERIOD, ':f' => $fromYmd,
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
     * Query: the GW app's 8am check-ins, as the GW report reads them — keyed
     * on monthly_assign_gw_code, which is the pool's `person` for a GW.
     *
     * A row with no code identifies nobody and is dropped: the table carries
     * plenty of them from submissions that never named a worker.
     *
     * NOT scoped by company, unlike the other two. A GW code is unique across
     * the group, and the caller only ever looks up codes the pool handed it —
     * so a company filter here could only ever remove a row that was about to
     * be ignored anyway.
     */
    public function gwApp($fromYmd, $toYmd)
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
        $stmt->execute(array(':period' => ATT_PERIOD, ':f' => $fromYmd, ':t' => $toYmd));

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
     * LUNCH IS NOT AN ARRIVAL. is_lunch_break marks the out/in pair somebody
     * logs at midday; counting one would turn a lunch break into a very late
     * start on any day whose arrival row is missing. Dropped here so the
     * earliest row left is an arrival.
     *
     * Indexed by person AND by mymk_id, exactly as staffApp() is and for the
     * same reason — this table carries both, and the pool's mk_id matches the
     * second when a login has been renamed.
     *
     * Deleted rows are retractions and stay out.
     */
    public function siteCheckIn($compId, $fromYmd, $toYmd)
    {
        $sql = "SELECT l.person, l.mymk_id, DATE(l.checked_in_at) AS d,
                       MIN(TIME(l.checked_in_at)) AS t
                  FROM mkPortal.staff_attendance_logs l
                 WHERE l.deleted = '0'
                   AND l.comp_id = :c
                   AND IFNULL(l.is_lunch_break, 0) <> 1
                   AND l.checked_in_at IS NOT NULL
                   AND DATE(l.checked_in_at) BETWEEN :f AND :t
                 GROUP BY l.person, l.mymk_id, DATE(l.checked_in_at)";

        /* comp_id is VARCHAR here, so it binds as a string. */
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array(':c' => (string)(int)$compId, ':f' => $fromYmd, ':t' => $toYmd));

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

    /** Command: keep the earliest time seen for this key and day. The GROUP BY
     *  already does it per row set; this holds when one person's rows arrive
     *  under two spellings of the same key. */
    private static function keepEarliest(array &$index, $key, $date, $time)
    {
        if (!isset($index[$key][$date]) || $time < $index[$key][$date]) {
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
     *  against ATT_LATE_FROM as a string is the same as comparing clocks. */
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
 * Query: one person's day, as the answer prints it — where they were sourced
 * from, what the 8am rule makes of it, and whether the company was on holiday.
 *
 * The holiday is a fact about the DAY and not about this person, and it DOES
 * NOT move status: see PUBLIC HOLIDAYS at the top of this file. It is attached
 * here, in the one place every cell of both modes passes through, so no caller
 * has to remember which mode carries it.
 */
function attCell(array $row, $date, array $staffApp, array $gwApp, array $site,
                 array $holidays)
{
    $cell = attArrival($row, $date, $staffApp, $gwApp, $site);
    $cell['is_public_holiday'] = attHolidayFlag($holidays, $date);
    return $cell;
}

/**
 * Query: the first source that has this person on this day, and what it says.
 *
 * THE ORDER IS THE RULE. App first, site check-in second, nothing third; see
 * the chain at the top of this file. Written once, here, so the daily answer
 * and every cell of a monthly one are decided the same way.
 */
function attArrival(array $row, $date, array $staffApp, array $gwApp, array $site)
{
    $person = strtolower(trim((string)$row['person']));
    $isGw   = ($row['person_type'] === 'gw');

    if ($isGw) {
        if (isset($gwApp[$person][$date])) {
            return attJudge($gwApp[$person][$date], 'attendance_app');
        }
        /* No site-check-in arm for a GW on purpose: that table is keyed on
         * users.person, which a GW does not have, so a code landing on
         * somebody's login there would be a different person. */
        return attJudge(null, '');
    }

    if (isset($staffApp['by_person'][$person][$date])) {
        return attJudge($staffApp['by_person'][$person][$date], 'attendance_app');
    }
    $mkId = isset($row['mk_id']) ? (int)$row['mk_id'] : 0;
    if ($mkId > 0 && isset($staffApp['by_mk_id'][$mkId][$date])) {
        return attJudge($staffApp['by_mk_id'][$mkId][$date], 'attendance_app');
    }
    if (isset($site['by_person'][$person][$date])) {
        return attJudge($site['by_person'][$person][$date], 'site_check_in');
    }
    if ($mkId > 0 && isset($site['by_mk_id'][$mkId][$date])) {
        return attJudge($site['by_mk_id'][$mkId][$date], 'site_check_in');
    }
    return attJudge(null, '');
}

/** Query: a time and a source as the answer prints them. */
function attJudge($time, $source)
{
    if ($time === null) {
        return array('time' => '', 'status' => 'no_data', 'source' => '');
    }
    return array(
        'time'   => $time,
        'status' => ($time < ATT_LATE_FROM) ? 'on_time' : 'late',
        'source' => $source,
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

    /* ---- the three reads, once each ---- */
    $att      = new AttendanceRepository($pdo);
    $appFrom  = str_replace('-', '/', $fromIso);
    $appTo    = str_replace('-', '/', $toIso);

    $staffApp = $att->staffApp($compId, $appFrom, $appTo);
    $gwApp    = $att->gwApp($appFrom, $appTo);
    $site     = $att->siteCheckIn($compId, $fromIso, $toIso);

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
    $out    = array();
    $totals = array('on_time' => 0, 'late' => 0, 'no_data' => 0);

    foreach ($rows as $row) {
        $public = publicRow($row, $overrides, $compNames);

        /* Which department this person BELONGS to — the nearest head above
         * them, not every head above them. One hod unit and at most one am
         * unit each, so grouping a report by these covers everybody once.
         * See StaffPoolTree::placement(). */
        $where = $tree->placement($row['person'], $row['comp_id'], $row['person_type']);

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
        ) + $where;

        if ($mode === 'daily') {
            $cell  = attCell($row, $fromIso, $staffApp, $gwApp, $site, $holidays);
            $entry = $entry + $cell;
            $totals[$cell['status']]++;
        } else {
            $byDay   = array();
            $summary = array('on_time' => 0, 'late' => 0, 'no_data' => 0);
            foreach ($days as $day) {
                $cell = attCell($row, $day, $staffApp, $gwApp, $site, $holidays);
                /* Keyed by day OF THE MONTH, which is what a month grid is
                 * indexed by on screen; the full date is derivable from
                 * year + month and is not repeated 31 times per person. */
                $byDay[(string)(int)substr($day, 8, 2)] = $cell;
                $summary[$cell['status']]++;
            }
            $entry['summary'] = $summary;
            $entry['days']    = $byDay;
            foreach ($summary as $k => $n) { $totals[$k] += $n; }
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
            'period'    => ATT_PERIOD,
            /* The moment lateness begins. A caller comparing a time against
             * this uses <, the same way this file does, and gets the same
             * answer — which is why the rule is published as one number and
             * not as a boundary somebody has to guess the inclusiveness of. */
            'late_from' => ATT_LATE_FROM,
            'sources'   => array('attendance_app', 'site_check_in'),
            'statuses'  => array('on_time', 'late', 'no_data'),
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
             * to people x days and not to people. */
            'on_time' => $totals['on_time'],
            'late'    => $totals['late'],
            'no_data' => $totals['no_data'],
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
    /* Longest name first: 'staff_attendance' is a prefix of
     * 'staff_attendance_logs', so testing it first would blame the app for a
     * site check-in failure. */
    foreach (array('staff_attendance_logs'    => 'site check-in',
                   'gw_attendance'            => 'the GW attendance app',
                   'staff_attendance'         => 'the staff attendance app',
                   'organization_chart_glob'  => 'the reporting line',
                   'department_head'          => 'the reporting line') as $table => $what) {
        if (strpos($e->getMessage(), $table) !== false) {
            fail('Could not read ' . $what . ' (' . $table . '). Check the '
               . 'error log.', 500);
        }
    }
    fail('Could not build the attendance report. Check the error log.', 500);
}
