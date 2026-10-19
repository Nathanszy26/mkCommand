<?php
	
	/*
	ini_set('display_errors', 1);
	ini_set('display_startup_errors', 1);
	error_reporting(E_ALL);
	*/

	include_once("includes/db.php");
	include_once("includes/access.php");
	include_once("includes/function.php");
	
	//$submission_type_id = "1";
	
	$no_of_cc = 5;
	$no_of_attachments = 5;
	
	$errorMessage = "";
	
	$staff_performance_ref = "";
	$staff_performance_date = "";
	$staff_performance_staff_id = "";
	$staff_performance_type_title = "";
	$staff_performance_type_merit_demerit = "";
	$staff_performance_type_id = "";
	$staff_performance_type_points = "";
	
	// Active performance types, loaded once: drives both server-side validation and the client-side filter
	$performance_types = array();
	
	$queryTypes = "SELECT staff_performance_type_id, staff_performance_type_title, staff_performance_type_merit_demerit, staff_performance_type_points FROM staff_performance_types WHERE staff_performance_type_deleted = '0' ORDER BY staff_performance_type_title";
	$resultTypes = mysql_query($queryTypes, $db_globportal) or die(mysql_error($db_globportal));
	while ($rowTypes = mysql_fetch_assoc($resultTypes))
	{
		$performance_types[] = array(
			'id' => $rowTypes['staff_performance_type_id'],
			'title' => $rowTypes['staff_performance_type_title'],
			'merit_demerit' => strtolower($rowTypes['staff_performance_type_merit_demerit']),
			'points' => $rowTypes['staff_performance_type_points']
		);
	}
	
	function PerformanceTypesFor($performance_types, $merit_demerit)
	{
		$matched = array();
		
		foreach ($performance_types as $performance_type)
		{
			if ($performance_type['merit_demerit'] == strtolower(trim($merit_demerit))) { $matched[] = $performance_type; }
		}
		
		return $matched;
	}
	
	if (isset($_POST['runScript'])) { $runScript = $_POST['runScript']; } else { $runScript = ""; }
	
	if ($runScript == "Submit Staff Performance")
	{
		$staff_performance_ref = $_POST['staff_performance_ref'];
		$staff_performance_date = $_POST['staff_performance_date'];
		$staff_performance_staff_id = $_POST['staff_performance_staff_id'];
		$staff_performance_type_title = $_POST['staff_performance_type_title'];
		$staff_performance_type_merit_demerit = $_POST['staff_performance_type_merit_demerit'];
		$staff_performance_type_id = $_POST['staff_performance_type_id'];
		$staff_performance_type_points = $_POST['staff_performance_type_points'];
		
		$staff_performance_uploaded_by = $rowUsers['id'];
		$staff_performance_uploaded_date = date("Y/m/d");
		$staff_performance_uploaded_time = date("H:i:s");
		
		if (($staff_performance_date == "") || ($staff_performance_staff_id == "") || ($staff_performance_type_title == "") || ($staff_performance_type_merit_demerit == "") || ($staff_performance_type_id == ""))
		{
			$errorMessage = "Ref., Date, To, Title and Merit/Demerit are all required.";
		}
		
		// Type is optional, but when supplied it must be active and belong to the chosen Merit/Demerit
		if ($staff_performance_type_id != "")
		{
			$valid_type = false;
			
			foreach (PerformanceTypesFor($performance_types, $staff_performance_type_merit_demerit) as $available_type)
			{
				if ($available_type['id'] == $staff_performance_type_id) { $valid_type = true; }
			}
			
			if ($valid_type == false) { $errorMessage = "The selected Type does not match the selected Merit/Demerit."; }
		}
		
		if ($errorMessage == "")
		{
			mysql_query("INSERT INTO staff_performance (staff_performance_id, staff_performance_ref, staff_performance_date, staff_performance_staff_id, staff_performance_type_id, staff_performance_type_title, staff_performance_type_merit_demerit, staff_performance_type_points, staff_performance_uploaded_by, staff_performance_uploaded_date, staff_performance_uploaded_time)
			VALUES ('', '" . addslashes($staff_performance_ref) . "', '" . addslashes($staff_performance_date) . "', '" . addslashes($staff_performance_staff_id) . "', '" . addslashes($staff_performance_type_id) . "', '" . addslashes($staff_performance_type_title) . "', '" . addslashes($staff_performance_type_merit_demerit) . "', '" . addslashes($staff_performance_type_points) . "', '$staff_performance_uploaded_by', '$staff_performance_uploaded_date', '$staff_performance_uploaded_time')", $db_globportal) or die(mysql_error($db_globportal));
			
			$new_staff_performance_id = mysql_insert_id($db_globportal);
			
			//TransactionLog("staff_da", $new_staff_da_id, "new", $rowUsers['id'], $_SERVER['REMOTE_ADDR']);
			
			$total_attachments = $_POST['total_attachments'];
			
			for ($i = 1; $i <= $total_attachments; $i ++)
			{
				$attachment = addslashes($_FILES['attachment_' . $i]["name"]);
				
				if ($attachment != "")
				{
					StaffPerformance_AttachmentAdd($db_globportal, "attachment_" . $i, $new_staff_performance_id);
				}
			}
			
			echo "<script type='text/javascript'>";
			echo "alert('Staff Performance Uploaded!');";
			echo "window.location = 'staff_performance_listing.php';";
			echo "</script>";
		}
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
    <!-- iCheck -->
    <link href="../vendors/iCheck/skins/flat/green.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../vendors/font-awesome/css/font-awesome.min.css" rel="stylesheet">
    <!-- Dropzone.js -->
    <link href="../vendors/dropzone/dist/min/dropzone.min.css" rel="stylesheet">

    <!-- Custom Theme Style -->
    <link href="../build/css/custom.min.css" rel="stylesheet">
    <!-- Select2 -->
    <link href="../vendors/select2/dist/css/select2.min.css" rel="stylesheet">
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
          <div class="">
            <div class="page-title">
              <div class="title_left">
                <h3>Upload Staff Merit/Demerit</h3>
              </div>

              <!--div class="title_right">
                <div class="col-md-5 col-sm-5 col-xs-12 form-group pull-right top_search">
                  <div class="input-group">
                    <input type="text" class="form-control" placeholder="Search for...">
                    <span class="input-group-btn">
                      <button class="btn btn-default" type="button">Go!</button>
                    </span>
                  </div>
                </div>
              </div-->
            </div>

            <div class="clearfix"></div>
			
            <div class="row">
              <div class="col-md-12 col-sm-12 col-xs-12">
                <div class="x_panel">
                  <div class="x_title">
                    <h2>Upload Staff Merit/Demerit <small>Merit/Demerit</small></h2>
                    <!--ul class="nav navbar-right panel_toolbox">
                      <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a>
                      </li>
                      <li class="dropdown">
                        <a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-expanded="false"><i class="fa fa-wrench"></i></a>
                        <ul class="dropdown-menu" role="menu">
                          <li><a href="#">Settings 1</a>
                          </li>
                          <li><a href="#">Settings 2</a>
                          </li>
                        </ul>
                      </li>
                      <li><a class="close-link"><i class="fa fa-close"></i></a>
                      </li>
                    </ul-->
                    <div class="clearfix"></div>
                  </div>
                  <div class="x_content">
                    <br />
					<?php if ($errorMessage != "") { ?>
					<div class="alert alert-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
					<?php } ?>
                    <form action="staff_performance_upload.php" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left">
						
                      <div class="form-group">
                        <label class="control-label col-md-3 col-sm-3 col-xs-12" for="staff_performance_ref">Ref. (Optional)
                        </label>
                        <div class="col-md-6 col-sm-6 col-xs-12">
                          <input id="staff_performance_ref" name="staff_performance_ref" type="text" required="required" class="form-control col-md-7 col-xs-12" value="<?php echo htmlspecialchars($staff_performance_ref); ?>">
                        </div>
                      </div>
					  
                      <div class="form-group">
                        <label class="control-label col-md-3 col-sm-3 col-xs-12" for="staff_performance_date">Date <span class="required">*</span>
                        </label>
                        <div class="col-md-6 col-sm-6 col-xs-12">
                          <input id="staff_performance_date" name="staff_performance_date" type="text" required="required" class="form-control col-md-7 col-xs-12" value="<?php echo htmlspecialchars($staff_performance_date); ?>" readonly>
                        </div>
                      </div>
					  
                      <div class="form-group">
                        <label class="control-label col-md-3 col-sm-3 col-xs-12" for="staff_performance_staff_id">To <span class="required">*</span>
                        </label>
                        <div class="col-md-6 col-sm-6 col-xs-12">
						  <select id="staff_performance_staff_id" name="staff_performance_staff_id" class="select2_group form-control">
							<option value="">- Select Staff -</option>
							<?php
								
								$queryStaffList = "SELECT * FROM staff_portal2.users WHERE comp_id = '" . $rowUsers['comp_id'] . "' AND status = '1' ORDER BY users.fullname";
								$resultStaffList = mysql_query($queryStaffList, $db_globportal) or die(mysql_error());
								while ($rowStaffList = mysql_fetch_array($resultStaffList))
								{
									$selected = ($rowStaffList['id'] == $staff_performance_staff_id) ? " selected" : "";
									echo "<option value='" . $rowStaffList['id'] . "'" . $selected . ">" . ucwords($rowStaffList['fullname']) . " - " . $rowStaffList['position'] . " - " . $rowStaffList['email'] . " - " . $rowStaffList['mobile_no'] . "</option>";
								}
								
							?>
						  </select>
                        </div>
                      </div>
					  
                      <div class="form-group">
                        <label class="control-label col-md-3 col-sm-3 col-xs-12" for="staff_performance_type_title">Title <span class="required">*</span>
                        </label>
                        <div class="col-md-6 col-sm-6 col-xs-12">
                          <input id="staff_performance_type_title" name="staff_performance_type_title" type="text" required="required" class="form-control col-md-7 col-xs-12" value="<?php echo htmlspecialchars($staff_performance_type_title); ?>">
                        </div>
                      </div>
					  
                      <div class="form-group">
                        <label class="control-label col-md-3 col-sm-3 col-xs-12" for="staff_performance_type_merit_demerit">Merit/Demerit <span class="required">*</span>
                        </label>
                        <div class="col-md-6 col-sm-6 col-xs-12">
						  <select id="staff_performance_type_merit_demerit" name="staff_performance_type_merit_demerit" class="select2_group form-control">
							<option value="">- Select Merit/Demerit -</option>
							<option value="Merit"<?php if ($staff_performance_type_merit_demerit == "Merit") { echo " selected"; } ?>>Merit</option>
							<option value="Demerit"<?php if ($staff_performance_type_merit_demerit == "Demerit") { echo " selected"; } ?>>Demerit</option>
						  </select>
                        </div>
                      </div>
					  
                      <div class="form-group">
                        <label class="control-label col-md-3 col-sm-3 col-xs-12" for="staff_performance_type_id">Type <span class="required">*</span>
                        </label>
                        <div class="col-md-6 col-sm-6 col-xs-12">
						  <select id="staff_performance_type_id" name="staff_performance_type_id" class="select2_group form-control">
							<option value="">- Select Merit/Demerit First -</option>
						  </select>
                        </div>
                      </div>
					  
                      <div class="form-group">
                        <label class="control-label col-md-3 col-sm-3 col-xs-12" for="staff_performance_type_points">Points (Optional)
                        </label>
                        <div class="col-md-6 col-sm-6 col-xs-12">
                          <input id="staff_performance_type_points" name="staff_performance_type_points" type="text" class="form-control col-md-7 col-xs-12" value="<?php echo htmlspecialchars($staff_performance_type_points); ?>">
                        </div>
                      </div>
					  
                      <div class="form-group">
                        <label class="control-label col-md-3 col-sm-3 col-xs-12" for="attachments">Attachments (Optional)
                        </label>
                        <div class="col-md-6 col-sm-6 col-xs-12">
						  <input id="total_attachments" name="total_attachments" type="hidden" value="<?php echo $no_of_attachments; ?>">
                          <?php for ($i = 1; $i <= $no_of_attachments; $i ++) { ?>
						  <input id="attachment_<?php echo $i; ?>" name="attachment_<?php echo $i; ?>" type="file" accept="image/*" capture="camera"><br>
						  <?php } ?>
                        </div>
                      </div>
					  
                      <div class="ln_solid"></div>

                      <div class="form-group">
                        <div class="col-md-6 col-sm-6 col-xs-12 col-md-offset-3">
                          <button type="reset" class="btn btn-primary">Reset</button>
                          <button id="runScript" name="runScript" type="submit" class="btn btn-success" value="Submit Staff Performance">Submit</button>
                        </div>
                      </div>

                    </form>
                  </div>
                </div>
              </div>
            </div>

            <!--div class="row">
              <div class="col-md-12 col-sm-12 col-xs-12">
                <div class="x_panel">
                  <div class="x_title">
                    <h2>Dropzone multiple file uploader</h2>
                    <ul class="nav navbar-right panel_toolbox">
                      <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a>
                      </li>
                      <li class="dropdown">
                        <a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-expanded="false"><i class="fa fa-wrench"></i></a>
                        <ul class="dropdown-menu" role="menu">
                          <li><a href="#">Settings 1</a>
                          </li>
                          <li><a href="#">Settings 2</a>
                          </li>
                        </ul>
                      </li>
                      <li><a class="close-link"><i class="fa fa-close"></i></a>
                      </li>
                    </ul>
                    <div class="clearfix"></div>
                  </div>
                  <div class="x_content">
                    <p>Drag multiple files to the box below for multi upload or click to select files. This is for demonstration purposes only, the files are not uploaded to any server.</p>
                    <form action="form_upload.html" class="dropzone">
						<div class="fallback">
							<input name="file" type="file" multiple />
						</div>
					</form>
                    <br />
                    <br />
                    <br />
                    <br />
                  </div>
                </div>
              </div>
            </div-->
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
    <!-- bootstrap-daterangepicker -->
    <script src="js/moment/moment.min.js"></script>
    <script src="js/datepicker/daterangepicker.js"></script>
    <!-- Dropzone.js -->
    <script src="../vendors/dropzone/dist/min/dropzone.min.js"></script>

    <!-- Custom Theme Scripts -->
    <script src="../build/js/custom.min.js"></script>
	
    <!-- Select2 -->
    <script src="../vendors/select2/dist/js/select2.full.min.js"></script>
	
    <!-- Select2 -->
    <script>
      $(document).ready(function() {
        $(".select2_single").select2({
          placeholder: "Select a state",
          allowClear: true
        });
        $(".select2_group").select2({});
        $(".select2_multiple").select2({
          maximumSelectionLength: 4,
          placeholder: "With Max Selection limit 4",
          allowClear: true
        });
      });
    </script>
    <!-- /Select2 -->
	
    <!-- Merit/Demerit -> Type dependency -->
    <script>
      $(document).ready(function() {
        var performanceTypes = <?php echo json_encode($performance_types); ?>;
        var selectedTypeId = <?php echo json_encode($staff_performance_type_id); ?>;
        var $meritDemerit = $('#staff_performance_type_merit_demerit');
        var $type = $('#staff_performance_type_id');

        function renderTypes(meritDemerit, selectedId) {
          meritDemerit = $.trim(meritDemerit).toLowerCase();

          var matched = $.grep(performanceTypes, function(type) {
            return type.merit_demerit === meritDemerit;
          });

          var placeholder = '- Select Type -';
          if (meritDemerit === '') {
            placeholder = '- Select Merit/Demerit First -';
          } else if (matched.length === 0) {
            placeholder = '- No Type Available -';
          }

          $type.empty();
          $type.append($('<option>', { value: '', text: placeholder }));

          $.each(matched, function(i, type) {
            $type.append($('<option>', { value: type.id, text: type.title }));
          });

          $type.val(selectedId || '').trigger('change.select2');
        }

        $meritDemerit.on('change', function() {
          renderTypes($(this).val(), '');
        });

        renderTypes($meritDemerit.val(), selectedTypeId);
      });
    </script>
    <!-- /Merit/Demerit -> Type dependency -->
	
    <!-- bootstrap-daterangepicker -->
    <script>
      $(document).ready(function() {
        var cb = function(start, end, label) {
          console.log(start.toISOString(), end.toISOString(), label);
          $('#reportrange_right span').html(start.format('MMMM D, YYYY') + ' - ' + end.format('MMMM D, YYYY'));
        };

        var optionSet1 = {
          startDate: moment().subtract(29, 'days'),
          endDate: moment(),
          minDate: '01/01/2012',
          maxDate: '12/31/2015',
          dateLimit: {
            days: 60
          },
          showDropdowns: true,
          showWeekNumbers: true,
          timePicker: false,
          timePickerIncrement: 1,
          timePicker12Hour: true,
          ranges: {
            'Today': [moment(), moment()],
            'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
            'Last 7 Days': [moment().subtract(6, 'days'), moment()],
            'Last 30 Days': [moment().subtract(29, 'days'), moment()],
            'This Month': [moment().startOf('month'), moment().endOf('month')],
            'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
          },
          opens: 'right',
          buttonClasses: ['btn btn-default'],
          applyClass: 'btn-small btn-primary',
          cancelClass: 'btn-small',
          format: 'MM/DD/YYYY',
          separator: ' to ',
          locale: {
            applyLabel: 'Submit',
            cancelLabel: 'Clear',
            fromLabel: 'From',
            toLabel: 'To',
            customRangeLabel: 'Custom',
            daysOfWeek: ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'],
            monthNames: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
            firstDay: 1
          }
        };

        $('#reportrange_right span').html(moment().subtract(29, 'days').format('MMMM D, YYYY') + ' - ' + moment().format('MMMM D, YYYY'));

        $('#reportrange_right').daterangepicker(optionSet1, cb);

        $('#reportrange_right').on('show.daterangepicker', function() {
          console.log("show event fired");
        });
        $('#reportrange_right').on('hide.daterangepicker', function() {
          console.log("hide event fired");
        });
        $('#reportrange_right').on('apply.daterangepicker', function(ev, picker) {
          console.log("apply event fired, start/end dates are " + picker.startDate.format('MMMM D, YYYY') + " to " + picker.endDate.format('MMMM D, YYYY'));
        });
        $('#reportrange_right').on('cancel.daterangepicker', function(ev, picker) {
          console.log("cancel event fired");
        });

        $('#options1').click(function() {
          $('#reportrange_right').data('daterangepicker').setOptions(optionSet1, cb);
        });

        $('#options2').click(function() {
          $('#reportrange_right').data('daterangepicker').setOptions(optionSet2, cb);
        });

        $('#destroy').click(function() {
          $('#reportrange_right').data('daterangepicker').remove();
        });

      });
    </script>

    <script>
      $(document).ready(function() {
        var cb = function(start, end, label) {
          console.log(start.toISOString(), end.toISOString(), label);
          $('#reportrange span').html(start.format('MMMM D, YYYY') + ' - ' + end.format('MMMM D, YYYY'));
        };

        var optionSet1 = {
          startDate: moment().subtract(29, 'days'),
          endDate: moment(),
          minDate: '01/01/2012',
          maxDate: '12/31/2015',
          dateLimit: {
            days: 60
          },
          showDropdowns: true,
          showWeekNumbers: true,
          timePicker: false,
          timePickerIncrement: 1,
          timePicker12Hour: true,
          ranges: {
            'Today': [moment(), moment()],
            'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
            'Last 7 Days': [moment().subtract(6, 'days'), moment()],
            'Last 30 Days': [moment().subtract(29, 'days'), moment()],
            'This Month': [moment().startOf('month'), moment().endOf('month')],
            'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
          },
          opens: 'left',
          buttonClasses: ['btn btn-default'],
          applyClass: 'btn-small btn-primary',
          cancelClass: 'btn-small',
          format: 'MM/DD/YYYY',
          separator: ' to ',
          locale: {
            applyLabel: 'Submit',
            cancelLabel: 'Clear',
            fromLabel: 'From',
            toLabel: 'To',
            customRangeLabel: 'Custom',
            daysOfWeek: ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'],
            monthNames: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
            firstDay: 1
          }
        };
        $('#reportrange span').html(moment().subtract(29, 'days').format('MMMM D, YYYY') + ' - ' + moment().format('MMMM D, YYYY'));
        $('#reportrange').daterangepicker(optionSet1, cb);
        $('#reportrange').on('show.daterangepicker', function() {
          console.log("show event fired");
        });
        $('#reportrange').on('hide.daterangepicker', function() {
          console.log("hide event fired");
        });
        $('#reportrange').on('apply.daterangepicker', function(ev, picker) {
          console.log("apply event fired, start/end dates are " + picker.startDate.format('MMMM D, YYYY') + " to " + picker.endDate.format('MMMM D, YYYY'));
        });
        $('#reportrange').on('cancel.daterangepicker', function(ev, picker) {
          console.log("cancel event fired");
        });
        $('#options1').click(function() {
          $('#reportrange').data('daterangepicker').setOptions(optionSet1, cb);
        });
        $('#options2').click(function() {
          $('#reportrange').data('daterangepicker').setOptions(optionSet2, cb);
        });
        $('#destroy').click(function() {
          $('#reportrange').data('daterangepicker').remove();
        });
      });
    </script>

    <script>
      $(document).ready(function() {
        $('#single_cal1').daterangepicker({
          singleDatePicker: true,
          calender_style: "picker_1"
        }, function(start, end, label) {
          console.log(start.toISOString(), end.toISOString(), label);
        });
        $('#single_cal2').daterangepicker({
          singleDatePicker: true,
          calender_style: "picker_2"
        }, function(start, end, label) {
          console.log(start.toISOString(), end.toISOString(), label);
        });
        $('#single_cal3').daterangepicker({
          singleDatePicker: true,
          calender_style: "picker_3"
        }, function(start, end, label) {
          console.log(start.toISOString(), end.toISOString(), label);
        });
        $('#single_cal4').daterangepicker({
          singleDatePicker: true,
          calender_style: "picker_4"
        }, function(start, end, label) {
          console.log(start.toISOString(), end.toISOString(), label);
        });
        $('#staff_performance_date').daterangepicker({
          singleDatePicker: true,
          calender_style: "picker_4"
        }, function(start, end, label) {
          console.log(start.toISOString(), end.toISOString(), label);
        });
      });
    </script>

    <script>
      $(document).ready(function() {
        $('#reservation').daterangepicker(null, function(start, end, label) {
          console.log(start.toISOString(), end.toISOString(), label);
        });
      });
    </script>
    <!-- /bootstrap-daterangepicker -->  </body>
</html>