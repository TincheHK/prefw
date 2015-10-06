<?php
/*! HeadlessWorker.php | Takes care of headless process ahead. */

require_once('.private/scripts/Initialize.php');

use core\Database;
use core\Log;

use framework\Bayeux;
use framework\Configuration as conf;
use framework\Service;

use models\WorkInstance;
use models\TaskInstance;

$taskInstance = new TaskInstance();

// Consumes Headless tasks ahead.
$tasks = Database::fetchArray('SELECT `nextTask` FROM `WorkInstance`
  WHERE `nextTask` IN (SELECT `uuid` FROM `TaskInstance` WHERE `type` = \'Headless\')
    AND `state` = \'Open\';');

$tasks = array_map(compose('core\Utility::unpackUuid', prop('nextTask')), $tasks);

if ( $tasks ) {
  Log::debug(sprintf('%d headless tasks found!', count($tasks)), $tasks);
}

$serviceOptions = array(
    'resolver' => new framework\Resolver()
  );

$serviceOptions['resolver']->registerResolver(new resolvers\WebServiceResolver(array(
    'prefix' => conf::get('web::resolvers.service.prefix', '/service')
  )));

foreach ( $tasks as $taskUuid ) {
  // renew response object for each call
  $serviceOptions['response'] = new framework\Response(array(
    'autoOutput' => false
  ));

  Service::call('_/WorkInstance', 'process', array($taskUuid), $serviceOptions);

  // todo: send bayeux update message to notify related users about the task update.
}
