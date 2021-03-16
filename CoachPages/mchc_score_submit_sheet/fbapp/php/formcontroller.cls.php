<?php

/**
 * CoffeeCup Software's Web Form Builder.
 *
 * Form controller, routes POST/GET requests to the appropriate scripts.
 *
 *
 * @version $Revision: 2456 $
 * @author Cees de Gruijter
 * @category FB
 * @copyright Copyright (c) 2011 CoffeeCup Software, Inc. (http://www.coffeecup.com/)
 */

class FormController  {

	private $invoiceSent = false;			///< set this to true when invoice has been mailed
	public $checkedout = false;				///< set this to true when action='checkedout' was used


	public function Dispatch ( ) {

		if( isset( $_POST[ '_checkout_redirect'] ) &&
			method_exists( 'FormController', $_POST[ '_checkout_redirect'] ) )
		{
			// used by some payment gateways, eg GooglePay
			$this->$_POST['_checkout_redirect']();
		}
		elseif( isset( $_POST['_xclick_invoice'] )  )
		{
			// the option to send and invoice by mail
			Config::GetInstance()->InitSession();
			FormPage::GetInstance()->RestorePostFromSession( false );
			
			$this->_SendInvoice();
			$this->ShowUserConfirmation();
			exit();
		}
		else
		{
			// regular post of the initial form
			$this->_HandleFormPost();
		}

		// show whatever result there is
		FormPage::GetInstance()->HandleErrors();
	}


	private function _HandleFormPost ( )
	{
		// validate the input
		include 'fbapp/inc/validator.inc.php';

		$r = ValidateInput();
			
		FormPage::GetInstance()->ReportStats( 'NotifyFormSubmit', $r );

		// remember if this client uses javascript
		Config::GetInstance()->SetSessionVariableFromPost( CC_FB_JSENABLED );

		// process input if 0 errors encountered
		if( $r == 0 && FormPage::GetInstance()->ProcessPostedData() )
		{
			$this->ShowUserConfirmation();
		}
	}


	function ShowUserConfirmation ( )
	{
		// return to the user according to the settings
		$action = Config::GetInstance()->GetConfig( 'settings', 'redirect_settings', 'type' );
		if( $action === false ) 	$action = 'default';

		// handle the payment first because it adds an additional 'post'-form step
		if( ! $this->checkedout &&
			Config::GetInstance()->UsePayments() &&
			$this->_PreparePayment() )
		{
			// save post information that might be needed after payment
			Config::GetInstance()->SetSessionVariableFromPost( CC_FB_EMBEDDED );
			Config::GetInstance()->SetSessionVariableFromPost( CC_FB_CUSTOMHTML );
			exit();
		}

		switch( $action ) {

		case 'gotopage':
			$this->Redirect();
			break;
		
		case 'inline':
			$this->_ShowInlinePage();
			break;


		case 'confirmpage':
		default:

			$this->_ShowConfirmPage();
			exit( 0 );
		}
	}


	function Redirect ( )
	{
		$url = Config::GetInstance()->GetConfig( 'settings', 'redirect_settings', 'gotopage' );

		if( empty($url) ) {

			$url = "http://www.coffeecup.com/form-builder/";

		} elseif( !url( $url) && !preg_match( '/^(?:ftp|https?):\/\//xi' , $url ) ) {
			isset( $_SERVER['HTTPS'] ) ? $proto = "https" : $proto = "http";
			$url = sprintf( '%s://%s', $proto, $url );
		}

		ob_end_clean();

		// if this came from an iframe, we must force the browser to break out to the parent frame
		if( isset( $_POST[ CC_FB_EMBEDDED ] ) || Config::GetInstance()->GetSessionVariable( CC_FB_EMBEDDED ) )
		{
			echo '<html><script type="text/javascript">'
			   . 'top.location.href = "' . $url . '";'
			   . '</script></html>';

		}  else {

			header( "Location: " . $url );
		}

		exit( 0 );
	}


	private function _ShowConfirmPage ( )
	{
		FormPage::GetInstance()->PrepareConfirmPage();
		Config::GetInstance()->SetSessionVariable( 'code', FormPage::GetInstance()->source );
		ob_end_clean();

		// redirect to the user's site if the hidden field is present in post
		// requires the confirm.html file to be on the user's server and the confirm.js.php on ours
		$custom_html = Config::GetInstance()->GetSessionVariable( CC_FB_CUSTOMHTML ); 
		if( isset( $_POST[ CC_FB_CUSTOMHTML ] ) && ! empty( $_POST[ CC_FB_CUSTOMHTML ] ) )
			$custom_html = $_POST[ CC_FB_CUSTOMHTML ];
		
		if( $custom_html ) {

			$url = preg_replace( '/[^\/]*?$/', FormPage::GetInstance()->GetFormName(true) . '/confirm.html', $_POST[ CC_FB_CUSTOMHTML ], 1 );
			header( 'Location: ' .  $url );

		} else {

			// look for the .php and take a possible query string into account
			$relpath = preg_match( '/\.php(?:\?action=checkedout)?/', $_SERVER['REQUEST_URI'] ) ? FormPage::GetInstance()->GetFormName(true) . '/' : '';
			header( 'Location: ' .  $relpath . 'confirm.php' );
		}	

		exit( 0 );
	}


	private function _ShowInlinePage ( )
	{
		// get the confirm message
		$html = Config::GetInstance()->GetConfig( 'settings', 'redirect_settings', 'inline' );
		$usernode = false;

		if( ! empty( $html ) ) {

			// restore cart data from session if needed
			if( Config::GetInstance()->UsePayments() )
			{
				Config::GetInstance()->InitSession();	
				$payment = new CheckoutController();
				MessagePostMerger::GetInstance()->cart = $payment->getCartInstance();
				MessagePostMerger::GetInstance()->setDecimals( Config::GetInstance()->GetConfig( 'settings', 'payment_settings', 'decimals' ) );
			}	
			
			$html = MessagePostMerger::GetInstance()->SubstituteFieldNames( $html );
			
			// since there is no meta-tag in this html fragment, we need to tell DOMDocument
			// it with a meta-tag prefix
			$userdom = new DOMDocument();
			$previous_value = libxml_use_internal_errors(true);
			$success = $userdom->loadHTML( '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html );
			libxml_clear_errors();
			libxml_use_internal_errors($previous_value);

			if( $success ) {

				$xpath = new DOMXpath( $userdom );
				$bodies = $xpath->query( '//body' );

				if( $bodies->length > 0 )
					$root = $bodies->item( 0 );
				else
					$root = $xpath->query( '/*' )->item( 0 );

				$usernode = $userdom->importNode( $root, true );
			}
		}

		if( ! $usernode ) {
			$userdom = new DOMDocument();
			$usernode = $userdom->createTextNode ( "Thank your for filling in the form." );
		}
		FormPage::GetInstance()->PrepareInlineMessage( $usernode );

		// how to send the inline message depends on the how we ended up here:
		// - for a normal form, we redirect to 'confirmation' to avoid new posts with a refresh
		// - returning from a payment gateway, we've already been redirected, thus show the form 
		// - for a 'Send Invoice', handle toe same as for a normal form to avoid refresh problems 
		if( ! $this->checkedout || $this->invoiceSent ) {

			Config::GetInstance()->InitSession( array( 'code' => FormPage::GetInstance()->source ) );
			@ob_end_clean();				// fail silently to prevent a notice if there is no buffer

			header( 'Location: ' . getUrl( 'action=confirmation' ) );
			exit( 0 );

		} else {

			echo FormPage::GetInstance()->source;
		}
	}


	private function _PreparePayment ( )
	{
		Config::GetInstance()->InitSession();
		$payment = new CheckoutController();

		// make a cart out of the form
		if( ($html = $payment->PreparePayment()) === false )
		{
			// an empty cart is allowed if there are no required payment fields
			// but in that case any emails must be send now, because the checkout page won't be there
			FormPage::GetInstance()->SendEmails( true );

			return false;
		}

		// ensure the merger has access to cart details
		MessagePostMerger::GetInstance()->cart = $payment->getCartInstance();

		// saveCartToDB generates a new id if none was specified
		$transactid = $payment->saveCartToDB();

		// associate our transaction id to the form
		FormPage::GetInstance()->SaveTransactionId( $transactid );
		
		// prepare the page with cart info to show the user
		$userdom = new DOMDocument( '1.0', 'UTF-8' );
		$previous_value = libxml_use_internal_errors(true);
		$userdom->loadHTML( $html );
		libxml_clear_errors();
		libxml_use_internal_errors($previous_value);
		$xpath = new DOMXpath( $userdom );
		$msgnode = $xpath->query( '//body' )->item( 0 );

		FormPage::GetInstance()->PrepareInlineMessage( $msgnode, true );

		// save cart to session, needed if emails are send from the confirmation screen
		$payment->saveCartToSession();

		// send any emails, now that cart data has been made available
		FormPage::GetInstance()->SendEmails();

		// save the checkout page and the post data (needed it after payment)
		Config::GetInstance()->SetSessionVariable( 'code', FormPage::GetInstance()->source );
		Config::GetInstance()->SetSessionVariable( 'post', FormPage::GetInstance()->post );

		ob_end_clean();
		header( 'Location: ' . getUrl( 'action=checkout' ) );
		exit( 0 );
	}


	private function _SendInvoice ( )
	{
		// restore cart data from session
		Config::GetInstance()->InitSession();	
		$payment = new CheckoutController();
		MessagePostMerger::GetInstance()->cart = $payment->getCartInstance();

		// merge message with cart data and send it
		$emailer = new DataSaveMailer( 'email_settings' );
		$emailer->Save();
		FormPage::GetInstance()->SetErrors( $emailer->errors );

		// flags that determine further processing
		$this->invoiceSent = true;
		$this->checkedout = true;
	}


	private function _GooglePay ( )
	{
		Config::GetInstance()->InitSession();
		$payment = new CheckoutController();
		$msg = $payment->DoGoogleCheckout();

		if( !empty( $msg )  )
			FormPage::GetInstance()->SetErrors( array( array( 'field' => 'Form', 'err' => $msg ) ) );
	}

}
