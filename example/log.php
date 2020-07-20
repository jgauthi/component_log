<?php
define('SCRIPT_VERSION', '2.1.2');
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
$batch = new Batch('batch_v2_mindclass');
$batch->help(__DIR__.'/batch_doc.txt');

// Déclaration des observeurs
$batch->attach( ((PHP_SAPI === 'cli') ? new batch_observer_cli : new batch_observer_web_plain) );
$batch->attach( new batch_observer_logfile(PATH_LOG_DIR, 'batch_v2') );

if(!empty($callback_func_mail) && !empty($email_admin))
{
    $batch->attach( new batch_observer_rapport_mail
    (
        $callback_func_mail,
        [
            'from' 		=> 'mail', // mail à null, reprend la valeur de {$batch->mail_admin}
            'from_name'	=> basename($_SERVER['PHP_SELF']),
            'reply'		=> null,
            'to'		=> null,
            'title'		=> true,
            'content'	=> true,
            'smtp'		=> true,
            'html'		=> true,
        ],
        $email_admin,
        batch_observer_rapport_mail::MODE_SENDMAIL_IF_ERROR
    ));
}


$batch->start(SCRIPT_VERSION);
$batch->log('Lancement de la class du batch');
$batch->varExport($email_admin);

// Config Mail admin en cas d'erreur
if(!empty($callback_func_mail) && !empty($email_admin))
    $batch->log('Init mail admin: "'. $email_admin.'"');

echo $var_dont_exists;
//echo func_not_exists();

// Work in progress
$batch->log('Traitement en cours... (3s)');
for($i = 0; $i < 92; $i++)
{
    $batch->progressbar();
    usleep(60000);
}
$batch->progressbar_reset();

// Variable pour définir le "produit" ou "dossier" en cours, pour l'observer PDO
$batch->code_ref = 'MYCODE02';

// Récupération d'un argument (GET/POST pour le web, --varname=value pour cli)
$myvar = $batch->get_arg('file', 'NULL');
$batch->log('Récupération de la variable file: '.$myvar);
user_error('Custom error notice');
$batch->log('Fin du log');

$batch->end_script();
?>