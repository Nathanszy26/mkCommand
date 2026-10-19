<?php
	
	include_once($_SERVER['DOCUMENT_ROOT'] . "/accounts/vehicleDetails/includes/db.php");
	include_once($_SERVER['DOCUMENT_ROOT'] . "/accounts/vehicleDetails/includes/function.php");
	
	$runScript = $_GET['runScript'];
	
	/*
	if ($runScript == "showSupplierDetails")
	{
		$po_supplier_id = $_GET['po_supplier_id'];
		
		
		echo $po_supplier_id . ";" . CheckTable("*", "po_suppliers", "po_supplier_id = '$po_supplier_id'", "po_supplier_name") . ";" . CheckTable("*", "po_suppliers", "po_supplier_id = '$po_supplier_id'", "po_supplier_contact_person") . ";" . CheckTable("*", "po_suppliers", "po_supplier_id = '$po_supplier_id'", "po_supplier_contact_no");
	}
	*/
	
	if ($runScript == "showPreviousTrip")
	{
		$car_no = $_GET['car_no'];
		
		$queryTrip = "SELECT * FROM gas_usage.travels WHERE car_no = '$car_no' AND travel_deleted = '0' ORDER BY travel_date DESC, travel_id DESC LIMIT 1";
		$resultTrip = mysql_query($queryTrip, $db_globportal) or die(mysql_error());
		$numTrip = mysql_num_rows($resultTrip);
		
		if ($numTrip > 0)
		{
			$rowTrip = mysql_fetch_array($resultTrip);
			
			echo $rowTrip['driver_id'] . ";" . $rowTrip['car_no'] . ";" . $rowTrip['travel_date'] . ";" . $rowTrip['travel_time'] . ";" . $rowTrip['travel_odometer_from'] . ";" . $rowTrip['travel_odometer_to'];
		} else {
			echo "No Trip";
		}
	}
	
	if ($runScript == "noSubmission")
	{
		$date = $_GET['date'];
		$car_no = $_GET['car_no'];
		$driver = $_GET['driver'];
		$tick_status = $_GET['tick_status'];
		if ($tick_status == "true") { $tick_status = "1"; }
		if ($tick_status == "false") { $tick_status = ""; }
		
		if (($date != "") && ($car_no != "") && ($driver != ""))
		{
			$query = "SELECT * FROM gas_usage.no_submissions WHERE no_submission_date = '$date' AND no_submission_car_no = '$car_no' AND no_submission_driver = '$driver'";
			$result = mysql_query($query, $db_globportal) or die(mysql_error());
			$num = mysql_num_rows($result);
			
			if ($num == 1)
			{
				$row = mysql_fetch_array($result);
				
				mysql_query("UPDATE gas_usage.no_submissions SET no_submission_status = '$tick_status' WHERE no_submission_id = '" . $row['no_submission_id'] . "'", $db_globportal) or die(mysql_error());
			}
			
			if ($num == 0)
			{
				mysql_query("INSERT INTO gas_usage.no_submissions (no_submission_id, no_submission_date, no_submission_car_no, no_submission_driver, no_submission_status)
				VALUES ('', '$date', '$car_no', '$driver', '$tick_status')", $db_globportal) or die(mysql_error());
			}
		}
		
		if ($tick_status == "1") { echo "Ticked"; }
		if ($tick_status == "") { echo "Unticked"; }
	}
	
?>