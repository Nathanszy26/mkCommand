<?php
/**
 * Shared logic for the Staff and GW self-attendance reports.
 *
 * SRP: this file owns (a) period + late configuration, (b) the date-range filter
 * (UI/resolution/SQL), (c) day classification (OK/LATE/MISSING/FUTURE/HOLIDAY/LEAVE),
 * and (d) both renderings — single-day (photos) and multi-day calendar. It does NO
 * entity querying; each report builds its own SELECT and feeds normalised entries here.
 *
 * An "entry" is one self check-in, normalised to:
 *   ['date' => 'Y/m/d', 'time' => 'HH:MM:SS', 'verify' => 'Face|Manual|',
 *    'remarks' => '...', 'photo_url' => '...|']
 *
 * PHP 7.2 compatible.
 */

// Self check-in photo web bases (mirror getGwAttendanceSelfAPI.php exactly).
define('ATT_STAFF_IMG_URL_BASE', 'https://globportal.com/accounts/gwAttendance/staff_attachments/');
define('ATT_GW_IMG_URL_BASE',    'https://globportal.com/accounts/gwAttendance/attachments/');

// A check-in must be at least this many seconds past the scheduled time to be LATE.
// 60 => 8:00:00–8:00:59 is on time, 8:01:00 is the first LATE second (late = 1 min).
define('ATT_LATE_GRACE_SECONDS', 60);

// Render mode: 'screen' (default) | 'excel' | 'pdf'. Excel drops images.
$ATT_MODE = 'screen';
function attSetMode($m) { global $ATT_MODE; $ATT_MODE = $m; }
function attMode()      { global $ATT_MODE; return $ATT_MODE; }

/** Command: Excel (.xls via HTML) download headers. */
function attExcelDownloadHeaders($filenameBase)
{
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.xls"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');
}

/** Command: open a bare export document (shared print styles for both Excel and PDF). */
function attExportDocStart($title, $mode)
{
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>" . htmlspecialchars($title) . "</title>";
    echo "<style>
        body { font-family:Arial, Helvetica, sans-serif; color:#333; margin:12px; }
        h2 { font-size:16px; margin:0 0 10px 0; }
        table { border-collapse:collapse; width:100%; }
        th, td { border:1px solid #333; padding:4px 6px; font-size:11px; vertical-align:middle; text-align:center; }
        thead th { background:#f2f2f2; }
        img { max-width:60px; max-height:60px; }
        .att-toolbar { margin:0 0 14px 0; }
        .att-toolbar button { font-size:14px; padding:8px 18px; cursor:pointer; border:0; border-radius:4px; background:#d9534f; color:#fff; }
        .att-toolbar span { color:#777; font-size:12px; margin-left:10px; }
        @media print { .noprint { display:none !important; } body { margin:0; } }";

    if ($mode === 'pdf')
    {
        // Landscape + fit-to-width (fixed layout squeezes all day columns onto the page,
        // no matter how many) + force background colours so red/holiday/leave cells print.
        echo "
        @page { size: A4 landscape; margin: 8mm; }
        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        table { table-layout: fixed; width: 100%; }
        th, td { font-size: 9px; padding: 3px; word-wrap: break-word; overflow-wrap: break-word; }
        img { max-width: 40px; max-height: 40px; }
        thead { display: table-header-group; }        /* repeat column headers on every page */
        tr { page-break-inside: avoid; }
        tbody { page-break-inside: avoid; }            /* keep each person's rows together */";
    }

    echo "</style></head><body>";

    if ($mode === 'pdf')
    {
        echo "<div class='att-toolbar noprint'>"
           . "<button type='button' onclick='window.print();'>&#128424; Print / Save as PDF</button>"
           . "<span>Choose \"Save as PDF\" as the destination.</span>"
           . "</div>";
    }

    echo "<h2>" . htmlspecialchars($title) . "</h2>";
}

/** Command: close the export document. PDF mode attempts to open the print dialog. */
function attExportDocEnd($mode)
{
    if ($mode === 'pdf')
    {
        // Resilient auto-print: fire shortly after load, but don't hang forever on remote
        // images. The visible button is always available if the browser blocks auto-print.
        echo "<script>
            (function () {
                var printed = false;
                function go() { if (printed) { return; } printed = true; try { window.focus(); window.print(); } catch (e) {} }
                if (document.readyState === 'complete') { setTimeout(go, 400); }
                else { window.addEventListener('load', function () { setTimeout(go, 400); }); }
                setTimeout(go, 2500);   // fallback if load stalls on images
            })();
        </script>";
    }
    echo "</body></html>";
}

/**
 * Command: echo the Export Excel / Export PDF buttons. They re-run the current filters
 * via GET in a new tab, adding export=excel|pdf. Expects the filter form id 'form_submit'.
 */
function attRenderExportButtons()
{
?>
                          <button type="button" class="btn btn-success" onclick="attExport('excel')"><i class="fa fa-download"></i> Export Excel</button>
                          <button type="button" class="btn btn-danger" onclick="attExport('pdf')"><i class="fa fa-print"></i> Export PDF</button>
                          <script type="text/javascript">
                            function attExport(mode) {
                              var f = document.getElementById('form_submit');
                              var p = new URLSearchParams();
                              for (var i = 0; i < f.elements.length; i++) { if (f.elements[i].name) { p.set(f.elements[i].name, f.elements[i].value); } }
                              p.set('runScript', 'Show Report');
                              p.set('export', mode);
                              window.open(window.location.pathname + '?' + p.toString(), '_blank');
                            }
                          </script>
<?php
}

// Reported slots (code => display label), in order. The KEY is exactly the string the
// app writes into attendance_period / gw_attendance_period — single source of truth.
$ATT_PERIODS = array(
    '8AM'    => '8:00am',
    '1PM'    => '1:00pm',
    '4.30PM' => '4:30pm',
    '7PM'    => '7:00pm (OT)',
    '12AM'   => '12:00am (OT)',
    '4AM'    => '4:00am (OT)',
);

// Scheduled time per slot (code => HH:MM:SS). Single source for "late".
$ATT_PERIOD_TIME = array(
    '8AM'    => '08:00:00',
    '1PM'    => '13:00:00',
    '4.30PM' => '16:30:00',
    '7PM'    => '19:00:00',
    '12AM'   => '00:00:00',
    '4AM'    => '04:00:00',
);

// Which slots enforce lateness. false = any check-in counts as OK (we only track a
// missing submission for that slot); the check-in time is still shown, just never LATE.
$ATT_PERIOD_LATE_CHECK = array(
    '8AM'    => true,
    '1PM'    => true,
    '4.30PM' => false,
    '7PM'    => false,
    '12AM'   => false,
    '4AM'    => false,
);

// ─────────────────────────── OVERTIME ───────────────────────────

// Overtime slots. OT is optional work: never LATE, never MISSING, never counted in
// Total Missing. A slot with no check-in is 'na' (a neutral dash), not a red cell —
// marking an optional slot as missing would make the report lie.
$ATT_PERIOD_OT = array(
    '7PM'  => true,
    '12AM' => true,
    '4AM'  => true,
);

/** Query: is this an overtime slot? */
function attIsOtPeriod($periodCode)
{
    global $ATT_PERIOD_OT;
    return isset($ATT_PERIOD_OT[$periodCode]);
}

// OT filter for the current request:
//   'none' (default) — no OT columns at all, every person listed (the plain report);
//   'all'            — all three OT columns, and ONLY people who worked at least one;
//   an OT code       — that one OT column, and ONLY people who worked it.
$ATT_OT_FILTER = 'none';

/** Command: set the request's OT filter. Anything unrecognised falls back to 'none'. */
function attSetOtFilter($v)
{
    global $ATT_OT_FILTER;
    $ATT_OT_FILTER = (attIsOtPeriod($v) || $v === 'all') ? $v : 'none';
}

/** Query: the current OT filter. */
function attOtFilter()
{
    global $ATT_OT_FILTER;
    return $ATT_OT_FILTER;
}

/**
 * Query: the slots to render for these days under the current OT filter. Single owner of
 * the column set + order, so headers, cells and rowspans can never drift apart.
 * A slot retired on EVERY day of the range is dropped; one still live on any day stays.
 * The normal slots otherwise always render — the OT filter scopes the OT columns only.
 */
function attActivePeriods($days)
{
    global $ATT_PERIODS;

    $ot  = attOtFilter();
    $out = array();
    foreach ($ATT_PERIODS as $code => $label)
    {
        if (!attPeriodLiveInDays($code, $days)) { continue; }                     // retired for the whole range
        if (!attIsOtPeriod($code))          { $out[$code] = $label; continue; }   // normal slot: always
        if ($ot === 'all' || $ot === $code) { $out[$code] = $label; }             // 'none' adds no OT column
    }
    return $out;
}

/** Query->display: label for the current OT filter, or '' when it is 'none'. */
function attOtFilterLabel()
{
    global $ATT_PERIODS;
    $ot = attOtFilter();
    if ($ot === 'all')             { return 'All OT'; }
    if (isset($ATT_PERIODS[$ot]))  { return $ATT_PERIODS[$ot]; }
    return '';
}

/** Query: does this person's grid hold at least one check-in for a slot? */
function attGridHasPeriod($grid, $periodCode)
{
    if (!isset($grid[$periodCode])) { return false; }
    foreach ($grid[$periodCode] as $dayEntries)
    {
        if (count($dayEntries) > 0) { return true; }
    }
    return false;
}

/**
 * Query: keep this person in the report under the current OT filter?
 * 'none' keeps everyone; 'all' keeps anyone who worked ANY OT slot; an OT code keeps
 * only those who worked that slot.
 */
function attPersonPassesOtFilter($grid)
{
    global $ATT_PERIOD_OT;

    $ot = attOtFilter();
    if ($ot === 'none') { return true; }
    if ($ot === 'all')
    {
        foreach ($ATT_PERIOD_OT as $code => $on)
        {
            if (attGridHasPeriod($grid, $code)) { return true; }
        }
        return false;
    }
    return attGridHasPeriod($grid, $ot);
}

/**
 * Command: echo the OT filter form-group. Shared by the Staff and GW reports (DRY).
 * Option text is built from $ATT_PERIODS, so it always matches the column headers.
 */
function attRenderOtFilter($selected)
{
    global $ATT_PERIODS, $ATT_PERIOD_OT;

    $otLabels = array();
    foreach ($ATT_PERIOD_OT as $code => $on) { $otLabels[] = str_replace(' (OT)', '', $ATT_PERIODS[$code]); }
    $allLabel = 'All OT (' . implode(', ', $otLabels) . ')';
?>
                      <div class="form-group">
                        <label class="control-label col-md-3 col-sm-3 col-xs-12">Overtime</label>
                        <div class="col-md-6 col-sm-6 col-xs-12">
                          <select id="report_ot" name="report_ot" class="form-control">
                            <option value="none" <?php if ($selected === 'none') { echo 'selected'; } ?>></option>
                            <option value="all" <?php if ($selected === 'all') { echo 'selected'; } ?>><?php echo htmlspecialchars($allLabel); ?></option>
                            <?php
                                foreach ($ATT_PERIOD_OT as $code => $on)
                                {
                                    $sel = ($selected === $code) ? 'selected' : '';
                                    echo "<option value='" . htmlspecialchars($code) . "' $sel>" . htmlspecialchars($ATT_PERIODS[$code]) . "</option>";
                                }
                            ?>
                          </select>
                          <span class="help-block" style="margin-bottom:0;">Leave blank for the normal report. Choosing an OT option adds its column(s) and lists only the people who checked in for it.</span>
                        </div>
                      </div>
<?php
}

// ─────────────────────────── SLOT POLICY (retired / optional) ───────────────────────────

// Per-report slot policy. The report owns the policy (sets it); this file owns the
// mechanism. Both are empty by default, so a report that sets nothing (GW) renders as before.
//   retired : code => array(fromYmd, toYmd|null) — the slot does not exist on those days
//             (inclusive; null = open-ended). Cells are 'na', and the column is dropped when
//             the slot is retired for the whole range.
//   optional: code => fromYmd — from that day on, the slot follows the OT rules: a check-in
//             is never LATE, and no check-in is never MISSING.
$ATT_PERIOD_RETIRED  = array();
$ATT_PERIOD_OPTIONAL = array();

/** Guard: fail loud on an unknown slot code. */
function attAssertPeriod($fn, $periodCode)
{
    global $ATT_PERIODS;
    if (!isset($ATT_PERIODS[$periodCode]))
    {
        throw new InvalidArgumentException($fn . ": unknown period '" . $periodCode . "'");
    }
}

/** Guard: fail loud unless $ymd is a real Y/m/d date. */
function attAssertYmd($fn, $ymd)
{
    $p = explode('/', (string)$ymd);
    if (!preg_match('#^\d{4}/\d{2}/\d{2}$#', (string)$ymd) || !checkdate((int)$p[1], (int)$p[2], (int)$p[0]))
    {
        throw new InvalidArgumentException($fn . ": date must be a valid Y/m/d, got '" . $ymd . "'");
    }
}

/** Command: retire a slot for $fromYmd..$toYmd (Y/m/d, inclusive). $toYmd null = open-ended. */
function attRetirePeriod($periodCode, $fromYmd, $toYmd = null)
{
    global $ATT_PERIOD_RETIRED;

    attAssertPeriod('attRetirePeriod', $periodCode);
    attAssertYmd('attRetirePeriod', $fromYmd);
    if ($toYmd !== null)
    {
        attAssertYmd('attRetirePeriod', $toYmd);
        if ($toYmd < $fromYmd)
        {
            throw new InvalidArgumentException("attRetirePeriod: '" . $toYmd . "' is before '" . $fromYmd . "'");
        }
    }
    $ATT_PERIOD_RETIRED[$periodCode] = array($fromYmd, $toYmd);
}

/** Command: make a slot optional from $fromYmd (Y/m/d, inclusive) onward. */
function attMakePeriodOptionalFrom($periodCode, $fromYmd)
{
    global $ATT_PERIOD_OPTIONAL;

    attAssertPeriod('attMakePeriodOptionalFrom', $periodCode);
    attAssertYmd('attMakePeriodOptionalFrom', $fromYmd);
    $ATT_PERIOD_OPTIONAL[$periodCode] = $fromYmd;
}

/** Query: is this slot retired on this day? Y/m/d is fixed-width, so string compare is chronological. */
function attIsPeriodRetired($periodCode, $dayYmd)
{
    global $ATT_PERIOD_RETIRED;

    if (!isset($ATT_PERIOD_RETIRED[$periodCode])) { return false; }
    $w = $ATT_PERIOD_RETIRED[$periodCode];
    return $dayYmd >= $w[0] && ($w[1] === null || $dayYmd <= $w[1]);
}

/** Query: is this slot optional on this day? OT is always optional. */
function attIsPeriodOptional($periodCode, $dayYmd)
{
    global $ATT_PERIOD_OPTIONAL;

    if (attIsOtPeriod($periodCode)) { return true; }
    return isset($ATT_PERIOD_OPTIONAL[$periodCode]) && $dayYmd >= $ATT_PERIOD_OPTIONAL[$periodCode];
}

/** Query: is this slot still collected on at least one day of the range? */
function attPeriodLiveInDays($periodCode, $days)
{
    foreach ($days as $d)
    {
        if (!attIsPeriodRetired($periodCode, $d['ymd'])) { return true; }
    }
    return false;
}

// ─────────────────────────── GW POOL ───────────────────────────

/**
 * Query->SQL: the "this GW counts" condition for evaluation.monthly_assign_gw.
 * Single owner of the rule, so the GW report and the geofence setting page can never
 * disagree about who exists. NULL is not-excluded — a bare != '1' evaluates to NULL and
 * would silently drop every GW whose column has never been written.
 * Caller supplies the comp_id / district conditions; this owns status + KPI exclusion.
 */
function attGwActiveSql()
{
    return " AND monthly_assign_gw_status LIKE '%1%'"
         . " AND (kpi_scorecard_exclude IS NULL OR kpi_scorecard_exclude != '1')";
}

// ─────────────────────────── DATE RANGE ───────────────────────────

/**
 * Query: resolve the requested range from $_REQUEST into concrete bounds.
 * day  -> single 'select_date'.
 * week -> Mon–Sun of 'week_start' (a Monday; falls back to the week of select_date).
 * custom -> 'date_from'..'date_to'.
 * attendance_by_date is fixed-width Y/m/d, so string BETWEEN is chronological.
 */
function attResolveRange($req)
{
    $mode = isset($req['range_type']) ? $req['range_type'] : 'day';
    if (!in_array($mode, array('day', 'week', 'custom'))) { $mode = 'day'; }

    $select_date = (isset($req['select_date']) && $req['select_date'] !== '') ? $req['select_date'] : date('Y-m-d');
    $from_in     = (isset($req['date_from'])   && $req['date_from']   !== '') ? $req['date_from']   : $select_date;
    $to_in       = (isset($req['date_to'])     && $req['date_to']     !== '') ? $req['date_to']     : $select_date;

    if ($mode === 'week')
    {
        if (isset($req['week_start']) && $req['week_start'] !== '')
        {
            $mon = strtotime($req['week_start']);
        }
        else
        {
            $ts  = strtotime($select_date);
            $dow = date('N', $ts);
            $mon = strtotime('-' . ($dow - 1) . ' days', $ts);
        }
        $from = date('Y-m-d', $mon);
        $to   = date('Y-m-d', strtotime('+6 days', $mon));
    }
    else if ($mode === 'custom')
    {
        $from = $from_in;
        $to   = $to_in;
        if (strtotime($to) < strtotime($from)) { $tmp = $from; $from = $to; $to = $tmp; }
    }
    else // day
    {
        $from = $select_date;
        $to   = $select_date;
    }

    return array(
        'mode'        => $mode,
        'select_date' => $select_date,                 // Y-m-d (picker)
        'from_ymd'    => $from,                         // Y-m-d
        'to_ymd'      => $to,                           // Y-m-d
        'from'        => str_replace('-', '/', $from),  // Y/m/d (query + day keys)
        'to'          => str_replace('-', '/', $to),    // Y/m/d (query)
        'multi'       => ($from !== $to),
    );
}

/** Query->SQL: the date condition for a range, escaped. Works for a single day too. */
function attDateSql($column, $range, $db)
{
    $f = mysql_real_escape_string($range['from'], $db);
    $t = mysql_real_escape_string($range['to'], $db);
    return " AND $column BETWEEN '$f' AND '$t'";
}

/** Query->display: header label for the chosen range. */
function attRangeLabel($range)
{
    if ($range['mode'] === 'week')
    {
        return 'Week ' . date('W', strtotime($range['from_ymd']))
             . ' (' . date('d M Y', strtotime($range['from_ymd'])) . ' &ndash; ' . date('d M Y', strtotime($range['to_ymd'])) . ')';
    }
    if ($range['multi'])
    {
        return date('d M Y', strtotime($range['from_ymd'])) . ' &ndash; ' . date('d M Y', strtotime($range['to_ymd']));
    }
    return date('d M Y', strtotime($range['from_ymd']));
}

/** Query: expand a range into an ordered list of day descriptors. */
function attRangeDays($range)
{
    $days = array();
    $cur  = strtotime($range['from_ymd']);
    $end  = strtotime($range['to_ymd']);
    while ($cur <= $end)
    {
        $days[] = array(
            'ymd'     => date('Y/m/d', $cur),   // matches entry['date'] keys
            'dow'     => date('D', $cur),
            'dom'     => date('j', $cur),
            'weekend' => in_array(date('N', $cur), array(6, 7)),
        );
        $cur = strtotime('+1 day', $cur);
    }
    return $days;
}

/** Query: week dropdown options for the current year (value = Monday Y-m-d). */
function attWeekOptions()
{
    $opts = array();
    $year = date('Y');
    $jan1 = strtotime($year . '-01-01');
    $dow  = date('N', $jan1);
    $mon  = strtotime('-' . ($dow - 1) . ' days', $jan1);   // Monday on/before Jan 1
    $dec31 = strtotime($year . '-12-31');
    while ($mon <= $dec31)
    {
        $sun = strtotime('+6 days', $mon);
        $opts[] = array(
            'value' => date('Y-m-d', $mon),
            'label' => 'Week ' . date('W', $mon) . ' (' . date('j M', $mon) . ' - ' . date('j M', $sun) . ')',
        );
        $mon = strtotime('+7 days', $mon);
    }
    return $opts;
}

/**
 * Command: echo the range filter form-groups. Day -> date; Week -> week dropdown;
 * Custom -> From/To. One JS toggle keeps only the relevant inputs visible.
 */
function attRenderRangeFilter($range)
{
    $mode = $range['mode'];
    $sel  = htmlspecialchars($range['select_date']);
    $from = htmlspecialchars($range['from_ymd']);
    $to   = htmlspecialchars($range['to_ymd']);

    // Selected Monday for the week dropdown.
    $selMon = ($mode === 'week') ? $range['from_ymd'] : date('Y-m-d', strtotime('-' . (date('N') - 1) . ' days'));
?>
                      <div class="form-group">
                        <label class="control-label col-md-3 col-sm-3 col-xs-12">Report Range</label>
                        <div class="col-md-6 col-sm-6 col-xs-12">
                          <select id="range_type" name="range_type" class="form-control" onchange="attToggleRange()">
                            <option value="day"    <?php if ($mode == 'day')    { echo 'selected'; } ?>>Single Day</option>
                            <option value="week"   <?php if ($mode == 'week')   { echo 'selected'; } ?>>Week</option>
                            <option value="custom" <?php if ($mode == 'custom') { echo 'selected'; } ?>>Custom Range</option>
                          </select>
                        </div>
                      </div>

                      <div class="form-group" id="row_single">
                        <label class="control-label col-md-3 col-sm-3 col-xs-12">Date</label>
                        <div class="col-md-6 col-sm-6 col-xs-12">
                          <input id="select_date" name="select_date" type="date" class="form-control" value="<?php echo $sel; ?>">
                        </div>
                      </div>

                      <div class="form-group" id="row_week">
                        <label class="control-label col-md-3 col-sm-3 col-xs-12">Week</label>
                        <div class="col-md-6 col-sm-6 col-xs-12">
                          <select id="week_start" name="week_start" class="form-control">
                            <?php
                                foreach (attWeekOptions() as $w)
                                {
                                    $selected = ($w['value'] === $selMon) ? 'selected' : '';
                                    echo "<option value='" . $w['value'] . "' $selected>" . htmlspecialchars($w['label']) . "</option>";
                                }
                            ?>
                          </select>
                        </div>
                      </div>

                      <div class="form-group" id="row_from">
                        <label class="control-label col-md-3 col-sm-3 col-xs-12">From</label>
                        <div class="col-md-6 col-sm-6 col-xs-12">
                          <input id="date_from" name="date_from" type="date" class="form-control" value="<?php echo $from; ?>">
                        </div>
                      </div>

                      <div class="form-group" id="row_to">
                        <label class="control-label col-md-3 col-sm-3 col-xs-12">To</label>
                        <div class="col-md-6 col-sm-6 col-xs-12">
                          <input id="date_to" name="date_to" type="date" class="form-control" value="<?php echo $to; ?>">
                        </div>
                      </div>

                      <script type="text/javascript">
                        function attToggleRange() {
                          var t = document.getElementById('range_type').value;
                          document.getElementById('row_single').style.display = (t == 'day')    ? '' : 'none';
                          document.getElementById('row_week').style.display   = (t == 'week')   ? '' : 'none';
                          document.getElementById('row_from').style.display   = (t == 'custom') ? '' : 'none';
                          document.getElementById('row_to').style.display     = (t == 'custom') ? '' : 'none';
                        }
                        window.addEventListener('load', attToggleRange);
                      </script>
<?php
}

// ─────────────────────────── ENTRIES ───────────────────────────

/** Query->display: verify_type enum('photo','manual') to a human label. */
function attVerifyLabel($verifyType)
{
    if ($verifyType === 'manual') { return 'Manual'; }
    if ($verifyType === 'photo')  { return 'Face'; }
    return '';
}

/** Query->entry: build one normalised entry (single mapping point). */
function attEntry($date, $time, $verifyType, $remarks, $photoUrl, $lat = '', $lng = '')
{
    return array(
        'date'      => $date,
        'time'      => $time,
        'verify'    => attVerifyLabel($verifyType),
        'remarks'   => $remarks,
        'photo_url' => $photoUrl,
        'lat'       => $lat,
        'lng'       => $lng,
    );
}

// ─────────────────────────── HOLIDAY / LEAVE ───────────────────────────

/** Query: public-holiday flag per day of the range (keyed by Y/m/d). Company-wide. */
function attHolidaysByDay($db, $days)
{
    $out = array();
    foreach ($days as $d)
    {
        $p = explode('/', $d['ymd']);   // Y, m, d (zero-padded)
        $out[$d['ymd']] = (CheckPublicHoliday($db, $p[0], $p[1], $p[2]) > 0);
    }
    return $out;
}

/**
 * Query: lazily open (once) and return the HR PDO handle, or null if unavailable.
 * hr.leave_application lives on a separate host; staff can file leave there instead of
 * in gas_usage.driver_leaves, so the leave lookup must consult both.
 * Returns: PDO on success, null if the connection failed (caller degrades gracefully).
 */
function attHrDb()
{
    static $db_hr = false;   // false = not yet attempted; null = failed; PDO = connected
    if ($db_hr !== false) { return $db_hr; }
    try
    {
        $db_hr = new PDO('mysql:host=175.145.207.141;port=3306;dbname=hr;charset=utf8mb4', 'portal', 'portal2018', array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 5,
        ));
    }
    catch (PDOException $e)
    {
        error_log('HR DB connection failed: ' . $e->getMessage());
        $db_hr = null;
    }
    return $db_hr;
}

/**
 * Query (batched): for MANY staff at once, the days (Y/m/d) each is on leave within the
 * range (status 1/2/4), mapped to the leave TYPE for that day. Reads BOTH
 * gas_usage.driver_leaves and hr.leave_application in ONE query each (not per person),
 * so a large staff list stays at two round-trips total instead of two per head.
 * Returns: [ person => [ 'Y/m/d' => leaveType, ... ], ... ] (only persons with leave).
 */
function attStaffLeaveDaysBatch($db, $persons, $range)
{
    $out = array();

    // De-dup + drop blanks so IN(...) is clean and the HR placeholder count lines up.
    $persons = array_values(array_unique(array_filter($persons, function ($v) { return $v !== null && $v !== ''; })));
    if (count($persons) == 0) { return $out; }

    $f = mysql_real_escape_string($range['from_ymd'], $db);   // Y-m-d
    $t = mysql_real_escape_string($range['to_ymd'], $db);

    // person => list of [fromTs, toTs, type]. driver_leaves is appended before HR, so it
    // wins on any day both sources cover (first matching interval per day is kept below).
    $intervalsByPerson = array();

    // ── Primary: gas_usage.driver_leaves (one query for all persons) ──
    $inParts = array();
    foreach ($persons as $pn) { $inParts[] = "'" . mysql_real_escape_string($pn, $db) . "'"; }
    $inList = implode(',', $inParts);

    $sql  = "SELECT driver_leave_person AS person, driver_leave_from AS lf, driver_leave_to AS lt, driver_leave_type AS lty";
    $sql .= " FROM gas_usage.driver_leaves";
    $sql .= " WHERE driver_leave_person IN ($inList)";
    $sql .= " AND (driver_leave_status = '1' OR driver_leave_status = '2' OR driver_leave_status = '4')";
    $sql .= " AND driver_leave_from <= '$t' AND driver_leave_to >= '$f'";
    $result = mysql_query($sql, $db) or die(mysql_error($db));
    while ($row = mysql_fetch_array($result))
    {
        $intervalsByPerson[$row['person']][] = array(strtotime($row['lf']), strtotime($row['lt']), $row['lty']);
    }

    // ── Secondary: hr.leave_application (one query for all persons, PDO) ──
    $db_hr = attHrDb();
    if ($db_hr instanceof PDO)
    {
        $fromSlash = str_replace('-', '/', $range['from_ymd']);   // hr stores dates as Y/m/d
        $toSlash   = str_replace('-', '/', $range['to_ymd']);
        $ph = implode(',', array_fill(0, count($persons), '?'));
        try
        {
            $stmt = $db_hr->prepare(
                "SELECT staff_id, date_from, date_to, leave_type_id FROM leave_application"
              . " WHERE staff_id IN ($ph) AND date_from <= ? AND date_to >= ?"
              . " AND leave_status_id IN ('1','2','4')"
              . " AND (cancel_date IS NULL OR cancel_date = '')"
            );
            $stmt->execute(array_merge($persons, array($toSlash, $fromSlash)));
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC))
            {
                $lf = strtotime(str_replace('/', '-', $r['date_from']));
                $lt = strtotime(str_replace('/', '-', $r['date_to']));
                if ($lf && $lt) { $intervalsByPerson[$r['staff_id']][] = array($lf, $lt, (string)$r['leave_type_id']); }
            }
        }
        catch (PDOException $e) { error_log('HR leave batch lookup failed: ' . $e->getMessage()); }
    }

    // ── Expand each person's intervals to a per-day map (in-memory, no queries) ──
    $from = strtotime($range['from_ymd']);
    $end  = strtotime($range['to_ymd']);
    foreach ($intervalsByPerson as $person => $intervals)
    {
        $days = array();
        $cur  = $from;
        while ($cur <= $end)
        {
            foreach ($intervals as $iv)
            {
                if ($cur >= $iv[0] && $cur <= $iv[1]) { $days[date('Y/m/d', $cur)] = (string)$iv[2]; break; }
            }
            $cur = strtotime('+1 day', $cur);
        }
        if (count($days) > 0) { $out[$person] = $days; }
    }
    return $out;
}

/** Query: single-person convenience wrapper over attStaffLeaveDaysBatch (backward compat). */
function attStaffLeaveDays($db, $person, $range)
{
    $map = attStaffLeaveDaysBatch($db, array($person), $range);
    return isset($map[$person]) ? $map[$person] : array();
}

// ─────────────────────────── CLASSIFY ───────────────────────────

/** Display helper: HH:MM:SS -> "7:52:10AM". */
function attFmtTime($t)
{
    return date('g:i:sA', strtotime('2000-01-01 ' . $t));
}

/** Display helper: minutes -> "13hrs31min" (or "45min" under an hour). */
function attFmtLate($minutes)
{
    $m = (int)$minutes;
    if ($m < 60) { return $m . 'min'; }
    return intval($m / 60) . 'hrs' . ($m % 60) . 'min';
}

/**
 * Query: classify one (person, period, day). States:
 *   ok | late | missing | future | holiday | leave | na.
 * A slot the report has retired for this day is always 'na' (it no longer exists).
 * Worked (has a check-in) always wins. Then holiday/leave are recorded (and are NOT
 * counted as missing); these take precedence over 'future'. An optional slot (OT, or a
 * slot the report made optional for this day) is never LATE, and when empty it is 'na',
 * never a miss. A remaining not-yet-due slot is future; a past required slot is missing.
 */
function attCellStatus($periodCode, $dayEntries, $dayYmd, $isHoliday, $onLeave)
{
    global $ATT_PERIOD_TIME, $ATT_PERIOD_LATE_CHECK;

    // A retired slot does not exist on this day: neutral, never missing, never late.
    if (attIsPeriodRetired($periodCode, $dayYmd)) { return array('state' => 'na'); }

    $optional  = attIsPeriodOptional($periodCode, $dayYmd);
    $scheduled = isset($ATT_PERIOD_TIME[$periodCode]) ? $ATT_PERIOD_TIME[$periodCode] : '00:00:00';
    $dayDash   = str_replace('/', '-', $dayYmd);
    $schedTs   = strtotime($dayDash . ' ' . $scheduled);

    if (count($dayEntries) > 0)
    {
        $e = $dayEntries[0];                                  // earliest = most on-time

        $ciTs = strtotime($dayDash . ' ' . $e['time']);
        $diff = $ciTs - $schedTs;
        // Minutes past the scheduled time (0 if on time / early). Shown as the duration
        // on the cell for every slot; only late-checked slots also flip to LATE.
        $lateMin = ($diff > 0) ? (int)floor($diff / 60) : 0;

        // An optional slot has no schedule to be late against, so the duration is noise.
        if ($optional) { $lateMin = 0; }

        // Slots with late-check off (and every optional slot) are OK on any check-in. We
        // only flag missing ones, but the duration is kept so the cell shows how far past it was.
        $lateChecked = !$optional && (isset($ATT_PERIOD_LATE_CHECK[$periodCode]) ? $ATT_PERIOD_LATE_CHECK[$periodCode] : true);
        if (!$lateChecked || $diff < ATT_LATE_GRACE_SECONDS)
        {
            return array('state' => 'ok', 'time' => $e['time'], 'late_min' => $lateChecked ? 0 : $lateMin, 'photo_url' => $e['photo_url'], 'lat' => $e['lat'], 'lng' => $e['lng'], 'date' => $dayYmd);
        }
        return array('state' => 'late', 'time' => $e['time'], 'late_min' => $lateMin, 'photo_url' => $e['photo_url'], 'lat' => $e['lat'], 'lng' => $e['lng'], 'date' => $dayYmd);
    }

    if ($isHoliday)        { return array('state' => 'holiday'); }
    if ($onLeave)          { return array('state' => 'leave', 'leave_type' => is_string($onLeave) ? $onLeave : ''); }
    if ($optional)         { return array('state' => 'na'); }   // optional: no check-in is not a miss
    if ($schedTs > time()) { return array('state' => 'future'); }
    return array('state' => 'missing');
}

// ─────────────────────────── LOCATION ───────────────────────────

/** Query: are these usable coordinates? Rejects NULL/blank and the 0,0 null-island default. */
function attHasCoords($lat, $lng)
{
    if ($lat === null || $lng === null || $lat === '' || $lng === '') { return false; }
    if (!is_numeric($lat) || !is_numeric($lng))                       { return false; }
    return !((float)$lat == 0 && (float)$lng == 0);
}

/**
 * Command: echo the Location button for one check-in. Screen only — a button is dead
 * weight in Excel/PDF. Silent when there are no coordinates, so records captured before
 * the app started sending them simply show the photo as before.
 */
function attRenderLocationButton($lat, $lng, $label)
{
    if (attMode() !== 'screen')    { return; }
    if (!attHasCoords($lat, $lng)) { return; }

    $l = htmlspecialchars($label, ENT_QUOTES);
    echo "<button type='button' class='btn btn-xs btn-default' style='margin-top:4px; padding:1px 6px; font-size:10px; white-space:nowrap;'"
       . " onclick=\"attShowLocation('" . (float)$lat . "','" . (float)$lng . "','" . $l . "');\">"
       . "<i class='fa fa-map-marker'></i> Location</button>";
}

/**
 * Command: echo the shared location modal + its script. Call ONCE per page, AFTER jQuery
 * and Bootstrap are loaded. Screen only.
 *
 * Reverse geocoding runs on click, never on render: Nominatim's usage policy allows one
 * request per second and forbids bulk querying, and a week's calendar can hold hundreds
 * of check-ins. Each result is cached per coordinate for the life of the page, and only
 * one lookup is ever in flight. The map itself is an OSM embed — no map library, no CDN.
 */
function attRenderLocationModal()
{
    if (attMode() !== 'screen') { return; }
?>
    <style type="text/css">
      /* Gentelella's sidebar sits above Bootstrap's default modal layer, so the dialog
         has to be lifted explicitly — otherwise the left column paints over it. */
      #attLocModal          { z-index: 10050; }
      .modal-backdrop       { z-index: 10040; }
      #attLocModal .modal-dialog { width: 90%; max-width: 950px; margin: 24px auto; }
      /* Viewport-relative so the header, address, map and Close button always fit on
         screen together — a fixed pixel height pushes the footer below the fold. */
      #attLocMap { display: block; height: calc(100vh - 290px); min-height: 300px; }
    </style>

    <div class="modal fade" id="attLocModal" tabindex="-1" role="dialog">
      <div class="modal-dialog" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title">Check-in Location <small id="attLocLabel"></small></h4>
          </div>
          <div class="modal-body">
            <p style="margin-bottom:8px;">
              <strong>Address:</strong> <span id="attLocName">&mdash;</span><br>
              <strong>Coordinates:</strong> <span id="attLocCoords">&mdash;</span>
              &nbsp;&nbsp;<a id="attLocOpen" href="#" target="_blank"></a>
            </p>
            <iframe id="attLocMap" width="100%" height="480" frameborder="0" scrolling="no" style="border:1px solid #ccc;"></iframe>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>

    <script type="text/javascript">
      var attGeoCache   = {};      // 'lat,lng' => resolved address
      var attGeoPending = false;   // one lookup at a time (Nominatim: 1 request/second)
      var attMapSrc     = '';      // map url, loaded only once the modal is actually visible

      // The embedded map measures its own container to fit the bbox. Loading it while the
      // modal is still display:none makes it measure zero and fall back to a world-level
      // zoom, so the src is assigned on 'shown' and cleared on 'hidden' (fresh fit each time).
      $(function () {
        $('#attLocModal')
          .appendTo('body')   // escape any ancestor stacking/overflow context in the layout
          .on('shown.bs.modal',  function () { if (attMapSrc) { document.getElementById('attLocMap').src = attMapSrc; } })
          .on('hidden.bs.modal', function () { document.getElementById('attLocMap').src = 'about:blank'; });
      });

      function attShowLocation(lat, lng, label) {
        var key  = lat + ',' + lng;
        var d    = 0.0025;                                   // ~250m box around the point
        var bbox = (Number(lng) - d) + ',' + (Number(lat) - d) + ',' + (Number(lng) + d) + ',' + (Number(lat) + d);

        document.getElementById('attLocLabel').innerHTML  = label;
        document.getElementById('attLocCoords').innerHTML = lat + ', ' + lng;
        document.getElementById('attLocOpen').href = 'https://www.openstreetmap.org/?mlat=' + lat + '&mlon=' + lng + '#map=18/' + lat + '/' + lng;

        attMapSrc = 'https://www.openstreetmap.org/export/embed.html?bbox=' + bbox + '&layer=mapnik&marker=' + lat + ',' + lng;

        var already = $('#attLocModal').hasClass('in');
        $('#attLocModal').modal('show');
        if (already) { document.getElementById('attLocMap').src = attMapSrc; }   // no 'shown' event when already open

        var nameEl = document.getElementById('attLocName');
        if (attGeoCache[key]) { nameEl.innerHTML = attGeoCache[key]; return; }
        if (attGeoPending)    { nameEl.innerHTML = 'Another lookup is in progress — close and retry in a moment.'; return; }

        nameEl.innerHTML = 'Looking up address...';
        attGeoPending = true;
        $.ajax({
          url: 'https://nominatim.openstreetmap.org/reverse',
          data: { format: 'jsonv2', zoom: '18', addressdetails: '1', lat: lat, lon: lng },
          dataType: 'json',
          timeout: 8000
        }).done(function (r) {
          var name = (r && r.display_name) ? r.display_name : 'No address found for this point.';
          attGeoCache[key] = name;
          nameEl.innerHTML = name;
        }).fail(function (x, status) {
          // Loud, not silent: the map and coordinates are still valid, only the name failed.
          nameEl.innerHTML = '<span style="color:#c0392b;">Address lookup failed (' + status + '). The map below is still correct.</span>';
        }).always(function () {
          attGeoPending = false;
        });
      }
    </script>
<?php
}

// ─────────────────────────── SINGLE-DAY CELL (with photos + status) ───────────────────────────

/** Display: the <td> style for a single-day cell. Only 'missing' gets a red fill. */
function attSingleDayCellStyle($state)
{
    $base = 'vertical-align:top; text-align:center;';
    if ($state === 'missing') { return $base . ' background:#f2dede !important;'; }
    return $base;
}

/**
 * Command: echo one full single-day <td> — status-driven background + inner content.
 * Single owner of the single-day cell, shared by the Staff and GW reports (DRY).
 */
function renderSingleDayTd($periodCode, $dayEntries, $dayYmd, $isHoliday, $onLeave)
{
    $st = attCellStatus($periodCode, $dayEntries, $dayYmd, $isHoliday, $onLeave);
    echo "<td style='" . attSingleDayCellStyle($st['state']) . "'>";
    renderSingleDayCell($st, $dayEntries);
    echo "</td>";
}

/**
 * Command: echo the INNER html of one single-day cell from a precomputed status,
 * with OK/LATE status, plus Public Holiday / On Leave / not-due / not-checked-in,
 * and photos when present.
 */
function renderSingleDayCell($st, $dayEntries)
{
    if ($st['state'] === 'na')      { echo "<div style='color:#bbb; font-size:11px;'>&mdash;</div>"; return; }
    if ($st['state'] === 'future')  { echo "<div style='color:#999; font-size:11px;'>Not due yet</div>"; return; }
    if ($st['state'] === 'holiday') { echo "<div style='color:#31708f; font-weight:bold; font-size:12px;'>Public Holiday</div>"; return; }
    if ($st['state'] === 'leave')   { echo "<div style='color:#6b4fa3; font-weight:bold; font-size:12px;'>On Leave</div>"; if (!empty($st['leave_type'])) { echo "<div style='color:#6b4fa3; font-size:11px;'>" . htmlspecialchars($st['leave_type']) . "</div>"; } return; }
    if ($st['state'] === 'missing') { echo "<div style='color:#c0392b; font-size:11px;'><img src='images/tick_no.jpg' height='12'> Not checked in</div>"; return; }

    $color = ($st['state'] === 'late') ? '#e67e22' : '#27ae60';
    $word  = ($st['state'] === 'late') ? 'LATE'    : 'OK';
    echo "<div style='color:$color; font-weight:bold; font-size:12px;'>$word";
    if (!empty($st['late_min'])) { echo " <span style='font-weight:normal;'>(" . attFmtLate($st['late_min']) . ")</span>"; }
    echo "</div>";

    foreach ($dayEntries as $e)
    {
        echo "<div style='margin-top:4px;'>";
        echo "<div style='font-size:11px; color:#555;'>" . htmlspecialchars($e['time']);
        if ($e['verify'] !== "") { echo " (" . htmlspecialchars($e['verify']) . ")"; }
        echo "</div>";
        $remarks = (isset($e['remarks']) && trim($e['remarks']) !== "") ? $e['remarks'] : "No Remarks";
        echo "<div style='font-size:10px; color:#888;'>" . htmlspecialchars($remarks) . "</div>";
        if (!empty($e['photo_url']) && attMode() === 'screen')
        {
            echo "<a href='" . $e['photo_url'] . "' target='_blank'>"
               . "<img src='" . $e['photo_url'] . "' style='display:block; width:110px; height:110px; object-fit:cover; border-radius:4px; margin:3px auto 0;'></a>";
        }
        attRenderLocationButton(
            isset($e['lat']) ? $e['lat'] : '',
            isset($e['lng']) ? $e['lng'] : '',
            $e['date'] . ' ' . $e['time']
        );
        echo "</div>";
    }
}

// ─────────────────────────── MULTI-DAY CALENDAR ───────────────────────────

/** Display: the <td> inline style for a calendar state. */
function attCalendarCellStyle($state)
{
    $base = 'vertical-align:middle; text-align:center; padding:6px;';
    if ($state === 'missing') { return $base . ' background:#e74c3c !important;'; }
    if ($state === 'future')  { return $base . ' background:#fdf6e3 !important; color:#c9bd8f;'; }
    if ($state === 'holiday') { return $base . ' background:#d9edf7 !important; color:#31708f;'; }
    if ($state === 'leave')   { return $base . ' background:#efe8f7 !important; color:#6b4fa3;'; }
    if ($state === 'na')      { return $base . ' background:#fbfbfb !important; color:#bbb;'; }
    return $base;
}

/** Command: echo the INNER html of one calendar cell. Caller owns the <td> + style. */
function attRenderCalendarCell($st)
{
    $state = $st['state'];
    if ($state === 'na')      { echo "&mdash;"; return; }
    if ($state === 'missing') { echo "&nbsp;"; return; }
    if ($state === 'future')  { echo "-"; return; }
    if ($state === 'holiday') { echo "<strong>PH</strong>"; return; }
    if ($state === 'leave')   { echo "<strong>Leave</strong>"; if (!empty($st['leave_type'])) { echo "<div style='font-size:10px; font-weight:normal;'>" . htmlspecialchars($st['leave_type']) . "</div>"; } return; }

    $color = ($state === 'late') ? '#e67e22' : '#27ae60';
    $word  = ($state === 'late') ? 'LATE'    : 'OK';

    echo "<div style='color:$color; font-weight:bold;'>$word</div>";
    echo "<div style='color:$color; font-size:11px; white-space:nowrap;'>" . attFmtTime($st['time']) . "</div>";
    if (!empty($st['late_min'])) { echo "<div style='color:$color; font-size:11px; white-space:nowrap;'>(" . attFmtLate($st['late_min']) . ")</div>"; }

    // Small thumbnail — click to open the full photo. Screen only: the source images are
    // full-size, so including them in PDF/Excel is slow and (in PDF) not clickable anyway.
    if (!empty($st['photo_url']) && attMode() === 'screen')
    {
        echo "<a href='" . $st['photo_url'] . "' target='_blank' title='View photo'>"
           . "<img src='" . $st['photo_url'] . "' style='display:block; width:46px; height:46px; object-fit:cover; border-radius:4px; margin:4px auto 0;'></a>";
    }

    attRenderLocationButton(
        isset($st['lat'])  ? $st['lat']  : '',
        isset($st['lng'])  ? $st['lng']  : '',
        (isset($st['date']) ? $st['date'] : '') . ' ' . $st['time']
    );
}

/** Command: echo the calendar <thead>. $leadingHeaders = the rowspan columns' labels. */
function attRenderCalendarHeader($leadingHeaders, $days)
{
    echo "<thead><tr>";
    $i = 0;
    foreach ($leadingHeaders as $h)
    {
        $w = ($i === 0) ? 'width:34px;' : 'min-width:110px;';
        echo "<th style='vertical-align:middle; text-align:center; $w'>" . htmlspecialchars($h) . "</th>";
        $i++;
    }
    echo "<th style='vertical-align:middle; text-align:center; min-width:90px;'>Time Period</th>";
    foreach ($days as $d)
    {
        $shade = $d['weekend'] ? "background:#ececec;" : "";
        echo "<th style='text-align:center; min-width:78px; $shade'>" . htmlspecialchars($d['dow']) . "<br>" . htmlspecialchars($d['dom']) . "</th>";
    }
    echo "<th style='vertical-align:middle; text-align:center; width:64px;'>Total<br>Missing</th>";
    echo "</tr></thead>";
}

/**
 * Command: echo one person's calendar rows (one row per period, one column per day).
 * $grid = [periodCode][dayYmd] => [entries]. $holidayByDay / $leaveByDay keyed by Y/m/d.
 * Returns the person's Total Missing count.
 */
function attRenderPersonCalendarRows($leadingCells, $grid, $days, $holidayByDay, $leaveByDay)
{
    global $ATT_PERIOD_TIME;

    $periods = attActivePeriods($days);   // honours the OT filter + retired slots

    $states  = array();
    $missing = 0;
    foreach ($periods as $code => $label)
    {
        foreach ($days as $d)
        {
            $entries  = isset($grid[$code][$d['ymd']]) ? $grid[$code][$d['ymd']] : array();
            $isHol    = !empty($holidayByDay[$d['ymd']]);
            $onLeave  = isset($leaveByDay[$d['ymd']]) ? $leaveByDay[$d['ymd']] : '';
            $st = attCellStatus($code, $entries, $d['ymd'], $isHol, $onLeave);
            $states[$code][$d['ymd']] = $st;
            if ($st['state'] === 'missing') { $missing++; }   // optional slots (OT, optional 1PM) can never reach this state
        }
    }

    $rowspan = count($periods);
    $first   = true;
    echo "<tbody>";   // one tbody per person so print keeps the group on one page
    foreach ($periods as $code => $label)
    {
        echo "<tr>";

        if ($first)
        {
            foreach ($leadingCells as $c)
            {
                echo "<td rowspan='$rowspan' style='vertical-align:middle; text-align:center;'>" . $c . "</td>";
            }
        }

        echo "<td style='vertical-align:middle; text-align:center; white-space:nowrap;'><strong>" . htmlspecialchars($ATT_PERIOD_TIME[$code]) . "</strong>";
        if (attIsOtPeriod($code)) { echo "<div style='font-size:10px; color:#888;'>OT</div>"; }
        echo "</td>";

        foreach ($days as $d)
        {
            $st = $states[$code][$d['ymd']];
            echo "<td style='" . attCalendarCellStyle($st['state']) . "'>";
            attRenderCalendarCell($st);
            echo "</td>";
        }

        if ($first)
        {
            $mv = ($missing > 0) ? "<span style='color:#e67e22; font-weight:bold; font-size:16px;'>$missing</span>" : "0";
            echo "<td rowspan='$rowspan' style='vertical-align:middle; text-align:center;'>" . $mv . "</td>";
            $first = false;
        }

        echo "</tr>";
    }
    echo "</tbody>";

    return $missing;
}
?>