<?php
use Jgauthi\Component\Database\Db;

define('SCRIPT_VERSION', '1.0');
define('PATH_LOG_DIR', sys_get_temp_dir()); // Declare folder for logs

// In this example, the vendor folder is located in "example/"
require_once __DIR__.'/vendor/autoload.php';

// Init database
define('DB_SERVER', 'localhost');
define('DB_LOGIN', 'root');
define('DB_PASS', '');
define('DB_DATABASE', 'dbname');
define('DB_PORT', 3306);
$pdo = new db(DB_SERVER, DB_LOGIN, DB_PASS, DB_DATABASE, DB_PORT);

// Init & configuration batch
// [Before use] Install the table: src/batch_db.class.php:18
$batch = new batch_db($pdo, null, SCRIPT_VERSION);
$batch->help(__DIR__ . '/batch_doc.txt');
$batch->log_get_all_error();
$batch->code_ref = 'MYCODE01';
$batch->log('Lancement de la class du batch_db');
//echo $var_dont_exists;
//echo func_not_exists();

$batch->log('Traitement en cours... (3s)');
sleep(3);


// Clean old export + old log (traitement lancé une fois par mois)
$batch->delete_old_logfile();

$batch->end_script();
?>