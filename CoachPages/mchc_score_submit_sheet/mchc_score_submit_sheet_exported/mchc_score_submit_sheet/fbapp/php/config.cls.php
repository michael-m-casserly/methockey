<?php
/**
 * CoffeeCup Software's Web Form Builder.
 *
 * Configuration Singleton
 *
 *
 * @version $Revision: 2456 $
 * @author Cees de Gruijter
 * @category FB
 * @copyright Copyright (c) 2012 CoffeeCup Software, Inc. (http://www.coffeecup.com/)
 */


define( 'CONFIG_FILE', 'form.cfg.php');
define( 'CONFIG_FILE_SDRIVE', 'form.cfg.dat');

define( 'CC_FB_STORAGE_FOLDER', '/storage/' );		// where all data is stored
define( 'CC_FB_PREFIX', 'fb_' );
define( 'CC_FB_UPLOADS_DIRECTORY', 'files/' );		// where files are uploaded, need not be publicly visible
define( 'CC_FB_UPLOADS_TEMPDIR', 'files/temp/' );	// where files are uploaded temporarily
define( 'CC_FB_PUBLIC_DIRECTORY', 'public/' );		// where uploaded files are copied to for public visibility
define( 'CC_FB_DB_DIRECTORY', 'db/' );				// where sqlite stores its files
define( 'CC_FB_CSV_DIRECTORY', 'csv/' );			// where csv files are stored

define( 'CC_FB_TEMPUPLOADS', 'tempuploads' );

define( 'CC_FB_EMBEDDED', 'fb_form_embedded');		// state flags coming form hidden form fields
define( 'CC_FB_CUSTOMHTML', 'fb_form_custom_html' );
define( 'CC_FB_JSENABLED', 'fb_js_enable' );
define( 'CC_FB_URLEMBEDDED', 'fb_url_embedded' );

class Config {

	private static $instance = null;	// me...for singleton
	private $config = false;			// form configuration
	private $user_timezone = null;		// user specified timezone
 	private $submit_count = null;
 	private $max_submits = null; 

	public $postkeymap = array();		// relationship lower_cased_post_key <=> original_post_key
	public $sdrive = false;				// sdrive configuration
	public $sdrive_model = null;		// sdrive model from SdriveConfig.php with the essentials for the form scripts

	private function __construct ( ) {
	}


	public static function GetInstance ( ) {

		if( ! isset( self::$instance ) ) {

			$className = __CLASS__;
			self::$instance = new $className();
		}

		return self::$instance;
	}


	// return the required section or the whole config if section is not given.
	// method accepts variable length argument list
	function GetConfig ( ) {

		if( ! func_num_args() )	 						return $this->config;

		$args = func_get_args();

		$cfg = $this->config;
		foreach( $args as $arg ) {

			if( ! isset( $cfg->$arg ) )					return false;
			$cfg = $cfg->$arg;
		}

		return $cfg == null ? false : $cfg;
	}


	public function GetFieldNames ( ) {
		
		return array_keys( get_object_vars( $this->config->rules ) );
	}


	// get a validation rule property by field name
	// return false if the rule or property isn't found
	function GetRulePropertyByName ( $fieldname, $property ) {

		// rules have lower cased keys
		$fieldname = strtolower( $fieldname );

		if( isset( $this->config->rules->$fieldname ) &&
			isset( $this->config->rules->$fieldname->$property ) )
		{
			return $this->config->rules->$fieldname->$property;
		}
		
		return false;
	}


	public function UsePayments ( ) {

		return $this->GetConfig( 'settings', 'payment_settings', 'is_present' );
	}


	public function isOverSubmitLimit ( )
	{
		// limits are only enforced on sdrive
		// added method_exists() test in case FB scripts are updated before the server scripts.
		return $this->sdrive_model && method_exists( $this->sdrive_model, 'isOverSubmitLimit' ) ? $this->sdrive_model->isOverSubmitLimit() : false;
	}


	public function getSubmitLimitUpgradeDate ( )
	{
		// limits are only enforced on sdrive
		// added method_exists() test in case FB scripts are updated before the server scripts.
		return $this->sdrive_model && method_exists( $this->sdrive_model, 'getSubmitLimitUpgradeDate' ) ? $this->sdrive_model->getSubmitLimitUpgradeDate() : false;
	}


	// Forms that are exported with automatic form processing can't reset their session
	// on our server, thus before storing data in the session, this test might be handy
	public function isAutoEmbedded ( )
	{
		return isset( $_POST[ 'fb_form_custom_html' ] );
	}


	// return path to file system storage location
	public function GetStorageFolder ( $which ) {

		global $scriptpath;

		if( $this->sdrive ) {

			$storage = $this->sdrive[ 'sdrive_account_datastore_path' ] . DIRECTORY_SEPARATOR . CC_FB_PREFIX;

		} else {
	
			$storage = $scriptpath . CC_FB_STORAGE_FOLDER;
		}

		switch ( $which ) {
			case 1:				//uploaded files
				return $storage . CC_FB_UPLOADS_DIRECTORY;
			
			case 2:				// database location
				return $storage . CC_FB_DB_DIRECTORY;

			case 3:				// csv location
				return $storage . CC_FB_CSV_DIRECTORY;

			case 4:				// publicly visible uploads
				return $storage . CC_FB_PUBLIC_DIRECTORY;

			case 5:				// temporary storage for uploaded files
				return $storage . CC_FB_UPLOADS_TEMPDIR;

			default:
				writeErrorLog( 'Storage folder ID is not defined:', $which );
		}
		return false;		
	}

	
	public function ApplyUserTimezone ( $value )
	{
		if( ! $this->user_timezone )		return $value;

		$recorded_time = new DateTime( $value );
		$offset = $this->user_timezone->getOffset( $recorded_time );

		return date( 'Y-m-d H:i:s', $recorded_time->format('U') + $offset );
	}


	public function MakeUTC ( $value )
	{
		if( ! $this->user_timezone )		return $value;

		$user_time = new DateTime( $value, $this->user_timezone );
		$utc_timezone = new DateTimeZone('UTC');
		$offset = $utc_timezone->getOffset( $user_time );

		return date( 'Y-m-d H:i:s', $user_time->format('U') + $offset );
	}


	// used by the storage engines to create additional columns
	function GetReservedFieldTypes ( ) {

		// note: don't change the order in the array, because the CSV file goes by position!
		return array( '_submitted_' => 'datetime',		// format: YYYY-MM-DD HH:MM:SS
					  '_fromaddress_' => 'ipaddress',
					  '_flags_'	=> 'number',
					  '_transactid_' => 'text' );
	}


	// used by the page for display purposes, exclude fields that are not for display
	function GetReservedFields ( ) {

		static $names = array();

		if( empty( $names ) ) {
			$names = array_diff( array_keys( $this->GetReservedFieldTypes() ), array( '_flags_' ) );
		}

		return $names;	
	}



	// return format string suitable for date() defined for a field, default to the iso format
	public function GetDateFormatByFieldname ( $fieldname ) {

		$date_formats = array(
			'US_SLASHED' => 'm/d/Y',
			'ISO_8601' => 'Y-m-d',
			'RFC_822' => 'D, j M y',
			'RFC_850' => 'l, d-M-y',
			'RFC_1036' => 'D, d M y',
			'RFC_1123' => 'D, j M Y',
			'COOKIE' => 'D, d M Y',
			'DATE_CUSTOM_1' => 'd/m/Y',
			'DATE_CUSTOM_2' => 'Y/m/d',
			'DATE_CUSTOM_3' => 'm-d-Y',
			'DATE_CUSTOM_4' => 'd-m-Y',
			'DATE_CUSTOM_5' => 'd.m.Y',		// 20.02.2012
			'DATE_CUSTOM_6' => 'd.m.y',		// 20.02.12
			'DATE_CUSTOM_7' => 'd:m:Y', 	// 20:02:2012
			'DATE_CUSTOM_8' => 'd.F y',		// 20.February 12
			'DATE_CUSTOM_9' => 'd.F'		// 20.February
			);


		if( isset( $this->config->rules->$fieldname ) && $this->config->rules->$fieldname->fieldtype == 'date' )
			return $date_formats[ $this->config->rules->$fieldname->date_config->dateFormat ];

		return $date_formats['ISO_8601'];
	}


	// return timestamp or false on failure
	function ParseDateStringOnFormatByFieldname ( $fieldname, $value ) {

		if( $this->GetConfig( 'rules', $fieldname, 'fieldtype' ) == 'date' )
			$format = $this->GetConfig( 'rules', $fieldname, 'date_config', 'dateFormat' );
		else
			return false;
		
		switch( $format ) {
			case 'US_SLASHED':
			case 'ISO_8601':
			case 'RFC_822':
			case 'RFC_850':
			case 'RFC_1036':
			case 'RFC_1123':
			case 'COOKIE':
				// formats that strtotime() understands
				return strtotime( $value );

			// formats that we need to parse, using named capture groups in pcre
			case 'DATE_CUSTOM_1':
				$pattern = '/^(\d+)\/(\d+)\/(\d+)$/';				/*'d/m/Y'*/ 
				$replacement = '$2/$1/$3';
				break;
			case 'DATE_CUSTOM_2':
				$pattern = '/^(\d+)\/(\d+)\/(\d+)$/'; 				/*'Y/m/d'*/ 
				$replacement = '$2/$3/$1';
				break;
			case 'DATE_CUSTOM_3':
				$pattern = '/-/';					 				/*'m-d-Y'*/
				$replacement = '/';
				break;
		 	case 'DATE_CUSTOM_4':
		 		$pattern = '/^(\d+)-(\d+)-(\d+)$/';			 		/*'d-m-Y'*/
				$replacement = '$2/$1/$3';
				break;
			case 'DATE_CUSTOM_5':									/*'dd.mm.yy', 20.02.2012 */
			case 'DATE_CUSTOM_7':									/*'dd:mm:yy', 20:02:2012 */
		 		$pattern = '/^(\d{2})[.:](\d{2})[.:](\d{4})$/';
				$replacement = '$2/$1/$3';
				break;
			case 'DATE_CUSTOM_6':
				$pattern = '/^(\d{2})\.(\d{2})\.(\d{2})$/';			/*'dd.mm.y', 20.02.12 */
				$replacement = '$2/$1/$3';
				break;
			case 'DATE_CUSTOM_8':
				$pattern = '/^(\d{1,2})\.([a-z]+)\w+(\d{2,4})$/i';	/*'dd.MM y', 20.February 12 */
				$replacement = '$1 $2 $3';
				break;
			case 'DATE_CUSTOM_9':
				$pattern = '/^(\d{1,2})\.([a-z]+)$/';				/*'dd.MM', 20.February */
				$replacement = '$1 $2';
				break;

			default:
				return false;
		}
		
		// strtotime should understand the US format now
		return strtotime( preg_replace( $pattern, $replacement, $value) );
	}


	///@brief Set the relation between lower-case keys and original _POST keys
	public function SetOriginalPostKeyMap ( $map )
	{
		$this->postkeymap = $map;
	}


	///@return the original _POST key of the form
	public function GetOriginalPostKey ( $key )
	{
		return isset( $this->postkeymap[ $key ] ) ? $this->postkeymap[ $key ] : $key;
	}


	// read form config from the json text file and
	public function LoadConfig ( $sdrive_config = null ) {
	
		// load sdrive first, because the setting is needed to load the rest.
		if( $sdrive_config ) {

			$this->sdrive =& $sdrive_config;

			if( isset( $this->sdrive[ 'sdrive_account_formbuilder_stats' ] ) &&
				! empty( $this->sdrive[ 'sdrive_account_formbuilder_stats' ] ) ) {

				FormPage::GetInstance()->SetStats( $this->sdrive );
			}
		}

		// always record times in UTC, apply time zones only when displaying
		date_default_timezone_set( 'UTC' );

		$txt = file_get_contents( ($this->sdrive ? CONFIG_FILE_SDRIVE : CONFIG_FILE) , FILE_USE_INCLUDE_PATH );

		if( $txt === false ) {
			writeErrorLog( 'Couldn\'t open or read:', CONFIG_FILE );
			echo '<html><body>Configuration missing.</body></html>';
			exit();
		}

		$this->config = json_decode( substr( $txt, strpos( $txt, "{" ) ) );

		if( $this->config == NULL )
		{
			FormPage::GetInstance()->SetErrors( array( array( 'err' => 'Failed to read or decode form configuration.' ) ) );
			writeErrorLog( 'Couldn\'t decode:', ($this->sdrive ? CONFIG_FILE_SDRIVE : CONFIG_FILE) );
			return false;
		}

		// move all settings that are not fields 1 level up 
		if( isset( $this->config->rules->_special ) )
		{
			$this->config->special = $this->config->rules->_special;
			unset( $this->config->rules->_special );
		}

		$tz = $this->GetConfig( 'settings', 'general_settings', 'timezone' );
		if( $tz )
		{
			try 
			{
				$this->user_timezone = new DateTimeZone( $tz );
			}
			catch( Exception $e )
			{
				writeErrorLog( 'Problem setting Timezone "' . $tz . '", error message:', $e->getMessage() );
				FormPage::GetInstance()->SetErrors( array( array( 'err' => 'Failed to set the timezone, check CoffeeCup FormBuilder\'s Settings->General tab for your timezone setting.' ) ) );
			}
		}

		#print_r( $this->config );
	}


	// start session and initialize with data
	public function InitSession ( $session_data = null ) {

		static $session_exists = false;

		if( ! $session_exists ) {
			$session_exists = session_start();
		}

		if( ! empty( $session_data ) )
			$_SESSION = array_merge( $_SESSION, $session_data );
	}


	public function ClearSession ( ) {

		$_SESSION = array();
	}


	public function SetSessionVariable ( $key, $value ) {
		
		$_SESSION[ $key ] = $value;
	}
	

	public function UnsetSessionVariable ( $key ) {

		if( isset( $_SESSION[ $key ] ) )	unset( $_SESSION[ $key ] );
		
	}


	public function SetSessionVariableFromPost ( $key ) {

		$this->InitSession();

		if( isset( $_POST[ $key ] ) )		$_SESSION[ $key ] = $_POST[ $key ];
		else								$this->UnsetSessionVariable( $key );
	}


	public function GetSessionVariable ( $key ) {

		return isset( $_SESSION[ $key ] ) ? $_SESSION[ $key ] : false;
	}

}


?>