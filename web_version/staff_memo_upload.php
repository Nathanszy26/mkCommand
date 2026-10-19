<?php

//ini_set('display_errors', 1);
//ini_set('display_startup_errors', 1);
//error_reporting(E_ALL);

include_once("includes/db.php");
include_once("includes/access.php");
include_once("includes/function.php");
	
	/* --- staff directory for this form ------------------------------------
	 *
	 * The subsidiaries -> users loop was written twice on this page (To, CC)
	 * and From makes three. One copy instead: the population is a single rule
	 * - subsidiaries flagged staff_memo, active users - and three
	 * hand-maintained copies of it drift.
	 *
	 * Same rows and same order as SubordinateDirectory::ADDRESSABLE_FROM in
	 * mkCommand/mkCommandIssue.php, so the web form and the app offer one list.
	 * ---------------------------------------------------------------------- */

/**
 * Command: print the <optgroup> blocks for a staff <select>.
 *
 * Prints only the groups - the leading "- Select Staff -" / "- To All Staff -"
 * entries differ per field and stay with the field that owns them.
 *
 * $selectedId marks one option selected; pass null for the multi-selects.
 */

function StaffMemo_PrintStaffOptions_From($db, $selectedId = null)
{
  $selectedId = ($selectedId === null || $selectedId === '') ? null : (int)$selectedId;

  $querySubsidiaries = "SELECT * FROM staff_portal2.subsidiaries WHERE staff_memo = '1' ORDER BY code";
  $resultSubsidiaries = mysql_query($querySubsidiaries, $db) or die(mysql_error());

  while ($rowSubsidiaries = mysql_fetch_array($resultSubsidiaries)) {
    echo "<optgroup label='" . $rowSubsidiaries['code'] . "'>";

    $queryStaffList = "SELECT * FROM staff_portal2.users WHERE comp_id = '" . $rowSubsidiaries['id'] . "' AND status = '1' ORDER BY users.fullname";
    $resultStaffList = mysql_query($queryStaffList, $db) or die(mysql_error());

    while ($rowStaffList = mysql_fetch_array($resultStaffList)) {
      $isSelected = ($selectedId !== null && (int)$rowStaffList['id'] === $selectedId)
        ? " selected='selected'"
        : "";

      echo "<option value='" . $rowStaffList['id'] . "'" . $isSelected . ">"
        . ucwords($rowStaffList['fullname']) . " - " . $rowStaffList['position']
        . " - " . $rowStaffList['email'] . " - " . $rowStaffList['mobile_no']
        . "</option>";
    }

    echo "</optgroup>";
  }
}

function StaffMemo_PrintStaffOptions($db, $selectedId = null)
{
  $selectedId = ($selectedId === null || $selectedId === '') ? null : (int)$selectedId;

  $querySubsidiaries = "SELECT * FROM staff_portal2.subsidiaries WHERE staff_memo = '1' ORDER BY code";
  $resultSubsidiaries = mysql_query($querySubsidiaries, $db) or die(mysql_error());

  while ($rowSubsidiaries = mysql_fetch_array($resultSubsidiaries)) {
    echo "<optgroup label='" . $rowSubsidiaries['code'] . "'>";
    echo "<option value='all_" . $rowSubsidiaries['id'] . "'>- To All " . $rowSubsidiaries['code'] . " Staff -</option>";

    $queryStaffList = "SELECT * FROM staff_portal2.users WHERE comp_id = '" . $rowSubsidiaries['id'] . "' AND status = '1' ORDER BY users.fullname";
    $resultStaffList = mysql_query($queryStaffList, $db) or die(mysql_error());

    while ($rowStaffList = mysql_fetch_array($resultStaffList)) {
      $isSelected = ($selectedId !== null && (int)$rowStaffList['id'] === $selectedId)
        ? " selected='selected'"
        : "";

      echo "<option value='" . $rowStaffList['id'] . "'" . $isSelected . ">"
        . ucwords($rowStaffList['fullname']) . " - " . $rowStaffList['position']
        . " - " . $rowStaffList['email'] . " - " . $rowStaffList['mobile_no']
        . "</option>";
    }

    echo "</optgroup>";
  }
}

/**
 * Query: the fullname behind a users.id, or "" when the id is not an active
 * addressable staff member.
 *
 * The From name is resolved here rather than read from the post, so
 * staff_memo_from and staff_memo_from_staff_id cannot disagree about who wrote
 * the memo - the whole point of storing the id alongside the name.
 */
function StaffMemo_StaffName($db, $staffId)
{
  $staffId = (int)$staffId;
  if ($staffId <= 0) {
    return "";
  }

  $query = "SELECT u.fullname
				FROM staff_portal2.subsidiaries s
				INNER JOIN staff_portal2.users u ON u.comp_id = s.id AND u.status = '1'
				WHERE s.staff_memo = '1' AND u.id = '" . $staffId . "'
				LIMIT 1";

  $result = mysql_query($query, $db) or die(mysql_error());
  $row = mysql_fetch_array($result);

  return $row ? $row['fullname'] : "";
}


/* --- myMK app inbox mirror --------------------------------------------
	 *
	 * Posts a committed memo id to the app inbox and records whether it landed.
	 * The whole contract is the id - subject, body, recipients and attachments
	 * are all read from the memo row on the other side.
	 *
	 * Same behaviour as MemoMirrorClient / MemoMirrorLog in
	 * mkCommand/mkCommandIssue.php, written against this page's mysql_* layer
	 * rather than PDO. Both write the same staff_memo_mirror row, so a memo is
	 * traceable whether it was issued from the web or the app.
	 *
	 * The endpoint has no token and only accepts 127.0.0.1; /etc/hosts on this
	 * box maps dashboard.mkgroup.my to loopback, so the URL, vhost and
	 * certificate all stay correct while the connection never leaves the
	 * machine. A 403 means that hosts entry is missing.
	 * ---------------------------------------------------------------------- */

define('STAFF_MEMO_MIRROR_URL', 'https://dashboard.mkgroup.my/sportal-memo.php');
define('STAFF_MEMO_MIRROR_TIMEOUT', 10);
define('STAFF_MEMO_MIRROR_CONNECT_TIMEOUT', 5);
define('STAFF_MEMO_MIRROR_MAX_LOG_BYTES', 1000);

/**
 * Query: POST the memo id and normalise the outcome.
 *
 * Never dies and never throws. The memo is already committed by the time this
 * runs, so a mirror that is down must not cost the user the record they wrote.
 *
 * Returns array(ok, http, error, body, raw).
 */
function StaffMemo_Mirror($memoId)
{
  if (!function_exists('curl_init')) {
    return StaffMemo_MirrorOutcome(false, 0, 'curl is not available on this server', null, '');
  }

  $ch = curl_init(STAFF_MEMO_MIRROR_URL);
  curl_setopt_array($ch, array(
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => array('memo_id' => $memoId),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => STAFF_MEMO_MIRROR_TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => STAFF_MEMO_MIRROR_CONNECT_TIMEOUT,
  ));

  $raw      = curl_exec($ch);
  $curlErr  = curl_error($ch);
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($raw === false) {
    return StaffMemo_MirrorOutcome(false, $httpCode, 'could not reach the mirror: ' . $curlErr, null, '');
  }

  $body = json_decode($raw, true);

  if ($httpCode === 403) {
    return StaffMemo_MirrorOutcome(false, 403, 'mirror refused the call as non-local - check the /etc/hosts entry for dashboard.mkgroup.my', $body, $raw);
  }
  if ($httpCode !== 200 || !is_array($body) || empty($body['success'])) {
    $message = (is_array($body) && isset($body['message']))
      ? $body['message']
      : 'mirror returned HTTP ' . $httpCode;

    return StaffMemo_MirrorOutcome(false, $httpCode, $message, $body, $raw);
  }

  return StaffMemo_MirrorOutcome(true, 200, null, $body, $raw);
}

function StaffMemo_MirrorOutcome($ok, $http, $error, $body, $raw)
{
  return array(
    'ok'    => (bool)$ok,
    'http'  => (int)$http,
    'error' => $error,
    'body'  => is_array($body) ? $body : array(),
    'raw'   => substr((string)$raw, 0, STAFF_MEMO_MIRROR_MAX_LOG_BYTES),
  );
}

/**
 * Command: write the outcome to staff_memo_mirror.
 *
 * UNIQUE on staff_memo_id, so a retry updates the row and bumps the attempt
 * count rather than piling up history. The question the table answers is
 * "which memos are still not in the app inbox", and that is a scan for
 * status <> 'sent'. The mirror is idempotent, so re-posting is always safe.
 *
 * Uses the same $db_globportal link as the staff_memo insert, so the table is
 * reached unprefixed exactly as staff_memo is.
 */
function StaffMemo_MirrorLog($db, $memoId, $outcome)
{
  $body = $outcome['body'];

  $memoId      = (int)$memoId;
  $status      = $outcome['ok'] ? 'sent' : 'failed';
  $httpCode    = (int)$outcome['http'];
  $jobId       = isset($body['job_id']) ? addslashes((string)$body['job_id']) : '';
  $recipients  = isset($body['recipients']) ? (int)$body['recipients'] : 0;
  $alreadySent = !empty($body['already_sent']) ? '1' : '0';
  $response    = addslashes($outcome['ok'] ? $outcome['raw'] : (string)$outcome['error']);
  $date        = date("Y/m/d");
  $time        = date("H:i:s");

  mysql_query("INSERT INTO staff_memo_mirror
			(staff_memo_id, staff_memo_mirror_status, staff_memo_mirror_attempts,
			staff_memo_mirror_http_code, staff_memo_mirror_job_id,
			staff_memo_mirror_recipients, staff_memo_mirror_already_sent,
			staff_memo_mirror_response,
			staff_memo_mirror_created_date, staff_memo_mirror_created_time,
			staff_memo_mirror_updated_date, staff_memo_mirror_updated_time)
			VALUES ('$memoId', '$status', 1, '$httpCode', '$jobId', '$recipients',
					'$alreadySent', '$response', '$date', '$time', '$date', '$time')
			ON DUPLICATE KEY UPDATE
				staff_memo_mirror_status       = VALUES(staff_memo_mirror_status),
				staff_memo_mirror_attempts     = staff_memo_mirror_attempts + 1,
				staff_memo_mirror_http_code    = VALUES(staff_memo_mirror_http_code),
				staff_memo_mirror_job_id       = VALUES(staff_memo_mirror_job_id),
				staff_memo_mirror_recipients   = VALUES(staff_memo_mirror_recipients),
				staff_memo_mirror_already_sent = VALUES(staff_memo_mirror_already_sent),
				staff_memo_mirror_response     = VALUES(staff_memo_mirror_response),
				staff_memo_mirror_updated_date = VALUES(staff_memo_mirror_updated_date),
				staff_memo_mirror_updated_time = VALUES(staff_memo_mirror_updated_time)", $db)
    or error_log('staff_memo_upload mirror log failed: ' . mysql_error());
}

/**
 * Query: the line to add to the upload confirmation, or "" when there is
 * nothing worth saying.
 *
 * A failed mirror is not a failed memo - the record stands and the log holds it
 * for a retry - so this reads as a note, not an error.
 */
function StaffMemo_MirrorNotice($outcome)
{
  if (!$outcome['ok']) {
    return "\\n\\nNot shown in the myMK app inbox yet. The memo is saved and will be retried.";
  }

  $skipped = isset($outcome['body']['skipped_attachments']) ? $outcome['body']['skipped_attachments'] : array();
  if (empty($skipped)) {
    return "";
  }

  return "\\n\\nIn the myMK app inbox. These attachments cannot be shown there: "
    . addslashes(implode(', ', $skipped));
}

//$submission_type_id = "1";

$no_of_to = 15;
$no_of_cc = 15;
$no_of_attachments = 5;

if (isset($_POST['runScript'])) {
  $runScript = $_POST['runScript'];
} else {
  $runScript = "";
}

if ($runScript == "Submit Staff Memo") {
  $staff_memo_ref = $_POST['staff_memo_ref'];
  $staff_memo_date = $_POST['staff_memo_date'];
  $staff_memo_to_staff_id_no = count($_POST['staff_memo_to_staff_id']);
  $staff_memo_cc_staff_id_no = count($_POST['staff_memo_cc_staff_id']);

  // From is picked by id now. The NAME is looked up from that id rather
  // than taken from the post, so staff_memo_from and
  // staff_memo_from_staff_id can never disagree about who wrote the memo.
  $staff_memo_from_staff_id = (int)$_POST['staff_memo_from_staff_id'];
  $staff_memo_from_comp_id = CheckTable($db_globportal, "comp_id", "staff_portal2.users", "id = '$staff_memo_from_staff_id'", "comp_id");
  $staff_memo_from = addslashes(StaffMemo_StaffName($db_globportal, $staff_memo_from_staff_id));

  $staff_memo_subject = addslashes($_POST['staff_memo_subject']);
  $staff_memo_content = addslashes($_POST['staff_memo_content']);

  $staff_memo_uploaded_by = $rowUsers['id'];
  $staff_memo_uploaded_date = date("Y/m/d");
  $staff_memo_uploaded_time = date("H:i:s");

  if (($staff_memo_date != "") && ($staff_memo_to_staff_id_no > 0) && ($staff_memo_from != "")) {
    mysql_query("INSERT INTO staff_memo (staff_memo_id, staff_memo_ref, staff_memo_date, staff_memo_from, staff_memo_from_staff_id, staff_memo_from_comp_id, staff_memo_subject, staff_memo_content, staff_memo_uploaded_by, staff_memo_uploaded_date, staff_memo_uploaded_time)
			VALUES ('', '$staff_memo_ref', '$staff_memo_date', '$staff_memo_from', '$staff_memo_from_staff_id', '$staff_memo_from_comp_id', '$staff_memo_subject', '$staff_memo_content', '$staff_memo_uploaded_by', '$staff_memo_uploaded_date', '$staff_memo_uploaded_time')", $db_globportal) or die(mysql_error($db_globportal));

    $new_staff_memo_id = mysql_insert_id($db_globportal);

    if ($new_staff_memo_id != "") {
      for ($i = 0; $i < count($_POST['staff_memo_to_staff_id']); $i++) {
        $returnID = $_POST['staff_memo_to_staff_id'][$i];
        $checkID = explode('_', $returnID);

        switch ($checkID[0]) {
          case "all":

            if ($checkID[1] == "mk") {
              mysql_query("INSERT INTO staff_memo_receivers (staff_memo_receiver_id, staff_memo_id, staff_memo_receiver_type, staff_memo_receiver_staff_id, staff_memo_receiver_comp_id)
								VALUES ('', '$new_staff_memo_id', 'to', 'all', 'all')", $db_globportal) or die(mysql_error($db_globportal));
            } else {
              mysql_query("INSERT INTO staff_memo_receivers (staff_memo_receiver_id, staff_memo_id, staff_memo_receiver_type, staff_memo_receiver_staff_id, staff_memo_receiver_comp_id)
								VALUES ('', '$new_staff_memo_id', 'to', 'all', '" . $checkID[1] . "')", $db_globportal) or die(mysql_error($db_globportal));
            }

            break;

          case (is_numeric($returnID)):

            $compID = CheckTable($db_globportal, "comp_id", "staff_portal2.users", "id = '$returnID'", "comp_id");

            mysql_query("INSERT INTO staff_memo_receivers (staff_memo_receiver_id, staff_memo_id, staff_memo_receiver_type, staff_memo_receiver_staff_id, staff_memo_receiver_comp_id)
							VALUES ('', '$new_staff_memo_id', 'to', '$returnID', '$compID')", $db_globportal) or die(mysql_error($db_globportal));

            break;

          default:
            break;
        }
      }

      for ($i = 0; $i < count($_POST['staff_memo_cc_staff_id']); $i++) {
        $returnID = $_POST['staff_memo_cc_staff_id'][$i];
        $checkID = explode('_', $returnID);

        switch ($checkID[0]) {
          case "all":

            if ($checkID[1] == "mk") {
              mysql_query("INSERT INTO staff_memo_receivers (staff_memo_receiver_id, staff_memo_id, staff_memo_receiver_type, staff_memo_receiver_staff_id, staff_memo_receiver_comp_id)
								VALUES ('', '$new_staff_memo_id', 'cc', 'all', 'all')", $db_globportal) or die(mysql_error($db_globportal));
            } else {
              mysql_query("INSERT INTO staff_memo_receivers (staff_memo_receiver_id, staff_memo_id, staff_memo_receiver_type, staff_memo_receiver_staff_id, staff_memo_receiver_comp_id)
								VALUES ('', '$new_staff_memo_id', 'cc', 'all', '" . $checkID[1] . "')", $db_globportal) or die(mysql_error($db_globportal));
            }

            break;

          case (is_numeric($returnID)):

            $compID = CheckTable($db_globportal, "comp_id", "staff_portal2.users", "id = '$returnID'", "comp_id");

            mysql_query("INSERT INTO staff_memo_receivers (staff_memo_receiver_id, staff_memo_id, staff_memo_receiver_type, staff_memo_receiver_staff_id, staff_memo_receiver_comp_id)
							VALUES ('', '$new_staff_memo_id', 'cc', '$returnID', '$compID')", $db_globportal) or die(mysql_error($db_globportal));

            break;

          default:
            break;
        }
      }
    }

    //TransactionLog("staff_memo", $new_staff_memo_id, "new", $rowUsers['id'], $_SERVER['REMOTE_ADDR']);

    $total_attachments = $_POST['total_attachments'];

    for ($i = 1; $i <= $total_attachments; $i++) {
      $attachment = addslashes($_FILES['attachment_' . $i]["name"]);

      if ($attachment != "") {
        StaffMemo_AttachmentAdd($db_globportal, "attachment_" . $i, $new_staff_memo_id);
      }
    }

    // Mirror LAST: the attachments have to be on disk before the app is
    // told to fetch them, since the mirror links to this folder rather
    // than copying the files. Never blocks the memo - the outcome is
    // logged either way and the alert says if it did not land.
    $mirror = StaffMemo_Mirror($new_staff_memo_id);
    StaffMemo_MirrorLog($db_globportal, $new_staff_memo_id, $mirror);

    /* --- tell the recipients ------------------------------------------
     *
     * Push and email for everybody the memo is addressed to or copied to,
     * resolved from staff_memo_receivers - the same rule the app's Memo
     * list uses to decide who may see it, so nobody is emailed a memo they
     * cannot open and nobody on it is left out.
     *
     * Shared with the app's own issue endpoint rather than rewritten here:
     * mkPortal/mkCommand/staffNotify.php owns the recipient rule, the
     * wording and the sending. It opens its own PDO connection, so it does
     * not care that this page speaks mysql_*.
     *
     * LAST, and after the mirror, for the same reason the mirror is last:
     * the notification tells people to open the memo in the app, so the app
     * inbox and the attachments have to be in place before it goes out.
     * Nothing is actually sent inside this request - the recipients are
     * queued and a background drain delivers them - so an all-staff memo
     * costs this page two queries, not seven hundred emails.
     * ------------------------------------------------------------------ */
    require_once($_SERVER['DOCUMENT_ROOT'] . "/accounts/mkPortal/mkCommand/staffNotify.php");
    staff_notify_memo($new_staff_memo_id);

    echo "<script type='text/javascript'>";
    // echo "alert('Staff Memo Uploaded!" . StaffMemo_MirrorNotice($mirror) . "');";
    echo "alert('Staff Memo Uploaded!');";
    echo "window.location = 'staff_memo_listing.php';";
    echo "</script>";
  } else {
    echo "<script type='text/javascript'>";
    echo "alert('Staff Memo Failed to Upload, please try again!');";
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
              <h3>Upload Staff Memo</h3>
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
                  <h2>Upload Staff Memo <!--small>different form elements</small--></h2>
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
                  <form action="staff_memo_upload.php" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left">

                    <div class="form-group">
                      <label class="control-label col-md-3 col-sm-3 col-xs-12" for="staff_memo_ref">Ref.
                      </label>
                      <div class="col-md-6 col-sm-6 col-xs-12">
                        <input id="staff_memo_ref" name="staff_memo_ref" type="text" class="form-control col-md-7 col-xs-12"> (Optional)
                      </div>
                    </div>

                    <div class="form-group">
                      <label class="control-label col-md-3 col-sm-3 col-xs-12" for="staff_memo_date">Date <span class="required">*</span>
                      </label>
                      <div class="col-md-6 col-sm-6 col-xs-12">
                        <input id="staff_memo_date" name="staff_memo_date" type="text" required="required" class="form-control col-md-7 col-xs-12"> (DD/MM/YYYY)
                      </div>
                    </div>

                    <div class="form-group">
                      <label class="control-label col-md-3 col-sm-3 col-xs-12" for="staff_memo_from_staff_id">From <span class="required">*</span>
                      </label>
                      <div class="col-md-6 col-sm-6 col-xs-12">
                        <select id="staff_memo_from_staff_id" name="staff_memo_from_staff_id" class="select2_from form-control" required="required">
                          <option value="">- Select Staff -</option>
                          <?php StaffMemo_PrintStaffOptions_From($db_globportal, $rowUsers['id']); ?>
                        </select>
                        (Defaults to you)
                      </div>
                    </div>

                    <div class="form-group">
                      <label class="control-label col-md-3 col-sm-3 col-xs-12" for="staff_memo_to_staff_id">To <span class="required">*</span>
                      </label>
                      <div class="col-md-6 col-sm-6 col-xs-12">
                        <select id="staff_memo_to_staff_id[]" name="staff_memo_to_staff_id[]" class="select2_multiple form-control" multiple="multiple">
                          <option value="">- Select Staff -</option>
                          <option value="all_mk">- To All MK Group Staff -</option>
                          <?php StaffMemo_PrintStaffOptions($db_globportal); ?>
                        </select>
                        (Maximum <?php echo $no_of_to; ?>)
                      </div>
                    </div>

                    <div class="form-group">
                      <label class="control-label col-md-3 col-sm-3 col-xs-12" for="staff_memo_cc">CC
                      </label>
                      <div class="col-md-6 col-sm-6 col-xs-12">

                        <select id="staff_memo_cc_staff_id[]" name="staff_memo_cc_staff_id[]" class="select2_multiple form-control" multiple="multiple">
                          <option value="">- Select Staff -</option>
                          <option value="all_mk">- To All MK Group Staff -</option>
                          <?php StaffMemo_PrintStaffOptions($db_globportal); ?>
                        </select>
                        (Maximum <?php echo $no_of_cc; ?>)

                      </div>
                    </div>

                    <div class="form-group">
                      <label class="control-label col-md-3 col-sm-3 col-xs-12" for="staff_memo_subject">Subject <span class="required">*</span>
                      </label>
                      <div class="col-md-6 col-sm-6 col-xs-12">
                        <input id="staff_memo_subject" name="staff_memo_subject" type="text" required="required" class="form-control col-md-7 col-xs-12">
                      </div>
                    </div>

                    <div class="form-group">
                      <label class="control-label col-md-3 col-sm-3 col-xs-12" for="staff_memo_content">Content <span class="required">*</span>
                      </label>
                      <div class="col-md-6 col-sm-6 col-xs-12">
                        <textarea id="staff_memo_content" name="staff_memo_content" class="form-control" rows="3"></textarea>
                      </div>
                    </div>

                    <div class="form-group">
                      <label class="control-label col-md-3 col-sm-3 col-xs-12" for="attachments">Attachments <span class="required">*</span>
                      </label>
                      <div class="col-md-6 col-sm-6 col-xs-12">
                        <input id="total_attachments" name="total_attachments" type="hidden" value="<?php echo $no_of_attachments; ?>">
                        <?php for ($i = 1; $i <= $no_of_attachments; $i++) { ?>
                          <input id="attachment_<?php echo $i; ?>" name="attachment_<?php echo $i; ?>" type="file" accept="image/*,application/pdf,.pdf"><br>
                        <?php } ?>
                      </div>
                    </div>

                    <div class="ln_solid"></div>

                    <div class="form-group">
                      <div class="col-md-6 col-sm-6 col-xs-12 col-md-offset-3">
                        <button type="reset" class="btn btn-primary">Reset</button>
                        <button id="runScript" name="runScript" type="submit" class="btn btn-success" value="Submit Staff Memo">Submit</button>
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
        maximumSelectionLength: <?php echo $no_of_to; ?>,
        //placeholder: "With Max Selection limit 5",
        allowClear: false
      });
      // From is single-select and pre-set to the logged-in staff. It gets its
      // own init rather than select2_single, whose allowClear would let the
      // field be emptied and whose placeholder belongs to another form.
      $(".select2_from").select2({
        allowClear: false
      });
    });
  </script>
  <!-- /Select2 -->

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
      // staff_memo_date is stored as DD/MM/YYYY, matching
      // IssueRepository::MEMO_DATE_FORMAT on the mobile endpoint. Without an
      // explicit format the picker defaults to MM/DD/YYYY and the column ends
      // up holding both conventions, which is unreadable - 05/08 and 08/05 are
      // different days with nothing in the row to say which was meant.
      $('#staff_memo_date').daterangepicker({
        singleDatePicker: true,
        calender_style: "picker_2",
        locale: {
          format: 'DD/MM/YYYY'
        }
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
  <!-- /bootstrap-daterangepicker -->

</body>

</html>