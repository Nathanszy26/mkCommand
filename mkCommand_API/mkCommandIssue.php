<?php
header('Content-Type: application/json; charset=UTF-8');

/**
 * MK Command — issue Memo / Merit / Demerit against your own subordinates.
 *
 * Mirrors the field contract of staff_memo_upload.php and
 * staff_performance_upload.php, minus the attachment step (see NOTE below).
 *
 * IN  action=types        person, comp_id
 *     action=staff        person, comp_id
 *     action=memo         person, comp_id, to_ids, cc_ids?, subject, content, ref?
 *                         (From is the caller — name and id both come from the
 *                          resolved issuer row, never from the request.)
 *     action=performance  person, comp_id, to_ids, ref, title,
 *                         merit_demerit (Merit|Demerit), type_id?, points?
 *
 *     to_ids / cc_ids are CSV lists of users.id.
 *
 *     Optional attachments ride along as multipart files named attachment_1 ..
 *     attachment_5, exactly like the two web upload pages. Sending none is
 *     normal and never blocks the record.
 *
 * OUT { success:true, ..., attachments:{ saved, errors[] } }
 *   | { success:false, message }
 *
 * A memo is additionally mirrored into the myMK app inbox (MemoMirrorClient)
 * and the outcome recorded in staff_memo_mirror, so a memo the mirror missed
 * can be found and re-posted later. The mirror never blocks the record:
 *   { success:true, ..., mirror:{ sent, skipped[], error } }
 */

/**
 * TEMPORARY: send the real exception text to the app instead of the generic
 * message, so a mis-typed schema or a missing GRANT is visible in the banner
 * rather than swallowed.
 *
 * >>> Set to false once a record has actually landed. <<<
 * The exception text leaks schema names and connection detail.
 */
define('MK_ISSUE_DEBUG_ERRORS', true);

class DatabaseConfig
{
    const HOST     = "localhost";
    const USERNAME = "prog4";
    const PASSWORD = "prog42023";
    const DATABASE = "staff_portal2";
    const CHARSET  = "utf8";

    /**
     * Schema behind $db_globportal in includes/db.php. Despite that variable's
     * name the tables live in `staff_profile`: staff_memo, staff_performance
     * and staff_performance_types are all there, while users lives in
     * staff_portal2. Same MySQL instance, so one connection reaches both via a
     * fully-qualified name (identical trick to EvaluationRepository in
     * staffHierarchy.php).
     *
     * Requires prog4 to hold SELECT *and* INSERT on staff_profile.
     */
    const STAFF_PROFILE = "staff_profile";
}

class Database
{
    private static $connection = null;

    public static function getConnection()
    {
        if (self::$connection === null) {
            $dsn = "mysql:host=" . DatabaseConfig::HOST
                . ";dbname=" . DatabaseConfig::DATABASE
                . ";charset=" . DatabaseConfig::CHARSET;

            self::$connection = new PDO($dsn, DatabaseConfig::USERNAME, DatabaseConfig::PASSWORD);
            self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }
        return self::$connection;
    }
}

/**
 * Who the caller is allowed to act on.
 *
 * The app already hides non-subordinates, but that is a UI hint only: this
 * class is the authority. Same chart tables, same active/visible rules as
 * staffHierarchy.php, so the two can never disagree about who reports to whom.
 */
class SubordinateDirectory
{
    const GLOB_COMP_ID = 1;
    const MAX_DEPTH    = 20;

    const USER_VISIBLE = "u.status = 1 AND IFNULL(u.deleted, 0) = 0
                          AND IFNULL(u.mk_command_excluded, 0) <> 1";

    const ACTIVE_CLAUSE = "
        (oc.monthly_assign_from IS NULL OR oc.monthly_assign_from = ''
            OR STR_TO_DATE(REPLACE(oc.monthly_assign_from, '-', '/'), '%Y/%m/%d') <= CURDATE())
        AND (oc.monthly_assign_to IS NULL OR oc.monthly_assign_to = ''
            OR STR_TO_DATE(REPLACE(oc.monthly_assign_to, '-', '/'), '%Y/%m/%d') >= CURDATE())";

    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private static function chartSources()
    {
        return array(
            array('organization_chart',      'oc.comp_id <> ' . self::GLOB_COMP_ID),
            array('organization_chart_glob', 'oc.comp_id = '  . self::GLOB_COMP_ID),
        );
    }

    /**
     * The addressable directory, byte-for-byte the same population the web
     * form's To/CC selects offer:
     *
     *   subsidiaries WHERE staff_memo = '1'  ->  users WHERE comp_id = ? AND status = '1'
     *
     * Two things follow from copying it exactly rather than reusing
     * USER_VISIBLE:
     *  - the staff_memo flag is what keeps one person from appearing once per
     *    company they hold a row in; without it the picker shows duplicates the
     *    web form never had.
     *  - `deleted` and `mk_command_excluded` are NOT applied. Those govern the
     *    org chart, and applying them here would quietly offer a smaller list
     *    than the web, which is the opposite of "same list".
     *
     * One JOIN instead of the web's N+1 loop; same rows, same order.
     */
    const ADDRESSABLE_FROM = "FROM subsidiaries s
                INNER JOIN users u ON u.comp_id = s.id AND u.status = '1'
                WHERE s.staff_memo = '1'";

    /** Query: everyone the memo form may address, grouped by company code. */
    public function addressableStaff()
    {
        $sql = "SELECT u.id, u.fullname, u.position, u.email, u.mobile_no,
                       s.code AS company_code
                " . self::ADDRESSABLE_FROM . "
                ORDER BY s.code, u.fullname";

        $staff = array();
        foreach ($this->db->query($sql)->fetchAll() as $row) {
            $staff[] = array(
                'id'        => (string)$row['id'],
                'fullname'  => $row['fullname'],
                'position'  => $row['position'],
                'email'     => $row['email'],
                'mobile_no' => $row['mobile_no'],
                'company'   => $row['company_code'],
            );
        }
        return $staff;
    }

    /**
     * Query: the subset of $ids that are not addressable.
     *
     * Shares ADDRESSABLE_FROM with the picker on purpose — if this were stricter
     * the form would offer people it then refuses to accept.
     */
    public function unaddressableIds(array $ids)
    {
        if (empty($ids)) {
            return array();
        }

        $place = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT u.id " . self::ADDRESSABLE_FROM . " AND u.id IN ({$place})";

        $stmt = $this->db->prepare($sql);
        foreach (array_values($ids) as $i => $id) {
            $stmt->bindValue($i + 1, (int)$id, PDO::PARAM_INT);
        }
        $stmt->execute();

        $found = array();
        foreach ($stmt->fetchAll() as $row) {
            $found[(int)$row['id']] = true;
        }

        $unknown = array();
        foreach ($ids as $id) {
            if (!isset($found[(int)$id])) {
                $unknown[] = $id;
            }
        }
        return $unknown;
    }

    /** Query: the caller's own users row, or null. */
    public function findStaff($person, $compId)
    {
        $sql = "SELECT u.id, u.person, u.comp_id, u.fullname
                FROM users u
                WHERE u.person = :person AND u.comp_id = :comp_id
                  AND " . self::USER_VISIBLE . "
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':person', $person, PDO::PARAM_STR);
        $stmt->bindValue(':comp_id', (int)$compId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Query: every descendant of (person, comp_id), keyed by users.id.
     * BFS one level per query, visited-set kills cycles — same shape as
     * HierarchyService::buildSubordinateTree.
     *
     * GW never appear: they are read from monthly_assign_gw, have no users.id,
     * and therefore cannot be the target of a memo or a performance record.
     */
    public function descendantsById($person, $compId)
    {
        $frontier = array(array('person' => $person, 'comp_id' => (int)$compId));
        $visited  = array($person . '|' . (int)$compId => true);
        $byId     = array();
        $depth    = 0;

        while (!empty($frontier) && $depth < self::MAX_DEPTH) {
            $rows = $this->findDirectReports($frontier);
            $next = array();

            foreach ($rows as $row) {
                $key = $row['person'] . '|' . (int)$row['comp_id'];
                if (isset($visited[$key])) {
                    continue;
                }
                $visited[$key] = true;

                $byId[(int)$row['id']] = array(
                    'id'       => (int)$row['id'],
                    'person'   => $row['person'],
                    'comp_id'  => (int)$row['comp_id'],
                    'fullname' => $row['fullname'],
                );

                $next[] = array('person' => $row['person'], 'comp_id' => (int)$row['comp_id']);
            }

            $frontier = $next;
            $depth++;
        }

        return $byId;
    }

    private function findDirectReports(array $bosses)
    {
        $tuples   = implode(',', array_fill(0, count($bosses), '(?, ?)'));
        $branches = array();

        foreach (self::chartSources() as $source) {
            list($table, $scope) = $source;
            $branches[] = "SELECT u.id, u.person, u.comp_id, u.fullname
                FROM {$table} oc
                INNER JOIN users u
                    ON u.person = oc.staff_id AND u.comp_id = oc.comp_id
                   AND " . self::USER_VISIBLE . "
                WHERE {$scope}
                  AND (oc.boss_id, oc.boss_comp_id) IN ({$tuples})
                  AND " . self::ACTIVE_CLAUSE;
        }

        $stmt = $this->db->prepare(implode("\nUNION ALL\n", $branches));

        $i = 1;
        for ($b = 0; $b < count($branches); $b++) {
            foreach ($bosses as $boss) {
                $stmt->bindValue($i++, $boss['person'], PDO::PARAM_STR);
                $stmt->bindValue($i++, (int)$boss['comp_id'], PDO::PARAM_INT);
            }
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }
}

/** Active performance types — drives the mobile Type picker and its validation. */
class PerformanceTypeRepository
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function all()
    {
        $sql = "SELECT staff_performance_type_id            AS id,
                       staff_performance_type_title         AS title,
                       staff_performance_type_merit_demerit AS merit_demerit,
                       staff_performance_type_points        AS points
                FROM " . DatabaseConfig::STAFF_PROFILE . ".staff_performance_types
                WHERE staff_performance_type_deleted = '0'
                ORDER BY staff_performance_type_title";

        $types = array();
        foreach ($this->db->query($sql)->fetchAll() as $row) {
            $types[] = array(
                'id'            => (string)$row['id'],
                'title'         => $row['title'],
                'merit_demerit' => strtolower(trim($row['merit_demerit'])),
                'points'        => $row['points'] === null ? '' : (string)$row['points'],
            );
        }
        return $types;
    }
}

/**
 * Writes. Column lists and value semantics match the two web upload pages
 * exactly, so a mobile-issued record is indistinguishable from a web-issued one.
 */
class IssueRepository
{
    /**
     * Two columns, two conventions — one shared constant would be a lie.
     *
     * staff_memo_date        d/m/Y  (08/05/2024 = 8 May 2024)
     * staff_performance_date Y/m/d  (2026/07/31) — must stay year-first:
     *   staffRecords.php filters it with `LIKE '<year>%'` and staffHierarchy.php
     *   parses it with STR_TO_DATE('%Y/%m/%d'). Changing it breaks both.
     *
     * The *_uploaded_date audit stamps are Y/m/d on both and are unaffected.
     *
     * staff_memo_upload.php writes the same memo column from the web and must
     * use d/m/Y too — a column carrying both conventions is unreadable, since
     * 05/08 and 08/05 are different days with nothing to tell them apart.
     */
    const MEMO_DATE_FORMAT        = "d/m/Y";
    const PERFORMANCE_DATE_FORMAT = "Y/m/d";

    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Command: one memo row addressed to all recipients (web stores CSV ids). */
    public function createMemo(array $memo)
    {
        $sql = "INSERT INTO " . DatabaseConfig::STAFF_PROFILE . ".staff_memo
                    (staff_memo_ref, staff_memo_date, staff_memo_to_staff_id,
                     staff_memo_from, staff_memo_from_staff_id,
                     staff_memo_cc_staff_id, staff_memo_subject, staff_memo_content,
                     staff_memo_uploaded_by, staff_memo_uploaded_date, staff_memo_uploaded_time)
                VALUES (:ref, :date, :to_ids, :from, :from_id, :cc_ids, :subject, :content,
                        :uploaded_by, :uploaded_date, :uploaded_time)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array(
            ':ref'           => $memo['ref'],
            ':date'          => date(self::MEMO_DATE_FORMAT),
            ':to_ids'        => $memo['to_ids'],
            ':from'          => $memo['from'],
            ':from_id'       => $memo['from_id'],
            ':cc_ids'        => $memo['cc_ids'],
            ':subject'       => $memo['subject'],
            ':content'       => $memo['content'],
            ':uploaded_by'   => $memo['uploaded_by'],
            ':uploaded_date' => date("Y/m/d"),
            ':uploaded_time' => date("H:i:s"),
        ));

        return (int)$this->db->lastInsertId();
    }

    /**
     * Command: one performance row per recipient. The web form is single-staff;
     * the mobile bar issues to a selection, so the loop lives here and runs in
     * one transaction — a half-written batch is worse than none.
     *
     * Returns the new staff_performance_id list, in $staffIds order, because
     * attachments have to be filed against every one of them.
     */
    public function createPerformanceBatch(array $record, array $staffIds)
    {
        $sql = "INSERT INTO " . DatabaseConfig::STAFF_PROFILE . ".staff_performance
                    (staff_performance_ref, staff_performance_date, staff_performance_staff_id,
                     staff_performance_type_id, staff_performance_type_title,
                     staff_performance_type_merit_demerit, staff_performance_type_points,
                     staff_performance_uploaded_by, staff_performance_uploaded_date,
                     staff_performance_uploaded_time)
                VALUES (:ref, :date, :staff_id, :type_id, :title, :merit_demerit, :points,
                        :uploaded_by, :uploaded_date, :uploaded_time)";

        $date         = date(self::PERFORMANCE_DATE_FORMAT);
        $uploadedDate = date("Y/m/d");
        $uploadedTime = date("H:i:s");

        $ids = array();

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare($sql);
            foreach ($staffIds as $staffId) {
                $stmt->execute(array(
                    ':ref'           => $record['ref'],
                    ':date'          => $date,
                    ':staff_id'      => $staffId,
                    ':type_id'       => $record['type_id'],
                    ':title'         => $record['title'],
                    ':merit_demerit' => $record['merit_demerit'],
                    ':points'        => $record['points'],
                    ':uploaded_by'   => $record['uploaded_by'],
                    ':uploaded_date' => $uploadedDate,
                    ':uploaded_time' => $uploadedTime,
                ));
                $ids[] = (int)$this->db->lastInsertId();
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $ids;
    }
}

/**
 * Optional file attachments, filed the same way StaffMemo_AttachmentAdd and
 * StaffPerformance_AttachmentAdd do it so the existing listing and download
 * pages keep working untouched:
 *
 *   1. insert the row with a blank filename
 *   2. take the auto-increment id
 *   3. stored name = {record_id}_{attachment_id}.{ext}
 *   4. update the row, then move the file into place
 *
 * Two deliberate differences from those helpers:
 *  - the extension is the LAST dot-segment, not the second. `explode(".", $n)[1]`
 *    turns "site.photo.jpg" into a file called "12_3.photo".
 *  - the target directory is derived from __DIR__ rather than DOCUMENT_ROOT,
 *    which is not dependable for an API endpoint.
 */
class AttachmentStore
{
    const MAX_FILES     = 5;                       // matches $no_of_attachments
    const MAX_BYTES     = 10485760;                // 10 MB per file

    /**
     * Whitelist, never a blacklist — these land in a web-served directory, so
     * anything the server might execute must simply never be nameable. Mirrored
     * client-side in IssueActionModal's ALLOWED_EXT; change both together.
     */
    const ALLOWED_EXT   = 'jpg,jpeg,png,gif,webp,heic,heif,pdf,doc,docx,xls,xlsx,csv,txt';

    private $db;
    private $config;

    private function __construct(PDO $db, array $config)
    {
        $this->db     = $db;
        $this->config = $config;
    }

    /** The web pages' attachments folder, resolved from this file's location. */
    private static function attachmentsRoot()
    {
        return __DIR__ . '/../../staffProfile/attachments';
    }

    public static function forMemo(PDO $db)
    {
        return new self($db, array(
            'table'     => DatabaseConfig::STAFF_PROFILE . '.staff_memo_attachments',
            'idCol'     => 'staff_memo_attachment_id',
            'recordCol' => 'staff_memo_id',
            'origCol'   => 'staff_memo_attachment_original_filename',
            'fileCol'   => 'staff_memo_attachment_filename',
            'dir'       => self::attachmentsRoot() . '/staff_memo',
        ));
    }

    public static function forPerformance(PDO $db)
    {
        return new self($db, array(
            'table'     => DatabaseConfig::STAFF_PROFILE . '.staff_performance_attachments',
            'idCol'     => 'staff_performance_attachment_id',
            'recordCol' => 'staff_performance_id',
            'origCol'   => 'staff_performance_attachment_original_filename',
            'fileCol'   => 'staff_performance_attachment_filename',
            'dir'       => self::attachmentsRoot() . '/staff_performance',
        ));
    }

    /**
     * Query: the posted attachment_N entries that actually carry a file.
     * UPLOAD_ERR_NO_FILE is the normal "left this slot empty" case and is not
     * an error; anything else is reported rather than dropped.
     */
    public static function posted()
    {
        $files  = array();
        $errors = array();

        foreach ($_FILES as $field => $file) {
            if (strpos($field, 'attachment_') !== 0 || is_array($file['name'])) {
                continue;
            }
            if ((int)$file['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ((int)$file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = $file['name'] . ': upload error ' . (int)$file['error'];
                continue;
            }
            $files[] = $file;
        }

        if (count($files) > self::MAX_FILES) {
            $files = array_slice($files, 0, self::MAX_FILES);
            $errors[] = 'Only the first ' . self::MAX_FILES . ' attachments were kept.';
        }

        return array('files' => $files, 'errors' => $errors);
    }

    private static function extensionOf($filename)
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return preg_replace('/[^a-z0-9]/', '', $ext);
    }

    /**
     * Query: the name to store as original_filename — the one a recipient reads.
     *
     * Picked files can arrive percent-encoded: an Android SAF uri segment
     * standing in for a display name, or a file a browser saved. Left alone,
     * "Kaunter%20KWSP.pdf" is what lands in the app inbox.
     *
     * Decode BEFORE basename, not after: "..%2Fetc%2Fpasswd" only reveals its
     * separators once decoded, and basename has to be the last word. rawurldecode
     * rather than urldecode, so a real '+' in a filename survives. Decoding a
     * plain name is a no-op, so this is safe whatever the client sent.
     */
    private static function displayName($originalName)
    {
        return basename(rawurldecode((string)$originalName));
    }

    private static function reject(array $file)
    {
        $ext = self::extensionOf($file['name']);

        if ($ext === '' || !in_array($ext, explode(',', self::ALLOWED_EXT), true)) {
            return 'file type not allowed';
        }
        if ((int)$file['size'] <= 0) {
            return 'file is empty';
        }
        if ((int)$file['size'] > self::MAX_BYTES) {
            return 'larger than ' . (self::MAX_BYTES / 1048576) . ' MB';
        }
        return null;
    }

    /**
     * Command: file every attachment against every record id.
     *
     * Runs AFTER the record is committed, and never throws: a lost photo must
     * not cost the user the memo they just wrote, nor tempt them into
     * re-submitting and creating a duplicate. Failures come back in `errors`
     * so the app can say so out loud.
     *
     * An uploaded temp file can only be move_uploaded_file'd once, so a batch
     * moves it for the first record and copies to the rest.
     */
    public function saveAll(array $recordIds, array $files)
    {
        $saved  = 0;
        $errors = array();

        if (empty($files) || empty($recordIds)) {
            return array('saved' => 0, 'errors' => $errors);
        }

        if (!is_dir($this->config['dir'])) {
            return array(
                'saved'  => 0,
                'errors' => array('Attachment folder is missing on the server.'),
            );
        }

        foreach ($files as $file) {
            $problem = self::reject($file);
            if ($problem !== null) {
                $errors[] = $file['name'] . ': ' . $problem;
                continue;
            }

            $source = $file['tmp_name']; // becomes the first written copy below

            foreach ($recordIds as $recordId) {
                try {
                    $target = $this->register($recordId, $file['name']);

                    $ok = ($source === $file['tmp_name'] && is_uploaded_file($source))
                        ? move_uploaded_file($source, $target)
                        : copy($source, $target);

                    if (!$ok) {
                        $errors[] = $file['name'] . ': could not be written';
                        continue;
                    }

                    $source = $target; // later records copy from the stored file
                    $saved++;
                } catch (Exception $e) {
                    error_log('mkCommandIssue attachment failed: ' . $e->getMessage());
                    $errors[] = $file['name'] . ': could not be saved';
                }
            }
        }

        return array('saved' => $saved, 'errors' => $errors);
    }

    /** Command: insert the row, name the file from its id, return the full path. */
    private function register($recordId, $originalName)
    {
        $c = $this->config;

        $insert = $this->db->prepare(
            "INSERT INTO {$c['table']} ({$c['recordCol']}, {$c['origCol']}, {$c['fileCol']})
             VALUES (:record_id, :original, '')"
        );
        $insert->execute(array(
            ':record_id' => $recordId,
            ':original'  => self::displayName($originalName),
        ));

        $attachmentId = (int)$this->db->lastInsertId();
        $filename     = $recordId . '_' . $attachmentId . '.' . self::extensionOf($originalName);

        $update = $this->db->prepare(
            "UPDATE {$c['table']} SET {$c['fileCol']} = :filename WHERE {$c['idCol']} = :id"
        );
        $update->execute(array(':filename' => $filename, ':id' => $attachmentId));

        return $c['dir'] . '/' . $filename;
    }
}

/**
 * Posts a committed memo id to the myMK inbox mirror.
 *
 * The whole contract is the id — subject, body, recipients and attachments are
 * all read from the memo row on the other side. Pure network: it holds no PDO
 * and writes nothing, so MemoMirrorLog can record whatever comes back.
 *
 * The endpoint has no token and only accepts 127.0.0.1; hq maps
 * dashboard.mkgroup.my to loopback in /etc/hosts so the URL, vhost and
 * certificate all stay correct while the connection never leaves the box.
 * A 403 means that hosts entry is missing — the error text says so, because
 * that is the one failure a reader of the log will not otherwise guess.
 */
class MemoMirrorClient
{
    const URL             = 'https://dashboard.mkgroup.my/sportal-memo.php';
    const TIMEOUT         = 10;
    const CONNECT_TIMEOUT = 5;

    /** Longest response we keep for diagnosis. The column is TEXT; be sane. */
    const MAX_LOG_BYTES = 1000;

    /**
     * Query: POST the id and normalise the outcome. Never throws — the memo is
     * already committed by the time this runs, and a mirror that is down must
     * not cost the user the record they just wrote.
     *
     * Returns { ok, http, error, body[], raw }.
     */
    public static function post($memoId)
    {
        if (!function_exists('curl_init')) {
            return self::outcome(false, 0, 'curl is not available on this server', null, '');
        }

        $ch = curl_init(self::URL);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => array('memo_id' => $memoId),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
        ));

        $raw      = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return self::outcome(false, $httpCode, 'could not reach the mirror: ' . $curlErr, null, '');
        }

        $body = json_decode($raw, true);

        if ($httpCode === 403) {
            return self::outcome(false, 403, 'mirror refused the call as non-local — check the /etc/hosts entry for dashboard.mkgroup.my', $body, $raw);
        }
        if ($httpCode !== 200 || !is_array($body) || empty($body['success'])) {
            $message = (is_array($body) && isset($body['message']))
                ? $body['message']
                : 'mirror returned HTTP ' . $httpCode;
            return self::outcome(false, $httpCode, $message, $body, $raw);
        }

        return self::outcome(true, 200, null, $body, $raw);
    }

    private static function outcome($ok, $http, $error, $body, $raw)
    {
        return array(
            'ok'    => (bool)$ok,
            'http'  => (int)$http,
            'error' => $error,
            'body'  => is_array($body) ? $body : array(),
            'raw'   => substr((string)$raw, 0, self::MAX_LOG_BYTES),
        );
    }
}

/**
 * One row per memo recording whether the mirror took it.
 *
 * UNIQUE on staff_memo_id, so a retry updates the row and bumps the attempt
 * count rather than piling up history — the question this table exists to
 * answer is "which memos are still not in the app inbox", and that is a scan
 * for status <> 'sent'. The mirror itself is idempotent (`already_sent`), so
 * re-posting a memo id is always safe.
 */
class MemoMirrorLog
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Command: write the outcome.
     *
     * Returns null on success, or the failure text — same report-back shape as
     * AttachmentStore::saveAll(). It still never throws: a log that cannot be
     * written must not undo a memo that was. But it does not hide either, or a
     * missing GRANT looks identical to a mirror that was never called.
     */
    public function record($memoId, array $outcome)
    {
        $body = $outcome['body'];

        $sql = "INSERT INTO " . DatabaseConfig::STAFF_PROFILE . ".staff_memo_mirror
                    (staff_memo_id, staff_memo_mirror_status, staff_memo_mirror_attempts,
                     staff_memo_mirror_http_code, staff_memo_mirror_job_id,
                     staff_memo_mirror_recipients, staff_memo_mirror_already_sent,
                     staff_memo_mirror_response,
                     staff_memo_mirror_created_date, staff_memo_mirror_created_time,
                     staff_memo_mirror_updated_date, staff_memo_mirror_updated_time)
                VALUES (:memo_id, :status, 1, :http_code, :job_id, :recipients, :already_sent,
                        :response, :created_date, :created_time, :updated_date, :updated_time)
                ON DUPLICATE KEY UPDATE
                    staff_memo_mirror_status       = VALUES(staff_memo_mirror_status),
                    staff_memo_mirror_attempts     = staff_memo_mirror_attempts + 1,
                    staff_memo_mirror_http_code    = VALUES(staff_memo_mirror_http_code),
                    staff_memo_mirror_job_id       = VALUES(staff_memo_mirror_job_id),
                    staff_memo_mirror_recipients   = VALUES(staff_memo_mirror_recipients),
                    staff_memo_mirror_already_sent = VALUES(staff_memo_mirror_already_sent),
                    staff_memo_mirror_response     = VALUES(staff_memo_mirror_response),
                    staff_memo_mirror_updated_date = VALUES(staff_memo_mirror_updated_date),
                    staff_memo_mirror_updated_time = VALUES(staff_memo_mirror_updated_time)";

        $now  = date('Y/m/d');
        $time = date('H:i:s');

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array(
                ':memo_id'      => (int)$memoId,
                ':status'       => $outcome['ok'] ? 'sent' : 'failed',
                ':http_code'    => $outcome['http'],
                ':job_id'       => isset($body['job_id']) ? (string)$body['job_id'] : null,
                ':recipients'   => isset($body['recipients']) ? (int)$body['recipients'] : null,
                ':already_sent' => !empty($body['already_sent']) ? '1' : '0',
                ':response'     => $outcome['ok'] ? $outcome['raw'] : (string)$outcome['error'],
                ':created_date' => $now,
                ':created_time' => $time,
                ':updated_date' => $now,
                ':updated_time' => $time,
            ));
            return null;
        } catch (Exception $e) {
            error_log('mkCommandIssue mirror log failed: ' . $e->getMessage());
            return $e->getMessage();
        }
    }
}

/* --- HTTP layer only: read input, validate, delegate, respond -------------- */

function respond($payload, $httpStatus = 200)
{
    http_response_code($httpStatus);
    echo json_encode($payload);
    exit;
}

function fail($message, $httpStatus = 400)
{
    respond(array('success' => false, 'message' => $message), $httpStatus);
}

function input($keys, $default = null)
{
    foreach ((array)$keys as $key) {
        if (isset($_POST[$key]) && $_POST[$key] !== '') {
            return trim($_POST[$key]);
        }
        if (isset($_GET[$key]) && $_GET[$key] !== '') {
            return trim($_GET[$key]);
        }
    }
    return $default;
}

/** Query: "12,15,18" -> [12, 15, 18], de-duped, positives only. */
function idList($raw)
{
    $ids = array();
    foreach (explode(',', (string)$raw) as $part) {
        $id = (int)trim($part);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

/** Query: the subset of $ids the caller does not supervise. */
function unauthorizedIds(array $ids, array $allowed)
{
    $rejected = array();
    foreach ($ids as $id) {
        if (!isset($allowed[$id])) {
            $rejected[] = $id;
        }
    }
    return $rejected;
}

/**
 * A multipart body over post_max_size arrives with $_POST and $_FILES both
 * EMPTY and no warning of any kind, which would otherwise surface as the
 * nonsense "action, person and comp_id are required". Say what actually
 * happened instead.
 */
function assertUploadNotTruncated()
{
    $length = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $length > 0 && empty($_POST) && empty($_FILES)) {
        fail('Upload too large for the server (post_max_size = '
            . ini_get('post_max_size') . '). Try fewer or smaller attachments.', 413);
    }
}

try {
    assertUploadNotTruncated();

    $action = input('action');
    $person = input('person');
    $compId = input(array('comp_id', 'comid'));

    if ($action === null || $person === null || $compId === null) {
        fail('action, person and comp_id are required');
    }

    $db        = Database::getConnection();
    $directory = new SubordinateDirectory($db);

    $issuer = $directory->findStaff($person, $compId);
    if (!$issuer) {
        fail('Staff not found or inactive', 404);
    }

    if ($action === 'types') {
        $repo = new PerformanceTypeRepository($db);
        respond(array('success' => true, 'types' => $repo->all()));
    }

    if ($action === 'staff') {
        respond(array('success' => true, 'staff' => $directory->addressableStaff()));
    }

    /* Everything past this point writes, so the recipient list is checked first. */
    $toIds = idList(input('to_ids'));
    if (empty($toIds)) {
        fail('Select at least one staff member');
    }

    $allowed  = $directory->descendantsById($issuer['person'], $issuer['comp_id']);
    $rejected = unauthorizedIds($toIds, $allowed);
    if (!empty($rejected)) {
        fail('You may only issue this to your own subordinates', 403);
    }

    $repo = new IssueRepository($db);

    if ($action === 'memo') {
        $subject = input('subject');
        $content = input('content');

        if ($subject === null || $content === null) {
            fail('Subject and Content are both required');
        }

        // CC spans the whole addressable directory, exactly like the web form —
        // see addressableStaff(). It still has to BE in that directory, so a
        // stale or invented id is rejected rather than stored as a dangling ref.
        $ccIds = idList(input('cc_ids'));
        if (!empty($ccIds) && !empty($directory->unaddressableIds($ccIds))) {
            fail('One of the CC recipients is not an addressable staff member', 400);
        }

        // From is the issuer, taken from the row findStaff() already resolved —
        // never from the request. A posted `from` is ignored: the sender's name
        // is not the client's to assert, and the id has to agree with the name
        // or the two columns can disagree about who wrote the memo.
        $memoId = $repo->createMemo(array(
            'ref'         => (string)input('ref', ''),
            'to_ids'      => implode(',', $toIds),
            'cc_ids'      => implode(',', $ccIds),
            'from'        => $issuer['fullname'],
            'from_id'     => (string)$issuer['id'],
            'subject'     => $subject,
            'content'     => $content,
            'uploaded_by' => $issuer['id'],
        ));

        // Attachments are optional and filed after the commit — see saveAll().
        $posted   = AttachmentStore::posted();
        $attached = AttachmentStore::forMemo($db)->saveAll(array($memoId), $posted['files']);

        // Mirror LAST: the attachments have to be on disk before the app is told
        // to go and fetch them, since the mirror links to the staff portal's own
        // attachment folder rather than copying the files.
        $mirror    = MemoMirrorClient::post($memoId);
        $logFailed = (new MemoMirrorLog($db))->record($memoId, $mirror);

        respond(array(
            'success'     => true,
            'id'          => $memoId,
            'recipients'  => count($toIds),
            'attachments' => array(
                'saved'  => $attached['saved'],
                'errors' => array_merge($posted['errors'], $attached['errors']),
            ),
            'mirror'      => array(
                'sent'       => $mirror['ok'],
                // Word/Excel are on the memo but cannot be rendered in the app,
                // so the issuer is told rather than left to assume otherwise.
                'skipped'    => isset($mirror['body']['skipped_attachments'])
                    ? $mirror['body']['skipped_attachments']
                    : array(),
                'error'      => $mirror['error'],
                // Only while debugging: an unwritable log is a deployment fault
                // (missing table, wrong schema, missing GRANT), not something
                // the issuer can act on. It leaks schema names, same as the
                // catch-all below, and goes when MK_ISSUE_DEBUG_ERRORS does.
                'log_error'  => MK_ISSUE_DEBUG_ERRORS ? $logFailed : null,
                'http'       => MK_ISSUE_DEBUG_ERRORS ? $mirror['http'] : null,
            ),
        ));
    }

    if ($action === 'performance') {
        $ref          = input('ref');
        $title        = input('title');
        $meritDemerit = input('merit_demerit');

        if ($ref === null || $title === null || $meritDemerit === null) {
            fail('Ref., Title and Merit/Demerit are all required');
        }

        $meritDemerit = ucfirst(strtolower($meritDemerit));
        if ($meritDemerit !== 'Merit' && $meritDemerit !== 'Demerit') {
            fail('Merit/Demerit must be either Merit or Demerit');
        }

        // Type is optional; when supplied it must be active AND belong to the
        // chosen Merit/Demerit — same rule as staff_performance_upload.php.
        $typeId = (string)input('type_id', '');
        if ($typeId !== '') {
            $valid = false;
            foreach ((new PerformanceTypeRepository($db))->all() as $type) {
                if ($type['id'] === $typeId && $type['merit_demerit'] === strtolower($meritDemerit)) {
                    $valid = true;
                }
            }
            if (!$valid) {
                fail('The selected Type does not match the selected Merit/Demerit');
            }
        }

        $recordIds = $repo->createPerformanceBatch(array(
            'ref'           => $ref,
            'type_id'       => $typeId,
            'title'         => $title,
            'merit_demerit' => $meritDemerit,
            'points'        => (string)input('points', ''),
            'uploaded_by'   => $issuer['id'],
        ), $toIds);

        // One row per recipient, so each gets its own copy of each attachment —
        // that is what the {record_id}_{attachment_id} naming requires.
        $posted   = AttachmentStore::posted();
        $attached = AttachmentStore::forPerformance($db)->saveAll($recordIds, $posted['files']);

        respond(array(
            'success'     => true,
            'created'     => count($recordIds),
            'attachments' => array(
                'saved'  => $attached['saved'],
                'errors' => array_merge($posted['errors'], $attached['errors']),
            ),
        ));
    }

    fail('Unknown action: ' . $action);
} catch (Exception $e) {
    error_log('mkCommandIssue error: ' . $e->getMessage());
    respond(array(
        'success' => false,
        'message' => MK_ISSUE_DEBUG_ERRORS ? $e->getMessage() : 'System error occurred',
    ), 500);
}