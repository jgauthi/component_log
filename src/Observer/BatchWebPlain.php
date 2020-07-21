<?php
namespace Jgauthi\Component\Log\Observer;

class BatchWebPlain extends AbstractBatchObserver
{
    public function __construct()
    {
        if (!headers_sent()) {
            @ob_end_flush();
            ob_implicit_flush(true);
            header('Content-Type: text/plain; charset=UTF-8');
        }
    }

	public function log(string $msg, ?string $type = null): void
    {
        echo BatchLogFile::msg_format($msg, $type)."\n";
        $this->flush_apache();
    }

    // Maintenir Apache réveillé
    protected function flush_apache(): void
    {
        flush();
        if (ob_get_level() > 0) {
            ob_flush();
        }
    }
}
