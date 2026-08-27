// Network layer for the Job Spec feature. UI components never build URLs or
// parse responses themselves — they call these. One place owns the endpoints.
 
const JOB_SPECS_URL = 'https://mkcommand.mkgroup.my/API/jobSpecs.php';
const JOB_SPEC_WRITE_URL = 'https://globportal.com/accounts/mkPortal/mkCommand/jobSpecWrite.php';
 
const buildQuery = (params) =>
    Object.keys(params)
        .filter((k) => params[k] !== undefined && params[k] !== null && params[k] !== '')
        .map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(params[k]))
        .join('&');
 
// Read the active list (mkPortal approved version, else glob_portal source).
export async function fetchJobSpecs(target) {
    const person = target.person;
    const compId = target.compId;
    const id = target.id;
    if (!person && !compId && !id) {
        throw new Error('No staff specified.');
    }
    const qs = buildQuery({ person: person, comp_id: compId, id: id, is_gw: target.isGw ? 1 : 0 });
    const res = await fetch(JOB_SPECS_URL + '?' + qs);
    const data = await res.json();
    if (!res.ok || !data.success) {
        throw new Error(data.message || ('API error ' + res.status));
    }
    return {
        source: data.source,
        version: data.version,
        job_specs: data.job_specs || [],
    };
}
 
// Submit a whole-list snapshot as a new version.
// items: [{ task, source_job_description_id|null, sort_order }]
// is_gw marks the target as a general worker: identity and approval routing
// then come from monthly_assign_gw instead of organization_chart.
// Returns { version_id, status } where status is 'approved' (auto) or 'pending'.
export async function submitJobSpecVersion(args) {
    const body = buildQuery({
        action: 'submit',
        person: args.person,
        comp_id: args.compId,
        is_gw: args.isGw ? 1 : 0,
        submitter: args.submitter,
        submitter_comp_id: args.submitterCompId,
        items: JSON.stringify(args.items),
    });
    const res = await fetch(JOB_SPEC_WRITE_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body,
    });
    const data = await res.json();
    if (!res.ok || !data.success) {
        throw new Error(data.message || ('API error ' + res.status));
    }
    return { version_id: data.version_id, status: data.status };
}
 
// Pending versions awaiting this superior's review (enriched with names + tasks).
export async function fetchPending(args) {
    const qs = buildQuery({
        action: 'pending',
        boss: args.boss,
        boss_comp_id: args.bossCompId,
    });
    const res = await fetch(JOB_SPEC_WRITE_URL + '?' + qs);
    const data = await res.json();
    if (!res.ok || !data.success) {
        throw new Error(data.message || ('API error ' + res.status));
    }
    return data.pending || [];
}
 
// Approve or reject one pending version. action: 'approve' | 'reject'.
export async function reviewJobSpecVersion(args) {
    const body = buildQuery({
        action: args.action,
        version_id: args.versionId,
        reviewer: args.reviewer,
        reviewer_comp_id: args.reviewerCompId,
        note: args.note,
    });
    const res = await fetch(JOB_SPEC_WRITE_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body,
    });
    const data = await res.json();
    if (!res.ok || !data.success) {
        throw new Error(data.message || ('API error ' + res.status));
    }
    return { version_id: data.version_id, status: data.status };
}