<?php

/**
 * CoffeeCup Software's Web Form Builder.
 *
 * Methods to handle data mails.
 *
 *
 * @version $Revision: 2456 $
 * @author Cees de Gruijter
 * @category FB
 * @copyright Copyright (c) 2011 CoffeeCup Software, Inc. (https://www.coffeecup.com/)
 */


define( 'CC_USERCONFIG', 'user.cfg.php');


class DataSaveMailer extends DataSave {

	private $mailer;
	private $default_from;

	public $cart = false;		// cart is needed to subsitute fieldnames when payment is enabled


	function __construct ( $cfg_section ) {

		parent::__construct( $cfg_section );

		$this->mailer = new Mailer();

		if( Config::GetInstance()->sdrive ) {

			// use sdrive config if available
			$config['service'] = 'smtp';
			$config['smtp']['auth'] = false;
			$config['smtp']['user'] = '';
			$config['smtp']['password'] = '';
			$config['smtp']['host'] = Config::GetInstance()->sdrive['smtp_server'];
			$config['smtp']['port'] = Config::GetInstance()->sdrive['smtp_port'];
			$config['smtp']['secure'] = '';
			$this->mailer->SetConfig( $config );

			// stop our newsletter processing system from rewrite all URLs and adding the tracking image.
			$this->mailer->extra_header ='X-GreenArrow-MailClass: noclick';

		} else {

			// check if the user config exists in include path
			$handle = @fopen( CC_USERCONFIG, 'r', 1 );
			if( $handle ) {
				fclose( $handle );
				include CC_USERCONFIG;
				if( isset( $user_config['mailer'] ) ) {
					$this->mailer->SetConfig( $user_config['mailer'] );
				}
			}
		}

		// generate default from address, remove all characters that aren't alpha numerical to avoid problems
		global $myName;
		$name = preg_replace( '/[^a-z0-9]/i', '', $myName );

		// get a server name, we need HTTP_X_FORWARDED_HOST when on sdrive
		if( isset( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ) {

			list( $server ) = explode( ',', $_SERVER['HTTP_X_FORWARDED_HOST'] );

		} else {

			$server = $_SERVER['SERVER_NAME'] ;
		}

		// set the default
		$this->default_from = $name . '@' . $server;
	}


	function __destruct ( )
	{
		// remove the temporary files from our temp storage if we used them
		$tempuploads = Config::GetInstance()->GetSessionVariable( CC_FB_TEMPUPLOADS );
		if( ! $tempuploads )		return;

		$filepath = Config::GetInstance()->GetStorageFolder( 5 );
		foreach( $tempuploads as $post_name => $file_name )
		{
			unlink( $filepath . $file_name );
		}
	}


	// save really means "send" in this context.
	// notification messages are sent with a from address using the form and server names 
	// auto-response message are sent with the from address defined, or auto generated one if it's missing 
	function Save ( )
	{
		// don't send anything anymore when the contracted limit has been reached
		if( Config::GetInstance()->isOverSubmitLimit() )
			return;

		if( Config::GetInstance()->GetConfig( 'settings', $this->cfg_section, 'auto_response_message', 'is_present' ) ) {
			$this->_Send( 'auto_response_message' );
		}
		
		if( Config::GetInstance()->GetConfig( 'settings', $this->cfg_section, 'notification_message', 'is_present' ) ) {
			$this->_HandleAttachedFiles();
			$this->_Send( 'notification_message' );
		}
	}


	function _HandleAttachedFiles ( )
	{
		$attachments = array();

		// get the list of files we put in our temp storage
		$tempuploads = Config::GetInstance()->GetSessionVariable( CC_FB_TEMPUPLOADS );

		// check the rules for any files that must be attached
		$rules = Config::GetInstance()->GetConfig( 'rules' );
		foreach( $rules as $fieldname => $rule ) {

			if( ($rule->fieldtype != 'sigpad') && ($rule->fieldtype != 'fileupload' || $rule->attach == false ) )
			{
				continue;
			}

			$org_fieldname = Config::GetInstance()->GetOriginalPostKey( $fieldname );
			
			// find out if the file is in the temp space else look for it in the uploads table
			if( isset( $_FILES[ $org_fieldname ] ) && file_exists( $_FILES[ $org_fieldname ][ 'tmp_name' ] ) )
			{
				$attachments[ FormPage::GetInstance()->post[ $fieldname ] ] = $_FILES[$org_fieldname]['tmp_name'];
			}
			elseif( $tempuploads && isset( $tempuploads[ $fieldname ] ) )
			{
				$filepath = Config::GetInstance()->GetStorageFolder( 5 ) . $tempuploads[ $fieldname ];

				if( file_exists( $filepath ) )
				{
					$attachments[ FormPage::GetInstance()->post[ $fieldname ] ] = $filepath;
					continue;
				}
				else
				{
					writeErrorLog( 'Attachment file not found in temp storage nor in:', $filepath );
				}
			}
			else
			{
				foreach( FormPage::GetInstance()->uploads as $up )
			   	{
					if( $up['fieldname'] != $fieldname )		continue;

					$filepath = Config::GetInstance()->GetStorageFolder( 1 ) . $up['storedname'];

					if( file_exists( $filepath ) )
				   	{
						$attachments[ $up['storedname'] ] = $filepath;
						continue;
					}
				   	else
				   	{
						writeErrorLog( 'Attachment file not found in :', $filepath );
					}
				}
			}
		}

		if( empty( $attachments ) ) 
			$this->mailer->Attach( false );
		else
			$this->mailer->Attach( $attachments );
	}


	function _Send( $spec ) {

		if( ! Config::GetInstance()->GetConfig( 'settings', $this->cfg_section, $spec, 'is_present') )
			return;

		$this->mailer->ResetFields();

		$cfg_spec = Config::GetInstance()->GetConfig( 'settings', $this->cfg_section, $spec );
		$cfg_replyto = Config::GetInstance()->GetConfig( 'settings', $this->cfg_section, $spec, 'replyto' );
		$cfg_cc = Config::GetInstance()->GetConfig( 'settings', $this->cfg_section, $spec, 'cc' );
		$cfg_bcc = Config::GetInstance()->GetConfig( 'settings', $this->cfg_section, $spec, 'bcc' );
		$cfg_subject = Config::GetInstance()->GetConfig( 'settings', $this->cfg_section, $spec,  'custom', 'subject' );
		$cfg_body = Config::GetInstance()->GetConfig( 'settings', $this->cfg_section, $spec, 'custom', 'body' );

		$to = $this->_SubstituteAddress( $cfg_spec->to );

		// In case the email is formatted as Name<email@domain.com> we need to get the email@domain.com
		$angle_init = strpos($to, '<');
		$angle_end = strpos($to, '>');
		if ( $angle_init !== false && $angle_end !== false ) {
			$angle_length = $angle_end - $angle_init;
			$to = substr( $to, $angle_init + 1, $angle_length -1 );
		} 
		
		
		if( $to == '' && ! empty( $cfg_spec->to ) ) {

			// happens when the to field is a magic field, but the user didn't fill it in
			// return without an error
			return;

		} else if( $to == '' || ! $this->mailer->SetRecipients( $to ) ) {

			$this->errors[] = array( 'err' => _T('Could not send an email because the recipient isn\'t defined.') );
			writeErrorLog('Can\'t send a mail message when the "to:" field is empty.');
			return;
		}

		// set default and update with settings if available
		$this->mailer->SetFrom( $this->default_from );

		if( isset( $cfg_spec->from ) && $cfg_spec->from ) {		

			$from = $this->_SubstituteAddress( $cfg_spec->from );
			if( ! empty( $from ) ) 		$this->mailer->SetFrom( $from );
		}

		if( isset( $cfg_spec->replyto ) && $cfg_spec->replyto ) {		

			$replyto = $this->_SubstituteAddress( $cfg_spec->replyto );
			if( ! empty( $replyto ) ) 		$this->mailer->SetReplyTo( $replyto );
		}

		if( isset( $cfg_spec->cc ) && $cfg_spec->cc ) {

			$cc = $this->_SubstituteAddress( $cfg_spec->cc );
			if( ! empty( $cc ) ) 		$this->mailer->SetCC( $cc );
		}

		if( isset( $cfg_spec->bcc ) && $cfg_spec->bcc ) {

			$bcc = $this->_SubstituteAddress( $cfg_spec->bcc );
			if( ! empty( $bcc ) ) 		$this->mailer->SetBCC( $bcc );
		}

		// subject should not be html-encoded, that is done by the mailer
		MessagePostMerger::GetInstance()->setDecimals( Config::GetInstance()->GetConfig( 'settings', 'payment_settings', 'decimals' ) );
		$this->mailer->SetSubject( MessagePostMerger::GetInstance()->SubstituteFieldNames( $cfg_spec->custom->subject, false ) );
		$this->mailer->SetMessage( MessagePostMerger::GetInstance()->SubstituteFieldNames( $cfg_spec->custom->body, false ) );

		if( ! $this->mailer->Send() )
		{
			$this->errors[] = array( 'err' => $this->mailer->error );
		}
	}


	function _SubstituteAddress ( $name ) {

		$matches = array();
		$r = preg_match_all( '\'\[([^\]]+)\]\'', $name, $matches, PREG_PATTERN_ORDER );

		if( $r === false )				writeErrorLog( 'Error in regex parsing:', $name );
		if( ! $r )						return trim( $name );

		foreach( $matches[1] as $match ) {

			// check if this is an email field and get its value if it is
			$match = strtolower( $match );
			if( ( ( Config::GetInstance()->GetConfig( 'rules', $match, 'fieldtype' ) == 'email') ||
				  Config::GetInstance()->GetConfig( 'rules', $match, 'contactList' ) ) &&
				isset( FormPage::GetInstance()->post[ $match ] ) ) {

				$name = str_ireplace( '[' . $match . ']', FormPage::GetInstance()->post[ $match ] , $name);
			}
		}

		return trim( $name );
	}
}

?>