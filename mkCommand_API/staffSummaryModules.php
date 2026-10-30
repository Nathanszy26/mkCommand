<?php
/**
 * DAILY SUMMARY MODULES — which sections a person is shown, which of them they
 * must acknowledge, and where each one's figures come from.
 *
 * WHAT THIS IS FOR. The daily summary had three sections and every one of them
 * was a constant: summarySections() in staffSummaryShared.php names 'late',
 * 'leave' and 'comp', and the cron reads attendanceAPI.php, leaveAPI.php and
 * performanceAPI.php by filename because all three sit in hr/ beside it.
 *
 * RO1 WORK ACHIEVEMENT BREAKS BOTH OF THOSE ASSUMPTIONS. It is not on this
 * server — it is on glob.com.my, with its own document root and its own
 * database — so there is no filename that reaches it and its address has to be
 * stored. And it is not for everybody: a handful of Area Managers get it and
 * the other twenty-odd people on the list must not see it at all. So "which
 * sections does this person get" stops being a constant and becomes a lookup,
 * and this file is that lookup.
 *
 * TWO LAYERS, AND THE SECOND ONE IS USUALLY EMPTY:
 *
 *     effective = the person's staff_summary_module_staff row, if they have one
 *                 otherwise the catalogue's default_allowed / default_must_ack
 *
 * The three original modules default to allowed AND must-acknowledge, so a
 * person nobody has decided about gets exactly what they got before this file
 * existed. That is not a convenience — it is what makes installing this
 * invisible to the 33 people already on the list. RO1 Work Achievement
 * defaults to off, so it reaches nobody until somebody ticks it.
 *
 * IT FAILS OPEN, AND IT FAILS OPEN TO TODAY'S BEHAVIOUR. If the tables are not
 * installed, or a read throws, dsModCatalogue() answers with the three built-in
 * modules, every one allowed and must-acknowledge. A missing table therefore
 * costs the fourth section and nothing else: the mail, the page and the app all
 * carry on. The alternative — failing closed — would take the whole summary
 * away from everyone the first time a DDL was forgotten, which is a far worse
 * day than one section being absent. dsModInstalled() is how a caller finds out
 * which of the two it got.
 *
 * NOTHING RUNS WHEN THIS IS INCLUDED, for the same reason staffSummaryShared.php
 * does not: the cron ends by sending mail, and a page that included this to
 * borrow a helper must not send anything.
 *
 * WHO READS IT
 *   staffSummaryModuleReport.php   the admin grid — reads and writes
 *   staffHeadcountReport.php       only to say whether the button has a page
 *   staffSummaryCron.php           which sections to build for each recipient
 *   staffSummaryAPI.php            which sections to serve, and which to count
 *                                  towards a complete acknowledgement
 *
 * REQUIRES staffPoolCore.php to be loaded first — homeCompId() comes from it.
 *
 * See sql/staff_summary_module.sql for the two tables and the seed.
 *
 * PHP 7.2 compatible.
 */

if (defined('STAFF_SUMMARY_MODULES_LOADED')) {
    return;
}
define('STAFF_SUMMARY_MODULES_LOADED', true);

/** The tables, written out once. */
define('DS_MOD_TABLE',       'mkPortal.staff_summary_module');
define('DS_MOD_STAFF_TABLE', 'mkPortal.staff_summary_module_staff');

/**
 * THE THREE MODULES THAT EXISTED BEFORE THE TABLE DID, and what this file
 * answers with when the table is not there.
 *
 * THE KEYS ARE summarySections()'s KEYS — 'late', 'leave', 'comp' — and that
 * is not a coincidence to be tidied away. Those strings are what
 * staff_summary_notify.acked_items stores, what the mail's tick boxes post and
 * what the app sends back as items[]. A fourth key may be added freely; these
 * three cannot be renamed without orphaning every acknowledgement on record.
 *
 * ALL THREE ARE allowed AND must_ack HERE, because that is what the three
 * sections did before any of this: everybody on the list saw all three and had
 * to tick all three. The fallback has to reproduce the old behaviour exactly or
 * a missing table would quietly change who is held at the gate.
 *
 * built_in SAYS THE CRON MAKES THIS SECTION ITSELF, out of the snapshot it
 * already builds. A module that is not built in has to be fetched per person
 * from api_url. That one flag is what lets the fourth module arrive without
 * the first three changing how they are produced.
 */
function dsModBuiltIn()
{
    return array(
        'late' => array(
            'module_key' => 'late', 'title' => 'Lateness',
            'empty_line' => 'Nobody was late or missing a check-in.',
            'api_url'    => 'https://globportal.com/accounts/mkPortal/hr/attendanceAPI.php',
            'api_server' => 'globportal',
            'built_in'   => 1, 'sort_order' => 10, 'active' => 1,
            'default_allowed' => 1, 'default_must_ack' => 1,
        ),
        'leave' => array(
            'module_key' => 'leave', 'title' => 'Excessive Leaves',
            'empty_line' => 'No leave taken or booked this month.',
            'api_url'    => 'https://globportal.com/accounts/mkPortal/hr/leaveAPI.php',
            'api_server' => 'globportal',
            'built_in'   => 1, 'sort_order' => 20, 'active' => 1,
            'default_allowed' => 1, 'default_must_ack' => 1,
        ),
        'comp' => array(
            'module_key' => 'comp', 'title' => 'Compliance',
            'empty_line' => 'No compliance recorded this month.',
            'api_url'    => 'https://globportal.com/accounts/mkPortal/hr/performanceAPI.php',
            'api_server' => 'globportal',
            'built_in'   => 1, 'sort_order' => 30, 'active' => 1,
            'default_allowed' => 1, 'default_must_ack' => 1,
        ),
    );
}

/**
 * Query / command: did the catalogue come out of the database?
 *
 * false means dsModCatalogue() handed back dsModBuiltIn() instead — the tables
 * are not installed, or a read failed. Callers use it to say so on screen
 * rather than to decide anything: the figures are correct either way, there are
 * just only ever three of them.
 *
 * One static for the request, set by the first read and asked by everything
 * after it, so a missing table is diagnosed once and not once per person.
 */
function dsModInstalled($set = null)
{
    static $ok = true;
    if ($set !== null) { $ok = (bool)$set; }
    return $ok;
}

/**
 * WHICH COMPANIES HAVE A DAILY SUMMARY LIST — a comma list of comp ids, '1'
 * today.
 *
 * MOVED HERE FROM staffHeadcountReport.php, which still names it as
 * HC_DAILY_SUMMARY_COMPS but now reads it from this one definition. Two pages
 * ask the question — the report draws the tick column and the module grid
 * draws a row per ticked person — and a second company added to one list and
 * not the other would give a company a tick box whose modules could not be
 * edited.
 *
 * An empty string turns the list off everywhere without removing any code, and
 * without touching the data: the ticks stay on the users rows and the module
 * rows stay in their table, and both come back the moment an id goes back in.
 *
 * It is matched against homeCompId(), so Trillium (16) is covered by 1.
 */
define('DS_MOD_COMPS', '1');

/** Query: DS_MOD_COMPS as a list of ints. */
function dsModComps()
{
    $out = array();
    foreach (explode(',', DS_MOD_COMPS) as $c) {
        $c = trim($c);
        if ($c !== '' && ctype_digit($c)) { $out[] = (int)$c; }
    }
    return $out;
}

/**
 * Query: can THIS pool row be on the daily summary list — a staff row at a
 * listed company?
 *
 * STAFF ONLY, which falls out of where the tick is stored: a GW has no users
 * row to carry daily_summary_report and no Staff Utility to be stopped at.
 */
function dsModOnRow(array $row)
{
    return $row['person_type'] === 'staff'
        && in_array(homeCompId($row['comp_id']), dsModComps(), true);
}

/**
 * Query: everybody ticked for the daily summary, as a set keyed by dsModKey().
 *
 * ONE QUERY FOR A PAGE, not one per row. $compId narrows the read; null or ''
 * reads every company, which is what "All companies" needs — the column then
 * holds rows from all of them and each row decides for itself.
 *
 * THE COLUMN MAY NOT EXIST YET. sql/daily_summary_report.sql adds it, and
 * until it has been run this returns an empty set and marks the feature
 * uninstalled rather than taking the calling page down with it. Both callers
 * then show no list and say why.
 */
function dsModTicked(PDO $db, $compId = null)
{
    $sql  = "SELECT u.person, u.comp_id
               FROM staff_portal2.users u
              WHERE IFNULL(u.daily_summary_report, 0) = 1
                AND u.status = '1' AND u.deleted = '0'";
    $args = array();
    if ($compId !== null && $compId !== '') {
        $sql .= " AND u.comp_id = :c";
        $args[':c'] = homeCompId((int)$compId);
    }

    $out = array();
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($args);
        foreach ($stmt->fetchAll() as $r) {
            $out[dsModKey($r['comp_id'], $r['person'])] = 1;
        }
    } catch (PDOException $e) {
        error_log('staffSummaryModules ticked: ' . $e->getMessage());
        dsModListInstalled(false);
        return array();
    }
    return $out;
}

/**
 * Query / command: is users.daily_summary_report there to be read?
 *
 * A SEPARATE FLAG FROM dsModInstalled(), because they are separate DDL files
 * with separate advice: this one is sql/daily_summary_report.sql (the tick
 * column) and that one is sql/staff_summary_module.sql (the two module
 * tables). Either can be missing without the other, and a page that said "run
 * the SQL" without saying which would send somebody to the wrong file.
 */
function dsModListInstalled($set = null)
{
    static $ok = true;
    if ($set !== null) { $ok = (bool)$set; }
    return $ok;
}

/**
 * Query: every active module, keyed by module_key, in the order they are shown.
 *
 * READ ONCE PER REQUEST. The cron asks for this inside a loop over 33 people
 * and the report asks for it while drawing a grid; four rows are not worth
 * four hundred queries.
 *
 * A ROW THAT IS NOT ACTIVE IS NOT HERE AT ALL, which is what makes active = 0
 * a complete retirement: a section nothing can name cannot be rendered,
 * cannot be ticked and cannot hold an acknowledgement up. The staff rows for
 * it stay where they are, so putting it back restores who had it.
 */
function dsModCatalogue(PDO $db = null, $reset = false)
{
    static $cache = null;
    if ($reset) { $cache = null; }
    if ($cache !== null) { return $cache; }
    if ($db === null) { return dsModBuiltIn(); }

    try {
        $stmt = $db->query(
            "SELECT module_key, title, empty_line, api_url, api_server,
                    built_in, sort_order, active,
                    default_allowed, default_must_ack
               FROM " . DS_MOD_TABLE . "
              WHERE active = 1
              ORDER BY sort_order ASC, module_key ASC");
        $out = array();
        foreach ($stmt->fetchAll() as $r) {
            $key = trim((string)$r['module_key']);
            if ($key === '') { continue; }
            $out[$key] = array(
                'module_key'       => $key,
                'title'            => (string)$r['title'],
                'empty_line'       => (string)$r['empty_line'],
                'api_url'          => (string)$r['api_url'],
                'api_server'       => (string)$r['api_server'],
                'built_in'         => (int)$r['built_in'],
                'sort_order'       => (int)$r['sort_order'],
                'active'           => (int)$r['active'],
                'default_allowed'  => (int)$r['default_allowed'] ? 1 : 0,
                'default_must_ack' => (int)$r['default_must_ack'] ? 1 : 0,
            );
        }
        /* AN EMPTY TABLE IS NOT AN ANSWER. The seed is part of the DDL, so a
         * table with no rows in it means the INSERT was skipped or everything
         * was retired at once — and serving nobody any sections at all is a
         * worse reading of that than serving the three that used to be
         * constants. Treated the same as a missing table. */
        if (empty($out)) {
            dsModInstalled(false);
            return $cache = dsModBuiltIn();
        }
        dsModInstalled(true);
        return $cache = $out;
    } catch (PDOException $e) {
        /* Almost certainly the table not being there yet. Noted, and the three
         * built-ins stand in — see the header for why open and not closed. */
        error_log('staffSummaryModules catalogue: ' . $e->getMessage());
        dsModInstalled(false);
        return $cache = dsModBuiltIn();
    }
}

/**
 * Query: the key one person is held under.
 *
 * LOWER-CASED, because a login is typed by people and users.person is not
 * consistently cased — 'Fionaliew' sits beside 'victorchai' in the same table.
 * THE HOME COMPANY, because Trillium people are reported as company 16 and
 * stored under Globinaco, so filing them under 1 is what makes a Trillium row
 * and its users row the same person. Both rules are hcDsKey()'s in
 * staffHeadcountReport.php, deliberately, so the tick column and this grid
 * agree about who they are talking about.
 */
function dsModKey($compId, $person)
{
    return homeCompId($compId) . "\x1f" . strtolower(trim((string)$person));
}

/**
 * Query: every stored exception, as key => (module_key => row).
 *
 * ONE QUERY FOR A WHOLE PAGE, not one per person per module. $compId narrows it
 * to one company; null or '' reads every company, which is what "All
 * companies" on the report needs.
 *
 * A PERSON WITH NO ROW IS SIMPLY ABSENT, and dsModFor() then answers from the
 * catalogue's defaults. There is no "unset" value to represent, which is why
 * this is a sparse map and not a grid.
 */
function dsModStaffRows(PDO $db, $compId = null)
{
    /* IFNULL BECAUSE A ROW'S FIRST SAVE LEAVES updated_datetime NULL — the
     * upsert sets created_datetime on the insert and updated_datetime only on
     * the update. Without this, a cell ticked a minute ago says "Set by nathan
     * on —", which reads as a corrupt record rather than a new one. */
    $sql  = "SELECT person, comp_id, module_key, allowed, must_ack,
                    updated_by,
                    IFNULL(updated_datetime, created_datetime) AS updated_datetime
               FROM " . DS_MOD_STAFF_TABLE;
    $args = array();
    if ($compId !== null && $compId !== '') {
        $sql .= " WHERE comp_id = :c";
        $args[':c'] = homeCompId((int)$compId);
    }

    $out = array();
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($args);
        foreach ($stmt->fetchAll() as $r) {
            $k = dsModKey($r['comp_id'], $r['person']);
            $out[$k][trim((string)$r['module_key'])] = array(
                'allowed'          => (int)$r['allowed'] ? 1 : 0,
                'must_ack'         => (int)$r['must_ack'] ? 1 : 0,
                'updated_by'       => (string)$r['updated_by'],
                'updated_datetime' => $r['updated_datetime'],
            );
        }
    } catch (PDOException $e) {
        error_log('staffSummaryModules staff rows: ' . $e->getMessage());
        dsModInstalled(false);
        return array();
    }
    return $out;
}

/**
 * Query: what ONE person gets for ONE module — the two answers and where they
 * came from.
 *
 * @return array allowed (0|1), must_ack (0|1), set (bool: a stored row decided
 *               it, rather than the catalogue default), updated_by, updated_datetime
 *
 * must_ack IS FORCED TO 0 WHEN allowed IS 0, here and not in the caller. "You
 * must acknowledge a section you are never shown" is not a state any surface
 * can honour — the app would hold somebody at a gate over a tick box that is
 * not on their screen — so it is collapsed at the one point every reader goes
 * through. A stored row may still hold must_ack = 1 beside allowed = 0 (the
 * page lets a value be kept while a module is switched off and back on); this
 * is what makes that harmless.
 */
function dsModFor($moduleKey, $personKey, array $staffRows, array $catalogue)
{
    $mod = isset($catalogue[$moduleKey]) ? $catalogue[$moduleKey] : null;
    if ($mod === null) {
        /* Not in the catalogue means retired or never defined. Nobody gets it. */
        return array('allowed' => 0, 'must_ack' => 0, 'set' => false,
                     'updated_by' => '', 'updated_datetime' => null);
    }

    $row = isset($staffRows[$personKey][$moduleKey])
        ? $staffRows[$personKey][$moduleKey] : null;

    $allowed = ($row === null) ? $mod['default_allowed']  : $row['allowed'];
    $mustAck = ($row === null) ? $mod['default_must_ack'] : $row['must_ack'];

    return array(
        'allowed'          => $allowed ? 1 : 0,
        'must_ack'         => ($allowed && $mustAck) ? 1 : 0,
        'set'              => ($row !== null),
        'updated_by'       => $row === null ? '' : $row['updated_by'],
        'updated_datetime' => $row === null ? null : $row['updated_datetime'],
    );
}

/**
 * Query: the module keys one person is shown, in display order.
 *
 * WHAT THE CRON AND THE API ASK. The catalogue is already ordered, so this
 * preserves that order and the sections come out numbered the same way for
 * everybody — a person without RO1 Work Achievement sees 1, 2, 3 and a person
 * with it sees 1, 2, 3, 4, rather than the fourth sliding into a gap.
 */
function dsModAllowedKeys($personKey, array $staffRows, array $catalogue)
{
    $out = array();
    foreach ($catalogue as $key => $mod) {
        if (dsModFor($key, $personKey, $staffRows, $catalogue)['allowed']) {
            $out[] = $key;
        }
    }
    return $out;
}

/**
 * Query: the module keys one person MUST tick before the day is finished.
 *
 * A SUBSET OF dsModAllowedKeys(), always — dsModFor() guarantees it. This is
 * the list staffSummaryAPI.php should intersect with summaryItemKeys() to
 * decide whether an acknowledgement is complete: a section with nobody in it
 * still has no box (there is nothing to have read), and a section somebody is
 * shown for information only still has no box. Both tests have to pass.
 */
function dsModMustAckKeys($personKey, array $staffRows, array $catalogue)
{
    $out = array();
    foreach ($catalogue as $key => $mod) {
        if (dsModFor($key, $personKey, $staffRows, $catalogue)['must_ack']) {
            $out[] = $key;
        }
    }
    return $out;
}

/**
 * Command: store one person's answer for one module.
 *
 * AN UPSERT ON (person, comp_id, module_key), so saving the same grid twice
 * cannot leave two answers for one cell. There is no delete: a row saying
 * "allowed = 0" and no row at all are different things to a reader — the first
 * is a decision somebody made and signed, the second is the catalogue default
 * — and only the first survives a change to that default.
 *
 * THE PERSON IS LOWER-CASED ON THE WAY IN, matching dsModKey(). The column's
 * collation is case-insensitive so it would have found the row either way;
 * storing one casing is what keeps a SELECT on the table readable.
 *
 * Returns false and logs rather than throwing: the report saves a batch and one
 * refused cell must not take the other thirty with it.
 */
function dsModWrite(PDO $db, $compId, $person, $moduleKey, $allowed, $mustAck,
                    $byName = '', $byId = 0)
{
    try {
        $stmt = $db->prepare(
            "INSERT INTO " . DS_MOD_STAFF_TABLE . "
                 (person, comp_id, module_key, allowed, must_ack,
                  updated_by, updated_by_id, created_datetime)
             VALUES (:p, :c, :m, :a, :k, :by, :byid, NOW())
             ON DUPLICATE KEY UPDATE
                  allowed          = VALUES(allowed),
                  must_ack         = VALUES(must_ack),
                  updated_by       = VALUES(updated_by),
                  updated_by_id    = VALUES(updated_by_id),
                  updated_datetime = NOW()");
        $stmt->execute(array(
            ':p'    => strtolower(trim((string)$person)),
            ':c'    => homeCompId($compId),
            ':m'    => (string)$moduleKey,
            /* must_ack is stored AS GIVEN, not collapsed. dsModFor() collapses
             * it on the way out, so switching a module back on restores the
             * must-acknowledge setting it had rather than silently clearing
             * it. */
            ':a'    => $allowed ? 1 : 0,
            ':k'    => $mustAck ? 1 : 0,
            ':by'   => substr((string)$byName, 0, 100),
            ':byid' => (int)$byId,
        ));
        return true;
    } catch (PDOException $e) {
        error_log('staffSummaryModules write: ' . $e->getMessage());
        dsModInstalled(false);
        return false;
    }
}

/* ---------- what a module PUTS ON a summary ------------------------------ */

/**
 * The payload key the fetched modules are stored under.
 *
 * ONE KEY, NAMED ONCE. The cron writes it into staff_summary_notify.payload
 * and the acknowledgement page, the API and the app all read it back out. A
 * payload written before this existed simply has no such key, and every reader
 * below treats that as "no fetched modules" — which is exactly right for a day
 * that ran before the fourth module did.
 */
define('DS_MOD_PAYLOAD_KEY', 'modules');

/** How long one module fetch may take. Generous, because the RO1 endpoint
 *  scans pothole_daily on a date expression no index covers and takes a
 *  couple of seconds — and a timeout costs that person their section. */
define('DS_MOD_FETCH_TIMEOUT', 25);

/**
 * COMMAND: call ONE module's endpoint for ONE person, and bring back what it
 * said. Never throws, never warns, always returns the same shape.
 *
 * ONLY THE CRON CALLS THIS, and it calls it once per person per morning. The
 * acknowledgement page, the API and the app read the STORED result out of the
 * payload and never refetch — the same rule the three built-in sections live
 * under, and for the same reason: what somebody acknowledged has to be what
 * they were sent. A live read here would let a manager's figures change under
 * their hand between opening the mail and pressing the button. (A live leave
 * read was built on 29 Oct 2026 and taken back out the same day; this is that
 * decision, applied up front.)
 *
 * A DEAD ENDPOINT COSTS ONE SECTION, NOT THE MORNING. The failure is recorded
 * in the returned shape and stored with everything else, so the section can say
 * plainly that its figures could not be read instead of printing nothing and
 * looking like a quiet month. summaryApi() in the cron takes the same line
 * about the three local APIs.
 *
 * @param array $mod     a catalogue row — api_url and title are what is used
 * @param array $who     person, comp_id, email — email is the identity the
 *                       endpoint matches on; the other two are for its log
 * @param string $dateIso the day being summarised, NOT today: a re-run of
 *                        yesterday must ask for yesterday
 * @return array ok, title, api_url, fetched_at, error, data
 */
function dsModFetch(array $mod, array $who, $dateIso, $timeout = DS_MOD_FETCH_TIMEOUT)
{
    $out = array(
        'ok'         => false,
        'title'      => isset($mod['title']) ? $mod['title'] : '',
        'api_url'    => isset($mod['api_url']) ? $mod['api_url'] : '',
        'fetched_at' => date('Y-m-d H:i:s'),
        'error'      => '',
        'data'       => null,
    );

    if ($out['api_url'] === '') {
        $out['error'] = 'no endpoint is set for this module';
        return $out;
    }
    if (!function_exists('curl_init')) {
        $out['error'] = 'curl is not available on this server';
        return $out;
    }

    $url = $out['api_url'] . (strpos($out['api_url'], '?') === false ? '?' : '&')
         . http_build_query(array(
             'email'   => isset($who['email'])   ? $who['email']   : '',
             'person'  => isset($who['person'])  ? $who['person']  : '',
             'comp_id' => isset($who['comp_id']) ? $who['comp_id'] : '',
             'date'    => $dateIso,
         ));

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => (int)$timeout,
        CURLOPT_FOLLOWLOCATION => true,
        /* The endpoint may be on another host with its own certificate chain
         * — the whole reason this module is fetched rather than built. The
         * answer carries no secret and is read for display only. */
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'staffSummaryModules',
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code !== 200) {
        $out['error'] = 'HTTP ' . $code . ($err !== '' ? ' ' . $err : '');
        error_log('dsModFetch ' . $out['api_url'] . ': ' . $out['error']);
        return $out;
    }

    $json = json_decode((string)$body, true);
    if (!is_array($json)) {
        $out['error'] = 'the answer was not JSON';
        error_log('dsModFetch ' . $out['api_url'] . ': not JSON');
        return $out;
    }
    /* BOTH ENVELOPES ACCEPTED. glob.com.my answers {ok:...} and globportal's
     * own APIs answer {success:...}; a module may one day point at either, and
     * refusing one of them would be a rule nobody could guess from the table. */
    $good = !empty($json['ok']) || !empty($json['success']);
    if (!$good) {
        $out['error'] = isset($json['error']) ? (string)$json['error']
                      : (isset($json['message']) ? (string)$json['message']
                                                 : 'the endpoint refused');
        error_log('dsModFetch ' . $out['api_url'] . ': ' . $out['error']);
        return $out;
    }

    $out['ok']   = true;
    $out['data'] = $json;
    return $out;
}

/**
 * Query: the fetched modules out of a stored payload, keyed by module.
 *
 * ALWAYS AN ARRAY, never null. A payload from before this feature has no such
 * key, and so does a person who has no fetched module — and neither is a
 * condition a caller should have to test for separately.
 */
function dsModPayloadModules($payload)
{
    if (!is_array($payload)) { return array(); }
    $m = isset($payload[DS_MOD_PAYLOAD_KEY]) ? $payload[DS_MOD_PAYLOAD_KEY] : array();
    return is_array($m) ? $m : array();
}

/**
 * Query: is there anything in this module worth a tick box?
 *
 * TWO KINDS OF MODULE, TWO TESTS, ONE QUESTION. A built-in section has content
 * when at least one person in the snapshot carries its flag — the same walk
 * summaryItemKeys() has always done. A fetched section has content when its
 * endpoint answered AND returned rows: a manager with no districts has nothing
 * to have read, exactly as a department with nobody late has nothing to have
 * read.
 *
 * A FETCH THAT FAILED HAS NO CONTENT EITHER, and that is deliberate. The
 * section still prints, saying its figures could not be read, but it must not
 * hold somebody's morning up over an endpoint that was down — they cannot
 * acknowledge figures nobody could show them.
 *
 * The built-in branch reads summarySections() out of staffSummaryShared.php,
 * which both callers load. Without it a built-in module has no flag to look
 * for and reports no content, which fails safe: no box rather than a box
 * nobody can satisfy.
 */
function dsModHasContent($moduleKey, array $catalogue, array $groups, array $modules)
{
    $mod = isset($catalogue[$moduleKey]) ? $catalogue[$moduleKey] : null;
    if ($mod === null) { return false; }

    if (empty($mod['built_in'])) {
        $m = isset($modules[$moduleKey]) ? $modules[$moduleKey] : null;
        if (!is_array($m) || empty($m['ok']) || !is_array($m['data'])) { return false; }
        $rows = isset($m['data']['rows']) ? $m['data']['rows'] : array();
        return is_array($rows) && !empty($rows);
    }

    $sections = function_exists('summarySections') ? summarySections() : array();
    if (!isset($sections[$moduleKey])) { return false; }
    $flag = $sections[$moduleKey];

    foreach ($groups as $g) {
        foreach (isset($g['rows']) ? $g['rows'] : array() as $r) {
            if (!empty($r[$flag])) { return true; }
        }
    }
    return false;
}

/**
 * Query: the catalogue facts a RENDERER needs, for the keys given, in order.
 *
 * WRITTEN INTO THE PAYLOAD BY THE CRON so every surface draws from the
 * snapshot and not from the live table. A title corrected at noon, a module
 * retired at three, a person's tick changed at five — none of them may rewrite
 * a summary already in somebody's inbox, or move the finishing line on a day
 * half acknowledged. The same reason the groups are stored rather than rebuilt.
 */
function dsModMeta(array $keys, array $catalogue)
{
    $out = array();
    foreach ($keys as $key) {
        if (!isset($catalogue[$key])) { continue; }
        $out[$key] = array(
            'title'      => $catalogue[$key]['title'],
            'empty_line' => $catalogue[$key]['empty_line'],
            'built_in'   => (int)$catalogue[$key]['built_in'],
        );
    }
    return $out;
}

/**
 * Query: the sections to draw, out of a STORED payload, in order.
 * @return array list of ['key','title','empty_line','built_in']
 *
 * THE ONE READER EVERY SURFACE USES — the acknowledgement page, the API and
 * through it the app. None of them touches the module tables: what the morning
 * decided is in the payload, and that is what they draw.
 *
 * A PAYLOAD FROM BEFORE ANY OF THIS falls back to the original three, with the
 * headings they were hard-coded with. Every day already in
 * staff_summary_notify is such a payload, so this is not a corner case — it is
 * every row written before today, and they must all still open.
 */
function dsModPayloadSections($payload)
{
    $fallback = array(
        'late'  => array('title' => 'Lateness',
                         'empty_line' => 'Nobody was late or missing a check-in.'),
        'leave' => array('title' => 'Excessive Leaves',
                         'empty_line' => 'No leave taken or booked this month.'),
        'comp'  => array('title' => 'Compliance',
                         'empty_line' => 'No compliance recorded this month.'),
    );

    $keys = (is_array($payload) && isset($payload['module_keys'])
             && is_array($payload['module_keys']) && !empty($payload['module_keys']))
        ? $payload['module_keys']
        : array_keys($fallback);

    $meta = (is_array($payload) && isset($payload['module_meta'])
             && is_array($payload['module_meta']))
        ? $payload['module_meta'] : array();

    $out = array();
    foreach ($keys as $key) {
        $m = isset($meta[$key]) ? $meta[$key] : array();
        $f = isset($fallback[$key]) ? $fallback[$key] : array();
        $out[] = array(
            'key'        => $key,
            'title'      => (isset($m['title']) && $m['title'] !== '')
                ? $m['title'] : (isset($f['title']) ? $f['title'] : $key),
            'empty_line' => (isset($m['empty_line']) && $m['empty_line'] !== '')
                ? $m['empty_line'] : (isset($f['empty_line']) ? $f['empty_line'] : 'Nothing to show.'),
            /* A key the fallback knows is built in; anything else arrived with
             * the catalogue and says for itself. An unknown key with no meta
             * is treated as fetched, which is the safe reading: it will find
             * no fetched data and print its empty line rather than being run
             * through the people renderer with no flag to look for. */
            'built_in'   => isset($m['built_in'])
                ? (int)$m['built_in'] : (isset($fallback[$key]) ? 1 : 0),
        );
    }
    return $out;
}

/**
 * Query: the tick boxes on a STORED payload, as a set keyed by module.
 *
 * ack_keys IS WHAT THE MORNING DECIDED, so it is read back rather than
 * recomputed — see dsModMeta(). summaryItemKeys() is the fallback for every
 * payload written before the key existed, which is every day up to today, and
 * it gives exactly the old answer: the built-in sections that have somebody in
 * them.
 */
function dsModPayloadBoxes($payload, array $groups)
{
    if (is_array($payload) && isset($payload['ack_keys']) && is_array($payload['ack_keys'])) {
        $out = array();
        foreach ($payload['ack_keys'] as $k) { $out[$k] = true; }
        return $out;
    }
    return function_exists('summaryItemKeys') ? summaryItemKeys($groups) : array();
}

/**
 * Query: the modules that get a TICK BOX on this person's summary, in order.
 *
 * THREE TESTS, ALL OF WHICH MUST PASS:
 *   allowed      they are shown the section at all
 *   has content  there is something in it to have read
 *   must_ack     they are actually held to it
 *
 * THIS IS THE LIST THE ACKNOWLEDGEMENT IS MEASURED AGAINST, so it replaces
 * summaryItemKeys() wherever a day is judged complete. summaryItemKeys() tests
 * only the middle one, which was the whole rule while every person got every
 * section and had to tick all of them; it is still correct about content and
 * is still what this calls through to in spirit.
 *
 * A SECTION SHOWN WITHOUT A BOX IS THE POINT OF must_ack. A manager can be
 * given RO1 Work Achievement to read without being stopped at the gate by it,
 * and that is a different thing from not being shown it — which is why there
 * are two columns and not one.
 */
function dsModBoxKeys($personKey, array $staffRows, array $catalogue,
                      array $groups, array $modules)
{
    $out = array();
    foreach ($catalogue as $key => $mod) {
        $eff = dsModFor($key, $personKey, $staffRows, $catalogue);
        if (!$eff['allowed'] || !$eff['must_ack']) { continue; }
        if (!dsModHasContent($key, $catalogue, $groups, $modules)) { continue; }
        $out[] = $key;
    }
    return $out;
}

/**
 * Query: how many people have each module, over the rows given.
 *
 * @return array module_key => array('allowed' => n, 'must_ack' => n)
 *
 * The grid's column totals. Counted off the same dsModFor() every cell is
 * drawn from, so the foot can never disagree with the boxes above it.
 */
function dsModColumnTotals(array $people, array $staffRows, array $catalogue)
{
    $out = array();
    foreach ($catalogue as $key => $mod) {
        $out[$key] = array('allowed' => 0, 'must_ack' => 0);
    }
    foreach ($people as $p) {
        $k = dsModKey($p['comp_id'], $p['person']);
        foreach ($catalogue as $key => $mod) {
            $eff = dsModFor($key, $k, $staffRows, $catalogue);
            if ($eff['allowed'])  { $out[$key]['allowed']++; }
            if ($eff['must_ack']) { $out[$key]['must_ack']++; }
        }
    }
    return $out;
}
