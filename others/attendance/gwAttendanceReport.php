<?php

	ob_start();   // buffer so Excel/PDF export can send headers after the includes

	include_once("includes/dbDailyActivityApp.php");
	include_once("includes/accessDailyActivityApp.php");
	include_once("includes/function.php");
	include_once("includes/attendanceReportShared.php");

	// ── Inputs ──
	if (isset($_REQUEST['runScript']))          { $runScript          = $_REQUEST['runScript']; }          else { $runScript          = ""; }
	if (isset($_REQUEST['report_gw_district'])) { $report_gw_district = $_REQUEST['report_gw_district']; } else { $report_gw_district = "all"; }
	if (isset($_REQUEST['report_ot']))          { $report_ot          = $_REQUEST['report_ot']; }          else { $report_ot          = "none"; }
	if (isset($_REQUEST['export']))             { $export             = $_REQUEST['export']; }             else { $export             = ""; }

	// Day / Week / Custom range (shared resolver). gw_attendance_by_date is Y/m/d.
	$range = attResolveRange($_REQUEST);

	// OT filter is request-wide state, so the screen and the exports can never disagree.
	attSetOtFilter($report_ot);
	$report_ot = attOtFilter();   // normalised ('none' unless 'all' or a real OT code was posted)

	// Escape everything that reaches SQL.
	$e_district = mysql_real_escape_string($report_gw_district, $db_globportal);
	$comp_id    = mysql_real_escape_string($rowUsers['comp_id'], $db_globportal);

	/**
	 * Query: one GW's self check-ins over the range, grouped by period code.
	 * Matches the self API exactly (self_checkin=1, submittedFrom='1'), joins the photo.
	 */
	function getGwSelfCheckins($db, $gwCode, $range)
	{
		$e_code = mysql_real_escape_string($gwCode, $db);

		$sql  = "SELECT a.gw_attendance_id, a.gw_attendance_period AS period, a.gw_attendance_by_date AS date, a.gw_attendance_by_time AS time,";
		$sql .= " a.gw_attendance_gw_remarks AS remarks, a.gw_verify_type AS verify_type,";
		$sql .= " a.gw_attendance_latitude AS lat, a.gw_attendance_longitude AS lng,";
		$sql .= " t.gw_attendance_attachment_filename AS filename";
		$sql .= " FROM evaluation.gw_attendance a";
		$sql .= " LEFT JOIN evaluation.gw_attendance_attachments t";
		$sql .= " ON t.gw_attendance_id = a.gw_attendance_id AND t.gw_attendance_attachment_deleted = '0'";
		$sql .= " WHERE a.monthly_assign_gw_code = '$e_code'";
		$sql .= " AND a.gw_attendance_self_checkin = 1";
		$sql .= " AND a.gw_attendance_submittedFrom = '1'";
		$sql .= attDateSql("a.gw_attendance_by_date", $range, $db);
		$sql .= " GROUP BY a.gw_attendance_id";
		$sql .= " ORDER BY a.gw_attendance_by_date ASC, a.gw_attendance_by_time ASC";
		$result = mysql_query($sql, $db) or die(mysql_error($db));

		$byPeriod = array();
		while ($row = mysql_fetch_array($result))
		{
			$photo = !empty($row['filename']) ? ATT_GW_IMG_URL_BASE . rawurlencode($row['filename']) : "";
			$byPeriod[$row['period']][$row['date']][] = attEntry($row['date'], $row['time'], $row['verify_type'], $row['remarks'], $photo, $row['lat'], $row['lng']);
		}
		return $byPeriod;
	}

	/**
	 * Command: echo the result table(s) for the GW report. Reused by the on-screen page
	 * and by the Excel/PDF export so the layout exists in exactly one place.
	 */
	function renderGwResult($db, $range, $report_gw_district, $comp_id)
	{
		$e_district = mysql_real_escape_string($report_gw_district, $db);

		// nric is selected for one reason: leave_application_GW has no GW code and is
		// matched on the NRIC instead. See attGwLeaveDaysBatch().
		$queryGW  = "SELECT monthly_assign_gw_id, monthly_assign_gw_code, monthly_assign_gw_fullname, monthly_assign_gw_district, monthly_assign_gw_nric";
		$queryGW .= " FROM evaluation.monthly_assign_gw";
		$queryGW .= " WHERE comp_id = '$comp_id'";
		$queryGW .= attGwActiveSql();
		if ($report_gw_district != "all") { $queryGW .= " AND monthly_assign_gw_district = '$e_district'"; }
		$queryGW .= " ORDER BY monthly_assign_gw_district, monthly_assign_gw_fullname";
		$resultGW = mysql_query($queryGW, $db) or die(mysql_error($db));

		$gwData = array();
		$no = 0;
		while ($rowGW = mysql_fetch_array($resultGW))
		{
			$grid = getGwSelfCheckins($db, $rowGW['monthly_assign_gw_code'], $range);

			// When one OT slot is selected the report lists only the people who worked it.
			// Filtered here (before numbering) so '#' stays contiguous.
			if (!attPersonPassesOtFilter($grid)) { continue; }

			$no ++;
			$gwData[] = array(
				'no'       => $no,
				'code'     => $rowGW['monthly_assign_gw_code'],
				'name'     => strtoupper($rowGW['monthly_assign_gw_fullname']),
				'district' => $rowGW['monthly_assign_gw_district'],
				'nric'     => $rowGW['monthly_assign_gw_nric'],
				'grid'     => $grid,
			);
		}

		// Leave for every GW on the report in ONE query, then attach each one's slice —
		// instead of a query per head. Keyed on the GW code, matched on the NRIC.
		$gwByCode = array();
		foreach ($gwData as $g) { $gwByCode[$g['code']] = $g['nric']; }
		$leaveByCode = attGwLeaveDaysBatch($gwByCode, $range);
		foreach ($gwData as $i => $g)
		{
			$gwData[$i]['leave'] = isset($leaveByCode[$g['code']]) ? $leaveByCode[$g['code']] : array();
		}

		$days         = attRangeDays($range);
		$holidayByDay = attHolidaysByDay($db, $days);

		if ($range['multi'])
		{
			echo "<div style='overflow-x:auto;'>";
			echo "<table class='table table-bordered' border='1' style='margin-bottom:0;'>";
			attRenderCalendarHeader(array('#', 'GW Name', 'District'), $days);
			foreach ($gwData as $g)
			{
				$leading = array(
					$g['no'],
					"<strong>" . htmlspecialchars($g['name']) . "</strong>",
					htmlspecialchars($g['district']),
				);
				attRenderPersonCalendarRows($leading, $g['grid'], $days, $holidayByDay, $g['leave']);
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
			echo "<th style='width:16%; text-align:center;'>GW Name</th>";
			echo "<th style='width:12%; text-align:center;'>District</th>";
			foreach ($periods as $pCode => $pLabel) { echo "<th style='text-align:center; min-width:130px;'>" . htmlspecialchars($pLabel) . "</th>"; }
			echo "</tr></thead><tbody>";
			foreach ($gwData as $g)
			{
				echo "<tr>";
				echo "<th scope='row' style='text-align:center;'>" . $g['no'] . "</th>";
				echo "<td style='word-wrap:break-word;'>" . htmlspecialchars($g['name']) . "</td>";
				echo "<td style='word-wrap:break-word;'>" . htmlspecialchars($g['district']) . "</td>";
				foreach ($periods as $pCode => $pLabel)
				{
					$entries = isset($g['grid'][$pCode][$range['from']]) ? $g['grid'][$pCode][$range['from']] : array();
					$isHol   = !empty($holidayByDay[$range['from']]);
					$onLeave = isset($g['leave'][$range['from']]) ? $g['leave'][$range['from']] : '';
					renderSingleDayTd($pCode, $entries, $range['from'], $isHol, $onLeave);
				}
				echo "</tr>";
			}
			echo "</tbody></table></div>";
		}
	}

	// ── Export (Excel / PDF) — reuses renderGwResult, then exits ──
	if ($runScript == "Show Report" && ($export == "excel" || $export == "pdf"))
	{
		attSetMode($export);
		while (ob_get_level() > 0) { ob_end_clean(); }   // drop buffered page chrome

		$otLbl    = attOtFilterLabel();
		$otSuffix = ($otLbl !== "") ? " - " . $otLbl : "";
		$otFname  = ($otLbl !== "") ? "_OT" . strtoupper(str_replace(".", "", attOtFilter())) : "";
		$title = "GW Attendance Report" . $otSuffix . " - " . html_entity_decode(strip_tags(attRangeLabel($range)));
		if ($export == "excel")
		{
			$fname = "GwAttendance" . $otFname . "_" . $range['from_ymd'] . ($range['multi'] ? "_to_" . $range['to_ymd'] : "");
			attExcelDownloadHeaders($fname);
		}
		attExportDocStart($title, $export);
		renderGwResult($db_globportal, $range, $report_gw_district, $comp_id);
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

    <title>ATTENDANCE APP - GW Attendance Report</title>

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
                    <h2>Attendance App Report <small>GENERAL WORKERS</small></h2>
                    <div class="clearfix"></div>
                  </div>
                  <div class="x_content">
                    <br />
                    <form id="form_submit" name="form_submit" class="form-horizontal form-label-left" action="" method="post">

                      <?php attRenderRangeFilter($range); ?>

                      <div class="form-group">
                        <label class="control-label col-md-3 col-sm-3 col-xs-12">District</label>
                        <div class="col-md-6 col-sm-6 col-xs-12">
						  <select id="report_gw_district" name="report_gw_district" class="form-control">
							<option value="all">All Districts</option>
							<?php
								$queryDistricts  = "SELECT monthly_assign_gw_district FROM evaluation.monthly_assign_gw";
								$queryDistricts .= " WHERE comp_id = '$comp_id'";
								$queryDistricts .= attGwActiveSql();
								$queryDistricts .= " AND monthly_assign_gw_district IS NOT NULL AND monthly_assign_gw_district != ''";
								$queryDistricts .= " GROUP BY monthly_assign_gw_district ORDER BY monthly_assign_gw_district";
								$resultDistricts = mysql_query($queryDistricts, $db_globportal) or die(mysql_error($db_globportal));
								while ($rowDistricts = mysql_fetch_array($resultDistricts))
								{
									$selected = ($report_gw_district == $rowDistricts['monthly_assign_gw_district']) ? "selected" : "";
									echo "<option value='" . htmlspecialchars($rowDistricts['monthly_assign_gw_district']) . "' $selected>" . htmlspecialchars($rowDistricts['monthly_assign_gw_district']) . "</option>";
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
								<h2>GW Attendance <small><?php echo attRangeLabel($range); $otLbl = attOtFilterLabel(); if ($otLbl !== "") { echo " &middot; OT " . htmlspecialchars($otLbl); } ?></small></h2>
								<div class="clearfix"></div>
							  </div>
							  <div class="x_content">

								<?php renderGwResult($db_globportal, $range, $report_gw_district, $comp_id); ?>

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