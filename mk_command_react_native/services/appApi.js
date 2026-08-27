// Network layer for App Tracker data consumed OUTSIDE the App screen (e.g. the
// Job Spec editor's app picker). Same mobile backend App.js uses; person/comp_id
// authenticate the request server-side. One place owns this endpoint.

const APP_IMPL_URL =
    'https://globportal.com/accounts/mkPortal/app_implementation/app_implementation.php';

const buildQuery = (params) =>
    Object.keys(params)
        .filter((k) => params[k] !== undefined && params[k] !== null && params[k] !== '')
        .map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(params[k]))
        .join('&');

// The apps assigned to a given staff (owner/developer/user). Used to populate the
// per-item picker so a spec item is tagged with apps that staff actually works on.
// Returns [{ application_id, application_title, application_section }].
export async function fetchAppListing(target) {
    const qs = buildQuery({
        mobile: 1,
        action: 'get_my_app_listing',
        person: target.person,
        comp_id: target.compId,
    });
    const res = await fetch(APP_IMPL_URL + '?' + qs);
    const data = await res.json();
    if (!res.ok || !data.success) {
        throw new Error(data.message || ('API error ' + res.status));
    }
    return (data.apps || []).map((a) => ({
        application_id: a.application_id,
        application_title: a.application_title,
        application_section: a.application_section || 'Other',
    }));
}