<?php
header('Content-Type: application/json; charset=UTF-8');

/**
 * PERFORMANCE API — what merits and demerits a person collected this month.
 *
 * MEANT TO BE READ BESIDE staffPoolAPI.php, attendanceAPI.php and leaveAPI.php,
 * not instead of them. staffPoolAPI.php owns who exists; attendanceAPI.php
 * whether they turned up; leaveAPI.php whether they were excused; this one what
 * was written down about them. All four print the SAME identity:
 *
 *     person + comp_id + staff_type
 *
 * so a caller holding two of them joins on that. This one ALSO prints mk_id,
 * because mk_id is the key the performance table itself is written against —
 * see WHICH PERSON A RECORD BELONGS TO below.
 *
 * MUST be hosted on globportal.com beside staffPoolAPI.php: staff_profile,
 * staff_portal2, evaluation and mkmembers are all local there under prog4.
 * Nothing in this endpoint leaves the box.
 *
 * Read-only. Every query in this file is a SELECT.
 *
 * IN (GET or POST):
 *   comp_id      int    required. ANY company the pool covers — NOT just 1.
 *                       See WHY THERE IS NO hod_unit HERE below.
 *
 *   -- WHEN. A whole month, which is the only mode --
 *   year         int    four digits. Default: the current month.
 *   month        1-12   with year. Both or neither.
 *
 *   -- WHO --
 *   mk_id        int    that ONE person. Default: everybody in the company.
 *                       ONE person, not a branch — unlike attendanceAPI.php's
 *                       `staff`, which also covers everybody under them.
 *                       mk_id names an individual, not a place in the org.
 *
 *   -- WHAT --
 *   performance  string merit | demerit | all. Default all.
 *                       Filters the RECORDS, never the people: everybody in
 *                       scope is still listed, with an empty records[] when
 *                       they have none of what was asked for.
 *
 * OUT:
 *   { success, comp_id, company_name, generated_at, mode, year, month,
 *     from, to, rules{}, scope{}, counts{}, staff[] }
 *
 *   staff[] is one row per person in the scope, each ONCE, whether or not they
 *   have records:
 *     mk_id, person, comp_id, staff_type    the keys to join the others on
 *     fullname, position, department, district, total_staff
 *     summary{ records, merit, demerit, unknown, by_type{} }
 *     records[]  one entry per performance row, newest date first
 *     days{ "21": [ performance_id, ... ] }   day OF THE MONTH -> the ids
 *                landing on it. A LIST, because a day can hold several — which
 *                is why this is not one cell per day like attendanceAPI.php's.
 *                The bodies live in records[] and are not repeated here.
 *
 * WHICH PERSON A RECORD BELONGS TO — mk_id, and the two columns are exclusive.
 *   staff_performance carries staff_performance_staff_id AND
 *   staff_performance_gw_id, and EXACTLY ONE of them is ever set: 1,646 rows
 *   are staff, 15 are GW, none are both and none are neither. They hold
 *   different ids and must never be compared with each other:
 *
 *     staff_performance_staff_id  = staff_portal2.users.id
 *     staff_performance_gw_id     = mkmembers.member_registrations.id
 *
 *   BOTH OF THOSE ARE THE POOL'S mk_id. staffPoolCore.php selects users.id as
 *   mk_id for a staff row, and withGwEmails() fills a GW's from their myMK
 *   registration — so one parameter addresses both populations, which is why
 *   this endpoint is keyed on mk_id and not on `person`.
 *
 *   withGwEmails() IS CALLED HERE, unlike in attendanceAPI.php which skips it.
 *   That endpoint has no use for mk_id on a GW; this one cannot match a GW
 *   without it. A GW whose mobile matches no myMK registration has a null
 *   mk_id, so staff_performance_gw_code is matched as well — it holds the GW
 *   code, which is the pool's `person` for a GW.
 *
 * WHAT A RECORD SAYS — the five things asked for, and where each comes from:
 *   date           staff_performance_date, normalised to Y-m-d
 *   ref            staff_performance_ref
 *   title          staff_performance_type_title — the SPECIFIC description on
 *                  this record ("Improvement Notice of GW Attendance App
 *                  Lateness"), not the type's name
 *   type           staff_performance_types.staff_performance_type_title — the
 *                  CANONICAL type ("Improvement Notice"). '' when the record
 *                  names no type: see below.
 *   merit_demerit  'merit' | 'demerit' | ''
 *
 * MERIT OR DEMERIT — THE TYPES TABLE FIRST, THE RECORD SECOND, AND THE SECOND
 * ONE IS NOT A FALLBACK YOU CAN SKIP. 445 of the 1,243 live rows — better than
 * a third — carry an EMPTY staff_performance_type_id: free-form entries like
 * "Merit for 6s Committee (2025-2026)" or "Blood Donation Participation" that
 * were never one of the seven types. For those the types table knows nothing
 * and the record's own staff_performance_type_merit_demerit column is the only
 * thing that says which way it counts. So:
 *
 *   1. type_id resolves in staff_performance_types -> that table's answer,
 *      and merit_demerit_from says "type".
 *   2. otherwise the record's own column, lower-cased — it stores 'Merit' and
 *      'Demerit' capitalised while the types table stores them lower — and
 *      merit_demerit_from says "record".
 *   3. neither (2 live rows) -> '' and "". Counted under `unknown`, and
 *      returned by performance=all only, because a record that does not say
 *      which way it counts is neither a merit nor a demerit.
 *
 *   The two sources AGREE on every live row that has both, today. Publishing
 *   merit_demerit_from anyway is what makes that checkable next month.
 *
 * WHY THERE IS NO hod_unit / am_unit HERE, and attendanceAPI.php has them:
 *   those come from staffPoolTree.php, which is built on Globinaco's
 *   organization chart and answers for company 1 only. Performance records are
 *   NOT a company 1 story — company 7 holds 948 of them against company 1's
 *   249, with 4 and 2 behind — so restricting this endpoint to the one company
 *   the tree covers would hide most of the data. Answering for every company
 *   is worth more than copying two fields, so this endpoint does not build the
 *   tree at all. Group a report by department or district instead.
 *
 * PHP 7.2 compatible.
 */

require_once __DIR__ . '/staffPoolCore.php';

/* ---------- the rule ------------------------------------------------------ */

/** The table this endpoint is about, and the lookup that names its types. */
define('PERF_TABLE',       'staff_profile.staff_performance');
define('PERF_TABLE_TYPES', 'staff_profile.staff_performance_types');

/** The `performance` filter's accepted values. 'all' includes the records that
 *  say neither — see MERIT OR DEMERIT at the top. */
function perfFilters()
{
    return array('all', 'merit', 'demerit');
}

/** What a record can count as. '' is a real answer here, not a missing one. */
function perfVerdicts()
{
    return array('merit', 'demerit', '');
}

/* ---------- plumbing ------------------------------------------------------ */

if (!function_exists('respond')) {
    function respond(array $payload, $status = 200)
    {
        http_response_code($status);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            /* ref and title are free text typed by people, so an invalid byte
             * is a real possibility; a partial answer naming the problem beats
             * an empty body and silence. */
            error_log('performanceAPI json: ' . json_last_error_msg());
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

/**
 * Query: a stored date as Y-m-d, or null when it is not a date at all.
 *
 * staff_performance_date is a VARCHAR and almost all of it is 'Y/m/d', with 4
 * rows as 'Y-m-d' and 4 holding junk like '2' and '6'. Both separators are
 * accepted so nothing downstream has to care, and the junk is dropped rather
 * than guessed at.
 *
 * Deliberately a copy of the same helper in attendanceAPI.php and leaveAPI.php:
 * these are separate endpoints, and requiring one of those here would run its
 * request.
 */
function perfIsoDate($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $iso = str_replace('/', '-', substr($value, 0, 10));
    $d   = DateTime::createFromFormat('Y-m-d', $iso);
    return ($d && $d->format('Y-m-d') === $iso) ? $iso : null;
}

/* ---------- the reads ----------------------------------------------------- */

/**
 * Every performance read, and nothing else. The month is fetched for the WHOLE
 * company in a single query — a per-person query would be 668 of them.
 */
class PerformanceRepository
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Query: the seven types, as id => (title, merit_demerit).
     *
     * DELETED TYPES ARE INCLUDED ON PURPOSE. staff_performance_type_deleted
     * retires a type from the pick list; it does not unwrite the records
     * already filed under it, and dropping it here would blank the type name
     * on history that is perfectly valid.
     */
    public function types()
    {
        $sql = "SELECT t.staff_performance_type_id              AS id,
                       t.staff_performance_type_title           AS title,
                       t.staff_performance_type_merit_demerit   AS merit_demerit
                  FROM " . PERF_TABLE_TYPES . " t";

        $out = array();
        foreach ($this->db->query($sql)->fetchAll() as $r) {
            $id = trim((string)$r['id']);
            if ($id === '') {
                continue;
            }
            $out[$id] = array(
                'title'         => trim((string)$r['title']),
                'merit_demerit' => strtolower(trim((string)$r['merit_demerit'])),
            );
        }
        return $out;
    }

    /**
     * Query: every live record dated inside the month, for everybody, newest
     * first.
     *
     * NOT SCOPED BY COMPANY, because the table has no comp_id — it has two id
     * columns and nothing else. The caller indexes what comes back against the
     * one company's pool and ignores the rest, which is cheap: the whole table
     * is 1,661 rows.
     *
     * staff_performance_deleted = '0' is the live test. The column is int(1)
     * and 418 rows fail it.
     */
    public function month($fromIso, $toIso)
    {
        /* The date is a VARCHAR holding 'Y/m/d' and a few 'Y-m-d', so the
         * range is compared on it folded to '-' — zero-padded either way, so
         * it still orders correctly. perfIsoDate() then checks what came back
         * is a real date, so the junk rows drop out here rather than becoming
         * a record nobody can place. */
        $sql = "SELECT p.staff_performance_id                    AS id,
                       p.staff_performance_ref                   AS ref,
                       p.staff_performance_date                  AS d,
                       p.staff_performance_staff_id              AS staff_id,
                       p.staff_performance_gw_id                 AS gw_id,
                       p.staff_performance_gw_code               AS gw_code,
                       p.staff_performance_type_id               AS type_id,
                       p.staff_performance_type_title            AS title,
                       p.staff_performance_type_merit_demerit    AS row_merit_demerit,
                       p.staff_performance_type_points           AS points
                  FROM " . PERF_TABLE . " p
                 WHERE p.staff_performance_deleted = '0'
                   AND REPLACE(p.staff_performance_date,'/','-') BETWEEN :f AND :t
                 ORDER BY REPLACE(p.staff_performance_date,'/','-') DESC,
                          p.staff_performance_id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array(':f' => $fromIso, ':t' => $toIso));
        return $stmt->fetchAll();
    }
}

/* ---------- composing ----------------------------------------------------- */

/**
 * Query: one stored row as the answer prints it, with its type resolved.
 *
 * THE TYPES TABLE FIRST, THEN THE RECORD'S OWN COLUMN. See MERIT OR DEMERIT at
 * the top of this file for why the second is not optional — a third of the
 * live rows name no type at all and the record is the only thing that says
 * which way they count.
 */
function perfRecord(array $r, array $types)
{
    $typeId = trim((string)$r['type_id']);
    $known  = ($typeId !== '' && isset($types[$typeId]));

    if ($known && $types[$typeId]['merit_demerit'] !== '') {
        $verdict = $types[$typeId]['merit_demerit'];
        $from    = 'type';
    } else {
        /* Stored capitalised here ('Merit') and lower in the types table, so
         * it is folded before anybody compares the two. */
        $verdict = strtolower(trim((string)$r['row_merit_demerit']));
        $from    = ($verdict === '') ? '' : 'record';
    }
    if (!in_array($verdict, perfVerdicts(), true)) {
        /* Anything that is not one of the two words is not a verdict. Keeping
         * it would put a typo into a caller's merit column. */
        $verdict = '';
        $from    = '';
    }

    return array(
        'performance_id'     => (string)$r['id'],
        'date'               => perfIsoDate($r['d']),
        'ref'                => trim((string)$r['ref']),
        /* The specific description on this record, not the type's name. */
        'title'              => trim((string)$r['title']),
        'type_id'            => $typeId,
        /* The canonical type, '' when this record names none. */
        'type'               => $known ? $types[$typeId]['title'] : '',
        'merit_demerit'      => $verdict,
        /* Which of the two sources decided it — see MERIT OR DEMERIT. */
        'merit_demerit_from' => $from,
        'points'             => trim((string)$r['points']),
    );
}

/**
 * Query: the records for the month indexed by the pool key they belong to.
 *
 * TWO INDEXES, NEVER MIXED. A staff record is filed under its users.id and a
 * GW record under its myMK registration id, and the same number means two
 * different people across those two columns — so they go into separate maps
 * and the caller reads the one matching the person's type.
 *
 * A GW is ALSO indexed by code, because a GW whose mobile matched no myMK
 * registration has no mk_id and could not be found any other way.
 */
function perfIndex(array $rows, array $types)
{
    $byStaffId = array();
    $byGwId    = array();
    $byGwCode  = array();

    foreach ($rows as $r) {
        $record = perfRecord($r, $types);
        if ($record['date'] === null) {
            continue;                       /* junk date: see perfIsoDate() */
        }

        $staffId = (int)$r['staff_id'];
        $gwId    = (int)$r['gw_id'];
        $gwCode  = strtolower(trim((string)$r['gw_code']));

        if ($staffId > 0) {
            $byStaffId[$staffId][] = $record;
            continue;                       /* exclusive: never also a GW */
        }
        if ($gwId > 0) {
            $byGwId[$gwId][] = $record;
        }
        if ($gwCode !== '') {
            $byGwCode[$gwCode][] = $record;
        }
    }

    return array('staff' => $byStaffId, 'gw_id' => $byGwId, 'gw_code' => $byGwCode);
}

/**
 * Query: the records belonging to one pool row, from the indexes.
 *
 * mk_id FIRST, THE GW CODE ONLY AS A SECOND CHANCE, and never both — a GW
 * found by id must not have the same records added again by code.
 */
function perfForRow(array $row, array $index)
{
    $mkId = isset($row['mk_id']) ? (int)$row['mk_id'] : 0;

    if ($row['person_type'] !== 'gw') {
        return ($mkId > 0 && isset($index['staff'][$mkId]))
            ? $index['staff'][$mkId] : array();
    }

    if ($mkId > 0 && isset($index['gw_id'][$mkId])) {
        return $index['gw_id'][$mkId];
    }
    $code = strtolower(trim((string)$row['person']));
    return ($code !== '' && isset($index['gw_code'][$code]))
        ? $index['gw_code'][$code] : array();
}

/**
 * Query: one person's month — their records after the filter, the per-day map
 * and the totals.
 *
 * THE FILTER DROPS RECORDS, NOT PEOPLE. Somebody with only demerits still
 * appears under performance=merit, with an empty records[] and zeroes — so a
 * caller rendering a company sees who has nothing, which is usually the point.
 *
 * `unknown` counts the records that name neither, and performance=merit and
 * performance=demerit both exclude them: a record that does not say which way
 * it counts is not evidence either way.
 */
function perfCompose(array $records, $filter)
{
    $out     = array();
    $days    = array();
    $byType  = array();
    $tally   = array('merit' => 0, 'demerit' => 0, 'unknown' => 0);

    foreach ($records as $record) {
        $verdict = $record['merit_demerit'];

        if ($filter !== 'all' && $verdict !== $filter) {
            continue;
        }

        $out[] = $record;

        if ($verdict === 'merit' || $verdict === 'demerit') {
            $tally[$verdict]++;
        } else {
            $tally['unknown']++;
        }

        /* Grouped by the CANONICAL type where there is one, and by the
         * record's own title where there is not — which is the only name a
         * free-form entry has. */
        $label = ($record['type'] !== '') ? $record['type']
               : (($record['title'] !== '') ? $record['title'] : '(untitled)');
        if (!isset($byType[$label])) { $byType[$label] = 0; }
        $byType[$label]++;

        /* Day OF THE MONTH, and a LIST: several records can land on one day,
         * which is why this is not one cell per day. The bodies stay in
         * records[] rather than being repeated here. */
        $dayKey = (string)(int)substr($record['date'], 8, 2);
        if (!isset($days[$dayKey])) { $days[$dayKey] = array(); }
        $days[$dayKey][] = $record['performance_id'];
    }

    uksort($days, function ($a, $b) { return (int)$a - (int)$b; });

    return array(
        'records' => $out,
        'days'    => $days,
        'summary' => array(
            'records' => count($out),
            'merit'   => $tally['merit'],
            'demerit' => $tally['demerit'],
            'unknown' => $tally['unknown'],
            'by_type' => $byType,
        ),
    );
}

/* ---------- the request --------------------------------------------------- */

try {
    $pdo = Db::get();
} catch (Exception $e) {
    error_log('performanceAPI db: ' . $e->getMessage());
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

    /* ---- what ---- */
    $filter = input('performance');
    if ($filter === null || $filter === '') {
        $filter = 'all';
    }
    $filter = strtolower($filter);
    if (!in_array($filter, perfFilters(), true)) {
        fail('performance must be one of: ' . implode(', ', perfFilters()) . '.');
    }

    /* ---- which company ---- */
    $rawComp = input('comp_id');
    if ($rawComp === null || $rawComp === '') {
        fail('comp_id is required.');
    }
    $compId = (int)$rawComp;

    $repo      = new StaffPoolRepository($pdo);
    $companies = $repo->companies();
    $compNames = array();
    foreach ($companies as $c) {
        $compNames[(int)$c['comp_id']] = $c['display_name'];
    }
    if (!isset($compNames[$compId])) {
        fail('No such company: ' . $compId . '.', 404);
    }
    $companyName = $compNames[$compId];

    /* ---- who ---- */
    /* The pool, exactly as staffPoolAPI.php builds it, so every endpoint in
     * this family is answering about the same people. withGwEmails() IS called
     * here, unlike attendanceAPI.php: a GW's mk_id is what this endpoint
     * matches them on, and gw() only leaves a placeholder. */
    $pool      = array_merge($repo->staff($compId), $repo->gw($compId));
    $pool      = $repo->withGwEmails($pool);
    $overrides = $repo->overrides($compId);

    $wantMkId = input('mk_id');
    $scope    = array('mk_id' => null, 'people' => 'company');

    if ($wantMkId !== null && $wantMkId !== '') {
        if (!ctype_digit($wantMkId)) {
            fail('mk_id must be a number. It is users.id for a staff member '
               . 'and the myMK registration id for a GW — the same mk_id '
               . 'staffPoolAPI.php prints.');
        }
        $wantMkId = (int)$wantMkId;
        $rows     = array();
        foreach ($pool as $row) {
            if ((int)$row['mk_id'] === $wantMkId) {
                $rows[] = $row;
            }
        }
        if (empty($rows)) {
            fail('Nobody in this company has mk_id ' . $wantMkId . '. A GW who '
               . 'has never been matched to a myMK registration has no mk_id '
               . 'at all — staffPoolAPI.php shows which.', 404);
        }
        $scope = array('mk_id' => $wantMkId, 'people' => 'person');
    } else {
        $rows = $pool;
    }

    /* ---- the reads, once each ---- */
    $perf  = new PerformanceRepository($pdo);
    $types = $perf->types();
    $index = perfIndex($perf->month($fromIso, $toIso), $types);

    /* ---- compose ---- */
    $out    = array();
    $totals = array('people_with_records' => 0, 'records' => 0,
                    'merit' => 0, 'demerit' => 0, 'unknown' => 0);

    foreach ($rows as $row) {
        $public = publicRow($row, $overrides, $compNames);
        $month_ = perfCompose(perfForRow($row, $index), $filter);

        $entry = array(
            /* mk_id first here, and not in staffPoolAPI.php's order, because
             * mk_id is what this endpoint is addressed by and what the
             * performance table is written against. */
            'mk_id'       => $public['mk_id'],
            'person'      => $public['person'],
            'comp_id'     => $public['comp_id'],
            'staff_type'  => $public['staff_type'],
            'fullname'    => $public['fullname'],
            'position'    => $public['position'],
            'department'  => $public['department'],
            'district'    => $public['district'],
            /* 1 for somebody in the pool, 0 for somebody an override has taken
             * out of it. They are listed either way: a record written about a
             * person is a fact, not a function of whether they are counted. */
            'total_staff' => $public['total_staff'],
            'summary'     => $month_['summary'],
            'records'     => $month_['records'],
            'days'        => $month_['days'],
        );

        if ($month_['summary']['records'] > 0) { $totals['people_with_records']++; }
        $totals['records'] += $month_['summary']['records'];
        $totals['merit']   += $month_['summary']['merit'];
        $totals['demerit'] += $month_['summary']['demerit'];
        $totals['unknown'] += $month_['summary']['unknown'];

        $out[] = $entry;
    }

    $typeList = array();
    foreach ($types as $id => $t) {
        $typeList[$id] = array('type' => $t['title'], 'merit_demerit' => $t['merit_demerit']);
    }

    respond(array(
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
            'performance'   => $filter,
            'filters'       => perfFilters(),
            'verdicts'      => array('merit', 'demerit', ''),
            /* The seven types, so a caller never has to look up a type_id.
             * A record with an empty type_id is in none of them — see
             * MERIT OR DEMERIT at the top of this file. */
            'types'         => $typeList,
            'matched_on'    => 'mk_id',
        ),
        'scope'        => $scope,
        'counts'       => array(
            'people'              => count($out),
            'days'                => (int)date('t', strtotime($fromIso)),
            /* People with at least one record AFTER the filter. */
            'people_with_records' => $totals['people_with_records'],
            'records'             => $totals['records'],
            'merit'               => $totals['merit'],
            'demerit'             => $totals['demerit'],
            /* Records naming neither, which performance=all alone returns. */
            'unknown'             => $totals['unknown'],
        ),
        'staff'        => $out,
    ));
} catch (Exception $e) {
    error_log('performanceAPI: ' . $e->getMessage());
    /* Longest name first: 'staff_performance' is a prefix of
     * 'staff_performance_types', so testing it first would blame the records
     * for a lookup failure. */
    foreach (array('staff_performance_types' => 'the performance types',
                   'staff_performance'       => 'the performance records',
                   'member_registrations'    => 'the myMK registrations',
                   'monthly_assign_gw'       => 'the GW records',
                   'users'                   => 'the staff list') as $table => $what) {
        if (strpos($e->getMessage(), $table) !== false) {
            fail('Could not read ' . $what . ' (' . $table . '). Check the '
               . 'error log.', 500);
        }
    }
    fail('Could not build the performance report. Check the error log.', 500);
}
