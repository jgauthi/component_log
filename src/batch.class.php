<?php
/*****************************************************************************************************
 * @name Batch
 * @note: Gestion des batchs & log avec gestion des erreurs
 * @author Jgauthi <github.com/jgauthi>
 * @version 1.6.23
 * @Requirements:
    - Dossier de log (variable: PATH_LOG_DIR)

 ******************************************************************************************************/

// Lancer directement l'envoi d'information au navigateur sans l'init de la class
if(!headers_sent()) ob_implicit_flush(true);

class batch
{
    // Configuration Log & Timer
    protected $mode_execution = 'apache/text';
    public $logfile;
    public $dir_logfile;
    public $log_echo = true;
    protected $log_error = false;
    public $mail_admin = null;

    public $debug = false;
    private $timer;
    private $endscript = false;

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

            $this->mode_execution = 'php-cli'; //@todo: Adapter le batch au mode shell
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

            header("Content-Type: text/plain; charset=$charset");
            if(isset($_GET['echo']))
                $this->log_echo = (bool)$_GET['echo'];
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
        $this->timer = microtime(true);
        if(!empty($logfile))
        {
            // Déterminer l'emplacement du dossier LOG (par défaut à la racine)
            if(defined('PATH_LOG_DIR') && is_writable(PATH_LOG_DIR))
                $this->dir_logfile = PATH_LOG_DIR;
            elseif(!empty($_SERVER['DOCUMENT_ROOT']) && is_writable($_SERVER['DOCUMENT_ROOT'].'/log'))
                $this->dir_logfile = $_SERVER['DOCUMENT_ROOT'].'/log';
            else return die(user_error('Aucun dossier de log accessible'));

            $this->logfile = "{$this->dir_logfile}/{$logfile}_" .date('Ymd'). '.log';
            $this->logfile_format = $logfile;
        }

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
        $duree = ceil((microtime(true) - $this->timer) * 1000);

        if($this->log_error)
            $this->endscript = false;


        // Vérifier que le script s'est terminé convenablement
        if($this->endscript === false)
        {
            $this->log('/!\ Warning: Le script a été interrompu lors de son execution !');

            // Contacter l'administrateur
            if($this->mail_admin()) // $rapport_mail
                $this->log('Un administrateur a été averti par email');
            else $this->log('Merci de contacter un administrateur');
        }

        $this->log("###FIN DE SESSION (duree {$duree} milliseconds) #############################################################>");
        if($this->endscript && $this->log_echo) echo $this->endscript; // Tag pour le script executant le batch
    }

    /**
     * Affiche / écrit dans un log: un message
     * @param  string	$mesg
     * @return void
     */
    public function log($mesg)
    {
        if(!empty($this->logfile))
            error_log(date("Y-m-d H:i:s") ."\t{$mesg}\n", 3, $this->logfile);

        if($this->log_echo)
        {
            echo $mesg . "\n";

            // Maintenir Apache réveillé
            if(preg_match('#apache#i', $this->mode_execution))
            {
                flush();
                if(ob_get_level() > 0) ob_flush();
            }
        }

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
        $this->log("/!\ PHP Error: {$errstr} in file '{$errfile}:{$errline}'");
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
        $this->debug(1);
        set_error_handler(array($this, 'error_handler_notice'), E_ALL ^ E_STRICT);
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

        $mesg =  "URL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}\n";
        $mesg .= 'DATE: '. date('d/m/Y H\hi') ."\n";
        $mesg .= ((!empty($txt)) ? "MESSAGE: $txt \n" : '');
        $mesg .= "\n";

        // Ajouter le contenu du log
        if(!empty($this->logfile) && is_readable($this->logfile))
        {
            $mesg .= "LOGFILE: {$this->logfile}\n";
            $mesg .= trim(file_get_contents($this->logfile));
            $mesg .= "\n\n";
        }

        // Envoie du mail
        return @mail($this->mail_admin, $subject, $mesg);
    }

    /**
     * Suppression des logs liés au batch datant de X temps
     * @param  string	$filtre	Filtre date interprété par strtotime (défaut: first day of -2 month)
     * @return (void|null)
     */
    public function delete_old_logfile($filtre = 'first day of -2 month')
    {
        $time = strtotime($filtre);
        if(empty($time) || !function_exists('glob'))
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