import React, { Component } from 'react';
import {
    View,
    Text,
    SectionList,
    ScrollView,
    StyleSheet,
    TextInput,
    ActivityIndicator,
    TouchableOpacity,
    Platform,
    Dimensions,
} from 'react-native';
import StaffPhoto from './StaffPhoto';
import {
    MIN_QUERY_LENGTH,
    NO_DEPARTMENT,
    fetchCompanies,
    fetchDepartments,
    searchDirectory,
    browseDirectory,
} from '../services/staffDirectoryApi';

/**
 * Staff Directory search — the company phone book, rendered INSIDE the Staff
 * screen rather than over it.
 *
 * It finds people and hands one back; it does not show them. Tapping a result
 * calls `onSelect`, and the Staff screen puts that person's card on the page —
 * the same card it draws for a superior, a subordinate or a department head,
 * reached by the same Up / company / Back controls. Searching is therefore one
 * more way INTO the page's navigation instead of a parallel copy of it, which
 * is what the separate sheet had become.
 *
 * Deliberate choices kept from that sheet:
 *  - Not limited to your own reporting line: a directory you can only use on
 *    your own subordinates is not a directory. The company picker defaults to
 *    your own company and can be changed.
 *  - Every field is searched at once (name, email, mobile, department,
 *    position, job-spec task text) rather than making you pick a field first —
 *    you rarely know WHICH field holds the thing you half-remember.
 *  - A hit matched only by job-spec text shows the task that matched, so a
 *    result whose name and department look unrelated still explains itself.
 *  - Typing is debounced and every response is stamped with the query that
 *    asked for it, so a slow early response can never overwrite a later one.
 *  - With the box empty it BROWSES: everybody in the company, cut into
 *    department sections, cached per company+department.
 *
 * Props:
 *   session  { person, compId }     who is asking
 *   onSelect (row) => void          a result was tapped
 *   header   element                drawn above the filters, inside the list
 *
 * `header` goes INSIDE the list rather than above it, along with the filters
 * and the box itself: everything above the results scrolls away with them, so
 * a long department list is read with the whole screen instead of through a
 * window under a fixed panel.
 */

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
    gw: '#ca8a04',
    gwSoft: 'rgba(202, 138, 4, 0.10)',
};

const SEARCH_DEBOUNCE_MS = 350;

const titleCase = (value) =>
    (value || '').toLowerCase().replace(/\b\w/g, (c) => c.toUpperCase());

/** Same identity rule as the Staff tree: a GW code and a users.person live in
 *  different namespaces and can collide, so the gw flag is part of the key. */
const rowKey = (row) => `${row.person}|${row.comp_id}|${row.is_gw ? 1 : 0}`;

/** The label for people with no department on file — shown as a real section
 *  rather than an unnamed one, because at Globinaco it is the largest group. */
const NO_DEPARTMENT_LABEL = 'No Department';

/** Placeholders the HR sheets use for "nothing on file" read as real values on
 *  a phone screen, so they are blanked rather than shown. */
const clean = (value) => {
    const v = (value === null || value === undefined ? '' : String(value)).trim();
    return v === '' || v === '-' || v === 'N/A' || v === 'NA' ? null : v;
};

/**
 * Cut an already-ordered list into SectionList sections by department. The
 * server returns browse rows grouped and sorted, so this is one pass with no
 * sorting of its own — change the server's ORDER BY and the sections follow.
 */
const buildSections = (rows) => {
    const sections = [];
    let current = null;
    rows.forEach((row) => {
        const title = clean(row.department) || NO_DEPARTMENT_LABEL;
        // Grouped case-insensitively to match the server's ordering. A company
        // that spells one department two ways ("ACCOUNTS" / "accounts") would
        // otherwise get two adjacent sections for the same team; the first
        // spelling seen wins the header.
        const key = title.toUpperCase();
        if (!current || current.key !== key) {
            current = { key, title, data: [] };
            sections.push(current);
        }
        current.data.push(row);
    });
    return sections;
};

const GwPill = () => (
    <View style={styles.gwPill}>
        <Text style={styles.gwPillText}>GW</Text>
    </View>
);

/** One search hit. Photo + the basics; the whole row hands the person back to
 *  the page, which draws their card. */
const ResultRow = ({ row, onPress }) => {
    const dept = clean(row.department);
    const position = clean(row.position);
    const mobile = clean(row.mobile_no);
    const email = clean(row.email);

    return (
        <TouchableOpacity style={styles.resultRow} activeOpacity={0.6} onPress={() => onPress(row)}>
            <StaffPhoto uri={row.photo_url} fullname={row.fullname} size={48} isGw={!!row.is_gw} />
            <View style={styles.resultText}>
                <View style={styles.resultNameRow}>
                    <Text style={styles.resultName} numberOfLines={1}>
                        {titleCase(row.fullname)}
                    </Text>
                    {!!row.is_gw && <GwPill />}
                </View>

                {!!position && (
                    <Text style={styles.resultPosition} numberOfLines={1}>
                        {position}
                    </Text>
                )}
                {!!dept && (
                    <Text style={styles.resultMeta} numberOfLines={1}>
                        {dept}
                    </Text>
                )}
                {(!!mobile || !!email) && (
                    <Text style={styles.resultMeta} numberOfLines={1}>
                        {[mobile, email].filter(Boolean).join('  •  ')}
                    </Text>
                )}

                {/* Only present when the job spec is what matched. */}
                {!!clean(row.matched_task) && (
                    <View style={styles.matchBox}>
                        <Text style={styles.matchLabel}>Job spec</Text>
                        <Text style={styles.matchText} numberOfLines={2}>
                            {row.matched_task}
                        </Text>
                    </View>
                )}
            </View>
            <Text style={styles.chevron}>{'›'}</Text>
        </TouchableOpacity>
    );
};

export default class StaffDirectorySearch extends Component {
    constructor(props) {
        super(props);
        this.state = {
            query: '',
            compId: null,          // null until the company list lands
            companies: [],
            companyPickerOpen: false,

            department: null,      // null = every department
            departments: [],       // staff departments, from users.department
            gwDistricts: [],       // GW districts, from monthly_assign_gw_district
            deptPickerOpen: false,

            loadingCompanies: true,
            searching: false,
            results: [],
            searched: false,       // a search has completed for the current query
            error: null,

            // Browse: everybody in the company, shown while the box is empty.
            browseRows: [],
            browseLoading: false,
            browseError: null,
            browseTruncated: false,
            browseKey: null,       // the compId|department the rows are for
        };

        this.searchTimer = null;
        // Every search is stamped; only the newest stamp may write to state.
        this.searchToken = 0;
        this.browseToken = 0;
        this.unmounted = false;
    }

    /** Query: which list the panel is showing. Derived, never stored — storing
     *  it is how a mode and the box it reflects drift apart. */
    isSearching() {
        return this.state.query.trim().length >= MIN_QUERY_LENGTH;
    }

    componentDidMount() {
        this.loadCompanies();
    }

    componentWillUnmount() {
        if (this.searchTimer) {
            clearTimeout(this.searchTimer);
        }
        // Responses that arrive after unmount must not setState.
        this.searchToken += 1;
        this.unmounted = true;
    }

    loadCompanies = async () => {
        this.setState({ loadingCompanies: true, error: null });
        try {
            const { ownCompId, companies } = await fetchCompanies(this.props.session);
            if (this.unmounted) {
                return;
            }
            // Your own company is the default. If it somehow isn't in the list,
            // fall back to the first offered one so the picker is never stuck
            // on a company that cannot be searched.
            const own = companies.filter((c) => c.comp_id === ownCompId);
            const compId = own.length ? ownCompId : (companies.length ? companies[0].comp_id : null);
            this.setState({ companies, compId, ownCompId, loadingCompanies: false }, () => {
                if (compId !== null) {
                    this.loadDepartments();
                    this.loadBrowse();
                }
            });
        } catch (error) {
            if (this.unmounted) {
                return;
            }
            this.setState({
                loadingCompanies: false,
                error: error.message || 'Failed to load companies',
            });
        }
    };

    /** Command: the department options for the current company. A failure here
     *  leaves the filter on "All departments", which still works — so it is not
     *  surfaced as an error that blocks the list. */
    loadDepartments = async () => {
        const { compId } = this.state;
        try {
            const { departments, gwDistricts } = await fetchDepartments(this.props.session, compId);
            if (this.unmounted || this.state.compId !== compId) {
                return;
            }
            this.setState({ departments, gwDistricts });
        } catch (error) {
            if (this.unmounted) {
                return;
            }
            this.setState({ departments: [], gwDistricts: [] });
        }
    };

    /**
     * Command: load the browse list for the current company + department.
     *
     * Skipped when the rows already on hand are for that exact pair — clearing
     * the search box is the common way back here and should not re-fetch a
     * company that has not changed.
     */
    loadBrowse = async (force = false) => {
        const { compId, department } = this.state;
        if (compId === null) {
            return;
        }
        const key = compId + '|' + (department === null ? '' : department);
        if (!force && this.state.browseKey === key && this.state.browseRows.length > 0) {
            return;
        }

        const token = ++this.browseToken;
        this.setState({ browseLoading: true, browseError: null });

        try {
            const { rows, truncated } = await browseDirectory(this.props.session, compId, department);
            if (this.unmounted || token !== this.browseToken) {
                return;
            }
            this.setState({
                browseRows: rows,
                browseTruncated: truncated,
                browseKey: key,
                browseLoading: false,
            });
        } catch (error) {
            if (this.unmounted || token !== this.browseToken) {
                return;
            }
            this.setState({
                browseRows: [],
                browseKey: null,
                browseLoading: false,
                browseError: error.message || 'Failed to load the directory',
            });
        }
    };

    /** Command: typing. Debounced — one request per pause, not per keystroke. */
    onChangeQuery = (query) => {
        this.setState({ query });
        if (this.searchTimer) {
            clearTimeout(this.searchTimer);
        }

        if (query.trim().length < MIN_QUERY_LENGTH) {
            // Clearing the box drops back to the browse list rather than leaving
            // the previous query's results under a box that no longer says them.
            this.searchToken += 1;
            this.setState(
                { results: [], searched: false, searching: false, error: null },
                this.loadBrowse
            );
            return;
        }

        this.searchTimer = setTimeout(() => this.runSearch(query), SEARCH_DEBOUNCE_MS);
    };

    /** Command: search now, from the keyboard's Search key. Cancels the pending
     *  debounce so the one explicit request replaces it rather than racing it. */
    submitSearch = () => {
        if (this.searchTimer) {
            clearTimeout(this.searchTimer);
            this.searchTimer = null;
        }
        this.runSearch(this.state.query);
    };

    /** Command: fetch results for one query. Stale responses are dropped. */
    runSearch = async (query) => {
        const { compId, department } = this.state;
        // Too short to search is the normal state of a box being typed into:
        // leave the browse list up rather than answering a query that was never
        // really asked with "no match".
        if (compId === null || (query || '').trim().length < MIN_QUERY_LENGTH) {
            return;
        }

        const token = ++this.searchToken;
        this.setState({ searching: true, error: null });

        try {
            const results = await searchDirectory(this.props.session, query, compId, department);
            if (this.unmounted || token !== this.searchToken) {
                return;
            }
            this.setState({ results, searching: false, searched: true });
        } catch (error) {
            if (this.unmounted || token !== this.searchToken) {
                return;
            }
            this.setState({
                searching: false,
                searched: true,
                results: [],
                error: error.message || 'Search failed',
            });
        }
    };

    /**
     * Command: switch company. The department filter resets to "All" — a
     * department belongs to the company it came from, and carrying "ACCOUNTS"
     * across to a company that spells it differently silently empties the list.
     */
    selectCompany = (compId) => {
        if (compId === this.state.compId) {
            this.setState({ companyPickerOpen: false });
            return;
        }
        this.setState(
            {
                companyPickerOpen: false,
                compId,
                department: null,
                departments: [],
                gwDistricts: [],
                results: [],
                searched: false,
                browseRows: [],
                browseKey: null,
            },
            () => {
                this.loadDepartments();
                this.refreshActiveList();
            }
        );
    };

    /** Command: switch department. Re-runs whichever list is on screen. */
    selectDepartment = (department) => {
        if (department === this.state.department) {
            this.setState({ deptPickerOpen: false });
            return;
        }
        this.setState(
            { deptPickerOpen: false, department, results: [], searched: false },
            this.refreshActiveList
        );
    };

    /** Command: re-run the list currently on screen, after a filter changed
     *  under it. */
    refreshActiveList = () => {
        if (this.isSearching()) {
            this.runSearch(this.state.query);
        } else {
            this.loadBrowse();
        }
    };

    activeCompanyName() {
        const { companies, compId } = this.state;
        const match = companies.filter((c) => c.comp_id === compId);
        return match.length ? match[0].name : 'Select company';
    }

    activeDepartmentName() {
        const { department, departments, gwDistricts } = this.state;
        if (department === null) {
            return 'All departments';
        }
        if (department === NO_DEPARTMENT) {
            return NO_DEPARTMENT_LABEL;
        }
        // The same string can appear as a staff department and a GW district;
        // either way the label is the string itself.
        const all = departments.concat(gwDistricts);
        const match = all.filter((d) => d.value === department);
        return match.length ? match[0].name : department;
    }

    renderEmptyState() {
        const {
            query, searching, searched, results, error,
            browseLoading, browseError, loadingCompanies, compId,
        } = this.state;
        const browsing = !this.isSearching();

        if (loadingCompanies) {
            return (
                <View style={styles.stateBox}>
                    <ActivityIndicator size="large" color={C.primary} />
                </View>
            );
        }

        // The company list failing is not the same as a search failing: without
        // it there is nothing to search, so retrying THAT is the only way on.
        if (error && !compId) {
            return (
                <View style={styles.stateBox}>
                    <Text style={styles.stateError}>{error}</Text>
                    <TouchableOpacity
                        style={styles.retryBtn}
                        activeOpacity={0.7}
                        onPress={this.loadCompanies}
                    >
                        <Text style={styles.retryText}>Retry</Text>
                    </TouchableOpacity>
                </View>
            );
        }

        if (searching || (browsing && browseLoading)) {
            return (
                <View style={styles.stateBox}>
                    <ActivityIndicator color={C.primary} />
                </View>
            );
        }

        const failure = browsing ? browseError : error;
        if (failure) {
            return (
                <View style={styles.stateBox}>
                    <Text style={styles.stateError}>{failure}</Text>
                    <TouchableOpacity
                        style={styles.retryBtn}
                        activeOpacity={0.7}
                        onPress={browsing ? () => this.loadBrowse(true) : this.submitSearch}
                    >
                        <Text style={styles.retryText}>Retry</Text>
                    </TouchableOpacity>
                </View>
            );
        }

        if (browsing) {
            return (
                <View style={styles.stateBox}>
                    <Text style={styles.stateTitle}>Nobody here</Text>
                    <Text style={styles.stateHint}>
                        {this.activeCompanyName()} has nobody listed under{' '}
                        {this.activeDepartmentName().toLowerCase()}.
                    </Text>
                </View>
            );
        }

        if (searched && results.length === 0) {
            return (
                <View style={styles.stateBox}>
                    <Text style={styles.stateTitle}>No match</Text>
                    <Text style={styles.stateHint}>
                        Nobody in {this.activeCompanyName()}
                        {this.state.department === null
                            ? ''
                            : ' under ' + this.activeDepartmentName()}{' '}
                        matches “{query.trim()}”. Try a different company or department, or
                        clear the box to browse everyone.
                    </Text>
                </View>
            );
        }

        return null;
    }

    /**
     * The frame both pickers sit in.
     *
     * An in-page overlay, NOT a <Modal>: a Modal is its own native window, which
     * is how the card once ended up laid out against something other than the
     * list it belongs to. An absolutely-filled View has a parent whose size is
     * known, so the card centres against what you can actually see.
     *
     * The height cap is a pixel value read at render time, not a percentage:
     * percentages resolve against a parent whose own height has to be definite
     * first. Reading it per render also means rotation is handled.
     */
    renderPickerFrame(title, onClose, children) {
        const maxHeight = Math.round(Dimensions.get('window').height * 0.7);

        return (
            <View style={styles.pickerOverlay}>
                {/* The backdrop is its own layer behind the card, so dismissing
                    by tapping outside cannot be confused with a tap on a row. */}
                <TouchableOpacity
                    style={StyleSheet.absoluteFill}
                    activeOpacity={1}
                    onPress={onClose}
                />
                <View style={[styles.pickerCard, { maxHeight }]}>
                    <View style={styles.pickerHead}>
                        <Text style={styles.pickerTitle}>{title}</Text>
                        <TouchableOpacity
                            onPress={onClose}
                            hitSlop={{ top: 12, bottom: 12, left: 12, right: 12 }}
                        >
                            <Text style={styles.pickerClose}>{'✕'}</Text>
                        </TouchableOpacity>
                    </View>
                    <ScrollView
                        style={styles.pickerList}
                        contentContainerStyle={styles.pickerListContent}
                        keyboardShouldPersistTaps="handled"
                    >
                        {children}
                    </ScrollView>
                </View>
            </View>
        );
    }

    renderCompanyPicker() {
        const { companyPickerOpen, companies, compId } = this.state;
        if (!companyPickerOpen) {
            return null;
        }

        return this.renderPickerFrame(
            'Company',
            () => this.setState({ companyPickerOpen: false }),
            companies.map((c) => {
                const active = c.comp_id === compId;
                // One total, not a staff/GW split: the number says how big the
                // company is, and the list it opens does not separate them.
                const total = c.staff_count + c.gw_count;
                return (
                    <TouchableOpacity
                        key={c.comp_id}
                        style={[styles.pickerRow, active && styles.pickerRowActive]}
                        activeOpacity={0.7}
                        onPress={() => this.selectCompany(c.comp_id)}
                    >
                        <View style={styles.pickerRowText}>
                            <Text
                                style={[styles.pickerRowName, active && styles.pickerRowNameActive]}
                                numberOfLines={2}
                            >
                                {c.name}
                            </Text>
                            <Text style={styles.pickerRowCount}>
                                {total + (total === 1 ? ' person' : ' people')}
                            </Text>
                        </View>
                        {active && <Text style={styles.pickerTick}>{'✓'}</Text>}
                    </TouchableOpacity>
                );
            })
        );
    }

    renderDepartmentPicker() {
        const { deptPickerOpen, departments, gwDistricts, department } = this.state;
        if (!deptPickerOpen) {
            return null;
        }

        const row = (opt, key) => {
            const active = opt.value === department;
            return (
                <TouchableOpacity
                    key={key}
                    style={[styles.pickerRow, active && styles.pickerRowActive]}
                    activeOpacity={0.7}
                    onPress={() => this.selectDepartment(opt.value)}
                >
                    <View style={styles.pickerRowText}>
                        <Text
                            style={[styles.pickerRowName, active && styles.pickerRowNameActive]}
                            numberOfLines={2}
                        >
                            {opt.name === null ? NO_DEPARTMENT_LABEL : opt.name}
                        </Text>
                        {opt.count !== undefined && (
                            <Text style={styles.pickerRowCount}>{opt.count} people</Text>
                        )}
                    </View>
                    {active && <Text style={styles.pickerTick}>{'✓'}</Text>}
                </TouchableOpacity>
            );
        };

        const options = [row({ name: 'All departments', value: null }, 'all')];

        if (departments.length > 0) {
            options.push(
                <Text key="dh" style={styles.pickerGroup}>
                    Departments
                </Text>
            );
            departments.forEach((d, i) => options.push(row(d, 'd' + i)));
        }

        // Districts, not departments — a GW's monthly_assign_gw_district is the
        // nearest thing they have, so it is labelled for what it is.
        if (gwDistricts.length > 0) {
            options.push(
                <Text key="gh" style={styles.pickerGroup}>
                    GW districts
                </Text>
            );
            gwDistricts.forEach((d, i) => options.push(row(d, 'g' + i)));
        }

        return this.renderPickerFrame(
            'Department',
            () => this.setState({ deptPickerOpen: false }),
            options
        );
    }

    /** The filters and the box. Part of the list's header, so it scrolls. */
    renderSearchBar() {
        const { query } = this.state;

        return (
            <View style={styles.searchBar}>
                {/* Side by side: two filters that narrow the same list, and
                    stacking them pushed the results below the fold. */}
                <View style={styles.filterRow}>
                    <TouchableOpacity
                        style={[styles.companyBtn, styles.filterCell]}
                        activeOpacity={0.7}
                        onPress={() => this.setState({ companyPickerOpen: true })}
                    >
                        <Text style={styles.companyBtnLabel}>Company</Text>
                        <View style={styles.companyBtnRow}>
                            <Text style={styles.companyBtnName} numberOfLines={1}>
                                {this.activeCompanyName()}
                            </Text>
                            <Text style={styles.companyBtnCaret}>{'\u25BE'}</Text>
                        </View>
                    </TouchableOpacity>

                    <TouchableOpacity
                        style={[styles.companyBtn, styles.filterCell, styles.filterCellLast]}
                        activeOpacity={0.7}
                        onPress={() => this.setState({ deptPickerOpen: true })}
                    >
                        <Text style={styles.companyBtnLabel}>Department</Text>
                        <View style={styles.companyBtnRow}>
                            <Text style={styles.companyBtnName} numberOfLines={1}>
                                {this.activeDepartmentName()}
                            </Text>
                            <Text style={styles.companyBtnCaret}>{'\u25BE'}</Text>
                        </View>
                    </TouchableOpacity>
                </View>

                <View style={styles.inputWrap}>
                    <Text style={styles.inputIcon}>{'\u2315'}</Text>
                    <TextInput
                        style={styles.input}
                        value={query}
                        onChangeText={this.onChangeQuery}
                        placeholder="Name, email, mobile, dept, position, job spec"
                        placeholderTextColor={C.faint}
                        autoCorrect={false}
                        autoCapitalize="none"
                        autoFocus
                        returnKeyType="search"
                        onSubmitEditing={this.submitSearch}
                    />
                    {query.length > 0 && (
                        <TouchableOpacity
                            onPress={() => this.onChangeQuery('')}
                            hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}
                        >
                            <Text style={styles.inputClear}>{'\u2715'}</Text>
                        </TouchableOpacity>
                    )}
                </View>
            </View>
        );
    }

    /**
     * The one list, in either mode.
     *
     * Browse is grouped into department sections (the server already ordered
     * the rows that way). Search results stay in one flat, name-ordered run:
     * when you are hunting one person, cutting ten hits across six department
     * headers hides the thing you came for.
     */
    render() {
        const {
            results, browseRows, searching, browseTruncated,
            loadingCompanies, error, compId,
        } = this.state;

        const browsing = !this.isSearching();
        const rows = browsing ? browseRows : results;
        // Nothing to filter by yet, or nothing to filter: either way the box
        // would be a control over an empty world, so it waits.
        const ready = !loadingCompanies && !(error && !compId);

        const sections = (!ready || rows.length === 0)
            ? []
            : (browsing ? buildSections(rows) : [{ title: null, data: rows }]);

        return (
            <View style={styles.wrap}>
                <SectionList
                    sections={sections}
                    keyExtractor={rowKey}
                    renderItem={({ item }) => (
                        <ResultRow row={item} onPress={this.props.onSelect} />
                    )}
                    renderSectionHeader={({ section }) =>
                        section.title === null ? null : (
                            <View style={styles.sectionHeader}>
                                <Text style={styles.sectionTitle} numberOfLines={1}>
                                    {section.title}
                                </Text>
                                <Text style={styles.sectionCount}>{section.data.length}</Text>
                            </View>
                        )
                    }
                    /* Sticky headers are OFF on purpose. Rows here are variable
                       height (a job-spec match adds two lines), and a sticky
                       header over rows whose heights are only measured as they
                       scroll into view re-pins itself constantly — the department
                       label jumping up and down. Scrolling with the content, it
                       sits still. */
                    stickySectionHeadersEnabled={false}
                    /* removeClippedSubviews is OFF on purpose too: on Android it
                       detaches rows that are still on screen, which is what left a
                       blank strip and a list that would not scroll to its end. The
                       SectionList already virtualises; this only added a bug. */
                    removeClippedSubviews={false}
                    contentContainerStyle={styles.listContent}
                    keyboardShouldPersistTaps="handled"
                    keyboardDismissMode="on-drag"
                    initialNumToRender={12}
                    windowSize={11}
                    /* An ELEMENT, not a component or a function: a function
                       prop is a new component type on every keystroke, which
                       remounts the header and takes the keyboard away from the
                       box inside it. An element just re-renders. */
                    ListHeaderComponent={
                        <View>
                            <View style={styles.headBleed}>
                                {this.props.header || null}
                                {ready ? this.renderSearchBar() : null}
                            </View>
                            {rows.length > 0 && (
                                <View style={styles.countRow}>
                                    <Text style={styles.countText}>
                                        {browsing
                                            ? rows.length +
                                              ' in ' +
                                              this.activeCompanyName() +
                                              (this.state.department === null
                                                  ? ''
                                                  : ' \u2022 ' + this.activeDepartmentName())
                                            : rows.length +
                                              ' result' +
                                              (rows.length === 1 ? '' : 's') +
                                              ' in ' +
                                              this.activeCompanyName()}
                                    </Text>
                                    {searching && <ActivityIndicator size="small" color={C.primary} />}
                                </View>
                            )}
                        </View>
                    }
                    ListEmptyComponent={this.renderEmptyState()}
                    ListFooterComponent={
                        browsing && browseTruncated && rows.length > 0 ? (
                            <Text style={styles.truncatedNote}>
                                Showing the first {rows.length}. Narrow by department, or search,
                                to see the rest.
                            </Text>
                        ) : null
                    }
                />

                {this.renderCompanyPicker()}
                {this.renderDepartmentPicker()}
            </View>
        );
    }
}

const styles = StyleSheet.create({
    wrap: { flex: 1, backgroundColor: C.bg },

    // The header escapes the list's padding so the brand bar and the filter
    // band stay full-bleed while scrolling with everything else.
    headBleed: { marginTop: -12, marginHorizontal: -12, marginBottom: 12 },

    /* search bar */
    searchBar: {
        backgroundColor: C.surface,
        paddingHorizontal: 14,
        paddingTop: 12,
        paddingBottom: 12,
        borderTopWidth: 1,
        borderBottomWidth: 1,
        borderColor: C.border,
    },
    filterRow: { flexDirection: 'row', marginBottom: 10 },
    filterCell: { flex: 1, marginRight: 8, marginBottom: 0 },
    filterCellLast: { marginRight: 0 },
    companyBtn: {
        borderWidth: 1,
        borderColor: C.border,
        backgroundColor: C.bg,
        borderRadius: 10,
        paddingHorizontal: 12,
        paddingVertical: 8,
        marginBottom: 10,
    },
    companyBtnLabel: {
        fontSize: 10,
        fontWeight: '700',
        color: C.faint,
        letterSpacing: 0.6,
        textTransform: 'uppercase',
    },
    companyBtnRow: { flexDirection: 'row', alignItems: 'center', marginTop: 2 },
    companyBtnName: { flex: 1, fontSize: 14.5, fontWeight: '700', color: C.primary },
    companyBtnCaret: { fontSize: 13, color: C.primary, marginLeft: 8 },

    inputWrap: {
        flexDirection: 'row',
        alignItems: 'center',
        borderWidth: 1.5,
        borderColor: C.border,
        backgroundColor: C.bg,
        borderRadius: 10,
        paddingHorizontal: 10,
    },
    inputIcon: { fontSize: 17, color: C.faint, marginRight: 6 },
    input: {
        flex: 1,
        fontSize: 14.5,
        color: C.text,
        paddingVertical: Platform.OS === 'ios' ? 11 : 7,
    },
    inputClear: { fontSize: 15, color: C.muted, paddingHorizontal: 4 },

    /* results */
    listContent: { padding: 12, paddingBottom: 48 },
    countRow: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        paddingHorizontal: 4,
        paddingBottom: 8,
    },
    countText: { flex: 1, fontSize: 11.5, fontWeight: '700', color: C.muted },
    truncatedNote: {
        fontSize: 11.5,
        color: C.muted,
        textAlign: 'center',
        paddingVertical: 14,
        paddingHorizontal: 20,
        lineHeight: 17,
    },

    /* department section headers (browse mode) */
    sectionHeader: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        backgroundColor: C.bg,
        paddingVertical: 7,
        paddingHorizontal: 4,
        marginBottom: 6,
        borderBottomWidth: 2,
        borderBottomColor: C.primary,
    },
    sectionTitle: {
        flex: 1,
        fontSize: 12,
        fontWeight: '800',
        color: C.primary,
        letterSpacing: 0.5,
        textTransform: 'uppercase',
    },
    sectionCount: { fontSize: 11, fontWeight: '800', color: C.muted, marginLeft: 8 },

    resultRow: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: C.surface,
        borderRadius: 12,
        borderWidth: 1,
        borderColor: C.border,
        borderLeftWidth: 4,
        borderLeftColor: C.primary,
        padding: 10,
        marginBottom: 8,
    },
    resultText: { flex: 1, marginLeft: 11 },
    resultNameRow: { flexDirection: 'row', alignItems: 'center' },
    resultName: { flexShrink: 1, fontSize: 15, fontWeight: '700', color: C.text },
    resultPosition: { fontSize: 12.5, fontWeight: '600', color: C.primary, marginTop: 2 },
    resultMeta: { fontSize: 11.5, color: C.muted, marginTop: 2 },
    chevron: { fontSize: 22, color: C.faint, paddingLeft: 6 },

    matchBox: {
        marginTop: 6,
        backgroundColor: C.primarySoft,
        borderRadius: 7,
        paddingHorizontal: 8,
        paddingVertical: 5,
    },
    matchLabel: {
        fontSize: 9,
        fontWeight: '800',
        color: C.primary,
        letterSpacing: 0.6,
        textTransform: 'uppercase',
    },
    matchText: { fontSize: 11.5, color: C.text, marginTop: 2, lineHeight: 15 },

    gwPill: {
        marginLeft: 6,
        backgroundColor: C.gwSoft,
        borderWidth: 1,
        borderColor: C.gw,
        borderRadius: 8,
        paddingHorizontal: 6,
        paddingVertical: 1,
    },
    gwPillText: { fontSize: 10, fontWeight: '800', color: C.gw },

    /* empty / error states */
    stateBox: { alignItems: 'center', justifyContent: 'center', padding: 30, paddingTop: 44 },
    stateTitle: { fontSize: 15.5, fontWeight: '700', color: C.text, marginBottom: 6 },
    stateHint: { fontSize: 13, color: C.muted, textAlign: 'center', lineHeight: 19 },
    stateError: { fontSize: 13.5, color: C.danger, textAlign: 'center', marginBottom: 12 },
    retryBtn: {
        backgroundColor: C.primary,
        paddingHorizontal: 22,
        paddingVertical: 9,
        borderRadius: 8,
    },
    retryText: { color: '#fff', fontWeight: '700', fontSize: 13.5 },

    /* company / department pickers */
    pickerOverlay: {
        ...StyleSheet.absoluteFillObject,
        backgroundColor: 'rgba(15, 23, 42, 0.45)',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 20,
    },
    pickerCard: {
        width: '100%',
        maxWidth: 420,
        backgroundColor: C.surface,
        borderRadius: 14,
        padding: 14,
        // maxHeight is set per render from Dimensions. overflow:hidden matters:
        // RN Views default to overflow:'visible', so without it a list taller
        // than the card paints straight past its rounded bottom edge.
        overflow: 'hidden',
    },
    pickerHead: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        marginBottom: 10,
    },
    pickerTitle: { fontSize: 16, fontWeight: '800', color: C.text },
    pickerClose: { fontSize: 17, color: C.muted, paddingHorizontal: 4 },
    // flexShrink: 1 is the fix for the list running off the bottom. RN defaults
    // flexShrink to 0 (unlike the web), so the ScrollView kept its full content
    // height instead of shrinking into the card — and so never scrolled.
    pickerList: { flexGrow: 0, flexShrink: 1 },
    pickerListContent: { paddingBottom: 4 },
    pickerRow: {
        flexDirection: 'row',
        alignItems: 'center',
        borderWidth: 1,
        borderColor: C.border,
        backgroundColor: C.bg,
        borderRadius: 10,
        paddingHorizontal: 12,
        paddingVertical: 11,
        marginBottom: 6,
    },
    pickerRowActive: { borderColor: C.primary, backgroundColor: C.primarySoft },
    pickerRowText: { flex: 1 },
    pickerRowName: { fontSize: 14.5, fontWeight: '600', color: C.text },
    pickerRowNameActive: { color: C.primary, fontWeight: '800' },
    pickerRowCount: { fontSize: 11, color: C.muted, marginTop: 2 },
    pickerGroup: {
        fontSize: 10,
        fontWeight: '800',
        color: C.faint,
        letterSpacing: 0.7,
        textTransform: 'uppercase',
        marginTop: 10,
        marginBottom: 6,
        marginLeft: 2,
    },
    pickerTick: { fontSize: 15, fontWeight: '800', color: C.primary, marginLeft: 8 },
});
