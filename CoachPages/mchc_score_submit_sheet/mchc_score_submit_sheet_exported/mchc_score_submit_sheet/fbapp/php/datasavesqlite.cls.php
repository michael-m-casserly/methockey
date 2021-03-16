<?php

/**
 * CoffeeCup Software's Web Form Builder.
 *
 * Methods on SQLite for saving data.
 *
 *
 * @version $Revision: 2456 $
 * @author Cees de Gruijter
 * @category FB
 * @copyright Copyright (c) 2011 CoffeeCup Software, Inc. (http://www.coffeecup.com/)
 */



class DataSaveSQLite extends DataSQLite {

	function DataSaveSQLite ( $cfg_section ) {

		parent::__construct( $cfg_section );

		$this->_CheckSubmitLimitReset();
	}

	protected function _InsertRow ( )
	{
		if( $this->_CreateKeyNamesTable() )
		{
			$sql = 'INSERT OR REPLACE INTO ' . FB_KEYNAMES_TABLE . ' (colname,orgname) VALUES (?,?);';
			$sth = $this->db->prepare( $sql );

			if( $sth === false )
				writeErrorLog( 'Failed compile query:', $sql );
			else
			{
				foreach( Config::GetInstance()->postkeymap as $key => $value )
				{
					if( $key != $value )
					{
						if( ! $sth->execute( array( $key, $value ) ) )
							writeErrorLog( 'Failed to update table keynames:', $sth->errorInfo() );
					}
				}
			}
		}

		return DataSave::_InsertRow();
	}


	function _CreateTable ( ) {

		// create the posts table
		$sql = 'CREATE TABLE ' . $this->_EscapeName( $this->table ) . ' (';
			
		// no need for an id, sqlite has the 'rowid' build-in

		// the form fields
		$fields = $this->_MakeCreateFieldsSQL();

		if( $fields === false ) return false;

		$sql .= $fields . ');';

		return $this->_Exec( $sql );
	}


	function _CreateUploadsRefTable ( ) {
		
		$sql = 'CREATE TABLE ' . $this->_EscapeName( $this->table . FB_UPLOADS_TABLE_POSTFIX )
			 . ' ( id INT, fieldname TEXT, orgname TEXT, storedname TEXT );';
		$sql .= 'DROP INDEX IF EXISTS row_index;';			// needed because we changed the table names
		$sql .= 'CREATE INDEX row_index ON ' . $this->_EscapeName( $this->table . FB_UPLOADS_TABLE_POSTFIX ) . ' ( id );';

		return $this->_Exec( $sql );
	}


	function _CreateCartTable ( ) {

		$sql = 'CREATE TABLE ' . $this->_EscapeName( $this->table . FB_CART_TABLE_POSTFIX )
			 . ' ( id INT, cart TEXT );';
		$sql .= 'DROP INDEX IF EXISTS cart_index;';
		$sql .= 'CREATE INDEX cart_index ON ' . $this->_EscapeName( $this->table . FB_CART_TABLE_POSTFIX ) . ' ( id );';

		return $this->_Exec( $sql );
	}


	private function _CreateKeyNamesTable ( )
	{
		if( ! $this->_TableExists( FB_KEYNAMES_TABLE ) )
		{
			// create the table to store relation between original _post names and internally used column names 
			$sql = 'CREATE TABLE IF NOT EXISTS ' . FB_KEYNAMES_TABLE . ' ( colname TEXT, orgname TEXT );'
				 . 'CREATE INDEX keyname_index ON ' . FB_KEYNAMES_TABLE . ' ( colname );';
			return $this->_Exec( $sql );
		}

		return true;
	}


	function _MakeCreateFieldsSQL ( $selection = false ) {

		$sql = '';
		$rules = Config::GetInstance()->GetConfig( 'rules' );

		foreach( $rules as $name => $format ) {

			if( $selection != false && ! in_array( $name, $selection) )
				continue;

			if( $name == '_submitted_' ) {

				$this->errors[] = array( 'err' => _T('Field name "_submitted_" isn\'t allowed, because it is reserved for internal use.') );
				return false;
			}

			switch( $format->fieldtype ) {

			case 'text':
			case 'hidden':
			case 'password':
			case 'url':
			case 'email':
			case 'regex':
			case 'listbox':
			case 'dropdown':
			case 'checkbox':
			case 'radiogroup':
			case 'fileupload':
			case 'sigpad':
			case 'tel':
				$sql .= $this->_EscapeName( $name ) . ' TEXT,';
				break;

			case 'number':
				$sql .= $this->_EscapeName( $name ) . ' INT,';
				break;

			case 'textarea':
				$sql .= $this->_EscapeName( $name ) . ' TEXT,';
				break;
				
			case 'date':
				$sql .= $this->_EscapeName( $name ) . ' TEXT,';
				break;

			default:
				writeErrorLog( __CLASS__ . ' hit upon an unhandled field type:', $format->fieldtype );
			}
		}

		// add the reserved fields
		foreach( Config::GetInstance()->GetReservedFieldTypes() as $name => $type ) {

			if( $selection != false && ! in_array( $name, $selection) )
				continue;

			switch( $type ) {
			case 'datetime':
			case 'text':
			case 'ipaddress':
				$sql .= $this->_EscapeName( $name ) . ' TEXT,';
				break;

			case 'number':
				$sql .= $this->_EscapeName( $name ) . ' INT DEFAULT 0 ,';
				break;

			default:
				writeErrorLog( __CLASS__ . ' unhandled type in reserved fields:', Config::GetInstance()->GetReservedFieldTypes() );
			}
		}

		return rtrim( $sql, ',');
	}


	private function _CheckSubmitLimitReset ( )
	{
		$upgrade_date = Config::GetInstance()->getSubmitLimitUpgradeDate();

		if( $upgrade_date && $upgrade_date != $this->GetSetting( FB_SETTING_SUBMITLIMIT ) )
		{
			// store the new date so that we know if it changed or not
			$this->SetSetting ( array( FB_SETTING_SUBMITLIMIT => $upgrade_date ) );

			// clear the flags for the month that the date is set
			$qry = 'UPDATE ' . $this->_EscapeName( $this->table )
				 . ' SET _flags_ = _flags_&' . ~FLAG_OVERSUBMITLIMIT
				 . ' WHERE strftime(\'%Y-%m\', _submitted_) = \'' . substr( $upgrade_date, 0, 7 ) . '\';';

			$count = $this->db->exec( $qry );
		}

		if( $count === false )
		{
			writeErrorLog( __METHOD__, $this->db->errorInfo() );
			return 0;
		}

		return $count;
	}

}

?>