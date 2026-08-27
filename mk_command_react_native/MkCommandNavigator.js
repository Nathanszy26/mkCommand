import React from 'react';
import {
    View,
    Text,
    TouchableOpacity,
    StyleSheet,
    ScrollView,
    ActivityIndicator,
} from 'react-native';
import { createMaterialTopTabNavigator } from '@react-navigation/material-top-tabs';
import ROUTES from '../../../library/routes.js';
import { appColor } from '../../../library/constant.js';
import MkCommandStaff from './screens/Staff.js';
import MkCommandApp from './screens/App.js';
import MkCommandJobSpec from './screens/JobSpec.js';
import MkCommandMemo from './screens/Memo.js';
import MkCommandMeritDemerit from './screens/MeritDemerit.js';
import MkCommandApproval from './screens/Processing.js';
import {
    getApproval,
    subscribeApproval,
    refreshApproval,
} from './screens/processing/approvalCount.js';

const Tab = createMaterialTopTabNavigator();

// ---- Switch this to test: 'underline' | 'pills' | 'segmented' ----
const TAB_STYLE = 'pills';

const ACCENT = appColor.primary;
const INACTIVE = '#8b949e';

const TopTabBar = ({ state, descriptors, navigation }) => {
    const s = tabStyles[TAB_STYLE];

    return (
        <View style={s.wrapper}>
            <ScrollView
                horizontal
                showsHorizontalScrollIndicator={false}
                contentContainerStyle={s.container}
            >
                {state.routes.map((route, index) => {
                    const { options } = descriptors[route.key];
                    const label = options.tabBarLabel ?? route.name;
                    const isActive = state.index === index;

                    const onPress = () => {
                        const event = navigation.emit({
                            type: 'tabPress',
                            target: route.key,
                            canPreventDefault: true,
                        });
                        if (!isActive && !event.defaultPrevented) {
                            navigation.navigate(route.name);
                        }
                    };

                    return (
                        <TouchableOpacity
                            key={route.key}
                            style={[s.button, isActive && s.activeButton]}
                            onPress={onPress}
                            activeOpacity={0.7}
                        >
                            <Text
                                style={[s.text, isActive && s.activeText]}
                                numberOfLines={1}
                            >
                                {label}
                            </Text>
                        </TouchableOpacity>
                    );
                })}
            </ScrollView>
        </View>
    );
};

const MkCommandNavigator = () => {
    // Whether the Approval tab exists, and the number on it, are both the API's
    // call -- the endpoint and the page it opens each gate themselves, so this
    // only decides what is drawn.
    const [approval, setApproval] = React.useState(getApproval);

    React.useEffect(() => {
        const unsubscribe = subscribeApproval(setApproval);
        refreshApproval();
        return unsubscribe;
    }, []);

    // Nothing is mounted until the answer is in. A navigator whose screens
    // appear a moment after it starts has already chosen a landing tab against
    // the wrong list, and re-picking one afterwards moves the ground under
    // whoever is already looking at it.
    if (!approval.resolved) {
        return (
            <View style={styles.booting}>
                <ActivityIndicator size="large" color={ACCENT} />
            </View>
        );
    }

    const approvalLabel = approval.count > 0 ? `Approval (${approval.count})` : 'Approval';

    return (
        <Tab.Navigator
            // Access decides which screens exist, so it also decides the identity
            // of the navigator: should it ever change, the whole thing is rebuilt
            // against the new list rather than reindexed against the old one.
            key={approval.authorized ? 'with-approval' : 'without-approval'}
            // Approval sits first in the bar but is never the landing tab. Staff
            // is the one screen everybody has, so the app opens on the same
            // place for everybody rather than on whatever their role unlocks.
            initialRouteName={ROUTES.MkCommandStaff}
            tabBar={(props) => <TopTabBar {...props} />}
        >
            {approval.authorized && (
                <Tab.Screen
                    name={ROUTES.MkCommandProcessing}
                    component={MkCommandApproval}
                    options={{ tabBarLabel: approvalLabel, swipeEnabled: false }}
                />
            )}
            <Tab.Screen
                name={ROUTES.MkCommandStaff}
                component={MkCommandStaff}
                options={{ tabBarLabel: 'Staff' }}
            />
            <Tab.Screen
                name={ROUTES.MkCommandJobSpec}
                component={MkCommandJobSpec}
                options={{ tabBarLabel: 'Job Spec' }}
            />
            <Tab.Screen
                name={ROUTES.MkCommandApp}
                component={MkCommandApp}
                options={{ tabBarLabel: 'App' }}
            />
            <Tab.Screen
                name={ROUTES.MkCommandMemo}
                component={MkCommandMemo}
                options={{ tabBarLabel: 'Memo' }}
            />
            <Tab.Screen
                name={ROUTES.MkCommandMeritDemerit}
                component={MkCommandMeritDemerit}
                options={{ tabBarLabel: 'Merit/Demerit' }}
            />
        </Tab.Navigator>
    );
};

// Option A — Underline
const underline = StyleSheet.create({
    wrapper: {
        backgroundColor: 'white',
        borderBottomWidth: 1,
        borderBottomColor: '#e9ecef',
    },
    container: {
        flexDirection: 'row',
        alignItems: 'stretch',
        paddingHorizontal: 4,
    },
    button: {
        paddingHorizontal: 18,
        paddingVertical: 14,
        borderBottomWidth: 3,
        borderBottomColor: 'transparent',
        alignItems: 'center',
        justifyContent: 'center',
    },
    activeButton: {
        borderBottomColor: ACCENT,
    },
    text: {
        fontSize: 14,
        fontWeight: '500',
        color: INACTIVE,
    },
    activeText: {
        color: ACCENT,
        fontWeight: '700',
    },
});

// Option B — Soft pills
const pills = StyleSheet.create({
    wrapper: {
        backgroundColor: 'white',
        borderBottomWidth: 1,
        borderBottomColor: '#e9ecef',
    },
    container: {
        flexDirection: 'row',
        alignItems: 'center',
        paddingVertical: 10,
        paddingHorizontal: 12,
    },
    button: {
        height: 36,
        paddingHorizontal: 16,
        marginRight: 8,
        borderRadius: 8,
        backgroundColor: 'transparent',
        alignItems: 'center',
        justifyContent: 'center',
    },
    activeButton: {
        backgroundColor: ACCENT,
    },
    text: {
        fontSize: 13,
        fontWeight: '500',
        color: '#6c757d',
    },
    activeText: {
        color: 'white',
        fontWeight: '700',
    },
});

// Option C — Segmented control
const segmented = StyleSheet.create({
    wrapper: {
        backgroundColor: 'white',
        borderBottomWidth: 1,
        borderBottomColor: '#e9ecef',
        paddingVertical: 10,
        paddingHorizontal: 12,
    },
    container: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: '#f1f3f5',
        borderRadius: 10,
        padding: 4,
    },
    button: {
        height: 34,
        paddingHorizontal: 14,
        marginRight: 4,
        borderRadius: 7,
        backgroundColor: 'transparent',
        alignItems: 'center',
        justifyContent: 'center',
    },
    activeButton: {
        backgroundColor: 'white',
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 1 },
        shadowOpacity: 0.12,
        shadowRadius: 2,
        elevation: 2,
    },
    text: {
        fontSize: 13,
        fontWeight: '500',
        color: '#6c757d',
    },
    activeText: {
        color: ACCENT,
        fontWeight: '700',
    },
});

const styles = StyleSheet.create({
    booting: {
        flex: 1,
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: 'white',
    },
});

const tabStyles = { underline, pills, segmented };

export default MkCommandNavigator;