import React, { Component } from 'react';
import {
    View,
    Text,
    Image,
    ScrollView,
    StyleSheet,
    ActivityIndicator,
    TouchableOpacity,
    RefreshControl,
    Modal,
    Alert,
    Linking,
} from 'react-native';
import store from 'react-native-simple-store';
import ROUTES from '../../../../library/routes.js';
import IssueActionModal from './IssueActionModal.js';
import StaffDirectorySearch from '../components/StaffDirectorySearch.js';
import PhotoViewerModal from '../components/PhotoViewerModal.js';
import Icon from 'react-native-vector-icons/Ionicons';
import { fetchStaffDetail, fetchRoots } from '../services/staffDirectoryApi.js';
import StaffPhoto from '../components/StaffPhoto.js';
import { forgetFailures } from '../components/imageQueue.js';

const STAFF_HIERARCHY_API =
    'https://globportal.com/accounts/mkPortal/mkCommand/staffHierarchy.php';

const C = {
    bg: '#f1f5f9',
    surface: '#ffffff',
    primary: '#2563eb',
    primarySoft: 'rgba(37, 99, 235, 0.08)',
    success: '#059669',
    text: '#1e293b',
    muted: '#64748b',
    border: '#e2e8f0',
};

// GW codes live in a different namespace from users.person and can collide with
// one, so the gw flag is part of the identity everywhere (expand/tick/selection).
const nodeKey = (node) => `${node.person}|${node.comp_id}|${node.is_gw ? 1 : 0}`;
const titleCase = (value) =>
    (value || '').toLowerCase().replace(/\b\w/g, (c) => c.toUpperCase());

// 2 -> "2nd", 3 -> "3rd", 4 -> "4th" ...
const ordinal = (n) => {
    const s = ['th', 'st', 'nd', 'rd'];
    const v = n % 100;
    return n + (s[(v - 20) % 10] || s[v] || s[0]);
};

/** Every key in this node's subtree, node included. Used to cascade tick state. */
const collectDescendantKeys = (node) => {
    const keys = [nodeKey(node)];
    node.children.forEach((child) => {
        keys.push(...collectDescendantKeys(child));
    });
    return keys;
};

/** Flat map of every subordinate node keyed by nodeKey — resolves ticked selection. */
const flattenTree = (nodes, into) => {
    const map = into || {};
    (nodes || []).forEach((node) => {
        map[nodeKey(node)] = node;
        if (node.children && node.children.length) {
            flattenTree(node.children, map);
        }
    });
    return map;
};

// Real yearly-evaluation score, supplied per node by staffHierarchy.php
// (EvaluationRepository). null when the staff has no evaluation for the year.
const scoreColor = (s) =>
    s >= 70 ? C.success : s >= 60 ? '#ca8a04' : '#dc2626';

/* --- presentational pieces ------------------------------------------------ */

/** The Staff tab's faces. staffHierarchy.php now returns photo_url for the self
 *  card, every superior and every tree node; StaffPhoto falls back to initials
 *  when there is no photo on file or the bucket 404s, so `color` still decides
 *  what an initials bubble looks like. */
const Avatar = ({ fullname, photoUrl, size = 40, color = C.primary, isGw }) => (
    <StaffPhoto uri={photoUrl} fullname={fullname} size={size} color={color} isGw={isGw} />
);

const StatCard = ({ label, value, color }) => (
    <View style={styles.statCard}>
        <Text style={[styles.statValue, { color }]}>{value}</Text>
        <Text style={styles.statLabel}>{label}</Text>
    </View>
);

/**
 * One rung in the superior ladder. Labelled by how far above you the person
 * sits: "Direct" for your manager, "Top level" for the top of the line, and
 * "2nd level up", "3rd level up"… for everyone in between. A dot + line draws
 * the reporting chain; the rail only shows in the full-line view.
 */
const SuperiorRow = ({ person, showRail, isLast, onPress }) => {
    const isDirect = person.level === 1;
    const isTop = !isDirect && !!person.is_top;
    const label = isDirect ? 'Direct' : isTop ? 'Top level' : ordinal(person.level) + ' level up';
    const pillStyle = isDirect ? styles.pillDirect : isTop ? styles.pillTop : styles.pillUp;
    const pillTextStyle = isDirect
        ? styles.pillTextDirect
        : isTop
        ? styles.pillTextTop
        : styles.pillTextUp;
    return (
        <TouchableOpacity
            style={[styles.supRow, !showRail && styles.supRowPlain]}
            activeOpacity={onPress ? 0.6 : 1}
            onPress={() => (onPress ? onPress(person) : null)}
        >
            {showRail && (
                <View style={styles.supRail}>
                    <View style={[styles.supDot, isDirect && styles.supDotDirect, isTop && styles.supDotTop]} />
                    {!isLast && <View style={styles.supLine} />}
                </View>
            )}
            <Avatar
                fullname={person.fullname}
                photoUrl={person.photo_url}
                size={38}
                color={isDirect ? C.primary : C.muted}
            />
            <View style={styles.personText}>
                <Text style={styles.personName} numberOfLines={1}>
                    {titleCase(person.fullname)}
                </Text>
                <Text style={styles.personMeta} numberOfLines={1}>
                    {person.position || 'No Position'}
                    {person.department ? ` \u2022 ${person.department}` : ''}
                </Text>
            </View>
            <View style={[styles.pill, pillStyle]}>
                <Text style={[styles.pillText, pillTextStyle]}>{label}</Text>
            </View>
        </TouchableOpacity>
    );
};

/**
 * One member of a committee.
 *
 * A committee crosses companies, so the company belongs on the line itself
 * rather than behind a tap — on a group-wide committee it is the thing that
 * tells two colleagues apart. Tapping opens that person's card, the same as
 * every other person row on this screen.
 */
const CommitteeMemberRow = ({ member, isMe, onPress }) => (
    <TouchableOpacity
        style={[styles.memberRow, isMe && styles.memberRowMe]}
        activeOpacity={0.6}
        onPress={() => onPress(member)}
    >
        <Avatar
            fullname={member.fullname}
            photoUrl={member.photo_url}
            size={32}
            color={isMe ? C.primary : C.muted}
        />
        <View style={styles.personText}>
            <View style={styles.nameRow}>
                <Text style={styles.memberName} numberOfLines={1}>
                    {titleCase(member.fullname)}
                </Text>
                {isMe && (
                    <View style={[styles.teamPill, styles.mePill]}>
                        <Text style={[styles.teamPillText, styles.mePillText]}>You</Text>
                    </View>
                )}
            </View>
            <Text style={styles.personMeta} numberOfLines={1}>
                {member.position || 'No Position'}
                {member.company_name ? ` \u2022 ${member.company_name}` : ''}
            </Text>
        </View>
        {!!member.role && (
            <View style={styles.rolePill}>
                <Text style={styles.rolePillText} numberOfLines={1}>
                    {member.role}
                </Text>
            </View>
        )}
    </TouchableOpacity>
);

/**
 * One committee, closed to a single line until it is opened.
 *
 * Closed is the default on purpose: what this section answers first is "which
 * committees am I on", and one committee can seat a dozen people from several
 * companies. Opening it lists the whole membership — the hierarchy payload
 * already carries it, so that costs no round-trip and no spinner.
 */
const CommitteeRow = ({ committee, open, onToggle, onPressMember, meKey }) => {
    const members = committee.members || [];

    return (
        <View style={styles.committeeWrap}>
            <TouchableOpacity
                style={[styles.committeeRow, open && styles.committeeRowOpen]}
                activeOpacity={0.6}
                onPress={() => onToggle(committee.id)}
            >
                <Text style={styles.rootCaret}>{open ? '\u25BE' : '\u25B8'}</Text>
                <View style={styles.committeeText}>
                    <Text style={styles.committeeName} numberOfLines={2}>
                        {committee.name}
                        {committee.code ? ` (${committee.code})` : ''}
                    </Text>
                </View>
                <View style={styles.committeeCount}>
                    <Text style={styles.committeeCountText}>{committee.member_count}</Text>
                </View>
            </TouchableOpacity>

            {open && (
                <View style={styles.memberList}>
                    {members.length === 0 ? (
                        <Text style={styles.kidsNote}>No active members.</Text>
                    ) : (
                        members.map((member) => (
                            <CommitteeMemberRow
                                key={nodeKey(member)}
                                member={member}
                                isMe={nodeKey(member) === meKey}
                                onPress={onPressMember}
                            />
                        ))
                    )}
                </View>
            )}
        </View>
    );
};

/** Tri-state-free checkbox: on/off only, cascade logic lives in the screen. */
const Checkbox = ({ checked, onPress }) => (
    <TouchableOpacity
        activeOpacity={0.6}
        onPress={onPress}
        hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}
        style={[styles.checkbox, checked && styles.checkboxChecked]}
    >
        {checked && <Text style={styles.checkboxMark}>{'\u2713'}</Text>}
    </TouchableOpacity>
);

/**
 * Recursive tree node. Expand + tick state are owned by the screen.
 *
 * Interaction:
 *  - Tapping the row body opens the staff menu (onPressNode) at any depth —
 *    you can view any subordinate's job spec from there.
 *  - The caret on the right expands/collapses a team.
 *  - Direct reports (depth 0) render with a blue avatar: they're the only ones
 *    you can edit/create for (enforced server-side; passed as `editable`).
 */
const TreeNode = ({ node, depth, expanded, onToggle, checked, onToggleCheck, onPressNode }) => {
    const key = nodeKey(node);
    const hasChildren = node.children.length > 0;
    const isOpen = !!expanded[key];
    const isChecked = !!checked[key];
    const isDirect = depth === 0;
    const score = node.score; // number | null (real yearly-eval average)

    return (
        <View>
            <View style={[styles.treeRow, { marginLeft: depth * 18 }]}>
                {depth > 0 && <View style={styles.treeElbow} />}

                <Checkbox checked={isChecked} onPress={() => onToggleCheck(node)} />

                <TouchableOpacity
                    activeOpacity={0.6}
                    onPress={() => onPressNode(node, depth)}
                    style={styles.treeRowContent}
                >
                    <Avatar
                        fullname={node.fullname}
                        photoUrl={node.photo_url}
                        size={34}
                        color={isDirect ? C.primary : C.muted}
                        isGw={!!node.is_gw}
                    />
                    <View style={styles.personText}>
                        <View style={styles.nameRow}>
                            <Text style={styles.personName} numberOfLines={1}>
                                {titleCase(node.fullname)}
                            </Text>
                            {!!node.is_gw && (
                                <View style={[styles.teamPill, styles.gwPill]}>
                                    <Text style={[styles.teamPillText, styles.gwPillText]}>GW</Text>
                                </View>
                            )}
                            {node.total_subordinates > 0 && (
                                <View style={styles.teamPill}>
                                    <Text style={styles.teamPillText}>{node.total_subordinates} staff</Text>
                                </View>
                            )}
                        </View>
                        <Text style={styles.personMeta} numberOfLines={1}>
                            {node.position || 'No Position'}
                        </Text>
                    </View>
                </TouchableOpacity>

                {/* Right: hardcoded marks + expand caret (caret owns expand). */}
                <View style={styles.treeRight}>
                    <View style={styles.marksBox}>
                        {score === null || score === undefined ? (
                            <Text style={[styles.marksValue, styles.marksValueEmpty]}>{'\u2014'}</Text>
                        ) : (
                            <Text style={[styles.marksValue, { color: scoreColor(score) }]}>
                                {Math.round(score)}
                            </Text>
                        )}
                        <Text style={styles.marksLabel}>Score</Text>
                    </View>
                    {hasChildren ? (
                        <TouchableOpacity
                            activeOpacity={0.6}
                            onPress={() => onToggle(key)}
                            hitSlop={{ top: 10, bottom: 10, left: 8, right: 8 }}
                            style={styles.caretBtn}
                        >
                            <Text style={styles.caret}>{isOpen ? '\u25BE' : '\u25B8'}</Text>
                        </TouchableOpacity>
                    ) : (
                        <View style={styles.caretSpacer} />
                    )}
                </View>
            </View>

            {isOpen &&
                node.children.map((child) => (
                    <TreeNode
                        key={nodeKey(child)}
                        node={child}
                        depth={depth + 1}
                        expanded={expanded}
                        onToggle={onToggle}
                        checked={checked}
                        onToggleCheck={onToggleCheck}
                        onPressNode={onPressNode}
                    />
                ))}
        </View>
    );
};

const Section = ({ title, count, action, children }) => (
    <View style={styles.card}>
        <View style={styles.cardHeader}>
            <Text style={styles.cardTitle}>{title}</Text>
            <View style={styles.cardHeaderRight}>
                {count !== undefined && (
                    <View style={styles.headerBadge}>
                        <Text style={styles.headerBadgeText}>{count}</Text>
                    </View>
                )}
                {action}
            </View>
        </View>
        <View style={styles.cardBody}>{children}</View>
    </View>
);

const Empty = ({ text }) => <Text style={styles.emptyText}>{text}</Text>;

/**
 * One entry in the floating menu. Drawn as a labelled pill to the LEFT of the
 * button column, so the label is readable without the icon having to carry the
 * meaning on its own.
 */
const FabItem = ({ icon, label, onPress, tone }) => (
    <TouchableOpacity style={styles.fabItem} activeOpacity={0.75} onPress={onPress}>
        <View style={styles.fabItemLabel}>
            <Text style={styles.fabItemLabelText}>{label}</Text>
        </View>
        <View style={[styles.fabItemBtn, tone === 'muted' && styles.fabItemBtnMuted]}>
            <Text style={styles.fabItemIcon}>{icon}</Text>
        </View>
    </TouchableOpacity>
);

/**
 * One label/value line on the identity card. The same shape the directory card
 * uses, so your own details read identically wherever you meet them. Renders
 * nothing when there is no value, rather than a label with a blank beside it.
 */
const DetailField = ({ label, value, action }) => {
    const v = (value === null || value === undefined ? '' : String(value)).trim();
    if (v === '' || v === '-') {
        return null;
    }
    return (
        <View style={styles.detailField}>
            <Text style={styles.detailLabel}>{label}</Text>
            <Text style={styles.detailValue}>{v}</Text>
            {action || null}
        </View>
    );
};

/** Numbered row used by the staff modal (both layers). */
const MenuItem = ({ number, label, onPress }) => (
    <TouchableOpacity style={styles.menuItem} activeOpacity={0.7} onPress={onPress}>
        <View style={styles.menuNumber}>
            <Text style={styles.menuNumberText}>{number}</Text>
        </View>
        <Text style={styles.menuItemText}>{label}</Text>
        <Text style={styles.menuItemChevron}>{'\u203A'}</Text>
    </TouchableOpacity>
);

/* --- screen --------------------------------------------------------------- */

export default class Staff extends Component {
    constructor(props) {
        super(props);
        this.state = {
            loading: true,
            refreshing: false,
            error: null,
            data: null,
            expanded: {},
            checked: {},
            openCommittees: {},   // committee id -> membership showing
            issueAction: null,    // 'memo' | 'merit' | 'demerit' for the selected staff
            issueOpen: false,     // the issue form is up
            user: null,           // session + own name, handed to the issue form
            menuNode: null,       // selected subordinate (null = modal closed)
            menuEditable: false,  // direct report? -> may edit (server re-checks)
            fabOpen: false,       // floating menu expanded
            searchOpen: false,    // the directory search panel is up
            myDetail: null,       // own contact card, loaded after the hierarchy
            viewPhoto: null,      // photo being shown full screen

            // --- org navigation, in place on this page ---------------------
            // focus === null means "me": the full screen with the tree, the
            // ticks and the issue actions. Focusing anyone else swaps the body
            // for their card, because those actions are only ever yours.
            focus: null,
            focusDetail: null,
            focusLoading: false,
            focusError: null,
            focusStack: [],       // the way you came, so Back can walk it out

            rootsOpen: false,     // the company's top level is showing
            roots: [],
            rootsSource: null,
            rootsLoading: false,
            rootsError: null,

            // Which rows are open, and who is under them. One pair of maps
            // for every depth and for both lists, keyed by nodeKey — a row is
            // the same row wherever it appears, so opening it in a department
            // and opening it on a card mean the same thing. Kept across a
            // close/open, so a branch is fetched once.
            openNodes: {},
            nodeKids: {},
        };
    }

    componentDidMount() {
        this.load();
    }

    componentWillUnmount() {
        this.unmounted = true;
    }

    /** Command: fetches hierarchy and pushes it into state. */
    load = async (refreshing = false) => {
        this.setState(refreshing ? { refreshing: true, error: null } : { loading: true, error: null });

        // Pulling the page down retries every photo that gave up, not just the
        // hierarchy. A grey circle is the most visible thing on a bad screen,
        // so it is the thing the gesture should fix.
        if (refreshing) {
            forgetFailures();
        }

        try {
            const userData = await store.get('AppUser');
            if (!userData || !userData.person || !userData.comid) {
                throw new Error('Missing user session. Please log in again.');
            }

            const response = await fetch(STAFF_HIERARCHY_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:
                    `person=${encodeURIComponent(userData.person)}` +
                    `&comid=${encodeURIComponent(userData.comid)}`,
            });

            const payload = await response.json();
            if (!response.ok || !payload.success) {
                throw new Error(payload.message || `API error ${response.status}`);
            }

            const expanded = {};
            payload.subordinates.forEach((node) => {
                expanded[nodeKey(node)] = false;
            });

            // Own contact details are a second, independent read: the
            // hierarchy is what this screen is for, so a failure here must not
            // take the tree down with it.
            this.loadMyDetail({ person: userData.person, compId: userData.comid });

            this.setState({
                data: payload,
                user: {
                    person: userData.person,
                    compId: userData.comid,
                    fullname: payload.staff.fullname, // prefills the memo's "From"
                },
                expanded,
                checked: {},
                loading: false,
                refreshing: false,
            });
        } catch (error) {
            this.setState({
                error: error.message || 'Failed to load hierarchy',
                loading: false,
                refreshing: false,
            });
        }
    };

    /** Query: own membership, contacts and company, from the directory's
     *  detail endpoint. Best-effort — the card simply does not appear if it
     *  fails, and nothing else on the screen depends on it. */
    loadMyDetail = async (session) => {
        try {
            const detail = await fetchStaffDetail(session, {
                person: session.person,
                comp_id: session.compId,
                is_gw: 0,
            });
            this.setState({ myDetail: detail });
        } catch (error) {
            this.setState({ myDetail: null });
        }
    };

    toggle = (key) => {
        this.setState((prev) => ({
            expanded: { ...prev.expanded, [key]: !prev.expanded[key] },
        }));
    };

    /** Command: show/hide one committee's membership. Its own key space — a
     *  committee id is an integer and would collide with a nodeKey. */
    toggleCommittee = (id) => {
        this.setState((prev) => ({
            openCommittees: { ...prev.openCommittees, [id]: !prev.openCommittees[id] },
        }));
    };

    /** Command: ticking/unticking a node cascades the same state to its whole subtree. */
    toggleCheck = (node) => {
        const key = nodeKey(node);
        this.setState((prev) => {
            const nextValue = !prev.checked[key];
            const checked = { ...prev.checked };
            collectDescendantKeys(node).forEach((k) => {
                checked[k] = nextValue;
            });
            return { checked };
        });
    };

    /** Query: resolve the ticked keys back to their staff nodes. */
    getSelectedStaff = () => {
        const { data, checked } = this.state;
        if (!data) {
            return [];
        }
        const index = flattenTree(data.subordinates);
        return Object.keys(checked)
            .filter((k) => checked[k])
            .map((k) => index[k])
            .filter(Boolean);
    };

    /** Command: tick every subordinate, or clear all if everything is already ticked. */
    selectAll = () => {
        const { data, checked } = this.state;
        if (!data) {
            return;
        }
        const keys = Object.keys(flattenTree(data.subordinates));
        const allSelected = keys.length > 0 && keys.every((k) => checked[k]);
        const next = {};
        if (!allSelected) {
            keys.forEach((k) => {
                next[k] = true;
            });
        }
        this.setState({ checked: next });
    };

    /** Command: pick which action to issue (tap again to clear). */
    setIssueAction = (action) => {
        this.setState((prev) => ({ issueAction: prev.issueAction === action ? null : action }));
    };

    /** Command: open the issue form for the ticked staff. */
    proceed = () => {
        if (!this.state.issueAction || this.getSelectedStaff().length === 0) {
            return;
        }
        this.setState({ issueOpen: true });
    };

    closeIssue = () => this.setState({ issueOpen: false });

    /** Command: the record was written. Drop the form, clear the ticks, and
     *  reload — a demerit can move a score, so the tree is now stale.
     *
     *  Attachments are filed, and the memo is mirrored to the myMK app inbox,
     *  after the record commits — so either can fail on its own. The record
     *  still stands; the alert says what did not make it rather than letting
     *  the user assume the photos are there or the app was notified. */
    onIssued = (action, count, notices) => {
        const label = action === 'memo' ? 'Memo' : action === 'merit' ? 'Merit' : 'Demerit';
        const failures = (notices && notices.attachments) || [];
        const mirror = (notices && notices.mirror) || null;
        const detail = 'Sent to ' + count + ' staff.'
            + (failures.length ? '\n\nAttachments not saved:\n' + failures.join('\n') : '')
            + (mirror ? '\n\n' + mirror : '');

        this.setState({ issueOpen: false, issueAction: null, checked: {} }, () => {
            Alert.alert(label + ' issued', detail);
            this.load(true);
        });
    };

    /* --- staff menu modal ------------------------------------------------- */

    openStaffMenu = (node, depth) => this.setState({ menuNode: node, menuEditable: depth === 0 });
    closeStaffMenu = () => this.setState({ menuNode: null });

    /** Command: close the menu, then jump to the Job Spec page for this person.
     *  Edit-or-not is decided there (the Edit button shows when editable). */
    goToJobSpec = () => {
        const { menuNode, menuEditable } = this.state;
        this.setState({ menuNode: null });
        const nav = this.props.navigation;
        if (menuNode && nav) {
            nav.navigate(ROUTES.MkCommandJobSpec, {
                person: menuNode.person,       // GW: monthly_assign_gw_code
                comp_id: menuNode.comp_id,
                fullname: menuNode.fullname,
                is_gw: menuNode.is_gw ? 1 : 0,
                editable: menuEditable, // UI hint only; server re-verifies direct-superior
            });
        }
    };

    /* --- floating menu ---------------------------------------------------- */

    toggleFab = () => this.setState((prev) => ({ fabOpen: !prev.fabOpen }));

    /** Command: show the directory search panel in place of the card.
     *
     *  It is a LAYER over whatever is on screen, not a move: closing it puts
     *  back the same card, so searching is never a way of losing your place.
     */
    openSearch = () => this.setState({ searchOpen: true });
    closeSearch = () => this.setState({ searchOpen: false });
    toggleSearch = () =>
        this.setState((prev) => ({ searchOpen: !prev.searchOpen }));

    /** Command: a search result was tapped. The panel closes and that person's
     *  card opens on the page — the same card every other route produces, with
     *  the same Up / company / Back controls on it. */
    pickFromSearch = (row) => {
        this.setState({ searchOpen: false });
        this.openInfo(row);
    };

    /** Command: open the org navigator on one person. The hero, the superior
     *  ladder and the tree all come through here, so every way in behaves the
     *  same and the card always has a header to draw before its fetch lands. */
    /** Best-effort: a device with no dialer or WhatsApp does nothing rather
     *  than throwing an unhandled rejection. Mirrors the directory card. */
    openUrl = (url) => {
        Linking.openURL(url).catch(() => {});
    };

    /** Command: start an email to this person. Whichever mail app is set up
     *  opens with the address filled in; a device with none does nothing
     *  rather than throwing. */
    openMail = (email) => {
        const to = (email || '').trim();
        if (to === '') {
            return;
        }
        this.openUrl('mailto:' + to);
    };

    openWhatsApp = (phone) => {
        const digits = (phone || '').replace(/\D/g, '');
        if (digits.length < 8) {
            return;
        }
        let number = digits;
        if (!(digits.indexOf('60') === 0 && digits.length >= 11)) {
            number = digits.charAt(0) === '0'
                ? '60' + digits.substring(1)
                : (digits.charAt(0) === '1' ? '60' + digits : digits);
        }
        Linking.openURL('whatsapp://send?phone=' + number).catch(() => {
            Linking.openURL('https://wa.me/' + number).catch(() => {});
        });
    };

    /**
     * Command: focus one person - the "go in" of the navigation.
     *
     * Everything routes through here (superior rows, subordinate rows, the
     * tree's menu, the company root), so every way in pushes the trail the same
     * way and the card always has a header to draw before its fetch lands.
     */
    openInfo = (person) => {
        if (!person) {
            return;
        }
        const row = {
            person: person.person,
            comp_id: person.comp_id,
            is_gw: person.is_gw ? 1 : 0,
            fullname: person.fullname,
            position: person.position,
            department: person.department,
            company_name: person.company_name,
            photo_url: person.photo_url,
        };

        this.setState((prev) => ({
            // The card being LEFT is what gets pushed, not the new one. Leaving
            // "me" pushes nothing: Home is always one tap away anyway.
            focusStack: prev.focus ? prev.focusStack.concat([prev.focus]) : prev.focusStack,
            rootsOpen: false,
        }));
        this.showFocus(row);
    };

    /** Put one person on screen and fetch their record. The single path for
     *  "show this card", so forward, Back and Up behave identically. */
    showFocus = async (row) => {
        this.setState({
            focus: row,
            focusDetail: null,
            focusLoading: true,
            focusError: null,
            searchOpen: false,
        });
        try {
            const detail = await fetchStaffDetail(
                { person: this.state.user.person, compId: this.state.user.compId },
                row
            );
            if (this.unmounted) {
                return;
            }
            this.setState({ focusDetail: detail, focusLoading: false });
        } catch (error) {
            if (this.unmounted) {
                return;
            }
            this.setState({
                focusLoading: false,
                focusError: error.message || 'Failed to load details',
            });
        }
    };

    /** Command: back to my own screen - the tree, the ticks, the issue bar. */
    goHome = () =>
        this.setState({
            focus: null,
            focusDetail: null,
            focusError: null,
            focusStack: [],
            rootsOpen: false,
            searchOpen: false,
        });

    /** The direct superior of whoever is on screen. On my own screen that comes
     *  from the hierarchy payload; on someone else's, from their card. */
    directSuperior() {
        const { focus, focusDetail, data } = this.state;
        const list = focus
            ? ((focusDetail && focusDetail.superiors) || [])
            : ((data && data.superiors) || []);
        const direct = list.filter((b) => b.level === 1);
        return direct.length ? direct[0] : null;
    }

    /**
     * Command: go up a level.
     *
     * Climbing back to where we just came from POPS the trail rather than
     * extending it, so the path shortens on the way up the way a folder path
     * does. With nobody above, the next level up is the company itself.
     */
    goUp = () => {
        const boss = this.directSuperior();
        if (!boss) {
            this.openRoots();
            return;
        }
        const { focusStack } = this.state;
        const prev = focusStack.length ? focusStack[focusStack.length - 1] : null;
        if (prev && prev.person === boss.person && prev.comp_id === boss.comp_id) {
            this.goBack();
            return;
        }
        this.openInfo(boss);
    };

    /** Command: Back - one step along the trail, or home when it is empty. */
    goBack = () => {
        const { focusStack, searchOpen } = this.state;
        if (searchOpen) {
            this.setState({ searchOpen: false });
            return;
        }
        if (focusStack.length === 0) {
            this.goHome();
            return;
        }
        const previous = focusStack[focusStack.length - 1];
        this.setState({ focusStack: focusStack.slice(0, -1), rootsOpen: false });
        this.showFocus(previous);
    };

    /**
     * Command: show the company's top level - departments for comp 1, top-of-
     * chart people elsewhere. Also where Up lands once the superiors run out,
     * so climbing never dead-ends.
     */
    openRoots = async () => {
        const { user, roots } = this.state;
        this.setState({
            rootsOpen: true,
            focus: null,
            focusDetail: null,
            focusStack: [],
            searchOpen: false,
        });

        if (roots.length > 0) {
            return;
        }
        this.setState({ rootsLoading: true, rootsError: null });
        try {
            const res = await fetchRoots(
                { person: user.person, compId: user.compId },
                user.compId
            );
            if (this.unmounted) {
                return;
            }
            this.setState({ roots: res.roots, rootsSource: res.source, rootsLoading: false });
        } catch (error) {
            if (this.unmounted) {
                return;
            }
            this.setState({
                rootsLoading: false,
                rootsError: error.message || 'Failed to load the top level',
            });
        }
    };

    /**
     * Command: open or close one row.
     *
     * Opening lists who reports to that person, in place — the level below,
     * exactly as going into them would show it. It works at any depth and in
     * either list, because a row is identified the same way everywhere.
     * Closing keeps what was fetched, so reopening is instant.
     */
    toggleKids = (node) => {
        const key = nodeKey(node);
        const open = !this.state.openNodes[key];

        this.setState((prev) => ({ openNodes: { ...prev.openNodes, [key]: open } }));

        if (open && !this.state.nodeKids[key]) {
            this.loadKids(node, key);
        }
    };

    /** Query: one row's contents. Its own loading and error live on that row,
     *  so a branch that fails to open says so where you tapped rather than
     *  replacing the whole list with an error. */
    loadKids = async (node, key) => {
        this.setState((prev) => ({
            nodeKids: { ...prev.nodeKids, [key]: { loading: true, rows: [] } },
        }));
        try {
            const detail = await fetchStaffDetail(
                { person: this.state.user.person, compId: this.state.user.compId },
                node
            );
            if (this.unmounted) {
                return;
            }
            this.setState((prev) => ({
                nodeKids: {
                    ...prev.nodeKids,
                    [key]: { loading: false, rows: detail.subordinates || [] },
                },
            }));
        } catch (error) {
            if (this.unmounted) {
                return;
            }
            this.setState((prev) => ({
                nodeKids: {
                    ...prev.nodeKids,
                    [key]: {
                        loading: false,
                        rows: [],
                        error: error.message || 'Could not open this one',
                    },
                },
            }));
        }
    };

    /** Command: close the tree's menu, then focus that person. */
    goToInfo = () => {
        const { menuNode } = this.state;
        this.setState({ menuNode: null }, () => this.openInfo(menuNode));
    };

    /**
     * Command: close the menu, then hand over to an existing page.
     *
     * Both targets live in the app's ROOT stack, not in this tab navigator, so
     * navigate() bubbles up to find them — the same call staff-utility.js makes
     * from its own menus. Neither takes params: each reads AppUser from the
     * store itself, so there is nothing to pass and nothing to keep in step.
     */
    goToRoute = (route) => {
        this.setState({ fabOpen: false }, () => {
            const nav = this.props.navigation;
            if (nav) {
                nav.navigate(route);
            }
        });
    };

    /** The I.T Call Centre page — staff-utility.js's "I.T Call Centre Support". */
    reportIssue = () => this.goToRoute(ROUTES.CallCentre);

    /** staff-utility.js's "Leave Application", off its Leave menu. */
    leaveApplication = () => this.goToRoute(ROUTES.StaffLeave);

    /**
     * Bottom-right floating menu. Collapsed it is one button; tapping it fans
     * out the items above it and dims the page so a second tap anywhere closes.
     *
     * Both items hand off to pages that already exist elsewhere in the app
     * rather than reimplementing them here.
     *
     * Leave Application is hidden for comp 8 (TP Group), who are not on this
     * leave system. It is hidden too when the session has not loaded yet: for
     * an exclusion rule, showing it to someone who should not see it is the
     * worse failure of the two.
     *
     * `lifted` raises the whole thing clear of the Issue Actions bar, which
     * appears at the bottom of this same screen whenever staff are ticked —
     * otherwise the button would sit on top of the Proceed button.
     */
    renderFab(lifted) {
        const { fabOpen, user } = this.state;
        const showLeave = !!user && String(user.compId) !== '8';

        return (
            <View pointerEvents="box-none" style={StyleSheet.absoluteFill}>
                {/* Scrim is mounted only while open, so it never swallows taps
                    meant for the tree underneath it. */}
                {fabOpen && (
                    <TouchableOpacity
                        style={styles.fabScrim}
                        activeOpacity={1}
                        onPress={this.toggleFab}
                    />
                )}

                <View
                    style={[styles.fabDock, { bottom: lifted ? 164 : 24 }]}
                    pointerEvents="box-none"
                >
                    {fabOpen && (
                        <View style={styles.fabItems}>
                            <FabItem
                                icon={'!'}
                                label="Report Issue"
                                tone="muted"
                                onPress={this.reportIssue}
                            />
                            {showLeave && (
                                <FabItem
                                    icon={'\u2708'}
                                    label="Leave Application"
                                    tone="muted"
                                    onPress={this.leaveApplication}
                                />
                            )}
                        </View>
                    )}

                    <TouchableOpacity
                        style={[styles.fab, fabOpen && styles.fabActive]}
                        activeOpacity={0.85}
                        onPress={this.toggleFab}
                    >
                        <Text style={styles.fabIcon}>{fabOpen ? '✕' : '+'}</Text>
                    </TouchableOpacity>
                </View>
            </View>
        );
    }

    /**
     * The navigation strip, fixed above the body.
     *
     * Up climbs to the superior (and to the company once they run out), the
     * building button jumps to the top level, the person button comes home, and
     * Search swaps the body for the directory.
     *
     * No breadcrumb: the card below already says whose it is, and the trail was
     * repeating that name back at the cost of the width the search box now has.
     * Back still walks the way you came, one step at a time.
     */
    renderNavBar() {
        const { focus, rootsOpen, searchOpen } = this.state;

        return (
            <View style={styles.navBar}>
                <TouchableOpacity
                    style={styles.navUp}
                    activeOpacity={0.7}
                    onPress={this.goUp}
                    disabled={rootsOpen}
                    hitSlop={{ top: 10, bottom: 10, left: 6, right: 6 }}
                >
                    <Icon
                        name="arrow-up"
                        size={19}
                        color={rootsOpen ? C.muted : C.primary}
                    />
                    <Text style={[styles.navUpText, rootsOpen && styles.navTextOff]}>Up</Text>
                </TouchableOpacity>

                <TouchableOpacity
                    style={styles.navIconBtn}
                    activeOpacity={0.7}
                    onPress={this.openRoots}
                    hitSlop={{ top: 10, bottom: 10, left: 6, right: 6 }}
                >
                    <Icon name="business" size={18} color={C.primary} />
                </TouchableOpacity>

                <TouchableOpacity
                    style={styles.navIconBtn}
                    activeOpacity={0.7}
                    onPress={this.goHome}
                    hitSlop={{ top: 10, bottom: 10, left: 6, right: 6 }}
                >
                    <Icon name="person" size={18} color={C.primary} />
                </TouchableOpacity>

                {/* Where the name used to be. The page is the search now —
                    one tap opens the box, one more closes it again. */}
                <TouchableOpacity
                    style={[styles.navSearch, searchOpen && styles.navSearchOn]}
                    activeOpacity={0.7}
                    onPress={this.toggleSearch}
                >
                    <Text style={[styles.navSearchIcon, searchOpen && styles.navSearchTextOn]}>
                        {'\u2315'}
                    </Text>
                    <Text
                        style={[styles.navSearchText, searchOpen && styles.navSearchTextOn]}
                        numberOfLines={1}
                    >
                        Search
                    </Text>
                </TouchableOpacity>

                {(!!focus || rootsOpen || searchOpen) && (
                    <TouchableOpacity
                        style={styles.navBack}
                        activeOpacity={0.7}
                        onPress={this.goBack}
                        hitSlop={{ top: 10, bottom: 10, left: 6, right: 6 }}
                    >
                        <Text style={styles.navBackText}>Back</Text>
                    </TouchableOpacity>
                )}
            </View>
        );
    }

    /**
     * Brand + navigation, drawn either inside the scroller or above the search
     * panel.
     *
     * `scrolled` is true in the first case, and only changes the spacing —
     * inside the scroller the brand bar has to escape the content padding to
     * stay full-bleed. Either way the bar travels with the page: a long team,
     * or a long department, is read with the whole screen rather than through
     * a window under a fixed header.
     */
    renderTopBar(scrolled) {
        return (
            <View>
                <View style={[styles.brandHeader, scrolled && styles.brandHeaderScroll]}>
                    <Image
                        source={require('../assets/mk.png')}
                        style={styles.brandLogo}
                        resizeMode="contain"
                    />
                    <Text style={styles.brandTitle}>MK Command Center</Text>
                </View>

                <View style={scrolled ? styles.navWrapScroll : styles.navWrap}>
                    {this.renderNavBar()}
                </View>
            </View>
        );
    }

    /** One entry in the top level, drawn as a folder row. A unit reads as the
     *  unit first and the person second — that is what you are picking at this
     *  level, and it is how the manpower summary presents it. */
    rootRow(entry) {
        const key = nodeKey(entry);
        const open = !!this.state.openNodes[key];
        // The server counts who is under each row, so a department with nobody
        // in it never offers to open — no caret, no round trip to find out.
        const has = (entry.direct_subordinates || 0) > 0;

        return (
            <View>
                <View style={[styles.rootRow, open && styles.rootRowOpen]}>
                    {/* Two targets, two answers. The folder opens the
                        department where it stands; the arrow on the right goes
                        to the head's own card. Tapping the row used to do only
                        the second, which made reading a department a round
                        trip through the person running it. */}
                    <TouchableOpacity
                        style={styles.rootMain}
                        activeOpacity={0.6}
                        onPress={() => this.toggleKids(entry)}
                        disabled={entry.vacant || !has}
                    >
                        <Text style={[styles.rootCaret, !has && styles.rootCaretOff]}>
                            {!has ? ' ' : (open ? '\u25BE' : '\u25B8')}
                        </Text>
                        <Icon
                            name={open ? 'folder-open' : 'folder'}
                            size={18}
                            color={entry.vacant ? C.muted : C.primary}
                        />
                        <View style={styles.rootText}>
                            <View style={styles.nameRow}>
                                <Text style={styles.rootName} numberOfLines={2}>
                                    {entry.dept_name || titleCase(entry.fullname)}
                                </Text>
                                {has && (
                                    <View style={styles.teamPill}>
                                        <Text style={styles.teamPillText}>
                                            {entry.direct_subordinates}
                                        </Text>
                                    </View>
                                )}
                            </View>
                            <Text style={styles.rootMeta} numberOfLines={2}>
                                {entry.dept_name
                                    ? (entry.fullname
                                        ? titleCase(entry.fullname)
                                            + (entry.position ? ' \u2022 ' + entry.position : '')
                                        : 'No head on file')
                                    : (entry.position || 'No Position')}
                            </Text>
                        </View>
                    </TouchableOpacity>

                    {entry.vacant ? (
                        <View style={styles.teamPill}>
                            <Text style={styles.teamPillText}>Vacant</Text>
                        </View>
                    ) : (
                        <TouchableOpacity
                            style={styles.rootGo}
                            activeOpacity={0.6}
                            onPress={() => this.openInfo(entry)}
                            hitSlop={{ top: 10, bottom: 10, left: 6, right: 8 }}
                        >
                            <Avatar
                                fullname={entry.fullname || '?'}
                                photoUrl={entry.photo_url}
                                size={30}
                            />
                            <View style={styles.rootGoBtn}>
                                <Icon name="arrow-forward" size={14} color={C.primary} />
                            </View>
                        </TouchableOpacity>
                    )}
                </View>

                {open && this.renderKids(entry, 1)}
            </View>
        );
    }

    /**
     * What is inside one open row: the people under it, each one a row of the
     * same kind — so a team opens inside a department, and a team inside that,
     * as far down as the chart goes.
     *
     * `depth` only sets the indent, and it stops stepping in after a few
     * levels: on a phone, an indent that keeps growing squeezes the names it
     * is supposed to be organising.
     */
    renderKids(node, depth) {
        const kids = this.state.nodeKids[nodeKey(node)];

        if (!kids || kids.loading) {
            return (
                <View style={styles.kidsBox}>
                    <ActivityIndicator size="small" color={C.primary} />
                </View>
            );
        }
        if (kids.error) {
            return (
                <View style={styles.kidsBox}>
                    <Text style={styles.kidsNote}>{kids.error}</Text>
                    <TouchableOpacity
                        style={styles.retryBtn}
                        activeOpacity={0.7}
                        onPress={() => this.loadKids(node, nodeKey(node))}
                    >
                        <Text style={styles.retryText}>Retry</Text>
                    </TouchableOpacity>
                </View>
            );
        }
        if (kids.rows.length === 0) {
            return (
                <View style={styles.kidsBox}>
                    <Text style={styles.kidsNote}>
                        Nobody reports to {titleCase(node.fullname)}.
                    </Text>
                </View>
            );
        }

        return (
            <View style={[styles.kidsWrap, { marginLeft: Math.min(depth, 3) * 11 }]}>
                {kids.rows.map((kid) => this.personRow(kid, depth))}
            </View>
        );
    }

    /**
     * One person in a list, at any depth.
     *
     * Two targets, the same pair the department folders offer: the body opens
     * their team where it stands, the arrow on the right goes to their card.
     * Somebody with nobody under them has no team to open, so for them the
     * whole row goes to the card and no caret is drawn.
     */
    personRow(node, depth) {
        const key = nodeKey(node);
        const open = !!this.state.openNodes[key];
        const has = (node.direct_subordinates || 0) > 0;

        return (
            <View key={key}>
                <View style={[styles.kidRow, open && styles.kidRowOpen]}>
                    <TouchableOpacity
                        style={styles.rootMain}
                        activeOpacity={0.6}
                        onPress={() => (has ? this.toggleKids(node) : this.openInfo(node))}
                    >
                        <Text style={[styles.rootCaret, !has && styles.rootCaretOff]}>
                            {!has ? ' ' : (open ? '\u25BE' : '\u25B8')}
                        </Text>
                        <Avatar
                            fullname={node.fullname}
                            photoUrl={node.photo_url}
                            size={30}
                            isGw={!!node.is_gw}
                        />
                        <View style={styles.rootText}>
                            <View style={styles.nameRow}>
                                <Text style={styles.kidName} numberOfLines={1}>
                                    {titleCase(node.fullname)}
                                </Text>
                                {!!node.is_gw && (
                                    <View style={[styles.teamPill, styles.gwPill]}>
                                        <Text style={[styles.teamPillText, styles.gwPillText]}>
                                            GW
                                        </Text>
                                    </View>
                                )}
                                {has && (
                                    <View style={styles.teamPill}>
                                        <Text style={styles.teamPillText}>
                                            {node.direct_subordinates}
                                        </Text>
                                    </View>
                                )}
                            </View>
                            <Text style={styles.rootMeta} numberOfLines={1}>
                                {node.position || 'No Position'}
                                {node.department ? ' \u2022 ' + node.department : ''}
                            </Text>
                        </View>
                    </TouchableOpacity>

                    <TouchableOpacity
                        style={styles.rootGoBtn}
                        activeOpacity={0.6}
                        onPress={() => this.openInfo(node)}
                        hitSlop={{ top: 10, bottom: 10, left: 8, right: 8 }}
                    >
                        <Icon name="arrow-forward" size={13} color={C.primary} />
                    </TouchableOpacity>
                </View>

                {open && this.renderKids(node, depth + 1)}
            </View>
        );
    }

    /**
     * The person the rest of the company hangs off, drawn as the head of the
     * list rather than as one more row in it.
     *
     * Globinaco never gets one: its roots are departments standing side by
     * side, named by department_head. Every other company's chart has a single
     * person at the top, and listing them as a sibling of the departments they
     * run says the opposite of what the chart says.
     */
    renderLeader(entry) {
        return (
            <TouchableOpacity
                key={entry.person + '|' + entry.comp_id}
                style={styles.leader}
                activeOpacity={0.7}
                onPress={() => this.openInfo(entry)}
            >
                <Avatar
                    fullname={entry.fullname || '?'}
                    photoUrl={entry.photo_url}
                    size={46}
                />
                <View style={styles.leaderText}>
                    <View style={styles.nameRow}>
                        <Text style={styles.leaderName} numberOfLines={1}>
                            {titleCase(entry.fullname)}
                        </Text>
                        <View style={styles.leaderPill}>
                            <Text style={styles.leaderPillText}>TOP</Text>
                        </View>
                    </View>
                    <Text style={styles.leaderMeta} numberOfLines={1}>
                        {entry.position || 'No Position'}
                    </Text>
                    {!!entry.department && (
                        <Text style={styles.leaderMeta} numberOfLines={1}>
                            {entry.department}
                        </Text>
                    )}
                </View>
                <Text style={styles.menuItemChevron}>{'\u203A'}</Text>
            </TouchableOpacity>
        );
    }

    /**
     * The company's top level.
     *
     * Where a chart has one person at the top, they are drawn first and
     * everything reporting to them hangs off a rail beneath — so the list says
     * who runs the company, not just which departments exist. Globinaco has no
     * such person at this level (department_head names its departments
     * outright), so there the rail never appears and the departments stand on
     * their own.
     */
    renderRoots() {
        const { roots, rootsLoading, rootsError } = this.state;

        if (rootsLoading) {
            return (
                <View style={styles.inlineState}>
                    <ActivityIndicator color={C.primary} />
                </View>
            );
        }
        if (rootsError) {
            return (
                <View style={styles.inlineState}>
                    <Text style={styles.errorText}>{rootsError}</Text>
                    <TouchableOpacity style={styles.retryBtn} onPress={this.openRoots}>
                        <Text style={styles.retryText}>Retry</Text>
                    </TouchableOpacity>
                </View>
            );
        }
        if (roots.length === 0) {
            return (
                <View style={styles.inlineState}>
                    <Text style={styles.emptyText}>No departments or reporting lines on file.</Text>
                </View>
            );
        }

        // is_top comes from the server, not from "has no department": a direct
        // report with none on file is still a report, not the top.
        const tops = roots.filter((r) => r.is_top);
        const under = roots.filter((r) => !r.is_top);
        const leader = tops.length === 1 ? tops[0] : null;

        return (
            <Section
                title={under.length > 0 ? 'Departments' : 'Top of chart'}
                count={under.length > 0 ? under.length : roots.length}
            >
                {leader ? this.renderLeader(leader) : tops.map((t) => this.renderLeader(t))}

                {/* The rail is only drawn when there is somebody above it to
                    hang from; without one these are the top level themselves. */}
                {under.map((entry, i) => (
                    <View key={entry.person + '|' + entry.comp_id} style={styles.branchRow}>
                        {tops.length > 0 && (
                            <View style={styles.branchRail}>
                                <View
                                    style={[
                                        styles.branchLine,
                                        i === under.length - 1 && styles.branchLineLast,
                                    ]}
                                />
                                <View style={styles.branchElbow} />
                            </View>
                        )}
                        <View style={styles.branchBody}>{this.rootRow(entry)}</View>
                    </View>
                ))}
            </Section>
        );
    }

    /** Somebody else's card: identity, details, superiors, subordinates. The
     *  ticks and the issue bar are deliberately absent - they only ever apply
     *  to your own team. */
    renderFocus() {
        const { focus, focusDetail, focusLoading, focusError } = this.state;
        const d = focusDetail || focus;
        const supers = (focusDetail && focusDetail.superiors) || [];
        const subs = (focusDetail && focusDetail.subordinates) || [];
        const phone = d.mobile_no || d.personal_mobile_no || null;
        const email = d.email || d.personal_email || null;

        return (
            <View>
                <View style={styles.hero}>
                    <TouchableOpacity
                        activeOpacity={0.8}
                        onPress={() =>
                            this.setState({
                                viewPhoto: { uri: d.photo_url, fullname: d.fullname },
                            })
                        }
                    >
                        <Avatar
                            fullname={d.fullname}
                            photoUrl={d.photo_url}
                            size={128}
                            isGw={!!d.is_gw}
                        />
                    </TouchableOpacity>
                    <Text style={styles.heroName}>{titleCase(d.fullname)}</Text>
                    <Text style={styles.heroMeta}>{d.position || 'No Position'}</Text>
                    <Text style={styles.heroMeta}>
                        {d.department || 'No Department'}
                        {d.company_name ? ' \u2022 ' + d.company_name : ''}
                    </Text>
                    {focusLoading && (
                        <ActivityIndicator size="small" color={C.primary} style={{ marginTop: 10 }} />
                    )}
                </View>

                {!!focusError && (
                    <View style={styles.inlineState}>
                        <Text style={styles.errorText}>{focusError}</Text>
                        <TouchableOpacity style={styles.retryBtn} onPress={() => this.showFocus(focus)}>
                            <Text style={styles.retryText}>Retry</Text>
                        </TouchableOpacity>
                    </View>
                )}

                {!!focusDetail && (
                    <View style={styles.card}>
                        <View style={styles.cardHeader}>
                            <Text style={styles.cardTitle}>
                                {d.is_gw ? 'Worker' : 'Staff'} Details
                            </Text>
                        </View>
                        <View style={styles.cardBody}>
                            <DetailField label="Membership No" value={focusDetail.membership_no} />
                            <DetailField label="Name" value={titleCase(d.fullname)} />
                            <DetailField label="Department" value={d.department} />
                            <DetailField label="Position" value={d.position} />
                            <DetailField
                                label="Phone Number"
                                value={phone}
                                action={
                                    phone ? (
                                        <View style={styles.callRow}>
                                            <TouchableOpacity
                                                style={[styles.iconBtn, styles.iconBtnCall]}
                                                activeOpacity={0.7}
                                                onPress={() => this.openUrl('tel:' + phone)}
                                                hitSlop={{ top: 8, bottom: 8, left: 6, right: 6 }}
                                            >
                                                <Icon name="call" size={19} color={C.primary} />
                                            </TouchableOpacity>
                                            <TouchableOpacity
                                                style={[styles.iconBtn, styles.iconBtnWa]}
                                                activeOpacity={0.7}
                                                onPress={() => this.openWhatsApp(phone)}
                                                hitSlop={{ top: 8, bottom: 8, left: 6, right: 6 }}
                                            >
                                                <Icon name="logo-whatsapp" size={19} color="#128C7E" />
                                            </TouchableOpacity>
                                        </View>
                                    ) : null
                                }
                            />
                            <DetailField
                                label="Email"
                                value={email}
                                action={
                                    email ? (
                                        <TouchableOpacity
                                            style={[styles.iconBtn, styles.iconBtnMail]}
                                            activeOpacity={0.7}
                                            onPress={() => this.openMail(email)}
                                            hitSlop={{ top: 8, bottom: 8, left: 6, right: 6 }}
                                        >
                                            <Icon name="mail" size={18} color="#b45309" />
                                        </TouchableOpacity>
                                    ) : null
                                }
                            />
                            <DetailField label="Company" value={d.company_name} />
                        </View>
                    </View>
                )}

                <Section
                    title={supers.length === 1 ? 'Superior' : 'Superiors'}
                    count={supers.length}
                >
                    {supers.length === 0 ? (
                        <Empty text="No active superior assigned." />
                    ) : (
                        supers
                            .slice()
                            .sort((a, b) =>
                                b.level !== a.level
                                    ? b.level - a.level
                                    : titleCase(a.fullname).localeCompare(titleCase(b.fullname))
                            )
                            .map((boss, i, list) => (
                                <SuperiorRow
                                    key={nodeKey(boss)}
                                    person={boss}
                                    showRail
                                    isLast={i === list.length - 1}
                                    onPress={this.openInfo}
                                />
                            ))
                    )}
                </Section>

                {/* The same rows the departments list uses, so a team opens
                    here too rather than only after going into the person. */}
                <Section title="Subordinates" count={subs.length}>
                    {subs.length === 0 ? (
                        <Empty text="No active subordinates." />
                    ) : (
                        subs.map((sub) => this.personRow(sub, 0))
                    )}
                </Section>
            </View>
        );
    }

    /** Pinned action bar: appears while staff are ticked. Choose one action, Proceed. */
    renderIssueActions(count) {
        const { issueAction } = this.state;
        const opts = [
            { key: 'memo', label: 'Memo' },
            { key: 'merit', label: 'Merit' },
            { key: 'demerit', label: 'Demerit' },
        ];
        return (
            <View style={styles.actionBar}>
                <View style={styles.actionBarHead}>
                    <Text style={styles.actionBarTitle}>Issue Actions</Text>
                    <Text style={styles.actionBarCount}>
                        {count} selected
                    </Text>
                </View>
                <View style={styles.actionChips}>
                    {opts.map((o, i) => {
                        const active = issueAction === o.key;
                        return (
                            <TouchableOpacity
                                key={o.key}
                                style={[
                                    styles.chip,
                                    i === opts.length - 1 && styles.chipLast,
                                    active && styles.chipActive,
                                ]}
                                activeOpacity={0.7}
                                onPress={() => this.setIssueAction(o.key)}
                            >
                                <Text style={[styles.chipText, active && styles.chipTextActive]}>
                                    {o.label}
                                </Text>
                            </TouchableOpacity>
                        );
                    })}
                </View>
                <TouchableOpacity
                    style={[styles.proceedBtn, !issueAction && styles.proceedBtnDisabled]}
                    activeOpacity={0.7}
                    disabled={!issueAction}
                    onPress={this.proceed}
                >
                    <Text style={styles.proceedText}>Proceed</Text>
                </TouchableOpacity>
            </View>
        );
    }

    renderStaffMenu() {
        const { menuNode } = this.state;
        return (
            <Modal
                visible={!!menuNode}
                transparent
                animationType="fade"
                onRequestClose={this.closeStaffMenu}
            >
                <TouchableOpacity style={styles.modalOverlay} activeOpacity={1} onPress={this.closeStaffMenu}>
                    {/* Inner card swallows taps so they don't dismiss the modal. */}
                    <TouchableOpacity activeOpacity={1} style={styles.modalCard}>
                        {menuNode && (
                            <View>
                                <View style={styles.modalHeader}>
                                    <Avatar
                                        fullname={menuNode.fullname}
                                        photoUrl={menuNode.photo_url}
                                        size={44}
                                        color={C.primary}
                                        isGw={!!menuNode.is_gw}
                                    />
                                    <View style={styles.modalHeaderText}>
                                        <Text style={styles.modalName} numberOfLines={1}>
                                            {titleCase(menuNode.fullname)}
                                        </Text>
                                        <Text style={styles.modalRole} numberOfLines={1}>
                                            {menuNode.position || 'No Position'}
                                        </Text>
                                    </View>
                                    <TouchableOpacity
                                        onPress={this.closeStaffMenu}
                                        hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}
                                    >
                                        <Text style={styles.modalClose}>{'\u2715'}</Text>
                                    </TouchableOpacity>
                                </View>

                                {/* Screens only. Tapping one navigates; edit is chosen on that page. */}
                                <MenuItem number={1} label="Staff Info" onPress={this.goToInfo} />
                                <MenuItem number={2} label="Job Spec" onPress={this.goToJobSpec} />
                            </View>
                        )}
                    </TouchableOpacity>
                </TouchableOpacity>
            </Modal>
        );
    }

    render() {
        const { loading, refreshing, error, data, expanded, checked } = this.state;

        if (loading) {
            return (
                <View style={styles.center}>
                    <ActivityIndicator size="large" color={C.primary} />
                </View>
            );
        }

        if (error) {
            return (
                <View style={styles.center}>
                    <Text style={styles.errorText}>{error}</Text>
                    <TouchableOpacity style={styles.retryBtn} onPress={() => this.load()}>
                        <Text style={styles.retryText}>Retry</Text>
                    </TouchableOpacity>
                </View>
            );
        }

        const { staff, superiors, summary, subordinates } = data;
        // Absent until committee.sql is run and this staff is seated on one.
        const committees = data.committees || [];
        // Who "me" is inside a member list, in the same key space as everyone else.
        const meKey = nodeKey({ person: staff.person, comp_id: staff.comp_id, is_gw: 0 });
        const my = this.state.myDetail;
        // Work contact first, personal only when there is no work one — the
        // same rule the directory card applies.
        const myPhone = my ? (my.mobile_no || my.personal_mobile_no || null) : null;
        const myEmail = my ? (my.email || my.personal_email || null) : null;
        const { focus, rootsOpen, searchOpen } = this.state;
        const selectedStaff = this.getSelectedStaff();
        const totalNodes = Object.keys(flattenTree(subordinates)).length;
        const allSelected = totalNodes > 0 && selectedStaff.length === totalNodes;

        return (
            <View style={styles.screen}>
                {/* Four bodies, one page: the directory search, the company's
                    top level, one other person's card, or my own screen. Only
                    my own carries the ticks and the issue bar, because those
                    actions are only ever mine to take.

                    The search panel owns its own list, so it replaces the
                    scroller rather than sitting inside it, and carries the top
                    bar INSIDE that list — so the bar, the filters and the box
                    scroll away with the results instead of pinning them into a
                    strip at the bottom of the screen. */}
                {searchOpen ? (
                    <StaffDirectorySearch
                        session={{ person: this.state.user.person, compId: this.state.user.compId }}
                        header={this.renderTopBar(false)}
                        onSelect={this.pickFromSearch}
                    />
                ) : (
                <ScrollView
                    style={styles.screen}
                    contentContainerStyle={styles.content}
                    refreshControl={
                        <RefreshControl
                            refreshing={refreshing}
                            onRefresh={() => this.load(true)}
                            colors={[C.primary]}
                            tintColor={C.primary}
                        />
                    }
                >
                    {this.renderTopBar(true)}

                    {rootsOpen ? (
                        this.renderRoots()
                    ) : focus ? (
                        this.renderFocus()
                    ) : (
                        <View>
                    {/* Identity. Tapping it opens your own card in the org
                        navigator — Up and the company button take it from
                        there. */}
                    <TouchableOpacity
                        style={styles.hero}
                        activeOpacity={0.7}
                        onPress={() => this.openInfo(staff)}
                    >
                        {/* The circle is a crop; tapping it shows the stored
                            photo whole, without opening the navigator. */}
                        <TouchableOpacity
                            activeOpacity={0.8}
                            onPress={() =>
                                this.setState({
                                    viewPhoto: {
                                        uri: staff.photo_url,
                                        fullname: staff.fullname,
                                    },
                                })
                            }
                        >
                            <Avatar
                                fullname={staff.fullname}
                                photoUrl={staff.photo_url}
                                size={128}
                            />
                        </TouchableOpacity>
                        <Text style={styles.heroName}>{titleCase(staff.fullname)}</Text>
                        <Text style={styles.heroMeta}>{staff.position || 'No Position'}</Text>
                        <Text style={styles.heroMeta}>
                            {staff.department || 'No Department'}
                            {staff.company_name ? ' \u2022 ' + staff.company_name : ''}
                        </Text>
                    </TouchableOpacity>

                    {/* The same details the directory card shows, so your own
                        record reads identically wherever you open it. */}
                    {!!this.state.myDetail && (
                        <View style={styles.card}>
                            <View style={styles.cardHeader}>
                                <Text style={styles.cardTitle}>Staff Details</Text>
                            </View>
                            <View style={styles.cardBody}>
                                <DetailField
                                    label="Membership No"
                                    value={this.state.myDetail.membership_no}
                                />
                                <DetailField label="Name" value={titleCase(staff.fullname)} />
                                <DetailField label="Department" value={staff.department} />
                                <DetailField label="Position" value={staff.position} />
                                <DetailField
                                    label="Phone Number"
                                    value={myPhone}
                                    action={
                                        myPhone ? (
                                            <View style={styles.callRow}>
                                                <TouchableOpacity
                                                    style={[styles.iconBtn, styles.iconBtnCall]}
                                                    activeOpacity={0.7}
                                                    onPress={() => this.openUrl('tel:' + myPhone)}
                                                    hitSlop={{ top: 8, bottom: 8, left: 6, right: 6 }}
                                                >
                                                    <Icon name="call" size={19} color={C.primary} />
                                                </TouchableOpacity>
                                                <TouchableOpacity
                                                    style={[styles.iconBtn, styles.iconBtnWa]}
                                                    activeOpacity={0.7}
                                                    onPress={() => this.openWhatsApp(myPhone)}
                                                    hitSlop={{ top: 8, bottom: 8, left: 6, right: 6 }}
                                                >
                                                    <Icon
                                                        name="logo-whatsapp"
                                                        size={19}
                                                        color="#128C7E"
                                                    />
                                                </TouchableOpacity>
                                            </View>
                                        ) : null
                                    }
                                />
                                <DetailField
                                    label="Email"
                                    value={myEmail}
                                    action={
                                        myEmail ? (
                                            <TouchableOpacity
                                                style={[styles.iconBtn, styles.iconBtnMail]}
                                                activeOpacity={0.7}
                                                onPress={() => this.openMail(myEmail)}
                                                hitSlop={{ top: 8, bottom: 8, left: 6, right: 6 }}
                                            >
                                                <Icon name="mail" size={18} color="#b45309" />
                                            </TouchableOpacity>
                                        ) : null
                                    }
                                />
                                <DetailField label="Company" value={staff.company_name} />
                            </View>
                        </View>
                    )}

                    {/* Totals */}
                    <View style={styles.statRow}>
                        <StatCard label="Superiors" value={summary.total_superiors} color={C.primary} />
                        <StatCard label="Direct Subordinates" value={summary.direct_subordinates} color={C.success} />
                        <StatCard label="Total Subordinates" value={summary.total_subordinates} color={C.text} />
                    </View>

                    {/* Committees. Drawn only when this staff actually sits on
                        one: there is nothing worth saying to the many people
                        who sit on none, and an empty card would push the
                        reporting line — what this tab is for — further down. */}
                    {committees.length > 0 && (
                        <Section title="Committees" count={committees.length}>
                            {committees.map((committee) => (
                                <CommitteeRow
                                    key={committee.id}
                                    committee={committee}
                                    open={!!this.state.openCommittees[committee.id]}
                                    onToggle={this.toggleCommittee}
                                    onPressMember={this.openInfo}
                                    meKey={meKey}
                                />
                            ))}
                        </Section>
                    )}

                    <Section title="Superiors" count={superiors.length}>
                        {superiors.length === 0 ? (
                            <Empty text="No active superior assigned." />
                        ) : (
                            // Backend returns nearest-first; show the whole line top -> direct,
                            // A-Z by name within the same level.
                            superiors
                                .slice()
                                .sort((a, b) =>
                                    b.level !== a.level
                                        ? b.level - a.level
                                        : titleCase(a.fullname).localeCompare(titleCase(b.fullname))
                                )
                                .map((boss, i, list) => (
                                    <SuperiorRow
                                        key={nodeKey(boss)}
                                        person={boss}
                                        showRail
                                        isLast={i === list.length - 1}
                                        onPress={this.openInfo}
                                    />
                                ))
                        )}
                    </Section>

                    <Section
                        title="Subordinates"
                        count={summary.total_subordinates}
                        action={
                            subordinates.length > 0 ? (
                                <TouchableOpacity
                                    style={styles.selectAllBtn}
                                    onPress={this.selectAll}
                                    activeOpacity={0.7}
                                    hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}
                                >
                                    <Text style={styles.selectAllText}>
                                        {allSelected ? 'Clear all' : 'Select all'}
                                    </Text>
                                </TouchableOpacity>
                            ) : undefined
                        }
                    >
                        {subordinates.length === 0 ? (
                            <Empty text="No active subordinates." />
                        ) : (
                            <View>
                                {subordinates.map((node) => (
                                    <TreeNode
                                        key={nodeKey(node)}
                                        node={node}
                                        depth={0}
                                        expanded={expanded}
                                        onToggle={this.toggle}
                                        checked={checked}
                                        onToggleCheck={this.toggleCheck}
                                        onPressNode={this.openStaffMenu}
                                    />
                                ))}
                            </View>
                        )}
                    </Section>
                        </View>
                    )}
                </ScrollView>
                )}

                {!focus && !rootsOpen && !searchOpen && selectedStaff.length > 0
                    && this.renderIssueActions(selectedStaff.length)}
                {!searchOpen
                    && this.renderFab(!focus && !rootsOpen && selectedStaff.length > 0)}
                {this.renderStaffMenu()}

                {/* Mounted only while a photo is open. */}
                {!!this.state.viewPhoto && (
                    <PhotoViewerModal
                        uri={this.state.viewPhoto.uri}
                        fullname={this.state.viewPhoto.fullname}
                        onClose={() => this.setState({ viewPhoto: null })}
                    />
                )}

                {/* Mounted only while open, so each issue starts from a clean form. */}
                {this.state.issueOpen && (
                    <IssueActionModal
                        action={this.state.issueAction}
                        user={this.state.user}
                        staff={selectedStaff}
                        onClose={this.closeIssue}
                        onDone={this.onIssued}
                    />
                )}
            </View>
        );
    }
}

const styles = StyleSheet.create({
    screen: { flex: 1, backgroundColor: C.bg },
    content: { padding: 14, paddingBottom: 40 },

    brandHeader: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: '#1e293b',
        paddingHorizontal: 16,
        paddingVertical: 14,
        borderBottomWidth: 3,
        borderBottomColor: '#22c55e',
    },
    // Inside the scroller it has to escape the content padding to stay
    // full-bleed; above the search panel it is already flush.
    brandHeaderScroll: { marginTop: -14, marginHorizontal: -14, marginBottom: 14 },
    brandLogo: { width: 42, height: 42, borderRadius: 11, marginRight: 13 },
    brandTitle: { fontSize: 18.5, fontWeight: '800', color: '#ffffff', letterSpacing: 0.4 },
    center: { flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: C.bg, padding: 24 },

    errorText: { color: '#dc2626', textAlign: 'center', marginBottom: 16, fontSize: 15 },
    retryBtn: {
        backgroundColor: C.primary,
        paddingHorizontal: 24,
        paddingVertical: 10,
        borderRadius: 8,
    },
    retryText: { color: '#fff', fontWeight: '600' },

    hero: {
        backgroundColor: C.surface,
        borderRadius: 14,
        paddingVertical: 22,
        alignItems: 'center',
        borderWidth: 1,
        borderColor: C.border,
        marginBottom: 12,
    },
    heroName: { fontSize: 19, fontWeight: '700', color: C.text, marginTop: 10 },
    heroMeta: { fontSize: 13, color: C.muted, marginTop: 2 },

    navWrap: { paddingHorizontal: 14, paddingTop: 12, paddingBottom: 12 },
    navWrapScroll: { paddingBottom: 12 },
    navBar: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: C.surface,
        borderRadius: 12,
        borderWidth: 1,
        borderColor: C.border,
        paddingHorizontal: 8,
        paddingVertical: 8,
    },
    // Sized for a thumb rather than for the text in them: these are the
    // controls the whole page is driven from.
    navUp: {
        flexDirection: 'row',
        alignItems: 'center',
        borderWidth: 1,
        borderColor: C.primary,
        backgroundColor: C.primarySoft,
        borderRadius: 10,
        paddingHorizontal: 10,
        paddingVertical: 9,
    },
    navUpText: { fontSize: 13.5, fontWeight: '800', color: C.primary, marginLeft: 4 },
    navTextOff: { color: C.muted },
    navIconBtn: {
        borderWidth: 1,
        borderColor: C.primary,
        backgroundColor: C.primarySoft,
        borderRadius: 10,
        paddingHorizontal: 10,
        paddingVertical: 9,
        marginLeft: 6,
    },
    navSearch: {
        flex: 1,
        flexDirection: 'row',
        alignItems: 'center',
        marginHorizontal: 6,
        borderWidth: 1,
        borderColor: C.primary,
        backgroundColor: C.primarySoft,
        borderRadius: 10,
        paddingHorizontal: 10,
        paddingVertical: 9,
    },
    navSearchOn: { backgroundColor: C.primary },
    navSearchIcon: { fontSize: 17, fontWeight: '700', color: C.primary, marginRight: 5 },
    navSearchText: { flex: 1, fontSize: 13.5, fontWeight: '700', color: C.primary },
    navSearchTextOn: { color: '#ffffff' },
    navBack: { paddingLeft: 4, paddingRight: 2, paddingVertical: 9 },
    navBackText: { fontSize: 13.5, fontWeight: '700', color: C.primary },

    inlineState: { alignItems: 'center', paddingVertical: 26 },

    rootRow: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: C.bg,
        borderRadius: 10,
        borderWidth: 1,
        borderColor: C.border,
        paddingHorizontal: 10,
        paddingVertical: 10,
        marginTop: 7,
    },
    rootRowOpen: { borderColor: C.primary, backgroundColor: C.primarySoft },
    rootMain: { flex: 1, flexDirection: 'row', alignItems: 'center' },
    rootCaret: { width: 14, fontSize: 12, color: C.primary },
    rootCaretOff: { color: C.border },
    rootGo: { flexDirection: 'row', alignItems: 'center' },
    rootGoBtn: {
        width: 26,
        height: 26,
        borderRadius: 13,
        borderWidth: 1,
        borderColor: C.primary,
        backgroundColor: C.surface,
        alignItems: 'center',
        justifyContent: 'center',
        marginLeft: 7,
    },

    kidsWrap: { marginTop: 7 },
    kidRow: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: C.surface,
        borderRadius: 9,
        borderWidth: 1,
        borderColor: C.border,
        borderLeftWidth: 3,
        borderLeftColor: C.primary,
        paddingHorizontal: 9,
        paddingVertical: 8,
        marginBottom: 6,
    },
    kidRowOpen: { borderColor: C.primary, backgroundColor: C.primarySoft },
    kidName: { flexShrink: 1, fontSize: 13.5, fontWeight: '700', color: C.text },
    kidsBox: { alignItems: 'center', paddingVertical: 14 },
    kidsNote: { fontSize: 12, color: C.muted, textAlign: 'center', marginBottom: 8 },

    rootText: { flex: 1, marginHorizontal: 9 },

    // The head of the chart: a card, not a row, so the departments beneath it
    // read as its branches rather than its siblings.
    leader: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: C.primarySoft,
        borderRadius: 11,
        borderWidth: 1.5,
        borderColor: C.primary,
        paddingHorizontal: 11,
        paddingVertical: 11,
    },
    leaderText: { flex: 1, marginHorizontal: 11 },
    leaderName: { flexShrink: 1, fontSize: 15.5, fontWeight: '800', color: C.text },
    leaderMeta: { fontSize: 12, color: C.muted, marginTop: 2 },
    leaderPill: {
        marginLeft: 7,
        backgroundColor: C.primary,
        borderRadius: 8,
        paddingHorizontal: 7,
        paddingVertical: 2,
    },
    leaderPillText: { fontSize: 9.5, fontWeight: '800', color: '#ffffff', letterSpacing: 0.7 },

    // The rail: one line down from the leader, one elbow into each row. The
    // last row's line stops at its own elbow, so the rail ends with the list
    // instead of running off the bottom of it.
    branchRow: { flexDirection: 'row', alignItems: 'stretch' },
    branchRail: { width: 20 },
    branchLine: {
        position: 'absolute',
        left: 9,
        top: 0,
        bottom: 0,
        width: 1.5,
        backgroundColor: C.primary,
        opacity: 0.35,
    },
    branchLineLast: { bottom: undefined, height: '50%' },
    branchElbow: {
        position: 'absolute',
        left: 9,
        top: '50%',
        width: 11,
        height: 1.5,
        backgroundColor: C.primary,
        opacity: 0.35,
    },
    branchBody: { flex: 1 },
    rootName: { flexShrink: 1, fontSize: 14, fontWeight: '700', color: C.text },
    rootMeta: { fontSize: 11.5, color: C.muted, marginTop: 2 },

    detailField: { flexDirection: 'row', alignItems: 'center', marginBottom: 9 },
    detailLabel: { width: 118, fontSize: 12.5, color: C.muted, fontWeight: '600' },
    detailValue: { flex: 1, fontSize: 13.5, color: C.text, fontWeight: '500' },
    callRow: { flexDirection: 'row', alignItems: 'center' },
    // Sized for a thumb: calling, messaging and emailing are what people come
    // to a contact card to do.
    iconBtn: {
        width: 38,
        height: 38,
        borderRadius: 19,
        borderWidth: 1,
        alignItems: 'center',
        justifyContent: 'center',
        marginLeft: 8,
    },
    iconBtnCall: { borderColor: C.primary, backgroundColor: C.primarySoft },
    iconBtnWa: { borderColor: '#25D366', backgroundColor: 'rgba(37, 211, 102, 0.12)' },
    iconBtnMail: { borderColor: '#f59e0b', backgroundColor: 'rgba(245, 158, 11, 0.12)' },

    statRow: { flexDirection: 'row', marginBottom: 12 },
    statCard: {
        flex: 1,
        backgroundColor: C.surface,
        borderRadius: 12,
        paddingVertical: 14,
        marginHorizontal: 3,
        alignItems: 'center',
        borderWidth: 1,
        borderColor: C.border,
    },
    statValue: { fontSize: 22, fontWeight: '700' },
    statLabel: { fontSize: 10.5, color: C.muted, marginTop: 4, textAlign: 'center' },

    card: {
        backgroundColor: C.surface,
        borderRadius: 14,
        borderWidth: 1,
        borderColor: C.border,
        marginBottom: 12,
        overflow: 'hidden',
    },
    cardHeader: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        paddingHorizontal: 16,
        paddingVertical: 12,
        backgroundColor: C.primarySoft,
        borderBottomWidth: 1,
        borderBottomColor: C.border,
    },
    cardTitle: { fontSize: 15, fontWeight: '700', color: C.text },
    cardHeaderRight: { flexDirection: 'row', alignItems: 'center' },
    headerBadge: {
        backgroundColor: C.primary,
        borderRadius: 10,
        minWidth: 24,
        paddingHorizontal: 7,
        paddingVertical: 2,
        alignItems: 'center',
    },
    headerBadgeText: { color: '#fff', fontSize: 11, fontWeight: '700' },
    cardBody: { padding: 10 },

    /* committees */
    committeeWrap: { marginBottom: 7 },
    committeeRow: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: C.bg,
        borderRadius: 10,
        borderWidth: 1,
        borderColor: C.border,
        paddingHorizontal: 10,
        paddingVertical: 10,
    },
    committeeRowOpen: { borderColor: C.primary, backgroundColor: C.primarySoft },
    committeeText: { flex: 1, marginHorizontal: 9 },
    committeeName: { fontSize: 14, fontWeight: '700', color: C.text },
    committeeCount: {
        minWidth: 24,
        borderRadius: 10,
        backgroundColor: C.primary,
        paddingHorizontal: 7,
        paddingVertical: 2,
        alignItems: 'center',
    },
    committeeCountText: { color: '#fff', fontSize: 11, fontWeight: '700' },

    // The membership sits inside its committee rather than beside it, so an
    // open one still reads as one block when several are listed.
    memberList: { marginTop: 7, marginLeft: 12 },
    memberRow: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: C.surface,
        borderRadius: 9,
        borderWidth: 1,
        borderColor: C.border,
        borderLeftWidth: 3,
        borderLeftColor: C.border,
        paddingHorizontal: 9,
        paddingVertical: 8,
        marginBottom: 6,
    },
    memberRowMe: { borderLeftColor: C.primary, backgroundColor: C.primarySoft },
    memberName: { flexShrink: 1, fontSize: 13.5, fontWeight: '700', color: C.text },
    mePill: { backgroundColor: C.primarySoft, borderColor: C.primary },
    mePillText: { color: C.primary },
    rolePill: {
        marginLeft: 7,
        maxWidth: 92,
        borderRadius: 8,
        borderWidth: 1,
        borderColor: C.border,
        backgroundColor: C.bg,
        paddingHorizontal: 7,
        paddingVertical: 3,
    },
    rolePillText: { fontSize: 10, fontWeight: '700', color: C.muted },

    hint: { fontSize: 11.5, color: C.muted, marginBottom: 8, marginLeft: 4 },
    emptyText: { color: C.muted, fontSize: 13, textAlign: 'center', paddingVertical: 14 },

    /* issue actions bar */
    selectAllBtn: {
        marginLeft: 10,
        paddingVertical: 4,
        paddingHorizontal: 10,
        borderRadius: 8,
        backgroundColor: C.surface,
        borderWidth: 1,
        borderColor: C.primary,
    },
    selectAllText: { color: C.primary, fontSize: 12.5, fontWeight: '700' },
    actionBar: {
        backgroundColor: C.surface,
        borderTopWidth: 1,
        borderTopColor: C.border,
        paddingHorizontal: 14,
        paddingTop: 12,
        paddingBottom: 16,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: -2 },
        shadowOpacity: 0.06,
        shadowRadius: 4,
        elevation: 8,
    },
    actionBarHead: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        marginBottom: 10,
    },
    actionBarTitle: { fontSize: 15, fontWeight: '800', color: C.text },
    actionBarCount: { fontSize: 12.5, color: C.muted, fontWeight: '600' },
    actionChips: { flexDirection: 'row', marginBottom: 12 },
    chip: {
        flex: 1,
        paddingVertical: 11,
        borderRadius: 8,
        borderWidth: 1,
        borderColor: C.border,
        backgroundColor: C.bg,
        alignItems: 'center',
        marginRight: 8,
    },
    chipLast: { marginRight: 0 },
    chipActive: { backgroundColor: C.primarySoft, borderColor: C.primary },
    chipText: { fontSize: 13.5, fontWeight: '700', color: C.muted },
    chipTextActive: { color: C.primary },
    proceedBtn: {
        backgroundColor: C.primary,
        paddingVertical: 13,
        borderRadius: 8,
        alignItems: 'center',
    },
    proceedBtnDisabled: { opacity: 0.5 },
    proceedText: { color: '#fff', fontSize: 15, fontWeight: '700' },

    /* superior chain */
    /* superior ladder */
    supRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 6, paddingRight: 4 },
    supRowPlain: { paddingVertical: 8, paddingLeft: 2 },
    supRail: { width: 20, alignItems: 'center', alignSelf: 'stretch' },
    supDot: {
        width: 12,
        height: 12,
        borderRadius: 6,
        marginTop: 19,
        backgroundColor: C.muted,
    },
    supDotDirect: { backgroundColor: C.primary },
    supDotTop: { backgroundColor: C.text },
    supLine: { flex: 1, width: 2, backgroundColor: C.border, marginTop: 2 },
    pill: {
        borderRadius: 9,
        paddingHorizontal: 9,
        paddingVertical: 3,
        marginLeft: 6,
        borderWidth: 1,
    },
    pillDirect: { backgroundColor: C.primarySoft, borderColor: C.primary },
    pillTop: { backgroundColor: C.text, borderColor: C.text },
    pillUp: { backgroundColor: '#eef2f7', borderColor: C.border },
    pillText: { fontSize: 11, fontWeight: '700' },
    pillTextDirect: { color: C.primary },
    pillTextTop: { color: '#ffffff' },
    pillTextUp: { color: C.muted },

    personText: { flex: 1, marginLeft: 10 },
    personName: { flexShrink: 1, fontSize: 14.5, fontWeight: '600', color: C.text },
    personMeta: { fontSize: 12, color: C.muted, marginTop: 1 },

    treeRow: {
        flexDirection: 'row',
        alignItems: 'center',
        paddingVertical: 8,
        paddingHorizontal: 8,
        marginBottom: 8,
        borderRadius: 10,
        backgroundColor: C.bg,
        borderWidth: 2,
        borderColor: C.border,
        borderLeftWidth: 4,
        borderLeftColor: C.primary,
    },
    treeRowContent: { flex: 1, flexDirection: 'row', alignItems: 'center' },
    treeElbow: {
        width: 10,
        height: 1,
        backgroundColor: C.border,
        marginRight: 4,
    },
    treeRight: { flexDirection: 'row', alignItems: 'center', paddingLeft: 6 },
    nameRow: { flexDirection: 'row', alignItems: 'center' },
    teamPill: {
        marginLeft: 6,
        backgroundColor: C.surface,
        borderWidth: 1,
        borderColor: C.border,
        borderRadius: 8,
        paddingHorizontal: 6,
        paddingVertical: 1,
    },
    teamPillText: { fontSize: 10.5, fontWeight: '700', color: C.muted },
    gwPill: { backgroundColor: 'rgba(202, 138, 4, 0.10)', borderColor: '#ca8a04' },
    gwPillText: { color: '#ca8a04' },
    marksBox: { alignItems: 'center', minWidth: 40, marginRight: 2 },
    marksValue: { fontSize: 16.5, fontWeight: '800' },
    marksValueEmpty: { color: C.muted },
    marksLabel: { fontSize: 9.5, fontWeight: '600', color: C.muted, letterSpacing: 0.3, marginTop: -1 },
    caretBtn: { paddingHorizontal: 2 },
    caret: { fontSize: 14, color: C.primary, width: 18, textAlign: 'center' },
    caretSpacer: { width: 18 },

    checkbox: {
        width: 22,
        height: 22,
        borderRadius: 5,
        borderWidth: 2,
        borderColor: C.muted,
        backgroundColor: C.surface,
        alignItems: 'center',
        justifyContent: 'center',
        marginRight: 8,
    },
    checkboxChecked: { backgroundColor: C.primary, borderColor: C.primary },
    checkboxMark: { color: '#fff', fontSize: 13, fontWeight: '800', lineHeight: 13 },

    /* modal */
    modalOverlay: {
        flex: 1,
        backgroundColor: 'rgba(15, 23, 42, 0.45)',
        justifyContent: 'center',
        alignItems: 'center',
        padding: 20,
    },
    modalCard: {
        width: '100%',
        maxWidth: 420,
        backgroundColor: C.surface,
        borderRadius: 14,
        padding: 14,
    },
    modalHeader: { flexDirection: 'row', alignItems: 'center', marginBottom: 10 },
    modalHeaderText: { flex: 1, marginLeft: 10 },
    modalName: { fontSize: 16, fontWeight: '700', color: C.text },
    modalRole: { fontSize: 12.5, color: C.muted, marginTop: 1 },
    modalClose: { fontSize: 18, color: C.muted, paddingHorizontal: 4 },

    menuItem: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        paddingVertical: 14,
        paddingHorizontal: 12,
        borderWidth: 1,
        borderColor: C.border,
        borderRadius: 10,
        backgroundColor: C.bg,
        marginTop: 4,
    },
    menuNumber: {
        width: 24,
        height: 24,
        borderRadius: 12,
        backgroundColor: C.primary,
        alignItems: 'center',
        justifyContent: 'center',
        marginRight: 10,
    },
    menuNumberText: { color: '#fff', fontSize: 12.5, fontWeight: '700' },
    menuItemText: { flex: 1, fontSize: 15, fontWeight: '600', color: C.text },
    menuItemChevron: { fontSize: 20, color: C.muted },

    /* floating menu (bottom right) */
    fabScrim: { ...StyleSheet.absoluteFillObject, backgroundColor: 'rgba(15, 23, 42, 0.35)' },
    fabDock: { position: 'absolute', right: 18, alignItems: 'flex-end' },
    fabItems: { alignItems: 'flex-end', marginBottom: 12 },
    fabItem: { flexDirection: 'row', alignItems: 'center', marginBottom: 12 },
    fabItemLabel: {
        backgroundColor: C.surface,
        borderRadius: 8,
        paddingHorizontal: 11,
        paddingVertical: 7,
        marginRight: 10,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 1 },
        shadowOpacity: 0.18,
        shadowRadius: 3,
        elevation: 4,
    },
    fabItemLabelText: { fontSize: 13, fontWeight: '700', color: C.text },
    fabItemBtn: {
        width: 46,
        height: 46,
        borderRadius: 23,
        backgroundColor: C.primary,
        alignItems: 'center',
        justifyContent: 'center',
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.22,
        shadowRadius: 4,
        elevation: 6,
    },
    fabItemBtnMuted: { backgroundColor: C.muted },
    fabItemIcon: { color: '#fff', fontSize: 20, fontWeight: '800' },

    fab: {
        width: 58,
        height: 58,
        borderRadius: 29,
        backgroundColor: C.primary,
        alignItems: 'center',
        justifyContent: 'center',
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 3 },
        shadowOpacity: 0.28,
        shadowRadius: 5,
        elevation: 9,
    },
    fabActive: { backgroundColor: '#1e293b' },
    fabIcon: { color: '#fff', fontSize: 27, fontWeight: '300', lineHeight: 31 },

    /* staff directory entry point (top of the page) */
});
