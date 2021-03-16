<?php
/**
 * CoffeeCup Software's Web Form Builder.
 *
 * Base class for the API calls from the S-Drive Dashboard. 
 *
 *
 * @version $Revision: 2456 $
 * @author Cees de Gruijter
 * @category FB
 * @copyright Copyright (c) 2012 CoffeeCup Software, Inc. (http://www.coffeecup.com/)
 */


class DashboardData
{

	protected	$db = false;
	protected	$transacts = false;				// set true if the transaction db is attached

	public		$error = '';
	public		$data = array( 'version' => 1 );// version helps views to deal with differences between older and newer versions


	protected function __construct ( )
	{
		$this->_Connect();
	}


	private function _Connect ( ) {

		// connect to the database
		if( $this->db === false )
		{
			if( Config::GetInstance()->sdrive )
			{
				$this->db = new DataAccessSQLite( 'save_sqlite' );

				// attach the transaction database if the form uses payments
				if( Config::GetInstance()->UsePayments() )
				{
					$dbfile = TransactionLogger::GetInstance()->GetSqliteFile();
					if( empty( $dbfile ) || ! file_exists( $dbfile ) )
					{
						writeErrorLog( 'Tried to attach transaction log, but file is not defined or doesn\'t exist:', $dbfile );
					}
					else
					{
						$this->transacts = $this->db->AttachTransActions( $dbfile );
					}
				}
			}
			else
			{
				$this->db = new DataAccessMySQL( 'save_database' );
			}
		}
	}
}
