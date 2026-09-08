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
} from 'react-native';
import store from 'react-native-simple-store';
import ROUTES from '../../../../library/routes.js';
import IssueActionModal from './IssueActionModal.js';

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

const initials = (fullname) =>
    titleCase(fullname)
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0])
        .join('')
        .toUpperCase();

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

const Avatar = ({ fullname, size = 40, color = C.primary }) => (
    <View
        style={[
            styles.avatar,
            { width: size, height: size, borderRadius: size / 2, backgroundColor: color },
        ]}
    >
        <Text style={[styles.avatarText, { fontSize: size * 0.36 }]}>{initials(fullname)}</Text>
    </View>
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
const SuperiorRow = ({ person, showRail, isLast }) => {
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
        <View style={[styles.supRow, !showRail && styles.supRowPlain]}>
            {showRail && (
                <View style={styles.supRail}>
                    <View style={[styles.supDot, isDirect && styles.supDotDirect, isTop && styles.supDotTop]} />
                    {!isLast && <View style={styles.supLine} />}
                </View>
            )}
            <Avatar fullname={person.fullname} size={38} color={isDirect ? C.primary : C.muted} />
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
                    <Avatar fullname={node.fullname} size={34} color={isDirect ? C.primary : C.muted} />
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
            issueAction: null,    // 'memo' | 'merit' | 'demerit' for the selected staff
            issueOpen: false,     // the issue form is up
            user: null,           // session + own name, handed to the issue form
            menuNode: null,       // selected subordinate (null = modal closed)
            menuEditable: false,  // direct report? -> may edit (server re-checks)
        };
    }

    componentDidMount() {
        this.load();
    }

    /** Command: fetches hierarchy and pushes it into state. */
    load = async (refreshing = false) => {
        this.setState(refreshing ? { refreshing: true, error: null } : { loading: true, error: null });

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

    toggle = (key) => {
        this.setState((prev) => ({
            expanded: { ...prev.expanded, [key]: !prev.expanded[key] },
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
                                    <Avatar fullname={menuNode.fullname} size={44} color={C.primary} />
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
                                <MenuItem number={1} label="Job Spec" onPress={this.goToJobSpec} />
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
        const selectedStaff = this.getSelectedStaff();
        const totalNodes = Object.keys(flattenTree(subordinates)).length;
        const allSelected = totalNodes > 0 && selectedStaff.length === totalNodes;

        return (
            <View style={{ flex: 1 }}>
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
                    <View style={styles.brandHeader}>
                        <Image source={require('../assets/mk.png')} style={styles.brandLogo} resizeMode="contain" />
                        <Text style={styles.brandTitle}>MK Command Center</Text>
                    </View>

                    {/* Identity */}
                    <View style={styles.hero}>
                        <Avatar fullname={staff.fullname} size={64} />
                        <Text style={styles.heroName}>{titleCase(staff.fullname)}</Text>
                        <Text style={styles.heroMeta}>{staff.position || 'No Position'}</Text>
                        <Text style={styles.heroMeta}>
                            {staff.department || 'No Department'}
                            {staff.company_name ? ` \u2022 ${staff.company_name}` : ''}
                        </Text>
                    </View>

                    {/* Totals */}
                    <View style={styles.statRow}>
                        <StatCard label="Superiors" value={summary.total_superiors} color={C.primary} />
                        <StatCard label="Direct Subordinates" value={summary.direct_subordinates} color={C.success} />
                        <StatCard label="Total Subordinates" value={summary.total_subordinates} color={C.text} />
                    </View>

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
                </ScrollView>

                {selectedStaff.length > 0 && this.renderIssueActions(selectedStaff.length)}
                {this.renderStaffMenu()}

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
        marginTop: -14,
        marginHorizontal: -14,
        marginBottom: 14,
        paddingHorizontal: 16,
        paddingVertical: 14,
        borderBottomWidth: 3,
        borderBottomColor: '#22c55e',
    },
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

    avatar: { alignItems: 'center', justifyContent: 'center' },
    avatarText: { color: '#fff', fontWeight: '700' },

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
});