<?php

/**
 * CoffeeCup Software's Web Form Builder.
 *
 * Some tools that are used throughtout the app.
 *
 *
 * @version $Revision: 2456 $
 * @author Cees de Gruijter
 * @category FB
 * @copyright Copyright (c) 2011 CoffeeCup Software, Inc. (http://www.coffeecup.com/)
 */

// load PHP4 compatibility functions (from the PEAR::PHP_Compat package) if needed
// 5.1 might not have the json extension installed!
if( strcmp( PHP_VERSION, '5.2' ) < 0 ) {
	include 'fbapp/inc/php4compat.inc.php';
}


// write to error log in storage folder
// $text1 or 2 are flattened if they are simple arrays.
// $text1 and $text2 are concatenated with a space
function writeErrorLog ( $text1 ) {

	global $scriptpath;
	global $errorLoggingType;
	
	$log = '';
	$prefix = '';
	$postfix = '';
	
	if( $errorLoggingType == 3 ) {

		if( ! file_exists( $scriptpath . '/storage' ) ) {

			// don't log if the target folder doesn't exist
			return;	

		} else {
			
			// create empty log with some access protection if it doesn't exist yet 
			$log = $scriptpath . '/storage/fb_error.log.php';
			if( ! file_exists( $log ) )		@error_log( "<?php echo 'Access denied.'; exit(); ?>\n", 3, $log );
			
			// in a file, we need to add a timestamp and a new line
			$prefix = date( 'r');
			$postfix = "\n";
		}
	} else {
		
		// in the hosted environment, we should add a userid to the log
		global $sdrive_config;
		if( isset( $sdrive_config['sdrive_account_id'] ) )
			$prefix = 'sdrive_account=' . $sdrive_config['sdrive_account_id'];
	}

	if( empty( $text1 ) ) $text1 = 'Error logger was called with empty text.';

	$text = '';
	foreach( func_get_args() as $arg ) {

		$text .= ' ';

		// convert object to array if needed
		if( is_object( $arg ) )
			$arg = get_object_vars( $arg );

		if( is_array( $arg ) )
		{
			foreach( $arg as $key => $value )
			{	
				$text .= '[' . $key . '] ' . print_r( $value, true ) . '   ';
			}
		}
		else
		{
			$text .= $arg;
		}
	}

	// if it fails, it should fail silently
	@error_log( $prefix . ': ' . trim( $text ) . $postfix, $errorLoggingType, $log );
}


function getFileLock ( &$handle, $lockType ) {

	$retries = 0;
    $max_retries = 50;

    do {
        if ($retries > 0) {
            usleep( rand(5, 1000) );
        }
        $retries += 1;
    } while( ! flock( $handle, $lockType) && $retries <= $max_retries );

    if( $retries == $max_retries )
    	return false;
    else
    	return true;
}


function makeRandomString ( $length = 6 ) {

	$data = '0123456789abcdefghijklmnopqrstuvwxyz';
	$txt = '';

	for( $i = 0; $i < $length; $i++ ) {
		$txt .= $data[ rand(0,35) ];
	}
	return $txt;
}


// Checks if a given value is a valid URL
function url( $value )
{
	// Thanks to drupal for this
	return (bool) preg_match("
		/^														# Start at the beginning of the text
		(?:ftp|https?):\/\/										# Look for ftp, http, or https schemes
		(?:														# Userinfo (optional) which is typically
		(?:(?:[\w\.\-\+!$&'\(\)*\+,;=]|%[0-9a-f]{2})+:)*		# a username or a username and password
		(?:[\w\.\-\+%!$&'\(\)*\+,;=]|%[0-9a-f]{2})+@			# combination
		)?
		(?:
		(?:[a-z0-9\-\.]|%[0-9a-f]{2})+							# A domain name or a IPv4 address
		|(?:\[(?:[0-9a-f]{0,4}:)*(?:[0-9a-f]{0,4})\])			# or a well formed IPv6 address
		)
		(?::[0-9]+)?											# Server port number (optional)
		(?:[\/|\?]
		(?:[\w#!:\.\?\+=&@$'~*,;\/\(\)\[\]\-]|%[0-9a-f]{2})		# The path and query (optional)
		*)?
		$/xi", $value);
}


function SaveUploadAsFile ( $dest, $filedata ) {

	if( ! is_dir( $dest ) && !mkdir( $dest, 0755, true ) )
	{
		writeErrorLog( 'Could not create file upload directory \'' . $dest . '\'' );
		return false;
	}

	// filename may or may not have an extension that must be preserved
	$pos = strrpos( $filedata['name'], '.' );
	$basename = $filedata['name'];

	// replace any dots left with a _ for scripts diguised as an image (e.g. exploit-db.php.jpg)
	if( $pos !== false )
	{ 
		$tmp = substr( $basename, 0, $pos );
		$basename = str_replace( '.', '_', $tmp ) . substr( $basename, $pos );	
	}

	// try the org name first, only if it exists add the random string
	$uploadname = $basename;

	while( file_exists( $dest . $uploadname ) )
	{
		$rand = makeRandomString();

		if( $pos === false )
		{
			$uploadname = $basename  . '_' . $rand;
		}
		else
		{
			$uploadname = substr( $basename, 0, $pos )  . '_' . $rand . substr( $basename, $pos );
		}
	}

	if( empty( $filedata['tmp_name']) )
	{
		writeErrorLog( 'Could not move uploaded file because the tmp_name is empty.' );
		return false;
	}

	$rc = move_uploaded_file( $filedata["tmp_name"], $dest. $uploadname );
	if( $rc )
	{
		return $uploadname;
	}

	writeErrorLog( 'Moving file ' . $filedata['tmp_name'] . ' to ' . $uploadname . ' failed.' );
	return false;
}


// callback to apply _T() to each element of an array using array_walk()
// usage:   array_walk( $input_array, translate_element_callback)  
function translate_element_callback ( &$item, $key )
{
	$item = _T( $item );
}

// GetText-like translator
function _T ( $text, $vars = false ) {

	static $lang = false;

	// load language table if necessary
	if( $lang === false ) {
		$file = dirname(__FILE__) . '/language.dat.php';
		@$handle = fopen( $file, "r", true );
		if( $handle !==false ) {
			$sdat = fread( $handle, filesize( $file ) );
			fclose( $handle );
			$lang = unserialize( $sdat );
		} else {
			$lang = '';
		}
	}

	if( ! empty( $lang ) && isset($lang[$text]) ) {
		$translated = $lang[$text];
	} else {
		$translated =  $text;
	}

	// replace %s markers with values in vars
	if( $vars ) {

		if( is_string( $vars ) )		$vars = array( $vars );

		foreach( $vars as $var ) {

			$pos = strpos( $translated, '%s' );

			if( $pos !== false ) {
				$translated = substr( $translated, 0, $pos )
							. $var
							. substr( $translated, $pos + 2 );
			}
		}
	}

	return $translated;
}


// $query substitutes the current query (if any) with this value 
// calling this method with an empty string will always return an url with query part 
// 
// Jeff: Overridding this method as PHP_SELF will not generate correct links for S-Drive. 
//       We need to use REQUEST_URI.
function getUrl ( $query = false ) { 

	// get self without query string, sdrive must use REQUEST_URI, but some IIS servers don't have that property set
	$url = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : $_SERVER['PHP_SELF'];
	$p = strpos( $url, '?' ); 
	if( $p !== false )
		$url = substr( $url, 0, $p ); 

	// do we need to replace the query string or use the original? 
	if( $query !== false )
	{
		if( ! empty( $query ) )		$url .= '?' . $query;
	}
	else if( !empty( $_SERVER['QUERY_STRING'] ) )
	{
		$url = $url . '?' . htmlspecialchars( $_SERVER['QUERY_STRING'], ENT_NOQUOTES, 'UTF-8'); 
	}
	
	return $url; 
}



?>