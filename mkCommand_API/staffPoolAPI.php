<?php
header('Content-Type: application/json; charset=UTF-8');

/**
 * STAFF POOL API — the staff listing other applications read.
 *
 * Same figures as staffHeadcountReport.php, by construction and not by
 * agreement: the pool, the four flags, the overrides and the counting all come
 * out of staffPoolCore.php, which that report includes too. Change a rule
 * there and the report, this endpoint and every application reading it move
 * together. There is no rule in this file — it authenticates, it filters, it
 * prints JSON.
 *
 * MUST be hosted on globportal.com, same folder as staffHeadcountReport.php.
 * Read-only: every query behind it is a SELECT.
 *
 * IN  (GET or POST — POST for long filters, same names):
 *   comp_id     int     the company to report. '' or 'all' means every company
 *                       in the weekly-reporting group, which only a super
 *                       admin (or an API key) may ask for. Absent means the
 *                       CALLER'S OWN company, which is what the report opens
 *                       on as well.
 *   action      string  pool (default) | companies
 *                       companies = the list the report's dropdown offers,
 *                       for an application that wants to let someone choose.
 *
 *   -- who is asking: NOTHING, normally --
 *   (nothing)           the open path. ?comp_id=1 on its own answers for
 *                       company 1, which is how this endpoint is called:
 *
 *                         https://globportal.com/accounts/mkPortal/hr/
 *                                 staffPoolAPI.php?comp_id=1
 *
 *                       Every company is readable this way, and so is the
 *                       whole group with comp_id=all. See $ALLOW_ANONYMOUS
 *                       below for what that means and how to close it.
 *   person      string  ask AS somebody instead, and the answer is narrowed
 *                       to the companies that person may see — the same
 *                       identity staffDirectory.php and staffRecords.php
 *                       take. comid (alias caller_comp_id) goes with it when
 *                       the same login exists at more than one company.
 *   api_key     string  server to server, for an application that has no
 *                       staff to speak for. Off until a key is put in
 *                       API_KEYS below; a key sees every company.
 *   (session)           a logged-in globportal session is used only when the
 *                       open path is turned off — otherwise one URL would
 *                       answer differently depending on whose browser it was
 *                       opened in.
 *
 *   -- optional, and they NEVER move the summary (see below) --
 *   q           string  name or code search
 *   dept        string  one department / district, as the label it is reported
 *                       under ("No department" selects the people with none)
 *   staff_type  string  staff | gw
 *   excluded    0|1     include the excluded_staff list      (default 1)
 *   summary_only 0|1    the figures alone, no lists          (default 0)
 *
 *   -- the reporting line, and it is NOT asked for unless you ask --
 *   staff_tree  all     the company's managers and their people, as TWO
 *                       SECTIONS. comp_id=1 only for now:
 *
 *                         staffPoolAPI.php?comp_id=1&staff_tree=all
 *
 *   staff_tree_individual  one person's branch: THEMSELVES and everybody
 *               string     under them, at every level, and nothing above:
 *
 *                         staffPoolAPI.php?comp_id=1&staff_tree_individual=rinob
 *
 *                       `specific_staff` is accepted as the same parameter.
 *                       Given both, the individual wins — it is the more
 *                       specific question.
 *
 *                       EITHER ONE CHANGES THE WHOLE ANSWER. A tree request
 *                       is a different question from a pool listing, so it is
 *                       answered on its own: staff[] and excluded_staff[] are
 *                       NOT sent — the tree already holds those people — and
 *                       neither are filters{}, counts{} or caller{}. See OUT
 *                       below for exactly what comes back.
 *
 *                       Leave them off and this endpoint behaves exactly as
 *                       it did before the tree existed, down to the last
 *                       field. q, dept and staff_type do not touch a tree.
 *
 * OUT:
 *   { success, comp_id, company_name, generated_at,
 *     staff[],            everyone in the pool
 *     excluded_staff[],   everyone an override has taken OUT of the pool
 *     summary{},          company_total_*, over the WHOLE company
 *     by_company[],       per-company summaries, when comp_id is every company
 *     staff_tree{},       the reporting line, when it was asked for
 *     filters{}, counts{}, caller{} }
 *
 * THE TREE (staffPoolTree.php owns all of it — read that file for the rules)
 *
 *   staff_tree=all answers TWO FLAT LISTS of managers — no nesting anywhere
 *     { success, comp_id, company_name, generated_at, summary{},
 *       staff_tree { mode:"all", counts{}, hod[], am[], vacant[], access[] } }
 *
 *     hod[]   one entry per DEPARTMENT        — Globinaco has 16
 *     am[]    one entry per sub-department    — Globinaco has 18
 *     vacant[] departments that are live but whose head is not in the pool
 *
 *     An entry is department_id and department_name, then that manager's
 *     staff[] row, then two numbers — and NO subordinates[]. Nobody's people
 *     are listed here. To open one org, ask for it:
 *     staff_tree_individual=<that person>.
 *
 *       direct_subordinates   how many report straight to them
 *       total_subordinates    how many are under them at any depth
 *
 *     Both are read off the chart, so they are the REAL org size and not a
 *     count of anything in this answer. jeffrey's entry says 657 even though
 *     asking for him individually returns 13 — see the cap below.
 *
 *     An entry is one DEPARTMENT, not one person: mackaylachu heads both
 *     Accounts and Procurement & Inventory, so he is two entries with the
 *     same person and different units — 16 departments cannot print as 15
 *     rows. counts.managers is how many distinct people the two lists name.
 *     The chief executive is deliberately not in either list.
 *
 *   staff_tree_individual=x answers
 *     { success, comp_id, company_name, generated_at,
 *       staff_tree { mode:"individual", person, comp_id, company_name,
 *                    staff_type, counts{}, access{}, staff{} } }
 *
 *     staff{} is that one person as a node, with everybody under them nested
 *     inside it. Nothing about the rest of the company comes with it.
 *     access{} is what they may SEE, which is a different question — see
 *     ACCESS below.
 *
 *   In BOTH shapes a person is a staff[] row — the same publicRow() shape, the
 *   same values — with this added on top of it:
 *
 *     hod_department_name   NEW. Every department this person heads, comma
 *                           separated, from department_head; '' for everybody
 *                           else. On tree nodes only: staff[] is untouched.
 *     direct_subordinates   see each shape above / below
 *     total_subordinates    see each shape above / below
 *
 *   A NODE in the nested shape carries three more:
 *
 *     level                 0 at the root of this answer
 *     also_reports_to[]     their other live superiors. The chart is not a
 *                           tree — 16 of Globinaco's GW are assigned under two
 *                           people, and 82 across the group — so inside any
 *                           ONE tree each is drawn once, on their shortest
 *                           line down from that tree's own top, and the
 *                           superiors that did not get them are named here.
 *                           At the top of a tree it is everybody above them,
 *                           which is what that tree leaves out.
 *     subordinates[]        the nested nodes
 *
 *   On a NODE the two counts describe WHAT IS IN THIS ANSWER, so they add up
 *   to the arrays you are holding. On a flat hod[]/am[] entry there is no
 *   array to add up and they are the real org size instead. Nothing is capped
 *   today — StaffPoolTree::depthLimits() is empty — so the two agree.
 *
 * ACCESS — WHO MAY SEE WHICH DEPARTMENTS
 *   A separate statement from the reporting line, and it moves nobody in it.
 *
 *     staff_tree=all          access[]  one entry per person who holds a
 *                                       grant (three today)
 *     staff_tree_individual   access{}  that person's own grant, always
 *                                       present — scope "none" for the
 *                                       people who have none
 *
 *   { scope: "all"|"listed"|"none", departments[], counts{}, unknown[] }
 *   and each granted department carries its id, name, head, and the
 *   sub-departments inside it.
 *
 *   IT CHANGES NO BRANCH AND NO COUNT. staff_tree_individual=sooCK answers
 *   with his reporting line — himself and two people — and says beside it
 *   that he may see eight departments. Seeing a department is not managing
 *   it, and an earlier version of this file said so by re-parenting those
 *   heads under him, which made the org chart claim something nobody had
 *   agreed to. See StaffPoolTree::departmentAccess().
 *
 *   `unknown` is only present when a grant names a department that no longer
 *   exists — a rename that this list did not follow, surfaced rather than
 *   quietly shrinking somebody's access.
 *
 *   The tree is the WHOLE company, like the summary and for the same reason:
 *   q, dept and staff_type do not touch it. Filter out one manager and every
 *   branch under them is orphaned, which is not a tree anybody can read.
 *   summary_only=1 does empty the lists, as it empties every other list.
 *
 * Each person in both lists:
 *   mk_id, comp_id, company_name, person, fullname, email, mobile_no,
 *   staff_picture_url, position, department, district, staff_type,
 *   total_staff, kpi_scorecard_exempted, mk_command_exempted,
 *   mk_academy_exempted
 * — see publicRow() in staffPoolCore.php, which defines that shape.
 *
 *   staff_picture_url is the ABSOLUTE url of that person's profile photo in
 *   the myMK storage bucket, ready to put in an <img>, and null when there is
 *   no photo on file — which is the common case outside companies 1, 2, 3, 4,
 *   7 and 8. It is the same face staffDirectory.php shows, resolved by the
 *   same links; PoolPhotos below owns all of it. Every list carries it: the
 *   pool, the excluded, and every node of the tree.
 *
 *   mk_id is the ACCOUNT id behind the person, for an application that wants
 *   to join this listing to its own records: staff_portal2.users.id for a
 *   staff row, and for a GW -- who has no users row of their own --
 *   mkmembers.member_registrations.id matched on mobile, falling back to
 *   their GW login account, exactly as the email beside it is matched. It is
 *   null when a GW matched nothing, or matched more than one live account:
 *   the pool never guesses between two people. person + comp_id remains the
 *   identity this pool is keyed by; mk_id is a convenience, not a key.
 *
 * THE SUMMARY IS THE WHOLE COMPANY, the lists are what you filtered.
 *   This is the report's own behaviour: its tiles are a head count and do not
 *   follow the search box, or they would stop being one. So with no filters,
 *   count(staff) == summary.company_total_staff and summing a flag over
 *   staff[] gives the matching company_total_*; with a filter, counts{} tells
 *   you what the lists hold and summary{} still tells you the company.
 *
 * ACCESS
 *   Open by default — see $ALLOW_ANONYMOUS. When a person IS named, or the
 *   open path is turned off and a session is used, the scope is the one the
 *   report grants that same user, out of the same code: own company always;
 *   administered companies (kpi_company_admins) on top; active
 *   kpi_super_admins get every company and "all". A company asked for but not
 *   allowed falls back to their own, exactly as the report falls back, and the
 *   answer then carries requested_comp_id and a notice saying so.
 *
 * PHP 7.2 compatible.
 */

require_once __DIR__ . '/staffPoolCore.php';

/** Server-to-server keys. EMPTY ON PURPOSE: with no key in here the only ways
 *  in are a globportal session and a staff login, and nothing changes for the
 *  applications that use those. Add a long random string per calling
 *  application — never one key shared by all of them, or a withdrawal means
 *  reissuing to everybody — and the holder reads every company. */
$API_KEYS = array(
    // 'mymk'    => 'put-a-long-random-string-here',
    // 'academy' => 'another-one',
);

/** OPEN TO ANYONE WHO HAS THE URL. true is what makes
 *
 *      staffPoolAPI.php?comp_id=1
 *
 *  answer on its own, with no login, no person and no key — which is how this
 *  endpoint is meant to be called.
 *
 *  Know what that means before changing it back and forth: the answer carries
 *  every name, email and mobile number in the company, so with this on, and
 *  the folder reachable from the internet, that listing is public to anyone
 *  who learns the address. Nothing can be WRITTEN through here under any
 *  setting — every query behind this file is a SELECT.
 *
 *  Two ways to tighten it without changing anything else:
 *    - $ANON_ALLOW_IPS below, to let only your own servers call it, or
 *    - false, which puts it back to a globportal session, a person, or a key.
 */
$ALLOW_ANONYMOUS = true;

/** The addresses the open path above accepts, as plain IPv4/IPv6 strings.
 *  EMPTY MEANS ANYWHERE, which is the setting that matches $ALLOW_ANONYMOUS
 *  being on. Name your calling servers here and everybody else has to
 *  authenticate, which is the cheapest way to keep a machine-to-machine
 *  endpoint off the open internet. A browser on somebody's laptop is not a
 *  fixed address — this is for server-to-server. */
$ANON_ALLOW_IPS = array(
    // '203.0.113.10',
);

/* ---------- profile photos ------------------------------------------------
 *
 * The one thing in this file that is not in staffPoolCore.php on purpose: a
 * photo is not a pool rule. It moves nobody in or out of the pool and changes
 * no flag and no count, so staffHeadcountReport.php — the core's other caller
 * — has no use for one and does not pay for it. publicRow() only PUBLISHES
 * what is stamped on the row here.
 *
 * The matching rules are staffDirectory.php's, so the face this endpoint
 * names is the face the directory shows. See that file for why each link
 * exists; the one deliberate difference is noted on gwIndex() below.
 */

/** Profile photos live in the myMK app's storage bucket, not on this host.
 *  staff_profile.profile_pic / gw_profile.profile_pic hold a path relative to
 *  the bucket root; a caller only ever gets the finished absolute URL, and the
 *  base is the same one staffDirectory.php publishes. */
class ProfilePhoto
{
    const URL_BASE = 'https://app.globportal.com/mymk/storage/';

    /** Absolute URL of a stored path, or null when there is no photo on file.
     *  Each path segment is encoded separately so the slashes survive. */
    public static function url($pic)
    {
        if ($pic === null || trim($pic) === '') {
            return null;
        }
        $segments = array_map('rawurlencode', explode('/', ltrim(trim($pic), '/')));
        return self::URL_BASE . implode('/', $segments);
    }
}

/**
 * Stamps staff_picture_url on every pool row, for both populations.
 *
 * TWO READS FOR THE WHOLE ANSWER, matched in PHP. staffDirectory.php resolves
 * a staff photo with a correlated subquery per row, which is right for a
 * search that returns a screenful; this endpoint answers with a whole company
 * — about 940 rows at Globinaco, or some 1,400 for every company at once —
 * while the two photo tables together hold roughly 1,040 rows with a picture
 * on them. Reading those once and indexing them beats a subquery per person,
 * and the phone link cannot use an index on either side anyway.
 *
 * Nothing here is scoped to the companies asked for: the whole of both tables
 * is a thousand rows, and the mymkid link below is not company-scoped in the
 * directory's SQL either, so loading everything is both simpler and exactly
 * what that SQL does.
 *
 * A row that matches nothing gets null, which is a real answer and not a
 * failure — most of the group has no photo on file at all.
 */
class PoolPhotos
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Digits only. Every one of these four tables stores a number however it
     *  was typed, so this is the only comparable form. */
    private static function digits($v)
    {
        return preg_replace('/\D/', '', (string)$v);
    }

    /** A Malaysian mobile reduced to its national form: digits, then the
     *  country code, then the trunk zero — the PHP of staffDirectory.php's
     *  TRIM(LEADING '0' FROM TRIM(LEADING '60' FROM ...)), repeated prefixes
     *  and all. '0123456789', '123456789' and '60123456789' are one number. */
    private static function msisdn($v)
    {
        $d = self::digits($v);
        while (strncmp($d, '60', 2) === 0) {
            $d = substr($d, 2);
        }
        return ltrim($d, '0');
    }

    /** The company a person's PROFILE is filed under, which is not always the
     *  company the pool reports them as: Trillium's people are stored under
     *  Globinaco and relabelled as company 16, so their staff_profile rows are
     *  still comid 1. Look them up under home, or all 21 of them miss. */
    private static function homeComp($compId)
    {
        return (int)$compId === StaffPoolRepository::TRILLIUM_COMP_ID
            ? StaffPoolRepository::TRILLIUM_HOME_COMP_ID
            : (int)$compId;
    }

    /** Fills staff_picture_url on every row and hands them back. */
    public function attach(array $rows)
    {
        if (empty($rows)) {
            return $rows;
        }
        $staff = $this->staffIndexes();
        $gw    = $this->gwIndex();

        foreach ($rows as &$r) {
            $r['staff_picture_url'] = ProfilePhoto::url(
                (isset($r['person_type']) && $r['person_type'] === 'gw')
                    ? self::gwPhoto($gw, $r)
                    : self::staffPhoto($staff, $r)
            );
        }
        unset($r);
        return $rows;
    }

    /**
     * A staff member's photo path, or null. Link priority mirrors
     * staffDirectory.php's STAFF_PHOTO_SUBQUERY, which in turn mirrors
     * enroll_admin.php's checkFaceEncodings:
     *
     *   1. staff_profile.person + comid  ==  users.person + comp_id
     *   2. fallback: staff_profile.mymkid == users.id
     *   3. fallback: the phone number, reduced to its national form
     *
     * Link 3 exists for company 8 (TP Group), whose staff_profile.person holds
     * the PHONE NUMBER rather than the username and whose mymkid is a myMK
     * account id rather than users.id, so links 1 and 2 both miss.
     *
     * Verified on live data: no two profiles in one company share a username,
     * a mymkid or a reduced number, so no link here can return the wrong face.
     */
    private static function staffPhoto(array $ix, array $row)
    {
        $comp = self::homeComp($row['comp_id']);

        $key = $comp . '|' . strtolower(trim((string)$row['person']));
        if (isset($ix['person'][$key])) {
            return $ix['person'][$key];
        }
        if (isset($row['mk_id']) && $row['mk_id'] !== null
            && isset($ix['mymkid'][(int)$row['mk_id']])) {
            return $ix['mymkid'][(int)$row['mk_id']];
        }
        /* Enough digits to be a real number, the same guard the directory's
         * SQL carries: without it the hundreds of profiles holding a USERNAME
         * in `person` and the live users with no usable mobile_no would both
         * reduce to '' and match each other. */
        if (strlen(self::digits($row['mobile_no'])) >= 9) {
            $phone = $comp . '|' . self::msisdn($row['mobile_no']);
            if (isset($ix['phone'][$phone])) {
                return $ix['phone'][$phone];
            }
        }
        return null;
    }

    /** A GW's photo path, or null. gw_profile has no GW code to join on, so
     *  the phone number is the only thing a worker's assignment row and their
     *  signup have in common. */
    private static function gwPhoto(array $ix, array $row)
    {
        $key = self::msisdn($row['mobile_no']);
        if ($key === '') {
            return null;
        }
        $key .= '|' . (int)$row['comp_id'];
        return isset($ix[$key]) ? $ix[$key] : null;
    }

    /** The three staff_profile indexes, from one read. */
    private function staffIndexes()
    {
        $ix = array('person' => array(), 'mymkid' => array(), 'phone' => array());

        $sql = "SELECT p.comid, p.person, p.mymkid, p.profile_pic
                FROM staff_portal2.staff_profile p
                WHERE p.deleted_at IS NULL
                  AND p.profile_pic IS NOT NULL AND p.profile_pic <> ''";
        foreach ($this->db->query($sql)->fetchAll() as $p) {
            $comp = (int)$p['comid'];
            $pic  = $p['profile_pic'];

            /* Lower-cased because MySQL compared these case-insensitively and
             * a PHP array key does not. */
            $ix['person'][$comp . '|' . strtolower(trim((string)$p['person']))] = $pic;
            $ix['mymkid'][(int)$p['mymkid']] = $pic;

            /* Only company 8 files a number here; for everybody else `person`
             * is a username and reduces to '', which is not an index key. */
            $phone = self::msisdn($p['person']);
            if ($phone !== '') {
                $ix['phone'][$comp . '|' . $phone] = $pic;
            }
        }
        return $ix;
    }

    /**
     * GW photos, keyed by national-form number and company.
     *
     * THE ONE DELIBERATE DIFFERENCE from staffDirectory.php, which compares
     * these two numbers digit for digit: gw_profile stores a signup as
     * '60196307391' while monthly_assign_gw stores that same worker as
     * '0196307391', so an exact comparison finds 29 of Arus Sawit's 243
     * pictures. Reducing both sides the way the staff link already does finds
     * 232 of them, and it cannot cross two people: the only six numbers in
     * the whole table carrying two different pictures are six workers who
     * signed up twice, and the newer signup wins below.
     *
     * `person` beats `mobile` when a row disagrees with itself — it is the
     * column the signup writes — and a later row beats an earlier one, which
     * is what reading in id order gives.
     */
    private function gwIndex()
    {
        $index = array();

        $sql = "SELECT p.comp_id, p.person, p.mobile, p.profile_pic
                FROM staff_portal2.gw_profile p
                WHERE p.deleted_at IS NULL
                  AND p.profile_pic IS NOT NULL AND p.profile_pic <> ''
                ORDER BY p.id";
        foreach ($this->db->query($sql)->fetchAll() as $p) {
            $comp = (int)$p['comp_id'];

            $byMobile = self::msisdn($p['mobile']);
            if ($byMobile !== '' && !isset($index[$byMobile . '|' . $comp])) {
                $index[$byMobile . '|' . $comp] = $p['profile_pic'];
            }
            $byPerson = self::msisdn($p['person']);
            if ($byPerson !== '') {
                $index[$byPerson . '|' . $comp] = $p['profile_pic'];
            }
        }
        return $index;
    }
}

/* ---------- plumbing ------------------------------------------------------ */

/** No Access-Control-Allow-Origin header on purpose: this answers with names,
 *  emails and mobile numbers, so it is not opened to every page on the
 *  internet by default. A browser application on another domain needs one
 *  line here naming THAT origin — never '*' — and a server-side caller
 *  (PHP, the mobile app) needs nothing. */

function respond(array $payload, $status = 200)
{
    http_response_code($status);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        /* A byte that is not valid UTF-8 somewhere in the data would otherwise
         * answer with an empty body and no explanation. The two GW tables are
         * latin1, so this is a real possibility, and a partial answer that
         * says which person is at fault beats silence. */
        error_log('staffPoolAPI json: ' . json_last_error_msg());
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                      | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) {
            http_response_code(500);
            $json = '{"success":false,"message":"Could not encode the answer. '
                  . 'Check the error log."}';
        }
    }
    echo $json;
    exit;
}

function fail($message, $status = 400, array $extra = array())
{
    respond(array('success' => false, 'message' => $message) + $extra, $status);
}

/** Query: one input, from the query string or the body, POST first. */
function input($keys, $default = null)
{
    foreach ((array)$keys as $key) {
        if (isset($_POST[$key]) && $_POST[$key] !== '') { return trim((string)$_POST[$key]); }
        if (isset($_GET[$key])  && $_GET[$key]  !== '') { return trim((string)$_GET[$key]); }
    }
    /* Told apart from absent: ?comp_id= is "every company", and that is not
     * the same request as no comp_id at all. */
    foreach ((array)$keys as $key) {
        if (isset($_POST[$key]) || isset($_GET[$key])) { return ''; }
    }
    return $default;
}

/** Query: a 0|1 switch, with a default for when it was not asked. */
function flagInput($key, $default)
{
    $v = input($key);
    if ($v === null || $v === '') { return $default; }
    return in_array(strtolower($v), array('1', 'true', 'yes', 'y'), true);
}

/* ---------- who is asking ------------------------------------------------- */

/** Query: may THIS request use the open path?
 *
 *  An empty list is any address — that is the setting the open path ships
 *  with. A non-empty one is matched against REMOTE_ADDR and nothing else:
 *  X-Forwarded-For is a header the caller writes, so trusting it would make
 *  the list decoration rather than a check. */
function anonAllowed(array $allowIps)
{
    if (empty($allowIps)) {
        return true;
    }
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    return $ip !== '' && in_array($ip, $allowIps, true);
}

/** Query: the caller from a globportal session, or null.
 *
 *  No session is STARTED for this: a machine caller has no cookie and has no
 *  business being given one. Only a request that already carries the session
 *  cookie gets this far. */
function sessionCaller()
{
    $auth = __DIR__ . '/../includes/UnifiedAuth.php';
    if (!isset($_COOKIE[session_name()]) || !file_exists($auth)) {
        return null;
    }
    try {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        require_once $auth;
        if (!class_exists('UnifiedAuth') || !UnifiedAuth::isLoggedIn()) {
            return null;
        }
        $u = UnifiedAuth::getLoggedInUser();
        if (!is_array($u) || !isset($u['id'])) {
            return null;
        }
        return array(
            'via'     => 'session',
            'id'      => (int)$u['id'],
            'person'  => isset($u['person']) ? (string)$u['person'] : '',
            'comp_id' => (isset($u['comp_id']) && $u['comp_id'] !== '')
                ? (int)$u['comp_id'] : null,
        );
    } catch (Exception $e) {
        error_log('staffPoolAPI session: ' . $e->getMessage());
        return null;
    }
}

/** Query: the caller from a login, re-resolved against users — the identity
 *  staffDirectory.php and staffRecords.php already take from the mobile app.
 *
 *  The id is never taken from the request: only the login is, and everything
 *  that decides what may be seen is read from the row it matches. An inactive
 *  or deleted login is nobody.
 *
 *  A login that exists at more than one company is refused rather than
 *  guessed — comid says which one. */
function loginCaller(PDO $pdo, $person, $ownComp)
{
    $sql = "SELECT id, person, comp_id
              FROM staff_portal2.users
             WHERE person = :p AND status = '1' AND deleted = '0'";
    $args = array(':p' => (string)$person);
    if ($ownComp !== null && $ownComp !== '') {
        $sql .= " AND comp_id = :c";
        $args[':c'] = (int)$ownComp;
    }
    $stmt = $pdo->prepare($sql . " LIMIT 5");
    foreach ($args as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        return null;
    }
    if (count($rows) > 1) {
        fail('That login exists at more than one company. Pass comid as well.', 409);
    }
    return array(
        'via'     => 'login',
        'id'      => (int)$rows[0]['id'],
        'person'  => (string)$rows[0]['person'],
        'comp_id' => ($rows[0]['comp_id'] === null || $rows[0]['comp_id'] === '')
            ? null : (int)$rows[0]['comp_id'],
    );
}

/* ---------- the request --------------------------------------------------- */

try {
    $pdo = Db::get();
} catch (Exception $e) {
    error_log('staffPoolAPI db: ' . $e->getMessage());
    fail('Could not reach the database.', 503);
}

$apiKey = input('api_key');
$keyName = null;
if ($apiKey !== null && $apiKey !== '') {
    foreach ($API_KEYS as $name => $secret) {
        if ($secret !== '' && hash_equals((string)$secret, (string)$apiKey)) {
            $keyName = $name;
            break;
        }
    }
    if ($keyName === null) {
        fail('Unknown API key.', 403);
    }
}

if ($keyName !== null) {
    /* A key speaks for an application, not a person: no user id, and the same
     * every-company reach a super admin has. Read-only either way — nothing in
     * this file writes. */
    $caller  = array('via' => 'api_key:' . $keyName, 'id' => null,
                     'person' => '', 'comp_id' => null);
    $isSuper = true;
    $viewComps = array();
} else {
    /* A person named in the request is answered as that person, whatever the
     * open path below would allow — asking as somebody narrows the answer to
     * what they may see, and that is worth honouring. */
    $person = input('person');
    $caller = null;
    if ($person !== null && $person !== '') {
        $caller = loginCaller($pdo, $person, input(array('comid', 'caller_comp_id')));
        if ($caller === null) {
            fail('Staff not found or inactive.', 404);
        }
    }

    /* Nobody named: the open path, before the session is consulted, so that
     * one URL answers the same thing to everybody who opens it instead of
     * quietly narrowing for whoever happens to be logged in on that browser. */
    if ($caller === null && $ALLOW_ANONYMOUS && anonAllowed($ANON_ALLOW_IPS)) {
        $caller    = array('via' => 'open', 'id' => null, 'person' => '',
                           'comp_id' => null);
        $isSuper   = true;
        $viewComps = array();
    }

    if (!isset($isSuper)) {
        if ($caller === null) {
            $caller = sessionCaller();
        }
        if ($caller === null) {
            fail('Who is asking? Pass person (with comid), or an api_key, or '
               . 'call this from a logged-in globportal session.', 401);
        }

        $access    = viewScope($pdo, $caller['id'], $caller['comp_id']);
        $isSuper   = $access['is_super'];
        $viewComps = $access['view_comps'];

        if (!$isSuper && empty($viewComps)) {
            fail('Your account is not linked to a company, so there is nothing '
               . 'to report. Ask HR to set your company.', 403);
        }
    }
}

try {
    $repo      = new StaffPoolRepository($pdo);
    $companies = $repo->companies();

    /* The report's own scope: "every company" is the companies in this list,
     * so a group total is the sum of the per-company figures and never a
     * larger number nobody can reproduce by adding them up. */
    $scopeComps = array();
    $compNames  = array();
    foreach ($companies as $c) {
        $scopeComps[] = (int)$c['comp_id'];
        $compNames[(int)$c['comp_id']] = $c['display_name'];
    }

    if (input('action', 'pool') === 'companies') {
        $out = array();
        foreach ($companies as $c) {
            if ($isSuper || in_array((int)$c['comp_id'], $viewComps, true)) {
                $out[] = array(
                    'comp_id'      => (int)$c['comp_id'],
                    'company_name' => $c['display_name'],
                );
            }
        }
        respond(array(
            'success'      => true,
            'own_comp_id'  => $caller['comp_id'],
            'all_allowed'  => (bool)$isSuper,
            'companies'    => $out,
            'caller'       => array('via' => $caller['via'], 'person' => $caller['person']),
        ));
    }

    /* 'all' is spelt out for a human typing a URL; '' is what the report uses
     * and both mean the same request. */
    $rawComp = input('comp_id');
    if ($rawComp !== null && strtolower($rawComp) === 'all') {
        $rawComp = '';
    }

    $defaultCompId = $caller['comp_id'];
    if ($defaultCompId === null && !empty($viewComps)) {
        $defaultCompId = $viewComps[0];
    }
    /* An api_key has no own company, so no comp_id means every company. */
    $compId = resolveCompId($rawComp, $isSuper, $viewComps, $defaultCompId);

    /* The WHOLE company, unfiltered: the summary is counted off this, exactly
     * as the report counts its tiles before its search box is applied. */
    $rows = array_merge(
        $repo->staff($compId, $scopeComps),
        $repo->gw($compId, $scopeComps)
    );
    $rows      = $repo->withGwEmails($rows);
    /* Before the rows are split, filtered or handed to the tree, so staff[],
     * excluded_staff[] and every tree node carry the same field — see
     * PoolPhotos. summary_only=1 still pays for the two reads; they are a
     * thousand rows between them and the alternative is a second code path
     * that could answer differently. */
    $photos    = new PoolPhotos($pdo);
    $rows      = $photos->attach($rows);
    $overrides = $repo->overrides($compId);
    $summary   = publicSummary($rows, $overrides);

    /* The filters, through the report's own filterRows() so a q or a dept here
     * selects exactly what it would select on screen. onlyChanged is not
     * offered: this is a listing, not an audit of what was ticked. */
    $q         = (string)input('q', '');
    $dept      = (string)input('dept', '');
    $staffType = strtolower((string)input('staff_type', ''));
    if ($staffType !== '' && $staffType !== 'staff' && $staffType !== 'gw') {
        fail('staff_type must be staff or gw.');
    }
    $withExcluded = flagInput('excluded', true);
    $summaryOnly  = flagInput('summary_only', false);

    $shown = filterRows($rows, $q, $dept, false, $overrides);
    if ($staffType !== '') {
        /* Filtered here and not in the loop below, so counts.filtered_out
         * accounts for this filter too and the three numbers add up. */
        $byType = array();
        foreach ($shown as $row) {
            if ($row['person_type'] === $staffType) { $byType[] = $row; }
        }
        $shown = $byType;
    }

    $staffOut    = array();
    $excludedOut = array();
    if (!$summaryOnly) {
        foreach ($shown as $row) {
            $public = publicRow($row, $overrides, $compNames);
            if ($public['total_staff'] === 1) {
                $staffOut[] = $public;
            } elseif ($withExcluded) {
                $excludedOut[] = $public;
            }
        }
    }

    $payload = array(
        'success'      => true,
        'comp_id'      => $compId,                       // null = every company
        'company_name' => ($compId === null)
            ? 'All companies'
            : (isset($compNames[$compId]) ? $compNames[$compId] : 'Company ' . $compId),
        'generated_at' => date('Y-m-d H:i:s'),
        'staff'          => $staffOut,
        'excluded_staff' => $excludedOut,
        'summary'        => $summary,
        'counts'       => array(
            'staff_listed'    => count($staffOut),
            'excluded_listed' => count($excludedOut),
            /* What the filters left out, so a caller can tell a filtered
             * listing from a company that really has nobody. */
            'filtered_out'    => count($rows) - count($shown),
        ),
        'filters'      => array(
            'q'            => $q,
            'dept'         => $dept,
            'staff_type'   => $staffType,
            'excluded'     => $withExcluded ? 1 : 0,
            'summary_only' => $summaryOnly ? 1 : 0,
        ),
        'caller'       => array(
            'via'     => $caller['via'],
            'person'  => $caller['person'],
            'comp_id' => $caller['comp_id'],
            'scope'   => $isSuper ? 'all' : $viewComps,
        ),
    );

    /* The report falls back to the viewer's own company when it is asked for
     * one they may not see, and this answers the same way so the two cannot
     * disagree — but an application is not a person reading a heading, so it
     * is told plainly that it is not holding what it asked for. */
    if ($rawComp !== null && $rawComp !== '' && (int)$rawComp !== (int)$compId) {
        $payload['requested_comp_id'] = (int)$rawComp;
        $payload['notice'] = 'You may not see company ' . (int)$rawComp
            . ', so this is company ' . (int)$compId . ' instead.';
    }

    /* Every company: the same figures per company, so switching the report's
     * dropdown and adding this up agree. One pass over the rows already
     * loaded — no second trip to the database. */
    if ($compId === null) {
        $byComp = array();
        foreach ($rows as $row) {
            $byComp[(int)$row['comp_id']][] = $row;
        }
        ksort($byComp);
        $payload['by_company'] = array();
        foreach ($byComp as $cid => $set) {
            $payload['by_company'][] = array(
                'comp_id'      => (int)$cid,
                'company_name' => isset($compNames[$cid])
                    ? $compNames[$cid] : 'Company ' . $cid,
            ) + publicSummary($set, $overrides);
        }
    }

    /* ---------- the reporting line, when it was asked for ----------------
     *
     * LAST, and additive. Everything above is already built and nothing here
     * reaches back into it, so a request that does not ask for a tree never
     * runs a line of this and gets the answer it always got. staffPoolTree.php
     * is not even loaded.
     *
     * The tree is built over $rows — the WHOLE company, unfiltered, the same
     * set the summary is counted off. Not $shown: a tree with a filter applied
     * to it is a handful of disconnected people.
     */
    $treeAll   = input('staff_tree');
    $treeOne   = input(array('staff_tree_individual', 'specific_staff'));
    $wantsTree = ($treeAll !== null) || ($treeOne !== null && $treeOne !== '');

    if ($wantsTree) {
        $treeFile = __DIR__ . '/staffPoolTree.php';
        if (!file_exists($treeFile)) {
            /* Two files go up together. Said plainly, because the alternative
             * is a fatal error in the log and an empty body on the caller. */
            fail('The staff tree is not installed on this server yet. Upload '
               . 'staffPoolTree.php beside staffPoolAPI.php.', 500);
        }
        require_once $treeFile;

        /* One company for now. Answering another with two empty sections would
         * read as "this company has no managers" rather than "this is not
         * built for it yet" — see StaffPoolTree::supportedComps(). */
        if (!in_array((int)$compId, StaffPoolTree::supportedComps(), true)) {
            fail('The staff tree is only built for company '
               . implode(' and ', StaffPoolTree::supportedComps())
               . ' at the moment. Pass comp_id=1.', 400);
        }

        $tree = new StaffPoolTree($pdo, $rows, $overrides, $compNames);

        if ($treeOne !== null && $treeOne !== '') {
            /* One person. Asking for somebody the pool does not hold is a
             * mistake worth naming — an empty tree would read as "nobody
             * reports to them", which is a different answer. */
            $found = $tree->candidates($treeOne);
            if (empty($found)) {
                fail('No such person in the staff pool for this company: '
                   . $treeOne . '.', 404);
            }
            /* Ambiguous only when the same login is live at two companies in
             * an all-companies request, or when a GW code reads like a
             * username. Never guessed: comp_id says which. */
            if (count($found) > 1) {
                $who = array();
                foreach ($found as $key) {
                    $who[] = $tree->describe($key);
                }
                fail('More than one person is called ' . $treeOne
                   . '. Pass comp_id as well: ' . implode('; ', $who) . '.', 409);
            }
            $treeOut = $tree->individual($found[0]);
            /* Asked for ONE person: the answer is that person's tree and
             * nothing else. A company summary, a head count and a filter block
             * beside it are about the company, not about them. */
            $keep = array('success', 'comp_id', 'company_name', 'generated_at');
        } else {
            /* 'all' is what the URL says; '' is what an empty staff_tree= in a
             * hand-typed URL leaves, and both mean the whole company. Anything
             * else is a typo, and answering the whole company anyway would
             * hide it. */
            $mode = strtolower((string)$treeAll);
            if (!in_array($mode, array('all', '', '1', 'true', 'yes', 'y'), true)) {
                fail("staff_tree must be 'all'. For one person's branch use "
                   . 'staff_tree_individual=<person>.');
            }
            $treeOut = $tree->sections();
            /* The whole company: the head count still belongs beside the
             * sections, the flat listing does not — the sections ARE the
             * listing, and printing staff[] as well would send the same people
             * twice in one response. */
            $keep = array('success', 'comp_id', 'company_name', 'generated_at',
                          'summary');
        }

        if ($summaryOnly) {
            /* "The figures alone, no lists" covers the tree too. counts stay,
             * so the shape of the answer does not change with the switch. */
            foreach (array('hod', 'am', 'vacant', 'staff') as $list) {
                if (isset($treeOut[$list])) {
                    $treeOut[$list] = array();
                }
            }
        }

        /* Rebuilt rather than unset one key at a time, so what a tree request
         * answers is one list to read and not a subtraction to work out. */
        $lean = array();
        foreach ($keep as $field) {
            if (isset($payload[$field])) {
                $lean[$field] = $payload[$field];
            }
        }
        if (isset($payload['notice'])) {
            $lean['requested_comp_id'] = $payload['requested_comp_id'];
            $lean['notice']            = $payload['notice'];
        }
        $lean['staff_tree'] = $treeOut;
        $payload = $lean;
    }

    respond($payload);
} catch (Exception $e) {
    error_log('staffPoolAPI: ' . $e->getMessage());
    /* The tick-box tables are the one failure worth naming: nothing works
     * until the DDL has been run, and the message says which file to run. */
    if (strpos($e->getMessage(), 'staff_flag_override') !== false) {
        fail('The override tables are not installed yet. Run '
           . 'mkCommand_API/sql/staff_flag_override.sql on globportal.', 500);
    }
    /* The reporting line is read from three tables the pool itself never
     * touches, so a missing grant on one of them would otherwise be reported
     * as the pool failing — and the pool is fine. */
    foreach (array('organization_chart_glob', 'monthly_assign', 'department_head')
             as $table) {
        if (strpos($e->getMessage(), $table) !== false) {
            fail('Could not read the reporting line (' . $table . '). The '
               . 'staff pool itself is fine — drop staff_tree from the request '
               . 'to read it. Check the error log.', 500);
        }
    }
    fail('Could not build the staff pool. Check the error log.', 500);
}
