<?php
/**
* CoffeeCup Software's Web Form Builder.
*
*	This configuration file is intended to change the default behavior of the Web Form Builder scripts to
*	accomodate special needs some users may have due to the server setup they have to deal with.
*	
*	The user is responsible for any changes to this file.
*	Remove the '//' in front of the lines that you need add '//' in front of the lines you don't need.
*	
*
* @version $Revision: 2244 $
* @author Cees de Gruijter
* @category FB
* @copyright Copyright (c) 2011 CoffeeCup Software, Inc. (http://www.coffeecup.com/)
*/

/*********************** Send Mail Configuration ****************************/

// What service is available for sending mail? Possible values are:
//	- localhost	[default]		Server where the shop is installed, must be a Linux or Unix machine with sendmail
//	- smtp						A mail server of your choice that supports the standard SMTP protocol
$user_config['mailer']['service'] = 'localhost';
//$user_config['mailer']['service'] = 'smtp';

// Does the remote SMTP server need authentication?
$user_config['mailer']['smtp']['auth'] = true;
//$user_config['mailer']['smtp']['auth'] = false;

// Use this if the server needs authentication:
$user_config['mailer']['smtp']['user'] = 'name@your_domain.com';
$user_config['mailer']['smtp']['password'] = 'password';

// The address of the server
$user_config['mailer']['smtp']['host'] = 'mail.your_domain.com';
//$user_config['mailer']['smtp']['host'] = 'smtp.gmail.com';

// Connection parameters for a plain SMTP server
$user_config['mailer']['smtp']['port'] = '25';
$user_config['mailer']['smtp']['secure'] = '';

// Connection parameters for a SMTP server that supports secure connections, such as smtp.gmail.com
//$user_config['mailer']['smtp']['port'] = '465';
//$user_config['mailer']['smtp']['secure'] = 'ssl';

?>