<?php
/*****************************************************************************************************
  * @name Batch DB
  * @note: Extension de la class de batch pour placer les logs en base de donnée
  * @author Jgauthi <github.com/jgauthi>, crée le 17juin2018
  * @version 2.1
  * @Requirements:
    - php version 5.5+ avec pdo_mysql, mysql v5.6
    - Class batch >= v2
    - Class Indieteq pdo: https://github.com/jgauthi/indieteq-php-my-sql-pdo-database-class

 ******************************************************************************************************/

use Jgauthi\Component\Database\Db;

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

class batch_observer_pdo_db extends AbstractBatchObserver
{
    const TABLE = 'batch_logs';
    const PK = 'id';

    private $pdo;
    private $id_session;
    protected $code_ref = null;
    protected $datetime_delete_oldfile = null;

    /**
     * Constructeur
     * @param Db $pdo
     * @param string $datetime_delete_oldfile
     */
    public function __construct(Db &$pdo, $datetime_delete_oldfile = 'first day of -2 month')
    {
        // Ajout support DB
        $this->pdo = clone $pdo;

        $this->datetime_delete_oldfile = $datetime_delete_oldfile; // Suppression des anciens logs
    }

    /**
     * Affiche / écrit dans un log: un message
     * @param  string	$msg
     * @param  string	$type	null|success|warning|info
     * @return void
     */
    public function log($msg, $type = null)
    {
        switch($type)
        {
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

    protected function query_msg($message)
    {
        if(empty($this->id_session))
        {
            $param = array
            (
                'script' 	=> $this->name,
                'date'		=> date('Y-m-d H:i:s'),
                'messages'	=> "{$message}\n",
            );
            $sql = 'INSERT INTO `'. static::TABLE .'` SET `script` = :script, `date` = :date, `messages` = :messages';

            $insert = $this->pdo->Query($sql, $param);
            if($insert > 0)
                $this->id_session = $this->pdo->lastInsertId();
            else throw new PDOException('Error during insert log in database');
        }
        else
        {
            $param = array
            (
                'id'		=> $this->get_id_session(),
                'messages'	=> "{$message}\n",
            );
            $param_sql_set = "`messages` = CONCAT(messages, :messages)";

            // Ne l'enregister qu'une fois
            if(!empty($this->code_ref))
            {
                $param['code_ref'] = $this->code_ref;
                $param_sql_set .= ", `code_ref` = :code_ref";
                unset($this->code_ref);
            }

            // Ne l'enregister qu'une fois
            if(!empty($this->nb_error))
            {
                $param['nb_error'] = $this->nb_error;
                $param_sql_set .= ", `nb_error` = :nb_error";
                unset($this->nb_error);
            }

            $sql = 'UPDATE `'. static::TABLE ."` SET {$param_sql_set} WHERE ". static::PK .' = :id';
            $update = $this->pdo->query($sql, $param);
        }
    }

    /**
     * Déterminer le code en cours + Suppression des logs liés au batch datant de X temps
     * @param  batch_v2		$batch
     * @return void
     */
    public function __epilogue(batch_v2 $batch)
    {
        // Déterminer le code associé au contenu actuel (si définis)
        $this->nb_error = $batch->get_nb_error();
        if(!empty($batch->code_ref))
            $this->code_ref = $batch->code_ref;

        // Effacer les logs au-dela d'une certaine date
        if(empty($this->datetime_delete_oldfile))
            return;

        $time = strtotime($this->datetime_delete_oldfile);
        if(empty($time))
            return;

        // Ajout delete old lines in database
        $sql = 'DELETE FROM `'. static::TABLE .'` WHERE `date` < :date';
        $args = array('date' => date('Y-m-d', $time));

        $this->pdo->query($sql, $args);
    }

    /**
     * Return current ID Session
     * @return int
     */
    public function get_id_session()
    {
        return $this->id_session;
    }
}

?>