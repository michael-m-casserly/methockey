<?php

$txt = false;

if( file_exists( 'form.cfg.dat' ) )
	$txt = file_get_contents( 'form.cfg.dat' );
else if ( file_exists( 'form.cfg.php' ) )
	$txt = file_get_contents( 'form.cfg.php' );

if( $txt === false ) {
	echo 'null';
	exit(0);
}

$config = json_decode( substr( $txt, strpos( $txt, "{" ) ), true );

if( isset( $config['settings'] ) &&
	isset( $config['settings']['uid'] ) ) {

		echo $config['settings']['uid'];

} else {

	echo 'null';
}

?>