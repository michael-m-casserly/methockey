<?php

/**
 * CoffeeCup Software's Web Form Builder.
 *
 * Base class for anything related to the page.
 *
 *
 * @version $Revision: 2456 $
 * @author Cees de Gruijter
 * @category FB
 * @copyright Copyright (c) 2011 CoffeeCup Software, Inc. (http://www.coffeecup.com/)
 */

require_once 'fbapp/inc/utilities.inc.php';


define( 'HTMLENTITY_FLAGS', ENT_COMPAT );

// this define is used to store a copy of what is saved in session[ORDERREFKEY], but
// that define is part of the shopping cart scripts, that need not be included when
// recieving feedback from the gateways
define( 'CC_FB_CARTID', 'FormBuildersCartId' );


class FormPage {

	private static $instance = null;	// me...for singleton
	private $name = '';					// name of the form
	public $source = false;				// form markup to display
	private $errors = array();			// validation errors
	public $post = false;				// any valid data the user has send
	private $stats = false;				// statistics reporter

	private $mysql = false;				// db instance
	private $sqlite = false;			// db instance
	private $csv = false;				// db instance
	private $email = false;				// emailer
	public	$uploads = array();			// remember upload-fields relationship, info is needed for reporting


	private function __construct ( $formname ) {
		$this->name = $formname;
	}


	// singleton instance is only created when the formname is specified!
	public static function GetInstance ( $formname = false ) {

		if( ! isset( self::$instance ) && $formname !== false ) {

			$className = __CLASS__;
			self::$instance = new $className( $formname );
		}

		return self::$instance;
	}


	function ReadSource ( ) {

		$filename = $this->name . '.html';
		$this->source = file_get_contents( $filename , FILE_USE_INCLUDE_PATH );

		if( $this->source === false ) {
			writeErrorLog( 'Couldn\'t open or read:', $filename );
		}

		if( Config::GetInstance()->sdrive !== false ) {

			// sdrive has the recaptcha keys if needed
			$this->source = str_replace( '_FB_RECAPTCHA_', Config::GetInstance()->sdrive['recaptcha_public_key'], $this->source );
			$this->source = str_replace( '_FB_RECAPTCHA2_', Config::GetInstance()->sdrive['recaptcha2_public_key'], $this->source );
			$this->source = $this->_setSDriveFreebieNotice($this->source);
		}

		// changed if condition: check the URI instead of testing $this->sdrive - this should work also
		// on the sdrive version that still uses de myform.php entry point
		// match '.php' ignoring a query string that my follow it
		if( preg_match( '/\.php(?:\?.*)?$/i', $_SERVER['REQUEST_URI'] ) ) {

			// adjust paths, because source may have paths relative to its own location for stand-alone use
			// not needed for sdrive, because form is accessed like: ..../formname/ instead of ..../formname.php
				$this->source = str_replace( 'data-name=""', 'data-name="' .rawurlencode($this->name ). '/"', $this->source );
				$this->source = str_replace( 'href="theme/', 'href="' . rawurlencode($this->name) . '/theme/', $this->source );
				$this->source = str_replace( 'src="common/', 'src="' . rawurlencode($this->name) . '/common/', $this->source );
				$this->source = str_replace( 'url(common/', 'url(' . rawurlencode($this->name) . '/common/', $this->source );
				$this->source = str_replace( 'url(theme/', 'url(' . rawurlencode($this->name) . '/theme/', $this->source );
				$this->source = str_replace( 'url(\'common/', 'url(\'' . rawurlencode($this->name) . '/common/', $this->source );
				$this->source = str_replace( 'url(\'theme/', 'url(\'' . rawurlencode($this->name) . '/theme/', $this->source );
				$this->source = str_replace( '../' . rawurlencode($this->name) . '.php', rawurlencode($this->name) . '.php', $this->source );
		}
	}

	
	function SetStats( $sdrive ) {

		$this->stats = new StatsReporter( $sdrive['sdrive_account_id'],
										  $this->GetFormName(),
										  $sdrive[ 'sdrive_account_host' ],
										  $sdrive[ 'sdrive_account_formbuilder_stats' ] );
	}


	function SetPostValues ( $post ) {

		$this->post = $post;

		// since this means the data is valid and ready to store, we might as well add 
		// ip address and timestamp
		$this->post[ '_submitted_' ] = date('Y-m-d H:i:s');

		if( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {

			list( $this->post[ '_fromaddress_' ] ) = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );

		} else {

			$this->post[ '_fromaddress_' ] = $_SERVER['REMOTE_ADDR'] ;
		}
	}


	function SetErrors ( $errors ) {

		$this->errors = array_merge( $this->errors, $errors );
	}


	function GetErrors ( $assoc = false ) {

		if( !$assoc )			return $this->errors;

		$errors = array();
		foreach( $this->errors as $err ) {
			if( isset( $err['field'] ) )		$errors[ $err['field'] ] = $err['err'];
			else if( isset( $err['err'] ) )		$errors[] = $err['err'];
			else								$errors[] = $err['warn'];
		}

		return $errors;
	}


	function GetErrorCount ( ) {

		return count( $this->errors );
	}


	public function Show ( ) {
	
		if( isset( $_GET['action'] ) )
		{
			$content = $_GET['action'];
		}
		elseif( isset( $_GET[ 'merchant_return_link' ] ) &&
				$_GET[ 'merchant_return_link' ] )
		{
			// PayPal sends it's own query string when the return after payment link is clicked,
			// set our 'checkedout' state if found
			$content = 'checkedout';
		}
		else
			$content = '';

		if( Config::GetInstance()->UsePayments() || empty( $content ) ) {

			// session is needed for payments and most submits that have '?action' set
			Config::GetInstance()->InitSession();

			// clear any old sessions when showing a fresh form to clear payment stuff
			if( empty( $content ) )		Config::GetInstance()->ClearSession();
		}

		// check what content to show
		switch( $content ) {

			case 'confirmation':
			case 'checkout':
				$this->_PageFromSession();
				break;

			case 'cancel':
				$this->SetErrors( array( array( 'field' => 'Form',
								 		'err' => _T('Payment transaction cancelled.' ) ) ) );
				// fall through

			case 'back':
				// show the original page after canceling a payment transaction
				$this->RestorePostFromSession();

				$this->HandleErrors();
				echo $this->source;
				$this->_UpdateCartState( 'cancelled' );
				break;

			case 'checkedout':
				$this->_UpdateCartState( 'checked-out' );
				$this->RestorePostFromSession();

				$ctl = new FormController();
				$ctl->checkedout = true;
				$ctl->ShowUserConfirmation();
				break;

			case 'submitpayment':
				// sent by Javascript when user pushed on a 'Pay' button
				$this->RestorePostFromSession( false );		// don't clear session, it is needed upon 'checkedout'

				// ensure the merger has access to cart details
				$payment = new CheckoutController();
				MessagePostMerger::GetInstance()->cart = $payment->getCartInstance();

				// send the emails
				$this->SendEmails();

				// prepare feedback
				if( count( $this->errors ) == 0 )
					echo 'ok';
				else
					echo json_encode( $this->errors );

				break;
			
			default:
				if( $this->source === false ) 		$this->ReadSource();

				if( $this->source !== false ) 		echo $this->source;
				else								trigger_error( 'Read the template BEFORE trying to send it to a user.', E_USER_WARNING );
				break;
		}		
	}


	function ReportStats( $type, $param = false ) {
		
		if( ! $this->stats )		return;
		
		if( method_exists( $this->stats, $type ) ) {

			if( $param !== false )				$this->stats->$type( $param );
			else								$this->stats->$type();

		} else {

			writeErrorLog('Call to undefined statistcs reporter method:', $type );
		}
	}


	// return error count
	function ProcessPostedData ( ) {

		$this->_LoadDataBaseExtensions();
		$this->_LoadEmailExtensions();
		$this->_DoProcessPostedData();
		Config::GetInstance()->SetSessionVariable( 'post', $this->post );
		Config::GetInstance()->SetSessionVariable( 'uploads', $this->uploads );
		return count( $this->errors ) == 0;
	}


	private function _LoadDataBaseExtensions ( ) {

		// when on sdrive, never enable mysql storage, no matter what the config setting is
		if( ! Config::GetInstance()->sdrive &&
			Config::GetInstance()->GetConfig( 'settings', 'data_settings', 'save_database', 'is_present' ) == true ) {
			
			$this->mysql = new DataSaveMySQL( 'save_database' );
			$this->errors = array_merge( $this->errors, $this->mysql->errors );
		}

		// when on sdrive, alway enable sqlite, no matter what the config setting is
		if( Config::GetInstance()->GetConfig( 'settings', 'data_settings', 'save_sqlite', 'is_present' ) == true ||
			Config::GetInstance()->sdrive ) {

			$this->sqlite = new DataSaveSQLite( 'save_sqlite' );
			$this->errors = array_merge( $this->errors, $this->sqlite->errors );
		}

		// when on sdrive, never enable csv storage, no matter what the config setting is
		if( ! Config::GetInstance()->sdrive &&
			Config::GetInstance()->GetConfig( 'settings', 'data_settings', 'save_file', 'is_present' ) == true ) {

			$this->csv = new DataSaveCSV( 'save_file' );
			$this->errors = array_merge( $this->errors, $this->csv->errors );
		}
	}


	private function _LoadEmailExtensions ( $emptyCart = false ) {

		if( Config::GetInstance()->GetConfig( 'settings', 'email_settings', 'auto_response_message', 'is_present' ) == true ||
			Config::GetInstance()->GetConfig( 'settings', 'email_settings', 'notification_message', 'is_present') == true )
		{	
			$hasPayment = Config::GetInstance()->UsePayments();
			$hasJavascript = Config::GetInstance()->GetSessionVariable( CC_FB_JSENABLED );
			$submitPayment = $hasPayment && isset( $_GET[ 'action' ] ) && $_GET[ 'action' ] == 'submitpayment';

			// send emails for:
			if( ! $hasPayment ||				// any non-payment form
				! $hasJavascript ||				// payment form with javascript-less clients
				$submitPayment ||				// payment form with javascript clients with '?action=submitpayment'
				$emptyCart )					// payment form with empty cart
			{
				$this->email = new DataSaveMailer( 'email_settings' );
				$this->errors = array_merge( $this->errors, $this->email->errors );
				return count( $this->email->errors ) == 0;
			}
		}
		return false;
	}


	private function _DoProcessPostedData ( ) {

		if( $this->GetErrorCount() > 0 )		return false;

		// handle the data
		$this->_ProcessSignature();
		$this->_ProcessFiles();
		$this->_ProcessPost();

		if( $this->GetErrorCount() > 0 )		return false;

		// anything else we need to do before returning to the user
		$mailchimpList = Config::GetInstance()->GetConfig( 'settings', 'mailchimp', 'lists' );
		if( $mailchimpList && is_array( $mailchimpList ) ) {
				
			foreach( $mailchimpList as $list ) {

				if( isset( $list->is_present ) && $list->is_present ) {

					$mc = new MailChimp();
					$mc->Dispatch( Config::GetInstance()->GetConfig( 'settings',  'mailchimp') );

					// dispatcher loops through the lists, thus no need to continue here
					break;
				}
			}
		}
	}


	public function SaveTransactionId ( $transactid ) {

		if( $this->mysql !== false ) {
			if( ! $this->mysql->SaveTransactionId( $transactid ) )
				$this->errors = array_merge( $this->errors, $this->mysql->errors );
		}

		if( $this->sqlite ) {
			if( ! $this->sqlite->SaveTransactionId( $transactid ) )
				$this->errors = array_merge( $this->errors, $this->sqlite->errors );
		}
	}


	function HandleErrors ( ) {

		// check the configuration to determine what to do
		switch ( Config::GetInstance()->GetConfig('settings', 'validation_report' ) ) {

		case 'in_line':

			// get the html file and merge the errors into it
			include 'fbapp/inc/mergeformpost.inc.php';
			$this->ReadSource();

			// post data is taken from the session instead of the form after canceling a payment 
			if( $_SERVER['REQUEST_METHOD'] == 'GET')	$this->source = MergeFormPost( $this->post );
			else										$this->source = MergeFormPost( $_POST );

			break;

		case 'separate_page':
		default:
			ob_start();
			include 'fbapp/inc/displayerrors.inc.php';
		}
	}


	function GetFormName ( $encoded = false ) {
		return $encoded ? rawurlencode( $this->name ) : $this->name;
	}


	private function _UpdateCartState ( $state ) {

		$cartid = Config::GetInstance()->GetSessionVariable( CC_FB_CARTID );

		if( ! $cartid )			return;

		$rowid = preg_replace('/[^\d]+/', '', $cartid );

		if( $this->mysql )		$this->mysql->UpdateCart ( $rowid, $state );
		if( $this->sqlite )		$this->sqlite->UpdateCart ( $rowid, $state );
	}



	private function _ProcessFiles ( ) {

		if( empty( $_FILES ) )		return;

		if( $this->mysql !== false ) {
			$this->mysql->SaveUploads();
			$this->errors = array_merge( $this->errors, $this->mysql->errors );
		}

		//check if there is a file upload that must be saved even without database
		$this->_SaveUploadsAsFiles();
		if( $this->sqlite )		$this->sqlite->UpdatePost( $this->post );
		if( $this->csv )		$this->csv->UpdatePost( $this->post );
	}

	private function _ProcessSignature ( ) {

		if( Config::GetInstance()->GetRulePropertyByName( 'sigpad', 'sigpad' ) != 'enable' ) {
				return;
		}

		$dest = Config::GetInstance()->GetStorageFolder( 1 );
		if( ! is_dir( $dest ) && !mkdir( $dest, 0755 ) )
		{
			$this->errors[] = array("err" => _T('Could not create file upload directory "%s" for the signature', $dest ) );
			return;
		}

		$prefix = MessagePostMerger::GetInstance()->SubstituteFieldNames( $this->post['sigpad']['sigpad-prefix'], false );
		$filename = $prefix . '_' . makeRandomString() . '.png';

		include 'fbapp/inc/signature-to-image.php';
		$json = $this->post['sigpad']['sigpad-output'];

		// Set drawMultiplier to 4 to avoid memory issues.
		$img = sigJsonToImage($json, array( 
			'imageSize' => array($this->post['sigpad']['sigpad-width'], $this->post['sigpad']['sigpad-height']), 
			'drawMultiplier'=> 4 ));

		imagepng($img, $dest . $filename );
		imagedestroy($img);

		// store the name in post
		$this->post[ 'sigpad' ] =  $filename;
		
		// add the file to the uploads array
		$this->uploads[] = array( 'orgname' =>  $filename,
						  'storedname' => $filename,
						  'fieldname' => 'sigpad' );

		if( $this->mysql )		$this->mysql->UpdatePost( $this->post );
		if( $this->sqlite )		$this->sqlite->UpdatePost( $this->post );
		if( $this->csv )		$this->csv->UpdatePost( $this->post );
  }
  
	private function _SaveUploadsAsFiles ( ) {

		$dest = Config::GetInstance()->GetStorageFolder( 1 );
		$tempuploads = array();

		if( ! is_dir( $dest ) && !mkdir( $dest, 0755 ) )
		{
			$this->errors[] = array("err" => _T('Could not create file upload directory "%s"', $dest ) );
			return;
		}

		foreach( $_FILES as $fieldname => $filedata )
		{
			if( empty( $filedata['tmp_name'] ) )
				continue;

			// it isn't a upload if the file isn't mentioned in the rules
			if( Config::GetInstance()->GetRulePropertyByName( $fieldname, 'fieldtype' ) != 'fileupload' )
				continue;

			// check if the file must be saved on the server
			if( ! Config::GetInstance()->GetRulePropertyByName( $fieldname, 'files' ) )
			{
				
				// filename may or may not have an extension that must be preserved
				$pos = strrpos( $filedata['name'], '.' );
				$basename = $filedata['name'];
				$uploadname = $basename;
				if( $pos === false )
				{
					$uploadname = $basename  . '_' . makeRandomString();
				}
				else
				{
					$uploadname = substr( $basename, 0, $pos )  . '_' . makeRandomString() . substr( $basename, $pos );
				}
				
				// store the name in post
				$this->post[ strtolower($fieldname) ] =  $uploadname;

				// without payments - emailer looks for the file in its temp storage
				// with payments and JS - emailer is called from the checkout screen, thus we need
				// 		to move the file to our own temp storage.
				if( Config::GetInstance()->UsePayments() &&
					Config::GetInstance()->GetSessionVariable( CC_FB_JSENABLED ) &&
					Config::GetInstance()->GetRulePropertyByName( $fieldname, 'attach' ) )
				{
					$tempname = SaveUploadAsFile( Config::GetInstance()->GetStorageFolder( 5 ), $filedata );
					if( $tempname !== false )		$tempuploads[ strtolower($fieldname) ] = $tempname;
				}

				continue;
			}

			$storedname = SaveUploadAsFile( $dest, $filedata );

			// add it to post, mailer needs it if the file is to be attached
			if( $storedname !== false )		$this->post[ strtolower($fieldname) ] = $storedname;

			// remember which files are stored for which fields, we need that info
			// when reporting data, because in that context we don't have access to the rules
			$this->uploads[] = array( 'orgname' => $filedata['name'],
									  'storedname' => $storedname,
									  'fieldname' => strtolower($fieldname ) );
		}

		if( count( $tempuploads ) > 0 )
			Config::GetInstance()->SetSessionVariable( CC_FB_TEMPUPLOADS, $tempuploads ); 
	}
	

	private function _ProcessPost ( ) {

		if( $this->mysql !== false ) {
			$this->mysql->Save();
			$this->mysql->UpdateStoredFileIds();
			$this->mysql->SaveUploadsRef( $this->uploads );
			$this->errors = array_merge( $this->errors, $this->mysql->errors );
		}

		if( $this->sqlite ) {
			$this->sqlite->Save();
			$this->sqlite->SaveUploadsRef( $this->uploads );
			$this->errors = array_merge( $this->errors, $this->sqlite->errors );
		}

		if( $this->csv !== false ) {
			$this->csv->Save();
			$this->errors = array_merge( $this->errors, $this->csv->errors );
		}

		// email messages can be customized and may contain cart fields for substitution
		// therefor we can't call this before the payment data has been processed
		if( ! Config::GetInstance()->UsePayments() )		$this->SendEmails();
	}


	// Payment enabled with an empty cart needs to be handled as if there is no payment
	public function SendEmails ( $emptyCart = false )
	{
		if( $this->email !== false || $this->_LoadEmailExtensions( $emptyCart ) )
		{
			// don't send emails when over submit limit on our servers
			if( Config::GetInstance()->isOverSubmitLimit() )
			{
				return;
			}

			$this->email->Save();
			$this->errors = array_merge( $this->errors, $this->email->errors );
		}
	}


	// make a page based on the custom html from the user
	function PrepareConfirmPage ( ) {

		$this->source = Config::GetInstance()->GetConfig( 'settings', 'redirect_settings', 'confirmpage' );
		 
		if( empty( $this->source ) ) {

			$this->source = false;
			return;

		} else if( empty( $this->post ) )
			return;

		if( Config::GetInstance()->UsePayments() ) {
			// restore cart data from session
			Config::GetInstance()->InitSession();	
			$payment = new CheckoutController();
			MessagePostMerger::GetInstance()->cart = $payment->getCartInstance();

			MessagePostMerger::GetInstance()->setDecimals( Config::GetInstance()->GetConfig( 'settings', 'payment_settings', 'decimals' ) );
		}

		$this->source = MessagePostMerger::GetInstance()->SubstituteFieldNames( $this->source );
	}


	// substitute form contents by the custom html from the user
	function PrepareInlineMessage ( $messagenode, $replaceFormByDiv = false ) {

		// get the html
		$this->ReadSource();
		$dom = new DOMDocument('1.0', 'UTF-8');
		$previous_value = libxml_use_internal_errors(true);
		$success = $dom->loadHTML( $this->source );
		libxml_clear_errors();
		libxml_use_internal_errors($previous_value);

		if( $success === false ) {
			writeErrorLog('Failed to parse HTML form.');
			return false;
		}

		// find the container for the message
		$container = $dom->getElementById( 'fb_confirm_inline' );
		if( ! $container ) {
			writeErrorLog('Parsed HTML form, but can\'t locate element with id "#fb_confirm_inline".');
			return false;
		}

		// remove all child nodes 
		while( $container->hasChildNodes() ) {
			$container->removeChild( $container->firstChild );
		}

		// add our html and change the 'display:none' style to 'display:block' 
		$usernode = $dom->importNode( $messagenode, true );
		$container->appendChild( $usernode );
		$style = str_replace( 'none', 'block', $container->getAttribute( 'style' ) );
		$container->setAttribute( 'style', $style );

		// remove all siblings, go up to parent ... until we arrive at the form 
		do {

			while( $container->previousSibling ) {
				$container->parentNode->removeChild( $container->previousSibling ); 
			}

			while( $container->nextSibling ) {
				$container->parentNode->removeChild( $container->nextSibling ); 
			}

			$container = $container->parentNode;

		} while( $container->getAttribute( 'id' ) != 'docContainer' );

		// replace the form by a div container, because forms can't be nested
		if( $replaceFormByDiv ) {

			$old = $dom->getElementById( 'docContainer' );
			$new = $dom->createElement( 'div' );
			$new->setAttributeNode( $old->getAttributeNode( 'id' ) );
			$new->setAttributeNode( $old->getAttributeNode( 'class' ) );
			if( $old->hasAttribute( 'style' ) )
				$new->setAttributeNode( $old->getAttributeNode( 'style' ) );
			$new->appendChild( $old->firstChild );
			$old->parentNode->replaceChild( $new, $old );
		}

		$this->source = $dom->saveHTML();

		return true;
	}


	private function _PageFromSession ( ) {

		Config::GetInstance()->InitSession();
		$code = Config::GetInstance()->GetSessionVariable( 'code' );

		if( $code )
		 {
			// serve this contents only once
			echo $code;
			Config::GetInstance()->UnsetSessionVariable( 'code' );
			return;

		} else {

			// a second time, redirect to the original form (i.e. remove the query part)
			ob_end_clean();
			header( 'Location: ' . getUrl( '' ) );
			exit();
		}
	}


	// restore if possible, else redirect to original form
	function RestorePostFromSession ( $clearSession = true ) {

		$this->post = Config::GetInstance()->GetSessionVariable( 'post' );

		if( $clearSession )		Config::GetInstance()->UnsetSessionVariable( 'post' );

		if( $this->post )
		{
			// also restore the uploads table, possibly needed for sending emails
			$this->uploads = Config::GetInstance()->GetSessionVariable( 'uploads' );
		}
		else
		{
			header( 'Location: ' . getUrl( '' ) );
			exit();
		}
	}

	// Set the SDrive Freebie notice
	private function _setSDriveFreebieNotice($source) {
		if(Config::GetInstance()->sdrive['sdrive_user_tier'] > 0) {
			return $source;
		}

		if(preg_match('/<div[^>]*id="fb_error_report"[^>]*>/i', $source)) {
			return preg_replace('/<div[^>]*id="fb_error_report"[^>]*>/i', '<div id="fb_error_report">' . Config::GetInstance()->sdrive['freebie_tier_notice'], $source);
		}

		return preg_replace('/</form>/i', '<div id="fb_error_report">' . Config::GetInstance()->sdrive['freebie_tier_notice'] . '</div></form>', $source);
	}

}


?>