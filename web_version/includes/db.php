<?php
	
	$db_globportal = mysql_connect("localhost", "staff_profile", "StaffProfileGlob@2024");
	//$db_gts = mysql_connect("60.51.93.181:33060", "root", "globmysql");
	//$db_glob = mysql_connect("124.82.158.118", "root", "globmysql");
	//$db_sms = mysql_connect("175.138.70.209:33061", "globin", "globin", true);
	
	mysql_select_db("staff_profile", $db_globportal) or die('Unable to select database 1');
	//mysql_select_db("bkgts", $db_gts); // or die('Unable to select database 2');
	//mysql_select_db("hr", $db_glob) or die('Unable to select database 2');
	//mysql_select_db("gammu", $db_sms) or die('Unable to select database 3');
	
	$user = "root";
	$pass = "globmysql";
	$db = "staff_profile";
	
	//mysql_connect("localhost", $user, $pass);
	//@mysql_select_db($db) or die( "Unable to select database");
	
	$starting_year = 2022;
	
	//$no_of_attachments = 10;
	
	define('WEBSITE_TITLE', "Staff Performance Appraisal");
	
	$access_list_memo = array(
		3563, // Emily Yap - Globinaco
		3946, // Nathan Soo - Globinaco
		1082, // AMC - Dr Cannye Chung
		4158, // TP - Dr Pan
	);
	
	$access_list_memo_hr = array(
		427, // Kevin Chua - Globinaco
		3424, // Vincent Ng - Globinaco
		3791, // Grandis - Natasha Beatta Gait
		3659, // Grandis - Rosalia Binti Gabriel
		4111, // Grandis - Clare Yvonne Primus
		1446, // Grandis - Michael Tan
		3103, // LS - Lim Wuang Ching
		3772, // LS - Fionne Foo Ka Kyun
		1228, // Arus - Lim Chen Eng
		3442, //Arus sawit - Asyrafy
		4187, // AMC - Aqilla
		4456, // TP Nicole
		4545, // TP Esther
	);
	
	$access_list = array(
		427, // Kevin Chua - Globinaco
		//1963, // Alice Lun - Admin
		//3414, // Christilin Binti Kisil - Admin
		//3365, // Sabina Binti Justin - Assistant HR Manager
		//3384, // Mackayla Chu
		//3341, // Soo Chee Kiong
		//2557, // Jeffrey Soo - Globinaco
		3424, // Vincent Ng - Globinaco
		//3144, // Prudencio F Gonzales = Globinaco
		//3383, // Jeffone Bin Naijim
		//551, // Nur Ain Binti Mahmud
		//569, // Roslina@ Marlina binti Gambias
		//3418, // Chriscynthia Raymond Chong
		3563, // Emily Yap - Globinaco
		1082, // AMC - Dr Cannye Chung
		//3909, // AMC - Patricia Ongir
		3791, // Grandis - Natasha Beatta Gait
		3659, // Grandis - Rosalia Binti Gabriel
		4111, // Grandis - Clare Yvonne Primus
		1446, // Grandis - Michael Tan
		3103, // LS - Lim Wuang Ching
		3772, // LS - Fionne Foo Ka Kyun
		1228, // Arus - Lim Chen Eng
		4158, // TP - Dr Pan
	    2891, // City Skill - Dr Pan
		3657, // Elien Kong - Globinaco
		3895, // Siti - Globinaco
		3946, //Nathan Soo - Globinaco
		3442, //Arus sawit - Asyrafy
		4187, // AMC - Aqilla
		4456, // TP Nicole
		4545, // TP Esther
		3758, // Bella Thien - Globinaco
	);
	/*
	*/
	
	/*
	$hr_list = array(
		427, 	// Kevin Chua - Developer
		2557,	// Glob: Jeffrey Soo (jeffrey.glob)
		2646,	// Glob: Melissa Stephen John (melissajohn)
		3365,	// Glob: Sabina Binti Justin (sabina)
		3269,	// Glob: Mohd Hazran Bin Abdul Basir (Hazran)
		3144,	// Glob: Prudencio F Gonzales (prudencio)
		3383,	// Glob: Jeffone Bin Naijim (jeffone)
	);
	*/
	
	/*
	$purchase_list = array(
		427, // Kevin Chua - Developer
		2041, // IT Admin - Grandis
		1501, // Lai Chuen How - Grandis: Purchasing Manager
		2526, // Remelyn H. Sikopit - Grandis: Account Executive
	);
	
	$verify_list = array(
		427, // Kevin Chua - Developer
		2041, // IT Admin - Grandis
		1546, // Pansy Soo - Grandis: Account Manager
	);
	
	$approve_list = array(
		427, // Kevin Chua - Developer
		2041, // IT Admin - Grandis
		1594, // Chung Keh Vee - Grandis: Deputy General Manager
	);
	*/
	
	/*
	$review_list = array(
		427, // Kevin Chua
		1220, // Jeffrey Soo - Arus Sawit
		1949, // Vivit Yee - Arus Sawit
		2533, // Charles - Arus Sawit
		2557, // Jeffrey Soo - Globinaco
	);
	
	$access_list = array(
		427, // Kevin Chua
		1220, // Jeffrey Soo - Arus Sawit
		1949, // Vivit Yee - Arus Sawit
		2533, // Charles - Arus Sawit
		2557, // Jeffrey Soo - Globinaco
	);
	
	$access_list_prepare = array();
	$access_list_review = array();
	$access_list_admin = array();
	
	$queryPrepare = "SELECT * FROM access_lists WHERE access_list_prepare = '1' AND access_list_deleted = '0'";
	$resultPrepare = mysql_query($queryPrepare) or die(mysql_error());
	while ($rowPrepare = mysql_fetch_array($resultPrepare))
	{
		array_push($access_list_prepare, $rowPrepare['user_id']);
	}
	
	$queryReview = "SELECT * FROM access_lists WHERE access_list_review = '1' AND access_list_deleted = '0'";
	$resultReview = mysql_query($queryReview) or die(mysql_error());
	while ($rowReview = mysql_fetch_array($resultReview))
	{
		array_push($access_list_review, $rowReview['user_id']);
	}
	
	$queryAdmin = "SELECT * FROM access_lists WHERE access_list_admin = '1' AND access_list_deleted = '0'";
	$resultAdmin = mysql_query($queryAdmin) or die(mysql_error());
	while ($rowAdmin = mysql_fetch_array($resultAdmin))
	{
		array_push($access_list_admin, $rowAdmin['user_id']);
	}
	*/
	
?>
