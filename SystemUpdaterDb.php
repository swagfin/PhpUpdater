<?php
class SystemUpdaterDb {
	
	public $updateServerUrl ="http://localhost/";
	public $updateVersionFile = "current_version_db.txt"; 
	public $updateReleaseRemotePath = "migrations/v{{version}}.txt"; //Will Add VersionNo to End #e.g. wil be v1.txt u can use .sql extension
	public $initialVersionRelease = 0;
	
	#Errors Count
	public $terminateOnSqlError = false;
	public $errorsCount = 0;
	public $successCount = 0;
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
			fwrite($versionfile, $this->initialVersionRelease);  
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
		$this->sendErrorResponse(500, "Failed to Get Latest Database Version from Target URL, Error: ".$e->getMessage());
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
			throw new Exception("FAILED TO DOWNLOAD DATABASE SCRIPT VERSION: v$vnum");
		}
		//Copied :-)
		//Required Database connection
		require ("connect.php");
		$mysqli = new mysqli($db_servername, $db_username, $db_password, $db_database);
		$mysqli->set_charset("utf8");
		header('Content-Type: text/html;charset=utf-8');
		$this->sqlImport($release_file);

		if($this->terminateOnSqlError && $this->successCount <=0)
		{
			throw new Exception("SQL SCRIPT EXECUTION SCRIPT DID NOT EXECUTE SUCCESSFULLY");
		}
		//Proceed
		//UPDATE VERSION NO 
		$versionfile = fopen ($this->updateVersionFile, "w");
		$user_vnum = fgets($versionfile);  
		fwrite($versionfile, $vnum);  
		fclose($versionfile);
		//Return Success
		echo "<br/><br/>SUCCESS | Peak MB: ", memory_get_peak_usage(true)/1024/1024;
	}
	catch(Exception $e)
	{
		$this->sendErrorResponse(500, "Failed Install And Update Database, Error: ".$e->getMessage());
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
/**
 * Import SQL from file
 *
 * @param string path to sql file
 */
function sqlImport($file)
{
	$this->errorsCount = 0;
	$this->successCount = 0;
    $delimiter = ';';
    $file = fopen($file, 'r');
    $isFirstRow = true;
    $isMultiLineComment = false;
    $sql = '';

    while (!feof($file)) {

        $row = fgets($file);

        // remove BOM for utf-8 encoded file
        if ($isFirstRow) {
            $row = preg_replace('/^\x{EF}\x{BB}\x{BF}/', '', $row);
            $isFirstRow = false;
        }

        // 1. ignore empty string and comment row
        if (trim($row) == '' || preg_match('/^\s*(#|--\s)/sUi', $row)) {
            continue;
        }

        // 2. clear comments
        $row = trim($this->clearSQL($row, $isMultiLineComment));

        // 3. parse delimiter row
        if (preg_match('/^DELIMITER\s+[^ ]+/sUi', $row)) {
            $delimiter = preg_replace('/^DELIMITER\s+([^ ]+)$/sUi', '$1', $row);
            continue;
        }

        // 4. separate sql queries by delimiter
        $offset = 0;
        while (strpos($row, $delimiter, $offset) !== false) {
            $delimiterOffset = strpos($row, $delimiter, $offset);
            if ($this->isQuoted($delimiterOffset, $row)) {
                $offset = $delimiterOffset + strlen($delimiter);
            } else {
                $sql = trim($sql . ' ' . trim(substr($row, 0, $delimiterOffset)));
                $this->query($sql);

                $row = substr($row, $delimiterOffset + strlen($delimiter));
                $offset = 0;
                $sql = '';
            }
        }
        $sql = trim($sql . ' ' . $row);
    }
    if (strlen($sql) > 0) {
        $this->query($row);
    }

    fclose($file);
}

/**
 * Remove comments from sql
 *
 * @param string sql
 * @param boolean is multicomment line
 * @return string
 */
function clearSQL($sql, &$isMultiComment)
{
    if ($isMultiComment) {
        if (preg_match('#\*/#sUi', $sql)) {
            $sql = preg_replace('#^.*\*/\s*#sUi', '', $sql);
            $isMultiComment = false;
        } else {
            $sql = '';
        }
        if(trim($sql) == ''){
            return $sql;
        }
    }

    $offset = 0;
    while (preg_match('{--\s|#|/\*[^!]}sUi', $sql, $matched, PREG_OFFSET_CAPTURE, $offset)) {
        list($comment, $foundOn) = $matched[0];
        if ($this->isQuoted($foundOn, $sql)) {
            $offset = $foundOn + strlen($comment);
        } else {
            if (substr($comment, 0, 2) == '/*') {
                $closedOn = strpos($sql, '*/', $foundOn);
                if ($closedOn !== false) {
                    $sql = substr($sql, 0, $foundOn) . substr($sql, $closedOn + 2);
                } else {
                    $sql = substr($sql, 0, $foundOn);
                    $isMultiComment = true;
                }
            } else {
                $sql = substr($sql, 0, $foundOn);
                break;
            }
        }
    }
    return $sql;
}

/**
 * Check if "offset" position is quoted
 *
 * @param int $offset
 * @param string $text
 * @return boolean
 */
function isQuoted($offset, $text)
{
    if ($offset > strlen($text))
        $offset = strlen($text);

    $isQuoted = false;
    for ($i = 0; $i < $offset; $i++) {
        if ($text[$i] == "'")
            $isQuoted = !$isQuoted;
        if ($text[$i] == "\\" && $isQuoted)
            $i++;
    }
    return $isQuoted;
}

function query($sql)
{
	try
	{
		global $mysqli;
		echo '<br/>#<strong>Executing:</strong> ' . htmlspecialchars($sql) . ';';
		if (!mysqli_query($mysqli, $sql))
		{
			echo "<span style='color:red'> [!ERROR!]</span>";
			throw new Exception(" [!ERROR!]");
		}
		else
		{
			echo "<span style='color:green'> SUCCESS</span>";
			$this->successCount++; //Success
		}	
	}
	catch(Exception $e)
	{
		$this->errorsCount++;
		if($this->terminateOnSqlError)
		{
			throw new Exception("Cannot execute request to the database {$sql}: " . $e->getMessage());
		}
	}
   
	
}
}
/*
 * SYSTEM UPDATER CHECKS
 */
#SET DEFAULT PHP CONFIGS
ini_set('memory_limit', '5120M');
set_time_limit ( 0 );
#End of Config
$updater = new SystemUpdaterDb();
//Test Version
$vnumbers = $updater->getLatestVersion();
if ($vnumbers===null)
{
	exit("An Exception Occurred while checking for Database Update");
}
else if(count($vnumbers) == 0)
{
	exit("Database has the Lastest Migrations"); 
}
else
{
	echo "Migration Updates Found: (". count($vnumbers) .") Updates";
	//Loop through all Updates
	foreach ($vnumbers as $vnum)
	{
		echo "<br><strong>Running migration v: ".$vnum."</strong>";
		$response = $updater->downloadAndInstallUpdate($vnum);
		echo "<br>".$response;
	}
}
?>