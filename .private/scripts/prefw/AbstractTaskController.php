<?php
/* AbstractTaskController.php | Base class of all task controllers. */

namespace prefw;

use models\TaskInstance;

use framework\Resolver;

abstract class AbstractTaskController {

  /**
   * @constructor
   *
   * Controllers have the context of current HTTP request.
   */
  public function __construct(TaskInstance $instance) {
    $this->taskInstance = $instance;
    $this->resolver = Resolver::getActiveInstance();
  }

  //----------------------------------------------------------------------------
  //
  //  Properties
  //
  //----------------------------------------------------------------------------

  /**
   * @protected
   *
   * TaskInstance model cache.
   */
  private $taskInstance;

  protected function taskInstance() {
    return $this->taskInstance;
  }

  /**
   * @protected
   *
   * Resolver instance at instantiation time.
   */
  private $resolver;

  protected function request() {
    if ( $this->resolver ) {
      return $this->resolver->request();
    }
  }

  protected function response() {
    if ( $this->resolver ) {
      return $this->resolver->response();
    }
  }

  /**
   * Retrieve persistant data store in the work instance.
   */
  protected function &dataStore() {
    $dataStore = &$this->taskInstance()->workInstance()->dataStore;
    return $dataStore;
  }

}
