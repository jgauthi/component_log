<?php
/*****************************************************************************************************
 * @name Batch (avec design pattern observer)
 * @note: Ensemble de class pour la gestion des batchs & log avec gestion des erreurs
 * @author Jgauthi <github.com/jgauthi>, crée le 2octobre2012
 * @version 3.2.4
 * @Requirements: PHP 7.4+

 ******************************************************************************************************/

namespace Jgauthi\Component\Log;

use InvalidArgumentException;
use Jgauthi\Component\Log\Observer\AbstractBatchObserver;
use Throwable;

class Batch
{
    // Design pattern Observer
    protected array $observers = [];
    private string $name;

    // Configuration Log
    protected int $nb_error = 0;
    protected int $progressbar = 0;

    // Config batch
    private bool $endscript = false;

    public function __construct(?string $name = null)
    {
        // Current script name
        $this->name = ((!empty($name)) ? $name : basename($_SERVER['PHP_SELF']));
        $this->name = (string) preg_replace('#[^a-z0-9._-]#i', '-', $this->name);
        $this->name = mb_substr($this->name, 0, 40);

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

    public function attach(AbstractBatchObserver $observer): self
    {
        $observer->set_name($this->name);
        $this->observers[] = $observer;

        return $this;
    }

    public function detach(AbstractBatchObserver $observer): self
    {
        if (is_int($key = array_search($observer, $this->observers, true))) {
            unset($this->observers[$key]);
        }

        return $this;
    }

    //-- Gestion des messages --

    public function start(?string $version = null): self
    {
        if (empty($this->observers)) {
            throw new InvalidArgumentException('No observer declared.');
        }
        // Information du log
        $info = ['posix pid ID: '.getmypid()];
        if (!empty($version)) {
            $info[] = 'script version: '.$version;
        }

        if (!empty($_SERVER['SCRIPT_FILENAME']) && @filemtime($_SERVER['SCRIPT_FILENAME'])) {
            $info[] = 'script datemaj: '.date("dMY H\hi", filemtime($_SERVER['SCRIPT_FILENAME']));
        }

        // Formater la colonne avec les informations et les sharps #
        $info = implode(', ', $info);
        $nb_charp = (80 - mb_strlen($info));
        if ($nb_charp < 1) {
            $nb_charp = 1;
        }

        // Lancer le timer
        if (empty($_SERVER['REQUEST_TIME_FLOAT'])) {
            $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        }

        // $this->config_print_function();
        $this->log('<##DEBUT DE SESSION ('.$info.') '.str_repeat('#', $nb_charp));

        // Préparer la destruction de l'objet
        register_shutdown_function([$this, '__epilogue']);

        return $this;
    }

    /**
     * Scripts à lancer à la fin du script (__destruct désactivé car il s'executait pas dans le cas où le script plantait).
     */
    public function __epilogue(): void
    {
        // Mettre fin au timer
        $duree = ceil((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000);

        // Vérifier si le client a abandonné la connexion
        if (connection_aborted()) {
            $this->log('Connexion HTTP interrompu avec l\'utilisateur ayant lancé le script.', 'info');
        }

        if ($this->nb_error > 1) {
            $this->log("Le comportement du script a peut être été altéré car {$this->nb_error} erreurs se sont produites durant son execution", 'warning');
        } elseif (1 === $this->nb_error) {
            $this->log('Le comportement du script a peut être été altéré car une erreur s\'est produite durant son execution', 'warning');
        }

        // Vérifier que le script s'est terminé convenablement
        if (false === $this->endscript) {
            $this->log('Le script a été interrompu lors de son execution !', 'warning');
        }

        // Lancer les fins de scripts des différents observer
        foreach ($this->observers as $observer) {
            $observer->__epilogue($this);
        }

        // Mémoire occupé
        $memory = memory_get_usage();
        $unit = ['o', 'ko', 'mo', 'go', 'to', 'po'];
        $memory = @round($memory / pow(1024, ($i = floor(log($memory, 1024)))), 2).' '.$unit[$i];

        $this->log("###FIN DE SESSION (duree {$duree} milliseconds, mémoire utilisé: {$memory}) ################################>");
    }

    /**
     * Récupération des arguments du script
     * Compatible REQUEST pour le mode web, cli args "--name" ou "--name=value" pour php-cli.
     *
     * @param string       $name
     * @param string|array $default_value
     *
     * @return string|array
     */
    public function get_arg(string $name, $default_value = null)
    {
        // Mode cli
        if (\PHP_SAPI === 'cli') {
            global $argv;

            $expreg = "#^--{$name}(=([^$]+))?$#i";
            $search = preg_grep($expreg, $argv);

            if (!empty($search)) {
                $result = reset($search);
                if (preg_match($expreg, $result, $row)) {
                    $value = ((!empty($row[2])) ? $row[2] : true);
                }
            }

            if (!isset($value)) {
                return $default_value;
            }
        }
        // Mode web
        else {
            if (!isset($_REQUEST[$name])) {
                return $default_value;
            }

            $value = $_REQUEST[$name];
            if ('' === $value) {
                $value = true;
            }
        }

        return $value;
    }

    /**
     * Retourne le nombre d'erreur détecté durant l'execution du script.
     */
    public function get_nb_error(): int
    {
        return $this->nb_error;
    }

    /**
     * Affiche / écrit dans un log: un message.
     *
     * @param string $msg
     * @param string $type null|success|warning|info
     *
     * @return self
     */
    public function log(string $msg, string $type = null): self
    {
        foreach ($this->observers as $observer) {
            $observer->log($msg, $type);
        }

        return $this;
    }

    /**
     * Affiche / écrit dans un log: une erreur.
     */
    public function error(string $msg): self
    {
        ++$this->nb_error;

        foreach ($this->observers as $observer) {
            $observer->error($msg);
        }

        return $this;
    }

    /**
     * Affiche une documentation de la cron, commande à placer en début de script.
     *
     * @param string $file Format txt (pour le moment, il faudra ajouter d'autres formats)
     * @return void
     */
    public function help(string $file): void
    {
        // Afficher l'aide si l'argument help est demandé
        $display_help = $this->get_arg('help');
        if (empty($display_help)) {
            return;
        } elseif (!is_readable($file)) {
            $this->log("Le fichier d'aide \"{$file}\" n'est pas lisible ou n'existe pas.");
        }

        // Affichage de l'aide et fin du script
        header('Content-Type: text/plain; charset=UTF-8');
        readfile($file);

        $this->end_script();

        die();
    }

    /**
     * Ajoute les erreurs PHP dans les logs du batch.
     */
    public function error_handler_notice(int $errno, string $errstr, string $errfile, string $errline): void
    {
        $this->error("PHP Error: {$errstr} in file '{$errfile}:{$errline}'");
    }

    /**
     * Ajouter les exceptions non cath dans les logs du batch.
     */
    public function exception_handler_error(Throwable $e): void
    {
        $this->error("Exception no catch error: {$e->getMessage()} in file '{$e->getFile()}:{$e->getLine()}");
    }

    /**
     * A executer à la fin du script, permet de vérifier qu'il n'a pas été interrompu en cours de route.
     */
    public function end_script(): void
    {
        $this->endscript = true;
    }

    /**
     * Récupération des erreurs PHP dans les logs.
     */
    public function varExport(): self
    {
        $args = \func_get_args();
        $debug_var = [];

        foreach ($args as $var) {
            if (null === $var) {
                $var = '# NULL #';
            } elseif (false === $var) {
                $var = '# false #';
            } elseif (true === $var) {
                $var = '# true #';
            } elseif ('' === $var) {
                $var = '# empty #';
            } elseif (\is_string($var)) {
                $var = stripslashes(mb_substr(var_export(wordwrap($var, 160), true), 1, -1));
            } elseif (is_numeric($var)) {
                $var = var_export(wordwrap($var, 160), true);
            } else {
                $var = var_export($var, true);
            }

            $debug_var[] = $var;
        }
        $this->log('Debug var: '.implode(PHP_EOL, $debug_var), 'info');

        return $this;
    }

    /**
     * Affichage d'une barre de progression.
     */
    public function progressbar(): self
    {
        echo '*';

        if ($this->progressbar >= 30) {
            $this->progressbar_reset();
        } else {
            ++$this->progressbar;
        }

        return $this;
    }

    /**
     * Ré-initialise le compteur de la barre de progression.
     */
    public function progressbar_reset(): self
    {
        $this->progressbar = 0;
        echo PHP_EOL;

        return $this;
    }
}
