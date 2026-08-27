import React, { Component } from 'react';
import {
    View,
    Text,
    ScrollView,
    StyleSheet,
    Modal,
    TouchableOpacity,
    ActivityIndicator,
} from 'react-native';
import { fetchPending, reviewJobSpecVersion } from '../services/jobSpecApi';

// A superior's pending-approval queue. Opened from the Job Spec header.
// Self-contained: loads when it becomes visible, actions each card in place,
// and reports the remaining count back via onChanged.
//
// Props: { visible, boss, bossCompId, reviewer, reviewerCompId, onClose, onChanged }

const C = {
    bg: '#f1f5f9',
    surface: '#ffffff',
    primary: '#2563eb',
    success: '#059669',
    successSoft: 'rgba(5, 150, 105, 0.10)',
    danger: '#dc2626',
    dangerSoft: 'rgba(220, 38, 38, 0.08)',
    text: '#1e293b',
    muted: '#64748b',
    border: '#e2e8f0',
};

const titleCase = (value) =>
    (value || '').toLowerCase().replace(/\b\w/g, (c) => c.toUpperCase());

// "2026-07-16 08:29:00" -> "16 Jul 2026 · 08:29"
const fmtDate = (dt) => {
    if (!dt) return '';
    const parts = String(dt).split(' ');
    const p = (parts[0] || '').split('-');
    const time = (parts[1] || '').substring(0, 5);
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const mi = parseInt(p[1], 10) - 1;
    if (p.length < 3 || isNaN(mi) || mi < 0 || mi > 11) return dt;
    return parseInt(p[2], 10) + ' ' + months[mi] + ' ' + p[0] + (time ? ' \u00b7 ' + time : '');
};

export default class JobSpecApprovals extends Component {
    constructor(props) {
        super(props);
        this.state = { loading: false, error: null, items: [], busyId: null };
    }

    componentDidMount() {
        if (this.props.visible) {
            this.load();
        }
    }

    componentDidUpdate(prev) {
        // Reload each time the modal is opened.
        if (!prev.visible && this.props.visible) {
            this.load();
        }
    }

    load = async () => {
        this.setState({ loading: true, error: null });
        try {
            const items = await fetchPending({
                boss: this.props.boss,
                bossCompId: this.props.bossCompId,
            });
            this.setState({ items: items, loading: false });
        } catch (e) {
            this.setState({ error: e.message, loading: false });
        }
    };

    // Command: approve/reject one version, then drop it from the list.
    act = async (versionId, action) => {
        this.setState({ busyId: versionId, error: null });
        try {
            await reviewJobSpecVersion({
                action: action,
                versionId: versionId,
                reviewer: this.props.reviewer,
                reviewerCompId: this.props.reviewerCompId,
            });
            const remaining = this.state.items.filter((it) => it.version_id !== versionId);
            this.setState({ items: remaining, busyId: null });
            this.props.onChanged && this.props.onChanged(remaining.length);
        } catch (e) {
            this.setState({ error: e.message, busyId: null });
        }
    };

    renderCard = (it) => {
        const busy = this.state.busyId === it.version_id;
        const specs = it.job_specs || [];
        return (
            <View key={it.version_id} style={styles.card}>
                <Text style={styles.cardName} numberOfLines={1}>{titleCase(it.person_name)}</Text>
                <Text style={styles.cardMeta} numberOfLines={1}>
                    {'Submitted ' + fmtDate(it.submitted_at) +
                        (it.submitted_by_name ? ' \u00b7 by ' + titleCase(it.submitted_by_name) : '')}
                </Text>

                <View style={styles.specBox}>
                    {specs.length === 0 ? (
                        <Text style={styles.specEmpty}>No tasks in this version.</Text>
                    ) : (
                        specs.map((s, i) => (
                            <View key={i} style={styles.specRow}>
                                <Text style={styles.specNum}>{i + 1}</Text>
                                <Text style={styles.specTask}>{s.task}</Text>
                            </View>
                        ))
                    )}
                </View>

                <View style={styles.actions}>
                    <TouchableOpacity
                        style={[styles.rejectBtn, busy && styles.btnDisabled]}
                        onPress={() => this.act(it.version_id, 'reject')}
                        disabled={busy}
                        activeOpacity={0.7}
                    >
                        <Text style={styles.rejectText}>Reject</Text>
                    </TouchableOpacity>
                    <TouchableOpacity
                        style={[styles.approveBtn, busy && styles.btnDisabled]}
                        onPress={() => this.act(it.version_id, 'approve')}
                        disabled={busy}
                        activeOpacity={0.7}
                    >
                        {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.approveText}>Approve</Text>}
                    </TouchableOpacity>
                </View>
            </View>
        );
    };

    render() {
        const { visible, onClose } = this.props;
        const { loading, error, items } = this.state;

        return (
            <Modal visible={!!visible} transparent statusBarTranslucent animationType="slide" onRequestClose={onClose}>
                <View style={styles.overlay}>
                    <View style={styles.sheet}>
                        <View style={styles.header}>
                            <Text style={styles.title}>Pending Approvals</Text>
                            <TouchableOpacity onPress={onClose} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}>
                                <Text style={styles.close}>{'\u2715'}</Text>
                            </TouchableOpacity>
                        </View>

                        {error ? <Text style={styles.error}>{error}</Text> : null}

                        {loading ? (
                            <View style={styles.center}>
                                <ActivityIndicator size="large" color={C.primary} />
                            </View>
                        ) : items.length === 0 ? (
                            <View style={styles.center}>
                                <Text style={styles.empty}>No pending approvals.</Text>
                            </View>
                        ) : (
                            <ScrollView contentContainerStyle={styles.list}>
                                {items.map((it) => this.renderCard(it))}
                            </ScrollView>
                        )}
                    </View>
                </View>
            </Modal>
        );
    }
}

const styles = StyleSheet.create({
    overlay: { flex: 1, backgroundColor: 'rgba(15, 23, 42, 0.45)', justifyContent: 'flex-end' },
    sheet: {
        backgroundColor: C.surface,
        borderTopLeftRadius: 18,
        borderTopRightRadius: 18,
        maxHeight: '85%',
        paddingBottom: 28,
    },
    header: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        paddingHorizontal: 16,
        paddingVertical: 14,
        borderBottomWidth: 1,
        borderBottomColor: C.border,
    },
    title: { fontSize: 16, fontWeight: '700', color: C.text },
    close: { fontSize: 18, color: C.muted, paddingHorizontal: 4 },

    error: { color: C.danger, fontSize: 13, textAlign: 'center', paddingHorizontal: 16, paddingTop: 10 },

    center: { minHeight: 220, paddingVertical: 40, alignItems: 'center', justifyContent: 'center' },
    empty: { color: C.muted, fontSize: 14 },

    list: { padding: 14 },

    card: {
        backgroundColor: C.bg,
        borderWidth: 1,
        borderColor: C.border,
        borderRadius: 12,
        padding: 12,
        marginBottom: 12,
    },
    cardName: { fontSize: 15.5, fontWeight: '700', color: C.text },
    cardMeta: { fontSize: 12, color: C.muted, marginTop: 2, marginBottom: 8 },

    specBox: {
        backgroundColor: C.surface,
        borderWidth: 1,
        borderColor: C.border,
        borderRadius: 8,
        padding: 8,
        marginBottom: 10,
    },
    specEmpty: { color: C.muted, fontSize: 12.5, textAlign: 'center', paddingVertical: 6 },
    specRow: { flexDirection: 'row', paddingVertical: 3 },
    specNum: { width: 20, textAlign: 'center', fontSize: 12.5, fontWeight: '700', color: C.muted, marginRight: 6 },
    specTask: { flex: 1, fontSize: 13.5, color: C.text, lineHeight: 19 },

    actions: { flexDirection: 'row' },
    rejectBtn: {
        flex: 1,
        paddingVertical: 11,
        borderRadius: 8,
        backgroundColor: C.dangerSoft,
        borderWidth: 1,
        borderColor: C.danger,
        alignItems: 'center',
        marginRight: 8,
    },
    rejectText: { color: C.danger, fontSize: 14.5, fontWeight: '700' },
    approveBtn: {
        flex: 2,
        paddingVertical: 11,
        borderRadius: 8,
        backgroundColor: C.success,
        alignItems: 'center',
        justifyContent: 'center',
    },
    approveText: { color: '#fff', fontSize: 14.5, fontWeight: '700' },
    btnDisabled: { opacity: 0.6 },
});