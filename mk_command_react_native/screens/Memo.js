import React, { Component } from 'react';
import {
    View,
    Text,
    Image,
    Modal,
    ScrollView,
    StyleSheet,
    ActivityIndicator,
    TouchableOpacity,
    RefreshControl,
    TextInput,
    Linking,
    Platform,
} from 'react-native';
import store from 'react-native-simple-store';
import { fetchMemos, fetchMemoDetail } from '../services/staffRecordsApi';

const C = {
    bg: '#f1f5f9',
    surface: '#ffffff',
    primary: '#2563eb',
    primarySoft: 'rgba(37, 99, 235, 0.08)',
    text: '#0f172a',
    muted: '#64748b',
    faint: '#94a3b8',
    border: '#e8edf3',
    danger: '#dc2626',
};

const titleCase = (value) =>
    (value || '').toLowerCase().replace(/\b\w/g, (c) => c.toUpperCase());

const initials = (name) =>
    titleCase(name)
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0])
        .join('')
        .toUpperCase() || '#';

// Stable colour per sender so monograms read as distinct people, not a wall of blue.
const AVATAR_COLORS = [
    '#2563eb', '#0891b2', '#7c3aed', '#db2777', '#ea580c',
    '#059669', '#ca8a04', '#4f46e5', '#0d9488', '#be123c',
];
const colorFor = (name) => {
    const s = String(name || '');
    let h = 0;
    for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
    return AVATAR_COLORS[h % AVATAR_COLORS.length];
};

// "2026-07-16" / "2026-07-16 08:29:00" -> "16 Jul 2026"
const fmtDate = (dt) => {
    if (!dt) return '';
    const d = String(dt).split(' ')[0];
    const p = d.split(/[-/]/);
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const mi = parseInt(p[1], 10) - 1;
    if (p.length < 3 || isNaN(mi) || mi < 0 || mi > 11) return dt;
    return parseInt(p[2], 10) + ' ' + months[mi] + ' ' + p[0];
};

const isBroadcast = (name) => String(name || '').trim().toLowerCase() === 'all staff';

const norm = (s) => String(s || '').trim().toLowerCase();
// A recipient is "me" when it exactly matches the logged-in staff's fullname.
// Both sides originate from users.fullname, so exact (normalized) equality is
// correct — substring matching would wrongly light up shared name prefixes.
const isMe = (recipient, me) => {
    const a = norm(recipient);
    const b = norm(me);
    return b.length > 0 && a === b;
};

/* --- presentational -------------------------------------------------------- */

// Full comma-separated recipient list, with the current user's name highlighted.
const Recipients = ({ toName, me }) => {
    const parts = String(toName || '').split(',').map((p) => p.trim()).filter(Boolean);
    if (parts.length === 0) return <Text style={styles.toValue}>{'\u2014'}</Text>;
    return (
        <Text style={styles.toValue}>
            {parts.map((p, i) => (
                <React.Fragment key={i}>
                    <Text style={isMe(p, me) ? styles.toMe : null}>{titleCase(p)}</Text>
                    {i < parts.length - 1 ? ', ' : ''}
                </React.Fragment>
            ))}
        </Text>
    );
};

const Monogram = ({ name, broadcast }) => (
    <View style={[styles.mono, { backgroundColor: broadcast ? C.primary : colorFor(name) }]}>
        <Text style={styles.monoText}>{broadcast ? '\u2691' : initials(name)}</Text>
    </View>
);

/* --- detail ---------------------------------------------------------------- */

const Attachment = ({ file }) => (
    <TouchableOpacity
        style={styles.attachment}
        activeOpacity={0.7}
        onPress={() => Linking.openURL(file.url)}
    >
        {file.is_image ? (
            <Image source={{ uri: file.url }} style={styles.thumb} resizeMode="cover" />
        ) : (
            <View style={styles.thumbFallback}>
                <Text style={styles.thumbIcon}>{'\uD83D\uDCCE'}</Text>
            </View>
        )}
        <View style={{ flex: 1 }}>
            <Text style={styles.attachmentName} numberOfLines={2}>{file.name}</Text>
            <Text style={styles.attachmentHint}>Tap to open</Text>
        </View>
    </TouchableOpacity>
);

const DetailRow = ({ label, children }) => (
    <View style={styles.detailRow}>
        <Text style={styles.detailLabel}>{label}</Text>
        <View style={{ flex: 1 }}>{children}</View>
    </View>
);

/**
 * Full memo, opened by tapping a card. Mounted only while open so each open
 * starts from a clean fetch — the list carries no body, so the detail is always
 * loaded fresh rather than half-populated from the card.
 */
class MemoDetail extends Component {
    constructor(props) {
        super(props);
        this.state = { loading: true, error: null, memo: null };
    }

    componentDidMount() {
        this.load();
    }

    load = async () => {
        this.setState({ loading: true, error: null });
        try {
            const memo = await fetchMemoDetail(this.props.user, this.props.memoId);
            this.setState({ memo, loading: false });
        } catch (e) {
            this.setState({ loading: false, error: e.message });
        }
    };

    renderBody() {
        const { loading, error, memo } = this.state;

        if (loading) {
            return (
                <View style={styles.detailCenter}>
                    <ActivityIndicator size="large" color={C.primary} />
                </View>
            );
        }
        if (error || !memo) {
            return (
                <View style={styles.detailCenter}>
                    <Text style={styles.errorText}>{error || 'Memo not available.'}</Text>
                    <TouchableOpacity style={styles.retryBtn} onPress={this.load}>
                        <Text style={styles.retryText}>Retry</Text>
                    </TouchableOpacity>
                </View>
            );
        }

        const attachments = memo.attachments || [];

        return (
            <ScrollView style={{ flex: 1 }} contentContainerStyle={styles.detailContent}>
                <Text style={styles.detailSubject}>{memo.subject || '(No subject)'}</Text>

                <View style={styles.detailMeta}>
                    <DetailRow label="FROM">
                        <Text style={styles.detailValue}>{memo.from || 'Unknown sender'}</Text>
                    </DetailRow>
                    <DetailRow label="TO">
                        <Recipients toName={memo.to_name} me={this.props.me} />
                    </DetailRow>
                    {!!memo.cc_name && (
                        <DetailRow label="CC">
                            <Recipients toName={memo.cc_name} me={this.props.me} />
                        </DetailRow>
                    )}
                    <DetailRow label="DATE">
                        <Text style={styles.detailValue}>{fmtDate(memo.date)}</Text>
                    </DetailRow>
                    {!!memo.ref && (
                        <DetailRow label="REF">
                            <Text style={styles.detailValue}>{memo.ref}</Text>
                        </DetailRow>
                    )}
                </View>

                <Text style={styles.detailContentText}>
                    {memo.content || '(This memo has no content.)'}
                </Text>

                {attachments.length > 0 && (
                    <View style={styles.attachmentBlock}>
                        <Text style={styles.attachmentHeading}>
                            {attachments.length} Attachment{attachments.length !== 1 ? 's' : ''}
                        </Text>
                        {attachments.map((f) => <Attachment key={f.id} file={f} />)}
                    </View>
                )}
            </ScrollView>
        );
    }

    render() {
        return (
            <Modal visible animationType="slide" onRequestClose={this.props.onClose}>
                <View style={styles.detailScreen}>
                    <View style={styles.detailHeader}>
                        <TouchableOpacity
                            onPress={this.props.onClose}
                            hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}
                        >
                            <Text style={styles.detailClose}>Close</Text>
                        </TouchableOpacity>
                        <Text style={styles.detailTitle}>Memo</Text>
                        <View style={{ width: 46 }} />
                    </View>
                    {this.renderBody()}
                </View>
            </Modal>
        );
    }
}

const MemoCard = ({ memo, index, me, onPress }) => {
    const broadcast = isBroadcast(memo.to_name);
    return (
        <TouchableOpacity style={styles.card} activeOpacity={0.75} onPress={onPress}>
            <View style={styles.cardHead}>
                <Monogram name={memo.from} />
                <View style={styles.headText}>
                    <Text style={styles.subject} numberOfLines={2}>
                        {memo.subject || '(No subject)'}
                    </Text>
                    <View style={styles.fromRow}>
                        <Text style={styles.fromLabel}>FROM</Text>
                        <Text style={styles.from} numberOfLines={1}>
                            {memo.from || 'Unknown sender'}
                        </Text>
                    </View>
                </View>
                <Text style={styles.index}>#{index}</Text>
            </View>

            <View style={styles.divider} />

            <View style={styles.toRow}>
                <Text style={styles.toLabel}>TO</Text>
                {broadcast ? (
                    <View style={styles.broadcastPill}>
                        <Text style={styles.broadcastText}>All Staff</Text>
                    </View>
                ) : (
                    <Recipients toName={memo.to_name} me={me} />
                )}
            </View>

            <View style={styles.footer}>
                <Text style={styles.date}>{fmtDate(memo.date)}</Text>
                {!!memo.ref && <Text style={styles.ref} numberOfLines={1}>{memo.ref}</Text>}
            </View>
        </TouchableOpacity>
    );
};

/* --- screen ---------------------------------------------------------------- */

export default class Memo extends Component {
    constructor(props) {
        super(props);
        this.state = {
            loading: true,
            refreshing: false,
            error: null,
            user: null,
            memos: [],
            meName: '',
            query: '',
            openMemoId: null, // null => detail closed
        };
    }

    componentDidMount() {
        this.init();
    }

    /** Command: resolve the stored session, then load memos. */
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
            const res = await fetchMemos(this.state.user);
            // Accepts the current array return, or { memos, meName } once the API
            // surfaces me_name from staffRecords.php. Absent meName => no highlight.
            const memos = Array.isArray(res) ? res : (res.memos || []);
            const meName = Array.isArray(res) ? '' : (res.meName || '');
            this.setState({ memos, meName, loading: false, refreshing: false });
        } catch (e) {
            this.setState({ loading: false, refreshing: false, error: e.message });
        }
    };

    /* --- derived ----------------------------------------------------------- */

    filtered() {
        const q = this.state.query.trim().toLowerCase();
        const memos = this.state.memos;
        if (!q) return memos;
        return memos.filter(
            (m) =>
                (m.subject || '').toLowerCase().indexOf(q) !== -1 ||
                (m.from || '').toLowerCase().indexOf(q) !== -1 ||
                (m.to_name || '').toLowerCase().indexOf(q) !== -1 ||
                (m.ref || '').toLowerCase().indexOf(q) !== -1
        );
    }

    render() {
        const { loading, error, refreshing } = this.state;

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

        const memos = this.filtered();
        const me = this.state.meName || (this.state.user && this.state.user.fullname) || '';

        return (
            <ScrollView
                style={styles.screen}
                contentContainerStyle={styles.content}
                keyboardShouldPersistTaps="handled"
                refreshControl={
                    <RefreshControl
                        refreshing={refreshing}
                        onRefresh={() => this.load(true)}
                        colors={[C.primary]}
                        tintColor={C.primary}
                    />
                }
            >
                <TextInput
                    style={styles.search}
                    placeholder="Search subject, sender or ref..."
                    placeholderTextColor={C.faint}
                    value={this.state.query}
                    onChangeText={(query) => this.setState({ query })}
                    autoCapitalize="none"
                    autoCorrect={false}
                />
                <Text style={styles.count}>
                    {memos.length} memo{memos.length !== 1 ? 's' : ''}
                </Text>

                {memos.length === 0 ? (
                    <Text style={styles.emptyText}>
                        {this.state.query ? 'No memos match your search.' : 'No memos addressed to you yet.'}
                    </Text>
                ) : (
                    memos.map((m, i) => (
                        <MemoCard
                            key={m.id}
                            memo={m}
                            index={i + 1}
                            me={me}
                            onPress={() => this.setState({ openMemoId: m.id })}
                        />
                    ))
                )}

                {this.state.openMemoId !== null && (
                    <MemoDetail
                        user={this.state.user}
                        memoId={this.state.openMemoId}
                        me={me}
                        onClose={() => this.setState({ openMemoId: null })}
                    />
                )}
            </ScrollView>
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
    content: { padding: 16, paddingBottom: 40 },
    center: { flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: C.bg, padding: 24 },

    errorText: { color: C.danger, textAlign: 'center', marginBottom: 16, fontSize: 15 },
    retryBtn: { backgroundColor: C.primary, paddingHorizontal: 24, paddingVertical: 10, borderRadius: 8, alignSelf: 'center' },
    retryText: { color: '#fff', fontWeight: '600' },

    search: {
        backgroundColor: C.surface,
        borderWidth: 1,
        borderColor: C.border,
        borderRadius: 12,
        paddingHorizontal: 16,
        paddingVertical: 12,
        fontSize: 15,
        color: C.text,
        marginBottom: 12,
        ...CARD_SHADOW,
    },
    count: { fontSize: 12, fontWeight: '600', color: C.faint, letterSpacing: 0.4, marginBottom: 10, marginLeft: 2, textTransform: 'uppercase' },
    emptyText: { color: C.muted, fontSize: 14, textAlign: 'center', paddingVertical: 40 },

    card: {
        backgroundColor: C.surface,
        borderRadius: 14,
        padding: 16,
        marginBottom: 12,
        ...CARD_SHADOW,
    },
    cardHead: { flexDirection: 'row', alignItems: 'flex-start' },
    headText: { flex: 1, marginLeft: 12 },
    subject: { fontSize: 16, fontWeight: '700', color: C.text, lineHeight: 21 },
    fromRow: { flexDirection: 'row', alignItems: 'center', marginTop: 4 },
    fromLabel: { fontSize: 10.5, fontWeight: '800', color: C.faint, letterSpacing: 0.8, marginRight: 6 },
    from: { flex: 1, fontSize: 13, color: C.muted },
    index: { fontSize: 12, fontWeight: '700', color: C.faint, letterSpacing: 0.3, marginLeft: 8 },

    divider: { height: 1, backgroundColor: C.border, marginVertical: 12 },

    toRow: { flexDirection: 'row', alignItems: 'flex-start' },
    toLabel: { fontSize: 10.5, fontWeight: '800', color: C.faint, letterSpacing: 0.8, width: 26, marginTop: 3 },
    toValue: { flex: 1, fontSize: 13.5, color: C.text, lineHeight: 20 },
    toMe: { color: '#60a5fa', fontWeight: '600' },
    broadcastPill: {
        backgroundColor: C.primarySoft,
        borderRadius: 20,
        paddingHorizontal: 10,
        paddingVertical: 3,
    },
    broadcastText: { fontSize: 12, fontWeight: '700', color: C.primary },

    footer: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 12 },
    date: { fontSize: 12, color: C.faint, fontWeight: '600' },
    ref: { fontSize: 11, color: C.faint, marginLeft: 10, flexShrink: 1 },

    /* detail */
    detailScreen: { flex: 1, backgroundColor: C.bg },
    detailHeader: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        paddingHorizontal: 16,
        paddingTop: Platform.OS === 'ios' ? 52 : 14,
        paddingBottom: 14,
        backgroundColor: C.surface,
        borderBottomWidth: 1,
        borderBottomColor: C.border,
    },
    detailTitle: { fontSize: 16, fontWeight: '800', color: C.text },
    detailClose: { fontSize: 15, color: C.muted, fontWeight: '600', width: 46 },
    detailCenter: { flex: 1, justifyContent: 'center', alignItems: 'center', padding: 24 },
    detailContent: { padding: 16, paddingBottom: 48 },

    detailSubject: { fontSize: 20, fontWeight: '800', color: C.text, lineHeight: 27, marginBottom: 14 },
    detailMeta: {
        backgroundColor: C.surface,
        borderRadius: 12,
        padding: 14,
        marginBottom: 16,
        ...CARD_SHADOW,
    },
    detailRow: { flexDirection: 'row', alignItems: 'flex-start', marginBottom: 8 },
    detailLabel: {
        fontSize: 10.5,
        fontWeight: '800',
        color: C.faint,
        letterSpacing: 0.8,
        width: 46,
        marginTop: 3,
    },
    detailValue: { fontSize: 13.5, color: C.text, lineHeight: 20 },
    detailContentText: { fontSize: 15, color: C.text, lineHeight: 24 },

    attachmentBlock: { marginTop: 24 },
    attachmentHeading: {
        fontSize: 12,
        fontWeight: '800',
        color: C.muted,
        letterSpacing: 0.5,
        textTransform: 'uppercase',
        marginBottom: 10,
    },
    attachment: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: C.surface,
        borderRadius: 12,
        padding: 10,
        marginBottom: 8,
        ...CARD_SHADOW,
    },
    thumb: { width: 52, height: 52, borderRadius: 8, marginRight: 12, backgroundColor: C.border },
    thumbFallback: {
        width: 52,
        height: 52,
        borderRadius: 8,
        marginRight: 12,
        backgroundColor: C.primarySoft,
        alignItems: 'center',
        justifyContent: 'center',
    },
    thumbIcon: { fontSize: 20 },
    attachmentName: { fontSize: 14, fontWeight: '600', color: C.text },
    attachmentHint: { fontSize: 11.5, color: C.faint, marginTop: 3 },

    mono: {
        width: 44,
        height: 44,
        borderRadius: 12,
        alignItems: 'center',
        justifyContent: 'center',
    },
    monoText: { color: '#fff', fontWeight: '800', fontSize: 15, letterSpacing: 0.5 },
});