import { fetchApprovalAccess } from './api.js';

/**
 * Whether the Approval tab is shown, the number on it, and whether the server
 * has answered yet.
 *
 * Held here rather than in either component because two of them need it: the
 * navigator draws the label, and the screen asks for a refresh when a decision
 * is taken or the tab is left. Neither owns the other, so neither owns this.
 *
 * `resolved` matters on its own: the navigator cannot pick a landing tab before
 * it knows whether the Approval tab exists, so "no answer yet" and "answered,
 * no access" have to be tellable apart.
 */
let state = { resolved: false, authorized: false, count: 0 };
let listeners = [];

const publish = (next) => {
    state = next;
    listeners.forEach((listener) => listener(state));
};

export const getApproval = () => state;

/** Subscribe to changes. Fires immediately with the current value; returns an unsubscribe. */
export const subscribeApproval = (listener) => {
    listeners.push(listener);
    listener(state);
    return () => {
        listeners = listeners.filter((entry) => entry !== listener);
    };
};

/**
 * Re-read access and the waiting count.
 *
 * A failed read still resolves, keeping whatever was last known: a dropped
 * connection must neither make the tab vanish from under someone working
 * through the queue, nor leave the navigator waiting forever on an answer that
 * is not coming. Nothing is authorised on this side anyway -- the API and the
 * page it points at each check for themselves.
 */
export const refreshApproval = async () => {
    try {
        const payload = await fetchApprovalAccess();
        publish({ resolved: true, authorized: !!payload.authorized, count: payload.count || 0 });
    } catch (error) {
        console.warn('Approval count refresh failed:', error.message);
        publish({ ...state, resolved: true });
    }
    return state;
};