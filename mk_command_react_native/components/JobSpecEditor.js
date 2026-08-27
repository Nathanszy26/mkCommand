import React, { Component } from 'react';
import {
    View,
    Text,
    ScrollView,
    StyleSheet,
    TextInput,
    TouchableOpacity,
    ActivityIndicator,
    KeyboardAvoidingView,
    Keyboard,
    Platform,
    Modal,
    findNodeHandle,
} from 'react-native';
import { fetchJobSpecs, submitJobSpecVersion } from '../services/jobSpecApi';
import { fetchAppListing } from '../services/appApi';
 
// Edits a staff member's job spec and submits the whole list as one new version.
// Seeds itself from the current active list (so a superior edits what's really
// there). Carried-over rows keep their source_job_description_id; new rows are
// null. On submit it reports back the resulting status ('approved' | 'pending').
//
// Props: { person, compId, id, isGw, submitter, submitterCompId, onCancel, onSubmitted }
 
const C = {
    bg: '#f1f5f9',
    surface: '#ffffff',
    primary: '#2563eb',
    success: '#059669',
    text: '#1e293b',
    muted: '#64748b',
    border: '#e2e8f0',
    danger: '#dc2626',
};
 
const hit = { top: 8, bottom: 8, left: 8, right: 8 };
 
let _seq = 0;
const newKey = () => 'row-' + (++_seq);
 
export default class JobSpecEditor extends Component {
    constructor(props) {
        super(props);
        this.state = {
            loadingInitial: true,
            submitting: false,
            error: null,
            items: [],
            kbHeight: 0,
            appList: [],        // apps selectable in the picker (target's assigned apps)
            appMap: {},         // application_id -> { application_title, application_section }
            pickerKey: null,    // row key whose picker is open, or null
        };
        this.scrollRef = React.createRef();
        this.inputRefs = {};
    }
 
    componentDidMount() {
        this.seed();
        this.loadApps();
        // Keyboard height drives extra scroll room so a focused row can clear it,
        // regardless of whether the Android window itself resizes.
        const showEvt = Platform.OS === 'ios' ? 'keyboardWillShow' : 'keyboardDidShow';
        const hideEvt = Platform.OS === 'ios' ? 'keyboardWillHide' : 'keyboardDidHide';
        this._kbShow = Keyboard.addListener(showEvt, this.onKbShow);
        this._kbHide = Keyboard.addListener(hideEvt, this.onKbHide);
    }
 
    componentWillUnmount() {
        this._kbShow && this._kbShow.remove();
        this._kbHide && this._kbHide.remove();
    }
 
    onKbShow = (e) => {
        const h = (e && e.endCoordinates && e.endCoordinates.height) || 0;
        this.setState({ kbHeight: h });
        if (this.focusedKey) {
            this.ensureVisible(this.focusedKey);
        }
    };
 
    onKbHide = () => {
        this.setState({ kbHeight: 0 });
    };
 
    // Scroll the focused input above the keyboard using RN's own routine.
    ensureVisible = (key) => {
        const node = this.inputRefs[key];
        const sv = this.scrollRef.current;
        if (!node || !sv || !sv.getScrollResponder) {
            return;
        }
        const responder = sv.getScrollResponder();
        const handle = findNodeHandle(node);
        if (responder && handle && responder.scrollResponderScrollNativeHandleToKeyboard) {
            // 120px of clearance above the keyboard.
            responder.scrollResponderScrollNativeHandleToKeyboard(handle, 120, true);
        }
    };
 
    onFocusRow = (key) => {
        this.focusedKey = key;
        // Let the keyboard frame settle first (esp. the first focus).
        setTimeout(() => this.ensureVisible(key), 60);
    };
 
    // Command: load the current list and turn it into editable rows.
    seed = async () => {
        this.setState({ loadingInitial: true, error: null });
        try {
            const data = await fetchJobSpecs({
                person: this.props.person,
                compId: this.props.compId,
                id: this.props.id,
                isGw: this.props.isGw,
            });
            const seededMap = {};
            const items = (data.job_specs || []).map((s) => {
                const apps = Array.isArray(s.apps) ? s.apps : [];
                apps.forEach((a) => {
                    seededMap[a.application_id] = {
                        application_title: a.application_title,
                        application_section: a.application_section,
                    };
                });
                return {
                    key: newKey(),
                    task: s.task || '',
                    source_job_description_id: s.job_description_id != null ? s.job_description_id : null,
                    appIds: apps.map((a) => a.application_id),
                };
            });
            // Merge seeded titles so previously-tagged apps render even if the target
            // is no longer assigned to them (picker list may not include them).
            this.setState((prev) => ({
                items: items,
                loadingInitial: false,
                appMap: { ...seededMap, ...prev.appMap },
            }));
        } catch (e) {
            this.setState({ error: e.message, loadingInitial: false });
        }
    };
 
    // Load the target's assigned apps for the picker. Non-fatal: a failure just
    // leaves the picker empty; task editing still works.
    loadApps = async () => {
        try {
            const apps = await fetchAppListing({
                person: this.props.person,
                compId: this.props.compId,
            });
            const map = {};
            apps.forEach((a) => {
                map[a.application_id] = {
                    application_title: a.application_title,
                    application_section: a.application_section,
                };
            });
            this.setState((prev) => ({ appList: apps, appMap: { ...prev.appMap, ...map } }));
        } catch (e) {
            // keep editor usable without apps
        }
    };
 
    openPicker = (key) => { this.setState({ pickerKey: key }); };
    closePicker = () => { this.setState({ pickerKey: null }); };
 
    toggleApp = (key, appId) => {
        this.setState((prev) => ({
            items: prev.items.map((it) => {
                if (it.key !== key) return it;
                const has = it.appIds.indexOf(appId) !== -1;
                return {
                    ...it,
                    appIds: has ? it.appIds.filter((x) => x !== appId) : it.appIds.concat([appId]),
                };
            }),
        }));
    };
 
    updateTask = (key, text) => {
        this.setState((prev) => ({
            items: prev.items.map((it) => (it.key === key ? { ...it, task: text } : it)),
        }));
    };
 
    addRow = () => {
        this.setState((prev) => ({
            items: prev.items.concat([{ key: newKey(), task: '', source_job_description_id: null, appIds: [] }]),
        }));
    };
 
    removeRow = (key) => {
        this.setState((prev) => ({ items: prev.items.filter((it) => it.key !== key) }));
    };
 
    // dir = -1 (up) or +1 (down). Returning null from the updater is a no-op.
    move = (index, dir) => {
        this.setState((prev) => {
            const j = index + dir;
            if (j < 0 || j >= prev.items.length) {
                return null;
            }
            const items = prev.items.slice();
            const tmp = items[index];
            items[index] = items[j];
            items[j] = tmp;
            return { items: items };
        });
    };
 
    // Command: build the snapshot payload and submit it.
    submit = async () => {
        const cleaned = this.state.items
            .map((it) => ({
                task: (it.task || '').trim(),
                source_job_description_id: it.source_job_description_id,
                apps: Array.isArray(it.appIds) ? it.appIds : [],
            }))
            .filter((it) => it.task !== '');
 
        if (cleaned.length === 0) {
            this.setState({ error: 'Add at least one task before submitting.' });
            return;
        }
 
        const payload = cleaned.map((it, i) => ({
            task: it.task,
            source_job_description_id: it.source_job_description_id,
            sort_order: i,
            apps: it.apps,
        }));
 
        this.setState({ submitting: true, error: null });
        try {
            const res = await submitJobSpecVersion({
                person: this.props.person,
                compId: this.props.compId,
                isGw: this.props.isGw,
                submitter: this.props.submitter,
                submitterCompId: this.props.submitterCompId,
                items: payload,
            });
            this.setState({ submitting: false });
            this.props.onSubmitted && this.props.onSubmitted(res.status);
        } catch (e) {
            this.setState({ submitting: false, error: e.message });
        }
    };
 
    render() {
        const { loadingInitial, submitting, error, items } = this.state;
 
        if (loadingInitial) {
            return (
                <View style={styles.center}>
                    <ActivityIndicator size="large" color={C.primary} />
                </View>
            );
        }
 
        return (
            <KeyboardAvoidingView
                style={styles.screen}
                behavior={Platform.OS === 'ios' ? 'padding' : undefined}
            >
                <ScrollView
                    ref={this.scrollRef}
                    style={styles.scroll}
                    contentContainerStyle={[styles.content, { paddingBottom: 24 + this.state.kbHeight }]}
                    keyboardShouldPersistTaps="handled"
                    keyboardDismissMode="interactive"
                >
                    {error ? <Text style={styles.error}>{error}</Text> : null}
 
                    {items.map((it, index) => (
                        <View key={it.key} style={styles.row}>
                            <Text style={styles.num}>{index + 1}</Text>
                            <View style={styles.rowBody}>
                                <TextInput
                                    ref={(r) => { this.inputRefs[it.key] = r; }}
                                    style={styles.input}
                                    value={it.task}
                                    onChangeText={(t) => this.updateTask(it.key, t)}
                                    onFocus={() => this.onFocusRow(it.key)}
                                    placeholder={'Describe the task\u2026'}
                                    placeholderTextColor={C.muted}
                                    multiline
                                />
                                <View style={styles.appsBar}>
                                    {it.appIds.map((id) => (
                                        <View key={id} style={styles.chip}>
                                            <Text style={styles.chipText} numberOfLines={1}>
                                                {(this.state.appMap[id] && this.state.appMap[id].application_title) || ('App ' + id)}
                                            </Text>
                                            <TouchableOpacity onPress={() => this.toggleApp(it.key, id)} hitSlop={hit}>
                                                <Text style={styles.chipX}>{'\u2715'}</Text>
                                            </TouchableOpacity>
                                        </View>
                                    ))}
                                    <TouchableOpacity
                                        style={styles.appAddBtn}
                                        onPress={() => this.openPicker(it.key)}
                                        activeOpacity={0.7}
                                    >
                                        <Text style={styles.appAddText}>
                                            {it.appIds.length ? '＋ Apps' : '＋ Link apps'}
                                        </Text>
                                    </TouchableOpacity>
                                </View>
                            </View>
                            <View style={styles.rowActions}>
                                <TouchableOpacity
                                    onPress={() => this.move(index, -1)}
                                    disabled={index === 0}
                                    hitSlop={hit}
                                >
                                    <Text style={[styles.moveBtn, index === 0 && styles.moveDisabled]}>{'\u25B2'}</Text>
                                </TouchableOpacity>
                                <TouchableOpacity
                                    onPress={() => this.move(index, 1)}
                                    disabled={index === items.length - 1}
                                    hitSlop={hit}
                                >
                                    <Text style={[styles.moveBtn, index === items.length - 1 && styles.moveDisabled]}>
                                        {'\u25BC'}
                                    </Text>
                                </TouchableOpacity>
                                <TouchableOpacity onPress={() => this.removeRow(it.key)} hitSlop={hit}>
                                    <Text style={styles.removeBtn}>{'\u2715'}</Text>
                                </TouchableOpacity>
                            </View>
                        </View>
                    ))}
 
                    <TouchableOpacity style={styles.addBtn} onPress={this.addRow} activeOpacity={0.7}>
                        <Text style={styles.addBtnText}>{'\uFF0B'} Add task</Text>
                    </TouchableOpacity>
                </ScrollView>
 
                <View style={styles.footer}>
                    <TouchableOpacity
                        style={styles.cancelBtn}
                        onPress={this.props.onCancel}
                        disabled={submitting}
                        activeOpacity={0.7}
                    >
                        <Text style={styles.cancelText}>Cancel</Text>
                    </TouchableOpacity>
                    <TouchableOpacity
                        style={[styles.submitBtn, submitting && styles.submitBtnDisabled]}
                        onPress={this.submit}
                        disabled={submitting}
                        activeOpacity={0.7}
                    >
                        {submitting ? (
                            <ActivityIndicator color="#fff" />
                        ) : (
                            <Text style={styles.submitText}>Submit</Text>
                        )}
                    </TouchableOpacity>
                </View>
 
                {this.renderPicker()}
            </KeyboardAvoidingView>
        );
    }
 
    renderPicker() {
        const { pickerKey, items, appList } = this.state;
        if (pickerKey === null) {
            return null;
        }
        const row = items.find((it) => it.key === pickerKey);
        const selected = row ? row.appIds : [];
 
        return (
            <Modal
                visible
                transparent
                animationType="slide"
                onRequestClose={this.closePicker}
            >
                <View style={styles.modalBackdrop}>
                    <View style={styles.modalCard}>
                        <View style={styles.modalHeader}>
                            <Text style={styles.modalTitle}>Link apps to this task</Text>
                            <TouchableOpacity onPress={this.closePicker} hitSlop={hit}>
                                <Text style={styles.modalDone}>Done</Text>
                            </TouchableOpacity>
                        </View>
 
                        {appList.length === 0 ? (
                            <Text style={styles.modalEmpty}>No apps assigned to this staff.</Text>
                        ) : (
                            <ScrollView style={styles.modalList}>
                                {appList.map((a) => {
                                    const on = selected.indexOf(a.application_id) !== -1;
                                    return (
                                        <TouchableOpacity
                                            key={a.application_id}
                                            style={styles.pickRow}
                                            activeOpacity={0.7}
                                            onPress={() => this.toggleApp(pickerKey, a.application_id)}
                                        >
                                            <View style={[styles.checkbox, on && styles.checkboxOn]}>
                                                {on && <Text style={styles.checkboxMark}>{'\u2713'}</Text>}
                                            </View>
                                            <View style={styles.pickInfo}>
                                                <Text style={styles.pickTitle} numberOfLines={1}>{a.application_title}</Text>
                                                <Text style={styles.pickSection} numberOfLines={1}>{a.application_section}</Text>
                                            </View>
                                        </TouchableOpacity>
                                    );
                                })}
                            </ScrollView>
                        )}
                    </View>
                </View>
            </Modal>
        );
    }
}
 
const styles = StyleSheet.create({
    screen: { flex: 1, backgroundColor: C.bg },
    scroll: { flex: 1 },
    content: { padding: 14 },
    center: { flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: C.bg, padding: 24 },
 
    error: { color: C.danger, fontSize: 13, marginBottom: 10, textAlign: 'center' },
 
    row: {
        flexDirection: 'row',
        alignItems: 'flex-start',
        backgroundColor: C.surface,
        borderWidth: 1,
        borderColor: C.border,
        borderRadius: 10,
        padding: 10,
        marginBottom: 8,
    },
    num: { width: 22, textAlign: 'center', fontSize: 13, fontWeight: '700', color: C.muted, marginTop: 9, marginRight: 4 },
    rowBody: { flex: 1 },
    input: {
        alignSelf: 'stretch',
        fontSize: 14.5,
        color: C.text,
        lineHeight: 20,
        paddingVertical: 6,
        paddingHorizontal: 8,
        minHeight: 38,
        textAlignVertical: 'top',
    },
    rowActions: { alignItems: 'center', paddingLeft: 4, paddingTop: 4 },
 
    appsBar: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', paddingHorizontal: 8, marginTop: 2 },
    chip: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: 'rgba(37, 99, 235, 0.08)',
        borderWidth: 1,
        borderColor: 'rgba(37, 99, 235, 0.35)',
        borderRadius: 14,
        paddingLeft: 10,
        paddingRight: 6,
        paddingVertical: 3,
        marginRight: 6,
        marginBottom: 6,
        maxWidth: '100%',
    },
    chipText: { color: C.primary, fontSize: 12, fontWeight: '600', maxWidth: 150 },
    chipX: { color: C.primary, fontSize: 12, fontWeight: '700', marginLeft: 6, paddingHorizontal: 2 },
    appAddBtn: {
        borderWidth: 1,
        borderColor: C.primary,
        borderStyle: 'dashed',
        borderRadius: 14,
        paddingHorizontal: 10,
        paddingVertical: 3,
        marginBottom: 6,
    },
    appAddText: { color: C.primary, fontSize: 12, fontWeight: '700' },
 
    modalBackdrop: { flex: 1, backgroundColor: 'rgba(15, 23, 42, 0.45)', justifyContent: 'flex-end' },
    modalCard: {
        backgroundColor: C.surface,
        borderTopLeftRadius: 16,
        borderTopRightRadius: 16,
        paddingHorizontal: 16,
        paddingTop: 14,
        paddingBottom: 20,
        maxHeight: '75%',
    },
    modalHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 },
    modalTitle: { fontSize: 16, fontWeight: '700', color: C.text },
    modalDone: { fontSize: 15, fontWeight: '700', color: C.primary },
    modalEmpty: { color: C.muted, fontSize: 13, textAlign: 'center', paddingVertical: 24 },
    modalList: { marginTop: 2 },
    pickRow: {
        flexDirection: 'row',
        alignItems: 'center',
        paddingVertical: 10,
        borderBottomWidth: 1,
        borderBottomColor: C.border,
    },
    checkbox: {
        width: 22,
        height: 22,
        borderRadius: 6,
        borderWidth: 2,
        borderColor: C.border,
        alignItems: 'center',
        justifyContent: 'center',
        marginRight: 12,
    },
    checkboxOn: { backgroundColor: C.primary, borderColor: C.primary },
    checkboxMark: { color: '#fff', fontSize: 14, fontWeight: '800' },
    pickInfo: { flex: 1, minWidth: 0 },
    pickTitle: { fontSize: 14.5, fontWeight: '600', color: C.text },
    pickSection: { fontSize: 12, color: C.muted, marginTop: 1 },
    moveBtn: { fontSize: 15, color: C.primary, paddingVertical: 2, paddingHorizontal: 4 },
    moveDisabled: { color: C.border },
    removeBtn: { fontSize: 15, color: C.danger, paddingVertical: 2, paddingHorizontal: 4, marginTop: 2 },
 
    addBtn: {
        borderWidth: 1,
        borderColor: C.primary,
        borderStyle: 'dashed',
        borderRadius: 10,
        paddingVertical: 12,
        alignItems: 'center',
        marginTop: 2,
    },
    addBtnText: { color: C.primary, fontSize: 14, fontWeight: '700' },
 
    footer: {
        flexDirection: 'row',
        padding: 12,
        backgroundColor: C.surface,
        borderTopWidth: 1,
        borderTopColor: C.border,
    },
    cancelBtn: {
        flex: 1,
        paddingVertical: 12,
        borderRadius: 8,
        borderWidth: 1,
        borderColor: C.border,
        alignItems: 'center',
        marginRight: 8,
    },
    cancelText: { color: C.muted, fontSize: 15, fontWeight: '600' },
    submitBtn: {
        flex: 2,
        paddingVertical: 12,
        borderRadius: 8,
        backgroundColor: C.primary,
        alignItems: 'center',
        justifyContent: 'center',
    },
    submitBtnDisabled: { opacity: 0.6 },
    submitText: { color: '#fff', fontSize: 15, fontWeight: '700' },
});