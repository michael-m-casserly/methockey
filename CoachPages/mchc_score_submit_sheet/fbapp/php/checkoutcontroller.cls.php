<?php

/*
 *	This class contains all methods that the ShoppingCart expects.
 *
 */


require CARTREVISION . '/api/checkoutcontroller.interface.php';
require CARTREVISION . '/php/shoppingCart.cls.php';
require_once CARTREVISION . '/php/utilities.inc.php';


define( 'PAYPALWPS_URL',	'https://www.paypal.com/cgi-bin/webscr' );
//define( 'PAYPALWPS_URL',	'https://www.sandbox.paypal.com/cgi-bin/webscr' );
define( 'GOOGLE_URL', 	'https://checkout.google.com/api/checkout/v2/merchantCheckout/Merchant/' );
//define( 'GOOGLE_URL',		'https://sandbox.google.com/checkout/api/checkout/v2/merchantCheckout/Merchant/' );
define( 'TOCO_URL',			'https://www.2checkout.com/checkout/purchase' );
define( 'TOCO_RELAY',		'relay2co.php' );
define( 'WORLDPAY_URL',		'https://secure.worldpay.com/wcc/purchase' );
define( 'WORLDPAY_TESTURL',	'https://select-test.wp3.worldpay.com/wcc/purchase' );
define( 'AUTHNET_URL',		'https://secure2.authorize.net/gateway/transact.dll' );

define( 'ORDERREFKEY', 'CartOrderRef' );


class CheckoutController implements CheckoutControllerInterface  {

	private $cart = false;
	private $products;

	function __construct ( ) {
	}


	public function getConfigS ( $param1, $param2 = false )	{

		// use the form config 
		$result = Config::GetInstance()->GetConfig( $param1 );
		if( $result )				return $result;

		// but if it fails, get it from the payment section
		$cfg = Config::GetInstance()->GetConfig( 'settings', 'payment_settings' );

		// json uses lower case
		$param1 = strtolower( $param1 );
		if( $param2 )	$param2 = strtolower( $param2 );

		if( ! isset( $cfg->$param1 ) )
		{
			switch( $param1 ) {

				case 'shopname':
					return FormPage::GetInstance()->GetFormName();
					break;

				case 'shipping_calcmethod':
				case 'transaction_log':
				case 'taxrates':
					return array();
				case 'shoplogo':
					//TODO, give meaningful values to missing parameters
					break;

				default:
					writeErrorLog( 'Failed to get config parameter:', $param1 );
					#var_dump( debug_backtrace(0)); die('error');
					break;
			}
				
			return false;								// param1 doesn't exist
		}

		if( $param2 ) {

			if( isset( $cfg->$param1->$param2 ) )
				return $cfg->$param1->$param2;
			else {

				// add the parameters that SCC has, but FB doesn't
				if( $param2 == 'url' ) {
					switch( $param1 ) {
						case 'paypalwps':								return PAYPALWPS_URL;
						case 'google':									return GOOGLE_URL;
						case '2co':										return TOCO_URL;
						case 'worldpay':								return WORLDPAY_URL;
						case 'authorizenetsim':							return AUTHNET_URL;
					}
				}
				if( $param1 == '2co' && $param2 == 'cc_2relay' )		return TOCO_RELAY;
				if( $param1 == 'worldpay' && $param2 == 'url_test' )	return WORLDPAY_TESTURL;
				if( $param1 == 'google' && $param2 == 'use_proxy' )		return false;
				
				#writeErrorLog( 'Failed to get config parameter:', array( $param1, $param2) );
				#var_dump($cfg);
				#var_dump( debug_backtrace(0)); die('error');
				return false;							// param2 is asked for, but doesn't exist
			}
		}

		return $cfg->$param1;
	}


	public function getProduct ( $productid, $formated = true ) {
	}


	public function getDefaultTaxLocationId ( ) {

		// // this is the originale code that was in shoppingCart.cls.php:
		// // set this to the first tax location id if it exists
		// if( $locs = $this->owner->getTaxLocations() ) {
		// // Only set the default in case none is showing in the cart page
		// 	if( count( $locs ) <= 1) {
		// 		$this->taxLocationId = key( $locs );
		// 	}
		// }

		return -1;
	}


	public function getExtraShippingDescr ( $index ) {
		return '';
	}


	public function getExtraShippingDefinitions ( ) {
		return array();
	}


	public function getExtraShippingList ( ) {
		return array();
	}


	public function getExtraShippingCosts ( $index ) {
		return (array( -1, 0 ) );
	}

	
	/**
	 * Fill the cart and prepare the page that connects to the payment processor
	 *
	 * @return false on empty cart, string with html-table definition on success.
	 */
	public function PreparePayment ( ) {

		// ensure there isn't anything left from a previous session
		$this->getCartInstance()->emptyCart();

		// translate the form into 'products'
		include 'fbapp/inc/fieldpricer.inc.php';
		$this->products = makeprices( $this );

		if( count( $this->products ) == 0 ) {
    		return false; 
		}

		// add these 'products' to the cart
		foreach( $this->products as $prd ) {
			$this->cart->addProduct( $prd, $prd->quantity, '');
		}

		MessagePostMerger::GetInstance()->cart = $this->getCartInstance();

		// build html table to show order (must be a html doc with encoding meta tag or else DomDocument gets upset)
		$html = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8">'
			  .  '<style>td{margin:5px;}</style></head><body>';

		$confirmpayment = Config::GetInstance()->GetConfig( 'settings', 'payment_settings', 'confirmpayment' );
		if( ! empty( $confirmpayment ) ) {

			MessagePostMerger::GetInstance()->setDecimals( $this->getConfigS( 'decimals' ) );
			$html .= MessagePostMerger::GetInstance()->SubstituteFieldNames( $confirmpayment );
		
		} else {

			MessagePostMerger::GetInstance()->GetHtmlCartTable( $html );
		}

		switch( Config::GetInstance()->GetConfig( 'settings', 'payment_settings', 'paymenttype' ) )
		{
			case 'invoice':
				$html .= $this->_getInvoiceButton();
				break;

			case 'redirect':
			    Config::GetInstance()->SetSessionVariableFromPost( CC_FB_URLEMBEDDED );
				$html .= $this->_getPayPalWPSButton();
		//		$html .= $this->_getWorldPayButton();
				$html .= $this->_getGoogleButton();
				$html .= $this->_getAuthNetButton();
				$html .= $this->_getToCheckoutButton();
				break;

			case 'sdrive':
				// not yet implemented
		}

		$html .= '</body></html>';
	
		return $html;
	}


	public function getFormName ( ) {
		return FormPage::GetInstance()->getFormName();
	}


	public function getFormPost ( ) {
		return FormPage::GetInstance()->post;
	}


	public function getCartInstance ( ) {

		if( $this->cart === false ) 		$this->cart = new ShoppingCart( $this );

		return $this->cart;
	}


	public function lockCart( $lock ) {
		$this->cart->lock = $lock;
	}


	public function getShopLogoUrl ( ) {

		return $this->getConfigS( 'transaction_log' ) ? getFullUrl( 'servicepp.php' ) : '';
	}


	public function getTransactionLogUrl ( ) {

		if( $this->getConfigS('shoplogo') )
			return getFullUrl( $this->getConfigS('shoplogo'), false );

		return '';
	}


	public function setCartMessage ( $msg ) {
	}


	
	public function saveCartToSession ( )
	{
		if( $this->cart )		$this->cart->saveCart();
	}


	public function saveCartToDB ( ) {

		$data = $this->getCartInstance()->exportCart();
		$data[ 'status' ] = 'redirect';
		$data[ 'testmode' ] = -1;

		// get existing order reference in case this is an update instead of a new transaction
		$transactid = Config::GetInstance()->GetSessionVariable( ORDERREFKEY );

		if( ! TransactionLogger::GetInstance()->saveData( $data, $transactid ) ) {

			// unset the session variable to ensure the cart scripts don't use empty values
			Config::GetInstance()->UnsetSessionVariable( ORDERREFKEY );	
			Config::GetInstance()->UnsetSessionVariable( CC_FB_CARTID );

			writeErrorLog( 'Failed to write transaction details to the database', TransactionLogger::GetInstance()->getError() );
			return false;
		}	

		// add the transaction id to the session for the cart
		Config::GetInstance()->SetSessionVariable( ORDERREFKEY, $transactid );
		// store a copy for FB, avoids the need to including the cart when getting feedback from a gateway
		Config::GetInstance()->SetSessionVariable( CC_FB_CARTID, $transactid );

		return $transactid;
	}


	private function _getPayPalWPSButton ( ) {
		
		if( ! $this->getConfigS('PayPalWPS', 'enabled') )		return '';

		include CARTREVISION . '/php/checkoutpps.cls.php';

		$checkout = new CheckoutPPS( $this );
		$checkout->setReturnUrl( urldecode( Config::GetInstance()->GetSessionVariable( CC_FB_URLEMBEDDED ) ) . '?action=checkedout' );
		$checkout->setCancelUrl( urldecode( Config::GetInstance()->GetSessionVariable( CC_FB_URLEMBEDDED ) ) . '?action=cancel' );

		$html = '<form target="_top" style="display:inline;" action="' . $this->getConfigS('PayPalWPS', 'URL') . '" method="POST">'
			  . $checkout->getCheckoutFields()
			  . '<input type="submit" id="fb_paypalwps" name="_xclick" value="Proceed to PayPal" /></form>';

		return $html;
	}


	private function _getWorldPayButton ( ) {

		if( ! $this->getConfigS('WorldPay', 'enabled') )		return '';

		include CARTREVISION . '/php/checkoutwpay.cls.php';

		$checkout = new CheckoutWPay( $this );
//		$checkout->setReturnUrl( getFullUrl( false, false ) . '?action=checkedout' );
//		$checkout->setCancelUrl( getFullUrl( false, false ) . '?action=cancel' );

		if( $this->getConfigS('WorldPay', 'TEST_MODE') )
			$url_selector = 'URL';
		else
			$url_selector = 'URL_TEST';

		$html = '<form target="_top" style="display:inline;" action="' . $this->getConfigS('WorldPay', $url_selector) . '" method="POST">'
			  . $checkout->getCheckoutFields()
			  . '<input type="submit" id="fb_worldpay" name="_xclick" value="Proceed to WorldPay" /></form>';

		return $html;
	}


	private function _getGoogleButton ( ) {

		if( ! $this->getConfigS('Google', 'enabled') )		return '';

		// add a hidden post field so that the formcontroller knows it needs to delegate to the checkoutcontroller 
		$html = '<form target="_top" style="display:inline;" action="' . getFullUrl( false, false ) . '" method="POST">'
			  . '<input type="hidden" name="_checkout_redirect" value="_GooglePay">'
			  . '<input type="submit" id="fb_googlepay" value="Proceed to Google Pay" /></form>';
		
		// the google button doesn't contain hidden fields, it does a redirect when
		// CheckoutController::DoGoogleCheckout() is called
		$this->getCartInstance()->saveCart();

		return $html;
	}


	public function DoGoogleCheckout ( ) {

		if( ! $this->getConfigS('Google', 'enabled') ) {
			writeErrorLog('Warning: received a request for a redirect to Google but Google Checkout is not configured.');
			return 'Google Checkout configuration is missing.';
		}

		include CARTREVISION . '/php/checkoutgc.cls.php';

		$checkout = new CheckoutGC( $this );
		$checkout->setReturnUrl( urldecode( Config::GetInstance()->GetSessionVariable( CC_FB_URLEMBEDDED ) ) . '?action=checkedout' );
//		$checkout->setCancelUrl( getFullUrl( false, false ) . '?action=cancel' );

		// get checkout fields and redirect
		if( ! $checkout->doCheckOut() )					return $checkout->resArray['MESSAGE'];
	}


	private function _getAuthNetButton ( ) {

		if( ! $this->getConfigS('AuthorizeNetSIM', 'enabled') )		return '';

		include CARTREVISION . '/php/checkoutans.cls.php';

		$checkout = new CheckoutANS( $this );
		$checkout->setReturnUrl( urldecode( Config::GetInstance()->GetSessionVariable( CC_FB_URLEMBEDDED ) ) . '?action=checkedout' );
//		$checkout->setCancelUrl( getFullUrl( false, false ) . '?action=cancel' );

		$html = '<form target="_top" style="display:inline;" action="' . $this->getConfigS('AuthorizeNetSIM', 'URL') . '" method="POST">'
			  . $checkout->getCheckoutFields()
			  . '<input type="submit" id="fb_authnet" name="_xclick" value="Proceed to Auth.Net" /></form>';

		return $html;
	}


	private function _getToCheckoutButton ( ) {

		if( ! $this->getConfigS('2CO', 'enabled') )		return '';

		include CARTREVISION . '/php/checkout2co.cls.php';

		$checkout = new Checkout2CO( $this );
		$checkout->setReturnUrl( urldecode( Config::GetInstance()->GetSessionVariable( CC_FB_URLEMBEDDED ) ) . '?action=checkedout' );
//		$checkout->setCancelUrl( getFullUrl( false, false ) . '?action=cancel' );

		$html = '<form target="_top" style="display:inline;" action="' . $this->getConfigS('2CO', 'URL') . '" method="POST">'
			  . $checkout->getCheckoutFields()
			  . '<input type="submit" id="fb_2checkout" name="_xclick" value="Proceed to 2Checkout" /></form>';

		return $html;
	}

	private function _getInvoiceButton ( )
	{
		$html = '<form style="display:inline;" action="" method="POST">'
			  . '<input type="submit" id="fb_invoice" name="_xclick_invoice" value="Send Invoice" /></form>';

		return $html;
	}
}


?>