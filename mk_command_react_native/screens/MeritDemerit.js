import React, { Component } from 'react';
import {
    View,
    Text,
    ScrollView,
    StyleSheet,
    ActivityIndicator,
    TouchableOpacity,
    RefreshControl,
    Modal,
} from 'react-native';
import store from 'react-native-simple-store';
import { fetchPerformance } from '../services/staffRecordsApi';

const C = {
    bg: '#f1f5f9',
    surface: '#ffffff',
    primary: '#2563eb',
    primarySoft: 'rgba(37, 99, 235, 0.08)',
    success: '#059669',
    successSoft: 'rgba(5, 150, 105, 0.10)',
    text: '#0f172a',
    muted: '#64748b',
    faint: '#94a3b8',
    border: '#e8edf3',
    danger: '#dc2626',
    dangerSoft: 'rgba(220, 38, 38, 0.10)',
};

const titleCase = (value) =>
    (value || '').toLowerCase().replace(/\b\w/g, (c) => c.toUpperCase());

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * staff_performance_date holds Y/m/d (e.g. 2026/07/31) — same as
 * IssueRepository::RECORD_DATE_FORMAT. MM/DD/YYYY is still accepted because a
 * handful of rows were written that way; drop that branch once they are
 * migrated.
 *
 * Query: [year, month, day] as numbers, or null when unparseable.
 */
const dateParts = (dt) => {
    const p = String(dt || '').trim().split(' ')[0].split(/[-/]/);
    if (p.length < 3) return null;
    // 4-digit head => year first; otherwise MM/DD/YYYY.
    const ordered = p[0].length === 4 ? [p[0], p[1], p[2]] : [p[2], p[0], p[1]];
    const y = parseInt(ordered[0], 10);
    const m = parseInt(ordered[1], 10);
    const d = parseInt(ordered[2], 10);
    if (!y || !m || !d || m < 1 || m > 12) return null;
    return [y, m, d];
};

// "03/12/2026" -> "12 Mar 2026"
const fmtDate = (dt) => {
    const p = dateParts(dt);
    return p ? p[2] + ' ' + MONTHS[p[1] - 1] + ' ' + p[0] : (dt || '');
};

const isDemerit = (v) => String(v || '').trim().toLowerCase().indexOf('demerit') !== -1;
const yearOf = (dt) => {
    const p = dateParts(dt);
    return p ? String(p[0]) : '';
};

// Baseline year range (current -> 2025, matching the web filter), unioned with any
// years actually present in the data so the picker always covers what's shown.
const FIRST_YEAR = 2025;
const buildYears = (records, known) => {
    const set = {};
    const now = new Date().getFullYear();
    for (let y = now; y >= FIRST_YEAR; y--) set[y] = true;
    // Already-known years are kept: the fetch is year-filtered, so dropping them
    // would make a year disappear from the picker as soon as it was selected.
    (known || []).forEach((y) => {
        const n = parseInt(y, 10);
        if (n) set[n] = true;
    });
    records.forEach((r) => {
        const y = parseInt(yearOf(r.date), 10);
        if (y) set[y] = true;
    });
    return Object.keys(set).map(Number).sort((a, b) => b - a).map(String);
};

/* --- presentational -------------------------------------------------------- */

const Stat = ({ label, value, color }) => (
    <View style={styles.stat}>
        <Text style={[styles.statValue, { color }]}>{value}</Text>
        <Text style={styles.statLabel}>{label}</Text>
    </View>
);

const Summary = ({ records }) => {
    let merit = 0;
    let demerit = 0;
    records.forEach((r) => {
        const pts = parseInt(r.points, 10) || 0;
        if (isDemerit(r.merit_demerit)) demerit += pts;
        else merit += pts;
    });
    const net = merit - demerit;
    return (
        <View style={styles.summary}>
            <Stat label="Merit" value={'+' + merit} color={C.success} />
            <View style={styles.statDivider} />
            <Stat label="Demerit" value={'-' + demerit} color={C.danger} />
            <View style={styles.statDivider} />
            <Stat label="Net" value={(net >= 0 ? '+' : '') + net} color={net >= 0 ? C.text : C.danger} />
        </View>
    );
};

const RecordCard = ({ record, index }) => {
    const demerit = isDemerit(record.merit_demerit);
    const tone = demerit ? C.danger : C.success;
    const toneSoft = demerit ? C.dangerSoft : C.successSoft;
    const sign = demerit ? '\u2212' : '+'; // − / +
    return (
        <View style={styles.card}>
            <View style={[styles.accent, { backgroundColor: tone }]} />
            <View style={styles.cardBody}>
                <View style={styles.cardTop}>
                    <View style={styles.cardTopLeft}>
                        <View style={styles.tagRow}>
                            <View style={[styles.tag, { backgroundColor: toneSoft }]}>
                                <Text style={[styles.tagText, { color: tone }]}>
                                    {demerit ? 'Demerit' : 'Merit'}
                                </Text>
                            </View>
                            <Text style={styles.index}>#{index}</Text>
                        </View>
                        <Text style={styles.title} numberOfLines={2}>
                            {record.title || '(Untitled)'}
                        </Text>
                    </View>
                    <View style={styles.pointsBox}>
                        <Text style={[styles.pointsValue, { color: tone }]}>
                            {sign}{record.points || '0'}
                        </Text>
                        <Text style={styles.pointsLabel}>PTS</Text>
                    </View>
                </View>

                <View style={styles.metaRow}>
                    <Text style={styles.metaText}>{fmtDate(record.date)}</Text>
                    {!!record.ref && (
                        <>
                            <Text style={styles.dot}>{'\u2022'}</Text>
                            <Text style={styles.metaText} numberOfLines={1}>{record.ref}</Text>
                        </>
                    )}
                </View>
            </View>
        </View>
    );
};

const YearPicker = ({ visible, years, active, onSelect, onClose }) => (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={onClose}>
        <TouchableOpacity style={styles.sheetOverlay} activeOpacity={1} onPress={onClose}>
            <View style={styles.sheet}>
                <View style={styles.sheetHandle} />
                <Text style={styles.sheetTitle}>Filter by year</Text>
                <ScrollView style={{ maxHeight: 320 }}>
                    {['', ...years].map((y) => {
                        const on = active === y;
                        return (
                            <TouchableOpacity
                                key={y || 'all'}
                                style={styles.sheetItem}
                                activeOpacity={0.6}
                                onPress={() => onSelect(y)}
                            >
                                <Text style={[styles.sheetItemText, on && styles.sheetItemTextOn]}>
                                    {y || 'All years'}
                                </Text>
                                {on && <Text style={styles.sheetCheck}>{'\u2713'}</Text>}
                            </TouchableOpacity>
                        );
                    })}
                </ScrollView>
            </View>
        </TouchableOpacity>
    </Modal>
);

/* --- screen ---------------------------------------------------------------- */

export default class MeritDemerit extends Component {
    constructor(props) {
        super(props);
        this.state = {
            loading: true,
            refreshing: false,
            error: null,
            user: null,
            records: [],
            years: buildYears([]),
            year: String(new Date().getFullYear()), // defaults to current year, like index.php
            pickerOpen: false,
        };
    }

    componentDidMount() {
        this.init();
    }

    init = async () => {
        try {
            const u = await store.get('AppUser');
            const comp = u && u.comid;
            if (!u || !u.person || !comp) {
                throw new Error('Missing user session. Please log in again.');
            }
            this.setState({ user: { person: u.person, compId: comp } }, () => this.load());
        } catch (e) {
            this.setState({ loading: false, error: e.message });
        }
    };

    load = async (refreshing = false) => {
        this.setState(refreshing ? { refreshing: true, error: null } : { loading: true, error: null });
        try {
            const records = await this.fetchRecords();
            this.setState((prev) => ({
                records,
                years: buildYears(records, prev.years),
                loading: false,
                refreshing: false,
            }));
        } catch (e) {
            this.setState({ loading: false, refreshing: false, error: e.message });
        }
    };

    /** Query: the selected year's records for the signed-in staff. */
    fetchRecords = () => fetchPerformance(this.state.user, this.state.year);

    selectYear = (year) => {
        this.setState({ year, pickerOpen: false }, () => this.load());
    };

    render() {
        const { loading, error, refreshing, records, year, years, pickerOpen } = this.state;

        if (loading && !refreshing && records.length === 0 && !error) {
            return (
                <View style={styles.center}>
                    <ActivityIndicator size="large" color={C.primary} />
                </View>
            );
        }

        return (
            <View style={styles.screen}>
                {/* Filter bar — dropdown replaces the pill row; scales to any number of years. */}
                <View style={styles.filterBar}>
                    <TouchableOpacity
                        style={styles.yearBtn}
                        activeOpacity={0.7}
                        onPress={() => this.setState({ pickerOpen: true })}
                    >
                        <Text style={styles.yearBtnLabel}>YEAR</Text>
                        <Text style={styles.yearBtnValue}>{year || 'All'}</Text>
                        <Text style={styles.yearBtnCaret}>{'\u25BE'}</Text>
                    </TouchableOpacity>
                    <Text style={styles.countInline}>
                        {records.length} record{records.length !== 1 ? 's' : ''}
                    </Text>
                </View>

                <ScrollView
                    style={styles.list}
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
                    {error ? (
                        <View style={styles.inlineError}>
                            <Text style={styles.errorText}>{error}</Text>
                            <TouchableOpacity style={styles.retryBtn} onPress={() => this.load()}>
                                <Text style={styles.retryText}>Retry</Text>
                            </TouchableOpacity>
                        </View>
                    ) : loading ? (
                        <ActivityIndicator color={C.primary} style={{ marginTop: 28 }} />
                    ) : records.length === 0 ? (
                        <Text style={styles.emptyText}>
                            No merit or demerit records{year ? ' for ' + year : ''}.
                        </Text>
                    ) : (
                        <>
                            <Summary records={records} />
                            {records.map((r, i) => (
                                <RecordCard key={r.id || i} record={r} index={i + 1} />
                            ))}
                        </>
                    )}
                </ScrollView>

                <YearPicker
                    visible={pickerOpen}
                    years={years}
                    active={year}
                    onSelect={this.selectYear}
                    onClose={() => this.setState({ pickerOpen: false })}
                />
            </View>
        );
    }
}

const CARD_SHADOW = {
    shadowColor: '#0f172a',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.05,
    shadowRadius: 6,
    elevation: 2,
};

const styles = StyleSheet.create({
    screen: { flex: 1, backgroundColor: C.bg },
    list: { flex: 1 },
    content: { padding: 16, paddingBottom: 40 },
    center: { flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: C.bg, padding: 24 },

    inlineError: { alignItems: 'center', paddingVertical: 40 },
    errorText: { color: C.danger, textAlign: 'center', marginBottom: 16, fontSize: 15 },
    retryBtn: { backgroundColor: C.primary, paddingHorizontal: 24, paddingVertical: 10, borderRadius: 8, alignSelf: 'center' },
    retryText: { color: '#fff', fontWeight: '600' },
    emptyText: { color: C.muted, fontSize: 14, textAlign: 'center', paddingVertical: 44 },

    /* filter bar */
    filterBar: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        paddingHorizontal: 16,
        paddingVertical: 12,
        backgroundColor: C.surface,
        borderBottomWidth: 1,
        borderBottomColor: C.border,
    },
    yearBtn: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: C.bg,
        borderWidth: 1,
        borderColor: C.border,
        borderRadius: 10,
        paddingHorizontal: 14,
        paddingVertical: 9,
    },
    yearBtnLabel: { fontSize: 10.5, fontWeight: '800', color: C.faint, letterSpacing: 0.8, marginRight: 8 },
    yearBtnValue: { fontSize: 15, fontWeight: '700', color: C.text, marginRight: 6 },
    yearBtnCaret: { fontSize: 12, color: C.muted },
    countInline: { fontSize: 12.5, color: C.faint, fontWeight: '600' },

    /* summary */
    summary: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: C.surface,
        borderRadius: 14,
        paddingVertical: 16,
        marginBottom: 14,
        ...CARD_SHADOW,
    },
    stat: { flex: 1, alignItems: 'center' },
    statValue: { fontSize: 22, fontWeight: '800' },
    statLabel: { fontSize: 11, fontWeight: '600', color: C.faint, letterSpacing: 0.5, marginTop: 2, textTransform: 'uppercase' },
    statDivider: { width: 1, height: 34, backgroundColor: C.border },

    /* record card */
    card: {
        flexDirection: 'row',
        backgroundColor: C.surface,
        borderRadius: 14,
        marginBottom: 12,
        overflow: 'hidden',
        ...CARD_SHADOW,
    },
    accent: { width: 5 },
    cardBody: { flex: 1, padding: 14 },
    cardTop: { flexDirection: 'row', alignItems: 'flex-start' },
    cardTopLeft: { flex: 1, paddingRight: 12 },
    tagRow: { flexDirection: 'row', alignItems: 'center', marginBottom: 8 },
    tag: { borderRadius: 6, paddingHorizontal: 8, paddingVertical: 3 },
    tagText: { fontSize: 11, fontWeight: '800', letterSpacing: 0.3, textTransform: 'uppercase' },
    index: { fontSize: 12, fontWeight: '700', color: C.faint, letterSpacing: 0.3, marginLeft: 8 },
    title: { fontSize: 15.5, fontWeight: '700', color: C.text, lineHeight: 20 },

    pointsBox: { alignItems: 'center', minWidth: 52 },
    pointsValue: { fontSize: 22, fontWeight: '800' },
    pointsLabel: { fontSize: 9, fontWeight: '800', color: C.faint, letterSpacing: 0.8, marginTop: -2 },

    metaRow: { flexDirection: 'row', alignItems: 'center', marginTop: 12 },
    metaText: { fontSize: 12, color: C.faint, fontWeight: '600' },
    dot: { fontSize: 12, color: C.border, marginHorizontal: 7 },

    /* year picker sheet */
    sheetOverlay: { flex: 1, backgroundColor: 'rgba(15, 23, 42, 0.45)', justifyContent: 'flex-end' },
    sheet: {
        backgroundColor: C.surface,
        borderTopLeftRadius: 20,
        borderTopRightRadius: 20,
        paddingHorizontal: 16,
        paddingTop: 10,
        paddingBottom: 28,
    },
    sheetHandle: { alignSelf: 'center', width: 40, height: 4, borderRadius: 2, backgroundColor: C.border, marginBottom: 12 },
    sheetTitle: { fontSize: 13, fontWeight: '700', color: C.muted, letterSpacing: 0.3, marginBottom: 6, marginLeft: 4 },
    sheetItem: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        paddingVertical: 15,
        paddingHorizontal: 8,
        borderBottomWidth: 1,
        borderBottomColor: C.border,
    },
    sheetItemText: { fontSize: 16, color: C.text },
    sheetItemTextOn: { color: C.primary, fontWeight: '700' },
    sheetCheck: { fontSize: 16, color: C.primary, fontWeight: '800' },
});