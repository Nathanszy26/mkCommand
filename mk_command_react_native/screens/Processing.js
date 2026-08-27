import React, { Component } from 'react';
import { View, Text, StyleSheet, ActivityIndicator, TouchableOpacity, Platform } from 'react-native';
import { WebView } from 'react-native-webview';
import { C } from './processing/theme.js';
import { currentUser, approvalListUrl, isDecisionUrl } from './processing/api.js';
import { refreshApproval } from './processing/approvalCount.js';

/**
 * The Approval tab: manpower_requisition_list.php opened with embed=1, which
 * pins the page to the approval queue and drops its own title bar and status
 * tabs. Everything on it -- the two totals, the All / Manpower / Candidate
 * filter, the company picker, and every approve and reject -- belongs to the
 * page, which is why there is no second copy of any of it here.
 */
export default class MkCommandApproval extends Component {
    constructor(props) {
        super(props);
        this.state = { uri: null, error: null, loading: true };
    }

    componentDidMount() {
        this.resolve();

        // The badge is re-read on the way out as well as after each decision, so
        // a change the redirect did not report is still picked up when the tab
        // is left.
        const nav = this.props.navigation;
        if (nav && nav.addListener) {
            this.unsubscribeBlur = nav.addListener('blur', () => refreshApproval());
        }
    }

    componentWillUnmount() {
        if (this.unsubscribeBlur) {
            this.unsubscribeBlur();
        }
        refreshApproval();
    }

    /** Command: build the page address from the signed-in user. */
    resolve = async () => {
        this.setState({ loading: true, error: null });
        try {
            const user = await currentUser();
            this.setState({ uri: approvalListUrl(user), loading: false });
        } catch (error) {
            this.setState({ error: error.message, loading: false, uri: null });
        }
    };

    /**
     * Every decision ends in a redirect carrying ?done=, so that is the moment
     * the badge is known to be stale. Refreshing on it means the count follows
     * the work instead of waiting for the tab to be left.
     */
    handleNavigation = (navState) => {
        if (isDecisionUrl(navState.url)) {
            refreshApproval();
        }
    };

    handleError = () => this.setState({ error: 'The approval list could not be loaded.' });

    renderSpinner = () => (
        <View style={styles.centre}>
            <ActivityIndicator size="large" color={C.primary} />
        </View>
    );

    render() {
        const { uri, error, loading } = this.state;

        if (loading) {
            return this.renderSpinner();
        }

        if (error) {
            return (
                <View style={styles.centre}>
                    <Text style={styles.errorText}>{error}</Text>
                    <TouchableOpacity activeOpacity={0.7} style={styles.retryBtn} onPress={this.resolve}>
                        <Text style={styles.retryText}>Try again</Text>
                    </TouchableOpacity>
                </View>
            );
        }

        return (
            <View style={styles.root}>
                <WebView
                    source={{ uri }}
                    style={styles.web}
                    onNavigationStateChange={this.handleNavigation}
                    onError={this.handleError}
                    onHttpError={this.handleError}
                    startInLoadingState
                    renderLoading={this.renderSpinner}
                    /* Scrolling. The page is long and is read by scrolling, so the
                       web view has to own the vertical gesture outright. */
                    scrollEnabled
                    nestedScrollEnabled
                    overScrollMode="never"
                    /* iOS adds its own top inset inside a navigator, which lands as
                       dead space the page then scrolls under. The page already
                       carries its own safe-area padding. */
                    automaticallyAdjustContentInsets={false}
                    contentInsetAdjustmentBehavior="never"
                    /* The edge swipe would compete with the tab bar for the same
                       gesture, and there is nothing to go back to. */
                    allowsBackForwardNavigationGestures={false}
                    androidLayerType={Platform.OS === 'android' ? 'hardware' : undefined}
                />
            </View>
        );
    }
}

const styles = StyleSheet.create({
    root: { flex: 1, backgroundColor: C.bg },
    web: { flex: 1, backgroundColor: C.bg },
    centre: {
        flex: 1,
        alignItems: 'center',
        justifyContent: 'center',
        padding: 24,
        backgroundColor: C.bg,
    },
    errorText: { color: C.danger, fontSize: 14, textAlign: 'center', marginBottom: 14 },
    retryBtn: { paddingHorizontal: 18, paddingVertical: 10, borderRadius: 8, backgroundColor: C.primary },
    retryText: { color: '#fff', fontWeight: '700', fontSize: 14 },
});