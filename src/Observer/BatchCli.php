<?php
namespace Jgauthi\Component\Log\Observer;

class BatchCli extends AbstractBatchObserver
{
    private string $function;

    public function __construct()
    {
        // Print msg on differents canal
        if (!\defined('STDOUT') && !\defined('STDERR')) {
            \define('STDOUT', fopen('php://stdout', 'w'));
            \define('STDERR', fopen('php://stderr', 'w'));

            register_shutdown_function(function () { fclose(STDOUT); fclose(STDERR); });
        }

        // Support color theme
        $this->function = ($this->support_terminal_color() ? 'log_terminal_color' : 'log_no_filter');
    }

	// https://cweiske.de/tagebuch/php-auto-coloring-output.htm
	protected function support_terminal_color(): bool
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            return false !== getenv('ANSICON')
                || 'ON' === getenv('ConEmuANSI')
                || 'xterm' === getenv('TERM');
        }

        return \function_exists('posix_isatty') && @posix_isatty(STDOUT);
    }

    /**
     * Retourne un message avec une couleur de fond correspond au status.
     *
     * @param string $msg
     * @param string|null $type null|success|error|warning|info
     */
    protected function log_terminal_color(string $msg, ?string $type = null): string
    {
        switch ($type) {
            case 'success':	$out = '[42m'; break;	// Green background
            case 'error':	$out = '[41m'; break;	// Red background
            case 'warning':	$out = '[43m'; break;	// Yellow background
            case 'info':	$out = '[44m'; break;	// Blue background
            default:		return $msg; break;
        }

        return \chr(27).$out.$msg.\chr(27).'[0m';
    }

    protected function log_no_filter(string $msg, ?string $type = null): string
    {
        return $msg;
    }

	public function log(string $msg, ?string $type = null): void
    {
        fwrite(STDOUT, \call_user_func([$this, $this->function], $msg, $type)."\n"); // -v 1>>msg.log
    }

    public function error(string $msg): void
    {
        fwrite(STDERR, \call_user_func([$this, $this->function], $msg, 'error')."\n"); // -v 2>>error.log
    }
}
