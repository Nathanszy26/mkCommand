import React, { Component } from 'react';
import {
    View,
    Text,
    ScrollView,
    StyleSheet,
    ActivityIndicator,
    TouchableOpacity,
    RefreshControl,
} from 'react-native';
import { fetchJobSpecs } from '../services/jobSpecApi';
 
// Shared by the Job Spec tab (self) and the Staff modal (a subordinate).
// Pure w.r.t. props: give it { person, compId, id } and it renders the list.
 
const C = {
    bg: '#f1f5f9',
    surface: '#ffffff',
    primary: '#2563eb',
    primarySoft: 'rgba(37, 99, 235, 0.08)',
    success: '#059669',
    successSoft: 'rgba(5, 150, 105, 0.10)',
    text: '#1e293b',
    muted: '#64748b',
    border: '#e2e8f0',
    danger: '#dc2626',
};
 
const titleCase = (value) =>
    (value || '').toLowerCase().replace(/\b\w/g, (c) => c.toUpperCase());
 
// "2026-07-16 08:29:00" -> "16 Jul 2026 · 08:29"
const fmtDate = (dt) => {
    if (!dt) return '';
    const [d, t] = String(dt).split(' ');
    const p = (d || '').split('-');
    const time = (t || '').substring(0, 5);
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const mi = parseInt(p[1], 10) - 1;
    if (p.length < 3 || isNaN(mi) || mi < 0 || mi > 11) return dt;
    return parseInt(p[2], 10) + ' ' + months[mi] + ' ' + p[0] + (time ? ' \u00b7 ' + time : '');
};
 
const Empty = ({ text }) => <Text style={styles.emptyText}>{text}</Text>;
 
const SourceBadge = ({ source, version }) => {
    const approved = source === 'mkportal';
    return (
        <View style={[styles.badge, approved ? styles.badgeApproved : styles.badgeSource]}>
            <Text style={[styles.badgeText, approved ? styles.badgeTextApproved : styles.badgeTextSource]}>
                {approved ? 'Latest Version' : 'Capability Version'}
            </Text>
            {approved && version && !!version.reviewed_at && (
                <Text style={styles.badgeSub}>
                    {'Updated ' + fmtDate(version.reviewed_at) +
                        (version.reviewed_by ? ' \u00b7 ' + titleCase(version.reviewed_by) : '')}
                </Text>
            )}
        </View>
    );
};
 
const SpecRow = ({ index, task, apps }) => (
    <View style={styles.specRow}>
        <Text style={styles.specNum}>{index}</Text>
        <View style={styles.specBody}>
            <Text style={styles.specTask}>{task}</Text>
            {Array.isArray(apps) && apps.length > 0 && (
                <View style={styles.appsBar}>
                    {apps.map((a) => (
                        <View key={a.application_id} style={styles.chip}>
                            <Text style={styles.chipText} numberOfLines={1}>{a.application_title}</Text>
                        </View>
                    ))}
                </View>
            )}
        </View>
    </View>
);
 
export default class JobSpecList extends Component {
    constructor(props) {
        super(props);
        this.state = { loading: true, refreshing: false, error: null, data: null };
    }
 
    componentDidMount() {
        this.load();
    }
 
    // Reused across staff in the same modal instance: refetch when the target changes.
    componentDidUpdate(prev) {
        if (
            prev.person !== this.props.person ||
            prev.compId !== this.props.compId ||
            prev.id !== this.props.id ||
            prev.isGw !== this.props.isGw
        ) {
            this.load();
        }
    }
 
    load = async (refreshing = false) => {
        this.setState(refreshing ? { refreshing: true, error: null } : { loading: true, error: null });
        try {
            const data = await fetchJobSpecs({
                person: this.props.person,
                compId: this.props.compId,
                id: this.props.id,
                isGw: this.props.isGw,
            });
            this.setState({
                data: { source: data.source, version: data.version, job_specs: data.job_specs },
                loading: false,
                refreshing: false,
            });
        } catch (e) {
            this.setState({ error: e.message, loading: false, refreshing: false });
        }
    };
 
    render() {
        const { loading, refreshing, error, data } = this.state;
 
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
 
        const specs = data.job_specs;
 
        return (
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
                <SourceBadge source={data.source} version={data.version} />
                <Text style={styles.count}>
                    {specs.length} item{specs.length !== 1 ? 's' : ''}
                </Text>
 
                {specs.length === 0 ? (
                    <Empty text="No job specifications on record." />
                ) : (
                    specs.map((s, i) => (
                        <SpecRow
                            key={(s.job_description_id != null ? s.job_description_id : 'x') + '-' + i}
                            index={i + 1}
                            task={s.task}
                            apps={s.apps}
                        />
                    ))
                )}
            </ScrollView>
        );
    }
}
 
const styles = StyleSheet.create({
    screen: { flex: 1, backgroundColor: C.bg },
    content: { padding: 14, paddingBottom: 30 },
    center: { flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: C.bg, padding: 24 },
 
    errorText: { color: C.danger, textAlign: 'center', marginBottom: 16, fontSize: 15 },
    retryBtn: { backgroundColor: C.primary, paddingHorizontal: 24, paddingVertical: 10, borderRadius: 8, alignSelf: 'center' },
    retryText: { color: '#fff', fontWeight: '600' },
 
    badge: { alignSelf: 'flex-start', borderRadius: 8, paddingHorizontal: 10, paddingVertical: 6, marginBottom: 8 },
    badgeApproved: { backgroundColor: C.successSoft },
    badgeSource: { backgroundColor: C.primarySoft },
    badgeText: { fontSize: 12, fontWeight: '700' },
    badgeTextApproved: { color: C.success },
    badgeTextSource: { color: C.primary },
    badgeSub: { fontSize: 11, color: C.muted, marginTop: 2 },
 
    count: { fontSize: 12, color: C.muted, marginBottom: 6, marginLeft: 2 },
 
    specRow: {
        flexDirection: 'row',
        backgroundColor: C.surface,
        borderWidth: 1,
        borderColor: C.border,
        borderRadius: 10,
        padding: 12,
        marginBottom: 8,
    },
    specNum: { width: 24, textAlign: 'center', fontSize: 13, fontWeight: '700', color: C.muted, marginRight: 8, marginTop: 1 },
    specBody: { flex: 1 },
    specTask: { fontSize: 14.5, color: C.text, lineHeight: 20 },
 
    appsBar: { flexDirection: 'row', flexWrap: 'wrap', marginTop: 8 },
    chip: {
        backgroundColor: C.primarySoft,
        borderWidth: 1,
        borderColor: 'rgba(37, 99, 235, 0.30)',
        borderRadius: 12,
        paddingHorizontal: 10,
        paddingVertical: 3,
        marginRight: 6,
        marginBottom: 6,
    },
    chipText: { color: C.primary, fontSize: 11.5, fontWeight: '600', maxWidth: 160 },
 
    emptyText: { color: C.muted, fontSize: 13, textAlign: 'center', paddingVertical: 24 },
});