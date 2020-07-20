<?php
/*****************************************************************************************************
 * @name Batch (avec design pattern observer)
 * @note: Ensemble de class pour la gestion des batchs & log avec gestion des erreurs
 * @author Jgauthi <github.com/jgauthi>, crée le 2octobre2012
 * @version 2.1.3 (PHP5 Version)
 * @Requirements:
    - PHP 5.5+

 ******************************************************************************************************/

class Batch
{
    // Design pattern Observer
    protected $observers = [];
    private $name;

    // Configuration Log
    protected $nb_error = 0;
    protected $progressbar = 0;

    // Config batch
    private $endscript = false;

    /**
     * Constructeur
     * @param string	$name_script	(Optionnel)
     */
    public function __construct($name = null)
    {
        // Current script name
        $this->name = ((!empty($name)) ? $name : basename($_SERVER['PHP_SELF']));
        $this->name = preg_replace('#[^a-z0-9._-]#i', '-', $this->name);
        $this->name = substr($this->name, 0, 40);

        set_time_limit(0);
        ini_set('max_execution_time', 0);
        ini_set('max_input_time', 3600);
        ini_set('memory_limit', '516M');

        // Récupérer les erreurs détectés par les autres classes
        ini_set('display_errors', 'On');
        error_reporting(E_ALL);
        set_exception_handler([$this, 'exception_handler_error']);
        set_error_handler([$this, 'error_handler_notice'], E_USER_NOTICE);
        set_error_handler([$this, 'error_handler_notice'], E_ALL ^ E_STRICT);
    }

    // Design pattern
    public function attach(AbstractBatchObserver $observer)
    {
        $observer->set_name($this->name);
        $this->observers[] = $observer;

        return $this;
    }

    public function detach(AbstractBatchObserver $observer)
    {
        if(is_int($key = array_search($observer, $this->observers, true)))
            unset($this->observers[$key]);
    }


    //-- Gestion des messages --
    public function start($version = null)
    {
        if(empty($this->observers))
            throw new InvalidArgumentException('No observer declared.');

        // Information du log
        $info = ['posix pid ID: '. getmypid()];
        if(!empty($version))
            $info[] = 'script version: '.$version;

        if(!empty($_SERVER['SCRIPT_FILENAME']) && @filemtime($_SERVER['SCRIPT_FILENAME']))
            $info[] = 'script datemaj: '. date("dMY H\hi", filemtime($_SERVER['SCRIPT_FILENAME']));

        // Formater la colonne avec les informations et les sharps #
        $info = implode(', ', $info);
        $nb_charp = (80-strlen($info));
        if($nb_charp < 1)
            $nb_charp = 1;

        // Lancer le timer
        if(empty($_SERVER['REQUEST_TIME_FLOAT']))
            $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);

        // $this->config_print_function();
        $this->log('<##DEBUT DE SESSION ('.$info.') '. str_repeat('#', $nb_charp) );

        // Préparer la destruction de l'objet
        register_shutdown_function([$this, '__epilogue']);
    }

    /**
     * Scripts à lancer à la fin du script (__destruct désactivé car il s'executait pas dans le cas où le script plantait)
     * @return void
     */
    public function __epilogue()
    {
        // Mettre fin au timer
        $duree = ceil((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000);

        // Vérifier si le client a abandonné la connexion
        if(connection_aborted())
            $this->log('Connexion HTTP interrompu avec l\'utilisateur ayant lancé le script.', 'info');

        if($this->nb_error > 1)
            $this->log("Le comportement du script a peut être été altéré car {$this->nb_error} erreurs se sont produites durant son execution", 'warning');
        elseif($this->nb_error == 1)
            $this->log('Le comportement du script a peut être été altéré car une erreur s\'est produite durant son execution', 'warning');

        // Vérifier que le script s'est terminé convenablement
        if(false === $this->endscript)
            $this->log('Le script a été interrompu lors de son execution !', 'warning');

        // Lancer les fins de scripts des différents observer
        foreach($this->observers as $observer)
            $observer->__epilogue($this);

        // Mémoire occupé
        $memory = memory_get_usage();
        $unit = ['o','ko','mo','go','to','po'];
        $memory = @round($memory / pow(1024, ($i = floor(log($memory, 1024)))),2).' '.$unit[$i];

        $this->log("###FIN DE SESSION (duree {$duree} milliseconds, mémoire utilisé: {$memory}) ################################>");
    }


    /**
     * Récupération des arguments du script
     * Compatible REQUEST pour le mode web, cli args "--name" ou "--name=value" pour php-cli
     * @param  string 		$name
     * @param  string|array $default_value
     * @return string|array
     */
    public function get_arg($name, $default_value = null)
    {
        // Mode cli
        if(PHP_SAPI === 'cli')
        {
            global $argv;

            $expreg = "#^--{$name}(=([^$]+))?$#i";
            $search = preg_grep($expreg, $argv);

            if(!empty($search))
            {
                $result = reset($search);
                if(preg_match($expreg, $result, $row))
                    $value = ((!empty($row[2])) ? $row[2] : true);
            }

            if(!isset($value))
                return $default_value;
        }
        // Mode web
        else
        {
            if(!isset($_REQUEST[$name]))
                return $default_value;

            $value = $_REQUEST[$name];
            if($value == '')
                $value = true;
        }

        return $value;
    }

    /**
     * Retourne le nombre d'erreur détecté durant l'execution du script
     * @return int
     */
    public function get_nb_error()
    {
        return $this->nb_error;
    }

    /**
     * Affiche / écrit dans un log: un message
     * @param  string	$msg
     * @param  string	$type	null|success|warning|info
     * @return void
     */
    public function log($msg, $type = null)
    {
        foreach($this->observers as $observer)
            $observer->log($msg, $type);
    }

    /**
     * Affiche / écrit dans un log: une erreur
     * @param  string	$msg
     * @return void
     */
    public function error($msg)
    {
        $this->nb_error++;

        foreach($this->observers as $observer)
            $observer->error($msg);
    }


    /**
     * Affiche une documentation de la cron, commande à placer en début de script
     * @param  file	$file	Format txt (pour le moment, il faudra ajouter d'autres formats)
     * @return die
     */
    public function help($file)
    {
        // Afficher l'aide si l'argument help est demandé
        $display_help = $this->get_arg('help');
        if(empty($display_help))
            return;

        elseif(!is_readable($file))
            $this->log("Le fichier d'aide \"{$file}\" n'est pas lisible ou n'existe pas.");

        // Affichage de l'aide et fin du script
        header('Content-Type: text/plain; charset=UTF-8');
        readfile($file);

        $this->end_script();
        return die();
    }

    /**
     * Ajoute les erreurs PHP dans les logs du batch
     * @param  int 		$errno
     * @param  string	$errstr
     * @param  string	$errfile
     * @param  string	$errline
     * @return void
     */
    public function error_handler_notice($errno, $errstr, $errfile, $errline)
    {
        $this->error("PHP Error: {$errstr} in file '{$errfile}:{$errline}'");
    }

    /**
     * Ajouter les exceptions non cath dans les logs du batch
     * @param  object $message
     * @return void
     */
    public function exception_handler_error($e)
    {
        $this->error("Exception no catch error: {$e->getMessage()} in file '{$e->getFile()}:{$e->getLine()}");
    }

    /**
     * A executer à la fin du script, permet de vérifier qu'il n'a pas été interrompu en cours de route
     * @param  string $msg_fin        Message à afficher à l'écran à la fin du batch
     * @return void
     */
    public function end_script()
    {
        $this->endscript = true;
    }

    /**
     * Récupération des erreurs PHP dans les logs
     * @return void
     */
    public function varExport()
    {
        $args = func_get_args();
        $debug_var = [];

        foreach($args as $var)
        {
            if(is_null($var))			$var = '# NULL #';
            elseif($var === false)		$var = '# false #';
            elseif($var === true)		$var = '# true #';
            elseif($var == '')			$var = '# empty #';

            elseif(is_string($var))
                $var = stripslashes(substr(var_export(wordwrap($var, 160), true), 1, -1));

            elseif(is_numeric($var))
                $var = var_export(wordwrap($var, 160), true);

            else $var = var_export($var, true);

            $debug_var[] = $var;
        }
        $this->log('Debug var: '.implode("\n", $debug_var), 'info');
    }


    /**
     * Affichage d'une barre de progression
     * @return void
     */
    public function progressbar()
    {
        echo '*';

        if($this->progressbar >= 30)
            $this->progressbar_reset();
        else $this->progressbar++;
    }

    /**
     * Ré-initialise le compteur de la barre de progression
     * @return void
     */
    public function progressbar_reset()
    {
        $this->progressbar = 0;
        echo "\n";
    }
}


abstract class AbstractBatchObserver
{
    protected $name;

    abstract public function log($msg, $type = null);

    /**
     * Error messages (can be re-write by children)
     * @param  string $msg
     * @return void
     */
    public function error($msg)
    {
        $this->log($msg, 'error');
    }

    /**
     * Nom du script en cours
     * @param string $name
     */
    public function set_name($name)
    {
        $this->name = $name;
    }

    /**
     * (Optionnel) Action executé automatiquement à la fin du script
     * @return void
     */
    public function __epilogue(Batch $batch)
    {
        return; // Aucune action par défaut
    }
}


class batch_observer_cli extends AbstractBatchObserver
{
    private $function;

    public function __construct()
    {
        // Print msg on differents canal
        if(!defined('STDOUT') && !defined('STDERR'))
        {
            define('STDOUT', fopen('php://stdout', 'w'));
            define('STDERR', fopen('php://stderr', 'w'));

            register_shutdown_function(function() { fclose(STDOUT); fclose(STDERR); });
        }

        // Support color theme
        $this->function = ($this->support_terminal_color() ? 'log_terminal_color' : 'log_no_filter');
    }

    // https://cweiske.de/tagebuch/php-auto-coloring-output.htm
    protected function support_terminal_color()
    {
        if(DIRECTORY_SEPARATOR === '\\')
        {
            return false !== getenv('ANSICON')
                || 'ON' === getenv('ConEmuANSI')
                || 'xterm' === getenv('TERM');
        }

        return function_exists('posix_isatty') && @posix_isatty(STDOUT);
    }

    /**
     * Retourne un message avec une couleur de fond correspond au status
     * @param  string $msg
     * @param  string $type 	null|success|error|warning|info
     * @return string
     */
    protected function log_terminal_color($msg, $type = null)
    {
        switch($type)
        {
            case 'success':	$out = '[42m';	break;	// Green background
            case 'error':	$out = '[41m';	break;	// Red background
            case 'warning':	$out = '[43m';	break;	// Yellow background
            case 'info':	$out = '[44m';	break;	// Blue background
            default:		return $msg;	break;
        }
        return chr(27) . $out . $msg . chr(27) . '[0m';
    }

    protected function log_no_filter($msg, $type = null)
    {
        return $msg;
    }


    public function log($msg, $type = null)
    {
        fwrite(STDOUT, call_user_func([$this, $this->function], $msg, $type)."\n"); // -v 1>>msg.log
    }

    public function error($msg)
    {
        fwrite(STDERR, call_user_func([$this, $this->function], $msg, 'error')."\n"); // -v 2>>error.log
    }
}

class batch_observer_logfile extends AbstractBatchObserver
{
    private $folder;
    protected $logfile;
    protected $datetime_delete_oldfile;

    public function __construct($folder, $datetime_delete_oldfile = 'first day of -2 month')
    {
        if(empty($folder))
            throw new InvalidArgumentException('Folder empty in class: '. __CLASS__);

        elseif(!is_writable($folder))
            throw new InvalidArgumentException(sprintf('Folder "%s" not writeable in class: %s', $folder, __CLASS__));

        $this->folder = $folder;
        $this->datetime_delete_oldfile = $datetime_delete_oldfile;
    }

    public function set_name($name)
    {
        parent::set_name($name);
        $this->logfile = str_replace('\\', '/', "{$this->folder}/{$this->name}_" .date('Ymd'). '.log');
    }

    public function get_filename()
    {
        return $this->logfile;
    }

    public static function msg_format($msg, $type)
    {
        switch($type)
        {
            case 'success': break;

            case 'info':
                $msg = "[{$type}] {$msg}";
                break;

            case 'error':
                $msg = "/!\ {$msg}";
                break;

            case 'warning':
                $msg = "/!\ Warning: {$msg}";
                break;
        }

        return $msg;
    }

    public function log($msg, $type = null)
    {
        $msg = self::msg_format($msg, $type);
        error_log(date('Y-m-d H:i:s') ."\t{$msg}\n", 3, $this->logfile);
    }

    /**
     * Suppression des logs liés au batch datant de X temps
     * @param  Batch		$batch
     * @return void
     */
    public function __epilogue(Batch $batch)
    {
        if(!function_exists('glob') || empty($this->datetime_delete_oldfile))
            return;

        $time = strtotime($this->datetime_delete_oldfile);
        if(empty($time))
            return;

        // Récupérer la liste des fichiers logs correspondant du cron en cours
        $log_liste = @glob("{$this->folder}/{$this->name}_*.log");
        if(empty($log_liste))
            return false;

        $nb_delete = $nb_echec = 0;
        foreach($log_liste as $file)
        {
            $date_update = filemtime($file);
            if($date_update < $time)
            {
                if(@unlink($file))
                    $nb_delete++;
                else	$nb_echec++;
            }
        }

        // Rapport de clean
        if($nb_delete > 0 || $nb_echec > 0)
        {
            $msg = [];
            if($nb_delete > 0)	$msg[] = $nb_delete.' supprimes';
            if($nb_echec > 0)	$msg[] = $nb_echec.' tentatives de suppressions (droit insuffisant)';

            $batch->log("Suppression des vieux logs (filtre: $filtre): ". implode(', ', $msg));
        }
    }
}

class batch_observer_web_plain extends AbstractBatchObserver
{
    public function __construct()
    {
        if(!headers_sent())
        {
            @ob_end_flush();
            ob_implicit_flush(true);
            header('Content-Type: text/plain; charset=UTF-8');
        }
    }

    public function log($msg, $type = null)
    {
        echo batch_observer_logfile::msg_format($msg, $type)."\n";
        $this->flush_apache();
    }

    // Maintenir Apache réveillé
    protected function flush_apache()
    {
        flush();
        if(ob_get_level() > 0) ob_flush();
    }
}

class batch_observer_rapport_mail extends AbstractBatchObserver
{
    // Configuration mail en cas d'erreur
    private $callback_mail_function = null;
    protected $mail_admin = null;

    // Messages
    protected $logfile;
    protected $mode;

    // Modes disponibles
    const MODE_SENDMAIL_ENDSCRIPT = 'sendmail_endscript';
    const MODE_SENDMAIL_IF_ERROR = 'sendmail_if_error';
    const MODE_SENDMAIL_MANUAL = 'sendmail_manual';

    /**
     * Définir une fonction d'envoie de mail (conseillé pour les serveurs utilisant du smtp)
     * @param  string|array	$function	Nom de la fonction, ou array(class, method)
     * @param  array 		$args		Arguments de la fonction DANS l'ordre de la fonction. Array('hook_name' => 'value|null pour autocomplete'), cf keywords dans la fonction
     * @return bool
     */
    public function __construct($function, $args, $mail_admin, $mode = self::MODE_SENDMAIL_ENDSCRIPT)
    {
        if( empty($function) || (!is_array($function) && !function_exists($function)) )
            return !user_error("La fonction de callback mail déclaré '{$function}' n'existe pas.");

        elseif(empty($args))
            return !user_error("La fonction de callback mail déclaré '{$function}' n'a pas d'arguments.");


        // Les mots clés sont les arguments acceptés pour la fonction
        // 'keywords'	=> array('hook_name' => 'action')
        $this->callback_mail_function = ['function' => $function, 'args' => []];
        $this->mail_admin = $mail_admin;
        $keywords = [
            'to'			=>	'mail',
            'to_name'		=>	'name',
            'title'			=>	null,
            'content'		=>	null,
            'from'			=>	'mail',
            'from_name'		=>	'name',
            'reply'			=>	'mail',
            'reply_name'	=>	'name',
            'html'			=>	'bool',
            'smtp'			=>	'bool',
        ];

        // Renseigné les arguments de la fonction dans l'ordre, $args = array($hook1 => 'value', $hook2 => 'value'...)
        foreach($args as $hook => $value)
        {
            // Valeur null = autocompletion, sinon valeur imposé
            if(isset($keywords[$hook]))
                $this->callback_mail_function['args'][$hook] = (($value !== null) ? $value : $keywords[$hook]);
            else	$this->callback_mail_function['args'][$hook] = null;
        }

        $this->logfile = tmpfile();	// Création d'un fichier temporaire pour stocker les logs
        $this->mode = $mode;		// Sendmail mode: end script / if error / manual
    }

    public function log($msg, $type = null)
    {
        fwrite($this->logfile, batch_observer_logfile::msg_format($msg, $type)."\n");
    }

    public function sendmail()
    {
        if(empty($this->mail_admin))
            return false;

        $subject = 'Rapport du script > Log '. $this->name . ' du '. date('d/m/Y');

        // Contenu du rapport
        $msg = [];
        if(!empty($_SERVER['HTTP_HOST']) && !empty($_SERVER['REQUEST_URI']))
            $msg[] = "URL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
        else	$msg[] = "SCRIPT: {$_SERVER['PHP_SELF']}";

        $msg[] = 'DATE: '. date('d/m/Y H\hi');

        // Contenu du log
        $log_contents = file(stream_get_meta_data($this->logfile)['uri'], FILE_IGNORE_NEW_LINES);

        // Ajouter le contenu du log
        $msg[] = null; // imposer retour à la ligne
        $msg = implode("\n", array_merge($msg, $log_contents));

        // Utilisation fonction mail classique
        if(empty($this->callback_mail_function))
            return @mail($this->mail_admin, $subject, $msg);

        // Envoie du mail, utilisation d'une fonction de callback
        $args = []; // Préparation des arguments

        foreach($this->callback_mail_function['args'] as $hook => $value)
        {
            if($hook == 'title')
                $args[$hook] = trim($value.' '.$subject);

            elseif($hook == 'content')
                $args[$hook] = trim($value."\n\n".$msg);

            elseif(in_array($hook, array('html', 'smtp')))
                $args[$hook] = (($value !== 'bool') ? $value : false);

            elseif(in_array($hook, array('to', 'from', 'reply')) && $value == 'mail')
                $args[$hook] = $this->mail_admin;

            elseif(in_array($hook, array('to_name', 'from_name', 'reply_name')) && $value == 'name')
            {
                $args[$hook] = 'System';
                if(!empty($_SERVER['HTTP_HOST']))
                    $args[$hook] .= ' '.$_SERVER['HTTP_HOST'];
            }

            else $args[$hook] = $value;
        }
        // Convertir en html si nécessaire
        if(isset($args['html']) && $args['html'])
            $args['content'] = nl2br($args['content']);

        return call_user_func_array($this->callback_mail_function['function'], $args);
    }

    /**
     * Envoie d'un email à un administrateur
     * @return bool				Succès de l'envoie du mail
     */
    public function __epilogue(Batch $batch)
    {
        switch($this->mode)
        {
            // Forcer l'envoie du mail si des erreurs ont été détectés
            case static::MODE_SENDMAIL_IF_ERROR:
                if($batch->get_nb_error() <= 0)
                    return;
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

        if($this->sendmail())
        {
            if($batch->get_nb_error() > 0)
                $batch->log('Un administrateur a été averti par email', 'info');
            else $batch->log('Une copie de ces logs a été transmis à l\'administrateur');
        }
        else $batch->log('Echec lors de l\'envoie du email', 'warning');
    }
}

?>