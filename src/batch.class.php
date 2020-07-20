<?php
/*****************************************************************************************************
 * @name Batch
 * @note: Gestion des batchs & log avec gestion des erreurs
 * @author Jgauthi <github.com/jgauthi>, crée le 2octobre2012
 * @version 1.7.9
 * @Requirements:
    - Dossier de log (variable: PATH_LOG_DIR)

 ******************************************************************************************************/

// Lancer directement l'envoi d'information au navigateur sans l'init de la class
if(!headers_sent())
{
    @ob_end_flush(); // A tester
    ob_implicit_flush(true);
}

class batch
{
    // Configuration Log & Timer
    protected $mode_execution = 'apache/text';
    public $logfile;
    public $dir_logfile;
    public $log_echo = true;
    protected $log_error = false;
    public $nb_error = 0;

    // Configuration mail en cas d'erreur
    private $callback_mail_function = null;
    public $mail_admin = null;

    // Config batch
    public $debug = false;
    private $endscript = false;
    private $callback_echo_function = array();

    /**
     * Constructeur
     * @param string	$logfile	(Optionnel)
     * @param string	$version	(Optionnel)
     */
    public function __construct($logfile = null, $version = null)
    {
        if(PHP_SAPI === 'cli')
        {
            global $argv;

            $this->mode_execution = 'php-cli';
            if( !empty($argv) && (in_array('-v', $argv) || in_array('--verbose', $argv)) )
                $this->log_echo = true;
            else 	$this->log_echo = false;
        }
        elseif(!headers_sent())
        {
            // Charset du script
            if(defined('CHARSET'))
                $charset = ((CHARSET == 'utf-8') ? 'UTF-8' : 'ISO-8859-1');
            else	$charset = 'UTF-8';

            if(isset($_GET['echo']))
                $this->log_echo = (bool)$_GET['echo'];

            if($this->log_echo)
                header("Content-Type: text/plain; charset=$charset");
        }

        set_time_limit(0);
        ini_set('max_execution_time', 0);
        ini_set('max_input_time', 3600);
        ini_set('memory_limit', '516M');

        // Récupérer les erreurs détectés par les autres classes
        set_error_handler(array($this, 'error_handler_notice'), E_USER_NOTICE);

        // Information du log
        $info = array('posix pid ID: '. getmypid());
        if(!is_null($version))
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

        if(!empty($logfile))
        {
            // Déterminer l'emplacement du dossier LOG (par défaut à la racine)
            if(defined('PATH_LOG_DIR') && is_writable(PATH_LOG_DIR))
                $this->dir_logfile = PATH_LOG_DIR;
            elseif(!empty($_SERVER['DOCUMENT_ROOT']) && is_writable($_SERVER['DOCUMENT_ROOT'].'/log'))
                $this->dir_logfile = $_SERVER['DOCUMENT_ROOT'].'/log';
            else return die(user_error('Aucun dossier de log accessible'));

            $this->logfile = str_replace('\\', '/', "{$this->dir_logfile}/{$logfile}_" .date('Ymd'). '.log');
            $this->logfile_format = $logfile;
        }

        $this->config_print_function();
        $this->log('<##DEBUT DE SESSION ('.$info.') '. str_repeat('#', $nb_charp) );

        // Préparer la destruction de l'objet
        register_shutdown_function(array($this,'__epilogue'));
    }

    /**
     * Scripts à lancer à la fin du script (__destruct désactivé car il s'executait pas dans le cas où le script plantait)
     * @return void
     */
    public function __epilogue()
    {
        // Mettre fin au timer
        $duree = ceil((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000);

        if($this->log_error)
            $this->endscript = false;

        // Vérifier si le client a abandonné la connexion
        if(connection_aborted())
            $this->log('Connexion HTTP interrompu avec l\'utilisateur ayant lancé le script.', 'info');

        // Vérifier que le script s'est terminé convenablement
        if($this->endscript === false)
        {
            $this->log('Le script a été interrompu lors de son execution !', 'warning');

            // Contacter l'administrateur
            if($this->mail_admin()) // $rapport_mail
                $this->log('Un administrateur a été averti par email', 'info');
            else $this->log('Merci de contacter un administrateur', 'info');
        }

        $this->log("###FIN DE SESSION (duree {$duree} milliseconds) #############################################################>");
        if($this->endscript && $this->log_echo) echo $this->endscript; // Tag pour le script executant le batch
    }


    /**
     * Créer à la volée, la fonction d'écriture + envoie de log selon la configuration du batch
     * @return void
     */
    private function config_print_function()
    {
        $print_msg = $print_error = '';

        if(!empty($this->logfile))
            $print_msg = $print_error = 'error_log(date("Y-m-d H:i:s") ."\t{$msg}\n", 3, "'.$this->logfile.'");'."\n\n";

        // Impression des messages selon l'environnement
        if($this->log_echo)
        {
            // Mode cli
            if($this->mode_execution == 'php-cli')
            {
                // Print msg on differents canal
                if(!defined('STDOUT') && !defined('STDERR'))
                {
                    define('STDOUT', fopen('php://stdout', 'w'));
                    define('STDERR', fopen('php://stderr', 'w'));

                    register_shutdown_function(function() { fclose(STDOUT); fclose(STDERR); });
                }

                // Add color theme
                if(!empty($_SERVER['COLORTERM']))
                {
                    $print_msg .= '$msg = batch::log_terminal_color($msg, $type);'."\n";
                    $print_error .= '$msg = batch::log_terminal_color($msg, $type);'."\n";
                }

                // Possibilités d'exporter en 2 canneaux de messages (cumulable)
                // ex: php test_batch.php -v 1>>/dev/null 2>>error.log
                $print_msg .= 'fwrite(STDOUT, $msg."\n");'."\n";	// -v 1>>msg.log
                $print_error .= 'fwrite(STDERR, $msg."\n");'."\n";	// -v 2>>error.log
            }
            // Apache, todo: Gérer le HTML?
            else
            {
                $print = 'echo $msg."\n";'."\n";

                // Maintenir Apache réveillé
                $print .= 'flush();
				if(ob_get_level() > 0) ob_flush();'."\n\n";

                $print_msg .= $print;
                $print_error .= $print;
            }
        }


        // Définir les fonctions de callback
        // $this->callback_echo_function['msg'] = create_function('$msg, $type', $print_msg);
        // $this->callback_echo_function['error'] = create_function('$msg, $type', $print_error);
        $this->callback_echo_function['msg'] = function($msg, $type) use (&$print_msg) { eval($print_msg); };
        $this->callback_echo_function['error'] = function($msg, $type) use (&$print_error) { eval($print_error); };
        // alternative à examiner: utilisation de hook comme wordpress
    }

    /**
     * Retourne un message avec une couleur de fond correspond au status
     * @param  string $msg
     * @param  string $type 	null|success|error|warning|info
     * @return string
     */
    static public function log_terminal_color($msg, $type)
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


    /**
     * Affiche / écrit dans un log: un message
     * @param  string	$msg
     * @param  string	$type	null|success|warning|info
     * @return void
     */
    public function log($msg, $type = null)
    {
        if($type == 'warning')
            $msg = '/!\ Warning: '.$msg;

        call_user_func($this->callback_echo_function['msg'], $msg, $type);
    }

    /**
     * Affiche / écrit dans un log: une erreur
     * @param  string	$msg
     * @return void
     */
    public function error($msg)
    {
        $this->nb_error++;
        call_user_func($this->callback_echo_function['error'], '/!\ '.$msg, 'error');
    }


    /**
     * Affiche une documentation de la cron, commande à placer en début de script
     * @param  file	$file	Format txt (pour le moment, il faudra ajouter d'autres formats)
     * @return die
     */
    public function help($file)
    {
        global $argv;

        if(preg_match('#apache/([^$]+)#i', $this->mode_execution, $row))
            $display_help = ((isset($_GET['help'])) ? $row : null);

        elseif( !empty($argv) && (in_array('-h', $argv) || in_array('--help', $argv)) )
            $display_help = 'cli';

        // Afficher l'aide si l'argument help est demandé
        if(empty($display_help))
            return;


        if(!is_readable($file))
            $this->log("Le fichier d'aide \"{$file}\" n'est pas lisible ou n'existe pas.");

        $help = file_get_contents($file);
        if($display_help == 'html')
            $help = nl2br(htmlentities($help));

        // Affichage de l'aide et fin du script
        echo $help."\n";
        $this->mail_admin = null;
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
        $this->log_error = true;
        $this->error("PHP Error: {$errstr} in file '{$errfile}:{$errline}'");
    }

    /**
     * A executer à la fin du script, permet de vérifier qu'il n'a pas été interrompu en cours de route
     * @param  string $mesg_fin        Message à afficher à l'écran à la fin du batch
     * @param  array  $reload_with_arg (non supporté) Permet de relancer la cron avec arguments GET (html+js seulement)
     * @return void
     */
    public function end_script($mesg_fin = null, $reload_with_arg = array())
    {
        $this->endscript = $mesg_fin;
    }

    /**
     * Affichage des erreurs PHP
     * @param  bool $afficher
     * @return void
     */
    public function debug($afficher)
    {
        $this->debug = $afficher;

        if($afficher)
        {
            ini_set('display_errors','On');
            error_reporting(E_ALL); // E_ALL | E_STRICT
        }
        else
        {
            ini_set('display_errors','Off');
            error_reporting(0);
        }
    }

    /**
     * Récupération des erreurs PHP dans les logs
     * @return void
     */
    public function log_get_all_error()
    {
        $this->debug(true);
        set_error_handler(array($this, 'error_handler_notice'), E_ALL ^ E_STRICT);
    }

    /**
     * Récupération des erreurs PHP dans les logs
     * @return void
     */
    public function varExport()
    {
        $args = func_get_args();
        $debug_var = array();

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
        $this->log('/!\ Debug var: '.implode("\n", $debug_var), 'info');
    }



    /**
     * Envoie d'un email à un administrateur
     * @param  string	$txt	Ajout d'un message supplémentaire au mail
     * @return bool				Succès de l'envoie du mail
     */
    public function mail_admin($txt = null)
    {
        if(empty($this->mail_admin))
            return false;

        $subject = 'Rapport> Log '. basename($_SERVER['PHP_SELF']) . ' du '. date('d/m/Y');

        // Contenu du rapport
        $mesg = array();
        if(!empty($_SERVER['HTTP_HOST']) && !empty($_SERVER['REQUEST_URI']))
            $mesg[] = "URL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
        else	$mesg[] = "SCRIPT: {$_SERVER['PHP_SELF']}";

        $mesg[] = 'DATE: '. date('d/m/Y H\hi');
        if(!empty($txt))
            $mesg[] = "MESSAGE: $txt";

        // Ajouter le contenu du log
        if(!empty($this->logfile) && is_readable($this->logfile))
        {
            $mesg[] = null; //imposer retour à la ligne
            $mesg[] = "LOGFILE: {$this->logfile}";
            $mesg[] = trim(file_get_contents($this->logfile));
        }
        $mesg = implode("\n", $mesg);


        // Envoie du mail, utilisation d'une fonction de callback
        if(!empty($this->callback_mail_function))
        {
            // Préparation des arguments
            $args = array();

            foreach($this->callback_mail_function['args'] as $hook => $value)
            {
                if($hook == 'title')
                    $args[$hook] = trim($value.' '.$subject);

                elseif($hook == 'content')
                    $args[$hook] = trim($value."\n\n".$mesg);

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

            return (bool) call_user_func_array($this->callback_mail_function['function'], $args);
        }
        // Utilisation fonction mail classique
        else return @mail($this->mail_admin, $subject, $mesg);
    }

    /**
     * Définir une fonction d'envoie de mail (conseillé pour les serveurs utilisant du smtp)
     * @param  string|array	$function	Nom de la fonction, ou array(class, method)
     * @param  array 		$args		Arguments de la fonction DANS l'ordre de la fonction. Array('hook_name' => 'value|null pour autocomplete'), cf keywords dans la fonction
     * @return bool
     */
    public function mail_set_callback_function($function, $args)
    {
        if( empty($function) || (!is_array($function) && !function_exists($function)) )
            return !user_error("La fonction de callback mail déclaré '$function' n'existe pas.");

        elseif(empty($args))
            return !user_error("La fonction de callback mail déclaré '$function' n'a pas d'arguments.");


        // Les mots clés sont les arguments acceptés pour la fonction
        // 'keywords'	=> array('hook_name' => 'action')
        $this->callback_mail_function = array('function' => $function, 'args' => array());
        $keywords = array
        (
            'to'			=>	'mail',
            'to_name'		=>	'name',
            'title'			=>	null,
            'content'		=>	null,
            'from'			=>	'mail',
            'from_name'		=>	'name',
            'reply'			=>	'mail',
            'reply_name'	=>	'name',
            'html'			=>	'bool',
            'smtp'			=>	'bool'
        );

        // Renseigné les arguments de la fonction dans l'ordre, $args = array($hook1 => 'value', $hook2 => 'value'...)
        foreach($args as $hook => $value)
        {
            // Valeur null = autocompletion, sinon valeur imposé
            if(isset($keywords[$hook]))
                $this->callback_mail_function['args'][$hook] = (($value !== null) ? $value : $keywords[$hook]);
            else	$this->callback_mail_function['args'][$hook] = null;
        }

        return true;
    }


    /**
     * Check directory before use it on cron
     * @param  string  $dir
     * @param  boolean $writable
     * @return (bool)
     */
    public function check_directory($dir, $readable = true, $writable = true)
    {
        if(empty($dir))							return !user_error('Empty directory.');
        elseif(!is_dir($dir))					return !user_error(sprintf('The folder "%s" does not exists.', $dir));
        elseif(!is_readable($dir) && $readable)	return !user_error(sprintf('The folder "%s" does not have read permissions.', $dir));
        elseif(!is_writable($dir) && $writable)	return !user_error(sprintf('The folder "%s" does not have write permissions.', $dir));

        return true;
    }

    /**
     * Suppression des logs liés au batch datant de X temps
     * @param  string	$filtre	Filtre date interprété par strtotime (défaut: first day of -2 month)
     * @return (void|null)
     */
    public function delete_old_logfile($filtre = 'first day of -2 month')
    {
        $time = strtotime($filtre);
        if(empty($time) || !function_exists('glob') || empty($this->logfile_format))
            return false;

        // Récupérer la liste des fichiers logs correspondant du cron en cours
        $log_liste = @glob("{$this->dir_logfile}/{$this->logfile_format}_*.log");
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
            $msg = array();
            if($nb_delete > 0)	$msg[] = $nb_delete.' supprimes';
            if($nb_echec > 0)	$msg[] = $nb_echec.' tentatives de suppressions (droit insuffisant)';

            $this->log("Suppression des vieux logs (filtre: $filtre): ". implode(', ', $msg));
            return true;
        }
        else return null;
    }

}

?>