<?php
/* ITaskProcessor | Interface for classes with process method for prefw tasks. */

namespace prefw;

use models\TaskInstance;

interface ITaskProcessor {

  /**
   * Method to process the current task.
   *
   * 1. WorkInstance::process() -> TaskInstance::process(), then
   * 2. TaskInstance::process() -> ITaskProcessor::process()
   *
   * Only at this point WorkInstance will unlock its mutability and in turn allows
   * modifications to task instance and/or work instance itself. While these two
   * parent classes should ensure only the data store of their own can be modified
   * in the mean time.
   *
   * This method is responsible to invoke $taskInstance->resolve() upon success,
   * or $taskInstance()->reject() when there is a rejection action and in turn
   * revert back to one step behind to let users work again.
   */
  function process();

}
