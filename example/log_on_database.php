<?php
use Jgauthi\Component\Log\Batch;
use Jgauthi\Component\Log\Observer\BatchPdo;

define('SCRIPT_VERSION', 1.2);

// In this example, the vendor folder is located in "example/"
require_once __DIR__.'/vendor/autoload.php';

// Init database
define('DB_SERVER', 'localhost');
define('DB_LOGIN', 'root');
define('DB_PASS', '');
define('DB_DATABASE', 'dbname');
define('DB_PORT', 3306);

$pdo = new PDO('mysql:dbname='.DB_DATABASE.';host='. DB_SERVER .';port='.DB_PORT, DB_LOGIN, DB_PASS, [
    PDO::ATTR_DEFAULT_FETCH_MODE    => PDO::FETCH_ASSOC,
    PDO::ATTR_ERRMODE               => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_INIT_COMMAND    => 'SET NAMES utf8 COLLATE utf8_unicode_ci',
]);


// Init & configuration batch
$batch = new Batch('batch_v2_database');
$batchPdo = new BatchPdo($pdo);

try {
    $batchPdo->install(); // Install the table `batch_logs`
} catch (Exception $exception) {
    die($exception->getMessage());
}

$batch
    ->attach($batchPdo)
    ->start(SCRIPT_VERSION)
    ->log('Lancement de la class observateur BatchPdo')
;

// Variable pour définir le "produit" ou "dossier" en cours, pour l'observer PDO
$batch->code_ref = 'MYCODE01';

echo $var_dont_exists;

$batch->log('Traitement en cours... (3s)');
sleep(3);

$batch->end_script();