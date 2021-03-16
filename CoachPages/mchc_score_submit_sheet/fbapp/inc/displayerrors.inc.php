<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">      
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">

<head>
  <title>CoffeeCup Form Builder</title>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  <meta name="robots" content="noindex,nofollow" />
  <style>
    .error { color: #142dcc; }
    .warning { color: #007748; font-style: italic; }
  </style>
</head>
<body>
    <h4><?php echo _T('Your form could not be submitted for the following reason(s):'); ?></h4>
    <ul>
    <?php
        $page = FormPage::GetInstance();

        if( $page )                       $myErrors = $page->GetErrors();
        else if( isset( $errors ) )       $myErrors = $errors;
        else                              $myErrors = array( 'err' => 'Unspecified error.' );

        foreach( $myErrors as $error ) {
            if( isset( $error['err'] ) )    printf('<li class="error">%s</li>', $error['err']);
            if( isset( $error['warn'] ) )   printf('<li class="warning">%s</li>', $error['warn']);
        }
    ?>
    </ul>
</body>
</html>
