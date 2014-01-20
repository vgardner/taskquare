<?php

/**
 * @file
 * Contains \Drupal\content_translation\Plugin\Derivative\ContentTranslationLocalTasks.
 */

namespace Drupal\content_translation\Plugin\Derivative;

use Drupal\content_translation\ContentTranslationManagerInterface;
use Drupal\Core\Menu\LocalTaskDerivativeBase;
use Drupal\Core\Plugin\Discovery\ContainerDerivativeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides dynamic local tasks for content translation.
 */
class ContentTranslationLocalTasks extends LocalTaskDerivativeBase implements ContainerDerivativeInterface {

  /**
   * The base plugin ID
   *
   * @var string
   */
  protected $basePluginId;

  /**
   * The content translation manager.
   *
   * @var \Drupal\content_translation\ContentTranslationManagerInterface
   */
  protected $contentTranslationManager;

  /**
   * Constructs a new ContentTranslationLocalTasks.
   *
   * @param string $base_plugin_id
   *   The base plugin ID.
   * @param \Drupal\content_translation\ContentTranslationManagerInterface $content_translation_manager
   *   The content translation manager.
   */
  public function __construct($base_plugin_id, ContentTranslationManagerInterface $content_translation_manager) {
    $this->basePluginId = $base_plugin_id;
    $this->contentTranslationManager = $content_translation_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $base_plugin_id,
      $container->get('content_translation.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions(array $base_plugin_definition) {
    // Create tabs for all possible entity types.
    foreach ($this->contentTranslationManager->getSupportedEntityTypes() as $entity_type => $entity_info) {
      // Find the route name for the translation overview.
      $translation_route_name = $entity_info['links']['drupal:content-translation-overview'];

      $this->derivatives[$translation_route_name] = array(
        'entity_type' => $entity_type,
        'title' => 'Translate',
        'route_name' => $translation_route_name,
      ) + $base_plugin_definition;
    }
    return parent::getDerivativeDefinitions($base_plugin_definition);
  }

  /**
   * Alters the local tasks to find the proper tab_root_id for each task.
   */
  public function alterLocalTasks(array &$local_tasks) {
    foreach ($this->contentTranslationManager->getSupportedEntityTypes() as $entity_info) {
      // Find the route name for the entity page.
      $entity_route_name = $entity_info['links']['canonical'];

      // Find the route name for the translation overview.
      $translation_route_name = $entity_info['links']['drupal:content-translation-overview'];
      $translation_tab = $this->basePluginId . ':' . $translation_route_name;

      $local_tasks[$translation_tab]['tab_root_id'] = $this->getPluginIdFromRoute($entity_route_name, $local_tasks);
    }
  }

}
