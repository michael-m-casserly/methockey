<?php

/**
 * CoffeeCup Software's Web Form Builder.
 *
 * Methods to handle CSV data files.
 *
 *
 * @version $Revision: 2456 $
 * @author Cees de Gruijter
 * @category FB
 * @copyright Copyright (c) 2011 CoffeeCup Software, Inc. (http://www.coffeecup.com/)
 */


class DataSaveCSV extends DataSave {

	private $fp;			// file handle
	private $outfile;
	private $ignoreFields = array( '_transactid_' );

	function DataSaveCSV ( $cfg_section ) {
		parent::__construct( $cfg_section );
	}


	function Save ( ) {

		if( $this->_GetFileHandle() ) {

			$data = array();
			$this->_FlattenPost();
			$rules = Config::GetInstance()->GetConfig( 'rules' );

			foreach( $rules as $field => $rule )
			{
				if( ! isset( $this->post[ $field  ] ) )
				{

					// it could still be an uploaded file
					$notfound = true;
					foreach( FormPage::GetInstance()->uploads as $up ) {

						if( $up[ 'fieldname' ] == $field ) {
							$data[] = $up[ 'storedname' ];
							$notfound = false;
							break;
						}
					}
					if( $notfound )		$data[] = '';
				}
				else if( $rule->fieldtype == 'date' && ! empty( $this->post[ $field ] ) )
				{
					$data[] = date( Config::GetInstance()->GetDateFormatByFieldname( $field ), $this->post[ $field ] );
				}
				else if( $field == '_submitted_' )
				{
					$data[] = Config::ApplyUserTimezone( $this->post[ $field ] ); 
				}
				else
				{
					$data[] = $this->post[ $field ];
				}
			}

			foreach( Config::GetInstance()->GetReservedFields() as $name )
			{
				if( isset( $this->post[ $name ] ) && $name == '_submitted_' )
				{
					$data[] = Config::GetInstance()->ApplyUserTimezone( $this->post[ $name ] );
				}
				else if( isset( $this->post[ $name ] ) )
				{
					$data[] = $this->post[ $name ];
				}
				else
				{
					$data[] = '';
				}
			}

			fputcsv( $this->fp, $data );
			fclose( $this->fp );

		} else {

			$this->errors[] = array( 'err' => _T('Failed to record the data because the server is too busy or doesn\'t have write permission.') );			
		} 
	}


	// First line in the output file is the field list.
	// Archive the file if this doesn't coincide with the current field list
	function _GetFileHandle ( ) {

		// where to save
		$this->output_file = Config::GetInstance()->GetStorageFolder( 3 )
						   . Config::GetInstance()->GetConfig( 'settings', 'data_settings', $this->cfg_section, 'filename' );

		if( ! file_exists( $this->output_file) ) {

			return $this->_MakeNew();

		} else if( ! $this->_CheckFields() ) {
			
			return $this->_Archive() && $this->_MakeNew();

		} else {

			if( ! is_writable( $this->output_file ) ) {
				writeErrorLog( 'Output file is not writable:', $this->output_file );
				return false;	
			}
			$this->fp = fopen( $this->output_file, 'a' );
			return $this->_GetLock( LOCK_EX );
		}
	}


	function _MakeNew ( ) {

		if( ! is_writable( dirname( $this->output_file ) ) ) {
			writeErrorLog( 'Output folder is not writable:', $this->output_file );
			return false;	
		}

		$this->fp = fopen( $this->output_file, 'a' );
		if( ! $this->_GetLock( LOCK_EX ) )		return false;

		$columns = Config::GetInstance()->GetFieldNames();

		foreach( Config::GetInstance()->GetReservedFields() as $name )
		{
			// translate the fieldname for non-english versions
			if( ! in_array( $name, $this->ignoreFields ) )		$columns[] = _T($name);
		}

		fputcsv( $this->fp, $columns );

		// leave file open for next write operation
		return true;
	}


	function _GetLock ( $locktype ) {

		if( ! $this->fp || ! getFileLock( $this->fp, $locktype ) ) {

			writeErrorLog( 'Failed to obtain lock on:', $this->output_file );
			if( $this->fp !== false )		fclose( $this->fp );
			return false;
		}

		return true;
	}


	function _CheckFields ( ) {

		$this->fp = fopen( $this->output_file, 'r' );

		if( ! $this->_GetLock( LOCK_SH ) ) {

			fclose( $this->fp );
			return false;
		}

		$fields = fgetcsv( $this->fp );
		fclose( $this->fp );

		// fields must be the same as rules + reserved_fields_count	
		$rulenames = Config::GetInstance()->GetFieldNames();
		$delta = array_diff( $fields , $rulenames );

		// get the (translated) reserved fields
		$resFlds = array_diff( Config::GetInstance()->GetReservedFields(), $this->ignoreFields );
		array_walk( $resFlds, 'translate_element_callback' );

		// use array_values because array_diff respects the keys, thus those will be different
		return array_values( $delta) == array_values( $resFlds );
	}
			
	
	function _Archive( ) {

		$i = 1;
		do {
			$newname = str_replace( '.', $i . '.', $this->output_file );
			$i++;
		} while ( file_exists( $newname ) );

		if( ! rename( $this->output_file, $newname ) ) {

			writeErrorLog( 'Failed to archive ' . $this->output_file . ' to:', $newname );
			$this->errors[] = array( 'err' => _T('Failed to archive data file.') );
			return false;
		}
		return true;
	}

}



?>