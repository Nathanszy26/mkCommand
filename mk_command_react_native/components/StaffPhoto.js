import React, { Component } from 'react';
import { View, Text, Image, StyleSheet, TouchableOpacity } from 'react-native';
import {
    request,
    release,
    knownOutcome,
    remember,
    forget,
    onFailuresCleared,
} from './imageQueue';

/**
 * A staff or GW profile photo, falling back to their initials.
 *
 * Shared by the Staff screen and the Staff Directory search so one person looks
 * the same in both. The photo lives in the myMK app's storage bucket, which the
 * APIs resolve to an absolute URL (staff_profile for staff, gw_profile for GW);
 * all this needs is that URL, or null when there is none on file.
 *
 * TWO THINGS CAN GO WRONG, and they used to look identical — plain initials
 * either way, which is why "sometimes the photos don't load" could never be
 * pinned down:
 *
 *   no photo on file   the record has no profile picture. Nothing to fix here;
 *                      it is fixed by enrolling one. Drawn as initials on the
 *                      usual blue (or amber for a GW).
 *   failed to load     there IS a URL and it could not be fetched. Drawn grey,
 *                      with a small retry mark, and tapping it tries again.
 *
 * So a screenful of grey circles means a loading problem, a screenful of blue
 * ones means missing records, and the difference is visible at a glance instead
 * of being guessed at.
 *
 * Loading is also gated — see imageQueue.js. A department list mounts twenty
 * photos at once, and firing all of them at one host is what produced most of
 * the failures in the first place: the losers time out, and a timeout and a
 * missing file report the same error. At most five load at a time now, a photo
 * that has loaded once is never re-queued, and only a URL that fails every
 * attempt settles on grey.
 */
const C = {
    primary: '#2563eb',
    muted: '#64748b',
    gw: '#ca8a04',
    border: '#e2e8f0',
    dead: '#cbd5e1',
};

const MAX_ATTEMPTS = 3;
const RETRY_DELAY_MS = 700;

/**
 * How long one attempt may hold its slot.
 *
 * Insurance, not policy: if an <Image> ever fired neither onLoad nor onError —
 * a request that hangs rather than failing — its slot would never come back and
 * the queue behind it would stop for good. That is a worse failure than the one
 * this file exists to fix, so an attempt that goes quiet is treated as a failed
 * one and retried like any other.
 */
const LOAD_TIMEOUT_MS = 15000;

/**
 * When a photo that has given up tries again by itself, and how many times.
 *
 * The three attempts above all happen within a couple of seconds, which is
 * still inside the burst that caused the failure — so all three can lose the
 * same race and the photo settles on grey while the file is perfectly fine.
 * These later rounds are the ones that actually recover it: by then the list
 * has finished loading and the host is idle.
 *
 * Bounded, and widening each time, because the other reason a photo fails is
 * that the file is genuinely gone. Retrying that one forever would be a slow
 * drip of pointless requests for as long as the screen is open. After the last
 * round it stays grey until it is tapped or the page is pulled down.
 */
const AUTO_RETRY_MS = [6000, 18000, 45000];

export const initialsOf = (fullname) =>
    (fullname || '')
        .toLowerCase()
        .replace(/\b\w/g, (c) => c.toUpperCase())
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0])
        .join('')
        .toUpperCase();

export default class StaffPhoto extends Component {
    constructor(props) {
        super(props);
        // 'wait'   queued, nothing drawn yet
        // 'load'   holding a slot, the <Image> is mounted
        // 'shown'  it loaded
        // 'failed' every attempt failed
        this.state = { phase: 'wait', attempt: 0 };
        this.job = null;
        this.retryTimer = null;
        this.loadTimer = null;
        this.autoTimer = null;
        this.autoRound = 0;
    }

    componentDidMount() {
        // Pull-to-refresh clears every failure at once; a photo already sitting
        // on screen has to hear about it, or the gesture would only work for
        // rows that happen to remount afterwards.
        this.unsubscribe = onFailuresCleared(() => {
            if (!this.unmounted && this.state.phase === 'failed') {
                this.retryNow();
            }
        });
        this.begin(this.props.uri);
    }

    /** Lists recycle rows, so a new person in the same slot must not inherit
     *  the previous one's slot, failure, or pending retry. */
    componentDidUpdate(prev) {
        if (prev.uri !== this.props.uri) {
            this.stop();
            this.autoRound = 0;
            this.setState({ phase: 'wait', attempt: 0 }, () => this.begin(this.props.uri));
        }
    }

    componentWillUnmount() {
        this.unmounted = true;
        if (this.unsubscribe) {
            this.unsubscribe();
            this.unsubscribe = null;
        }
        this.stop();
    }

    /** Hand back whatever this instance is holding: its slot and its timer. */
    stop() {
        if (this.retryTimer) {
            clearTimeout(this.retryTimer);
            this.retryTimer = null;
        }
        if (this.loadTimer) {
            clearTimeout(this.loadTimer);
            this.loadTimer = null;
        }
        if (this.autoTimer) {
            clearTimeout(this.autoTimer);
            this.autoTimer = null;
        }
        if (this.job) {
            release(this.job);
            this.job = null;
        }
    }

    /** Decide what to do with a URL: skip the queue if it is already known,
     *  otherwise wait for a slot. */
    begin(uri) {
        if (!uri) {
            return;
        }

        const known = knownOutcome(uri);
        if (known === 'ok') {
            // Already in the native cache — drawing it costs nothing, and
            // making it queue behind twenty new requests is what made going
            // back up a list slower than coming down it.
            this.setState({ phase: 'shown' });
            return;
        }
        if (known === 'bad') {
            this.setState({ phase: 'failed' });
            return;
        }

        this.job = request(() => {
            if (this.unmounted) {
                return;
            }
            this.loadTimer = setTimeout(this.onError, LOAD_TIMEOUT_MS);
            this.setState({ phase: 'load' });
        });
    }

    onLoad = () => {
        remember(this.props.uri, 'ok');
        this.stop();
        if (!this.unmounted) {
            this.setState({ phase: 'shown' });
        }
    };

    onError = () => {
        const next = this.state.attempt + 1;
        this.stop();

        if (next >= MAX_ATTEMPTS) {
            remember(this.props.uri, 'bad');
            if (!this.unmounted) {
                this.setState({ phase: 'failed' }, this.scheduleAutoRetry);
            }
            return;
        }

        // Backing off AND giving the slot up: the usual cause is contention,
        // and holding the slot through the wait would starve the queue behind
        // us for no gain.
        if (!this.unmounted) {
            this.setState({ phase: 'wait', attempt: next });
        }
        this.retryTimer = setTimeout(() => {
            this.retryTimer = null;
            if (!this.unmounted) {
                this.begin(this.props.uri);
            }
        }, RETRY_DELAY_MS * next);
    };

    /** Command: try a given-up photo again later, on its own. */
    scheduleAutoRetry = () => {
        const wait = AUTO_RETRY_MS[this.autoRound];
        if (wait === undefined || this.unmounted) {
            return;
        }
        this.autoRound += 1;
        this.autoTimer = setTimeout(() => {
            this.autoTimer = null;
            if (!this.unmounted && this.state.phase === 'failed') {
                this.retryNow(true);
            }
        }, wait);
    };

    /** Command: try again now — from a tap, from pull-to-refresh, or from the
     *  timer above. A tap is a person saying the failure is stale, so it also
     *  restores the full set of automatic rounds. */
    retryNow = (automatic) => {
        forget(this.props.uri);
        this.stop();
        if (automatic !== true) {
            this.autoRound = 0;
        }
        this.setState({ phase: 'wait', attempt: 0 }, () => this.begin(this.props.uri));
    };

    render() {
        const { uri, fullname, size = 40, isGw, color } = this.props;
        const { phase } = this.state;
        const round = { width: size, height: size, borderRadius: size / 2 };

        if (phase === 'failed') {
            return (
                <TouchableOpacity
                    activeOpacity={0.6}
                    onPress={() => this.retryNow(false)}
                    style={[styles.fallback, styles.dead, round]}
                >
                    <Text style={[styles.initials, styles.deadText, { fontSize: size * 0.36 }]}>
                        {initialsOf(fullname)}
                    </Text>
                    {size >= 34 && <Text style={styles.retryMark}>{'↻'}</Text>}
                </TouchableOpacity>
            );
        }

        // No URL at all, or still waiting for a slot. The initials stand in for
        // both: a placeholder that reads as a person beats an empty grey disc.
        if (!uri || phase === 'wait') {
            const bg = color || (isGw ? C.gw : C.primary);
            return (
                <View style={[styles.fallback, round, { backgroundColor: bg }]}>
                    <Text style={[styles.initials, { fontSize: size * 0.36 }]}>
                        {initialsOf(fullname)}
                    </Text>
                </View>
            );
        }

        return (
            <Image
                // Remounted per attempt: re-rendering the same URI can be
                // served straight from the cached failure instead of refetched.
                key={this.state.attempt}
                source={{ uri }}
                style={[styles.photo, round]}
                resizeMode="cover"
                onLoad={this.onLoad}
                onError={this.onError}
            />
        );
    }
}

const styles = StyleSheet.create({
    photo: { backgroundColor: C.border },
    fallback: { alignItems: 'center', justifyContent: 'center' },
    initials: { color: '#fff', fontWeight: '700' },

    // Grey, not blue: this one HAS a photo that would not load, and it is worth
    // being able to tell that apart from a record with no photo at all.
    dead: { backgroundColor: C.dead },
    deadText: { color: C.muted },
    retryMark: {
        position: 'absolute',
        right: 1,
        bottom: 0,
        fontSize: 11,
        fontWeight: '800',
        color: C.muted,
    },
});
