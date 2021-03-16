<?php

/**
 * CoffeeCup Software's Web Form Builder.
 *
 * Statistics reporter for S-Drive
 *
 *
 * @version $Revision: 2456 $
 * @author Cees de Gruijter
 * @category FB
 * @copyright Copyright (c) 2011 CoffeeCup Software, Inc. (http://www.coffeecup.com/)
 */

class StatsReporter {
		
	var $base_fields;
	var $curl;
	var $base_url = false;
	
	function __construct ( $sdrive_account_id, $form_name, $stats_host, $stats_url ) {
					
		$this->base_fields = 'sdrive_account_id=' . $sdrive_account_id
					  	   . '&http_referrer=' . urlencode( $_SERVER['HTTP_REFERER'] )
					  	   . '&ip_address=' . $_SERVER['REMOTE_ADDR']
					  	   . '&form_name=' . urlencode( $form_name );
		
		$this->base_url = $stats_url; 

		$this->curl = curl_init();
		curl_setopt( $this->curl, CURLOPT_TIMEOUT, 10 );
		curl_setopt( $this->curl, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $this->curl, CURLOPT_POST, 1 );
		curl_setopt( $this->curl, CURLOPT_FAILONERROR, 1 );
		curl_setopt( $this->curl, CURLOPT_HTTPHEADER, array( 'Host: ' . $stats_host ) );		

		// turnoff the server and peer verification
		curl_setopt( $this->curl, CURLOPT_SSL_VERIFYPEER, FALSE );
		curl_setopt( $this->curl, CURLOPT_SSL_VERIFYHOST, FALSE );		
	}

	function __destruct ( ) {
		
		if( ! $this->base_url )			return;
		curl_close( $this->curl );
	}


	function NotifyFormView ( ) {
						
		$this->_do_call( '&form_action=view' );
	}


	function NotifyFormChange ( ) {
						
		$this->_do_call( '&form_action=change' );
	}


	function NotifyFormSubmit ( $errorcount ) {
		
		$this->_do_call( '&form_action=submit&error_count=' . $errorcount );
	}

	
	/************************** private methods *******************************/
	
	function _do_call ( $fields = '' ) {

		if( ! $this->base_url )			return;

		curl_setopt( $this->curl, CURLOPT_URL, $this->base_url );
		curl_setopt( $this->curl, CURLOPT_POSTFIELDS, $this->base_fields . $fields );
		curl_setopt( $this->curl, CURLOPT_CONNECTTIMEOUT, 1 );		// ensure the page won't wait too long

		$response = curl_exec( $this->curl );

		if( curl_errno( $this->curl ) ) {
			$effurl = curl_getinfo( $this->curl, CURLINFO_EFFECTIVE_URL );
			writeErrorLog( 'Stats Reporter error - ' . curl_errno( $this->curl )  . ': ' . curl_error( $this->curl ), $effurl . '   ' . $this->base_fields . $fields );
			return false;
		}
		#echo $this->base_fields;
		#writeErrorLog( 'Stats Reporter success - ' . $response, $fields );

		return true;
	}

}


?>