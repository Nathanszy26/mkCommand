<?php

function CheckTable($db, $select, $from, $where, $return)
{
	$query = "SELECT $select FROM $from WHERE $where";
	$result = mysql_query($query, $db) or die(mysql_error());
	$row = mysql_fetch_array($result);

	return $row[$return];
}

/*
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
		
		if ($row['COUNT(speed_ticket_id)'] > 0)
		{
			return $row['COUNT(speed_ticket_id)'];
		} else {
			return "-";
		}
	}
	*/

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

/*
	function Scoreboard_TotalDailyTravelFormSubmission($db, $year, $month, $car_no, $staff_id)
	{
		$total_days = date("t", $year . "-" . $month . "-01");
		$working_days = 0;
		$off_days = 0;
		$working_submitted_days = 0;
		$off_submitted_days = 0;
		
		for ($i = 1; $i <= $total_days; $i ++)
		{
			if ($i < 10) { $j = "0" . $i; } else { $j = $i; }
			
			$check_working_day = true;
			$check_submitted_day = true;
			
			// Check Sunday
			if (date("w", strtotime($year . "-" . $month . "-" . $j)) == 0) { $check_working_day = false; }
			
			// Check Public Holiday
			if (CheckPublicHoliday($db, $year, $month, $j) == 1) { $check_working_day = false; }
			
			// Check Annual Leave
			$queryLeaves = "SELECT * FROM gas_usage.driver_leaves WHERE driver_leave_person = '$staff_id' AND driver_leave_status = '4' AND driver_leave_from <= '" . $year . "-" . $month . "-" . $j . "' AND driver_leave_to >= '" . $year . "-" . $month . "-" . $j . "'";
			$resultLeaves = mysql_query($queryLeaves, $db) or die(mysql_error());
			$numLeaves = mysql_num_rows($resultLeaves);
			if ($numLeaves == 1) { $check_working_day = false; }
			
			if ($check_working_day == true)
			{
				$working_days ++;
			} else {
				$off_days ++;
			}
			
			// DB: gas_usage.travels
			//$query = "SELECT driver_id, car_no, travel_date, travel_deleted FROM gas_usage.travels WHERE driver_id = '$staff_id' AND car_no = '$car_no' AND travel_date = '" . $year . "-" . $month . "-" . $j . "' AND travel_deleted = '0'";
			// DB: gas_usage.daily_vtf
			$query = "SELECT * FROM gas_usage.daily_vtf WHERE car_no = '$car_no' AND travel_date = '" . $year . "-" . $month . "-" . $j . "' AND daily_vtf_deleted = '0'";
			$result = mysql_query($query, $db) or die(mysql_error());
			$num = mysql_num_rows($result);
			
			if ($num == 0) { $check_submitted_day = false; }
			
			if ($check_submitted_day == true)
			{
				if ($check_working_day == true)
				{
					$working_submitted_days ++;
				} else {
					$off_submitted_days ++;
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
		if ($payer != "all") { $query .= " AND payer_name = '$payer'"; }
		$query .= " AND transaction_date LIKE '$period%'";
		if ($payer != "all") { $query .= " GROUP BY payer_name"; }
		$result = mysql_query($query, $db) or die(mysql_error());
		$row = mysql_fetch_array($result);
		
		return $row['SUM(net_amount)'];
	}
	*/

/*
	function LoginLog($db, $user_id, $user_ip, $user_action)
	{
		$date = date("Y/m/d");
		$time = date("H:i:s");
		
		mysql_query("INSERT INTO gas_usage.user_login_log (user_login_log_id, user_id, user_login_log_action, user_login_log_date, user_login_log_time, user_login_log_ip) VALUES ('', '$user_id', '$user_action', '$date', '$time', '$user_ip')", $db) or die(mysql_error());
	}
	
	function TransactionLog($table, $id, $type, $by, $by_ip)
	{
		$date = date("Y/m/d");
		$time = date("H:i:s");
		
		if (($table != "") && ($id != "") && ($type != "") && ($by != "") && ($by_ip != "") && ($date != "") && ($time != ""))
		{
			mysql_query("INSERT INTO transaction_log (transaction_log_id, transaction_log_table, transaction_log_data_id, transaction_log_type, transaction_log_by, transaction_log_by_ip, transaction_log_date, transaction_log_time)
			VALUES ('', '$table', '$id', '$type', '$by', '$by_ip', '$date', '$time')") or die(mysql_error());
		}
	}
	
	function UpdatePOStatus($po_listing_id, $status_id)
	{
		mysql_query("UPDATE po_listing SET po_listing_status = '$status_id' WHERE po_listing_id = '$po_listing_id'");
	}
	*/

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

function StaffMemo_AttachmentAdd($db, $attachment, $id)
{
	$now_date = date("Y/m/d");
	$now_time = date("H:i:s");

	//$filename_old = basename($_FILES[$attachment]["name"]);
	$filename_old = basename($_FILES[$attachment]["name"]);
	$filename_old_sql = mysql_real_escape_string($filename_old, $db);

	$dir = $_SERVER['DOCUMENT_ROOT'] . "/accounts/staffProfile/attachments/staff_memo/";

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

			$sql = "INSERT INTO staff_memo_attachments (staff_memo_attachment_id, staff_memo_id, staff_memo_attachment_original_filename, staff_memo_attachment_filename)
					VALUES ('', '$id', '" . $filename_old_sql . "', '')";
			//VALUES ('', '$id', '" . addslashes($filename_old) . "', '')";

			if (!mysql_query($sql, $db)) {
				die('Error: ' . mysql_error());
				$message .= "There is an error, please try again.<br />";
			} else {
				$new_staff_memo_attachment_id = mysql_insert_id($db);

				//TransactionLog("po_listing_attachment", $new_po_listing_attachment_id, "new", $rowUsers['id'], $_SERVER['REMOTE_ADDR']);

				$extension = explode(".", $filename_old);
				$filename = $id . "_" . $new_staff_memo_attachment_id . "." . $extension[1];

				mysql_query("UPDATE staff_memo_attachments SET staff_memo_attachment_filename = '$filename' WHERE staff_memo_attachment_id = '$new_staff_memo_attachment_id'", $db) or die(mysql_error());

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

function StaffMemo_AttachmentDelete($db, $id)
{
	$queryAttachment = "SELECT * FROM staff_memo_attachments WHERE staff_memo_attachment_id = '$id'";
	$resultAttachment = mysql_query($queryAttachment, $db) or die(mysql_error());
	$rowAttachment = mysql_fetch_array($resultAttachment);

	$path = $_SERVER['DOCUMENT_ROOT'] . "/accounts/staffProfile/attachments/staff_memo/" . $rowAttachment['staff_memo_attachment_filename'];

	$new_dir = $_SERVER['DOCUMENT_ROOT'] . "/accounts/staffProfile/attachments/staff_memo/deleted";
	if (is_dir($new_dir) == false) {
		mkdir($new_dir, 0777);
	}

	$new_path = $new_dir . "/" . date("YmdHis") . "_" . $rowAttachment['staff_memo_attachment_filename'];

	copy($path, $new_path);
	unlink($path);

	mysql_query("UPDATE staff_memo_attachments SET staff_memo_attachment_deleted = '1' WHERE staff_memo_attachment_id = '$id'", $db) or die(mysql_error());

	//$message .= $row_photo['photo_taken_date'] . "/" . $row_photo['photo_filename'] . " deleted";

	//SystemLog("photo", "delete", $staff_id, $_SERVER['REMOTE_ADDR'], "Delete Photo: $photo_id");

	//return $message;
}

function StaffPerformance_AttachmentAdd($db, $attachment, $id)
{
	$now_date = date("Y/m/d");
	$now_time = date("H:i:s");

	$filename_old = basename($_FILES[$attachment]["name"]);

	$dir = $_SERVER['DOCUMENT_ROOT'] . "/accounts/staffProfile/attachments/staff_performance/";

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

			$sql = "INSERT INTO staff_performance_attachments (staff_performance_attachment_id, staff_performance_id, staff_performance_attachment_original_filename, staff_performance_attachment_filename)
					VALUES ('', '$id', '" . addslashes($filename_old) . "', '')";

			if (!mysql_query($sql, $db)) {
				die('Error: ' . mysql_error($db));
				$message .= "There is an error, please try again.<br />";
			} else {
				$new_staff_performance_attachment_id = mysql_insert_id($db);

				//TransactionLog("po_listing_attachment", $new_po_listing_attachment_id, "new", $rowUsers['id'], $_SERVER['REMOTE_ADDR']);

				$extension = explode(".", $filename_old);
				$filename = $id . "_" . $new_staff_performance_attachment_id . "." . $extension[1];

				mysql_query("UPDATE staff_performance_attachments SET staff_performance_attachment_filename = '$filename' WHERE staff_performance_attachment_id = '$new_staff_performance_attachment_id'", $db) or die(mysql_error($db));

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

function StaffPerformance_AttachmentDelete($db, $id)
{
	$queryAttachment = "SELECT * FROM staff_performance_attachments WHERE staff_performance_attachment_id = '$id'";
	$resultAttachment = mysql_query($queryAttachment, $db) or die(mysql_error());
	$rowAttachment = mysql_fetch_array($resultAttachment);

	$path = $_SERVER['DOCUMENT_ROOT'] . "/accounts/staffProfile/attachments/staff_performance/" . $rowAttachment['staff_performance_attachment_filename'];

	$new_dir = $_SERVER['DOCUMENT_ROOT'] . "/accounts/staffProfile/attachments/staff_performance/deleted";
	if (is_dir($new_dir) == false) {
		mkdir($new_dir, 0777);
	}

	$new_path = $new_dir . "/" . date("YmdHis") . "_" . $rowAttachment['staff_performance_attachment_filename'];

	copy($path, $new_path);
	unlink($path);

	mysql_query("UPDATE staff_performance_attachments SET staff_performance_attachment_deleted = '1' WHERE staff_performance_attachment_id = '$id'", $db) or die(mysql_error());

	//$message .= $row_photo['photo_taken_date'] . "/" . $row_photo['photo_filename'] . " deleted";

	//SystemLog("photo", "delete", $staff_id, $_SERVER['REMOTE_ADDR'], "Delete Photo: $photo_id");

	//return $message;
}

function StaffDA_AttachmentAdd($db, $attachment, $id)
{
	$now_date = date("Y/m/d");
	$now_time = date("H:i:s");

	$filename_old = basename($_FILES[$attachment]["name"]);

	$dir = $_SERVER['DOCUMENT_ROOT'] . "/accounts/staffProfile/attachments/staff_da/";

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

			$sql = "INSERT INTO staff_da_attachments (staff_da_attachment_id, staff_da_id, staff_da_attachment_original_filename, staff_da_attachment_filename)
					VALUES ('', '$id', '" . addslashes($filename_old) . "', '')";

			if (!mysql_query($sql, $db)) {
				die('Error: ' . mysql_error());
				$message .= "There is an error, please try again.<br />";
			} else {
				$new_staff_da_attachment_id = mysql_insert_id($db);

				//TransactionLog("po_listing_attachment", $new_po_listing_attachment_id, "new", $rowUsers['id'], $_SERVER['REMOTE_ADDR']);

				$extension = explode(".", $filename_old);
				$filename = $id . "_" . $new_staff_da_attachment_id . "." . $extension[1];

				mysql_query("UPDATE staff_da_attachments SET staff_da_attachment_filename = '$filename' WHERE staff_da_attachment_id = '$new_staff_da_attachment_id'", $db) or die(mysql_error());

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

function StaffDA_AttachmentDelete($db, $id)
{
	$queryAttachment = "SELECT * FROM staff_da_attachments WHERE staff_da_attachment_id = '$id'";
	$resultAttachment = mysql_query($queryAttachment, $db) or die(mysql_error());
	$rowAttachment = mysql_fetch_array($resultAttachment);

	$path = $_SERVER['DOCUMENT_ROOT'] . "/accounts/staffProfile/attachments/staff_da/" . $rowAttachment['staff_da_attachment_filename'];

	$new_dir = $_SERVER['DOCUMENT_ROOT'] . "/accounts/staffProfile/attachments/staff_da/deleted";
	if (is_dir($new_dir) == false) {
		mkdir($new_dir, 0777);
	}

	$new_path = $new_dir . "/" . date("YmdHis") . "_" . $rowAttachment['staff_da_attachment_filename'];

	copy($path, $new_path);
	unlink($path);

	mysql_query("UPDATE staff_da_attachments SET staff_da_attachment_deleted = '1' WHERE staff_da_attachment_id = '$id'", $db) or die(mysql_error());

	//$message .= $row_photo['photo_taken_date'] . "/" . $row_photo['photo_filename'] . " deleted";

	//SystemLog("photo", "delete", $staff_id, $_SERVER['REMOTE_ADDR'], "Delete Photo: $photo_id");

	//return $message;
}

//Staff performance
function StaffPerformance_MeritDemeritOptions()
{
	return array("merit" => "Merit", "demerit" => "Demerit");
}

function StaffPerformance_MeritDemeritLabel($merit_demerit)
{
	$options = StaffPerformance_MeritDemeritOptions();
	$key = strtolower($merit_demerit);

	return isset($options[$key]) ? $options[$key] : $merit_demerit;
}

function StaffPerformance_TypeList($db)
{
	$types = array();

	$result = mysql_query("SELECT staff_performance_type_id, staff_performance_type_title, staff_performance_type_merit_demerit FROM staff_performance_types WHERE staff_performance_type_deleted = '0' ORDER BY staff_performance_type_merit_demerit, staff_performance_type_title", $db) or die(mysql_error($db));
	while ($row = mysql_fetch_assoc($result)) {
		$types[] = $row;
	}

	return $types;
}

function StaffPerformance_TypeGet($db, $id)
{
	$result = mysql_query("SELECT staff_performance_type_id, staff_performance_type_title, staff_performance_type_merit_demerit FROM staff_performance_types WHERE staff_performance_type_id = '" . (int)$id . "' AND staff_performance_type_deleted = '0'", $db) or die(mysql_error($db));
	$row = mysql_fetch_assoc($result);

	return $row ? $row : null;
}

function StaffPerformance_TypeValidate($db, $id, $title, $merit_demerit)
{
	if (($title == "") || ($merit_demerit == "")) {
		return "Title and Merit/Demerit are both required.";
	}
	if (!array_key_exists($merit_demerit, StaffPerformance_MeritDemeritOptions())) {
		return "Merit/Demerit must be either Merit or Demerit.";
	}
	if (($id > 0) && (StaffPerformance_TypeGet($db, $id) === null)) {
		return "The Type being edited does not exist or has been deleted.";
	}

	$result = mysql_query("SELECT COUNT(*) FROM staff_performance_types WHERE staff_performance_type_deleted = '0' AND staff_performance_type_title = '" . addslashes($title) . "' AND staff_performance_type_merit_demerit = '" . addslashes($merit_demerit) . "' AND staff_performance_type_id <> '" . (int)$id . "'", $db) or die(mysql_error($db));
	$row = mysql_fetch_row($result);

	if ($row[0] > 0) {
		return "A " . $merit_demerit . " Type with this Title already exists.";
	}

	return "";
}

function StaffPerformance_TypeSave($db, $id, $title, $merit_demerit)
{
	$fields = "staff_performance_type_title = '" . addslashes($title) . "', staff_performance_type_merit_demerit = '" . addslashes($merit_demerit) . "'";

	if ($id > 0) {
		$query = "UPDATE staff_performance_types SET " . $fields . " WHERE staff_performance_type_id = '" . (int)$id . "'";
	} else {
		$query = "INSERT INTO staff_performance_types SET " . $fields . ", staff_performance_type_deleted = '0'";
	}

	mysql_query($query, $db) or die(mysql_error($db));
}
