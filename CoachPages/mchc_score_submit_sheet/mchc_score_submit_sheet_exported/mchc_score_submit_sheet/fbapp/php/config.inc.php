<?php

error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
$errorLoggingType = 3;			// writeErrorLog sends output to a file in the storage folder (default behavior)
//$errorLoggingType = 0;		// writeErrorLog sends output to the server log

set_include_path( $scriptpath . PATH_SEPARATOR . get_include_path() );

// get the S-Drive configuration if it exists
@include_once 'SdriveConfig.php';


// initialize the singleton with the form-name
// the global $myPage is used in older code, don't remove it! 
$myPage = FormPage::GetInstance( $myName );

if( isset( $sdrive_config ) ) {

	$errorLoggingType = 0;		// better send writeErrorLog output to the server log
	
	// $myPage->sdrive is initialized to false
	Config::GetInstance()->LoadConfig( $sdrive_config );
	
	if( isset( $sdrive_model ) )
		Config::GetInstance()->sdrive_model = $sdrive_model;

	// on S-Drive the cart scripts are taken directly from the resources
	$buildnum = Config::GetInstance()->GetConfig( 'resource_version' );
	if( ! $buildnum )	writeErrorLog( 'Parameter missing or empty in form.cfg.dat', 'resource_version' );
	$cartpath = CC_HOSTING_RESOURCES . DIRECTORY_SEPARATOR .'FB' . DIRECTORY_SEPARATOR . $buildnum . DIRECTORY_SEPARATOR . 'fb';

	set_include_path( get_include_path() . PATH_SEPARATOR . $cartpath );
	
	// add this constant to the file names to include instead of adding it to the include path
	// as a type of name spacing
	define( 'CARTREVISION', 'cartapp' );

} else {

	// A version number is added to the folder name for forward compatibility. FB increments this
	// number if changes are NOT backward compatible. FB must also create the corrresponding 
	// folder (leaving the old folder for forms made and uploaded with a previous version).
	define( 'CARTREVISION', 'cartapp_v1' );
	Config::GetInstance()->LoadConfig();
}



// catch warnings with our own error handler to ignore them as appropriate
set_error_handler( 'myErrorHandler', E_WARNING ) ;

/*** end of global config ***/








/*********** utility functions ************/

// define our auto-loader for classes
function __autoload( $class_name )
{
	global $scriptpath;
    include $scriptpath . '/fbapp/php/' . strtolower( $class_name ) . '.cls.php';
}


// shows warning more user-friendly
function myErrorHandler( $errno, $errstr, $errfile, $errline ) {

	// some fopen() may fail because the files are optional
	if( strpos( $errstr, 'fopen') !== false )		return false;

	$page = FormPage::GetInstance();

	// the rules may contain an invalid regexp
	if( strpos( $errstr, 'preg_match' ) !== false &&
		strpos( $errfile, 'validator.inc.php' ) !== false ) {

		$msg = _T('Error validating RegEx magic field') . substr( $errstr , strrpos( $errstr, ':') );
			
	} else {
	
		// default message
		$msg = 'Warning: [ err ' . $errno . '/line '. $errline . substr( $errfile, strrpos( $errfile, '/' ) ) . '] ' . $errstr;
	}

	if( $page ) {
		$page->SetErrors( array( array( 'warn' => $msg ) ) );		
	} else {
		$errors = array( 'warn' => $msg );
		include 'fbapp/inc/displayerrors.inc.php';
		exit(0);
	}

	return true;
}

?>