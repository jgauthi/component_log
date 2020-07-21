<?php
use Jgauthi\Component\Log\Batch;
use Jgauthi\Component\Log\Observer\{BatchCli, BatchLogFile, BatchWebPlain};

define('SCRIPT_VERSION', 2.3);
define('PATH_LOG_DIR', sys_get_temp_dir()); // Declare folder for logs

// In this example, the vendor folder is located in "example/"
require_once __DIR__.'/vendor/autoload.php';

// Init & configuration batch
$batch = new Batch('batch_v3_component');
$batch->help(__DIR__ . '/batch_doc.txt');

// Déclaration des observateurs: Mode cli ou Web + Écriture dans un fichier de log
$batch
    ->attach(((PHP_SAPI === 'cli') ? new BatchCli : new BatchWebPlain))
    ->attach(new BatchLogFile(PATH_LOG_DIR));

$batch
    ->start(SCRIPT_VERSION)
    ->log('Lancement de la class du batch')
    ->varExport($batch);

echo $var_dont_exists;
//echo func_not_exists();

// Progressbar
$batch->log('Traitement en cours... (3s)');
for ($i = 0; $i < 92; ++$i) {
    $batch->progressbar();
    usleep(60000);
}
$batch->progressbar_reset();

// Récupération d'un argument (GET/POST pour le web, --varname=value pour cli)
$myvar = $batch->get_arg('file', 'no value');
$batch->log('Récupération de la variable file: ' . $myvar);
trigger_error('Custom error notice');
$batch->log('Fin du log');

$batch->end_script();