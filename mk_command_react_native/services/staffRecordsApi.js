// Network layer for the MK Command "Memo" and "Merit/Demerit" screens.
// UI components never build URLs or parse responses themselves — they call these.
// One place owns the endpoints. Same mobile backend + auth (person/comp_id) as
// staffHierarchy.php / jobSpecWrite.php, hosted in the same mkCommand folder.
//
//   staffRecords.php    reads  — what a staff member has received
//   mkCommandIssue.php  writes — issuing a memo / merit / demerit to your team

const MK_COMMAND_BASE = 'https://globportal.com/accounts/mkPortal/mkCommand/';

const STAFF_RECORDS_URL = MK_COMMAND_BASE + 'staffRecords.php';
const ISSUE_URL         = MK_COMMAND_BASE + 'mkCommandIssue.php';

/**
 * Form-encodes a flat param bag. Empty / absent values are dropped so the
 * server's own defaults apply, and arrays collapse to CSV — which is what
 * mkCommandIssue.php's idList() reads for to_ids / cc_ids.
 */
const buildQuery = (params) => {
    const parts = [];
    Object.keys(params).forEach((key) => {
        const value = Array.isArray(params[key]) ? params[key].join(',') : params[key];
        if (value === undefined || value === null || value === '') {
            return;
        }
        parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
    });
    return parts.join('&');
};

/** Fail loudly on transport errors, HTTP errors and { success: false } alike,
 *  so every caller can treat "returned" as "it worked". */
async function readJson(res) {
    const data = await res.json();
    if (!res.ok || !data.success) {
        throw new Error(data.message || ('API error ' + res.status));
    }
    return data;
}

function requireSession(target) {
    if (!target || !target.person || !target.compId) {
        throw new Error('Missing user session.');
    }
}

async function getJson(params) {
    return readJson(await fetch(STAFF_RECORDS_URL + '?' + buildQuery(params)));
}

/**
 * Writes go by POST, not GET: a memo body is free text and would blow past URL
 * length limits, and these calls are not idempotent.
 *
 * With no attachments the body stays form-urlencoded — small, and identical to
 * what it was before files were a possibility. With attachments it switches to
 * multipart, posting them as attachment_1..attachment_N to match the field
 * names the two web upload pages already use.
 *
 * The Content-Type header is deliberately NOT set for multipart: fetch has to
 * generate it itself so the boundary token matches the body it builds.
 */
async function postIssue(action, user, fields, attachments) {
    requireSession(user);

    const params = Object.assign(
        { action: action, person: user.person, comp_id: user.compId },
        fields
    );
    const files = attachments || [];

    const request = files.length
        ? { body: buildFormData(params, files) }
        : {
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: buildQuery(params),
          };

    return readJson(await fetch(ISSUE_URL, Object.assign({ method: 'POST' }, request)));
}

/**
 * Same param rules as buildQuery — arrays to CSV, empties dropped — plus the
 * picked files. Each file is { uri, name, type }, which is React Native's own
 * shape for a multipart part.
 */
function buildFormData(params, files) {
    const form = new FormData();

    Object.keys(params).forEach((key) => {
        const value = Array.isArray(params[key]) ? params[key].join(',') : params[key];
        if (value === undefined || value === null || value === '') {
            return;
        }
        form.append(key, String(value));
    });

    // Mirrors the hidden total_attachments field on the web forms.
    form.append('total_attachments', String(files.length));

    files.forEach((file, i) => {
        form.append('attachment_' + (i + 1), {
            uri: file.uri,
            name: file.name,
            type: file.type,
        });
    });

    return form;
}

/* --- reads ---------------------------------------------------------------- */

// Memos addressed to this staff (or to ALL), newest first.
// Returns { memos: [{ id, ref, date, to_name, from, subject }], meName }.
// meName is the logged-in staff's fullname, used to highlight their own name
// within a memo's recipient list.
export async function fetchMemos(target) {
    requireSession(target);
    const data = await getJson({
        action: 'memos',
        person: target.person,
        comp_id: target.compId,
    });
    return { memos: data.memos || [], meName: data.me_name || '' };
}

// One memo including its body and live attachments. Only returns memos this
// staff can already see in the list; anything else comes back as a 404.
// Returns { id, ref, date, to_name, cc_name, from, subject, content,
//           attachments: [{ id, name, url, is_image }] }.
export async function fetchMemoDetail(target, memoId) {
    requireSession(target);
    const data = await getJson({
        action: 'memo_detail',
        person: target.person,
        comp_id: target.compId,
        id: memoId,
    });
    return data.memo || null;
}

// Merit/Demerit records for this staff, newest first, optionally filtered by year.
// Returns [{ id, ref, date, staff_name, title, merit_demerit, points }].
export async function fetchPerformance(target, year) {
    requireSession(target);
    const data = await getJson({
        action: 'performance',
        person: target.person,
        comp_id: target.compId,
        year: year, // '' / undefined => all years
    });
    return data.performance || [];
}

/* --- writes --------------------------------------------------------------- */

// Active staff_performance_types, for the Type picker on the issue form.
// Returns [{ id, title, merit_demerit, points }].
export async function fetchPerformanceTypes(user) {
    const data = await postIssue('types', user, {});
    return data.types || [];
}

// The addressable staff directory for the memo CC picker — the same population
// the web form's To/CC selects offer (subsidiaries flagged staff_memo, active
// users), grouped by company code.
// Returns [{ id, fullname, position, email, mobile_no, company }].
export async function fetchAllStaff(user) {
    const data = await postIssue('staff', user, {});
    return data.staff || [];
}

// Creates ONE staff_memo row addressed to every recipient (web stores CSV ids).
// issue: { to_ids[], cc_ids[], ref, from, subject, content }
// attachments: optional [{ uri, name, type }]
// Returns { id, recipients, attachments: { saved, errors[] } }.
export function submitMemo(user, issue, attachments) {
    return postIssue('memo', user, issue, attachments);
}

// Creates ONE staff_performance row PER recipient, in a single transaction.
// issue: { to_ids[], ref, title, merit_demerit: 'Merit'|'Demerit', type_id, points }
// attachments: optional [{ uri, name, type }] — copied to every recipient's row.
// Returns { created, attachments: { saved, errors[] } }.
export function submitPerformance(user, issue, attachments) {
    return postIssue('performance', user, issue, attachments);
}