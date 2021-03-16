<?php
/**
 * CoffeeCup Software's Web Form Builder.
 *
 * Base class for Page Extension modules. It's purpose is to supply a stable interface
 * to any function the derived classes may need from Page.  
 *
 *
 * @version $Revision: 2456 $
 * @author Cees de Gruijter
 * @category FB
 * @copyright Copyright (c) 2012 CoffeeCup Software, Inc. (http://www.coffeecup.com/)
 */

class PageExtension {

	function __construct ( ) {
	}


	// if $fieldname is an array, the corresponding map with post values is returned
	// returns false if key(s) not found in post
	protected function getPost ( $fieldname = false ) {

		if( $fieldname === false )		return FormPage::GetInstance()->post;

		if( is_object( $fieldname ) ) {

			$output = array();
			foreach( $fieldname as $mcname => $postname ) {

				if( !empty( $postname ) &&
					isset( FormPage::GetInstance()->post[ $postname ] ) )
					$output[ $mcname ] =  FormPage::GetInstance()->post[ $postname ];
			}
			return empty( $output ) ? false : $output;
		}

		return isset( FormPage::GetInstance()->post[ $fieldname ] ) ? FormPage::GetInstance()->post[ $fieldname ] : false;
	}


	protected function setError ( $msg ) {

		// follow the same format that validator is using, thus errors 
		// are an array of key-value pair maps. 
		FormPage::GetInstance()->SetErrors( array( array( 'err' => $msg ) ) );
	}
}


?>