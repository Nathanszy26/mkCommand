/*
 * The Group Photo columns resolve per PERIOD, not per photo: with two 8AM
 * photos, the first one coming back without a face says nothing yet. Getting
 * that bookkeeping wrong writes "not detected" against someone who is clearly
 * visible in the second photo, so it is checked here in isolation.
 *
 * The counter logic below mirrors startTable() and scanPhoto()'s done() in
 * gwAttendanceReport.php.
 *
 * Run: node test_matrix_periods.js
 */

function makeRun(attachments) {
    var state = { pending: attachments.length, periodPending: {}, periodFailed: {} };
    var finalized = [];      // periods finalized, in order
    var finishedAll = false;

    attachments.forEach(function (a) {
        state.periodPending[a.period] = (state.periodPending[a.period] || 0) + 1;
    });

    function report(attachment, ok) {
        state.pending--;
        if (!ok) { state.periodFailed[attachment.period] = true; }

        state.periodPending[attachment.period]--;
        if (state.periodPending[attachment.period] <= 0) {
            finalized.push(attachment.period);
        }
        if (state.pending <= 0) { finishedAll = true; }
    }

    return {
        report: report,
        finalized: finalized,
        failedPeriods: state.periodFailed,
        isDone: function () { return finishedAll; },
        snapshot: function () { return finalized.slice(); }
    };
}

var failures = [];

function check(label, got, want) {
    var g = JSON.stringify(got), w = JSON.stringify(want);
    var ok = g === w;
    console.log((ok ? 'PASS  ' : 'FAIL  ') + label);
    if (!ok) { failures.push(label + '\n   got:  ' + g + '\n   want: ' + w); }
}

// 1. One photo per period: each resolves as soon as its own photo lands.
var A = { id: 1, period: '8AM' }, B = { id: 2, period: '1PM' };
var r = makeRun([A, B]);
r.report(A, true);
check('a period with one photo resolves immediately', r.snapshot(), ['8AM']);
r.report(B, true);
check('the second period resolves on its own photo', r.snapshot(), ['8AM', '1PM']);
check('all photos reported', r.isDone(), true);

// 2. Two photos in the SAME period: the column must wait for both.
var P1 = { id: 1, period: '8AM' }, P2 = { id: 2, period: '8AM' };
r = makeRun([P1, P2]);
r.report(P1, true);
check('a period with two photos does NOT resolve on the first', r.snapshot(), []);
r.report(P2, true);
check('it resolves once the second lands', r.snapshot(), ['8AM']);

// 3. A period is finalized exactly once, never twice.
r = makeRun([P1, P2]);
r.report(P1, true);
r.report(P2, true);
check('finalized exactly once', r.snapshot().length, 1);

// 4. A failed photo marks its period failed, so the column says "scan failed"
//    rather than asserting an absence it cannot know about.
var F = { id: 9, period: '4.30PM' };
r = makeRun([F]);
r.report(F, false);
check('a failed scan flags its period', !!r.failedPeriods['4.30PM'], true);
check('a failed scan still resolves the column', r.snapshot(), ['4.30PM']);

// 5. One failure among several in the same period still flags it: a column
//    cannot be called complete when part of its evidence never arrived.
r = makeRun([P1, P2]);
r.report(P1, false);
r.report(P2, true);
check('one failure in a period flags the whole period', !!r.failedPeriods['8AM'], true);
check('the period still resolves after both report', r.snapshot(), ['8AM']);

// 6. Mixed periods resolve independently and in completion order.
var X = { id: 1, period: '8AM' }, Y = { id: 2, period: '8AM' }, Z = { id: 3, period: '1PM' };
r = makeRun([X, Y, Z]);
r.report(Z, true);
check('1PM resolves while 8AM is still outstanding', r.snapshot(), ['1PM']);
r.report(X, true);
check('8AM still waiting on its second photo', r.snapshot(), ['1PM']);
r.report(Y, true);
check('8AM resolves last', r.snapshot(), ['1PM', '8AM']);
check('everything reported', r.isDone(), true);

console.log('');
if (failures.length) {
    console.log(failures.length + ' FAILURE(S)\n');
    failures.forEach(function (f) { console.log(f + '\n'); });
    process.exit(1);
}
console.log('All period-completion checks passed.');
