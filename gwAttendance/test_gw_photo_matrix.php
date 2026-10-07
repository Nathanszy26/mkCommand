<?php
/**
 * Render renderGwPhotoMatrix() against stubbed check-in data and assert the
 * table is structurally sound. The colspan arithmetic and the three repeated
 * column groups are exactly the kind of thing that silently renders a ragged
 * table, so every row is counted here.
 */

// ---- stubs for the report functions the matrix calls ----
$STUB_STAFF = array();
$STUB_SELF  = array();

function getGwStaffCheckinCards($gwId, $date, $extraWhere = "")
{
    global $STUB_STAFF;
    return array('cards' => isset($STUB_STAFF[$gwId]) ? $STUB_STAFF[$gwId] : array(), 'order' => array());
}

function getSelfCheckinEntries($gwCode, $gwMobile, $date)
{
    global $STUB_SELF;
    return isset($STUB_SELF[$gwCode]) ? $STUB_SELF[$gwCode] : array();
}

// ---- verbatim from gwAttendanceReport.php ----
function gwPeriodLabel($code, $periods, $nightPeriods)
{
    if (isset($periods[$code]))      { return $periods[$code]; }
    if (isset($nightPeriods[$code])) { return $nightPeriods[$code]; }
    return $code;
}

function gwOrderPeriodCodes($wanted, $periods, $nightPeriods, $firstSeen = array())
{
    $ordered = array();
    foreach ($periods as $pCode => $pLabel)
    {
        if (isset($wanted[$pCode])) { $ordered[] = $pCode; }
    }
    foreach ($nightPeriods as $pCode => $pLabel)
    {
        if (isset($wanted[$pCode]) && !in_array($pCode, $ordered, true)) { $ordered[] = $pCode; }
    }
    foreach ($firstSeen as $pCode)
    {
        if (isset($wanted[$pCode]) && !in_array($pCode, $ordered, true)) { $ordered[] = $pCode; }
    }
    foreach ($wanted as $pCode => $ignored)
    {
        if (!in_array($pCode, $ordered, true)) { $ordered[] = $pCode; }
    }
    return $ordered;
}

// ---- the functions under test, pulled straight out of the patched report ----
$report = file_get_contents($argv[1]);
$start  = strpos($report, '	/**' . "\n" . '	 * Command: one presence cell.');
$end    = strpos($report, '	function gwAdditionalWhere');
if ($start === false) { $start = strpos($report, '	function renderMatrixCell'); }
$end    = strpos($report, "\n?>", $start);
$code   = substr($report, $start, $end - $start);
eval($code);

// ---- fixtures ----
$GW_PERIODS = array('8AM' => '8:00am', '1PM' => '1:00pm', '4.30PM' => '4:30pm');
$GW_NIGHT   = array('7PM' => '7:00pm', '12AM' => '12:00am', '4AM' => '4:00am');

function gw($id, $code, $name, $alloc = 0)
{
    return array(
        'monthly_assign_gw_id' => $id,
        'monthly_assign_gw_code' => $code,
        'monthly_assign_gw_fullname' => $name,
        'monthly_assign_gw_mobile_no' => '0100000000',
        'is_allocated' => $alloc,
    );
}

function att($id, $period)
{
    return array('gw_attendance_attachment_id' => $id, 'gw_attendance_attachment_period' => $period);
}

$failures = array();

function check($label, $got, $want)
{
    global $failures;
    $ok = ($got === $want);
    echo ($ok ? "PASS  " : "FAIL  ") . $label . "\n";
    if (!$ok) { $failures[] = "$label\n   got:  " . var_export($got, true) . "\n   want: " . var_export($want, true); }
}

/**
 * Count <td>/<th> per <tr>, expanding colspan, so a ragged table is visible.
 * The SECOND header row is legitimately narrower: the first row's "#" and
 * "GW Name" carry rowspan='2' and so occupy it without appearing in it. Every
 * other row must be the full width.
 */
function rowWidths($html)
{
    $widths = array();
    preg_match_all('#<tr[^>]*>(.*?)</tr>#s', $html, $rows);
    foreach ($rows[1] as $row)
    {
        $n = 0;
        preg_match_all('#<(td|th)([^>]*)>#', $row, $cells, PREG_SET_ORDER);
        foreach ($cells as $c)
        {
            $span = 1;
            if (preg_match("#colspan='?\"?(\d+)#", $c[2], $m)) { $span = (int)$m[1]; }
            $n += $span;
        }
        $widths[] = $n;
    }
    return $widths;
}

// ── Case 1: three default periods, three photos, two GWs ──
$STUB_STAFF = array(101 => array('8AM' => array(array('time' => '07:45:16'))));
$STUB_SELF  = array('PPR-24' => array('1PM' => array(array('time' => '12:54:07', 'verify_type' => 'Face'))));

ob_start();
renderGwPhotoMatrix('m1',
    array(att(1, '8AM'), att(2, '1PM'), att(3, '4.30PM')),
    array(gw(101, 'PPR-24', 'RONNY B DAVID'), gw(102, 'PPR115', 'EXCELL ALLDY', 1)),
    '2026/10/06', $GW_PERIODS, $GW_NIGHT);
$html = ob_get_clean();

$widths = rowWidths($html);
// 2 label columns + 3 periods x 3 groups = 11, minus the 2 rowspans in header row 2.
check('row widths are 11 everywhere but the rowspan header row',
      $widths, array(11, 9, 11, 11, 11));
check('row width is 2 + 3 periods x 3 groups', $widths[0], 11);
check('two GW rows + 2 header rows + 1 anchor', count($widths), 5);
// Attributes are HTML-escaped, which is what getAttribute() in the browser undoes.
check('slot order published for the script',
      strpos($html, 'data-slots=\'[&quot;8AM&quot;,&quot;1PM&quot;,&quot;4.30PM&quot;]\'') !== false, true);
check('attachment list published for the script',
      strpos($html, '&quot;id&quot;:1,&quot;period&quot;:&quot;8AM&quot;') !== false, true);
check('allocated GW is badged', substr_count($html, 'ALLOC'), 1);
check('staff check-in tick rendered', substr_count($html, '07:45:16'), 1);
check('self check-in tick rendered', substr_count($html, '12:54:07 (Face)'), 1);
check('one photo cell per GW per period', substr_count($html, "class='gwPhotoCell'"), 6);
check('pending cells show the scanning indicator, not an empty-looking dot',
      substr_count($html, "class='gwScanDot'"), 6);

// data-checked-in is what lets the script say "not detected" instead of a bare
// dash. GW 101 (PPR-24) has a staff punch at 8AM and a self punch at 1PM; GW 102
// has neither, so only two of the six photo cells may carry it.
check('cells with a check-in behind them are flagged for the script',
      substr_count($html, "data-checked-in='1'"), 2);
preg_match_all(
    "#<td class='gwPhotoCell' data-gw-id='(\d+)' data-gw-code='([^']*)' data-period='([^']+)'( data-checked-in='1')?#",
    $html, $cm, PREG_SET_ORDER);
check('every photo cell publishes both keys', count($cm), 6);

$flagged = array();
foreach ($cm as $c) { if (!empty($c[4])) { $flagged[] = $c[1] . '/' . $c[3]; } }
sort($flagged);
check('the flagged cells are exactly the slots with a check-in',
      $flagged, array('101/1PM', '101/8AM'));

// The code is what the script matches on, so it has to reach the cell intact.
$codes = array();
foreach ($cm as $c) { $codes[$c[2]] = true; }
ksort($codes);
check('cells carry the GW code the matcher identifies people by',
      array_keys($codes), array('PPR-24', 'PPR115'));

// ── Case 2: a night photo adds a column; periods with no photo get a dash ──
ob_start();
renderGwPhotoMatrix('m2',
    array(att(9, '7PM')),
    array(gw(101, 'PPR-24', 'RONNY B DAVID')),
    '2026/10/06', $GW_PERIODS, $GW_NIGHT);
$html2 = ob_get_clean();

$w2 = rowWidths($html2);
check('night photo adds a 4th period column', $w2[0], 2 + 3 * 4);
check('night rows stay aligned', $w2, array(14, 12, 14, 14));
// Only the 7PM column has a photo, so only one scannable cell exists.
check('only the period with a photo is scannable', substr_count($html2, "class='gwPhotoCell'"), 1);
check('periods without a photo render a dash not a cross',
      substr_count($html2, '&ndash;</td>') >= 3, true);

// ── Case 3: a GW with no check-ins at all still gets a full row ──
$STUB_STAFF = array();
$STUB_SELF  = array();
ob_start();
renderGwPhotoMatrix('m3', array(att(1, '8AM')), array(gw(101, '', 'NO CODE GW')),
                    '2026/10/06', $GW_PERIODS, $GW_NIGHT);
$html3 = ob_get_clean();
$w3 = rowWidths($html3);
check('GW with no data still renders a full row', $w3, array(11, 9, 11, 11));
check('GW with empty code shows no code parens', strpos($html3, '()') === false, true);

echo "\n";
if ($failures) {
    echo count($failures) . " FAILURE(S)\n\n";
    foreach ($failures as $f) { echo $f . "\n\n"; }
    exit(1);
}
echo "All matrix rendering checks passed.\n";
