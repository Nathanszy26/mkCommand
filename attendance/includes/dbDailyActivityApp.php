<?php
	
	$db_globportal = mysql_connect("localhost", "vehicle_details", "VehicleGlob@2024");
	// $db_globIT = mysql_connect("glob.com.my", "root", "kRXvkeUGyi5er7");
	//$db_gts = mysql_connect("60.51.93.181:33060", "root", "globmysql");
	//$db_glob = mysql_connect("124.82.158.118", "root", "globmysql");
	//$db_sms = mysql_connect("175.138.70.209:33061", "globin", "globin", true);
	//$db_amc_osticket = mysql_connect("60.51.93.177:33060", "root", "amc_mysql");
	
	mysql_select_db("staff_portal2", $db_globportal) or die('Unable to select database 1');
	// mysql_select_db("it", $db_globIT) or die('Unable to connect to globIT database');
	//mysql_select_db("bkgts", $db_gts); // or die('Unable to select database 2');
	//mysql_select_db("hr", $db_glob) or die('Unable to select database 2');
	//mysql_select_db("gammu", $db_sms) or die('Unable to select database 3');
	//mysql_select_db("osTicket", $db_amc_osticket) or die('Unable to select database 2');
	
	/*
	$user = "root";
	$pass = "globmysql";
	$db = "evaluation";
	*/
	
	//mysql_connect("localhost", $user, $pass);
	//@mysql_select_db($db) or die( "Unable to select database");
	
	$starting_year = 2022;
	
	//$no_of_attachments = 10;
	
	define('WEBSITE_TITLE', "Vehicle Details");

	$access_list = array(
		427, // Kevin Chua
		3341, // Soo CK
		457, // Heldah
		3424, // Vincent Ng
		3704, // Dylson Nicolaus
		3758, // Thien Wen Jing
		3759, // Melannie Alliun Ledius
		3311, // Tiffiny Christina Ruel
		4080, // Eveyena Azel Binti Joman - Admin
		3657, // Elien Kong Xiao Lian
		3895,  // Siti Khadijah
		3946, // nathan
		3821, // john
		3758, // bella
		4100, // Chung Tze Ying
	);
	
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