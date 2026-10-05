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
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { launchCamera, launchImageLibrary } from 'react-native-image-picker';
import Icon from 'react-native-vector-icons/Ionicons';
import StaffPhoto from './StaffPhoto.js';
import { fetchStaffDetail } from '../services/staffDirectoryApi.js';

/**
 * Staff Search (By Photo) — find out WHO somebody is from a picture of them.
 *
 * This is 1:N identification, the opposite question from the check-in flow:
 * check-in asks "is this person who they claim to be" against one enrolled
 * face, while this asks "which of the enrolled faces is this". It therefore
 * talks to /identify, NOT to face_recognize.php, and nothing about check-in
 * changes.
 *
 * The service answers with an identity only — (person, comp_id, is_gw) — which
 * is the same key the rest of this app uses for a person. The name, photo,
 * department and contacts are then read from the staff directory this screen
 * already talks to, so there is one source of truth for what a staff record
 * says and the face service never needs to read the HR tables.
 *
 * A match is shown for confirmation rather than acted on: the photo went in,
 * the record comes back, and a human decides it is the right person before
 * anything opens.
 */

const STAFF_IDENTIFY_URL =
    'https://glob.com.my/it/mkPortal/site_checkin/face_services/staff_identify.php';

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

/** Shared by both pickers: one face, big enough to encode, small enough to post.
 *  Mirrors the options the GW attendance capture uses. */
const PICKER_OPTIONS = {
    mediaType: 'photo',
    includeBase64: true,
    quality: 0.7,
    maxWidth: 1024,
    maxHeight: 1024,
    saveToPhotos: false,
};

export default class StaffPhotoSearchModal extends Component {
    constructor(props) {
        super(props);
        this.state = this.blank();
    }

    blank() {
        return {
            busy: false,
            preview: null,   // data URI of the picture being searched
            match: null,     // the resolved staff record
            score: null,     // { confidence, searched }
            error: null,
            notFound: null,  // the service answered, with nobody
        };
    }

    componentWillUnmount() {
        this.unmounted = true;
    }

    /** Command: back to the two buttons, keeping the modal open. */
    reset = () => this.setState(this.blank());

    close = () => {
        this.setState(this.blank());
        this.props.onClose();
    };

    /** The picker's reply is the same shape for camera and gallery, so both
     *  land here and only the launcher differs. */
    handlePicked = (resp) => {
        if (!resp || resp.didCancel) {
            return;
        }
        if (resp.errorCode) {
            this.setState({ error: resp.errorMessage || 'Could not open the picker.' });
            return;
        }
        const asset = resp.assets && resp.assets[0];
        if (!asset || !asset.base64) {
            this.setState({ error: 'No photo was returned. Try again.' });
            return;
        }
        this.search(asset.base64, asset.type || 'image/jpeg');
    };

    takePhoto = () =>
        guardPicker(() =>
            launchCamera({ ...PICKER_OPTIONS, cameraType: 'back' }, this.handlePicked)
        );

    pickFromGallery = () =>
        guardPicker(() => launchImageLibrary(PICKER_OPTIONS, this.handlePicked));

    /**
     * Command: send the picture, then resolve whoever comes back into a full
     * staff record. Two round-trips on purpose — see the note at the top.
     */
    search = async (base64, mime) => {
        this.setState({
            busy: true,
            error: null,
            notFound: null,
            match: null,
            score: null,
            preview: `data:${mime};base64,${base64}`,
        });

        try {
            const res = await fetch(STAFF_IDENTIFY_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ image: base64 }),
            });
            const data = await res.json();

            if (this.unmounted) {
                return;
            }

            if (!data.matched) {
                this.setState({
                    busy: false,
                    notFound: data.error || 'No matching staff found.',
                    score: { confidence: data.confidence, searched: data.searched },
                });
                return;
            }

            // Identity -> record, as its own failure case. Roughly 3% of
            // enrolled faces belong to people who have since left or been
            // excluded: the service still matches them, but the directory has
            // nothing to show. That is a dead end, not a network fault, and
            // saying so beats "could not reach the service".
            let staff;
            try {
                staff = await fetchStaffDetail(this.props.session, {
                    person: data.person,
                    comp_id: data.comp_id,
                    is_gw: data.is_gw ? 1 : 0,
                });
            } catch (lookupError) {
                if (this.unmounted) {
                    return;
                }
                this.setState({
                    busy: false,
                    notFound:
                        'Matched a staff photo, but there is no active record for it '
                        + 'any more. They may have left.',
                    score: { confidence: data.confidence, searched: data.searched },
                });
                return;
            }

            if (this.unmounted) {
                return;
            }

            this.setState({
                busy: false,
                match: { ...staff, person: data.person, comp_id: data.comp_id, is_gw: data.is_gw ? 1 : 0 },
                score: { confidence: data.confidence, searched: data.searched },
            });
        } catch (e) {
            if (this.unmounted) {
                return;
            }
            this.setState({
                busy: false,
                error: e.message || 'Could not reach the face service.',
            });
        }
    };

    renderBody() {
        const { busy, preview, match, error, notFound, score } = this.state;

        if (busy) {
            return (
                <View style={styles.centreBox}>
                    {!!preview && <Image source={{ uri: preview }} style={styles.preview} />}
                    <ActivityIndicator size="large" color={C.primary} style={{ marginTop: 18 }} />
                    <Text style={styles.note}>Searching staff…</Text>
                </View>
            );
        }

        if (error) {
            return (
                <View style={styles.centreBox}>
                    <Icon name="alert-circle" size={44} color={C.danger} />
                    <Text style={styles.errorText}>{error}</Text>
                    <TouchableOpacity style={styles.primaryBtn} onPress={this.reset}>
                        <Text style={styles.primaryBtnText}>Try Again</Text>
                    </TouchableOpacity>
                </View>
            );
        }

        if (notFound) {
            return (
                <View style={styles.centreBox}>
                    {!!preview && <Image source={{ uri: preview }} style={styles.preview} />}
                    <Text style={styles.noMatchTitle}>No match</Text>
                    <Text style={styles.note}>{notFound}</Text>
                    {!!score && score.searched > 0 && (
                        <Text style={styles.subtle}>
                            Searched {score.searched} staff photo
                            {score.searched === 1 ? '' : 's'}.
                        </Text>
                    )}
                    <TouchableOpacity style={styles.primaryBtn} onPress={this.reset}>
                        <Text style={styles.primaryBtnText}>Try Another Photo</Text>
                    </TouchableOpacity>
                </View>
            );
        }

        if (match) {
            return (
                <View style={styles.resultBox}>
                    <View style={styles.compareRow}>
                        {!!preview && (
                            <View style={styles.compareCol}>
                                <Image source={{ uri: preview }} style={styles.compareImg} />
                                <Text style={styles.compareLabel}>Your photo</Text>
                            </View>
                        )}
                        <View style={styles.compareCol}>
                            <StaffPhoto
                                uri={match.photo_url}
                                fullname={match.fullname}
                                size={96}
                                color={C.primary}
                                isGw={!!match.is_gw}
                            />
                            <Text style={styles.compareLabel}>On file</Text>
                        </View>
                    </View>

                    <Text style={styles.matchName}>{titleCase(match.fullname)}</Text>
                    <Text style={styles.matchMeta}>{match.position || 'No Position'}</Text>
                    <Text style={styles.matchMeta}>
                        {match.department || 'No Department'}
                        {match.company_name ? ` • ${match.company_name}` : ''}
                    </Text>

                    <Text style={styles.subtle}>
                        Check the two pictures are the same person before opening the record.
                    </Text>

                    <TouchableOpacity
                        style={styles.primaryBtn}
                        onPress={() => {
                            const found = this.state.match;
                            this.setState(this.blank());
                            this.props.onOpenStaff(found);
                        }}
                    >
                        <Text style={styles.primaryBtnText}>View Full Record</Text>
                    </TouchableOpacity>
                    <TouchableOpacity style={styles.ghostBtn} onPress={this.reset}>
                        <Text style={styles.ghostBtnText}>Search Another</Text>
                    </TouchableOpacity>
                </View>
            );
        }

        // Idle: choose a source.
        return (
            <View style={styles.centreBox}>
                <View style={styles.hintIcon}>
                    <Icon name="person-circle-outline" size={54} color={C.primary} />
                </View>
                <Text style={styles.lead}>Find a staff member from a picture of their face.</Text>
                <Text style={styles.subtle}>
                    One face in the frame, looking at the camera, in decent light.
                </Text>

                <TouchableOpacity style={styles.primaryBtn} onPress={this.takePhoto}>
                    <Icon name="camera" size={18} color="#fff" />
                    <Text style={styles.primaryBtnText}>Take Photo</Text>
                </TouchableOpacity>

                <TouchableOpacity style={styles.secondaryBtn} onPress={this.pickFromGallery}>
                    <Icon name="images" size={18} color={C.primary} />
                    <Text style={styles.secondaryBtnText}>Choose from Gallery</Text>
                </TouchableOpacity>
            </View>
        );
    }

    render() {
        if (!this.props.visible) {
            return null;
        }
        return (
            <Modal visible animationType="slide" onRequestClose={this.close}>
                <SafeAreaView style={styles.screen} edges={['top', 'bottom']}>
                    <View style={styles.bar}>
                        <Text style={styles.barTitle}>Staff Search (By Photo)</Text>
                        <TouchableOpacity
                            onPress={this.close}
                            hitSlop={{ top: 14, bottom: 14, left: 14, right: 14 }}
                        >
                            <Text style={styles.close}>{'✕'}</Text>
                        </TouchableOpacity>
                    </View>
                    <ScrollView contentContainerStyle={styles.body}>{this.renderBody()}</ScrollView>
                </SafeAreaView>
            </Modal>
        );
    }
}

/** The picker throws rather than calling back when the native module is missing
 *  (a dev build without it linked). Surfacing that beats a dead button. */
function guardPicker(run) {
    try {
        run();
    } catch (e) {
        // eslint-disable-next-line no-console
        console.warn('image picker unavailable:', e);
    }
}

const styles = StyleSheet.create({
    screen: { flex: 1, backgroundColor: C.bg },
    bar: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        paddingHorizontal: 16,
        paddingVertical: 14,
        backgroundColor: C.surface,
        borderBottomWidth: 1,
        borderBottomColor: C.border,
    },
    barTitle: { fontSize: 16, fontWeight: '700', color: C.text },
    close: { fontSize: 20, color: C.muted, fontWeight: '700' },

    body: { padding: 18, paddingBottom: 40 },
    centreBox: { alignItems: 'center', paddingVertical: 24 },
    resultBox: { alignItems: 'center', paddingVertical: 10 },

    hintIcon: {
        width: 92,
        height: 92,
        borderRadius: 46,
        backgroundColor: C.primarySoft,
        alignItems: 'center',
        justifyContent: 'center',
        marginBottom: 16,
    },
    lead: {
        fontSize: 15,
        fontWeight: '600',
        color: C.text,
        textAlign: 'center',
        marginBottom: 6,
    },
    subtle: {
        fontSize: 12.5,
        color: C.muted,
        textAlign: 'center',
        marginTop: 6,
        marginBottom: 6,
        paddingHorizontal: 10,
    },
    note: { fontSize: 13.5, color: C.muted, textAlign: 'center', marginTop: 10 },
    errorText: {
        fontSize: 14,
        color: C.danger,
        textAlign: 'center',
        marginTop: 12,
        marginBottom: 4,
    },

    preview: { width: 140, height: 140, borderRadius: 12, backgroundColor: C.border },

    // The two faces side by side: the judgement this screen asks for is visual,
    // so the picture that was sent and the picture on file sit together.
    compareRow: { flexDirection: 'row', justifyContent: 'center', marginBottom: 16 },
    compareCol: { alignItems: 'center', marginHorizontal: 12 },
    compareImg: { width: 96, height: 96, borderRadius: 48, backgroundColor: C.border },
    compareLabel: { fontSize: 11.5, color: C.muted, marginTop: 7, fontWeight: '600' },

    matchName: { fontSize: 19, fontWeight: '800', color: C.text, textAlign: 'center' },
    matchMeta: { fontSize: 13, color: C.muted, marginTop: 2, textAlign: 'center' },
    noMatchTitle: { fontSize: 18, fontWeight: '800', color: C.text, marginTop: 14 },

    primaryBtn: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: C.primary,
        borderRadius: 11,
        paddingVertical: 14,
        paddingHorizontal: 22,
        marginTop: 18,
        minWidth: 230,
    },
    primaryBtnText: { color: '#fff', fontWeight: '800', fontSize: 14.5, marginLeft: 8 },
    secondaryBtn: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: C.primarySoft,
        borderWidth: 1,
        borderColor: C.primary,
        borderRadius: 11,
        paddingVertical: 13,
        paddingHorizontal: 22,
        marginTop: 10,
        minWidth: 230,
    },
    secondaryBtnText: { color: C.primary, fontWeight: '800', fontSize: 14.5, marginLeft: 8 },
    ghostBtn: { paddingVertical: 12, marginTop: 4 },
    ghostBtnText: { color: C.muted, fontWeight: '700', fontSize: 13.5 },
});
