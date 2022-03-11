<?php

/**
 * CoffeeCup Software's Web Form Builder.
 *
 * Functions to merge posted data into the HTML form definition.
 *
 *
 * @version $Revision: 2456 $
 * @author Cees de Gruijter
 * @category FB
 * @copyright Copyright (c) 2011 CoffeeCup Software, Inc. (https://www.coffeecup.com/)
 */



function MergeFormPost ( $post = false ) {

	if( ! $post )		$post =& $_POST;

	$dom = new DOMDocument('1.0', 'UTF-8');
	$previous_value = libxml_use_internal_errors(true);
	$success = $dom->loadHTML( FormPage::GetInstance()->source );
	libxml_clear_errors();
	libxml_use_internal_errors($previous_value);

	if( !$success ) {
		writeErrorLog('Failed to parse HTML form.');
		return;
	}

	$errors = FormPage::GetInstance()->GetErrors( true );
	$processed_names = array();
	
	$display_max_error = Config::GetInstance()->GetConfig( 'special', 'maxnumerrors' );
	if( $display_max_error === false )
		$display_max_error = 1000;					// some ridiculously large number


	// get all input nodes with a name
	$xpath = new DOMXpath( $dom );
	foreach( $xpath->query( '//input[@name]' ) as $e ) {

		$tagname = $e->getAttribute( 'name' );
		$tagname_stripped = str_replace( '[]', '', $tagname );

		// checkboxes have a names like "check1[]", but only "check1" is present in $post
		if( isset( $post[ $tagname ] ) || isset( $post[ $tagname_stripped ] ) ) {

			switch( $e->getAttribute( 'type' ) ) {

			case 'radio':

				if( $e->getAttribute( 'value' ) == $post[ $tagname ] ) {
					$e->setAttributeNode( new DOMAttr( 'checked', 'checked' ) );				
				}
				break;

			case 'checkbox':

				if( isset( $post[ $tagname_stripped ]) &&
					is_array( $post[ $tagname_stripped ]) &&
					in_array( $e->getAttribute( 'value' ) , $post[ $tagname_stripped ] ) ) {

					$e->setAttributeNode( new DOMAttr( 'checked', 'checked' ) );				
				}
				break;

			case 'file':
				break;

			default:
				$e->setAttributeNode( new DOMAttr( 'value', $post[ $tagname ] ) );
			}
		}

		if( !empty( $tagname_stripped ) && ! in_array( $tagname_stripped, $processed_names ) ) {

			if( $display_max_error> 0 ) {
				InserErrorLabel( $dom, $e, $errors );
				--$display_max_error;
			}

			$processed_names[] = $tagname_stripped;
		}
	}

	// get all select nodes with a name
	foreach( $xpath->query( '//select[@name]' ) as $e ) {

		// findout if the name is defined as an array[] or as a scalar
		$name = $e->getAttribute( 'name' );

		$is_array = false;
		if( ($p = strpos( $name, '[]' ) ) !== false ) {
			$name = substr( $name, 0, -2 );
			$is_array = true;
		}

		if( isset( $post[ $name ] ) ) {

			foreach( $e->getElementsByTagName( 'option' ) as $child ) {

				// set or unset the selected attribute
				if( $is_array ) {

					if( in_array( $child->getAttribute( 'value' ), $post[ $name ] )  &&
						! $child->hasAttribute( 'selected' ) ) {

						$child->setAttributeNode( new DOMAttr( 'selected', 'selected' ) );

					} else if( $child->hasAttribute( 'selected' ) ) {

						$child->removeAttribute( 'selected' );
					}
					
				} else {

					if( $child->getAttribute( 'value' ) == $post[ $name ] &&
						! $child->hasAttribute( 'selected' ) ) {

						$child->setAttributeNode( new DOMAttr( 'selected', 'selected' ) );					

					} else if( $child->hasAttribute( 'selected' ) ) {

						$child->removeAttribute( 'selected' );
					}
				}
			}
		}

		if( ! empty($name) && ! in_array( $name, $processed_names ) ) {

			InserErrorLabel( $dom, $e, $errors );
			$processed_names[] = $name;
		}
	}

	// get all textarea nodes with a name
	foreach( $xpath->query( '//textarea[@name]' ) as $e ) {

		$name = $e->getAttribute( 'name' );

		if( isset( $post[ $name ] ) ) {

			$e->appendChild( $dom->createTextNode ( $post[ $name ] ) );
		}

		if( ! in_array( $name, $processed_names ) ) {

			InserErrorLabel( $dom, $e, $errors );
			$processed_names[] = $name;
		}
	}

	// reCaptcha error should also be placed underneath the field
	if( isset( $errors[ 'reCaptcha' ] ) ) {

		$node = $dom->createElement( 'label', $errors[ 'reCaptcha' ] );
		$node->setAttributeNode( new DOMAttr('for', 'fb-captcha_control' ) );
		$node->setAttributeNode( new DOMAttr('class', 'error') );
		$dom->getElementById( 'fb-captcha_control' )->appendChild( $node );
		$processed_names[] = 'reCaptcha';
	}

	// add errors from fields that we haven't processed yet to the error div
	MakeErrorNode( $dom, $errors, $processed_names );

	return $dom->saveHTML();
}


// return a dom node, ready for insertion 
function MakeErrorNode ( $dom, $errors, $processed_names ) {

	// search for a predefined error container or add our own if not found
	// <div style="display:none;" id="fb_error_report" ></div>
	$node = $dom->getElementById( 'fb_error_report' );

	if( ! $node ) {

		$node = $dom->createElement( 'div' );
		$node->setAttributeNode( new DOMAttr( 'id', 'fb_error_report' ) );

		foreach( $dom->getElementsByTagName('body') as $e ) {
			$e->insertBefore( $node, $dom->getElementById('docContainer') );
			break;
		}
	}

	$li = $dom->createElement( 'ul' );

	$count = 0;

	foreach( $errors as $fld => $err ) {

		// some errors are simply added to the array and have a name like '0', however
		// in_array() returns false if '0' is numeric, thus force it to a string.
		if( ! in_array( (string)$fld, $processed_names ) ) {
			$li->appendChild( new DOMElement('li', $err ) );
			$count++;
		}
	}

	if( $count ) {
		$node->removeAttribute( 'style' );
		$node->appendChild( new DOMElement( 'h4', _T('Your form could not be submitted for the following reason(s):') ) );
		$node->appendChild( $li );
	}

	return $node;

}


function InserErrorLabel ( $dom, $element, $errors ) {

	$name = str_replace( '[]', '', $element->getAttribute( 'name' ) );

	if( ! isset( $errors[ $name ] ) ) 				return;

	$node = $dom->createElement( 'label', $errors[ $name ] );
	$node->setAttributeNode( new DOMAttr('for', $element->getAttribute( 'id' ) ) );
	$node->setAttributeNode( new DOMAttr('class', 'error') );

	$parent = $element->parentNode;
	if( in_array( $element->getAttribute( 'type' ), array( 'radio', 'checkbox' ) ) ) {

		$parent= $parent->parentNode;

	}
	$parent->appendChild( $node );
}

?>