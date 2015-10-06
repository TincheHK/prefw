<?php
/*! Jobs.php | Job models for the prefw system. */

namespace models;

use core\Configuration as conf;
use core\Database;
use core\Deferred;
use core\Relation;
use core\Log;
use core\Utility as util;

use framework\Bayeux;

use framework\exceptions\FrameworkException;

/**
 * The job model does not need json schema to work with.
 *
 * Jobs are merely instances of Works and all validations should be done there.
 *
 * This class provides a mean for View display and Controller scripts.
 *
 * Before a job is saved, it'll use the next step instance to process the data within.
 */
class WorkInstance extends abstraction\JsonSchemaModel {

  /**
   * Normal open state.
   */
  const STATE_OPEN = 'Open';

  /**
   * State for finished works.
   */
  const STATE_CLOSE = 'Closed';

  /**
   * @constructor
   *
   * Instantiate steps from Work model.
   */
  public function __construct($data = null) {
    if ( $data instanceof Work ) {
      $this->work($data);

      // note; Take the value copy of parent Work model.
      $data = $data->data();

      array_remove_keys($data, array(
          'uuid',
          'tasks',
          'timestamp'
        ));

      $data = array_filter($data);
    }

    parent::__construct($data);
  }

  //----------------------------------------------------------------------------
  //
  //  Properties : AbstractModel
  //
  //----------------------------------------------------------------------------

  protected $_primaryKey = 'uuid';

  public function identity($value = null) {
    if ( $value === null ) {
      return util::unpackUuid(parent::identity());
    }
    else {
      return parent::identity(util::packUuid($value));
    }
  }

  //----------------------------------------------------------------------------
  //
  //  Properties
  //
  //----------------------------------------------------------------------------

  /**
   * (Read-only) Lock down data mutability, enabled during task processing.
   */
  protected $_immutable = false;

  /**
   * @protected
   *
   * Work model cache.
   */
  protected $_work;

  /**
   * Get parent work of this job.
   *
   * @return {Works} Work model or null if not found.
   */
  public function work(Work $work = null, $useCache = true) {
    if ( $work !== null ) {
      $this->_work = $work;
      return $this;
    }

    if ( $useCache ) {
      $work = &$this->_work;
    }

    if ( !@$work ) {
      $work = $this->parents('Work');
      $work = reset($work);
      $work = (new Work)->load($work);
    }

    return $work;
  }

  //----------------------------------------------------------------------------
  //
  //  Methods: AbstractModel
  //
  //----------------------------------------------------------------------------

  /**
   * Prevent data change when marked immutable.
   */
  function __set($name, $value) {
    if ( !$this->_immutable ) {
      parent::__set($name, $value);
    }
  }

  function beforeLoad(array &$filter = array()) {
    if ( isset($filter[$this->primaryKey()]) ) {
      $filter[$this->primaryKey()] = util::packUuid($filter[$this->primaryKey()]);
    }

    return $this;
  }

  /**
   * @protected
   *
   * Default to open jobs only.
   */
  function find(array $filter = array()) {
    if ( empty($filter['state']) ) {
      $filter['state'] = static::STATE_OPEN;
    }

    // pack uuid
    if ( isset($filter[$this->primaryKey()]) ) {
      if ( is_array($filter[$this->primaryKey()]) ) {
        $filter[$this->primaryKey()] = array_map('core\Utility::packUuid', $filter[$this->primaryKey()]);
      }
      else {
        $filter[$this->primaryKey()] = util::packUuid($filter[$this->primaryKey()]);
      }
    }

    if ( isset($filter['nextTask']) ) {
      if ( is_array($filter['nextTask']) ) {
        $filter['nextTask'] = array_map('core\Utility::packUuid', $filter['nextTask']);
      }
      else {
        $filter['nextTask'] = util::packUuid($filter['nextTask']);
      }
    }

    return parent::find($filter);
  }

  /**
   * @protected
   *
   * Load task contents, remove task scripts.
   */
  function afterLoad() {
    // unpack uuid
    $this->uuid = util::unpackUuid($this->uuid);
    $this->nextTask = util::unpackUuid($this->nextTask);

    // note: Only users with access to nextTask has view permission.
    if ( !$this->__isSuperUser ) {
      // 1. Group:User
      $res = (array) @$this->__request->user->groups;
      // 2. Task:Group
      $res = Relation::getAncestors($res, Task::GROUP_RELATION);
      // 3. Task
      $res = Relation::getDescendants($res, 'Task');
      // 4. UUID comparison
      if ( !in_array($this->nextTask, $res) ) {
        return $this->data(array());
      }
    }

    // load task details, do not expose internal scripts
    $taskInstance = new TaskInstance();
    $this->tasks = array_map(removes('order'),
      $taskInstance->find(array(
        'uuid' => $this->children($taskInstance::WORK_RELATION),
        '@sorter' => ['order']
      )));
    unset($taskInstance);

    $res = parent::afterLoad();

    // lock the item after load
    $this->_immutable = true;

    return $res;
  }

  /**
   * Process POST data with current step.
   */
  function beforeSave(array &$errors = array()) {
    // note; WorkInstance is locked from modification, except when staging and processing.
    if ( !$this->isCreate() && $this->_immutable ) {
      // todo; Validation messages is not yet put into play.
      $errors[] = 'Cannot modify work instance.';

      throw new FrameworkException('Cannot modify work instance.');
    }

    if ( $this->state == static::STATE_CLOSE ) {
      $this->nextTask = null;
    }

    // binary encode task uuid
    if ( !empty($this->nextTask) ) {
      $this->nextTask = util::packUuid($this->nextTask);
    }

    if ( isset($this->uuid) ) {
      $this->uuid = util::packUuid($this->uuid);
    }

    // reset empty data store
    if ( empty($this->dataStore) ) {
      unset($this->dataStore);
    }

    // note; task instances will not be created after work instance creation.
    if ( $this->isCreate() ) {
      // note; loop until we find a unique uuid
      do {
        $this->identity(Database::fetchField("SELECT UNHEX(REPLACE(UUID(), '-', ''))"));
      }
      while (
        $this->find(array(
            $this->primaryKey() => $this->identity()
          ))
        );

      // note; Only users with permission to first task can create.
      // note;security; Copy tasks from parent work again.

      if ( empty($this->state) ) {
        $this->state = static::STATE_OPEN;
      }

      $this->__tasks = array_reduce($this->work()->tasks, function($result, $task) {
        $task = new TaskInstance($task);
        $task->workInstance($this);
        $task->order = count($result);
        $result[] = $task;
        return $result;
      }, array());

      $task = reset($this->__tasks);
      if ( !$task ) {
        throw new FrameworkException('Unable to load work tasks.');
      }

      if ( !$this->__isInternal && !array_intersect((array) @$this->__request->user->groups, $task->userGroups()) ) {
        throw new FrameworkException('User has no permission to work on this.');
      }
    }

    unset($this->tasks);

    return parent::beforeSave($errors);
  }

  /**
   * @protected
   *
   * Save steps only upon creation.
   */
  function afterSave() {
    if ( $this->isCreate() ) {
      // note; Work:Instance relation
      $this->parents('Work', $this->work(), true);

      if ( $this->__tasks ) {
        // temprorily release immutable lock
        $this->_immutable = false;

        $nextTask = reset($this->__tasks);

        array_map(invokes('save'), $this->__tasks);

        $this->nextTask = $nextTask->identity();

        unset($this->__tasks);

        // note; save will trigger internal reload, thus afterLoad(), and then immutable lock.
        $this->save();
      }
    }

    return $this;
  }

  /**
   * @protected
   *
   * Pack UUID for delete filters.
   */
  function beforeDelete(array &$filter = array()) {
    if ( isset($filter[$this->primaryKey()]) ) {
      $filter[$this->primaryKey()] = util::packUuid($filter[$this->primaryKey()]);
    }

    return $this;
  }

  /**
   * @protected
   *
   * Remove all job relations.
   */
  function afterDelete() {
    // Delete steps
    array_map(invokes('delete'), (array) @$this->tasks);

    // Delete work relation
    $this->deleteAncestors('Work');

    return parent::afterDelete();
  }

  //----------------------------------------------------------------------------
  //
  //  Methods
  //
  //----------------------------------------------------------------------------

  /**
   * Load WorkInstance by next task uuid.
   */
  public function loadByNextTask($uuid) {
    $instance = $this->find(array(
        'nextTask' => $uuid
      ));
    $instance = reset($instance);

    if ( $instance ) {
      $instance = $instance->data();
    }
    else {
      $instance = array();
    }

    $this->data($instance);

    return $this;
  }

  /**
   * Get next task with uuid.
   */
  public function nextTask() {
    $instance = (new TaskInstance)->load($this->nextTask);
    if ( $instance->identity() ) {
      return $instance->workInstance($this);
    }
  }

  /**
   * Process next task with POST data.
   */
  public function process() {
    // note: Some tasks can work without post data, but request method must be POST.
    if ( !$this->__isSuperUser && $this->__request->method() != 'post' ) {
      $this->__response->status(405); // Method not allowed
      return;
    }

    // WorkInstance
    if ( !$this->identity() ) {
      $this->__response->status(404); // WorkInstance not found
      return;
    }

    // TaskInstance
    $instance = $this->nextTask();
    if ( !$instance ) {
      $this->__response->status(404); // TaskInstance not foudn
      return;
    }

    // release mutable lock for work instance initialization.
    $this->_immutable = false;

    // remove tasks to prevent unwanted change.
    $tasks = $this->tasks; unset($this->tasks);

    // creates $this->dataStore if not yet.
    if ( empty($this->dataStore) ) {
      $this->dataStore = array();
    }

    unset($this->lastError);

    // immutable marker to prevent direct modifications to the internal data.
    $this->_immutable = true;

    // note: Send bayeux message to groups with permission to this task.
    $userGroups = $instance->userGroups();

    try {
      // Note: Since $this->dataStore is an array, it is mutable itself.
      $promise = $instance->process();
    }
    catch (\Exception $e) {
      Log::warning('Task process exception.', array_filter(array(
          'message' => $e->getMessage(),
          'code' => $e->getCode(),
          'file' => $e->getFile(),
          'line' => $e->getLine(),
          'trace' => $e->getTrace()
        )));

      $lastError = array(
          'message' => $this->__response->__($e->getMessage()),
          'code' => $e->getCode()
        );

      // note: Failure on Headless tasks will revert to previous task.
      if ( @$instance->type == 'Headless' ) {
        $deferred = new Deferred();

        $deferred->reject($lastError['message'], $lastError['code']);

        $promise = $deferred->promise();

        unset($deferred);
      }
      // note: Failure on Template tasks will leave "nextTask" as-is, but still stores last error.
      else {
        $this->_immutable = false;

        $this->lastError = $lastError;
      }

      unset($lastError);
    }

    $this->_immutable = false;

    $result = array();

    $saveFunc = function() use(&$result) {
      unset($this->timestamp);

      $this->save($result);
    };

    if ( isset($promise) ) {
      // note: rejection here means revert to previous task
      $promise->fail(function($error, $code = null) use($instance, $tasks) {
        $this->lastError = array_filter(array(
            'message' => $error,
            'code' => $code
          ));

        // revert to previous task
        $prevTask = array_search($instance->identity(), array_map(invokes('identity'), $tasks));
        $prevTask = @$tasks[$prevTask - 1];
        // fallback to the first task
        if ( !$prevTask ) {
          $prevTask = reset($tasks);
        }

        $this->nextTask = util::packUuid($prevTask->identity());
      });

      // note: resolution always advances to next task
      $promise->done(function() use($instance, $tasks) {
        $nextTask = array_search($instance->identity(), array_map(invokes('identity'), $tasks));

        $nextTask = @$tasks[$nextTask + 1];

        if ( $nextTask ) {
          $this->nextTask = util::packUuid($nextTask->identity());
        }
        else {
          $this->state = static::STATE_CLOSE;
          $this->nextTask = null;
        }
      });

      // note: controller script must call resolve() or reject() to make this happen.
      $promise->always($saveFunc);
    }
    else {
      $saveFunc();
    }

    unset($saveFunc);

    // note: Merge user groups before and after task processing
    if ( $this->nextTask ) {
      $userGroups = array_unique(array_merge($userGroups, $this->nextTask()->userGroups()));
    }

    foreach ( $userGroups as $userGroup ) {
      Bayeux::sendMessage("/group/$userGroup", array(
          'action' => 'update',
          '@collection' => 'WorkInstance',
          'timestamp' => $this->timestamp
        ));
    }

    if ( @$result['error'] ) {
      $this->__response->status(500);
      return $result;
    }
    else {
      $this->__response->status(200);
      return $this;
    }
  }

  /**
   * Expose process() method, use this before WebServiceResolver can pipe into result methods.
   *
   * @param {string} $uuid The UUID to search by nextTask for a work instance.
   */
  public function __process($uuid) {
    return $this->loadByNextTask($uuid)->process();
  }

}
