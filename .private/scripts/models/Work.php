<?php
/*! Works.php | The workflow data model. */

namespace models;

use core\ContentEncoder;
use core\Database;
use core\Node;
use core\Relation;
use core\Utility as util;

use framework\exceptions\FrameworkException;

/*! Dev notes

- if stored in the document as an array, when a task component is deleted all workflows should be walked through
- relation table do not support sorting
- if another table is created specifically for this relationship, generic approach is compromised

! Should be done in relation table because of the descendants() and ancestors();

- Workflow components (Tasks) must be consist of a config object for each instance inside a Workflow Container (Works).

! Better off with a standalone table containing such config objects.

- Workflow instances (Jobs) are merely intermediate data objects with a Container Id (Works).

- When workflow is changed, existing Jobs must be updated optionally.

! Thus,
  - Jobs must has a snapshot of corresponding Work that can only be modified by admins.
  - Jobs also has the working data object, modified by working with Steps.

! To consolidate,
  - Workflows has definition (Works) and instances (Jobs),
  - Workflow components has definition (Tasks) and config instances (Steps).
  - Works contains Steps, instead of Tasks.
  - Jobs takes a snapshot of Steps upon instantiate, and can be asked to copy from a modified Work later.

- Updated Works can be with or without the current step in Jobs
  Therefore, only those still contains the current step can be asked for update.
  Otherwise, the action should throw an exception.

*/

class Work extends abstraction\JsonSchemaModel {

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
  //  Methods : AbstractModel
  //
  //----------------------------------------------------------------------------

  function beforeLoad(array &$filter = array()) {
    if ( isset($filter[$this->primaryKey()]) ) {
      $filter[$this->primaryKey()] = util::packUuid($filter[$this->primaryKey()]);
    }

    return $this;
  }

  function afterLoad() {
    // unpack uuid
    $this->uuid = util::unpackUuid($this->uuid);

    // Merge tasks with settings
    $this->tasks = array_reduce((array) @$this->tasks, function($result, $task) {
      $taskModel = new Task($task);

      $taskModel->load($taskModel->identity());
      if ( $taskModel->identity() ) {
        $taskModel->appendData($task);
        $result[] = $taskModel;
      }

      return $result;
    }, array());

    // Restrict to users who have access to the first step
    if ( !$this->__isSuperUser ) {
      if ( !array_intersect((array) @$this->tasks[0]->userGroups(), (array) @$this->__request->user->groups) ) {
        $this->data(array());
      }
    }

    return parent::afterLoad();
  }

  function find(array $filter = array()) {
    if ( isset($filter[$this->primaryKey()]) ) {
      if ( is_array($filter[$this->primaryKey()]) ) {
        $filter[$this->primaryKey()] = array_map('core\Utility::packUuid', $filter[$this->primaryKey()]);
      }
      else {
        $filter[$this->primaryKey()] = util::packUuid($filter[$this->primaryKey()]);
      }
    }

    return parent::find($filter);
  }

  /**
   * @protected
   *
   * Takes care of tasks and settings.
   */
  function beforeSave(array &$errors = array()) {
    // packUuid
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
    else if ( isset($this->uuid) ) {
      $this->uuid = util::packUuid($this->uuid);
    }

    parent::beforeSave($errors);

    if ( !$errors ) {
      if ( empty($this->tasks) ) {
        unset($this->tasks);
      }
      else {
        // Only take task identity and settings
        $this->tasks = array_reduce($this->tasks, function($result, $task) {
          $taskModel = new Task($task);
          $taskModel->load($taskModel->identity());
          if ( $taskModel->identity() ) {
            $result[] = array_filter(array(
                'name' => $taskModel->name,
                'version' => $taskModel->version,
                'settings' => @$task['settings']
              ));
          }

          return $result;
        }, array());

        \core\Log::info('Tasks', $this->tasks);
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
   * Delete all relations.
   */
  function afterDelete() {
    // Delete jobs
    array_map(invokes('delete'), $this->getInstances());

    return parent::afterDelete();
  }

  //----------------------------------------------------------------------------
  //
  //  Methods
  //
  //----------------------------------------------------------------------------

  /**
   * Instantiate this work and tasks, new work instance will be in Stage state.
   *
   * This is designed in favor of the 3-step data flow of TaskInstance.
   */
  public function createInstance() {
    $instance = new WorkInstance($this);

    $instance->__request = $this->__request;
    $instance->__response = $this->__response;

    // note; Users can already give a description here.
    $instance->description = $this->__request->param('description');
    if ( empty($instance->description) ) {
      throw new FrameworkException('Work instance must have description.');
    }

    $result = array();

    $instance->save($result);

    if ( !$result['success'] ) {
      $this->__response->status(400);
      return $result;
    }

    switch ( $result['action'] ) {
      case 'insert':
        $this->__response->status(201); // 201 Created
        break;

      case 'update': break;

      default:
        return $result; // $result[errors]
    }

    return $instance;
  }

  /**
   * Get all jobs under this work.
   *
   * @return {array} Array of Jobs models.
   */
  public function getInstances() {
    return array_reduce($this->children(), function($result, $instance) {
      $instance = (new WorkInstance)->load($instance);
      if ( $instance->identity() ) {
        $result[] = $instance;
      }

      return $result;
    }, array());
  }

}
