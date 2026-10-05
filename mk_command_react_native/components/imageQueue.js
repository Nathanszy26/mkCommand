/**
 * A load gate for profile photos, plus what is known about each URL.
 *
 * Why this exists: a department list mounts twenty-odd photos at once, and
 * opening a team adds twenty more. Every one of them is an immediate request to
 * the same host, for a full-size file that will be drawn in a 30px circle. The
 * native image layer runs a small thread pool, so a burst like that does not
 * queue politely — the losers time out and report an error, and the row falls
 * back to initials even though the file is there. Retrying helped, but every
 * retry fired at once too, so the burst simply happened again.
 *
 * So the burst is removed instead: at most MAX_PARALLEL photos load at a time
 * and the rest wait their turn. Nothing is dropped, only ordered.
 *
 * What is remembered, for the life of the process:
 *   ok   a URL that has loaded once. Lists recycle rows constantly, and a photo
 *        already in the native cache must not queue behind twenty new requests
 *        to be shown again.
 *   bad  a URL that failed every attempt. Without this, scrolling past a dead
 *        photo re-runs all three attempts every single time it comes back on
 *        screen. It expires, because "the server was briefly unreachable" and
 *        "this file is gone" look identical from here and only one of them
 *        stays true.
 *
 * No React and no react-native imports, so the queue can be run and tested on
 * its own — a leak here would mean photos that never load at all, which is a
 * worse failure than the one it is fixing.
 */

const MAX_PARALLEL = 5;

/** How long a failure is trusted before the photo is allowed to try again. */
const BAD_TTL_MS = 60000;

let active = 0;
let queue = [];
let outcome = Object.create(null); // uri -> { result: 'ok'|'bad', at: ms }
let listeners = [];                // called when failures are cleared en masse

const now = () => Date.now();

/**
 * What is known about a URL right now: 'ok', 'bad', or null for "not tried, or
 * tried too long ago to still believe".
 */
export function knownOutcome(uri) {
    if (!uri) {
        return null;
    }
    const seen = outcome[uri];
    if (!seen) {
        return null;
    }
    if (seen.result === 'bad' && now() - seen.at > BAD_TTL_MS) {
        delete outcome[uri];
        return null;
    }
    return seen.result;
}

export function remember(uri, result) {
    if (uri) {
        outcome[uri] = { result: result, at: now() };
    }
}

/** Drop what is known about a URL, so the next render tries it afresh. Used by
 *  tap-to-retry: the person is telling us the failure is stale. */
export function forget(uri) {
    if (uri) {
        delete outcome[uri];
    }
}

/**
 * Forget every failure at once, and tell the photos showing one to try again.
 *
 * Successes are kept: they are what stops a list re-requesting photos it has
 * already got. This is what pull-to-refresh pulls — one gesture that retries
 * every grey circle on the screen instead of tapping them one at a time.
 */
export function forgetFailures() {
    let cleared = 0;
    Object.keys(outcome).forEach((uri) => {
        if (outcome[uri].result === 'bad') {
            delete outcome[uri];
            cleared += 1;
        }
    });
    listeners.forEach((fn) => fn());
    return cleared;
}

/** Subscribe to that. Returns the unsubscribe, which every caller must run on
 *  unmount — a stale listener here would setState on a dead component. */
export function onFailuresCleared(fn) {
    listeners.push(fn);
    return function () {
        listeners = listeners.filter((l) => l !== fn);
    };
}

let pumping = false;

function pump() {
    // Re-entrancy guard. start() only sets state in the real caller, so the
    // load it begins finishes a tick later — but a caller that finished inside
    // start() would call release(), which calls pump(), which would shift from
    // the queue underneath the loop already walking it. The nested call returns
    // instead, and the loop below picks up the slot it freed on its next turn.
    if (pumping) {
        return;
    }
    pumping = true;
    try {
        while (active < MAX_PARALLEL && queue.length > 0) {
            const job = queue.shift();
            if (job.done) {
                continue; // released before its turn came
            }
            job.running = true;
            active += 1;
            job.start();
        }
    } finally {
        pumping = false;
    }
}

/**
 * Ask for a slot. `start` is called when one is free — immediately if the gate
 * is open. The returned job must be passed to release() exactly once, whether
 * it loaded, failed, or was abandoned.
 */
export function request(start) {
    const job = { start: start, running: false, done: false };
    queue.push(job);
    pump();
    return job;
}

/** Give the slot back. Safe to call twice, and safe to call on a job that never
 *  got one — which is what unmounting mid-queue does. */
export function release(job) {
    if (!job || job.done) {
        return;
    }
    job.done = true;
    if (job.running) {
        job.running = false;
        active -= 1;
    }
    pump();
}

/** Tests and debugging only. */
export function stats() {
    return { active: active, waiting: queue.filter((j) => !j.done).length };
}

export function reset() {
    active = 0;
    pumping = false;
    queue = [];
    outcome = Object.create(null);
    listeners = [];
}
