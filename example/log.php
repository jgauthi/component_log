<?php
define('SCRIPT_VERSION', 1.43);
define('PATH_LOG_DIR', sys_get_temp_dir()); // Declare folder for logs

// In this example, the vendor folder is located in "example/"
require_once __DIR__.'/vendor/autoload.php';

//-- Mail config ------------------------------------------------------------------------------------
function local_send_mail($from = null, $fromName = null, $replyTo = null, $dest = null, $subject = 'Untitled', $msg = null, $isSmtp=false, $isHtml=false)
{
    // You can use any mail lib, example here with phpmailer
	$mail = new PHPMailer();
	$mail->IsHTML((bool)$isHtml);

	$mail->FromName = $fromName;
	$mail->AddAddress($dest);
	$mail->AddReplyTo($replyTo);
	$mail->Subject = $subject;
	$mail->Body = $msg;

	if($mail->Send())
	{
		if($isSmtp)
			$mail->SmtpClose();

		return true;
	}
	else return !user_error("Erreur lors de l'envoie du mail: {$mail->ErrorInfo}");
}

$email_admin = 'adminserver@yopmail.com';
$callback_func_mail = 'local_send_mail';
//-----------------------------------------------------------------------------------------------------


// Init & configuration batch
$batch = new batch('test_batch', SCRIPT_VERSION);
$batch->help(__DIR__ . '/batch_doc.txt');
$batch->log_get_all_error();
$batch->log('Lancement de la class du batch');

// Config Mail admin en cas d'erreur
$batch->mail_admin = $email_admin; // Init un email enverra un email à l'admin en cas d'erreur
$batch->log('Init mail admin: "'. ((!empty($email_admin)) ? $email_admin : 'NULL').'"');

// Ajout de la fonction de callback, avec les arguments dans le bon ordre
if(!empty($callback_func_mail) && !empty($email_admin))
{
	$batch->log($msg = "Init sendmail via la fonction de callback '$callback_func_mail'");
	$batch->mail_set_callback_function
	(
		$callback_func_mail,
		array
		(
			'from' 		=> null, // mail à null, reprend la valeur de {$batch->mail_admin}
			'from_name'	=> basename($_SERVER['PHP_SELF']),
			'reply'		=> null,
			'to'		=> null,
			'title'		=> 'Test class batch - ',
			'content'	=> $msg,
			'smtp'		=> true,
			'html'		=> true,
		)
	);
}

echo $var_dont_exists;
//echo func_not_exists();

$batch->log('Traitement en cours... (3s)');

for($i = 0; $i < 92; $i++)
{
	$batch->progressbar();
	usleep(60000); //
}

$batch->progressbar_reset();

// Récupération d'un argument (GET/POST pour le web, --varname=value pour cli)
$myvar = $batch->get_arg('file', 'NULL');
$batch->log('Récupération de la variable file: '.$myvar);

// Clean old export + old log (traitement lancé une fois par mois)
if(date('d') == '01')
	$batch->delete_old_logfile();

$batch->end_script();
?>