<?php

	include_once($_SERVER['DOCUMENT_ROOT'] . "/accounts/staffProfile/includes/db.php");

    session_start();
    session_name('staffProfile');
	
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
		echo "window.location = 'http://' + self.location.hostname + '/accounts/staffProfile/login.php?url=' + url;";
		echo "</script>";
	}
	
?>