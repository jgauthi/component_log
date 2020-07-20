# Component Log
Class and method for generated log on several canals, with error management.

* Terminal (cli)
* On browser (text/plain)
* On logfile
* Return by email if error
* In the table `batch_logs` on database

## Prerequisite

* PHP 5.5+
* Pdo Mysql (use the lib [indieQ Pdo Mysql](https://github.com/jgauthi/indieteq-php-my-sql-pdo-database-class))

## Install
Edit your [composer.json](https://getcomposer.org) (launch `composer update` after edit):
```json
{
  "repositories": [
    { "type": "git", "url": "git@github.com:jgauthi/component_log.git" }
  ],
  "require": {
    "jgauthi/component_log": "1.*"
  }
}
```


## Documentation
You can look at [folder example](https://github.com/jgauthi/component_log/tree/master/example).
