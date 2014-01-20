<?php

/**
 * @file
 * Contains \Drupal\shortcut\ShortcutAccessController.
 */

namespace Drupal\shortcut;

use Drupal\Core\Entity\EntityAccessController;
use Drupal\Core\Entity\EntityControllerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the access controller for the test entity type.
 */
class ShortcutAccessController extends EntityAccessController implements EntityControllerInterface {

  /**
   * The shortcut_set storage controller.
   *
   * @var \Drupal\shortcut\ShortcutSetStorageController
   */
  protected $shortcutSetStorage;

  /**
   * Constructs a ShortcutAccessController object.
   *
   * @param string $entity_type
   *   The entity type of the access controller instance.
   * @param array $entity_info
   *   An array of entity info for the entity type.
   * @param \Drupal\shortcut\ShortcutSetStorageController $shortcut_set_storage
   *   The shortcut_set storage controller.
   */
  public function __construct($entity_type, array $entity_info, ShortcutSetStorageController $shortcut_set_storage) {
    parent::__construct($entity_type, $entity_info);
    $this->shortcutSetStorage = $shortcut_set_storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, $entity_type, array $entity_info) {
    return new static(
      $entity_type,
      $entity_info,
      $container->get('entity.manager')->getStorageController('shortcut_set')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, $langcode, AccountInterface $account) {
    if ($shortcut_set = $this->shortcutSetStorage->load($entity->bundle())) {
      return shortcut_set_edit_access($shortcut_set, $account);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    if ($shortcut_set = $this->shortcutSetStorage->load($entity_bundle)) {
      return shortcut_set_edit_access($shortcut_set, $account);
    }
  }

}
