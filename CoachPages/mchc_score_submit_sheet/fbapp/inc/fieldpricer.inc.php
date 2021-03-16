<?php

/**
 * CoffeeCup Software's Web Form Builder.
 *
 * Create product definitions from form fields that the SCC cart accepts.
 *
 *
 * @version $Revision: 2456 $
 * @author Cees de Gruijter
 * @category FB
 * @copyright Copyright (c) 2011 CoffeeCup Software, Inc. (http://www.coffeecup.com/)
 */

function makeprices ( $checkoutctr ) {

	$pricer = new FieldPricer();
	$pricer->setDecimals( Config::GetInstance()->GetConfig( 'settings', 'payment_settings', 'decimals' ) );

	// first get the fixed form price
	$descr = Config::GetInstance()->GetConfig( 'settings', 'payment_settings', 'invoicelabel' );
	$price = Config::GetInstance()->GetConfig( 'settings', 'payment_settings', 'fixedprice' );
	if( $price > 0 ) {

		$prd = new Prod();
		$prd->productid = 'formid_'.$checkoutctr->GetFormName();

		// name and description should be the same unless the descr really adds info
		$prd->name = empty( $descr ) ? $prd->productid : $descr;
		$prd->shortdescription = '';

		$prd->yourprice =  $price;
		$prd->quantity = 1;
		$pricer->addProduct( $prd );
	}

	$payrules = Config::GetInstance()->GetConfig( 'payment_rules' );
	$rules = Config::GetInstance()->GetConfig( 'rules' );

	foreach( $checkoutctr->getFormPost() as $name => $value ) {

		if( isset( $payrules->$name ) && isset( $rules->$name ) ) {

			// create method name like: "field_type"_"payment_type"
			$fieldtype = $rules->$name->fieldtype . '_' . $payrules->$name->type;

			if( method_exists( 'FieldPricer', $fieldtype ) ) {

				$pricer->$fieldtype( Config::GetInstance()->GetOriginalPostKey( $name ), $value, $payrules->$name );

			} else {

				writeErrorLog( 'Missing pricer method:', $fieldtype );
			}

		}
	}

	return $pricer->getProducts();
}


/******************* field handlers *******************/

class FieldPricer {

	public  $errors;
	public  $divider = 1;							// divider/decimal places
	private $multiplier = 1;
	private $prods = array();						// priced product definitions
	private $usingInvoiceText = false;				// available AFTER a call to FieldPricer::_getDescr()

	public function addProduct ( $product )
	{
		if( $product->yourprice > 0 )
		{
			$this->prods[ $product->productid ] = $product;		
		}
		else
		{
			writeErrorLog( 'Won\'t add a line-item with a price <= 0 for: ', $product->productid );
		}
	}


	public function setDecimals ( $decimals ) {
		
		$this->divider = pow( 10, $decimals );
	}


	// this 'special' field sets the quantity for all line items, thus it must be applied
	// after all other fields in FieldPricer::getProducts()
	function number_cart_multiplier ( $name, $value, $rules ) {

		if( $value <= 0 ) return;

		$this->multiplier = $value;
	}


	public function getProducts ( ) {

		if( $this->multiplier != 1 ) {

			foreach( $this->prods as $key => &$prd ) {

				$prd->quantity = $prd->quantity * $this->multiplier;
			}

			// prevent it is applied more than once
			$this->multiplier = 1;
		}

		return $this->prods;

	}
	

	function number_quantity ( $name, $value, $rules ) {

		if( $value <= 0 ) return;

		// for a numeric field, "value" is only a number
		$prd = new Prod();
		$prd->productid = $name;
		$prd->name = $this->_getDescr( $rules, $name );
		//$prd->shortdescription = $this->_getDescr( $rules, $name );
		$prd->shortdescription = '';
		$prd->yourprice = $rules->price;
		$prd->quantity = $value;
		$this->addProduct( $prd );
	}


	function number_amount ( $name, $value, $rules ) {

		if( $value <= 0 ) return;

		// for a numeric field, "value" is only a number
		$prd = new Prod();
		$prd->productid = $name;
		$prd->name = $this->_getDescr( $rules, $name );
		//$prd->shortdescription = $this->_getDescr( $rules, $name );
		$prd->shortdescription = '';
		$prd->yourprice = $value * $this->divider;
		$prd->quantity = 1;
		$this->addProduct( $prd );
	}


	function dropdown_amount ( $name, $value, $rules ) {
		$this->radiogroup_amount( $name, $value, $rules );
	}


	function dropdown_quantity ( $name, $value, $rules ) {
		$this->radiogroup_quantity( $name, $value, $rules );
	}


	function dropdown_cart_multiplier ( $name, $value, $rules ) {
		$this->radiogroup_cart_multiplier( $name, $value, $rules );
	}


	function radiogroup_quantity ( $name, $value, $rules ) {

		if( ! isset( $rules->price ) )	return;

		$descr = $this->_getDescr( $rules, $name );

		$prd = new Prod();
		$prd->productid = $name;			// use field name as productid
		$prd->shortdescription = $descr;
		$prd->name = $this->usingInvoiceText ? $descr : $name;

		// lookup the price multiplier to use
		foreach( $rules->options as $nam => $val ) {

			if( $nam == $value ) {

				// add the product if we can find a price
				$prd->yourprice = $rules->price;
				$prd->quantity = $val;
				$prd->shortdescription = $value;

				$this->addProduct( $prd );

				break;
			}
		}
	}
	

	function radiogroup_amount ( $name, $value, $rules ) {

		$descr = $this->_getDescr( $rules, $name );

		$prd = new Prod();
		$prd->productid = $name;			// use field name as productid
		$prd->shortdescription = $descr;
		$prd->name = $this->usingInvoiceText ? $descr : $name;

		// lookup the price to use
		$idx = 0;
		foreach( $rules->options as $nam => $val ) {

			if( $nam == $value ) {

				// add the product if we can find a price
				$prd->yourprice = $val;
				$prd->shortdescription = $value;

				$this->addProduct( $prd );

				break;
			}
			++$idx;
		}
	}


	function radiogroup_cart_multiplier ( $name, $value, $rules ) {

		// lookup the cart multiplier to use
		foreach( $rules->options as $nam => $val ) {

			if( $nam == $value ) {
				$this->number_cart_multiplier( $name, $val, $rules );
				break;
			}
		}
	}


	function checkbox_amount ( $name, $value, $rules ) {
		$this->listbox_amount( $name, $value, $rules );
	}


	function checkbox_quantity ( $name, $value, $rules ) {
		$this->listbox_quantity( $name, $value, $rules );
	}


	// listboxes allow multiple selects, sum the values for the result
	function listbox_amount ( $name, $values, $rules ) {

		if( empty( $values ) )				return;
		if( ! is_array( $values ) )			$values = array( $values );

		$price = 0;
		$descr = '';

		foreach( $values as $value ) {

			// lookup the price(s) to use for each checkbox or list item
			foreach( $rules->options as $nam => $val ) {

				if( $nam == $value ) {

					$price += $val;
					$descr .= !empty( $descr ) ? ', ' . $nam : $nam;
				}
			}
		}

		// add the product if we found a price
		if( $price ) {
			$prd = new Prod();
			$prd->productid = $name;					// use field name as productid
			$prd->name = $this->_getDescr( $rules, $name );							// field value is a good name
			$prd->shortdescription = $descr;
			$prd->yourprice = $price;
			$this->addProduct( $prd );
		}
	}


	function listbox_quantity ( $name, $values, $rules ) {

		if( empty( $values ) )				return;

		foreach( $values as $value ) {

			// lookup the price(s) to use for each checkbox
			$idx = 0;
			foreach( $rules->options as $nam => $val ) {

				if( $nam == $value ) {

					// add the product if we can find a price
					$prd = new Prod();
					$prd->productid = $name . '-' . $idx ;		// use field name as productid
					$prd->name = $this->_getDescr( $rules, $name );						// field value is a good name
					$prd->shortdescription = $value;
					$prd->yourprice = $rules->price;
					$prd->quantity = $val;
					$this->addProduct( $prd );
				}
				++$idx;
			}
		}
	}


	private function _getDescr ( $rules, $defaultname ) {

		if( isset( $rules->use_invoice ) && $rules->use_invoice &&
			isset( $rules->invoice_label ) && $rules->invoice_label != '' )
		{
			$this->usingInvoiceText = true;
			return $rules->invoice_label;
		}	
		else
		{
			$this->usingInvoiceText = false;
			return (isset( $rules->label ) ? $rules->label : '') . ' ' . $defaultname;
		}
	}		
}



class Prod extends ProductProps
{
	public $quantity = 1;			// how many of this product bought

	public function getOptionItemsByIndex ( $index ) {
		return null;
	}

	public function getDefaultOptionValue ( $index ) {
		return null;
	}

	public function getExtraShipping( $method ) {
		return array( -1, 0 );
	}


}


?>