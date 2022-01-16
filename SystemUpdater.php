<?php
class SystemUpdater {
	
	public $updateServerUrl ="http://localhost:8080/";
	public $updateVersionFile = "current_version.txt"; 
	public $updateReleaseRemotePath ="releases/update_v{{version}}.zip"; //Will Add VersionNo to End #e.g. wil be update_v1.zip

/*
 * FUNCTION TO GET LATEST VERSION
 */
function getLatestVersion(){
	try
	{
		// open version file on external server
		$file = fopen ("$this->updateServerUrl/$this->updateVersionFile", "r");
		$vnum = intval(fgets($file));    
		fclose($file);
		//Check if File Exists
		if (!file_exists($this->updateVersionFile))
		{
			$versionfile = fopen ($this->updateVersionFile, "w");
			$user_vnum = fgets($versionfile);  
			fwrite($versionfile, "0");  
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
			return "FAILED_TO_DOWNLOAD";
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
			return "FAILED_TO_EXTRACT";
		}
	}
	catch(Exception $e)
	{
		return "FAILED_EXCEPTION: ".$e->getMessage();
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