<?php

/**
 * @file
 * Contains Drupal\user\Entity\Role.
 */

namespace Drupal\user\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityStorageControllerInterface;
use Drupal\user\RoleInterface;

/**
 * Defines the user role entity class.
 *
 * @EntityType(
 *   id = "user_role",
 *   label = @Translation("Role"),
 *   controllers = {
 *     "storage" = "Drupal\user\RoleStorageController",
 *     "access" = "Drupal\user\RoleAccessController",
 *     "list" = "Drupal\user\RoleListController",
 *     "form" = {
 *       "default" = "Drupal\user\RoleFormController",
 *       "delete" = "Drupal\user\Form\UserRoleDelete"
 *     }
 *   },
 *   admin_permission = "administer permissions",
 *   config_prefix = "user.role",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "weight" = "weight",
 *     "label" = "label"
 *   },
 *   links = {
 *     "edit-form" = "user.role_edit"
 *   }
 * )
 */
class Role extends ConfigEntityBase implements RoleInterface {

  /**
   * The machine name of this role.
   *
   * @var string
   */
  public $id;

  /**
   * The UUID of this role.
   *
   * @var string
   */
  public $uuid;

  /**
   * The human-readable label of this role.
   *
   * @var string
   */
  public $label;

  /**
   * The weight of this role in administrative listings.
   *
   * @var int
   */
  public $weight;

  /**
   * The permissions belonging to this role.
   *
   * @var array
   */
  public $permissions = array();

  /**
   * {@inheritdoc}
   */
  public function getPermissions() {
    return $this->permissions;
  }

  /**
   * {@inheritdoc}
   */
  public function hasPermission($permission) {
    return in_array($permission, $this->permissions);
  }

  /**
   * {@inheritdoc}
   */
  public function grantPermission($permission) {
    if (!$this->hasPermission($permission)) {
      $this->permissions[] = $permission;
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function revokePermission($permission) {
    $this->permissions = array_diff($this->permissions, array($permission));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function postLoad(EntityStorageControllerInterface $storage_controller, array &$entities) {
    parent::postLoad($storage_controller, $entities);
    // Sort the queried roles by their weight.
    // See \Drupal\Core\Config\Entity\ConfigEntityBase::sort().
    uasort($entities, 'static::sort');
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageControllerInterface $storage_controller) {
    parent::preSave($storage_controller);

    if (!isset($this->weight) && ($roles = $storage_controller->loadMultiple())) {
      // Set a role weight to make this new role last.
      $max = array_reduce($roles, function($max, $role) {
        return $max > $role->weight ? $max : $role->weight;
      });
      $this->weight = $max + 1;
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageControllerInterface $storage_controller, array $entities) {
    parent::postDelete($storage_controller, $entities);

    $storage_controller->deleteRoleReferences(array_keys($entities));
  }

}
