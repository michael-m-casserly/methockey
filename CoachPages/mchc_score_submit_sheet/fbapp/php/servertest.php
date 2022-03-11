<?php 

/**
 * CoffeeCup Software's Web Form Builder.
 *
 * Tests the server for minimum requirements.
 *
 *
 * @version $Revision: 2456 $
 * @author Cees de Gruijter
 * @category FB
 * @copyright Copyright (c) 2011 CoffeeCup Software, Inc. (https://www.coffeecup.com/)
 */

$has_session = session_start();

?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "https://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">      
<html xmlns="https://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
  <title>CoffeeCup Web Form Builder</title>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  <meta name="robots" content="noindex,nofollow" />
  <style type="text/css">
   <!--
    div#script_info {
       border-top: 1px solid #666;
       font-size:  .85em;
    }
    p.data { 
      margin-top: 0px;
      margin-left: 20px;
    }
    p.section { 
      margin-bottom: 0px;
    }
   -->
  </style>
</head>
<body>
    <h4>Server configuration</h4>
    <div id="script_info">

<?php
  @$txt = file_get_contents( '../../form.cfg.php' );
  if( $txt ) {
    $config = json_decode( substr( $txt, strpos( $txt, "{" ) ), true );
    echo '<p>FormBuilder version: ' . $config['application_version'] . '</p>';
  }

  @include_once 'SdriveConfig.php';
  if( class_exists( 'SdriveACL' ) ) {
      echo '<p>S-Drive has everything under control - don\'t worry.</p>';
  } else {
?>
      <p>
        PHP Version: <?php echo PHP_VERSION; ?> (should be 5.3 or newer)
        <?php if( version_compare( PHP_VERSION, '5.3' ) < 0 ) {?>
          <span style="color:red";><b>FAILED</b></span>
        <?php } ?>
      </p>
      <p>
        Curl extensions:
        <?php if( !function_exists('curl_version') ) {?>
           <span style="color:red";><b>FAILED</b></span>
        <?php } else { ?>
           <?php echo 'OK' ?>
        <?php } ?>
      </p>
      <p class="section">Mail configuration in php.ini file:</p>
      <p class="data">
       Sendmail Path:<?php echo ini_get('sendmail_path'); ?><br />
       Sendmail From:<?php echo ini_get('sendmail_from'); ?><br />
       SMTP:<?php echo ini_get('SMTP'); ?><br />
       SMTP Port:<?php echo ini_get('smtp_port'); ?>
      </p>
      
      <p class="section">Data base access:</p>
      <p class="data">
       MySQL: <?php echo (extension_loaded('mysql') ? 'Installed' : 'Not Installed'); ?><br />
       SQLite: <?php echo (extension_loaded('sqlite') ? 'Installed' : 'Not Installed'); ?><br />
<?php  if( extension_loaded('pdo') ) { ?>
       PDO - MySQL driver: <?php echo (extension_loaded('pdo_mysql') ? 'Installed' : 'Not Installed'); ?><br />
       PDO - SQLite driver: <?php echo (extension_loaded('pdo_sqlite') ? 'Installed' : 'Not Installed'); ?>
<?php } else { ?>
       PDO: Not Installed - without this module you can't save or access data.<br />
<?php } ?>
      </p>
      <p class="section">File upload configuration:</p>
      <p class="data">
        File Uploads: <?php echo (ini_get('file_uploads') ? 'On' : 'Off'); ?><br />
        File Uploads Max Size: <?php echo ini_get('upload_max_filesize'); ?><br />
        Post Max Size: <?php echo ini_get('post_max_size'); ?>
     </p>
     <p class="section">PHP Sessions:</p>
     <p class="data">
<?php
      if( ! $has_session ||
          ( isset( $_GET[ 'sessiontest' ] ) && ! isset( $_SESSION[ 'testing' ] ) ) ) {

        echo 'Sorry, sessions don\'t appear to work on this server. FormBuilder won\'t work without them.';

      } else if( isset( $_GET[ 'sessiontest' ] ) && $_SESSION[ 'testing' ] != 'sdfsfdshfkdshkelkwefousdjfs' ) {

        echo 'Strange, it seems there is session data but it doesn\'t contain the data that was written to it. FormBuilder might not work.';

      } else if( isset( $_GET[ 'sessiontest' ] ) ) {

        echo 'Cool, sessions are usable!';

      } else {
        $url = $_SERVER[ 'REQUEST_URI'] . (strpos($_SERVER[ 'REQUEST_URI'], '?') === false ? '?sessiontest' : '');
        echo 'Click <a href="'. $url . '">here</a> to check if this server supports sessions';
        $_SESSION[ 'testing' ] = 'sdfsfdshfkdshkelkwefousdjfs';
      }
?>
     </p>
<?php
  }
?>

    </div>
</body>
</html>