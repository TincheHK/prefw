<?php
/*! HeadlessController.php | Log specified object path within current data store. */

use core\Log;
use core\Utility as util;

class HeadlessController extends prefw\AbstractTaskController implements prefw\ITaskProcessor {

  function process() {
    $path = (array) @$this->taskInstance()->settings;
    $path = $path['path'];

    $store = $this->dataStore();

    Log::info(sprintf('Value of (%s) in current data store.', $path), array(
        'path' => $path,
        'value' => util::deepVal($path, $store)
      ));
  }

}
