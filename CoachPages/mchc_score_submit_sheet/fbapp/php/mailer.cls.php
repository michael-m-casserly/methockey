<?php
/**
 * CoffeeCup Software's Web Form Builder.
 *
 *	Format of the $config array:
 *		$this->config['service'] = 'smtp';
 *		$this->config['smtp']['auth'] = 'false';
 *		$this->config['smtp']['user'] = '';
 *		$this->config['smtp']['password'] = '';
 *		$this->config['smtp']['host'] = $myPage->sdrive['smtp_server'];
 *		$this->config['smtp']['port'] = $myPage->sdrive['smtp_port'];
 *		$this->config['smtp']['secure'] = '';
 *
 *
 * @version $Revision: 2244 $
 * @author Cees de Gruijter
 * @category FB
 * @copyright Copyright (c) 2011 CoffeeCup Software, Inc. (http://www.coffeecup.com/)
*/



class Mailer {

	var $to;					// string or array
	var $from; 					// string
	var $replyto = ''; 			// string
	var $cc = '';				// string or array
	var $bcc = '';				// string or array
	var $subject = '';
	var $message = '';
	var $config = false;
	var $error = '';
	var $files = false;			// array format: $filename => $path
	var $mime_boundary;			// used for adding mime attachments

	/**
	 * Add this header to every message if set
	 * - use at own risk, there is no error checking and the \n is added by the script.`
	 */
	public $extra_header = false;

	function Mailer ( ) {
	}

	// Default behavior (no config defined) is to use the build-in PHP mail function.
	function SetConfig ( $config ) {

		$this->config =& $config;
	}

	// accepts array or comma seperated list as input
	function SetRecipients ( $recipients ) {

		if( empty( $recipients ) )		return false;
		$this->to = $recipients;
		return true;
	}

	// reset optional fields
	function ResetFields ( ) {

		$this->from = '';
		$this->replyto = '';
		$this->cc = '';
		$this->bcc = '';
		$this->subject = '';
		$this->message = '';

	}

	function SetFrom ( $from ) {

		// there can only be 1 from address
		if( ($pos = strpos( $from, ',')) === false )			$this->from = $from;
		else													$this->from = trim( substr( $from, 0, $pos ) );
	}

	function SetReplyTo ( $replyto ) {

		$this->replyto = $replyto;
	}

	function SetCC ( $cc ) {

		$this->cc = $cc;
	}

	function SetBCC ( $bcc ) {

		$this->bcc = $bcc;
	}


	function SetSubject ( $subject ) {

		// strip new lines
		$this->subject = str_replace( "\n", " ", $subject );
	}


	function SetMessage ( $message ) {

		$this->message = quoted_printable_encode( $message );
	}


	function Attach( $files ) {

		if( is_array( $files ) && empty( $files ) )
			$this->files = false;
		else
			$this->files = $files;
	}


	// returns false on failure
	function Send ( ) {

		// no user config or using localhost -> use php mailer.
		if( ! $this->config || $this->config['service'] == 'localhost' ) {
			return $this->_SendPHP();
		}
		return $this->_SendSMTP();

	}


	/************************** private functions ****************************/


	function _AddMimeHeaders ( &$headers ) {

		if( strpos( $this->message, '<html' ) === false  &&
			$this->files === false ) {

			$headers = 'Content-Type: text/plain; charset=utf-8' . "\n";
			$headers .= 'Content-Transfer-Encoding: quoted-printable' . "\n";
			return;
		}

		$headers .= 'MIME-Version: 1.0' . "\n";

	    // spamassassin is more sensitive about html messages than text message, so
	    // message must comply better with RFC. Especially missing message-id hurts.
		$headers .= 'Message-ID: <' . time() . '.' . strlen( $this->message )
				  . '@' . str_replace( 'www.', '', $_SERVER['HTTP_HOST'])
				  . '>' .  "\n";

		if( $this->extra_header )
			$headers .= $this->extra_header . "\n";

		if( $this->files === false ) {

			$headers .= 'Content-type: text/html; charset=utf-8' . "\n";
			$headers .= 'Content-Transfer-Encoding: quoted-printable' . "\n";

		} else {

			// Setup the unique mime boundary
			$this->mime_boundary = md5(time());                 

			$headers .= 'Content-Transfer-Encoding: 7bit' . "\n"
					  . 'Content-Type: multipart/mixed; boundary="'. $this->mime_boundary . "\"\n";
		}
	}


	function _AddAttachments ( ) {

		if( $this->files === false ||
			! is_array( $this->files ) ||
			empty( $this->files ) )					return;

		// rebuild $this->message into a multipart message using $this->mime_boundary

		if( strpos( $this->message, '<html' ) === false )
			$type = 'Content-Type: text/plain; charset=utf-8';
		else
			$type = 'Content-Type: text/html; charset=utf-8';

		$this->message = '--' . $this->mime_boundary . "\n"
					   . $type . "\n"
					   . 'Content-Transfer-Encoding: quoted-printable' . "\n\n"
					   . $this->message . "\n\n";

		foreach( $this->files as $usename => $filepath )
		{
			$contents = file_get_contents( $filepath );

			// Set up the new form owner message
			$this->message .= '--' . $this->mime_boundary . "\n"
							. 'Content-Type: application/octet-stream '
							. 'name="' . $usename . "\"\n"
							. 'Content-Transfer-Encoding: base64' . "\n"
							. 'Content-Description: ' . $usename . "\n"
							. 'Content-Disposition: attachment; filename="' . $usename . "\"\n\n"
							. chunk_split( base64_encode( $contents ) ) . "\n\n";

		}

		$this->message .= '--' . $this->mime_boundary . "--\n";
	}


	function _SendPHP ( ) {
		
		// transform array into a comma seperated list
		if( is_array( $this->to ) ) {
			$this->to = implode( ',' , $this->to );
		}

		$headers = '';
//		$headers = 'To: ' . $this->to . "\n";		// not needed, mail() adds it
	    $headers .= 'Date: ' .  date("D, j M Y G:i:s O") . "\n"; // Sat, 7 Jun 2001 12:35:58 -0700

		if( ! empty( $this->from ) ) {
			$headers .= 'From: ' . $this->from . "\n";
		}

		if( ! empty( $this->cc ) ) {
			if( is_array( $this->cc ) ) 	$this->cc = implode( ',' , $this->cc );
			$headers .= 'Cc: ' . $this->cc . "\n";
		}

		if( ! empty( $this->replyto ) ) {

			if( is_array( $this->replyto ) ) 	$this->replyto = implode( ',' , $this->replyto );
			$headers .= 'Reply-To: ' . $this->replyto . "\n";

		} else if( ! empty( $this->from ) )  {

			// PHPMailer always adds a reply-to, so let's do that here too.
			$headers .= 'Reply-To: ' . $this->from . "\n";
		}

		if( ! empty( $this->bcc ) ) {
			if( is_array( $this->bcc ) ) 	$this->bcc = implode( ',' , $this->bcc );
			$headers .= 'Bcc: ' . $this->bcc . "\n";
		}

		$this->_AddMimeHeaders( $headers );
		$this->_AddAttachments();

		// safe_mode doesn't allow a 'from' parameter
		if( empty( $this->from ) || ini_get( 'safe_mode' ) )
			$result = mail( $this->to, $this->subject, $this->message, $headers );
		else
			$result = mail( $this->to, $this->subject, $this->message, $headers, '-f ' . $this->from );

		if( ! $result ) {
			writeErrorLog( 'Sending PHP mail message failed. (to, from, reply, subject)=', array( $this->to, $this->from, $this->replyto, $this->subject) );
			$this->error = _T('Failed to send an email to "%s".', $this->to );
		}

		return $result;
	}


	function _SendSMTP ( ) {

		$config = &$this->config['smtp'];

		$mail = new PHPMailer();
		$mail->IsSMTP();
		$mail->CharSet = 'utf-8';
		$mail->Host = $config['host'];
		$mail->Port = $config['port'];
		$mail->SMTPSecure = $config['secure']; 
		$mail->SMTPAuth = $config['auth'];
		$mail->Username = $config['user'];
		$mail->Password = $config['password'];
		$mail->Subject = $this->subject;
		$mail->Encoding = 'quoted-printable';
		$mail->MsgHTML( quoted_printable_decode($this->message) );
		$mail->Timeout = 10;
		$mail->Debugoutput = writeErrorLog;

		$to = preg_split("/[;\s,]+/", $this->to, -1, PREG_SPLIT_NO_EMPTY);
		foreach( $to as $adr ) {
			$mail->AddAddress( $adr );
		}
		
		// reply-to MUST be set before from, or else the phpmailer adds the
		// from address to the reply-to header, which is not what we want for FB
		// see also PHPMailer::SetFrom($address, $name = '',$auto=1), around line 507
		if( !empty( $this->replyto ) ) {
			if( is_array( $this->replyto ) ) {
				foreach( $this->replyto as $adr ) {
					$mail->AddReplyTo( $adr );
				}
			} else {
				$mail->AddReplyTo( $this->replyto );
			}
		}

		if( ! empty( $this->from ) ) {
			
			// accept formats name@domain.ext or Name <name@domain.ext>
			$pos = strpos( $this->from, '<' );
			
			if( $pos === false ) {
				//global $myPage;
				//$name = $myPage->getConfigS('shopname');
				//if( $name )			$mail->SetFrom( $this->from, $name );
				//else 				$mail->SetFrom( $this->from );
				$mail->SetFrom( $this->from );
			} else {
				$name = substr( $this->from, 0, $pos );
				$addr = str_replace( '>', '', substr( $this->from, $pos + 1 ) );
				$mail->SetFrom( $addr, $name );
			}
		}

		if( !empty( $this->cc ) )
		{
			if( is_string( $this->cc ) )
				$this->cc = preg_split("/[;\s,]+/", $this->cc, -1, PREG_SPLIT_NO_EMPTY);

			if( is_array( $this->cc ) )
			{
				foreach( $this->cc as $adr ) {
					$mail->AddCC( $adr );
				}
			}
		}

		if( !empty( $this->bcc ) ) {
			if( is_array( $this->bcc ) ) {
				foreach( $this->bcc as $adr ) {
					$mail->AddBCC( $adr );
				}
			} else {
				$mail->AddBCC( $this->bcc );
			}
		}

		if( $this->extra_header )
			$mail->AddCustomHeader( $this->extra_header );

		if( $this->files !== false ) {		
			foreach( $this->files as $name => $path ) {
  				if( ! $mail->AddAttachment( $path, $name ) )
  					writeErrorLog( 'Mailer could locate attachment:', $path );
  			}
  		}

		if( ! $mail->Send() )  {

			writeErrorLog( $mail->ErrorInfo );
			$this->error = $mail->ErrorInfo;
			return false;
		}

		return true; 
	}
}

?>