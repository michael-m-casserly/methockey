<?php

$scriptpath = substr( $_SERVER['SCRIPT_FILENAME'], 0, -4 );
$paths = explode ( DIRECTORY_SEPARATOR , $scriptpath );
$myName = end($paths);
require $scriptpath . '/fbapp/php/config.inc.php';


if( strtoupper( $_SERVER['REQUEST_METHOD'] ) == 'POST' ) {

	$ctl = new FormController();
	$ctl->Dispatch();

} else {
	
	if( ! isset( $_GET['action'] ) )		$myPage->ReportStats( 'NotifyFormView' );
}

ob_start();

$myPage->Show();
ob_end_flush();

?>
