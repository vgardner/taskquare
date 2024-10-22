<?php

/**
 * @file
 * Contains \Drupal\Core\KeyValueStore\KeyValueNullExpirableFactory.
 */

namespace Drupal\Core\KeyValueStore;

/**
 * Defines the key/value store factory for the null backend.
 */
class KeyValueNullExpirableFactory implements KeyValueFactoryInterface {

  /**
   * {@inheritdoc}
   */
  public function get($collection) {
    return new NullStorageExpirable($collection);
  }
}
