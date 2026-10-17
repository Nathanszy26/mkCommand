<?php

// Disable display of errors in output (JSON API) but log all errors
ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

/**
 * Job Spec READ endpoint  (https://mkcommand.mkgroup.my/API/jobSpecs.php)
 *
 * IN  : person, comp_id (alias comid), id, [is_gw]
 * OUT : { success, staff{user_id}, source, version, job_specs[] }
 *
 * action=capability — read by the globportal.com pages (jobSpecWrite.php,
 * jobspecReport.php, staffOverview.php), which cannot reach these portal
 * schemas directly:
 *   IN  : comp_id, [persons[]]
 *   OUT : { success, comp_id, capability[{person, comp_id, items, [job_specs]}] }
 * Lists the active staff of one company who have at least one active portal
 * job description — their capability list. Without persons[] it is the whole
 * company with counts only; with persons[] it is just those staff, each with
 * their task list. It knows nothing of mkPortal: the caller decides whether a
 * staff member already has a version.
 *
 * Precedence:
 *   1. newest APPROVED mkPortal version for this staff -> source = "mkportal"
 *   2. else the live list from the company portal       -> source = "globportal"
 *
 * MULTI-COMPANY: each company has its own local schema. comp_id selects the
 * schema (see Config::$databases). The shapes are SIMILAR, not identical --
 * eaportal (comp 8) has no user_job_descriptions.status -- so PortalRepository
 * probes information_schema and adapts rather than assuming. Without comp_id
 * there is no company, so the id-only path scans the configured portals in
 * order and takes the first active staff_id match.
 *
 * GW (is_gw=1) bypass all of that. Their identity is
 * (monthly_assign_gw_code, comp_id) in evaluation.monthly_assign_gw — they have
 * no row in any portal's users table and no user_job_descriptions, so mkPortal
 * is their only source and the precedence rule above does not apply.
 *
 * mkPortal lives on globportal.com and the mkcommand<->globportal DB port is
 * firewalled, so mkPortal is read over HTTPS from jobSpecWrite.php (which runs
 * locally next to mkPortal). The company portals are local to THIS host and
 * stay direct PDO reads. The approved-version match key is (person, comp_id) —
 * the same identity the app and organization_chart use.
 *
 * PHP 7.2 compatible.
 */

const MK_APPROVED_URL = 'https://globportal.com/accounts/mkPortal/mkCommand/jobSpecWrite.php';

/* ---------- DB (company portals, local) ----------------------------------- */

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
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], array(
                PDO::ATTR_TIMEOUT => 5,
            ));
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$pool[$poolKey] = $pdo;
        }
        return self::$pool[$poolKey];
    }
}

/** Distinguishes "bad input" from "system broke" at the HTTP boundary. */
class UnknownCompanyException extends RuntimeException {}

class Config
{
    /** comp_id -> schema. All portals are local to this host. The DB name is not
     *  the only difference: see PortalRepository for the shape drift between
     *  them. Adding a comp_id here is not enough -- confirm the schema actually
     *  carries the tables and columns this endpoint reads. */
    private static $databases = array(
        1  => 'glob_portal',
        2  => 'likas_square_portal',
        3  => 'amc_portal',
        4  => 'arus_sawit_portal',
        7  => 'grandis_portal',
        17 => 'city_skill_portal',
        8 => 'eaportal',
    );

    /** Query: every configured comp_id, in lookup order. */
    public static function compIds()
    {
        return array_keys(self::$databases);
    }

    /** Query: PDO config for one company. Unknown comp_id is a caller error, not a 500. */
    public static function forCompany($compId)
    {
        $compId = (int)$compId;
        if (!isset(self::$databases[$compId])) {
            throw new UnknownCompanyException('Unsupported comp_id: ' . $compId);
        }
        return array(
            'host' => 'localhost',
            'db'   => self::$databases[$compId],
            'user' => 'globuser',
            'pass' => 'Gl0bUs3r@2026!',
        );
    }
}

/* ---------- company portal (source) --------------------------------------- */

/**
 * One instance per company schema. Table shape is identical across portals;
 * only the database differs, so a single class serves all of them.
 */
class PortalRepository
{
    private $db;
    private $columnCache = array();
    public function __construct(PDO $db) { $this->db = $db; }

    /**
     * Columns present in one table of THIS schema, lowercased, cached per
     * connection. An empty set means the table does not exist here.
     *
     * Portals are NOT shape-identical, whatever the header says: eaportal
     * (comp 8) has no user_job_descriptions.status, and a portal may not carry
     * the job-description tables at all. Probing beats hardcoding per-company
     * exceptions, and keeps the next portal from being another 500.
     */
    private function columns($table)
    {
        if (!isset($this->columnCache[$table])) {
            $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':t', $table, PDO::PARAM_STR);
            $stmt->execute();
            $cols = array();
            foreach ($stmt->fetchAll() as $r) {
                $cols[strtolower($r['COLUMN_NAME'])] = true;
            }
            $this->columnCache[$table] = $cols;
        }
        return $this->columnCache[$table];
    }

    private function hasTable($table)
    {
        $cols = $this->columns($table);
        return !empty($cols);
    }

    private function hasColumn($table, $column)
    {
        $cols = $this->columns($table);
        return isset($cols[strtolower($column)]);
    }

    /**
     * Resolve the staff row.
     * Primary : person + comp_id + status = 1
     * Fallback: staff_id = id + status = 1
     * Returns ['id','person','comp_id'] or null. person+comp_id are the canonical
     * identity used to look up the mkPortal approved version.
     */
    public function resolveUser($person, $compId, $id)
    {
        if ($person !== null && $compId !== null) {
            $sql = "SELECT id, person, comp_id FROM users
                    WHERE person = :person AND comp_id = :comp_id AND status = 1
                    LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':person', $person, PDO::PARAM_STR);
            $stmt->bindValue(':comp_id', (int)$compId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row) {
                return array('id' => (int)$row['id'], 'person' => $row['person'], 'comp_id' => (int)$row['comp_id']);
            }
        }

        // staff_id is not universal across portals. A portal without it must
        // skip this fallback, not blow up: the id-only path scans EVERY portal,
        // so one missing column there would 500 lookups for all companies.
        if ($id !== null && $this->hasColumn('users', 'staff_id')) {
            $sql = "SELECT id, person, comp_id FROM users
                    WHERE staff_id = :staff_id AND status = 1
                    LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':staff_id', $id, PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row) {
                return array('id' => (int)$row['id'], 'person' => $row['person'], 'comp_id' => (int)$row['comp_id']);
            }
        }

        return null;
    }

    /**
     * Live job specs from the company portal.
     * Join is ujd.user_id = users.id; a status = 1 filter keeps deactivated rows
     * out WHERE THAT COLUMN EXISTS — eaportal (comp 8) has no ujd.status, and a
     * schema with no such column has nothing to deactivate, so an unfiltered
     * read is the correct answer there, not an error.
     *
     * A portal missing the tables outright returns an empty list. That is a
     * company with no portal-side job descriptions, which is a legitimate state
     * (mkPortal approved versions still take precedence over this), not a 500.
     */
    public function getSourceJobSpecs($userId)
    {
        if (!$this->hasTable('user_job_descriptions') || !$this->hasTable('job_descriptions')) {
            return array();
        }

        $jdActive  = $this->hasColumn('job_descriptions', 'status') ? ' AND jd.status = 1' : '';
        $ujdActive = $this->hasColumn('user_job_descriptions', 'status') ? ' AND ujd.status = 1' : '';

        $sql = "SELECT jd.id AS job_description_id, jd.task
                FROM user_job_descriptions ujd
                INNER JOIN job_descriptions jd
                    ON jd.id = ujd.job_description_id" . $jdActive . "
                WHERE ujd.user_id = :user_id" . $ujdActive . "
                ORDER BY jd.id ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Capability holders of one company: active staff with at least one active
     * job description, by the same joins and status rules as
     * getSourceJobSpecs(), so "has a capability list" here means exactly what
     * the app would show them.
     *
     * $persons null: the whole company, ['person','items'] per holder.
     * $persons given: only those staff, ['person','job_description_id','task']
     * per task, ordered as getSourceJobSpecs() orders them.
     */
    public function capability($compId, array $persons = null)
    {
        if (!$this->hasTable('user_job_descriptions') || !$this->hasTable('job_descriptions')) {
            return array();
        }
        if ($persons !== null && empty($persons)) {
            return array();
        }

        $jdActive  = $this->hasColumn('job_descriptions', 'status') ? ' AND jd.status = 1' : '';
        $ujdActive = $this->hasColumn('user_job_descriptions', 'status') ? ' AND ujd.status = 1' : '';

        $from = "FROM user_job_descriptions ujd
                 INNER JOIN job_descriptions jd
                     ON jd.id = ujd.job_description_id" . $jdActive . "
                 INNER JOIN users u
                     ON u.id = ujd.user_id AND u.status = 1 AND u.comp_id = ?
                 WHERE 1 = 1" . $ujdActive;

        if ($persons === null) {
            $sql = "SELECT u.person, COUNT(*) AS items {$from}
                    GROUP BY u.person";
        } else {
            $ph  = implode(',', array_fill(0, count($persons), '?'));
            $sql = "SELECT u.person, jd.id AS job_description_id, jd.task {$from}
                      AND u.person IN ({$ph})
                    ORDER BY u.person ASC, jd.id ASC";
        }

        $stmt = $this->db->prepare($sql);
        $i = 1;
        $stmt->bindValue($i++, (int)$compId, PDO::PARAM_INT);
        foreach ((array)$persons as $p) {
            $stmt->bindValue($i++, $p, PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

/** Maps comp_id -> the repository that owns that company's tables. */
class PortalDirectory
{
    /** Query: repository for one company. */
    public static function repository($compId)
    {
        return new PortalRepository(DbFactory::get(Config::forCompany($compId)));
    }
}

/* ---------- mkPortal (approved versions, over HTTPS) ---------------------- */

class MkPortalClient
{
    private $baseUrl;
    public function __construct($baseUrl) { $this->baseUrl = $baseUrl; }

    /**
     * Latest approved version for person+comp, from jobSpecWrite.php on globportal.com.
     * $isGw selects the target population there: staff (organization_chart) or
     * GW (monthly_assign_gw). Defaults to staff so existing callers are unaffected.
     * Returns ['version' => array|null, 'job_specs' => array].
     * Throws on unreachable / bad response — the caller treats that as fail-loud
     * (a 500), NOT a silent fall-through to a possibly-stale portal list.
     */
    public function latestApproved($person, $comp, $isGw = false)
    {
        $url = $this->baseUrl
            . '?action=latest_approved'
            . '&person=' . rawurlencode($person)
            . '&comp_id=' . rawurlencode($comp)
            . '&is_gw=' . ($isGw ? 1 : 0);

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 8,
        ));
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('mkPortal unreachable: ' . $err);
        }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('mkPortal HTTP ' . $code);
        }
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['success'])) {
            throw new RuntimeException('mkPortal bad response');
        }
        return array(
            'version'   => isset($data['version']) ? $data['version'] : null,
            'job_specs' => isset($data['job_specs']) ? $data['job_specs'] : array(),
        );
    }
}

/* ---------- service ------------------------------------------------------- */

class JobSpecService
{
    private $mk;

    public function __construct(MkPortalClient $mk)
    {
        $this->mk = $mk;
    }

    /**
     * Query: ['user' => ['id','person','comp_id'], 'repo' => PortalRepository] or null.
     * comp_id selects the schema. Without it we have no company, so we scan the
     * configured portals in order and take the first active staff_id match.
     */
    public function resolveUser($person, $compId, $id)
    {
        if ($compId !== null) {
            $repo = PortalDirectory::repository($compId);
            $user = $repo->resolveUser($person, $compId, $id);
            return $user === null ? null : array('user' => $user, 'repo' => $repo);
        }

        foreach (Config::compIds() as $cid) {
            $repo = PortalDirectory::repository($cid);
            $user = $repo->resolveUser(null, null, $id);
            if ($user !== null) {
                return array('user' => $user, 'repo' => $repo);
            }
        }
        return null;
    }

    /** Query: active list. mkPortal approved wins, else the company portal source. */
    public function getActiveJobSpecs(PortalRepository $repo, array $user)
    {
        $approved = $this->mk->latestApproved($user['person'], $user['comp_id']);
        if ($approved['version'] !== null) {
            return array(
                'source'    => 'mkportal',
                'version'   => $approved['version'],
                'job_specs' => $approved['job_specs'],
            );
        }

        return array(
            'source'    => 'globportal',
            'version'   => null,
            'job_specs' => $repo->getSourceJobSpecs($user['id']),
        );
    }

    /**
     * Query: a GW's job specs. GW have no row in any company portal's users
     * table and no user_job_descriptions, so mkPortal is the only source and
     * there is nothing to fall back to. No approved version yet (still pending
     * review, say) is an empty list, not an error.
     */
    public function getGwJobSpecs($person, $comp)
    {
        $approved = $this->mk->latestApproved($person, $comp, true);
        return array(
            'source'    => 'mkportal',
            'version'   => $approved['version'],
            'job_specs' => $approved['job_specs'],
        );
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

/** Command: answer action=capability (see the header). Groups the repository's
 *  rows into one entry per staff member. */
function respondCapability($compId)
{
    $persons = null;
    if (isset($_REQUEST['persons']) && is_array($_REQUEST['persons'])) {
        $persons = array();
        foreach ($_REQUEST['persons'] as $p) {
            if (is_string($p) && trim($p) !== '') {
                $persons[] = trim($p);
            }
        }
    }

    $rows = PortalDirectory::repository($compId)->capability($compId, $persons);

    $out = array();
    if ($persons === null) {
        foreach ($rows as $r) {
            $out[] = array('person' => $r['person'], 'comp_id' => (int)$compId, 'items' => (int)$r['items']);
        }
    } else {
        $byPerson = array();
        foreach ($rows as $r) {
            if (!isset($byPerson[$r['person']])) {
                $byPerson[$r['person']] = array(
                    'person' => $r['person'], 'comp_id' => (int)$compId, 'items' => 0, 'job_specs' => array(),
                );
            }
            $byPerson[$r['person']]['items']++;
            $byPerson[$r['person']]['job_specs'][] = array(
                'job_description_id' => (int)$r['job_description_id'],
                'task'               => $r['task'],
            );
        }
        $out = array_values($byPerson);
    }
    respond(array('success' => true, 'comp_id' => (int)$compId, 'capability' => $out));
}

try {
    $person = input('person');
    $compId = input(array('comp_id', 'comid'));
    $id     = input('id');

    if (input('action') === 'capability') {
        if ($compId === null) {
            respond(array('success' => false, 'message' => 'comp_id is required'), 400);
        }
        respondCapability((int)$compId);
    }

    if ($person === null && $compId === null && $id === null) {
        respond(array('success' => false, 'message' => 'person + comp_id, or id, is required'), 400);
    }

    $service = new JobSpecService(new MkPortalClient(MK_APPROVED_URL));

    // GW short-circuit. Their identity is (monthly_assign_gw_code, comp_id) and
    // lives in evaluation.monthly_assign_gw, so resolving them against a portal
    // users table would always 404. There is also no id-only path for a GW:
    // they have no users.id, which is why both fields are required here.
    if ((int)input('is_gw') === 1) {
        if ($person === null || $compId === null) {
            respond(array('success' => false, 'message' => 'person and comp_id are required for a GW'), 400);
        }
        $gw = $service->getGwJobSpecs($person, $compId);
        respond(array(
            'success'   => true,
            'staff'     => array('user_id' => null),
            'source'    => $gw['source'],
            'version'   => $gw['version'],
            'job_specs' => $gw['job_specs'],
        ));
    }

    $found = $service->resolveUser($person, $compId, $id);
    if ($found === null) {
        respond(array('success' => false, 'message' => 'Staff not found or inactive'), 404);
    }

    $user   = $found['user'];
    $result = $service->getActiveJobSpecs($found['repo'], $user);

    respond(array(
        'success'   => true,
        'staff'     => array('user_id' => $user['id']),
        'source'    => $result['source'],
        'version'   => $result['version'],
        'job_specs' => $result['job_specs'],
    ));
} catch (UnknownCompanyException $e) {
    respond(array('success' => false, 'message' => $e->getMessage()), 400);
} catch (Exception $e) {
    error_log('jobSpecs error: ' . $e->getMessage());
    $out = array('success' => false, 'message' => 'System error occurred');
    if (input('debug') === '1') {
        // Bring-up only. Remove or password-gate before production.
        $out['debug'] = $e->getMessage();
    }
    respond($out, 500);
}