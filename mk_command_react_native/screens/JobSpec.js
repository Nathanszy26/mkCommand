import React, { Component } from 'react';
import { View, Text, StyleSheet, ActivityIndicator, TouchableOpacity } from 'react-native';
import store from 'react-native-simple-store';
import JobSpecList from '../components/JobSpecList';
import JobSpecEditor from '../components/JobSpecEditor';
import JobSpecApprovals from '../components/JobSpecApprovals';
import { fetchPending } from '../services/jobSpecApi';
 
const titleCase = (value) =>
    (value || '').toLowerCase().replace(/\b\w/g, (c) => c.toUpperCase());
 
const hit = { top: 8, bottom: 8, left: 8, right: 8 };
 
/**
 * Job Spec tab.
 *
 *  - Default / tapping the tab   -> the logged-in user's own spec (editable).
 *  - navigate() from Staff        -> a subordinate's spec (editable only if direct).
 *  - navigate() with startEdit    -> opens straight into the editor (gated by editable).
 *
 * Editing is a MODE of this tab (no separate route): the read-only JobSpecList
 * is swapped for JobSpecEditor. After a submit, a reload nonce remounts the list
 * so an auto-approved version shows immediately.
 *
 * Tab-hijack fix: the custom tab bar emits 'tabPress', so a real tab tap always
 * resets to self; arriving via navigate() (no tabPress) shows the passed target.
 */
export default class JobSpec extends Component {
    constructor(props) {
        super(props);
        this.self = null;
        this._forceSelf = false;
        this.state = {
            ready: false,
            error: null,
            target: null,        // { person, compId, id }
            viewingOther: false,
            editable: false,
            editing: false,
            otherName: null,
            notice: null,        // post-submit confirmation banner
            reloadKey: 0,        // bump to force JobSpecList refetch
            pendingCount: 0,     // my direct subordinates' submissions awaiting review
            showApprovals: false,
        };
    }
 
    async componentDidMount() {
        const nav = this.props.navigation;
        try {
            const u = await store.get('AppUser');
            this.self = {
                person: u && u.person,
                compId: u && (u.comp_id || u.comid),
                id: u && (u.id || u.staff_id),
            };
        } catch (e) {
            this.self = null;
        }
 
        this.applyRoute();
        this.loadPending();
 
        if (nav) {
            this._offFocus = nav.addListener('focus', this.onFocus);
            this._offTabPress = nav.addListener('tabPress', this.onTabPress);
        }
    }
 
    componentWillUnmount() {
        this._offFocus && this._offFocus();
        this._offTabPress && this._offTabPress();
    }
 
    // My subordinates' pending submissions. Failure just leaves the badge hidden.
    loadPending = async () => {
        const s = this.self;
        if (!s || !s.person || !s.compId) {
            return;
        }
        try {
            const items = await fetchPending({ boss: s.person, bossCompId: s.compId });
            this.setState({ pendingCount: items.length });
        } catch (e) {
            // non-fatal; keep the page usable
        }
    };
 
    onTabPress = () => {
        this._forceSelf = true;
    };
 
    onFocus = () => {
        this.loadPending();
        if (this._forceSelf) {
            this._forceSelf = false;
            this.resetToSelf();
            return;
        }
        this.applyRoute();
    };
 
    applyRoute = () => {
        const params = (this.props.route && this.props.route.params) || {};
        if (params.person) {
            const startEdit = !!params.startEdit && !!params.editable;
            this.setState({
                ready: true,
                error: null,
                viewingOther: true,
                editable: !!params.editable,
                editing: startEdit,
                otherName: params.fullname || null,
                notice: null,
                target: {
                    person: params.person,
                    compId: params.comp_id,
                    id: params.id,
                    isGw: !!params.is_gw,
                },
            });
            // Consume the one-shot edit intent so a later focus doesn't reopen it.
            if (startEdit) {
                this.clearStartEdit();
            }
        } else {
            this.showSelf();
        }
    };
 
    showSelf = () => {
        const s = this.self;
        if (!s || (!s.person && !s.compId && !s.id)) {
            this.setState({ ready: true, error: 'Missing user session. Please log in again.' });
            return;
        }
        this.setState({
            ready: true,
            error: null,
            viewingOther: false,
            editable: true,
            editing: false,
            otherName: null,
            notice: null,
            target: s,
        });
    };
 
    // Clears the subordinate params so the tab is genuinely "mine" again.
    resetToSelf = () => {
        const nav = this.props.navigation;
        if (nav) {
            nav.setParams({
                person: undefined,
                comp_id: undefined,
                id: undefined,
                editable: undefined,
                fullname: undefined,
                startEdit: undefined,
            });
        }
        this.showSelf();
    };
 
    clearStartEdit = () => {
        const nav = this.props.navigation;
        if (nav) {
            nav.setParams({ startEdit: undefined });
        }
    };
 
    startEditing = () => {
        this.setState({ editing: true, notice: null });
    };
 
    cancelEditing = () => {
        this.setState({ editing: false });
        this.clearStartEdit();
    };
 
    onSubmitted = (status) => {
        const msg =
            status === 'approved'
                ? 'Submitted and approved. The updated spec is now live.'
                : 'Submitted for approval. Your direct superior will review it.';
        this.setState((prev) => ({ editing: false, notice: msg, reloadKey: prev.reloadKey + 1 }));
        this.clearStartEdit();
    };
 
    openApprovals = () => {
        this.setState({ showApprovals: true });
    };
 
    closeApprovals = () => {
        this.setState({ showApprovals: false });
        this.loadPending();
    };
 
    onApprovalsChanged = (remaining) => {
        this.setState({ pendingCount: remaining });
        // If the reviewed staff is the one currently on screen, refresh their list.
        this.setState((prev) => ({ reloadKey: prev.reloadKey + 1 }));
    };
 
    render() {
        const {
            ready, error, target, viewingOther, editable, editing,
            otherName, notice, reloadKey, pendingCount, showApprovals,
        } = this.state;
 
        if (!ready) {
            return (
                <View style={styles.center}>
                    <ActivityIndicator size="large" color="#2563eb" />
                </View>
            );
        }
 
        if (error) {
            return (
                <View style={styles.center}>
                    <Text style={styles.errorText}>{error}</Text>
                </View>
            );
        }
 
        const who = viewingOther ? titleCase(otherName || target.person) : 'My Job Specifications';
 
        return (
            <View style={styles.screen}>
                <View style={styles.header}>
                    <View style={styles.headerRow}>
                        <View style={styles.headerText}>
                            {viewingOther && !editing && (
                                <TouchableOpacity onPress={this.resetToSelf} hitSlop={hit}>
                                    <Text style={styles.backLink}>{'\u2190'} My Job Spec</Text>
                                </TouchableOpacity>
                            )}
                            <Text style={styles.title}>{editing ? 'Edit Job Spec' : who}</Text>
                            {editing ? (
                                <Text style={styles.sub}>
                                    {viewingOther ? who : 'Your job specifications'}
                                </Text>
                            ) : (
                                viewingOther && <Text style={styles.sub}>Job Specifications</Text>
                            )}
                        </View>
 
                        <View style={styles.headerActions}>
                            {!viewingOther && !editing && (
                                <TouchableOpacity style={styles.approvalsBtn} onPress={this.openApprovals} activeOpacity={0.7}>
                                    <Text style={styles.approvalsBtnText}>Approvals</Text>
                                    {pendingCount > 0 && (
                                        <View style={styles.approvalsBadge}>
                                            <Text style={styles.approvalsBadgeText}>{pendingCount}</Text>
                                        </View>
                                    )}
                                </TouchableOpacity>
                            )}
 
                            {editable && !editing && (
                                <TouchableOpacity style={styles.editBtn} onPress={this.startEditing} activeOpacity={0.7}>
                                    <Text style={styles.editBtnText}>Edit</Text>
                                </TouchableOpacity>
                            )}
                        </View>
                    </View>
                </View>
 
                {notice ? (
                    <View style={styles.notice}>
                        <Text style={styles.noticeText}>{notice}</Text>
                    </View>
                ) : null}
 
                {editing ? (
                    <JobSpecEditor
                        key={'edit|' + target.person + '|' + target.compId + '|' + (target.isGw ? 1 : 0)}
                        person={target.person}
                        compId={target.compId}
                        id={target.id}
                        isGw={target.isGw}
                        submitter={this.self && this.self.person}
                        submitterCompId={this.self && this.self.compId}
                        onCancel={this.cancelEditing}
                        onSubmitted={this.onSubmitted}
                    />
                ) : (
                    <JobSpecList
                        key={target.person + '|' + target.compId + '|' + (target.isGw ? 1 : 0) + '|' + reloadKey}
                        person={target.person}
                        compId={target.compId}
                        id={target.id}
                        isGw={target.isGw}
                    />
                )}
 
                {this.self && (
                    <JobSpecApprovals
                        visible={showApprovals}
                        boss={this.self.person}
                        bossCompId={this.self.compId}
                        reviewer={this.self.person}
                        reviewerCompId={this.self.compId}
                        onClose={this.closeApprovals}
                        onChanged={this.onApprovalsChanged}
                    />
                )}
            </View>
        );
    }
}
 
const styles = StyleSheet.create({
    screen: { flex: 1, backgroundColor: '#f1f5f9' },
    center: { flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: '#f1f5f9', padding: 24 },
    errorText: { color: '#dc2626', textAlign: 'center', fontSize: 15 },
    header: {
        backgroundColor: '#ffffff',
        borderBottomWidth: 1,
        borderBottomColor: '#e2e8f0',
        paddingHorizontal: 14,
        paddingVertical: 12,
    },
    headerRow: { flexDirection: 'row', alignItems: 'center' },
    headerText: { flex: 1 },
    headerActions: { flexDirection: 'row', alignItems: 'center' },
    backLink: { color: '#2563eb', fontWeight: '600', fontSize: 13, marginBottom: 4 },
    title: { fontSize: 17, fontWeight: '700', color: '#1e293b' },
    sub: { fontSize: 12.5, color: '#64748b', marginTop: 1 },
 
    approvalsBtn: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: 'rgba(37, 99, 235, 0.08)',
        borderWidth: 1,
        borderColor: '#2563eb',
        paddingHorizontal: 12,
        paddingVertical: 7,
        borderRadius: 8,
        marginLeft: 10,
    },
    approvalsBtnText: { color: '#2563eb', fontSize: 13.5, fontWeight: '700' },
    approvalsBadge: {
        backgroundColor: '#dc2626',
        borderRadius: 9,
        minWidth: 18,
        paddingHorizontal: 5,
        paddingVertical: 1,
        alignItems: 'center',
        marginLeft: 6,
    },
    approvalsBadgeText: { color: '#fff', fontSize: 11, fontWeight: '800' },
 
    editBtn: {
        backgroundColor: '#2563eb',
        paddingHorizontal: 18,
        paddingVertical: 8,
        borderRadius: 8,
        marginLeft: 10,
    },
    editBtnText: { color: '#fff', fontSize: 14, fontWeight: '700' },
 
    notice: {
        backgroundColor: 'rgba(5, 150, 105, 0.10)',
        borderLeftWidth: 4,
        borderLeftColor: '#059669',
        paddingHorizontal: 14,
        paddingVertical: 10,
    },
    noticeText: { color: '#059669', fontSize: 13, fontWeight: '600' },
});