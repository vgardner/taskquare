<?php

/**
 * @file
 * Contains \Drupal\Core\Config\Entity\Query\QueryFactory.
 */

namespace Drupal\Core\Config\Entity\Query;

use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Entity\Query\QueryBase;
use Drupal\Core\Entity\Query\QueryException;
use Drupal\Core\Entity\Query\QueryFactoryInterface;

/**
 * Provides a factory for creating entity query objects for the config backend.
 */
class QueryFactory implements QueryFactoryInterface {

  /**
   * The config storage used by the config entity query.
   *
   * @var \Drupal\Core\Config\StorageInterface;
   */
  protected $configStorage;

  /**
   * The namespace of this class, the parent class etc.
   *
   * @var array
   */
  protected $namespaces;

  /**
   * Constructs a QueryFactory object.
   *
   * @param \Drupal\Core\Config\StorageInterface $config_storage
   *   The config storage used by the config entity query.
   */
  public function __construct(StorageInterface $config_storage) {
    $this->configStorage = $config_storage;
    $this->namespaces = QueryBase::getNamespaces($this);
  }

  /**
   * {@inheritdoc}
   */
  public function get($entity_type, $conjunction, EntityManagerInterface $entity_manager) {
    return new Query($entity_type, $conjunction, $entity_manager, $this->configStorage, $this->namespaces);
  }

  /**
   * @inheritdoc
   */
   public function getAggregate($entity_type, $conjunction, EntityManagerInterface $entity_manager) {
      throw new QueryException('Aggregation over configuration entities is not supported');
  }

}
