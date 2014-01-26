<?php

/**
 * @file
 * Contains \Drupal\user\Theme\AdminNegotiator.
 */

namespace Drupal\user\Theme;

use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Entity\EntityManager;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Sets the active theme on admin pages.
 */
class AdminNegotiator implements ThemeNegotiatorInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $user;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityManager
   */
  protected $entityManager;

  /**
   * Creates a new AdminNegotiator instance.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The current user.
   * @param \Drupal\Core\Config\ConfigFactory $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityManager $entity_manager
   *   The entity manager.
   */
  public function __construct(AccountInterface $user, ConfigFactory $config_factory, EntityManager $entity_manager) {
    $this->user = $user;
    $this->configFactory = $config_factory;
    $this->entityManager = $entity_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(Request $request) {
    $path = $request->attributes->get('_system_path');
    return ($this->entityManager->hasController('user_role', 'storage') && $this->user->hasPermission('view the administration theme') && path_is_admin($path));
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(Request $request) {
    return $this->configFactory->get('system.theme')->get('admin');
  }

}
