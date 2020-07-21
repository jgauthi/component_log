<?php
use Jgauthi\Component\Log\Batch;
use Jgauthi\Component\Log\Observer\BatchMailRapport;
use Symfony\Component\Mime\{Address, Email};
use Symfony\Component\Mailer\{Mailer, Transport};

define('SCRIPT_VERSION', 2.3);


//-- Mail config ------------------------------------------------------------------------------------

// Example with Symfony Mailer (you can use another lib)
$mailer = new Mailer(Transport::fromDsn('smtp://user:pass@smtp.example.com:port'));
$email_admin = 'adminserver@yopmail.com';

// Example with closure, you can set a function instead
$callback_func_mail = function($from = null, $fromName = null, $replyTo = null, $dest = null, $subject = 'Untitled', $msg = null, $isSmtp = false, $isHtml = false) use ($mailer): bool {

    if ($isHtml) {
        $html = $msg;
        $text = strip_tags($msg);
    } else {
        $html = nl2br($msg);
        $text = $msg;
    }

    $email = (new Email)
        ->from( new Address($from, $fromName) )
        ->replyTo($replyTo)
        ->to($dest)
        ->subject($subject)
        ->html($html)
        ->text($text)
    ;

    $mailer->send($email);
    return true;
};
//-----------------------------------------------------------------------------------------------------

// Init & configuration batch
$batch = new Batch('batch_v3_email');
$batchMail = new BatchMailRapport($callback_func_mail,
    // Declaration des arguments de la fonction en utilisant des mots clés pour identifier l'argument
    // src/Observer/BatchMailRapport.php:38
    // from/to à null, reprend la valeur de {$batch->mail_admin}
    [
        'from' => 'mail',
        'from_name' => basename($_SERVER['PHP_SELF']),
        'reply' => null,
        'to' => null,
        'title' => true,
        'content' => true,
        'smtp' => true,
        'html' => true,
    ],
    $email_admin,
    BatchMailRapport::MODE_SENDMAIL_IF_ERROR // Log return only if error is detected
//    BatchMailRapport::MODE_SENDMAIL_ENDSCRIPT // Log return at end script
//    BatchMailRapport::MODE_SENDMAIL_MANUAL // Log return by the code
);

$batch
    ->attach($batchMail)
    ->start(SCRIPT_VERSION)
    ->log('Lancement de la class du batch')
    ->log('Init mail admin: "'.$email_admin.'"')
    ->varExport($batch)
;

echo $var_dont_exists;
$batch->log('Fin du log');

// Envoie manuel
// $batchMail->sendmail();

$batch->end_script();