<?php
header('Content-Type: application/json; charset=UTF-8');

/**
 * LEAVE API — who was on leave this month, what kind, and on which days.
 *
 * MEANT TO BE READ BESIDE staffPoolAPI.php AND attendanceAPI.php, not instead
 * of them. staffPoolAPI.php owns who exists and what the org looks like;
 * attendanceAPI.php answers whether a person turned up; this one answers
 * whether they were entitled not to. All three print the SAME identity:
 *
 *     person + comp_id + staff_type
 *
 * so a caller holding two of them joins on that and has names, attendance and
 * leave together. A no_data in attendanceAPI.php and a leave record here are
 * the two halves of "absent but excused" — this endpoint deliberately does not
 * repeat the attendance, and that one deliberately does not repeat the leave.
 *
 * MUST be hosted on globportal.com beside staffPoolAPI.php: staff_portal2,
 * evaluation and gas_usage are all local there under prog4. hr is NOT — see
 * THE SOURCES below.
 *
 * Read-only. Every query in this file is a SELECT.
 *
 * IN (GET or POST):
 *   comp_id   int     company 1 only for now — see leaveSupportedComps().
 *
 *   -- WHEN. A whole month, which is the only mode --
 *   year      int     four digits. Default: the current month.
 *   month     1-12    with year. Both or neither.
 *
 *   -- WHO. Everybody in the company unless you narrow it --
 *   person    string  that person AND everybody under them in the reporting
 *                     line, at any depth. Read from staffPoolTree.php, so
 *                     "under them" means exactly what staffPoolAPI.php means
 *                     by it, hand-written placements included. Accepted as
 *                     `staff` too, the name attendanceAPI.php uses.
 *
 * OUT:
 *   { success, comp_id, company_name, generated_at, mode, year, month,
 *     from, to, rules{}, sources{}, scope{}, counts{}, staff[] }
 *
 *   staff[] is one row per person IN THE SCOPE, each ONCE, whether or not they
 *   took leave — so a caller can render the whole company and see the zeroes.
 *     person, comp_id, staff_type       the key to join the other two APIs on
 *     fullname, position, department, district, hod_department_name
 *     hod_person, hod_unit, am_person, am_unit   which department they BELONG
 *                                       to — the nearest head above them, and
 *                                       the nearest sub-department head.
 *     total_staff                       1 in the pool, 0 if an override took
 *                                       them out of it. They are listed either
 *                                       way: leave is a fact about a person.
 *     summary{ records, day_count, by_type{} }
 *     leaves[]                          one entry per leave application
 *     days{ "3": {leave_id,leave_type,half} }   day OF THE MONTH -> that day
 *
 *   applied_date ON A leaves[] ENTRY IS WHEN THE APPLICATION WAS MADE, Y-m-d,
 *   and is not one of the days taken. It is usually before date_from and is
 *   sometimes after it, because leave does get filed late — so it is the
 *   field to read for "how much notice was given", and never a day to count.
 *   days_in_month and the totals are unaffected by it.
 *
 *   '' MEANS NOT RECORDED, not "today" and not an error. It comes from
 *   apply_date on the two hr tables, which gas_usage.driver_leaves has no
 *   equivalent of — so a leave only that table knows about is blank here,
 *   exactly as its reason is. See THE SOURCES below.
 *
 *   GROUP A REPORT BY hod_unit AND am_unit, NOT BY A BRANCH — same rule as
 *   attendanceAPI.php, and for the same reason: each person has exactly one of
 *   each, while the branches overlap by design and would count people twice.
 *
 * THE SOURCES — THREE TABLES ON TWO HOSTS, and which one applies depends on
 * whether the person is staff or a GW. They never mix.
 *
 *   STAFF, both of these, merged:
 *     hr.leave_application      on 175.145.207.141. staff_id IS users.person.
 *                               Dates stored 'Y/m/d' (all 60,064 rows), and
 *                               apply_date with them — the one table family
 *                               that records when an application was made.
 *     gas_usage.driver_leaves   local. driver_leave_person is users.person.
 *                               Dates stored 'Y-m-d'. NINE COLUMNS, all of
 *                               them the leave itself: no apply_date, no
 *                               reason, no cancel_date. A row only this table
 *                               has comes back with applied_date ''.
 *
 *     DEDUPLICATED ON leave_application_id, WHICH IS NOT OPTIONAL. Every one
 *     of the 19,635 driver_leaves rows carries the id of its
 *     hr.leave_application parent and mirrors it exactly — same person, type,
 *     dates and status, only the date format differs. Listing both without
 *     merging would show every driver's leave twice. HR wins on a tie because
 *     it is the master; a row only driver_leaves has is still reported, and
 *     `source` on each record says which table answered.
 *
 *   GW, one table:
 *     hr.leave_application_GW   on 175.145.207.141. Dates MOSTLY 'Y-m-d' with
 *                               47 rows as 'Y/m/d'.
 *
 *     MATCHED ON NRIC, NOT ON A CODE, because that table has no GW code at
 *     all: its staff_id is the worker's NRIC. leaveNricKey() strips both sides
 *     to digits so '890616-12-6515' and '890616126515' are one key. A GW WITH
 *     NO NRIC ON FILE CANNOT BE MATCHED and comes back with no leave — 4 of
 *     367 at Globinaco, but almost every GW record at Arus Sawit, so that
 *     company will look empty here until those records are filled in.
 *
 *   hr IS A DIFFERENT HOST and may be unreachable. When it is, this endpoint
 *   still answers from gas_usage.driver_leaves and sources.hr says
 *   "unavailable" — a PARTIAL answer that names what is missing, rather than
 *   an empty one that looks like "nobody took leave". CHECK sources BEFORE
 *   TRUSTING AN EMPTY RESULT.
 *
 * WHICH APPLICATIONS COUNT — statuses 1, 2 and 4: NEW, PROCESSING, APPROVED.
 *   CANCELLED (3) AND REJECTED (5) ARE EXCLUDED, by every one of the three
 *   reads, because neither keeps anybody off work and so neither is a leave.
 *   The two hr tables also carry a cancel_date and must have it EMPTY — a
 *   cancellation is recorded there as well as in the status, and a row can
 *   carry one without the other. gas_usage.driver_leaves has no such column,
 *   so its status is the whole test.
 *
 *   rules.statuses lists only the three that can appear in staff[], and
 *   rules.statuses_excluded names the two that cannot, so the answer says what
 *   it left out instead of publishing a vocabulary that looks like a promise.
 *   The same three attendanceReportShared.php counts, so this endpoint and the
 *   attendance reports agree about who was away.
 *
 * SICK LEAVE RECEIPTS — receipt_date, AND ONLY WHERE ONE ACTUALLY MATCHES.
 *   hr.medical_claim holds the receipts people claim medical expenses against.
 *   A receipt is attached to a leave record when ALL FIVE of these hold, and
 *   receipt_date is '' whenever any of them does not:
 *
 *     1. the record is SICK LEAVE — leave_type 'SL' and nothing else. An AL
 *        or an EL never carries a receipt here even if the person happened to
 *        claim one that week.
 *     2. the person is STAFF, never a GW. medical_claim.staff_id is a login
 *        name (users.person, e.g. 'nadzmar'), and a GW's `person` is a code
 *        from a different namespace — so a GW code landing on a staff login
 *        would be a different human being.
 *     3. medical_claim.staff_id matches that person.
 *     4. the receipt falls INSIDE the record's own date_from..date_to, which
 *        is the whole span of the application and not the part clipped to this
 *        month — a receipt dated 28 Sep belongs to an SL that began on the
 *        28th, whichever month you asked about.
 *     5. the receipt date PARSES. See leaveClaimDate().
 *
 *   SEVERAL RECEIPTS CAN FALL INSIDE ONE SICK LEAVE, and they are not always
 *   the same day: one four-day SL in Jan 2026 carries receipts from the 6th
 *   and the 7th, and one one-day SL carries three receipts all dated that day.
 *   So there are TWO fields, because one would quietly lose the rest:
 *     receipt_date   the EARLIEST matching receipt, or '' — the single value
 *     receipt_dates  every DISTINCT matching receipt date, in order, or []
 *   receipt_date is always receipt_dates[0] when there is one.
 *
 *   NO STATUS FILTER, deliberately. medical_claim.status is 'yes', 'no' or ''
 *   (117 rows say 'no'), and a receipt_date is a fact about a receipt that
 *   exists whether or not the claim was ever paid. Filtering on a status this
 *   endpoint does not publish would hide rows for a reason a caller could not
 *   see. Say so and it can be added.
 *
 *   medical_claim has NO comp_id, only a login name — the same exposure
 *   hr.leave_application carries, and bounded the same way: this endpoint only
 *   looks up names the company's own pool handed it.
 *
 * HALF DAYS ARE REAL. Every one of these tables stores from_apm / to_apm as
 *   'AM' or 'PM'. A record starting PM or ending AM is half a day at that
 *   edge, so `half` on the day says 'am', 'pm' or '' and day_count is 0.5 per
 *   half day. A caller summing day_count gets 1.5, not 2, for a day and a
 *   morning.
 *
 * OVERLAPPING APPLICATIONS — THE SHORTEST OWNS THE DAY. One application can
 *   sit inside another: a GW record runs 3 Sep to 3 Nov 2026 over four shorter
 *   ones, and a staff LWP can wrap a one-day EL. days{} gives each day to the
 *   SHORTEST record covering it, so a day reads as the specific leave taken
 *   and not as the long stretch around it, and day_count never counts a day
 *   twice. leaves[] still lists every application in date order.
 *
 * PHP 7.2 compatible.
 */

require_once __DIR__ . '/staffPoolCore.php';

/* ---------- the rule ------------------------------------------------------ */

/** The application statuses that mean somebody is actually off work, per
 *  hr.leave_status: 1 NEW, 2 PROCESSING, 4 APPROVED. 3 is CANCELLED and 5 is
 *  REJECTED. Change this and the attendance reports disagree with this file. */
function leaveCountedStatuses()
{
    return array('1', '2', '4');
}

/** hr.leave_status, as names — ALL FIVE, which is the vocabulary and NOT the
 *  list of what this endpoint returns. Only leaveCountedStatuses() can reach an
 *  answer; 3 and 5 are here so leaveExcludedStatusNames() can say what was
 *  thrown away and why. Held here rather than queried: five rows of reference
 *  data that have not moved, and hr is the host that may be down — a status
 *  with no name here is reported by its code. */
function leaveStatusNames()
{
    return array(
        '1' => 'NEW',
        '2' => 'PROCESSING',
        '3' => 'CANCELLED',
        '4' => 'APPROVED',
        '5' => 'REJECTED',
    );
}

/** Query: the statuses that CAN appear in an answer, named. */
function leaveCountedStatusNames()
{
    $out = array();
    foreach (leaveCountedStatuses() as $status) {
        $out[$status] = leaveStatusName($status);
    }
    return $out;
}

/**
 * Query: the statuses this endpoint throws away, named — 3 CANCELLED and
 * 5 REJECTED today.
 *
 * Published so an answer SAYS WHY a cancelled application is not in it, rather
 * than leaving a caller to wonder whether it was filtered or simply missing.
 * Derived from the same two lists as everything else, so adding a status in one
 * place cannot leave this one stale.
 */
function leaveExcludedStatusNames()
{
    $out = array();
    foreach (leaveStatusNames() as $status => $name) {
        if (!in_array((string)$status, leaveCountedStatuses(), true)) {
            $out[$status] = $name;
        }
    }
    return $out;
}

/** hr.leave_type, code => title. Held here for the same reason as the statuses.
 *  All three leave tables store the CODE in leave_type_id / driver_leave_type,
 *  never the numeric id, so the code is the key. An unknown code is reported
 *  as itself rather than guessed at. */
function leaveTypeNames()
{
    return array(
        'AL'  => 'Annual Leave',
        'SL'  => 'Sick Leave',
        'ML'  => 'Maternity Leave',
        'PL'  => 'Paternity Leave',
        'LWP' => 'Leave Without Pay',
        'CL'  => 'Compassionate Leave',
        'CPL' => 'Compulsory Leave',
        'EL'  => 'Emergency Leave',
        'COL' => 'Company Leave',
        'MRL' => 'Marriage Leave',
    );
}

/** The two tables that answer for staff, and the one that answers for a GW. */
define('LEAVE_TABLE_HR',     'hr.leave_application');
define('LEAVE_TABLE_GW',     'hr.leave_application_GW');
define('LEAVE_TABLE_DRIVER', 'gas_usage.driver_leaves');

/** The receipts behind a sick leave. On HR's host, like the two tables above
 *  it. See SICK LEAVE RECEIPTS at the top. */
define('LEAVE_TABLE_CLAIM',  'hr.medical_claim');

/** HR's host. Same host and credentials attHrDb() uses in
 *  attendance/includes/attendanceReportShared.php and attendanceAPI.php reads
 *  the holiday calendar from. The only non-local read in this file. */
define('LEAVE_HR_DSN',  'mysql:host=175.145.207.141;port=3306;dbname=hr;charset=utf8mb4');
define('LEAVE_HR_USER', 'portal');
define('LEAVE_HR_PASS', 'portal2018');

/** Companies this endpoint answers for. The same single company
 *  staffPoolTree.php draws and attendanceAPI.php answers for — the org it
 *  scopes `person` by is only built for Globinaco today. */
function leaveSupportedComps()
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
            /* The GW and driver tables are latin1 and `reasons` is free text,
             * so an invalid byte is a real possibility; a partial answer
             * naming the problem beats an empty body and silence. */
            error_log('leaveAPI json: ' . json_last_error_msg());
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

/* ---------- small shared answers ------------------------------------------ */

/**
 * Query: a stored date as Y-m-d, or null when it is not a date at all.
 *
 * ALL THREE TABLES ARE CONSULTED AND THEY DISAGREE: hr.leave_application is
 * 'Y/m/d' throughout, gas_usage.driver_leaves is 'Y-m-d', and
 * hr.leave_application_GW is mostly 'Y-m-d' with 47 rows of 'Y/m/d'. Both
 * separators are accepted so nothing after this point has to care, and a row
 * carrying neither is dropped rather than guessed at.
 *
 * Deliberately a copy of attendanceAPI.php's attIsoDate(): these are two
 * endpoints, and requiring that file here would run its request.
 */
function leaveIsoDate($value)
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
 * Query: a medical_claim.receipt_date as Y-m-d, or null when it cannot be
 * placed. Stricter about SHAPE and looser about PADDING than leaveIsoDate(),
 * because this one column is messier than the three leave tables:
 *
 *   11,744 of 11,764 rows are YEAR FIRST — '2026/10/03', '2026-09-05', and
 *   also UNPADDED ones like '2021-11-2' and '2021/8/13'. All of those are
 *   accepted: year first means the parts cannot be mistaken for each other,
 *   and leaveIsoDate() would drop every unpadded one.
 *
 *   19 rows are NOT year first — '03/08/2020', '5/1/2022' — and are REFUSED
 *   rather than guessed at. Nothing in the column says whether '03/08/2020' is
 *   3 August or 8 March, and attaching the wrong receipt to somebody's sick
 *   leave is worse than attaching none. 1 row is blank and goes the same way.
 */
function leaveClaimDate($value)
{
    $value = trim((string)$value);
    if (!preg_match('/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})$/', $value, $m)) {
        return null;
    }
    $iso = sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    $d   = DateTime::createFromFormat('Y-m-d', $iso);
    return ($d && $d->format('Y-m-d') === $iso) ? $iso : null;
}

/**
 * Query: an NRIC reduced to nothing but its digits, so '890616-12-6515' and
 * '890616126515' are the same key.
 *
 * THE TWO SIDES STORE IT DIFFERENTLY and that is the whole reason this exists:
 * hr.leave_application_GW.staff_id carries the dashes, monthly_assign_gw_nric
 * carries them too, gw_nric does not, and a handful of rows on both sides have
 * stray spaces. Stripping to digits makes one key out of all of them.
 */
function leaveNricKey($nric)
{
    return preg_replace('/\D+/', '', (string)$nric);
}

/** Query: 'AM', 'PM' or '' from whatever the column holds. These are free-text
 *  VARCHARs, so anything else is treated as not-a-half-day. */
function leaveApm($value)
{
    $v = strtoupper(trim((string)$value));
    return ($v === 'AM' || $v === 'PM') ? $v : '';
}

/* ---------- the reads ----------------------------------------------------- */

/**
 * Every leave read, and nothing else. Each one covers the WHOLE month for the
 * WHOLE company in a single query — a per-person query would be 668 of them.
 *
 * All three return the same shape, a flat list of records:
 *   array( key, leave_id, leave_type, status, applied_date, date_from,
 *          date_to, from_apm, to_apm, reason, source )
 * so composing them is one rule applied three times and not three rules.
 */
class LeaveRepository
{
    private $local;    /* globportal: gas_usage      */
    private $hr;       /* 175.145.207.141: hr, or null */

    public function __construct(PDO $local, $hr)
    {
        $this->local = $local;
        $this->hr    = $hr;
    }

    /**
     * Query: HR's staff applications overlapping the month, keyed on the
     * person. staff_id IS users.person here, lower-cased on the way out
     * because the pool's `person` is matched case-insensitively everywhere
     * else in this family of endpoints.
     *
     * NOT SCOPED BY COMPANY, because the table has no comp_id — only a person
     * name. The caller only ever looks up names the pool handed it, so the
     * exposure is two people at two companies sharing one login name, which is
     * the same exposure the attendance reports already carry.
     */
    public function staffHr($fromIso, $toIso)
    {
        if (!($this->hr instanceof PDO)) {
            return null;
        }
        /* Dates are VARCHAR 'Y/m/d' here, so the range is compared on them
         * folded to '-' — zero-padded either way, so it still orders
         * correctly. Newest application first: see leaveComposeDays(). */
        $sql = "SELECT a.leave_application_id AS leave_id,
                       a.staff_id             AS person,
                       a.leave_type_id        AS leave_type,
                       a.leave_status_id      AS status,
                       a.date_from, a.date_to, a.from_apm, a.to_apm,
                       a.reasons              AS reason,
                       a.apply_date           AS applied_date
                  FROM hr.leave_application a
                 WHERE REPLACE(a.date_from,'/','-') <= :t
                   AND REPLACE(a.date_to,'/','-')   >= :f
                   AND a.leave_status_id IN (" . $this->statusPlaceholders() . ")
                   AND (a.cancel_date IS NULL OR a.cancel_date = '')
                 ORDER BY a.leave_application_id DESC";

        return $this->run($this->hr, $sql, $fromIso, $toIso, 'person', LEAVE_TABLE_HR);
    }

    /**
     * Query: the local driver leaves overlapping the month, keyed on the person.
     *
     * EVERY ROW HERE IS A MIRROR of an hr.leave_application row and carries its
     * id — all 19,635 of them — so this is read for the days HR cannot be
     * reached for, and for any row whose parent has gone, and the caller
     * dedupes on leave_id. See THE SOURCES at the top.
     */
    public function staffDriver($fromIso, $toIso)
    {
        /* Dates are VARCHAR 'Y-m-d' here. REPLACE anyway, so one expression
         * serves all three tables and nobody has to remember which is which.
         *
         * No cancel_date test, and that is not an omission: this table has no
         * such column, so driver_leave_status IN (1,2,4) is the whole of it —
         * which still drops the ~1,000 cancelled and ~100 rejected rows. */
        $sql = "SELECT d.leave_application_id   AS leave_id,
                       d.driver_leave_person    AS person,
                       d.driver_leave_type      AS leave_type,
                       d.driver_leave_status    AS status,
                       d.driver_leave_from      AS date_from,
                       d.driver_leave_to        AS date_to,
                       d.driver_leave_from_apm  AS from_apm,
                       d.driver_leave_to_apm    AS to_apm,
                       '' AS reason,
                       /* THIS TABLE DOES NOT RECORD WHEN A LEAVE WAS APPLIED
                        * FOR. Its nine columns are the leave itself and
                        * nothing about the application, so applied_date is ''
                        * for any row only this table has — the same blank,
                        * and for the same reason, as reason above. A row HR
                        * also has gets HR's apply_date, because HR is loaded
                        * first and wins the dedupe; see leaveMergeStaff(). */
                       '' AS applied_date
                  FROM gas_usage.driver_leaves d
                 WHERE REPLACE(d.driver_leave_from,'/','-') <= :t
                   AND REPLACE(d.driver_leave_to,'/','-')   >= :f
                   AND d.driver_leave_status IN (" . $this->statusPlaceholders() . ")
                 ORDER BY CAST(d.leave_application_id AS UNSIGNED) DESC";

        return $this->run($this->local, $sql, $fromIso, $toIso, 'person', LEAVE_TABLE_DRIVER);
    }

    /**
     * Query: the GW applications overlapping the month, keyed on the NRIC
     * reduced to digits — because that table has no GW code. The caller turns
     * that key back into a GW code. See THE SOURCES at the top.
     */
    public function gw($fromIso, $toIso)
    {
        if (!($this->hr instanceof PDO)) {
            return null;
        }
        $sql = "SELECT g.leave_application_id AS leave_id,
                       g.staff_id             AS person,
                       g.leave_type_id        AS leave_type,
                       g.leave_status_id      AS status,
                       g.date_from, g.date_to, g.from_apm, g.to_apm,
                       g.reasons              AS reason,
                       g.apply_date           AS applied_date
                  FROM hr.leave_application_GW g
                 WHERE REPLACE(g.date_from,'/','-') <= :t
                   AND REPLACE(g.date_to,'/','-')   >= :f
                   AND g.leave_status_id IN (" . $this->statusPlaceholders() . ")
                   AND (g.cancel_date IS NULL OR g.cancel_date = '')
                 ORDER BY g.leave_application_id DESC";

        return $this->run($this->hr, $sql, $fromIso, $toIso, 'nric', LEAVE_TABLE_GW);
    }

    /**
     * Query: the medical receipts in these years, as person => sorted distinct
     * Y-m-d dates. Staff only, by nature: staff_id is a login name.
     *
     * BOUNDED BY YEAR, not by the month, and that is on purpose. The caller
     * matches each receipt against one SICK LEAVE's own span, and a sick leave
     * overlapping this month can start or end outside it — so the read has to
     * cover whatever those records reach, which the caller works out and passes
     * in. The whole table is 11,764 rows, so a year or two of it is nothing.
     *
     * The year prefilter is done in SQL on the first four characters and the
     * precise test in PHP, because receipt_date is a VARCHAR of mixed shapes
     * that no single SQL comparison gets right. The REGEXP drops the 19
     * not-year-first rows here rather than letting leaveClaimDate() see them.
     *
     * SUBSTR() and not LEFT(), which does the same thing in MySQL: LEFT is a
     * reserved word elsewhere, and SUBSTR keeps this query runnable against a
     * fixture as well as against MySQL.
     */
    public function medicalClaims($fromYear, $toYear)
    {
        if (!($this->hr instanceof PDO)) {
            return null;
        }
        $sql = "SELECT c.staff_id AS person, c.receipt_date AS d
                  FROM hr.medical_claim c
                 WHERE c.staff_id <> ''
                   AND c.receipt_date REGEXP '^[0-9]{4}[/-]'
                   AND SUBSTR(c.receipt_date, 1, 4) BETWEEN :y1 AND :y2";

        $stmt = $this->hr->prepare($sql);
        $stmt->execute(array(':y1' => sprintf('%04d', (int)$fromYear),
                             ':y2' => sprintf('%04d', (int)$toYear)));

        $out = array();
        foreach ($stmt->fetchAll() as $r) {
            $person = strtolower(trim((string)$r['person']));
            $date   = leaveClaimDate($r['d']);
            if ($person === '' || $date === null) {
                continue;
            }
            /* Keyed by date so the same receipt date claimed twice — which
             * happens — is one date and not two. */
            $out[$person][$date] = true;
        }
        foreach ($out as $person => $dates) {
            $dates = array_keys($dates);
            sort($dates);
            $out[$person] = $dates;
        }
        return $out;
    }

    /**
     * Query: every active GW's NRIC, as code => nric, for the one company.
     *
     * MAX() and a GROUP BY for the same reason StaffPoolRepository::gw() has
     * them: monthly_assign_gw holds a row per GW per REPORTING LINE, so a
     * worker under two superiors is in there twice. The code identifies the
     * person, so the group is the code and the duplicates collapse.
     *
     * Read here rather than taken from the pool because the pool does not
     * carry the NRIC — it has no use for it — and this is the only place in
     * this family of endpoints that needs one.
     */
    public function gwNrics($compId)
    {
        $sql = "SELECT g.monthly_assign_gw_code        AS person,
                       MAX(g.monthly_assign_gw_nric)   AS nric
                  FROM evaluation.monthly_assign_gw g
                 WHERE g.monthly_assign_gw_status = '1'
                   AND IFNULL(g.test_user, 0) <> 1
                   AND g.comp_id = :c
                 GROUP BY g.monthly_assign_gw_code";

        $stmt = $this->local->prepare($sql);
        /* comp_id is VARCHAR here, so it binds as a string. */
        $stmt->execute(array(':c' => (string)(int)$compId));

        $out = array();
        foreach ($stmt->fetchAll() as $r) {
            $code = strtolower(trim((string)$r['person']));
            if ($code !== '') {
                $out[$code] = (string)$r['nric'];
            }
        }
        return $out;
    }

    /** The IN(...) list for the counted statuses, as named placeholders. */
    private function statusPlaceholders()
    {
        $parts = array();
        foreach (leaveCountedStatuses() as $i => $unused) {
            $parts[] = ':s' . $i;
        }
        return implode(',', $parts);
    }

    /**
     * Command: run one of the three reads and normalise what comes back.
     *
     * $keyAs says how the row identifies its person: 'person' for a login name
     * (lower-cased) or 'nric' for a GW's NRIC (reduced to digits). A row whose
     * key or dates will not parse is DROPPED — the tables carry a handful of
     * blank and junk ones, and a leave record nobody can be sure about is
     * worse than none.
     */
    private function run(PDO $db, $sql, $fromIso, $toIso, $keyAs, $source)
    {
        $args = array(':f' => $fromIso, ':t' => $toIso);
        foreach (leaveCountedStatuses() as $i => $status) {
            $args[':s' . $i] = $status;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($args);

        $out = array();
        foreach ($stmt->fetchAll() as $r) {
            $key = ($keyAs === 'nric')
                 ? leaveNricKey($r['person'])
                 : strtolower(trim((string)$r['person']));
            $from = leaveIsoDate($r['date_from']);
            $to   = leaveIsoDate($r['date_to']);
            if ($key === '' || $from === null || $to === null) {
                continue;
            }
            /* A record stored back-to-front is still that person's leave;
             * swapping is the only reading that does not silently lose it. */
            if ($to < $from) {
                $swap = $from; $from = $to; $to = $swap;
            }
            /* NOT A REASON TO DROP THE ROW, unlike the key and the two dates
             * above. When somebody applied is a fact ABOUT the application
             * and not part of it: a leave whose apply_date is blank, junk, or
             * from a table that never recorded one is still that person's
             * leave, on those days. It comes back '' — read it as "not
             * recorded", never as a date. leaveIsoDate() takes the same
             * 'Y/m/d' this column is stored in as date_from does. */
            $applied = leaveIsoDate($r['applied_date']);
            $out[] = array(
                'key'          => $key,
                'leave_id'     => (string)$r['leave_id'],
                'leave_type'   => strtoupper(trim((string)$r['leave_type'])),
                'status'       => (string)$r['status'],
                'applied_date' => $applied === null ? '' : $applied,
                'date_from'    => $from,
                'date_to'      => $to,
                'from_apm'     => leaveApm($r['from_apm']),
                'to_apm'       => leaveApm($r['to_apm']),
                'reason'       => trim((string)$r['reason']),
                'source'       => $source,
            );
        }
        return $out;
    }
}

/* ---------- composing ----------------------------------------------------- */

/**
 * Query: HR's PDO, opened at most once, or null when it cannot be reached.
 *
 * NEVER THROWS. hr holds most of the answer, but gas_usage can still speak for
 * the drivers, so a dead host costs the caller part of the answer and not all
 * of it — and sources.hr says which. See THE SOURCES at the top.
 */
function leaveHrDb()
{
    /* false = not tried yet, null = tried and failed, PDO = open. The three
     * states matter: without them a dead host is retried on every call. */
    static $db = false;
    if ($db !== false) {
        return $db;
    }
    try {
        $db = new PDO(LEAVE_HR_DSN, LEAVE_HR_USER, LEAVE_HR_PASS, array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            /* Short on purpose: this host is across a link that may be
             * firewalled, and a slow answer is worse than a partial one. */
            PDO::ATTR_TIMEOUT            => 5,
        ));
    } catch (Exception $e) {
        error_log('leaveAPI hr connect: ' . $e->getMessage());
        $db = null;
    }
    return $db;
}

/**
 * Query: the staff records for the month, indexed by person, with the two
 * sources merged.
 *
 * DEDUPED ON leave_application_id — the whole reason this function exists. HR
 * is loaded first and wins, so a driver's leave is reported once with HR named
 * as its source; a row only driver_leaves has keeps its own. Ids are compared
 * as strings because driver_leaves stores the id in a VARCHAR.
 */
function leaveMergeStaff($hrRows, $driverRows)
{
    $byPerson = array();
    $seen     = array();

    foreach (array($hrRows, $driverRows) as $rows) {
        if ($rows === null) {
            continue;
        }
        foreach ($rows as $r) {
            $id = $r['leave_id'];
            /* An id of '' or '0' identifies nothing, so it cannot be deduped
             * and is kept as its own record rather than collapsing every such
             * row into one. */
            if ($id !== '' && $id !== '0') {
                $dedupe = $r['key'] . '|' . $id;
                if (isset($seen[$dedupe])) {
                    continue;
                }
                $seen[$dedupe] = true;
            }
            $byPerson[$r['key']][] = $r;
        }
    }
    return $byPerson;
}

/** Query: the GW records for the month, indexed by GW CODE instead of by the
 *  NRIC they arrived under. Two codes CAN share one NRIC — a worker
 *  re-registered under a new code — and the leave belongs to both. */
function leaveIndexGwByCode($gwRows, array $nricByCode)
{
    $out = array();
    if ($gwRows === null) {
        return $out;
    }
    $codesByNric = array();
    foreach ($nricByCode as $code => $nric) {
        $key = leaveNricKey($nric);
        if ($key !== '') {
            $codesByNric[$key][] = $code;
        }
    }
    foreach ($gwRows as $r) {
        if (!isset($codesByNric[$r['key']])) {
            continue;
        }
        foreach ($codesByNric[$r['key']] as $code) {
            $out[$code][] = $r;
        }
    }
    return $out;
}

/**
 * Query: this person's records with receipt_date filled in on the sick ones.
 *
 * $receiptDates is that ONE person's receipts, already sorted, and is empty for
 * a GW — see SICK LEAVE RECEIPTS at the top for why a GW never gets one. Every
 * record comes back carrying both fields, sick or not, so a caller never has to
 * test whether the key exists.
 */
function leaveAttachReceipts(array $records, array $receiptDates)
{
    $out = array();
    foreach ($records as $r) {
        $matched = array();
        /* 'SL' and nothing else. leave_type is upper-cased by the reads. */
        if ($r['leave_type'] === 'SL' && !empty($receiptDates)) {
            foreach ($receiptDates as $date) {
                /* The RECORD's own span, not the part inside this month. */
                if ($date >= $r['date_from'] && $date <= $r['date_to']) {
                    $matched[] = $date;
                }
            }
        }
        $r['receipt_dates'] = $matched;
        /* The single value asked for: the earliest match, or '' when none. */
        $r['receipt_date']  = empty($matched) ? '' : $matched[0];
        $out[] = $r;
    }
    return $out;
}

/**
 * Query: one person's month — their records clipped to it, the per-day map and
 * the totals, from the records that already belong to them.
 *
 * HALF DAYS. A record starting 'PM' is half a day on its first date; one
 * ending 'AM' is half a day on its last. Both apply when the record is one day
 * long, which is how a single morning or afternoon off is stored. day_count
 * adds 0.5 for those and 1 for every other day.
 *
 * THE MOST SPECIFIC RECORD OWNS THE DAY. Overlaps are ordinary here — one GW
 * application runs 3 Sep to 3 Nov 2026 and covers four shorter ones, and a
 * staff LWP can wrap a one-day EL — so the records are tried SHORTEST FIRST
 * and the first one to claim a day keeps it. A one-day emergency leave sitting
 * inside a two-month unpaid stretch is the truthful answer for that day; the
 * long record still answers for every day nothing shorter covers.
 *
 * NOT "newest wins", which is the obvious rule and the wrong one: an id says
 * when the application was filed, nothing about how long it runs, so a long
 * application filed late would bury every short one under it. Ties break on
 * the newer id and then on arrival order, so the same month always resolves
 * the same way.
 *
 * leaves[] comes back in DATE ORDER regardless — that is a list for a human to
 * read, not a priority queue.
 */
function leaveComposeDays(array $records, $fromIso, $toIso)
{
    /* Clip each record to the month once, and keep what the two orderings
     * below both need. A record may start in September and end in November;
     * only the part inside the window is this month's. */
    $prepared = array();
    foreach ($records as $i => $r) {
        $from = ($r['date_from'] < $fromIso) ? $fromIso : $r['date_from'];
        $to   = ($r['date_to']   > $toIso)   ? $toIso   : $r['date_to'];
        if ($from > $to) {
            continue;
        }

        $inMonth = array();
        $cursor  = new DateTime($from);
        $end     = new DateTime($to);
        while ($cursor <= $end) {
            $inMonth[] = $cursor->format('Y-m-d');
            $cursor->modify('+1 day');
        }

        $prepared[] = array('record' => $r, 'days' => $inMonth, 'seq' => $i);
    }

    /* ---- the day map: shortest record first ---- */
    $bySpan = $prepared;
    usort($bySpan, function ($a, $b) {
        $spanA = count($a['days']);
        $spanB = count($b['days']);
        if ($spanA !== $spanB) {
            return $spanA - $spanB;                      /* shorter is more specific */
        }
        if ($a['record']['leave_id'] !== $b['record']['leave_id']) {
            /* Newer application breaks a tie between two equally short ones. */
            return ((int)$b['record']['leave_id']) - ((int)$a['record']['leave_id']);
        }
        return $a['seq'] - $b['seq'];                    /* and arrival order last */
    });

    $days     = array();
    $byType   = array();
    $dayCount = 0.0;

    foreach ($bySpan as $p) {
        $r = $p['record'];
        foreach ($p['days'] as $iso) {
            $dayKey = (string)(int)substr($iso, 8, 2);
            if (isset($days[$dayKey])) {
                continue;                                /* a shorter record has it */
            }

            /* The half is judged against the RECORD's own edges, not the
             * clipped ones: a record that began last month is already under
             * way on the 1st and its from_apm has nothing to say about it. */
            $half = '';
            if ($iso === $r['date_from'] && $r['from_apm'] === 'PM') { $half = 'pm'; }
            if ($iso === $r['date_to']   && $r['to_apm']   === 'AM') { $half = 'am'; }

            $days[$dayKey] = array(
                'leave_id'   => $r['leave_id'],
                'leave_type' => $r['leave_type'],
                'half'       => $half,
            );

            $worth = ($half === '') ? 1 : 0.5;
            $dayCount += $worth;

            $type = ($r['leave_type'] === '') ? '(none)' : $r['leave_type'];
            if (!isset($byType[$type])) { $byType[$type] = 0; }
            $byType[$type] += $worth;
        }
    }

    /* Day of the month, numerically, so a month grid reads in order. */
    uksort($days, function ($a, $b) { return (int)$a - (int)$b; });

    /* ---- the list: date order, for reading ---- */
    $byDate = $prepared;
    usort($byDate, function ($a, $b) {
        if ($a['record']['date_from'] !== $b['record']['date_from']) {
            return strcmp($a['record']['date_from'], $b['record']['date_from']);
        }
        return $a['seq'] - $b['seq'];
    });

    $out = array();
    foreach ($byDate as $p) {
        $r = $p['record'];
        $out[] = array(
            'leave_id'        => $r['leave_id'],
            'leave_type'      => $r['leave_type'],
            'leave_type_name' => leaveTypeName($r['leave_type']),
            'status'          => $r['status'],
            'status_name'     => leaveStatusName($r['status']),
            /* WHEN IT WAS APPLIED FOR, Y-m-d, or '' when no table recorded
             * it. It is NOT one of the leave's days and never belongs in
             * days_in_month — it is the day the application was made, which
             * is usually before date_from and is sometimes after it, because
             * leave gets filed late. '' means "not recorded", and
             * gas_usage.driver_leaves records it for nobody: see
             * staffDriver(). */
            'applied_date'    => isset($r['applied_date']) ? $r['applied_date'] : '',
            /* The WHOLE application, even the part outside this month, so a
             * caller can see that a leave started before the 1st. */
            'date_from'       => $r['date_from'],
            'date_to'         => $r['date_to'],
            'from_apm'        => $r['from_apm'],
            'to_apm'          => $r['to_apm'],
            /* And the part that is this month's, which is what days{} and the
             * totals are built from. */
            'days_in_month'   => $p['days'],
            /* Sick leave only, and only when a receipt really falls inside
             * this record. See SICK LEAVE RECEIPTS at the top. */
            'receipt_date'    => isset($r['receipt_date']) ? $r['receipt_date'] : '',
            'receipt_dates'   => isset($r['receipt_dates']) ? $r['receipt_dates'] : array(),
            'reason'          => $r['reason'],
            'source'          => $r['source'],
        );
    }

    return array(
        'leaves'  => $out,
        'days'    => $days,
        'summary' => array(
            'records'   => count($out),
            'day_count' => $dayCount,
            'by_type'   => $byType,
        ),
    );
}

/** Query: a leave type's title, or the code itself when it is not one we know. */
function leaveTypeName($code)
{
    $names = leaveTypeNames();
    return isset($names[$code]) ? $names[$code] : (string)$code;
}

/** Query: a status's name, or the code itself when it is not one we know. */
function leaveStatusName($status)
{
    $names = leaveStatusNames();
    return isset($names[$status]) ? $names[$status] : (string)$status;
}

/* ---------- the request --------------------------------------------------- */

try {
    $pdo = Db::get();
} catch (Exception $e) {
    error_log('leaveAPI db: ' . $e->getMessage());
    fail('Could not reach the database.', 503);
}

try {
    /* ---- when ---- */
    $year  = input('year');
    $month = input('month');

    if (($year !== null && $year !== '') xor ($month !== null && $month !== '')) {
        fail('year and month go together. Pass both, or neither for this month.');
    }
    if ($year === null || $year === '') {
        $year  = (int)date('Y');
        $month = (int)date('n');
    } else {
        $year  = (int)$year;
        $month = (int)$month;
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            fail('year must be a four-digit year and month must be 1 to 12.');
        }
    }

    $fromIso = sprintf('%04d-%02d-01', $year, $month);
    $toIso   = date('Y-m-t', strtotime($fromIso));

    /* ---- which company ---- */
    $rawComp = input('comp_id');
    $compId  = ($rawComp === null || $rawComp === '') ? 0 : (int)$rawComp;
    if (!in_array($compId, leaveSupportedComps(), true)) {
        fail('Leave is only built for company '
           . implode(' and ', leaveSupportedComps())
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

    /* The pool, exactly as staffPoolAPI.php builds it, so every endpoint in
     * this family is answering about the same people. */
    $pool      = array_merge($repo->staff($compId), $repo->gw($compId));
    $overrides = $repo->overrides($compId);

    $treeFile = __DIR__ . '/staffPoolTree.php';
    if (!file_exists($treeFile)) {
        fail('staffPoolTree.php is not on this server yet. Upload it beside '
           . 'leaveAPI.php.', 500);
    }
    require_once $treeFile;
    $tree = new StaffPoolTree($pdo, $pool, $overrides, $compNames);

    $wantStaff = input(array('person', 'staff'));
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

    /* ---- the reads, once each ---- */
    $hr    = leaveHrDb();
    $leave = new LeaveRepository($pdo, $hr);

    /* hr is a different host, so each of its two reads is caught on its own:
     * a failure there must not cost the caller the local driver leaves. */
    $hrRows = null;
    $gwRows = null;
    if ($hr !== null) {
        try {
            $hrRows = $leave->staffHr($fromIso, $toIso);
        } catch (Exception $e) {
            error_log('leaveAPI hr staff read: ' . $e->getMessage());
        }
        try {
            $gwRows = $leave->gw($fromIso, $toIso);
        } catch (Exception $e) {
            error_log('leaveAPI hr gw read: ' . $e->getMessage());
        }
    }

    $driverRows = $leave->staffDriver($fromIso, $toIso);
    $nricByCode = $leave->gwNrics($compId);

    $staffLeave = leaveMergeStaff($hrRows, $driverRows);
    $gwLeave    = leaveIndexGwByCode($gwRows, $nricByCode);

    /* ---- the receipts behind the sick leave ---- */
    /* Bounded by the years the SICK LEAVE records actually reach, which can be
     * wider than the month: an SL running 28 Dec to 3 Jan needs both years.
     * No sick leave in the answer means no query at all. */
    $claims   = null;
    $claimFrom = null;
    $claimTo   = null;
    foreach ($staffLeave as $records) {
        foreach ($records as $r) {
            if ($r['leave_type'] !== 'SL') {
                continue;
            }
            if ($claimFrom === null || $r['date_from'] < $claimFrom) { $claimFrom = $r['date_from']; }
            if ($claimTo   === null || $r['date_to']   > $claimTo)   { $claimTo   = $r['date_to']; }
        }
    }
    if ($claimFrom !== null) {
        try {
            $claims = $leave->medicalClaims(substr($claimFrom, 0, 4), substr($claimTo, 0, 4));
        } catch (Exception $e) {
            error_log('leaveAPI medical claim read: ' . $e->getMessage());
            $claims = null;
        }
    }

    /* What answered and what did not, so an empty result can be read
     * correctly. See THE SOURCES at the top of this file. */
    $sources = array(
        'staff' => array(
            LEAVE_TABLE_HR     => ($hrRows === null) ? 'unavailable' : 'ok',
            LEAVE_TABLE_DRIVER => 'ok',
            /* 'not_needed' when the answer holds no sick leave to attach a
             * receipt to, which is not the same as the table being down. */
            LEAVE_TABLE_CLAIM  => ($claimFrom === null)
                ? 'not_needed' : (($claims === null) ? 'unavailable' : 'ok'),
        ),
        'gw' => array(
            LEAVE_TABLE_GW => ($gwRows === null) ? 'unavailable' : 'ok',
        ),
    );

    /* ---- compose ---- */
    $out    = array();
    $totals = array('people_on_leave' => 0, 'records' => 0, 'day_count' => 0.0);

    foreach ($rows as $row) {
        $public = publicRow($row, $overrides, $compNames);
        $where  = $tree->placement($row['person'], $row['comp_id'], $row['person_type']);

        $person  = strtolower(trim((string)$row['person']));
        $isGw    = ($row['person_type'] === 'gw');
        $records = array();
        if ($isGw) {
            if (isset($gwLeave[$person])) { $records = $gwLeave[$person]; }
        } else {
            if (isset($staffLeave[$person])) { $records = $staffLeave[$person]; }
        }

        /* A GW is handed no receipts at all, by design — medical_claim is
         * keyed on a login name and a GW code is a different namespace. */
        $mine    = (!$isGw && $claims !== null && isset($claims[$person]))
                 ? $claims[$person] : array();
        $records = leaveAttachReceipts($records, $mine);

        $month_ = leaveComposeDays($records, $fromIso, $toIso);

        $entry = array(
            /* The join key, first and in the order staffPoolAPI.php prints it. */
            'person'              => $public['person'],
            'comp_id'             => $public['comp_id'],
            'staff_type'          => $public['staff_type'],
            'fullname'            => $public['fullname'],
            'position'            => $public['position'],
            'department'          => $public['department'],
            'district'            => $public['district'],
            'hod_department_name' => $tree->hodLabel($row['person'], $row['comp_id']),
            'total_staff'         => $public['total_staff'],
        ) + $where;

        $entry['summary'] = $month_['summary'];
        $entry['leaves']  = $month_['leaves'];
        $entry['days']    = $month_['days'];

        if ($month_['summary']['records'] > 0) { $totals['people_on_leave']++; }
        $totals['records']   += $month_['summary']['records'];
        $totals['day_count'] += $month_['summary']['day_count'];

        $out[] = $entry;
    }

    $payload = array(
        'success'      => true,
        'comp_id'      => $compId,
        'company_name' => $companyName,
        'generated_at' => date('Y-m-d H:i:s'),
        'mode'         => 'month',
        'year'         => $year,
        'month'        => $month,
        'from'         => $fromIso,
        'to'           => $toIso,
        'rules'        => array(
            /* The three statuses that mean somebody is off work, with their
             * names, so a caller never has to look up what a '4' was. ONLY
             * THESE THREE appear in staff[]. */
            'statuses_counted'  => leaveCountedStatuses(),
            'statuses'          => leaveCountedStatusNames(),
            /* And what was filtered out, named — so an answer with no record
             * for a day somebody applied for says plainly that the application
             * was cancelled or rejected rather than looking like a gap. */
            'statuses_excluded' => leaveExcludedStatusNames(),
            'leave_types'       => leaveTypeNames(),
            'half_days'         => array('', 'am', 'pm'),
        ),
        /* WHICH TABLES ANSWERED. hr is a different host: when it says
         * "unavailable" an empty result means "not known", not "no leave". */
        'sources'      => $sources,
        'scope'        => $scope,
        'counts'       => array(
            'people'          => count($out),
            'days'            => (int)date('t', strtotime($fromIso)),
            /* People with at least one record, NOT people x days. */
            'people_on_leave' => $totals['people_on_leave'],
            'records'         => $totals['records'],
            /* Half days count 0.5, so this can be fractional. */
            'day_count'       => $totals['day_count'],
        ),
        'staff'        => $out,
    );

    respond($payload);
} catch (Exception $e) {
    error_log('leaveAPI: ' . $e->getMessage());
    /* Longest name first: 'leave_application' is a prefix of
     * 'leave_application_GW', so testing it first would blame the staff table
     * for a GW failure. */
    foreach (array('leave_application_GW' => 'the GW leave table',
                   'medical_claim'        => 'the medical claim receipts',
                   'leave_application'    => 'the staff leave table',
                   'driver_leaves'        => 'the driver leave table',
                   'monthly_assign_gw'    => 'the GW records',
                   'organization_chart_glob' => 'the reporting line',
                   'department_head'      => 'the reporting line') as $table => $what) {
        if (strpos($e->getMessage(), $table) !== false) {
            fail('Could not read ' . $what . ' (' . $table . '). Check the '
               . 'error log.', 500);
        }
    }
    fail('Could not build the leave report. Check the error log.', 500);
}
