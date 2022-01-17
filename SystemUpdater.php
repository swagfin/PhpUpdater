<?php
class SystemUpdater {
	
	public $updateServerUrl ="http://localhost:8080/";
	public $updateVersionFile = "current_version.txt"; 
	public $updateReleaseRemotePath = "releases/update_v{{version}}.zip"; //Will Add VersionNo to End #e.g. wil be update_v1.zip
	public $initialVersionRelease = "1";
/*
 * FUNCTION TO GET LATEST VERSION
 */
function getLatestVersion(){
	try
	{
		// open version file on external server
		$file = fopen ("$this->updateServerUrl/$this->updateVersionFile", "r");
		if(!$file)
		{
			throw new Exception("SERVER NOT FOUND");
		}
		$vnum = intval(fgets($file));
		fclose($file);
		//Check if File Exists
		if (!file_exists($this->updateVersionFile))
		{
			$versionfile = fopen ($this->updateVersionFile, "w");
			$user_vnum = fgets($versionfile);  
			fwrite($versionfile, $initialVersionRelease);  
			fclose($versionfile);
		}
		// check users local file for version number
		$userfile = fopen ($this->updateVersionFile, "r");
		$user_vnum = intval(fgets($userfile));    
		fclose($userfile);
		if($user_vnum >= $vnum)
		{
			return array();
		}
		else
		{
			$vnumbers = range(intval($user_vnum) +1, intval($vnum));
			return $vnumbers;
		}
	}
	catch(Exception $e)
	{
		$this->sendErrorResponse(500, "Failed to Get Latest Version from Target URL, Error: ".$e->getMessage());
		return null;
	}	
}
/*
 * FUNCTION TO DOWNLOAD AND INSTALL SYSTEM UPDATE
 */
function downloadAndInstallUpdate($vnum){
	try
	{
		// copy the file from source server
		$release_file = str_replace("{{version}}",$vnum, $this->updateReleaseRemotePath);
		//Check If Directory Exists
		if (!file_exists(dirname($release_file)))
		{
			mkdir(dirname($release_file), 0777, true);
		}
		$copy = copy("$this->updateServerUrl/$release_file", $release_file);
		// check for success or fail
		if(!$copy)
		{
			throw new Exception("FAILED TO DOWNLOAD UPDATE");
		}
	// check for verification
		$path = pathinfo(realpath($release_file), PATHINFO_DIRNAME);
		// unzip update
		$zip = new ZipArchive;
		$res = $zip->open($release_file);
		if($res === TRUE){
			$copyDirectory = pathinfo(realpath($this->updateVersionFile), PATHINFO_DIRNAME);
			$zip->extractTo($copyDirectory);
			$zip->close();
			// delete zip file
			#skip>>>>> Deleting >>> unlink($release_file);
			// update users local version number file
			$versionfile = fopen ($this->updateVersionFile, "w");
			$user_vnum = fgets($versionfile);  
			fwrite($versionfile, $vnum);  
			fclose($versionfile);
			//return Success
			return "SUCCESS";
		}
		else
		{
			// delete potentially corrupt file
			unlink($release_file);
			throw new Exception("FAILED TO EXTRACT DOWNLOADED UPDATE");
		}
	}
	catch(Exception $e)
	{
		$this->sendErrorResponse(500, "Failed Install And Update, Error: ".$e->getMessage());
		return "FAILED_EXCEPTION: ".$e->getMessage();
	}
}
/*
 * EXTERNAL FUNCTION FOR RESPONSE
 */
function sendErrorResponse($code, $msg)
{
	$httpStatusCode = $code;
	$httpStatusMsg  = $msg;
	$phpSapiName    = substr(php_sapi_name(), 0, 3);
	if ($phpSapiName == 'cgi' || $phpSapiName == 'fpm')
	{
		header('Status: '.$httpStatusCode.' '.$httpStatusMsg);
	}
	else
	{
		$protocol = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0';
		header($protocol.' '.$httpStatusCode.' '.$httpStatusMsg);
	}
}
}
/*
 * SYSTEM UPDATER CHECKS
 */
$updater = new SystemUpdater();
//Test Version
$vnumbers = $updater->getLatestVersion();
if ($vnumbers===null)
{
	exit("An Exception Occurred while checking for Update");
}
else if(count($vnumbers) == 0)
{
	exit("System is the Latest Version"); 
}
else
{
	echo "Updates Found: (". count($vnumbers) .") Updates";
	//Loop through all Updates
	foreach ($vnumbers as $vnum)
	{
		echo "<br>Updating v: ".$vnum;
		$response = $updater->downloadAndInstallUpdate($vnum);
		echo "<br>".$response;
	}
}
?>