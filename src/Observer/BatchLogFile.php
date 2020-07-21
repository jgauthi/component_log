<?php
namespace Jgauthi\Component\Log\Observer;

use InvalidArgumentException;
use Jgauthi\Component\Log\Batch;

class BatchLogFile extends AbstractBatchObserver
{
    private string $folder;
    protected string $logfile;
    protected string $datetime_delete_oldfile;

    public function __construct(string $folder, string $datetime_delete_oldfile = 'first day of -2 month')
    {
        if (empty($folder)) {
            throw new InvalidArgumentException('Folder empty in class: '.__CLASS__);
        } elseif (!is_writable($folder)) {
            throw new InvalidArgumentException(sprintf('Folder "%s" not writeable in class: %s', $folder, __CLASS__));
        }
        $this->folder = $folder;
        $this->datetime_delete_oldfile = $datetime_delete_oldfile;
    }

    public function set_name(string $name): void
    {
        parent::set_name($name);
        $this->logfile = str_replace('\\', '/', "{$this->folder}/{$this->name}_".date('Ymd').'.log');
    }

    public function get_filename(): string
    {
        return $this->logfile;
    }

    public static function msg_format(string $msg, ?string $type = null): string
    {
        switch ($type) {
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

    public function log(string $msg, ?string $type = null): void
    {
        $msg = self::msg_format($msg, $type);
        error_log(date('Y-m-d H:i:s')."\t{$msg}\n", 3, $this->logfile);
    }

    /**
     * Suppression des logs liés au batch datant de X temps.
     */
    public function __epilogue(Batch $batch): void
    {
        if (!\function_exists('glob') || empty($this->datetime_delete_oldfile)) {
            return;
        }

        $time = strtotime($this->datetime_delete_oldfile);
        if (empty($time)) {
            return;
        }

        // Récupérer la liste des fichiers logs correspondant du cron en cours
        $log_liste = @glob("{$this->folder}/{$this->name}_*.log");
        if (empty($log_liste)) {
            return;
        }

        $nb_delete = $nb_echec = 0;
        foreach ($log_liste as $file) {
            $date_update = filemtime($file);
            if ($date_update < $time) {
                if (@unlink($file)) {
                    ++$nb_delete;
                } else {
                    ++$nb_echec;
                }
            }
        }

        // Rapport de clean
        if ($nb_delete > 0 || $nb_echec > 0) {
            $msg = [];
            if ($nb_delete > 0) {
                $msg[] = $nb_delete.' supprimes';
            }
            if ($nb_echec > 0) {
                $msg[] = $nb_echec.' tentatives de suppressions (droit insuffisant)';
            }

            $batch->log("Suppression des vieux logs (filtre: $this->datetime_delete_oldfile): ".implode(', ', $msg));
        }
    }
}
