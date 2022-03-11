<?php

/**
 * CoffeeCup Software's Web Form Builder.
 *
 * Methods on SQLite for accessing saved data.
 *
 *
 * @version $Revision: 2456 $
 * @author Cees de Gruijter
 * @category FB
 * @copyright Copyright (c) 2011 CoffeeCup Software, Inc. (https://www.coffeecup.com/)
 */


class DataAccessSQLite extends DataSQLite {

	/*	"pagination": {
	 *		"page_current": 2,				<- 1-based page number
	 *		"page_previous": 1,				<- null if on first page
	 *		"page_next": 3,					<- null if on last page
	 *		"page_last": 8,					
	 *		"items_per_page": 10,
	 *		"items_total": 78,
	 *		"items_starting_index": 11,
	 *		"items_ending_index": 20
	 */
	var $pagination = false;			// data structure with pagination information
	var $sort = false;					// data structure that defines sort order
	var $error = false;					// any error condition that was encountered
	var $result = false;				// feedback from data manipulation queries
	private	$transact = false;			// true if the transaction table is attached
	private $transact_fields = false;	// fieldnames of the transaction table


	// aliases for field names to make them more readable, the keys are prefixed with an underscore
	// to avoid conflicts with user defined field names
	private $fieldnameMap;

	private $fieldnamesloaded = false;	// indicates if original post names have been added to the map

	

	function DataAccessSQLite ( $cfg_section ) {

		parent::__construct( $cfg_section );

		$this->fieldnameMap['_transactid_'] = _T('Transaction ID');
		$this->fieldnameMap['_lineitemcount'] = _T('Line Item Count');
		$this->fieldnameMap['_gatewayref'] = _T('Gateway Reference');
		$this->fieldnameMap['_status'] = _T('Status');
		$this->fieldnameMap['_grandtotal'] = _T('Grand Total');
	}


	public function AttachTransActions ( $dbpath ) {

		$this->transact = $this->_Exec ( 'ATTACH "' .  $dbpath . '" AS ' . DB_TRANSACT );
		return $this->transact;
	}


	public function GetRealName ( $alias )
	{
		$this->_loadOriginalPostNames();

		$realName = array_search( $alias , $this->fieldnameMap );

		// aliases returned with a POST from the control panel have the ' ' replaces by a '_', thus
		// e.g. "Transaction ID" is returned as "Transaction_ID"
		// therefor also try to find a key with all '_' replaced by ' '
		// WARNING this fails when aliases contain a '_' for real!
		if( $realName === false )
		{
			$realName = array_search( str_replace( '_', ' ', $alias ), $this->fieldnameMap );
		}

		return $realName === false ? $alias : $realName;
	}


	public function GetAliasName ( $fieldname )
	{
		$this->_loadOriginalPostNames();

		return isset( $this->fieldnameMap[ $fieldname ] ) ? $this->fieldnameMap[ $fieldname ] : $fieldname;
	}


	// Overwrite the base class method to take the (possible) attached transaction table in to account
	function GetAllFields ( ) {

		if( $this->outputfields === false )
		{
			$this->outputfields = array_merge( $this->GetFormTableFields(), $this->GetPaymentFields() );

			// always remove internally used field(s)
			$to_remove = array( '_flags_', '_submit_limit_' );

			if( ! Config::GetInstance()->UsePayments() )
			    $to_remove[] = '_transactid_';

			$this->outputfields = array_values( array_diff( $this->outputfields, $to_remove ) );
		}

		return $this->outputfields;
	}


	///@return all fields of the Form table that might be of interest to the user
	private function GetFormTableFields ( )
	{
		$ffields = $this->_GetTableFields();
		$ffields[] = 'rowid';
		$ffields[] = '_read_';
		$ffields[] = '_starred_';
		$ffields[] = '_submit_limit_';
		return $ffields;
	}


	/// Get fieldnames from the Transaction table. Prefix with "_" to avoid
	/// name conflicts with user defined form names
	///@return all fields of the Transaction table that might be of interest to the user
	private function GetPaymentFields ( )
	{
		$pfields = array();

		if( $this->transact )
		{
			if( ! $this->transact_fields )
			{
				$this->transact_fields = $this->_GetTableFields( TTRANS );
			}
			foreach( $this->transact_fields as $fld ) {

				switch( $fld ) {
					case 'appname':
					case 'created':
					case 'modified':
					case 'object':
					case 'testmode':
					case 'route':
						break;

					default:
						$pfields[] = '_' . $fld;
				}
			}
		}

		return $pfields;
	}


	///@param[in,out]		$fieldname		The alias, changed to real name if true is returned
	///@ return true is the field is in the transactions table
	private function IsPaymentField ( &$fieldname )
	{
		if( ! $this->transact )			return false;

		// all transaction fields are aliased with a '_' prefix, thus with it, it can't be one
		if( $fieldname[ 0 ] != '_' )	return false;

		$real_name = substr( $fieldname, 1 );

		if( ! $this->transact_fields )
		{
			$this->transact_fields = $this->_GetTableFields( TTRANS );
		}

		if( in_array( $real_name, $this->transact_fields ) )
		{
			$fieldname = $real_name;
			return true;
		}
		return false;
	}


	// return false if the field is not in the table 
	function SetSortOrder ( $field, $asc = true ) {

		// fieldnames might be cast to lower case, check both to maintain compatibility with older versions
		if( in_array( $field, $this->_GetTableFields() ) )
		{
			$this->sort['field'] = $this->_EscapeName( $field );
			$this->sort['asc'] = $asc;
			return true;
		}
		elseif( in_array( strtolower( $field ), $this->_GetTableFields() ) )
		{
			$this->sort['field'] = $this->_EscapeName( strtolower( $field ) );
			$this->sort['asc'] = $asc;
			return true;
		}

		// rowid is a sqlite special field and is missing fields list
		if( preg_match( '/_?rowid_?/', $field ) )
		{
			$this->sort['field'] = 'rowid';
			$this->sort['asc'] = $asc;
			return true;
		}

		$this->sort = false;
		return false;
	}


	// Return associative array on success or false on failure
	function GetAllRows ( $tablename = false ) {

		if( ! $tablename )		$tablename = $this->table;
	
		$qry = $this->_QrySelectFrom( $tablename );

		if( $this->where ) {

			$qry .= ' WHERE ' . $this->where;
		}

		if( $this->sort ) {

			$qry .= ' ORDER BY ' . $this->sort['field'] . ($this->sort['asc'] ? ' ASC' : ' DESC');
		}
		$qry .= ';';

		$result = $this->db->query( $qry );

		if( $result === false )			return false;

		$data = array();

		while( $row = $result->fetch( PDO::FETCH_ASSOC ) ) {
			$data[] = $row;
		}

		return $data;
	}


	// return the data of the requested page and sets the pagination structure
	// or false on failure
	function GetPage ( $page, $pagelength, $tablename = false ) {

		if( ! $tablename )		$tablename = $this->table;

		$itemcount = $this->GetRecordCount();

		$start = ($page - 1) * $pagelength;
	
		if( $start < 0 || $start > $itemcount )
			return false;

		$qry = $this->_QrySelectFrom( $tablename );
		if( $this->where )				$qry .= ' WHERE ' . $this->where;

		$data = array();

		if( $this->sort ) {
			$qry .= ' ORDER BY ' . $this->sort['field'] . ($this->sort['asc'] ? ' ASC' : ' DESC');
		}
		$qry .= ' LIMIT ' . $pagelength . ' OFFSET ' . $start . ';';

		$r = $this->db->query( $qry );

		if( $r == false ) {
			writeErrorLog( 'Error in query: ' . $qry, $this->db->errorInfo() );
			$this->error = implode( ' ,', $this->db->errorInfo() );
			return false;
		}

		$rows = array();
		while( $row = $r->fetch( PDO::FETCH_ASSOC ) ) {
			$rows[] = $row;
		}

		$r->closeCursor();

		// add the remainder of the pagination
		// pages that don't exist should return null instead of 0
		$this->pagination[ 'items_total' ] = $itemcount;
		$this->pagination[ 'page_last' ] = ($itemcount > 0 ? ceil( $itemcount / $pagelength ) : 1);
		$this->pagination[ 'page_current' ] = $page;
		$this->pagination[ 'page_previous' ] = ($page == 1 ? null : $page - 1);
		$this->pagination[ 'page_next' ] = ($this->pagination[ 'page_last' ] == $page ? null : $page + 1);
		$this->pagination[ 'items_per_page' ] = $pagelength;
		$this->pagination[ 'items_starting_index' ] =  $start + 1;
		$this->pagination[ 'items_ending_index' ] = $start + count( $rows );

		return $rows;
	}

	
	public function GetItemData ( $rowid, $tablename = false )
	{
		if( ! is_numeric( $rowid ) ) {
			$this->error = 'Row ID must be numeric.';
			return false;
		}

		if( ! $tablename )		$tablename = $this->table;

		$qry = $this->_QrySelectFrom( $tablename, true );
		$qry .= ' WHERE ' . $this->_EscapeName( $tablename ) . '._rowid_=?;';

		$sth = $this->db->prepare( $qry );
		if( ! $sth ) {
			writeErrorLog( 'Failed to prepare query:', $this->db->errorInfo());
			return false;
		}
		$sth->execute( array( $rowid ) );
		$data = $sth->fetch( PDO::FETCH_ASSOC );

		if( $data === false ) {

			writeErrorLog( 'Failed to get item data:', $sth->errorInfo() ); 
			$this->error = implode( ' ,', $r->errorInfo() );
			return false;
		}

		$sth->closeCursor(); 

		return $data;
	}


	public function GetItemCart ( $rowid )
	{
		if( ! is_numeric( $rowid ) ) {
			$this->error = 'Row ID must be numeric.';
			return false;
		}

		if( ! $this->transact )
		{
			$this->error = 'No transactions found.';
			return false;
		}

		$qry = 'SELECT ' . $this->_EscapeName( $this->tablename ) . '.rowid, ' . DB_TRANSACT . '.*'
			 . ' FROM ' . $this->_EscapeName( $this->tablename )
			 . ' LEFT JOIN ' . DB_TRANSACT . '.' . TTRANS . ' ON ' . TTRANS . '.route = _transactid_'
			 . ' WHERE ' . $this->_EscapeName( $tablename ) . '._rowid_=?;';

 		$sth = $this->db->prepare( $qry );
		if( ! $sth )
		{
			writeErrorLog( 'Failed to prepare query:', $this->db->errorInfo());
			return false;
		}
		$sth->execute( array( $rowid ) );
		$data = $sth->fetch( PDO::FETCH_ASSOC );

		if( $data === false ) {

			writeErrorLog( 'Failed to get item data:', $sth->errorInfo() ); 
			$this->error = implode( ' ,', $r->errorInfo() );
			return false;
		}

		$sth->closeCursor(); 

		return $data;
	}


	function UpdateItem ( $rowid, $read, $starred , $tablename = false ) {

		if( ! is_numeric( $rowid ) ) {
			$this->error = 'Row ID must be numeric.';
			return false;
		}

		if( ! $tablename )		$tablename = $this->table;

		$flag = 0;
		if( $read !== false && $starred !== false ) {

			// update if both are defined
			if( $read )		$flag |= FLAG_READ;
			if( $starred )	$flag |= FLAG_STARRED;

		} else {

			// get current value and modify
			$qry = 'SELECT _flags_ FROM ' . $this->_EscapeName( $tablename ) . ' WHERE _rowid_=' . $rowid . ';';
			$result = $this->db->query( $qry );

			if( $result === false )	{

				writeErrorLog( 'Failed to get item with id: ' . $rowid, $this->db->errorInfo() );
				$this->error = 'Failed to get item with id: ' . $rowid;
				return false;
			}

			if( $r = $result->fetch( PDO::FETCH_NUM )  ) {

				$flag = $r[0];

			} else {

				$this->error = 'Couldn\'t find item with id: ' . $rowid;
				return false;
			}

			// update if both are defined
			if( $read !== false ) {
				if( $read )			$flag |= FLAG_READ;
				else 				$flag = $flag & ~FLAG_READ;
			}

			if( $starred !== false ) {
				if( $starred )		$flag |= FLAG_STARRED;
				else				$flag = $flag & ~FLAG_STARRED;
			}
		}

		$sql = 'UPDATE ' . $this->_EscapeName( $tablename ) . ' SET _flags_=? WHERE _rowid_=?;';
		$sth = $this->db->prepare( $sql );

		if( $sth->execute( array( $flag, (int)$rowid ) ) === false ) {

			writeErrorLog( 'Failed to execute query for update item:', $sth->errorInfo() ); 
			$this->error = implode( ' ,', $sth->errorInfo() );
			return false;
		}

		$this->result = 'Updated item ' . $rowid;

		return true;
	}
	

	// returns array( field => array( fieldname => '...', orgname => '...', storedname => '...' ), ... )
	// or false on error
	function GetItemFiles ( $rowid, $tablename = false ) {

		if( ! is_numeric( $rowid ) ) {
			$this->error = 'Row ID must be numeric.';
			return false;
		}

		if( ! $tablename )		$tablename = $this->table;

		// check table existance, it is only created when forms have file upload fields
		if( ! $this->_TableExists( $tablename . FB_UPLOADS_TABLE_POSTFIX ) ) {
			return array();
		}

		$qry = 'SELECT * FROM ' . $this->_EscapeName( $tablename . FB_UPLOADS_TABLE_POSTFIX ) . ' WHERE id=?;';
		$sth = $this->db->prepare( $qry );

		if( $sth->execute( array( $rowid ) ) === false ) {

			writeErrorLog( 'Failed to execute prepared query for get item files:', $sth->errorInfo() ); 
			$this->error = implode( ' ,', $sth->errorInfo() );
			return false;
		}

		$data = array();
		while( $row = $sth->fetch( PDO::FETCH_ASSOC ) ) {
			$data[ $row['fieldname'] ] = $row ;
		}

		$sth->closeCursor(); 

		return $data;
	}


	public function GetPagination ( )
	{
		return $this->pagination;
	}


	public function GetOverSubmitLimitCount ( )
	{
		$qry = 'SELECT count(*) FROM ' . $this->_EscapeName( $this->table ) . ' WHERE _flags_ & ' . FLAG_OVERSUBMITLIMIT . ';';
		$sth = $this->db->query( $qry );
		$count = $sth->fetchColumn( 0 );
		$sth->closeCursor();
		return $count;
	}

	// Get the rowids that match the where clause and delete the rows one by one instead of deleting
	// records directly, because the rowids are needed to delete associated files
	public function DeleteWhere ( $where, $tablename = false  ) {

		if( ! $tablename )		$tablename = $this->table;

		$rows = $this->_GetRowIdsByWhere( $where, $tablename );

		if( $rows === false || count( $rows ) == 0 ) {
			$this->result = 'No rows matched selection criteria.';
			return true;
		}

		$sql1 = 'DELETE FROM ' . $this->_EscapeName( $tablename ) .' WHERE _rowid_=?;';
		$sth1 = $this->db->prepare( $sql1 );
		$sth2 = false;
		$sth3 = false;

		if( $this->_TableExists( $tablename . FB_UPLOADS_TABLE_POSTFIX ) ) {

			$sql2 = 'DELETE FROM ' . $this->_EscapeName( $tablename . FB_UPLOADS_TABLE_POSTFIX ) . ' WHERE id=?;';
			$sql3 = 'SELECT storedname FROM ' . $this->_EscapeName( $tablename . FB_UPLOADS_TABLE_POSTFIX ) . ' WHERE id=?;';
			$sth2 = $this->db->prepare( $sql2 );
			$sth3 = $this->db->prepare( $sql3 );
		}

		$this->db->beginTransaction();
		$count = 0;

		foreach( $rows as $rowid ) {

			if( $sth1->execute( array( (int)$rowid ) ) === false ) {

				writeErrorLog( 'Failed to execute prepared query for delete item:', $sth1->errorInfo() ); 
				$this->error = implode( ' ,', $sth1->errorInfo() );
				$this->db->rollBack();
				return false;

			} else {

				// only do this if the table exists and we compiled the query
				if( $sth2 !== false ) {

					// delete any reference file
					if( $sth3->execute( array( (int)$rowid ) ) === false ) {

						writeErrorLog( 'Failed to execute prepared select query on: ' . $tablename . FB_UPLOADS_TABLE_POSTFIX, $sth3->errorInfo() );
						$this->error = implode( ' ,', $sth1->errorInfo() );

					} else {
						
						$path = Config::GetInstance()->GetStorageFolder( 1 );

						while( ($name = $sth3->fetchColumn( 0 )) !== false ) {
							unlink( $path . $name );
						}
					}

					// delete row from the file table
					if( $sth2->execute( array( (int)$rowid ) ) === false ) {

						writeErrorLog( 'Failed to execute prepared query for delete file reference:', $sth2->errorInfo() );
						$this->error = implode( ' ,', $sth2->errorInfo() );
						$this->db->rollBack();
						return false;
					}
				}

				$count += $sth1->rowCount();

			}
		}
		$this->db->commit();

		$this->result = 'Deleted ' . $count . ' record' . ($count == 0 ? '.' : 's.' );

		return true;
	}


	public function UpdateWhere ( $where, $read, $starred, $tablename = false  ) {

		$rows = $this->_GetRowIdsByWhere( $where, $tablename );

		if( $rows === false ) {

			return false;

		} else if( ! is_array( $rows ) ) {

			$this->error = 'UpdateRows expects the first parameter to be an array()';
			return false;

		} else if( count( $rows ) == 0 ) {

			$this->result = 'No rows matched selection criteria.';
			return true;
		}

		$count = 0;
		foreach( $rows as $id ) {

			if( ! $this->UpdateItem ( $id, $read, $starred , $tablename ) )		return false;
			++$count;
		}

		$this->result = 'Updated ' . $count . ' record' . ($count == 0 ? '.' : 's.' );
		return true;
	}


	private function _QrySelectFrom ( $tablename, $allfields = false ) {

		if( $allfields || $this->fields === false )
		{
			$this->fields = $this->GetAllFields();
		}

		// order the field names as in the current form, but the old ones at the back
		usort( $this->fields, array( $this, '_orderLikeInForm' ) );

		$myfields = '';
		foreach( $this->fields as $fld ) {

			switch( $fld ) {

				case '_read_':
			 		$myfields .= ', (_flags_ & ' . FLAG_READ . ') <> 0 AS _read_ ';
					break;

				case '_starred_':
			 		$myfields .= ', (_flags_ & ' . FLAG_STARRED . ') <> 0 AS _starred_ ';
					break;

				case '_submit_limit_':
			 		$myfields .= ', (_flags_ & ' . FLAG_OVERSUBMITLIMIT . ') <> 0 AS _submit_limit_ ';
					break;

				case 'flags':
				case 'rowid':
					// ignore internally used fields
					break;

				default:

					// alias normal field names with their lower cased name, because tables created with
					// early FB versions may have mixed case column names that the Dashboard no longer expects.
					$alias = isset( $this->fieldnameMap[ $fld ] ) ? $this->fieldnameMap[ $fld ] : strtolower($fld); 

					// add the table name to the rowid or else the join will make the query fail
					$prefix = ($this->IsPaymentField( $fld ) ? TTRANS : $this->_EscapeName( $tablename )) . '.';
					$myfields .= ', ' . $prefix . $this->_EscapeName( $fld ) . ' AS ' . $this->_EscapeName( $alias );
			}
		}

		$qry = 'SELECT ' . $this->_EscapeName( $tablename ) . '.rowid' . $myfields . ' FROM ' . $this->_EscapeName( $tablename );

		if( $this->transact )
			$qry .= ' LEFT JOIN ' . DB_TRANSACT . '.' . TTRANS . ' ON ' . TTRANS . '.route = _transactid_'; 

		return $qry;
	}


	function _GetRowIdsByWhere ( $where, $tablename = false ) {

		if( ! $tablename )		$tablename = $this->table;

		$qry = 'SELECT rowid FROM ' . $this->_EscapeName( $tablename ) . ' WHERE ' . $where . ';';
		$result = $this->db->query( $qry );

		if( $result === false )	{
			$this->error = 'Failed to execute query with this where clause: ' . $where;
			return false;
		}

		$rows = array();

		while( $r = $result->fetch( PDO::FETCH_NUM )  ) {
			$rows[] = $r[0];
		}

		return $rows;
	}


	// usort callback that compares 2 fieldnames in the rules  	
	private function _orderLikeInForm ( $a, $b ) {

		static $keys = false;

		if( ! $keys )
		{
			// get keys from the rules and convert to lower case
			$keys = array();
			foreach( Config::GetInstance()->GetConfig( 'rules' ) as $key => $value )
			{
	  			$keys[] = strtolower( $key );
	  		} 
		}

		$r = 0;	

		if( $a != $b ) {

			foreach( $keys as $key ) {
				
				if( $key == $a ) {
					$r = -1;		// a appears before b
					break;
				}
				if( $key == $b ) {
					$r = 1;			// b appears before a
					break;
				}
			}
		}

		return $r;
	}

	private function _loadOriginalPostNames ( )
	{
		if( $this->fieldnamesloaded )								return;

		$this->fieldnamesloaded = true;

		if( ! $this->_TableExists( FB_KEYNAMES_TABLE ) )			return;

		$qry = 'SELECT * FROM ' . FB_KEYNAMES_TABLE . ';';
		$r = $this->db->query( $qry );

		if( $r == false )
		{
			writeErrorLog( 'Error in query: ' . $qry, $this->db->errorInfo() );
			$this->error = implode( ' ,', $this->db->errorInfo() );
			return;
		}

		while( $row = $r->fetch( PDO::FETCH_ASSOC ) ) {
			$this->fieldnameMap[ $row[ 'colname' ] ] = $row[ 'orgname' ];
		}

		$r->closeCursor();
	}
}

?>