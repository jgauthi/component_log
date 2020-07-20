<?php
/*******************************************************************************
 * @name Batch
 * @note: Gestion des batchs & log avec gestion des erreurs
 * @author Jgauthi <github.com/jgauthi>
 * @version 1.5.31
 * @Requirements:
    - Dossier de log (variable: PATH_LOG_DIR)

 *******************************************************************************/

// Lancer directement l'envoi d'information au navigateur sans l'init de la class
if (!headers_sent()) ob_implicit_flush(true);

class batch
{
    // Log & Timer
    public $logfile;
    public $dir_logfile;
    public $log_echo = true;
    protected $log_error = false;
    public $mail_admin = null;

    public $debug = false;
    private $timer;
    private $endscript = false;

    // Constructeur
    public function __construct($logfile, $version = null)
    {
        if (!headers_sent())
        {
            // Charset du script
            if (defined('CHARSET'))
                $charset = ((CHARSET == 'utf-8') ? 'UTF-8' : 'ISO-8859-1');
            else $charset = 'ISO-8859-1';

            header("Content-Type: text/plain; charset=$charset");
        }

        set_time_limit(0);
        ini_set('max_execution_time', 0);
        ini_set('max_input_time', 3600);
        ini_set('memory_limit', '516M');


        // Récupérer les erreurs détectés par les autres classes
        set_error_handler(array($this, 'error_handler_notice'), E_USER_NOTICE);

        // Déterminer l'emplacement du dossier LOG (par défaut à la racine)
        if (defined('PATH_LOG_DIR'))
            $this->dir_logfile = PATH_LOG_DIR;
        else $this->dir_logfile = $_SERVER['DOCUMENT_ROOT'].'/log';

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
        $this->logfile = "{$this->dir_logfile}/{$logfile}_" .date('Ymd'). '.log';
        $this->log('<##DEBUT DE SESSION ('.$info.') '. str_repeat('#', $nb_charp) );

        // Préparer la destruction de l'objet
        register_shutdown_function(array($this,'__epilogue'));
    }

    // Scripts à lancer à la fin du script (__destruct désactivé car il s'executait pas dans le cas où le script plantait)
    public function __epilogue()
    {
        // Mettre fin au timer
        $duree = ceil((microtime(true) - $this->timer) * 1000);

        // Erreur php détectés ? [DESACTIVER, la portée des erreurs se faient bien au-dela des scripts en cours]
        $rapport_mail = null;
        $error_php = error_get_last();
        if (isset($error_php['message'], $error_php['file'], $error_php['line']) && in_array($error_php['file'], get_included_files()))
        {
            $this->endscript = false;
            $rapport_mail = "{$error_php['message']} in file {$error_php['file']}, line {$error_php['line']}";

            if ($this->debug)
                user_error($rapport_mail);
        }
        // Des erreurs mineurs ont été détectés (notice, warning...)
        elseif($this->log_error)
            $this->endscript = false;


        // Vérifier que le script s'est terminé convenablement
        if (!$this->endscript)
        {
            $this->log('/!\ Warning: Le script a été interrompu lors de son execution !');

            // Contacter l'administrateur
            if ($this->mail_admin($rapport_mail))
                $this->log('Un administrateur a été avertie par email');
            else $this->log('Merci de contacter un administrateur');
        }

        $this->log("###FIN DE SESSION (duree {$duree} milliseconds) #############################################################>");
        if ($this->endscript) echo $this->endscript; // Tag pour le script executant le batch
    }

    public function log($mesg)
    {
        if (!empty($this->logfile))
            error_log(date("Y-m-d H:i:s") ."\t{$mesg}\n", 3, $this->logfile);

        if ($this->log_echo)
            echo $mesg . "\n";
    }

    public function error_handler_notice($errno, $errstr, $errfile, $errline)
    {
        $this->log_error = true;
        $this->log("/!\ Erreur: $errstr in file '$errfile:$errline'");
    }

    // A executer à la fin du script, permet de vérifier qu'il n'a pas été interrompu en cours de route
    public function end_script($mesg_fin = 'Good bye!')
    {
        $this->endscript = $mesg_fin;
    }

    public function debug($afficher)
    {
        $this->debug = $afficher;

        if ($afficher)
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

    public function log_get_all_error()
    {
        $this->debug(1);
        set_error_handler(array($this, 'error_handler_notice'), E_ALL ^ E_STRICT);
    }


    public function mail_admin($txt = null)
    {
        if (!empty($this->mail_admin))
        {
            $subject = 'Rapport> Log '. basename($_SERVER['PHP_SELF']) . ' du '. date('d/m/Y');

            $mesg =  "URL: http://{$_SERVER['HTTP_HOST']}{$_SERVER["REQUEST_URI"]}\n";
            $mesg .= 'DATE: '. date('d/m/Y H\hi') ."\n";
            $mesg .= ((!empty($txt)) ? "MESSAGE: $txt \n" : '');
            $mesg .= "\n";

            // Ajouter le contenu du log
            if (!empty($this->logfile) && file_exists($this->logfile))
            {
                $mesg .= 'LOGFILE: '. $this->logfile . "\n";
                $mesg .= trim(file_get_contents($this->logfile));
                $mesg .= "\n\n";
            }

            // Envoie du mail
            return @mail($this->mail_admin, $subject, $mesg);
        }

        return false;
    }
}


?>