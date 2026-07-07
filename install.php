<?php
/*
IO200 Installation
 1. Upload this file to your webspace's website base directory (usually by using an FTP program).
 2. Start the installation script in your browser by opening the following URL in your browser: 
    www.yourwebsite.com/install.php (replace "www.yourwebsite.com" with your domain)
    and follow the steps during the installation.

Documentation: https://www.io200.com/documentation#installation-automatic
*/


/*
Copyright (c) Michael Kirste, https://www.io200.com/terms

The frontend system and all associated themes and templates (the "Software") is licensed under the following conditions:

You are permitted to:
 - Edit, alter, modify, adapt, translate or otherwise change the whole or any part of the Software.
 - Use the software to publish your website and related content engaging in personal, commercial, non-commercial or non-profit activity.

You are not permitted to:
 - Reproduce, copy, distribute, transfer, license or sublicense the whole or any part of the Software.
 - Sell, resell, rent, lease or assign the whole or any part of the Software.
 - Remove, alter or obscure any proprietary notice.
 - Use the Software in any way which breaches any applicable local, national or international law
 - Use the whole or any part of the software after the termination of this contract.

The software may contain subprojects for which the respective own license terms apply. 

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL WE OR ANY COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/



//#### SETTINGS ########################################################
error_reporting(E_ERROR); // E_ERROR
ini_set('display_errors', true);
ini_set('max_execution_time', 120); // 120 seconds
define('CMS_TABLES', ['cms_articles', 'cms_articles_categories', 'cms_articles_tags', 'cms_categories', 'cms_collections', 'cms_collections_coverphotos', 'cms_collections_photos', 'cms_comments', 'cms_links', 'cms_pages', 'cms_photos', 'cms_photos_categories', 'cms_photos_tags', 'cms_tags']);
define('ENDPOINT_URL', 'https://www.service.io200.com/api/v1/');
define('REQUIRE_SSL', false);
define('CMS_RESERVED_FILES_FOLDERS', ['admin', 'listener', 'res', 'storage', 'sys', 'templates', 'index.php', 'LICENSE.md', 'serve.php' ]); // , '.htaccess'
define('THEME', ['layout' => 'fullwidth', 'mode' => 'light', 'font' => 'karlabold', 'flavors' => ['layoutfixedheader', 'slideeffect']]);


//#### REDIRECT ########################################################
function connection_has_ssl() {
	if (isset($_SERVER['HTTPS'])) {
		if (strtolower($_SERVER['HTTPS']) == "on" || $_SERVER['HTTPS'] == "1") {
			return true;
		}
	}
	if (isset($_SERVER['SERVER_PORT'])) {
		if ($_SERVER['SERVER_PORT'] == "443") {
			return true;
		}
	}
	// ssl certificate handled by a reverse proxy 
	if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
		if ($_SERVER['HTTP_X_FORWARDED_PROTO'] == "https") {
			return true;
		}
	}
	if (isset($_SERVER['HTTP_X_FORWARDED_PORT'])) {
		if ($_SERVER['HTTP_X_FORWARDED_PORT'] == "443") {
			return true;
		}
	}
	if (isset($_SERVER['HTTP_X_FORWARDED_SSL'])) {
		if ($_SERVER['HTTP_X_FORWARDED_SSL'] == "on") {
			return true;
		}
	}

	return false;
}
function domain_has_ssl_certificate($domain) {
    $ssl_check = @fsockopen( 'ssl://' . $domain, 443, $errno, $errstr, 30 );
    $res = !! $ssl_check;
    if ($ssl_check) { fclose( $ssl_check ); }
    return $res;
}
if (!isset($_GET) || empty($_GET)){ // redirect to https if available
	if(connection_has_ssl() === false){
		if(domain_has_ssl_certificate($_SERVER['HTTP_HOST']) === true){
			header("Location: https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']. "?ssl");			
		}
	}
}

//#### POLYFILL ########################################################
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return '' === $needle || false !== strpos($haystack, $needle);
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return \strncmp($haystack, $needle, \strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || $needle === \substr($haystack, -\strlen($needle));
    }
}
if (!function_exists('array_key_first')) {
    function array_key_first(array $array) {
        foreach ($array as $key => $value) {
            return $key;
        }
        return null;
    }
}
if (!function_exists('array_key_last')) {
    function array_key_last($array) {
        if (!is_array($array) || empty($array)) {
            return null;
        }
        return array_keys($array)[count($array) - 1];
    }
}

//#### CLASSES ########################################################
class DatabaseConnection {
    private $_connection = null;
    private $_status = null; // true or ErrorInfo

    public function __construct($db_hostname, $db_username, $db_password, $db_database, $db_port = null, $db_socket = null) {
		mysqli_report(MYSQLI_REPORT_OFF);
        $errorlevel = error_reporting();
        error_reporting(0);
        if ($db_port === null && $db_socket === null) {
            $this->_connection = new mysqli($db_hostname, $db_username, $db_password, $db_database);
        } else {
            $this->_connection = new mysqli($db_hostname, $db_username, $db_password, $db_database, $db_port !== null ? $db_port : ini_get("mysqli.default_port"), $db_socket !== null ? $db_socket : ini_get("mysqli.default_socket"));
        }
        error_reporting($errorlevel);

        if ($this->_connection->connect_error === null) {
            $this->_connection->set_charset('utf8');
            $this->_status = true;
        } else {
            $error_message = $this->_connection->connect_error;
            $error_number = $this->_connection->connect_errno;
            $this->_status = new ErrorInfo('database_connection', $error_message, $error_number);
        }
    }

    public function __destruct() {
        $this->CLOSE();
    }

    public function STATUS() { // returns true or ErrorInfo
        return $this->_status;
    }

    public function CLOSE() {
        if ($this->_status === true) {
            $this->_connection->close();
        }
    }

    public function SERVERINFO() {
        return $this->_connection->server_info;
    }


    // ### QUERY Functions ########################################
    public function QUERY($query) { // returns true or mysqli_result object otherwise ErrorInfo
        // can only excecute one statement (otherwise no statement is excecuted)
        if ($this->_status === true) {
            $query_result = $this->_connection->query($query);
            if ($query_result !== false) {
                return $query_result;
            } else {
                $error_message = $this->_connection->error;
                $error_number = $this->_connection->errno;
                return new ErrorInfo('database_query', $error_message, $error_number);
            }
        } else {
            return $this->_status;
        }
    }

    public function TRANSACTION($QUERIES, $rollback = true) { // returns array with true or mysqli_result object otherwise ErrorInfo for each query
        // multiple queries as transaction (rolls back if one query fails)
        // rollback does not work with non transactional table types (like MyISAM or ISAM)
        if ($this->_status === true) {
            $status = true;

            $QUERIES_RESULT = [];
            $this->_connection->begin_transaction();
            foreach ($QUERIES as $query) {
                $query_result = $this->QUERY($query);
                array_push($QUERIES_RESULT, $query_result);
                if (ErrorInfo::isError($query_result)) {
                    $status = false;
                }
            }

            if ($status === true || $rollback === false) {
                $this->_connection->commit();
            } else {
                $this->_connection->rollback();
            }

            return $QUERIES_RESULT;
        } else {
            return $this->_status;
        }
    }

    public function MULTIQUERY($multiquery) {
        // excecutes all statements until first failed
        if ($this->_status === true) {
            if ($this->_connection->multi_query($multiquery)) {
                $RESULT = [];
                do {
                    $query_result = $this->_connection->store_result();
                    if ($this->_connection->errno === 0) {
                        if ($query_result) {
                            array_push($RESULT, $this->RESULT2ARRAY($query_result));
                            $query_result->free();
                        } else {
                            array_push($RESULT, null); // query didn't return a result (e.g. INSERT)
                        }
                    } else {
                        array_push($RESULT, new ErrorInfo('database_query', $this->_connection->error, $this->_connection->errno));
                    }
                } while ($this->_connection->next_result());
            } else {
                return [new ErrorInfo('database_query', $this->_connection->error, $this->_connection->errno)];
            }
        } else {
            return $this->_status;
        }
    }

    public function QUERY2ARRAY($query, $option_single = false) {
        return $this->RESULT2ARRAY($this->QUERY($query), $option_single);
    }


    // ### CRUD Functions ########################################
    public function SELECT($table, $SELECT, $condition = null, $ordering = null, $limit = null, $offset = null, $option_single = false) {  // returns result; otherwise ErrorInfo
        //-> multiple: SELECT($table, $SELECT, $condition);
        //-> single: SELECT($table, $SELECT, $condition, null, 1, null, true);
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';
        foreach ($SELECT as &$val) {
            $val = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $val)) . '`';
        }
        $SELECT = implode(', ', $SELECT);
        $where = $this->GetWhere($condition);
        if ($ordering !== Null) {
            $order = ' ORDER BY ' . $ordering;
        } else {
            $order = '';
        }
        if ($limit !== Null) {
            $limit = ' LIMIT ' . intval($limit);
        } else {
            $limit = '';
        }
        if (($limit !== Null) and ($offset !== Null)) {
            $offset = ' OFFSET ' . intval($offset);
        } else {
            $offset = '';
        }

        $RESULT = $this->QUERY('SELECT ' . $SELECT . ' FROM ' . $table . $where . $order . $limit . $offset);
        return $this->RESULT2ARRAY($RESULT, $option_single);
    }

    public function GET($table, $field, $condition) { // returns result; otherwise ErrorInfo
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';
        $field = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $field)) . '`';
        $where = ' WHERE ' . $condition;

        $RESULT = $this->QUERY('SELECT ' . $field . ' as fieldvalue FROM ' . $table . $where . ' LIMIT 1');
        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        }
        if ($RESULT->num_rows === 0) {
            return new ErrorInfo('database_nomatch', "No item found");
        }

        $datatype = self::GetType($RESULT->fetch_field()->type);
        $fieldvalue = $RESULT->fetch_object()->fieldvalue;
        settype($fieldvalue, $datatype);

        return $fieldvalue;
    }

    public function ADD($table, $ADD = null) { // returns insertid as int; otherwise ErrorInfo
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';

        if ($ADD !== Null) {
            $FIELDS = array();
            $VALUES = array();
            foreach ($ADD as $key => $val) {
                array_push($FIELDS, '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $key)) . '`');
                array_push($VALUES, $this->ValueToEscapedString($val));
            }
            $VALUES = '(' . implode(', ', $FIELDS) . ') VALUES (' . implode(', ', $VALUES) . ')';
        } else {
            $VALUES = '() VALUES ()';
        }

        $RESULT = $this->QUERY('INSERT INTO ' . $table . ' ' . $VALUES);
        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        } else {
            $newid = $this->_connection->insert_id;
            return intval($newid);
        }
    }

    public function UPDATE($table, $UPDATE, $condition = null) { // returns number affectedrows as int/string (can be zero if no changes in records); otherwise ErrorInfo
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';
        $SET = array();
        foreach ($UPDATE as $key => $val) {
            array_push($SET, '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $key)) . '`=' . $this->ValueToEscapedString($val));
        }
        $SET = implode(', ', $SET);
        $where = $this->GetWhere($condition);

        $RESULT = $this->QUERY('UPDATE ' . $table . ' SET ' . $SET . $where);
        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        } else {
            return $this->_connection->affected_rows;
        }
    }

    public function SET($table, $field, $value, $condition = null) { // returns number affectedrows as int/string (can be zero if no changes in records); otherwise ErrorInfo
        return $this->UPDATE($table, array($field => $value), $condition);
    }

    public function INCREASE($table, $field, $condition) { // returns number affectedrows as int/string (can be zero if no changes in records); otherwise ErrorInfo
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';
        $field = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $field)) . '`';
        $where = $this->GetWhere($condition);

        $RESULT = $this->QUERY('UPDATE ' . $table . ' SET ' . $field . '=' . $field . '+1' . $where);
        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        } else {
            return $this->_connection->affected_rows;
        }
    }

    public function DECREASE($table, $field, $condition) { // returns number affectedrows as int/string (can be zero if no changes in records); otherwise ErrorInfo
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';
        $field = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $field)) . '`';
        $where = $this->GetWhere($condition);

        $RESULT = $this->QUERY('UPDATE ' . $table . ' SET ' . $field . '=' . $field . '-1' . $where);
        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        } else {
            return $this->_connection->affected_rows;
        }
    }

    public function DELETE($table, $condition) { // returns number affectedrows as int/string (can be zero if no changes in records); otherwise ErrorInfo
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';
        $where = $this->GetWhere($condition);

        $RESULT = $this->QUERY('DELETE FROM ' . $table . $where);
        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        } else {
            return $this->_connection->affected_rows;
        }
    }

    public function COUNT($table, $condition = null) { // returns count as integer; otherwise ErrorInfo
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';
        $where = $this->GetWhere($condition);

        $RESULT = $this->QUERY('SELECT COUNT(*) as count FROM ' . $table . $where);
        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        } else {
            $count = $RESULT->fetch_object()->count;
            return intval($count);
        }
    }


    // ### Helper Functions ########################################     
    public function ESCAPESTRING($value) {
        if ($this->_status === true) {
            return $this->_connection->real_escape_string($value);
        } else {
            return null;
        }
    }

    public function ValueToEscapedString($val) {
        switch (true) {
            case ($val === null):
                return 'null';
                break;
            case is_bool($val):
                return $val ? '1' : '0';
                break;
            case is_int($val):
                return $this->ESCAPESTRING($val);
                break;
            case is_string($val):
                return '\'' . $this->ESCAPESTRING($val) . '\'';
                break;
            default:
                return '\'' . $this->ESCAPESTRING($val) . '\'';
        }
    }

    private function RESULT2ARRAY($RESULT, $option_single = false) { // processes mysqli_result and returns result; otherwise ErrorInfo
        // tinyint is always interpreted as boolean (0 => false; 1 => true)

        // columns/rows*    raw data                    single=false                single=true
        // 1/0              ()			                ()			                null
        // 1/1              ((a=1))			            (1)			                1
        // 1/2              ((a=1), (a=2))		        (1, 2)			            (1, 2)
        // 2/0              ()		                    ()		                    ()
        // 2/1              ((a=1, b=11))		        ((a=1, b=11))		        (a=1, b=11)
        // 2/2              ((a=1, b=11), (a=2, b=22))	((a=1, b=11), (a=2, b=22))	((a=1, b=11), (a=2, b=22))
        // *(fields/items)

        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        } else {
            $tableset = null;

            if ($RESULT->num_rows > 0) {
                // get data types
                $datatypes = array();
                foreach ($RESULT->fetch_fields() as $field) {
                    $datatypes[$field->name] = self::GetType($field->type);
                }

                // cast data
                if (method_exists($this, 'fetch_all')) {
                    $tableset = $RESULT->fetch_all(MYSQLI_ASSOC);
                } else {
                    $tableset = [];
                    while ($row = $RESULT->fetch_assoc()) {
                        $tableset[] = $row;
                    }
                }
                foreach ($tableset as &$row) {
                    foreach ($row as $colkey => &$colval) {
                        if ($colval !== null) {
                            settype($row[$colkey], $datatypes[$colkey]);
                        }
                    }
                }
                if ($RESULT->field_count === 1) {
                    $tableset = array_map('current', $tableset);
                }

                //consider option_single
                if ($option_single == true) {
                    if ($RESULT->num_rows === 1) {
                        $tableset = current($tableset);
                    }
                }
            } else {
                if ($RESULT->field_count === 1 && $option_single == true) {
                    $tableset = null;
                } else {
                    $tableset = array();
                }
            }

            return $tableset;
        }
    }

    private static function GetWhere($condition) {
        if ($condition !== Null) {
            return ' WHERE ' . $condition;
        } else {
            return '';
        }
    }

    private static function GetType($field_type) {
        $result = null;

        switch ($field_type) {
            case MYSQLI_TYPE_NULL:
                $result = 'null';
                break;
            case MYSQLI_TYPE_BIT:
                $result = 'boolean';
                break;
            case MYSQLI_TYPE_TINY:
            case MYSQLI_TYPE_SHORT:
            case MYSQLI_TYPE_LONG:
            case MYSQLI_TYPE_INT24:
            case MYSQLI_TYPE_LONGLONG:
                $result = 'int';
                break;
            case MYSQLI_TYPE_FLOAT:
            case MYSQLI_TYPE_DOUBLE:
            case MYSQLI_TYPE_DECIMAL:
            case MYSQLI_TYPE_NEWDECIMAL:
                $result = 'float';
                break;
            default:
                $result = 'string';
                break;
        }
        if ($field_type === MYSQLI_TYPE_TINY) {
            $result = 'boolean';
        }

        return $result;
    }

    public static function AddQueryLimiter($limit = null, $start = null) {
        $limiter = '';
        if ($limit !== null) {
            $limiter = ' LIMIT ' . intval($limit);
        }
        if (($limit !== null) and ($start !== null)) {
            $limiter = ' LIMIT ' . intval($start) . ',' . intval($limit);
        }
        return $limiter;
    }
}
class ErrorInfo {
    public $type; //string
    public $message; //string or array
    public $data;

    public function __construct($type, $message = null, $data = null) {
        $this->type = $type;
        $this->message = $message;
        $this->data = $data;
    }

    public function toArray() {
        return ['type' => $this->type, 'message' => $this->message, 'data' => $this->data];
    }

    public function getErrorMessage() {
        $result = $this->type;

        if ($this->message !== null) {
            if (is_array($this->message)) {
                $result = "";
                foreach ($this->message as $key => $value) {
                    if (is_array($value)) {
                        if (array_key_exists('msg', $value)) {
                            $result .= $value['msg'];
                        }
                    } elseif (is_string($value)) {
                        $result .= $value;
                    }
                }
            } elseif (is_string($this->message)) {
                $result = $this->message;
            }
        }

        return $result;
    }

    public static function isError($variable) {
        return $variable instanceof ErrorInfo;
    }
}

//#### FUNCTIONS ########################################################
function xcopy($src, $dest) {
    foreach (scandir($src) as $object) {
        if (!in_array($object, ['.', '..'])) {
            if (is_dir($src . '/' . $object)) {
                if(!is_dir($dest . '/' . $object)){mkdir($dest . '/' . $object, 0755);}
                xcopy($src . '/' . $object, $dest . '/' . $object);
            } else {
                copy($src . '/' . $object, $dest . '/' . $object);
				chmod( $dest . '/' . $object, 0644);
            }
        }
    }
}
function rrmdir($dir) {
    if (is_dir($dir)) {
        foreach (scandir($dir) as $object) {
            if (!in_array($object, ['.', '..'])) {
                if (filetype($dir . '/' . $object) === 'dir') {
                    rrmdir($dir . '/' . $object);
                } else {
                    unlink($dir . '/' . $object);
                }
            }
        }
        rmdir($dir);
    }
}

//#######################################################################
//#### SCRIPT ###########################################################
//#######################################################################
function getScriptFilename() {
    return basename(__FILE__); // install.php
}
function getScriptBaseURL() {
    return str_replace('/' . getScriptFilename(), '', (connection_has_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']);
}
function getPHPVersion() {
    return PHP_VERSION;
}
function getPHPVersionShort() {
    return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
}
function checkPHPVersion() {
    return version_compare(PHP_VERSION, '7.4') >= 0;
}
function getMissingPHPExtensions() {
	$required_extensions = ['date', 'fileinfo', 'hash', 'json', 'mbstring', 'mysqli', 'pcre', 'openssl']; // 'curl', 'zip', 'gd', 'imagick'
	$available_extensions = get_loaded_extensions();
	$missing_extensions = array_diff($required_extensions, $available_extensions);
	
	// gd or imagick must have JPEG/WebP (optional) support
	$imageprocessing_formats = false;
	if(in_array('gd', $available_extensions) && checkImageProcessingGD(false) === true){
		$imageprocessing_formats = true;
	}
	if(in_array('imagick', $available_extensions) && checkImageProcessingImagick(false) === true){
		$imageprocessing_formats = true;
	}
	if($imageprocessing_formats === false){
		array_push($missing_extensions, 'gd or imagick');
	}
	
	return $missing_extensions;
}
function getMissingPHPExtensionsForInstall() {
	$required_extensions = ['curl', 'zip'];
	$available_extensions = get_loaded_extensions();
	$missing_extensions = array_diff($required_extensions, $available_extensions);

	return $missing_extensions;
}
function checkPHPExtensions() {
	return count(getMissingPHPExtensions()) === 0;
}
function isCurlAvailable(){
	return function_exists('curl_version');
}
function getIncorrectPHPSettings() {
	$incorrect_settings = [];
	
	//if(!ini_get('allow_url_fopen')) {
	//	array_push($incorrect_settings, "'allow_url_fopen' must be on");
	//}

	return $incorrect_settings;
}
function checkPHPSettings() {
	return count(getIncorrectPHPSettings()) === 0;
}
function checkImageProcessing($require_webp = true) {
    return checkImageProcessingImagick($require_webp) || checkImageProcessingGD($require_webp);
}
function checkImageProcessingGD($require_webp = true) {
    return function_exists('gd_info') && gd_info()['JPEG Support'] && (gd_info()['WebP Support'] || !$require_webp);
}
function checkImageProcessingImagick($require_webp = true) {
    return class_exists('Imagick') && count(Imagick::queryformats('JPEG')) > 0 && (count(Imagick::queryformats('WEBP')) > 0 || !$require_webp);
}
function printImageProcessingLibrarys() {
    if (checkImageProcessing(false) === false) {
        $class_error = 'error';
    } else {
        $class_error = '';
    }

    $librarys = [];
    if (class_exists("Imagick")) {
        $class = checkImageProcessingImagick() ? 'success' : $class_error;
        $formats = [];
        if (class_exists('Imagick')) {
            $version = str_replace('ImageMagick ', '', Imagick::getVersion()['versionString']);
            $version = explode(' ', $version)[0];
            if (count(Imagick::queryformats('JPEG')) > 0) {
                array_push($formats, 'JPEG');
            }
            if (count(Imagick::queryformats('WEBP')) > 0) {
                array_push($formats, 'WebP');
            }
        }
        array_push($librarys, '<span class="textsmall ' . $class . '"><b>ImageMagick ' . $version . '</b> ' . (count($formats) > 0 ? '(' . implode(', ', $formats) . ')' : '') . '</span>');
    }
    if (function_exists('gd_info')) {
        $class = checkImageProcessingGD() ? 'success' : $class_error;
        $version = gd_info()['GD Version'];
        $formats = [];
		if (gd_info()['JPEG Support']) {
			array_push($formats, 'JPEG');
		}
		if (gd_info()['WebP Support']) {
			array_push($formats, 'WebP');
		}

        array_push($librarys, '<span class="textsmall ' . $class . '"><b>GD ' . $version . '</b> ' . (count($formats) > 0 ? '(' . implode(', ', $formats) . ')' : '') . '</span>');
    }

    if (count($librarys) > 0) {
        $result = '<br/> ' . implode(',<br/> ', $librarys);
    } else {
        $result = '<b class="textsmall ' . $class_error . '">none</b>';
    }
    return $result;
}
function checkHTTPS() {
	//return true;
    return connection_has_ssl();
}
function checkInstallFilename() {
    return getScriptFilename() === "install.php";
}
function checkKokenSubfolder() {
	$ftp_has_koken_parentfolder = str_ends_with(__DIR__, "/koken");
	$url_has_koken_folder = str_ends_with(getScriptBaseURL(), "/koken");
	
	$ftp_koken_parentfolder_has_htaccess = false;
	if($ftp_has_koken_parentfolder === true){
		$ftp_koken_parentfolder_has_htaccess = file_exists(__DIR__ . "/../.htaccess");
	}

	return !($ftp_has_koken_parentfolder && $ftp_koken_parentfolder_has_htaccess && !$url_has_koken_folder);
}
function checkInstallPath() {
	return is_dir(__DIR__) === true && scandir(__DIR__) !== false;
}
function checkInstallFolder() {
    // return count(array_diff(scandir(__DIR__), ['.', '..', 'install.php', 'dist.zip', 'cgi-bin', '_koken'])) === 0;
	return count(array_intersect(scandir(__DIR__), CMS_RESERVED_FILES_FOLDERS)) === 0;
}
function checkInstallFile() {
    return file_exists(__DIR__ . "/dist.zip");
}
/*Installation*/
function InstallCheck($DATA) {
    $DatabaseConnection = new DatabaseConnection($DATA['databasesettings']['db_hostname'], $DATA['databasesettings']['db_username'], $DATA['databasesettings']['db_password'], $DATA['databasesettings']['db_database']);

	// Database
    if (ErrorInfo::isError($DatabaseConnection->STATUS())) {
        return new ErrorInfo('', 'No database connection!');
    } else {
        $required_cms_tables = CMS_TABLES;
        $available_cms_tables = [];
        foreach ($required_cms_tables as $table) {
            if (ErrorInfo::isError($DatabaseConnection->QUERY("DESCRIBE `{$table}`")) === false) {
                array_push($available_cms_tables, $table);
            }
        }
        if (count($available_cms_tables) > 0) {
            return new ErrorInfo('', "CMS database tables already existing (<i>" . implode(', ', $available_cms_tables) . "</i>).<br/><b>Please delete the tables from your database before continuing (press F5)!</b>");
        }
    }

	// Files	
	$test_file = fopen(__DIR__ . '/test.json', 'w');
	if($test_file === false){
		return new ErrorInfo('', "Cannot open files. Try to assign access permissions (chmod) to install.php file and the folder that contains it or perform the manual installation!");
	}
    $result = fwrite($test_file, json_encode(['test' => 'test']));
	if($result === false){
		return new ErrorInfo('', "Cannot write files. Try to assign access permissions (chmod) to install.php file and the folder that contains it or perform the manual installation!");
	}else{
		fclose($test_file);
	}
	if (file_exists(__DIR__ . '/test.json')) {
		$result = unlink(__DIR__ . '/test.json');
		if($result === false){
			return new ErrorInfo('', "Cannot delete files. Try to assign access permissions (chmod) to install.php file and the folder that contains it or perform the manual installation!");
		}
	}
	
    return true;
}
function InstallSystem($DATA) {
	// set memory limit
	$parseStringBytes = function ($size_string) {
		$unit = preg_replace('/[^bkmgtpezy]/i', '', $size_string);
		$size = preg_replace('/[^0-9\.]/', '', $size_string);
		if (!empty($unit)) {
			return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
		} else {
			return round($size);
		}
	};
	if (function_exists('ini_get') && function_exists('ini_set')) {
		$memorylimit_available = ini_get('memory_limit');
		$memorylimit_required = '128M';
		if ($parseStringBytes($memorylimit_required) > $parseStringBytes($memorylimit_available)) {
			ini_set('memory_limit', "{$memorylimit_required}");
		}
	}	
	
    // download dist.zip
    if (!file_exists(__DIR__ . '/dist.zip')) {
		if(isCurlAvailable()){
			$fh = fopen(__DIR__ . '/dist.zip', 'w');
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json; charset=UTF-8'));
			curl_setopt($ch, CURLOPT_URL, ENDPOINT_URL . 'download:distribution?install');
			curl_setopt($ch, CURLOPT_FILE, $fh);
			curl_setopt($ch, CURLOPT_TIMEOUT, 20);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
			curl_exec($ch);
			$response_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			curl_close($ch);
			fclose($fh);

			if ($response_code === 200) {
				clearstatcache();
				if (!filesize(__DIR__ . "/dist.zip")) {
					if (file_exists(__DIR__ . '/dist.zip')) {
						unlink(__DIR__ . '/dist.zip');
					}
					return new ErrorInfo('', 'System install error (cannot save dist.zip)!<br><a href="' . ENDPOINT_URL . 'download:distribution" target="_blank">Download the IO200 Distribution (click here)</a> and upload the file (dist.zip) to the same directory as your installation file (install.php). Then, start this automatic installation again.');
				}
			} else {
				if (file_exists(__DIR__ . '/dist.zip')) {
					unlink(__DIR__ . "/dist.zip");
				}
				return new ErrorInfo('', 'System install error (cannot download dist.zip)!<br><a href="' . ENDPOINT_URL . 'download:distribution" target="_blank">Download the IO200 Distribution (click here)</a> and upload the file (dist.zip) to the same directory as your installation file (install.php). Then, start this automatic installation again.');
			}
		} else {
				return new ErrorInfo('', 'System install error (curl is not available)!<br><a href="' . ENDPOINT_URL . 'download:distribution" target="_blank">Download the IO200 Distribution (click here)</a> and upload the file (dist.zip) to the same directory as your installation file (install.php). Then, start this automatic installation again.');
		}
    }

    // extract files    
    if (file_exists(__DIR__ . '/dist.zip')) {
        $zip = new ZipArchive;
        if ($zip->open(__DIR__ . '/dist.zip') === true) {
            $zip->extractTo(__DIR__);
            $zip->close();

            if (file_exists(__DIR__ . '/.htaccess')) { unlink(__DIR__ . '/.htaccess'); }
            xcopy(__DIR__ . '/system-distribution', __DIR__);
            rrmdir(__DIR__ . '/system-distribution');
            unlink(__DIR__ . '/dist.zip');
        } else {
            return new ErrorInfo('', 'System install error (cannot extract dist.zip)!<br> Perform the manual installation as described in the documentation.');
        }
    } else {
        return new ErrorInfo('', 'System install error (missing dist.zip)!<br><a href="' . ENDPOINT_URL . 'download:distribution" target="_blank">Download the IO200 Distribution (click here)</a> and upload the file (dist.zip) to the same directory as your installation file (install.php). Then, start this automatic installation again.');
    }

    // database
    if (file_exists(__DIR__ . '/storage/temp/cms_db_schema.sql')) {
        $DatabaseConnection = new DatabaseConnection($DATA['databasesettings']['db_hostname'], $DATA['databasesettings']['db_username'], $DATA['databasesettings']['db_password'], $DATA['databasesettings']['db_database']);
        $DatabaseConnection->MULTIQUERY(file_get_contents(__DIR__ . '/storage/temp/cms_db_schema.sql'));
        unlink(__DIR__ . '/storage/temp/cms_db_schema.sql');
        if ($DATA['_migratekoken'] === false) {
            unlink(__DIR__ . '/storage/temp/cms_koken_migration.sql');
        }
        return true;
    } else {
        return new ErrorInfo('system_error', 'System install error (database)!<br> Perform the manual installation as described in the documentation.');
    }
}
function ConfigurateSystem($DATA) {
    // /storage/system/config.php
    if (file_exists(__DIR__ . '/storage/system/config.php')) {
        $new_config = file_get_contents(__DIR__ . '/storage/system/config.php');
        $new_config = str_replace("define('CMS_DB_HOSTNAME', '???');", "define('CMS_DB_HOSTNAME', '" . $DATA['databasesettings']['db_hostname'] . "');", $new_config);
        $new_config = str_replace("define('CMS_DB_USERNAME', '???');", "define('CMS_DB_USERNAME', '" . $DATA['databasesettings']['db_username'] . "');", $new_config);
        $new_config = str_replace("define('CMS_DB_PASSWORD', '???');", "define('CMS_DB_PASSWORD', '" . $DATA['databasesettings']['db_password'] . "');", $new_config);
        $new_config = str_replace("define('CMS_DB_DATABASE', '???');", "define('CMS_DB_DATABASE', '" . $DATA['databasesettings']['db_database'] . "');", $new_config);
        $new_config = str_replace("define('CMS_SECRETKEY', '???');", "define('CMS_SECRETKEY', '" . base64_encode(random_bytes(32)) . "');", $new_config);
        $new_config = str_replace("define('WEBSITE_SECRETKEY', '???');", "define('WEBSITE_SECRETKEY', '" .base64_encode(random_bytes(32)) . "');", $new_config);
        $new_config = str_replace("define('WEBSITE_URL', '???');", "define('WEBSITE_URL', '" . $DATA['websitesettings']['url'] . "');", $new_config);
		if($DATA['_migratekoken'] === true){
			$new_config = str_replace("define('CMS_ORIGINAL_IMAGE_SUBFOLDERDEPTH', 3);", "define('CMS_ORIGINAL_IMAGE_SUBFOLDERDEPTH', 2);", $new_config);
			$new_config = str_replace("define('CMS_ORIGINAL_IMAGE_SUBFOLDERDEPTH', 4);", "define('CMS_ORIGINAL_IMAGE_SUBFOLDERDEPTH', 2);", $new_config);
			$new_config = str_replace("define('CMS_ORIGINAL_IMAGE_SECRETFOLDERLENGTH', 20);", "define('CMS_ORIGINAL_IMAGE_SECRETFOLDERLENGTH', 0);", $new_config);
		}
		
        $config_file = fopen(__DIR__ . '/storage/system/config.php', 'w');
        $result = fwrite($config_file, $new_config);
        fclose($config_file);
        if ($result === false) {
            return new ErrorInfo('', 'Configuration error (no permissions to write config.php)!<br> Perform the manual installation as described in the documentation.');
        }
    } else {
        return new ErrorInfo('', 'Configuration error (missing config.php)!<br> Perform the manual installation as described in the documentation.');
    }

    // /storage/system/service.json
    $SERVICE = [];
    $SERVICE['service_id'] = null;
    $SERVICE['endpoint_url'] = ENDPOINT_URL;
    $service_file = fopen(__DIR__ . '/storage/system/service.json', 'w');
    $result = fwrite($service_file, json_encode($SERVICE));
    fclose($service_file);

    // /storage/system/user.json
    $USER = [];
    $USER['mail'] = $DATA['adminsettings']['mail'];
    $USER['passwordhash'] = password_hash($DATA['adminsettings']['password'], PASSWORD_DEFAULT);
    $USER['locked'] = false;
    $USER['resetpasswordhash'] = null;
    $USER['autologinhash'] = null;
    $USER['numberauthenticationattempts'] = 0;
    $USER['login_on'] = null;
    $user_file = fopen(__DIR__ . "/storage/system/user.json", 'w');
    $result = fwrite($user_file, json_encode($USER));
    fclose($user_file);

    // /storage/system/sitesettings.json
    $SITESETTINGS = [];
    $SITESETTINGS['WEBSITE_TITLE'] = $DATA['websitesettings']['title'];
    $SITESETTINGS['WEBSITE_MAIL'] = $DATA['adminsettings']['mail'];
    $SITESETTINGS['THEME'] = THEME;
	if(checkImageProcessing(true) === false){
		  $SITESETTINGS['WEBSITE_CACHE_THUMBS'] = ['mimetype' => 'image/jpeg', 'sizes' => [48, 192, 624, 912, 1296, 1680, 2016, 2832], 'quality' => 75];
	}
    $settings_file = fopen(__DIR__ . "/storage/system/sitesettings.json", 'w');
    $result = fwrite($settings_file, json_encode($SITESETTINGS));
    fclose($settings_file);

    // .htaccess
    $url_parts = parse_url($DATA['websitesettings']['url']);
    if (array_key_exists('path', $url_parts)) {
        $basepath = $url_parts['path'];
    } else {
        $basepath = '';
    }
    if ($basepath !== '') {
        if (file_exists(__DIR__ . '/.htaccess')) {
            $new_htaccess = file_get_contents(__DIR__ . '/.htaccess');
            $new_htaccess = str_replace("RewriteBase /", "RewriteBase {$basepath}/", $new_htaccess);

            $htaccess_file = fopen(__DIR__ . '/.htaccess', 'w');
            fwrite($htaccess_file, $new_htaccess);
            fclose($htaccess_file);
        } else {
            return new ErrorInfo('', 'Theme configuration error!<br> Perform the manual installation as described in the documentation.');
        }
    }

    return true;
}
/*Migration*/
function DetectKokenInstallation($koken_directory) {
    // check folders/files
    $required_files = ['/admin', '/app', '/storage', '/storage/originals', '/storage/configuration', '/storage/configuration/database.php'];
    foreach ($required_files as $file) {
        if (!file_exists($koken_directory . $file)) {
            return new ErrorInfo('', 'Missing Koken ' . (str_contains($file, '.') ? 'file' : 'directory') . ' ("' . $koken_directory . $file . '")!');
        }
    }

    // check database connection and koken tables
    $KOKEN_DB_SETTINGS = GetKokenDatabaseSettings($koken_directory);
    if (ErrorInfo::isError($KOKEN_DB_SETTINGS)) {
        return $KOKEN_DB_SETTINGS;
    } else {
        $DatabaseConnection = new DatabaseConnection($KOKEN_DB_SETTINGS['hostname'], $KOKEN_DB_SETTINGS['username'], $KOKEN_DB_SETTINGS['password'], $KOKEN_DB_SETTINGS['database']);
        if (ErrorInfo::isError($DatabaseConnection->STATUS())) {
            return new ErrorInfo('', 'Error connecting to Koken database!<br/>Check your Koken database settings in <span style="word-break:break-all;">"' . $koken_directory . '/storage/configuration/database.php"</span>.<br/><br/><b>Error Number:</b> ' . $DatabaseConnection->STATUS()->data . '<br/><b>Error Message:</b> ' . $DatabaseConnection->STATUS()->message);
        } else {
            $required_koken_tables = ['text', 'join_categories_text', 'join_tags_text', 'categories', 'albums', 'join_albums_covers', 'join_albums_content', 'content', 'join_categories_content', 'join_content_tags', 'tags'];
            foreach ($required_koken_tables as $table) {
                if (ErrorInfo::isError($DatabaseConnection->QUERY("DESCRIBE `{$KOKEN_DB_SETTINGS['prefix']}{$table}`"))) {
                    return new ErrorInfo('cms_service_error', "Missing Koken table (\"{$KOKEN_DB_SETTINGS['prefix']}{$table}\")!");
                }
            }
        }
    }

    return true;
}
function GetKokenDatabaseSettings($koken_directory) { // returns ErrorInfo or array with 'driver', 'hostname', 'database', 'username', 'password', 'prefix', 'socket'
    $database_configuration_file = $koken_directory . '/storage/configuration/database.php';
    if (file_exists($database_configuration_file)) {
        require($database_configuration_file);
        if (!isset($KOKEN_DATABASE)) {
            $KOKEN_DATABASE = require($database_configuration_file);
        }
        return $KOKEN_DATABASE;
    } else {
        return new ErrorInfo('', 'Missing Koken database configuration file!');
    }
}
function MoveKokenToSubfolder($koken_directory) {
    if (!file_exists($koken_directory . '/_koken')) {
        mkdir($koken_directory . '/_koken');
    }
    foreach (scandir($koken_directory) as $object) {
        if (!in_array($object, ['.', '..', getScriptFilename(), 'install.php', 'dist.zip', '_koken'])) {
            rename($koken_directory . '/' . $object, $koken_directory . '/_koken/' . $object);
        }
    }
}
function CopyKokenDatabase($KOKEN_DATABASE) {
    if (file_exists(__DIR__ . '/storage/temp/cms_koken_migration.sql')) {
        $script_file_content = file_get_contents(__DIR__ . '/storage/temp/cms_koken_migration.sql');
        $script_file_content = str_replace('koken_', $KOKEN_DATABASE['prefix'], $script_file_content);

        $DatabaseConnection = new DatabaseConnection($KOKEN_DATABASE['hostname'], $KOKEN_DATABASE['username'], $KOKEN_DATABASE['password'], $KOKEN_DATABASE['database']);
        $DatabaseConnection->MULTIQUERY($script_file_content);

        unlink(__DIR__ . '/storage/temp/cms_koken_migration.sql');

        return true;
    } else {
        return new ErrorInfo('system_error', 'Koken Database Migration Error');
    }
}
function CopyKokenOriginals($src, $dest) {
    //$src => koken_photo_directory, $dest => target_photo_directory

    foreach (scandir($src) as $object) {
        if (!in_array($object, ['.', '..'])) {
            if (is_dir($src . '/' . $object)) {
                if (!file_exists($dest . '/' . $object)) {
                    mkdir($dest . '/' . $object);
                }
                CopyKokenOriginals($src . '/' . $object, $dest . '/' . $object);
            } else {
                if (substr_count($object, '.') === 1) {
                    if (!file_exists($dest . '/' . mb_strtolower($object))) {
                        copy($src . '/' . $object, $dest . '/' . mb_strtolower($object));
                    }
                }
            }
        }
    }
}
function CopyKokenCustom($src, $dest) {
    xcopy($src, $dest);
}
function FixNestedCollectionStructure($KOKEN_DATABASE) {
    $DatabaseConnection = new DatabaseConnection($KOKEN_DATABASE['hostname'], $KOKEN_DATABASE['username'], $KOKEN_DATABASE['password'], $KOKEN_DATABASE['database']);

    $OBJECTS = $DatabaseConnection->SELECT('cms_collections', array('id', 'level', 'left_id', 'right_id', 'type'), null, 'left_id ASC');
    if (ErrorInfo::isError($OBJECTS)) {
        return $OBJECTS;
    }
    // Load and fix nested collection structure
    $structure_check = true;
    $top_level = 1;
    $RELATIONS = array();
    if (!empty($OBJECTS)) {
        foreach ($OBJECTS as $OBJECT) {
            // find parent
            $PARENT = &$RELATIONS;
            for ($l = $top_level; $l < $OBJECT['level']; $l++) {
                if (!is_array($PARENT)) {
                    break;
                } // corrupted structure
                $PARENT = &$PARENT[array_key_last($PARENT)];
            }
            if (!is_array($PARENT)) {
                $structure_check = false; // corrupted structure
                array_unshift($RELATIONS, $OBJECT['id']);
            } else {
                // add set/album
                if ($OBJECT['type'] === 2) { //set
                    array_push($PARENT, array($OBJECT['id']));
                } else { //album
                    array_push($PARENT, $OBJECT['id']);
                }
            }
        }
    }

    // Fix nested collection structure
    if ($structure_check === false) {
        $sql_fix = 'UPDATE `cms_collections` SET `level`=1;
        UPDATE `cms_collections` SET `total_count`=0 WHERE `type`=2;
        SET @i:=-1;
        SET @j:=0;
        UPDATE `cms_collections` SET `left_id`= @i:=(@i+2), `right_id`= @j:=(@j+2);';
        $DatabaseConnection->MULTIQUERY($sql_fix);
    }
    return true;
}
function FixKokenArticlesAndPages($KOKEN_DATABASE) {
    $DatabaseConnection = new DatabaseConnection($KOKEN_DATABASE['hostname'], $KOKEN_DATABASE['username'], $KOKEN_DATABASE['password'], $KOKEN_DATABASE['database']);

    $ARTICLES = $DatabaseConnection->QUERY2ARRAY("SELECT id, slug FROM cms_articles");
	foreach ($ARTICLES as $ARTICLE) {
		$content = $DatabaseConnection->GET("cms_articles", "content", 'id=' . $DatabaseConnection->ValueToEscapedString($ARTICLE['id']));
		$content = preg_replace('/\[PHOTO label="([^"]*)"/', '[PHOTO', $content);
		$content = preg_replace('/link="album" album="/', 'link="photosite" album="', $content);
		$content = preg_replace('/\[IMG filename="/', '[IMG src="/storage/custom/', $content);
		$DatabaseConnection->UPDATE('cms_articles', ['content' => $content], 'id=' . $DatabaseConnection->ValueToEscapedString($ARTICLE['id']));	
	}

    $PAGES = $DatabaseConnection->QUERY2ARRAY("SELECT id, slug FROM cms_pages");
	foreach ($PAGES as $PAGE) {
		$content = $DatabaseConnection->GET("cms_pages", "content", 'id=' . $DatabaseConnection->ValueToEscapedString($PAGE['id']));
		$content = preg_replace('/\[PHOTO label="([^"]*)"/', '[PHOTO', $content);
		$content = preg_replace('/link="album" album="/', 'link="photosite" album="', $content);
		$content = preg_replace('/\[IMG filename="/', '[IMG src="/storage/custom/', $content);
		$DatabaseConnection->UPDATE('cms_pages', ['content' => $content], 'id=' . $DatabaseConnection->ValueToEscapedString($PAGE['id']));	
	}
}
function FixPhotos($listener_url) {
	if(isCurlAvailable()){
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
		curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
		curl_setopt($ch, CURLOPT_TIMEOUT, 120);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		if (defined('CURL_SSLVERSION_MAX_DEFAULT')) {
			curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_MAX_DEFAULT);
		} elseif (defined('CURL_SSLVERSION_TLSv1_2')) {
			curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
		}
		curl_setopt($ch, CURLOPT_URL, $listener_url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_exec($ch);
		curl_close($ch);
		return true;
	}else{
		return false;
	}	
}


//#### PHP-INFO #####################################################
if (isset($_GET['phpinfo'])) {
    phpinfo();
	exit;
}

//#### START ########################################################
$DATA = [];
$DATA['_step'] = null;
$DATA['_migratekoken'] = null;
$DATA['systemcheck'] = null;
$DATA['databasesettings']  = null;
$DATA['adminsettings'] = null;
$DATA['websitesettings'] = null;
$DATA['install'] = null;
if (isset($_POST['data_transfer'])) { // load passed data
    $data_json = json_decode($_POST['data_transfer'], true);
    if ($data_json !== null) {
        foreach ($data_json as $key => $value) {
            if (is_array($value) && array_key_exists('type', $value)  && array_key_exists('message', $value) && array_key_exists('data', $value)) {
                $DATA[$key] = new ErrorInfo($value['type'], $value['message'], $value['data']);
            } else {
                $DATA[$key] = $value;
            }
        }
    }
}

$DATA['_step'] = 0;
if (!($DATA['systemcheck'] === null || ErrorInfo::isError($DATA['systemcheck']))) {
    $DATA['_step'] = 1;
    if (!($DATA['databasesettings'] === null || ErrorInfo::isError($DATA['databasesettings']))) {
        $DATA['_step'] = 2;
        if (!($DATA['adminsettings'] === null || ErrorInfo::isError($DATA['adminsettings']))) {
            $DATA['_step'] = 3;
            if (!($DATA['websitesettings'] === null || ErrorInfo::isError($DATA['websitesettings']))) {
                $DATA['_step'] = 4;
            }
        }
    }
}

if ($DATA['_step'] === 4 && $DATA['install'] === null) {
    $DATA['install'] = InstallSystem($DATA);
    if (ErrorInfo::isError($DATA['install'])) {
        echo '<p class="error" style="font-size:16px;"><strong>System Installation Error</strong></p>';
        echo '<p class="error" style="font-size:14px;">' . $DATA['install']->getErrorMessage() . '</p>';
        exit;
    }
    $DATA['_configurate'] = true;
}

if (($DATA['_step'] === 4 && !ErrorInfo::isError($DATA['install'])) || $DATA['_configurate'] === true) {
    $RESULT = ConfigurateSystem($DATA);
    if (ErrorInfo::isError($RESULT)) {
        echo '<p class="error" style="font-size:16px;"><strong>Configuration Error</strong></p>';
        echo '<p class="error" style="font-size:14px;">' . $RESULT->getErrorMessage() . '</p>';
        exit;
    }
}
