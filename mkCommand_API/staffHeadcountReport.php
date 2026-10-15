<?php
/**
 * Staff Headcount Report.
 *
 * MUST be hosted on globportal.com, same folder as jobspecReport.php —
 * staff_portal2 (users) and evaluation (monthly_assign_gw) are both local
 * there under prog4. The mkcommand <-> globportal 3306 path is firewalled.
 *
 * WHAT IT ANSWERS
 *   Per company, who is in the active staff pool today, and which of them are
 *   exempted from KPI/Scorecard, MK Command and MK Academy.
 *
 * THE POOL, THE FLAGS, THE COUNTING — staffPoolCore.php
 *   Which rows are in the pool, how the two populations are kept apart, how
 *   Trillium is lifted out of Globinaco, what an override does to a value and
 *   how a head count is counted are ALL defined in staffPoolCore.php, with
 *   the comments that explain them. This page renders what that file answers
 *   and staffPoolAPI.php publishes the same answers as JSON, which is how the
 *   page and every application reading that API stay on one set of figures.
 *   Change a rule there, never here.
 *
 * THE TICK BOXES — READ THIS BEFORE CHANGING ANYTHING
 *   Nothing on this page writes to users or monthly_assign_gw. Those stay the
 *   source of every value. A tick writes to staff_portal2.staff_flag_override
 *   instead (see sql/staff_flag_override.sql), and the value a cell shows is:
 *
 *       effective = override row, if one exists
 *                   otherwise the source column
 *
 *   So an override, once set, PINS that person's flag: a later edit to
 *   users.kpi_excluded will not move it. That is the point — this report is
 *   the authority once someone has touched a row — and it is why a cell whose
 *   override disagrees with its source carries a small "pinned" marker.
 *
 *   Every tick also appends to staff_flag_override_log, which is what the
 *   change log at the foot of the page reads. The log is how "who turned this
 *   person off, and when" stays answerable after the tick has been put back.
 *
 * ACCESS
 *   View : any logged-in user, their own company. Super admins see every
 *          company and "All companies"; a company admin sees the companies
 *          they administer (kpi_company_admins.comp_id, which is NOT always
 *          their own — user 1082 administers 3 and 17).
 *   Edit : active kpi_super_admins anywhere; active kpi_company_admins within
 *          the companies they administer. Everyone else is read-only and the
 *          boxes render disabled.
 *   Both checks fail closed, and the save endpoint re-checks the company of
 *   every single change rather than trusting the form.
 *
 * Query string:
 *   comp_id  int     one company, or empty for all   (all = super admins only)
 *   q        string  name or code search
 *   dept     string  department / district filter
 *   group    0|1     group rows by department        (default 1)
 *   changed  0|1     only rows whose flags were overridden away from source
 *   ajax     save    POST endpoint for the tick boxes
 *
 * PHP 7.2 compatible.
 */

session_start();

require_once '../includes/UnifiedAuth.php';

/* THE POOL, THE FLAGS AND THE COUNTING ALL LIVE IN HERE, and so does every
 * comment explaining them. staffPoolAPI.php includes the same file, which is
 * what keeps this page and every application reading that API on one set of
 * figures. Nothing below re-implements a rule from it. */
require_once __DIR__ . '/staffPoolCore.php';

if (!UnifiedAuth::isLoggedIn()) {
    header('Location: /accounts/mkPortal/user_validation.php');
    exit;
}

$currentUser = UnifiedAuth::getLoggedInUser();

/* ---------- view helpers -------------------------------------------------- */

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Query: this page's URL with some params replaced. A null value drops one. */
function selfUrl(array $changes)
{
    $q = $_GET;
    foreach ($changes as $k => $v) {
        if ($v === null) { unset($q[$k]); } else { $q[$k] = $v; }
    }
    unset($q['ajax']);
    $qs = http_build_query($q);
    return basename(__FILE__) . ($qs === '' ? '' : '?' . $qs);
}

/* ---------- controller ---------------------------------------------------- */

$error    = null;
$staff    = array();
$gw       = array();
$overrides = array();
$companies = array();
$logRows  = array();

/* The session's own company. UnifiedAuth joins users -> subsidiaries at login,
 * so comp_id is already there. */
$ownCompId = isset($currentUser['comp_id']) && $currentUser['comp_id'] !== ''
    ? (int)$currentUser['comp_id']
    : null;
$userId    = isset($currentUser['id']) ? (int)$currentUser['id'] : null;
$userName  = isset($currentUser['person']) ? (string)$currentUser['person'] : '';

/* Who this user may see and who they may edit — the same answer the API
 * gives the same user, because it is the same function. Fails closed. */
$access     = viewScope(Db::get(), $userId, $ownCompId);
$isSuper    = $access['is_super'];
$adminComps = $access['admin_comps'];
$viewComps  = $access['view_comps'];

/* Which company is on screen — resolveCompId() holds the rule. */
$defaultCompId = $ownCompId;
if ($defaultCompId === null && !empty($viewComps)) {
    $defaultCompId = $viewComps[0];
}

$compId = resolveCompId(
    isset($_GET['comp_id']) ? (string)$_GET['comp_id'] : null,
    $isSuper, $viewComps, $defaultCompId
);

$noScope = (!$isSuper && $defaultCompId === null);
if ($noScope) {
    $error = 'Your account is not linked to a company, so this report has '
           . 'nothing to show. Ask HR to set your company.';
}

/* Anyone who can edit at least one visible company gets live check boxes. The
 * save endpoint re-checks every row's company regardless, so this only decides
 * whether to render them enabled. */
$canEditAny = $isSuper || !empty($adminComps);

/* One token per session, checked on every save. The endpoint is a POST that a
 * logged-in browser would happily make from another origin otherwise. */
if (empty($_SESSION['sfr_token'])) {
    $_SESSION['sfr_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['sfr_token'];

/* ---------- save endpoint ------------------------------------------------- */

/** Command: apply a batch of ticks and answer in JSON. Everything below this
 *  block is skipped. A batch is one array of changes, so the bulk bar and a
 *  single check box use the same path. */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'save') {
    header('Content-Type: application/json; charset=UTF-8');

    $reply = function ($ok, $msg, $extra = array()) {
        echo json_encode(array('ok' => $ok, 'message' => $msg) + $extra);
        exit;
    };

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        $reply(false, 'POST only.');
    }
    if (!isset($_POST['token']) || !hash_equals($token, (string)$_POST['token'])) {
        http_response_code(403);
        $reply(false, 'Your session expired. Reload the page and try again.');
    }
    if (!$canEditAny || $noScope) {
        http_response_code(403);
        $reply(false, 'You do not have permission to change these.');
    }

    $items = json_decode(isset($_POST['payload']) ? $_POST['payload'] : '[]', true);
    if (!is_array($items) || empty($items)) {
        http_response_code(400);
        $reply(false, 'Nothing to save.');
    }
    if (count($items) > 2000) {
        http_response_code(400);
        $reply(false, 'Too many changes in one batch. Select fewer rows.');
    }

    $scope = json_decode(isset($_POST['scope']) ? $_POST['scope'] : '{}', true);
    if (!is_array($scope)) { $scope = array(); }

    /* The same rule the page itself was rendered under — '' and a missing
     * comp_id both mean every company, which only a super admin gets. */
    $viewComp = resolveCompId(
        (!isset($scope['comp_id']) || $scope['comp_id'] === null)
            ? '' : (string)$scope['comp_id'],
        $isSuper, $viewComps, $defaultCompId
    );
    $viewQ       = isset($scope['q']) ? trim((string)$scope['q']) : '';
    $viewDept    = isset($scope['dept']) ? trim((string)$scope['dept']) : '';
    $viewChanged = !empty($scope['changed']);
    $viewFlag    = isset($scope['flag']) ? trim((string)$scope['flag']) : '';
    if (!in_array($viewFlag, flagFilterValues(), true)) {
        $viewFlag = '';
    }

    try {
        $repo  = new StaffPoolRepository(Db::get());
        $defs  = flagDefs();

        /* The rows of the view being edited, loaded ONCE and used twice: to
         * validate every change against a real person, and afterwards to
         * recount the tiles and the table foot. Person, company and type all
         * have to line up with a row here, so a hand-made POST cannot invent
         * an override for somebody who is not in the report — or for a company
         * this user may not touch. */
        $scopeComps = array();
        foreach ($repo->companies() as $c) {
            $scopeComps[] = (int)$c['comp_id'];
        }
        $viewRows = array_merge($repo->staff($viewComp, $scopeComps),
                                $repo->gw($viewComp, $scopeComps));

        $pool = array();
        $seenComps = array();
        foreach ($viewRows as $r) {
            $pool[$r['person_type'] . "\x1f" . $r['comp_id'] . "\x1f" . $r['person']] = $r;
            $seenComps[$r['comp_id']] = true;
        }

        /* An Undo from the change log can name a company the current view does
         * not cover, so anything still unaccounted for is loaded on top. */
        $extraComps = array();
        foreach ($items as $it) {
            $c = isset($it['c']) ? (int)$it['c'] : 0;
            if (!isset($seenComps[$c])) { $extraComps[$c] = true; }
        }
        if (count($extraComps) > 25) {
            http_response_code(400);
            $reply(false, 'Too many companies in one batch.');
        }
        foreach (array_keys($extraComps) as $cmp) {
            foreach (array_merge($repo->staff($cmp), $repo->gw($cmp)) as $r) {
                $pool[$r['person_type'] . "\x1f" . $r['comp_id'] . "\x1f" . $r['person']] = $r;
            }
        }

        $saved   = 0;
        $refused = 0;
        foreach ($items as $it) {
            $type   = isset($it['t']) ? (string)$it['t'] : '';
            $comp   = isset($it['c']) ? (int)$it['c'] : 0;
            $person = isset($it['p']) ? (string)$it['p'] : '';
            $flag   = isset($it['f']) ? (string)$it['f'] : '';
            $value  = (!isset($it['v']) || $it['v'] === null || $it['v'] === '')
                ? null : (int)$it['v'];

            if (!isset($defs[$flag]) || ($type !== 'staff' && $type !== 'gw')) {
                $refused++;
                continue;
            }
            if ($value !== null && $value !== 0 && $value !== 1) {
                $refused++;
                continue;
            }
            if (!canEditComp($comp, $isSuper, $adminComps)) {
                $refused++;
                continue;
            }
            $pk = $type . "\x1f" . $comp . "\x1f" . $person;
            if (!isset($pool[$pk])) {
                $refused++;
                continue;
            }

            $repo->applyChange(
                $type, $comp, $person,
                displayName($pool[$pk]['fullname'], $person),
                $flag, $value, $userName, $userId
            );
            $saved++;
        }

        /* Recount from the database rather than from what was just posted:
         * the page's numbers then come from the same code a reload would run,
         * and a change someone else made in the meantime shows up too. */
        $freshOv = $repo->overrides($viewComp);
        $shown   = filterRows($viewRows, $viewQ, $viewDept, $viewChanged, $freshOv,
                              $viewFlag);

        $msg = $saved . ($saved === 1 ? ' change saved' : ' changes saved');
        if ($refused > 0) {
            $msg .= ', ' . $refused . ' refused';
        }
        $reply($refused === 0, $msg, array(
            'saved'   => $saved,
            'refused' => $refused,
            'totals'  => array(
                'summary' => summarise($viewRows, $freshOv),
                'foot'    => footTotals($shown, $freshOv),
                'shown'   => count($shown),
            ),
        ));
    } catch (Exception $e) {
        error_log('staffHeadcountReport save: ' . $e->getMessage());
        http_response_code(500);
        $reply(false, 'Could not save. Check the error log.');
    }
}

/* ---------- load ---------------------------------------------------------- */

/** The change log panel at the foot of the page, hidden for now.
 *  Set this to true to bring it back. staff_flag_override_log keeps recording
 *  every tick either way, so nothing is lost while it is off — and the Undo
 *  buttons live inside that panel, so they go with it. */
$showChangeLog = false;

$search    = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$deptWant  = isset($_GET['dept']) ? trim((string)$_GET['dept']) : '';
$groupBy   = !isset($_GET['group']) || $_GET['group'] === '1';   // default on
$onlyChanged = isset($_GET['changed']) && $_GET['changed'] === '1';

/* Which tile was clicked. Anything unrecognised is dropped rather than
 * erroring, so a stale bookmark just shows the unfiltered report. */
$flagWant = isset($_GET['flag']) ? trim((string)$_GET['flag']) : '';
if (!in_array($flagWant, flagFilterValues(), true)) {
    $flagWant = '';
}
$flagLabel = flagFilterLabel($flagWant);

try {
    if ($noScope) {
        throw new RuntimeException('Viewer has no company');
    }
    $repo      = new StaffPoolRepository(Db::get());
    $companies = $repo->companies();

    /* "All companies" means the companies in the list above, so the group
     * total is the sum of the per-company figures and never a larger number
     * nobody can reproduce by adding the dropdown up. */
    $scopeComps = array();
    foreach ($companies as $c) {
        $scopeComps[] = (int)$c['comp_id'];
    }

    $staff     = $repo->staff($compId, $scopeComps);
    $gw        = $repo->gw($compId, $scopeComps);
    $overrides = $repo->overrides($compId);
    if ($showChangeLog) {
        $logRows = $repo->log($compId);
    }
} catch (Exception $e) {
    error_log('staffHeadcountReport: ' . $e->getMessage());
    if ($error === null) {
        /* First run says so plainly rather than hiding behind the generic
         * message — neither table exists until the DDL has been run. */
        $error = (strpos($e->getMessage(), 'staff_flag_override') !== false)
            ? 'The tick-box tables are not installed yet. Run '
              . 'mkCommand_API/sql/staff_flag_override.sql on globportal, then reload.'
            : 'Could not load the report. Check the error log.';
    }
}

/** Query: the company label for a pinned viewer and for the page subtitle. */
$compLabel = 'All companies';
if ($compId !== null) {
    $compLabel = 'Company ' . $compId;
    foreach ($companies as $c) {
        if ((int)$c['comp_id'] === (int)$compId) {
            $compLabel = $c['display_name'];
            break;
        }
    }
    if ($compLabel === 'Company ' . $compId
        && isset($currentUser['company_name'])
        && trim((string)$currentUser['company_name']) !== ''
        && (int)$compId === (int)$ownCompId) {
        $compLabel = $currentUser['company_name'];
    }
}

/** Only "All companies" needs this: with one company on screen the name is
 *  already in the heading, and repeating it under every row is noise. */
if ($compId === null) {
    $compNames = array();
    foreach ($companies as $c) {
        $compNames[(int)$c['comp_id']] = $c['display_name'];
    }
    foreach (array('staff', 'gw') as $setName) {
        foreach ($$setName as &$row) {
            $row['company_name'] = isset($compNames[$row['comp_id']])
                ? $compNames[$row['comp_id']]
                : 'Company ' . $row['comp_id'];
        }
        unset($row);
    }
}

/* The companies the select may offer: everything for a super admin, otherwise
 * only what this user may view. */
$pickable = array();
foreach ($companies as $c) {
    if ($isSuper || in_array((int)$c['comp_id'], $viewComps, true)) {
        $pickable[] = $c;
    }
}

/* Over the WHOLE company, before the search and department filters, so the
 * tiles stay a head count instead of following the search box. */
$summary = summarise(array_merge($staff, $gw), $overrides);

/** The department list offered by the filter, from both populations. GW have
 *  no department — their district stands in for one, which is the only
 *  grouping GW rows carry. */
$deptOptions = array();
foreach (array($staff, $gw) as $set) {
    foreach ($set as $row) {
        $deptOptions[deptLabel($row['department'])] = true;
    }
}
$deptOptions = array_keys($deptOptions);
sort($deptOptions);
/* Keep the dropdown in the same order as the table: named departments first,
 * the unassigned group at the end. */
$noDept = array_search(deptLabel(''), $deptOptions, true);
if ($noDept !== false) {
    array_splice($deptOptions, $noDept, 1);
    $deptOptions[] = deptLabel('');
}

$staffShown = filterRows($staff, $search, $deptWant, $onlyChanged, $overrides, $flagWant);
$gwShown    = filterRows($gw, $search, $deptWant, $onlyChanged, $overrides, $flagWant);
$filtered   = ($search !== '' || $deptWant !== '' || $onlyChanged || $flagWant !== '');
$shownCount = count($staffShown) + count($gwShown);
$poolCount  = count($staff) + count($gw);

/* ---------- row rendering ------------------------------------------------- */

/** Command: one check box cell.
 *
 *  The "1" sits in the DOM after the input so `input:checked ~ .n` can show
 *  it, and the cell is laid out row-reverse so it still READS "1 [x]" the way
 *  the spec's mock-up does. Unticked prints nothing — a column of zeroes was
 *  explicitly not wanted. */
function renderFlagCell(array $row, $flag, array $overrides, $editable)
{
    list($value, $pinned, $o) = effective($row, $flag, $overrides);

    $title = '';
    if ($o !== null) {
        $title = 'Set by ' . $o['updated_by'] . ' on ' . fmtDateTime($o['updated_date']);
        if ($pinned) {
            $title .= ' — differs from the source record, so it is pinned here';
        }
    }

    echo '<td class="flagcell' . ($pinned ? ' pinned' : '') . '">';
    echo '<label class="flag"' . ($title !== '' ? ' title="' . h($title) . '"' : '') . '>';
    echo '<input type="checkbox"';
    echo ' data-type="' . h($row['person_type']) . '"';
    echo ' data-comp="' . (int)$row['comp_id'] . '"';
    echo ' data-person="' . h($row['person']) . '"';
    echo ' data-flag="' . h($flag) . '"';
    echo $value ? ' checked' : '';
    echo $editable ? '' : ' disabled';
    echo '>';
    echo '<span class="n">1</span>';
    if ($pinned) { echo '<span class="pindot" aria-hidden="true"></span>'; }
    echo '</label></td>';
}

/** Command: the rows of one population, grouped or flat. */
function renderRows(array $rows, $groupBy, array $overrides, $isSuper, array $adminComps,
                    $canEditAny, $colspan)
{
    if (empty($rows)) {
        echo '<tr><td colspan="' . (int)$colspan . '"><div class="empty">Nobody matches these filters.</div></td></tr>';
        return;
    }

    $flags    = array_keys(flagDefs());
    $i        = 0;
    $lastDept = null;

    foreach ($rows as $row) {
        $editable = $canEditAny && canEditComp($row['comp_id'], $isSuper, $adminComps);

        if ($groupBy) {
            $dept = deptLabel($row['department']);
            $key  = $row['person_type'] . "\x1f" . $dept;
            if ($key !== $lastDept) {
                $lastDept = $key;
                echo '<tr class="grouphdr"><td colspan="' . (int)$colspan . '">';
                if ($canEditAny) {
                    echo '<label class="selall"><input type="checkbox" class="groupsel"> </label>';
                }
                echo h($dept);
                if ($row['person_type'] === 'gw') { echo ' <span class="gwtag">GW</span>'; }
                echo '</td></tr>';
            }
        }

        list($inPool) = effective($row, 'in_pool', $overrides);
        $i++;
        echo '<tr class="personrow' . ($inPool ? '' : ' outofpool') . '"'
           . ' data-dept="' . h(deptLabel($row['department'])) . '">';
        echo '<td class="idx">' . $i . '</td>';
        if ($canEditAny) {
            echo '<td class="selcell">'
               . '<input type="checkbox" class="rowsel"' . ($editable ? '' : ' disabled') . '>'
               . '</td>';
        }
        echo '<td><span class="nm">' . h(displayName($row['fullname'], $row['person'])) . '</span>';
        echo '<div class="muted">' . h($row['person']);
        if (trim((string)$row['position']) !== '') {
            echo ' · ' . h($row['position']);
        }
        if (!$groupBy) {
            echo ' · ' . h(deptLabel($row['department']));
        }
        if (isset($row['company_name'])) {
            echo ' · ' . h($row['company_name']);
        }
        if ($row['person_type'] === 'gw') { echo ' · GW'; }
        echo '</div></td>';
        foreach ($flags as $flag) {
            renderFlagCell($row, $flag, $overrides, $editable);
        }
        echo '</tr>';
    }
}

/** Command: one population's table, head and body. */
function renderTable(array $rows, $groupBy, array $overrides, $isSuper, array $adminComps,
                     $canEditAny)
{
    $defs    = flagDefs();
    $colspan = 2 + count($defs) + ($canEditAny ? 1 : 0);

    /* Footer totals count the rows ON SCREEN, so they follow the filters —
     * the tiles above are the whole company and do not. */
    $foot = footTotals($rows, $overrides);
    ?>
    <div class="card">
        <table>
            <thead>
            <tr>
                <th class="idx">No.</th>
                <?php if ($canEditAny): ?><th class="selcell"></th><?php endif; ?>
                <th>Staff Name</th>
                <?php foreach ($defs as $key => $d): ?>
                    <th class="flagcol"><?php echo h($d['label']); ?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php renderRows($rows, $groupBy, $overrides, $isSuper, $adminComps,
                             $canEditAny, $colspan); ?>
            </tbody>
            <?php if (!empty($rows)): ?>
            <tfoot>
            <tr class="totalrow">
                <td class="idx"></td>
                <?php if ($canEditAny): ?><td class="selcell"></td><?php endif; ?>
                <td>Total</td>
                <?php foreach (array_keys($defs) as $f): ?>
                    <td class="flagcell" id="f_<?php echo h($f); ?>"><?php
                        echo (int)$foot[$f]; ?></td>
                <?php endforeach; ?>
            </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
    <?php
}

/** Query: how one log row reads. new_value -1 is the Undo case — the override
 *  was removed and the cell went back to whatever its source record says. */
function logPhrase($old, $new)
{
    $word = function ($v) { return $v ? 'ticked' : 'unticked'; };
    if ((int)$new === -1) {
        return 'override removed — back to the source record';
    }
    if ($old === null) {
        return 'set to ' . $word($new);
    }
    return $word($old) . ' → ' . $word($new);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Staff Headcount Report</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
    :root {
        --bg:#f1f5f9; --surface:#fff; --primary:#2563eb; --success:#059669;
        --danger:#dc2626; --warn:#b45309; --text:#1e293b; --muted:#64748b; --border:#e2e8f0;
    }
    * { box-sizing:border-box; }
    body { margin:0; padding:24px; background:var(--bg); color:var(--text);
           font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; }
    .wrap { max-width:1180px; margin:0 auto; }
    .main-content { margin-left:260px; transition:margin-left .3s ease; }
    .main-content.sidebar-collapsed { margin-left:60px; }
    @media (max-width:1024px) { .main-content { margin-left:60px; } }
    @media (max-width:768px)  { .main-content { margin-left:0 !important; } body { padding:12px; } }
    h1 { font-size:20px; margin:0 0 4px; }
    .sub { color:var(--muted); font-size:13px; margin-bottom:20px; }
    .card { background:var(--surface); border:1px solid var(--border); border-radius:12px;
            padding:16px; margin-bottom:16px; }
    .filters { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:10px; }
    input[type=search], input[type=text], select, .btn {
        font:inherit; padding:7px 12px; border-radius:8px;
        border:1px solid var(--border); background:var(--surface); color:var(--text); }
    .btn { text-decoration:none; display:inline-block; cursor:pointer; }
    .btn.on { background:var(--primary); border-color:var(--primary); color:#fff; font-weight:600; }
    .btn:disabled { opacity:.5; cursor:default; }
    .check { display:inline-flex; align-items:center; gap:6px; font-size:13px;
             padding:7px 2px; white-space:nowrap; }
    .tiles { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:6px; }
    .tile { flex:1; min-width:170px; background:var(--surface); border:1px solid var(--border);
            border-radius:12px; padding:14px; }
    .tile .n { font-size:26px; font-weight:700; line-height:1.1; }
    .tile .l { font-size:12px; color:var(--muted); margin-top:2px; }
    .n.kpi { color:var(--warn); }
    .n.mkc { color:var(--primary); }
    .n.mka { color:var(--success); }
    .tile { padding:0; overflow:hidden; }
    .tilemain { display:block; padding:14px 14px 10px; color:inherit; text-decoration:none; }
    .tilemain:hover { background:rgba(37,99,235,.05); }
    .tile.active { border-color:var(--primary); box-shadow:0 0 0 1px var(--primary) inset; }
    .tile .after { display:block; font-size:12px; color:var(--muted);
                   padding:8px 14px; border-top:1px solid var(--border);
                   text-decoration:none; }
    a.after:hover { background:rgba(37,99,235,.05); color:var(--text); }
    a.after.on { background:rgba(37,99,235,.08); color:var(--primary); font-weight:600; }
    .chip { display:inline-block; padding:1px 8px; border-radius:99px; font-size:11.5px;
            font-weight:700; background:rgba(37,99,235,.10); color:var(--primary); }
    .chip a { text-decoration:none; margin-left:4px; }
    .scopeline { color:var(--muted); font-size:12px; margin-bottom:16px; }
    .totalrow td { font-weight:700; background:#f8fafc; border-top:2px solid var(--border);
                   border-bottom:none; }
    .totalrow .flagcell { font-variant-numeric:tabular-nums; }
    table { width:100%; border-collapse:collapse; }
    th { text-align:left; font-size:12px; text-transform:uppercase; letter-spacing:.03em;
         color:var(--muted); padding:8px 10px; border-bottom:2px solid var(--border); }
    th.flagcol { text-align:center; width:150px; }
    td { padding:9px 10px; border-bottom:1px solid var(--border); vertical-align:middle; }
    tr:last-child td { border-bottom:none; }
    .idx { color:var(--muted); font-variant-numeric:tabular-nums; width:42px; }
    .selcell { width:34px; text-align:center; }
    .nm { font-weight:500; }
    .muted { color:var(--muted); font-size:12px; }
    .empty { color:var(--muted); text-align:center; padding:26px 0; }
    a { color:var(--primary); }
    .warnbox { border-left:4px solid var(--danger); background:rgba(220,38,38,.06); }

    /* flag cell: input first in the DOM so `:checked ~ .n` works, reversed in
       the layout so it reads "1 [x]". */
    .flagcell { text-align:center; }
    .flag { display:inline-flex; flex-direction:row-reverse; align-items:center;
            gap:7px; cursor:pointer; position:relative; }
    .flag input[disabled] { cursor:default; }
    .flag .n { visibility:hidden; font-weight:700; font-variant-numeric:tabular-nums;
               min-width:8px; text-align:right; }
    .flag input:checked ~ .n { visibility:visible; }
    .flag input { width:16px; height:16px; accent-color:var(--primary); }
    .pindot { position:absolute; top:-3px; right:-7px; width:6px; height:6px;
              border-radius:50%; background:var(--warn); }
    .flagcell.pinned .n { color:var(--warn); }

    .grouphdr td { background:#f8fafc; font-weight:700; font-size:12.5px;
                   text-transform:uppercase; letter-spacing:.03em; color:var(--muted); }
    .selall { display:inline-flex; align-items:center; margin-right:8px; vertical-align:middle; }
    .outofpool .nm { text-decoration:line-through; }
    .outofpool td { background:rgba(100,116,139,.05); }
    .rowbusy td { background:rgba(37,99,235,.07); }
    .rowsaved td { background:rgba(5,150,105,.09); }

    .sectionhead { display:flex; align-items:baseline; gap:10px; margin:30px 0 4px;
                   padding-top:22px; border-top:2px solid var(--border); }
    .sectionhead h2 { font-size:18px; margin:0; }
    .gwtag { display:inline-block; padding:2px 9px; border-radius:99px; font-size:11.5px;
             font-weight:700; background:rgba(202,138,4,.12); color:#ca8a04; }

    /* bulk bar: only in the flow once something is selected */
    .bulk { position:sticky; bottom:12px; z-index:5; display:none; gap:10px; flex-wrap:wrap;
            align-items:center; background:var(--text); color:#fff; padding:10px 14px;
            border-radius:12px; margin:12px 0; box-shadow:0 6px 24px rgba(15,23,42,.25); }
    .bulk.show { display:flex; }
    .bulk select { background:#fff; color:var(--text); }
    .bulk .count { font-weight:700; }
    .bulk .btn { background:var(--primary); border-color:var(--primary); color:#fff; font-weight:600; }
    .bulk .btn.ghost { background:transparent; border-color:rgba(255,255,255,.45); color:#fff; }

    details.logcard { background:var(--surface); border:1px solid var(--border);
                      border-radius:12px; padding:0 16px; margin-bottom:16px; }
    details.logcard > summary { cursor:pointer; padding:14px 0; font-weight:600;
                                list-style:none; display:flex; align-items:center; gap:8px; }
    details.logcard > summary::-webkit-details-marker { display:none; }
    details.logcard > summary::before { content:'\25B8'; color:var(--muted); }
    details.logcard[open] > summary::before { content:'\25BE'; }
    details.logcard table { margin-bottom:14px; }
    .logflag { display:inline-block; padding:1px 8px; border-radius:99px; font-size:11px;
               font-weight:700; background:rgba(37,99,235,.08); color:var(--primary); }
    .undo { font-size:12px; }

    #toast { position:fixed; left:50%; bottom:26px; transform:translateX(-50%) translateY(20px);
             background:var(--text); color:#fff; padding:10px 16px; border-radius:10px;
             font-size:13px; opacity:0; pointer-events:none; transition:all .18s ease; z-index:50; }
    #toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
    #toast.bad { background:var(--danger); }

    @media print {
        body { padding:0; background:#fff; }
        .filters, .btn, .bulk, .selcell, details.logcard { display:none !important; }
    }
</style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
<div class="wrap">

<h1>Staff Headcount Report</h1>

<?php if ($error !== null): ?>
    <div class="card warnbox"><?php echo h($error); ?></div>
<?php else: ?>

<form method="get" class="filters" id="filterform">
    <?php if ($isSuper || count($pickable) > 1): ?>
        <select name="comp_id" onchange="this.form.submit()">
            <?php if ($isSuper): ?>
                <option value="" <?php echo ($compId === null ? 'selected' : ''); ?>>All companies</option>
            <?php endif; ?>
            <?php foreach ($pickable as $c): ?>
                <option value="<?php echo (int)$c['comp_id']; ?>"
                    <?php echo ($compId === (int)$c['comp_id'] ? 'selected' : ''); ?>>
                    <?php echo h($c['display_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    <?php else: ?>
        <!-- One company and no right to pick another: a disabled select keeps
             the filter row looking the same and says why. -->
        <select disabled title="Only KPI super admins and company admins can change the company">
            <option><?php echo h($compLabel); ?></option>
        </select>
    <?php endif; ?>

    <input type="search" name="q" value="<?php echo h($search); ?>"
           placeholder="Search name or code" style="min-width:200px">

    <select name="dept" onchange="this.form.submit()">
        <option value="">All departments</option>
        <?php foreach ($deptOptions as $d): ?>
            <option value="<?php echo h($d); ?>" <?php echo ($deptWant === $d ? 'selected' : ''); ?>>
                <?php echo h($d); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <!-- The hidden partner is what makes unticking these work: an unchecked
         box submits nothing at all, and PHP keeps the LAST value of a repeated
         name, so unchecked arrives as 0 and checked as 1. -->
    <input type="hidden" name="group" value="0">
    <label class="check">
        <input type="checkbox" name="group" value="1" <?php echo $groupBy ? 'checked' : ''; ?>
               onchange="this.form.submit()">
        Group by department
    </label>

    <input type="hidden" name="changed" value="0">
    <label class="check">
        <input type="checkbox" name="changed" value="1" <?php echo $onlyChanged ? 'checked' : ''; ?>
               onchange="this.form.submit()">
        Only show changed rows
    </label>

    <button type="submit" class="btn">Apply</button>
    <?php if ($filtered): ?>
        <a class="btn" href="<?php echo h(selfUrl(array(
            'q' => null, 'dept' => null, 'changed' => null, 'flag' => null))); ?>">Clear</a>
    <?php endif; ?>
</form>

<?php
/* Every tile is a link that filters the table to the people it counted, so a
 * number can always be opened up and read. Clicking the one already applied
 * takes the filter off again. */
function tileUrl($flag, $flagWant)
{
    return selfUrl(array('flag' => $flagWant === $flag ? null : $flag));
}
?>
<div class="tiles">
    <div class="tile<?php echo $flagWant === 'in_pool' ? ' active' : ''; ?>">
        <a class="tilemain" href="<?php echo h(tileUrl('in_pool', $flagWant)); ?>"
           title="List everyone counted here">
            <div class="n" id="t_total"><?php echo (int)$summary['total']; ?></div>
            <div class="l">Total Staff (including exempted)</div>
        </a>
    </div>
    <?php
    /* The headline is how many are exempted; the line under it is the head
       count still measured once that exemption is applied, which is the figure
       the KPI and MK Command reports themselves work from. Both are links —
       the first to the exempted people, the second to everyone else. */
    $tileDefs = array(
        'kpi'        => array('KPI/Scorecard Exempted', 'kpi'),
        'mk_command' => array('MK Command Exempted',    'mkc'),
        'mk_academy' => array('MK Academy Exempted',    'mka'),
    );
    foreach ($tileDefs as $flag => $t): ?>
    <div class="tile<?php echo ($flagWant === $flag || $flagWant === 'not_' . $flag)
        ? ' active' : ''; ?>">
        <a class="tilemain" href="<?php echo h(tileUrl($flag, $flagWant)); ?>"
           title="List the <?php echo (int)$summary[$flag]; ?> exempted">
            <div class="n <?php echo h($t[1]); ?>" id="t_<?php echo h($flag); ?>"><?php
                echo (int)$summary[$flag]; ?></div>
            <div class="l"><?php echo h($t[0]); ?></div>
        </a>
        <a class="after<?php echo $flagWant === 'not_' . $flag ? ' on' : ''; ?>"
           href="<?php echo h(tileUrl('not_' . $flag, $flagWant)); ?>"
           title="List everyone still counted">
            <span id="a_<?php echo h($flag); ?>"><?php
                echo (int)$summary['total'] - (int)$summary[$flag]; ?></span> after exemption</a>
    </div>
    <?php endforeach; ?>
</div>

<div class="scopeline">
    <?php echo h($compLabel); ?> —
    <span id="s_staff"><?php echo (int)$summary['staff']; ?></span> staff<span
        id="s_gw_wrap"<?php echo $summary['gw'] > 0 ? '' : ' hidden'; ?>>
        + <span id="s_gw"><?php echo (int)$summary['gw']; ?></span> GW</span>
    in the pool<span id="s_removed_wrap"<?php echo $summary['removed'] > 0 ? '' : ' hidden'; ?>>,
        <a href="<?php echo h(tileUrl('not_in_pool', $flagWant)); ?>"
           title="List the people taken out of the pool"><span
            id="s_removed"><?php echo (int)$summary['removed']; ?></span>
        taken out of it here</a></span>.
    Totals cover the whole company, not the filters.
    <?php if ($filtered): ?>
        Showing <span id="s_shown"><?php echo (int)$shownCount; ?></span>
        of <?php echo (int)$poolCount; ?> rows.
    <?php endif; ?>
    <?php if ($flagLabel !== null): ?>
        <span class="chip"><?php echo h($flagLabel); ?>
            <a href="<?php echo h(selfUrl(array('flag' => null))); ?>"
               title="Remove this filter">&times;</a></span>
    <?php endif; ?>
    <?php if (!$canEditAny): ?>
        <br>Read-only: ticking is for KPI super admins and company admins.
    <?php endif; ?>
</div>

<?php
/* Staff and GW in ONE table, numbered straight through. They are still two
 * populations with two key namespaces - every row carries its own person_type,
 * and a GW row is marked as one - but they are no longer split into two
 * tables, so the last number on the page is the head count. */
renderTable(array_merge($staffShown, $gwShown), $groupBy, $overrides,
            $isSuper, $adminComps, $canEditAny);
?>

<?php if ($canEditAny): ?>
<div class="bulk" id="bulkbar">
    <span class="count" id="bulkcount">0 selected</span>
    <span>Set</span>
    <select id="bulkflag">
        <?php foreach (flagDefs() as $key => $d): ?>
            <option value="<?php echo h($key); ?>"><?php echo h($d['label']); ?></option>
        <?php endforeach; ?>
    </select>
    <span>to</span>
    <select id="bulkvalue">
        <option value="1">Ticked (1)</option>
        <option value="0">Unticked</option>
    </select>
    <button type="button" class="btn" id="bulkapply">Apply</button>
    <button type="button" class="btn ghost" id="bulkclear">Clear selection</button>
</div>
<?php endif; ?>

<?php if ($showChangeLog): ?>
<details class="logcard" <?php echo $onlyChanged ? 'open' : ''; ?>>
    <summary>Change log (<?php echo count($logRows); ?><?php echo count($logRows) >= 200 ? '+' : ''; ?>)</summary>
    <?php if (empty($logRows)): ?>
        <div class="empty">Nothing has been ticked or unticked yet.</div>
    <?php else: ?>
    <table>
        <thead>
        <tr>
            <th style="width:150px">When</th>
            <th style="width:120px">By</th>
            <th>Who</th>
            <th style="width:170px">Column</th>
            <th>Change</th>
            <th style="width:60px"></th>
        </tr>
        </thead>
        <tbody>
        <?php $defs = flagDefs(); foreach ($logRows as $l): ?>
            <tr>
                <td class="muted"><?php echo h(fmtDateTime($l['changed_date'])); ?></td>
                <td><?php echo h($l['changed_by']); ?></td>
                <td>
                    <?php echo h(displayName($l['person_name'], $l['person'])); ?>
                    <div class="muted"><?php echo h($l['person']); ?><?php
                        echo $l['person_type'] === 'gw' ? ' · GW' : ''; ?></div>
                </td>
                <td><span class="logflag"><?php
                    echo h(isset($defs[$l['flag']]) ? $defs[$l['flag']]['label'] : $l['flag']); ?></span></td>
                <td><?php echo h(logPhrase(
                        $l['old_value'] === null ? null : (int)$l['old_value'],
                        (int)$l['new_value'])); ?></td>
                <td>
                    <?php if ($canEditAny && canEditComp($l['comp_id'], $isSuper, $adminComps)): ?>
                        <a href="#" class="undo"
                           data-type="<?php echo h($l['person_type']); ?>"
                           data-comp="<?php echo (int)$l['comp_id']; ?>"
                           data-person="<?php echo h($l['person']); ?>"
                           data-flag="<?php echo h($l['flag']); ?>"
                           data-value="<?php echo $l['old_value'] === null ? '' : (int)$l['old_value']; ?>">Undo</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</details>
<?php endif; /* $showChangeLog */ ?>

<?php endif; /* $error */ ?>

</div><!-- /wrap -->
</div><!-- /main-content -->

<div id="toast" role="status" aria-live="polite"></div>

<script>
/* Ticking saves straight away — one box or a whole selection, both through the
 * same POST, because the server treats a batch as a list of single changes.
 *
 * Without JS the boxes do nothing: this page is a report first, and the server
 * never reads a form full of check boxes, only the JSON payload below. The
 * filters above are a plain GET form and keep working either way. */
(function () {
    'use strict';

    var TOKEN    = <?php echo json_encode($token); ?>;
    var SAVE_URL = <?php echo json_encode(basename(__FILE__) . '?ajax=save'); ?>;
    var CAN_EDIT = <?php echo $canEditAny ? 'true' : 'false'; ?>;

    /* The view the server must recount after a save. It is sent with every
     * save because the save URL carries no filters of its own. */
    var SCOPE = <?php echo json_encode(array(
        'comp_id' => $compId === null ? '' : (int)$compId,
        'q'       => $search,
        'dept'    => $deptWant,
        'changed' => $onlyChanged ? 1 : 0,
        'flag'    => $flagWant,
    )); ?>;

    /* With "only show changed" on, or a tile filter applied, a tick can move a
     * row in or out of the list — which no amount of recounting can show, so
     * those reload instead. */
    var RELOAD_ON_SAVE = <?php echo ($onlyChanged || $flagWant !== '') ? 'true' : 'false'; ?>;

    if (!window.fetch || !document.documentElement.closest) { return; }

    var toastEl = document.getElementById('toast');
    var toastTimer = null;

    function toast(msg, bad) {
        toastEl.textContent = msg;
        toastEl.className = 'show' + (bad ? ' bad' : '');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.className = ''; }, 2600);
    }

    function setNum(id, value) {
        var el = document.getElementById(id);
        if (el) { el.textContent = String(value); }
    }

    function show(id, on) {
        var el = document.getElementById(id);
        if (el) { if (on) { el.removeAttribute('hidden'); } else { el.setAttribute('hidden', ''); } }
    }

    /* The tiles, the scope line and the table foot are rendered by PHP, so
     * after a save they are stale until these numbers — recounted by the same
     * PHP — are written back over them. */
    function applyTotals(t) {
        if (!t || !t.summary) { return; }
        var s = t.summary, f = t.foot || {};

        setNum('t_total', s.total);
        ['kpi', 'mk_command', 'mk_academy'].forEach(function (k) {
            setNum('t_' + k, s[k]);
            setNum('a_' + k, s.total - s[k]);
        });

        setNum('s_staff', s.staff);
        setNum('s_gw', s.gw);
        show('s_gw_wrap', s.gw > 0);
        setNum('s_removed', s.removed);
        show('s_removed_wrap', s.removed > 0);
        setNum('s_shown', t.shown);

        ['in_pool', 'kpi', 'mk_command', 'mk_academy'].forEach(function (k) {
            setNum('f_' + k, f[k]);
        });
    }

    /* One POST, any number of changes. onDone(ok) so a caller can roll its own
     * check boxes back when the server refuses. */
    function save(items, rows, onDone) {
        rows.forEach(function (r) { r.classList.add('rowbusy'); });

        var body = new FormData();
        body.append('token', TOKEN);
        body.append('payload', JSON.stringify(items));
        body.append('scope', JSON.stringify(SCOPE));

        fetch(SAVE_URL, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                rows.forEach(function (r) {
                    r.classList.remove('rowbusy');
                    if (data.ok) {
                        r.classList.add('rowsaved');
                        setTimeout(function () { r.classList.remove('rowsaved'); }, 900);
                    }
                });
                /* Totals come back even on a partial refusal, because whatever
                 * did save has already moved them. */
                applyTotals(data.totals);
                toast(data.message || (data.ok ? 'Saved' : 'Not saved'), !data.ok);
                if (data.ok && RELOAD_ON_SAVE) { location.reload(); return; }
                onDone(!!data.ok);
            })
            .catch(function (err) {
                rows.forEach(function (r) { r.classList.remove('rowbusy'); });
                toast('Could not reach the server — nothing was saved.', true);
                onDone(false);
            });
    }

    function itemOf(box, value) {
        return {
            t: box.getAttribute('data-type'),
            c: parseInt(box.getAttribute('data-comp'), 10),
            p: box.getAttribute('data-person'),
            f: box.getAttribute('data-flag'),
            v: value
        };
    }

    /* A row that leaves the pool is struck through at once rather than waiting
     * for a reload, so the effect of unticking Total Staff is visible. */
    function reflectPool(box) {
        if (box.getAttribute('data-flag') !== 'in_pool') { return; }
        var row = box.closest('tr');
        if (row) { row.classList.toggle('outofpool', !box.checked); }
    }

    /* --- single tick ---------------------------------------------------- */
    document.addEventListener('change', function (e) {
        var box = e.target;
        if (!CAN_EDIT || box.type !== 'checkbox' || !box.hasAttribute('data-flag')) { return; }

        var was = !box.checked;
        reflectPool(box);
        save([itemOf(box, box.checked ? 1 : 0)], [box.closest('tr')], function (ok) {
            if (!ok) { box.checked = was; reflectPool(box); }
        });
    });

    if (!CAN_EDIT) { return; }

    /* --- selection ------------------------------------------------------ */
    var bar   = document.getElementById('bulkbar');
    var count = document.getElementById('bulkcount');

    function selected() {
        return Array.prototype.slice.call(
            document.querySelectorAll('tr.personrow .rowsel:checked')
        );
    }

    function refreshBar() {
        var n = selected().length;
        count.textContent = n + ' selected';
        bar.classList.toggle('show', n > 0);
    }

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('rowsel')) { refreshBar(); return; }

        /* A department header's box takes every enabled row under it, down to
         * the next header. */
        if (e.target.classList.contains('groupsel')) {
            var row = e.target.closest('tr');
            var on  = e.target.checked;
            for (var n = row.nextElementSibling; n; n = n.nextElementSibling) {
                if (n.classList.contains('grouphdr')) { break; }
                var sel = n.querySelector('.rowsel');
                if (sel && !sel.disabled) { sel.checked = on; }
            }
            refreshBar();
        }
    });

    document.getElementById('bulkclear').addEventListener('click', function () {
        selected().forEach(function (s) { s.checked = false; });
        document.querySelectorAll('.groupsel').forEach(function (s) { s.checked = false; });
        refreshBar();
    });

    /* --- bulk apply ------------------------------------------------------ */
    document.getElementById('bulkapply').addEventListener('click', function () {
        var flag  = document.getElementById('bulkflag').value;
        var value = parseInt(document.getElementById('bulkvalue').value, 10);
        var rows  = selected().map(function (s) { return s.closest('tr'); });

        var items = [], boxes = [], undo = [];
        rows.forEach(function (row) {
            var box = row.querySelector('input[data-flag="' + flag + '"]');
            if (!box || box.disabled) { return; }
            undo.push(box.checked);
            box.checked = !!value;
            reflectPool(box);
            boxes.push(box);
            items.push(itemOf(box, value));
        });

        if (!items.length) { toast('Nothing to change in that column.', true); return; }

        save(items, rows, function (ok) {
            if (ok) { return; }
            boxes.forEach(function (b, i) { b.checked = undo[i]; reflectPool(b); });
        });
    });

    /* --- undo from the change log ---------------------------------------- */
    document.addEventListener('click', function (e) {
        var link = e.target.closest('a.undo');
        if (!link) { return; }
        e.preventDefault();

        var raw = link.getAttribute('data-value');
        var item = {
            t: link.getAttribute('data-type'),
            c: parseInt(link.getAttribute('data-comp'), 10),
            p: link.getAttribute('data-person'),
            f: link.getAttribute('data-flag'),
            /* empty means there was no override before this change, so undoing
               it removes the override rather than setting a value. */
            v: raw === '' ? null : parseInt(raw, 10)
        };

        save([item], [link.closest('tr')], function (ok) {
            if (ok) { location.reload(); }
        });
    });
}());
</script>
</body>
</html>
