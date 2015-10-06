<?php
/*! DatabaseTransaction.php | Create database transaction and invokes functions attached. */

namespace framework;

use core\Database;

/**
 * Creates database transaction and fires attached promises, commit or rollback afterwards.
 */
class DatabaseTransaction extends \core\EventEmitter {

  protected $callbacks = array();

  function execute() {
    if ( !Database::beginTransaction() ) {
      trigger_error('Cannot initiate transaction.', E_USER_WARNING);

      return false; // Must halt explicitly because of data integrity.
    }

    $hasFailure = false;
    $failHandler = function() use (&$hasFailure) {
      $hasFailure = true;
    };

    foreach ( $this->callbacks as $callback ) {
      $callback()->fail($failHandler);

      if ( $hasFailure ) {
        break;
      }
    }

    if ( $hasFailure ) {
      Database::rollback();
    }
    else {
      Database::commit();
    }

    return !$hasFailure;
  }

  function when($callback) {
    if ( is_callable($callback) ) {
      $this->callbacks[] = $callback;
    }
  }

  function whenAll(array $promises = array()) {
    array_map(array($this, 'when'), $promises);
  }

}
