<?php

/**
 * CoffeeCup Software's Web Form Builder.
 *
 * Class for data reporting. Used for S-Drive control panel. 
 *
 *
 * @version $Revision: 2456 $
 * @author Cees de Gruijter
 * @category FB
 * @copyright Copyright (c) 2012 CoffeeCup Software, Inc. (http://www.coffeecup.com/)
 */

// remove dependency on DashboardData until a new resource branch is created
//class DataReporter extends DashboardData
class DataReporter
{

/*** remove this block once DashboardData is available ***/ 
	protected	$db = false;
	protected	$transacts = false;				// set true if the transaction db is attached

	public		$error = '';
	public		$data = array( 'version' => 1 );// version helps views to deal with differences between older and newer versions

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
/*** end block ***/ 

	private $where = false;
	private $fields = false;				// array of fields to use for select query
	private $maxwidth = -1;

	private $curSign;						// for formatting of money fields
	private	$decimals;
	private	$divider;


	function __construct ( )
	{
//** add this line once DashboardData is available 
//		parent::__construct();
/*** remove this block once DashboardData is available ***/ 
		$this->_Connect();
/*** end block ***/ 

		$this->curSign = (string) Config::GetInstance()->GetConfig( 'settings', 'payment_settings', 'currencysymbol' );
		$this->decimals = (int) Config::GetInstance()->GetConfig( 'settings', 'payment_settings', 'decimals' );
		$this->divider = pow( 10, -1 * $this->decimals );
	}


	///@param $with_limit_exceeded	possible values: -1 (all), 0 (only regular), 1 (only exceeded)
	function SetSelection ( $selection, $with_limit_exceeded = -1 )
	{
		// don't use any alias field names in this where clause because they won't
		// be defined when counting the records with a select count(*) from ... where ... 
		switch( (int) $with_limit_exceeded )
		{
		case -1:
			$this->where = '1';
			break;

		case 0:
			$this->where = '(_flags_&' . FLAG_OVERSUBMITLIMIT . '=0)';
			break;
			
		case 1:
			$this->where = '_flags_&' . FLAG_OVERSUBMITLIMIT;
			break;
		}

		if( $selection === false ) 		return true;

		// check the syntax and build a where clause
		// possible formats are:
		//		3days						last 3 days
		//		from20to40					from row 20 to row 40
		//		from2011-07-29 10:27:18to2011-07-30 10:27:18
		//									from date_time to date_time
		//		new							rows that don't have the _read_ flag set 
		//		starred						rows that have the _starred_ flag set 
		//		1,2,3						rows with ids 1, 2 and 3 

		$matches = array();
		if( $selection == 'new' ) {

			$this->where .= ' AND (_flags_&' . FLAG_READ . ')=0';

		} else if( $selection == 'starred') {

			$this->where .= ' AND _flags_&' . FLAG_STARRED;

		} else if( $selection == 'all') {

			// nothing needed

		} else if( preg_match( '/(\d+)days?/', $selection, $matches ) == 1 ) {
			
			$start = time() - ( $matches[1] * 24 * 60 * 60);
			$this->where .= ' AND _submitted_ >=\'' . date( 'Y-m-d', $start ) . ' 00:00:00\'';

		} else if( preg_match( '/from(\d+)to(\d+)/', $selection, $matches ) == 1 ) {

			$this->where .= ' AND _rowid_>=' . $matches[1] . ' AND ' . '_rowid_<=' . $matches[2];  

		} else if( preg_match( '/from([ \d-:]+)to([ \d-:]+)/', $selection, $matches ) == 1 ) {

			$this->where .= ' AND _submitted_ >=\''
						 . Config::GetInstance()->MakeUTC( $matches[1] )
						 . '\' AND _submitted_ <\''
						 . Config::GetInstance()->MakeUTC( $matches[2] )
						 . '\'';

		} else if( preg_match( '/[\d,]/', $selection ) ) {

			$this->where .= ' AND rowid IN (' . $selection . ')';

		} else {

			writeErrorLog( 'Failed to interpret record selector:', $selection );
			$this->error = 'Failed to interpret record selector. Allowed formats are: "all", "4,6,7", "new", "starred", "3days", "from20to40" and "from2011-07-29 10:27:18to2011-07-30 10:27:18"';
		
			return false;
		}

		return true;
	}


	function GetPagedData ( )
	{
		// define some sensible defaults
		$input = array();
		$input['page'] = ( isset( $_GET['page'] ) ? $_GET['page'] : 1 ); 
		$input['pagelength'] = ( isset( $_GET['items_per_page'] ) ? $_GET['items_per_page'] : 20 ); 

		if( $this->fields !== false ) {
			$this->db->SetFieldClause( $this->fields ); 
		}

		if( $this->where !== false ) {
			$this->db->SetWhereClause( $this->where ); 
		}

		if( ! $this->_SetSortOrder() ) {
			return false;
		}

		$this->data['data'] =& $this->db->GetPage( $input['page'], $input['pagelength'] );

		if( $this->data['data'] === false ) {
			$this->data['error'] = 'Failed to get data, check the log.';
			return false;
		}

		$this->_PostProcessData();
		$this->data['pagination'] = $this->db->GetPagination();

		// always handy to have the general settings 
		$this->GetGeneralSettings();

		return $this->data;
	}


	function GetAllData ( )
	{
		if( $this->where !== false ) {
			$this->db->SetWhereClause( $this->where ); 
		}

		if( $this->fields !== false ) {
			$this->db->SetFieldClause( $this->fields ); 
		}

		if( ! $this->_SetSortOrder() ) {
			return false;
		}

		$this->data['data'] =& $this->db->GetAllRows();

		if( $this->data['data'] === false ) {
			$this->data['error'] = _T('Failed to get data.');
			return false;
		}

		$this->_PostProcessData();

		return true;
	}


	public function GetItemTransaction ( )
	{
		if( ! isset( $_GET['rowid'] ) )
		{	
			$this->data[ 'error' ] = 'Missing Row ID .';
			return false;
		}

		$transactionid = $this->db->GetTransactionId( $_GET['rowid'] );
		if( $transactionid === false )
		{
			$this->data['error'] = _T('Failed to get transaction id.');
			return;
		}
		elseif( $transactionid === '' )
		{
			// no error, but no data either
			return;
		}

		if( ! TransactionLogger::GetInstance()->readData ( $transactionid, $this->data['data'] ) )
		{
			$this->data['error'] = _T('Failed to get transaction data.');
			return;
		}

		// apply basic formatting to the output
		foreach( $this->data['data']['lines'] as &$lineitem )
		{
			$lineitem['price'] =  $this->curSign . number_format( $lineitem['price'] * $this->divider, $this->decimals );
			$lineitem['subtotal'] =  $this->curSign . number_format( $lineitem['subtotal'] * $this->divider, $this->decimals );
		}

		$this->data['data']['grandtotal'] =  $this->curSign . number_format( $this->data['data']['grandtotal'] * $this->divider, $this->decimals );
	}


	function GetItemData ( )
	{
		if( ! isset( $_GET['rowid'] ) )
		{	
			$this->data[ 'error' ] = 'Missing Row ID .';
			return false;
		}

		$this->data['data'] =& $this->db->GetItemData( $_GET['rowid'] );

		if( $this->data['data'] === false ) {
			$this->data['error'] = $this->db->error;
			return false;
		}

		// findout if there is any date formatting to be done
		$formatfields =& $this->_GetDateFieldNamesInOutput();		// case sensitive, because taken from form.cfg
		$amountfields =& $this->_GetAmountFieldNamesInOutput();		// lower cased, because taken form column names

		foreach( $formatfields as $name )
		{
			$this->data['data'][$name] = date( Config::GetInstance()->GetDateFormatByFieldname( $this->db->GetRealName( $name ) ),
											   strtotime( $this->data['data'][ $name ] ) );
		}

		if( isset( $this->data['data']['_submitted_'] ) )
			$this->data['data']['_submitted_'] = Config::GetInstance()->ApplyUserTimezone( $this->data['data']['_submitted_'] );

		// apply amount formatting and use original POST names
		$temp = array();
		foreach( $this->data['data'] as $name => $value )
		{
			if( isset( $amountfields[ $this->db->GetRealName( $name ) ] ) )
			{
				if( $amountfields[ $this->db->GetRealName( $name ) ] == 'form' )	
					$temp[ $this->db->GetAliasName( $name ) ] = $this->curSign . number_format( $value, $this->decimals );
				else if( $amountfields[ $this->db->GetRealName( $name ) ] == 'cart' )	
					$temp[ $this->db->GetAliasName( $name ) ] = $this->curSign . number_format( $value * $this->divider, $this->decimals );
			}
			else
			{
				$temp[ $this->db->GetAliasName( $name ) ] = $value;
			}
		}
		$this->data['data'] = $temp;

		$this->data['files'] =& $this->db->GetItemFiles( $_GET['rowid'] );

		if( $this->data['files'] === false )
		{
			$this->data['error'] = $this->db->error;
		}

		return true;
	}


	function UpdateItem ( )
	{
		if( ! isset( $_GET['rowid'] ) ||
			! is_numeric( $_GET['rowid'] ) )
		{

			$this->data[ 'error' ] = 'Row ID must be numeric.';
			return false;
		}

		$read = false;
		$starred = false;

		if( ! $this->_GetUpdateData( $read, $starred ) ) {
			// nothing to update
			return true;;
		}

		if( $this->db->UpdateItem( $_GET['rowid'], $read, $starred ) )

			$this->data['result'] = $this->db->result;
		else
			$this->data['error'] = $this->db->error;

		return true;
	}


	// limits the number of columns to return and possibly the max width of the text for each column
	// get the first x columns from the database, respecting any columns selected by the user	
	function SetSummaryMode ( $maxcolumns, $maxwidth )
	{
		$specials = array( '_fromaddress_', '_submitted_', '_read_', '_starred_', '_submit_limit_' );

		if( $maxwidth != -1 )			$this->maxwidth = $maxwidth;

		if( ! $this->db->HasSetting( 'columns' ) )
		{
			// get all available columns from the database because no user settings available
			$cols = $this->db->GetAllFields();
			if( $maxcolumns == -1 ) {

				$this->fields = $cols;
				
			} else {

				$this->fields = array();

				foreach( $cols as $fld ) {

					if( $maxcolumns-- <= 0 )		break;

					if( ! in_array( $fld, $specials ) ) {
	
						$this->fields[] = $fld;
					}

				}
			}
		}
		else
		{
			// get the columns from the db + the user settings
			$cols =& $this->_GetColumnsData();

			// order the values, maintaining the keys 
			asort( $cols );

			// build the fields string
			$this->fields = array( 'rowid' );

			foreach( $cols as $name => $state ) {

				// skip these names
				if( $state == 0 || in_array( $name, array( 'rowid' ) ) ) {
					
					continue;
				}

				// include these names
				if( $state != 0 )			$this->fields[] = $name;
			}			
		}

		// always include the special fields
		$this->fields = array_merge( $this->fields , $specials );
		$this->fields = array_unique( $this->fields );

		return true;
	}


	// returns an array of all available columns and if they are selected for the summary display
	// format: array( field_name => 0/1,....), 0/1 being no/yes included in summary
	function _GetColumnsData ( ) {

		// get all available columns from the database
		$cols = $this->db->GetAllFields();

		// get any user preference for a column
		$tmp = $this->db->GetSetting( 'columns' );
		$prefs = empty( $tmp ) ? array() : json_decode( $tmp, true );

		$columns = array();

		foreach( $cols as $name ) {

			if( ! isset( $prefs[ $name ] ) )		$columns[ $name ] = 1;
			else									$columns[ $name ] = $prefs[ $name ];
		}

		return $columns;
	}

	
	// value 0 -> don't include
	// value 1 -> include, lower numbers come first
	function UpdateColumns ( $columns ) {

		// $columns comes from $_POST, thus find the real names first
		$input = array();
		foreach( $columns as $key => $value) {
			$input[ $this->db->GetRealName( $key ) ] = $value;
		}

		// merge the input with the stored data
		$this->data['data'] =& $this->_GetColumnsData();

		$count = 0;
		foreach( $this->data['data'] as $name => $state )
		{
			if( ! isset( $input[ $name ] ) )			continue;
			
			if( ! is_numeric( $input[ $name ] ) ) {

				$this->data[ 'error' ] = 'columns must have a numeric value, columns are ordered ascending'; 
				return false;
			}

			$this->data['data'][ $name ] = (int)$input[ $name ];
			$count++;
		}

		if( ! $count && count( $columns) > 0 )	{
			$this->data[ 'error' ] = 'None of the specified columns was found in the database.';
			return false;
		}
		
		if( ! $this->db->SetSetting( array( 'columns' => json_encode( $this->data['data'] ) ) ) ) {

				$this->data[ 'error' ] = $this->db->errors[0]['err'];
				return false;
		}

		if( $count )
			$this->data[ 'result' ] = 'Stored column configuration, ' . $count . ' column state(s) updated.';

		return true;
	}


	function UpdateRows ( ) {

		$read = false;
		$starred = false;

		if( ! $this->_GetUpdateData( $read, $starred ) ) {
			// nothing to update
			return true;;
		}

		if( $this->where !== false ) {

			if( $this->db->UpdateWhere( $this->where, $read, $starred ) )
				$this->data['result'] = $this->db->result;
			else
				$this->data['error'] = $this->db->error;

		} else {

			$this->data[ 'error' ] = 'Specify a selection criteria and try again.';
			return false;
		}

		return true;
	}


	function DeleteRows ( ) {

		if( $this->where !== false ) {

			if( $this->db->DeleteWhere( $this->where ) )
				$this->data['result'] = $this->db->result;
			else
				$this->data['error'] = $this->db->error;

		} else {

			$this->data[ 'error' ] = 'Specify a selection criteria and try again.';
			return false;
		}

		return true;
	}


	public function GetGeneralSettings ( )
	{
		$gs = Config::GetInstance()->GetConfig( 'settings', 'general_settings' );
		if( $gs )				$this->data[ 'settings' ] = $gs; 
	}

	
	public function DropTransactions ( )
	{
		// nothing to do if the form doesn't have payments associated
		if( ! Config::GetInstance()->UsePayments() )
			return;

		TransactionLogger::GetInstance()->dropData();
	}


	function _SetSortOrder ( ) {

		if( isset( $_GET['sort_field'] ) && $_GET['sort_field'] != '' ) {

			$order_desc = ( isset( $_GET['order_desc'] ) && $_GET['order_desc'] );

			if( ! $this->db->SetSortOrder( $_GET['sort_field'], ! $order_desc ) ) {

				$this->data['error'] = 'Failed to set sort order.';
				return false;
			}
		}

		return true;
	}


	private function _PostProcessData ( )
	{
		// add the fields to the output first, we'll use this info below
		$this->_SetFields();

		// findout if there is any date formatting to be done

		// formatnames are taken from the config file, thus are case sensitive
		$formatfields =& $this->_GetDateFieldNamesInOutput();

		// fieldnames are derived from columns, which are lower cased
		$amountfields =& $this->_GetAmountFieldNamesInOutput();

		$nothingtodo = true;

		for( $i = 0; $i < count( $this->data['data'] ); $i++ )
		{
			foreach( $this->data['data'][ $i ] as $key => &$value )
			{
				$realName = $this->db->GetRealName( $key );

				if( in_array( $realName, $formatfields ) && ! empty( $value ) )
				{	
					// this only works if $value is by reference in the foreach clause
					$value = date( Config::GetInstance()->GetDateFormatByFieldname( $realName ) , strtotime( $value ) );
					$nothingtodo = false;
				}

				if( isset( $amountfields[ $realName ] ) )
				{
					if( $amountfields[ $realName ] == 'form' )	
						$value = $this->curSign . number_format( $value, $this->decimals );
					else	
						$value = $this->curSign . number_format( $value * $this->divider, $this->decimals );
				}
				else
				{
					$temp[ $this->db->GetAliasName( $name ) ] = $value;
				}

				if( $this->maxwidth != -1 &&
					strlen( $value ) > $this->maxwidth &&
					! in_array( $realName, array( '_submitted_', 'rowid', '_fromaddress_' ) ) )
				{
					$this->data['data'][ $i ][ $key ] = substr( $value, 0, $this->maxwidth ) . '...';
					$nothingtodo = false;
				}

				if( $key == '_submitted_' )
				{
					$value = Config::GetInstance()->ApplyUserTimezone( $value );
					$nothingtodo = false;
				}
			}

			// if nothing was done to the first record, nothing needs to be done to the rest
			if( $nothingtodo )			break;
		}

		// add limit exceeded a record count to the data
		$this->data['submit_limit_exceeded_count'] = $this->db->GetOverSubmitLimitCount();
	}


	function _GetUpdateData ( &$read, &$starred ) {

		if( isset( $_GET['read'] ) )		$read = $_GET['read'] ? 1 : 0;
		else								$read = false;

		if( isset( $_GET['starred'] ) )		$starred = $_GET['starred'] ? 1 : 0;
		else								$starred = false;

		// return false if nothing defined
		if( $read === false && $starred === false )			return false;
		else 												return true;
	}


	function _SetFields ( ) {
		
		if( $this->fields !== false && 					// add the used output fields when in summary mode
			is_array( $this->data['data'] ) &&
			isset( $this->data['data'][0] ) )			// use the keys of the data map if it is available
		{
			foreach( $this->data['data'][0] as $key => $value )
			{
				$this->data['fields'][] = $this->db->GetAliasName( $key );
			}
		}

		// always include all fields in the output
		$this->data['all_fields'] = array();
		foreach( $this->db->GetAllFields() as $fld )
		{
			$this->data['all_fields'][] = $this->db->GetAliasName( $fld );
		}

		// add the field that is used for sorting
		if( $this->db->sort !== false ) {
			$this->data['sort_field'] = $this->db->sort['field'];
		}
	}


	// only call this AFTER a call to _SetFields()
	private function _GetDateFieldNamesInOutput ( )
	{
		$datefields = array();
		$outputfields =& $this->_GetOutputFields();		
		if( ! is_array( $outputfields ) ) 	return array();

		foreach( $outputfields as $fn ) {

			$realName = $this->db->GetRealName( $fn );

			if( Config::GetInstance()->GetConfig( 'rules', $realName, 'fieldtype' ) == 'date' )
				$datefields[] = $fn;
		}

		// add them to the output, it might be needed for column alignment
		if( ! empty( $datefields ) )		$this->data['date_fields'] = $datefields;

		return $datefields;
	}


	///@return array with fieldnames that contain numeric money values (thus not dropdowns and alike)
	private function _GetAmountFieldNamesInOutput ( )
	{
		$amountfields = array();

		// fields that come from the cart are in cents, fields that come from
		// a form already have the correct value, thus add a type specifier to
		// the array elements
		$amountfields[ $this->db->GetRealName( '_grandtotal' ) ] = 'cart';

		$outputfields =& $this->_GetOutputFields();
		if( ! is_array( $outputfields ) ) 	return $amountfields;

		foreach( $outputfields as $fn ) {

			$realName = $this->db->GetRealName( $fn );

			if( Config::GetInstance()->GetConfig( 'payment_rules', $realName, 'type' ) == 'amount' &&
				Config::GetInstance()->GetConfig( 'rules', $realName, 'fieldtype' ) == 'number' )
			{
				$amountfields[ $realName ] = 'form';
			}
		}

		return $amountfields;
	}


	private function _GetOutputFields ( )
	{
		// which fields are being used?
		if( isset( $this->data['fields'] ) )
		   	$outputfields = $this->data['fields'];
		else if( isset( $this->data['all_fields'] ) )
		   	$outputfields = $this->data['all_fields'];
		else
			$outputfields = array_keys( $this->data['data'] );

		return $outputfields;
	}

}

?>