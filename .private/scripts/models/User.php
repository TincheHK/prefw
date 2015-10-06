<?php
/*! User.php | The user data model. */

namespace models;

use core\Node;

use authenticators\IsAdministrator;
use authenticators\IsInternal;

class User extends abstraction\JsonSchemaModel {

  /**
   * Identifier for User-Role (1:*) relation.
   *
   * Note: Roles are not themselves a model, but plain strings in the relation
   *       table for mappings.
   */
  const GROUP_RELATION = 'Group:User';

  /**
   * @protected
   *
   * Prepended to hash for crypt() method to understand which algorithm to use.
   */
  protected $__hashPrefix = '$6$rounds=10000$'; // CRYPT_SHA512

  /**
   * @protected
   *
   * Create a hash for UNIX crypt(6)
   *
   * Note: Make this alterable, pay attention to key-strenthening.
   */
  protected function hash($username, $password) {
    $hash = sha1(time() + mt_rand());
    $hash = md5("$username:$hash");
    $hash = substr($hash, 16);
    $hash = "$this->__hashPrefix$hash";
    return crypt($password, $hash);
  }

  //----------------------------------------------------------------------------
  //
  //  Methods: AbstractModel
  //
  //----------------------------------------------------------------------------

  function load($identity) {
    if ( $identity == '~' ) {
      return $this->data( $this->__request->user->data() );
    }
    else {
      if ( !is_numeric($identity) && is_string($identity) ) {
        $identity = array( 'username' => $identity );
      }

      return parent::load($identity);
    }
  }

  function afterLoad() {
    if ( !$this->__isSuperUser ) {
      unset($this->password);
    }

    // Load groups
    $this->groups = $this->parents(static::GROUP_RELATION);

    return parent::afterLoad();
  }

  function validate(array &$errors = array()) {
    if ( $this->isCreate() ) {
      if ( (new User)->load($this->username)->identity() ) {
        $errors[] = 'This email has already been registerd.';
      }
    }
  }

  /**
   * Hash the user password if not yet.
   */
  function beforeSave(array &$errors = array()) {
    $password = $this->password;
    if ( strpos($password, $this->__hashPrefix) !== 0 ) {
      $this->password = $this->hash($this->username, $password);
    }
    unset($password);

    // note: do not store groups into virtual fields
    if ( !empty($this->groups) ) {
      $this->__groups = $this->groups; unset($this->groups);
    }

    return parent::beforeSave($errors);
  }

  /**
   * Save user groups
   */
  function afterSave() {
    // save groups
    if ( !empty($this->__groups) ) {
      $groups = array_map('ucwords', (array) $this->__groups);

      if ( $groups != $this->parents(static::GROUP_RELATION) ) {
        $this->parents(static::GROUP_RELATION, $groups, true);
      }

      // note: put it back for data consistency
      $this->groups = $groups;

      unset($this->__groups);
    }

    return parent::afterSave();
  }

  /**
   * Remove all sessions upon delete.
   */
  function afterDelete() {
    Node::delete(array(
        NODE_FIELD_COLLECTION => FRAMEWORK_COLLECTION_SESSION,
        'username' => $this->identity()
      ));

    $this->deleteAncestors(static::GROUP_RELATION);

    return parent::afterDelete();
  }

}
