<?php
/*! Task.php | Work flow components data model. */

namespace models;

use core\ContentDecoder;
use core\Utility as util;

use vierbergenlars\SemVer\version;
use vierbergenlars\SemVer\expression;

use framework\exceptions\FrameworkException;

class Task extends abstraction\JsonSchemaModel {

  /**
   * Idenitfier for Task-Group (1:*) relation.
   */
  const GROUP_RELATION = 'Task:Group';

  //----------------------------------------------------------------------------
  //
  //  Methods: AbstractModel
  //
  //----------------------------------------------------------------------------

  /**
   * Concatenates name and version of the package with an at sign "@", but returns
   * null when either one of them does not exsit.
   *
   * @param {null} $value Inherited signature, Task do not support modification.
   * @return {Task|string} When tried to set values, doesn't updates but still chainable.
   */
  function identity($value = null) {
    if ( $value !== null ) {
      return $this;
    }

    if ( empty($this->name) ) {
      return;
    }

    $identity = $this->name;
    if ( isset($this->version) ) {
      $identity.= "@$this->version";
    }

    return $identity;
  }

  /**
   * Loads target task module with specified composer name, from the path
   * ".private/modules/$vendor/$name".
   *
   * @param {string} $identity "$vendor/$name" format respecting composer syntax.
   * @return {array} JSON decoded array of composer.json.
   */
  function load($identity) {
    // todo; implement this
    // note; check identity format
    if ( !preg_match('/^\w+\/[^\:\/\.]+(?:@.+)?$/', $identity) ) {
      return $this->data(array());
    }

    @list($identity, $version) = explode('@', $identity);

    $composer = ".private/modules/$identity/composer.json";
    if ( !file_exists($composer) ) {
      return $this->data(array());
    }

    $composer = ContentDecoder::json(file_get_contents($composer));

    // no specific version, or we have a newer one.
    if ( !$version || (new version($composer['version']))->satisfies(new expression($version)) ) {
      $this->data($composer)->afterload();
    }
    else {
      $this->data(array());
    }

    return $this;
  }

  /**
   * @protected
   *
   * Appends user group permission into data, because afterload
   */
  function afterLoad() {
    $this->groups = $this->userGroups();

    return $this;
  }

  /**
   * @protected
   *
   * Returns all composer.json, this respects list range and list order, but
   * filtering is not supported.
   */
  function find(array $filter = array()) {
    // list tasks modules
    $modules = glob('.private/modules/*/*/composer.json');

    $modules = array_map(compose('core\ContentDecoder::json', 'file_get_contents'), $modules);
    $modules = array_map(function($module) {
      return (new Task($module))->afterLoad();
    }, $modules);

    // @sorter, list order
    if ( isset($filter['@sorter']) ) {
      $sorter = array();

      foreach ( $filter['@sorter'] as $key => $value ) {
        // numeric key, use value as ASC sorting
        if ( is_numeric($key) && is_string($value) ) {
          $key = $value;
          $value = true;
        }

        $sorter[] = array_map(prop($key), $modules);
        $sorter[] = $value ? SORT_ASC : SORT_DESC;
      }

      $sorter[] = &$modules;

      call_user_func_array('array_multisort', $sorter);

      unset($sorter);
    }

    // @limits, list range
    if ( isset($filter['@limits']) ) {
      $modules = call_user_func_array('array_slice', array_merge(array($modules), $filter['@limits']));
    }

    return $modules;
  }

  /**
   * @protected
   *
   * Updates user group.
   */
  function save(array &$result = null) {
    $this->userGroups((array) @$this->groups, true);

    $result['success'] = true;
    $result['action'] = 'update';

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  function delete(&$isDeleted = false) {
    // note; modification via Model interface
    throw new FrameworkException('Tasks are read-only.');
  }

  //----------------------------------------------------------------------------
  //
  //  Methods
  //
  //----------------------------------------------------------------------------

  /**
   * Despite no real data is stored in database, we generate the model identity
   * from package name and version. Which still works when doing node relations.
   */
  public function userGroups($newGroups = null, $replace = false) {
    return $this->children(static::GROUP_RELATION, $newGroups, $replace);
  }

}
