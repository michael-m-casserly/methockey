<?php

/**
 * CoffeeCup Software's Web Form Builder.
 *
 * Validates posted data against the rules.
 *
 *
 * @version $Revision: 2456 $
 * @author Cees de Gruijter
 * @category FB
 * @copyright Copyright (c) 2011 CoffeeCup Software, Inc. (http://www.coffeecup.com/)
 */

// copy anything valid from $_POST to $post
// return error count if the input doesn't pass the tests

function ValidateInput ( ) {

	if( $_SERVER['CONTENT_LENGTH'] > getPhpConfBytes() ) {

		FormPage::GetInstance()->SetErrors( array( array( 'field' => 'Form',
									 		'err' => _T('The form is attempting to send more data than the server allows. Please check that you are not uploading too many large files.' ) ) ) );

		return 1;
	}

	$cfg = Config::GetInstance()->GetConfig( 'rules' );
	if( $cfg === false ) 		return 1;
	
	$validator = new Validator();
	$conditionals = new Conditionals($cfg);

	foreach( $cfg as $name => $rules ) {

		// skip all rules that have a name with a _ prefix
		if( $name[0] == '_' )		continue;
		
		if( $conditionals->IsFieldIgnored($name) )	continue;

		$fieldtype = $rules->fieldtype;

		if( method_exists( 'Validator', $fieldtype ) ) {

			if( ! $validator->required( $name, $rules ) )
				continue;
			else
				$validator->$fieldtype( $name, $rules );

		} else {
			writeErrorLog( 'Validation handler missing for fieldtype: ', $fieldtype );
		}
	}

	$errcount = count( $validator->errors );

	// ready, assign the result to the page instance
	if( $errcount > 0 ) 		FormPage::GetInstance()->SetErrors( $validator->errors );
	else 						FormPage::GetInstance()->SetPostValues( $validator->post );

	return $errcount;
}

/******************* conditional rules handler *******************/

class Conditionals {

	private $post = array();

	public function __construct ( $cfg )
	{
		foreach( $_POST as $key => $value )
		{

			if(isset($cfg->$key->contactList) && $cfg->$key->contactList == true) {
				$value = deobfuscate_deep($value);
			}

			// internally, lower cased keys are used
			$this->post[ strtolower( $key ) ] = is_string( $value ) ? trim( $value ) : $value;
		}

		// also add any file keys to the map
		if( isset( $_FILES ) && is_array( $_FILES ) )
		{
			foreach( $_FILES as $key => $value )
			{
				if( isset( $value['name'] ) )
					$this->post[ strtolower( $key ) ] = is_string( $value['name'] ) ? trim( $value['name'] ) : $value['name'];
			}
		}
	}
	
	// Checks if the field needs to be ignored due to the conditional fields
	public function IsFieldIgnored ( $name ) {
		$cond_cfg = Config::GetInstance()->GetConfig( 'conditional_rules' );
		
		if( !isset( $cond_cfg->$name ) )
			return false;
	
		// If the field doesn't comply the rule needs to be ignored
		return !$this->CheckRule( $cond_cfg->$name );
	}


	// Check the rules for conditionals fields for a specific element.
	private function CheckRule ( $object ) {
	
		foreach($object as $key => $rule) {

			switch($key){
				// If it is a set of rules we need to look at each rule and apply the logic operatior
				case 'set':
					if( $object->$key->operator == 'and' )
						return $this->CheckRule( $object->$key->rule1 ) && $this->CheckRule( $object->$key->rule2 );
					else if( $object->$key->operator == 'or' )
						return $this->CheckRule( $object->$key->rule1 ) || $this->CheckRule( $object->$key->rule2 );
				// If it is an element we need to check the value of the control name is correct with the proper operator
				case 'element':
						return $this->CheckValue( $object->$key->name, $object->$key->operator, $object->$key->value);
				default:
					return false;
			}
		}
	}

	// Checks the value of the conditional rule is applied in the element based on the operator
	private function CheckValue ( $name, $operator, $value ) {
		$values = $this->post[$name];
	
		$valid = false;

		// In case it is in an array we check if it is being contained
		if( is_array($values) && in_array( $value, $values ) )
			$valid = true;
		else if( $values == $value )
			$valid = true;
		else if( $value == "" && !isset( $values ))
			$valid = true;
		
		if( $operator == 'is' ) {
			return $valid;
		}
	
		else if( $operator == 'is_not' )	{
			return !$valid;
		}
		
		return $valid;		
	}
}


/******************* validation handlers *******************/

class Validator {

	public $errors = array();
	public $post;
	private $input = array();
	private $m_noSlashes;				// posted string with slashes stripped
	private $m_noLinebreaks;			// posted string with slashes and line breaks stripped

	public function __construct ( )
	{
		foreach( $_POST as $key => $value )
		{
			// internally, lower cased keys are used
			$this->input[ strtolower( $key ) ] = is_string( $value ) ? trim( $value ) : $value;
		}

		// save the relation between internal and original keys (for display purposes)
		Config::GetInstance()->SetOriginalPostKeyMap( array_combine( array_keys( $this->input ), array_keys( $_POST ) ) );

		// also add any file keys to the map
		if( isset( $_FILES ) && is_array( $_FILES ) )
		{
			foreach( $_FILES as $key => $value )
			{
				Config::GetInstance()->postkeymap[ strtolower( $key ) ] = $key;
			}
		}

	}


	public function text ( $name, $rules ) {

		if(	! $this->_checklength( $name, $rules ) )		return;

		$this->post[ $name ] = $this->m_noLinebreaks;
	}


	public function hidden ( $name, $rules ) {

		// hidden fields are validated silently, thus don't call _checklength()
		if( ! isset( $rules->database ) ||
			! $rules->database ) 							return;
	
		if( isset( $this->input[ $name ] ) ) {

			$tmp = stripslashes_deep( $this->input[ $name ] );
			if( strlen( $tmp ) > $rules->maxbytes )			return;

		} else {

			$tmp = '';
		}

		$this->post[ $name ] = $tmp;
	}


	public function textarea ( $name, $rules ) {

		if(	! $this->_checklength( $name, $rules ) )		return;

		$this->post[ $name ] = $this->m_noSlashes;
	}


	public function number ( $name, $rules ) {

		// set empty number to null to avoid confusion with allowed values for none required fields
		if( ! isset( $this->input[ $name ] ) || $this->input[ $name ] == '' ) {
			$this->post[ $name ] = null;
			return;
		}

		$label = empty($rules->label) ? $name : $rules->label;
		$decimals = isset( $rules->decimals ) ? $rules->decimals : 0 ;
		$multi = pow( 10, $decimals );

		if( ! is_numeric( $this->input[ $name ] ) )
		{
			$this->_errormsg( $name, $rules, _T( '"%s" must be a number.', $name ) );
			return;
		}

		// round to the allowed number of digits
		$num = round( $this->input[ $name ], $decimals );

		if( isset( $rules->range ) && is_array( $rules->range ) ) {

			list( $min, $max, $step ) = array( false, false, false );

			if( count($rules->range ) == 3 )	list( $min, $max, $step ) = $rules->range;
			else								list( $min, $max ) = $rules->range;

			if( $min !== false && $num < $min ) {
				$this->_errormsg( $name, $rules, _T( '"%s" must be larger than %s.', array( $label, $min ) ) );
			} else if( $max !== false && $num > $max ) {
				$this->_errormsg( $name, $rules, _T( '"%s" must be smaller than %d.', array( $label, $max ) ) );
			} else if( $step !== false )  {

				// step can be < 1, but % (modulus) only accepts an int
				$remainder = ($num - $min) * $multi % ($step * $multi);

				if( $remainder != 0 )
					$this->_errormsg( $name, $rules, _T( '"%s" doesn\'t have an allowed value. Closest allowed values are %s or %s',
														array( $label, ($num - $remainder), ($num - $remainder + $step) ) ) );
			}
		}

		$this->post[ $name ] = $num;		
	}


	public function date ( $name, $rules ) {

		$value = isset( $this->input[ $name ] ) ? $this->input[ $name ] : '' ;

		if( strlen( $value ) == 0 ) {
			$this->post[ $name ] = '';
			return;
		}
	
		$postedtime = Config::GetInstance()->ParseDateStringOnFormatByFieldname( $name, $value );
		$label = empty($rules->label) ? $name : $rules->label;

		if( isset( $rules->date_config ) ) {

			if( $postedtime === false ||
				isset( $rules->date_config->dateFormat ) &&
				($tmp = date( Config::GetInstance()->GetDateFormatByFieldname( $name ), $postedtime )) != $value &&
				preg_replace( '/\b0/', '', $tmp) != $value )			// repeat test without leading 0's for day and month

			{
				$this->_errormsg( $name, $rules, _T( '"%s" must be a correctly formatted and valid date.', $name ) );
			}

			if( $rules->date_config->minDate > 0 && $postedtime < $rules->date_config->minDate ) {

				$this->_errormsg( $name, $rules, _T( '"%s" must be a date later than %s.',
													array( $label, date( Config::GetInstance()->GetDateFormatByFieldname( $name ), $rules->date_config->minDate ) ) ) );
			}
			if( $rules->date_config->maxDate > 0 && $postedtime > $rules->date_config->maxDate ) {

				$this->_errormsg( $name, $rules, _T( '"%s" must be a date before %s.',
													array( $label, date( Config::GetInstance()->GetDateFormatByFieldname( $name ), $rules->date_config->maxDate ) ) ) );
			}
		}
		$this->post[ $name ] = $postedtime;
	}


	public function email ( $name, $rules ) {

		$addr = isset( $this->input[ $name ] ) ? $this->input[ $name ] : '';

		if( strlen( $addr ) == 0 ) {

			$this->post[ $name ] = '';			// empty email
			return;
		}

		$label = empty($rules->label) ? $name : $rules->label;

		if( ! email( $addr ) ) {

			$this->_errormsg( $name, $rules, _T( '"%s" is not a valid email address.', $label ) );
			return;
		}

		if( isset( $rules->equalTo ) ) {

			if( ! isset( $this->input[ $rules->equalTo ] ) ||
				$addr != $this->input[ $rules->equalTo ] ) {

				$this->_errormsg( $name, $rules, _T( 'The addresses in the fields "%s" and "%s" must match.',
													 array( $label, $rules->label_equal ) ) );
				return;
			}
		} 

		$this->post[ $name ] = $addr;
	}


	public function password ( $name, $rules ) {

		if(	! $this->_checklength( $name, $rules ) )		return;

		if( isset( $rules->equalTo ) && $rules->equalTo != '' ) {

			if( ! isset( $this->input[ $rules->equalTo ] ) ||
				$this->m_noSlashes != stripslashes_deep( $this->input[ $rules->equalTo ] )  ) {

				$this->_errormsg( $name, $rules, _T( '"%s" and "%s" must match.', array( $rules->label, $rules->label_equal ) ) );
				return;
					
			}
		}
		$this->post[ $name ] = $this->m_noSlashes;
	}


	public function url ( $name, $rules ) {

		$url = isset( $this->input[ $name ] ) ? $this->input[ $name ] : '';

		if( strlen( $url ) == 0 ) {

			$this->post[ $name ] = '';			// empty url
			return;
		}

		// lets relax the test a little bit and prefix http if it isn't there
		$tmp = preg_match( '/^(?:ftp|https?):\/\//xi' , $url ) ? $url : 'http://' . $url; 

		if( ! url( $tmp ) ) {

			$this->_errormsg( $name, $rules, _T( '"%s" is not a valid web address.', empty($rules->label) ? $name : $rules->label ) );
			return;
		}

		if( isset( $rules->equalTo ) ) {

			if( ! isset( $this->input[ $rules->equalTo ] ) ||
				$url != $this->input[ $rules->equalTo ] ) {

				$this->_errormsg( $name, $rules, _T( 'The URLs in the fields "%s" and "%s" must match.',
														empty($rules->label) ? $name : $rules->label, $rules->label_equal ) );
				return;
			}
		}

		$this->post[ $name ] = $url;		
	}


	public function checkbox ( $name, $rules ) {

		$values = isset( $this->input[ $name ] ) ? stripslashes_deep( $this->input[ $name ] ) : array();

		if( isset( $rules->number_required ) && $rules->number_required > 0 ) {

			if( ! is_array( $values ) || count( $values ) < $rules->number_required )
			{
				$this->_errormsg( $name, $rules, _T( '"%s" must have at least %d checkboxes checked.',
														array( (empty($rules->label) ? $name : $rules->label),
																$rules->number_required ) ) );
				return;
			}
		}

		$this->post[ $name ] = $values;		
	}


	public function dropdown ( $name, $rules ) {

		// dropdown is like a listbox
		$this->listbox( $name, $rules );

		// but can have only 1 value
		if( isset( $this->input[ $name ] ) && is_array( $this->input[ $name ] ) ) {

			$this->_errormsg( $name, $rules, _T( '"%s" can\'t have more than 1 value.', empty($rules->label) ? $name : $rules->label ) );

		} else if( is_array( $this->post[ $name ] ) && count( $this->post[ $name ] ) > 0 ) {

			// flatten the array to the first element
			$this->post[ $name ] = $this->post[ $name ][ 0 ];

		} else {

			$this->post[ $name ] = '';
		}
	}


	public function listbox ( $name, $rules ) {

		if( isset( $this->input[ $name ] ) ) {

			// listboxes can be single and multiple select, unify input to array
			if( is_array( $this->input[ $name ] ) )			$values = stripslashes_deep($this->input[ $name ]);
			else										$values = array( stripslashes_deep( $this->input[ $name ] ) );
	
			if( isset( $rules->values ) && is_array( $rules->values ) ) {

				if(isset($rules->contactList) && $rules->contactList == true) {
					$values = deobfuscate_deep($values);
				}

				foreach( $values as $value ) {	

					if( ! in_array( trim( $value ), $rules->values ) ) {

						$this->_errormsg( $name, $rules, _T( '"%s" doesn\'t have a valid value.',
															  empty($rules->label) ? $name : $rules->label ) );
						return;
					}
				}
	
			} else {
	
				writeErrorLog( 'Validation rules for a listbox lacks values array.' );
			}

			$this->post[ $name ] = $values;

		} else {

			$this->post[ $name ] = '';
		}
	}


	public function radiogroup ( $name, $rules ) {
		
		if( isset( $this->input[ $name ] ) ) {

			$value = stripslashes_deep( $this->input[ $name ] );

			if( isset( $rules->values ) && is_array( $rules->values ) ) {
	
				if( ! in_array( $value, $rules->values ) ) {

					$this->_errormsg( $name, $rules, _T( '"%s" doesn\'t have a valid value.',
														  empty($rules->label) ? $name : $rules->label ) );
					return;
				}
	
			} else {
	
				writeErrorLog( 'Validation rules for a radio group lacks values array.' );
			}

			$this->post[ $name ] = $value;

		} else {

			$this->post[ $name ] = '';
		}
	}


	public function fileupload ( $name, $rules ) {

		// Get the original field name to access to the $_FILES
		$org_fieldname = Config::GetInstance()->GetOriginalPostKey( $name );

		if( $rules->accept && isset( $_FILES[ $org_fieldname ] ) ) {

			$uploaded_file = $_FILES[ $org_fieldname ];

			if( $uploaded_file['error'] ) {

				switch( $uploaded_file['error'] ) {

				case UPLOAD_ERR_INI_SIZE:
				case UPLOAD_ERR_FORM_SIZE:
					$this->_errormsg( $org_fieldname, $rules, _T( 'The file "%s" exceeds the maximum size that can be uploaded by this form.',
														  $uploaded_file[ 'name' ] ) );
				break;

				case UPLOAD_ERR_NO_FILE:
					// handled by required()
				break;

				default:
					$this->_errormsg( $org_fieldname, $rules, _T( 'The file "%s" was not uploaded; error code: %d.',
														  array( $uploaded_file[ 'name' ], $uploaded_file['error'] ) ) );
				}

			} else {

				// json formatted validation rule: "accept":"txt|jpg|png|gif"
				if( ! empty( $uploaded_file[ 'name' ] ) &&
					! preg_match( '/\.(' . $rules->accept . ')$/is', $uploaded_file[ 'name' ] ) ) {

					$this->_errormsg( $org_fieldname, $rules, _T( 'The file "%s" is not an allowed file type.',
														  $uploaded_file[ 'name' ] ) );
				}

			 	// test against scripts diguised as an image (exploit-db.php.jpg) that hurt default apache config
				if( preg_match( '/[\d\W]php\d?\./i', $uploaded_file[ 'name' ] ) ||
					strpos( $uploaded_file[ 'name' ], "\0" ) !== false ) {

					$this->_errormsg( $org_fieldname, $rules, _T( 'The filename "%s" is not allowed.', $uploaded_file[ 'name' ] ) );
				}

				if( isset( $rules->maxbytes ) && $uploaded_file[ 'size' ] > $rules->maxbytes ) {

					$this->_errormsg( $org_fieldname, $rules, _T( 'The file "%s" is larger than the maximum file size allowed.',
															  $uploaded_file[ 'name' ] ) );
				}
			}
		}
	}


	public function tel ( $name, $rules ) {

		$telnum = isset( $this->input[ $name ] ) ? $this->input[ $name ] : '';

		if( strlen( $telnum ) == 0 ) {

			$this->post[ $name ] = '';			// empty tel. number
			return;
		}

		$method = '_' . strtolower( $rules->phone );
		if( ! method_exists( $this, $method ) ) {

			writeErrorLog( 'No format defined for telephone number type:', $rules->phone );
			$this->errors[] = array( 'field' => $name, 'err' =>  _T( 'No format specifier found for "%s".', $rules->phone ) );

		} else if( $this->$method( $telnum ) ) {

			$this->post[ $name ] = $telnum;

		} else {

			$this->_errormsg( $name, $rules, _T( '"%s" isn\'t recognized as a valid telephone number format.',
												  empty($rules->label) ? $name : $rules->label ) );
		}
	}


	public function captcha ( $name, $rules ){

		$private_key = '';

		if( $rules->captcha == 'automatic' && Config::GetInstance()->sdrive !== false ) {

			$private_key = Config::GetInstance()->sdrive[ $rules->version == 'v1' ? 'recaptcha_private_key' : 'recaptcha2_private_key' ];

		} else if( $rules->captcha == 'manual' && isset( $rules->private_key ) && $rules->private_key != '' ) {

			$private_key = $rules->private_key;

		} else {

			$this->_errormsg( $name, $rules, _T('Please configure valid public and private reCaptcha keys or use CoffeeCup S-Drive\'s automatic reCaptcha processing.' ) );
			return;
		}

		if($rules->version == 'v1') {

			if( ! isset( $this->input[ 'recaptcha_challenge_field' ] ) ||
				! isset( $this->input[ 'recaptcha_response_field' ] ) ) {

				$this->_errormsg( $name, $rules, _T( 'The form post is missing reCaptcha fields.' ) );
			}


			include 'fbapp/inc/recaptchalib.php';

			$resp = recaptcha_check_answer( $private_key,
											$_SERVER[ 'REMOTE_ADDR' ],
											$this->input[ 'recaptcha_challenge_field' ],
											$this->input[ 'recaptcha_response_field' ] );

			$valid = $resp->is_valid;

		} else if($rules->version == 'v2') {

			if( ! isset( $this->input[ 'g-recaptcha-response' ] ) ) {

				$this->_errormsg( $name, $rules, _T( 'The form post is missing reCaptcha fields.' ) );
				return;
			}

			$data = array('secret'   => $private_key,
										'response' => $this->input[ 'g-recaptcha-response' ],
										'remoteip' => $_SERVER['REMOTE_ADDR']);

			$url = 'https://www.google.com/recaptcha/api/siteverify';

			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, $url );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt( $ch, CURLOPT_TIMEOUT, 10);
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $data ) );

			$result = curl_exec( $ch );
			curl_close ($ch);

			$result = json_decode($result);

			$valid = $result && $result->success;

		}

		if( !$valid ) {
			$this->_errormsg( $name, $rules, _T( 'Please enter the correct info in the Captcha box.' ) );
		}
	}

	public function sigpad ( $name, $rules ){

		if( ! isset( $this->input[ 'sigpad-output' ] ) ) {
			$this->_errormsg( $name, $rules, _T( 'The form post is missing the digital signature fields.' ) );
		}
		
		if( ! isset( $this->input[ 'sigpad-width' ] ) ) {
			$this->_errormsg( $name, $rules, _T( 'The form post is missing the width of the signature fields.' ) );
		}
		
		if( ! isset( $this->input[ 'sigpad-height' ] ) ) {
			$this->_errormsg( $name, $rules, _T( 'The form post is missing the height of signature fields.' ) );
		}
		
		if( ! isset( $this->input[ 'sigpad-prefix' ] ) ) {
			$this->_errormsg( $name, $rules, _T( 'The form post is missing the prefix of signature fields.' ) );
		}

    $this->post[ 'sigpad' ] = array( 
			'sigpad-output' => $this->input[ 'sigpad-output' ],
			'sigpad-width' => $this->input[ 'sigpad-width' ],
			'sigpad-height' =>  $this->input[ 'sigpad-height' ],
			'sigpad-prefix' => $this->input[ 'sigpad-prefix' ] );

	}

	public function regex ( $name, $rules ) {

		$val = isset( $this->input[ $name ] ) ? $this->input[ $name ] : ''; 

		// allow an empty field
		if( strlen( $val ) == 0 ) {

			$this->post[ $name ] = '';
			return;
		}

		if( preg_match( $rules->regex_config, $val ) == 0 ) {

			$this->_errormsg( $name,
							  $rules, _T( '"%s" must match the format defined for this field.' ) ,
							  empty($rules->abel) ? $name : $rules->label );
			return;
		}

    Config::GetInstance()->postkeymap[ 'sigpad' ] = 'sigpad-output';
		$this->post[ $name ] = $val;
	}



	/*** generic functions ***/

	public function required ( $name, $rules ) {

		if( ! isset( $rules->required ) || ! $rules->required )
			return true;

		$error = false;

		if( $rules->fieldtype == 'fileupload' ) {

			$name = Config::GetInstance()->GetOriginalPostKey( $name );

			$error = ! isset( $_FILES[ $name ] ) ||
					 $_FILES[ $name ]['size'] == 0 ||
					 $_FILES[ $name ]['error'] == UPLOAD_ERR_NO_FILE;

		} else {

			$tmp = isset( $this->input[ $name ] ) ? $this->input[ $name ] : '';	
			if( is_array( $tmp ) )
			{
				$error = empty( $tmp );
			}
			else
			{
				$error = empty( $tmp ) && strlen( $tmp ) == 0;
			}
		}

		if( $error ) {

			$this->_errormsg( $name, $rules, _T( '"%s" is a required field and cannot be empty.',
									 			  empty($rules->label) ? $name : $rules->label ) );
		}

		return ! $error;
	}



	/*** private functions ***/

	// check length, ignoring charriage returns and leading/trailing spaces
	// as a side effect, it sets the private properties $this->m_noSlashes and $this->m_noLinebreaks
	// which the caller may use if true is returned
	private function _checklength ( $name, $rules ) {

		$input = isset( $this->input[ $name ] ) ? $this->input[ $name ] : ''; 

		if( strlen( $input ) == 0 ) {

			$this->m_noLinebreaks = '';
			$this->m_noSlashes = '';
			return true;
		}	

		// prepare the string for counting
		$this->m_noSlashes = stripslashes_deep( $input );

		// strips the carriage returns for character count
		// Processes \r\n's first so they aren't converted twice.
		$this->m_noLinebreaks = str_replace( array( "\r\n", "\n", "\r" ), ' ', $this->m_noSlashes );

		$label = empty($rules->label) ? $name : $rules->label;

		if( isset( $rules->maxlength ) && strlen( $this->m_noLinebreaks ) > $rules->maxlength ) {

			$this->_errormsg( $name, $rules, _T( '"%s" must be less than %d characters.',
									 			  array( $label, $rules->maxlength ) ) );
			return false;

		} else if( isset( $rules->minlength ) && strlen( $this->m_noLinebreaks ) < $rules->minlength ) {

			$this->_errormsg( $name, $rules, _T( '"%s" must be at least %d characters.',
									 			  array( $label, $rules->minlength ) ) );
			return false;
			
		}

		return true;
	}

	
	private function _errormsg ( $name, $rules, $default, $values = array() ) {

		if( isset( $rules->messages ) && ! empty( $rules->messages ) )
		{
			$this->errors[] = array( 'field' => $name, 'err' => $rules->messages );
		}
		else
		{
			// substitute values with sprintf
			if( ! empty( $values ) )		$default = vsprintf( $default, $values );
	
			$this->errors[] = array( 'field' => $name, 'err' => $default );
		}
	}


	// return true if valid
	// International
	// <= 15 digits, may have leading + and contain ().- (according to Wikipedia)
	private function _international ( $number ) {

		//ignoring all non-digits makes counting easier
		$tmp = preg_replace('/[^\d+]/', '', RemoveExtensionAndSpaces( $number ) );
		return preg_match( '/^\+?\d{9,15}$/', $tmp );

	}

	// (111) 111-1111
	// 1-222-222-2222
	// 111-111-1111
	// 111.111.1111
	// possibly with x or ext. 123 added
	private function _phoneus ( $number ) {

		$tmp = RemoveExtensionAndSpaces( $number );

		return preg_match( '/^\(\d{3}\)\d{3}-\d{4}$/', $tmp ) ||
			   preg_match( '/^1-[\d-]{10}$/', $tmp ) ||
			   preg_match( '/^[\d-.]{10}$/', $tmp) ||
			   preg_match( '/^[\d]{10}$/', $tmp);
	}

	// (02x) AAAA AAAA
	// (01xx) AAA BBBB
	// (01xxx) AAAAAA
	// (01AAA) BBBBB
	// (01AA AA) BBBBB
	// (01AA AA) BBBB
	// 0AAA BBB BBBB
	// 0AAA BBB BBB
	private function _phoneuk ( $number ) {

		$tmp = RemoveExtensionAndSpaces( $number );

		return preg_match( '/^\(0\d{2}\)\d{8}$/', $tmp ) ||
			   preg_match( '/^\(0\d{3}\)\d{7}$/', $tmp ) ||
			   preg_match( '/^\(0\d{4}\)\d{5,6}$/', $tmp) ||
			   preg_match( '/^\(0\d{5}\)\d{4,5}$/', $tmp) ||
			   preg_match( '/^0\d{9,10}$/', $tmp);
	}

	// 07AAA BBBBBB
	private function _mobileuk ( $number ) {

		return preg_match( '/^07\d{9}$/', str_replace( ' ', '', $number ) );
	}

}

/**
* Removes the backslashes in case their are set in the post
* of the fields from a form.
* 
* @param  $value could be an array or a string where the backslashes will be removed
* @return the element passed as parameter with no backslashed 
*/
function stripslashes_deep ( $value )
{
	if( ! get_magic_quotes_gpc() )		return $value;
	if( is_array( $value ) )			return array_map( 'stripslashes_deep', $value );
	else								return stripslashes($value);
}

/**
* Deobfuscate the values even if on an array
* 
* @param  $value could be an array or a string which need to be deobfuscated
* @return the element passed as parameter with deobfuscated
*/
function deobfuscate_deep ( $value )
{
	if( is_array( $value ) )			return array_map( 'deobfuscate_deep', $value );
	else								return obfuscate($value);
}

/**
* Does the actual deobfuscation
* 
* @param  $value string which need to be deobfuscated
* @return the element passed as parameter with deobfuscated
*/
function obfuscate ( $txt, $salt = 13 ) {
	$length = strlen( $txt );

	for( $i = 0; $i < $length; $i++ ) {
		$c = 159 - ord( $txt[$i] ) - abs( $salt ) % 94;
		$txt[$i] = chr( $c < 33 ? $c + 94 : $c );
	}

	return $txt;
}

/**
* Checks if a given value is a valid email address.
*
* @access  public
* @param   mixed $value  value to check
* @return  boolean
* @static
*/
function email( $value )
{
	if( ! is_string($value) )		return false;

	if( (strpos($value, '..') !== false) ||
		(!preg_match('/^(.+)@([^@]+)$/', $value, $matches)))
	{
		return false;
	}

	$localpart = $matches[1];
	$hostname  = $matches[2];

	if((strlen($localpart) > 64) || (strlen($hostname) > 255))
	{
		return false;
	}

	$atext = 'a-zA-Z0-9\x21\x23\x24\x25\x26\x27\x2a\x2b\x2d\x2f\x3d\x3f\x5e\x5f\x60\x7b\x7c\x7d\x7e';
	if(!preg_match('/^[' . $atext . ']+(\x2e+[' . $atext . ']+)*$/', $localpart))
	{
		// Try quoted string format

		// Quoted-string characters are: DQUOTE *([FWS] qtext/quoted-pair) [FWS] DQUOTE
		// qtext: Non white space controls, and the rest of the US-ASCII characters not
		// including "\" or the quote charadcter
		$noWsCtl = '\x01-\x08\x0b\x0c\x0e-\x1f\x7f';
		$qtext = $noWsCtl . '\x21\x23-\x5b\x5d-\x7e';
		$ws = '\x20\x09';
		if(!preg_match('/^\x22([' . $ws . $qtext . '])*[$ws]?\x22$/', $localpart))
		{
			return false;
		}
	}

	return (bool) preg_match("/^(?:[A-Z0-9]+(?:-*[A-Z0-9]+)*\.)+[A-Z]{2,}$/i", $hostname);
}


function getPhpConfBytes ( ) {

	$value = trim( ini_get('post_max_size') );

	switch( strtolower( substr($value, -1) ) ) {
		case 'g':
			$value *= 1024;
		case 'm':
			$value *= 1024;
		case 'k':
			$value *= 1024;
	}

	return $value;
}

//ignoring extension and spaces of phone numbers
function RemoveExtensionAndSpaces( $number ) {
	return str_replace( ' ', '', preg_replace( '/[ext]{1,3}\.?\s*[\d]+$/', '', $number ) );
}
?>