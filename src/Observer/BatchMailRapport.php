<?php
namespace Jgauthi\Component\Log\Observer;

use InvalidArgumentException;
use Jgauthi\Component\Log\Batch;

class BatchMailRapport extends AbstractBatchObserver
{
    // Modes disponibles
    public const MODE_SENDMAIL_ENDSCRIPT = 'sendmail_endscript';
    public const MODE_SENDMAIL_IF_ERROR = 'sendmail_if_error';
    public const MODE_SENDMAIL_MANUAL = 'sendmail_manual';

    // Configuration mail en cas d'erreur
    private ?array $callback_mail_function = null;
    protected ?string $mail_admin = null;

    // Messages
    protected string $mode;
    /** @var false|resource $logfile */
    protected $logfile;

    /**
	 * Définir une fonction d'envoie de mail (conseillé pour les serveurs utilisant du smtp).
	 *
	 * @param callable $function Nom de la fonction, ou array(class, method)
	 * @param array $args Arguments de la fonction DANS l'ordre de la fonction. Array('hook_name' => 'value|null pour autocomplete'), cf keywords dans la fonction
	 *
	 * @param string $mail_admin
	 * @param string $mode
	 */
    public function __construct(callable $function, iterable $args, string $mail_admin, string $mode = self::MODE_SENDMAIL_ENDSCRIPT)
    {
        if (empty($args)) {
        	throw new InvalidArgumentException("La fonction de callback mail déclaré '{$function}' n'a pas d'arguments.");
        }

        // Les mots clés sont les arguments acceptés pour la fonction
        // 'keywords'	=> array('hook_name' => 'action')
        $this->callback_mail_function = ['function' => $function, 'args' => []];
        $this->mail_admin = $mail_admin;
        $keywords = [
            'to' => 'mail',
            'to_name' => 'name',
            'title' => null,
            'content' => null,
            'from' => 'mail',
            'from_name' => 'name',
            'reply' => 'mail',
            'reply_name' => 'name',
            'html' => 'bool',
            'smtp' => 'bool',
        ];

        // Renseigné les arguments de la fonction dans l'ordre, $args = array($hook1 => 'value', $hook2 => 'value'...)
        foreach ($args as $hook => $value) {
            // Valeur null = auto completion, sinon valeur imposé
            if (isset($keywords[$hook])) {
                $this->callback_mail_function['args'][$hook] = ((null !== $value) ? $value : $keywords[$hook]);
            } else {
                $this->callback_mail_function['args'][$hook] = null;
            }
        }

        $this->logfile = tmpfile();	// Création d'un fichier temporaire pour stocker les logs
        $this->mode = $mode;		// Sendmail mode: end script / if error / manual
    }

	public function log(string $msg, ?string $type = null): void
    {
        fwrite($this->logfile, BatchLogFile::msg_format($msg, $type)."\n");
    }

	public function sendmail(): bool
    {
        if (empty($this->mail_admin)) {
            return false;
        }

        $subject = 'Rapport du script > Log '.$this->name.' du '.date('d/m/Y');

        // Contenu du rapport
        $msg = [];
        if (!empty($_SERVER['HTTP_HOST']) && !empty($_SERVER['REQUEST_URI'])) {
            $msg[] = "URL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
        } else {
            $msg[] = "SCRIPT: {$_SERVER['PHP_SELF']}";
        }

        $msg[] = 'DATE: '.date('d/m/Y H\hi');

        // Contenu du log
        $log_contents = file(stream_get_meta_data($this->logfile)['uri'], FILE_IGNORE_NEW_LINES);

        // Ajouter le contenu du log
        $msg[] = null; // imposer retour à la ligne
        $msg = implode("\n", array_merge($msg, $log_contents));

        // Utilisation fonction mail classique
        if (empty($this->callback_mail_function)) {
            return @mail($this->mail_admin, $subject, $msg);
        }

        // Envoie du mail, utilisation d'une fonction de callback
        $args = []; // Préparation des arguments

        foreach ($this->callback_mail_function['args'] as $hook => $value) {
            if ('title' === $hook) {
                $args[$hook] = trim($value.' '.$subject);
            } elseif ('content' === $hook) {
                $args[$hook] = trim($value."\n\n".$msg);
            } elseif (\in_array($hook, ['html', 'smtp'], true)) {
                $args[$hook] = (('bool' !== $value) ? $value : false);
            } elseif (\in_array($hook, ['to', 'from', 'reply'], true) && 'mail' === $value) {
                $args[$hook] = $this->mail_admin;
            } elseif (\in_array($hook, ['to_name', 'from_name', 'reply_name'], true) && 'name' === $value) {
                $args[$hook] = 'System';
                if (!empty($_SERVER['HTTP_HOST'])) {
                    $args[$hook] .= ' '.$_SERVER['HTTP_HOST'];
                }
            } else {
                $args[$hook] = $value;
            }
        }
        // Convertir en html si nécessaire
        if (isset($args['html']) && $args['html']) {
            $args['content'] = nl2br($args['content']);
        }

        return \call_user_func_array($this->callback_mail_function['function'], $args);
    }

    /**
     * Envoie d'un email à un administrateur.
     */
    public function __epilogue(Batch $batch): void
    {
        switch ($this->mode) {
            // Forcer l'envoie du mail si des erreurs ont été détectés
            case static::MODE_SENDMAIL_IF_ERROR:
                if ($batch->get_nb_error() <= 0) {
                    return;
                }
                break;

            // Envoie du mail à la fin du script
            case static::MODE_SENDMAIL_ENDSCRIPT:
                break;

            // Envoie manuel
            case static::MODE_SENDMAIL_MANUAL:
            default:
                return;
                break;
        }

        if ($this->sendmail()) {
            if ($batch->get_nb_error() > 0) {
                $batch->log('Un administrateur a été averti par email', 'info');
            } else {
                $batch->log('Une copie de ces logs a été transmis à l\'administrateur');
            }
        } else {
            $batch->log('Echec lors de l\'envoie du email', 'warning');
        }
    }
}
