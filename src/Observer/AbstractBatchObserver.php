<?php
namespace Jgauthi\Component\Log\Observer;

use Jgauthi\Component\Log\Batch;

abstract class AbstractBatchObserver
{
    protected string $name;

    abstract public function log(string $msg, ?string $type = null): void;

    /**
     * Error messages (can be re-write by children).
     */
    public function error(string $msg): void
    {
        $this->log($msg, 'error');
    }

    /**
     * Nom du script en cours.
     */
    public function set_name(string $name): void
    {
        $this->name = $name;
    }

    /**
     * (Optionnel) Action executé automatiquement à la fin du script.
     */
    public function __epilogue(Batch $batch): void
    {
        // Aucune action par défaut
    }
}
