<?php

	ob_start();   // buffer so Excel/PDF export can send headers after the includes

	include_once("includes/dbDailyActivityApp.php");
	include_once("includes/accessDailyActivityApp.php");
	include_once("includes/function.php");
	include_once("includes/attendanceReportShared.php");

	// ── Inputs ──
	if (isset($_REQUEST['runScript']))       { $runScript       = $_REQUEST['runScript']; }       else { $runScript       = ""; }
	if (isset($_REQUEST['report_district'])) { $report_district = $_REQUEST['report_district']; } else { $report_district = "all"; }
	if (isset($_REQUEST['report_position'])) { $report_position = $_REQUEST['report_position']; } else { $report_position = "all"; }
	if (isset($_REQUEST['report_ot']))       { $report_ot       = $_REQUEST['report_ot']; }       else { $report_ot       = "none"; }
	if (isset($_REQUEST['export']))          { $export          = $_REQUEST['export']; }          else { $export          = ""; }

	// Day / Week / Custom range (shared resolver). attendance_by_date is Y/m/d.
	$range = attResolveRange($_REQUEST);

	// OT filter is request-wide state, so the screen and the exports can never disagree.
	attSetOtFilter($report_ot);
	$report_ot = attOtFilter();   // normalised ('none' unless 'all' or a real OT code was posted)

	// Staff 1:00pm check-in: not collected 21–30 Sep 2026; back from 1 Oct 2026 as an
	// optional slot (never LATE, never MISSING). GW still submits it as a required slot.
	attRetirePeriod('1PM', '2026/09/21', '2026/09/30');
	attMakePeriodOptionalFrom('1PM', '2026/10/01');

	// Escape everything that reaches SQL.
	$e_district = mysql_real_escape_string($report_district, $db_globportal);
	$e_position = mysql_real_escape_string($report_position, $db_globportal);
	$comp_id    = mysql_real_escape_string($rowUsers['comp_id'], $db_globportal);

	/**
	 * Query: one staff member's self check-ins over the range, grouped by period code.
	 * Matches the self API exactly (self_checkin=1, submittedFrom='1'), joins the photo.
	 */
	function getStaffSelfCheckins($db, $person, $compId, $mymkId, $range)
	{
		$e_person = mysql_real_escape_string($person, $db);
		$e_comp   = mysql_real_escape_string($compId, $db);
		$e_mymk   = mysql_real_escape_string($mymkId, $db);

		$sql  = "SELECT a.attendance_id, a.attendance_period AS period, a.attendance_by_date AS date, a.attendance_by_time AS time,";
		$sql .= " a.attendance_remarks AS remarks, a.verify_type AS verify_type,";
		$sql .= " a.attendance_latitude AS lat, a.attendance_longitude AS lng,";
		$sql .= " t.attendance_attachment_filename AS filename";
		$sql .= " FROM evaluation.staff_attendance a";
		$sql .= " LEFT JOIN evaluation.staff_attendance_attachments t";
		$sql .= " ON t.attendance_id = a.attendance_id AND t.attendance_attachment_deleted = '0'";
		$sql .= " WHERE ((a.attendance_by_person = '$e_person' AND a.attendance_by_comp_id = '$e_comp') OR a.mymk_id = '$e_mymk')";
		$sql .= " AND a.attendance_self_checkin = 1";
		$sql .= " AND a.attendance_submittedFrom = '1'";
		$sql .= attDateSql("a.attendance_by_date", $range, $db);
		$sql .= " GROUP BY a.attendance_id";
		$sql .= " ORDER BY a.attendance_by_date ASC, a.attendance_by_time ASC";
		$result = mysql_query($sql, $db) or die(mysql_error($db));

		$byPeriod = array();
		while ($row = mysql_fetch_array($result))
		{
			$photo = !empty($row['filename']) ? ATT_STAFF_IMG_URL_BASE . rawurlencode($row['filename']) : "";
			$byPeriod[$row['period']][$row['date']][] = attEntry($row['date'], $row['time'], $row['verify_type'], $row['remarks'], $photo, $row['lat'], $row['lng']);
		}
		return $byPeriod;
	}

	/**
	 * Command: echo the result table(s) for the staff report. Reused by the on-screen
	 * page and by the Excel/PDF export so the layout exists in exactly one place.
	 */
	function renderStaffResult($db, $range, $report_district, $report_position, $comp_id)
	{
		$e_district = mysql_real_escape_string($report_district, $db);
		$e_position = mysql_real_escape_string($report_position, $db);

		$queryStaffs  = "SELECT users.id, users.person, users.comp_id, users.fullname, users.district, users.position";
		$queryStaffs .= " FROM staff_portal2.users, staff_portal2.glob_users_districts, staff_portal2.glob_districts, staff_portal2.glob_users_positions, staff_portal2.glob_positions";
		$queryStaffs .= " WHERE users.comp_id = '$comp_id' AND users.status = '1' AND users.deleted = '0'";
		$queryStaffs .= " AND glob_users_districts.staff_id = users.id AND glob_districts.glob_district_id = glob_users_districts.glob_district_id AND glob_users_districts.glob_user_district_deleted = '0'";
		$queryStaffs .= " AND glob_users_positions.staff_id = users.id AND glob_positions.glob_position_id = glob_users_positions.glob_position_id AND glob_users_positions.glob_user_position_deleted = '0'";
		if ($report_district != "all") { $queryStaffs .= " AND glob_users_districts.glob_district_id = '$e_district'"; }
		if ($report_position != "all") { $queryStaffs .= " AND glob_users_positions.glob_position_id = '$e_position'"; }
		$queryStaffs .= " GROUP BY users.id ORDER BY glob_districts.glob_district_name, glob_positions.glob_position_title, users.fullname";
		$resultStaffs = mysql_query($queryStaffs, $db) or die(mysql_error($db));

		$staffData = array();
		$no = 0;
		while ($rowStaffs = mysql_fetch_array($resultStaffs))
		{
			$grid = getStaffSelfCheckins($db, $rowStaffs['person'], $rowStaffs['comp_id'], $rowStaffs['id'], $range);

			// When one OT slot is selected the report lists only the people who worked it.
			// Filtered here (before numbering) so '#' stays contiguous.
			if (!attPersonPassesOtFilter($grid)) { continue; }

			$no ++;

			$district_names = array();
			$qd = "SELECT * FROM staff_portal2.glob_users_districts WHERE staff_id = '" . $rowStaffs['id'] . "' AND glob_user_district_deleted = '0'";
			$rd = mysql_query($qd, $db) or die(mysql_error($db));
			while ($row_d = mysql_fetch_array($rd))
			{
				$district_names[] = CheckTable($db, "*", "staff_portal2.glob_districts", "glob_district_id = '" . $row_d['glob_district_id'] . "'", "glob_district_name");
			}

			$position_names = array();
			$qp = "SELECT * FROM staff_portal2.glob_users_positions WHERE staff_id = '" . $rowStaffs['id'] . "' AND glob_user_position_deleted = '0'";
			$rp = mysql_query($qp, $db) or die(mysql_error($db));
			while ($row_p = mysql_fetch_array($rp))
			{
				$position_names[] = CheckTable($db, "*", "staff_portal2.glob_positions", "glob_position_id = '" . $row_p['glob_position_id'] . "'", "glob_position_title");
			}

			$staffData[] = array(
				'no'        => $no,
				'name'      => strtoupper($rowStaffs['fullname']),
				'districts' => implode(", ", $district_names),
				'positions' => implode(", ", $position_names),
				'person'    => $rowStaffs['person'],
				'grid'      => $grid,
			);
		}

		// Leave for every staff member in TWO queries total (driver_leaves + HR), then
		// attach each person's slice — instead of two queries per head.
		$persons = array();
		foreach ($staffData as $s) { $persons[] = $s['person']; }
		$leaveByPerson = attStaffLeaveDaysBatch($db, $persons, $range);
		foreach ($staffData as $i => $s)
		{
			$staffData[$i]['leave'] = isset($leaveByPerson[$s['person']]) ? $leaveByPerson[$s['person']] : array();
		}

		$days         = attRangeDays($range);
		$holidayByDay = attHolidaysByDay($db, $days);

		if ($range['multi'])
		{
			echo "<div style='overflow-x:auto;'>";
			echo "<table class='table table-bordered' border='1' style='margin-bottom:0;'>";
			attRenderCalendarHeader(array('#', 'Staff Name', 'District', 'Position'), $days);
			foreach ($staffData as $s)
			{
				$leading = array(
					$s['no'],
					"<strong>" . htmlspecialchars($s['name']) . "</strong>",
					htmlspecialchars($s['districts']),
					htmlspecialchars($s['positions']),
				);
				attRenderPersonCalendarRows($leading, $s['grid'], $days, $holidayByDay, $s['leave']);
			}
			echo "</table></div>";
		}
		else
		{
			$periods = attActivePeriods($days);

			echo "<div style='overflow-x:auto;'>";
			echo "<table class='table table-bordered table-hover' border='1' style='table-layout:fixed; margin-bottom:0;'>";
			echo "<thead><tr>";
			echo "<th style='width:40px; text-align:center;'>#</th>";
			echo "<th style='width:14%; text-align:center;'>Staff Name</th>";
			echo "<th style='width:11%; text-align:center;'>District</th>";
			echo "<th style='width:11%; text-align:center;'>Position</th>";
			foreach ($periods as $pCode => $pLabel) { echo "<th style='text-align:center; min-width:130px;'>" . htmlspecialchars($pLabel) . "</th>"; }
			echo "</tr></thead><tbody>";
			foreach ($staffData as $s)
			{
				echo "<tr>";
				echo "<th scope='row' style='text-align:center;'>" . $s['no'] . "</th>";
				echo "<td style='word-wrap:break-word;'>" . htmlspecialchars($s['name']) . "</td>";
				echo "<td style='word-wrap:break-word;'>" . htmlspecialchars($s['districts']) . "</td>";
				echo "<td style='word-wrap:break-word;'>" . htmlspecialchars($s['positions']) . "</td>";
				foreach ($periods as $pCode => $pLabel)
				{
					$entries = isset($s['grid'][$pCode][$range['from']]) ? $s['grid'][$pCode][$range['from']] : array();
					$isHol   = !empty($holidayByDay[$range['from']]);
					$onLeave = isset($s['leave'][$range['from']]) ? $s['leave'][$range['from']] : '';
					renderSingleDayTd($pCode, $entries, $range['from'], $isHol, $onLeave);
				}
				echo "</tr>";
			}
			echo "</tbody></table></div>";
		}
	}

	// ── Export (Excel / PDF) — reuses renderStaffResult, then exits ──
	if ($runScript == "Show Report" && ($export == "excel" || $export == "pdf"))
	{
		attSetMode($export);
		while (ob_get_level() > 0) { ob_end_clean(); }   // drop buffered page chrome

		$otLbl    = attOtFilterLabel();
		$otSuffix = ($otLbl !== "") ? " - " . $otLbl : "";
		$otFname  = ($otLbl !== "") ? "_OT" . strtoupper(str_replace(".", "", attOtFilter())) : "";
		$title = "Staff Attendance Report" . $otSuffix . " - " . html_entity_decode(strip_tags(attRangeLabel($range)));
		if ($export == "excel")
		{
			$fname = "StaffAttendance" . $otFname . "_" . $range['from_ymd'] . ($range['multi'] ? "_to_" . $range['to_ymd'] : "");
			attExcelDownloadHeaders($fname);
		}
		attExportDocStart($title, $export);
		renderStaffResult($db_globportal, $range, $report_district, $report_position, $comp_id);
		attExportDocEnd($export);
		exit;
	}

?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>ATTENDANCE APP - Staff Attendance Report</title>

    <link href="../vendors/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../vendors/font-awesome/css/font-awesome.min.css" rel="stylesheet">
    <link href="../vendors/iCheck/skins/flat/green.css" rel="stylesheet">
    <link href="../build/css/custom.min.css" rel="stylesheet">

	<script type="text/javascript" src="includes/function.js"></script>
  </head>

  <body class="nav-md">
    <div class="container body">
      <div class="main_container">
        <div class="col-md-3 left_col">
          <div class="left_col scroll-view">
			<?php include_once("includes/sidebarDailyActivityApp.php"); ?>
          </div>
        </div>

        <div class="top_nav">
          <?php include_once("includes/topbar.php"); ?>
        </div>

        <div class="right_col" role="main">
          <div class="">
            <div class="clearfix"></div>
            <div class="row">
              <div class="col-md-12 col-sm-12 col-xs-12">
                <div class="x_panel">
                  <div class="x_title">
                    <h2>Attendance App Report <small>STAFF</small></h2>
                    <div class="clearfix"></div>
                  </div>
                  <div class="x_content">
                    <br />
                    <form id="form_submit" name="form_submit" class="form-horizontal form-label-left" action="" method="post">

                      <?php attRenderRangeFilter($range); ?>

                      <div class="form-group">
                        <label class="control-label col-md-3 col-sm-3 col-xs-12">District</label>
                        <div class="col-md-6 col-sm-6 col-xs-12">
						  <select id="report_district" name="report_district" class="form-control">
							<option value="all">All Districts</option>
							<?php
								$queryDistricts = "SELECT * FROM staff_portal2.glob_districts WHERE glob_district_deleted = '0' ORDER BY glob_district_name";
								$resultDistricts = mysql_query($queryDistricts, $db_globportal) or die(mysql_error($db_globportal));
								while ($rowDistricts = mysql_fetch_array($resultDistricts))
								{
									$selected = ($report_district == $rowDistricts['glob_district_id']) ? "selected" : "";
									echo "<option value='" . $rowDistricts['glob_district_id'] . "' $selected>" . $rowDistricts['glob_district_name'] . " - " . $rowDistricts['glob_district_shortname'] . "</option>";
								}
							?>
						  </select>
                        </div>
                      </div>

                      <div class="form-group">
                        <label class="control-label col-md-3 col-sm-3 col-xs-12">Position</label>
                        <div class="col-md-6 col-sm-6 col-xs-12">
						  <select id="report_position" name="report_position" class="form-control">
							<option value="all">All Positions</option>
							<?php
								// Only positions actually assigned to active staff in this company, so the
								// filter always matches what appears in the table (no reliance on the
								// glob_position_show_report flag, which hides e.g. Area Manager).
								$queryPositions  = "SELECT DISTINCT glob_positions.glob_position_id, glob_positions.glob_position_title";
								$queryPositions .= " FROM staff_portal2.glob_positions, staff_portal2.glob_users_positions, staff_portal2.users";
								$queryPositions .= " WHERE glob_users_positions.glob_position_id = glob_positions.glob_position_id";
								$queryPositions .= " AND glob_users_positions.glob_user_position_deleted = '0'";
								$queryPositions .= " AND glob_positions.glob_position_deleted = '0'";
								$queryPositions .= " AND users.id = glob_users_positions.staff_id";
								$queryPositions .= " AND users.comp_id = '$comp_id' AND users.status = '1' AND users.deleted = '0'";
								$queryPositions .= " ORDER BY glob_positions.glob_position_title";
								$resultPositions = mysql_query($queryPositions, $db_globportal) or die(mysql_error($db_globportal));
								while ($rowPositions = mysql_fetch_array($resultPositions))
								{
									$selected = ($report_position == $rowPositions['glob_position_id']) ? "selected" : "";
									echo "<option value='" . $rowPositions['glob_position_id'] . "' $selected>" . $rowPositions['glob_position_title'] . "</option>";
								}
							?>
						  </select>
                        </div>
                      </div>

                      <?php attRenderOtFilter($report_ot); ?>

                      <div class="form-group">
                        <div class="col-md-6 col-sm-6 col-xs-12 col-md-offset-3">
                          <button id="runScript" name="runScript" type="submit" class="btn btn-primary" value="Show Report">Show Report</button>
                          <?php if ($runScript == "Show Report") { attRenderExportButtons(); } ?>
                        </div>
                      </div>

                      <div class="ln_solid"></div>

					  <?php if ($runScript == "Show Report") { ?>
                      <div class="form-group">
						  <div class="col-md-12 col-sm-12 col-xs-12">
							<div class="x_panel">
							  <div class="x_title">
								<h2>Staff Attendance <small><?php echo attRangeLabel($range); $otLbl = attOtFilterLabel(); if ($otLbl !== "") { echo " &middot; OT " . htmlspecialchars($otLbl); } ?></small></h2>
								<div class="clearfix"></div>
							  </div>
							  <div class="x_content">

								<?php renderStaffResult($db_globportal, $range, $report_district, $report_position, $comp_id); ?>

							  </div>
							</div>
						  </div>
                      </div>
					  <?php } ?>

                    </form>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <footer>
          <?php include_once("includes/footer.php"); ?>
        </footer>
      </div>
    </div>

    <script src="../vendors/jquery/dist/jquery.min.js"></script>
    <script src="../vendors/bootstrap/dist/js/bootstrap.min.js"></script>
    <script src="../build/js/custom.min.js"></script>

    <?php attRenderLocationModal(); ?>
  </body>
</html>