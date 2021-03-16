<?php

/**
 * CoffeeCup Software's Web Form Builder.
 *
 * Methods to handle data that function on all database connections.
 *
 *
 * @version $Revision: 2456 $
 * @author Cees de Gruijter
 * @category FB
 * @copyright Copyright (c) 2011 CoffeeCup Software, Inc. (http://www.coffeecup.com/)
 */

define( 'FB_UPLOADS_TABLE_POSTFIX', '_uploadrefs');
define( 'DB_TRANSACT', 'transact' );

// bit masks for the _flags_ field, predominantly used for S-Drive hosted forms
define ( 'FLAG_READ', 1 );
define ( 'FLAG_STARRED', 2 );
define ( 'FLAG_OVERSUBMITLIMIT', 4 );



class DataSave {

	protected $table;					// used by sqlite and mysql classes
	protected $db;						// used by sqlite and mysql classes
	protected $where = false;			// used by sqlite
	protected $fields = false;			// used by sqlite, defaults to all db fields
	protected $outputfields = false;	// all fields a user can possibly ask for
	protected $lastrowid = false;		// becomes available AFTER a call to _InsertRow()
	protected $cfg_section;				// section of configuration data to use

	protected $post;
	public $errors = array();


	function __construct ( $cfg_section ) {

		// copy values to store, so that this class may modify data without side-effects
		$this->post = FormPage::GetInstance()->post;

		$this->cfg_section = $cfg_section;

		$this->table = Config::GetInstance()->GetConfig( 'settings', 'data_settings', $cfg_section, 'tablename' );

		// session is needed to keep track of 'back' button use
		Config::GetInstance()->InitSession();

		if( $this->table )		trim( $this->table );
	}


	/***-*** public methods start ***-***/

	// return false on failure or true on success
	public function Save ( ) {

		if( ! $this->_GetTable() ) 			return false;

		$this->_FlattenPost();

		// session key with instantiated class name, so that mysql and sqlite instances can coexist 
		$session_key = get_class( $this ) . '_rowid';

		if( $this->lastrowid = Config::GetInstance()->GetSessionVariable( $session_key ) )
		{
			if( ! $this->_UpdateRow() ) {

				$this->errors[] = array( 'err' => _T('Failed to update stored data.') );
				return false;
			}
		}
		else
		{
			if( ! $this->_InsertRow() ) {

				$this->errors[] = array( 'err' => _T('Failed to store the data.') );
				return false;
			}

			// save the record id, so that using the back button doesn't cause multiple records
			// but don't do this for auto-embedded forms, because there is no way to reset this session
			// and the same record gets over written time and time again.
			if( ! Config::GetInstance()->isAutoEmbedded() )
				Config::GetInstance()->SetSessionVariable( $session_key , $this->lastrowid );
		}
		
		return true;
	}


	// saves relationship between rowid, fieldname and uploaded file, which is very convenient
	// when reporting on mysql or sqlite
	public function SaveUploadsRef ( $uploads ) {

		if( empty( $uploads ) )		return;

		if( $this->_GetUploadsRefTable() &&
			$this->_InsertUploadsRows( $uploads ) ) {

				return true;
		}

		return false;
	}


	public function SaveTransactionId ( $transactid ) {

		if( ! $transactid )		return true;

		if( $this->lastrowid == false ) {
			writeErrorLog( 'Can\'t add transactionid to a record that doesn\'t exist. Call Save() first!.');
			return false;
		}

		$this->GetQueryFields();
		if( ! in_array( '_transactid_', $this->fields ) &&
			! $this->_addTransIdField() ) {
			
			writeErrorLog( 'Failed to add the "_transactid_" field.', $this->fields );
			return false;
		}

		$sql = 'UPDATE ' . $this->_EscapeName( $this->table ) . ' SET _transactid_=? WHERE _rowid_=?;';
		$sth = $this->db->prepare( $sql );

		if( $sth === false ) {

			writeErrorLog( 'Failed compile query:', $sql );
			return false;
		}

		if( ! $sth->execute( array( $transactid, $this->lastrowid ) ) ) {

			writeErrorLog( 'Failed to update table with "_transactid_"', $sth->errorInfo() );
			return false;
		}

		return true;
	}


	///@returns false on error, empty string on not found or the id-string
	public function GetTransactionId ( $rowid )
	{
		if( ! is_numeric( $rowid ) )
		{
			writeErrorLog( 'Rowid must be numeric:', $rowid );
			return false;
		}

		// check if the colums exists, just in case
		if( ! in_array( '_transactid_', $this->_GetTableFields() ) )
		{
			return '';
		}

		$qry = 'SELECT _transactid_ FROM ' . $this->_EscapeName( $this->table ) . ' WHERE _rowid_=?;';
		$sth = $this->db->prepare( $qry );

		if( ! $sth->execute( array( $rowid ) ) )
		{
			writeErrorLog( 'Failed to execute query:', $sth->errorInfo() );
			return false;
		}

		$transactid = $sth->fetchColumn( 0 );
		$sth->closeCursor();

		return $transactid;
	}


	public function UpdatePost ( $post ) {
		$this->post = $post;
	}


	public function SetWhereClause ( $where ) {

		$this->where = $where;
	}


	public function SetFieldClause ( $fields ) {

		$this->fields = $fields;

	} 

	// returns un-escaped array of query fields
	private function GetQueryFields ( ) {

		if( $this->fields === false )		$this->fields = $this->_GetTableFields();
		return $this->fields;
	}


	// returns all fields a user can possibly ask for
	public function GetAllFields ( ) {

		if( $this->outputfields === false ) {

			$this->outputfields = $this->_GetTableFields();
			$this->outputfields[] = 'rowid';
			$this->outputfields[] = '_read_';
			$this->outputfields[] = '_starred_';

			// always remove internally used field(s)
			$this->outputfields = array_values( array_diff( $this->outputfields, array( '_flags_' ) ) );
		}

		return $this->outputfields;

	}


	public function HasSetting ( $name ) {

		if( ! $this->_TableExists( FB_SETTINGS_TABLE ) )	return false;

		$qry = 'SELECT count(*) FROM ' . FB_SETTINGS_TABLE . ' WHERE name=?;';
		$sth = $this->db->prepare( $qry );

		if( $sth->execute( array( $name ) ) == false ) {

			writeErrorLog( 'Failed to read settings data:', $sth->errorInfo() );
			return false;
		}

		$count = $sth->fetch( PDO::FETCH_NUM );
		$sth->closeCursor();

		if( $count === false || ! $count[0] )				return false;

		return true;
	}


	/***-*** public methods end ***-***/



	/*** private, shared methods ***/

	protected function _FlattenPost ( ) {

		foreach( $this->post as $field => $value ) {
			
			if( is_array( $value ) )   $this->post[ $field ] = implode( $value, ', ');
		}
	}


	protected function _GetTable ( ) {
		
		if( ! $this->_TableExists( $this->table ) ) {

			return $this->_CreateTable();
		}

		return $this->_CheckFields();
	}


	protected function _GetUploadsRefTable ( ) {
	
		if( ! $this->_TableExists( $this->table . FB_UPLOADS_TABLE_POSTFIX ) )
			return $this->_CreateUploadsRefTable();
		else
			return true;
	}	



	// add any missing fields
	protected function _CheckFields ( ) {

		$dbfields = $this->_GetTableFields();

		$missing = array();

		// notes: - array_diff() is not usuable because the table may contain more fields than post
		//        - _GetTableFields() returns all names in lower case for case insensitive compare
		//        - reserved field names are translated (e.g.in to spanish) for mysql, thus don't only check against $dbfields
		foreach( array_keys( $this->post ) as $key ) {

			if( ! in_array( strtolower( $key ), $dbfields )  &&
				! in_array( $key, array_keys(Config::GetInstance()->GetReservedFieldTypes() ) ) )
			{
				$missing[] = $key;
			}
		}

		if( count( $missing ) > 0 ) {

			// do the ALTER 1 field at a time, because sqlite doesn't allow more
			foreach( $missing as $name )
			{
				$sql = 'ALTER TABLE ' . $this->_EscapeName( $this->table )
					 . ' ADD '
					 . $this->_MakeCreateFieldsSQL( array( $name ) );

				$r = $this->_Exec( $sql );
				
				if( $r === false )		return false;
			}
		}
		return true;
	}


	protected function _addTransIdField ( ) {

		$sql = 'ALTER TABLE ' . $this->_EscapeName( $this->table)
			 . ' ADD '
			 . $this->_MakeCreateFieldsSQL( array( '_transactid_' ) );

		$r = $this->_Exec( $sql );
		
		return $r !== false;
	}


	protected function _InsertRow ( ) {
		
		$fields = '';
		$rules = Config::GetInstance()->GetConfig( 'rules' );

		$data = array();

		// deal with the data in the post map
		foreach( $this->post as $key => $value )
		{
			$fields .= $this->_EscapeName( $key ) . ',';

			// check rules for special formatting needs
			if( isset( $rules->$key ) && $rules->$key->fieldtype == 'date' && ! empty( $value ) )
			{	
				$data[] = date('Y-m-d', $value );
			}
			else if( $key == '_submitted_' )
			{
				$data[] = $this->_applyTimeZone( $value );
 			}
 			else
			{
				$data[] = $value;
			}
		}

		// add any file fields that aren't included yet
		foreach( FormPage::GetInstance()->uploads as $upload )
		{
			if( ! isset( $this->post[ $upload[ 'fieldname' ] ] ) ) {
				
				$data[] = $upload[ 'storedname' ];
				$fields .= $this->_EscapeName( $upload[ 'fieldname' ] ) . ',';
			}
		}

		// register over limit posts on S-Drive
		if( Config::GetInstance()->isOverSubmitLimit() )
		{
			$data[] = FLAG_OVERSUBMITLIMIT;
			$fields .= '_flags_,';
		}

		// make sure there are as many ?'s as data items'
		$values = str_repeat( '?,', count( $data ) );

		$sql = 'INSERT INTO ' . $this->_EscapeName( $this->table )
			 . ' (' . rtrim( $fields, ',' ) . ') VALUES ( ' . rtrim( $values, ',' ) . ');';
 		$sth = $this->db->prepare( $sql );

		if( $sth === false )
		{
			writeErrorLog( 'Failed compile query:', $sql );
			return false;

		}
		else if( ! $sth->execute( $data ) )
		{
			writeErrorLog( 'Failed to insert data in from table:', $sth->errorInfo() );
			return false;
		}

		$this->lastrowid = $this->db->lastInsertId();
		 
		return true;
	}


	protected function _UpdateRow ( ) {

		$fields = '';
		$rules = Config::GetInstance()->GetConfig( 'rules' );

		$data = array();

		// deal with the data in the post map
		foreach( $this->post as $key => $value ) {

			$fields .= $this->_EscapeName( $key ) . '=?,';

			// check rules for special formatting needs
			if( isset( $rules->$key ) && $rules->$key->fieldtype == 'date' && ! empty( $value ) )
			{	
				$data[] = date('Y-m-d', $value );
			}
			else if( $key == '_submitted_' )
			{
				$data[] = $this->_applyTimeZone( $value );
 			}
			else
			{
				$data[] = $value;
			}
		}

		// add any file fields that aren't included yet
		foreach( FormPage::GetInstance()->uploads as $upload ) {

			if( ! isset( $this->post[ $upload[ 'fieldname' ] ] ) ) {
				
				$fields .= $this->_EscapeName( $upload[ 'fieldname' ] ) . '=?,';
				$data[] = $upload[ 'storedname' ];
			}
		}

		$sql = 'UPDATE ' . $this->_EscapeName( $this->table )
			 . ' SET' . rtrim( $fields, ',' )
			 . ' WHERE _rowid_=' . $this->lastrowid;
 		$sth = $this->db->prepare( $sql );

		if( $sth === false ) {

			writeErrorLog( 'Failed compile query:', $sql );
			return false;

		} else if( ! $sth->execute( $data ) ) {

			writeErrorLog( 'Failed to update data in from table:', $sth->errorInfo() );
			return false;
		}

		return true;
	}

	
	protected function _InsertUploadsRows ( $uploads ) {

		if( $this->lastrowid === false ) {

				return;
		}

		$sql = 'INSERT INTO ' . $this->_EscapeName( $this->table . FB_UPLOADS_TABLE_POSTFIX )
			 . ' (id, fieldname, orgname, storedname) VALUES( ?, ?, ?, ? );';

		$sth = $this->db->prepare( $sql );

		if( $sth === false ) {

			writeErrorLog( 'Failed compile query:', $sql );
			return false;
		}

		foreach( $uploads as $row ) {

			if( ! $sth->execute( array( $this->lastrowid,
									   $row['fieldname'],
									   $row['orgname'],
									   $row['storedname'] ) ) ) {
				writeErrorLog( 'Failed to insert data in upload refs table:', $sth->errorInfo() );
			}
		}
	}


	protected function _Exec ( $sql ) {

		$res = $this->db->exec( $sql );

		if( $res === false ) {
			writeErrorLog( 'Failed to execute query on table: ' . $this->table . '(' . $sql . ')', $this->db->errorInfo() );
			$this->errors[] = array( 'err' => 'Failed to execute query associated to this form.' );
			return false;
		}

		return true;
	}

	// overload this method per database type to yes/no apply user timezone
	protected function _applyTimeZone ( $value )
	{
		return $value;
	}
	
}



?>