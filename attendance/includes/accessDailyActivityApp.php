<?php

	include_once($_SERVER['DOCUMENT_ROOT'] . "/accounts/vehicleDetails/includes/dbDailyActivityApp.php");

    session_start();
    session_name('vehicleDetails');
	
	//$user_id = $_SESSION['user_id'];
	if (isset($_SESSION['user_id'])) { $user_id = $_SESSION['user_id']; } else { $user_id = ""; }
	
	$queryUsers = "SELECT * FROM staff_portal2.users WHERE id = '$user_id' AND status != '3'";
	$resultUsers = mysql_query($queryUsers, $db_globportal) or die(mysql_error());
	$rowUsers = mysql_fetch_array($resultUsers);
	$numUsers = mysql_num_rows($resultUsers);
	
	if ($numUsers != 1)
	//if (!in_array($rowUsers['id'], $access_list))
	{
		echo "<script language='javascript'>";
		echo "url = window.location;";
		echo "window.location = 'http://' + self.location.hostname + '/accounts/vehicleDetails/loginDailyActivityApp.php?url=' + url;";
		echo "</script>";
	} else {
		$staff_positions_array = array();
		
		$query = "SELECT * FROM glob_users_positions WHERE staff_id = '" . $rowUsers['id'] . "' AND glob_user_position_deleted = '0'";
		$result = mysql_query($query, $db_globportal) or die(mysql_error($db_globportal));
		while ($row = mysql_fetch_array($result))
		{
			if (!in_array($row['glob_position_id'], $staff_positions_array))
			{
				array_push($staff_positions_array, $row['glob_position_id']);
			}
		}
		
		$staff_districts_array = array();
		
		$query = "SELECT * FROM glob_users_districts WHERE staff_id = '" . $rowUsers['id'] . "' AND glob_user_district_deleted = '0'";
		$result = mysql_query($query, $db_globportal) or die(mysql_error($db_globportal));
		while ($row = mysql_fetch_array($result))
		{
			if (!in_array($row['glob_district_id'], $staff_districts_array))
			{
				array_push($staff_districts_array, $row['glob_district_id']);
			}
		}
	}
	
?>