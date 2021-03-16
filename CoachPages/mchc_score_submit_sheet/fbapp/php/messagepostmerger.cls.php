<?php
/* 
 * Only call this class using GetInstance(), because information is added (such as cart) as it becomes available. 
 *
 */

class MessagePostMerger {

	private $post;						// values that are to merged be into the document
	private $upload;

	private static $instance = null;	// me...for singleton

	private	$divider = 1;				// used for money formatting
	private $cursym;					// user defined currency symbol
	public	$cart = false;				// set this to the cart instance to include prices in merged texts


	function __construct( )
	{	
		$this->post =& FormPage::GetInstance()->post; 
		$this->uploads =& FormPage::GetInstance()->uploads;

		// string cast, because value must be printable
		$this->cursym = (string)Config::GetInstance()->GetConfig( 'settings', 'payment_settings', 'currencysymbol' );
	}

	public static function GetInstance ( )
	{
		if( ! isset( self::$instance ) )
		{
			$className = __CLASS__;
			self::$instance = new $className( );
		}

		return self::$instance;
	}


	public function setDecimals ( $decimals )
	{	
		$this->divider = pow( 10, $decimals );
	}


	function SubstituteFieldNames ( $text, $useHtmlEntities = true ) {

		$replacements = array();

		foreach( $this->post as $field => $value ) {

			// translate reserved words as needed
			if( $field[0] == '_' )		 $needles[] = '[' . _T($field) . ']';
			else						 $needles[] = '[' . $field . ']';

			$replacements[] = $this->_FormatFieldValue( $field, $value, $useHtmlEntities );
		}

		foreach ( $this->uploads as $up ) {

			$needles[] = '[' . $up[ 'fieldname' ] . ']';
			$replacements[] = $up[ 'orgname' ];
		}

		if( Config::GetInstance()->UsePayments() ) {

			$this->_PaymentFieldSubstitutions( $needles, $replacements );
			$this->_PaymentFormSubstitutions( $needles, $replacements );

			if( strpos(	$text, _T('[_cart_summary_]') ) !== false ) {

				$html = '';
				$this->GetHtmlCartTable( $html );

				// always substitute tag, even when $html is empty, to hide it from the end user
				$text = str_ireplace( _T('[_cart_summary_]'), $html, $text );
			}

		} else {

			// remove all payment tags from the text to hide them from the end user
			$text = preg_replace( '/\[\w+'._T('_invoicetext').'\]/', '', $text );
			$text = preg_replace( '/\[\w+'._T('_price').'\]/', '', $text );
			$text = preg_replace( '/\[cart_\w+\]/', '', $text );
		}

		$text = str_ireplace( $needles, $replacements, $text );

		if( strpos(	$text, _T('[_form_results_]') ) !== false ) {

			$text = str_ireplace( _T('[_form_results_]'), $this->_FormatFormContents( $useHtmlEntities ), $text );

		} else {

			foreach ( Config::GetInstance()->GetReservedFields() as $name ) {

				// only include those words that are present in post, else remove tag
				if( isset( $this->post[ $name ] ) )
				{
					// the text being changed is expected to be in the language of the app,
					// e.g "_submitted_" is expected to be "_enviado_el_" in the spanish version
					$text = str_ireplace( '[' . _T($name) . ']', $this->post[ $name ], $text );
				}
				else
				{
					$text = str_ireplace( '[' . _T($name) . ']', '', $text );
				}
			}
		}

		// anything not substituted by now is not present in the form, let's remove the placeholders
		$fieldnames = Config::GetInstance()->GetFieldNames();
		foreach( $fieldnames as $field ) {

			if( stripos( $text, '[' . $field .']' ) !== false )
				$text = str_ireplace( '[' . $field .']', '', $text );
		}

		return $text;
	}


	private function _FindLabel ( $key ) {

		static $labels = array();

		$labels = array('_transactid_' => _T('Transaction ID'),
						'lineitemcount' => _T('Line Item Count'),
						'gatewayref' => _T('Gateway Reference'),
						'status' => _T('Status'),
						'grandtotal' => _T('Grand Total'),
						'_submitted_' => _T('Submitted On'),
						'_fromaddress_' => _T('IP Address') );
		
		return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
	}


	private function _FindLabelByFieldName ( $name ) {
		
		$label = Config::GetInstance()->GetConfig( 'rules', $name, 'label' );
		return $label ? $label : $name;
	}


	private function _FormatFormContents ( $useHtmlEntities = true ) {
		
		$form_contents = '<table style="width: 100%;"><tbody style="vertical-align: text-top;line-height: 30px;">';

		// use the rules to list output in the right order
		foreach( Config::GetInstance()->GetFieldNames() as $name )
		{	
			// first check if the post value exist
			if( isset( $this->post[ $name ] ) && !empty( $this->post[ $name ] ) ) {

				$form_contents .= '<tr><td style="font-weight: bold; width:30%;">' . $this->_FindLabelByFieldName( $name )
							   .  ':</td><td>'. $this->_FormatFieldValue( $name, $this->post[ $name ], $useHtmlEntities ) . '</td></tr>';

			}
			// check the file uploads only if the post[] doesn't exist, 
			// this avoids listing the same file twice which may happen when
			// saving for sqlite and csv adds these fields to post, but
			// for mysql with files stored in a table this doesn't happened
			elseif( isset( $this->uploads[ $name ] ) ) {

				$form_contents .= '<tr><td style="font-weight: bold; width:30%;">' . $this->_FindLabelByFieldName( $name )
							   .  ':</td><td>'.  $this->uploads[ $name ][ 'orgname' ] . '</td></tr>';
			}
		}

		// finally add the reserved keywords
		foreach( Config::GetInstance()->GetReservedFields() as $name ) {

			// only include those words that are present in post
			if( ! isset( $this->post[ $name ] ) )				continue;

			$form_contents .= '<tr><td style="font-weight: bold; width:30%;">' . $this->_FindLabel( $name )
						   .  ':</td><td>'
						   . ($name == '_submitted_' ? Config::GetInstance()->ApplyUserTimezone( $this->post[ $name ] ) : $this->post[ $name ])
						   . '</td></tr>'; 
		}

		$form_contents .= '</tbody></table>';

		return $form_contents;
	}


	private function _FormatFieldValue ( $field, $value, $useHtmlEntities ) {

		if( Config::GetInstance()->GetRulePropertyByName( $field, 'fieldtype' ) == 'date' && ! empty( $value ) )
			return date( Config::GetInstance()->GetDateFormatByFieldname( $field ), $value );

		if( Config::GetInstance()->GetRulePropertyByName( $field, 'fieldtype' ) == 'textarea' )
			return nl2br( $useHtmlEntities ? htmlentities( $value, HTMLENTITY_FLAGS, 'UTF-8' ) : $value );

		if( is_array( $value ) )
		{
			$value = implode( $value, ', ');
		}
		elseif( $field == '_submitted_' )
		{
			return Config::GetInstance()->ApplyUserTimezone( $value );
		}

		return $useHtmlEntities ? htmlentities( $value, HTMLENTITY_FLAGS, 'UTF-8' ) : $value;
	}


	private function _PaymentFieldSubstitutions ( &$needles, &$replacements ) {

		$pr = Config::GetInstance()->GetConfig( 'payment_rules' );

		foreach( $pr as $name => $rule ) {

			$needles[] = '[' . $name . _T('_invoicetext]');

			if( isset( $rule->use_invoice ) &&
				$rule->use_invoice &&
				! empty( $rule->invoice_label ) )
			{
				$replacements[] = $rule->invoice_label;
			}
			else
			{
				$replacements[] = $name;
			}
			
			if( $this->cart ) {
				$needles[] = '[' . $name . _T('_price]');
				if( ($price = $this->cart->getSubtotalPriceProduct( $name )) )
					$replacements[] = $this->cursym . formatMoney( $price, $this->divider );
				else
					$replacements[] = '';
			}
		}
	}


	private function _PaymentFormSubstitutions ( &$needles, &$replacements ) {

		$needles[] = _T('[_form_invoicetext_]');
		$needles[] = _T('[_form_price_]');
		$needles[] = _T('[_cart_total_]');

		if( ! $this->cart )
		{
			// cart may be empty
			$replacements[] = '';
			$replacements[] = '';
			$replacements[] = '';
			return;
		}

		if( ($price = $this->cart->getSubtotalPriceProduct( FormPage::GetInstance()->GetFormName() )) )
		{
			$replacements[] = $this->cart->getName( FormPage::GetInstance()->GetFormName() );
			$replacements[] = $this->cursym . formatMoney( $price, $this->divider );
		}
		else
		{
			$replacements[] = '';
			$replacements[] = '';
		}

		$replacements[] = $this->cursym . formatMoney( $this->cart->getGrandTotalCart(), $this->divider );
	}


	public function GetHtmlCartTable ( &$html ) {

		// cart MUST be set before this can work
		if( ! $this->cart )			return false;

		$html .= '<table id="cart" style="width: 100%;"><tr><th>Description</th><th>Qty</th><th>Price</th><th>Total</th></tr>';

	 	foreach( $this->cart->getPairProductIdGroupId() as $article ) {

	  		$cid =& $article['cartid'];

	  		$prod_name = $this->cart->getName( $cid);
	  		if( $this->cart->getDescr( $cid) != '') {
	   			$prod_name .= ':' . $this->cart->getDescr( $cid);
	  		}


	  		$html .= '<tr><td>' . $prod_name . '</td>'
	      		. '<td style="text-align:right;">' . $this->cart->getUnitsOfProduct( $cid ) . '</td>'
	       		. '<td style="text-align:right;">' . $this->cursym . number_format( $this->cart->getPrice( $cid ) / 100, 2, '.', '' ) . '</td>'
	      		. '<td style="text-align:right;">' . $this->cursym . number_format( $this->cart->getSubtotalPriceProduct( $cid ) / 100, 2, '.', '' ) . '</td></tr>';
	 	}

	 	$html .= '<tr><td colspan="3" style="text-align:right;">Order Total:</td>';
	 	$html .= '<td style="text-align:right;">' . $this->cursym . number_format( $this->cart->getGrandTotalCart() / 100, 2, '.', '' ) . '</td></tr>';
		$html .= '</table>';

		return true;
	}

}


?>