<?php 
/*
 * consult the Looking Glass files for object names and references.
 * 
 *	Todo:
 * Pull server configuration from system settings, rather than hard coding ( Find: [DSS] )
 * Use CSS for cosmetics - Not HTML Tags 
 * 
 */



require_once("./sites/all/modules/nlg/includes/s3_class.inc.php");

if (isset($_REQUEST['ServerID'])) {
	$ServerID=$_REQUEST['ServerID']; 
	$output = host_service_detail($ServerID);
} else {  
	$output = whole_host_list();
} 	

echo $output;

####################################### Only Functions below this Line ###########################################

#######################################
######################### Present Data
#######################################

function host_service_detail($ServerID) {
	$NagiosHostObject = new S3_NagiosHost();
	$ServiceStatusText = variable_get('nagios_service_status_text', Array("OK", "Warning", "Critical", "Unknown"));
	if (!PingPoller($PollerObject)) { // We notify on error here. 
	}
	
	foreach ($PollerObject->Hosts as $NagiosHost)
	{
		if ($NagiosHost->HostID == $ServerID)
		{
			$HostFound = true;
			$NagiosHostObject = $NagiosHost;
			unset($NagiosHost);
		}
	}
	$HostStatus = array("OK", "W", "C", "UN");
	$PollerObject=get_output_template();
	$header = array(
			array('data' => t('Service Name')),
			array('data' => t('Status')),
			array('data' => t('Status Description / Text')),
	);

	foreach($NagiosHostObject->HostServices as $NagiosService) {
		$ServerName = $NagiosHostObject->HostName;
		$ServiceName = $NagiosService->ServiceName; 
		$ServiceStatus = $HostStatus[$NagiosHostObject->HostStatus];
		$ServiceText = $NagiosService->CheckResult;
	
		if ($ServiceStatus == "OK") { 	// Later these should be modified by a Class.
			$ServiceStatusOutput="<font color=green>$ServiceStatus</font>";
		} else {
			$ServiceStatusOutput="<font color=red>$ServiceStatus</font>";
		}
		$ServerNameHost=$_SERVER['SERVER_NAME'];
		$ServerNameOutput="<a href=\"http://$ServerNameHost/nlg7\">$ServerName</a>";

		if (!isset($ServiceName) or $ServiceName != "Total Processes") {
		$rows[]= array(
				array('data' => "$ServiceName</a>"),
				array('data' => "$ServiceStatusOutput"),
				array('data' => "$ServiceText"),
		);}
		unset($NagiosService);
	}
	$output = theme('table',array('header'=>$header,'rows'=>$rows,'caption'=>$ServerNameOutput));
	return $output;
}


function whole_host_list() {
	$HostStatus = array("OK", "W", "C", "UN");
	$PollerObject=get_output_template();
	$header = array(
			array('data' => t('Server Name')),
			array('data' => t('Status')),
			array('data' => t('Services')),
			array('data' => t('OK')),
			array('data' => t('Warning')),
			array('data' => t('Failed')),
	);

	foreach($PollerObject->Hosts as $NagiosHost) {
		$HostID=$NagiosHost->HostID;
		$ServerName = $NagiosHost->HostName;
		$ServerStatus = $HostStatus[$NagiosHost->HostStatus];
		$ServiceCount = $NagiosHost->ServiceCount_Total;
		$ServiceOK = $NagiosHost->ServiceCount_OK;
		$ServiceWarn = $NagiosHost->ServiceCount_Warn;
		$ServiceFail = $NagiosHost->ServiceCount_Fail;

		// Later these should be modified by a Class.
		if ($ServerStatus == "OK") {
			$ServerStatusOutput="<font color=green>$ServerStatus</font>";
		} else {
			$ServerStatusOutput="<font color=red>$ServerStatus</font>";
		}

		$ServiceOKOutput="<font color=green>$ServiceOK</font>";
		$ServiceWarnOutput="<font color=orange>$ServiceWarn</font>";
		$ServiceFailOutput="<font color=red>$ServiceFail</font>";

		$rows[]= array(
				array('data' => "<a href=\"/nlg7/?ServerID=$HostID\">$ServerName</a>"),
				array('data' => "$ServerStatusOutput"),
				array('data' => "$ServiceCount"),
				array('data' => "$ServiceOKOutput"),
				array('data' => "$ServiceWarnOutput"),
				array('data' => "$ServiceFailOutput"),
		);

	} 	unset($NagiosHost);

	$output = theme('table',array('header'=>$header,'rows'=>$rows));

	return $output;
}

#######################################
######## Get Object Data / Make Object  
#######################################

function get_output_template() {
	$PollerObject = new S3_NagiosPoller();
		if (!PingPoller($PollerObject)) { /* We Alert the user here */ }
	return $PollerObject;
}

function PingPoller(&$PollerObject) { 
	
	// Later we need to get this from the Drupal System Settings. [DSS]
	
	$ServerFeed_URL = variable_get('nlg_serverfeed_url'); 
	$ServerFeed_AuthEnabled = variable_get('nlg_serverfeed_authenabled');
	$ServerFeed_AuthUsername = variable_get('nlg_authusername');
	$ServerFeed_AuthPassword = variable_get('nlg_authpassword');
	$Language = variable_get('nlg_lang', 'en');

	if ($ServerFeed_AuthEnabled == 1) {
		$ServerFeed_URL = preg_replace("/^http([s]*):\/\/(.+)$/", "http$1://" . $ServerFeed_AuthUsername . ":" . $ServerFeed_AuthPassword . "@$2", $ServerFeed_URL);
	}
	elseif ($ServerFeed_AuthEnabled == 2) {
		if(array_key_exists("PHP_AUTH_USER", $_SERVER) && array_key_exists("PHP_AUTH_PW", $_SERVER)) {
			$ServerFeed_URL = preg_replace("/^http([s]*):\/\/(.+)$/", "http$1://" . $_SERVER['PHP_AUTH_USER'] . ":" . $_SERVER['PHP_AUTH_PW'] . "@$2", $ServerFeed_URL);
		}
	}

	$PollerFeedRaw = file_get_contents($ServerFeed_URL);

	if ($PollerFeedRaw === FALSE) {
		$PollerObject->LastPollerError = $Language['POLLING_SERVER_DOWN'];
		return FALSE;
	}
	$PollerFeed = explode("!!", $PollerFeedRaw);
	/**
	 * Now we're left with:
	 * $PollerFeed = Array(
	 *   [0] => **NLGPOLLER <Token header and app name>
	 *   [1] => Feed/X.Y.Z <Version of the feed>
	 *   [2] => hostname.domain.co.uk <Hostname the feed came from>
	 *   [3] => TRUE <Result of the feed processing on the server - TRUE/FALSE>
	 *   [4] => <base64, serialized representation of the NLGPoller class (when [3] == TRUE) > -or- <base64 representation of the feed creation error (when [3] == FALSE) >
	 *   [5] => 2ec761ee83f8769108f6612694831116** <MD5 checksum of the base64 data [4]>
	 *   [6] => NLGPOLLER** <app name and token trailer>
	 * )
	 */

	// SANITY CHECKS ON THE DOWNLOADED FEED //
	// ==================================== //
	// First check the base64 data - recalculate the MD5 hash and compare
	if ($PollerFeed[5] != md5($PollerFeed[4])) {
		$PollerObject->LastPollerError = variable_get('nlg_polling_checksum_diff', 'Checksum does not match');
		return FALSE;
	}

	// Check the server generated the feed OK
	if ($PollerFeed[3] == "FALSE") {
		// need to de-crypt the error string
		$PollerObject->LastPollerError = base64_decode($PollerFeed[4]);
		return FALSE;
	}

	// END SANITY CHECKS ON THE DOWNLOADED FEED //

	// all OK so far, now need to decrypt and deserialize the class data and check it is a valid class
	$NLGPoller = unserialize(base64_decode($PollerFeed[4]));

	if ($NLGPoller instanceof S3_NagiosPoller) {

		$PollerObject = $NLGPoller;
		unset($NLGPoller);
		unset($PollerFeed);
		unset($PollerFeedRaw);
		return true;
	}
	else {
		$PollerObject->LastPollerError = $Language['POLLING_CORRUPT_FEED'];

		unset($NLGPoller);
		unset($PollerFeed);
		unset($PollerFeedRaw);
		return FALSE;
	}
}
