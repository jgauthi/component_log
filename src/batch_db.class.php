<?php
/*****************************************************************************************************
 * @name Batch DB
 * @note: Extension de la class de batch pour placer les logs en base de donnée
 * @author Jgauthi <github.com/jgauthi>, crée le 17juin2018
 * @version 1.0.6
 * @Requirements:
    - php version 5.5+ avec pdo_mysql, mysql v5.6
    - Class batch >= v1.77
    - Class Indieteq pdo: https://github.com/jgauthi/indieteq-php-my-sql-pdo-database-class

 * @todo: Ajout support hook pour implémenter les messages dans "config_print_function"
au lieu des methodes log/error()

 ******************************************************************************************************/

/*
DROP TABLE IF EXISTS `batch_logs`;
CREATE TABLE `batch_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `script` varchar(40) NOT NULL,
  `date` datetime NOT NULL,
  `messages` LONGTEXT NOT NULL,
  `code_ref` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `script` (`script`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COMMENT='Log de la class batch-db' AUTO_INCREMENT=1 ;
*/

class batch_db extends batch
{
    private $pdo;
    private $table = 'batch_logs';
    private $script_name;
    public $code_ref = null;

    /**
     * Constructeur
     * @param string	$logfile	(Optionnel)
     * @param string	$version	(Optionnel)
     */
    public function __construct(&$pdo, $script_name = null, $version = null, $logfile = null)
    {
        // Ajout support DB
        $this->pdo = clone $pdo;
        $this->script_name = ((!empty($script_name)) ? $script_name : substr(basename($_SERVER['PHP_SELF']),0,40));

        parent::__construct($logfile, $version);
    }


    protected function query_msg($message)
    {
        static $id_session = null;

        if(empty($id_session))
        {
            $param = array
            (
                'script' 	=> $this->script_name,
                'date'		=> date('Y-m-d H:i:s'),
                'messages'	=> "{$message}\n",
            );
            $sql = "INSERT INTO `{$this->table}` SET `script` = :script, `date` = :date, `messages` = :messages";

            $insert = $this->pdo->Query($sql, $param);
            if($insert > 0)
                $id_session = $this->pdo->lastInsertId();
            else return !user_error('Error during insert log in database');
        }
        else
        {
            $param = array
            (
                'id'		=> $id_session,
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

            $sql = "UPDATE `{$this->table}` SET {$param_sql_set} WHERE id = :id";
            $update = $this->pdo->query($sql, $param);
        }
    }

    /**
     * Affiche / écrit dans un log: un message
     * @param  string	$msg
     * @param  string	$type	null|success|warning|info
     * @return void
     */
    public function log($msg, $type = null)
    {
        parent::log($msg, $type);
        $this->query_msg($msg);
    }

    /**
     * Affiche / écrit dans un log: une erreur
     * @param  string	$msg
     * @return void
     */
    public function error($msg)
    {
        parent::error($msg);
        $this->query_msg($msg);
    }

    /**
     * Suppression des logs liés au batch datant de X temps
     * @param  string	$filtre	Filtre date interprété par strtotime (défaut: first day of -2 month)
     * @return (void|null)
     */
    public function delete_old_logfile($filtre = 'first day of -2 month')
    {
        // Ajout delete old lines in database
        $time = strtotime($filtre);
        if(empty($time))
            return false;

        $sql = "DELETE FROM `{$this->table}` WHERE `date` < :date";
        $args = array('date' => date('Y-m-d', $time));

        $this->pdo->query($sql, $args);

        return parent::delete_old_logfile($filtre);
    }

}

?>