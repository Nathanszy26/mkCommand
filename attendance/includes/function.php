<?php

function CheckTable($db, $select, $from, $where, $return)
{
	$query = "SELECT $select FROM $from WHERE $where";
	$result = mysql_query($query, $db) or die(mysql_error());
	$row = mysql_fetch_array($result);

	return $row[$return];
}

function Scoreboard_TotalTravelledDistance($db, $period, $car_no)
{
	$query = "SELECT SUM(travel_distance) FROM gas_usage.travels WHERE car_no = '$car_no' AND travel_date LIKE '$period%' AND travel_distance > 0 AND travel_deleted = '0'";
	$result = mysql_query($query, $db) or die(mysql_error());
	$row = mysql_fetch_array($result);

	return $row['SUM(travel_distance)'];
}

function Scoreboard_TotalFuelAmount($db, $period, $car_no)
{
	$query = "SELECT *, SUM(amount), SUM(total_litre) FROM gas_usage.gasoline WHERE car_no = '$car_no' AND status = '1'";
	$query .= " AND date LIKE '$period%'";
	$result = mysql_query($query, $db) or die(mysql_error());
	$row = mysql_fetch_array($result);

	return number_format($row['SUM(amount)'], 2);
}

function Scoreboard_TotalFuelLitre($db, $period, $car_no)
{
	$query = "SELECT *, SUM(amount), SUM(total_litre) FROM gas_usage.gasoline WHERE car_no = '$car_no' AND status = '1'";
	$query .= " AND date LIKE '$period%'";
	$result = mysql_query($query, $db) or die(mysql_error());
	$row = mysql_fetch_array($result);

	return number_format($row['SUM(total_litre)'], 2);
}

function Scoreboard_TotalSpeedingTicket($db, $period, $car_no)
{
	$from = strtotime($period . "-01");
	$to = strtotime($period . "-" . date("t", $from));

	$query = "SELECT COUNT(speed_ticket_id) FROM staff_portal2.speed_ticket WHERE speed_ticket_VRN = '$car_no' AND speed_ticket_time_from >= '$from' AND speed_ticket_time_to <= '$to' AND speed_ticket_status = '2'";
	$result = mysql_query($query, $db) or die(mysql_error());
	$row = mysql_fetch_array($result);

	if ($row['COUNT(speed_ticket_id)'] > 0) {
		return $row['COUNT(speed_ticket_id)'];
	} else {
		return "-";
	}
}

/*
	function Scoreboard_TotalDailyTravelFormSubmission($db, $year, $month, $car_no, $staff_id)
	{
		$total_days = date("t", $year . "-" . $month . "-01");
		$submitted_days = 0;
		
		for ($i = 1; $i <= $total_days; $i ++)
		{
			if ($i < 10) { $j = "0" . $i; } else { $j = $i; }
			
			$check_submitted_day = true;
			
			$query = "SELECT * FROM gas_usage.travels WHERE driver_id = '$staff_id' AND car_no = '$car_no' AND travel_date = '" . $year . "-" . $month . "-" . $j . "' AND travel_deleted = '0'";
			$result = mysql_query($query, $db) or die(mysql_error());
			$num = mysql_num_rows($result);
			
			if ($num == 0) { $check_submitted_day = false; }
			
			if ($check_submitted_day == true) { $submitted_days ++; }
		}
		
		return $submitted_days;
	}
	*/

function Scoreboard_TotalDailyTravelFormSubmission($db, $year, $month, $car_no, $staff_id)
{
	$total_days = date("t", $year . "-" . $month . "-01");
	$working_days = 0;
	$off_days = 0;
	$working_submitted_days = 0;
	$off_submitted_days = 0;

	for ($i = 1; $i <= $total_days; $i++) {
		if ($i < 10) {
			$j = "0" . $i;
		} else {
			$j = $i;
		}

		$check_working_day = true;
		$check_submitted_day = true;

		// Check Sunday
		if (date("w", strtotime($year . "-" . $month . "-" . $j)) == 0) {
			$check_working_day = false;
		}

		// Check Public Holiday
		if (CheckPublicHoliday($db, $year, $month, $j) == 1) {
			$check_working_day = false;
		}

		// Check Annual Leave
		$queryLeaves = "SELECT * FROM gas_usage.driver_leaves WHERE driver_leave_person = '$staff_id' AND driver_leave_status = '4' AND driver_leave_from <= '" . $year . "-" . $month . "-" . $j . "' AND driver_leave_to >= '" . $year . "-" . $month . "-" . $j . "'";
		$resultLeaves = mysql_query($queryLeaves, $db) or die(mysql_error());
		$numLeaves = mysql_num_rows($resultLeaves);
		if ($numLeaves == 1) {
			$check_working_day = false;
		}

		if ($check_working_day == true) {
			$working_days++;
		} else {
			$off_days++;
		}

		// DB: gas_usage.travels
		//$query = "SELECT driver_id, car_no, travel_date, travel_deleted FROM gas_usage.travels WHERE driver_id = '$staff_id' AND car_no = '$car_no' AND travel_date = '" . $year . "-" . $month . "-" . $j . "' AND travel_deleted = '0'";
		// DB: gas_usage.daily_vtf
		$query = "SELECT * FROM gas_usage.daily_vtf WHERE car_no = '$car_no' AND travel_date = '" . $year . "-" . $month . "-" . $j . "' AND daily_vtf_deleted = '0'";
		$result = mysql_query($query, $db) or die(mysql_error());
		$num = mysql_num_rows($result);

		if ($num == 0) {
			$check_submitted_day = false;
		}

		if ($check_submitted_day == true) {
			if ($check_working_day == true) {
				$working_submitted_days++;
			} else {
				$off_submitted_days++;
			}
		}
	}

	return $working_submitted_days . " / " . $working_days . " ( " . $off_submitted_days . " / " . $off_days . " )";
}

function CheckPublicHoliday($db, $year, $month, $day)
{
	$query = "SELECT * FROM gas_usage.public_holiday WHERE year = '$year' AND month = '$month' AND day = '$day'";
	$result = mysql_query($query, $db);
	$num = mysql_num_rows($result);

	return $num;
}

function CheckShellPayment($db, $period, $payer)
{
	$query = "SELECT payer_name, transaction_date, SUM(net_amount) FROM gas_usage.shell_upload_items WHERE deleted = '0'";
	if ($payer != "all") {
		$query .= " AND payer_name = '$payer'";
	}
	$query .= " AND transaction_date LIKE '$period%'";
	if ($payer != "all") {
		$query .= " GROUP BY payer_name";
	}
	$result = mysql_query($query, $db) or die(mysql_error());
	$row = mysql_fetch_array($result);

	return $row['SUM(net_amount)'];
}

// function LoginLog($db, $user_id, $user_ip, $user_action)
// {
// 	$date = date("Y/m/d");
// 	$time = date("H:i:s");

// 	mysql_query("INSERT INTO gas_usage.user_login_log (user_login_log_id, user_id, user_login_log_action, user_login_log_date, user_login_log_time, user_login_log_ip) VALUES ('', '$user_id', '$user_action', '$date', '$time', '$user_ip')", $db) or die(mysql_error());
// }

// function LoginLog($db, $user_id, $user_ip, $user_action)
// {
// 	$date = date("Y/m/d");
// 	$time = date("H:i:s");

// 	mysql_query("INSERT INTO gas_usage.user_login_log (user_id, user_login_log_action, user_login_log_date, user_login_log_time, user_login_log_ip) VALUES ('$user_id', '$user_action', '$date', '$time', '$user_ip')", $db) or die("LoginLog Error: " . mysql_error());
// }

function LoginLog($db, $user_id, $user_ip, $user_action)
{
	$date = date("Y/m/d");
	$time = date("H:i:s");

	$query = "INSERT INTO gas_usage.user_login_log (user_id, user_login_log_action, user_login_log_date, user_login_log_time, user_login_log_ip) VALUES ('$user_id', '$user_action', '$date', '$time', '$user_ip')";
	if (!mysql_query($query, $db)) {
		echo "LoginLog Error: " . mysql_error();
	}
}
function TransactionLog($table, $id, $type, $by, $by_ip)
{
	$date = date("Y/m/d");
	$time = date("H:i:s");

	if (($table != "") && ($id != "") && ($type != "") && ($by != "") && ($by_ip != "") && ($date != "") && ($time != "")) {
		mysql_query("INSERT INTO transaction_log (transaction_log_id, transaction_log_table, transaction_log_data_id, transaction_log_type, transaction_log_by, transaction_log_by_ip, transaction_log_date, transaction_log_time)
			VALUES ('', '$table', '$id', '$type', '$by', '$by_ip', '$date', '$time')") or die(mysql_error());
	}
}

function UpdatePOStatus($po_listing_id, $status_id)
{
	mysql_query("UPDATE po_listing SET po_listing_status = '$status_id' WHERE po_listing_id = '$po_listing_id'");
}

/*
	function ReviewsCategories($year, $month, $review_category_id, $submission_type_id, $account_review_status)
	{
		$return = 0;
		$returnID = "";
		
		$queryAccounts = "SELECT * FROM accounts WHERE accounts_year = '$year' AND accounts_month = '$month'";
		$queryAccounts .= " AND submission_type_id = '$submission_type_id'";
		$resultAccounts = mysql_query($queryAccounts) or die(mysql_error());
		while ($rowAccounts = mysql_fetch_array($resultAccounts))
		{
			$queryReviews = "SELECT * FROM accounts_reviews WHERE accounts_id = '" . $rowAccounts['accounts_id'] . "' AND parent_id = ''";
			if ($review_category_id != "") { $queryReviews .= " AND review_category_id = '$review_category_id'"; }
			if ($account_review_status != "") { $queryReviews .= " AND account_review_status = '$account_review_status'"; }
			$resultReviews = mysql_query($queryReviews) or die(mysql_error());
			$numReviews = mysql_num_rows($resultReviews);
			
			$return += $numReviews;
			
			if (($numReviews > 0) && ($account_review_status != ""))
			{
				if ($returnID != "") { $returnID .= "<br>"; }
				$returnID .= "<a href='http://globportal.com/accounts/vehicleDetails/accountsDetails" . $submission_type_id . ".php?id=" . $rowAccounts['accounts_id'] . "' target='_blank'>Link</a>";
			}
		}
		
		$return .= "<br>" . $returnID;
		
		return $return;
	}
	*/

function getFileTypes()
{
	return array(
		'application/pdf'
	);
}

function AttachmentAdd($db, $attachment, $travel_id)
{
	$now_date = date("Y/m/d");
	$now_time = date("H:i:s");

	$filename_old = basename($_FILES[$attachment]["name"]);

	$dir = $_SERVER['DOCUMENT_ROOT'] . "/accounts/vehicleDetails/attachments/";

	//if (!in_array($_FILES[$attachment]["type"], getFileTypes()))
	//{
	//$message .= "<strong>" . $filename_old . "</strong>" . " invalid file type<br />";
	//} else {
	if ($_FILES[$attachment]["error"] > 0) {
		$message .= "<strong>" . $filename_old . "</strong>" . " " . $_FILES[$attachment]["error"] . "<br />";
	} else {
		if (file_exists($target_path)) {
			$message .= "<strong>" . $filename_old . "</strong>" . " already exists<br />";
		} else {
			$filesize = $_FILES[$attachment]["size"] / 1024;

			$extension = explode(".", $filename_old);
			$filename = $travel_id . "." . $extension[1];

			mysql_query("UPDATE gas_usage.travels SET travel_attachment_filename_original = '$filename_old', travel_attachment_filename = '$filename' WHERE travel_id = '$travel_id'", $db) or die(mysql_error($db));

			$target_path = $dir . "/" . $filename;

			move_uploaded_file($_FILES[$attachment]["tmp_name"], $target_path);

			$message .= "<strong>" . $filename_old . "</strong>" . " added<br />";
		}
	}
	//}

	return $message;
}

function AttachmentDelete($db, $travel_id)
{
	$queryTravels = "SELECT * FROM gas_usage.travels WHERE travel_id = '$travel_id'";
	$resultTravels = mysql_query($queryTravels, $db) or die(mysql_error($db));
	$rowTravels = mysql_fetch_array($resultTravels);

	$path = $_SERVER['DOCUMENT_ROOT'] . "/accounts/vehicleDetails/attachments/" . $rowTravels['travel_attachment_filename'];

	$new_dir = $_SERVER['DOCUMENT_ROOT'] . "/accounts/vehicleDetails/attachments/deleted";
	if (is_dir($new_dir) == false) {
		mkdir($new_dir, 0777);
	}

	$new_path = $new_dir . "/" . date("YmdHis") . "_" . $rowTravels['travel_attachment_filename'];

	copy($path, $new_path);
	unlink($path);

	mysql_query("UPDATE gas_usage.travels SET travel_attachment_filename_original = '', travel_attachment_filename = '' WHERE travel_id = '$travel_id'", $db) or die(mysql_error($db));

	//$message .= $row_photo['photo_taken_date'] . "/" . $row_photo['photo_filename'] . " deleted";

	//SystemLog("photo", "delete", $staff_id, $_SERVER['REMOTE_ADDR'], "Delete Photo: $photo_id");

	//return $message;
}

function HourlyJobPhotoAdd($db, $attachment, $staff_hourly_job_log_id)
{
	$now_date = date("Y/m/d");
	$now_time = date("H:i:s");

	$filename_old = basename($_FILES[$attachment]["name"]);

	$dir = $_SERVER['DOCUMENT_ROOT'] . "/accounts/vehicleDetails/attachmentsGlob/hourlyJobLogs/";

	//if (!in_array($_FILES[$attachment]["type"], getFileTypes()))
	//{
	//$message .= "<strong>" . $filename_old . "</strong>" . " invalid file type<br />";
	//} else {
	if ($_FILES[$attachment]["error"] > 0) {
		$message .= "<strong>" . $filename_old . "</strong>" . " " . $_FILES[$attachment]["error"] . "<br />";
	} else {
		if (file_exists($target_path)) {
			$message .= "<strong>" . $filename_old . "</strong>" . " already exists<br />";
		} else {
			$filesize = $_FILES[$attachment]["size"] / 1024;

			$extension = explode(".", $filename_old);
			$filename = $staff_hourly_job_log_id . "." . $extension[1];

			mysql_query("UPDATE gas_usage.staff_hourly_job_logs SET staff_hourly_job_log_photo_filename_original = '$filename_old', staff_hourly_job_log_photo_filename = '$filename' WHERE staff_hourly_job_log_id = '$staff_hourly_job_log_id'", $db) or die(mysql_error($db));

			$target_path = $dir . "/" . $filename;

			move_uploaded_file($_FILES[$attachment]["tmp_name"], $target_path);

			$message .= "<strong>" . $filename_old . "</strong>" . " added<br />";
		}
	}
	//}

	return $message;
}

function GWAttachmentAdd($db, $attachment, $staff_id, $period)
{
	$now_date = date("Y/m/d");
	$now_time = date("H:i:s");

	$filename_old = basename($_FILES[$attachment]["name"]);

	$dir = $_SERVER['DOCUMENT_ROOT'] . "/accounts/gwAttendance/attachments/";

	//if (!in_array($_FILES[$attachment]["type"], getFileTypes()))
	//{
	//$message .= "<strong>" . $filename_old . "</strong>" . " invalid file type<br />";
	//} else {
	if ($_FILES[$attachment]["error"] > 0) {
		$message .= "<strong>" . $filename_old . "</strong>" . " " . $_FILES[$attachment]["error"] . "<br />";
	} else {
		if (file_exists($target_path)) {
			$message .= "<strong>" . $filename_old . "</strong>" . " already exists<br />";
		} else {
			$filesize = $_FILES[$attachment]["size"] / 1024;

			$datetime = time();
			$date = date("Y/m/d", $datetime);
			$time = date("H:i:s", $datetime);

			$sql = "INSERT INTO evaluation.gw_attendance_attachments (gw_attendance_attachment_id, gw_attendance_attachment_period, gw_attendance_attachment_filename, gw_attendance_attachment_by, gw_attendance_attachment_date, gw_attendance_attachment_time)
					VALUES ('', '$period', '', '$staff_id', '" . $date . "', '" . $time . "')";

			if (!mysql_query($sql, $db)) {
				die('Error: ' . mysql_error($db));
				$message .= "There is an error, please try again.<br />";
			} else {
				$new_gw_attendance_attachment_id = mysql_insert_id($db);

				//TransactionLog("po_listing_attachment", $new_po_listing_attachment_id, "new", $rowUsers['id'], $_SERVER['REMOTE_ADDR']);

				$extension = explode(".", $filename_old);
				$filename = $staff_id . "_" . $new_gw_attendance_attachment_id . "_" . date("YmdHis", $datetime) . "." . $extension[1];

				mysql_query("UPDATE evaluation.gw_attendance_attachments SET gw_attendance_attachment_filename = '$filename' WHERE gw_attendance_attachment_id = '$new_gw_attendance_attachment_id'", $db) or die(mysql_error($db));

				$target_path = $dir . "/" . $filename;

				move_uploaded_file($_FILES[$attachment]["tmp_name"], $target_path);

				/*
						if ($_FILES[$attachment]["type"] == "application/pdf")
						{
							// Moving Original File Size
							move_uploaded_file($_FILES[$attachment]["tmp_name"], $target_path);
						} else {
							// Resize The Original File
							//resizePhoto("448", "336", $_FILES[$attachment]["tmp_name"], $target_path);
							//resizePhoto("800", "", $_FILES[$attachment]["tmp_name"], $target_path);
						}
						*/

				//$photoStat = stat($target_path);
				//$photoSize = $photoStat["size"] / 1024;

				$message .= "<strong>" . $filename_old . "</strong>" . " added<br />";

				//SystemLog("photo", "add", $staff_id, $_SERVER['REMOTE_ADDR'], "Add New Photo: $last_insert_id");
			}
		}
	}
	//}

	return $message;
}

function NonWorkingAttachmentAdd($db, $field_name, $travel_id, $index)
{
	$filename_old = basename($_FILES[$field_name]["name"]);

	$dir = "/mnt/LiveHDD/sportal_v2/accounts/vehicleDetails/attachments_vtf_non_working";

	if (!is_dir($dir)) {
		mkdir($dir, 0777, true);
	}

	if ($_FILES[$field_name]["error"] > 0) {
		return "Error uploading file: " . $_FILES[$field_name]["error"];
	}

	$path_info = pathinfo($filename_old);
	$extension = isset($path_info['extension']) ? strtolower($path_info['extension']) : 'jpg';
	$basename_clean = str_replace('.', '_', isset($path_info['filename']) ? $path_info['filename'] : '');
	$basename_clean = preg_replace('/[^a-zA-Z0-9_-]/', '_', $basename_clean);

	$unique_id = uniqid();
	$filename = $travel_id . "_" . $unique_id . "." . $extension;
	$target_path = $dir . "/" . $filename;

	if (file_exists($target_path)) {
		$unique_id = str_replace('.', '', uniqid('', true));
		$filename = $travel_id . "_" . $unique_id . "." . $extension;
		$target_path = $dir . "/" . $filename;
	}

	if (move_uploaded_file($_FILES[$field_name]["tmp_name"], $target_path)) {
		$insertSQL = "INSERT INTO gas_usage.travels_non_working_attachments 
					  (travel_id, travel_attachment_filename, deleted) 
					  VALUES ('$travel_id', '$filename', '0')";

		if (!mysql_query($insertSQL, $db)) {
			unlink($target_path);
			return "Database error: " . mysql_error($db);
		}
		return "File uploaded: " . $filename;
	}

	return "Failed to move uploaded file";
}
