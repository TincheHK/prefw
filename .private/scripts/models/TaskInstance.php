<?php
/*! Steps.php | Model class for workflow component instance objects. */

namespace models;

use core\Database;
use core\Deferred;
use core\Utility as util;

use framework\Configuration as conf;
use framework\Request;
use framework\Response;

use framework\exceptions\FrameworkException;

class TaskInstance extends abstraction\JsonSchemaModel {

  /**
   * Identifier for WorkInstance-TaskInstance (1:*) relation.
   */
  const WORK_RELATION = 'WorkInstance:TaskInstance';

  /**
   * @constructor
   */
  public function __construct($data = null) {
    if ( $data instanceof Task ) {
      // relation: Task
      $this->task($data);

      // Also stores the task identity "$name@$version"
      $this->identity = str_replace('@', '@~', $data->identity());
      $this->name = $data->name;
      $this->version = $data->version;
      $this->type = $data->extra['type'];
      $this->extra = array_select($data->extra, array(
          'name',
          'endpoint',
        ));

      if ( isset($data->settings) ) {
        $this->settings = $data->settings;
      }

      // Copy fields from Task model
      $data = $data->data();
    }
    else {
      parent::__construct($data);
    }
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
   * @protected
   *
   * Task model cache.
   */
  protected $_task;

  /**
   * Accessor of Task model.
   */
  public function task(Task $value = null, $useCache = true) {
    if ( $value !== null ) {
      $workInstance = $this->_workInstance;
      if ( !$workInstance || !$workInstance->immutable() ) {
        $this->_task = $value;
      }

      return $this;
    }

    if ( $useCache ) {
      $task = &$this->_task;
    }

    if ( @$task === null ) {
      $task = $this->parents('Task');
      $task = reset($task);
      $task = (new Task)->load($task);
    }

    return $task;
  }

  /**
   * @protected
   *
   * Work instance cache.
   */
  protected $_workInstance;

  /**
   * Accessor of WorkInstance model.
   */
  public function workInstance(WorkInstance $value = null, $useCache = true) {
    if ( $value !== null ) {
      $this->_workInstance = $value;
      return $this;
    }

    if ( $useCache ) {
      $workInstance = &$this->_workInstance;
    }

    if ( @$workInstance === null ) {
      $workInstance = $this->parents(static::WORK_RELATION);
      $workInstance = reset($workInstance);
      $workInstance = (new WorkInstance)->load($workInstance);
    }

    return $workInstance;
  }

  /**
   * Get accessible user groups
   */
  public function userGroups() {
    return $this->task()->userGroups();
  }

  /**
   * @protected
   *
   * Deferred object for current task.
   */
  protected $__deferred;

  //----------------------------------------------------------------------------
  //
  //  Methods : AbstractModel
  //
  //----------------------------------------------------------------------------

  /**
   * Respect parent work instance locking mechanism.
   */
  function __set($name, $value) {
    $workInstance = $this->_workInstance;
    if ( !$workInstance || !$workInstance->immutable() ) {
      parent::__set($name, $value);
    }
  }

  function load($identity) {
    return parent::load(util::packUuid($identity));
  }

  function find(array $filter = array()) {
    $identity = &$filter[$this->primaryKey()];
    if ( $identity ) {
      if ( is_array($identity) ) {
        $identity = array_map('core\Utility::packUuid', $identity);
      }
      else {
        $identity = util::packUuid($identity);
      }
    }
    else {
      unset($filter[$this->primaryKey()]);
    }
    unset($identity);

    return parent::find($filter);
  }

  /**
   * @protected
   *
   * Unpack uuid field.
   */
  function afterLoad() {
    // unpack uuid
    $this->uuid = util::unpackUuid($this->uuid);

    return parent::afterLoad();
  }

  /**
   * @protected
   *
   * A TaskInstance can only be saved when,
   *   1. WorkInstance is mutable, or
   *   2. WorkInstance exists and this is creating, or
   *   3. WorkInstance is Open, and nextTask is empty or equals to this one.
   */
  function beforeSave(array &$errors = array()) {
    $workInstance = $this->workInstance();
    if ( $workInstance->immutable() ) {
      throw new FrameworkException('Task instance cannot be modified.');
    }

    if ( !$workInstance || $workInstance->state == $workInstance::STATE_CLOSE ) {
      throw new FrameworkException('Work instance does not exist.');
    }

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
    }
    else {
      if ( $workInstance->nextTask != $this->identity() ) {
        throw new FrameworkException('Task is not active.');
      }
    }

    return parent::beforeSave($errors);
  }

  /**
   * @protected
   *
   * Create relations between steps and works or jobs, even though works and jobs
   * are mutually exclusive, manage this on the parent side.
   */
  function afterSave() {
    if ( $this->isCreate() ) {
      // Task:Instance
      $this->parents('Task', $this->task(), true);

      // WorkInstance:TaskInstance
      $this->parents(static::WORK_RELATION, $this->workInstance(), true);
    }

    // unpack uuid strings
    $this->uuid = util::unpackUuid($this->uuid);

    return parent::afterSave();
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
   * Cleanup relations
   */
  function afterDelete() {
    $this->deleteAncestors('Task');
    $this->deleteAncestors(static::WORK_RELATION);

    return parent::afterDelete();
  }

  //----------------------------------------------------------------------------
  //
  //  Methods
  //
  //----------------------------------------------------------------------------

  /**
   * Last step of task process. Responsible of taking request parameters and/or
   * any external input and process them. Tasks might also update the data store
   * in work instance to for reference of further tasks.
   *
   * @param {Request} $request The HTTP request parameter for post data, null for headless tasks.
   *
   * @return {Promise} The promise object about whether this task is successfully processed.
   */
  public function process() {
    $this->__deferred = new Deferred();

    // // note; execute $this->processScript.
    // $f = tmpfile(); fwrite($f, '<?php ' . $this->processScript);
    // $i = stream_get_meta_data($f); $i = $i['uri'];
    // call_user_func(
    //   function($request) { include(func_get_arg(1)); },
    //   $request, $i);
    // fclose($f); unset($f, $i);

    if ( empty($this->extra['endpoint']['controller']) ) {
      throw new FrameworkException('Task instance has no endpoint defined.');
    }
    else {
      // load the specified controller
      $controller = $this->extra['endpoint']['controller'];
      $basePath = ".private/modules/$this->name";
      require_once("$basePath/controllers/$controller.php");
      chdir($basePath);
      unset($basePath);

      $controller = new $controller($this);
      if ( !$controller instanceof \prefw\ITaskProcessor ) {
        throw new FrameworkException('Controller must implement ITaskProcessor interface!');
      }
      else {
        $ret = $controller->process();

        /*! note;
         *  Three kinds of acceptible return value,
         *    1. null type resolves immediately
         *    2. Exceptions rejects immediately
         *    3. Promise objects pipes results
         */
        if ( $ret === null ) {
          $this->resolve();
        }
        else if ( $ret instanceof \core\Promise ) {
          $ret->then(array($this, 'resolve'), array($this, 'reject'));
        }
      }
    }

    \core\Log::info('TaskInstance:afterProcess', array(
        'dataStore' => $this->workInstance()->dataStore
      ));

    return $this->__deferred->promise();
  }

  /**
   * Shorthand resolve method to underlying deferred object, for convenient use in task scripts.
   */
  public function resolve() {
    return call_user_func_array(
      array($this->__deferred, 'resolve'),
      func_get_args());
  }

  /**
   * Shorthand reject method to underlying deferred object, for convenient use in task scripts.
   */
  public function reject() {
    return call_user_func_array(
      array($this->__deferred, 'reject'),
      func_get_args());
  }

}
