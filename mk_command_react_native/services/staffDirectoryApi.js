// Network layer for the MK Command "Staff Directory Search" sheet.
// UI components never build URLs or parse responses themselves — they call these.
// Same mobile backend + auth (person/comp_id) as staffHierarchy.php, hosted in
// the same mkCommand folder.
//
//   staffDirectory.php  reads — company list, staff search, one staff's detail

const MK_COMMAND_BASE = 'https://globportal.com/accounts/mkPortal/mkCommand/';

const STAFF_DIRECTORY_URL = MK_COMMAND_BASE + 'staffDirectory.php';

/** Mirrors DirectoryService::MIN_QUERY_LENGTH — the server returns an empty
 *  result below it, so the UI can skip the round-trip entirely. */
export const MIN_QUERY_LENGTH = 2;

/** Mirrors DirectoryRepository::NO_DEPARTMENT. Selecting it asks for the people
 *  with no department on file — 107 of Globinaco's 352, so it is a bucket you
 *  can pick, not a gap. Keep the two in step. */
export const NO_DEPARTMENT = '__none__';

const buildQuery = (params) => {
    const parts = [];
    Object.keys(params).forEach((key) => {
        const value = params[key];
        if (value === undefined || value === null || value === '') {
            return;
        }
        parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
    });
    return parts.join('&');
};

/** Fail loudly on transport errors, HTTP errors and { success: false } alike,
 *  so every caller can treat "returned" as "it worked". */
async function getJson(params) {
    const res = await fetch(STAFF_DIRECTORY_URL + '?' + buildQuery(params));
    const data = await res.json();
    if (!res.ok || !data.success) {
        throw new Error(data.message || 'API error ' + res.status);
    }
    return data;
}

function requireSession(session) {
    if (!session || !session.person || !session.compId) {
        throw new Error('Missing user session.');
    }
}

/**
 * Companies worth offering in the picker — every subsidiary with at least one
 * visible staff member or active GW. Also returns own_comp_id, which is the
 * company the sheet opens on.
 */
export async function fetchCompanies(session) {
    requireSession(session);
    const data = await getJson({
        action: 'companies',
        person: session.person,
        comp_id: session.compId,
    });
    return {
        ownCompId: data.own_comp_id,
        companies: data.companies || [],
    };
}

/**
 * The departments to offer in the filter for one company, with head counts.
 *
 * Two lists, not one: `departments` comes from users.department, `gwDistricts`
 * from monthly_assign_gw_district — a department and a geographic district are
 * not the same kind of thing, so they are shown as separate groups even though
 * they feed the same filter.
 */
export async function fetchDepartments(session, targetCompId) {
    requireSession(session);
    const data = await getJson({
        action: 'departments',
        person: session.person,
        comp_id: session.compId,
        target_comp_id: targetCompId,
    });
    return {
        departments: data.departments || [],
        gwDistricts: data.gw_districts || [],
    };
}

/**
 * The org navigator's top level for one company — its "root folder".
 *
 * For Globinaco that is the live top-level departments, each carrying its name
 * and its head, which is how manpower_summary.php reads the company. Every
 * other company has no departments on file, so it is whoever sits in the chart
 * with nobody above them. `source` says which rule produced the list.
 */
export async function fetchRoots(session, targetCompId) {
    requireSession(session);
    const data = await getJson({
        action: 'roots',
        person: session.person,
        comp_id: session.compId,
        target_comp_id: targetCompId,
    });
    return {
        source: data.source,
        roots: data.roots || [],
    };
}

/**
 * The committees one company is allowed to see, each with its full membership
 * already attached — the other way of reading a company's top level.
 *
 * Which committees come back is decided by mk_committee.comp_id, a list of the
 * companies that may SEE each one (empty = all of them). Who is IN one is not
 * filtered by that at all, so a committee scoped to Globinaco still lists its
 * members from every other subsidiary. See committee_visibility.sql.
 *
 * `installed` is false where the committee tables have not been created on the
 * host yet. That is a different thing from a company with no committees, and
 * the screen says so differently — so it is reported rather than flattened
 * into an empty list.
 */
export async function fetchCommittees(session, targetCompId) {
    requireSession(session);
    const data = await getJson({
        action: 'committees',
        person: session.person,
        comp_id: session.compId,
        target_comp_id: targetCompId,
    });
    return {
        installed: data.installed !== false,
        committees: data.committees || [],
    };
}

/**
 * Staff and GW in `targetCompId` matching `query` on name, email, mobile,
 * department, position or the task text of their latest approved job spec.
 * `department` is optional; null means every department.
 *
 * Below MIN_QUERY_LENGTH this resolves to an empty list without calling the
 * server — the same answer the server would give, minus the request. A short
 * query is the normal state of a search box being typed into, not an error.
 */
export async function searchDirectory(session, query, targetCompId, department) {
    requireSession(session);
    const q = (query || '').trim();
    if (q.length < MIN_QUERY_LENGTH) {
        return [];
    }
    const data = await getJson({
        action: 'search',
        person: session.person,
        comp_id: session.compId,
        target_comp_id: targetCompId,
        department,
        q,
    });
    return data.results || [];
}

/**
 * Everybody in `targetCompId` (optionally one department), already ordered by
 * department then name so the caller can cut it into sections in one pass.
 *
 * This is what the sheet shows before anything is typed: a directory you can
 * read is more use than an empty box telling you to search. Capped server-side;
 * `truncated` says when the cap was reached rather than quietly showing part of
 * a company.
 */
export async function browseDirectory(session, targetCompId, department) {
    requireSession(session);
    const data = await getJson({
        action: 'browse',
        person: session.person,
        comp_id: session.compId,
        target_comp_id: targetCompId,
        department,
    });
    return {
        rows: data.results || [],
        truncated: !!data.truncated,
    };
}

/**
 * One person's full record: contacts, reporting line, direct-report count and
 * their latest approved job spec. `isGw` picks which table they come from, so
 * it has to be carried through from the search result that was tapped — a GW
 * code and a users.person can collide.
 */
export async function fetchStaffDetail(session, target) {
    requireSession(session);
    const data = await getJson({
        action: 'detail',
        person: session.person,
        comp_id: session.compId,
        target_person: target.person,
        target_comp_id: target.comp_id,
        is_gw: target.is_gw ? 1 : 0,
    });
    return data.staff;
}
