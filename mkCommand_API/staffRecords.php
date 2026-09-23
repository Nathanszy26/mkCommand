<?php
header('Content-Type: application/json; charset=UTF-8');

/**
 * Staff records (Memo + Merit/Demerit) READ endpoint for MK Command mobile.
 *
 * MUST be hosted on globportal.com, same folder as staffHierarchy.php /
 * jobSpecWrite.php (proven, in production). prog4@localhost reaches BOTH the
 * globportal DB (staff_memo, staff_performance) AND staff_portal2 (users) on the
 * same mysqld — so a single connection cross-references staff_portal2.users by
 * prefix, exactly the way the web index.php already does it.
 *
 * IN  : action=memos        person, comp_id(|comid)
 *       action=memo_detail  person, comp_id(|comid), id
 *       action=performance  person, comp_id(|comid), [year]
 * OUT : { success, me_name, memos[] }
 *     | { success, memo{...content, attachments[]} }
 *     | { success, performance[] }
 *
 * Filters:
 *   memos       -> not deleted, and
 *                  id < 97  : me OR 'all' in staff_memo_to_staff_id (index.php)
 *                  id >= 97 : a live staff_memo_receivers To/CC row for me,
 *                             'all' of my company, or 'all' of the group
 *   performance -> my records, not deleted, [date LIKE 'YEAR%']
 * Both newest-first. Recipient/staff names resolved in ONE batched lookup (no N+1).
 *
 * SECURITY NOTE (called out, not silently accepted): like staffHierarchy.php,
 * this stack has no server session/token. Identity is client-asserted; the id is
 * re-resolved from (person, comp_id) against users. Bolt real auth on when the
 * app has a token.
 *
 * PHP 7.2 compatible.
 */

/* ---------- DB ------------------------------------------------------------ */

class DbFactory
{
    private static $pool = array();

    public static function get(array $cfg)
    {
        $poolKey = $cfg['host'] . '|' . $cfg['db'];
        if (!isset(self::$pool[$poolKey])) {
            $dsn = 'mysql:host=' . $cfg['host'];
            if (isset($cfg['port']) && $cfg['port'] !== '') {
                $dsn .= ';port=' . $cfg['port'];
            }
            $dsn .= ';dbname=' . $cfg['db'] . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], array(PDO::ATTR_TIMEOUT => 5));
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$pool[$poolKey] = $pdo;
        }
        return self::$pool[$poolKey];
    }
}

class Config
{
    /**
     * staff_memo + staff_performance live here (index.php's $db_globportal).
     * Same host/user/pass as every other mkCommand endpoint.
     *
     * >>> CONFIRM THE DB NAME <<< index.php only exposes the connection as
     * $db_globportal, not the schema name. It is NOT staff_portal2 (index.php
     * prefixes users as `staff_portal2.users` but leaves staff_memo unprefixed).
     * Set this to the real globportal schema before deploying.
     */
    public static function globPortal()
    {
        return array(
            'host' => 'localhost',
            'db'   => 'staff_profile', // <-- CONFIRM: the DB holding staff_memo / staff_performance
            'user' => 'prog4',
            'pass' => 'prog42023',
        );
    }
}

/**
 * "SITI KHADIJAH BINTI KABUL" / "chua shin fun @ kevin chua" ->
 * "Siti Khadijah Binti Kabul" / "Chua Shin Fun @ Kevin Chua".
 *
 * users.fullname and staff_memo_from are typed freehand in any case, so names
 * are normalised here once rather than in every client. The extra delimiters
 * keep "a/l", "o'brien", "(kevin)" and double-barrelled names right.
 */
function properName($name)
{
    $name = preg_replace('/\s+/', ' ', trim((string)$name));
    return ucwords(strtolower($name), " -/('.");
}

/* ---------- repository ---------------------------------------------------- */

class StaffRecordsRepository
{
    private $db;

    public function __construct(PDO $db) { $this->db = $db; }

    /** Resolve the numeric users.id these tables key on, from the mobile identity. */
    public function resolveUserId($person, $comp)
    {
        $sql = "SELECT id FROM staff_portal2.users
                WHERE person = :p AND comp_id = :c AND status = 1 AND deleted = 0
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':p', $person, PDO::PARAM_STR);
        $stmt->bindValue(':c', (int)$comp, PDO::PARAM_INT);
        $stmt->execute();
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /**
     * From this memo id on, To/CC live in staff_memo_receivers and the CSV
     * columns on staff_memo are ignored (they are blank, or stale — memo 97's
     * CSV still says 'all' while its live receivers are Globinaco only).
     * Below it, the CSV columns are the only record.
     */
    const RECEIVERS_FROM_MEMO_ID = 97;

    /**
     * Shared by memos() and memoById(): if the two ever diverge, a memo becomes
     * openable that the list never showed.
     *
     *   legacy    -> my id or 'all' in staff_memo_to_staff_id (as index.php did)
     *   receivers -> a live To OR CC row naming me, 'all' of my company, or
     *                'all' of the whole group
     */
    private static function visibleClause()
    {
        return "m.staff_memo_deleted = '0'
                AND (
                    (m.staff_memo_id < " . self::RECEIVERS_FROM_MEMO_ID . "
                        AND (FIND_IN_SET('all', m.staff_memo_to_staff_id) <> 0
                          OR FIND_IN_SET(:id, m.staff_memo_to_staff_id) <> 0))
                    OR
                    (m.staff_memo_id >= " . self::RECEIVERS_FROM_MEMO_ID . "
                        AND EXISTS (
                            SELECT 1 FROM staff_memo_receivers r
                            WHERE r.staff_memo_id = m.staff_memo_id
                              AND r.staff_memo_receiver_deleted = '0'
                              AND (r.staff_memo_receiver_staff_id = :rid
                                OR (r.staff_memo_receiver_staff_id = 'all'
                                    AND r.staff_memo_receiver_comp_id IN ('all', :rcomp)))))
                )";
    }

    private static function bindVisibility(PDOStatement $stmt, $userId, $compId)
    {
        $stmt->bindValue(':id', (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(':rid', (string)(int)$userId, PDO::PARAM_STR);
        $stmt->bindValue(':rcomp', (string)(int)$compId, PDO::PARAM_STR);
    }

    /** Memos addressed to me, not deleted, newest first. */
    public function memos($userId, $compId)
    {
        $sql = "SELECT m.staff_memo_id, m.staff_memo_ref, m.staff_memo_date,
                       m.staff_memo_to_staff_id, m.staff_memo_from, m.staff_memo_subject
                FROM staff_memo m
                WHERE " . self::visibleClause() . "
                ORDER BY m.staff_memo_id DESC";
        $stmt = $this->db->prepare($sql);
        self::bindVisibility($stmt, $userId, $compId);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** One memo, body included — but ONLY if it would appear in this user's list. */
    public function memoById($memoId, $userId, $compId)
    {
        $sql = "SELECT m.staff_memo_id, m.staff_memo_ref, m.staff_memo_date,
                       m.staff_memo_to_staff_id, m.staff_memo_cc_staff_id,
                       m.staff_memo_from, m.staff_memo_subject, m.staff_memo_content
                FROM staff_memo m
                WHERE m.staff_memo_id = :mid
                  AND " . self::visibleClause() . "
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':mid', (int)$memoId, PDO::PARAM_INT);
        self::bindVisibility($stmt, $userId, $compId);
        $stmt->execute();

        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Live receiver rows for a set of memo ids, in insertion order:
     * [memo_id => ['to' => [[staff_id, comp_id], ...], 'cc' => [...]]].
     */
    public function receiversByMemoIds(array $memoIds)
    {
        $memoIds = array_values(array_unique(array_map('intval', $memoIds)));
        if (empty($memoIds)) {
            return array();
        }
        $place = implode(',', array_fill(0, count($memoIds), '?'));
        $sql = "SELECT staff_memo_id, staff_memo_receiver_type,
                       staff_memo_receiver_staff_id, staff_memo_receiver_comp_id
                FROM staff_memo_receivers
                WHERE staff_memo_id IN ({$place})
                  AND staff_memo_receiver_deleted = '0'
                ORDER BY staff_memo_receiver_id";
        $stmt = $this->db->prepare($sql);
        foreach ($memoIds as $i => $id) {
            $stmt->bindValue($i + 1, (string)$id, PDO::PARAM_STR);
        }
        $stmt->execute();

        $out = array();
        foreach ($stmt->fetchAll() as $r) {
            $type = strtolower(trim($r['staff_memo_receiver_type']));
            if ($type !== 'to' && $type !== 'cc') {
                continue;
            }
            $out[(int)$r['staff_memo_id']][$type][] = array(
                trim($r['staff_memo_receiver_staff_id']),
                trim($r['staff_memo_receiver_comp_id']),
            );
        }
        return $out;
    }

    /** subsidiaries.id => code, for "ALL <code> STAFF" labels. */
    public function companyCodes()
    {
        $map = array();
        foreach ($this->db->query("SELECT id, code FROM staff_portal2.subsidiaries")->fetchAll() as $r) {
            $map[(int)$r['id']] = $r['code'];
        }
        return $map;
    }

    /**
     * Live attachment rows for a memo.
     *
     * StaffMemo_AttachmentDelete flags the row AND moves the file into a
     * /deleted subfolder, so a flagged row points at a path that no longer
     * exists — filtering here is what stops the app rendering dead links. The
     * blank-filename guard covers the brief window inside AttachmentAdd where
     * the row exists before it is named.
     */
    public function memoAttachments($memoId)
    {
        $sql = "SELECT staff_memo_attachment_id,
                       staff_memo_attachment_original_filename,
                       staff_memo_attachment_filename
                FROM staff_memo_attachments
                WHERE staff_memo_id = :mid
                  AND IFNULL(staff_memo_attachment_deleted, '0') <> '1'
                  AND IFNULL(staff_memo_attachment_filename, '') <> ''
                ORDER BY staff_memo_attachment_id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':mid', (int)$memoId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** My performance records, not deleted, optional year, newest first. */
    public function performance($userId, $year)
    {
        $sql = "SELECT staff_performance_id, staff_performance_ref, staff_performance_date,
                       staff_performance_staff_id, staff_performance_type_title,
                       staff_performance_type_merit_demerit, staff_performance_type_points
                FROM staff_performance
                WHERE staff_performance_staff_id = :id
                  AND staff_performance_deleted = 0";
        if ($year !== null && $year !== '') {
            $sql .= " AND staff_performance_date LIKE :ylike";
        }
        $sql .= " ORDER BY staff_performance_id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', (int)$userId, PDO::PARAM_INT);
        if ($year !== null && $year !== '') {
            $stmt->bindValue(':ylike', $year . '%', PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** "users.id" => fullname for a set of ids, in one query. */
    public function namesByIds(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return array();
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT id, fullname FROM staff_portal2.users WHERE id IN ({$place})";
        $stmt = $this->db->prepare($sql);
        foreach ($ids as $i => $id) {
            $stmt->bindValue($i + 1, $id, PDO::PARAM_INT);
        }
        $stmt->execute();
        $map = array();
        foreach ($stmt->fetchAll() as $r) {
            $map[(int)$r['id']] = properName($r['fullname']);
        }
        return $map;
    }
}

/* ---------- service (shaping) --------------------------------------------- */

class StaffRecordsService
{
    private $repo;

    public function __construct(StaffRecordsRepository $repo) { $this->repo = $repo; }

    /** The staff_memo CSV column that held each receiver type before receivers. */
    private static $legacyColumn = array(
        'to' => 'staff_memo_to_staff_id',
        'cc' => 'staff_memo_cc_staff_id',
    );

    /**
     * Query: a memo's To or CC as [staff_id, comp_id] pairs, whichever era it
     * is from. staff_id is a users.id or 'all'; comp_id is a subsidiaries.id,
     * 'all' (whole group) or '' (legacy row, company unrecorded).
     *
     * A legacy 'all' meant the whole group, so it becomes ['all', 'all'].
     */
    private function recipientsOf(array $row, $type, array $receivers)
    {
        $memoId = (int)$row['staff_memo_id'];

        if ($memoId >= StaffRecordsRepository::RECEIVERS_FROM_MEMO_ID) {
            return isset($receivers[$memoId][$type]) ? $receivers[$memoId][$type] : array();
        }

        $column = self::$legacyColumn[$type];
        $raw    = isset($row[$column]) ? (string)$row[$column] : '';

        $out = array();
        foreach (explode(',', $raw) as $tok) {
            $tok = trim($tok);
            if ($tok === '') {
                continue;
            }
            $out[] = strtolower($tok) === 'all' ? array('all', 'all') : array($tok, '');
        }
        return $out;
    }

    /** Collect the numeric users.ids referenced across recipient lists. */
    private static function staffIdsIn(array $lists)
    {
        $ids = array();
        foreach ($lists as $pairs) {
            foreach ($pairs as $pair) {
                if (strtolower($pair[0]) !== 'all' && (int)$pair[0] > 0) {
                    $ids[] = (int)$pair[0];
                }
            }
        }
        return $ids;
    }

    /**
     * Query: recipients as the comma-joined label the app splits on.
     * "All Staff" = the whole group, "All <code> Staff" = one company — the
     * app keys its broadcast styling on the "All ... Staff" shape.
     */
    private static function labelOf(array $pairs, array $names, array $codes)
    {
        $out = array();
        foreach ($pairs as $pair) {
            list($staffId, $compId) = $pair;

            if (strtolower($staffId) === 'all') {
                $label = ($compId === '' || strtolower($compId) === 'all' || !isset($codes[(int)$compId]))
                    ? 'All Staff'
                    : 'All ' . $codes[(int)$compId] . ' Staff';
            } else {
                $label = isset($names[(int)$staffId]) ? $names[(int)$staffId] : $staffId;
            }
            $out[$label] = $label; // de-dupe, keep first-seen order
        }
        return implode(', ', $out);
    }

    public function memos($person, $comp)
    {
        $userId = $this->repo->resolveUserId($person, $comp);
        if ($userId === null) {
            throw new RuntimeException('STAFF_NOT_FOUND');
        }
        $rows = $this->repo->memos($userId, $comp);

        $receivers = $this->repo->receiversByMemoIds(array_map(function ($r) {
            return $r['staff_memo_id'];
        }, $rows));

        $toLists = array();
        foreach ($rows as $r) {
            $toLists[] = $this->recipientsOf($r, 'to', $receivers);
        }

        // Resolve recipient names AND my own name in one batched lookup. My id is
        // appended so me_name is available even on 'all'-addressed memos, where my
        // id isn't in the recipient set. The client highlights the recipient
        // segment matching me_name.
        $ids   = self::staffIdsIn($toLists);
        $ids[] = $userId;
        $names = $this->repo->namesByIds($ids);
        $codes = $this->repo->companyCodes();
        $meName = isset($names[$userId]) ? $names[$userId] : '';

        $out = array();
        foreach ($rows as $i => $r) {
            $out[] = array(
                'id'      => (int)$r['staff_memo_id'],
                'ref'     => $r['staff_memo_ref'],
                'date'    => $r['staff_memo_date'],
                'to_name' => self::labelOf($toLists[$i], $names, $codes),
                'from'    => properName($r['staff_memo_from']),
                'subject' => $r['staff_memo_subject'],
            );
        }
        return array('me_name' => $meName, 'memos' => $out);
    }

    /** Where StaffMemo_AttachmentAdd writes, relative to the web root. */
    const MEMO_ATTACHMENT_PATH = '/accounts/staffProfile/attachments/staff_memo/';

    /** Rendered inline by the app; everything else opens in an external viewer. */
    const IMAGE_EXT = 'jpg,jpeg,png,gif,webp,heic,heif';

    /**
     * Absolute URLs are built here, not in the app: the client has no business
     * knowing the attachment folder layout, and this survives the files moving.
     */
    private static function baseUrl()
    {
        $secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'globportal.com';

        return ($secure ? 'https' : 'http') . '://' . $host;
    }

    private static function isImage($filename)
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, explode(',', self::IMAGE_EXT), true);
    }

    public function memoDetail($person, $comp, $memoId)
    {
        $userId = $this->repo->resolveUserId($person, $comp);
        if ($userId === null) {
            throw new RuntimeException('STAFF_NOT_FOUND');
        }

        $row = $this->repo->memoById($memoId, $userId, $comp);
        if ($row === null) {
            // Same response whether the memo is missing, deleted, or simply not
            // addressed to this user — no probing the id space for what exists.
            throw new RuntimeException('MEMO_NOT_FOUND');
        }

        $receivers = $this->repo->receiversByMemoIds(array($row['staff_memo_id']));
        $to    = $this->recipientsOf($row, 'to', $receivers);
        $cc    = $this->recipientsOf($row, 'cc', $receivers);
        $names = $this->repo->namesByIds(self::staffIdsIn(array($to, $cc)));
        $codes = $this->repo->companyCodes();

        $base = self::baseUrl() . self::MEMO_ATTACHMENT_PATH;
        $attachments = array();
        foreach ($this->repo->memoAttachments($row['staff_memo_id']) as $a) {
            $stored = $a['staff_memo_attachment_filename'];
            $attachments[] = array(
                'id'       => (int)$a['staff_memo_attachment_id'],
                'name'     => $a['staff_memo_attachment_original_filename'],
                'url'      => $base . rawurlencode($stored),
                'is_image' => self::isImage($stored),
            );
        }

        return array(
            'id'          => (int)$row['staff_memo_id'],
            'ref'         => $row['staff_memo_ref'],
            'date'        => $row['staff_memo_date'],
            'to_name'     => self::labelOf($to, $names, $codes),
            'cc_name'     => self::labelOf($cc, $names, $codes),
            'from'        => properName($row['staff_memo_from']),
            'subject'     => $row['staff_memo_subject'],
            'content'     => $row['staff_memo_content'],
            'attachments' => $attachments,
        );
    }

    public function performance($person, $comp, $year)
    {
        $userId = $this->repo->resolveUserId($person, $comp);
        if ($userId === null) {
            throw new RuntimeException('STAFF_NOT_FOUND');
        }
        $rows  = $this->repo->performance($userId, $year);
        // Every row is filtered to my own users.id, so one name covers them all.
        $names = $this->repo->namesByIds(array($userId));
        $staffName = isset($names[$userId]) ? $names[$userId] : (string)$userId;

        $out = array();
        foreach ($rows as $r) {
            $out[] = array(
                'id'            => (int)$r['staff_performance_id'],
                'ref'           => $r['staff_performance_ref'],
                'date'          => $r['staff_performance_date'],
                'staff_name'    => $staffName,
                'title'         => $r['staff_performance_type_title'],
                'merit_demerit' => $r['staff_performance_type_merit_demerit'],
                'points'        => $r['staff_performance_type_points'],
            );
        }
        return $out;
    }
}

/* ---------- HTTP ---------------------------------------------------------- */

function respond($payload, $status = 200)
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function input($keys)
{
    foreach ((array)$keys as $key) {
        if (isset($_POST[$key]) && $_POST[$key] !== '') {
            return trim($_POST[$key]);
        }
        if (isset($_GET[$key]) && $_GET[$key] !== '') {
            return trim($_GET[$key]);
        }
    }
    return null;
}

try {
    $action = input('action');
    $person = input('person');
    $comp   = input(array('comp_id', 'comid'));

    if ($action === null) {
        respond(array('success' => false, 'message' => 'action is required'), 400);
    }
    if ($person === null || $comp === null) {
        respond(array('success' => false, 'message' => 'person and comp_id are required'), 400);
    }

    $repo = new StaffRecordsRepository(DbFactory::get(Config::globPortal()));
    $svc  = new StaffRecordsService($repo);

    if ($action === 'memos') {
        $res = $svc->memos($person, $comp);
        respond(array('success' => true, 'me_name' => $res['me_name'], 'memos' => $res['memos']));
    }

    if ($action === 'memo_detail') {
        $memoId = input('id');
        if ($memoId === null) {
            respond(array('success' => false, 'message' => 'id is required'), 400);
        }
        respond(array('success' => true, 'memo' => $svc->memoDetail($person, $comp, $memoId)));
    }

    if ($action === 'performance') {
        $year = input('year'); // '' / null => all years
        respond(array('success' => true, 'performance' => $svc->performance($person, $comp, $year)));
    }

    respond(array('success' => false, 'message' => 'Unknown action'), 400);

} catch (RuntimeException $e) {
    if ($e->getMessage() === 'STAFF_NOT_FOUND') {
        respond(array('success' => false, 'message' => 'Staff not found or inactive'), 404);
    }
    if ($e->getMessage() === 'MEMO_NOT_FOUND') {
        respond(array('success' => false, 'message' => 'Memo not available'), 404);
    }
    respond(array('success' => false, 'message' => 'System error occurred'), 500);
} catch (Exception $e) {
    error_log('staffRecords error: ' . $e->getMessage());
    $out = array('success' => false, 'message' => 'System error occurred');
    if (input('debug') === '1') {
        $out['debug'] = $e->getMessage();
    }
    respond($out, 500);
}