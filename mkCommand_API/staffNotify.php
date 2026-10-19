<?php
/**
 * Notification dispatch for Staff Memo and Staff Merit/Demerit.
 *
 * One issued record -> the people it concerns -> a push and an email each ->
 * one queue row per recipient per channel. Nothing here decides anything and
 * nothing here is read back by a UI, so it is always called AFTER the record
 * it announces is committed: a dead mail server must never cost somebody the
 * memo they just wrote.
 *
 * Shared by four callers, which is the whole point of it being one file:
 *   staffProfile/staff_memo_upload.php          (web, mysql_*)
 *   staffProfile/staff_performance_upload.php   (web, mysql_*)
 *   mkPortal/mkCommand/mkCommandIssue.php       (app, PDO)
 *   mkPortal/mkCommand/staffNotifyDrain.php     (the sender)
 * It opens its own PDO connection rather than borrowing the caller's link, so
 * the two web pages keep their mysql_* world and this file keeps a connection
 * that is unambiguously utf8mb4 -- see staff_notify_text() for why that
 * matters.
 *
 * WRITE then SEND, never write-and-send. An all-staff memo reaches ~720 live
 * accounts; sending inline would mean ~720 curl calls plus ~720 mail() calls
 * inside the request that saved the memo. Enqueuing all of them is two
 * queries. staffNotifyDrain.php does the sending, and staff_notify_kick()
 * pokes it so nobody has to wait for a cron tick.
 *
 * PHP 5.4+ -- the staffProfile site still runs the mysql_* extension, so
 * nothing in here may use syntax newer than that.
 */

if (defined('STAFF_NOTIFY_LOADED')) {
    return;
}
define('STAFF_NOTIFY_LOADED', true);

/* =====================================================================
 * CONFIG -- the only lines you edit.
 * ===================================================================== */

/** Master switches. Both off = nothing is resolved and nothing is queued. */
define('STAFF_NOTIFY_PUSH',  true);
define('STAFF_NOTIFY_EMAIL', true);

/**
 * Endpoint that turns a staff_portal2.users.id into FCM sends. Posted as JSON:
 *   {"id":<users.id>,"title":..,"body":..,"data":{"screen":..}}
 * Same endpoint the Manpower Requisition module pushes through.
 */
define('STAFF_NOTIFY_PUSH_URL',     'http://app.globportal.com/mymk/api/firebase/fcm/custom');
define('STAFF_NOTIFY_PUSH_TIMEOUT', 8);

/** Envelope for the email channel. The display name is what the inbox shows. */
define('STAFF_NOTIFY_FROM', 'MK Staff Portal <noreply@glob.com.my>');

/** Absolute base for attachment links inside an email. No trailing slash. */
define('STAFF_NOTIFY_BASE_URL', 'https://globportal.com');

/** Where StaffMemo_AttachmentAdd writes, relative to the web root. */
define('STAFF_NOTIFY_MEMO_ATTACHMENT_PATH', '/accounts/staffProfile/attachments/staff_memo/');

/**
 * The drain, and the secret that lets this process poke it. Change the key
 * here and nowhere else -- staffNotifyDrain.php reads this same constant.
 */
define('STAFF_NOTIFY_DRAIN_URL', 'https://globportal.com/accounts/mkPortal/mkCommand/staffNotifyDrain.php');
define('STAFF_NOTIFY_DRAIN_KEY', 'K7mQ2xVp9LdR4sTnB6wZyE3hJ8aCfU5g');

/**
 * Testing whitelist: CSV of staff_portal2.users.id. When set, only these
 * people are queued, whatever the memo actually said. '' = notify everybody
 * the record resolves to. Set it to your own id to point every send at one
 * handset while you are trying this out.
 */
define('STAFF_NOTIFY_TEST_USER_IDS', '');

/**
 * Testing redirect for email: every message goes here instead of the real
 * inbox, and the queue row still records who it was meant for. '' = send for
 * real.
 */
define('STAFF_NOTIFY_EMAIL_OVERRIDE', '');

/**
 * Connection. Same box and same login as the rest of mkCommand; it needs
 * SELECT + INSERT + UPDATE on staff_profile.staff_notify_queue on top of
 * what it already holds.
 */
function staff_notify_db_config()
{
    return array(
        'host' => 'localhost',
        'db'   => 'staff_profile',
        'user' => 'prog4',
        'pass' => 'prog42023',
    );
}

/* =====================================================================
 * Connection
 * ===================================================================== */

/** The one connection this file uses. utf8mb4 all the way down. */
function staff_notify_db()
{
    static $pdo = null;

    if ($pdo === null) {
        $c   = staff_notify_db_config();
        $dsn = 'mysql:host=' . $c['host'] . ';dbname=' . $c['db'] . ';charset=utf8mb4';

        $pdo = new PDO($dsn, $c['user'], $c['pass'], array(PDO::ATTR_TIMEOUT => 5));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    return $pdo;
}

/* =====================================================================
 * Text
 * ===================================================================== */

/**
 * Every string that goes into a notification passes through here.
 *
 * Two jobs:
 *
 * 1. Repair text that was written as UTF-8 but travelled through a latin1
 *    step on its way in or out. That is where "Globinaco's" turns into
 *    "Globinaco" followed by three stray symbols: the three bytes of the
 *    typographic apostrophe get read one at a time as Windows-1252
 *    characters. Converting the string back DOWN to Windows-1252 undoes
 *    exactly that step.
 *
 *    The conversion is only kept when the result is still valid UTF-8. That
 *    is the whole safety argument: a string that was never double-encoded
 *    does not survive the round trip -- "cafe" with an e-acute becomes a lone
 *    0xE9 byte, which is not valid UTF-8, so the original is kept. Only text
 *    that really was mangled converts cleanly, and it is allowed to convert
 *    twice because a string can be mangled twice.
 *
 * 2. Drop anything that is still not valid UTF-8, because json_encode()
 *    returns false for the whole payload on a single bad byte -- one stray
 *    character in one memo would otherwise silence the push for everybody on
 *    it.
 */
function staff_notify_text($value)
{
    $value = (string)$value;
    if ($value === '') {
        return '';
    }

    for ($pass = 0; $pass < 2; $pass++) {
        // C2/C3 lead bytes are what mojibake is made of. No match, no work.
        if (!preg_match('/[\xC2-\xC3][\x80-\xBF]/', $value)) {
            break;
        }

        $down = @iconv('UTF-8', 'Windows-1252', $value);
        if ($down === false || $down === '' || $down === $value) {
            break;
        }
        if (!staff_notify_is_utf8($down)) {
            break;  // not double-encoded: leave it exactly as it came
        }

        $value = $down;
    }

    $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

    return $clean === false ? '' : $clean;
}

/** True when every byte of $value is part of a well-formed UTF-8 sequence. */
function staff_notify_is_utf8($value)
{
    if (function_exists('mb_check_encoding')) {
        return mb_check_encoding($value, 'UTF-8');
    }
    return (bool)preg_match('//u', $value);
}

/** Collapse to one line and trim to $max characters, with an ellipsis when cut. */
function staff_notify_clip($value, $max)
{
    $value = staff_notify_text($value);
    $value = trim(preg_replace('/\s+/u', ' ', $value));

    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($length <= $max) {
        return $value;
    }

    $cut = function_exists('mb_substr')
        ? mb_substr($value, 0, $max - 1, 'UTF-8')
        : substr($value, 0, $max - 1);

    return rtrim($cut) . "\xE2\x80\xA6";
}

/** "SITI BINTI KABUL" -> "Siti Binti Kabul". Same rule as staffRecords.php. */
function staff_notify_proper_name($name)
{
    $name = preg_replace('/\s+/', ' ', trim(staff_notify_text($name)));
    return ucwords(strtolower($name), " -/('.");
}

/** CSV constant to a clean list. */
function staff_notify_csv($csv)
{
    $out = array();
    foreach (explode(',', (string)$csv) as $value) {
        $value = trim($value);
        if ($value !== '') {
            $out[] = $value;
        }
    }
    return $out;
}

/**
 * A readable name list that does not run to three hundred entries. Memos go
 * to whole companies, and a recipient line naming everybody is no longer
 * telling the reader anything.
 */
function staff_notify_name_list(array $names, $max = 12)
{
    $names = array_values(array_filter(array_map('trim', $names), 'strlen'));
    if (!$names) {
        return '';
    }
    if (count($names) <= $max) {
        return implode(', ', $names);
    }

    $extra = count($names) - $max;
    return implode(', ', array_slice($names, 0, $max))
        . ' and ' . $extra . ' other' . ($extra === 1 ? '' : 's');
}

/* =====================================================================
 * Recipients
 *
 * "Who is notified" has to be the same question as "who can see it", or the
 * app shows a badge for a memo the person cannot open -- or worse, somebody
 * is emailed a memo they are not on. The rule below is the enqueue-side twin
 * of StaffRecordsRepository::visibleClause() in staffRecords.php; change one
 * and change the other.
 *
 * Active means staff_portal2.users.status = '1' AND deleted = '0', which is
 * the same population staffPoolAPI.php reports and the same one the memo
 * form's To/CC pickers offer. Nothing else counts as a staff member here.
 * ===================================================================== */

/** The active-account columns every recipient query selects. */
function staff_notify_user_columns()
{
    return "u.id, u.fullname, u.email, u.comp_id";
}

/**
 * Query: the named recipients of a memo, as users rows tagged 'to' or 'cc'.
 *
 * staff_memo_receiver_staff_id is a varchar holding either a users.id or the
 * literal 'all', so the numeric rows are picked out with a REGEXP and joined
 * on a CAST -- comparing a utf8mb4 varchar to a latin1 one across schemas is
 * what raises "Illegal mix of collations" on this 5.6 box.
 */
function staff_notify_memo_named(PDO $db, $memoId)
{
    $sql = "SELECT r.staff_memo_receiver_type AS role, " . staff_notify_user_columns() . "
              FROM staff_memo_receivers r
              JOIN staff_portal2.users u
                ON u.id = CAST(r.staff_memo_receiver_staff_id AS UNSIGNED)
             WHERE r.staff_memo_id = :mid
               AND IFNULL(r.staff_memo_receiver_deleted, '0') <> '1'
               AND r.staff_memo_receiver_staff_id REGEXP '^[0-9]+$'
               AND u.status = '1' AND u.deleted = '0'";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':mid', (int)$memoId, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/** Query: the 'all staff' rows of a memo, as role + company scope. */
function staff_notify_memo_broadcasts(PDO $db, $memoId)
{
    $sql = "SELECT DISTINCT r.staff_memo_receiver_type AS role,
                   r.staff_memo_receiver_comp_id AS comp
              FROM staff_memo_receivers r
             WHERE r.staff_memo_id = :mid
               AND IFNULL(r.staff_memo_receiver_deleted, '0') <> '1'
               AND r.staff_memo_receiver_staff_id = 'all'";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':mid', (int)$memoId, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * Query: every active account of one company, or -- for the group-wide
 * broadcast -- of every company the memo module covers.
 *
 * The group is scoped by subsidiaries.staff_memo = '1' because that is the
 * population the memo form itself offers under "To All Staff". A company
 * nobody can address a memo to is not a company anybody gets memos from.
 */
function staff_notify_company_users(PDO $db, $compId)
{
    if ($compId === 'all') {
        $sql = "SELECT " . staff_notify_user_columns() . "
                  FROM staff_portal2.users u
                  JOIN staff_portal2.subsidiaries s ON s.id = u.comp_id
                 WHERE u.status = '1' AND u.deleted = '0'
                   AND s.staff_memo = '1'";
        $stmt = $db->prepare($sql);
    } else {
        $sql = "SELECT " . staff_notify_user_columns() . "
                  FROM staff_portal2.users u
                 WHERE u.status = '1' AND u.deleted = '0'
                   AND u.comp_id = :c";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':c', (int)$compId, PDO::PARAM_INT);
    }

    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * Fold one users row into the recipient map, keyed by users.id so nobody is
 * notified twice however many ways the memo reached them.
 *
 * 'to' outranks 'cc': somebody who is both is addressed, not copied. That is
 * enforced here rather than by the caller's ordering alone, so it also holds
 * for the person named in To who is swept up again by an all-staff CC.
 */
function staff_notify_collect(array &$map, array $row, $role)
{
    $id = (int)$row['id'];
    if ($id <= 0) {
        return;
    }

    if (isset($map[$id]) && $map[$id]['role'] === 'to') {
        return;
    }

    $map[$id] = array(
        'role'     => $role === 'cc' ? 'cc' : 'to',
        'id'       => $id,
        'fullname' => staff_notify_proper_name($row['fullname']),
        'email'    => trim(staff_notify_text(isset($row['email']) ? $row['email'] : '')),
        'comp_id'  => (int)$row['comp_id'],
    );
}

/** Apply STAFF_NOTIFY_TEST_USER_IDS, which is a no-op once it is blank. */
function staff_notify_apply_whitelist(array $map)
{
    $testIds = staff_notify_csv(STAFF_NOTIFY_TEST_USER_IDS);
    if (!$testIds) {
        return $map;
    }

    $out = array();
    foreach ($map as $id => $person) {
        if (in_array((string)$id, $testIds, true)) {
            $out[$id] = $person;
        }
    }
    return $out;
}

/**
 * Everybody a memo must be told about, keyed by users.id.
 *
 * The sender is dropped. They wrote it; a push telling them they have mail
 * from themselves is noise, and on an all-staff memo it is the first thing
 * they would see. Both the named sender and whoever uploaded it on their
 * behalf are dropped, because on the web an assistant types the memo under
 * the boss's name and staff_memo_uploaded_by is the only trace of it.
 */
function staff_notify_memo_recipients(PDO $db, array $memo)
{
    $memoId = (int)$memo['staff_memo_id'];
    $map    = array();

    // 'to' first, so the "to beats cc" rule holds whatever order the rows
    // came back in.
    foreach (array('to', 'cc') as $role) {
        foreach (staff_notify_memo_named($db, $memoId) as $row) {
            if (strtolower(trim($row['role'])) === $role) {
                staff_notify_collect($map, $row, $role);
            }
        }
        foreach (staff_notify_memo_broadcasts($db, $memoId) as $cast) {
            if (strtolower(trim($cast['role'])) !== $role) {
                continue;
            }
            $comp = trim((string)$cast['comp']);
            $comp = ($comp === 'all' || $comp === '') ? 'all' : $comp;

            foreach (staff_notify_company_users($db, $comp) as $row) {
                staff_notify_collect($map, $row, $role);
            }
        }
    }

    foreach (array($memo['staff_memo_from_staff_id'], $memo['staff_memo_uploaded_by']) as $self) {
        $self = (int)trim((string)$self);
        if ($self > 0) {
            unset($map[$self]);
        }
    }

    return staff_notify_apply_whitelist($map);
}

/** The one staff member a merit/demerit row is about, keyed the same way. */
function staff_notify_performance_recipients(PDO $db, array $record)
{
    $sql = "SELECT " . staff_notify_user_columns() . "
              FROM staff_portal2.users u
             WHERE u.id = :id AND u.status = '1' AND u.deleted = '0'
             LIMIT 1";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':id', (int)$record['staff_performance_staff_id'], PDO::PARAM_INT);
    $stmt->execute();

    $map = array();
    foreach ($stmt->fetchAll() as $row) {
        staff_notify_collect($map, $row, 'to');
    }

    return staff_notify_apply_whitelist($map);
}

/* =====================================================================
 * Records
 * ===================================================================== */

/**
 * Query: one memo row, or null. Cached, because the drain renders an email
 * per recipient and an all-staff memo would otherwise read the same row
 * several hundred times.
 */
function staff_notify_memo_row(PDO $db, $memoId)
{
    static $cache = array();

    $memoId = (int)$memoId;
    if (array_key_exists($memoId, $cache)) {
        return $cache[$memoId];
    }

    $stmt = $db->prepare(
        "SELECT staff_memo_id, staff_memo_ref, staff_memo_date, staff_memo_from,
                staff_memo_from_staff_id, staff_memo_from_comp_id,
                staff_memo_subject, staff_memo_content, staff_memo_uploaded_by
           FROM staff_memo
          WHERE staff_memo_id = :id AND IFNULL(staff_memo_deleted, '0') <> '1'
          LIMIT 1"
    );
    $stmt->bindValue(':id', $memoId, PDO::PARAM_INT);
    $stmt->execute();

    $row = $stmt->fetch();
    $cache[$memoId] = $row === false ? null : $row;

    return $cache[$memoId];
}

/** Query: one merit/demerit row, or null. Cached for the same reason. */
function staff_notify_performance_row(PDO $db, $recordId)
{
    static $cache = array();

    $recordId = (int)$recordId;
    if (array_key_exists($recordId, $cache)) {
        return $cache[$recordId];
    }

    $stmt = $db->prepare(
        "SELECT staff_performance_id, staff_performance_ref, staff_performance_date,
                staff_performance_staff_id, staff_performance_type_title,
                staff_performance_type_merit_demerit, staff_performance_type_points
           FROM staff_performance
          WHERE staff_performance_id = :id AND IFNULL(staff_performance_deleted, 0) <> 1
          LIMIT 1"
    );
    $stmt->bindValue(':id', $recordId, PDO::PARAM_INT);
    $stmt->execute();

    $row = $stmt->fetch();
    $cache[$recordId] = $row === false ? null : $row;

    return $cache[$recordId];
}

/**
 * Query: a memo's live attachments as name + absolute URL.
 *
 * Same filter staffRecords.php applies -- a deleted row points at a file that
 * has been moved away, and a blank filename is the half-second inside
 * AttachmentAdd before the row is named.
 */
function staff_notify_memo_attachments(PDO $db, $memoId)
{
    static $cache = array();

    $memoId = (int)$memoId;
    if (isset($cache[$memoId])) {
        return $cache[$memoId];
    }

    $stmt = $db->prepare(
        "SELECT staff_memo_attachment_original_filename AS name,
                staff_memo_attachment_filename          AS file
           FROM staff_memo_attachments
          WHERE staff_memo_id = :id
            AND IFNULL(staff_memo_attachment_deleted, '0') <> '1'
            AND IFNULL(staff_memo_attachment_filename, '') <> ''
          ORDER BY staff_memo_attachment_id"
    );
    $stmt->bindValue(':id', $memoId, PDO::PARAM_INT);
    $stmt->execute();

    $out = array();
    foreach ($stmt->fetchAll() as $row) {
        $name = staff_notify_text($row['name']);
        $out[] = array(
            'name' => $name !== '' ? $name : staff_notify_text($row['file']),
            'url'  => STAFF_NOTIFY_BASE_URL . STAFF_NOTIFY_MEMO_ATTACHMENT_PATH
                      . rawurlencode($row['file']),
        );
    }

    $cache[$memoId] = $out;

    return $out;
}

/** 'Merit' / 'Demerit', however the row spells it. */
function staff_notify_merit_word($value)
{
    return stripos((string)$value, 'demerit') !== false ? 'Demerit' : 'Merit';
}

/* =====================================================================
 * Messages
 *
 * The push and the email are built from one description of the event, so the
 * two can never say different things about the same record -- and the screen
 * the push opens is named here, once, rather than at each call site.
 * ===================================================================== */

/**
 * What a memo says, and where tapping it lands.
 *
 * `screen` and `memo_id` are what fcm.ts routes on: MkCommandMemo opens the
 * MK Command Memo tab, and the id tells it which memo to open on arrival.
 * FCM data values are strings on the wire, so they are sent as strings.
 */
function staff_notify_memo_message(array $memo)
{
    $subject = staff_notify_clip($memo['staff_memo_subject'], 120);
    $from    = staff_notify_proper_name($memo['staff_memo_from']);

    return array(
        'event'     => 'memo',
        'record_id' => (int)$memo['staff_memo_id'],
        'reference' => staff_notify_clip($memo['staff_memo_ref'], 100),
        'title'     => 'New Memo',
        'body'      => ($subject !== '' ? $subject : '(No subject)')
                       . ($from !== '' ? "\nFrom " . $from : ''),
        'payload'   => array(
            'screen' => 'MkCommandMemo',
            'data'   => array(
                'screen'  => 'MkCommandMemo',
                'memo_id' => (string)(int)$memo['staff_memo_id'],
            ),
        ),
    );
}

/**
 * What a merit or demerit says. The two are separate events rather than one
 * event with a field, because "Merit Awarded" and "Demerit Issued" are not
 * the same news and must never be phrased as though they were.
 */
function staff_notify_performance_message(array $record)
{
    $word   = staff_notify_merit_word($record['staff_performance_type_merit_demerit']);
    $title  = staff_notify_clip($record['staff_performance_type_title'], 120);
    $points = trim(staff_notify_text($record['staff_performance_type_points']));

    $line = $title !== '' ? $title : $word;
    if ($points !== '' && (int)$points !== 0) {
        $line .= ' (' . ($word === 'Demerit' ? '-' : '+') . (int)$points . ' points)';
    }

    return array(
        'event'     => strtolower($word),
        'record_id' => (int)$record['staff_performance_id'],
        'reference' => staff_notify_clip($record['staff_performance_ref'], 100),
        'title'     => $word === 'Demerit' ? 'Demerit Issued' : 'Merit Awarded',
        'body'      => $line,
        'payload'   => array(
            'screen' => 'MkCommandMeritDemerit',
            'data'   => array(
                'screen'         => 'MkCommandMeritDemerit',
                'performance_id' => (string)(int)$record['staff_performance_id'],
                // The screen filters by year and defaults to the current one,
                // so the record's own year rides along -- a demerit dated last
                // December must not open onto an empty list.
                'year'           => staff_notify_year_of($record['staff_performance_date']),
            ),
        ),
    );
}

/** The 4-digit year out of a Y/m/d or d/m/Y date string, or ''. */
function staff_notify_year_of($date)
{
    $parts = preg_split('#[-/ ]#', trim((string)$date));
    foreach ($parts as $part) {
        if (preg_match('/^\d{4}$/', $part)) {
            return $part;
        }
    }
    return '';
}

/* =====================================================================
 * Queue
 * ===================================================================== */

/**
 * Command: one queue row per recipient per enabled channel.
 *
 * INSERT IGNORE against uq_once, so running this twice for the same record
 * tops the queue up rather than doubling it -- which is what makes it safe
 * to call from a retry, or from both halves of a save that was interrupted.
 * Rows go in 100 at a time: one statement for a memo to a handful of people,
 * eight for a memo to the whole group.
 *
 * A recipient with no usable email address is queued as 'skipped' rather than
 * dropped, because "nobody emailed this person" is a fact worth being able to
 * look up, and silence cannot be distinguished from success.
 */
function staff_notify_enqueue(PDO $db, array $message, array $recipients)
{
    $channels = array();
    if (STAFF_NOTIFY_PUSH)  { $channels[] = 'push'; }
    if (STAFF_NOTIFY_EMAIL) { $channels[] = 'email'; }

    if (!$channels || !$recipients) {
        return 0;
    }

    $payload = json_encode($message['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $now     = date('Y-m-d H:i:s');
    $rows    = array();

    foreach ($recipients as $person) {
        foreach ($channels as $channel) {
            $email  = $person['email'];
            $usable = $channel === 'email'
                ? ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))
                : true;

            $rows[] = array(
                $message['event'], $message['record_id'], $message['reference'],
                $channel, $person['id'], $person['fullname'], $person['comp_id'],
                $person['role'],
                $channel === 'email' ? $email : (string)$person['id'],
                $message['title'], $message['body'], $payload,
                $usable ? 'pending' : 'skipped',
                $usable ? null : 'No usable email address on the users row.',
                $now,
            );
        }
    }

    $columns = "(`event`, `record_id`, `reference`, `channel`, `recipient_user_id`,
                 `recipient_name`, `recipient_comp_id`, `recipient_role`, `target`,
                 `title`, `body`, `payload`, `status`, `response`, `created_datetime`)";
    $tuple   = '(' . rtrim(str_repeat('?, ', 15), ', ') . ')';
    $queued  = 0;

    foreach (array_chunk($rows, 100) as $chunk) {
        $sql = "INSERT IGNORE INTO staff_notify_queue " . $columns . " VALUES "
             . implode(', ', array_fill(0, count($chunk), $tuple));

        $flat = array();
        foreach ($chunk as $row) {
            foreach ($row as $value) {
                $flat[] = $value;
            }
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($flat);
        $queued += $stmt->rowCount();
    }

    return $queued;
}

/* =====================================================================
 * Email bodies
 *
 * Styles are inline and the layout is a table on purpose -- Gmail strips
 * <style> blocks and ignores most modern CSS, so anything else degrades to
 * unstyled text.
 * ===================================================================== */

/** Escape for HTML, after the text has been straightened out. */
function staff_notify_e($value)
{
    return htmlspecialchars(staff_notify_text($value), ENT_QUOTES, 'UTF-8');
}

/**
 * Query: how a memo's To and CC lines should read.
 *
 * A broadcast is printed as "All Staff" or "All Grandis Staff" rather than
 * expanded: the point of the line is to tell the reader who else got this,
 * and three hundred names does not tell them that. Cached per memo, because
 * every recipient's email asks the same question.
 */
function staff_notify_memo_audience(PDO $db, $memoId)
{
    static $cache = array();

    $memoId = (int)$memoId;
    if (isset($cache[$memoId])) {
        return $cache[$memoId];
    }

    $lists = array('to' => array(), 'cc' => array());

    foreach (staff_notify_memo_named($db, $memoId) as $row) {
        $role = strtolower(trim($row['role'])) === 'cc' ? 'cc' : 'to';
        $lists[$role][] = staff_notify_proper_name($row['fullname']);
    }

    foreach (staff_notify_memo_broadcasts($db, $memoId) as $cast) {
        $role = strtolower(trim($cast['role'])) === 'cc' ? 'cc' : 'to';
        $comp = trim((string)$cast['comp']);

        $lists[$role][] = ($comp === 'all' || $comp === '')
            ? 'All Staff'
            : 'All ' . staff_notify_company_code($db, $comp) . ' Staff';
    }

    $cache[$memoId] = array(
        'to' => staff_notify_name_list($lists['to']),
        'cc' => staff_notify_name_list($lists['cc']),
    );

    return $cache[$memoId];
}

/** Query: a subsidiary's short code, falling back to its id. */
function staff_notify_company_code(PDO $db, $compId)
{
    static $cache = array();

    $compId = (int)$compId;
    if (isset($cache[$compId])) {
        return $cache[$compId];
    }

    $stmt = $db->prepare("SELECT code, name FROM staff_portal2.subsidiaries WHERE id = :id LIMIT 1");
    $stmt->bindValue(':id', $compId, PDO::PARAM_INT);
    $stmt->execute();

    $row  = $stmt->fetch();
    $code = $row ? trim(staff_notify_text($row['code'])) : '';
    if ($code === '' && $row) {
        $code = trim(staff_notify_text($row['name']));
    }

    $cache[$compId] = $code !== '' ? $code : ('Company ' . $compId);

    return $cache[$compId];
}

/**
 * The shell every notification email shares: a heading, an optional
 * reference, one labelled row per fact, an optional free-text block, an
 * optional attachment list, and a closing line.
 */
function staff_notify_email_html($title, $reference, array $lines, $content, array $attachments, $note)
{
    $rows = '';
    foreach ($lines as $label => $value) {
        $value = trim(staff_notify_text($value));
        if ($value === '') {
            continue;   // a fact the record does not carry is left out, not printed as "-"
        }
        $rows .= '<tr>'
               . '<td style="padding:7px 18px 7px 0;color:#6b7280;font-size:13px;'
               . 'white-space:nowrap;vertical-align:top">' . staff_notify_e($label) . '</td>'
               . '<td style="padding:7px 0;color:#111827;font-size:14px;font-weight:600">'
               . staff_notify_e($value) . '</td>'
               . '</tr>';
    }

    $ref = trim(staff_notify_text($reference)) !== ''
         ? '<p style="margin:2px 0 0;color:#6b7280;font-size:12px">Reference '
           . staff_notify_e($reference) . '</p>'
         : '';

    $body = trim(staff_notify_text($content)) !== ''
          ? '<div style="margin-top:18px;padding-top:16px;border-top:1px solid #e5e7eb;'
            . 'color:#111827;font-size:14px;line-height:1.6">'
            . nl2br(staff_notify_e($content)) . '</div>'
          : '';

    $files = '';
    if ($attachments) {
        $items = '';
        foreach ($attachments as $file) {
            $items .= '<li style="margin:4px 0"><a href="' . staff_notify_e($file['url'])
                    . '" style="color:#2563eb;font-size:13px">'
                    . staff_notify_e($file['name']) . '</a></li>';
        }
        $files = '<div style="margin-top:16px"><p style="margin:0 0 4px;color:#6b7280;'
               . 'font-size:12px">' . count($attachments) . ' attachment'
               . (count($attachments) === 1 ? '' : 's')
               . '</p><ul style="margin:0;padding-left:18px">' . $items . '</ul></div>';
    }

    return '<div style="margin:0;padding:24px 12px;background:#f4f4f5;'
         . 'font-family:Arial,Helvetica,sans-serif">'
         . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"'
         . ' style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;'
         . 'border-radius:10px"><tr><td style="padding:22px 24px">'
         . '<h1 style="margin:0;color:#111827;font-size:17px;line-height:1.35">'
         . staff_notify_e($title) . '</h1>'
         . $ref
         . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"'
         . ' style="margin-top:16px;border-top:1px solid #e5e7eb;padding-top:4px">'
         . $rows . '</table>'
         . $body
         . $files
         . '<p style="margin:20px 0 0;color:#6b7280;font-size:12px;line-height:1.5">'
         . staff_notify_e($note) . '</p>'
         . '</td></tr></table>'
         . '<p style="max-width:560px;margin:12px auto 0;color:#9ca3af;font-size:11px;'
         . 'text-align:center">Computer generated. Do not reply to this message.</p>'
         . '</div>';
}

/**
 * Query: the subject and HTML of one queued email, rendered from the record
 * it announces rather than from anything stored on the queue row. The queue
 * holds who and how; the memo holds what it says, and it is read fresh so a
 * correction made before the queue drained is the version that goes out.
 *
 * Returns null when the record has since been deleted -- that is not a
 * failure, it is a memo that must no longer be sent.
 */
function staff_notify_email_body(PDO $db, array $job)
{
    if ($job['event'] === 'memo') {
        $memo = staff_notify_memo_row($db, $job['record_id']);
        if (!$memo) {
            return null;
        }

        $audience = staff_notify_memo_audience($db, (int)$job['record_id']);
        $subject  = staff_notify_clip($memo['staff_memo_subject'], 150);

        return array(
            'subject' => 'Memo: ' . ($subject !== '' ? $subject : '(No subject)'),
            'html'    => staff_notify_email_html(
                $subject !== '' ? $subject : 'Staff Memo',
                $memo['staff_memo_ref'],
                array(
                    'Date' => $memo['staff_memo_date'],
                    'From' => staff_notify_proper_name($memo['staff_memo_from']),
                    'To'   => $audience['to'],
                    'CC'   => $audience['cc'],
                ),
                $memo['staff_memo_content'],
                staff_notify_memo_attachments($db, (int)$job['record_id']),
                'Open the myMK app and go to MK Command > Memo to read this memo there.'
            ),
        );
    }

    $record = staff_notify_performance_row($db, $job['record_id']);
    if (!$record) {
        return null;
    }

    $word   = staff_notify_merit_word($record['staff_performance_type_merit_demerit']);
    $title  = $word === 'Demerit' ? 'Demerit Issued' : 'Merit Awarded';
    $points = trim(staff_notify_text($record['staff_performance_type_points']));

    return array(
        'subject' => $title . ' - ' . staff_notify_clip($record['staff_performance_type_title'], 120),
        'html'    => staff_notify_email_html(
            $title,
            $record['staff_performance_ref'],
            array(
                'Date'   => $record['staff_performance_date'],
                'Staff'  => $job['recipient_name'],
                'Title'  => $record['staff_performance_type_title'],
                'Type'   => $word,
                'Points' => $points === '' ? '' : ($word === 'Demerit' ? '-' : '+') . (int)$points,
            ),
            '',
            array(),
            'Open the myMK app and go to MK Command > Merit/Demerit to see this record.'
        ),
    );
}

/* =====================================================================
 * Transports -- each returns array(ok, response)
 * ===================================================================== */

/** One push, by users.id. The endpoint fans it out to that person's tokens. */
function staff_notify_push_send($userId, $title, $body, array $data)
{
    $payload = json_encode(
        array('id' => (int)$userId, 'title' => $title, 'body' => $body, 'data' => $data),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($payload === false) {
        return array('ok' => false, 'response' => 'payload is not encodable as JSON');
    }

    $ch = curl_init(STAFF_NOTIFY_PUSH_URL);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => STAFF_NOTIFY_PUSH_TIMEOUT,
    ));
    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return array('ok' => false, 'response' => 'curl: ' . $error);
    }

    // success:true is the endpoint's own verdict. HTTP 200 alone is not
    // enough: it answers 200 with success:false when it holds no token for
    // the id, which is the normal state for somebody who never installed the
    // app and is not a fault worth retrying.
    $json = json_decode((string)$response, true);
    $ok   = $code === 200 && is_array($json) && !empty($json['success']);

    return array('ok' => $ok, 'response' => 'HTTP ' . $code . ' ' . substr((string)$response, 0, 500));
}

/** One email. The caller owns the wording; this only puts it on the wire. */
function staff_notify_email_send($to, $subject, $html)
{
    $headers = "MIME-Version: 1.0\r\n"
             . "Content-type: text/html; charset=UTF-8\r\n"
             . 'From: ' . STAFF_NOTIFY_FROM . "\r\n"
             . 'Reply-To: ' . STAFF_NOTIFY_FROM;

    // Subjects are not 7-bit clean once a memo title carries a dash or a
    // quote mark, and a raw 8-bit subject header is what makes a subject
    // line arrive as mojibake. Encoded-word is the only portable answer.
    $encoded = '=?UTF-8?B?' . base64_encode(staff_notify_text($subject)) . '?=';

    $sent = @mail($to, $encoded, $html, $headers);

    return array(
        'ok'       => (bool)$sent,
        'response' => $sent ? 'accepted by MTA' : 'mail() refused the message',
    );
}

/* =====================================================================
 * Drain -- the half that actually sends
 * ===================================================================== */

/** Command: record the outcome of one attempt against its queue row. */
function staff_notify_settle(PDO $db, $jobId, $status, $response)
{
    static $stmt = null;

    if ($stmt === null) {
        $stmt = $db->prepare(
            "UPDATE staff_notify_queue
                SET status = :s, attempts = attempts + 1,
                    response = :r, updated_datetime = NOW()
              WHERE id = :id"
        );
    }

    $stmt->execute(array(
        ':s'  => $status,
        ':r'  => substr((string)$response, 0, 2000),
        ':id' => (int)$jobId,
    ));
}

/** Send one queue row. Never throws: a dead transport is a row, not a crash. */
function staff_notify_send(PDO $db, array $job)
{
    try {
        if ($job['channel'] === 'push') {
            $payload = json_decode((string)$job['payload'], true);
            $data    = (is_array($payload) && isset($payload['data'])) ? $payload['data'] : array();

            return staff_notify_push_send(
                $job['recipient_user_id'], $job['title'], $job['body'], $data
            );
        }

        $body = staff_notify_email_body($db, $job);
        if ($body === null) {
            // Deleted between queueing and draining. Not a failure -- it is a
            // message that must no longer be sent, and the row says so.
            return array('ok' => null, 'response' => 'The record no longer exists; nothing was sent.');
        }

        $to = STAFF_NOTIFY_EMAIL_OVERRIDE !== '' ? STAFF_NOTIFY_EMAIL_OVERRIDE : $job['target'];

        return staff_notify_email_send($to, $body['subject'], $body['html']);
    } catch (Exception $e) {
        return array('ok' => false, 'response' => get_class($e) . ': ' . $e->getMessage());
    }
}

/**
 * Command: send what is waiting, oldest first, and write down what happened.
 *
 * Two guards make this safe to call from anywhere -- a save, a cron tick, a
 * browser:
 *   GET_LOCK  one drain at a time, so two overlapping calls cannot both send
 *             the same row. A second caller returns immediately rather than
 *             queueing up behind the first.
 *   budget    the loop stops after $budgetSeconds whatever is left, and asks
 *             for another drain if rows remain. An all-staff memo therefore
 *             goes out over several short passes instead of one request that
 *             the web server kills halfway.
 */
function staff_notify_drain($limit = 300, $budgetSeconds = 45)
{
    $stats = array('locked' => false, 'attempted' => 0, 'sent' => 0,
                   'failed' => 0, 'skipped' => 0, 'remaining' => 0);

    try {
        $db = staff_notify_db();
    } catch (Exception $e) {
        error_log('staff_notify drain: ' . $e->getMessage());
        return $stats;
    }

    $lock = $db->query("SELECT GET_LOCK('staff_notify_drain', 0)")->fetchColumn();
    if ((int)$lock !== 1) {
        $stats['locked'] = true;
        return $stats;
    }

    $before = null;

    try {
        $limit   = max(1, (int)$limit);
        $started = microtime(true);

        // What was waiting when this pass began. The pass only asks for
        // another one if it got that number DOWN -- see the kick below.
        $before = (int)$db->query(
            "SELECT COUNT(*) FROM staff_notify_queue WHERE status = 'pending'"
        )->fetchColumn();

        // LIMIT is interpolated, not bound: PDO's emulated prepares quote a
        // bound parameter, and a quoted LIMIT is a syntax error.
        $jobs = $db->query(
            "SELECT id, `event`, record_id, channel, recipient_user_id, recipient_name,
                    recipient_role, target, title, body, payload
               FROM staff_notify_queue
              WHERE status = 'pending'
              ORDER BY id
              LIMIT " . $limit
        )->fetchAll();

        foreach ($jobs as $job) {
            if (microtime(true) - $started > $budgetSeconds) {
                break;
            }

            $result = staff_notify_send($db, $job);
            $stats['attempted']++;

            if ($result['ok'] === null) {
                $status = 'skipped';
                $stats['skipped']++;
            } elseif ($result['ok']) {
                $status = 'sent';
                $stats['sent']++;
            } else {
                $status = 'failed';
                $stats['failed']++;
                error_log('staff_notify ' . $job['channel'] . ' failed for '
                        . $job['recipient_name'] . ': ' . $result['response']);
            }

            staff_notify_settle($db, $job['id'], $status, $result['response']);
        }

        $stats['remaining'] = (int)$db->query(
            "SELECT COUNT(*) FROM staff_notify_queue WHERE status = 'pending'"
        )->fetchColumn();
    } catch (Exception $e) {
        error_log('staff_notify drain: ' . $e->getMessage());
    }

    $db->query("SELECT RELEASE_LOCK('staff_notify_drain')");

    /*
     * Hand on to another pass, but only on proof that this one moved the
     * queue. A pass that sent nothing and left the same number waiting has
     * hit something it cannot get past -- the database refusing writes, a
     * row it cannot settle -- and asking for another pass would be asking
     * for the same failure in a loop, forever, as fast as HTTP allows.
     * Leaving it is safe: the rows are still pending and the cron tick will
     * try again in a minute, by which time the fault may be gone.
     */
    if ($before !== null && $stats['remaining'] > 0 && $stats['remaining'] < $before) {
        staff_notify_kick();
    }

    return $stats;
}

/**
 * Command: ask the drain to run, without waiting for it.
 *
 * The timeout is deliberately shorter than the drain could possibly take:
 * the request is made, the connection is dropped, and the drain carries on
 * because staffNotifyDrain.php sets ignore_user_abort(). Nothing here reads
 * a response, and a failure to connect is not reported -- the queue rows are
 * still there and a cron tick will find them.
 */
function staff_notify_kick()
{
    if (!function_exists('curl_init')) {
        return;
    }

    $ch = curl_init(STAFF_NOTIFY_DRAIN_URL);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => array('key' => STAFF_NOTIFY_DRAIN_KEY),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOSIGNAL       => 1,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT_MS     => 400,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    curl_exec($ch);
    curl_close($ch);
}

/* =====================================================================
 * Entry points -- what the four callers use
 *
 * Both of these are called AFTER the record is committed and neither throws.
 * A notification that could not be queued must never undo a memo that was
 * already written; the error_log line is the loud failure.
 * ===================================================================== */

/**
 * A memo has been issued. Queues a push and an email for everybody on it and
 * pokes the drain.
 *
 * Safe to call twice for the same memo -- see staff_notify_enqueue().
 */
function staff_notify_memo($memoId)
{
    $result = array('queued' => 0, 'recipients' => 0, 'error' => null);

    if (!STAFF_NOTIFY_PUSH && !STAFF_NOTIFY_EMAIL) {
        return $result;
    }

    try {
        $db   = staff_notify_db();
        $memo = staff_notify_memo_row($db, $memoId);

        if (!$memo) {
            $result['error'] = 'Memo ' . (int)$memoId . ' was not found.';
            error_log('staff_notify: ' . $result['error']);
            return $result;
        }

        $recipients = staff_notify_memo_recipients($db, $memo);

        $result['recipients'] = count($recipients);
        $result['queued']     = staff_notify_enqueue(
            $db, staff_notify_memo_message($memo), $recipients
        );
    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
        error_log('staff_notify memo ' . (int)$memoId . ': ' . $e->getMessage());
        return $result;
    }

    if ($result['queued'] > 0) {
        staff_notify_kick();
    }

    return $result;
}

/**
 * One or more merit/demerit rows have been issued -- the app writes a row per
 * recipient in one go, so this takes a list.
 */
function staff_notify_performance($recordIds)
{
    $result = array('queued' => 0, 'recipients' => 0, 'error' => null);

    if (!STAFF_NOTIFY_PUSH && !STAFF_NOTIFY_EMAIL) {
        return $result;
    }

    $recordIds = is_array($recordIds) ? $recordIds : array($recordIds);

    try {
        $db = staff_notify_db();

        foreach ($recordIds as $recordId) {
            $record = staff_notify_performance_row($db, $recordId);
            if (!$record) {
                error_log('staff_notify: performance ' . (int)$recordId . ' was not found.');
                continue;
            }

            $recipients = staff_notify_performance_recipients($db, $record);

            $result['recipients'] += count($recipients);
            $result['queued']     += staff_notify_enqueue(
                $db, staff_notify_performance_message($record), $recipients
            );
        }
    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
        error_log('staff_notify performance: ' . $e->getMessage());
        return $result;
    }

    if ($result['queued'] > 0) {
        staff_notify_kick();
    }

    return $result;
}
