<?php
/**
 * STAFF POOL TREE — the reporting line drawn over the staff pool.
 *
 * staffPoolAPI.php includes this, and only when a tree was actually asked for.
 * staffHeadcountReport.php does not include it at all: a head count has no
 * reporting line in it, so nothing in this file can move a single figure on
 * that report, and a request without staff_tree / staff_tree_individual is
 * answered by exactly the code that answered it before this file existed.
 *
 * WHO IS IN THE TREE — THE POOL, AND NOBODY ELSE
 *   Every node is a row staffPoolCore.php already returned, published through
 *   publicRow(), so a node and a row in staff[] are the same shape with the
 *   same values. The reporting-line tables are read for EDGES ONLY: an edge
 *   naming somebody the pool did not list is dropped, never followed. The tree
 *   therefore cannot show a person the flat list does not, and the two cannot
 *   disagree about anybody's name, company, department or flags.
 *
 *   That includes people an override has taken OUT of the pool: they keep
 *   total_staff = 0 and they keep their place in the line. Cutting them out
 *   would orphan everybody under them, and a tick box about head counting was
 *   never a statement about who reports to whom.
 *
 * WHERE THE LINE COMES FROM — the same tables staffDirectory.php reads, routed
 * the same way on the STAFF-side comp_id, so the two draw the same chart:
 *   comp 1        staff_portal2.organization_chart_glob
 *   every other   evaluation.monthly_assign
 *   GW            evaluation.monthly_assign_gw (boss_id, boss_comp_id)
 *   the sections  staff_portal2.department_head, both levels
 * monthly_assign keeps history, so every read of it is DISTINCT — the same
 * edge written for six months running is one edge, not six.
 *
 * WHAT IT ANSWERS — two shapes, and only for the companies supportedComps()
 * names (Globinaco today):
 *
 *   staff_tree=all               TWO FLAT LISTS of managers, no tree at all:
 *                                  hod[]  one entry per department      (16)
 *                                  am[]   one entry per sub-department  (18)
 *                                An entry is the manager and their unit, with
 *                                how many people are under them — and NOT one
 *                                of those people. See sections().
 *   staff_tree_individual=x      that person and everybody under them,
 *                                nested. This is the only shape that nests.
 *
 *   Both also carry ACCESS — which departments somebody may SEE. It is a
 *   separate statement from the reporting line and moves nobody in it; see
 *   departmentAccess(). The line itself is now exactly what the chart and
 *   department_head say, with no hand-written edges at all.
 *
 * ONE PERSON, ONE PLACE — WITHIN A SINGLE TREE
 *   The chart is not a tree. 16 of Globinaco's general workers are assigned
 *   under two superiors; across the whole group 82 people are under more than
 *   one. Inside ONE tree, a person is drawn once, on their shortest line down
 *   from that tree's root, and the superiors that did not get them are listed
 *   on the node as also_reports_to. So a tree's totals always add up to what
 *   is in it.
 *
 *   The flat lists count differently and say so: their two figures are read
 *   off the chart, so they are the size of that manager's real org rather
 *   than of anything in the answer.
 *
 * CYCLES are impossible to walk into — within a tree a person already placed
 *   is never placed again — so a ring in the data is walked once and left.
 *
 * MUST be hosted on globportal.com beside staffPoolAPI.php: staff_portal2 and
 * evaluation are both local there under prog4.
 *
 * Read-only. Every query in this file is a SELECT.
 *
 * PHP 7.2 compatible.
 */

/**
 * All reporting-line reads. Nothing here knows about HTTP or about the pool.
 */
class StaffTreeRepository
{
    /**
     * Where a company's reporting line lives — staffDirectory.php's split,
     * repeated here because the two files must agree about it.
     *
     * Comp 1 keeps its own chart in staff_portal2.organization_chart_glob, as
     * manpower_summary.php walks it. Every other company's line is
     * evaluation.monthly_assign, the table their supervisors actually
     * maintain. staff_portal2.organization_chart is not consulted for anyone.
     *
     * Both tables carry the same columns (staff_id, comp_id, boss_id,
     * boss_comp_id, monthly_assign_from, monthly_assign_to), so each is
     * aliased `oc` and one read serves both.
     */
    const GLOB_COMP_ID     = 1;
    const GLOB_CHART_TABLE = 'staff_portal2.organization_chart_glob';
    const ASSIGN_TABLE     = 'evaluation.monthly_assign';

    /** Assignment is live today. Empty / NULL bounds mean "unbounded" — the
     *  same clause staffDirectory.php applies, dates and all. */
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

    /** An IN list of company ids. They are ints before they get here — cast
     *  again anyway — so they inline safely, which is what lets one query
     *  cover a whole group instead of one per company. */
    private static function idList(array $comps)
    {
        $ids = array();
        foreach ($comps as $c) {
            $ids[(int)$c] = (int)$c;
        }
        return implode(',', $ids);
    }

    /**
     * Query: the live staff reporting edges for these companies, as
     * staff_id / comp_id / boss_id / boss_comp_id.
     *
     * Read by the STAFF side, which is how the routing above is defined: an
     * edge belongs to the table its CHILD's company uses, whatever company the
     * boss is in. A boss at another company is still returned, and the caller
     * drops the edge if that boss is outside the pool it asked for.
     */
    public function staffEdges(array $comps)
    {
        $glob   = array();
        $assign = array();
        foreach ($comps as $c) {
            if ((int)$c === self::GLOB_COMP_ID) {
                $glob[] = (int)$c;
            } else {
                $assign[] = (int)$c;
            }
        }

        $out = array();
        if (!empty($glob)) {
            $out = array_merge($out, $this->edges(self::GLOB_CHART_TABLE, $glob));
        }
        if (!empty($assign)) {
            $out = array_merge($out, $this->edges(self::ASSIGN_TABLE, $assign));
        }
        return $out;
    }

    /** DISTINCT for monthly_assign's history: the same reporting line written
     *  month after month is one edge. An empty boss_id is not an edge — comp 2
     *  has rows like that — and leaving them in would make a person their own
     *  root under a boss who is nobody. */
    private function edges($table, array $comps)
    {
        $sql = "SELECT DISTINCT oc.staff_id, oc.comp_id, oc.boss_id, oc.boss_comp_id
                  FROM {$table} oc
                 WHERE oc.comp_id IN (" . self::idList($comps) . ")
                   AND oc.boss_id IS NOT NULL AND TRIM(oc.boss_id) <> ''
                   AND " . self::ACTIVE_CLAUSE;
        return $this->db->query($sql)->fetchAll();
    }

    /**
     * Query: the live GW reporting edges, in the same four columns.
     *
     * monthly_assign_gw carries no date bounds — there is nothing to apply
     * ACTIVE_CLAUSE to — so monthly_assign_gw_status is the whole test, which
     * is what staffDirectory.php tests too.
     *
     * DISTINCT, and for the same reason the pool groups by code: that table
     * holds a row per GW per reporting line, so a worker under two superiors
     * is in it twice. Here that is two edges and the walk places them once.
     *
     * kpi_scorecard_exclude is deliberately NOT tested. Who EXISTS is the
     * pool's question and it has already answered it; this read only answers
     * who reports to whom, and a second opinion about existence here could
     * only disagree with the flat list.
     *
     * comp_id is VARCHAR on this table, but every id in it is numeric and
     * MySQL compares the two sides numerically, so the inlined int list is
     * still the right filter.
     */
    public function gwEdges(array $comps)
    {
        $ids = array();
        foreach ($comps as $c) {
            if (in_array((int)$c, StaffPoolRepository::gwComps(), true)) {
                $ids[] = (int)$c;
            }
        }
        if (empty($ids)) {
            return array();
        }

        $sql = "SELECT DISTINCT g.monthly_assign_gw_code AS staff_id,
                       g.comp_id, g.boss_id, g.boss_comp_id
                  FROM evaluation.monthly_assign_gw g
                 WHERE g.monthly_assign_gw_status = '1'
                   AND IFNULL(g.test_user, 0) <> 1
                   AND g.comp_id IN (" . self::idList($ids) . ")
                   AND g.boss_id IS NOT NULL AND TRIM(g.boss_id) <> ''";
        return $this->db->query($sql)->fetchAll();
    }

    /**
     * Query: every live department and its head.
     *
     * Only comp 1 has rows here — all 40 of them — so for every other company
     * this returns nothing. It is read by company rather than pinned to comp 1
     * so that a company given department_head rows later needs no change here.
     *
     * ALL live rows, both levels. The two levels ARE the two sections this
     * endpoint publishes:
     *   top (parent_id empty)  the departments        -> hod[]
     *   the rest               their sub-departments  -> am[]
     * and `top` is what tells them apart. A row also gives its head a
     * hod_department_name, which is a label on a PERSON and so spans both
     * levels — an area manager heads a department too.
     *
     * is_trillium marks the departments belonging to Trillium Group, which the
     * pool reports as its own company. They are carried out of here rather
     * than filtered in SQL so the caller can tell a Trillium department from
     * one whose head has simply left — see sections().
     *
     * Ordered the way the org navigator shows them: the departments in their
     * own order first, then each one's sub-departments grouped under it.
     */
    public function departmentHeads(array $comps)
    {
        if (empty($comps)) {
            return array();
        }
        $top = "(dh.parent_id IS NULL OR dh.parent_id = '')";
        $sql = "SELECT dh.id, dh.parent_id, dh.staff_id, dh.comp_id, dh.dept_name,
                       IFNULL(dh.is_trillium, 0) AS is_trillium,
                       {$top} AS top
                  FROM staff_portal2.department_head dh
                 WHERE dh.status = '1'
                   AND dh.comp_id IN (" . self::idList($comps) . ")
                   AND dh.staff_id IS NOT NULL AND TRIM(dh.staff_id) <> ''
                 ORDER BY {$top} DESC,
                          CAST(dh.parent_id AS UNSIGNED) ASC,
                          CAST(dh.`order` AS UNSIGNED) ASC,
                          dh.dept_name ASC";
        return $this->db->query($sql)->fetchAll();
    }
}

/**
 * The tree itself: pool rows in, nested publicRow() nodes out.
 *
 * Built once per request over the rows staffPoolAPI.php has already loaded —
 * no second read of users or monthly_assign_gw — and then asked for either the
 * whole thing or one person's branch.
 */
class StaffPoolTree
{
    /**
     * THE ONE COMPANY THIS ENDPOINT DRAWS, for now.
     *
     * The sections below are Globinaco's shape: departments and their
     * sub-departments, out of department_head, which is the only company with
     * rows in that table. Asking for any other company is refused rather than
     * answered with two empty lists, which would read as "this company has no
     * managers" instead of "this is not built yet".
     *
     * When another company gets department_head rows, add its id here and
     * nothing else in this file needs to change.
     */
    public static function supportedComps()
    {
        return array(1);
    }

    /**
     * The person above every department, when there is one.
     *
     * EMPTY ON PURPOSE. Globinaco's chart does have a CEO above the
     * departments, but he is not wanted in this answer: the two sections are
     * the departments and their sub-departments, and a single root above them
     * adds a level without adding a fact. Put a login back in here and every
     * department head with no boss of their own is hung off that person again,
     * exactly as before.
     *
     * Nothing else depends on it. He is still drawn wherever the CHART puts
     * him — this only decides whether the departments are hung off him.
     */
    const MANPOWER_TOP_PERSON  = '';
    const MANPOWER_TOP_COMP_ID = 1;

    /**
     * PLACEMENTS WRITTEN BY HAND — the two branches that are not in the data.
     *
     * department_head lists Workshop (leongwee) and Fleet (rinob) side by
     * side, and Management B (O&E) beside the four divisional heads. The
     * company is not run that way: Workshop sits under Fleet, and the four
     * divisions sit under Management B. Neither organization_chart_glob nor
     * department_head says so, and there is nothing to derive it from, so it
     * is said here — where it can be read and changed — rather than buried in
     * a query or left to whoever reads the endpoint to patch afterwards.
     *
     * Each entry MOVES THE WHOLE BRANCH: the child and everybody under them
     * follow, in staff_tree=all and in staff_tree_individual alike. So asking
     * for rinob returns leongwee's people as well, and asking for sooCK
     * returns all four divisions and theirs.
     *
     * A hand-written parent WINS over the chart. If one of these children is
     * later given a boss in organization_chart_glob, the line written here is
     * still the one drawn — otherwise the placement would be a race between
     * this table and a table somebody else maintains, and the answer would
     * change without anyone touching this file. Delete the entry to hand the
     * child back to the chart.
     *
     * Children are named by the POOL's person + comp_id — what staff[] prints
     * — and matched case-insensitively, as the database matches them. An entry
     * naming somebody who is no longer in the pool is skipped: that branch
     * stays where the chart puts it, rather than taking the whole answer down
     * over one line in a table.
     */
    public static function handPlacements()
    {
        /* EMPTY ON PURPOSE. This held fourteen edges that put every
         * department head under jeffrey, the four divisions under sooCK and
         * Workshop under rinob. They were removed because they were answering
         * the wrong question: who may SEE a department is not who REPORTS to
         * whom, and writing an access rule as a fake reporting line left the
         * chart saying something nobody had agreed to.
         *
         * That is now departmentAccess(), below, which states the access and
         * changes no line in the chart. The reporting line here is once again
         * only what organization_chart_glob and department_head say.
         *
         * The mechanism is kept: add an entry and it still moves a branch,
         * for the day a line really is missing from the data. */
        return array();
    }

    /**
     * WHO MAY SEE WHICH DEPARTMENTS.
     *
     * A statement about access, nothing else. It moves nobody in the chart,
     * changes no branch and no count — staff_tree_individual still answers
     * with the reporting line, and this is reported beside it. The two are
     * deliberately separate: a manager seeing another department's figures
     * does not make its people report to them, and the previous attempt to
     * say this by re-parenting them made the org chart tell a lie.
     *
     * `departments` is either the string 'all' — every department this
     * company has, now and after the next one is added — or the exact
     * dept_name of each grant. Names are matched against department_head
     * case-insensitively, and any that match nothing are reported back as
     * `unknown` rather than dropped, so a renamed department shows up as a
     * broken grant instead of silently shrinking somebody's access.
     *
     * Top-level departments only. The sub-departments inside a granted one
     * come with it — see accessFor(), which lists them.
     */
    public static function departmentAccess()
    {
        return array(
            /* Jeffrey Soo Yee Lieh — the whole company. */
            array('person' => 'jeffrey', 'comp_id' => 1, 'departments' => 'all'),

            /* Soo Chee Kiong — the four divisions and the four units under
             * Management B (O&E). */
            array('person' => 'sooCK', 'comp_id' => 1, 'departments' => array(
                'GM, Call Centre & Division A',
                'R01, Training & Division B',
                'Periodic & Division C',
                'Emergency & Division D',
                'Fleet',
                'Workshop',
                'Sajak Warni',
                'Cold Mix',
            )),

            /* Rino Binsoning — Fleet and the Workshop beside it. */
            array('person' => 'rinob', 'comp_id' => 1, 'departments' => array(
                'Fleet',
                'Workshop',
            )),

            /* Vincent Ng — Admin beside his own HR. He heads HR, so that
             * line is his either way; it is named here as well so the grant
             * says the whole of what he may see rather than half of it. */
            array('person' => 'vincentng', 'comp_id' => 1, 'departments' => array(
                'Admin',
                'HR',
            )),
        );
    }

    /**
     * HOW DEEP A BRANCH IS DRAWN, where the whole of it is too much to send.
     *
     * `levels` is how many rungs BELOW that person are printed: 1 means their
     * direct reports and nothing under those. The limit travels with the
     * person, so it holds wherever their branch is drawn — their own section
     * entry, staff_tree_individual for them, and anywhere they are nested.
     *
     * ONLY TREES ARE CAPPED. sections() reads its two figures off the chart,
     * so a limit here cannot understate anybody's org in the lists — jeffrey's
     * hod[] entry says 657 whatever this says.
     *
     * Jeffrey is the top of what this endpoint draws, so his tree is the whole
     * company. Capped at his direct reports, which is what asking for him is
     * actually read for: the department heads under him.
     *
     * THE CUT IS SILENT, and that is worth knowing before you add to this
     * list. A node whose branch was cut comes back with direct_subordinates
     * and total_subordinates at 0, exactly like somebody who really has
     * nobody under them — there is no flag on the node saying which it is.
     * (There was: `truncated` and `hidden_below`. They were taken off the
     * node on request, to keep the shape lean. Put them back in node() if a
     * caller ever needs to tell the two apart.)
     *
     * What rescues it is that everybody cut here is a department head, so
     * hod[] carries their real org size and staff_tree_individual for them
     * returns everything below them. A cap on somebody who is NOT in either
     * list would hide people with nothing anywhere else to show them.
     *
     * Empty the list and every tree is drawn in full again.
     */
    public static function depthLimits()
    {
        /* EMPTY. jeffrey was capped here because handPlacements() had put the
         * whole company under him and his tree was 657 people. Those
         * placements are gone, so his branch is now his five real chart
         * reports — and a cap on that would hide their teams for no gain.
         *
         * The mechanism stays for the next branch that grows too big to
         * send. */
        return array();
    }

    private $repo;
    private $overrides;
    private $compNames;

    /** key => pool row. The universe: nothing outside this is ever a node. */
    private $rows = array();
    /** lower-cased person => array of keys, for staff_tree_individual. */
    private $byName = array();
    /** chart identity => key, which is where Trillium is reconciled. */
    private $byChart = array();
    /** the companies whose reporting line has to be read. */
    private $chartComps = array();

    /** parent key => set of child keys, and the same edges the other way. */
    private $children = array();
    private $parents  = array();

    /** key => set of department names they head. */
    private $hodNames = array();
    /** key => sort rank, for the departments' own order. */
    private $rank = array();
    /** The two sections, as department rows. See readDepartments(). */
    private $units = array('hod' => array(), 'am' => array());
    /** section => key => that department row, for placing people. */
    private $unitByKey = array('hod' => array(), 'am' => array());
    /** Live departments whose head is not in the pool. */
    private $vacantUnits = array();
    /** key => how many rungs below it are drawn. See depthLimits(). */
    private $depthLimit = array();

    /**
     * $rows are the WHOLE pool for the scope asked for — the same unfiltered
     * set the summary is counted off. The tree is never built over a filtered
     * list: drop a manager because somebody typed three letters in a search
     * box and every branch under them is orphaned.
     */
    public function __construct(PDO $db, array $rows, array $overrides,
                                array $compNames = array())
    {
        $this->repo      = new StaffTreeRepository($db);
        $this->overrides = $overrides;
        $this->compNames = $compNames;

        $this->index($rows);
        $this->readLine();
    }

    /** One identity. The person part is lower-cased because every collation in
     *  play is case-insensitive — MySQL already treats sooCK and soock as one
     *  login, and a tree that disagreed would silently lose an edge.
     *
     *  The type is part of the key, and has to be: a GW's code is a
     *  monthly_assign_gw_code, a different namespace from users.person, and
     *  staffPoolCore.php's own notes say the two CAN collide. */
    private static function key($type, $person, $compId)
    {
        return $type . '|' . strtolower(trim((string)$person)) . '|' . (int)$compId;
    }

    /** Command: index the pool, and work out which charts have to be read. */
    private function index(array $rows)
    {
        $trillium = array();

        foreach ($rows as $row) {
            $type = (isset($row['person_type']) && $row['person_type'] === 'gw')
                ? 'gw' : 'staff';
            $key  = self::key($type, $row['person'], $row['comp_id']);

            $this->rows[$key] = $row;
            $lower = strtolower(trim((string)$row['person']));
            if (!isset($this->byName[$lower])) {
                $this->byName[$lower] = array();
            }
            $this->byName[$lower][] = $key;

            /* Trillium's people have no chart rows of their own: they are
             * stored under Globinaco and only relabelled as company 16 by the
             * pool, so Globinaco is where their reporting line is. Held back
             * and mapped last so that a real Globinaco row can never be
             * displaced by one of theirs. */
            if ($type === 'staff'
                && (int)$row['comp_id'] === StaffPoolRepository::TRILLIUM_COMP_ID) {
                $trillium[] = array($key, $row['person']);
                $this->chartComps[StaffPoolRepository::TRILLIUM_HOME_COMP_ID] =
                    StaffPoolRepository::TRILLIUM_HOME_COMP_ID;
                continue;
            }

            $this->byChart[self::key($type, $row['person'], $row['comp_id'])] = $key;
            $this->chartComps[(int)$row['comp_id']] = (int)$row['comp_id'];
        }

        foreach ($trillium as $t) {
            $chartKey = self::key('staff', $t[1],
                                  StaffPoolRepository::TRILLIUM_HOME_COMP_ID);
            if (!isset($this->byChart[$chartKey])) {
                $this->byChart[$chartKey] = $t[0];
            }
        }
    }

    /** Command: read the reporting line and lay it over the pool. The ORDER
     *  of these four steps is the whole of the precedence rule — the chart
     *  first, then the placements written by hand on top of it, then the
     *  departments, which only fill in a top for whoever still has none. */
    private function readLine()
    {
        $comps = array_values($this->chartComps);

        foreach ($this->repo->staffEdges($comps) as $e) {
            $this->link(
                self::key('staff', $e['staff_id'], $e['comp_id']),
                self::key('staff', $e['boss_id'],  $e['boss_comp_id'])
            );
        }
        foreach ($this->repo->gwEdges($comps) as $e) {
            $this->link(
                self::key('gw',    $e['staff_id'], $e['comp_id']),
                self::key('staff', $e['boss_id'],  $e['boss_comp_id'])
            );
        }

        $this->applyHandPlacements();
        $this->readDepartments($comps);
        $this->readDepthLimits();
    }

    /** Command: resolve depthLimits() against the pool. A cap naming somebody
     *  who is not in it caps nothing, exactly as a hand placement does. */
    private function readDepthLimits()
    {
        foreach (self::depthLimits() as $limit) {
            $key = self::key('staff', $limit['person'], $limit['comp_id']);
            if (isset($this->rows[$key])) {
                $this->depthLimit[$key] = max(0, (int)$limit['levels']);
            }
        }
    }

    /** Query: how many rungs may be drawn below this node — its own cap, the
     *  one it inherits from above, or whichever is tighter. null is no cap. */
    private function budgetFor($key, $inherited)
    {
        if (!isset($this->depthLimit[$key])) {
            return $inherited;
        }
        $own = $this->depthLimit[$key];
        return ($inherited === null) ? $own : min($own, $inherited);
    }

    /** Command: one edge, child to boss, both named as the CHART names them.
     *  An end the pool did not list is not an edge. That test lives here and
     *  nowhere else, and it is what keeps the tree inside the pool. */
    private function link($childChartKey, $parentChartKey)
    {
        if (!isset($this->byChart[$childChartKey])
            || !isset($this->byChart[$parentChartKey])) {
            return;
        }
        $child  = $this->byChart[$childChartKey];
        $parent = $this->byChart[$parentChartKey];

        /* A row naming itself as its own boss is not a reporting line, and
         * taking it as one would leave that person with a parent they can
         * never be reached through. */
        if ($child !== $parent) {
            $this->addEdge($child, $parent);
        }
    }

    private function addEdge($child, $parent)
    {
        if (isset($this->parents[$child][$parent])) {
            return;
        }
        if (!isset($this->parents[$child])) {
            $this->parents[$child] = array();
        }
        if (!isset($this->children[$parent])) {
            $this->children[$parent] = array();
        }
        $this->parents[$child][$parent]  = true;
        $this->children[$parent][$child] = true;
    }

    /** Command: apply handPlacements(), replacing whatever the chart said
     *  about those children. See that method for why it wins. */
    private function applyHandPlacements()
    {
        foreach (self::handPlacements() as $p) {
            $child  = self::key('staff', $p['person'], $p['comp_id']);
            $parent = self::key('staff', $p['boss'],   $p['boss_comp_id']);

            if ($child === $parent
                || !isset($this->rows[$child]) || !isset($this->rows[$parent])) {
                continue;
            }

            if (!empty($this->parents[$child])) {
                foreach (array_keys($this->parents[$child]) as $old) {
                    unset($this->children[$old][$child]);
                }
                $this->parents[$child] = array();
            }

            $this->addEdge($child, $parent);
        }
    }

    /**
     * Command: read department_head for the HOD labels and for the two
     * sections this endpoint publishes.
     *
     * THREE things come out of one read.
     *
     * 1. hod_department_name, for every live row at either level. It is a
     *    label on a PERSON, so it is every department they head: mackaylachu
     *    holds both Accounts and Procurement & Inventory, and the label says
     *    both.
     * 2. The section rows themselves — hod[] from the top level, am[] from the
     *    sub-departments. These are DEPARTMENTS, not people, which is why
     *    mackaylachu appears twice in hod[]: he heads two of them, and a list
     *    of 16 departments that printed 15 entries would be a list of
     *    something else.
     * 3. A rank, so the sections come out in the order the org navigator shows
     *    them rather than alphabetically.
     *
     * A head outside the pool heads nothing here, which is the single gate
     * this whole file runs on. Two kinds of row fall through it and they are
     * NOT the same thing, so they are told apart by is_trillium: a Trillium
     * department belongs to company 16's tree and is simply not this company's
     * business, while a department whose head is no longer a live user is a
     * real gap in this company and goes to vacant[].
     *
     * MANPOWER_TOP_PERSON is honoured if it is set, but it is empty — see that
     * constant. With no top, nothing is hung off anybody here: where each
     * department head sits is the chart's business, or handPlacements().
     */
    private function readDepartments(array $comps)
    {
        $rank = 0;

        foreach ($this->repo->departmentHeads($comps) as $dh) {
            $key  = self::key('staff', $dh['staff_id'], $dh['comp_id']);
            $isTop = !empty($dh['top']);

            if (!empty($dh['is_trillium'])) {
                continue;   // company 16's departments, not this company's
            }

            $unit = array(
                'department_id'   => (int)$dh['id'],
                'department_name' => trim((string)$dh['dept_name']),
                'person'          => (string)$dh['staff_id'],
                'comp_id'         => (int)$dh['comp_id'],
                /* 0 for a top-level department. Carried so accessFor() can
                 * say which sub-departments sit inside a granted one. */
                'parent_id'       => (int)$dh['parent_id'],
            );

            if (!isset($this->rows[$key])) {
                /* The department is live, its head is not. Reported rather
                 * than dropped: a unit with nobody in it is the kind of thing
                 * this endpoint exists to make visible. */
                $unit['section'] = $isTop ? 'hod' : 'am';
                $this->vacantUnits[] = $unit;
                continue;
            }

            $name = $unit['department_name'];
            if ($name !== '') {
                if (!isset($this->hodNames[$key])) {
                    $this->hodNames[$key] = array();
                }
                $this->hodNames[$key][$name] = true;
            }

            $unit['key'] = $key;
            $section = $isTop ? 'hod' : 'am';
            $this->units[$section][] = $unit;

            /* FIRST ONE WINS. mackaylachu heads two departments, so asking
             * "which unit is this person in" has two answers for him; the one
             * listed first is the one taken, which is the lowest `order` and
             * so the one the org navigator shows him under. The sections
             * still print both — this index is for placing OTHER people. */
            if (!isset($this->unitByKey[$section][$key])) {
                $this->unitByKey[$section][$key] = $unit;
            }

            if (!isset($this->rank[$key])) {
                $this->rank[$key] = $rank++;
            }
        }

        /* Only when a top person is named, which he is not today. Kept so that
         * putting a login back in MANPOWER_TOP_PERSON restores the single-root
         * shape without touching anything else. */
        $topPerson = trim((string)self::MANPOWER_TOP_PERSON);
        if ($topPerson === '' || empty($this->units['hod'])) {
            return;
        }
        $topKey = self::key('staff', $topPerson, self::MANPOWER_TOP_COMP_ID);
        if (!isset($this->rows[$topKey])) {
            return;
        }
        foreach ($this->units['hod'] as $unit) {
            if ($unit['key'] !== $topKey && empty($this->parents[$unit['key']])) {
                $this->addEdge($unit['key'], $topKey);
            }
        }
    }

    /* ---------- asking it things ----------------------------------------- */

    /**
     * Query: the keys a person string could mean, nearest match first.
     *
     * Normally there is exactly one: the endpoint is called for one company,
     * so the pool it was built over holds one row per login. The list exists
     * for comp_id=all, where the same login can be live at two companies, and
     * for the collision staffPoolCore.php warns about — a GW code that happens
     * to read like a username. Staff come first, because a username is what a
     * caller typing a name means.
     */
    public function candidates($person)
    {
        $lower = strtolower(trim((string)$person));
        if ($lower === '' || !isset($this->byName[$lower])) {
            return array();
        }

        $staff = array();
        $gw    = array();
        foreach ($this->byName[$lower] as $key) {
            if ($this->rows[$key]['person_type'] === 'gw') {
                $gw[] = $key;
            } else {
                $staff[] = $key;
            }
        }
        return array_merge($staff, $gw);
    }

    /**
     * Query: this person's pool rows — themselves and everybody below them,
     * at any depth, as the flat set another endpoint can work over.
     *
     * NO DEPTH LIMIT. depthLimits() shortens a drawn TREE because a tree is
     * read by a human; this answers "who is in this person's org", and a
     * caller asking that needs all of them. attendanceAPI.php scopes itself
     * with this, so staff=jeffrey covers the whole company.
     *
     * Rows, not keys: the caller wants the people, and the pool row is what
     * every other endpoint here publishes.
     */
    public function branch($key)
    {
        if (!isset($this->rows[$key])) {
            return array();
        }

        $seen  = array($key => true);
        $queue = array($key);

        for ($i = 0; $i < count($queue); $i++) {
            if (empty($this->children[$queue[$i]])) {
                continue;
            }
            foreach (array_keys($this->children[$queue[$i]]) as $child) {
                if (isset($seen[$child]) || !isset($this->rows[$child])) {
                    continue;
                }
                $seen[$child] = true;
                $queue[]      = $child;
            }
        }

        $out = array();
        foreach ($queue as $k) {
            $out[] = $this->rows[$k];
        }
        return $out;
    }

    /** Query: every department this person heads, as the sections label them,
     *  or '' — so a caller that never draws a tree can still say who is a
     *  head. Keyed by the pool's own person + comp_id. */
    public function hodLabel($person, $compId)
    {
        $key = self::key('staff', $person, $compId);
        return isset($this->hodNames[$key])
            ? implode(', ', array_keys($this->hodNames[$key]))
            : '';
    }

    /**
     * Query: the departments one person may see — see departmentAccess().
     *
     * Resolved against the departments this company ACTUALLY has, so a grant
     * naming something that no longer exists comes back in `unknown` and a
     * department added after the grant was written is picked up by 'all'
     * without anybody editing a list.
     *
     * Each granted department carries its head and the sub-departments inside
     * it, because access to a department is access to what is in it, and a
     * caller should not have to go and work that out.
     *
     * scope: 'all' | 'listed' | 'none' — 'none' is most people, and is a real
     * answer rather than a missing field.
     */
    public function accessFor($person, $compId)
    {
        $want = null;
        foreach (self::departmentAccess() as $grant) {
            if ((int)$grant['comp_id'] === (int)$compId
                && strcasecmp(trim((string)$grant['person']), trim((string)$person)) === 0) {
                $want = $grant['departments'];
                break;
            }
        }

        if ($want === null) {
            return array('scope' => 'none', 'departments' => array(),
                         'counts' => array('departments' => 0, 'sub_departments' => 0));
        }

        $all = (is_string($want) && strtolower($want) === 'all');

        /* Wanted names, lower-cased, so the lookup is the case-insensitive
         * match the grant promises. */
        $byName = array();
        if (!$all) {
            foreach ((array)$want as $name) {
                $byName[strtolower(trim((string)$name))] = trim((string)$name);
            }
        }

        $out  = array();
        $subs = 0;
        foreach ($this->units['hod'] as $unit) {
            $key = strtolower($unit['department_name']);
            if (!$all && !isset($byName[$key])) {
                continue;
            }
            unset($byName[$key]);

            /* The sub-departments inside it, named the way am[] names them. */
            $inside = array();
            foreach ($this->units['am'] as $sub) {
                if ((int)$sub['parent_id'] === (int)$unit['department_id']) {
                    $inside[] = array(
                        'department_id'   => $sub['department_id'],
                        'department_name' => $sub['department_name'],
                        'person'          => $sub['person'],
                    );
                }
            }
            $subs += count($inside);

            $row = $this->rows[$unit['key']];
            $out[] = array(
                'department_id'   => $unit['department_id'],
                'department_name' => $unit['department_name'],
                'person'          => (string)$row['person'],
                'fullname'        => displayName($row['fullname'], $row['person']),
                'sub_departments' => $inside,
            );
        }

        $access = array(
            'scope'       => $all ? 'all' : 'listed',
            'departments' => $out,
            'counts'      => array('departments'     => count($out),
                                   'sub_departments' => $subs),
        );

        /* A grant naming a department that is not there any more. Reported,
         * never swallowed: it means somebody renamed a unit and this list did
         * not follow. */
        if (!empty($byName)) {
            $access['unknown'] = array_values($byName);
        }
        return $access;
    }

    /** Query: every grant there is, for the whole-company answer. Ordered as
     *  departmentAccess() writes them, and skipping anybody who is not in the
     *  pool — a grant to somebody who has left grants nothing. */
    public function accessAll()
    {
        $out = array();
        foreach (self::departmentAccess() as $grant) {
            $key = self::key('staff', $grant['person'], $grant['comp_id']);
            if (!isset($this->rows[$key])) {
                continue;
            }
            $row = $this->rows[$key];
            $out[] = array(
                'person'   => (string)$row['person'],
                'comp_id'  => (int)$row['comp_id'],
                'fullname' => displayName($row['fullname'], $row['person']),
            ) + $this->accessFor($grant['person'], $grant['comp_id']);
        }
        return $out;
    }

    /**
     * Query: WHICH DEPARTMENT THIS PERSON BELONGS TO — the nearest department
     * head above them, and the nearest sub-department head above them.
     *
     * This is what turns the two flat sections into a grouping. Every person
     * lands in exactly ONE hod unit and at most one am unit, so a report that
     * groups by this partitions the company instead of repeating people under
     * every manager above them, which is what the branches do.
     *
     * NEAREST, AND THEMSELVES COUNTS. A head is in their own unit, so a
     * department's group holds its head and their people together. Walking
     * up from a general worker under victorchai (KK/PTN) under justin, the
     * am is KK/PTN and the hod is justin's department — not sooCK's or
     * jeffrey's, which are further up the same line.
     *
     * Breadth-first upward, so "nearest" means fewest rungs, and the 82
     * people with two superiors get the closer of the two rather than
     * whichever edge happened to be read first.
     *
     * Empty strings when nothing is found: somebody on no chart at all
     * belongs to no department, and saying so beats inventing one.
     */
    public function placement($person, $compId, $type = 'staff')
    {
        $out = array('hod_person' => '', 'hod_unit' => '',
                     'am_person'  => '', 'am_unit'  => '');

        $start = self::key($type === 'gw' ? 'gw' : 'staff', $person, $compId);
        if (!isset($this->rows[$start])) {
            return $out;
        }

        $seen  = array($start => true);
        $queue = array($start);
        $hod   = null;
        $am    = null;

        for ($i = 0; $i < count($queue); $i++) {
            $key = $queue[$i];

            if ($hod === null && isset($this->unitByKey['hod'][$key])) {
                $hod = $this->unitByKey['hod'][$key];
            }
            if ($am === null && isset($this->unitByKey['am'][$key])) {
                $am = $this->unitByKey['am'][$key];
            }
            if ($hod !== null && $am !== null) {
                break;
            }
            if (empty($this->parents[$key])) {
                continue;
            }
            foreach (array_keys($this->parents[$key]) as $parent) {
                if (!isset($seen[$parent]) && isset($this->rows[$parent])) {
                    $seen[$parent] = true;
                    $queue[]       = $parent;
                }
            }
        }

        if ($hod !== null) {
            $out['hod_person'] = $hod['person'];
            $out['hod_unit']   = $hod['department_name'];
        }
        if ($am !== null) {
            $out['am_person'] = $am['person'];
            $out['am_unit']   = $am['department_name'];
        }
        return $out;
    }

    /** Query: what a key is, for an error message a human can act on. */
    public function describe($key)
    {
        if (!isset($this->rows[$key])) {
            return $key;
        }
        $row = $this->rows[$key];
        return displayName($row['fullname'], $row['person'])
             . ' (' . $row['person'] . ', '
             . ($row['person_type'] === 'gw' ? 'GW, ' : '')
             . 'company ' . (int)$row['comp_id'] . ')';
    }

    /**
     * Query: the company's managers as TWO FLAT LISTS — who heads what.
     *
     *   hod[]  one entry per top-level department   (Globinaco: 16)
     *   am[]   one entry per sub-department         (Globinaco: 18)
     *
     * NOBODY'S SUBORDINATES ARE IN HERE. An entry is one manager and the unit
     * they hold — no nested people, no subordinates[] at all. This is the
     * index: it answers "who are the managers and how big is each org",
     * and fetching any one of those orgs is one more request,
     * staff_tree_individual=<their person>.
     *
     * It carried the whole subtree per entry once, which answered the same
     * question in 850 KB instead of 15, because every person below a manager
     * was repeated under each manager above them.
     *
     * THE TWO COUNTS ON AN ENTRY ARE THE REAL ORG SIZE, not a count of
     * anything in this answer — there is nothing in the answer to count. They
     * are read straight off the chart, so a depth limit does not touch them:
     * jeffrey's entry says 657, which is how many people are under him, even
     * though asking for him individually returns his 13 direct reports. A
     * list of orgs that understated the orgs would be worth nothing.
     *
     * An entry is one DEPARTMENT, not one person: mackaylachu heads both
     * Accounts and Procurement & Inventory, so he is two entries carrying the
     * same person and different units. counts.managers is how many distinct
     * people the two lists name.
     */
    public function sections()
    {
        $out = array(
            'mode'   => 'all',
            'counts' => array(),
            'hod'    => array(),
            'am'     => array(),
        );

        $people = array();

        foreach (array('hod', 'am') as $section) {
            foreach ($this->units[$section] as $unit) {
                $key = $unit['key'];

                /* The entry IS a department, so it says WHICH one, and leads
                 * with it. hod_department_name stays the person's own label —
                 * every department they head — because that is a fact about
                 * them rather than about this entry. */
                $entry = array(
                    'department_id'   => $unit['department_id'],
                    'department_name' => $unit['department_name'],
                ) + $this->flat($key);

                $entry['direct_subordinates'] = $this->directCount($key);
                $entry['total_subordinates']  = $this->countBelow($key);

                $out[$section][] = $entry;
                $people[$key]    = true;
            }
        }

        $out['counts'] = array(
            'hod'      => count($out['hod']),
            'am'       => count($out['am']),
            'vacant'   => count($this->vacantUnits),
            /* Distinct PEOPLE named by the two lists, which is fewer than
             * hod + am whenever somebody heads two departments. */
            'managers' => count($people),
        );

        if (!empty($this->vacantUnits)) {
            $out['vacant'] = $this->vacantUnits;
        }

        /* Who may see which departments. Beside the lists, not inside them —
         * it is a fact about three people, not about all 34 units. */
        $access = $this->accessAll();
        if (!empty($access)) {
            $out['access'] = $access;
            $out['counts']['access'] = count($access);
        }
        return $out;
    }

    /** Query: how many people report directly to this person and are in the
     *  pool. Read off the chart, so it is the real figure whatever a depth
     *  limit does to a tree. */
    private function directCount($key)
    {
        if (empty($this->children[$key])) {
            return 0;
        }
        $n = 0;
        foreach (array_keys($this->children[$key]) as $child) {
            if (isset($this->rows[$child])) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Query: how many people are below this person in the chart, at any depth.
     *
     * Its own walk, with no depth limit and no shared placed-set, so it is the
     * size of THEIR org and not of whatever tree happened to be built. Runs
     * 34 times for the sections, entirely in memory — the edges are already
     * read — and the visited set makes a ring in the data harmless.
     */
    private function countBelow($key)
    {
        $seen  = array($key => true);
        $queue = array($key);

        for ($i = 0; $i < count($queue); $i++) {
            if (empty($this->children[$queue[$i]])) {
                continue;
            }
            foreach (array_keys($this->children[$queue[$i]]) as $child) {
                if (isset($seen[$child]) || !isset($this->rows[$child])) {
                    continue;
                }
                $seen[$child] = true;
                $queue[]      = $child;
            }
        }
        return count($seen) - 1;   // not counting the person themselves
    }

    /** Query: one person, and everybody under them. They are the root, so
     *  their own level is 0 and nothing above them is in the answer. */
    public function individual($key)
    {
        $placed = $this->walk(array($key));
        $row    = $this->rows[$key];
        $compId = (int)$row['comp_id'];

        return array(
            'mode'         => 'individual',
            'person'       => (string)$row['person'],
            'comp_id'      => $compId,
            'company_name' => isset($this->compNames[$compId])
                ? $this->compNames[$compId] : 'Company ' . $compId,
            'staff_type'   => $row['person_type'] === 'gw' ? 'gw' : 'staff',
            'counts'       => array(
                'people'    => count($placed['depth']),
                'max_depth' => $placed['depth'] ? max($placed['depth']) : 0,
            ),
            /* What they may SEE, beside what they MANAGE. The two are
             * different questions and this answer keeps them apart: `staff`
             * below is the reporting line and nothing else, and `access` does
             * not add a single person to it. See departmentAccess(). */
            'access'       => $this->accessFor($row['person'], $compId),
            'staff'        => $this->node($key, 0, $this->kidsOf($placed),
                                          $placed['parent']),
        );
    }

    /** Command: parent key => child keys, in the order the walk enqueued them,
     *  which is the order sortKeys() put them in. */
    private function kidsOf(array $placed)
    {
        $kidsOf = array();
        foreach ($placed['order'] as $key) {
            $parent = $placed['parent'][$key];
            if ($parent === null) {
                continue;
            }
            if (!isset($kidsOf[$parent])) {
                $kidsOf[$parent] = array();
            }
            $kidsOf[$parent][] = $key;
        }
        return $kidsOf;
    }

    /**
     * Query: breadth-first from these roots — who is placed, how deep, and
     * under whom.
     *
     * Breadth-first on purpose. A person under five superiors is placed on
     * their SHORTEST line to a root, which is the one a reader would call
     * their line; depth-first would place them wherever the walk happened to
     * arrive first. The placed set doubles as the cycle guard: a person is
     * never placed twice, so a ring in the data is walked once and left.
     *
     * Iterative, not recursive: the chart is shallow but it is data, and a
     * recursive walk over 1,000 rows is a stack depth nobody chose.
     */
    private function walk(array $roots)
    {
        $depth  = array();
        $parent = array();
        $queue  = array();
        $budget = array();   // key => rungs still allowed below it, null = all

        foreach ($roots as $key) {
            if (isset($depth[$key]) || !isset($this->rows[$key])) {
                continue;
            }
            $depth[$key]  = 0;
            $parent[$key] = null;
            $budget[$key] = $this->budgetFor($key, null);
            $queue[]      = $key;
        }

        for ($i = 0; $i < count($queue); $i++) {
            $key  = $queue[$i];
            $kids = isset($this->children[$key])
                ? array_keys($this->children[$key]) : array();
            if (empty($kids)) {
                continue;
            }

            /* Out of budget: this person HAS people under them and they are
             * not being drawn. See depthLimits(). */
            if ($budget[$key] !== null && $budget[$key] < 1) {
                continue;
            }
            $this->sortKeys($kids);

            foreach ($kids as $child) {
                if (isset($depth[$child]) || !isset($this->rows[$child])) {
                    continue;
                }
                $depth[$child]  = $depth[$key] + 1;
                $parent[$child] = $key;
                $budget[$child] = $this->budgetFor(
                    $child, $budget[$key] === null ? null : $budget[$key] - 1
                );
                $queue[]        = $child;
            }
        }

        return array('depth' => $depth, 'parent' => $parent, 'order' => $queue);
    }

    /**
     * Query: one person as a node.
     *
     * publicRow() first, so a node IS a staff[] row — same fields, same
     * values, defined in staffPoolCore.php next to the counting rules. What
     * the tree adds on top:
     *
     *   hod_department_name   every department this person heads, comma
     *                         separated, or '' for everybody else
     *   level                 0 at the root of this answer
     *   direct_subordinates   how many are nested in subordinates
     *   total_subordinates    the whole branch under them, every level
     *   also_reports_to       their other live superiors — see walk()
     *   subordinates          the nested nodes
     *
     * Recursive over the TREE, not the chart: every node appears once, so this
     * is bounded by the depth of the company and cannot loop.
     */
    private function node($key, $level, array $kidsOf, array $placedParent)
    {
        $out = $this->flat($key);
        $out['level'] = $level;

        $subs  = array();
        $total = 0;
        if (!empty($kidsOf[$key])) {
            foreach ($kidsOf[$key] as $child) {
                $node = $this->node($child, $level + 1, $kidsOf, $placedParent);
                $total += 1 + $node['total_subordinates'];
                $subs[] = $node;
            }
        }

        /* Both counts describe what is IN this answer — a reader can add them
         * up and get the array they are holding. Where a depth limit cut a
         * branch they therefore read 0, and nothing in the node says so: see
         * depthLimits() for what that costs. */
        $out['direct_subordinates'] = count($subs);
        $out['total_subordinates']  = $total;
        $out['also_reports_to']     = $this->otherSuperiors($key, $placedParent);
        $out['subordinates']        = $subs;
        return $out;
    }

    /** Query: a pool row plus its HOD label and nothing else — what every
     *  node is built on. */
    private function flat($key)
    {
        $out = publicRow($this->rows[$key], $this->overrides, $this->compNames);
        $out['hod_department_name'] = isset($this->hodNames[$key])
            ? implode(', ', array_keys($this->hodNames[$key]))
            : '';
        return $out;
    }

    /** Query: the superiors this person was NOT placed under. Empty for
     *  almost everybody — 16 of Globinaco's GW have one, and 82 people do in
     *  an all-companies answer.
     *
     *  On the ROOT of an individual tree this is every superior they have,
     *  since that tree placed them under nobody. That is the useful reading of
     *  it there: it says who the branch hangs off, which the branch itself
     *  does not show. */
    private function otherSuperiors($key, array $placedParent)
    {
        if (empty($this->parents[$key])) {
            return array();
        }
        $placed = isset($placedParent[$key]) ? $placedParent[$key] : null;

        $out = array();
        foreach (array_keys($this->parents[$key]) as $parent) {
            if ($parent === $placed || !isset($this->rows[$parent])) {
                continue;
            }
            $row   = $this->rows[$parent];
            $out[] = array(
                'person'   => (string)$row['person'],
                'comp_id'  => (int)$row['comp_id'],
                'fullname' => displayName($row['fullname'], $row['person']),
            );
        }
        return $out;
    }

    /**
     * Command: sort keys the way the chart is read.
     *
     * Departments in their own order first — that is the whole reason rank
     * exists — then staff before their GW, then by name. Deterministic down to
     * the key, so two calls a second apart cannot come back in two different
     * orders and make a diff of the answer unreadable.
     */
    private function sortKeys(array &$keys)
    {
        if (count($keys) < 2) {
            return;
        }
        usort($keys, function ($a, $b) {
            return $this->compareKeys($a, $b);
        });
    }

    private function compareKeys($a, $b)
    {
        $ra = isset($this->rank[$a]) ? $this->rank[$a] : PHP_INT_MAX;
        $rb = isset($this->rank[$b]) ? $this->rank[$b] : PHP_INT_MAX;
        if ($ra !== $rb) {
            return $ra < $rb ? -1 : 1;
        }

        $ta = $this->rows[$a]['person_type'] === 'gw' ? 1 : 0;
        $tb = $this->rows[$b]['person_type'] === 'gw' ? 1 : 0;
        if ($ta !== $tb) {
            return $ta < $tb ? -1 : 1;
        }

        $cmp = strcasecmp(
            displayName($this->rows[$a]['fullname'], $this->rows[$a]['person']),
            displayName($this->rows[$b]['fullname'], $this->rows[$b]['person'])
        );
        return $cmp !== 0 ? $cmp : strcmp($a, $b);
    }
}
