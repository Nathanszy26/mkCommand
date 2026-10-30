<?php
// staffSummaryWidget.php
// Shows a blocking popup only when staffSummaryAPI.php returns "show_popup": true.
// Optional: set $summaryPerson before the include to force a person (testing only).


error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

if (defined('SSW_LOADED')) return;          // already included on this page
define('SSW_LOADED', true);

if (isset($summaryPerson)) {
    $sswPerson = $summaryPerson;
} elseif (isset($person)) {
    $sswPerson = $person;
} else {
    $u = class_exists('UnifiedAuth') ? UnifiedAuth::getLoggedInUser() : null;
    $sswPerson = isset($u['person']) ? $u['person'] : '';
}
if ($sswPerson === '') return;              // not logged in, so show nothing

$sswPerson = 'chuasf';                   // TESTING ONLY: force a person, remove before deploying

$sswComp = isset($comp_id) ? (int)$comp_id : 1;
?>
<style>
.ssw-ov{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:2000;display:flex;align-items:center;justify-content:center;padding:16px}
.ssw-ov[hidden]{display:none}
.ssw-box{background:#fff;color:#1e293b;border-radius:20px;width:100%;max-width:1000px;max-height:92vh;display:flex;flex-direction:column;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;box-shadow:0 20px 50px rgba(0,0,0,.3)}
.ssw-head{padding:14px 18px;border-bottom:1px solid #e2e8f0}
.ssw-head h3{margin:0;font-size:25px}
.ssw-head .ssw-sub{color:#64748b;font-size:15px}
.ssw-head #sswSub{color:#1e293b;font-size:14px;margin-top:2px}
.ssw-hod-cnt{display:inline-block;margin-left:10px;padding:1px 10px;border-radius:99px;background:rgba(37,99,235,.10);color:#2563eb;font-size:12.5px;font-weight:500;vertical-align:middle}
.ssw-tabs{display:flex;gap:6px;padding:10px 18px 0;border-bottom:1px solid #e2e8f0;flex-wrap:wrap}
.ssw-tab{border:1px solid #e2e8f0;border-bottom:none;border-radius:8px 8px 0 0;background:#f8fafc;padding:7px 14px;cursor:pointer;font:inherit;color:#475569}
.ssw-tab.on{background:#fff;color:#2563eb;font-weight:700;border-color:#2563eb}
.ssw-tab .ssw-ok{color:#059669;margin-left:4px}
.ssw-cnt{background:#e2e8f0;border-radius:99px;padding:0 7px;font-size:11.5px;margin-left:4px}
.ssw-body{padding:14px 18px;overflow:auto;flex:1}
.ssw-grp{font-weight:700;margin:10px 0 6px;font-size:13px}
.ssw-grp small{color:#64748b;font-weight:500}
.ssw-wrap{overflow-x:auto;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:10px}
.ssw-t{width:100%;min-width:560px;table-layout:fixed;border-collapse:collapse;font-size:13px}
.ssw-t th:first-child,.ssw-t td:first-child{width:50px}
.ssw-t th:nth-child(2),.ssw-t td:nth-child(2){width:28%}
.ssw-t th{background:#f8fafc;text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.03em;color:#64748b;padding:7px 10px;border-bottom:2px solid #e2e8f0;white-space:nowrap}
.ssw-t td{padding:7px 10px;border-bottom:1px solid #e2e8f0;vertical-align:top}
.ssw-t tr:last-child td{border-bottom:none}
.ssw-t tr.ssw-el-alert td{background:rgba(220,38,38,.08);color:#991b1b}
.ssw-t tr.ssw-el-alert td:first-child{box-shadow:inset 4px 0 0 #dc2626}
.ssw-t tr.ssw-el-alert .ssw-pos{color:#b91c1c}
.ssw-pos{color:#64748b;font-size:12px}
.ssw-p{display:inline-block;padding:1px 8px;border-radius:99px;font-size:12px;font-weight:700;white-space:nowrap}
.ssw-p.bad{background:rgba(220,38,38,.10);color:#dc2626}
.ssw-p.ok{background:rgba(5,150,105,.12);color:#059669}
.ssw-p.lv{background:rgba(37,99,235,.10);color:#2563eb}
.ssw-p.zero{background:#f1f5f9;color:#64748b}
.ssw-io{display:block;margin:1px 0}
.ssw-empty{text-align:center;color:#64748b;padding:26px 0}
.ssw-foot{padding:12px 18px;border-top:1px solid #e2e8f0;display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.ssw-btn{font:inherit;padding:8px 18px;border-radius:8px;border:1px solid #2563eb;background:#2563eb;color:#fff;font-weight:600;cursor:pointer}
.ssw-btn:disabled{background:#cbd5e1;border-color:#cbd5e1;cursor:not-allowed}
.ssw-msg{font-size:12.5px;color:#dc2626;width:100%}
.ssw-note{font-size:12.5px;color:#b45309}
.ssw-chk{display:flex;gap:8px;align-items:flex-start;cursor:pointer;font-weight:600;flex:1;min-width:240px}
.ssw-chk input{width:18px;height:18px;margin-top:2px;flex:none}
.ssw-prog{font-size:12.5px;color:#64748b;white-space:nowrap}
.ssw-actions{display:flex;align-items:center;gap:10px}
.ssw-sub-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.ssw-st{border:1px solid #e2e8f0;border-radius:99px;background:#f8fafc;padding:3px 12px;cursor:pointer;font:inherit;font-size:12.5px;color:#475569}
.ssw-st.on{background:#2563eb;border-color:#2563eb;color:#fff;font-weight:600}
.ssw-st .ssw-cnt{background:rgba(100,116,139,.18);margin-left:6px}
.ssw-st.on .ssw-cnt{background:rgba(255,255,255,.25);color:#fff}
</style>

<div class="ssw-ov" id="sswOv" hidden>
  <div class="ssw-box" role="dialog" aria-modal="true" aria-labelledby="sswTitle">
    <div class="ssw-head"><h3 id="sswTitle">Staff Summary</h3><div class="ssw-sub" id="sswSub"></div><div class="ssw-sub" id="sswSub2"></div></div>
    <div class="ssw-tabs" id="sswTabs"></div>
    <div class="ssw-body" id="sswBody"></div>
    <div class="ssw-foot">
      <label class="ssw-chk" id="sswChkWrap"><input type="checkbox" id="sswChk"><span id="sswChkTxt"></span></label>
      <div class="ssw-actions">
        <span class="ssw-note" id="sswNote"></span>
        <span class="ssw-prog" id="sswProg"></span>
        <button class="ssw-btn" id="sswAckBtn" disabled>Acknowledge</button>
      </div>
      <div class="ssw-msg" id="sswMsg"></div>
    </div>
  </div>
</div>

<script>
(function () {
  if (window.self !== window.top) return;     // do not show inside iframes

  const API  = '/accounts/mkPortal/hr/staffSummaryAPI.php';
  const PERSON = <?php echo json_encode($sswPerson); ?>;
  const COMP = <?php echo $sswComp; ?>;
  const $ = id => document.getElementById(id);
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const dmy = s => String(s || '').replace(/(\d{4})-(\d{2})-(\d{2})/g, '$3/$2/$1');
  let D = null, cur = 0;

  // ---------- cell renderers ----------
  function attCell(a) {
    if (!a) return '<span class="ssw-p zero">–</span>';
    if (a.on_leave) return '<span class="ssw-p lv">' + esc(a.leave_label || 'On leave') + '</span>';
    const inC  = a.in_missing ? '<span class="ssw-p bad">Missing</span>'
               : a.in_late    ? '<span class="ssw-p bad">' + esc(a.in) + '</span>'
               : a.in && a.in !== '—' ? '<span class="ssw-p ok">' + esc(a.in) + '</span>' : '–';
    let outC = '';
    if (a.show_out) {
      outC = a.out_missing ? '<span class="ssw-p bad">Missing</span>'
           : a.out && a.out !== '—' ? '<span class="ssw-p ok">' + esc(a.out) + '</span>' : '–';
    } else outC = '<span class="ssw-pos">—</span>';
    return '<span class="ssw-io">IN ' + inC + '</span><span class="ssw-io">OUT ' + outC + '</span>';
  }
  // withEl = true adds "N EL" / "N LWP" badges after the name (Leave tab only),
  // shown only when el_alert is true and that count is above 0
  const who = (r, withEl) => '<b>' + esc(r.fullname) + '</b>' +
    (withEl && elAlert(r) && elDays(r) > 0  ? ' <span class="ssw-p bad">' + elDays(r)  + ' EL</span>'  : '') +
    (withEl && elAlert(r) && lwpDays(r) > 0 ? ' <span class="ssw-p bad">' + lwpDays(r) + ' LWP</span>' : '') +
    '<div class="ssw-pos">' + esc(r.position || '') + (r.unit ? ' · ' + esc(r.unit) : '') + '</div>';

  // True/1/"1"/"true" all count, in case PHP sends it as a string or int.
  // Looks at r.el_alert first, then r.leave.el_alert.
  const elAlert = r => {
    const v = (r.el_alert != null) ? r.el_alert : (r.leave && r.leave.el_alert);
    return v === true || v === 1 || v === '1' || v === 'true';
  };

  // Number of EL days: use the API value if present (r.el_days or r.leave.el_days),
  // otherwise add up the "taken" leave records whose name/code mentions Emergency.
  const elDays = r => {
    const v = (r.el_days != null) ? r.el_days : (r.leave && r.leave.el_days);
    if (v != null && v !== '' && !isNaN(Number(v))) return Number(v);
    return (r.leaves || [])
      .filter(x => x.when === 'taken' && /emergency/i.test((x.name || '') + ' ' + (x.code || '')))
      .reduce((t, x) => {
        const from = new Date(x.from), to = new Date(x.to || x.from);
        if (isNaN(from.getTime()) || isNaN(to.getTime())) return t;
        return t + Math.floor((to - from) / 86400000) + 1;
      }, 0);
  };

  // Leave Without Pay days: r.lwp_days or r.leave.lwp_days from the API,
  // otherwise add up "taken" records whose name/code mentions Without Pay.
  const lwpDays = r => {
    const v = (r.lwp_days != null) ? r.lwp_days : (r.leave && r.leave.lwp_days);
    if (v != null && v !== '' && !isNaN(Number(v))) return Number(v);
    return (r.leaves || [])
      .filter(x => x.when === 'taken' && /without pay/i.test((x.name || '') + ' ' + (x.code || '')))
      .reduce((t, x) => {
        const from = new Date(x.from), to = new Date(x.to || x.from);
        if (isNaN(from.getTime()) || isNaN(to.getTime())) return t;
        return t + Math.floor((to - from) / 86400000) + 1;
      }, 0);
  };

  // function leaveCell(r) {
  //   const l = r.leave || {};
  //   const ls = (r.leaves || []).filter(x => x.when === 'taken');
  //   const up = (r.leaves || []).filter(x => x.when !== 'taken');
  //   const line = x => '<div class="ssw-pos">' + esc(x.name || x.code) + ': ' + dmy(x.from) + (x.to && x.to !== x.from ? ' – ' + dmy(x.to) : '') + '</div>';
  //   return [
  //     '<td><span class="ssw-p ' + (l.taken > 0 ? 'lv' : 'zero') + '">' + (l.taken || 0) + ' day' + (l.taken == 1 ? '' : 's') + '</span>' + ls.map(line).join('') + '</td>',
  //     '<td><span class="ssw-p ' + (l.upcoming > 0 ? 'lv' : 'zero') + '">' + (l.upcoming || 0) + ' day' + (l.upcoming == 1 ? '' : 's') + '</span>' + up.map(line).join('') + '</td>'
  //   ].join('');
  // }


  function leaveCell(r) {
      const l = r.leave || {};

      const ls = (r.leaves || []).filter(x => x.when === 'taken');
      const up = (r.leaves || []).filter(x => x.when !== 'taken');

      const line = x =>
          '<div class="ssw-pos">' +
          esc(x.name || x.code) + ': ' +
          dmy(x.from) +
          (x.to && x.to !== x.from ? ' – ' + dmy(x.to) : '') +
          '</div>';

      return [
          '<td>' +
              '<span class="ssw-p ' + (l.taken > 0 ? 'lv' : 'zero') + '">' +
              (l.taken || 0) + ' day' + (l.taken == 1 ? '' : 's') +
              '</span>' +
              ls.map(line).join('') +
          '</td>',

          '<td>' +
              '<span class="ssw-p ' + (l.upcoming > 0 ? 'lv' : 'zero') + '">' +
              (l.upcoming || 0) + ' day' + (l.upcoming == 1 ? '' : 's') +
              '</span>' +
              up.map(line).join('') +
          '</td>'
      ].join('');
  }

  function compCell(r) {
    const c = r.comp_recs || [];
    if (!c.length) return '<span class="ssw-p zero">0</span>';
    return '<span class="ssw-p bad">⚠️ ' + c.length + '</span>' +
      c.map(x => '<div class="ssw-pos">' + dmy(x.date) + ' · ' + esc(x.type || '') + ' — ' + esc(x.title || '') + '</div>').join('');
  }

  // ---------- section table ----------
  // Rows that are shown for a section (same filters used for the table and for hasRecords)
  function visibleRows(sec, g) {
    const rows = g.rows || [];
    if (sec.key === 'late')  return rows;
    if (sec.key === 'leave') return rows.filter(r => r.leave && r.leave.total > 0);
    return rows.filter(r => (r.comp || 0) > 0);
  }
  function hasRecords(sec) {
    return (sec.groups || []).some(g => visibleRows(sec, g).length > 0);
  }

  // function sectionHtml(sec) {
  //   const cols = D.day_cols || [];
  //   let any = false, html = '';
  //   (sec.groups || []).forEach(g => {
  //     const rows = visibleRows(sec, g);
  //     if (!rows.length) return;
  //     any = true;
  //     let head, body;
  //     if (sec.key === 'late') {
  //       head = '<th>No</th><th>Staff</th>' + cols.map(c => '<th>' + esc(c.label) + '</th>').join('');
  //       body = rows.map((r, i) => '<tr><td>' + (i + 1) + '</td><td>' + who(r) + '</td>' +
  //         cols.map(c => '<td>' + attCell((r.att || {})[c.date]) + '</td>').join('') + '</tr>').join('');
  //     } else if (sec.key === 'leave') {
  //       head = '<th>No</th><th>Staff</th><th>Taken</th><th>Upcoming</th>';
  //       body = rows.map((r, i) => '<tr><td>' + (i + 1) + '</td><td>' + who(r) + '</td>' + leaveCell(r) + '</tr>').join('');
  //     } else {
  //       head = '<th>No</th><th>Staff</th><th>Compliance records</th>';
  //       body = rows.map((r, i) => '<tr><td>' + (i + 1) + '</td><td>' + who(r) + '</td><td>' + compCell(r) + '</td></tr>').join('');
  //     }
  //     html += '<div class="ssw-grp">' + esc(g.unit) + (g.head_name ? ' <small>· ' + esc(g.head_word || 'Head') + ': ' + esc(g.head_name) + '</small>' : '') + '</div>' +
  //             '<div class="ssw-wrap"><table class="ssw-t"><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table></div>';
  //   });
  //   return any ? html : '<div class="ssw-empty">' + esc(sec.empty_line || 'Nothing to show.') + '</div>';
  // }

    function tableHtml(sec, rows) {
    const cols = D.day_cols || [];
    let head, body;
    if (sec.key === 'late') {
      head = '<th>No</th><th>Staff</th>' + cols.map(c => '<th>' + esc(c.label) + '</th>').join('');
      body = rows.map((r, i) => '<tr><td>' + (i + 1) + '</td><td>' + who(r) + '</td>' +
        cols.map(c => '<td>' + attCell((r.att || {})[c.date]) + '</td>').join('') + '</tr>').join('');
    } else if (sec.key === 'leave') {
      head = '<th>No</th><th>Staff</th><th>Taken</th><th>Upcoming</th>';
      body = rows.map((r, i) =>
        '<tr' + (elAlert(r) ? ' class="ssw-el-alert"' : '') + '>' +
        '<td>' + (i + 1) + '</td><td>' + who(r, true) + '</td>' + leaveCell(r) +
        '</tr>').join('');
    } else {
      head = '<th>No</th><th>Staff</th><th>Compliance records</th>';
      body = rows.map((r, i) => '<tr><td>' + (i + 1) + '</td><td>' + who(r) + '</td><td>' + compCell(r) + '</td></tr>').join('');
    }
    return '<div class="ssw-wrap"><table class="ssw-t"><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table></div>';
  }

  function sectionHtml(sec) {
    // only departments that have rows to show
    const gs = (sec.groups || []).map((g, gi) => ({ g: g, gi: gi, rows: visibleRows(sec, g) })).filter(x => x.rows.length);
    if (!gs.length) return '<div class="ssw-empty">' + esc(sec.empty_line || 'Nothing to show.') + '</div>';

    let sel = sec._g;                                            // chosen department index, null/undefined = All
    if (sel != null && !gs.some(x => x.gi === sel)) sel = null;

    let bar = '';
    if (gs.length > 1) {
      const all = gs.reduce((n, x) => n + x.rows.length, 0);
      bar = '<div class="ssw-sub-tabs">' +
        '<button class="ssw-st' + (sel == null ? ' on' : '') + '" data-g="all">All<span class="ssw-cnt">' + all + '</span></button>' +
        gs.map(x => '<button class="ssw-st' + (sel === x.gi ? ' on' : '') + '" data-g="' + x.gi + '">' + esc(x.g.unit) +
          '<span class="ssw-cnt">' + x.rows.length + '</span></button>').join('') +
        '</div>';
    }

    const show = sel == null ? gs : gs.filter(x => x.gi === sel);
    return bar + show.map(x =>
      '<div class="ssw-grp">' + esc(x.g.unit) + (x.g.head_name ? ' <small>· ' + esc(x.g.head_word || 'Head') + ': ' + esc(x.g.head_name) + '</small>' : '') + '</div>' +
      tableHtml(sec, x.rows)).join('');
  }


   // Total staff under the person (all levels), not counting the person
  async function countStaffUnder(person) {
    const r = await fetch('/accounts/mkPortal/hr/staffPoolAPI.php?comp_id=' + COMP +
      '&staff_tree_individual=' + encodeURIComponent(person), { credentials: 'same-origin' });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const j = await r.json();
    if (!j.success) throw new Error(j.message || 'failed');
    const t = j.staff_tree || {}, head = t.staff || {};

    // 1) the head's own total (already excludes him)
    if (head.total_subordinates != null) return Number(head.total_subordinates);

    // 2) counts.people includes the person, so take one off
    if (t.counts && t.counts.people != null) return Math.max(0, Number(t.counts.people) - 1);

    // 3) walk the tree, each person counted once, not counting the person
    const seen = new Set(), me = String(person).toLowerCase();
    const walk = p => (p.subordinates || []).forEach(c => {
      const k = String(c.person).toLowerCase();
      if (k !== me) seen.add(k);
      walk(c);
    });
    walk(head);
    return seen.size;
  }

  // Fallback if the call fails: people who appear in the summary, excluding the person
  function countFromSummary() {
    const me = String((D.recipient && D.recipient.person) || PERSON).toLowerCase(), seen = new Set();
    D.sections.forEach(s => (s.groups || []).forEach(g => (g.rows || []).forEach(r => {
      if (String(r.person).toLowerCase() !== me) seen.add(String(r.person).toLowerCase());
    })));
    return seen.size;
  }

  // ---------- UI ----------
  const needsAck   = s => s.tick !== false;                 // the API expects an acknowledgement for it
  const needsTick  = s => needsAck(s) && hasRecords(s);     // the person must tick it (empty tabs are skipped)
  const isChecked  = s => !!(s.ticked || s._chk || !hasRecords(s));
  const allChecked = () => D.sections.every(s => !needsTick(s) || isChecked(s));
  const readOnly   = () => D.can_write === false;

  // Every section the server is tracking that is ticked at this moment.
  // The API replaces what it is sent, so a half-answer sent as the whole
  // answer un-ticks the rest -- which is what used to happen when only the
  // newly ticked sections were sent and one had already been ticked from the
  // email. Both calls below send this.
  const tickedKeys = () => D.sections.filter(s => needsTick(s) && isChecked(s)).map(s => s.key);

  function renderTabs() {
    $('sswTabs').innerHTML = D.sections.map((x, i) =>
      '<button class="ssw-tab' + (i === cur ? ' on' : '') + '" data-i="' + i + '">' + esc(x.title) +
      '<span class="ssw-cnt">' + (x.total || 0) + '</span>' + (isChecked(x) ? '<span class="ssw-ok">✔</span>' : '') + '</button>').join('');
  }

  // Checkbox for the tab that is currently open (hidden when the tab has no records)
  function renderChecks() {
    const s = D.sections[cur], tick = needsTick(s);
    $('sswChkWrap').style.display = tick ? '' : 'none';
    $('sswChk').checked = isChecked(s);
    $('sswChk').disabled = readOnly() || !!s.ticked;
    $('sswChkTxt').textContent = s.confirm || 'I have read the above.';
    const need = D.sections.filter(needsTick), done = need.filter(isChecked).length;
    $('sswProg').textContent = (readOnly() || !need.length) ? '' : done + ' of ' + need.length + ' ticked';
  }

  function updateBtn() {
    const b = $('sswAckBtn');
    if (readOnly()) { b.textContent = 'Close'; b.disabled = false; return; }   // nothing can be saved, so let them leave
    b.textContent = 'Acknowledge';
    b.disabled = !allChecked();
  }

    // Department tabs inside a category
  $('sswBody').addEventListener('click', e => {
    const b = e.target.closest('.ssw-st'); if (!b) return;
    D.sections[cur]._g = b.dataset.g === 'all' ? null : +b.dataset.g;
    $('sswBody').innerHTML = sectionHtml(D.sections[cur]);
  });

  function render() {
    const s = D.sections[cur];
    renderTabs();
    $('sswBody').innerHTML = sectionHtml(s);
    $('sswBody').scrollTop = 0;
    renderChecks();
    $('sswNote').textContent = readOnly()
      ? 'Read-only: acknowledgement is not available for this session.'
      : (!hasRecords(s) ? 'No records in this section, nothing to tick.' : '');
    $('sswMsg').textContent = '';
    updateBtn();
  }

  // One call for all sections, e.g.
  // staffSummaryAPI.php?action=acknowledge&person=justin&comid=1&platform=web&items[]=late&items[]=leave&items[]=comp
  //
  // commit=0 says "this is where I am up to": the ticks are stored and each
  // one is logged with the time it happened, and the day stays open. Without
  // it, ticking the last box would acknowledge the day before anybody pressed
  // the button.
  async function sendTicks(items, commit) {
    const who = (D.recipient && D.recipient.person) || PERSON;
    const qs = new URLSearchParams();
    qs.append('action', 'acknowledge');
    qs.append('person', who);
    qs.append('comid', COMP);
    qs.append('platform', 'web');
    if (!commit) qs.append('commit', '0');
    items.forEach(k => qs.append('items[]', k));
    const r = await fetch(API + '?' + qs.toString(), { credentials: 'same-origin' });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const j = await r.json().catch(() => ({ success: true }));
    if (j.success === false) throw new Error(j.message || 'Acknowledge failed');
    return j;
  }

  const sendAck = items => sendTicks(items, true);

  // Fire and forget. This is a note about reading, not the answer: the
  // Acknowledge button sends the whole set again, so a report that never
  // arrives costs a line of history and not a tick. Before it existed, all
  // three ticks and the acknowledgement reached the log in the one call the
  // button made, sharing a timestamp to the second.
  function reportTick() {
    if (readOnly()) return;
    sendTicks(tickedKeys(), false).catch(() => {});
  }
  function closeAll() { $('sswOv').hidden = true; document.body.style.overflow = ''; }

  $('sswTabs').addEventListener('click', e => {
    const b = e.target.closest('.ssw-tab'); if (!b) return;
    cur = +b.dataset.i; render();
  });

  // Ticking is reported as it happens, so the log holds the minute each
  // section was read. It still does not acknowledge anything: the day is only
  // finished by the button below.
  $('sswChk').addEventListener('change', e => {
    D.sections[cur]._chk = e.target.checked;
    renderTabs(); renderChecks(); updateBtn();
    reportTick();
  });

  $('sswAckBtn').addEventListener('click', async () => {
    if (readOnly()) { closeAll(); return; }
    if (!allChecked()) return;
    const b = $('sswAckBtn'); b.disabled = true; b.textContent = 'Saving…'; $('sswMsg').textContent = '';
    try {
      // THE WHOLE ANSWER, EVERY TIME -- see tickedKeys(). The call is made
      // even when there is nothing in the list: a day whose three sections
      // are all empty has nothing to tick, and pressing the button is what
      // completes it. Sending only the sections that were new left a day
      // ticked from the email short of its own total, so it never completed.
      await sendAck(tickedKeys());
      D.sections.forEach(s => { if (isChecked(s)) { s.ticked = true; } });
      closeAll();
    } catch (err) {
      $('sswMsg').textContent = 'Couldn’t save your acknowledgement: ' + err.message + '. Please try again.';
      updateBtn();
    }
  });

  // ---------- loader: fetch the summary and open the popup only when show_popup is true ----------
  (async () => {
    try {
      const qs = '?action=mine&comid=' + COMP + (PERSON ? '&person=' + encodeURIComponent(PERSON) : '');
      const r = await fetch(API + qs, { credentials: 'same-origin' });
      if (!r.ok) return;
      const j = await r.json();
      if (!j.success || j.show_popup !== true) return;          // nothing to show
      D = j;
      // $('sswTitle').textContent = j.title || 'Staff Summary';
      const baseTitle = j.title || 'Staff Summary';
      $('sswTitle').textContent = baseTitle;

      // Row 1: HOD name (staff count is added below once known). Row 2: instruction.
      const hodName = (j.recipient && j.recipient.fullname) ? j.recipient.fullname : '';
      const setHod = n => {
        $('sswSub').innerHTML = (hodName ? '<b>' + esc(hodName) + '</b>' : '') +
          (n != null ? '<span class="ssw-hod-cnt">Total subordinates: <b>' + esc(n) + '</b></span>' : '');
      };
      setHod(null);
      $('sswSub2').textContent = 'Please read and acknowledge each section.';
      cur = Math.max(0, D.sections.findIndex(s => needsTick(s) && !isChecked(s)));
      render();
      $('sswOv').hidden = false;
      document.body.style.overflow = 'hidden';

      // Add the total staff under this person after the name (shown as soon as it is known)
      const hodPerson = (j.recipient && j.recipient.person) || PERSON;
      countStaffUnder(hodPerson)
        .catch(() => countFromSummary())
        .then(n => setHod(n));
    } catch (e) { console.warn('Staff summary popup failed:', e); }
  })();
})();
</script>