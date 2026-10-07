/*
 * markCell() decides which row(s) in the matrix a recognised face belongs to.
 *
 * It matters because the keys disagree in the real data: the face service
 * identifies people by GW CODE, while a matrix row is a monthly_assign_gw row
 * with an id — and 19 active codes exist on TWO such rows at once (the same
 * worker registered under two mandors). Matching on the id alone stranded those
 * people: the tick had no row to land on, and they were then reported as extra
 * people standing beside themselves.
 *
 * markCell() below is copied verbatim from gwAttendanceReport.php; only the DOM
 * it queries is faked.
 *
 * Run: node test_matrix_marking.js
 */

// ---- verbatim from gwAttendanceReport.php ----
function cssEscape(value) {
    return String(value == null ? '' : value).replace(/["\\]/g, '\\$&');
}

function markCell(table, person, period, confidence) {
    var sel = [];
    if (person.code) {
        sel.push('.gwPhotoCell[data-gw-code="' + cssEscape(person.code)
                 + '"][data-period="' + cssEscape(period) + '"]');
    }
    if (person.gw_id) {
        sel.push('.gwPhotoCell[data-gw-id="' + cssEscape(person.gw_id)
                 + '"][data-period="' + cssEscape(period) + '"]');
    }

    var cells = [];
    for (var s = 0; s < sel.length && cells.length === 0; s++) {
        cells = table.querySelectorAll(sel[s]);
    }
    if (!cells.length) { return false; }

    var pct = Math.round((confidence || 0) * 100);
    for (var i = 0; i < cells.length; i++) {
        var cell = cells[i];
        cell.setAttribute('data-resolved', '1');
        cell.style.background = '#eaf7ea';
        cell.style.color = '#2e7d32';
        cell.innerHTML = '&#10003;';
        cell.title = 'Identified in the ' + period + ' photo (' + pct + '% confidence)';
    }
    return true;
}
function belongsInExtras(person, placed) {
    if (placed) { return false; }
    return !(person.type === 'gw' && person.in_pool);
}
// ---- end verbatim ----

/* Minimal stand-in for the cells of one matrix. The selector parser understands
 * exactly the two shapes markCell builds and nothing else, so a change in those
 * selectors shows up here as a miss rather than passing silently. */
function makeTable(rows) {
    var cells = rows.map(function (r) {
        return {
            attrs: { 'data-gw-id': String(r.id), 'data-gw-code': r.code, 'data-period': r.period },
            style: {}, innerHTML: '', title: '',
            setAttribute: function (k, v) { this.attrs[k] = v; },
            getAttribute: function (k) { return this.attrs[k]; }
        };
    });

    return {
        cells: cells,
        querySelectorAll: function (sel) {
            var m = sel.match(/^\.gwPhotoCell\[([a-z-]+)="(.*?)"\]\[data-period="(.*?)"\]$/);
            if (!m) { throw new Error('stub cannot parse selector: ' + sel); }
            return cells.filter(function (c) {
                return c.attrs[m[1]] === m[2] && c.attrs['data-period'] === m[3];
            });
        },
        ticked: function () {
            return cells.filter(function (c) { return c.attrs['data-resolved']; })
                        .map(function (c) { return c.attrs['data-gw-id'] + '/' + c.attrs['data-period']; })
                        .sort();
        }
    };
}

var failures = [];

function check(label, got, want) {
    var g = JSON.stringify(got), w = JSON.stringify(want);
    var ok = g === w;
    console.log((ok ? 'PASS  ' : 'FAIL  ') + label);
    if (!ok) { failures.push(label + '\n   got:  ' + g + '\n   want: ' + w); }
}

// The real shape of the bug: KMU153 on two active rows, 2646 and 2649.
// The enrolment points at 2646; this report is the mandor who holds 2649.
var dup = [
    { id: 2646, code: 'KMU153', period: '8AM' },
    { id: 2649, code: 'KMU153', period: '8AM' },
    { id: 2318, code: 'KMU-66', period: '8AM' }
];

var t = makeTable(dup);
check('a duplicated code ticks BOTH of its rows',
      markCell(t, { code: 'KMU153', gw_id: 2646 }, '8AM', 0.64) && t.ticked(),
      ['2646/8AM', '2649/8AM']);

// The id the enrolment carries is not even in this report; the code still lands.
t = makeTable(dup);
markCell(t, { code: 'KMU153', gw_id: 99999 }, '8AM', 0.64);
check('a stale id does not matter when the code matches', t.ticked(), ['2646/8AM', '2649/8AM']);

// A GW with no code falls back to the id rather than matching nothing.
t = makeTable([{ id: 2318, code: '', period: '8AM' }]);
check('a GW with no code is still placed by id',
      markCell(t, { code: '', gw_id: 2318 }, '8AM', 0.7) && t.ticked(), ['2318/8AM']);

// Someone genuinely absent from the table is reported as unplaced, which is what
// sends them to "Additional from the photo".
t = makeTable(dup);
check('an unknown person is not placed',
      markCell(t, { code: 'BFT126', gw_id: 1234 }, '8AM', 0.7), false);
check('and nothing was ticked by the attempt', t.ticked(), []);

// Only the period asked for is touched.
t = makeTable([
    { id: 2646, code: 'KMU153', period: '8AM' },
    { id: 2646, code: 'KMU153', period: '1PM' }
]);
markCell(t, { code: 'KMU153', gw_id: 2646 }, '1PM', 0.7);
check('only the matching period is ticked', t.ticked(), ['2646/1PM']);

// A period code containing a dot ("4.30PM") must survive the selector.
t = makeTable([{ id: 1, code: 'PNG-91', period: '4.30PM' }]);
check('a period code with a dot still matches',
      markCell(t, { code: 'PNG-91', gw_id: 1 }, '4.30PM', 0.7) && t.ticked(), ['1/4.30PM']);

// A quote in a code would otherwise break out of the selector string.
t = makeTable([{ id: 7, code: 'A"B', period: '8AM' }]);
check('a quote in a code does not break the selector',
      markCell(t, { code: 'A"B', gw_id: 7 }, '8AM', 0.7) && t.ticked(), ['7/8AM']);

// Confidence reaches the tooltip, since that is how a supervisor judges a tick.
t = makeTable([{ id: 2646, code: 'KMU153', period: '8AM' }]);
markCell(t, { code: 'KMU153', gw_id: 2646 }, '8AM', 0.6432);
check('tooltip reports rounded confidence',
      t.cells[0].title, 'Identified in the 8AM photo (64% confidence)');

// ── Who is listed under "Additional from the photo" ──────────────────────────
// The block must not hide people who were genuinely in the photo, and must not
// print a rostered GW twice.
check('a ticked person is never listed again below',
      belongsInExtras({ type: 'gw', in_pool: true }, true), false);
check("another mandor's GW is listed",
      belongsInExtras({ type: 'gw', in_pool: false }, false), true);
check('a visiting staff member is listed',
      belongsInExtras({ type: 'staff', in_pool: false }, false), true);
check('the mandor themselves is listed, even though they are in the pool',
      belongsInExtras({ type: 'staff', in_pool: true }, false), true);
check('a rostered GW whose row could not be found is NOT listed twice',
      belongsInExtras({ type: 'gw', in_pool: true }, false), false);

console.log('');
if (failures.length) {
    console.log(failures.length + ' FAILURE(S)\n');
    failures.forEach(function (f) { console.log(f + '\n'); });
    process.exit(1);
}
console.log('All cell-marking checks passed.');
