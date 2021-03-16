<?php


require CARTREVISION . '/api/transactionlogger.interface.php';

define( 'FBAPP_PREFIX' , '_FB_');


class TransactionLogger extends TransactionLoggerInterface  {

	private static $instance = null;	// me...for singleton

	public static function GetInstance ( ) {

		if( ! isset( self::$instance ) ) {
			$className = __CLASS__;
			self::$instance = new $className();

			// use the sqlite version for S_Drive, else use the mysql version
			if( Config::GetInstance()->sdrive ) {

				include CARTREVISION . '/phphosted/database_sqlite.cls.php';

			} else {

				include CARTREVISION . '/phphosted/database_mysql.cls.php';
			}
		}

		return self::$instance;
	}


	public function getApplicationName ( ) {

		// use the form name with a prefix that allows us to recognize the FB app.
		return FBAPP_PREFIX . FormPage::GetInstance()->GetFormName();
	}


	public function createDBInstance ( ) {

		// always use the db version, no support for the simple file based storage
		include CARTREVISION . '/phphosted/fbase.cls.php';
		if( ! class_exists( 'HostedFBase' ) ) {

			writeErrorLog( 'Tried to include "phphosted/fbase.cls.php", but "HostedFBase" still doesn\'t exist.');
			return false;
		}

		return new HostedFBase( TTRANS );
	}


	public function GetSqliteFile ( ) {

		if( Config::GetInstance()->sdrive )
			return Config::GetInstance()->sdrive['sdrive_account_datastore_path'] . WRITEDB;

		return '';
	}

}


?>