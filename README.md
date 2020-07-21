# Component Log
Class and method for generated log on several canals, with error management.

* Terminal (cli)
* On the browser (text/plain)
* On logfile
* Return by email on certain condition (if error, at end script or manually)
* In the table `batch_logs` on database

## Prerequisite

* PHP 7.4+
* PHP Extension: mbstring
* Pdo Mysql (optional)

## Install
Edit your [composer.json](https://getcomposer.org) (launch `composer update` after edit):
```json
{
  "repositories": [
    { "type": "git", "url": "git@github.com:jgauthi/component_log.git" }
  ],
  "require": {
    "jgauthi/component_log": "3.*"
  }
}
```


## Documentation
You can look at [folder example](https://github.com/jgauthi/component_log/tree/master/example).
