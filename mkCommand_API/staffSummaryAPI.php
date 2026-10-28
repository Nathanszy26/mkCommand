<?php
header('Content-Type: application/json; charset=UTF-8');

/**
 * STAFF SUMMARY API — the one place the mail, the web page and the app all
 * ask "what is this person's summary today, and have they acknowledged it".
 *
 * WHY THIS EXISTS. There are three ways to acknowledge a day and only one
 * acknowledgement: whichever place it is done in, the other two must show it
 * done. Without a shared endpoint each client would re-derive who is on the
 * list, what they were shown and whether a day is finished — three copies of
 * one rule, free to drift the first time any of them is edited.
 *
 * IT DOES NOT BUILD THE SUMMARY. staffSummaryCron.php does, once a morning,
 * and stores the whole table in staff_summary_notify.payload. This endpoint
 * READS THAT SNAPSHOT and nothing else. Rebuilding it here would mean the app
 * showed a different table from the mail — somebody whose check-in was
 * corrected at 9am would quietly change under the ticker's hand, and what they
 * acknowledged would not be what they were sent.
 *
 *     staffSummaryCron.php   builds it, sends the mail, writes the row
 *     staffSummaryAPI.php    serves that row, and records acknowledgements
 *     staffSummaryAck.php    the web page — a client of the same writer
 *     the app                a client of this API
 *
 * SO: TO CHANGE WHAT IS IN A SUMMARY, change the cron. To change what
 * acknowledging means, change summaryAckWrite() in staffSummaryShared.php.
 * Neither is edited in three places any more.
 *
 * MUST be hosted beside the other files, in hr/ on globportal.
 *
 * ---------------------------------------------------------------------------
 * IN (GET or POST). `action` picks one; `today` is the default date.
 *
 *   action=mine        THE POPUP'S CALL. Does the person using this web page
 *                      or app owe an acknowledgement today? No token.
 *     date=              which day. Default today.
 *     person= & comid=   WHO THE QUESTION IS ABOUT — the login the app
 *                        already sends to staffDirectory.php. IT BEATS THE
 *                        SESSION: a logged-in browser asking about
 *                        person=justin is answered about Justin, not about
 *                        whoever is logged in. Leave it out and the session
 *                        answers for itself. comid= only matters where the
 *                        same login exists at more than one company.
 *     -> has_summary     false when they are not on the list at all, or it
 *                        is a Sunday or a holiday. NOT an error.
 *     -> show_popup      the one field to branch on. False the moment ANY
 *                        platform acknowledges, which is the whole point.
 *     -> ack_url         staffSummaryAck.php with this person's token, ready
 *                        to put in an iframe. Returned on EVERY route,
 *                        person= included — see apiAckUrl() for what that
 *                        costs.
 *
 *   action=summary     one person's summary, as JSON, with the ticks so far.
 *     t=TOKEN            the mail's token — no login, one person, one day
 *     OR a portal session, as that person
 *     OR person= (& comid=, date=), key= optional
 *
 *   action=acknowledge write ticks. Records WHICH PLATFORM it came from.
 *     t=TOKEN | a session | person= (& comid=, date=), key= optional
 *     platform=          email | web | mobile   (required — see below)
 *     items[]=           late, leave, comp — the sections being ticked
 *     mode=replace       the default: items[] IS the answer, and anything
 *                        left out is cleared
 *     mode=add           items[] are added to what is already ticked, and
 *                        nothing is cleared — the mail's one-box-at-a-time
 *                        route
 *
 *   action=open        note that somebody looked. Not an acknowledgement.
 *     t=TOKEN | person= & date= (+ key=), platform=
 *
 *   action=pending     who still has not acknowledged. key= required.
 *     date=              which day. Default today.
 *     include=all        every row, acknowledged or not (default: only the
 *                        ones outstanding)
 *
 * OUT: { success, ... } on anything that worked, and
 *      { success:false, error, message } on anything that did not, always
 *      with an HTTP status that says the same thing.
 *
 * ---------------------------------------------------------------------------
 * AUTHORITY. Four routes in, and they are not equal.
 *
 *   a session  UnifiedAuth, the portal's own login — the same check
 *              staffPoolAPI::sessionCaller() makes. The web page and the app
 *              have this already, which is why NEITHER NEEDS A TOKEN. Good
 *              for reading and for acknowledging, as that person.
 *
 *   t=TOKEN    the mail's 64-character token. It IS the authority — one
 *              person, one day, nothing else — which is what lets somebody
 *              acknowledge from a phone at 7am without logging in. It cannot
 *              list other people and cannot read another day. It exists for
 *              the mail and for nothing else: a logged-in caller should use
 *              their session, not this.
 *
 *   person=    a login named in the request, resolved against users — the
 *              identity the app already sends to staffDirectory.php. IT IS
 *              NOT PROOF: anybody can type any login. It READS, and since
 *              28 Oct 2026 IT WRITES — because the same read already returns
 *              ack_url, which is this person's token, which acknowledges
 *              without proving anything either. apiWriteIdentity() has the
 *              whole of that reasoning and what to undo to reverse it.
 *
 *   key=       SUMMARY_API_KEY. Any person, any day, the whole company, and
 *              it may write — so it belongs in the APP SERVER or the cron and
 *              NEVER in a mobile binary or a page's JavaScript, because
 *              anything shipped to a device can be read off it.
 *
 * ACKNOWLEDGING TAKES THE TOKEN, A SESSION, THE KEY, OR person= NAMING WHOSE
 * SUMMARY IT IS. See apiWriteIdentity(), which is where that line is drawn —
 * and what is left guarding it, which is who can reach this file at all.
 *
 * `platform` IS NOT AUTHORITY either. It is a label the caller supplies and
 * anybody holding a token could supply any of them. It is recorded because it
 * is useful, not because it is proof.
 *
 * Read-only except for the acknowledgement it is asked to write.
 *
 * PHP 7.2 compatible.
 */

/* ---------- where the pieces are ------------------------------------------ */

/* This file lives in hr/ beside the APIs; the ../hr/ form is kept for the day
 * somebody moves it to mkCommand/. */
$apiCore = __DIR__ . '/staffPoolCore.php';
if (!file_exists($apiCore)) {
    $apiCore = __DIR__ . '/../hr/staffPoolCore.php';
}
require_once $apiCore;

/* The ONE definition of what a section is, what counts as ticked, and how an
 * acknowledgement is written. staffSummaryAck.php includes the same file. The
 * cron is never included here, because including it would run it. */
require_once __DIR__ . '/staffSummaryShared.php';

/* ---------- the rules ----------------------------------------------------- */

/**
 * THE SERVER-SIDE KEY. Holds the whole company: see AUTHORITY above.
 *
 * Deliberately a different string from SUMMARY_CRON_KEY, so that handing the
 * app server a key to read summaries does not also hand it the ability to
 * fire the morning mail.
 */
define('SUMMARY_API_KEY', 'aQ4vR8nK2sXwL6pD9mBtE3yH7jZcU5gF');

/**
 * WHERE THE ACKNOWLEDGEMENT PAGE IS. The same address staffSummaryCron.php
 * puts in every mail — KEEP THE TWO THE SAME, or a link handed to the app
 * points somewhere the mail does not.
 *
 * Absolute, because what is built from it is given to another device.
 */
define('SUMMARY_API_ACK_URL',
       'https://globportal.com/accounts/mkPortal/hr/staffSummaryAck.php');

/** How many rows `action=pending` will return at once. A whole morning is
 *  about thirty recipients, so this is a guard against a runaway query and
 *  not a paging scheme. */
define('SUMMARY_API_MAX_ROWS', 500);

/* ---------- plumbing ------------------------------------------------------ */

function apiOut(array $payload, $status = 200)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                             | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Command: say no, in one shape, and stop.
 *
 * $error is a stable code for the caller to branch on; $message is for a
 * human reading a log. The two are separate so the wording can be improved
 * without breaking an app that tests the code.
 */
function apiFail($error, $message, $status = 400)
{
    apiOut(array('success' => false, 'error' => $error, 'message' => $message), $status);
}

/** Query: a GET or POST parameter, by name or by the first of several names. */
function apiIn($keys, $default = null)
{
    foreach ((array)$keys as $k) {
        if (isset($_POST[$k]) && $_POST[$k] !== '') { return $_POST[$k]; }
        if (isset($_GET[$k])  && $_GET[$k]  !== '') { return $_GET[$k]; }
    }
    return $default;
}

/** Query: a repeated parameter — items[]=late&items[]=leave — as a flat array
 *  of strings. A single items=late is accepted too, because a hand-built URL
 *  or a form library that does not bracket is not worth an error. */
function apiInList($key)
{
    $raw = isset($_POST[$key]) ? $_POST[$key] : (isset($_GET[$key]) ? $_GET[$key] : null);
    if ($raw === null || $raw === '') { return array(); }
    if (!is_array($raw)) { $raw = array($raw); }

    $out = array();
    foreach ($raw as $v) {
        if (is_scalar($v)) { $out[] = (string)$v; }
    }
    return $out;
}

/** Query: the caller's address and browser, for the log. Neither is identity;
 *  see AUTHORITY. */
function apiWho()
{
    return array(
        'ip'    => isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '',
        'agent' => isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '',
    );
}

/** Query: a date as Y-m-d, or null. Today when nothing was asked for. */
function apiDate($raw)
{
    if ($raw === null || $raw === '') { return date('Y-m-d'); }
    $d = DateTime::createFromFormat('Y-m-d', (string)$raw);
    return ($d && $d->format('Y-m-d') === (string)$raw) ? (string)$raw : null;
}

/* ---------- who is asking ------------------------------------------------- */

/**
 * Query: the logged-in caller from the portal session, or null.
 *
 * The same UnifiedAuth check staffPoolAPI::sessionCaller() makes, so "logged
 * in" means the same thing in both files. Nothing is taken from the request:
 * the person comes from the session the portal itself issued.
 */
function apiSessionCaller()
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
        if (!is_array($u) || !isset($u['person']) || $u['person'] === '') {
            return null;
        }
        return array('via'     => 'session',
                     'person'  => (string)$u['person'],
                     'comp_id' => (isset($u['comp_id']) && $u['comp_id'] !== '')
                                  ? (int)$u['comp_id'] : 1);
    } catch (Exception $e) {
        error_log('staffSummaryAPI session: ' . $e->getMessage());
        return null;
    }
}

/**
 * Query: a person named in the request, resolved against users — the identity
 * staffDirectory.php and staffRecords.php already take from the mobile app,
 * and the same one staffPoolAPI::loginCaller() takes.
 *
 * NOT PROOF OF ANYTHING, AND THAT IS WHY IT ONLY READS. Anybody can put any
 * login in a query string, so this is good enough to NARROW an answer — show
 * me what this person would see — and nowhere near good enough to write an
 * acknowledgement under their name. apiWriteIdentity() is what guards that.
 */
function apiLoginCaller(PDO $pdo, $person, $ownComp)
{
    $sql  = "SELECT person, comp_id FROM staff_portal2.users
              WHERE person = :p AND status = '1' AND deleted = '0'";
    $args = array(':p' => (string)$person);
    if ($ownComp !== null && $ownComp !== '') {
        $sql .= " AND comp_id = :c";
        $args[':c'] = (int)$ownComp;
    }
    $stmt = $pdo->prepare($sql . " LIMIT 5");
    $stmt->execute($args);
    $rows = $stmt->fetchAll();

    if (count($rows) !== 1) {
        /* Nobody, or a login that exists at more than one company — refused
         * rather than guessed. comid says which one. */
        return null;
    }
    return array('via' => 'login', 'person' => (string)$rows[0]['person'],
                 'comp_id' => (int)$rows[0]['comp_id']);
}

/**
 * Query: who the caller is, by the first route that answers. Returns null
 * when none do.
 *
 * IN ORDER OF HOW MUCH THEY PROVE, strongest first, because the answer
 * carries more for a caller who has proved more — apiAckUrl() hands a token
 * to the first two and not to the third:
 *
 *   session       the portal's own login. The web page has this.
 *   key + person  the server key naming somebody. An APP SERVER has this,
 *                 and it is how an app that does not carry the portal
 *                 session cookie still gets a link.
 *   person        a login named in the request and nothing else. Reads, and
 *                 is told nothing it could not ask for by name.
 *
 * A NAMED person= IS THE SUBJECT, AND IT BEATS THE SESSION. The session is
 * only consulted when the request names nobody, or names the session's own
 * user. Otherwise ?person=justin from a logged-in browser answered about
 * whoever was logged in — the question was about Justin and the answer was
 * about Nathan, with via:'session' as the only hint that it had happened.
 * This is the rule apiFindRow() already applies to action=summary, written
 * once more here so action=mine cannot drift from it.
 *
 * WHICH IS NOT A WAY ROUND THE SESSION, because a bare person= never could
 * write: it is read authority only, and the session still wins for the one
 * person who holds it, so a popup asking about itself keeps can_write.
 */
function apiCaller(PDO $pdo)
{
    $person = apiIn('person');
    $comp   = apiIn(array('comid', 'comp_id'));

    $c = apiSessionCaller();
    if ($c !== null
        && ($person === null || strcasecmp((string)$person, $c['person']) === 0)) {
        return $c;
    }

    if ($person === null) {
        return null;
    }

    /* The key is checked only when one was sent, so an ordinary person= call
     * is not turned into a 401 for want of a key it never needed. A WRONG
     * key is still refused outright rather than quietly demoted to a login:
     * somebody who meant to prove something and failed should be told. */
    $key = (string)apiIn('key', '');
    if ($key !== '') {
        apiRequireKey();
        $c = apiLoginCaller($pdo, $person, $comp);
        if ($c !== null) {
            $c['via'] = 'key';
            return $c;
        }
        return null;
    }

    return apiLoginCaller($pdo, $person, $comp);
}

/**
 * Command: refuse unless this caller may WRITE an acknowledgement for this
 * row. Returns the platform-worthy description of how they proved it.
 *
 * FOUR WAYS, AND A BARE person= IS NOW ONE OF THEM:
 *
 *   the token    it names this row and only this row — the mail's route,
 *                and the reason somebody can acknowledge without logging in
 *   the session  UnifiedAuth says who they are, and it is this row's person
 *   the key      the server key, held by the app's backend
 *   person=      a login named in the request, which found this row
 *
 * person= WAS REFUSED HERE UNTIL 28 OCT 2026, on the reasoning that anybody
 * can type any login and an acknowledgement is a record of somebody accepting
 * something. What changed is that the refusal had stopped protecting anything:
 * since the same date a bare person= read is answered with ack_url, which is
 * this row's token, and that token acknowledges through staffSummaryAck.php
 * with no further proof. Refusing the same caller one call later only forced
 * them through the page — so the gate cost the app its tick box and bought
 * nothing. The decision is the one in apiAckUrl(): either this endpoint is
 * reachable only by things that may act for a person, or naming a person is
 * enough. It is the second.
 *
 * SO AN ACKNOWLEDGEMENT IS ONLY AS TRUSTWORTHY AS WHO CAN REACH THIS FILE.
 * acked_platform and the event log say which route and which address it came
 * from, which is what there is to go on. To tighten it again: drop 'login'
 * from the list below AND from apiAckUrl(), and have the app server pass
 * key=. Both, or the token route puts it straight back.
 */
function apiWriteIdentity(PDO $pdo, array $row, $via)
{
    if ($via === 'token' || $via === 'key' || $via === 'login') {
        return $via;
    }

    $c = apiSessionCaller();
    if ($c !== null && strcasecmp($c['person'], (string)$row['person']) === 0) {
        return 'session';
    }

    apiFail('cannot_write',
            'Acknowledging needs the link from the email, a portal login, '
          . 'person= naming who it is for, or the server key.', 403);
}

/* ---------- finding the row ----------------------------------------------- */

/** The columns every route reads. Named once so a route cannot quietly depend
 *  on a column another one does not load. */
function apiRowColumns()
{
    return 'id, notify_date, person, comp_id, fullname, role,
            email_intended, email_sent_to, payload,
            late_count, leave_count, compliance_count, item_count,
            status, response, token,
            sent_datetime, opened_datetime, opened_platform,
            acked_datetime, acked_items, acked_platform, acked_ip';
}

/**
 * Query: the one notify row this request is about, with the authority it was
 * found by — array('row', 'via'), or a refusal.
 *
 * A TOKEN NAMES ITS OWN ROW AND CANNOT REACH ANOTHER. person+date reaches any
 * row there is, and takes no key — a summary is somebody's department laid out
 * by name, so what guards it is who can reach this file, not this function.
 * See apiWriteIdentity() for when that was decided and what to undo.
 */
function apiFindRow(PDO $pdo)
{
    $token = apiIn('t');

    if ($token !== null) {
        $token = trim((string)$token);
        /* Shape-checked before it is ever sent to the database: a token is 64
         * hex characters and nothing else is worth a query. */
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            apiFail('bad_token', 'That link is not valid. Ask for the summary to be re-sent.', 400);
        }
        $stmt = $pdo->prepare('SELECT ' . apiRowColumns() . '
                                 FROM mkPortal.staff_summary_notify WHERE token = :t');
        $stmt->execute(array(':t' => $token));
        $row = $stmt->fetch();
        if (!$row) {
            /* The same words every bad way in gets, because telling a stranger
             * WHICH part of their guess was wrong is how a token gets guessed. */
            apiFail('not_found', 'That link is not valid. Ask for the summary to be re-sent.', 404);
        }
        return array('row' => $row, 'via' => 'token');
    }

    /* NO TOKEN. Then the caller is logged in — as this person or another —
     * or they named a person, with or without the server key.
     *
     * A NAMED person= WINS OVER THE SESSION, as it does in apiCaller(): the
     * request says who the question is about, and a session cookie that
     * happens to be in the browser does not get to answer about somebody
     * else. The session is still what answers when nobody is named, so an
     * ordinary logged-in user never needs anything else.
     *
     * NO KEY IS DEMANDED OF A NAMED PERSON any more. It used to be, to keep
     * one person's department from being read by name; ack_url has handed a
     * bare person= this row's token since 28 Oct 2026, which reads and writes
     * it, so the key was a gate with a door beside it. See
     * apiWriteIdentity(). The key still names itself when it is sent, because
     * 'key' in the log means an app server and 'login' means a request. */
    $session = apiSessionCaller();
    $person  = apiIn('person');
    $via     = 'login';

    if ($session !== null
        && ($person === null || strcasecmp((string)$person, $session['person']) === 0)) {
        $person = $session['person'];
        $comp   = (int)$session['comp_id'];
        $via    = 'session';
    } else {
        if ($person === null) {
            apiFail('no_person',
                    'Pass t=TOKEN, log in, or pass person= naming whose '
                  . 'summary this is.', 400);
        }
        /* A key that was SENT is still checked, and a wrong one is refused
         * outright rather than quietly demoted to a login: somebody who meant
         * to prove something and failed should be told. */
        if ((string)apiIn('key', '') !== '') {
            apiRequireKey();
            $via = 'key';
        }
        /* Both spellings, because the app already sends comid to
         * staffDirectory.php and nobody should have to remember which file
         * wants which. */
        $comp = (int)apiIn(array('comid', 'comp_id'), 1);
    }

    $date = apiDate(apiIn('date'));
    if ($date === null) {
        apiFail('bad_date', 'date must be a calendar date in YYYY-MM-DD form.', 400);
    }

    $stmt = $pdo->prepare('SELECT ' . apiRowColumns() . '
                             FROM mkPortal.staff_summary_notify
                            WHERE person = :p AND notify_date = :d AND comp_id = :c');
    $stmt->execute(array(':p' => (string)$person, ':d' => $date, ':c' => $comp));
    $row = $stmt->fetch();
    if (!$row) {
        apiFail('not_found', 'No summary for ' . $person . ' on ' . $date
                           . '. The cron may not have run, or they are not on the list.', 404);
    }
    return array('row' => $row, 'via' => $via);
}

/** Command: refuse unless the server key was given. hash_equals, so a wrong
 *  key takes the same time to reject however much of it is right. */
function apiRequireKey()
{
    $key = (string)apiIn('key', '');
    if ($key === '' || !hash_equals(SUMMARY_API_KEY, $key)) {
        apiFail('unauthorised', 'A valid key is required for this action.', 401);
    }
}

/**
 * Query: this row's acknowledgement page, with its token. It exists so a web
 * or app popup can frame the page without going to the database for a token
 * of its own.
 *
 * EVERY ROUTE IN GETS ONE, INCLUDING A BARE person=. That was decided on
 * 28 Oct 2026, so that the app can ask for a link with the identity it
 * already sends to staffDirectory.php and nothing more.
 *
 * KNOW WHAT IT MEANS. A login name is not a secret — the portal shows them —
 * so anybody who can reach this URL and types a name is handed that person's
 * acknowledgement link, and can acknowledge in their name. The token was the
 * thing standing between a name and that power, and this takes it away. The
 * acknowledgement record is only as trustworthy as whoever could reach this
 * endpoint.
 *
 * IF THAT EVER MATTERS, the fix is not here: put this file behind the same
 * network restriction the app's other endpoints use, or drop 'login' from the
 * list below AND from apiWriteIdentity() and let the app server pass key=.
 * Both lists, since 28 Oct 2026 — a token handed out here acknowledges
 * through staffSummaryAck.php whatever this API refuses, which is exactly why
 * apiWriteIdentity() stopped refusing it.
 */
function apiAckUrl(array $row, $via)
{
    if (!in_array($via, array('token', 'session', 'key', 'login'), true)) {
        return '';
    }
    return SUMMARY_API_ACK_URL . '?t=' . urlencode((string)$row['token']);
}

/* ---------- what a summary looks like on the wire -------------------------- */

/**
 * Query: the stored snapshot, shaped for a client that has to DRAW it.
 *
 * STRUCTURED, NOT HTML. The mail is the only place HTML is built, because an
 * HTML mail is a different thing from an app screen. What every client shares
 * is the DATA and the rules, which is what this returns: the same groups the
 * mail drew, the day columns, the three sections with their true totals, and
 * the wording each tick box stands for.
 *
 * THE SENTENCES COME FROM HERE, not from the app. summaryConfirmLine() is
 * what the mail printed and what the page prints; an app writing its own
 * would be asking somebody to agree to something nobody recorded.
 */
function apiSummaryBody(array $row)
{
    $payload = json_decode((string)$row['payload'], true);
    $groups  = (is_array($payload) && isset($payload['groups']))   ? $payload['groups']   : array();
    $dayCols = (is_array($payload) && isset($payload['day_cols'])) ? $payload['day_cols'] : array();

    $want   = summaryItemKeys($groups);
    $ticked = summaryTickedSoFar($row['acked_items'], $want);
    $done   = !empty($row['acked_datetime']);

    /* The three sections, in order, each with the people it is about. The
     * totals are counted HERE from the snapshot rather than read from the
     * row's denormalised columns: those tally events (a person late twice is
     * two) and these count people, which is what a heading shows. */
    $sections = array();
    $headings = array(
        'late'  => array('no' => 1, 'title' => 'Lateness',
                         'empty' => 'Nobody was late or missing a check-in.'),
        'leave' => array('no' => 2, 'title' => 'Excessive Leaves',
                         'empty' => 'No leave taken or booked this month.'),
        'comp'  => array('no' => 3, 'title' => 'Compliance',
                         'empty' => 'No compliance recorded this month.'),
    );

    foreach (summarySections() as $kind => $flag) {
        $people = array();
        foreach ($groups as $g) {
            $keep = array();
            foreach (isset($g['rows']) ? $g['rows'] : array() as $r) {
                if (!empty($r[$flag])) { $keep[] = $r; }
            }
            if (!empty($keep)) {
                $g['rows'] = $keep;
                $people[]  = $g;
            }
        }

        $total = 0;
        foreach ($people as $g) { $total += count($g['rows']); }

        $sections[] = array(
            'key'        => $kind,
            'no'         => $headings[$kind]['no'],
            'title'      => $headings[$kind]['title'],
            'month'      => ($kind === 'late') ? '' : summaryMonthLabel($row['notify_date']),
            'total'      => $total,
            'empty_line' => $headings[$kind]['empty'],
            /* NO BOX ON AN EMPTY SECTION — there is nothing to have
             * understood, so it is not part of the total and cannot hold the
             * acknowledgement up. A client must honour this or it will ask
             * for a tick the server will not count. */
            'tick'       => isset($want[$kind]),
            'ticked'     => isset($ticked[$kind]),
            'confirm'    => summaryConfirmLine($kind),
            'groups'     => $people,
        );
    }

    return array(
        'date'      => $row['notify_date'],
        'recipient' => array(
            'person'   => $row['person'],
            'comp_id'  => (int)$row['comp_id'],
            'fullname' => $row['fullname'],
            'role'     => $row['role'],
        ),
        'title'     => 'Staff Summary ' . $row['notify_date'],
        'day_cols'  => $dayCols,
        'sections'  => $sections,
        /* WHAT THE CLIENT MUST COLLECT, and what it has so far. A client that
         * submits anything outside items[] is submitting nothing: the server
         * filters against this list. */
        'ack'       => array(
            'items'        => array_keys($want),
            'ticked'       => array_keys($ticked),
            'outstanding'  => array_keys(array_diff_key($want, $ticked)),
            'complete'     => $done,
            'acked_at'     => $done ? $row['acked_datetime'] : null,
            'acked_from'   => $done ? $row['acked_platform'] : null,
            'opened_at'    => $row['opened_datetime'],
            'opened_from'  => $row['opened_platform'],
            'confirm_line' => summaryAckLine($row['notify_date']),
            'platforms'    => summaryPlatforms(),
        ),
    );
}

/* ---------- the request --------------------------------------------------- */

try {
    $pdo = Db::get();
} catch (Exception $e) {
    error_log('staffSummaryAPI db: ' . $e->getMessage());
    apiFail('db', 'The portal is not reachable at the moment. Please try again shortly.', 503);
}

$action = strtolower(trim((string)apiIn('action', 'summary')));
$who    = apiWho();

try {
    /* ---- who has not acknowledged ---- */
    if ($action === 'pending') {
        apiRequireKey();

        $date = apiDate(apiIn('date'));
        if ($date === null) {
            apiFail('bad_date', 'date must be a calendar date in YYYY-MM-DD form.', 400);
        }
        $all = (string)apiIn('include', '') === 'all';

        $sql = 'SELECT id, notify_date, person, comp_id, fullname, role,
                       email_intended, email_sent_to, status, response,
                       late_count, leave_count, compliance_count, item_count,
                       sent_datetime, opened_datetime, opened_platform,
                       acked_datetime, acked_platform, acked_items
                  FROM mkPortal.staff_summary_notify
                 WHERE notify_date = :d'
             . ($all ? '' : ' AND acked_datetime IS NULL')
             . ' ORDER BY (acked_datetime IS NULL) DESC, person
                 LIMIT ' . (int)SUMMARY_API_MAX_ROWS;

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array(':d' => $date));

        $rows  = array();
        $counts = array('sent' => 0, 'failed' => 0, 'opened' => 0,
                        'acknowledged' => 0, 'outstanding' => 0);
        $byPlatform = array();
        foreach (summaryPlatforms() as $p) { $byPlatform[$p] = 0; }

        foreach ($stmt->fetchAll() as $r) {
            $done   = !empty($r['acked_datetime']);
            $ticked = json_decode((string)$r['acked_items'], true);
            $ticked = is_array($ticked) ? $ticked : array();

            if ($r['status'] === 'sent')   { $counts['sent']++; }
            if ($r['status'] === 'failed') { $counts['failed']++; }
            if (!empty($r['opened_datetime'])) { $counts['opened']++; }
            if ($done) {
                $counts['acknowledged']++;
                $p = summaryPlatform($r['acked_platform']);
                $byPlatform[$p]++;
            } else {
                $counts['outstanding']++;
            }

            $rows[] = array(
                'person'       => $r['person'],
                'comp_id'      => (int)$r['comp_id'],
                'fullname'     => $r['fullname'],
                'role'         => $r['role'],
                'email'        => $r['email_intended'],
                'sent_to'      => $r['email_sent_to'],
                'status'       => $r['status'],
                'response'     => $r['response'],
                'sent_at'      => $r['sent_datetime'],
                'opened_at'    => $r['opened_datetime'],
                'opened_from'  => $r['opened_platform'],
                'acknowledged' => $done,
                'acked_at'     => $done ? $r['acked_datetime'] : null,
                /* WHERE IT CAME FROM — the platform that completed it. Only
                 * ever set on an acknowledged row; a half-ticked one has no
                 * single answer, and the event log has the detail. */
                'acked_from'   => $done ? $r['acked_platform'] : null,
                'ticked'       => $ticked,
                'staff'        => array('late'       => (int)$r['late_count'],
                                        'leave'      => (int)$r['leave_count'],
                                        'compliance' => (int)$r['compliance_count'],
                                        'on_list'    => (int)$r['item_count']),
            );
        }

        apiOut(array(
            'success'      => true,
            'action'       => 'pending',
            'date'         => $date,
            'generated_at' => date('Y-m-d H:i:s'),
            'counts'       => $counts,
            /* How the day was acknowledged, which is the question this whole
             * file was added for. Only acknowledged rows are counted. */
            'acked_by_platform' => $byPlatform,
            'recipients'   => $rows,
        ));
    }

    /* ---- the popup's question: do I owe an acknowledgement today? ---- */
    /* NO TOKEN HERE, ON PURPOSE. The web page and the app already know who
     * is using them — a portal session, or the login the app sends — so
     * asking them to carry the mail's token as well would be putting a
     * weaker credential on top of a stronger one. The token exists for the
     * one case neither of them has: a mail client with nobody logged in. */
    if ($action === 'mine') {
        $caller = apiCaller($pdo);
        if ($caller === null) {
            apiFail('no_caller',
                    'Log in, or pass person= (and comid= where a login exists '
                  . 'at more than one company).', 401);
        }

        $date = apiDate(apiIn('date'));
        if ($date === null) {
            apiFail('bad_date', 'date must be a calendar date in YYYY-MM-DD form.', 400);
        }

        $stmt = $pdo->prepare('SELECT ' . apiRowColumns() . '
                                 FROM mkPortal.staff_summary_notify
                                WHERE person = :p AND notify_date = :d AND comp_id = :c');
        $stmt->execute(array(':p' => $caller['person'], ':d' => $date,
                             ':c' => (int)$caller['comp_id']));
        $row = $stmt->fetch();

        /* NO ROW IS NOT AN ERROR. Most people are not on this list at all,
         * and the ones who are have no row on a Sunday or a holiday. The
         * popup asks every morning and must be told "nothing for you"
         * without having to interpret a 404. */
        if (!$row) {
            apiOut(array(
                'success'     => true,
                'action'      => 'mine',
                'via'         => $caller['via'],
                'date'        => $date,
                'person'      => $caller['person'],
                'has_summary' => false,
                'show_popup'  => false,
                'complete'    => null,
            ));
        }

        $done = !empty($row['acked_datetime']);

        apiOut(array(
            'success'     => true,
            'action'      => 'mine',
            'via'         => $caller['via'],
            'has_summary' => true,
            /* THE ONE FIELD THE POPUP BRANCHES ON. Acknowledged anywhere —
             * the mail, the web, the app — means acknowledged everywhere, so
             * this is false the moment any of the three completes it. */
            'show_popup'  => !$done,
            /* WHETHER TO DRAW THE TICK BOXES. Every route apiCaller() can
             * return may now acknowledge — see apiWriteIdentity() — but it is
             * written as a list and not as true, so that a weaker route added
             * later does not inherit the right by default. */
            'can_write'   => in_array($caller['via'],
                                      array('session', 'login', 'key'), true),
            /* THE PAGE, READY TO FRAME. Empty unless the caller proved who
             * they are — see apiAckUrl(). A popup that would rather show
             * staffSummaryAck.php than draw the table itself puts this
             * straight in an iframe; one that draws its own ignores it. */
            'ack_url'     => apiAckUrl($row, $caller['via']),
        ) + apiSummaryBody($row));
    }

    /* ---- everything else is about ONE person's day ---- */
    $found = apiFindRow($pdo);
    $row   = $found['row'];

    if ($action === 'summary') {
        apiOut(array('success' => true, 'action' => 'summary',
                     'via'     => $found['via'],
                     'ack_url' => apiAckUrl($row, $found['via']))
               + apiSummaryBody($row));
    }

    if ($action === 'open') {
        summaryAckOpened($pdo, $row, apiIn('platform', ''), $who['ip'], $who['agent']);
        /* Re-read, so the answer carries the row as it now stands rather than
         * as it was a moment ago. */
        $after = apiFindRow($pdo);
        $row   = $after['row'];
        apiOut(array('success' => true, 'action' => 'open',
                     'via'     => $found['via'],
                     'ack_url' => apiAckUrl($row, $found['via']))
               + apiSummaryBody($row));
    }

    if ($action === 'acknowledge' || $action === 'ack') {
        /* PLATFORM IS REQUIRED HERE AND NOWHERE ELSE. An acknowledgement that
         * does not say where it came from is the one thing this endpoint was
         * built to stop; a client that cannot be bothered to say gets an
         * error rather than a quiet 'other'. */
        $platform = apiIn('platform');
        if ($platform === null) {
            apiFail('no_platform',
                    'platform is required: ' . implode(', ', summaryPlatforms()) . '.', 400);
        }
        $platform = summaryPlatform($platform);

        /* WHO MAY WRITE THIS. A token, a session that IS this person, the
         * server key, or person= naming whose row this is — and $proved is
         * which of them, which is what the log records. See
         * apiWriteIdentity(). */
        $proved = apiWriteIdentity($pdo, $row, $found['via']);

        $payload = json_decode((string)$row['payload'], true);
        $groups  = (is_array($payload) && isset($payload['groups'])) ? $payload['groups'] : array();
        $want    = summaryItemKeys($groups);

        $items = apiInList('items');
        $mode  = strtolower((string)apiIn('mode', 'replace'));
        $replace = ($mode !== 'add');

        /* Anything sent that is not a real section is dropped rather than
         * refused, exactly as the page does — but the caller is told, because
         * a client sending 'compliance' instead of 'comp' should find out
         * now and not from a day that never completes. */
        $unknown = array();
        foreach ($items as $i) {
            if (!isset($want[$i])) { $unknown[] = $i; }
        }

        $res = summaryAckWrite($pdo, $row, $want, $items, $platform,
                               $replace, $who['ip'], $who['agent']);

        /* Re-read, so the answer carries the row as it now stands — and so
         * that a day another platform completed a moment ago reports that
         * platform and not this one. */
        $after = apiFindRow($pdo);
        $row   = $after['row'];

        apiOut(array(
            'success'     => true,
            'action'      => 'acknowledge',
            'via'         => $proved,
            'platform'    => $platform,
            /* THE ANSWER THE CLIENT ACTUALLY NEEDS: is the day done, and if
             * not, what is left. */
            'complete'    => $res['complete'],
            'outstanding' => array_keys(array_diff_key($want, $res['ticked'])),
            'ticked'      => array_keys($res['ticked']),
            'items'       => array_keys($want),
            /* True when it was ALREADY acknowledged before this call — by
             * another platform, or by this one twice. Not an error: the day
             * is acknowledged either way, which is what was asked for. */
            'already'     => $res['already'],
            'acked_at'    => $row['acked_datetime'],
            'acked_from'  => $row['acked_datetime'] ? $row['acked_platform'] : null,
            'unknown_items' => $unknown,
        ));
    }

    apiFail('bad_action',
            'action must be one of: mine, summary, open, acknowledge, pending.', 400);

} catch (Exception $e) {
    error_log('staffSummaryAPI: ' . $e->getMessage());
    if (strpos($e->getMessage(), 'staff_summary_ack_event') !== false
     || strpos($e->getMessage(), 'acked_platform') !== false) {
        apiFail('migration',
                'The platform columns are not installed on this server yet. '
              . 'Run sql/staff_summary_platform.sql.', 500);
    }
    if (strpos($e->getMessage(), 'staff_summary_notify') !== false) {
        apiFail('migration',
                'The acknowledgement table is not installed on this server yet. '
              . 'Run sql/staff_summary_notify.sql.', 500);
    }
    apiFail('server', 'Something went wrong. Please try again shortly.', 500);
}
