<?php

/**
 * @file
 * Contains \Drupal\aggregator\Entity\Feed.
 */

namespace Drupal\aggregator\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Field\FieldDefinition;
use Symfony\Component\DependencyInjection\Container;
use Drupal\Core\Entity\EntityStorageControllerInterface;
use Drupal\Core\Entity\Annotation\EntityType;
use Drupal\Core\Annotation\Translation;
use Drupal\aggregator\FeedInterface;

/**
 * Defines the aggregator feed entity class.
 *
 * @EntityType(
 *   id = "aggregator_feed",
 *   label = @Translation("Aggregator feed"),
 *   controllers = {
 *     "storage" = "Drupal\aggregator\FeedStorageController",
 *     "view_builder" = "Drupal\aggregator\FeedViewBuilder",
 *     "form" = {
 *       "default" = "Drupal\aggregator\FeedFormController",
 *       "delete" = "Drupal\aggregator\Form\FeedDeleteForm",
 *       "remove_items" = "Drupal\aggregator\Form\FeedItemsRemoveForm"
 *     }
 *   },
 *   base_table = "aggregator_feed",
 *   fieldable = TRUE,
 *   entity_keys = {
 *     "id" = "fid",
 *     "label" = "title",
 *   }
 * )
 */
class Feed extends ContentEntityBase implements FeedInterface {

  /**
   * The feed ID.
   *
   * @todo rename to id.
   *
   * @var \Drupal\Core\Field\FieldItemListInterface
   */
  public $fid;

  /**
   * Title of the feed.
   *
   * @var \Drupal\Core\Field\FieldItemListInterface
   */
  public $title;

  /**
   * The feed language code.
   *
   * @var \Drupal\Core\Field\FieldItemListInterface
   */
  public $langcode;

  /**
   * URL to the feed.
   *
   * @var \Drupal\Core\Field\FieldItemListInterface
   */
  public $url;

  /**
   * How often to check for new feed items, in seconds.
   *
   * @var \Drupal\Core\Field\FieldItemListInterface
   */
  public $refresh;

  /**
   * Last time feed was checked for new items, as Unix timestamp.
   *
   * @var \Drupal\Core\Field\FieldItemListInterface
   */
  public $checked;

  /**
   * Time when this feed was queued for refresh, 0 if not queued.
   *
   * @var \Drupal\Core\Field\FieldItemListInterface
   */
  public $queued;

  /**
   * The parent website of the feed; comes from the <link> element in the feed.
   *
   * @var \Drupal\Core\Field\FieldItemListInterface
   */
  public $link ;

  /**
   * The parent website's description;
   * comes from the <description> element in the feed.
   *
   * @var \Drupal\Core\Field\FieldItemListInterface
   */
  public $description;

  /**
   * An image representing the feed.
   *
   * @var \Drupal\Core\Field\FieldItemListInterface
   */
  public $image;

  /**
   * Calculated hash of the feed data, used for validating cache.
   *
   * @var \Drupal\Core\Field\FieldItemListInterface
   */
  public $hash;

  /**
   * Entity tag HTTP response header, used for validating cache.
   *
   * @var \Drupal\Core\Field\FieldItemListInterface
   */
  public $etag;

  /**
   * When the feed was last modified, as a Unix timestamp.
   *
   * @var \Drupal\Core\Field\FieldItemListInterface
   */
  public $modified;

  /**
   * {@inheritdoc}
   */
  public function init() {
    parent::init();

    // We unset all defined properties, so magic getters apply.
    unset($this->fid);
    unset($this->title);
    unset($this->url);
    unset($this->refresh);
    unset($this->checked);
    unset($this->queued);
    unset($this->link);
    unset($this->description);
    unset($this->image);
    unset($this->hash);
    unset($this->etag);
    unset($this->modified);
  }

  /**
   * Implements Drupal\Core\Entity\EntityInterface::id().
   */
  public function id() {
    return $this->get('fid')->value;
  }

  /**
   * Implements Drupal\Core\Entity\EntityInterface::label().
   */
  public function label($langcode = NULL) {
    return $this->get('title')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function removeItems() {
    $manager = \Drupal::service('plugin.manager.aggregator.processor');
    foreach ($manager->getDefinitions() as $id => $definition) {
      $manager->createInstance($id)->remove($this);
    }
    // Reset feed.
    $this->checked->value = 0;
    $this->hash->value = '';
    $this->etag->value = '';
    $this->modified->value = 0;
    $this->save();
  }

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageControllerInterface $storage_controller, array &$values) {
    $values += array(
      'link' => '',
      'description' => '',
      'image' => '',
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function preDelete(EntityStorageControllerInterface $storage_controller, array $entities) {
    foreach ($entities as $entity) {
      // Notify processors to remove stored items.
      $manager = \Drupal::service('plugin.manager.aggregator.processor');
      foreach ($manager->getDefinitions() as $id => $definition) {
        $manager->createInstance($id)->remove($entity);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageControllerInterface $storage_controller, array $entities) {
    if (\Drupal::moduleHandler()->moduleExists('block')) {
      // Make sure there are no active blocks for these feeds.
      $ids = \Drupal::entityQuery('block')
        ->condition('plugin', 'aggregator_feed_block')
        ->condition('settings.feed', array_keys($entities))
        ->execute();
      if ($ids) {
        $block_storage = \Drupal::entityManager()->getStorageController('block');
        $block_storage->delete($block_storage->loadMultiple($ids));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions($entity_type) {
    $fields['fid'] = FieldDefinition::create('integer')
      ->setLabel(t('Feed ID'))
      ->setDescription(t('The ID of the aggregator feed.'))
      ->setReadOnly(TRUE);

    // @todo Add a UUID field for this entity type in
    // https://drupal.org/node/2149841.

    $fields['title'] = FieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setDescription(t('The title of the feed.'));

    $fields['langcode'] = FieldDefinition::create('language')
      ->setLabel(t('Language code'))
      ->setDescription(t('The feed language code.'));

    $fields['url'] = FieldDefinition::create('uri')
      ->setLabel(t('URL'))
      ->setDescription(t('The URL to the feed.'));

    $fields['refresh'] = FieldDefinition::create('integer')
      ->setLabel(t('Refresh'))
      ->setDescription(t('How often to check for new feed items, in seconds.'));

    // @todo Convert to a "timestamp" field in https://drupal.org/node/2145103.
    $fields['checked'] = FieldDefinition::create('integer')
      ->setLabel(t('Checked'))
      ->setDescription(t('Last time feed was checked for new items, as Unix timestamp.'));

    // @todo Convert to a "timestamp" field in https://drupal.org/node/2145103.
    $fields['queued'] = FieldDefinition::create('integer')
      ->setLabel(t('Queued'))
      ->setDescription(t('Time when this feed was queued for refresh, 0 if not queued.'));

    $fields['link'] = FieldDefinition::create('uri')
      ->setLabel(t('Link'))
      ->setDescription(t('The link of the feed.'));

    $fields['description'] = FieldDefinition::create('string')
      ->setLabel(t('Description'))
      ->setDescription(t("The parent website's description that comes from the !description element in the feed.", array('!description' => '<description>')));

    $fields['image'] = FieldDefinition::create('uri')
      ->setLabel(t('Image'))
      ->setDescription(t('An image representing the feed.'));

    $fields['hash'] = FieldDefinition::create('string')
      ->setLabel(t('Hash'))
      ->setDescription(t('Calculated hash of the feed data, used for validating cache.'));

    $fields['etag'] = FieldDefinition::create('string')
      ->setLabel(t('Etag'))
      ->setDescription(t('Entity tag HTTP response header, used for validating cache.'));

    // @todo Convert to a "changed" field in https://drupal.org/node/2145103.
    $fields['modified'] = FieldDefinition::create('integer')
      ->setLabel(t('Modified'))
      ->setDescription(t('When the feed was last modified, as a Unix timestamp.'));

    return $fields;
  }

}
