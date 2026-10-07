<?php
	
	include_once("includes/db.php");
	include_once("includes/access.php");
	
	if (isset($_REQUEST['runScript'])) { $runScript = $_REQUEST['runScript']; } else { $runScript = ""; }
	
	if ($runScript == "Previous Day")
	{
		if (isset($_REQUEST['selected_date'])) { $selected_date = $_REQUEST['selected_date']; } else { $selected_date = ""; }
		
		$show_day = mktime(0, 0, 0, date("m", strtotime($selected_date)), date("d", strtotime($selected_date)) - 1, date("Y", strtotime($selected_date)));
		
		$select_year = date("Y", $show_day);
		$select_month = date("m", $show_day);
		$select_day = date("d", $show_day);
	}
	
	if ($runScript == "Today")
	{
		$select_year = date("Y");
		$select_month = date("m");
		$select_day = date("d");
	}
	
	if ($runScript == "Next Day")
	{
		if (isset($_REQUEST['selected_date'])) { $selected_date = $_REQUEST['selected_date']; } else { $selected_date = ""; }
		
		$show_day = mktime(0, 0, 0, date("m", strtotime($selected_date)), date("d", strtotime($selected_date)) + 1, date("Y", strtotime($selected_date)));
		
		$select_year = date("Y", $show_day);
		$select_month = date("m", $show_day);
		$select_day = date("d", $show_day);
	}
	
	if ($runScript == "Verify Now")
	{
		$gw_attendance_verify_date = $_POST['gw_attendance_verify_date'];
		$gw_attendance_verify_boss_id = $_POST['gw_attendance_verify_boss_id'];
		$gw_attendance_verify_by_clerk_staff_id = $_POST['gw_attendance_verify_by_clerk_staff_id'];
		
		if (($gw_attendance_verify_date != "") && ($gw_attendance_verify_boss_id != "") && ($gw_attendance_verify_by_clerk_staff_id != ""))
		{
			mysql_query("INSERT INTO gw_attendance_verify (gw_attendance_verify_id, gw_attendance_verify_date, gw_attendance_verify_boss_id, gw_attendance_verify_by_clerk_staff_id, gw_attendance_verify_by_clerk_date, gw_attendance_verify_by_clerk_time)
			VALUES ('', '$gw_attendance_verify_date', '$gw_attendance_verify_boss_id', '$gw_attendance_verify_by_clerk_staff_id', '" . date("Y/m/d") . "', '" . date("H:i:s") . "')") or die(mysql_error());
			
			echo "<script type='text/javascript'>";
			echo "alert('Verified');";
			echo "</script>";
		}
		
		$runScript = "Show Report";
	}
		
	if (($runScript == "") || ($runScript == "Show Report"))
	{
		if (isset($_REQUEST['select_year'])) { $select_year = $_REQUEST['select_year']; } else { $select_year = ""; }
		if (isset($_REQUEST['select_month'])) { $select_month = $_REQUEST['select_month']; } else { $select_month = ""; }
		if (isset($_REQUEST['select_day'])) { $select_day = $_REQUEST['select_day']; } else { $select_day = ""; }
		
		if (($select_year == "") || ($select_month == "") || ($select_day == ""))
		{
			$select_year = date("Y");
			$select_month = date("m");
			$select_day = date("d");
		}
		
		if (isset($_REQUEST['select_gw_district'])) { $select_gw_district = $_REQUEST['select_gw_district']; } else { $select_gw_district = ""; }
		if (isset($_REQUEST['select_mandor'])) { $select_mandor = $_REQUEST['select_mandor']; } else { $select_mandor = "all"; }
	}
	
	$select_period = $select_year . "/" . $select_month . "/" . $select_day;
	
	// ── Self check-in config (mirrors getGwAttendanceSelfAPI.php) ──
	// Self check-in photos live under their own web path, NOT this report's local attachments/.
	define('GW_SELF_IMG_URL_BASE', 'https://globportal.com/accounts/gwAttendance/attachments/');
	// Staff-path self check-in photos (mirrors getGwAttendanceSelfAPI.php::STAFF_IMG_URL_BASE).
	define('GW_SELF_STAFF_IMG_URL_BASE', 'https://globportal.com/accounts/gwAttendance/staff_attachments/');
	
	// Default check-in slots (code => label), in display order. Shared by both the
	// staff and self columns — both store the same codes in gw_attendance_period.
	$GW_PERIODS = array(
		'8AM'    => '8:00am',
		'1PM'    => '1:00pm',
		'4.30PM' => '4:30pm',
	);
	
	// Night-shift self check-in slots (code => label). Unlike $GW_PERIODS these
	// are NOT always shown — they render only when a self check-in exists for them.
	$GW_SELF_NIGHT_PERIODS = array(
		'7PM'  => '7:00pm',
		'12AM' => '12:00am',
		'4AM'  => '4:00am',
	);
	
	/**
	 * Query only: this GW's self check-ins for a date, keyed by period code.
	 * Matches the self API exactly (self_checkin=1, submittedFrom='1', resolved by code).
	 * One row per attendance, with its self check-in photo joined on gw_attendance_id.
	 * No period whitelist here — the render loop decides what shows, so night slots
	 * (7PM/12AM/4AM) come through and appear only when actually punched.
	 */
	function getGwSelfCheckins($gwCode, $date)
	{
		$sql = "SELECT a.gw_attendance_id,
		               a.gw_attendance_period AS period,
		               a.gw_attendance_by_time AS time,
		               a.gw_attendance_gw_remarks AS remarks,
		               a.gw_verify_type AS verify_type,
		               t.gw_attendance_attachment_filename AS filename
		        FROM gw_attendance a
		        LEFT JOIN gw_attendance_attachments t
		               ON t.gw_attendance_id = a.gw_attendance_id
		              AND t.gw_attendance_attachment_deleted = '0'
		        WHERE a.monthly_assign_gw_code = '" . $gwCode . "'
		          AND a.gw_attendance_self_checkin = 1
		          AND a.gw_attendance_submittedFrom = '1'
		          AND a.gw_attendance_by_date = '" . $date . "'
		        GROUP BY a.gw_attendance_id
		        ORDER BY a.gw_attendance_by_time ASC";
		$result = mysql_query($sql) or die(mysql_error());
		
		$byPeriod = array();
		while ($row = mysql_fetch_array($result))
		{
			// trim() matches how the staff side keys its periods. Without it a stored
			// '12AM ' never matches the '12AM' slot code and the card vanishes silently.
			$code = trim($row['period']);
			if (!isset($byPeriod[$code])) { $byPeriod[$code] = array(); }
			$byPeriod[$code][] = $row;
		}
		return $byPeriod;
	}
	
	/**
	 * Query only: this GW's self check-ins that landed in the STAFF tables, keyed by period code.
	 *
	 * Why this exists: getGwAttendanceSelfAPI.php sends a person down the staff path whenever
	 * their staff_portal2.users row is NOT flagged is_gw = '1'. A GW missing that flag writes to
	 * evaluation.staff_attendance, where no GW code is stored — so the code-based query above can
	 * never see them and they read as "Not checked in" all day. The link back is the mobile
	 * number, the same key the API's resolveGwByMobile() uses
	 * (users.mobile_no -> monthly_assign_gw_mobile_no). The (person + comp_id) OR mymk_id match
	 * mirrors the API's own read queries exactly.
	 *
	 * users.status / users.deleted are deliberately NOT filtered: an account disabled after the
	 * fact must not silently erase check-ins it already made.
	 * GROUP BY a.attendance_id also de-dupes should two users rows share the same mobile.
	 *
	 * Columns are aliased to the names getGwSelfCheckins() returns, so buildSelfEntries()
	 * consumes both sources without a branch.
	 */
	function getStaffSelfCheckins($gwMobile, $date)
	{
		$mobile = trim($gwMobile);
		if ($mobile === "") { return array(); }
		
		$sql = "SELECT a.attendance_id,
		               a.attendance_period AS period,
		               a.attendance_by_time AS time,
		               a.attendance_remarks AS remarks,
		               a.verify_type AS verify_type,
		               t.attendance_attachment_filename AS filename
		        FROM staff_portal2.users u
		        INNER JOIN evaluation.staff_attendance a
		                ON a.mymk_id = u.id
		                OR (a.attendance_by_person = u.person AND a.attendance_by_comp_id = u.comp_id)
		        LEFT JOIN evaluation.staff_attendance_attachments t
		               ON t.attendance_id = a.attendance_id
		              AND t.attendance_attachment_deleted = '0'
		        WHERE u.mobile_no = '" . $mobile . "'
		          AND a.attendance_self_checkin = 1
		          AND a.attendance_submittedFrom = '1'
		          AND a.attendance_by_date = '" . $date . "'
		        GROUP BY a.attendance_id
		        ORDER BY a.attendance_by_time ASC";
		$result = mysql_query($sql) or die(mysql_error());
		
		$byPeriod = array();
		while ($row = mysql_fetch_array($result))
		{
			$code = trim($row['period']);
			if (!isset($byPeriod[$code])) { $byPeriod[$code] = array(); }
			$byPeriod[$code][] = $row;
		}
		return $byPeriod;
	}
	
	/**
	 * Query→entry mapper: convert self check-in rows into the shape renderAttendanceCard
	 * expects. One place for the mapping so default and night slots build entries identically.
	 * Both sources alias their columns to the same names, so the only thing that differs is
	 * where the photo lives — hence $imgBase.
	 */
	function buildSelfEntries($rows, $imgBase)
	{
		$entries = array();
		foreach ($rows as $r)
		{
			$entries[] = array(
				'time'        => $r['time'],
				'remarks'     => $r['remarks'],
				'verify_type' => ($r['verify_type'] === 'manual') ? 'Manual' : 'Face',
				'photo_url'   => !empty($r['filename']) ? $imgBase . rawurlencode($r['filename']) : "",
			);
		}
		return $entries;
	}
	
	/**
	 * Query: the GW's complete self check-in picture for one date, period code => entries.
	 * Merges the GW path (gw_attendance, by code) with the staff path (staff_attendance, by
	 * mobile) so a missing is_gw flag no longer hides a check-in.
	 *
	 * No sort needed after the merge: each source is already time-ordered, and a person is only
	 * ever on one path at a time, so the same period cannot receive entries from both.
	 */
	function getSelfCheckinEntries($gwCode, $gwMobile, $date)
	{
		$byPeriod = array();
		
		foreach (getGwSelfCheckins($gwCode, $date) as $code => $rows)
		{
			$byPeriod[$code] = buildSelfEntries($rows, GW_SELF_IMG_URL_BASE);
		}
		foreach (getStaffSelfCheckins($gwMobile, $date) as $code => $rows)
		{
			$entries = buildSelfEntries($rows, GW_SELF_STAFF_IMG_URL_BASE);
			$byPeriod[$code] = isset($byPeriod[$code]) ? array_merge($byPeriod[$code], $entries) : $entries;
		}
		return $byPeriod;
	}
	
	/**
	 * Command: render one attendance card in the shared column style.
	 * $label   = card header (period). Pass '' to omit the header.
	 * $entries = list of ['time','remarks','verify_type','photo_url']; empty => "Not checked in".
	 * verify_type / remarks / photo_url are optional per entry.
	 */
	function renderAttendanceCard($label, $entries)
	{
		$done = count($entries) > 0;
		echo "<div style='border:1px solid " . ($done ? "#5cb85c" : "#ddd") . "; border-radius:6px; padding:6px; background:" . ($done ? "#f2fbf2" : "#fafafa") . "; margin-bottom:8px;'>";
		if ($label !== "") { echo "<div style='font-size:12px; font-weight:bold; color:#333;'>" . htmlspecialchars($label) . "</div>"; }
		
		if ($done)
		{
			foreach ($entries as $e)
			{
				echo "<div style='font-size:11px; color:#3c763d; margin-top:2px;'>";
				echo "<img src='images/tick_yes.jpg' height='12'> " . htmlspecialchars($e['time']);
				if (!empty($e['verify_type'])) { echo " (" . htmlspecialchars($e['verify_type']) . ")"; }
				echo "</div>";
				$remarks = (isset($e['remarks']) && trim($e['remarks']) !== "") ? $e['remarks'] : "No Remarks";
				echo "<div style='font-size:10px; color:#888;'>" . htmlspecialchars($remarks) . "</div>";
				if (!empty($e['photo_url']))
				{
					echo "<a href='" . $e['photo_url'] . "' target='_blank'><img src='" . $e['photo_url'] . "' style='display:block; width:130px; height:130px; min-width:130px; max-width:130px; object-fit:cover; border-radius:4px; margin-top:4px; flex-shrink:0;'></a>";
				}
			}
		} else {
			echo "<div style='font-size:11px; color:#999; margin-top:2px;'><img src='images/tick_no.jpg' height='12'> Not checked in</div>";
		}
		
		echo "</div>";
	}
	
	/**
	 * Query only: staff-recorded check-ins for one GW on one date, ready to render.
	 * Returns array('cards' => periodCode => entries, 'order' => first-seen period order).
	 * The "" period key is a punch with no period — renderAttendanceCard('') omits the header.
	 *
	 * $extraWhere narrows the rows without duplicating the query. The Additional GW section
	 * passes the same predicate it uses to discover its GWs, so discovery and display can never
	 * disagree about which rows belong to it (DRY).
	 */
	function getGwStaffCheckinCards($gwId, $date, $extraWhere = "")
	{
		// Face recognition photos for this GW/date, keyed by period, so each card claims one
		// in time order and repeats are impossible.
		$queryFacePhotos = "SELECT gw_attendance_attachment_filename, gw_attendance_attachment_period
		                    FROM gw_attendance_attachments
		                    WHERE monthly_assign_gw_id = '" . $gwId . "'
		                      AND gw_attendance_attachment_date = '" . $date . "'
		                      AND gw_attendance_attachment_deleted = '0'
		                    ORDER BY gw_attendance_attachment_time ASC";
		$resultFacePhotos = mysql_query($queryFacePhotos) or die(mysql_error());
		
		$facePhotoMap = array();
		while ($rowFP = mysql_fetch_array($resultFacePhotos))
		{
			$fpPeriod = trim($rowFP['gw_attendance_attachment_period']);
			if (!isset($facePhotoMap[$fpPeriod])) { $facePhotoMap[$fpPeriod] = array(); }
			$facePhotoMap[$fpPeriod][] = $rowFP['gw_attendance_attachment_filename'];
		}
		
		$queryAttendance = "SELECT * FROM gw_attendance
		                    WHERE monthly_assign_gw_id = '" . $gwId . "'
		                      AND gw_attendance_by_date = '" . $date . "'" . $extraWhere;
		$resultAttendance = mysql_query($queryAttendance) or die(mysql_error());
		
		$cards = array();
		$order = array();
		while ($rowAttendance = mysql_fetch_array($resultAttendance))
		{
			$period = trim($rowAttendance['gw_attendance_period']);
			if (!isset($cards[$period])) { $cards[$period] = array(); $order[] = $period; }
			
			$photoUrl = "";
			if ($period !== "" && isset($facePhotoMap[$period]) && count($facePhotoMap[$period]) > 0)
			{
				$photoUrl = "attachments/" . array_shift($facePhotoMap[$period]);
			}
			
			$cards[$period][] = array(
				'time'        => $rowAttendance['gw_attendance_by_time'],
				'remarks'     => $rowAttendance['gw_attendance_gw_remarks'],
				'verify_type' => "",   // staff check-in has no Face/Manual distinction
				'photo_url'   => $photoUrl,
			);
		}
		
		return array('cards' => $cards, 'order' => $order);
	}
	
	/**
	 * Query only: the display label for a period code, from whichever slot list knows it.
	 * Unknown codes fall back to the raw code; "" is a punch with no period and renders
	 * headerless by renderAttendanceCard(). One lookup so both columns can never label the
	 * same slot differently.
	 */
	function gwPeriodLabel($code, $periods, $nightPeriods)
	{
		if (isset($periods[$code]))      { return $periods[$code]; }
		if (isset($nightPeriods[$code])) { return $nightPeriods[$code]; }
		return $code;
	}
	
	/**
	 * Query only: period codes to render in a column, ordered.
	 * $wanted is a set (codes as keys). Known slots come first in their configured order so the
	 * two columns line up row for row, then anything unexpected in first-seen order.
	 */
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
	
	/**
	 * Command: the stand-in for a column that has nothing to show. Only reachable when
	 * $showEmptySlots is false, so a column never collapses to a bare heading.
	 */
	function renderNoCheckinLine()
	{
		echo "<div style='font-size:11px; color:#999;'><img src='images/tick_no.jpg' height='12'> No check-in recorded</div>";
	}
	
	/**
	 * Command: render the two-column "Staff Check-In for GW | GW Self Check-In" block for one GW.
	 *
	 * Extracted so the assigned/allocated list and the Additional GW section render identically.
	 * They differ in exactly two arguments: $staffExtraWhere decides WHICH staff rows count, and
	 * $showEmptySlots decides which slots get a card. Self check-in is untouched by the filter:
	 * it is the GW's own punch and belongs to the GW, not to whoever recorded them.
	 */
	function renderGwCheckinColumns($gwId, $gwCode, $gwMobile, $date, $periods, $nightPeriods, $staffExtraWhere = "", $showEmptySlots = true)
	{
		$staff        = getGwStaffCheckinCards($gwId, $date, $staffExtraWhere);
		$staffCards   = $staff['cards'];
		$staffOrder   = $staff['order'];
		$selfCheckins = getSelfCheckinEntries($gwCode, $gwMobile, $date);
		
		echo "<div style='display:flex; gap:16px; align-items:flex-start; flex-wrap:wrap; margin-top:8px;'>";
		
		// LEFT: Staff Check-In for GW. Slot selection is decided below, once, for both columns.
		echo "<div style='flex:1; min-width:280px;'>";
		echo "<strong style='font-size:12px; color:#555;'>Staff Check-In for GW</strong>";
		echo "<div style='margin-top:6px;'>";
		
		// ── Which slots each column renders ────────────────────────────────────────
		// $showEmptySlots = true  (assigned/allocated roster): every default slot always, so an
		//                         absence against an expected roster is visible. Each column
		//                         additionally shows its own extra slots.
		// $showEmptySlots = false (Additional GW): the staff record is the claim and the self
		//                         check-in is the corroboration, so BOTH columns render exactly
		//                         the slots the staff recorded — the self column mirrors the
		//                         staff column row for row. An ad-hoc GW has no expected roster,
		//                         so any other slot is not part of what is being reported here.
		$defaultSet = array();
		foreach ($periods as $pCode => $pLabel) { $defaultSet[$pCode] = true; }
		
		if ($showEmptySlots)
		{
			$staffWanted = $defaultSet + $staffCards;
			$selfWanted  = $defaultSet + $selfCheckins;
		} else {
			$staffWanted = $staffCards;
			$selfWanted  = $staffCards;   // mirror: same slots, corroborated or not
		}
		
		$staffSlots = gwOrderPeriodCodes($staffWanted, $periods, $nightPeriods, $staffOrder);
		$selfSlots  = gwOrderPeriodCodes($selfWanted, $periods, $nightPeriods, $staffOrder);
		
		foreach ($staffSlots as $pCode)
		{
			renderAttendanceCard(
				gwPeriodLabel($pCode, $periods, $nightPeriods),
				isset($staffCards[$pCode]) ? $staffCards[$pCode] : array()
			);
		}
		if (count($staffSlots) === 0) { renderNoCheckinLine(); }
		
		echo "</div>";  // /left content
		echo "</div>";  // /left column
		
		// RIGHT: GW Self Check-In — gw_attendance (by GW code) + staff_attendance
		// (by mobile, for GWs whose users row is missing is_gw = 1), merged.
		echo "<div style='flex:1; min-width:280px; border-left:1px dashed #ccc; padding-left:16px;'>";
		echo "<strong style='font-size:12px; color:#555;'>GW Self Check-In</strong>";
		echo "<div style='margin-top:6px;'>";
		
		foreach ($selfSlots as $pCode)
		{
			renderAttendanceCard(
				gwPeriodLabel($pCode, $periods, $nightPeriods),
				isset($selfCheckins[$pCode]) ? $selfCheckins[$pCode] : array()
			);
		}
		if (count($selfSlots) === 0) { renderNoCheckinLine(); }
		
		echo "</div>";  // /cards
		echo "</div>";  // /right column
		echo "</div>";  // /flex row
	}
	
	/**
	 * Query only: the WHERE fragment identifying rows a staff member recorded for GWs they added
	 * on top of their roster. submitAPI.php stamps those rows gw_attendance_additional = 1 and
	 * writes users.person into gw_attendance_by_staff_id — hence $person, not users.id.
	 *
	 * Single definition, used by both the discovery query and the per-GW card query.
	 */
	function gwAdditionalWhere($person, $compId)
	{
		// Every column is table-qualified. These names are unique to gw_attendance today, but the
		// discovery query joins monthly_assign_gw, which already shares two column names with it —
		// qualifying costs nothing and removes the whole class of ambiguity error.
		// Table-qualifying is equally valid inside the single-table query getGwStaffCheckinCards()
		// runs, so one string serves both callers.
		return " AND gw_attendance.gw_attendance_additional = '1'"
		     . " AND gw_attendance.gw_attendance_by_staff_id = '" . $person . "'"
		     . " AND gw_attendance.gw_attendance_by_comp_id = '" . $compId . "'";
	}
	
	/**
	 * Query only: one mandor's GW roster for a date — permanently assigned GWs
	 * first, then any temporarily allocated to them, with duplicates removed.
	 *
	 * Lifted out of the GW table below so the photo matrix and that table read
	 * from ONE roster. When they each built their own, a GW could appear in one
	 * and not the other, and nothing in the page would show which was right.
	 * gwAttendanceIdentify.php builds the matcher's pool from the same two rules.
	 */
	function getGwRoster($bossPerson, $staffId, $date)
	{
		$gwList    = array();
		$seenGwIds = array();

		// 1. Permanent GWs (assigned via monthly_assign_gw.boss_id)
		$queryGW = "SELECT *, 0 AS is_allocated, '' AS alloc_date_from, '' AS alloc_date_to
		            FROM monthly_assign_gw
		            WHERE boss_id = '" . $bossPerson . "'";
		$resultGW = mysql_query($queryGW) or die(mysql_error());
		while ($rowGW = mysql_fetch_array($resultGW))
		{
			$gwList[] = $rowGW;
			$seenGwIds[$rowGW['monthly_assign_gw_id']] = true;
		}

		// 2. Temporarily allocated GWs (gw_allocations active on the report date).
		//    Mirrors getGWAttendanceAPI_test.php: mandor_id = staff_portal2.users.id,
		//    allocation applies when date_from <= report date <= date_to.
		//    gw_allocations stores dates as Y-m-d; $date is Y/m/d.
		$periodYmd = date('Y-m-d', strtotime($date));
		$queryAlloc = "SELECT gw.*, 1 AS is_allocated,
		                      a.date_from AS alloc_date_from,
		                      a.date_to AS alloc_date_to
		               FROM gw_allocations a
		               INNER JOIN monthly_assign_gw gw
		                       ON a.monthly_assign_gw_id = gw.monthly_assign_gw_id
		               WHERE a.mandor_id = '" . $staffId . "'
		                 AND a.deleted = 0
		                 AND a.date_from <= '$periodYmd'
		                 AND a.date_to   >= '$periodYmd'
		                 AND gw.monthly_assign_gw_status = '1'";
		$resultAlloc = mysql_query($queryAlloc) or die(mysql_error());
		while ($rowAlloc = mysql_fetch_array($resultAlloc))
		{
			// Permanent assignment wins over allocation for the same GW
			if (isset($seenGwIds[$rowAlloc['monthly_assign_gw_id']])) { continue; }
			$gwList[] = $rowAlloc;
			$seenGwIds[$rowAlloc['monthly_assign_gw_id']] = true;
		}

		return $gwList;
	}

	/**
	 * Command: one presence cell. $state is 'yes' or 'no'; $title is the hover
	 * detail ("" for none); $borderLeft separates the three column groups.
	 */
	function renderMatrixCell($state, $label, $title, $borderLeft = "1px solid #eee")
	{
		if ($state === 'yes') { $bg = "#eaf7ea"; $fg = "#2e7d32"; }
		else                  { $bg = "#fafafa"; $fg = "#ccc";    }

		echo "<td style='text-align:center; background:$bg; color:$fg; font-size:11px; "
		   . "border-left:$borderLeft; white-space:nowrap;'"
		   . ($title !== "" ? " title='" . htmlspecialchars($title, ENT_QUOTES) . "'" : "")
		   . ">" . $label . "</td>";
	}

	/**
	 * Command: the per-mandor attendance matrix — one row per rostered GW, with
	 * three column groups across the periods: who the camera saw, what the staff
	 * recorded, and what the GW punched themselves.
	 *
	 * The group-photo cells are rendered EMPTY and filled in by JavaScript, because
	 * face identification runs over the network and must not hold up the page. Each
	 * cell carries data-gw-id / data-period so the script can find it, and the table
	 * carries the attachment list it has to scan.
	 *
	 * Staff and self columns are read here, server side, through the same two
	 * functions the detail cards below use — so the matrix and the cards can never
	 * disagree about whether someone checked in.
	 */
	function renderGwPhotoMatrix($matrixId, $attachments, $gwList, $date, $periods, $nightPeriods)
	{
		// ── Which periods get columns ──────────────────────────────────────────
		// The three default slots always, so an absence against an expected roster
		// is visible. Night slots only when something actually happened in them —
		// an all-empty 12AM column would imply a shift nobody was asked to work.
		$wanted = array();
		foreach ($periods as $pCode => $pLabel) { $wanted[$pCode] = true; }

		// Per-GW check-in data, read once and reused for both the column choice
		// and the cells themselves.
		$gwData = array();
		foreach ($gwList as $rowGW)
		{
			$staff = getGwStaffCheckinCards(
				$rowGW['monthly_assign_gw_id'], $date,
				" AND gw_attendance.gw_attendance_additional = '0'"
			);
			$self = getSelfCheckinEntries(
				$rowGW['monthly_assign_gw_code'],
				$rowGW['monthly_assign_gw_mobile_no'],
				$date
			);
			$gwData[$rowGW['monthly_assign_gw_id']] = array('staff' => $staff['cards'], 'self' => $self);

			foreach ($staff['cards'] as $pCode => $entries) { if ($pCode !== "") { $wanted[$pCode] = true; } }
			foreach ($self as $pCode => $entries)           { if ($pCode !== "") { $wanted[$pCode] = true; } }
		}
		// A photo taken in a night slot also earns that slot a column.
		foreach ($attachments as $att)
		{
			$pCode = trim($att['gw_attendance_attachment_period']);
			if ($pCode !== "") { $wanted[$pCode] = true; }
		}

		$slots = gwOrderPeriodCodes($wanted, $periods, $nightPeriods);
		if (count($slots) === 0) { return; }

		// Which periods actually have a photo — the rest get a dash, not a cross,
		// because "no photo was taken" is not the same as "not in the photo".
		$photoPeriods = array();
		foreach ($attachments as $att)
		{
			$pCode = trim($att['gw_attendance_attachment_period']);
			if ($pCode !== "") { $photoPeriods[$pCode] = true; }
		}

		// What the script needs: every attachment on this card, with its period.
		$attMeta = array();
		foreach ($attachments as $att)
		{
			$attMeta[] = array(
				'id'     => (int)$att['gw_attendance_attachment_id'],
				'period' => trim($att['gw_attendance_attachment_period']),
			);
		}

		$nSlots = count($slots);

		echo "<div style='margin-top:14px;'>";
		echo "<strong style='font-size:12px; color:#555;'>Attendance Matrix</strong> ";
		echo "<span id='" . $matrixId . "_status' style='font-size:11px; color:#999;'></span>";

		// data-slots is the period order of the column groups, handed to the script
		// verbatim. The script appends rows of its own for people found in a photo
		// who are not on the roster, and those cells have to line up with these
		// columns exactly — so the order is published once here rather than being
		// re-derived from the rendered header.
		echo "<table id='" . $matrixId . "' class='table' "
		   . "data-attachments='" . htmlspecialchars(json_encode($attMeta), ENT_QUOTES) . "' "
		   . "data-slots='" . htmlspecialchars(json_encode(array_values($slots)), ENT_QUOTES) . "' "
		   . "style='margin-top:6px; font-size:12px; background:#fff;'>";

		// ── Header: three column groups over the same period list ──
		echo "<thead>";
		echo "<tr>";
		echo "<th rowspan='2' style='width:28px;'>#</th>";
		echo "<th rowspan='2'>GW Name</th>";
		echo "<th colspan='$nSlots' style='text-align:center; background:#eef4ff; border-left:2px solid #005AFC;'>Group Photo</th>";
		echo "<th colspan='$nSlots' style='text-align:center; background:#f3f3f3; border-left:2px solid #999;'>Staff Check-In for GW</th>";
		echo "<th colspan='$nSlots' style='text-align:center; background:#f3f3f3; border-left:2px solid #999;'>GW Self Check-In</th>";
		echo "</tr>";
		echo "<tr>";
		foreach (array('#eef4ff', '#f8f8f8', '#f8f8f8') as $gi => $bg)
		{
			$edge = ($gi === 0) ? "2px solid #005AFC" : "2px solid #999";
			foreach ($slots as $i => $pCode)
			{
				$border = ($i === 0) ? $edge : "1px solid #eee";
				echo "<th style='text-align:center; font-weight:normal; font-size:11px; background:$bg; border-left:$border;'>"
				   . htmlspecialchars(gwPeriodLabel($pCode, $periods, $nightPeriods)) . "</th>";
			}
		}
		echo "</tr>";
		echo "</thead>";

		echo "<tbody>";
		$n = 0;
		foreach ($gwList as $rowGW)
		{
			$n ++;
			$gwId   = $rowGW['monthly_assign_gw_id'];
			$gwCode = trim($rowGW['monthly_assign_gw_code']);
			$data = isset($gwData[$gwId]) ? $gwData[$gwId] : array('staff' => array(), 'self' => array());

			echo "<tr>";
			echo "<td>$n</td>";
			echo "<td style='white-space:nowrap;'>" . htmlspecialchars($rowGW['monthly_assign_gw_fullname']);
			if (trim($rowGW['monthly_assign_gw_code']) !== "")
			{
				echo " <small style='color:#999;'>(" . htmlspecialchars(trim($rowGW['monthly_assign_gw_code'])) . ")</small>";
			}
			if (!empty($rowGW['is_allocated']))
			{
				echo " <span style='display:inline-block;background:#f0ad4e;color:#fff;padding:0 5px;border-radius:3px;font-size:10px;font-weight:bold;'>ALLOC</span>";
			}
			echo "</td>";

			// GROUP PHOTO — filled in by the script.
			foreach ($slots as $i => $pCode)
			{
				$edge = ($i === 0) ? "2px solid #005AFC" : "1px solid #eee";
				if (!isset($photoPeriods[$pCode]))
				{
					// No photo for this slot at all.
					echo "<td style='text-align:center; background:#fafafa; color:#ddd; font-size:11px; border-left:$edge;'>&ndash;</td>";
				} else {
					// data-checked-in tells the script this slot has independent proof the
					// GW was on site — a staff record or the GW's own punch. If the face is
					// then NOT found, that is the detector failing, not an absence, and the
					// cell says so rather than showing a bare dash the reader would read as
					// "not there".
					$corroborated = (isset($data['staff'][$pCode]) && count($data['staff'][$pCode]) > 0)
					             || (isset($data['self'][$pCode])  && count($data['self'][$pCode])  > 0);

					// Both keys are published. The matcher identifies people by GW CODE,
					// so that is what the script matches on; the id is only a fallback for
					// a GW with no code. Keying on the id alone used to strand people:
					// 19 codes exist on two active monthly_assign_gw rows at once (the same
					// worker registered under two mandors), and the enrolment points at one
					// id while this report shows the other, so the tick had nowhere to land
					// and the person was reported as an extra standing beside themselves.
					echo "<td class='gwPhotoCell' data-gw-id='" . (int)$gwId . "'"
					   . " data-gw-code='" . htmlspecialchars($gwCode, ENT_QUOTES) . "'"
					   . " data-period='" . htmlspecialchars($pCode, ENT_QUOTES) . "'"
					   . ($corroborated ? " data-checked-in='1'" : "")
					   . " style='text-align:center; background:#fcfcfc; font-size:11px; border-left:$edge;'>"
					   . "<span class='gwScanDot' title='Scanning this photo&hellip;'></span></td>";
				}
			}

			// STAFF CHECK-IN
			foreach ($slots as $i => $pCode)
			{
				$edge = ($i === 0) ? "2px solid #999" : "1px solid #eee";
				$has = isset($data['staff'][$pCode]) && count($data['staff'][$pCode]) > 0;
				$title = "";
				if ($has)
				{
					$times = array();
					foreach ($data['staff'][$pCode] as $e) { $times[] = $e['time']; }
					$title = implode(", ", $times);
				}
				renderMatrixCell($has ? 'yes' : 'no', $has ? "&#10003;" : "&ndash;", $title, $edge);
			}

			// GW SELF CHECK-IN
			foreach ($slots as $i => $pCode)
			{
				$edge = ($i === 0) ? "2px solid #999" : "1px solid #eee";
				$has = isset($data['self'][$pCode]) && count($data['self'][$pCode]) > 0;
				$title = "";
				if ($has)
				{
					$times = array();
					foreach ($data['self'][$pCode] as $e)
					{
						$times[] = $e['time'] . (!empty($e['verify_type']) ? " (" . $e['verify_type'] . ")" : "");
					}
					$title = implode(", ", $times);
				}
				renderMatrixCell($has ? 'yes' : 'no', $has ? "&#10003;" : "&ndash;", $title, $edge);
			}

			echo "</tr>";
		}

		// Anchor row for people the camera found who are NOT on this roster. The
		// script inserts before it; it stays hidden while there are none.
		echo "<tr class='gwExtraAnchor' style='display:none;'><td colspan='" . (2 + 3 * $nSlots) . "'></td></tr>";

		echo "</tbody>";
		echo "</table>";
		echo "</div>";
	}
	
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <!-- Meta, title, CSS, favicons, etc. -->
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title><?php echo WEBSITE_TITLE; ?></title>

    <!-- Bootstrap -->
    <link href="../vendors/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../vendors/font-awesome/css/font-awesome.min.css" rel="stylesheet">
    <!-- iCheck -->
    <link href="../vendors/iCheck/skins/flat/green.css" rel="stylesheet">
    <!-- bootstrap-progressbar -->
    <link href="../vendors/bootstrap-progressbar/css/bootstrap-progressbar-3.3.4.min.css" rel="stylesheet">
    <!-- jVectorMap -->
    <link href="css/maps/jquery-jvectormap-2.0.3.css" rel="stylesheet"/>

    <!-- Custom Theme Style -->
    <link href="../build/css/custom.min.css" rel="stylesheet">
	
	<script type="text/javascript" src="includes/function.js"></script>
  </head>

  <body class="nav-md">
    <div class="container body">
      <div class="main_container">
        <div class="col-md-3 left_col">
          <div class="left_col scroll-view">
			<?php include_once("includes/sidebar.php"); ?>
          </div>
        </div>

        <!-- top navigation -->
        <div class="top_nav">
          <?php include_once("includes/topbar.php"); ?>
        </div>
        <!-- /top navigation -->

        <!-- page content -->
        <div class="right_col" role="main">
          <!-- top tiles -->
          <?php /*
		  <div class="row tile_count">
            <div class="col-md-2 col-sm-4 col-xs-6 tile_stats_count">
              <span class="count_top"><i class="fa fa-user"></i> Total Users</span>
              <div class="count">2500</div>
              <span class="count_bottom"><i class="green">4% </i> From last Week</span>
            </div>
            <div class="col-md-2 col-sm-4 col-xs-6 tile_stats_count">
              <span class="count_top"><i class="fa fa-clock-o"></i> Average Time</span>
              <div class="count">123.50</div>
              <span class="count_bottom"><i class="green"><i class="fa fa-sort-asc"></i>3% </i> From last Week</span>
            </div>
            <div class="col-md-2 col-sm-4 col-xs-6 tile_stats_count">
              <span class="count_top"><i class="fa fa-user"></i> Total Males</span>
              <div class="count green">2,500</div>
              <span class="count_bottom"><i class="green"><i class="fa fa-sort-asc"></i>34% </i> From last Week</span>
            </div>
            <div class="col-md-2 col-sm-4 col-xs-6 tile_stats_count">
              <span class="count_top"><i class="fa fa-user"></i> Total Females</span>
              <div class="count">4,567</div>
              <span class="count_bottom"><i class="red"><i class="fa fa-sort-desc"></i>12% </i> From last Week</span>
            </div>
            <div class="col-md-2 col-sm-4 col-xs-6 tile_stats_count">
              <span class="count_top"><i class="fa fa-user"></i> Total Collections</span>
              <div class="count">2,315</div>
              <span class="count_bottom"><i class="green"><i class="fa fa-sort-asc"></i>34% </i> From last Week</span>
            </div>
            <div class="col-md-2 col-sm-4 col-xs-6 tile_stats_count">
              <span class="count_top"><i class="fa fa-user"></i> Total Connections</span>
              <div class="count">7,325</div>
              <span class="count_bottom"><i class="green"><i class="fa fa-sort-asc"></i>34% </i> From last Week</span>
            </div>
          </div>
		  */ ?>
          <!-- /top tiles -->

          <div class="row">
			<?php /*
            <div class="col-md-12 col-sm-12 col-xs-12">
              <div class="x_panel">
                <div class="x_title">
                  <h2>Notice Board <small>Latest News</small></h2>
                  <div class="clearfix"></div>
                </div>
                <div class="x_content">

                  <div class="bs-example" data-example-id="simple-jumbotron">
                    <div class="jumbotron">
                      <p>Dear Colleagues,<br>
					  <br>
					  Welcome to GW Attendance System.<br>
					  <br>
					  Thank you.</p>
                    </div>
                  </div>

                </div>
              </div>
            </div>
			*/ ?>
			
			<!-- Start to do list -->
			<div class="col-md-12 col-sm-12 col-xs-12">
			  <div class="x_panel">
				<div class="x_title">
				  <h2>GW Attendance Report for<br><br><?php echo date("d M Y", strtotime($select_period)); ?> <small>Report</small></h2>
				  <div class="clearfix"></div>
				</div>
				<div class="x_content">
				  <form action="gwAttendanceReport.php" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left">
				  <div class="">
					<select id="select_year" name="select_year" onChange="this.form.submit();">
						<?php
							
							for ($i = date("Y"); $i >= 2019; $i --)
							{
								if ($i == $select_year) { $selected = "selected"; } else { $selected = ""; }
								echo "<option value='$i' $selected>$i</option>";
							}
							
						?>
					</select>
					<select id="select_month" name="select_month" onChange="this.form.submit();">
						<option value="01" <?php if ($select_month == "01") { echo "selected"; } ?>>January</option>
						<option value="02" <?php if ($select_month == "02") { echo "selected"; } ?>>February</option>
						<option value="03" <?php if ($select_month == "03") { echo "selected"; } ?>>March</option>
						<option value="04" <?php if ($select_month == "04") { echo "selected"; } ?>>April</option>
						<option value="05" <?php if ($select_month == "05") { echo "selected"; } ?>>May</option>
						<option value="06" <?php if ($select_month == "06") { echo "selected"; } ?>>June</option>
						<option value="07" <?php if ($select_month == "07") { echo "selected"; } ?>>July</option>
						<option value="08" <?php if ($select_month == "08") { echo "selected"; } ?>>August</option>
						<option value="09" <?php if ($select_month == "09") { echo "selected"; } ?>>September</option>
						<option value="10" <?php if ($select_month == "10") { echo "selected"; } ?>>October</option>
						<option value="11" <?php if ($select_month == "11") { echo "selected"; } ?>>November</option>
						<option value="12" <?php if ($select_month == "12") { echo "selected"; } ?>>December</option>
					</select>
					<select id="select_day" name="select_day" onChange="this.form.submit();">
						<?php
							
							for ($i = 1; $i <= 31; $i ++)
							{
								if ($i < 10) { $j = "0" . $i; } else { $j = $i; }
								if ($j == $select_day) { $selected = "selected"; } else { $selected = ""; }
								echo "<option value='$j' $selected>$j</option>";
							}
							
						?>
					</select>
					<select id="select_gw_district" name="select_gw_district" onChange="document.getElementById('select_mandor').value = 'all';">
						<option value="all" <?php if ($select_gw_district == "all") { echo "selected"; } ?>>- Show All District -</option>
						<?php
							
							$clerk_district = array();
							
							$queryClerk = "SELECT * FROM monthly_assign_gw_district_clerks WHERE monthly_assign_gw_district_clerk_staff_id = '" . $rowUsers['id'] . "' AND monthly_assign_gw_district_clerk_deleted = '0'";
							$resultClerk = mysql_query($queryClerk) or die(mysql_error());
							$numClerk = mysql_num_rows($resultClerk);
							
							if ($numClerk > 0)
							{
								while ($rowClerk = mysql_fetch_array($resultClerk))
								{
									$district = CheckTable("*", "monthly_assign_gw", "monthly_assign_gw_id = '" . $rowClerk['monthly_assign_gw_id'] . "' AND monthly_assign_gw_status = '1'", "monthly_assign_gw_district");
									
									if (($district != "") && (!in_array($district, $clerk_district))) { array_push($clerk_district, $district); }
								}
							}
							
							$queryGWDistrict = "SELECT * FROM monthly_assign_gw WHERE monthly_assign_gw_status = '1'";
							if (count($clerk_district) > 0)
							{
								$queryGWDistrict .= " AND (";
								
								for ($h = 0; $h < count($clerk_district); $h ++)
								{
									if ($h != 0) { $queryGWDistrict .= " OR "; }
									$queryGWDistrict .= "monthly_assign_gw_district = '" . $clerk_district[$h] . "'";
								}
								
								$queryGWDistrict .= ")";
							}
							$queryGWDistrict .= " GROUP BY monthly_assign_gw_district";
							$resultGWDistrict = mysql_query($queryGWDistrict) or die(mysql_error());
							while ($rowGWDistrict = mysql_fetch_array($resultGWDistrict))
							{
							if ($select_gw_district == $rowGWDistrict['monthly_assign_gw_district']) { $selected = "selected"; } else { $selected = ""; }
								echo "<option value='" . $rowGWDistrict['monthly_assign_gw_district'] . "' $selected>" . $rowGWDistrict['monthly_assign_gw_district'] . "</option>";
							}
							
						?>
					</select>
					<select id="select_mandor" name="select_mandor">
						<option value="all" <?php if ($select_mandor == "all") { echo "selected"; } ?>>- Show All Mandor -</option>
						<?php
							
							$queryGWMandor = "SELECT * FROM monthly_assign_gw, staff_portal2.users WHERE staff_portal2.users.person = monthly_assign_gw.boss_id AND monthly_assign_gw.comp_id = '" . $rowUsers['comp_id'] . "' AND staff_portal2.users.comp_id = '" . $rowUsers['comp_id'] . "'";
							if ($select_gw_district != "all") { $queryGWMandor .= " AND monthly_assign_gw.monthly_assign_gw_district = '$select_gw_district'"; }
							$queryGWMandor .= " GROUP BY staff_portal2.users.id ORDER BY staff_portal2.users.fullname, monthly_assign_gw.monthly_assign_gw_fullname";
							$resultGWMandor = mysql_query($queryGWMandor) or die(mysql_error());
							while ($rowGWMandor = mysql_fetch_array($resultGWMandor))
							{
							if ($select_mandor == $rowGWMandor['id']) { $selected = "selected"; } else { $selected = ""; }
								echo "<option value='" . $rowGWMandor['id'] . "' $selected>" . strtoupper($rowGWMandor['fullname']) . "</option>";
							}
							
						?>
					</select>
					<input id="runScript" name="runScript" type="submit" value="Show Report">
					<br><br>
					<input id="runScript" name="runScript" type="submit" value="Previous Day">
					<input id="runScript" name="runScript" type="submit" value="Today">
					<input id="runScript" name="runScript" type="submit" value="Next Day">
					<input id="selected_date" name="selected_date" type="hidden" value="<?php echo $select_period; ?>">
				  </div>
				  </form>
				  <div class="clearfix"></div>
				</div>
				<?php if ($runScript == "Show Report") { ?>
				<div class="x_content">
				  <div class="">
                    <table class="table table-bordered">
                      <thead>
                        <tr>
                          <th>#</th>
                          <th>Staff Name</th>
						  <th>Verify</th>
                        </tr>
                      </thead>
                      <tbody>
						<?php
							
							$queryBoss = "SELECT * FROM monthly_assign_gw, staff_portal2.users WHERE staff_portal2.users.person = monthly_assign_gw.boss_id AND monthly_assign_gw.comp_id = '" . $rowUsers['comp_id'] . "' AND staff_portal2.users.comp_id = '" . $rowUsers['comp_id'] . "'";
							if ($select_gw_district != "all") { $queryBoss .= " AND monthly_assign_gw.monthly_assign_gw_district = '$select_gw_district'"; }
							if ($select_mandor != "all") { $queryBoss .= " AND staff_portal2.users.id = '$select_mandor'"; }
							$queryBoss .= " GROUP BY staff_portal2.users.person ORDER BY staff_portal2.users.fullname";
							$resultBoss = mysql_query($queryBoss) or die(mysql_error());
							$no = 0;
							while ($rowBoss = mysql_fetch_array($resultBoss))
							{
								$no ++;
							
						?>
                        <tr>
                          <!-- rowspan covers the four stacked rows of one mandor: name,
                               attachments, attendance matrix, GW detail. Keep it in step
                               with that count — one short and the last row drops into this
                               column instead, stretching it to the width of the table inside.
                               width:1% makes the cell hug its number so the content column
                               gets the rest of the page. -->
                          <th scope="row" rowspan="4" style="width:1%; white-space:nowrap;"><?php echo $no; ?></th>
                          <td><?php echo strtoupper($rowBoss['fullname']) . "<br>" . "Department:" . $rowBoss['department'] . "<br>" . "District:" . $rowBoss['district'] . "<br>" . "Position:" . $rowBoss['position']; ?></td>
						  <td scope="row" rowspan="4" align="center" style="width:1%; white-space:nowrap;">
						  <?php
						  
								$queryVerify = "SELECT * FROM gw_attendance_verify WHERE gw_attendance_verify_date = '$select_period' AND gw_attendance_verify_boss_id = '" . $rowBoss['id'] . "' AND gw_attendance_verify_deleted = '0'";
								$resultVerify = mysql_query($queryVerify) or die(mysql_error());
								$numVerify = mysql_num_rows($resultVerify);
								
								if ($numVerify == 0)
								{
									echo "<form action='gwAttendanceReport.php' method='post' enctype='multipart/form-data' class='form-horizontal form-label-left'>";
                  echo "<button type='submit' id='runScript' name='runScript' value='Verify Now' class='btn btn-default'>Verify Now<br><small>(Area Manager)</small></button>";
									echo "<input id='gw_attendance_verify_date' name='gw_attendance_verify_date' type='hidden' value='$select_period'>";
									echo "<input id='gw_attendance_verify_boss_id' name='gw_attendance_verify_boss_id' type='hidden' value='" . $rowBoss['id'] . "'>";
									echo "<input id='gw_attendance_verify_by_clerk_staff_id' name='gw_attendance_verify_by_clerk_staff_id' type='hidden' value='" . $rowUsers['id'] . "'>";
									echo "<input id='select_year' name='select_year' type='hidden' value='$select_year'>";
									echo "<input id='select_month' name='select_month' type='hidden' value='$select_month'>";
									echo "<input id='select_day' name='select_day' type='hidden' value='$select_day'>";
									echo "<input id='select_gw_district' name='select_gw_district' type='hidden' value='$select_gw_district'>";
									echo "<input id='select_mandor' name='select_mandor' type='hidden' value='$select_mandor'>";
									echo "</form>";
								} else {
									$rowVerify = mysql_fetch_array($resultVerify);
									
									echo CheckTable("*", "staff_portal2.users", "id = '" . $rowVerify['gw_attendance_verify_by_clerk_staff_id'] . "'", "fullname") . "<br>" . $rowVerify['gw_attendance_verify_by_clerk_date'] . "<br>" . $rowVerify['gw_attendance_verify_by_clerk_time'];
								}
						  
						  ?>
						  </td>
                        </tr>
						<tr>
                          <td>
						  <?php
							
								$queryAttachments = "SELECT * FROM gw_attendance_attachments WHERE gw_attendance_attachment_deleted = '0'";
								$queryAttachments .= " AND gw_attendance_attachment_by = '" . $rowBoss['id'] . "'";
								$queryAttachments .= " AND gw_attendance_attachment_date = '$select_period'";
								$queryAttachments .= " AND (monthly_assign_gw_id IS NULL OR monthly_assign_gw_id = '' OR monthly_assign_gw_id = 0)";
								$resultAttachments = mysql_query($queryAttachments) or die(mysql_error());
								
								// Read once into an array: the thumbnails below and the attendance matrix
								// further down both need this list, and a mysql result can only be walked
								// once.
								$attachmentList = array();
								while ($rowAttachments = mysql_fetch_array($resultAttachments)) { $attachmentList[] = $rowAttachments; }
								$numAttachments = count($attachmentList);
								
								if ($numAttachments > 0)
								{
									echo $numAttachments . " attachment(s):";
									echo "<div style='display:flex; flex-wrap:wrap; gap:8px; margin-top:6px;'>";
									
									foreach ($attachmentList as $rowAttachments)
									{
										$attPeriod = trim($rowAttachments['gw_attendance_attachment_period']);
										$attFile   = $rowAttachments['gw_attendance_attachment_filename'];
										$attSrc    = "attachments/" . $attFile;
										
										echo "<div style='border:1px solid #ddd; border-radius:6px; padding:6px; background:#fafafa; width:132px;'>";
										if ($attPeriod !== "") { echo "<div style='font-size:12px; font-weight:bold; color:#333; margin-bottom:4px;'>(" . htmlspecialchars($attPeriod) . ")</div>"; }
										echo "<a href='" . $attSrc . "' target='_blank'><img src='" . $attSrc . "' style='width:120px; height:auto; border-radius:4px; display:block;'></a>";
										echo "</div>";
									}
									
									echo "</div>";
								} else {
									echo "No Attachment";
								}
							
						  ?>
						  </td>
						</tr>
						<tr>
                          <td>
						  <?php
							
								// The roster is built ONCE here and reused by the matrix and by the
								// detail table below, so the two can never list different GWs.
								$gwList = getGwRoster($rowBoss['boss_id'], $rowBoss['id'], $select_period);
								
								if ($numAttachments > 0 && count($gwList) > 0)
								{
									renderGwPhotoMatrix("gwMatrix_" . $rowBoss['id'], $attachmentList, $gwList,
									                    $select_period, $GW_PERIODS, $GW_SELF_NIGHT_PERIODS);
								}
							
						  ?>
						  </td>
						</tr>
                        <tr>
                          <td>
							<table class="table table-striped">
							  <thead>
								<tr>
								  <th>#</th>
								  <th>GW Name</th>
								</tr>
							  </thead>
							  <tbody>
							  <?php
								
								// $gwList was built above, next to the attendance matrix, so both
								// views describe exactly the same roster.
								
								$no_gw = 0;
								foreach ($gwList as $rowGW)
								{
									$no_gw ++;
									
								
							  ?>
								<tr>
								  <th scope="row"><?php echo $no_gw; ?></th>
								  <td>
								  <?php
								  
									echo $rowGW['monthly_assign_gw_fullname'];
									if (!empty($rowGW['is_allocated']))
									{
										echo " <span style='display:inline-block;background:#f0ad4e;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:bold;' title='Allocated "
										      . htmlspecialchars($rowGW['alloc_date_from']) . " to " . htmlspecialchars($rowGW['alloc_date_to']) . "'>ALLOCATED</span>";
										echo " <small style='color:#999;'>(" . htmlspecialchars($rowGW['alloc_date_from']) . " &rarr; " . htmlspecialchars($rowGW['alloc_date_to']) . ")</small>";
									}
									
									// Two-column check-in view. No staff filter here: this is the assigned/allocated
									// roster, so every staff-recorded row for this GW on this date belongs to it —
									// except rows another staff logged as an ADDITIONAL GW, which are shown under
									// that staff's own Additional GW section instead of being double-counted here.
									renderGwCheckinColumns(
										$rowGW['monthly_assign_gw_id'],
										$rowGW['monthly_assign_gw_code'],
										$rowGW['monthly_assign_gw_mobile_no'],
										$select_period,
										$GW_PERIODS,
										$GW_SELF_NIGHT_PERIODS,
										" AND gw_attendance.gw_attendance_additional = '0'"
									);
									
								  ?>
								  </td>
								</tr>
							  <?php
						
								}
								
							  ?>
								<?php
								
								// ── Additional GW ────────────────────────────────────────────────────
								// GWs this staff recorded on top of their assigned/allocated roster.
								// They cannot be discovered from monthly_assign_gw.boss_id or from
								// gw_allocations — by definition they are in neither — so they are read
								// back out of the attendance rows themselves, which submitAPI.php stamps
								// with gw_attendance_additional = 1.
								$additionalWhere = gwAdditionalWhere($rowBoss['boss_id'], $rowUsers['comp_id']);
								
								// monthly_assign_gw_id AND monthly_assign_gw_code both exist on gw_attendance
								// as well as monthly_assign_gw, so every column here is table-qualified.
								$queryAdditional = "SELECT DISTINCT monthly_assign_gw.monthly_assign_gw_id AS gw_id,
								                           monthly_assign_gw.monthly_assign_gw_fullname AS gw_fullname,
								                           IFNULL(monthly_assign_gw.monthly_assign_gw_code, '') AS gw_code,
								                           IFNULL(monthly_assign_gw.monthly_assign_gw_mobile_no, '') AS gw_mobile,
								                           IFNULL(monthly_assign_gw.monthly_assign_gw_district, '') AS gw_district
								                    FROM gw_attendance
								                    INNER JOIN monthly_assign_gw
								                            ON monthly_assign_gw.monthly_assign_gw_id = gw_attendance.monthly_assign_gw_id
								                    WHERE gw_attendance.gw_attendance_by_date = '$select_period'" . $additionalWhere . "
								                    ORDER BY monthly_assign_gw.monthly_assign_gw_fullname";
								$resultAdditional = mysql_query($queryAdditional) or die(mysql_error());
								$numAdditional = mysql_num_rows($resultAdditional);
								
								?>
								<tr>
								  <td colspan="2" style="background:#e8f1fe; border-top:2px solid #005AFC;">
									<strong style="color:#0b4ea2;">Additional GW</strong>
								  </td>
								</tr>
								<?php
								
								if ($numAdditional == 0)
								{
									echo "<tr><td colspan='2' style='color:#999; font-size:12px;'>No additional GW recorded for this date.</td></tr>";
								} else {
									$no_add = 0;
									while ($rowAdd = mysql_fetch_array($resultAdditional))
									{
										$no_add ++;
								
								?>
								<tr>
								  <th scope="row"><?php echo $no_add; ?></th>
								  <td>
								  <?php
								  
									echo $rowAdd['gw_fullname'];
									if ($rowAdd['gw_district'] !== "")
									{
										echo " <small style='color:#999;'>(District: " . htmlspecialchars($rowAdd['gw_district']) . ")</small>";
									}
									
									// Same two-column view as the roster above, narrowed to the rows this
									// staff recorded as additional. Self check-in is deliberately NOT
									// narrowed — it is the GW's own punch, read the usual way.
									// Empty slots are suppressed in both columns: an additional GW has no
									// expected roster of slots, so "Not checked in" would assert an
									// absence that was never expected in the first place.
									renderGwCheckinColumns(
										$rowAdd['gw_id'],
										$rowAdd['gw_code'],
										$rowAdd['gw_mobile'],
										$select_period,
										$GW_PERIODS,
										$GW_SELF_NIGHT_PERIODS,
										$additionalWhere,
										false   // only the slots actually attended — no empty default cards
									);
									
								  ?>
								  </td>
								</tr>
								<?php
						
									}
								}
								
								?>
							  </tbody>
							</table>
						  </td>
                        </tr>
						<?php
							
							}
							
						?>
                      </tbody>
                    </table>
                    <div class="clearfix"></div>
				  </div>
				</div>
				<?php } ?>
			  </div>
			</div>
			<!-- End to do list -->
			
          </div>
        </div>
        <!-- /page content -->

        <!-- footer content -->
        <footer>
          <?php include_once("includes/footer.php"); ?>
        </footer>
        <!-- /footer content -->
      </div>
    </div>

    <!-- jQuery -->
    <script src="../vendors/jquery/dist/jquery.min.js"></script>
    <!-- Bootstrap -->
    <script src="../vendors/bootstrap/dist/js/bootstrap.min.js"></script>
    <!-- FastClick -->
    <script src="../vendors/fastclick/lib/fastclick.js"></script>
    <!-- NProgress -->
    <script src="../vendors/nprogress/nprogress.js"></script>
    <!-- Chart.js -->
    <script src="../vendors/Chart.js/dist/Chart.min.js"></script>
    <!-- gauge.js -->
    <script src="../vendors/bernii/gauge.js/dist/gauge.min.js"></script>
    <!-- bootstrap-progressbar -->
    <script src="../vendors/bootstrap-progressbar/bootstrap-progressbar.min.js"></script>
    <!-- iCheck -->
    <script src="../vendors/iCheck/icheck.min.js"></script>
    <!-- Skycons -->
    <script src="../vendors/skycons/skycons.js"></script>

    <!-- Custom Theme Scripts -->
    <script src="../build/js/custom.min.js"></script>

    <!-- Attendance matrix: identify the group photos and fill the Group Photo columns -->
    <style type="text/css">
    /* A Group Photo cell has four end states and one waiting state, and they
       must be told apart at a glance: a pending cell that looks like an empty
       one reads as "this person was not in the photo" while the answer is still
       being computed. Hence a moving dot for waiting and a word, not a symbol,
       for the case where the camera disagreed with the check-in records. */
    @keyframes gwScanPulse { 0%, 100% { opacity: 0.2; } 50% { opacity: 1; } }
    .gwScanDot {
        display: inline-block; width: 7px; height: 7px; border-radius: 50%;
        background: #5b9bd5; animation: gwScanPulse 1s ease-in-out infinite;
    }
    .gwMiss  { color: #b06f00; font-size: 10px; line-height: 1.1; }
    .gwError { color: #a94442; font-size: 10px; line-height: 1.1; }
    </style>
    <script type="text/javascript">
    /*
     * Every attachment on the page is identified automatically — there is no
     * button. Three things keep that affordable:
     *
     *   1. The server caches each scan permanently (a photo's pixels never
     *      change), so only the first view of a day does any real work.
     *   2. Scans are queued GLOBALLY at a small concurrency. A busy report can
     *      hold 90 photos; firing those at once would bury the face service and
     *      stall the browser's connection pool for the rest of the page.
     *   3. It starts after load, so nothing here delays first paint.
     */
    (function () {
        var MAX_PARALLEL = 3;
        var queue = [];
        var active = 0;

        function escapeHtml(text) {
            return String(text == null ? '' : text)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function pump() {
            while (active < MAX_PARALLEL && queue.length > 0) {
                active++;
                queue.shift()(function () { active--; pump(); });
            }
        }

        /* Shared per-table state. periodPending counts the photos still
         * outstanding FOR EACH PERIOD, because a column can only be resolved
         * once every photo taken in that slot has reported — with two 8AM
         * photos, the first one coming back empty says nothing yet. */
        function tableState(table) {
            if (!table.__gwState) {
                table.__gwState = {
                    pending: 0, done: 0, failed: 0,
                    periodPending: {}, periodFailed: {}, extras: {}
                };
            }
            return table.__gwState;
        }

        function setStatus(table, text) {
            var el = document.getElementById(table.id + '_status');
            if (el) { el.innerHTML = text; }
        }

        /* A person the camera found who has no row in the matrix: someone from
         * another mandor's roster, or the mandor themselves. Keyed so the same
         * person seen in two photos becomes one row listing both periods.
         * The server has already dropped anyone below the off-roster confidence
         * floor, so everything arriving here is worth showing. */
        function noteExtra(state, person, period) {
            var key = (person.type === 'gw' ? 'gw:' : 'st:') + person.comp_id + ':' + person.code;
            var row = state.extras[key];
            if (!row) {
                row = state.extras[key] = { person: person, periods: {}, best: 0 };
            }
            row.periods[period] = true;
            if (person.confidence > row.best) { row.best = person.confidence; }
        }

        function cssEscape(value) {
            return String(value == null ? '' : value).replace(/["\\]/g, '\\$&');
        }

        /* Tick this person's cell for this period.
         *
         * Matched on GW CODE, because that is the identity the face service
         * works in; the row id is only a fallback for a GW with no code. And
         * EVERY row carrying the code is ticked, not just the first: the same
         * worker can hold two active monthly_assign_gw rows (registered under
         * two mandors, one of them showing as ALLOC), and they are one human
         * being who was either in the photo or was not. Ticking one and leaving
         * the other reading "not detected" would describe the same person as
         * both present and missed in a single table. */
        /* Does this identified person belong in "Additional from the photo"?
         *
         * Everyone the table could not tick goes there, so that nobody standing
         * in the photo silently disappears — another mandor's GW, a visiting
         * staff member, and the mandor themselves, who is in the pool but is
         * staff and has no row here.
         *
         * The single exception is a ROSTERED GW: they own a row above, so being
         * unplaceable means their GW record and their face enrolment disagree.
         * Listing them would print the same person twice in one table, which is
         * what this block is meant to avoid, so they are left out and their own
         * row reads "not detected".
         */
        function belongsInExtras(person, placed) {
            if (placed) { return false; }
            return !(person.type === 'gw' && person.in_pool);
        }

        function markCell(table, person, period, confidence) {
            var sel = [];
            if (person.code) {
                sel.push('.gwPhotoCell[data-gw-code="' + cssEscape(person.code)
                         + '"][data-period="' + cssEscape(period) + '"]');
            }
            if (person.gw_id) {
                sel.push('.gwPhotoCell[data-gw-id="' + cssEscape(person.gw_id)
                         + '"][data-period="' + cssEscape(period) + '"]');
            }

            var cells = [];
            for (var s = 0; s < sel.length && cells.length === 0; s++) {
                cells = table.querySelectorAll(sel[s]);
            }
            if (!cells.length) { return false; }

            var pct = Math.round((confidence || 0) * 100);
            for (var i = 0; i < cells.length; i++) {
                var cell = cells[i];
                cell.setAttribute('data-resolved', '1');
                cell.style.background = '#eaf7ea';
                cell.style.color = '#2e7d32';
                cell.innerHTML = '&#10003;';
                cell.title = 'Identified in the ' + period + ' photo (' + pct + '% confidence)';
            }
            return true;
        }

        /* Every photo for this period has reported. Cells still unresolved were
         * not matched in any of them, so say what that means — which depends
         * entirely on whether anything else puts the GW on site. */
        function finalizePeriod(table, period) {
            var state = tableState(table);
            var failed = !!state.periodFailed[period];
            var cells = table.querySelectorAll('.gwPhotoCell[data-period="' + period + '"]');

            for (var i = 0; i < cells.length; i++) {
                var cell = cells[i];
                if (cell.getAttribute('data-resolved')) { continue; }
                cell.setAttribute('data-resolved', '1');

                if (failed) {
                    cell.style.background = '#fff5f5';
                    cell.innerHTML = '<span class="gwError">scan failed</span>';
                    cell.title = 'The photo could not be scanned, so nothing is known either way.';
                } else if (cell.getAttribute('data-checked-in')) {
                    // Checked in, but the face was not found: the detector missed
                    // them. Masks, hard hats, sunglasses and small faces in a wide
                    // group shot all cause this, so it is a statement about the
                    // photo, not about attendance.
                    cell.style.background = '#fffaf0';
                    cell.innerHTML = '<span class="gwMiss">not detected</span>';
                    cell.title = 'Checked in for this slot, but the face was not detected in the '
                               + period + ' photo. Usually a mask, hat, sunglasses or a small/blurred face.';
                } else {
                    cell.style.background = '#fafafa';
                    cell.style.color = '#ccc';
                    cell.innerHTML = '&ndash;';
                    cell.title = 'Not identified in the ' + period + ' photo, and no check-in recorded.';
                }
            }
        }

        /* Once every photo has answered, append a row per off-roster person. */
        function renderExtras(table, slots) {
            var state = tableState(table);
            var anchor = table.querySelector('.gwExtraAnchor');
            if (!anchor) { return; }

            var keys = [];
            for (var k in state.extras) {
                if (Object.prototype.hasOwnProperty.call(state.extras, k)) { keys.push(k); }
            }
            if (keys.length === 0) { return; }

            keys.sort(function (a, b) { return state.extras[b].best - state.extras[a].best; });

            /* Rows are built and inserted against the anchor's own parent rather
             * than with table.insertRow(index): with a <thead> present, that
             * index is counted over the whole table, which is an easy way to
             * land a row in the header. insertBefore has no such ambiguity. */
            var body = anchor.parentNode;

            function addCell(row, html, css) {
                var cell = document.createElement('td');
                cell.style.cssText = css;
                cell.innerHTML = html;
                row.appendChild(cell);
                return cell;
            }

            var header = document.createElement('tr');
            var hc = document.createElement('td');
            hc.colSpan = 2 + 3 * slots.length;
            hc.style.cssText = 'background:#fff7e6; border-top:2px solid #f0ad4e; font-size:11px;';
            hc.innerHTML = '<strong style="color:#8a6d3b;">Additional from the photo</strong>'
                         + ' <span style="color:#999;">&mdash; identified by face, not on this '
                         + 'mandor\'s roster for the day</span>';
            header.appendChild(hc);
            body.insertBefore(header, anchor);

            for (var i = 0; i < keys.length; i++) {
                var extra = state.extras[keys[i]];
                var p = extra.person;
                var row = document.createElement('tr');

                addCell(row, '&bull;', 'color:#999;');

                var detail = [];
                if (p.type === 'gw' && p.code) { detail.push(p.code); }
                if (p.district) { detail.push(p.district); }
                if (p.type === 'staff') { detail.push('staff'); }

                addCell(row,
                    escapeHtml(p.name)
                      + (detail.length ? ' <small style="color:#999;">(' + escapeHtml(detail.join(' / ')) + ')</small>' : '')
                      + ' <small style="color:#aaa;">' + Math.round(extra.best * 100) + '%</small>',
                    'white-space:nowrap;');

                // Group-photo columns: tick the periods this person appeared in.
                for (var sgi = 0; sgi < slots.length; sgi++) {
                    var seen = !!extra.periods[slots[sgi]];
                    addCell(row, seen ? '&#10003;' : '&ndash;',
                        'text-align:center; font-size:11px; border-left:'
                        + (sgi === 0 ? '2px solid #005AFC' : '1px solid #eee') + ';'
                        + (seen ? 'background:#eaf7ea; color:#2e7d32;' : 'background:#fafafa; color:#ddd;'));
                }
                // Check-in columns do not apply to someone off the roster.
                for (var g = 0; g < 2; g++) {
                    for (var si = 0; si < slots.length; si++) {
                        addCell(row, '&ndash;',
                            'text-align:center; font-size:11px; color:#ddd; background:#fafafa;'
                            + 'border-left:' + (si === 0 ? '2px solid #999' : '1px solid #eee') + ';');
                    }
                }

                body.insertBefore(row, anchor);
            }
        }

        function finishIfDone(table, slots) {
            var state = tableState(table);
            if (state.pending > 0) { return; }
            renderExtras(table, slots);
            var bits = [];
            if (state.done > 0)   { bits.push(state.done + ' photo' + (state.done === 1 ? '' : 's') + ' scanned'); }
            if (state.failed > 0) { bits.push('<span style="color:#a94442;">' + state.failed + ' failed</span>'); }
            setStatus(table, bits.join(', '));
        }

        function scanPhoto(table, slots, attachment, release) {
            var state = tableState(table);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'gwAttendanceIdentify.php', true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            // Comfortably above the PHP timeout behind it, so a slow scan is
            // reported by the server rather than abandoned here unexplained.
            xhr.timeout = 180000;

            function done(ok) {
                state.pending--;
                if (ok) { state.done++; } else { state.failed++; state.periodFailed[attachment.period] = true; }

                state.periodPending[attachment.period]--;
                if (state.periodPending[attachment.period] <= 0) {
                    finalizePeriod(table, attachment.period);
                }

                finishIfDone(table, slots);
                release();
            }

            xhr.ontimeout = function () { done(false); };
            xhr.onerror   = function () { done(false); };

            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) { return; }

                var data = null;
                try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }

                if (!data || !data.success) { done(false); return; }

                var people = data.people || [];
                for (var i = 0; i < people.length; i++) {
                    var p = people[i];
                    // A GW on this mandor's roster has a row; tick it. Anyone
                    // else — another mandor's GW, or a staff member — becomes an
                    // "Additional from the photo" row once all photos are in.
                    var placed = (p.type === 'gw' && (p.code || p.gw_id))
                        ? markCell(table, p, attachment.period, p.confidence)
                        : false;

                    if (belongsInExtras(p, placed)) { noteExtra(state, p, attachment.period); }
                }
                done(true);
            };

            xhr.send(JSON.stringify({ attachment_id: attachment.id }));
        }

        function startTable(table) {
            var attachments, slots;
            try {
                attachments = JSON.parse(table.getAttribute('data-attachments') || '[]');
                slots = JSON.parse(table.getAttribute('data-slots') || '[]');
            } catch (e) { return; }
            if (!attachments.length || !slots.length) { return; }

            var state = tableState(table);
            state.pending = attachments.length;
            for (var a = 0; a < attachments.length; a++) {
                var per = attachments[a].period;
                state.periodPending[per] = (state.periodPending[per] || 0) + 1;
            }

            setStatus(table, '<span class="gwScanDot"></span> identifying '
                             + attachments.length + ' photo'
                             + (attachments.length === 1 ? '' : 's') + '&hellip;');

            for (var k = 0; k < attachments.length; k++) {
                (function (attachment) {
                    queue.push(function (release) { scanPhoto(table, slots, attachment, release); });
                })(attachments[k]);
            }
            pump();
        }

        function startAll() {
            var tables = document.querySelectorAll('table[data-attachments]');
            for (var i = 0; i < tables.length; i++) { startTable(tables[i]); }
        }

        if (window.addEventListener) { window.addEventListener('load', startAll, false); }
        else { window.attachEvent('onload', startAll); }
    })();
    </script>

  </body>
</html>