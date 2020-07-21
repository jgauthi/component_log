<?php
/*****************************************************************************************************
 * @name BatchPdo
 * @note: Extension de la class de batch pour placer les logs en base de donnée
 * @author Jgauthi <github.com/jgauthi>, crée le 17juin2018
 * @version 2.2
 * @Requirements:
    - php version 7.4+ avec pdo_mysql, mysql v5.6+
    - Class batch >= v2
    - PDO

 ******************************************************************************************************/
namespace Jgauthi\Component\Log\Observer;

use Jgauthi\Component\Log\Batch;
use PDO;
use PDOException;

/*
DROP TABLE IF EXISTS `batch_logs`;
CREATE TABLE `batch_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `script` varchar(40) NOT NULL,
  `date` datetime NOT NULL,
  `messages` LONGTEXT NOT NULL,
  `nb_error` int(4) UNSIGNED NOT NULL DEFAULT '0',
  `code_ref` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `script` (`script`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COMMENT='Log de la class batch-v2-db' AUTO_INCREMENT=1 ;
*/

class BatchPdo extends AbstractBatchObserver
{
    public const TABLE = 'batch_logs';
    public const PK = 'id';

    private PDO $pdo;
    private string $id_session;
    protected ?string $code_ref = null;
    protected ?string $datetime_delete_oldfile = null;

    public function __construct(PDO &$pdo, string $datetime_delete_oldfile = 'first day of -2 month')
    {
        $this->pdo = $pdo;
        $this->datetime_delete_oldfile = $datetime_delete_oldfile; // Suppression des anciens logs
    }

    /**
     * Affiche / écrit dans un log: un message.
     *
     * @param string $msg
     * @param string $type null|success|warning|info
     */
    public function log(string $msg, ?string $type = null): void
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
                $msg = "{$msg} (warning)";
                break;
        }

        $this->query_msg($msg);
    }

    /**
     * @throws PDOException
     */
    protected function query_msg(string $message): void
    {
        if (empty($this->id_session)) {
            $param = [
                'script' => $this->name,
                'date' => date('Y-m-d H:i:s'),
                'messages' => "{$message}\n",
            ];
            $sql = 'INSERT INTO `'.static::TABLE.'` SET `script` = :script, `date` = :date, `messages` = :messages';

            $insert = $this->pdo->prepare($sql)->execute($param);
            if ($insert <= 0) {
                throw new PDOException('Error during insert log in database');
            }

            $this->id_session = $this->pdo->lastInsertId();

        } else {
            $param = [
                'id' => $this->get_id_session(),
                'messages' => "{$message}\n",
            ];
            $param_sql_set = '`messages` = CONCAT(messages, :messages)';

            // Ne l'enregister qu'une fois
            if (!empty($this->code_ref)) {
                $param['code_ref'] = $this->code_ref;
                $param_sql_set .= ', `code_ref` = :code_ref';
                unset($this->code_ref);
            }

            // Ne l'enregister qu'une fois
            if (!empty($this->nb_error)) {
                $param['nb_error'] = $this->nb_error;
                $param_sql_set .= ', `nb_error` = :nb_error';
                unset($this->nb_error);
            }

            $sql = 'UPDATE `'.static::TABLE."` SET {$param_sql_set} WHERE ".static::PK.' = :id';
            $this->pdo->prepare($sql)->execute($param);
        }
    }

    /**
     * Déterminer le code en cours + Suppression des logs liés au batch datant de X temps.
     */
    public function __epilogue(Batch $batch): void
    {
        // Déterminer le code associé au contenu actuel (si définis)
        $this->nb_error = $batch->get_nb_error();
        if (!empty($batch->code_ref)) {
            $this->code_ref = $batch->code_ref;
        }

        // Effacer les logs au-dela d'une certaine date
        if (empty($this->datetime_delete_oldfile)) {
            return;
        }

        $time = strtotime($this->datetime_delete_oldfile);
        if (empty($time)) {
            return;
        }

        // Ajout delete old lines in database
        $sql = 'DELETE FROM `'.static::TABLE.'` WHERE `date` < :date';
        $args = ['date' => date('Y-m-d', $time)];

        $this->pdo->prepare($sql)->execute($args);
    }

    /**
     * Return current ID Session.
     */
    public function get_id_session(): string
    {
        return $this->id_session;
    }
}
