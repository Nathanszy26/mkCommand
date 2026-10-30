<?php
/**
 * Daily Summary Report — MODULES.
 *
 * MUST be hosted on globportal.com, in hr/, beside staffPoolCore.php and the
 * rest of the staffSummary* family. staff_portal2 (users) and mkPortal (the
 * two module tables) are both local there under prog4.
 *
 * WHAT IT ANSWERS
 *   Of the people HR has ticked for Daily Summary Report, which sections does
 *   each of them get, and which of those must they acknowledge before the day
 *   is finished?
 *
 * WHO IS ON THIS PAGE, AND WHY IT IS A SHORT LIST
 *   Exactly the people ticked on staffHeadcountReport.php — 33 today, out of a
 *   pool of several hundred. They are the only people the app stops at Staff
 *   Utility, so they are the only people for whom "which sections" is a
 *   question. Somebody not ticked has no row here; tick them on the report and
 *   they appear, with every module at its catalogue default.
 *
 * THE TWO BOXES ON EVERY CELL
 *   Show  the section is on their summary at all.
 *   Must  they cannot finish the day without ticking it.
 *
 *   They are different decisions and that is the point of having two. A
 *   manager can be shown RO1 Work Achievement for information without being
 *   held at the gate by it. Must is meaningless while Show is off, so it is
 *   disabled and greyed when Show is clear — and the value it HAD is kept in
 *   the database, so switching the section back on restores it rather than
 *   silently clearing it. dsModFor() in staffSummaryModules.php is what makes
 *   that safe: it collapses Must to 0 for every reader whenever Show is 0.
 *
 * WHERE THE RULES LIVE — staffSummaryModules.php
 *   The catalogue, the defaults, how an effective value is resolved, how a
 *   change is written and how a column is totalled are ALL there, with the
 *   comments that explain them. This file renders what that file answers. The
 *   cron and staffSummaryAPI.php read the same functions, which is how a tick
 *   made here and a section rendered at 6am stay the same decision.
 *   Change a rule there, never here.
 *
 * WHAT A TICK DOES NOT DO
 *   It does not change WHO gets a summary — that is the column on
 *   staffHeadcountReport.php. It does not change the morning MAIL's recipient
 *   list, which the cron still takes from the staff tree. And it writes no
 *   history: each row keeps who last changed it and when, and nothing keeps
 *   what it was before. sql/staff_summary_module.sql says why.
 *
 * ACCESS — the same scope the headcount report grants the same user, out of
 *   the same viewScope(), because it is the same population being edited.
 *   View : any logged-in user, their own company. Super admins see every
 *          company; a company admin sees the companies they administer.
 *   Edit : active kpi_super_admins anywhere; active kpi_company_admins within
 *          the companies they administer. Everyone else is read-only.
 *   Both fail closed, and the save endpoint re-checks the company of every
 *   single change rather than trusting the form.
 *
 * Query string:
 *   comp_id  int     one company, or empty for all   (all = super admins only)
 *   q        string  name, login or email search
 *   module   string  show only the people who have this module
 *   ajax     save    POST endpoint for the tick boxes
 *
 * PHP 7.2 compatible.
 */

session_start();

require_once '../includes/UnifiedAuth.php';

/* The pool, the scope and the company rules. */
require_once __DIR__ . '/staffPoolCore.php';

/* The catalogue, the defaults and every read and write behind this grid. */
require_once __DIR__ . '/staffSummaryModules.php';

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

/** The report this page is reached from, and goes back to. */
define('DSM_BACK_FILE', 'staffHeadcountReport.php');

/**
 * Query: the people on this page — the ticked rows of the pool, in the pool's
 * own order.
 *
 * OFF StaffPoolRepository, NOT off a query of its own. The pool is the one
 * head count and every page that counts people counts with it; a SELECT on
 * users here would be a second population that could disagree with the report
 * this page is opened from. The tick set narrows it and nothing else does.
 *
 * $ticks is dsModTicked()'s set, so somebody ticked but outside the pool (a
 * resigned row the column was never cleared on) is simply not here: they are
 * not in the pool, so they are not on the list, so there is nothing to decide
 * about them.
 */
function dsmPeople(array $poolRows, array $ticks)
{
    $out = array();
    foreach ($poolRows as $row) {
        if (!dsModOnRow($row)) { continue; }
        if (!isset($ticks[dsModKey($row['comp_id'], $row['person'])])) { continue; }
        $out[] = $row;
    }
    return $out;
}

/**
 * Query: does this person match the search box and the module filter?
 *
 * SEARCH COVERS THE EMAIL as well as the name and the login, because the email
 * is what the RO1 module matches against district_info.managerMail — so
 * "amkk" has to find Victor Chai on the strength of the address alone.
 */
function dsmMatches(array $row, $search, $moduleWant, array $staffRows, array $catalogue)
{
    if ($search !== '') {
        $hay = strtolower($row['fullname'] . ' ' . $row['person'] . ' '
                        . (isset($row['email']) ? $row['email'] : '') . ' '
                        . $row['position']);
        if (strpos($hay, strtolower($search)) === false) { return false; }
    }
    if ($moduleWant !== '') {
        $k = dsModKey($row['comp_id'], $row['person']);
        if (!dsModFor($moduleWant, $k, $staffRows, $catalogue)['allowed']) {
            return false;
        }
    }
    return true;
}

/* ---------- controller ---------------------------------------------------- */

$error     = null;
$companies = array();
$people    = array();
$staffRows = array();

$ownCompId = isset($currentUser['comp_id']) && $currentUser['comp_id'] !== ''
    ? (int)$currentUser['comp_id']
    : null;
$userId    = isset($currentUser['id']) ? (int)$currentUser['id'] : null;
$userName  = isset($currentUser['person']) ? (string)$currentUser['person'] : '';

/* Who this user may see and who they may edit — the same answer the headcount
 * report gives the same user, because it is the same function. Fails closed. */
$access     = viewScope(Db::get(), $userId, $ownCompId);
$isSuper    = $access['is_super'];
$adminComps = $access['admin_comps'];
$viewComps  = $access['view_comps'];

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
    $error = 'Your account is not linked to a company, so this page has '
           . 'nothing to show. Ask HR to set your company.';
}

$canEditAny = $isSuper || !empty($adminComps);

/* One token per session, checked on every save. Shared with the headcount
 * report on purpose: it is the same session, the same user and the same
 * permission, so a reader who has one page open can save on the other without
 * a reload minting a second token and invalidating the first. */
if (empty($_SESSION['sfr_token'])) {
    $_SESSION['sfr_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['sfr_token'];

/* ---------- save endpoint ------------------------------------------------- */

/** Command: apply a batch of changes and answer in JSON. Everything below
 *  this block is skipped. A batch is one array, so a single box and the bulk
 *  bar take the same path. */
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

    /* The same rule the page itself was rendered under. */
    $viewComp = resolveCompId(
        (!isset($scope['comp_id']) || $scope['comp_id'] === null)
            ? '' : (string)$scope['comp_id'],
        $isSuper, $viewComps, $defaultCompId
    );
    $viewQ      = isset($scope['q']) ? trim((string)$scope['q']) : '';
    $viewModule = isset($scope['module']) ? trim((string)$scope['module']) : '';

    try {
        $db        = Db::get();
        $catalogue = dsModCatalogue($db);
        if (!dsModInstalled()) {
            http_response_code(503);
            $reply(false, 'The module tables are not installed yet. Run '
                        . 'mkCommand_API/sql/staff_summary_module.sql on globportal.');
        }

        $repo = new StaffPoolRepository($db);

        /* THE PEOPLE OF THE VIEW BEING EDITED, loaded ONCE and used twice: to
         * validate every change against somebody really on the list, and
         * afterwards to recount the column totals. Person, company and the
         * tick all have to line up with a row here, so a hand-made POST cannot
         * invent a module for somebody who is not on the daily summary — or
         * for a company this user may not touch. */
        $scopeComps = array();
        foreach ($repo->companies() as $c) {
            $scopeComps[] = (int)$c['comp_id'];
        }
        $ticks    = dsModTicked($db, $viewComp);
        $viewRows = dsmPeople($repo->staff($viewComp, $scopeComps), $ticks);

        /* A batch may name a company the current view does not cover — a super
         * admin editing one company and then switching. Anything unaccounted
         * for is loaded on top. */
        $seenComps = array();
        $pool      = array();
        foreach ($viewRows as $r) {
            $pool[dsModKey($r['comp_id'], $r['person'])] = $r;
            $seenComps[homeCompId($r['comp_id'])] = true;
        }
        $extraComps = array();
        foreach ($items as $it) {
            $c = isset($it['c']) ? homeCompId((int)$it['c']) : 0;
            if (!isset($seenComps[$c])) { $extraComps[$c] = true; }
        }
        if (count($extraComps) > 25) {
            http_response_code(400);
            $reply(false, 'Too many companies in one batch.');
        }
        foreach (array_keys($extraComps) as $cmp) {
            foreach (dsmPeople($repo->staff($cmp), dsModTicked($db, $cmp)) as $r) {
                $pool[dsModKey($r['comp_id'], $r['person'])] = $r;
            }
        }

        $saved   = 0;
        $refused = 0;
        foreach ($items as $it) {
            $comp   = isset($it['c']) ? (int)$it['c'] : 0;
            $person = isset($it['p']) ? (string)$it['p'] : '';
            $module = isset($it['m']) ? (string)$it['m'] : '';
            $allow  = !empty($it['a']) ? 1 : 0;
            $must   = !empty($it['k']) ? 1 : 0;

            /* A module nobody defined, or one that has been retired, is not a
             * thing to be ticked. The catalogue is the list. */
            if (!isset($catalogue[$module])) { $refused++; continue; }
            if (!canEditComp($comp, $isSuper, $adminComps)) { $refused++; continue; }

            $pk = dsModKey($comp, $person);
            if (!isset($pool[$pk])) { $refused++; continue; }

            if (!dsModWrite($db, $comp, $person, $module, $allow, $must,
                            $userName, $userId)) {
                $refused++;
                continue;
            }
            $saved++;
        }

        /* Recount from the database rather than from what was just posted: the
         * page's numbers then come from the same code a reload would run, and
         * a change somebody else made in the meantime shows up too. */
        $freshRows = dsModStaffRows($db, $viewComp);
        $shown     = array();
        foreach ($viewRows as $r) {
            if (dsmMatches($r, $viewQ, $viewModule, $freshRows, $catalogue)) {
                $shown[] = $r;
            }
        }

        $msg = $saved . ($saved === 1 ? ' change saved' : ' changes saved');
        if ($refused > 0) { $msg .= ', ' . $refused . ' refused'; }

        $reply($refused === 0, $msg, array(
            'saved'   => $saved,
            'refused' => $refused,
            'totals'  => array(
                'columns' => dsModColumnTotals($shown, $freshRows, $catalogue),
                'shown'   => count($shown),
                'people'  => count($viewRows),
            ),
        ));
    } catch (Exception $e) {
        error_log('staffSummaryModuleReport save: ' . $e->getMessage());
        http_response_code(500);
        $reply(false, 'Could not save. Check the error log.');
    }
}

/* ---------- load ---------------------------------------------------------- */

$search     = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$moduleWant = isset($_GET['module']) ? trim((string)$_GET['module']) : '';

$catalogue = array();
try {
    if ($noScope) {
        throw new RuntimeException('Viewer has no company');
    }
    $db        = Db::get();
    $catalogue = dsModCatalogue($db);

    $repo      = new StaffPoolRepository($db);
    $companies = $repo->companies();

    $scopeComps = array();
    foreach ($companies as $c) {
        $scopeComps[] = (int)$c['comp_id'];
    }

    $ticks     = dsModTicked($db, $compId);
    $people    = dsmPeople($repo->staff($compId, $scopeComps), $ticks);
    $staffRows = dsModStaffRows($db, $compId);
} catch (Exception $e) {
    error_log('staffSummaryModuleReport: ' . $e->getMessage());
    if ($error === null) {
        $error = 'Could not load the page. Check the error log.';
    }
}

/* A module filter naming something retired or misspelt is dropped rather than
 * erroring, so a stale bookmark just shows the unfiltered list. */
if ($moduleWant !== '' && !isset($catalogue[$moduleWant])) {
    $moduleWant = '';
}

/** Query: the company label for the subtitle. */
$compLabel = 'All companies';
if ($compId !== null) {
    $compLabel = 'Company ' . $compId;
    foreach ($companies as $c) {
        if ((int)$c['comp_id'] === (int)$compId) {
            $compLabel = $c['display_name'];
            break;
        }
    }
}

/* Only "All companies" needs the company under each name. */
if ($compId === null) {
    $compNames = array();
    foreach ($companies as $c) {
        $compNames[(int)$c['comp_id']] = $c['display_name'];
    }
    foreach ($people as &$row) {
        $row['company_name'] = isset($compNames[$row['comp_id']])
            ? $compNames[$row['comp_id']]
            : 'Company ' . $row['comp_id'];
    }
    unset($row);
}

$pickable = array();
foreach ($companies as $c) {
    if ($isSuper || in_array((int)$c['comp_id'], $viewComps, true)) {
        $pickable[] = $c;
    }
}

$shown = array();
foreach ($people as $row) {
    if (dsmMatches($row, $search, $moduleWant, $staffRows, $catalogue)) {
        $shown[] = $row;
    }
}
$filtered = ($search !== '' || $moduleWant !== '');
$colTotals = dsModColumnTotals($shown, $staffRows, $catalogue);

/* ---------- row rendering ------------------------------------------------- */

/**
 * Command: one module cell — the Show box and the Must box.
 *
 * BOTH BOXES CARRY THE WHOLE ANSWER, not just their own half. The save writes
 * one row per (person, module) with both columns in it, so a client sending
 * only the box that moved would blank the other one. Each box therefore reads
 * its partner out of the DOM at submit time; data-pair is how it finds it.
 *
 * MUST IS DISABLED WHILE SHOW IS CLEAR, because "must acknowledge a section
 * you are never shown" is not a state any surface can honour. The value is
 * still submitted and still stored — see dsModWrite() — so switching Show back
 * on restores what Must was.
 */
function renderModuleCell(array $row, $moduleKey, array $staffRows, array $catalogue,
                          $editable)
{
    $k   = dsModKey($row['comp_id'], $row['person']);
    $eff = dsModFor($moduleKey, $k, $staffRows, $catalogue);
    /* The STORED must_ack, not the collapsed one: the box has to show what
     * will come back when Show is ticked again. */
    $storedMust = isset($staffRows[$k][$moduleKey])
        ? $staffRows[$k][$moduleKey]['must_ack']
        : $catalogue[$moduleKey]['default_must_ack'];

    $title = $eff['set']
        ? 'Set by ' . $eff['updated_by'] . ' on ' . fmtDateTime($eff['updated_datetime'])
        : 'Not set for this person — the module default applies';

    $pair = 'm_' . md5($k . '|' . $moduleKey);

    echo '<td class="modcell' . ($eff['set'] ? '' : ' bydefault') . '"'
       . ' title="' . h($title) . '">';

    echo '<label class="mbox"><input type="checkbox" class="show"';
    echo ' data-person="' . h($row['person']) . '"';
    echo ' data-comp="' . (int)$row['comp_id'] . '"';
    echo ' data-module="' . h($moduleKey) . '"';
    echo ' data-pair="' . h($pair) . '"';
    echo $eff['allowed'] ? ' checked' : '';
    echo $editable ? '' : ' disabled';
    echo '><span>Show</span></label>';

    echo '<label class="mbox must"><input type="checkbox" class="must"';
    echo ' data-person="' . h($row['person']) . '"';
    echo ' data-comp="' . (int)$row['comp_id'] . '"';
    echo ' data-module="' . h($moduleKey) . '"';
    echo ' data-pair="' . h($pair) . '"';
    echo $storedMust ? ' checked' : '';
    echo ($editable && $eff['allowed']) ? '' : ' disabled';
    echo '><span>Must</span></label>';

    echo '</td>';
}

/** Command: the rows of the grid. */
function renderRows(array $rows, array $staffRows, array $catalogue,
                    $isSuper, array $adminComps, $canEditAny)
{
    $colspan = 2 + count($catalogue) + ($canEditAny ? 1 : 0);

    if (empty($rows)) {
        echo '<tr><td colspan="' . (int)$colspan . '"><div class="empty">'
           . 'Nobody here. People appear on this page once they are ticked for '
           . 'Daily Summary Report on the headcount report.</div></td></tr>';
        return;
    }

    $i = 0;
    foreach ($rows as $row) {
        /* dsModInstalled() IS PART OF THE TEST, not just a banner at the top.
         * Without the tables the catalogue is the three built-in defaults and
         * there is nowhere to write a change, so every box renders disabled —
         * a box that looks live and silently does nothing is worse than one
         * that says it cannot be used. */
        $editable = $canEditAny && dsModInstalled()
                 && canEditComp($row['comp_id'], $isSuper, $adminComps);
        $i++;
        echo '<tr class="personrow">';
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
        if (isset($row['company_name'])) {
            echo ' · ' . h($row['company_name']);
        }
        echo '</div>';
        /* THE EMAIL IS ON THE ROW BECAUSE ONE MODULE KEYS OFF IT. RO1 Work
         * Achievement finds somebody's districts by matching this address
         * against district_info.managerMail on glob.com.my, so an empty or
         * wrong address here is the difference between a section with figures
         * and a section saying "no districts assigned". It is shown whether or
         * not that module is on, because that is when it is worth noticing. */
        $email = isset($row['email']) ? trim((string)$row['email']) : '';
        echo '<div class="mailline">'
           . ($email === ''
                ? '<span class="nomail">no email on file</span>'
                : h($email))
           . '</div>';
        echo '</td>';

        foreach (array_keys($catalogue) as $moduleKey) {
            renderModuleCell($row, $moduleKey, $staffRows, $catalogue, $editable);
        }
        echo '</tr>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Daily Summary Report — Modules</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
    :root {
        --bg:#f1f5f9; --surface:#fff; --primary:#2563eb; --success:#059669;
        --danger:#dc2626; --warn:#b45309; --text:#1e293b; --muted:#64748b; --border:#e2e8f0;
    }
    * { box-sizing:border-box; }
    body { margin:0; padding:24px; background:var(--bg); color:var(--text);
           font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; }
    .wrap { max-width:1320px; margin:0 auto; }
    .main-content { margin-left:260px; transition:margin-left .3s ease; }
    .main-content.sidebar-collapsed { margin-left:60px; }
    @media (max-width:1024px) { .main-content { margin-left:60px; } }
    @media (max-width:768px)  { .main-content { margin-left:0 !important; } body { padding:12px; } }
    h1 { font-size:20px; margin:0 0 4px; }
    .sub { color:var(--muted); font-size:13px; margin-bottom:18px; }
    .card { background:var(--surface); border:1px solid var(--border); border-radius:12px;
            padding:16px; margin-bottom:16px; }
    .filters { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:10px; }
    input[type=search], input[type=text], select, .btn {
        font:inherit; padding:7px 12px; border-radius:8px;
        border:1px solid var(--border); background:var(--surface); color:var(--text); }
    .btn { text-decoration:none; display:inline-block; cursor:pointer; }
    .btn:disabled { opacity:.5; cursor:default; }
    .backlink { font-size:13px; margin-bottom:14px; display:inline-block; }
    a { color:var(--primary); }
    .warnbox { border-left:4px solid var(--danger); background:rgba(220,38,38,.06); }
    .notebox { border-left:4px solid var(--warn); background:rgba(180,83,9,.06); }
    .scopeline { color:var(--muted); font-size:12px; margin-bottom:16px; }

    table { width:100%; border-collapse:collapse; }
    th { text-align:left; font-size:12px; text-transform:uppercase; letter-spacing:.03em;
         color:var(--muted); padding:8px 10px; border-bottom:2px solid var(--border);
         vertical-align:bottom; }
    th.modcol { text-align:center; width:132px; }
    th.modcol .t { display:block; text-transform:none; font-size:12.5px;
                   letter-spacing:0; color:var(--text); font-weight:700; }
    th.modcol .src { display:block; text-transform:none; letter-spacing:0;
                     font-size:10.5px; font-weight:400; margin-top:2px; }
    th.modcol .src.remote { color:var(--warn); font-weight:700; }
    td { padding:9px 10px; border-bottom:1px solid var(--border); vertical-align:middle; }
    tr:last-child td { border-bottom:none; }
    .idx { color:var(--muted); font-variant-numeric:tabular-nums; width:42px; }
    .selcell { width:34px; text-align:center; }
    .nm { font-weight:500; }
    .muted { color:var(--muted); font-size:12px; }
    .mailline { font-size:11.5px; color:var(--muted); font-family:ui-monospace,
                SFMono-Regular,Menlo,Consolas,monospace; }
    .nomail { color:var(--danger); font-family:inherit; font-style:italic; }
    .empty { color:var(--muted); text-align:center; padding:26px 0; }

    /* One cell, two boxes: Show above, Must below and dependent on it. */
    .modcell { text-align:center; white-space:nowrap; }
    .mbox { display:flex; align-items:center; justify-content:center; gap:6px;
            cursor:pointer; font-size:11.5px; }
    .mbox input { width:15px; height:15px; accent-color:var(--primary); margin:0; }
    .mbox input[disabled] { cursor:default; }
    .mbox span { color:var(--muted); }
    .mbox input:checked ~ span { color:var(--text); font-weight:700; }
    .mbox.must { margin-top:3px; }
    .mbox.must input { accent-color:var(--warn); }
    .mbox.must input:checked ~ span { color:var(--warn); }
    .mbox.must input[disabled] ~ span { opacity:.45; }
    /* A cell nobody has decided about is drawn faintly, so "this is the
       module's default" and "somebody chose this" are distinguishable. */
    .modcell.bydefault .mbox span { opacity:.75; }
    .modcell.bydefault { background:rgba(100,116,139,.035); }

    .totalrow td { font-weight:700; background:#f8fafc; border-top:2px solid var(--border);
                   border-bottom:none; font-variant-numeric:tabular-nums; }
    .totalrow .modcell { font-size:12px; }
    .totalrow .tshow { color:var(--primary); }
    .totalrow .tmust { color:var(--warn); }
    .rowbusy td { background:rgba(37,99,235,.07); }
    .rowsaved td { background:rgba(5,150,105,.09); }

    .bulk { position:sticky; bottom:12px; z-index:5; display:none; gap:10px; flex-wrap:wrap;
            align-items:center; background:var(--text); color:#fff; padding:10px 14px;
            border-radius:12px; margin:12px 0; box-shadow:0 6px 24px rgba(15,23,42,.25); }
    .bulk.show { display:flex; }
    .bulk select { background:#fff; color:var(--text); }
    .bulk .count { font-weight:700; }
    .bulk .btn { background:var(--primary); border-color:var(--primary); color:#fff; font-weight:600; }
    .bulk .btn.ghost { background:transparent; border-color:rgba(255,255,255,.45); color:#fff; }

    details.apicard { background:var(--surface); border:1px solid var(--border);
                      border-radius:12px; padding:0 16px; margin-bottom:16px; }
    details.apicard > summary { cursor:pointer; padding:14px 0; font-weight:600;
                                list-style:none; display:flex; align-items:center; gap:8px; }
    details.apicard > summary::-webkit-details-marker { display:none; }
    details.apicard > summary::before { content:'\25B8'; color:var(--muted); }
    details.apicard[open] > summary::before { content:'\25BE'; }
    details.apicard table { margin-bottom:14px; }
    .url { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
           font-size:11.5px; word-break:break-all; }
    .tag { display:inline-block; padding:1px 8px; border-radius:99px; font-size:11px;
           font-weight:700; background:rgba(37,99,235,.10); color:var(--primary); }
    .tag.remote { background:rgba(180,83,9,.12); color:var(--warn); }
    .tag.off { background:rgba(100,116,139,.12); color:var(--muted); }

    #toast { position:fixed; left:50%; bottom:26px; transform:translateX(-50%) translateY(20px);
             background:var(--text); color:#fff; padding:10px 16px; border-radius:10px;
             font-size:13px; opacity:0; pointer-events:none; transition:all .18s ease; z-index:50; }
    #toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
    #toast.bad { background:var(--danger); }

    @media print {
        body { padding:0; background:#fff; }
        .filters, .btn, .bulk, .selcell, details.apicard, .backlink { display:none !important; }
    }
</style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
<div class="wrap">

<a class="backlink" href="<?php echo h(DSM_BACK_FILE); ?>">&larr; Staff Headcount Report</a>

<h1>Daily Summary Report — Modules</h1>
<div class="sub">
    Which sections each person on the daily summary list is shown, and which of
    them they must acknowledge before the day is finished.
</div>

<?php if ($error !== null): ?>
    <div class="card warnbox"><?php echo h($error); ?></div>
<?php else: ?>

<?php if (!dsModInstalled()): ?>
    <div class="card warnbox">
        The module tables are not installed yet, so this page is showing the
        three original sections as read-only defaults and nothing can be saved.
        Run <code>mkCommand_API/sql/staff_summary_module.sql</code> on
        globportal, then reload.
    </div>
<?php endif; ?>

<?php if (!dsModListInstalled()): ?>
    <div class="card warnbox">
        <code>staff_portal2.users.daily_summary_report</code> is not there yet,
        so nobody is on the daily summary list and this page has no rows. Run
        <code>mkCommand_API/sql/daily_summary_report.sql</code> on globportal,
        tick people on the headcount report, then come back.
    </div>
<?php endif; ?>

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
        <select disabled title="Only KPI super admins and company admins can change the company">
            <option><?php echo h($compLabel); ?></option>
        </select>
    <?php endif; ?>

    <input type="search" name="q" value="<?php echo h($search); ?>"
           placeholder="Search name, login or email" style="min-width:220px">

    <select name="module" onchange="this.form.submit()">
        <option value="">All modules</option>
        <?php foreach ($catalogue as $key => $mod): ?>
            <option value="<?php echo h($key); ?>"
                <?php echo ($moduleWant === $key ? 'selected' : ''); ?>>
                Has <?php echo h($mod['title']); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <button type="submit" class="btn">Apply</button>
    <?php if ($filtered): ?>
        <a class="btn" href="<?php echo h(selfUrl(array('q' => null, 'module' => null))); ?>">Clear</a>
    <?php endif; ?>
</form>

<div class="scopeline">
    <?php echo h($compLabel); ?> —
    <strong id="s_people"><?php echo count($people); ?></strong>
    <?php echo count($people) === 1 ? 'person' : 'people'; ?> on the daily summary list,
    <?php echo count($catalogue); ?>
    <?php echo count($catalogue) === 1 ? 'module' : 'modules'; ?> available.
    <?php if ($filtered): ?>
        Showing <span id="s_shown"><?php echo count($shown); ?></span>
        of <?php echo count($people); ?>.
    <?php endif; ?>
    <?php if (!$canEditAny): ?>
        <br>Read-only: ticking is for KPI super admins and company admins.
    <?php endif; ?>
</div>

<div class="card">
    <table>
        <thead>
        <tr>
            <th class="idx">No.</th>
            <?php if ($canEditAny): ?><th class="selcell"></th><?php endif; ?>
            <th>Staff</th>
            <?php foreach ($catalogue as $key => $mod): ?>
                <th class="modcol" title="<?php echo h($mod['api_url']); ?>">
                    <span class="t"><?php echo h($mod['title']); ?></span>
                    <span class="src<?php echo $mod['built_in'] ? '' : ' remote'; ?>"><?php
                        echo h($mod['api_server'] !== '' ? $mod['api_server'] : 'no source set');
                    ?></span>
                </th>
            <?php endforeach; ?>
        </tr>
        </thead>
        <tbody>
        <?php renderRows($shown, $staffRows, $catalogue, $isSuper, $adminComps,
                         $canEditAny); ?>
        </tbody>
        <?php if (!empty($shown)): ?>
        <tfoot>
        <tr class="totalrow">
            <td class="idx"></td>
            <?php if ($canEditAny): ?><td class="selcell"></td><?php endif; ?>
            <td>Total</td>
            <?php foreach (array_keys($catalogue) as $key): ?>
                <td class="modcell">
                    <span class="tshow" id="c_show_<?php echo h($key); ?>"><?php
                        echo (int)$colTotals[$key]['allowed']; ?></span> shown<br>
                    <span class="tmust" id="c_must_<?php echo h($key); ?>"><?php
                        echo (int)$colTotals[$key]['must_ack']; ?></span> must
                </td>
            <?php endforeach; ?>
        </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>

<?php if ($canEditAny && dsModInstalled()): ?>
<div class="bulk" id="bulkbar">
    <span class="count" id="bulkcount">0 selected</span>
    <span>Set</span>
    <select id="bulkmodule">
        <?php foreach ($catalogue as $key => $mod): ?>
            <option value="<?php echo h($key); ?>"><?php echo h($mod['title']); ?></option>
        <?php endforeach; ?>
    </select>
    <span>to</span>
    <select id="bulkvalue">
        <option value="show_must">Shown, must acknowledge</option>
        <option value="show_only">Shown, acknowledgement optional</option>
        <option value="hide">Not shown</option>
    </select>
    <button type="button" class="btn" id="bulkapply">Apply</button>
    <button type="button" class="btn ghost" id="bulkclear">Clear selection</button>
</div>
<?php endif; ?>

<details class="apicard">
    <summary>Where each module's figures come from (<?php echo count($catalogue); ?>)</summary>
    <table>
        <thead>
        <tr>
            <th style="width:60px">Order</th>
            <th style="width:200px">Module</th>
            <th style="width:110px">Built by</th>
            <th>Endpoint</th>
            <th style="width:150px">Default for a new person</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($catalogue as $key => $mod): ?>
            <tr>
                <td class="muted"><?php echo (int)$mod['sort_order']; ?></td>
                <td>
                    <span class="nm"><?php echo h($mod['title']); ?></span>
                    <div class="muted"><?php echo h($key); ?></div>
                </td>
                <td><span class="tag<?php echo $mod['built_in'] ? '' : ' remote'; ?>"><?php
                    echo $mod['built_in'] ? 'the cron' : 'fetched'; ?></span></td>
                <td class="url"><?php
                    echo $mod['api_url'] === ''
                        ? '<span class="nomail">no endpoint set</span>'
                        : h($mod['api_url']); ?></td>
                <td class="muted"><?php
                    if (!$mod['default_allowed']) {
                        echo '<span class="tag off">not shown</span>';
                    } elseif ($mod['default_must_ack']) {
                        echo 'shown, must acknowledge';
                    } else {
                        echo 'shown, optional';
                    }
                ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="muted" style="margin-bottom:14px">
        "Built by the cron" means staffSummaryCron.php assembles that section
        from the snapshot it already builds each morning. "Fetched" means the
        endpoint above is called for the figures — which is how a module on
        another server works at all. Change an address with an UPDATE on
        <code>mkPortal.staff_summary_module</code>; there is a worked example at
        the foot of <code>sql/staff_summary_module.sql</code>.
    </div>
</details>

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
    var CAN_EDIT = <?php echo ($canEditAny && dsModInstalled()) ? 'true' : 'false'; ?>;

    /* The view the server must recount after a save. Sent with every save
     * because the save URL carries no filters of its own. */
    var SCOPE = <?php echo json_encode(array(
        'comp_id' => $compId === null ? '' : (int)$compId,
        'q'       => $search,
        'module'  => $moduleWant,
    )); ?>;

    /* With a module filter applied a change can move a row out of the list,
     * which no amount of recounting can show, so that reloads instead. */
    var RELOAD_ON_SAVE = <?php echo $moduleWant !== '' ? 'true' : 'false'; ?>;

    var MODULES = <?php echo json_encode(array_keys($catalogue)); ?>;

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
        if (el && value !== undefined && value !== null) { el.textContent = String(value); }
    }

    /* The scope line and the table foot are rendered by PHP, so after a save
     * they are stale until these numbers — recounted by the same PHP — are
     * written back over them. */
    function applyTotals(t) {
        if (!t) { return; }
        setNum('s_shown', t.shown);
        setNum('s_people', t.people);
        if (!t.columns) { return; }
        MODULES.forEach(function (k) {
            if (!t.columns[k]) { return; }
            setNum('c_show_' + k, t.columns[k].allowed);
            setNum('c_must_' + k, t.columns[k].must_ack);
        });
    }

    /* Query: the Must box that belongs to this Show box, and the other way
     * round. They are one database row, so neither can be sent without the
     * other — see renderModuleCell() for why. */
    function partner(box) {
        var want = box.classList.contains('show') ? 'must' : 'show';
        return document.querySelector(
            'input.' + want + '[data-pair="' + box.getAttribute('data-pair') + '"]');
    }

    /* Command: Must follows Show's state. A section nobody is shown cannot be
     * one they must acknowledge, so the box is disabled — but its CHECKED
     * state is left alone, because that is what the server stores and what
     * comes back when Show is ticked again. */
    function reflectShow(showBox) {
        var must = partner(showBox);
        if (!must) { return; }
        must.disabled = !showBox.checked || !CAN_EDIT;
    }

    function itemOf(box) {
        var show = box.classList.contains('show') ? box : partner(box);
        var must = box.classList.contains('must') ? box : partner(box);
        return {
            p: box.getAttribute('data-person'),
            c: parseInt(box.getAttribute('data-comp'), 10),
            m: box.getAttribute('data-module'),
            a: (show && show.checked) ? 1 : 0,
            k: (must && must.checked) ? 1 : 0
        };
    }

    /* One POST, any number of changes. onDone(ok) so a caller can roll its own
     * check boxes back when the server refuses. */
    function save(items, rows, onDone) {
        rows.forEach(function (r) { if (r) { r.classList.add('rowbusy'); } });

        var body = new FormData();
        body.append('token', TOKEN);
        body.append('payload', JSON.stringify(items));
        body.append('scope', JSON.stringify(SCOPE));

        fetch(SAVE_URL, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                rows.forEach(function (r) {
                    if (!r) { return; }
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
            .catch(function () {
                rows.forEach(function (r) { if (r) { r.classList.remove('rowbusy'); } });
                toast('Could not reach the server — nothing was saved.', true);
                onDone(false);
            });
    }

    /* --- single tick ---------------------------------------------------- */
    document.addEventListener('change', function (e) {
        var box = e.target;
        if (!CAN_EDIT || box.type !== 'checkbox' || !box.hasAttribute('data-module')) { return; }

        var isShow = box.classList.contains('show');
        var was    = !box.checked;
        var mate   = partner(box);
        var mateWasDisabled = mate ? mate.disabled : false;

        if (isShow) { reflectShow(box); }
        /* The cell stops being "the module default" the moment it is saved. */
        var cell = box.closest('td');

        save([itemOf(box)], [box.closest('tr')], function (ok) {
            if (ok) {
                if (cell) { cell.classList.remove('bydefault'); }
                return;
            }
            box.checked = was;
            if (mate) { mate.disabled = mateWasDisabled; }
        });
    });

    if (!CAN_EDIT) { return; }

    /* --- selection ------------------------------------------------------ */
    var bar   = document.getElementById('bulkbar');
    var count = document.getElementById('bulkcount');
    if (!bar) { return; }

    function selected() {
        return Array.prototype.slice.call(
            document.querySelectorAll('tr.personrow .rowsel:checked'));
    }

    function refreshBar() {
        var n = selected().length;
        count.textContent = n + ' selected';
        bar.classList.toggle('show', n > 0);
    }

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('rowsel')) { refreshBar(); }
    });

    document.getElementById('bulkclear').addEventListener('click', function () {
        selected().forEach(function (s) { s.checked = false; });
        refreshBar();
    });

    /* --- bulk apply ------------------------------------------------------ */
    document.getElementById('bulkapply').addEventListener('click', function () {
        var module = document.getElementById('bulkmodule').value;
        var mode   = document.getElementById('bulkvalue').value;
        var want   = {
            show_must: { a: 1, k: 1 },
            show_only: { a: 1, k: 0 },
            hide:      { a: 0, k: 0 }
        }[mode];
        if (!want) { return; }

        var rows  = selected().map(function (s) { return s.closest('tr'); });
        var items = [], undo = [];

        rows.forEach(function (row) {
            var show = row.querySelector('input.show[data-module="' + module + '"]');
            var must = row.querySelector('input.must[data-module="' + module + '"]');
            if (!show || show.disabled) { return; }

            undo.push({ show: show, must: must,
                        wasShow: show.checked, wasMust: must ? must.checked : false,
                        wasMustDisabled: must ? must.disabled : false });

            show.checked = !!want.a;
            /* 'hide' LEAVES MUST AS IT WAS, deliberately: the stored value is
             * what comes back when the module is switched on again, and
             * clearing it here would lose a setting the user did not touch.
             * The server stores Must as sent and collapses it on read. */
            if (must && mode !== 'hide') { must.checked = !!want.k; }
            reflectShow(show);

            items.push(itemOf(show));
        });

        if (!items.length) { toast('Nothing to change in that column.', true); return; }

        save(items, rows, function (ok) {
            if (ok) {
                rows.forEach(function (row) {
                    var cell = row.querySelector('input.show[data-module="' + module + '"]');
                    if (cell && cell.closest('td')) {
                        cell.closest('td').classList.remove('bydefault');
                    }
                });
                return;
            }
            undo.forEach(function (u) {
                u.show.checked = u.wasShow;
                if (u.must) { u.must.checked = u.wasMust; u.must.disabled = u.wasMustDisabled; }
            });
        });
    });
}());
</script>
</body>
</html>
