<?php
session_start();

require_once '../includes/UnifiedAuth.php';

if (!UnifiedAuth::isLoggedIn()) {
    header('Location: /accounts/mkPortal/user_validation.php');
    exit;
}

$currentUser = UnifiedAuth::getLoggedInUser();
// $person = $currentUser['person'];
$person = 'justin';   // TODO: restore the line above before deploying

// Only Heads of Department and Area Managers / Assistant Area Managers can use this page.
// Add any other person ids that should also be allowed (e.g. the CEO) here.
$allowPersons = array('khadijah', 'chuasf', 'nathan');

// staffTree.php — same folder as staffPoolAPI.php (accounts/mkPortal/hr/)
$comp_id = isset($_GET['comp_id']) ? (int)$_GET['comp_id'] : 1;

// staff_tree=all returns HODs and Area Managers but not the CEO, so set the top node here.
// The CEO card (and the full structure) is shown to this person and to everyone in $allowPersons.
// 'person' = his login id; change it if different.
$top = array(
  'person'   => 'lawrence',
  'name'     => 'Dr. Lawrence Chu',
  'position' => 'Chief Executive Officer',
  'unit'     => 'Management',
);
// The CEO and everyone in $allowPersons see the full structure starting from the CEO card.
$isTop = (strtolower((string)$person) === strtolower($top['person']))
      || in_array(strtolower((string)$person), array_map('strtolower', $allowPersons), true);
$topInitials = '';
$words = preg_split('/\s+/', trim(preg_replace('/^Dr\.?\s+/i', '', $top['name'])));
foreach (array_slice($words, 0, 2) as $w) {
  if ($w !== '') { $topInitials .= strtoupper(substr($w, 0, 1)); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Staff Structure</title>
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
    .filters { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    input[type=search], select, .btn { font:inherit; padding:7px 12px; border-radius:8px;
        border:1px solid var(--border); background:var(--surface); color:var(--text); }
    .btn { cursor:pointer; }
    .btn.on { background:var(--primary); border-color:var(--primary); color:#fff; font-weight:600; }
    input[type=search] { min-width:220px; }
    .spacer { flex:1; }
    .plabel { color:var(--muted); font-size:13px; }

    .tiles { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
    .tile { flex:1; min-width:150px; background:var(--surface); border:1px solid var(--border);
            border-radius:12px; padding:14px; }
    button.tile { font:inherit; color:inherit; text-align:left; cursor:pointer; }
    button.tile:hover { background:rgba(37,99,235,.05); }
    button.tile:focus-visible { outline:2px solid var(--primary); outline-offset:2px; }
    .tile.on { border-color:var(--primary); box-shadow:0 0 0 1px var(--primary) inset; }
    .tile .n { font-size:26px; font-weight:700; line-height:1.1; }
    .tile .l { font-size:12px; color:var(--muted); margin-top:2px; }
    .n.mkc { color:var(--primary); } .n.mka { color:var(--success); } .n.kpi { color:var(--warn); }

    .chip { display:inline-block; padding:1px 8px; border-radius:99px; font-size:11.5px;
            font-weight:700; background:rgba(37,99,235,.10); color:var(--primary); }
    .gwtag { display:inline-block; padding:2px 9px; border-radius:99px; font-size:11.5px;
             font-weight:700; background:rgba(202,138,4,.12); color:#ca8a04; }
    .muted { color:var(--muted); font-size:12px; }
    a { color:var(--primary); text-decoration:none; }

    /* department tree */
    .avatar { flex:none; width:38px; height:38px; border-radius:50%; display:grid; place-items:center;
              color:#fff; font-weight:600; font-size:13px; }
    .top { display:flex; align-items:center; gap:12px; padding:12px 14px; border-radius:10px;
           background:rgba(37,99,235,.07); border:2px solid var(--primary); }
    .top .avatar { width:46px; height:46px; background:var(--primary); font-size:16px; }
    .tree { margin:0 0 0 20px; padding:10px 0 0 20px; border-left:2px solid var(--border); }
    .node { position:relative; margin-bottom:8px; }
    .node::before { content:""; position:absolute; left:-20px; top:26px; width:18px; border-top:2px solid var(--border); }
    .row { width:100%; display:flex; align-items:center; gap:12px; text-align:left; font:inherit; color:inherit;
           background:#f8fafc; border:1px solid var(--border); border-radius:10px; padding:10px 12px; cursor:pointer; }
    .row:hover { background:rgba(37,99,235,.05); }
    .row.open { background:var(--surface); border-color:var(--primary); }
    #root.plain > .node::before { display:none; }
    .row.anc { cursor:default; background:var(--surface); border-style:dashed; }
    .row.anc:hover { background:var(--surface); border-color:var(--border); }
    .row:focus-visible, .btn:focus-visible, input:focus-visible, select:focus-visible, .tg:focus-visible { outline:2px solid var(--primary); outline-offset:2px; }
    .ico { flex:none; color:var(--primary); font-size:18px; width:22px; text-align:center; }
    .body { flex:1; min-width:0; }
    .title { display:flex; align-items:center; gap:8px; font-weight:600; flex-wrap:wrap; }
    .line { color:var(--muted); font-size:12.5px; margin-top:1px; }
    .go { flex:none; width:28px; height:28px; border-radius:50%; border:1.5px solid var(--primary);
          color:var(--primary); display:grid; place-items:center; font-size:11px; transition:transform .15s; }
    .row.open .go { transform:rotate(90deg); background:var(--primary); color:#fff; }
    .state { padding:8px 12px; color:var(--muted); font-size:13px; }
    .err { color:var(--danger); }
    .empty { color:var(--muted); text-align:center; padding:26px 0; }
    .foot { color:var(--muted); font-size:12px; text-align:right; margin-top:10px; }

    /* staff table */
    .tbox { margin-top:6px; overflow-x:auto; border:1px solid var(--border); border-radius:10px; background:var(--surface); }
    .tbox[hidden] { display:none; }
    table.stbl { width:100%; border-collapse:collapse; }
    .stbl th { text-align:left; font-size:12px; text-transform:uppercase; letter-spacing:.03em;
               color:var(--muted); padding:8px 10px; border-bottom:2px solid var(--border); background:#f8fafc; white-space:nowrap; }
    .stbl th.c, .stbl td.c { text-align:center; width:140px; }
    .stbl th.no { width:64px; }
    .stbl td { padding:8px 10px; border-bottom:1px solid var(--border); vertical-align:middle; }
    .stbl tr:last-child td { border-bottom:none; }
    .stbl td.no { color:var(--muted); font-variant-numeric:tabular-nums; }
    .stbl td.nm.has { cursor:pointer; }
    .stbl tr.srow:hover td { background:rgba(37,99,235,.04); }
    .stbl tr.hrow td { background:rgba(37,99,235,.06); }
    .stbl tr.hrow td.no { color:var(--primary); font-weight:700; font-size:11.5px; }
    .stbl tr.srow.open > td.nm .tg i { transform:rotate(90deg); }
    .nmin { display:flex; align-items:center; gap:8px; }
    .nmt { font-weight:500; }
    .avatar.sm { width:30px; height:30px; font-size:11px; }
    .tg { flex:none; width:22px; height:22px; border:0; background:transparent; color:var(--primary); cursor:pointer; padding:0; }
    .tg i { font-size:11px; transition:transform .15s; }
    .tg.sp { cursor:default; }
    td.cell { cursor:pointer; color:var(--muted); }
    td.cell:hover { background:rgba(37,99,235,.08); }
    .lg { display:inline-block; min-width:26px; padding:0 8px; border-radius:99px; font-size:12px; font-weight:700; }
    .lg.late  { background:rgba(180,83,9,.12);  color:var(--warn); }
    .lg.leave { background:rgba(37,99,235,.10); color:var(--primary); }
    .lg.ok    { background:rgba(5,150,105,.10); color:var(--success); }
    .lg.bad   { background:rgba(220,38,38,.09); color:var(--danger); }
    .lg.zero  { background:#f1f5f9; color:var(--muted); }
    .st { display:inline-flex; align-items:center; gap:5px; min-width:64px; justify-content:center; padding:2px 10px; border-radius:99px; font-size:12px; font-weight:700; font-variant-numeric:tabular-nums; }
    .st.ok   { background:rgba(5,150,105,.12); color:var(--success); }
    .st.bad  { background:rgba(220,38,38,.10); color:var(--danger); }
    .st.ph   { background:rgba(124,58,237,.12); color:#7c3aed; }
    .st.none { background:#f1f5f9; color:var(--muted); }
    .tdy { display:block; margin-top:2px; font-size:11px; color:var(--muted); font-weight:500; }
    /* calendar modal */
    .mdl { position:fixed; inset:0; background:rgba(15,23,42,.45); display:flex; align-items:center; justify-content:center; z-index:100; padding:16px; }
    .mdl[hidden] { display:none; }
    .mbox { background:var(--surface); border-radius:14px; max-width:860px; width:100%; max-height:90vh; overflow:auto; padding:16px 18px; }
    .mhead { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; margin-bottom:12px; }
    .mhead strong { font-size:16px; }
    .mnav { display:flex; align-items:center; gap:6px; }
    .mnav button { width:32px; height:32px; padding:0; }
    #mlabel { min-width:120px; text-align:center; font-weight:600; }
    .legend { display:flex; gap:14px; flex-wrap:wrap; font-size:12.5px; color:var(--muted); margin-bottom:10px; }
    .legend .st { min-width:0; }
    .cal { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
    .dow { font-size:11.5px; font-weight:700; color:var(--muted); text-align:center; padding:2px 0; }
    .cday { min-height:70px; border:1px solid var(--border); border-radius:8px; padding:4px 6px; font-size:12px; background:#fff; }
    .cday.blank { border:none; background:transparent; }
    .cday .d { font-weight:600; color:var(--muted); font-variant-numeric:tabular-nums; }
    .cday .v { display:block; margin-top:2px; font-weight:700; font-variant-numeric:tabular-nums; }
    .cday .v.ms { font-size:10.5px; line-height:1.25; }
    .sh { font-weight:600; font-size:12.5px; margin:10px 0 4px; color:var(--text); }
    .cday.lv  { background:rgba(37,99,235,.10); border-color:var(--primary); color:var(--primary); }
    .cday.lv .d { color:inherit; }
    .tags { float:right; display:flex; gap:3px; align-items:center; }
    .wn { font-size:12px; line-height:1; }
    .lvp {
          display: block;
          margin-top: 4px;
          padding: 2px 4px;
          background: #dbeafe;
          color: #1d4ed8;
          border-radius: 4px;
          font-size: 10px;
          font-weight: 600;
          line-height: 1.3;
          text-align: center;
      }
    .pm-attendance {
        display: block;
        margin-top: 4px;
        overflow-wrap: anywhere;
    }

    .pm-attendance .st.ok,
    .pm-attendance .st.bad {
        background: transparent !important;
        box-shadow: none !important;
        border: none !important;
    }
    .pmrow { display:block; margin-top:4px; }
    .mlists { margin-top:14px; }
    .mh { font-weight:700; font-size:13px; margin:12px 0 4px; }
    .ctbl { width:100%; border-collapse:collapse; font-size:13px; }
    .ctbl th { text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:var(--muted); padding:5px 8px; border-bottom:2px solid var(--border); }
    .ctbl td { padding:6px 8px; border-bottom:1px solid var(--border); vertical-align:top; }
    .cday.ph  { background:rgba(124,58,237,.10); border-color:#7c3aed; color:#7c3aed; }
    .cday.ph .d { color:inherit; }
    .pht { float:right; font-size:10px; font-weight:700; color:#7c3aed; }
    .cday.ok  { background:rgba(5,150,105,.12); border-color:var(--success); color:var(--success); }
    .cday.bad { background:rgba(220,38,38,.10); border-color:var(--danger);  color:var(--danger); }
    .cday.ok .d, .cday.bad .d { color:inherit; }
    .cday.today { box-shadow:0 0 0 2px var(--primary) inset; }
    .msum { margin-top:12px; font-size:13px; color:var(--muted); }
    @media (max-width:600px) { .cday { min-height:48px; padding:3px 4px; } }
    .dtitle { font-weight:600; font-size:13px; margin-bottom:4px; }
    .dlist div { padding:3px 0; font-size:13px; }
    @media (max-width:600px) { .tree { margin-left:8px; padding-left:12px; } .node::before { left:-12px; width:10px; } }
    @media print { body { padding:0; background:#fff; } .filters { display:none !important; } .tbox[hidden] { display:block !important; } }
</style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
<div class="wrap">

<h1>Staff Structure</h1>
<div class="sub">Open a department to see its staff, or use the totals above to expand everything at once. Click a name with an arrow to see who reports to them, or a Lateness / Excessive Leave / Compliance cell to open that person’s month calendar.</div>

<div class="tiles" id="tiles"></div>

<div class="card">
  <div class="filters">
    <div class="spacer"></div>
    <span class="plabel">Period</span>
    <select id="fm"></select>
    <select id="fy"></select>
    <input type="search" id="q" placeholder="Search department or name">
  </div>
</div>

<div class="card">
<?php if ($isTop): ?>
  <div class="top">
    <div class="avatar"><?php echo htmlspecialchars($topInitials); ?></div>
    <div>
      <strong><?php echo htmlspecialchars($top['name']); ?></strong>
      <div class="line"><?php echo htmlspecialchars($top['position']); ?> · <?php echo htmlspecialchars($top['unit']); ?></div>
    </div>
  </div>
<?php endif; ?>
  <div class="<?php echo $isTop ? 'tree' : 'plain'; ?>" id="root"><div class="state">Loading…</div></div>
  <div class="foot" id="foot"></div>
</div>

</div>
</div>

<div class="mdl" id="mdl" hidden>
  <div class="mbox" role="dialog" aria-modal="true" aria-labelledby="mtitle">
    <div class="mhead">
      <div><strong id="mtitle"></strong><div class="muted" id="msub"></div></div>
      <div class="mnav">
        <button class="btn" id="mprev" aria-label="Previous month"><i class="fa-solid fa-chevron-left"></i></button>
        <span id="mlabel"></span>
        <button class="btn" id="mnext" aria-label="Next month"><i class="fa-solid fa-chevron-right"></i></button>
        <button class="btn" id="mclose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>
    </div>
    <div id="mbody"></div>
  </div>
</div>

<script>
const API = 'staffPoolAPI.php', COMP = <?php echo $comp_id; ?>;
const ME = <?php echo json_encode($person); ?>;
const EXTRA = <?php echo json_encode($allowPersons); ?>;
const IS_TOP = <?php echo json_encode($isTop); ?>;      // the CEO sees every department
const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const DOW = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
const treeCache = {};          // person -> subtree from staff_tree_individual
const hodCount = {};           // person -> direct subordinates, from staff_tree=all
const rowData = {};            // row id -> {p, subs, no}
const monthCache = {};         // 'YYYY-M' -> { person: {late:[], leave:[], compliance:[]} }
const asked = {};              // 'YYYY-M' -> { person: true }
const mkIdOf = {};             // person -> mk_id (needed by performanceAPI.php)
let data = null, uid = 0;

const $ = id => document.getElementById(id);
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const initials = n => n.replace(/^(Dr|Mr|Ms|Mrs)\.?\s+/i,'').split(/[\s@]+/).filter(Boolean).slice(0,2).map(w => w[0]).join('').toUpperCase();
const hue = s => { let h = 0; for (const c of s) h = (h * 31 + c.charCodeAt(0)) % 360; return h; };
const photoUrl = p => (p && p.staff_picture_url) ? String(p.staff_picture_url) : null;   // from staffPoolAPI
// Nested trees can report 0 for people who are HODs/AMs of their own department,
// so also use their count from staff_tree=all and any nested children.
const cnt = p => Math.max(p.direct_subordinates || 0, (p.subordinates || []).length, hodCount[p.person] || 0);
const ym = () => $('fy').value + '-' + $('fm').value;
const fmtDate = s => { const d = new Date(s + 'T00:00:00'); return DOW[d.getDay()] + ', ' + d.getDate() + ' ' + MONTHS[d.getMonth()].slice(0, 3); };

// Turns every YYYY-MM-DD in a string into DD/MM/YYYY (any time part is kept).
const dmy = v => String(v == null ? '' : v).replace(/(\d{4})-(\d{2})-(\d{2})/g, '$3/$2/$1');

async function api(qs) {
  const r = await fetch(API + '?comp_id=' + COMP + '&' + qs, {credentials:'same-origin'});
  if (!r.ok) throw new Error('HTTP ' + r.status);
  const j = await r.json();
  if (!j.success) throw new Error(j.message || 'API returned success=false');
  return j;
}
async function fetchTree(person) {
  if (!treeCache[person]) treeCache[person] = (await api('staff_tree_individual=' + encodeURIComponent(person))).staff_tree.staff;
  return treeCache[person];
}

// ---------- Lateness (attendanceAPI.php) ----------
// staff=<person> returns that person plus everyone under them, so one call per opened department
// covers the whole branch. Result is cached by month: attCache['2026-10'][person] = {summary, days:{1..31}}.
const ATT_API = 'attendanceAPI.php';
const attCache = {}, attReq = {};
let attRules = { late_from: '08:01:00' };

async function loadAtt(staff, y, m) {
  const k = y + '-' + m + '|' + staff;
  if (!attReq[k]) {
    attReq[k] = (async () => {
      const r = await fetch(ATT_API + '?comp_id=' + COMP + '&staff=' + encodeURIComponent(staff) + '&year=' + y + '&month=' + m, {credentials:'same-origin'});
      if (!r.ok) throw new Error('HTTP ' + r.status);
      const j = await r.json();
      if (!j.success) throw new Error(j.message || 'API returned success=false');
      const c = attCache[y + '-' + m] = attCache[y + '-' + m] || {};
      (j.staff || []).forEach(s => { c[s.person] = s; });
      if (j.rules) attRules = j.rules;
    })().catch(e => { delete attReq[k]; throw e; });
  }
  return attReq[k];
}
const attMiss = {};   // 'YYYY-M' -> { person: true } when attendance was requested but nothing came back
// If the branch call didn't return someone who is on screen, ask for that person on their own.
async function fillAttGaps(y, m) {
  const k = y + '-' + m, miss = attMiss[k] = attMiss[k] || {}, need = [];
  document.querySelectorAll('#root tr.srow').forEach(tr => {
    const p = tr.dataset.person;
    if (!((attCache[k] || {})[p]) && !miss[p] && need.indexOf(p) < 0) need.push(p);
  });
  await pool(need, 3, async p => {
    try { await loadAtt(p, y, m); } catch (e) {}
    if (!((attCache[k] || {})[p])) miss[p] = true;
  });
}
function loadAttFor(staff) {
  const y = +$('fy').value, m = +$('fm').value;
  loadAtt(staff, y, m).catch(() => {})
    .then(() => fillAttGaps(y, m))
    .then(() => { if (y === +$('fy').value && m === +$('fm').value) refreshCells(); });
}

// ---------- Compliance: demerit records (performanceAPI.php) ----------
// One call per person per month. Needs the person's mk_id, which is remembered from the staff rows (mkIdOf).
const PERF_API = 'performanceAPI.php';
async function loadDemerit(person, year, month) {
  const mkId = mkIdOf[person];
  if (!mkId) return [];

  const r = await fetch(PERF_API + '?comp_id=' + COMP +
    '&mk_id=' + encodeURIComponent(mkId) +
    '&year=' + encodeURIComponent(year) +
    '&month=' + encodeURIComponent(month) +
    '&performance=demerit', { credentials: 'same-origin' });
  if (!r.ok) throw new Error('Demerit API HTTP ' + r.status);

  const j = await r.json();
  if (!j.success || !Array.isArray(j.staff)) {
    console.warn('Unexpected demerit API response:', j);
    return [];
  }

  const s = j.staff.find(x => String(x.mk_id) === String(mkId)) || j.staff[0];
  return ((s && s.records) || []).map(x => ({
    date: String(x.date || x.performance_date || x.demerit_date || '').slice(0, 10),
    reference: x.ref || x.reference || x.reference_no || '',
    type: x.type || x.performance || 'Demerit',
    title: x.title || x.description || x.reason || '',
    classification: x.merit_demerit || '',
    points: x.points ?? '',
    performance_id: x.performance_id || x.id || '',
    missed_dates: Array.isArray(x.missed_dates)
      ? x.missed_dates.map(m => ({ date: String(m.date || '').slice(0, 10), periods: m.periods || '' })).filter(m => m.date)
      : [],
    source: 'performanceAPI'
  })).filter(x => x.date);
}

// A day is a public holiday when the API sets is_public_holiday to 1 (string or number) on the day.
const isPH = d => !!d && String(d.is_public_holiday) === '1';
const phName = d => (d && (d.holiday_name || d.holiday || d.public_holiday_name)) || 'Public holiday';
const CHECK = '<i class="fa-solid fa-check"></i>', CROSS = '<i class="fa-solid fa-xmark"></i>';
function stHtml(d) {
  if (!d || d.status === 'no_data') return isPH(d) ? '<span class="st ph" title="' + esc(phName(d)) + '">PH</span>' : '<span class="st none">–</span>';
  const t = (d.time || '').slice(0, 5);
  return d.status === 'late' ? '<span class="st bad" title="Late">' + CROSS + ' ' + t + '</span>'
                             : '<span class="st ok" title="On time">' + CHECK + ' ' + t + '</span>';
}
// PM check-out is shown only from 4.30pm. Green = checked out at or after 4.30pm; red = checked out earlier or not at all.
const PM_FROM = '16:30';
function pmLateHtml(t, person, now) {
  const hhmm = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
  if (hhmm < PM_FROM || now.getDay() === 0) return '';                  // not before 4.30pm, never on Sunday
  const ds = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
  const md = (monthCache[ym()] || {})[person];
  if (md && (md.leave || []).some(r => (r.days_in_month || []).some(d => String(d).slice(0, 10) === ds))) return '';   // on leave today
  const pm = t && t.pm_status === 'has_data' && t.pm_time ? String(t.pm_time).slice(0, 5) : '';
  if (!pm) return '<span class="pmrow"><span class="st bad" title="No PM check-out recorded">' + CROSS + ' Missed</span></span>';
  return pm >= PM_FROM
    ? '<span class="pmrow"><span class="st ok" title="PM check-out at or after 4.30pm">' + CHECK + ' ' + esc(pm) + '</span></span>'
    : '<span class="pmrow"><span class="st bad" title="PM check-out before 4.30pm">' + CROSS + ' ' + esc(pm) + '</span></span>';
}

// Current month: today's check-in, plus today's PM check-out from 4.30pm (blank when there is none).
// Other months: late-day summary (blank when none).
function lateCell(person) {
  const y = +$('fy').value, m = +$('fm').value, e = (attCache[y + '-' + m] || {})[person];
  if (!e) return '';
  const now = new Date();
  if (y === now.getFullYear() && m === now.getMonth() + 1) {
    const t = e.days && e.days[now.getDate()];
    if (isPH(t) && (!t.status || t.status === 'no_data')) return '<span class="st ph" title="' + esc(phName(t)) + '">PH</span>';
    return (t && t.time && t.status !== 'no_data' ? stHtml(t) : '') + pmLateHtml(t, person, now);
  }
  const s = e.summary || {};
  if (s.late > 0) return '<span class="st bad" title="Late days this month">' + CROSS + ' ' + s.late + '</span>';
  if (s.on_time > 0) return '<span class="st ok" title="No late days this month">' + CHECK + '</span>';
  return '';
}

// ---------- calendar modal ----------
let cal = null, calTok = 0;
function openCal(person, name) {
  cal = { person: person, name: name, y: +$('fy').value, m: +$('fm').value };
  $('mdl').hidden = false;
  renderCal();
}
async function renderCal() {
  const c = cal, tok = ++calTok, key = c.y + '-' + c.m;
  $('mtitle').textContent = c.name;
  $('msub').textContent = 'Late from ' + (attRules.late_from || '08:01:00').slice(0, 5);
  $('mlabel').textContent = MONTHS[c.m - 1] + ' ' + c.y;
  $('mbody').innerHTML = '<div class="state">Loading…</div>';
  const jobs = [];
  if (!(attCache[key] || {})[c.person]) jobs.push(loadAtt(c.person, c.y, c.m));
  jobs.push(ensureMonth(c.person, c.y, c.m));
  const res = await Promise.allSettled(jobs);
  if (tok !== calTok) return;
  const bad = res.filter(r => r.status === 'rejected')[0];
  const e = (attCache[key] || {})[c.person] || { days: {}, summary: {} };
  const md = (monthCache[key] || {})[c.person] || {};

  const dk = d => c.y + '-' + String(c.m).padStart(2, '0') + '-' + String(d).padStart(2, '0');

  // Leave by day (a leave record can span several days)
  const leaveBy = {}, compBy = {};
  (md.leave || []).forEach(r => {
    (r.days_in_month || []).forEach(date => {
      const day = String(date).slice(0, 10);
      if (!leaveBy[day]) leaveBy[day] = [];
      leaveBy[day].push(r);
    });
  });

  // Compliance by day: demerit records from performanceAPI (loaded in fetchMonthData)
  // A record with missed_dates is shown on those dates (not on the date the record was issued).
  (md.compliance || []).forEach(r => {
    if (r.missed_dates && r.missed_dates.length) {
      r.missed_dates.forEach(m => {
        (compBy[m.date] = compBy[m.date] || []).push(Object.assign({}, r, { _periods: m.periods }));
      });
    } else if (r.date) {
      const day = r.date.slice(0, 10);
      (compBy[day] = compBy[day] || []).push(r);
    }
  });

  // Automatically detect missed attendance submissions (working days with no attendance record)
  const autoCompliance = [], missed = {};

  const today = new Date();
  today.setHours(0, 0, 0, 0);

  for (let day = 1; day <= new Date(c.y, c.m, 0).getDate(); day++) {
    const date = dk(day);
    const dayDate = new Date(c.y, c.m - 1, day);

    // Only check Monday to Saturday
    if (dayDate.getDay() === 0) continue;

    // Do not check today or future dates (today's submission may still come in)
    if (dayDate >= today) continue;

    const attendance = (e.days || {})[day] || {};
    const hasLeave = (leaveBy[date] || []).length > 0;

    // Skip public holidays and leave dates
    if (isPH(attendance) || hasLeave) continue;

    // Skip dates that already have attendance (status "no_data" means nothing was submitted)
    if (attendance.time || (attendance.status && attendance.status !== 'no_data')) continue;

    const record = {
      date: date,
      reference: 'AUTO',
      type: 'System-Detected',
      title: 'Missed Attendance Submission',
      source: 'system'
    };

    autoCompliance.push(record);
    missed[date] = record;

  }

  const n = new Date(c.y, c.m, 0).getDate(), first = new Date(c.y, c.m - 1, 1).getDay(), now = new Date();
  let grid = DOW.map(d => '<div class="dow">' + d + '</div>').join('');
  for (let i = 0; i < first; i++) grid += '<div class="cday blank"></div>';
  let phCount = 0;
  for (let d = 1; d <= n; d++) {
    const x = (e.days || {})[d] || {}, st = x.status, key2 = dk(d);

    const ph = isPH(x);
    const lv = leaveBy[key2] || [];
    const cw = compBy[key2] || [];

    if (ph) phCount++;
    const isToday = c.y === now.getFullYear() && c.m === now.getMonth() + 1 && d === now.getDate();
    // Today's PM check-out is not "missed" until 4.30pm; earlier days are always due.
    const pmDue = !isToday || (String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0')) >= PM_FROM;
    const ms = !!missed[key2];
    const cls = st === 'late' ? ' bad'
      : st === 'on_time' ? ' ok'
      : ms ? ' bad'
      : ph ? ' ph' : '';
    const val = st === 'late' ? CROSS + ' ' + esc((x.time || '').slice(0, 5)) : st === 'on_time' ? CHECK + ' ' + esc((x.time || '').slice(0, 5)) : ms ? CROSS + ' Missed' : '';
    const tip = [
      ph ? phName(x) : '',
      ms ? 'Missed submission' : '',

      ...lv.map(r =>
        (r.leave_type || r.leave_type_name || 'Leave') +
        ' (' + (r.status_name || 'Unknown status') + ')'
      ),

      cw.map(r =>
        (r.reference || r.ref || '') +
        ' ' + (r.title || r.label || '') +
        (r._periods ? ' – missed ' + r._periods : '')
      ).join('; '),

      x.source || ''
    ].filter(Boolean).join(' · ');

    grid += '<div class="cday' + cls + (isToday ? ' today' : '') + '" title="' + esc(tip) + '">' +
        '<span class="tags">' +
        (cw.length ? '<span class="wn">⚠️</span>' : '') +
        (ph ? '<span class="pht">PH</span>' : '') +
        '</span><span class="d">' + d + '</span>' +

        '<span class="v' + (ms ? ' ms' : '') + '">' + val + '</span>' +

        // PM attendance: Monday-Saturday, up to today only,
        // excluding public holidays and leave dates
        (
            !ph &&
            lv.length === 0 &&
            new Date(c.y, c.m - 1, d) <= today &&
            new Date(c.y, c.m - 1, d).getDay() !== 0
                ? (
                    x.pm_status === 'has_data' && x.pm_time
                        ? '<span class="pm-attendance"><span class="st ok">' + CHECK + ' ' + esc(x.pm_time.slice(0, 5)) + '</span></span>'
                        : (pmDue ? '<span class="pm-attendance"><span class="st bad">' + CROSS + ' Missed</span></span>' : '')
                  )
                : ''
        ) +

        (lv.length
            ? '<span class="lvp">' +
              esc(lv.map(r => r.leave_type || r.leave_type_name || 'On Leave').join(', ')) +
              '</span>'
            : '') + '</div>';

  }

  const leaves = (md.leave || []).slice().sort(
    (a, b) => String(a.date_from).localeCompare(String(b.date_from))
  );

  // Compliance has two parts:
  //  1. Missed attendance submissions detected by the system (working days, Mon-Sat, with no attendance at all).
  //     A date that is already in a compliance record's missed_dates is not listed here again.
  //  2. Official compliance records from the compliance API (performanceAPI).
  const apiComps = (md.compliance || []).slice().sort((a, b) => String(a.date).localeCompare(String(b.date)));
  const comps = apiComps;

  const fd = s => s ? dmy(String(s).slice(0, 10)) : '–';
  const ucw = v => String(v == null ? '' : v).replace(/(^|[\s_\-\/(])([a-z])/g, (m, a, b) => a + b.toUpperCase());
  const hasMissedCol = apiComps.some(r => (r.missed_dates || []).length);
  const compTable = list =>
    '<div style="overflow-x:auto">' +
    '<table class="ctbl">' +
    '<thead><tr>' +
    (hasMissedCol ? '<th>Missed Dates</th>' : '') +
    '<th>Action Date</th>' +
    '<th>Type</th>' +
    '<th>Reference</th>' +
    '<th>Title</th>' +
    '<th>Classification</th>' +
    '</tr></thead><tbody>' +
    list.map(r =>
        '<tr>' +
        (hasMissedCol
            ? '<td>' + (r.missed_dates || [])
                .map(m =>
                    esc(dmy(m.date)) +
                    (m.periods ? ' (' + esc(m.periods) + ')' : '')
                )
                .join('<br>') +
              '</td>'
            : '') +
        '<td>' + fd(r.date) + '</td>' +
        '<td>' + esc(ucw(r.type)) + '</td>' +
        '<td>' + esc(r.reference || r.ref || '') + '</td>' +
        '<td>' + esc(r.title || r.label || '') + '</td>' +
        '<td>' + esc(ucw(r.classification || r.merit_demerit || '')) + '</td>' +
        '</tr>'
    ).join('') +
    '</tbody></table></div>';

  // Dates already covered by a compliance record are left out of the system-detected list.
  const apiMissed = {};
  apiComps.forEach(r => (r.missed_dates || []).forEach(m => { apiMissed[m.date] = true; }));
  const detected = autoCompliance.filter(r => !apiMissed[r.date]);
  const detectedTable =
    '<div style="overflow-x:auto">' +
    '<table class="ctbl">' +
    '<thead><tr><th>Type</th><th>Date</th><th>Title</th></tr></thead><tbody>' +
    detected.map(r =>
      '<tr>' +
      '<td>' + esc(r.type || '') + '</td>' +
      '<td>' + fd(r.date) + '</td>' +
      '<td>' + esc(r.title || '') + '</td>' +
      '</tr>'
    ).join('') +
    '</tbody></table></div>';

  const missedN = autoCompliance.length;
  const compList =
    '<div class="mh">Compliance</div>' +
    '<div class="sh">1. Missed Attendance Submissions (System-Detected)</div>' +
    (detected.length
      ? detectedTable
      : '<div class="muted">' + (missedN
          ? 'All detected missed days are already covered by the compliance record (see Missed Dates below).'
          : 'No missed attendance submissions detected.') + '</div>') +
    '<div class="sh">2. Official Compliance Records</div>' +
    (apiComps.length ? compTable(apiComps) : '<div class="muted">No official compliance records for this month.</div>');

  const leaveList =
    '<div class="mh">Leave Records</div>' +
    (leaves.length
      ? '<div style="overflow-x:auto"><table class="ctbl">' +
        '<thead><tr>' +
        '<th>Date</th>' +
        '<th>Period</th>' +
        '<th>Applied Date</th>' +
        '<th>Type</th>' +
        '<th>Status</th>' +
        '<th>Reason</th>' +
        '<th>Receipt Date</th>' +
        '</tr></thead><tbody>' +
        leaves.map(r =>
          '<tr>' +
          '<td>' + esc(dmy(r.date_from || '')) +
            (r.date_to && r.date_to !== r.date_from
              ? ' – ' + esc(dmy(r.date_to)) : '') +
          '</td>' +
          '<td>' + esc(r.from_apm || '') + ' – ' +
            esc(r.to_apm || '') + '</td>' +
          '<td>' + esc(dmy(r.applied_date || '')) + '</td>' +
          '<td>' + esc(r.leave_type_name || r.leave_type || '') + '</td>' +
          '<td>' + (r.status_name
            ? '<span class="lg ' +
              (String(r.status_name).trim().toLowerCase() === 'approved'
                ? 'ok' : 'bad') +
              '">' + esc(r.status_name) + '</span>'
            : '') +
          '</td>' +
          '<td>' + esc(r.reason || '') + '</td>' +
          '<td>' + esc(dmy(r.receipt_dates || '')) + '</td>' +
          '</tr>'
        ).join('') +
        '</tbody></table></div>'
      : '<div class="muted">No leave records.</div>');

  const s = e.summary || {};

  $('mbody').innerHTML =
    ((bad || md.error)
      ? '<div class="state err">Some data couldn’t load: ' +
        esc(bad ? (bad.reason && bad.reason.message || '') : md.error) + '</div>'
      : '') +

    '<div class="legend">' +
      '<span><span class="st ok">' + CHECK + '</span> On time</span>' +
      '<span><span class="st bad">' + CROSS + '</span> Late / missed submission</span>' +
      '<span><span class="lg leave">Leave</span> On leave</span>' +
      '<span>⚠️ Compliance</span>' +
      '<span><span class="st ph">PH</span> Public holiday</span>' +
      '<span><span class="st none">–</span> No data</span>' +
    '</div>' +

    '<div class="cal">' + grid + '</div>' +

    '<div class="msum">' +
      (s.on_time || 0) + ' on time · ' +
      (s.late || 0) + ' late · ' +
      leaves.length + ' leave record' +
      (leaves.length === 1 ? '' : 's') + ' · ' +
      comps.length + ' compliance' +
      (missedN ? ' · ' + missedN + ' missed submission day' + (missedN === 1 ? '' : 's') : '') +
      (phCount
        ? ' · ' + phCount + ' public holiday' +
          (phCount > 1 ? 's' : '')
        : '') +
    '</div>' +

    '<div class="mlists">' +
      leaveList +
      compList +
    '</div>';
}

function calMove(delta) {
  cal.m += delta;
  if (cal.m < 1) { cal.m = 12; cal.y--; } else if (cal.m > 12) { cal.m = 1; cal.y++; }
  renderCal();
}
$('mprev').addEventListener('click', () => calMove(-1));
$('mnext').addEventListener('click', () => calMove(1));
$('mclose').addEventListener('click', () => { $('mdl').hidden = true; });
$('mdl').addEventListener('click', e => { if (e.target === $('mdl')) $('mdl').hidden = true; });
document.addEventListener('keydown', e => { if (e.key === 'Escape') $('mdl').hidden = true; });

// ---------- Excessive Leave / Compliance data ----------
// Leave comes from leaveAPI.php, compliance (demerits) from performanceAPI.php.
// Lateness comes from attendanceAPI.php.
// Called with the people on screen for one month. Result is keyed by person:
//   { 'justin': { day_count: 2, leave: [ ... ], compliance: [ { date, reference, type, title, ... } ] } }
const LEAVE_API = 'leaveAPI.php';

async function fetchMonthData(persons, year, month) {
  const mm = String(month).padStart(2, '0');
  const results = {};

  await Promise.all(persons.map(async person => {
    // --- leave (a failure is kept per person, so one bad call doesn't blank everyone) ---
    const url = LEAVE_API
      + '?person=' + encodeURIComponent(person)
      + '&comp_id=' + COMP
      + '&year=' + year
      + '&month=' + mm;

    let staff = null, leaveError = '';
    try {
      const response = await fetch(url, { credentials: 'same-origin' });
      if (!response.ok) throw new Error('Leave API HTTP ' + response.status);
      const json = await response.json();
      if (json.success === false) throw new Error(json.message || 'Leave API failed');
      staff = (json.staff || []).find(
        s => String(s.person).toLowerCase() === String(person).toLowerCase()
      );
    } catch (err) { leaveError = err.message || 'Leave API failed'; }

    // --- compliance: demerits (a failure here only logs, leave still shows) ---
    let demerits = [];
    try { demerits = await loadDemerit(person, year, month); }
    catch (err) { console.warn('Demerit load failed for ' + person + ':', err); }

    results[person] = {
      day_count: Number((staff && staff.summary && staff.summary.day_count) || 0),
      leave: (staff && staff.leaves) || [],
      compliance: demerits,
      error: leaveError
    };
  }));

  return results;
}

// Loads leave/compliance for one person and month (used by the modal, shares the cache with the table).
async function ensureMonth(person, y, m) {
  const key = y + '-' + m, have = asked[key] = asked[key] || {};
  if (have[person]) return;
  have[person] = true;
  try { monthCache[key] = Object.assign(monthCache[key] || {}, await fetchMonthData([person], y, m) || {}); }
  catch (e) { delete have[person]; throw e; }
}

async function loadMonth() {
  const key = ym(), y = +$('fy').value, m = +$('fm').value;
  const have = asked[key] = asked[key] || {};
  const need = [];
  document.querySelectorAll('#root tr.srow').forEach(tr => {
    const p = tr.dataset.person;
    if (!have[p] && need.indexOf(p) < 0) need.push(p);
  });
  if (need.length) {
    need.forEach(p => have[p] = true);
    try {
      const res = await fetchMonthData(need, y, m) || {};
      monthCache[key] = Object.assign(monthCache[key] || {}, res);
    } catch (e) { need.forEach(p => delete have[p]); }
  }
  if (key === ym()) refreshCells();
}

function cellHtml(person, k) {
  if (k === 'late') return lateCell(person);
  const d = (monthCache[ym()] || {})[person];
  if (!d) return '<span class="st none">…</span>';

  if (k === 'leave') {
    if (d.error) return '<span class="st none" title="' + esc(d.error) + '">n/a</span>';
    const days = Number(d.day_count || 0);
    return days > 0
      ? '<span class="lg leave" title="Total leave days this month">'
        + days + ' day' + (days === 1 ? '' : 's')
        + '</span>'
      : '<span class="lg zero" title="No leave this month">0 days</span>';
  }

  const n = (d.compliance || []).length;
  return n ? '<span class="lg bad" title="Compliance records">⚠️ ' + n + '</span>' : '<span class="lg zero" title="No compliance records">0</span>';
}
function refreshCells() {
  document.querySelectorAll('#root tr.srow').forEach(tr => {
    tr.querySelectorAll('td.cell').forEach(td => { td.innerHTML = cellHtml(tr.dataset.person, td.dataset.k); });
  });
}


// ---------- rendering ----------
// Initials circle with the staff picture on top; if the picture fails to load it is removed and the initials show.
function avatar(p, small) {
  const u = photoUrl(p);
  return '<div class="avatar' + (small ? ' sm' : '') + '" style="position:relative;overflow:hidden;background:hsl(' + hue(p.person) + ',42%,42%)">' +
    esc(initials(p.fullname)) +
    (u ? '<img src="' + esc(u) + '" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover">' : '') +
    '</div>';
}

function deptHtml(p) {
  const n = cnt(p);
  return '<div class="node" data-person="' + esc(p.person) + '">' +
    '<button class="row" aria-expanded="false">' +
      '<i class="fa-solid ' + (p.isArea ? 'fa-location-dot' : 'fa-folder') + ' ico"></i>' +
      '<div class="body"><div class="title">' + esc(p.department_name) + (p.isArea ? ' <span class="gwtag">Area</span>' : '') +
        (n ? ' <span class="chip" title="' + (p.total_subordinates || n) + ' in total">' + n + '</span>' : '') + '</div>' +
        '<div class="line">' + esc(p.fullname) + ' · ' + esc(p.position || 'HOD') + '</div>' + '</div>' +
      avatar(p) +
      '<span class="go"><i class="fa-solid fa-chevron-right"></i></span>' +
    '</button><div class="tbox" hidden></div></div>';
}

function rowsHtml(subs, prefix, level) {
  let k = 0;
  return subs.map(p => {
    const id = ++uid, no = p._head ? 'HOD' : prefix + (++k), n = p._head ? 0 : cnt(p);
    rowData[id] = { p: p, subs: p.subordinates || [], no: no };
    if (p.mk_id) mkIdOf[p.person] = p.mk_id;     // used by loadDemerit()
    const tip = [p.email, p.mobile_no, p.district].filter(Boolean).join(' · ');
    return '<tr class="srow' + (p._head ? ' hrow' : '') + '" data-id="' + id + '" data-person="' + esc(p.person) + '" data-level="' + level + '" data-n="' + n + '">' +
      '<td class="no">' + no + '</td>' +
      '<td class="nm' + (n ? ' has' : '') + '" title="' + esc(tip) + '"><div class="nmin" style="padding-left:' + ((level - 1) * 20) + 'px">' +
        (n ? '<button class="tg" aria-label="Show subordinates"><i class="fa-solid fa-chevron-right"></i></button>' : '<span class="tg sp"></span>') +
        avatar(p, true) + '<div><span class="nmt">' + esc(p.fullname) + '</span>' +
        (n ? ' <span class="chip" title="' + (p.total_subordinates || n) + ' in total">' + n + '</span>' : '') +
        (p.staff_type === 'gw' ? ' <span class="gwtag">GW</span>' : '') +
        '<div class="muted">' + esc(p.position || '—') + '</div></div></div></td>' +
      '<td class="c cell" data-k="late">' + cellHtml(p.person, 'late') + '</td>' +
      '<td class="c cell" data-k="leave">' + cellHtml(p.person, 'leave') + '</td>' +
      '<td class="c cell" data-k="comp">' + cellHtml(p.person, 'comp') + '</td></tr>';
  }).join('');
}

function tableHtml(subs, head) {
  return '<table class="stbl"><thead><tr><th class="no">No</th><th>Staff name</th><th class="c" title="Check-in today, plus PM check-out from 4.30pm (current month) or late days (other months). Click for the month calendar.">Lateness</th>' +
    '<th class="c" title="Click for the month calendar">Excessive Leave</th><th class="c" title="Click for the month calendar">Compliance</th></tr></thead><tbody>' +
    (head ? rowsHtml([head], '', 1) : '') +
    (subs.length ? rowsHtml(subs, '', 1) : '<tr><td colspan="5" class="state">No subordinates.</td></tr>') + '</tbody></table>';
}

// Superiors are drawn as view-only cards above the rows, nested like the CEO card, unless the superior already has a row.
function ancHtml(per, inner) {
  const i = info[per] || { fullname: per, position: '', dept: '' };
  return '<div class="node" data-anc="1"><div class="row anc" title="Superior (view only)">' +
    '<div class="body"><div class="title">' + esc(i.fullname) + '</div>' +
    '<div class="line">' + esc([i.position, i.dept].filter(Boolean).join(' · ')) + '</div></div>' +
    avatar({ person: per, fullname: i.fullname, staff_picture_url: i.picture }) + '</div><div class="tree">' + inner + '</div></div>';
}
function renderRows(list) {
  const visible = {};
  rows().forEach(r => { visible[String(r.person).toLowerCase()] = 1; });
  const top = { kids: {}, order: [], rows: [] };
  list.forEach(p => {
    let n = top;
    superiors(p.department_id).reverse().filter(x => !visible[String(x).toLowerCase()]).forEach(per => {
      if (!n.kids[per]) { n.kids[per] = { kids: {}, order: [], rows: [] }; n.order.push(per); }
      n = n.kids[per];
    });
    n.rows.push(p);
  });
  const draw = n => n.rows.map(deptHtml).join('') + n.order.map(per => ancHtml(per, draw(n.kids[per]))).join('');
  return draw(top);
}

function renderList() {
  mode = ''; if (data && $('tiles').innerHTML) renderTiles();
  const q = $('q').value.trim().toLowerCase();
  const list = rows().filter(p => !q || (p.department_name + ' ' + p.fullname).toLowerCase().indexOf(q) > -1);
  $('root').innerHTML = list.length ? renderRows(list)
    : '<div class="empty">' + (rows().length ? 'No matches.' : acc.leader ? 'You don’t have access to any department.' : 'This page is only available to Heads of Department and Area Managers.') + '</div>';
}

async function toggleDept(node) {
  const row = node.querySelector(':scope > .row'), box = node.querySelector(':scope > .tbox');
  const open = row.classList.toggle('open');
  row.setAttribute('aria-expanded', open);
  box.hidden = !open;
  if (!open || box.dataset.loaded) return;
  box.innerHTML = '<div class="state">Loading…</div>';
  try {
    const t = await fetchTree(node.dataset.person);
    // The head (HOD / area manager) gets their own row too, so their lateness, leave and compliance are visible.
    const deptP = rows().filter(r => r.person === node.dataset.person)[0] || {};
    const head = Object.assign({}, deptP, t || {}, { _head: true, person: node.dataset.person, subordinates: [], direct_subordinates: 0 });
    box.innerHTML = tableHtml(t.subordinates || [], head);
    box.dataset.loaded = '1';
    loadMonth();
    loadAttFor(node.dataset.person);
  } catch (e) {
    box.innerHTML = '<div class="state err">Couldn’t load: ' + esc(e.message) + '</div>';
  }
}

function removeAfter(tr, level) {
  while (tr.nextElementSibling && +tr.nextElementSibling.dataset.level > level) tr.nextElementSibling.remove();
}

async function toggleRow(tr) {
  const level = +tr.dataset.level, d = rowData[tr.dataset.id];
  if (tr.classList.contains('open')) { tr.classList.remove('open'); removeAfter(tr, level); return; }
  tr.classList.add('open');
  let subs = d.subs;
  try {
    if (!subs.length) { subs = d.subs = (await fetchTree(d.p.person)).subordinates || []; }   // nested data was cut off
  } catch (e) { tr.classList.remove('open'); return; }
  tr.insertAdjacentHTML('afterend', subs.length
    ? rowsHtml(subs, d.no + '.', level + 1)
    : '<tr data-level="' + (level + 1) + '"><td></td><td colspan="4" class="state">No subordinates.</td></tr>');
  loadMonth();
  const y = +$('fy').value, m = +$('fm').value;
  fillAttGaps(y, m).then(() => { if (y === +$('fy').value && m === +$('fm').value) refreshCells(); });
}


$('root').addEventListener('click', e => {
  const cell = e.target.closest('td.cell');
  if (cell) { const tr = cell.parentElement; openCal(tr.dataset.person, rowData[tr.dataset.id].p.fullname); return; }
  const nm = e.target.closest('td.nm');
  if (nm) { const tr = nm.parentElement; if (+tr.dataset.n > 0) toggleRow(tr); return; }
  const row = e.target.closest('.row');
  if (row && !row.classList.contains('anc')) toggleDept(row.parentElement);
});
$('q').addEventListener('input', renderList);
function onPeriod() {
  refreshCells(); loadMonth();
  document.querySelectorAll('#root .tbox[data-loaded]').forEach(b => loadAttFor(b.parentElement.dataset.person));
}
$('fm').addEventListener('change', onPeriod);
$('fy').addEventListener('change', onPeriod);

// ---------- access (which departments this user may view) ----------
// staff_tree=all returns "access": [ { person, scope, departments:[ { department_id, department_name, sub_departments } ] } ].
// scope "all" = every department; otherwise only the listed departments (and their sub-departments).
let acc = { leader: false, all: false, any: false, ids: {}, names: {} };
let accessSrc = [];
let parentMap = {}, info = {};       // department_id -> {person, deptId} of the superior; person -> {fullname, position, dept, deptId, total}
const same = (a, b) => String(a).toLowerCase() === String(b).toLowerCase();
function buildAccess() {
  const src = data.access || (data.staff_tree && data.staff_tree.access) || [];
  accessSrc = src;
  const mine = src.filter(a => String(a.person).toLowerCase() === String(ME).toLowerCase());
  // Leader = listed as a department HOD or an area manager / assistant area manager (or explicitly allowed in PHP).
  const leader = ((data.staff_tree && data.staff_tree.hod) || []).concat((data.staff_tree && data.staff_tree.am) || []).some(p => same(p.person, ME))
              || EXTRA.some(x => same(x, ME));
  acc = { leader: leader, all: false, any: mine.length > 0, ids: {}, names: {} };
  const add = d => {
    if (d == null) return;
    if (typeof d !== 'object') { acc.ids[String(d)] = 1; return; }
    if (d.department_id != null) acc.ids[String(d.department_id)] = 1;
    if (d.department_name) acc.names[d.department_name] = 1;
    (d.sub_departments || []).forEach(add);
  };
  // The CEO and the people listed in $allowPersons can see every department.
  if (IS_TOP || EXTRA.some(x => same(x, ME))) { acc.leader = true; acc.all = true; }
  mine.forEach(a => { if (a.scope === 'all') acc.all = true; (a.departments || []).forEach(add); });

  buildHierarchy(src);
}

// Who is above whom. Two sources in the access data:
//  1. A department's sub_departments report to that department's HOD (e.g. KMU/PTS -> Heldah).
//  2. A department listed in someone else's access belongs under that person if they are senior to its HOD
//     (more people under them). People in $allowPersons are viewers only and are never treated as superiors.
function buildHierarchy(src) {
  const lc = x => String(x).toLowerCase();
  info = {};
  ((data.staff_tree && data.staff_tree.hod) || []).concat((data.staff_tree && data.staff_tree.am) || []).forEach(h => {
    if (!info[h.person]) info[h.person] = { picture: h.staff_picture_url, fullname: h.fullname, position: h.position || '', dept: h.department_name, deptId: h.department_id, total: h.total_subordinates || 0 };
  });
  const hodOf = {};
  ((data.staff_tree && data.staff_tree.hod) || []).concat((data.staff_tree && data.staff_tree.am) || []).forEach(h => { hodOf[h.department_id] = h.person; });

  parentMap = {};
  const link = (d, parent) => {
    if (!d || typeof d !== 'object') return;
    if (parent && d.department_id != null && !parentMap[d.department_id]) parentMap[d.department_id] = { person: parent.person, deptId: parent.department_id };
    (d.sub_departments || []).forEach(sd => link(sd, d));
  };
  src.forEach(a => (a.departments || []).forEach(d => link(d, null)));

  const extra = EXTRA.map(lc), cands = {};
  src.forEach(a => {
    if (!info[a.person] || extra.indexOf(lc(a.person)) > -1) return;
    (a.departments || []).forEach(d => {
      if (d.department_id == null || same(d.person, a.person)) return;
      (cands[d.department_id] = cands[d.department_id] || []).push(a.person);
    });
  });
  Object.keys(cands).forEach(id => {
    if (parentMap[id]) return;
    const own = info[hodOf[id]] ? info[hodOf[id]].total : 0;
    let best = null;
    cands[id].forEach(per => { const t = info[per].total; if (t > own && (!best || t < best.t)) best = { person: per, t: t, deptId: info[per].deptId }; });
    if (best) parentMap[id] = { person: best.person, deptId: best.deptId };
  });
}
// Add ?debug=1 to the page URL to print how each row's superiors were worked out.
function debugHtml() {
  const out = ['ME=' + ME + '  leader=' + acc.leader + '  all=' + acc.all + '  access entries=' + accessSrc.length +
               '  mine=' + accessSrc.filter(a => same(a.person, ME)).length];
  rows().forEach(r => {
    const chain = superiors(r.department_id);
    out.push('row ' + r.department_name + ' (#' + r.department_id + ')  superiors: ' + (chain.join(' > ') || '(none found)'));
    [r.department_id].concat(chain.map(per => info[per] && info[per].deptId)).forEach(id => {
      if (id == null) return;
      accessSrc.forEach(a => (a.departments || []).forEach(d => {
        if (String(d.department_id) === String(id)) out.push('   #' + id + ' is listed in the access of ' + a.person + ' (scope ' + a.scope + ')');
        (d.sub_departments || []).forEach(sd => { if (String(sd.department_id) === String(id)) out.push('   #' + id + ' is a sub_department of #' + d.department_id + ' in the access of ' + a.person); });
      }));
    });
  });
  return '<pre style="margin-top:12px;padding:10px;background:#f8fafc;border:1px solid var(--border);border-radius:8px;font-size:12px;white-space:pre-wrap">' + esc(out.join('\n')) + '</pre>';
}
// Superiors above a department / area, nearest first (e.g. KMU/PTS -> [Heldah, Jeffrey]).
function superiors(deptId) {
  const out = []; let cur = deptId;
  while (cur != null && parentMap[cur] && out.length < 6 && out.indexOf(parentMap[cur].person) < 0) { out.push(parentMap[cur].person); cur = parentMap[cur].deptId; }
  return out;
}
const allowed = p => acc.leader && (acc.all || same(p.person, ME) || (p.department_id != null && !!acc.ids[String(p.department_id)]) || (!!p.department_name && !!acc.names[p.department_name]));
const vis = k => ((data.staff_tree && data.staff_tree[k]) || []).filter(allowed);
// One list: the departments this user may view, plus their own area if they are an area manager.
// Other people's areas are never listed here.
function rows() {
  const mine = ((data.staff_tree && data.staff_tree.am) || []).filter(p => acc.leader && same(p.person, ME))
    .map(p => Object.assign({}, p, { isArea: true }));
  return vis('hod').concat(mine);
}

// ---------- header totals + expand all ----------
let mode = '';                       // '' | 'sup' | 'sub'
const totals = { sup: null, sub: null };

function renderTiles() {
  const v = x => x == null ? '…' : x;
  const tile = (id, cls, n, label, hint) =>
    '<button class="tile' + (mode === id ? ' on' : '') + '" data-act="' + id + '" title="' + hint + '"><div class="n ' + cls + '">' + n + '</div><div class="l">' + label + '</div></button>';
  $('tiles').innerHTML =
    tile('dept', 'mkc', rows().length, 'Total departments', 'Collapse everything') +
    tile('sup',  'mka', v(totals.sup), 'Total superiors', 'Open every department and show its HOD’s direct staff') +
    tile('sub',  'kpi', v(totals.sub), 'Total subordinates', 'Open every department and expand all levels');
}

async function pool(items, n, fn) {
  let i = 0;
  await Promise.all(Array.from({ length: n }, async () => {
    while (i < items.length) { const it = items[i++]; try { await fn(it); } catch (e) {} }
  }));
}

// Superiors = the HOD of each department (one person counted once, even if they head two departments).
// Subordinates = everyone who reports to someone, at any level under those HODs.
async function computeTotals() {
  const heads = []; rows().forEach(h => { if (heads.indexOf(h.person) < 0) heads.push(h.person); });
  totals.sup = heads.length; renderTiles();
  const sub = new Set();
  const walk = p => (p.subordinates || []).forEach(c => { sub.add(c.person); walk(c); });
  await pool(heads, 3, async person => { walk(await fetchTree(person)); });
  totals.sub = sub.size;
  renderTiles();
}

async function expandBox(box) {
  for (let i = 0; i < 8; i++) {                       // depth guard
    const rows = [].filter.call(box.querySelectorAll('tr.srow'), r => +r.dataset.n > 0 && !r.classList.contains('open'));
    if (!rows.length) break;
    await Promise.all(rows.map(toggleRow));
  }
}

async function setMode(m) {
  if (m === 'dept' || m === mode) { renderList(); return; }       // collapse everything
  mode = m; renderTiles();
  document.body.style.cursor = 'progress';
  const nodes = [].slice.call($('root').querySelectorAll('.node[data-person]'));
  await pool(nodes, 3, async node => {
    if (!node.querySelector(':scope > .row').classList.contains('open')) await toggleDept(node);
    if (m === 'sub') await expandBox(node.querySelector(':scope > .tbox'));   // superiors: stop at the HOD’s direct staff
  });
  document.body.style.cursor = '';
}
$('tiles').addEventListener('click', e => { const t = e.target.closest('.tile'); if (t) setMode(t.dataset.act); });

setInterval(() => {
  const n = new Date();
  if (data && +$('fy').value === n.getFullYear() && +$('fm').value === n.getMonth() + 1) refreshCells();
}, 60000);

(function initPeriod() {
  const now = new Date(), y = now.getFullYear();
  $('fm').innerHTML = MONTHS.map((n, i) => '<option value="' + (i + 1) + '"' + (i === now.getMonth() ? ' selected' : '') + '>' + n + '</option>').join('');
  let yo = ''; for (let i = y; i >= y - 3; i--) yo += '<option>' + i + '</option>';
  $('fy').innerHTML = yo;
})();

(async () => {
  try {
    data = await api('staff_tree=all');
    buildAccess();
    (data.staff_tree.hod || []).concat(data.staff_tree.am || []).forEach(h => {
      hodCount[h.person] = Math.max(hodCount[h.person] || 0, h.direct_subordinates || 0);
    });
    $('foot').textContent = data.company_name + ' · generated ' + data.generated_at;
    renderList();
    renderTiles();
    computeTotals();
    if (/[?&]debug=1/.test(location.search)) $('foot').insertAdjacentHTML('afterend', debugHtml());
  } catch (e) {
    $('root').innerHTML = '<div class="state err">Couldn’t load staff list: ' + esc(e.message) + '</div>';
  }
})();
</script>

 <?php include 'staffSummaryWidget.php'; ?>
</body>
</html>