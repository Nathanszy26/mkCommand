import React, { Component } from 'react';
import {
    View,
    Text,
    ScrollView,
    StyleSheet,
    ActivityIndicator,
    TouchableOpacity,
    RefreshControl,
    TextInput,
    Linking,
} from 'react-native';
import store from 'react-native-simple-store';

// Same backend the web App Tracker uses. Mobile requests add ?mobile=1 and are
// authenticated by person/comp_id (validated against `users` server-side), so the
// app-listing + materials logic is never duplicated — it's the exact same endpoints.
const API_BASE =
    'https://globportal.com/accounts/mkPortal/app_implementation/app_implementation.php';

const C = {
    bg: '#f1f5f9',
    surface: '#ffffff',
    primary: '#2563eb',
    primarySoft: 'rgba(37, 99, 235, 0.08)',
    success: '#059669',
    text: '#1e293b',
    muted: '#64748b',
    border: '#e2e8f0',
    danger: '#dc2626',
};

const titleCase = (value) =>
    (value || '').toLowerCase().replace(/\b\w/g, (c) => c.toUpperCase());

// First 1-2 word initials, e.g. "Leave System" -> "LS" (same pattern as Staff.js avatars).
const initials = (name) =>
    titleCase(name)
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0])
        .join('')
        .toUpperCase() || '#';

// Stable colour per app name so tiles read as distinct "app icons", not a wall of blue.
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

const fmtSize = (bytes) => {
    bytes = +bytes || 0;
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
};

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

const fileGlyph = (name) => {
    const ext = (name || '').split('.').pop().toLowerCase();
    if (ext === 'pdf') return 'PDF';
    if (ext === 'doc' || ext === 'docx') return 'DOC';
    if (ext === 'xls' || ext === 'xlsx' || ext === 'csv') return 'XLS';
    if (ext === 'ppt' || ext === 'pptx') return 'PPT';
    if (['png', 'jpg', 'jpeg', 'gif', 'webp'].indexOf(ext) !== -1) return 'IMG';
    if (ext === 'zip') return 'ZIP';
    if (ext === 'mp4') return 'VID';
    return (ext || 'FILE').substring(0, 4).toUpperCase();
};

/* --- presentational pieces ------------------------------------------------ */

const Empty = ({ text }) => <Text style={styles.emptyText}>{text}</Text>;

const AppCard = ({ index, app, onOpenMaterials }) => (
    <View style={styles.appCard}>
        <Text style={styles.appNum}>{index}</Text>
        <View style={[styles.appIcon, { backgroundColor: colorFor(app.application_title) }]}>
            <Text style={styles.appIconText}>{initials(app.application_title)}</Text>
        </View>
        <View style={styles.appInfo}>
            <Text style={styles.appTitle} numberOfLines={1}>
                {app.application_title}
            </Text>
            <Text style={styles.appSection} numberOfLines={1}>
                {app.application_section || 'Other'}
            </Text>
        </View>
        <TouchableOpacity style={styles.matBtn} activeOpacity={0.7} onPress={() => onOpenMaterials(app)}>
            <Text style={styles.matBtnText}>Materials</Text>
        </TouchableOpacity>
    </View>
);

const MaterialRow = ({ item, onOpen }) => (
    <TouchableOpacity style={styles.matRow} activeOpacity={0.7} onPress={() => onOpen(item.id)}>
        <View style={styles.matBadge}>
            <Text style={styles.matBadgeText}>{fileGlyph(item.file_name)}</Text>
        </View>
        <View style={styles.matInfo}>
            <Text style={styles.matTitle} numberOfLines={2}>
                {item.title}
            </Text>
            {!!item.description && (
                <Text style={styles.matDesc} numberOfLines={3}>
                    {item.description}
                </Text>
            )}
            <Text style={styles.matMeta} numberOfLines={1}>
                {item.file_name} {'\u00b7'} {fmtSize(item.file_size)}
            </Text>
            <Text style={styles.matAdded} numberOfLines={1}>
                Added {fmtDate(item.created_at)} {'\u00b7'} {titleCase(item.uploaded_by_name)}
            </Text>
        </View>
        <Text style={styles.openHint}>{'\u2913'}</Text>
    </TouchableOpacity>
);

/* --- screen --------------------------------------------------------------- */

export default class App extends Component {
    constructor(props) {
        super(props);
        this.state = {
            loading: true,
            refreshing: false,
            error: null,
            user: null,
            apps: [],
            query: '',
            view: 'list', // 'list' | 'materials'
            activeApp: null,
            materials: null,
            matLoading: false,
            matError: null,
        };
    }

    componentDidMount() {
        this.init();
    }

    /** Command: resolve the stored session, then load the app list. */
    init = async () => {
        try {
            const u = await store.get('AppUser');
            const comp = u && (u.comid);
            if (!u || !u.person || !comp) {
                throw new Error('Missing user session. Please log in again.');
            }
            this.setState({ user: { person: u.person, comp_id: comp } }, () => this.loadApps());
        } catch (e) {
            this.setState({ loading: false, error: e.message });
        }
    };

    /** Query: build a fully-qualified mobile API url. */
    apiUrl = (action, params = {}) => {
        const { user } = this.state;
        const q = { mobile: 1, person: user.person, comp_id: user.comp_id, action, ...params };
        const qs = Object.keys(q)
            .map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(q[k]))
            .join('&');
        return `${API_BASE}?${qs}`;
    };

    loadApps = async (refreshing = false) => {
        this.setState(refreshing ? { refreshing: true, error: null } : { loading: true, error: null });
        try {
            const res = await fetch(this.apiUrl('get_my_app_listing'));
            const data = await res.json();
            if (!data.success) throw new Error(data.message || 'Failed to load applications.');
            this.setState({ apps: data.apps || [], loading: false, refreshing: false });
        } catch (e) {
            this.setState({ loading: false, refreshing: false, error: e.message });
        }
    };

    openMaterials = (app) => {
        this.setState(
            { view: 'materials', activeApp: app, materials: null, matError: null, matLoading: true },
            async () => {
                try {
                    const res = await fetch(this.apiUrl('get_reference_materials', { app_id: app.application_id }));
                    const data = await res.json();
                    if (!data.success) throw new Error(data.message || 'Failed to load materials.');
                    this.setState({ materials: data.materials || [], matLoading: false });
                } catch (e) {
                    this.setState({ matError: e.message, matLoading: false });
                }
            }
        );
    };

    backToList = () => this.setState({ view: 'list', activeApp: null, materials: null, matError: null });

    openFile = (id) => {
        const url = this.apiUrl('download_reference_material', { id });
        Linking.openURL(url).catch(() => {});
    };

    /* --- derived ---------------------------------------------------------- */

    filteredApps() {
        const q = this.state.query.trim().toLowerCase();
        const apps = this.state.apps;
        if (!q) return apps;
        return apps.filter(
            (a) =>
                (a.application_title || '').toLowerCase().indexOf(q) !== -1 ||
                (a.application_section || '').toLowerCase().indexOf(q) !== -1
        );
    }

    groupBySection(apps) {
        const groups = {};
        apps.forEach((a) => {
            const sec = a.application_section || 'Other';
            (groups[sec] = groups[sec] || []).push(a);
        });
        return Object.keys(groups)
            .sort()
            .map((sec) => ({ section: sec, apps: groups[sec] }));
    }

    /* --- renders ---------------------------------------------------------- */

    renderList() {
        const apps = this.filteredApps();
        const groups = this.groupBySection(apps);
        return (
            <ScrollView
                style={styles.screen}
                contentContainerStyle={styles.content}
                refreshControl={
                    <RefreshControl
                        refreshing={this.state.refreshing}
                        onRefresh={() => this.loadApps(true)}
                        colors={[C.primary]}
                        tintColor={C.primary}
                    />
                }
            >
                <TextInput
                    style={styles.search}
                    placeholder="Search applications..."
                    placeholderTextColor={C.muted}
                    value={this.state.query}
                    onChangeText={(query) => this.setState({ query })}
                    autoCapitalize="none"
                    autoCorrect={false}
                />
                <Text style={styles.count}>
                    {apps.length} application{apps.length !== 1 ? 's' : ''}
                </Text>

                {apps.length === 0 ? (
                    <Empty text="No applications assigned to you." />
                ) : (
                    (() => {
                        let n = 0; // continuous numbering across all sections
                        return groups.map((g) => (
                            <View key={g.section}>
                                <Text style={styles.groupTitle}>{g.section.toUpperCase()}</Text>
                                {g.apps.map((a) => {
                                    n += 1;
                                    return (
                                        <AppCard
                                            key={a.application_id}
                                            index={n}
                                            app={a}
                                            onOpenMaterials={this.openMaterials}
                                        />
                                    );
                                })}
                            </View>
                        ));
                    })()
                )}
            </ScrollView>
        );
    }

    renderMaterials() {
        const { activeApp, materials, matLoading, matError } = this.state;
        return (
            <ScrollView style={styles.screen} contentContainerStyle={styles.content}>
                <TouchableOpacity style={styles.backRow} activeOpacity={0.7} onPress={this.backToList}>
                    <Text style={styles.backText}>{'\u2190'}  Back to Apps</Text>
                </TouchableOpacity>

                <View style={styles.matHeader}>
                    <Text style={styles.matHeaderTitle}>{activeApp.application_title}</Text>
                    <Text style={styles.matHeaderSub}>Reference Materials</Text>
                </View>

                {matLoading ? (
                    <ActivityIndicator color={C.primary} style={{ marginTop: 30 }} />
                ) : matError ? (
                    <View>
                        <Text style={styles.errorText}>{matError}</Text>
                        <TouchableOpacity style={styles.retryBtn} onPress={() => this.openMaterials(activeApp)}>
                            <Text style={styles.retryText}>Retry</Text>
                        </TouchableOpacity>
                    </View>
                ) : !materials || materials.length === 0 ? (
                    <Empty text="No reference materials yet." />
                ) : (
                    materials.map((m) => <MaterialRow key={m.id} item={m} onOpen={this.openFile} />)
                )}
            </ScrollView>
        );
    }

    render() {
        const { loading, error, view } = this.state;

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
                    <TouchableOpacity style={styles.retryBtn} onPress={() => this.loadApps()}>
                        <Text style={styles.retryText}>Retry</Text>
                    </TouchableOpacity>
                </View>
            );
        }

        return view === 'materials' ? this.renderMaterials() : this.renderList();
    }
}

const styles = StyleSheet.create({
    screen: { flex: 1, backgroundColor: C.bg },
    content: { padding: 14, paddingBottom: 40 },
    center: { flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: C.bg, padding: 24 },

    errorText: { color: C.danger, textAlign: 'center', marginBottom: 16, fontSize: 15 },
    retryBtn: { backgroundColor: C.primary, paddingHorizontal: 24, paddingVertical: 10, borderRadius: 8, alignSelf: 'center' },
    retryText: { color: '#fff', fontWeight: '600' },

    search: {
        backgroundColor: C.surface,
        borderWidth: 1,
        borderColor: C.border,
        borderRadius: 10,
        paddingHorizontal: 14,
        paddingVertical: 10,
        fontSize: 15,
        color: C.text,
        marginBottom: 10,
    },
    count: { fontSize: 12, color: C.muted, marginBottom: 6, marginLeft: 2 },
    groupTitle: { fontSize: 12, fontWeight: '700', color: C.muted, letterSpacing: 0.5, marginTop: 10, marginBottom: 6, marginLeft: 2 },

    appCard: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: C.surface,
        borderWidth: 1,
        borderColor: C.border,
        borderRadius: 10,
        padding: 12,
        marginBottom: 8,
    },
    appNum: { width: 20, textAlign: 'center', fontSize: 13, fontWeight: '700', color: C.muted, marginRight: 6 },
    appIcon: {
        width: 44,
        height: 44,
        borderRadius: 10,
        backgroundColor: C.primary,
        alignItems: 'center',
        justifyContent: 'center',
        marginRight: 12,
    },
    appIconText: { fontSize: 16, fontWeight: '800', color: '#fff', letterSpacing: 0.5 },
    appInfo: { flex: 1, minWidth: 0 },
    appTitle: { fontSize: 15.5, fontWeight: '700', color: C.text },
    appSection: { fontSize: 12.5, color: C.muted, marginTop: 1 },

    matBtn: {
        backgroundColor: C.primarySoft,
        borderWidth: 1,
        borderColor: C.border,
        borderRadius: 8,
        paddingHorizontal: 12,
        paddingVertical: 8,
        marginLeft: 8,
    },
    matBtnText: { color: C.primary, fontWeight: '700', fontSize: 12.5 },

    backRow: { paddingVertical: 6, marginBottom: 6 },
    backText: { color: C.primary, fontWeight: '600', fontSize: 14.5 },

    matHeader: {
        backgroundColor: C.surface,
        borderWidth: 1,
        borderColor: C.border,
        borderRadius: 12,
        padding: 14,
        marginBottom: 12,
    },
    matHeaderTitle: { fontSize: 17, fontWeight: '700', color: C.text },
    matHeaderSub: { fontSize: 12.5, color: C.muted, marginTop: 2 },

    matRow: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: C.surface,
        borderWidth: 1,
        borderColor: C.border,
        borderRadius: 10,
        padding: 12,
        marginBottom: 8,
    },
    matBadge: {
        width: 42,
        height: 42,
        borderRadius: 8,
        backgroundColor: C.primarySoft,
        alignItems: 'center',
        justifyContent: 'center',
        marginRight: 12,
    },
    matBadgeText: { fontSize: 11, fontWeight: '800', color: C.primary },
    matInfo: { flex: 1, minWidth: 0 },
    matTitle: { fontSize: 14.5, fontWeight: '700', color: C.text },
    matDesc: { fontSize: 12.5, color: '#475569', marginTop: 2 },
    matMeta: { fontSize: 11.5, color: C.muted, marginTop: 4 },
    matAdded: { fontSize: 11.5, color: C.muted, marginTop: 1 },
    openHint: { fontSize: 20, color: C.primary, marginLeft: 8 },

    emptyText: { color: C.muted, fontSize: 13, textAlign: 'center', paddingVertical: 24 },
});