<?php

/**
 * CoffeeCup Software's Web Form Builder.
 *
 * Connection with MailChimp
 *
 * Errors must be reported to PageExtension::setError()!
 *
 * @version $Revision: 2456 $
 * @author Cees de Gruijter
 * @category FB
 * @copyright Copyright (c) 2012 CoffeeCup Software, Inc. (http://www.coffeecup.com/)
 */

// the %dc% must be replaced with the data-center id, which is the last part of the apikey
define( 'MC_URL', 'https://%dc%.api.mailchimp.com/3.0/' );

class MailChimp extends PageExtension {

	private $cfg;
	private $data;
	private $merges;
	private $interests;
	private $result;

	function __construct ( ) {
		parent::__construct( );
	}


	// figure out whether to update, subscribe or unsubscribe
	function dispatch ( $config ) {

		$this->cfg = $config;
		$post =& $this->getPost();

		foreach( $this->cfg->lists as $mclist ) {

			if( ! $mclist->is_present )		continue;

			if( ! isset( $mclist->action ) ) {
				writeErrorLog( 'Missing action definition in one of the MailChimp list configurations.' );
				continue;
			}

			foreach( $mclist->action as $action => $condition ) { 

				if( empty( $condition ) )		continue;
				
				// use "condition" to determine what to do
				switch( $condition->condition ) {

					case 'always':
						$valid = true;
						break;

					case 'never':
						$valid = false;
						break;
					
					default:
						$valid = isset( $post[ $condition->fb_name ] );

						if( $valid && is_array( $post[ $condition->fb_name ] ) )
							// checkboxes are arrays in the post
							$valid = in_array( $condition->fb_value, $post[ $condition->fb_name ] );
						else if( $valid )
							$valid = ($post[ $condition->fb_name ] == $condition->fb_value );
						break;
				}

				if( !$valid || !method_exists( $this, $action ) ) {
					continue;
				}

				if( !$this->$action( $mclist ) ) {
					return false;
				}
			}
		}

		return false;
	}


	private function subscribe ( $mclist ) {

		$this->_prepareData( 'subscribe', $mclist );
		$this->_mergeTags( $mclist->merge_tags );
		$this->_contactMailChimp( 'lists/' . $mclist->listid . '/members/' . md5( strtolower($this->data[ 'email_address' ] ) ), 'PUT' );
		if( ! $this->result || !is_object( $this->result ) )				return false;

		if( $this->result->error ) {

			switch( $this->result->code )
			{
				case 214:
					// already subscribed
					$msg = $this->result->error;
					break;

				default:
					$msg = sprintf( _T('MailChimp reported error %d while subscribing to the mailing list: %s'),
									$this->result->code, $this->result->error );
			}

			$this->setError( $msg );
			writeErrorLog( 'Error while subscribing to MailChimp [' . $this->result->code . ']', $this->result->error );
			return false;
		}

		return true;
	}


	private function unsubscribe ( $mclist ) {

		$this->_prepareData( 'unsubscribe', $mclist );
		$this->_mergeTags( $mclist->merge_tags, array( 'EMAIL' ) );
		$this->_contactMailChimp( 'lists/' . $mclist->listid . '/members/' . md5( strtolower($this->data[ 'email_address' ] ) ), 'DELETE' );

		// Unsubcribe actions return empty responses on success.
		if( ! $this->result || !is_object( $this->result ) )				return true;

		if( $this->result->error ) {

			$this->setError( 'Error '. $this->result->code . _T('while un-subscribing from the mailing list:') . ' ' . $this->result->error );
			writeErrorLog( 'Error while unsubscribing from MailChimp [' . $this->result->code . ']', $this->result->error );
			return false;
		}

		return true;
	}


	private function update ( $mclist ) {

		$this->_prepareData( 'update', $mclist );
		$this->_mergeTags( $mclist->merge_tags );
		$this->_contactMailChimp( 'lists/' . $mclist->listid . '/members/' . md5( strtolower($this->data[ 'email_address' ] ) ), 'PATCH' );
		if( ! $this->result || !is_object( $this->result ) )				return false;

		if( $this->result->error ) {

			$this->setError( 'Error '. $this->result->code . ' while updating your subscribtion to the mailing list: ' . $this->result->error );
			writeErrorLog( 'Error while updating MailChimp [' . $this->result->code . ']', $this->result->error );
			return false;
		}
		return true;
	}


	private function _prepareData ( $action, $config ) {

		$this->data = array(
			'email_type' => 'html'
			);

		if( $action == 'subscribe' ) $this->data[ 'status' ] = $config->$action->double_optin ? 'pending' : 'subscribed';
		if( $action == 'unsubscribe' ) $this->data[ 'status' ] = 'unsubscribed';
		if( $action == 'update' ) $this->data[ 'status' ] = 'subscribed';

		if( isset( $config->$action->update_existing ) )		$this->data[ 'update_existing' ] = $config->$action->update_existing;
		if( isset( $config->$action->replace_interests ) )		$this->data[ 'replace_interests' ] = $config->$action->replace_interests;
		if( isset( $config->$action->send_welcome ) )			$this->data[ 'send_welcome' ] = $config->$action->send_welcome;

		if( isset( $config->$action->email_type_field ) &&
			($type = $this->getPost( $config->$action->email_type_field ) ) &&
			in_array( $type, array( 'html', 'text', 'mobile' ) ) ) {

			$this->data[ 'email_type' ] = $type; 
		}
	}


	private function _contactMailChimp ( $method, $request ) {

		if( ! isset( $this->data[ 'email_address' ] ) ) {

			$this->setError( _T('Can\'t contact MailChimp if no Email address is specified. Check the error log.') );
			writeErrorLog( 'Email address is missing for MailChimp call.', $this->data );
			return;
		}

		if( ! empty($this->merges) )			$this->data['merge_fields'] =& $this->merges;
		if( ! empty($this->interests) )		$this->data['interests'] =& $this->interests;

		$payload = json_encode( $this->data );

		$url = str_replace( '%dc%', substr( $this->cfg->apiKey, strrpos( $this->cfg->apiKey , '-') + 1 ), MC_URL );
		$auth = base64_encode( 'user:'.$this->cfg->apiKey );

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url . $method );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json',
																								'Authorization: Basic '.$auth));
		curl_setopt( $ch, CURLOPT_USERAGENT, 'PHP-MCAPI/3.0');
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $request);
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt( $ch, CURLOPT_TIMEOUT, 10);
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );

		$result = curl_exec( $ch );
		curl_close ($ch);

		$this->result = json_decode( $result );

		if( ! $this->result && $request != 'DELETE') {

			$this->setError( _('Communication with MailChimp failed. Check the error log.') );
			writeErrorLog( 'MailChimp returned this:', $this->result );
		}
	}


	// validate the input data, because we can't be sure the user/FB has
	// matched the correct field types
	// $subset makes it possble to define which of the tags must be merged
	private function _mergeTags ( $merge_tags, $subset = false ) {

		$this->merges = array();
		$this->interests = array();

		foreach( $merge_tags as $tag ) {
			
			if( ( $subset && ! in_array( $tag->tag, $subset ) ) ||
				! isset( $tag->fb_name ) ||
				empty( $tag->fb_name ) )		continue;
			

			if( ! isset( $tag->field_type ) ||
				! isset( $tag->fb_name ) ||
				( !method_exists( $this, $tag->field_type ) && isset( $tag->isGrouping ) && !$tag->isGrouping ) ) {
				
				writeErrorLog( 'MailChimp merge - property or handler missing', $tag );
				continue;
			}

			// required test
			if( isset( $tag->req ) &&
				$tag->req &&
				$this->getPost( $tag->fb_name ) === false ) {

				$this->setError( _T( 'Required field %s is missing or failed input validation.', $tag->fb_name ) );
				continue;
			}

			// field specific tests and merges
			if( $this->getPost( $tag->fb_name ) !== false ) {

				// grouping-tags can all be dealt with the same way
				$fieldtype = ( isset( $tag->isGrouping ) && $tag->isGrouping ) ? 'grouping' : $tag->field_type;

				$this->$fieldtype( $tag );
			}
		}

		if( ! empty( $merges ) )					$this->data[ 'merge_fields'] = $merges;
		if( ! empty( $interests ) )				$this->data[ 'interests'] = $interests;
	}



	private function email ( $tag ) {

		if( ! email( $this->getPost( $tag->fb_name ) ) ) {

			$this->setError( _T( 'Field %s must contain a valid Email address.', $tag->fb_name) );
			return;
		}
		
		// the tag "EMAIL" is a special field and is not part of the merge
		if( $tag->tag == 'EMAIL' )
			$this->data[ 'email_address' ] = $this->getPost( $tag->fb_name );
		else
			$this->merges[ $tag->tag ] = $this->getPost( $tag->fb_name );
	}


	private function text ( $tag ) {

		$this->merges[ $tag->tag ] = $this->getPost( $tag->fb_name );
	}


	private function date ( $tag ) {
	
		$date = $this->getPost( $tag->fb_name );
		if( $date !== false && ! empty( $date ) ) 	$this->merges[ $tag->tag ] = date('Y-m-d', $date );		
	}


	private function number ( $tag ) {

		if( ! preg_match( '/^[\d\s,.]+$/', $this->getPost( $tag->fb_name ) ) ) {

			$this->setError( _T( 'Field %s must contain a valid numer.', $tag->fb_name ) );
			return;
		} 

		$this->merges[ $tag->tag ] = $this->getPost( $tag->fb_name );
	}


	private function radio ( $tag ) {

		$this->dropdown( $tag );
	}


	private function dropdown ( $tag ) {

		if( ! in_array( $this->getPost( $tag->fb_name ), $tag->choices ) ) {

			$this->setError( _T( 'Field %s can only be: %s.', array( $tag->fb_name, implode( ', ', $tag->choices ) ) ) );
			return;
		}

		$this->merges[ $tag->tag ] = $this->getPost( $tag->fb_name );
	}


	private function grouping ( $tag ) {

		$input = $this->getPost( $tag->fb_name );
		if( ! is_array( $input ) )		$input = array( $input );

		if( count( array_intersect( $tag->choices, $input ) ) != count( $input ) ) {

			$this->setError( _T( 'Field %s can only be one or more off these: %s.', array( $tag->fb_name, implode( ', ', $tag->choices ) ) ) );
			return;
		}

		// ensure the array exists and add this entry
		if( ! isset( $this->interests ) )		$this->interests = array();

		foreach($tag->choices as $i => $item) {
				$this->interests[$tag->groupingIDs[$i]] = in_array($item, $input);
		}
	}


	// used by the MailChimp::grouping() method
	private function _escapeGroup ( $val, $key ) {

		return str_replace( ',' , '\,' , $val );
	}


	/* Example birthday from form
		{
		"fb_name": {
			"MM": "month1",
			"DD": "day"
		},
		"field_type": "birthday",
		"req": false,
		"tag": "MMERGEX" },
		*/
	private function birthday ( $tag ) {

		$bd = $this->getPost( $tag->fb_name );
		
		if( ! isset( $bd[ 'MM' ] ) || ! is_numeric( $bd[ 'MM' ] ) ||
			(int)$bd[ 'MM' ] < 1 || (int)$bd[ 'MM' ] > 12 ) {

			$this->setError( _T( 'Month must be a number between 1 and 12.' ) );
			return;
		}

		if( ! isset( $bd[ 'DD' ] ) || ! is_numeric( $bd[ 'DD' ] ) ||
			(int)$bd[ 'DD' ] < 1 || (int)$bd[ 'DD' ] > 31 ) {

			$this->setError( _T( 'Day must be a number between 1 and 31.' ) );
			return;
		}

		// build the output format mm/dd
		$this->merges[ $tag->tag ] = sprintf( '%02d/%02d', $bd[ 'MM' ], $bd[ 'DD' ] );
	}


	/* Example address from form
		{
		"fb_name": {
			"addr1": "AdressLine1",
			"addr2": "AdressLine2",
			"city": "City",
			"country": "",
			"state": "Suffix",
			"zip": "ZipCode"
		},
		"field_type": "address",
		"req": false,
		"tag": "MMERGE9" },
	*/
	private function address ( $tag ) {

		$adr = $this->getPost( $tag->fb_name );

		// country should be nothing or a 2 character ISO-3166-1 code
		if( isset( $adr[ 'country' ] ) && ! empty( $adr[ 'country' ] ) ) {

			include 'fbapp/inc/countryiso.inc.php';

			$adr[ 'country' ] = Country2Iso( $adr[ 'country' ] );
			
			if( ! $adr[ 'country' ] ) {

				$this->setError( _T( 'Country name not recognized, may be it isn\'t spelled correctly.' ) );
				return;
			} 
		}

		// add minimum required keys: addr1, city, state, zip, country
		$keys = array( 'addr1', 'city', 'country', 'state', 'zip' );
		foreach( $keys as $key ) { 
				if( !isset( $adr[ $key ] ) || $adr[ $key ] == '' ) {
						$this->setError( _T( 'Please enter a complete address' ) );
				}
		}

		$this->merges[ $tag->tag ] = $adr;		
	}


	private function zip ( $tag ) {

		// don't know what the format specs are, threat as text for the time being
		$this->text( $tag );
	}


	private function phone ( $tag ) {

		if( $tag->phoneformat == 'US' ) {

			// must be formatted like: NPA-NXX-LINE (404-555-1212)
			$phone = $this->getPost( $tag->fb_name );
			$output = '';

			for( $i = 0; $i < strlen( $phone ); $i++ ) {

				if( is_numeric( $phone[ $i ] ) )			$output .= $phone[ $i ];

				if( strlen( $output ) == 3 ||
					strlen( $output ) == 7 )				$output .= '-';
			}

			if( strlen( $output ) != 12 ) {

				$this->setError( _T( 'Field %s could not be formatted as NPA-NXX-LINE.' ) );
				return;
			}
		}

		$this->merges[ $tag->tag ] = $this->getPost( $tag->fb_name );
	}


	private function url ( $tag ) {

		if( ! $this->getPost( $tag->fb_name ) )			return;

		// lets relax the test a little bit and prefix http if it isn't there
		$url = trim( $this->getPost( $tag->fb_name ) );
		$tmp = preg_match( '/^(?:ftp|https?):\/\//xi' , $url ) ? $url : 'http://' . $url; 

		if( ! url( $url ) ) {

			$this->setError( _T( 'Field %s must contain a valid URL.', $tag->fb_name ) );
			return;
		}
		
		$this->merges[ $tag->tag ] = $this->getPost( $tag->fb_name );
	}


	// the MC image-url can be filled with a normal url OR with an uploaded file
	// for the latter, we must generate a valid url for the uploaded file
	private function imageurl ( $tag ) {

		// get our field name and check the rules for its type
		$fldnam = $tag->fb_name;

		if( ! Config::GetInstance()->GetConfig( 'rules', $fldnam ) ) {
			writeErrorLog( 'MailChimp plugin couldn\'t access rules or failed to locate:', $fldnam );
			return;
		}

		switch( Config::GetInstance()->GetRulePropertyByName( $fldnam, 'fieldtype' ) ) {
		
		case 'hidden':
		case 'url':

			$this->url( $tag );
			break;
	
		case 'fileupload':

			$filename = $this->getPost( $fldnam );
			
			if( $filename === false ) {
				
				$this->setError( _T( 'There is no file stored for the field named %s.', $fldnam ) );
	
			} else {

				$this->merges[ $tag->tag ] = $this->_makePublicUrl( $fldnam ,$filename );
			}
			break;
		}
	}


	private function _makePublicUrl ( $fieldname, $filename ) {

		// ensure the publicly visible folder exists
		if( ! file_exists ( Config::GetInstance()->getStorageFolder( 4 ) ) )
			mkdir( Config::GetInstance()->getStorageFolder( 4 ) );

		// use the rules to find out where the file is
		if( Config::GetInstance()->GetRulePropertyByName( $fieldname, 'files' ) == true ) {

			if( ! copy( Config::GetInstance()->getStorageFolder( 1 ) . $filename, Config::GetInstance()->getStorageFolder( 4 ) . $filename ) ) {
		
				writeErrorLog( 'MailChimp plugin couldn\'t copy the uploaded file to a public folder', $filename );
				$this->setError( _T( 'Failed to copy the uploaded file %s to a publicly visible folder.', $filename ) );
				return;
			}

		} else {

			// look for it in the uploads table
			if( isset( $_FILES[ $fieldname ] ) &&
				file_exists( $_FILES[ $fieldname ][ 'tmp_name' ] ) ) {

				$filename = SaveUploadAsFile( Config::GetInstance()->getStorageFolder( 4 ), $_FILES[ $fieldname ] );
				if( $filename == false ) {
	
					writeErrorLog( 'MailChimp plugin couldn\'t move the uploaded file to a public folder', $filename );
					$this->setError( _T( 'Failed to move the uploaded file %s to a publicly visible folder.', $filename ) );
					return;
				}
			}
		}

		$servername = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME'];

		$path = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : $_SERVER['PHP_SELF'];
		$path = substr( $path, 0, strrpos( $path, '/' ) ); 

		// encode the folders, not the '/'!
		$tmp = explode( '/', $path);
		for( $i = 0; $i < count( $tmp ); ++$i ) {
			$tmp[$i] = rawurlencode( $tmp[$i] );
		}
		$path = implode( '/', $tmp );

		// windows servers may set [HTTPS] => off, linux server usually don't set [HTTPS] at all
		if( isset( $_SERVER['HTTPS'] ) && ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] != 'off' ) {
			$protocol = 'https';
		} else {
			$protocol = 'http';
		}

		$url = $protocol . '://' . $servername;

		// only add the serverport when it differs from the default
		if( strpos( $servername, ':') === false &&
			( $_SERVER['SERVER_PORT'] != '80' || $protocol != 'http') ) {
			$url .= ':' . $_SERVER['SERVER_PORT'];
		}

		return $url . $path . '/' . FormPage::GetInstance()->GetFormName() . CC_FB_STORAGE_FOLDER . CC_FB_PUBLIC_DIRECTORY . $filename;
	}


}

?>