import React, { Component } from 'react';
import {
    View,
    Text,
    Image,
    Modal,
    StyleSheet,
    ActivityIndicator,
    TouchableOpacity,
    Dimensions,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { ReactNativeZoomableView } from '@openspacelabs/react-native-zoomable-view';
import { initialsOf } from './StaffPhoto';

/**
 * One staff photo, full screen and pinch-zoomable.
 *
 * Profile pictures are cropped to a small circle everywhere else, which is fine
 * for recognising somebody in a list and useless for actually looking at them.
 * This shows the stored image whole, so a face, a uniform or a name badge can
 * be read.
 *
 * Shared by the Staff tab and the directory card so a photo opens the same way
 * wherever it is tapped.
 */

const C = {
    primary: '#2563eb',
    muted: '#94a3b8',
};

export default class PhotoViewerModal extends Component {
    constructor(props) {
        super(props);
        this.state = { loading: true, failed: false };
    }

    render() {
        const { uri, fullname, onClose } = this.props;
        const { loading, failed } = this.state;
        const screen = Dimensions.get('window');
        // Square box the image is fitted into: `contain` then letterboxes a
        // portrait or landscape photo rather than cropping it, which is the
        // whole point of opening it full size.
        const side = Math.min(screen.width, screen.height) * 0.92;

        return (
            <Modal visible transparent={false} animationType="fade" onRequestClose={onClose}>
                <SafeAreaView style={styles.screen} edges={['top', 'bottom']}>
                    <View style={styles.bar}>
                        <Text style={styles.name} numberOfLines={1}>
                            {fullname || ''}
                        </Text>
                        <TouchableOpacity
                            onPress={onClose}
                            hitSlop={{ top: 14, bottom: 14, left: 14, right: 14 }}
                        >
                            <Text style={styles.close}>{'✕'}</Text>
                        </TouchableOpacity>
                    </View>

                    <View style={styles.body}>
                        {!uri || failed ? (
                            <View style={styles.fallback}>
                                <Text style={styles.fallbackInitials}>
                                    {initialsOf(fullname)}
                                </Text>
                                <Text style={styles.fallbackText}>
                                    {uri ? 'Photo could not be loaded' : 'No photo on file'}
                                </Text>
                            </View>
                        ) : (
                            <ReactNativeZoomableView
                                style={styles.zoom}
                                contentWidth={side}
                                contentHeight={side}
                                maxZoom={4}
                                minZoom={1}
                                zoomStep={0.5}
                                initialZoom={1}
                                bindToBorders
                            >
                                <Image
                                    source={{ uri }}
                                    style={{ width: side, height: side }}
                                    resizeMode="contain"
                                    onLoadEnd={() => this.setState({ loading: false })}
                                    onError={() => this.setState({ loading: false, failed: true })}
                                />
                            </ReactNativeZoomableView>
                        )}

                        {loading && !!uri && !failed && (
                            <ActivityIndicator
                                size="large"
                                color={C.primary}
                                style={StyleSheet.absoluteFill}
                            />
                        )}
                    </View>

                    {!!uri && !failed && (
                        <Text style={styles.hint}>Pinch to zoom {'•'} drag to pan</Text>
                    )}
                </SafeAreaView>
            </Modal>
        );
    }
}

const styles = StyleSheet.create({
    screen: { flex: 1, backgroundColor: '#0b1220' },
    bar: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        paddingHorizontal: 16,
        paddingVertical: 14,
    },
    name: { flex: 1, fontSize: 16, fontWeight: '700', color: '#fff', marginRight: 12 },
    close: { fontSize: 20, color: '#cbd5e1' },

    body: { flex: 1, alignItems: 'center', justifyContent: 'center' },
    zoom: { flex: 1, width: '100%' },

    fallback: { alignItems: 'center' },
    fallbackInitials: {
        fontSize: 64,
        fontWeight: '800',
        color: '#334155',
        letterSpacing: 2,
    },
    fallbackText: { fontSize: 13.5, color: C.muted, marginTop: 14 },

    hint: {
        fontSize: 11.5,
        color: C.muted,
        textAlign: 'center',
        paddingBottom: 14,
    },
});
