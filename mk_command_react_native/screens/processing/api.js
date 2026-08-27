import store from 'react-native-simple-store';

/**
 * The only server address the app knows. Everything goes through
 * processing_redirect.php, which decides where each module actually lives -- so
 * a page can be moved, renamed or opened with different parameters server-side
 * without a new build.
 */
const ENTRY = 'https://globportal.com/accounts/mkPortal/processing/processing_redirect.php';

/** The signed-in user, or an Error if there isn't one. */
export const currentUser = async () => {
    const user = await store.get('AppUser');
    if (!user || !user.person || !user.comid) {
        throw new Error('Missing user session. Please log in again.');
    }
    return user;
};

/** One module's address, with the identity every target needs to gate itself. */
const moduleUrl = (module, user) =>
    `${ENTRY}?module=${encodeURIComponent(module)}` +
    `&person=${encodeURIComponent(user.person)}` +
    `&comid=${encodeURIComponent(user.comid)}`;

/**
 * May this user open the Approval tab, and how many decisions are waiting.
 * Resolves with { authorized, access, count }.
 *
 * The entry point answers a POST with a 307, which keeps the method and the body
 * intact all the way to the API, so fetch follows it without losing anything.
 */
export const fetchApprovalAccess = async () => {
    const user = await currentUser();
    const response = await fetch(moduleUrl('access', user), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:
            `person=${encodeURIComponent(user.person)}` +
            `&comid=${encodeURIComponent(user.comid)}`,
    });

    const payload = await response.json();
    if (!response.ok || !payload.ok) {
        throw new Error(payload.message || `API error ${response.status}`);
    }
    return payload;
};

/**
 * The approval queue page. The web view lands on whatever the entry point points
 * at, opened with whatever it adds -- embed=1 today, which is what drops the
 * page's own title bar and status tabs.
 */
export const approvalListUrl = (user) => moduleUrl('list', user);

/** True once a decision has been recorded -- the page redirects to ?...&done=. */
export const isDecisionUrl = (url) => /[?&]done=/.test(url || '');