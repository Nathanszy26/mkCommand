import React, { Component } from 'react';
import {
    View,
    Text,
    Modal,
    ScrollView,
    StyleSheet,
    TextInput,
    ActivityIndicator,
    TouchableOpacity,
    KeyboardAvoidingView,
    Platform,
} from 'react-native';
import {
    pick,
    types,
    keepLocalCopy,
    errorCodes,
    isErrorWithCode,
} from '@react-native-documents/picker';
import {
    fetchPerformanceTypes,
    fetchAllStaff,
    submitMemo,
    submitPerformance,
} from '../services/staffRecordsApi';

/**
 * Issue Memo / Merit / Demerit to the staff ticked on the Staff screen.
 *
 * Mobile rendering of staff_memo_upload.php and staff_performance_upload.php.
 * Same fields, same required-ness, same Merit/Demerit -> Type dependency.
 *
 * Deliberate differences from the web forms:
 *  - Date is stamped by the server (today). The web performance form is already
 *    readonly here; doing the same for memo removes a whole class of
 *    date-format bugs and needs no native date-picker dependency.
 *  - "To" is the tick selection carried in from the Staff screen, not a search
 *    box — it is shown read-only so the issuer can see exactly who is affected.
 *  - Attachments accept documents as well as images, which is wider than the
 *    web forms' accept="image/*". They are optional: an empty list submits
 *    normally. The allowed extension list is mirrored server-side in
 *    AttachmentStore::ALLOWED_EXT — change both together.
 *
 * Uses @react-native-documents/picker (already a dependency). Note this is the
 * v10 API: the old `copyTo` option is gone, so a picked file is copied with
 * keepLocalCopy() before upload.
 *
 * Mounted only while open, so every open starts from a clean constructor —
 * no stale-field bugs between one issue and the next.
 */

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

const pad = (n) => (n < 10 ? '0' + n : String(n));

// Matches IssueRepository::RECORD_DATE_FORMAT — display only; the server stamps
// the value that is actually stored.
const todayLabel = () => {
    const d = new Date();
    return pad(d.getMonth() + 1) + '/' + pad(d.getDate()) + '/' + d.getFullYear();
};

const MAX_ATTACHMENTS = 5;   // matches $no_of_attachments on both web forms
const MAX_ATTACHMENT_MB = 10; // matches AttachmentStore::MAX_BYTES

// Mirrors AttachmentStore::ALLOWED_EXT. Checked here as well so a rejected file
// is caught before the upload rather than after it. A whitelist, never a
// blacklist — these land in a web-served directory.
const ALLOWED_EXT = [
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif',
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt',
];

const extensionOf = (name) => {
    const parts = String(name || '').split('.');
    return parts.length > 1 ? parts[parts.length - 1].toLowerCase() : '';
};

const ACTION_META = {
    memo: { title: 'Issue Memo', tone: C.primary },
    merit: { title: 'Issue Merit', tone: C.success },
    demerit: { title: 'Issue Demerit', tone: C.danger },
};

/* --- presentational -------------------------------------------------------- */

const Field = ({ label, required, hint, children }) => (
    <View style={styles.field}>
        <View style={styles.labelRow}>
            <Text style={styles.label}>{label}</Text>
            {required ? <Text style={styles.required}>*</Text> : <Text style={styles.optional}>Optional</Text>}
        </View>
        {children}
        {!!hint && <Text style={styles.hint}>{hint}</Text>}
    </View>
);

const ReadOnly = ({ value }) => (
    <View style={styles.readOnly}>
        <Text style={styles.readOnlyText}>{value}</Text>
    </View>
);

const Chips = ({ items, empty }) => {
    if (items.length === 0) {
        return <Text style={styles.chipsEmpty}>{empty}</Text>;
    }
    return (
        <View style={styles.chips}>
            {items.map((item) => (
                <View key={item.id} style={styles.chip}>
                    <Text style={styles.chipText} numberOfLines={1}>{titleCase(item.fullname)}</Text>
                </View>
            ))}
        </View>
    );
};

const AttachmentRow = ({ file, onRemove }) => (
    <View style={styles.fileRow}>
        <Text style={styles.fileIcon}>{'\uD83D\uDCCE'}</Text>
        <View style={{ flex: 1 }}>
            <Text style={styles.fileName} numberOfLines={1}>{file.name}</Text>
            {!!file.size && (
                <Text style={styles.fileSize}>{(file.size / 1048576).toFixed(1)} MB</Text>
            )}
        </View>
        <TouchableOpacity
            onPress={onRemove}
            hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}
        >
            <Text style={styles.fileRemove}>{'\u2715'}</Text>
        </TouchableOpacity>
    </View>
);

const SelectButton = ({ text, placeholder, onPress, disabled }) => (
    <TouchableOpacity
        style={[styles.input, styles.selectBtn, disabled && styles.selectBtnDisabled]}
        activeOpacity={0.7}
        onPress={onPress}
        disabled={disabled}
    >
        <Text style={[styles.selectText, !text && styles.selectPlaceholder]} numberOfLines={1}>
            {text || placeholder}
        </Text>
        <Text style={styles.selectCaret}>{'\u25BE'}</Text>
    </TouchableOpacity>
);

/**
 * One sheet for both pickers: single-select (Type) closes on tap, multi-select
 * (CC) toggles and closes on Done. Options are [{ value, label, sub }].
 *
 * `searchable` adds a filter box — needed once the CC list is the whole company
 * rather than one team. Selected entries always stay visible regardless of the
 * query, so filtering can never hide a choice the user already made and lead
 * them to think it was dropped.
 */
class SelectSheet extends Component {
    constructor(props) {
        super(props);
        this.state = { query: '' };
    }

    isOn(value) {
        const { multi, selected } = this.props;
        return multi ? selected.indexOf(value) !== -1 : selected === value;
    }

    /** Query: the options to render for the current filter. */
    visibleOptions() {
        const { options } = this.props;
        const q = this.state.query.trim().toLowerCase();
        if (!q) {
            return options;
        }
        const hit = (s) => (s || '').toLowerCase().indexOf(q) !== -1;
        return options.filter(
            (o) => this.isOn(o.value) || hit(o.label) || hit(o.sub) || hit(o.group)
        );
    }

    render() {
        const { title, searchable, multi, onSelect, onClose, loading } = this.props;
        const options = this.visibleOptions();

        return (
            <Modal visible transparent animationType="fade" onRequestClose={onClose}>
                <TouchableOpacity style={styles.sheetOverlay} activeOpacity={1} onPress={onClose}>
                    <TouchableOpacity style={styles.sheet} activeOpacity={1}>
                        <View style={styles.sheetHandle} />
                        <Text style={styles.sheetTitle}>{title}</Text>

                        {searchable && (
                            <TextInput
                                style={styles.sheetSearch}
                                value={this.state.query}
                                onChangeText={(query) => this.setState({ query })}
                                placeholder="Search name or position..."
                                placeholderTextColor={C.faint}
                                autoCapitalize="none"
                                autoCorrect={false}
                            />
                        )}

                        <ScrollView style={{ maxHeight: 340 }} keyboardShouldPersistTaps="handled">
                            {loading ? (
                                <ActivityIndicator style={{ paddingVertical: 26 }} color={C.primary} />
                            ) : options.length === 0 ? (
                                <Text style={styles.sheetEmpty}>
                                    {this.state.query ? 'No match.' : 'Nothing available.'}
                                </Text>
                            ) : (
                                options.map((o, i) => {
                                    // Company header whenever the group changes —
                                    // the web form's <optgroup>. Without it the
                                    // same person in two companies reads as a
                                    // duplicate with no way to tell them apart.
                                    const newGroup = !!o.group && (i === 0 || options[i - 1].group !== o.group);
                                    return (
                                        <View key={o.value || 'none'}>
                                            {newGroup && <Text style={styles.sheetGroup}>{o.group}</Text>}
                                            <TouchableOpacity
                                                style={styles.sheetItem}
                                                activeOpacity={0.6}
                                                onPress={() => onSelect(o.value)}
                                            >
                                                <View style={{ flex: 1 }}>
                                                    <Text
                                                        style={[
                                                            styles.sheetItemText,
                                                            this.isOn(o.value) && styles.sheetItemTextOn,
                                                        ]}
                                                    >
                                                        {o.label}
                                                    </Text>
                                                    {!!o.sub && (
                                                        <Text style={styles.sheetItemSub} numberOfLines={2}>
                                                            {o.sub}
                                                        </Text>
                                                    )}
                                                </View>
                                                {this.isOn(o.value) && (
                                                    <Text style={styles.sheetCheck}>{'\u2713'}</Text>
                                                )}
                                            </TouchableOpacity>
                                        </View>
                                    );
                                })
                            )}
                        </ScrollView>

                        {multi && (
                            <TouchableOpacity style={styles.sheetDone} activeOpacity={0.8} onPress={onClose}>
                                <Text style={styles.sheetDoneText}>Done</Text>
                            </TouchableOpacity>
                        )}
                    </TouchableOpacity>
                </TouchableOpacity>
            </Modal>
        );
    }
}

/* --- screen ---------------------------------------------------------------- */

export default class IssueActionModal extends Component {
    constructor(props) {
        super(props);
        this.state = {
            submitting: false,
            typesLoading: props.action !== 'memo',
            error: null,
            types: [],
            sheet: null,       // null | 'type' | 'cc'

            // memo
            ref: '',
            // A missing session must surface as the submit error postIssue
            // raises, not as a crash in the constructor.
            from: titleCase((props.user && props.user.fullname) || ''),
            subject: '',
            content: '',
            ccIds: [],

            // merit / demerit
            title: '',
            typeId: '',
            points: '',

            attachments: [], // [{ uri, name, type, size }] — optional

            staff: [],           // company-wide directory, for CC
            staffLoading: false,
        };
    }

    componentDidMount() {
        if (this.props.action === 'memo') {
            this.loadStaff();
        } else {
            this.loadTypes();
        }
    }

    /**
     * Command: pull the company-wide staff list for the CC picker. Fetched on
     * open rather than on first tap so the sheet is populated the moment it
     * appears; a failure only disables CC, which is optional, so it must not
     * block the rest of the form.
     */
    loadStaff = async () => {
        this.setState({ staffLoading: true });
        try {
            const staff = await fetchAllStaff(this.props.user);
            this.setState({ staff, staffLoading: false });
        } catch (e) {
            this.setState({ staffLoading: false, error: e.message });
        }
    };

    /** Command: pull the active performance types for the Type picker. */
    loadTypes = async () => {
        try {
            const types = await fetchPerformanceTypes(this.props.user);
            this.setState({ types, typesLoading: false });
        } catch (e) {
            this.setState({ typesLoading: false, error: e.message });
        }
    };

    /* --- derived (queries) ------------------------------------------------- */

    /** Only staff with a real users.id can receive a record; GW have none. */
    recipients() {
        return this.props.staff.filter((s) => s.id);
    }

    skippedCount() {
        return this.props.staff.length - this.recipients().length;
    }

    /** Types matching the chosen Merit/Demerit — the web page's renderTypes(). */
    availableTypes() {
        const wanted = this.props.action; // 'merit' | 'demerit'
        return this.state.types.filter((t) => t.merit_demerit === wanted);
    }

    selectedType() {
        const found = this.availableTypes().filter((t) => t.id === this.state.typeId);
        return found.length ? found[0] : null;
    }

    /**
     * Query: everyone who can be CC'd — the whole active directory, minus the
     * people already in the To list, since copying a recipient to themselves is
     * noise rather than a choice.
     */
    ccOptions() {
        const chosen = {};
        this.recipients().forEach((s) => { chosen[String(s.id)] = true; });

        // Label mirrors the web select: name - position - email - mobile.
        return this.state.staff
            .filter((c) => c.id && !chosen[String(c.id)])
            .map((c) => ({
                value: String(c.id),
                label: titleCase(c.fullname),
                group: c.company || '',
                sub: [c.position, c.email, c.mobile_no].filter(Boolean).join(' \u00B7 '),
            }));
    }

    /** Query: first validation failure, or null when the form is submittable. */
    validate() {
        const { action } = this.props;
        const { from, subject, content, ref, title, points } = this.state;

        if (this.recipients().length === 0) {
            return 'None of the selected staff can receive this record.';
        }
        if (action === 'memo') {
            if (!from.trim()) return 'From is required.';
            if (!subject.trim()) return 'Subject is required.';
            if (!content.trim()) return 'Content is required.';
            return null;
        }
        if (!ref.trim()) return 'Ref. is required.';
        if (!title.trim()) return 'Title is required.';
        if (points.trim() && !/^\d+$/.test(points.trim())) return 'Points must be a whole number.';
        return null;
    }

    /* --- attachments ------------------------------------------------------- */

    /**
     * Command: add files of any allowed type — images, PDFs, Office documents.
     * Oversized or disallowed files are rejected here rather than after a slow
     * upload, and the count is capped so the request cannot exceed what the
     * server will take.
     */
    pickAttachments = async () => {
        const remaining = MAX_ATTACHMENTS - this.state.attachments.length;
        if (remaining <= 0) {
            this.setState({ error: 'Up to ' + MAX_ATTACHMENTS + ' attachments.' });
            return;
        }

        try {
            const results = await pick({
                allowMultiSelection: true,
                type: [types.allFiles],
            });

            const accepted = [];
            const rejected = [];

            results.slice(0, remaining).forEach((file, i) => {
                const name = file.name || 'attachment_' + (Date.now() + i);
                const ext = extensionOf(name);

                if (ALLOWED_EXT.indexOf(ext) === -1) {
                    rejected.push(name + ' (type not allowed)');
                    return;
                }
                if (file.size && file.size > MAX_ATTACHMENT_MB * 1048576) {
                    rejected.push(name + ' (over ' + MAX_ATTACHMENT_MB + ' MB)');
                    return;
                }

                accepted.push({
                    uri: file.uri,
                    name: name,
                    type: file.type || 'application/octet-stream',
                    size: file.size || 0,
                });
            });

            // A picked uri is a scoped reference — an iOS security-scoped URL or
            // an Android SAF content:// — that is not dependably readable by the
            // time the upload runs. v10 dropped the old `copyTo` option, so take
            // the local copy explicitly. If a copy fails, the original uri is
            // kept: it often still works, and the server reports it if not.
            const picked = await this.localCopies(accepted);

            this.setState((prev) => ({
                attachments: prev.attachments.concat(picked),
                error: rejected.length ? 'Skipped: ' + rejected.join(', ') : null,
            }));
        } catch (e) {
            if (isErrorWithCode(e) && e.code === errorCodes.OPERATION_CANCELED) {
                return;
            }
            this.setState({ error: e.message || 'Could not open the file picker.' });
        }
    };

    /** Query: the same files, pointed at readable local copies where possible. */
    localCopies = async (files) => {
        if (files.length === 0) {
            return files;
        }

        const copies = await keepLocalCopy({
            files: files.map((f) => ({ uri: f.uri, fileName: f.name })),
            destination: 'cachesDirectory',
        });

        return files.map((file, i) => {
            const copy = copies[i];
            return copy && copy.status === 'success'
                ? Object.assign({}, file, { uri: copy.localUri })
                : file;
        });
    };

    removeAttachment = (index) => {
        this.setState((prev) => {
            const attachments = prev.attachments.slice();
            attachments.splice(index, 1);
            return { attachments };
        });
    };

    /* --- commands ---------------------------------------------------------- */

    pickType = (value) => {
        // Picking a type fills in its points and, when Title is still blank, its
        // title — the same shortcut the web page's Select2 gives by hand.
        const chosen = this.state.types.filter((t) => t.id === value)[0];
        this.setState((prev) => ({
            typeId: value,
            sheet: null,
            points: chosen && chosen.points ? String(chosen.points) : prev.points,
            title: prev.title || (chosen ? chosen.title : ''),
        }));
    };

    toggleCc = (value) => {
        this.setState((prev) => {
            const at = prev.ccIds.indexOf(value);
            const ccIds = prev.ccIds.slice();
            if (at === -1) { ccIds.push(value); } else { ccIds.splice(at, 1); }
            return { ccIds };
        });
    };

    submit = async () => {
        const problem = this.validate();
        if (problem) {
            this.setState({ error: problem });
            return;
        }

        const { action, user, onDone } = this.props;
        const toIds = this.recipients().map((s) => String(s.id));

        const attachments = this.state.attachments;

        this.setState({ submitting: true, error: null });
        try {
            let result;
            if (action === 'memo') {
                result = await submitMemo(user, {
                    to_ids: toIds,
                    cc_ids: this.state.ccIds,
                    ref: this.state.ref.trim(),
                    from: this.state.from.trim(),
                    subject: this.state.subject.trim(),
                    content: this.state.content.trim(),
                }, attachments);
            } else {
                result = await submitPerformance(user, {
                    to_ids: toIds,
                    ref: this.state.ref.trim(),
                    title: this.state.title.trim(),
                    merit_demerit: action === 'merit' ? 'Merit' : 'Demerit',
                    type_id: this.state.typeId,
                    points: this.state.points.trim(),
                }, attachments);
            }

            // The record is committed by this point; attachments are best-effort
            // and report their own failures. Say so instead of pretending the
            // whole submit succeeded.
            const failures = (result.attachments && result.attachments.errors) || [];
            onDone(action, toIds.length, failures);
        } catch (e) {
            this.setState({ submitting: false, error: e.message });
        }
    };

    /* --- render ------------------------------------------------------------ */

    renderMemoFields() {
        return (
            <View>
                <Field label="Ref.">
                    <TextInput
                        style={styles.input}
                        value={this.state.ref}
                        onChangeText={(ref) => this.setState({ ref })}
                        placeholder="e.g. MEMO-2026-001"
                        placeholderTextColor={C.faint}
                        autoCapitalize="none"
                        autoCorrect={false}
                    />
                </Field>

                <Field label="From" required>
                    <TextInput
                        style={styles.input}
                        value={this.state.from}
                        onChangeText={(from) => this.setState({ from })}
                        placeholder="Sender name"
                        placeholderTextColor={C.faint}
                    />
                </Field>

                <Field label="CC" hint="Any staff member can be CC'd. Tap to search by name.">
                    <SelectButton
                        text={this.state.ccIds.length ? this.state.ccIds.length + ' selected' : ''}
                        placeholder={this.state.staffLoading ? 'Loading staff...' : '- Select Staff -'}
                        onPress={() => this.setState({ sheet: 'cc' })}
                    />
                </Field>

                <Field label="Subject" required>
                    <TextInput
                        style={styles.input}
                        value={this.state.subject}
                        onChangeText={(subject) => this.setState({ subject })}
                        placeholder="Memo subject"
                        placeholderTextColor={C.faint}
                    />
                </Field>

                <Field label="Content" required>
                    <TextInput
                        style={[styles.input, styles.textarea]}
                        value={this.state.content}
                        onChangeText={(content) => this.setState({ content })}
                        placeholder="Write the memo..."
                        placeholderTextColor={C.faint}
                        multiline
                        textAlignVertical="top"
                    />
                </Field>
            </View>
        );
    }

    renderPerformanceFields() {
        const { action } = this.props;
        const { typesLoading } = this.state;
        const type = this.selectedType();
        const available = this.availableTypes();

        return (
            <View>
                <Field label="Ref." required>
                    <TextInput
                        style={styles.input}
                        value={this.state.ref}
                        onChangeText={(ref) => this.setState({ ref })}
                        placeholder={action === 'merit' ? 'e.g. MRT-2026-001' : 'e.g. DMR-2026-001'}
                        placeholderTextColor={C.faint}
                        autoCapitalize="none"
                        autoCorrect={false}
                    />
                </Field>

                <Field label="Merit/Demerit" required>
                    <ReadOnly value={action === 'merit' ? 'Merit' : 'Demerit'} />
                </Field>

                <Field
                    label="Type"
                    hint={
                        typesLoading
                            ? 'Loading types...'
                            : available.length === 0
                            ? 'No type available for ' + (action === 'merit' ? 'Merit' : 'Demerit') + '.'
                            : 'Picking a type fills in its points.'
                    }
                >
                    <SelectButton
                        text={type ? type.title : ''}
                        placeholder={typesLoading ? 'Loading...' : '- Select Type -'}
                        onPress={() => this.setState({ sheet: 'type' })}
                        disabled={typesLoading || available.length === 0}
                    />
                </Field>

                <Field label="Title" required>
                    <TextInput
                        style={styles.input}
                        value={this.state.title}
                        onChangeText={(title) => this.setState({ title })}
                        placeholder="What happened"
                        placeholderTextColor={C.faint}
                    />
                </Field>

                <Field label="Points">
                    <TextInput
                        style={styles.input}
                        value={this.state.points}
                        onChangeText={(points) => this.setState({ points })}
                        placeholder="0"
                        placeholderTextColor={C.faint}
                        keyboardType="number-pad"
                    />
                </Field>
            </View>
        );
    }

    /** Shared by both forms — the web pages carry the same field on each. */
    renderAttachmentsField() {
        const { attachments } = this.state;
        const full = attachments.length >= MAX_ATTACHMENTS;

        return (
            <Field
                label="Attachments"
                hint={'Images, PDF or Office files. Up to ' + MAX_ATTACHMENTS + ' files of ' + MAX_ATTACHMENT_MB + ' MB each.'}
            >
                {attachments.map((file, i) => (
                    <AttachmentRow
                        key={file.uri + i}
                        file={file}
                        onRemove={() => this.removeAttachment(i)}
                    />
                ))}

                <TouchableOpacity
                    style={[styles.fileBtn, full && styles.fileBtnDisabled]}
                    activeOpacity={0.7}
                    disabled={full}
                    onPress={this.pickAttachments}
                >
                    <Text style={styles.fileBtnText}>
                        {full ? MAX_ATTACHMENTS + ' attachments added' : '+ Add Attachments'}
                    </Text>
                </TouchableOpacity>
            </Field>
        );
    }

    renderSheet() {
        const { sheet, typeId, ccIds } = this.state;
        if (sheet === 'type') {
            const options = [{ value: '', label: '- No Type -' }].concat(
                this.availableTypes().map((t) => ({
                    value: t.id,
                    label: t.title,
                }))
            );
            return (
                <SelectSheet
                    title="Select Type"
                    options={options}
                    selected={typeId}
                    onSelect={this.pickType}
                    onClose={() => this.setState({ sheet: null })}
                />
            );
        }
        if (sheet === 'cc') {
            return (
                <SelectSheet
                    multi
                    searchable
                    title="CC"
                    loading={this.state.staffLoading}
                    options={this.ccOptions()}
                    selected={ccIds}
                    onSelect={this.toggleCc}
                    onClose={() => this.setState({ sheet: null })}
                />
            );
        }
        return null;
    }

    render() {
        const { action, onClose } = this.props;
        const { submitting, error } = this.state;
        const meta = ACTION_META[action];
        const recipients = this.recipients();
        const skipped = this.skippedCount();

        return (
            <Modal visible transparent={false} animationType="slide" onRequestClose={onClose}>
                <View style={styles.screen}>
                    <View style={styles.header}>
                        <TouchableOpacity onPress={onClose} disabled={submitting} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}>
                            <Text style={[styles.headerCancel, submitting && styles.headerDisabled]}>Cancel</Text>
                        </TouchableOpacity>
                        <Text style={styles.headerTitle}>{meta.title}</Text>
                        <View style={{ width: 52 }} />
                    </View>

                    <KeyboardAvoidingView
                        style={{ flex: 1 }}
                        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
                    >
                        <ScrollView
                            style={styles.body}
                            contentContainerStyle={styles.content}
                            keyboardShouldPersistTaps="handled"
                        >
                            {!!error && (
                                <View style={styles.errorBox}>
                                    <Text style={styles.errorText}>{error}</Text>
                                </View>
                            )}

                            <Field label="To" required>
                                <Chips items={recipients} empty="No eligible staff selected." />
                                {skipped > 0 && (
                                    <Text style={styles.warnText}>
                                        {skipped} general worker{skipped !== 1 ? 's' : ''} skipped — they have no staff record.
                                    </Text>
                                )}
                            </Field>

                            <Field label="Date" required hint="Stamped by the server when you submit.">
                                <ReadOnly value={todayLabel()} />
                            </Field>

                            {action === 'memo' ? this.renderMemoFields() : this.renderPerformanceFields()}

                            {this.renderAttachmentsField()}

                            <TouchableOpacity
                                style={[styles.submitBtn, { backgroundColor: meta.tone }, submitting && styles.submitBtnBusy]}
                                activeOpacity={0.8}
                                onPress={this.submit}
                                disabled={submitting}
                            >
                                {submitting ? (
                                    <ActivityIndicator color="#fff" />
                                ) : (
                                    <Text style={styles.submitText}>
                                        Submit to {recipients.length} staff
                                    </Text>
                                )}
                            </TouchableOpacity>
                        </ScrollView>
                    </KeyboardAvoidingView>

                    {this.renderSheet()}
                </View>
            </Modal>
        );
    }
}

const styles = StyleSheet.create({
    screen: { flex: 1, backgroundColor: C.bg },

    header: {
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
    headerTitle: { fontSize: 16, fontWeight: '800', color: C.text },
    headerCancel: { fontSize: 15, color: C.muted, fontWeight: '600', width: 52 },
    headerDisabled: { opacity: 0.4 },

    body: { flex: 1 },
    content: { padding: 16, paddingBottom: 48 },

    errorBox: {
        backgroundColor: C.dangerSoft,
        borderRadius: 10,
        padding: 12,
        marginBottom: 14,
    },
    errorText: { color: C.danger, fontSize: 13.5, fontWeight: '600' },

    field: { marginBottom: 16 },
    labelRow: { flexDirection: 'row', alignItems: 'center', marginBottom: 7 },
    label: { fontSize: 12, fontWeight: '800', color: C.muted, letterSpacing: 0.5, textTransform: 'uppercase' },
    required: { color: C.danger, fontSize: 13, fontWeight: '800', marginLeft: 4 },
    optional: { color: C.faint, fontSize: 11, fontWeight: '600', marginLeft: 6 },
    hint: { fontSize: 11.5, color: C.faint, marginTop: 6, marginLeft: 2 },
    warnText: { fontSize: 11.5, color: '#ca8a04', marginTop: 8, marginLeft: 2, fontWeight: '600' },

    input: {
        backgroundColor: C.surface,
        borderWidth: 1,
        borderColor: C.border,
        borderRadius: 10,
        paddingHorizontal: 14,
        paddingVertical: 12,
        fontSize: 15,
        color: C.text,
    },
    textarea: { height: 130, paddingTop: 12 },

    readOnly: {
        backgroundColor: '#eef2f7',
        borderWidth: 1,
        borderColor: C.border,
        borderRadius: 10,
        paddingHorizontal: 14,
        paddingVertical: 13,
    },
    readOnlyText: { fontSize: 15, color: C.muted, fontWeight: '600' },

    selectBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
    selectBtnDisabled: { opacity: 0.55 },
    selectText: { flex: 1, fontSize: 15, color: C.text },
    selectPlaceholder: { color: C.faint },
    selectCaret: { fontSize: 12, color: C.muted, marginLeft: 8 },

    fileRow: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: C.surface,
        borderWidth: 1,
        borderColor: C.border,
        borderRadius: 10,
        paddingHorizontal: 12,
        paddingVertical: 10,
        marginBottom: 8,
    },
    fileIcon: { fontSize: 16, marginRight: 10 },
    fileName: { fontSize: 14, color: C.text, fontWeight: '600' },
    fileSize: { fontSize: 11.5, color: C.faint, marginTop: 2 },
    fileRemove: { fontSize: 15, color: C.muted, fontWeight: '700', paddingLeft: 10 },

    fileBtn: {
        backgroundColor: C.surface,
        borderWidth: 1,
        borderColor: C.primary,
        borderStyle: 'dashed',
        borderRadius: 10,
        paddingVertical: 13,
        alignItems: 'center',
    },
    fileBtnDisabled: { opacity: 0.4, borderColor: C.border },
    fileBtnText: { fontSize: 13.5, fontWeight: '700', color: C.primary },

    chips: { flexDirection: 'row', flexWrap: 'wrap' },
    chip: {
        backgroundColor: C.primarySoft,
        borderWidth: 1,
        borderColor: C.primary,
        borderRadius: 16,
        paddingHorizontal: 11,
        paddingVertical: 5,
        marginRight: 6,
        marginBottom: 6,
        maxWidth: '100%',
    },
    chipText: { fontSize: 12.5, fontWeight: '700', color: C.primary },
    chipsEmpty: { fontSize: 13, color: C.danger, fontWeight: '600' },

    submitBtn: {
        borderRadius: 10,
        paddingVertical: 15,
        alignItems: 'center',
        justifyContent: 'center',
        marginTop: 8,
        minHeight: 50,
    },
    submitBtnBusy: { opacity: 0.75 },
    submitText: { color: '#fff', fontSize: 15.5, fontWeight: '800' },

    /* select sheet */
    sheetOverlay: { flex: 1, backgroundColor: 'rgba(15, 23, 42, 0.45)', justifyContent: 'flex-end' },
    sheet: {
        backgroundColor: C.surface,
        borderTopLeftRadius: 20,
        borderTopRightRadius: 20,
        paddingHorizontal: 16,
        paddingTop: 10,
        paddingBottom: 24,
    },
    sheetHandle: { alignSelf: 'center', width: 40, height: 4, borderRadius: 2, backgroundColor: C.border, marginBottom: 12 },
    sheetTitle: { fontSize: 13, fontWeight: '700', color: C.muted, letterSpacing: 0.3, marginBottom: 6, marginLeft: 4 },
    sheetSearch: {
        backgroundColor: C.bg,
        borderWidth: 1,
        borderColor: C.border,
        borderRadius: 10,
        paddingHorizontal: 14,
        paddingVertical: 10,
        fontSize: 15,
        color: C.text,
        marginBottom: 6,
    },
    sheetEmpty: { fontSize: 14, color: C.muted, textAlign: 'center', paddingVertical: 26 },
    sheetGroup: {
        fontSize: 11,
        fontWeight: '800',
        color: C.primary,
        letterSpacing: 0.7,
        textTransform: 'uppercase',
        backgroundColor: C.primarySoft,
        paddingHorizontal: 8,
        paddingVertical: 5,
        marginTop: 8,
        borderRadius: 6,
    },
    sheetItem: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        paddingVertical: 14,
        paddingHorizontal: 8,
        borderBottomWidth: 1,
        borderBottomColor: C.border,
    },
    sheetItemText: { fontSize: 15.5, color: C.text },
    sheetItemTextOn: { color: C.primary, fontWeight: '700' },
    sheetItemSub: { fontSize: 12, color: C.faint, marginTop: 2 },
    sheetCheck: { fontSize: 16, color: C.primary, fontWeight: '800', marginLeft: 10 },
    sheetDone: {
        backgroundColor: C.primary,
        borderRadius: 10,
        paddingVertical: 13,
        alignItems: 'center',
        marginTop: 14,
    },
    sheetDoneText: { color: '#fff', fontSize: 15, fontWeight: '700' },
});