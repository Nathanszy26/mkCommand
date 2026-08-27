<?php
header('Content-Type: application/json; charset=UTF-8');
 
/**
 * Job Spec WRITE + approval endpoint.
 *
 * MUST be hosted on globportal.com — prog4@localhost reaches BOTH mkPortal
 * (versions) and staff_portal2 (organization_chart) locally. No cross-server
 * DB hop: the mkcommand <-> globportal 3306 path is firewalled.
 * Same deployment folder as staffHierarchy.php (proven, in production).
 *
 * Actions:
 *   submit          person, comp_id, [is_gw], submitter, submitter_comp_id, items (JSON array)
 *   approve         version_id, reviewer, reviewer_comp_id, [note]
 *   reject          version_id, reviewer, reviewer_comp_id, [note]
 *   pending         boss, boss_comp_id                 (a superior's actionable queue)
 *   latest_approved person, comp_id, [is_gw]            (consumed by jobSpecs.php over HTTPS)
 *
 * GW (is_gw=1) are a second target population: identity is
 * (monthly_assign_gw_code, comp_id 1) and the reporting line comes from
 * evaluation.monthly_assign_gw (boss_id/boss_comp_id) instead of
 * organization_chart. Both go through the StaffDirectory interface, so the
 * permission rules below are written once and behave identically for each.
 *
 * Auto-approval rule: a submission auto-approves iff there
 * is NO eligible approver OTHER THAN the submitter — i.e. the submitter is the
 * target's sole current direct superior, OR the target has no direct superior
 * at all. Otherwise it stays 'pending' for a *different* direct superior.
 *
 * SECURITY NOTE (called out, not silently accepted): this stack has no server
 * session/token — exactly like staffHierarchy.php. Submitter/reviewer identity
 * is client-asserted; the server re-verifies only the RELATIONSHIP (self /
 * current direct superior) against organization_chart, never a client 'editable'
 * flag. Bolt real auth onto every action here the moment the app has a token.
 *
 * PHP 7.2 compatible.
 */
 
/* ---------- DB ------------------------------------------------------------ */
 
class DbFactory
{
    private static $pool = array();
 
    /** $cfg = ['host','db','user','pass'(,'port')]; one reused PDO per (host,db). */
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
 
class Config
{
    /** Versions + approval. Local to this host. */
    public static function mkPortal()
    {
        return array(
            'host' => 'localhost',
            'db'   => 'mkPortal',
            'user' => 'prog4',
            'pass' => 'prog42023',
        );
    }
 
    /** organization_chart + users, same box staffHierarchy.php reads. Local. */
    public static function staffPortal()
    {
        return array(
            'host' => 'localhost',
            'db'   => 'staff_portal2',
            'user' => 'prog4',
            'pass' => 'prog42023',
        );
    }
 
    /** App Tracker's `application` DB. Same host, but a DISTINCT mysqld on 33060
     *  (identical to app_implementation.php's DB::app()). The job_spec_version_*
     *  tables live on 3306, so app titles can't be JOINed — they're resolved via
     *  this second connection instead. */
    public static function appDb()
    {
        return array(
            'host' => 'localhost',
            'port' => '33060',
            'db'   => 'application',
            'user' => 'prog4',
            'pass' => 'prog42023',
        );
    }
}
 
/* ---------- org chart (permission source of truth) ------------------------ */
 
/**
 * A target population and its reporting lines. Two implementations, chosen by
 * the version's is_gw flag: staff answer to organization_chart, GW answer to
 * monthly_assign_gw. Every permission decision goes through this interface, so
 * the rules (self / current direct superior / sole-approver auto-approve) are
 * written once and apply identically to both.
 */
interface StaffDirectory
{
    public function staffExists($person, $comp);
    public function isDirectSuperior($bossP, $bossC, $staffP, $staffC);
    public function countOtherDirectSuperiors($staffP, $staffC, $exclP, $exclC);
    public function directSubordinates($bossP, $bossC);
    public function namesFor(array $pairs);
}

class OrgChartRepository implements StaffDirectory
{
    private $db;
 
    /** Assignment is live today. Empty / NULL bounds mean "unbounded".
     *  Copied verbatim from staffHierarchy.php so the two agree exactly. */
    const ACTIVE_CLAUSE = "
        (oc.monthly_assign_from IS NULL OR oc.monthly_assign_from = ''
            OR STR_TO_DATE(REPLACE(oc.monthly_assign_from, '-', '/'), '%Y/%m/%d') <= CURDATE())
        AND (oc.monthly_assign_to IS NULL OR oc.monthly_assign_to = ''
            OR STR_TO_DATE(REPLACE(oc.monthly_assign_to, '-', '/'), '%Y/%m/%d') >= CURDATE())";
 
    /** Visible to MK Command at all. mk_command_excluded = 1 hides a user
     *  everywhere in this app; NULL means never set, which is not excluded. */
    const USER_VISIBLE = "u.status = 1 AND IFNULL(u.deleted, 0) = 0
                          AND IFNULL(u.mk_command_excluded, 0) <> 1";
 
    /** An excluded boss cannot open MK Command, so they can neither approve nor
     *  count as an alternative approver. Without this a version would go pending
     *  and wait forever on someone who can never see it. */
    const BOSS_VISIBLE = "
        NOT EXISTS (SELECT 1 FROM users ubx
                    WHERE ubx.person = oc.boss_id AND ubx.comp_id = oc.boss_comp_id
                      AND IFNULL(ubx.mk_command_excluded, 0) = 1)";
 
    /** Comp 1's reporting lines live in organization_chart_glob; every other
     *  company stays in organization_chart. Same rule as staffHierarchy.php:
     *  routing is on the STAFF-side comp_id (the oc.comp_id column). */
    const GLOB_COMP_ID = 1;
 
    /** The staff's comp is known -> exactly one chart owns their rows. */
    private static function chartFor($staffComp)
    {
        return ((int)$staffComp === self::GLOB_COMP_ID)
            ? 'organization_chart_glob'
            : 'organization_chart';
    }
 
    /** A boss's comp says nothing about their children's comp, so a boss-first
     *  read hits both charts. Scopes are disjoint -> UNION ALL cannot
     *  double-count, and stray comp-1 rows in the old table are ignored. */
    private static function chartSources()
    {
        return array(
            array('organization_chart',      'oc.comp_id <> ' . self::GLOB_COMP_ID),
            array('organization_chart_glob', 'oc.comp_id = '  . self::GLOB_COMP_ID),
        );
    }
 
    public function __construct(PDO $db) { $this->db = $db; }
 
    /** Active, non-deleted, non-excluded staff exists? */
    public function staffExists($person, $comp)
    {
        $sql = "SELECT 1 FROM users u
                WHERE u.person = :p AND u.comp_id = :c
                  AND " . self::USER_VISIBLE . "
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':p', $person, PDO::PARAM_STR);
        $stmt->bindValue(':c', (int)$comp, PDO::PARAM_INT);
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }
 
    /** Is (boss) a CURRENT direct superior of (staff)? */
    public function isDirectSuperior($bossP, $bossC, $staffP, $staffC)
    {
        $table = self::chartFor($staffC);
        $sql = "SELECT 1 FROM {$table} oc
                WHERE oc.staff_id = :sp AND oc.comp_id = :sc
                  AND oc.boss_id = :bp AND oc.boss_comp_id = :bc
                  AND " . self::ACTIVE_CLAUSE . "
                  AND " . self::BOSS_VISIBLE . "
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':sp', $staffP, PDO::PARAM_STR);
        $stmt->bindValue(':sc', (int)$staffC, PDO::PARAM_INT);
        $stmt->bindValue(':bp', $bossP, PDO::PARAM_STR);
        $stmt->bindValue(':bc', (int)$bossC, PDO::PARAM_INT);
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }
 
    /** Count CURRENT direct superiors of (staff), excluding one boss.
     *  0 => the excluded boss is the sole superior (or none exist). */
    public function countOtherDirectSuperiors($staffP, $staffC, $exclP, $exclC)
    {
        $table = self::chartFor($staffC);
        $sql = "SELECT COUNT(*) FROM {$table} oc
                WHERE oc.staff_id = :sp AND oc.comp_id = :sc
                  AND NOT (oc.boss_id = :ep AND oc.boss_comp_id = :ec)
                  AND " . self::ACTIVE_CLAUSE . "
                  AND " . self::BOSS_VISIBLE;
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':sp', $staffP, PDO::PARAM_STR);
        $stmt->bindValue(':sc', (int)$staffC, PDO::PARAM_INT);
        $stmt->bindValue(':ep', $exclP, PDO::PARAM_STR);
        $stmt->bindValue(':ec', (int)$exclC, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }
 
    /** Current direct subordinates (person + comp) of a boss — for the queue. */
    public function directSubordinates($bossP, $bossC)
    {
        $branches = array();
        foreach (self::chartSources() as $source) {
            list($table, $scope) = $source;
            $branches[] = "SELECT oc.staff_id AS person, oc.comp_id AS comp_id
                FROM {$table} oc
                INNER JOIN users u
                    ON u.person = oc.staff_id AND u.comp_id = oc.comp_id
                   AND " . self::USER_VISIBLE . "
                WHERE {$scope}
                  AND oc.boss_id = ? AND oc.boss_comp_id = ?
                  AND " . self::ACTIVE_CLAUSE;
        }
 
        // Positional binds: the same boss pair is supplied once per branch.
        $stmt = $this->db->prepare(implode("\nUNION ALL\n", $branches));
        $i = 1;
        $branchCount = count($branches);
        for ($b = 0; $b < $branchCount; $b++) {
            $stmt->bindValue($i++, $bossP, PDO::PARAM_STR);
            $stmt->bindValue($i++, (int)$bossC, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }
 
    /** Full names for a set of (person, comp_id) pairs. Returns "person|comp" => fullname. */
    public function namesFor(array $pairs)
    {
        if (empty($pairs)) {
            return array();
        }
        $tuples = implode(',', array_fill(0, count($pairs), '(?, ?)'));
        $sql = "SELECT u.person, u.comp_id, u.fullname FROM users u
                WHERE (u.person, u.comp_id) IN ({$tuples})
                  AND " . self::USER_VISIBLE;
        $stmt = $this->db->prepare($sql);
        $i = 1;
        foreach ($pairs as $p) {
            $stmt->bindValue($i++, $p['person'], PDO::PARAM_STR);
            $stmt->bindValue($i++, (int)$p['comp_id'], PDO::PARAM_INT);
        }
        $stmt->execute();
        $map = array();
        foreach ($stmt->fetchAll() as $r) {
            $map[$r['person'] . '|' . (int)$r['comp_id']] = $r['fullname'];
        }
        return $map;
    }
}
 
/**
 * GW directory — evaluation.monthly_assign_gw.
 *
 * Reached through the SAME PDO as organization_chart (fully-qualified table
 * name), exactly like staffHierarchy.php reads evaluation2017. No second
 * connection. Requires SELECT on `evaluation`.
 *
 * Identity: person = monthly_assign_gw_code, comp_id = 1.
 * Superior: (boss_id, boss_comp_id) — the same pair staffHierarchy.php uses to
 * hang GW off a boss's node, so the tree, the queue and the approval check all
 * agree on who supervises a GW.
 */
class GwRepository implements StaffDirectory
{
    private $db;

    /** An excluded supervisor cannot open MK Command, so a GW reporting only to
     *  them would have no reachable approver. Same rule as OrgChartRepository. */
    const BOSS_VISIBLE = "
        NOT EXISTS (SELECT 1 FROM users ubx
                    WHERE ubx.person = g.boss_id AND ubx.comp_id = g.boss_comp_id
                      AND IFNULL(ubx.mk_command_excluded, 0) = 1)";
 
    /** Live, scorecard-included GW inside comp 1 only. Mirrors staffHierarchy.php. */
    const ACTIVE_CLAUSE = "
        g.comp_id = '1'
        AND g.monthly_assign_gw_status = '1'
        AND (g.kpi_scorecard_exclude IS NULL OR g.kpi_scorecard_exclude <> '1')";

    public function __construct(PDO $db) { $this->db = $db; }

    public function staffExists($person, $comp)
    {
        $sql = "SELECT 1 FROM evaluation.monthly_assign_gw g
                WHERE g.monthly_assign_gw_code = :p AND g.comp_id = :c
                  AND " . self::ACTIVE_CLAUSE . "
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':p', $person, PDO::PARAM_STR);
        $stmt->bindValue(':c', (string)(int)$comp, PDO::PARAM_STR); // varchar column
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }

    public function isDirectSuperior($bossP, $bossC, $staffP, $staffC)
    {
        $sql = "SELECT 1 FROM evaluation.monthly_assign_gw g
                WHERE g.monthly_assign_gw_code = :sp AND g.comp_id = :sc
                  AND g.boss_id = :bp AND g.boss_comp_id = :bc
                  AND " . self::ACTIVE_CLAUSE . "
                  AND " . self::BOSS_VISIBLE . "
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':sp', $staffP, PDO::PARAM_STR);
        $stmt->bindValue(':sc', (string)(int)$staffC, PDO::PARAM_STR);
        $stmt->bindValue(':bp', $bossP, PDO::PARAM_STR);
        $stmt->bindValue(':bc', (string)(int)$bossC, PDO::PARAM_STR);
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }

    public function countOtherDirectSuperiors($staffP, $staffC, $exclP, $exclC)
    {
        $sql = "SELECT COUNT(*) FROM evaluation.monthly_assign_gw g
                WHERE g.monthly_assign_gw_code = :sp AND g.comp_id = :sc
                  AND NOT (g.boss_id = :ep AND g.boss_comp_id = :ec)
                  AND " . self::ACTIVE_CLAUSE . "
                  AND " . self::BOSS_VISIBLE;
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':sp', $staffP, PDO::PARAM_STR);
        $stmt->bindValue(':sc', (string)(int)$staffC, PDO::PARAM_STR);
        $stmt->bindValue(':ep', $exclP, PDO::PARAM_STR);
        $stmt->bindValue(':ec', (string)(int)$exclC, PDO::PARAM_STR);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public function directSubordinates($bossP, $bossC)
    {
        $sql = "SELECT g.monthly_assign_gw_code AS person, g.comp_id AS comp_id
                FROM evaluation.monthly_assign_gw g
                WHERE g.boss_id = :bp AND g.boss_comp_id = :bc
                  AND " . self::ACTIVE_CLAUSE . "
                  AND " . self::BOSS_VISIBLE;
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':bp', $bossP, PDO::PARAM_STR);
        $stmt->bindValue(':bc', (string)(int)$bossC, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function namesFor(array $pairs)
    {
        if (empty($pairs)) {
            return array();
        }
        $tuples = implode(',', array_fill(0, count($pairs), '(?, ?)'));
        $sql = "SELECT g.monthly_assign_gw_code AS person, g.comp_id AS comp_id,
                       g.monthly_assign_gw_fullname AS fullname
                FROM evaluation.monthly_assign_gw g
                WHERE (g.monthly_assign_gw_code, g.comp_id) IN ({$tuples})
                  AND " . self::ACTIVE_CLAUSE;
        $stmt = $this->db->prepare($sql);
        $i = 1;
        foreach ($pairs as $p) {
            $stmt->bindValue($i++, $p['person'], PDO::PARAM_STR);
            $stmt->bindValue($i++, (string)(int)$p['comp_id'], PDO::PARAM_STR);
        }
        $stmt->execute();
        $map = array();
        foreach ($stmt->fetchAll() as $r) {
            $map[$r['person'] . '|' . (int)$r['comp_id']] = $r['fullname'];
        }
        return $map;
    }
}

/* ---------- application DB (app titles, separate mysqld) ------------------ */
 
class AppRepository
{
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }
 
    /** Batch: application_id => ['application_id','application_title','application_section'].
     *  Deactivated apps are still resolved so a stored association always renders. */
    public function titlesFor(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return array();
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT application_id, application_title, application_section
                FROM application
                WHERE application_id IN ({$ph})";
        $stmt = $this->db->prepare($sql);
        foreach ($ids as $i => $v) {
            $stmt->bindValue($i + 1, $v, PDO::PARAM_INT);
        }
        $stmt->execute();
        $map = array();
        foreach ($stmt->fetchAll() as $r) {
            $map[(int)$r['application_id']] = array(
                'application_id'      => (int)$r['application_id'],
                'application_title'   => $r['application_title'],
                'application_section' => $r['application_section'],
            );
        }
        return $map;
    }
}
 
/* ---------- mkPortal versions --------------------------------------------- */
 
class VersionRepository
{
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }
 
    /** Atomic: insert header + all snapshot items in one transaction. Returns id. */
    public function createVersion(array $h, array $items)
    {
        $this->db->beginTransaction();
        try {
            $sql = "INSERT INTO job_spec_version
                    (person, comp_id, is_gw, status, submitted_by, submitted_by_comp_id, submitted_at,
                     reviewed_by, reviewed_by_comp_id, reviewed_at, review_note)
                    VALUES
                    (:person, :comp_id, :is_gw, :status, :sb, :sbc, :sat, :rb, :rbc, :rat, :note)";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':person', $h['person'], PDO::PARAM_STR);
            $stmt->bindValue(':comp_id', (int)$h['comp_id'], PDO::PARAM_INT);
            $stmt->bindValue(':is_gw', (int)$h['is_gw'], PDO::PARAM_INT);
            $stmt->bindValue(':status', $h['status'], PDO::PARAM_STR);
            $stmt->bindValue(':sb', $h['submitted_by'], PDO::PARAM_STR);
            $stmt->bindValue(':sbc', (int)$h['submitted_by_comp_id'], PDO::PARAM_INT);
            $stmt->bindValue(':sat', $h['submitted_at'], PDO::PARAM_STR);
            $this->bindNullableStr($stmt, ':rb', $h['reviewed_by']);
            $this->bindNullableInt($stmt, ':rbc', $h['reviewed_by_comp_id']);
            $this->bindNullableStr($stmt, ':rat', $h['reviewed_at']);
            $this->bindNullableStr($stmt, ':note', $h['review_note']);
            $stmt->execute();
 
            $versionId = (int)$this->db->lastInsertId();
 
            $itemSql = "INSERT INTO job_spec_version_item
                        (version_id, sort_order, task, source_job_description_id)
                        VALUES (:vid, :so, :task, :src)";
            $istmt = $this->db->prepare($itemSql);
 
            $appSql = "INSERT INTO job_spec_version_item_app (item_id, application_id)
                       VALUES (:iid, :aid)";
            $astmt = $this->db->prepare($appSql);
 
            $i = 0;
            foreach ($items as $it) {
                $so = isset($it['sort_order']) && $it['sort_order'] !== '' ? (int)$it['sort_order'] : $i;
                $istmt->bindValue(':vid', $versionId, PDO::PARAM_INT);
                $istmt->bindValue(':so', $so, PDO::PARAM_INT);
                $istmt->bindValue(':task', $it['task'], PDO::PARAM_STR);
                if (isset($it['source_job_description_id'])
                    && $it['source_job_description_id'] !== ''
                    && $it['source_job_description_id'] !== null) {
                    $istmt->bindValue(':src', (int)$it['source_job_description_id'], PDO::PARAM_INT);
                } else {
                    $istmt->bindValue(':src', null, PDO::PARAM_NULL);
                }
                $istmt->execute();
 
                // Snapshot this item's app associations (part of the immutable version).
                $itemId = (int)$this->db->lastInsertId();
                $appIds = isset($it['apps']) && is_array($it['apps']) ? $it['apps'] : array();
                foreach (array_unique(array_filter(array_map('intval', $appIds))) as $aid) {
                    $astmt->bindValue(':iid', $itemId, PDO::PARAM_INT);
                    $astmt->bindValue(':aid', $aid, PDO::PARAM_INT);
                    $astmt->execute();
                }
                $i++;
            }
 
            $this->db->commit();
            return $versionId;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
 
    /** The version header (for review gating). Null if not found. */
    public function findVersion($versionId)
    {
        $sql = "SELECT id, person, comp_id, IFNULL(is_gw, 0) AS is_gw,
                       status, submitted_by, submitted_by_comp_id
                FROM job_spec_version WHERE id = :id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', (int)$versionId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ?: null;
    }
 
    /** Guarded UPDATE (only if still pending). Returns affected row count. */
    public function markReviewed($versionId, $status, $rby, $rbyC, $note)
    {
        $sql = "UPDATE job_spec_version
                SET status = :status, reviewed_by = :rb, reviewed_by_comp_id = :rbc,
                    reviewed_at = NOW(), review_note = :note
                WHERE id = :id AND status = 'pending'";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':rb', $rby, PDO::PARAM_STR);
        $stmt->bindValue(':rbc', (int)$rbyC, PDO::PARAM_INT);
        $this->bindNullableStr($stmt, ':note', $note);
        $stmt->bindValue(':id', (int)$versionId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }
 
    /** Newest approved version for a staff (keyed on person + comp). */
    public function latestApproved($person, $comp, $isGw)
    {
        $sql = "SELECT id, submitted_by, submitted_at, reviewed_by, reviewed_at
                FROM job_spec_version
                WHERE person = :p AND comp_id = :c AND IFNULL(is_gw, 0) = :g
                  AND status = 'approved'
                ORDER BY id DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':p', $person, PDO::PARAM_STR);
        $stmt->bindValue(':c', (int)$comp, PDO::PARAM_INT);
        $stmt->bindValue(':g', (int)$isGw, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ?: null;
    }
 
    /** Ordered snapshot items of a version, each with its associated app ids
     *  (['apps_ids' => int[]]). Titles are resolved later by the service, since
     *  the `application` table lives on a different mysqld. */
    public function versionItems($versionId)
    {
        $sql = "SELECT id, source_job_description_id AS job_description_id, task
                FROM job_spec_version_item
                WHERE version_id = :v
                ORDER BY sort_order ASC, id ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':v', (int)$versionId, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll();
        if (empty($items)) {
            return array();
        }
 
        // One query for every item's app links, grouped in PHP.
        $ids = array();
        foreach ($items as $it) {
            $ids[] = (int)$it['id'];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $lsql = "SELECT item_id, application_id
                 FROM job_spec_version_item_app
                 WHERE item_id IN ({$ph})
                 ORDER BY id ASC";
        $lstmt = $this->db->prepare($lsql);
        foreach ($ids as $i => $v) {
            $lstmt->bindValue($i + 1, $v, PDO::PARAM_INT);
        }
        $lstmt->execute();
 
        $byItem = array();
        foreach ($lstmt->fetchAll() as $r) {
            $byItem[(int)$r['item_id']][] = (int)$r['application_id'];
        }
 
        $out = array();
        foreach ($items as $it) {
            $itemId = (int)$it['id'];
            unset($it['id']); // internal PK — not part of the public item shape
            $it['apps_ids'] = isset($byItem[$itemId]) ? $byItem[$itemId] : array();
            $out[] = $it;
        }
        return $out;
    }
 
    /** Pending versions for a set of targets.
     *  $targets: [['person','comp_id','is_gw'],...] — is_gw is part of the key,
     *  because a GW code and a users.person can be the same string in comp 1. */
    public function pendingForTargets(array $targets)
    {
        if (empty($targets)) {
            return array();
        }
        $tuples = implode(',', array_fill(0, count($targets), '(?, ?, ?)'));
        $sql = "SELECT id, person, comp_id, IFNULL(is_gw, 0) AS is_gw,
                       submitted_by, submitted_by_comp_id, submitted_at
                FROM job_spec_version
                WHERE status = 'pending'
                  AND (person, comp_id, IFNULL(is_gw, 0)) IN ({$tuples})
                ORDER BY submitted_at ASC, id ASC";
        $stmt = $this->db->prepare($sql);
        $i = 1;
        foreach ($targets as $t) {
            $stmt->bindValue($i++, $t['person'], PDO::PARAM_STR);
            $stmt->bindValue($i++, (int)$t['comp_id'], PDO::PARAM_INT);
            $stmt->bindValue($i++, (int)$t['is_gw'], PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }
 
    private function bindNullableStr($stmt, $name, $value)
    {
        if ($value === null || $value === '') {
            $stmt->bindValue($name, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($name, $value, PDO::PARAM_STR);
        }
    }
 
    private function bindNullableInt($stmt, $name, $value)
    {
        if ($value === null || $value === '') {
            $stmt->bindValue($name, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($name, (int)$value, PDO::PARAM_INT);
        }
    }
}
 
/* ---------- service (permission + command/query logic) -------------------- */
 
class JobSpecWriteService
{
    private $org;
    private $gw;
    private $ver;
    private $apps;
 
    public function __construct(
        OrgChartRepository $org,
        GwRepository $gw,
        VersionRepository $ver,
        AppRepository $apps
    ) {
        $this->org  = $org;
        $this->gw   = $gw;
        $this->ver  = $ver;
        $this->apps = $apps;
    }
 
    /** The reporting-line source for a target. Every permission rule below is
     *  written once and reads whichever directory the target belongs to. */
    private function directory($isGw)
    {
        return $isGw ? $this->gw : $this->org;
    }
 
    /** Replace each item's raw 'apps_ids' with a resolved 'apps' list
     *  ([{application_id, application_title, application_section}]). One batched
     *  title lookup for the whole version. Shared by every read path (DRY). */
    private function withAppTitles(array $items)
    {
        $all = array();
        foreach ($items as $it) {
            if (!empty($it['apps_ids'])) {
                foreach ($it['apps_ids'] as $id) {
                    $all[] = $id;
                }
            }
        }
        $titles = $this->apps->titlesFor($all);
 
        $out = array();
        foreach ($items as $it) {
            $apps = array();
            $ids = isset($it['apps_ids']) ? $it['apps_ids'] : array();
            unset($it['apps_ids']);
            foreach ($ids as $id) {
                if (isset($titles[(int)$id])) {
                    $apps[] = $titles[(int)$id];
                }
            }
            $it['apps'] = $apps;
            $out[] = $it;
        }
        return $out;
    }
 
    /** Command: create a new snapshot version. Returns ['version_id','status']. */
    public function submit($person, $comp, $isGw, $subP, $subC, array $items)
    {
        $dir = $this->directory($isGw);
        if (!$dir->staffExists($person, $comp)) {
            throw new RuntimeException('TARGET_NOT_FOUND');
        }
        if (empty($items)) {
            throw new RuntimeException('EMPTY_ITEMS');
        }
 
        // A GW has no portal login, so a GW spec is always submitted by someone
        // else — only their monthly_assign_gw superior qualifies.
        $isSelf = (!$isGw && $subP === $person && (int)$subC === (int)$comp);
        if (!$isSelf && !$dir->isDirectSuperior($subP, $subC, $person, $comp)) {
            throw new RuntimeException('NOT_ALLOWED_TO_SUBMIT');
        }
 
        // Auto-approve iff no eligible approver other than the submitter.
        // Self-submit: submitter isn't their own boss, so this counts ALL
        //   superiors -> 0 only when the person is the org head.
        // Superior-submit: excludes the submitting boss -> 0 only when sole boss.
        $others = $dir->countOtherDirectSuperiors($person, $comp, $subP, $subC);
        $auto = ($others === 0);
        $now = date('Y-m-d H:i:s');
 
        $h = array(
            'person'               => $person,
            'comp_id'              => (int)$comp,
            'is_gw'                => $isGw ? 1 : 0,
            'status'               => $auto ? 'approved' : 'pending',
            'submitted_by'         => $subP,
            'submitted_by_comp_id' => (int)$subC,
            'submitted_at'         => $now,
            'reviewed_by'          => $auto ? $subP : null,
            'reviewed_by_comp_id'  => $auto ? (int)$subC : null,
            'reviewed_at'          => $auto ? $now : null,
            'review_note'          => $auto ? 'Auto-approved (sole approver)' : null,
        );
 
        $versionId = $this->ver->createVersion($h, $items);
        return array('version_id' => $versionId, 'status' => $h['status']);
    }
 
    /** Command: approve/reject a pending version. Returns ['version_id','status']. */
    public function review($versionId, $action, $rP, $rC, $note)
    {
        $v = $this->ver->findVersion($versionId);
        if (!$v) {
            throw new RuntimeException('VERSION_NOT_FOUND');
        }
        if ($v['status'] !== 'pending') {
            throw new RuntimeException('ALREADY_' . strtoupper($v['status']));
        }
        // GW route through monthly_assign_gw, staff through organization_chart.
        if (!$this->directory((int)$v['is_gw'])->isDirectSuperior($rP, $rC, $v['person'], $v['comp_id'])) {
            throw new RuntimeException('NOT_A_DIRECT_SUPERIOR');
        }
        // A pending version, by construction, has an approver other than its
        // submitter — the submitter never reviews their own.
        if ($rP === $v['submitted_by'] && (int)$rC === (int)$v['submitted_by_comp_id']) {
            throw new RuntimeException('SUBMITTER_CANNOT_REVIEW_OWN');
        }
 
        $status = ($action === 'approve') ? 'approved' : 'rejected';
        $affected = $this->ver->markReviewed($versionId, $status, $rP, $rC, $note);
        if ($affected === 0) {
            // Lost a race: someone else actioned it between read and update.
            throw new RuntimeException('VERSION_NOT_PENDING');
        }
        return array('version_id' => (int)$versionId, 'status' => $status);
    }
 
    /** Query: latest approved version + items for a staff or GW. */
    public function latestApproved($person, $comp, $isGw)
    {
        $v = $this->ver->latestApproved($person, $comp, $isGw);
        if (!$v) {
            return array('version' => null, 'job_specs' => array());
        }
        return array(
            'version'   => array(
                'id'           => (int)$v['id'],
                'submitted_by' => $v['submitted_by'],
                'submitted_at' => $v['submitted_at'],
                'reviewed_by'  => $v['reviewed_by'],
                'reviewed_at'  => $v['reviewed_at'],
            ),
            'job_specs' => $this->withAppTitles($this->ver->versionItems($v['id'])),
        );
    }
 
    /** Query: pending versions across a superior's current direct subordinates,
     *  enriched with names and the proposed task list so they're reviewable. */
    public function pendingForSuperior($bossP, $bossC)
    {
        // One queue, both populations: direct reports from organization_chart
        // and GW from monthly_assign_gw. is_gw is carried so the version lookup
        // and the name lookup each hit the right table.
        $targets = array();
        foreach ($this->org->directSubordinates($bossP, $bossC) as $s) {
            $targets[] = array('person' => $s['person'], 'comp_id' => (int)$s['comp_id'], 'is_gw' => 0);
        }
        foreach ($this->gw->directSubordinates($bossP, $bossC) as $s) {
            $targets[] = array('person' => $s['person'], 'comp_id' => (int)$s['comp_id'], 'is_gw' => 1);
        }
 
        $headers = $this->ver->pendingForTargets($targets);
        if (empty($headers)) {
            return array();
        }
 
        // Batched name lookups: targets from their own directory, submitters
        // always from organization_chart (only staff accounts can submit).
        $staffPairs = array();
        $gwPairs    = array();
        foreach ($headers as $h) {
            $key  = $h['person'] . '|' . (int)$h['comp_id'];
            $pair = array('person' => $h['person'], 'comp_id' => (int)$h['comp_id']);
            if ((int)$h['is_gw'] === 1) {
                $gwPairs[$key] = $pair;
            } else {
                $staffPairs[$key] = $pair;
            }
            $staffPairs[$h['submitted_by'] . '|' . (int)$h['submitted_by_comp_id']] =
                array('person' => $h['submitted_by'], 'comp_id' => (int)$h['submitted_by_comp_id']);
        }
        $names = $this->org->namesFor(array_values($staffPairs))
               + $this->gw->namesFor(array_values($gwPairs));
 
        $out = array();
        foreach ($headers as $h) {
            $pKey = $h['person'] . '|' . (int)$h['comp_id'];
            $sKey = $h['submitted_by'] . '|' . (int)$h['submitted_by_comp_id'];
            $out[] = array(
                'version_id'           => (int)$h['id'],
                'person'               => $h['person'],
                'comp_id'              => (int)$h['comp_id'],
                'is_gw'                => (int)$h['is_gw'],
                'person_name'          => isset($names[$pKey]) ? $names[$pKey] : $h['person'],
                'submitted_by'         => $h['submitted_by'],
                'submitted_by_comp_id' => (int)$h['submitted_by_comp_id'],
                'submitted_by_name'    => isset($names[$sKey]) ? $names[$sKey] : $h['submitted_by'],
                'submitted_at'         => $h['submitted_at'],
                'job_specs'            => $this->withAppTitles($this->ver->versionItems($h['id'])),
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
 
/** Decode the items payload (JSON array of {task, [source_job_description_id], [sort_order]}). */
function parseItems($raw)
{
    if ($raw === null || $raw === '') {
        throw new RuntimeException('EMPTY_ITEMS');
    }
    $items = json_decode($raw, true);
    if (!is_array($items) || empty($items)) {
        throw new RuntimeException('BAD_ITEMS');
    }
    $clean = array();
    foreach ($items as $it) {
        if (!is_array($it) || !isset($it['task']) || trim($it['task']) === '') {
            throw new RuntimeException('BAD_ITEMS');
        }
        $apps = array();
        if (isset($it['apps']) && is_array($it['apps'])) {
            $apps = array_values(array_unique(array_filter(array_map('intval', $it['apps']))));
        }
        $clean[] = array(
            'task'                      => trim($it['task']),
            'source_job_description_id' => isset($it['source_job_description_id']) ? $it['source_job_description_id'] : null,
            'sort_order'                => isset($it['sort_order']) ? $it['sort_order'] : null,
            'apps'                      => $apps,
        );
    }
    return $clean;
}
 
/** Map a domain error string to (message, http status). */
function errorStatus($code)
{
    $map = array(
        'TARGET_NOT_FOUND'          => array('Staff not found or inactive', 404),
        'VERSION_NOT_FOUND'         => array('Version not found', 404),
        'EMPTY_ITEMS'               => array('At least one task is required', 400),
        'BAD_ITEMS'                 => array('Malformed items payload', 400),
        'NOT_ALLOWED_TO_SUBMIT'     => array('You may only submit your own spec or a direct subordinate\'s', 403),
        'NOT_A_DIRECT_SUPERIOR'     => array('Only a current direct superior can review this', 403),
        'SUBMITTER_CANNOT_REVIEW_OWN' => array('The submitter cannot review their own version', 403),
        'VERSION_NOT_PENDING'       => array('This version is no longer pending', 409),
    );
    if (isset($map[$code])) {
        return $map[$code];
    }
    if (strpos($code, 'ALREADY_') === 0) {
        return array('This version was already ' . strtolower(substr($code, 8)), 409);
    }
    return array('System error occurred', 500);
}
 
try {
    $action = input('action');
 
    // GW share the staff_portal2 connection: evaluation is on the same mysqld
    // and is reached by fully-qualified table name (as staffHierarchy.php does).
    $staffPdo = DbFactory::get(Config::staffPortal());
    $org  = new OrgChartRepository($staffPdo);
    $gw   = new GwRepository($staffPdo);
    $ver  = new VersionRepository(DbFactory::get(Config::mkPortal()));
    $apps = new AppRepository(DbFactory::get(Config::appDb()));
    $svc  = new JobSpecWriteService($org, $gw, $ver, $apps);
 
    if ($action === 'submit') {
        $person = input('person');
        $comp   = input(array('comp_id', 'comid'));
        $subP   = input(array('submitter', 'submitted_by'));
        $subC   = input(array('submitter_comp_id', 'submitted_by_comp_id'));
        $isGw   = (int)input('is_gw') === 1;
        if ($person === null || $comp === null || $subP === null || $subC === null) {
            respond(array('success' => false, 'message' => 'person, comp_id, submitter, submitter_comp_id are required'), 400);
        }
        $items  = parseItems(input('items'));
        $result = $svc->submit($person, $comp, $isGw, $subP, $subC, $items);
        respond(array('success' => true) + $result);
    }
 
    if ($action === 'approve' || $action === 'reject') {
        $versionId = input('version_id');
        $rP        = input(array('reviewer', 'reviewed_by'));
        $rC        = input(array('reviewer_comp_id', 'reviewed_by_comp_id'));
        $note      = input('note');
        if ($versionId === null || $rP === null || $rC === null) {
            respond(array('success' => false, 'message' => 'version_id, reviewer, reviewer_comp_id are required'), 400);
        }
        $result = $svc->review($versionId, $action, $rP, $rC, $note);
        respond(array('success' => true) + $result);
    }
 
    if ($action === 'pending') {
        $bP = input(array('boss', 'person'));
        $bC = input(array('boss_comp_id', 'comp_id', 'comid'));
        if ($bP === null || $bC === null) {
            respond(array('success' => false, 'message' => 'boss and boss_comp_id are required'), 400);
        }
        respond(array('success' => true, 'pending' => $svc->pendingForSuperior($bP, $bC)));
    }
 
    if ($action === 'latest_approved') {
        $person = input('person');
        $comp   = input(array('comp_id', 'comid'));
        if ($person === null || $comp === null) {
            respond(array('success' => false, 'message' => 'person and comp_id are required'), 400);
        }
        $r = $svc->latestApproved($person, $comp, (int)input('is_gw') === 1);
        respond(array('success' => true, 'version' => $r['version'], 'job_specs' => $r['job_specs']));
    }
 
    respond(array('success' => false, 'message' => 'Unknown action'), 400);
 
} catch (RuntimeException $e) {
    $es = errorStatus($e->getMessage());
    respond(array('success' => false, 'message' => $es[0]), $es[1]);
} catch (Exception $e) {
    error_log('jobSpecWrite error: ' . $e->getMessage());
    $out = array('success' => false, 'message' => 'System error occurred');
    if (input('debug') === '1') {
        $out['debug'] = $e->getMessage();
    }
    respond($out, 500);
}