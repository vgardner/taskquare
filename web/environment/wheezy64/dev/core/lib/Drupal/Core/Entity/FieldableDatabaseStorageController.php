<?php

/**
 * @file
 * Contains \Drupal\Core\Entity\DatabaseStorageController.
 */

namespace Drupal\Core\Entity;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Language\Language;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Uuid\Uuid;
use Drupal\field\FieldInfo;
use Drupal\field\FieldUpdateForbiddenException;
use Drupal\field\FieldInterface;
use Drupal\field\FieldInstanceInterface;
use Drupal\field\Entity\Field;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a base entity controller class.
 *
 * Default implementation of Drupal\Core\Entity\EntityStorageControllerInterface.
 *
 * This class can be used as-is by most simple entity types. Entity types
 * requiring special handling can extend the class.
 */
class FieldableDatabaseStorageController extends FieldableEntityStorageControllerBase {

  /**
   * Name of entity's revision database table field, if it supports revisions.
   *
   * Has the value FALSE if this entity does not use revisions.
   *
   * @var string
   */
  protected $revisionKey = FALSE;

  /**
   * The table that stores revisions, if the entity supports revisions.
   *
   * @var string
   */
  protected $revisionTable;

  /**
   * The table that stores properties, if the entity has multilingual support.
   *
   * @var string
   */
  protected $dataTable;

  /**
   * The table that stores revision field data if the entity supports revisions.
   *
   * @var string
   */
  protected $revisionDataTable;

  /**
   * Whether this entity type should use the static cache.
   *
   * Set by entity info.
   *
   * @var boolean
   */
  protected $cache;

  /**
   * Active database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The field info object.
   *
   * @var \Drupal\field\FieldInfo
   */
  protected $fieldInfo;

  /**
   * The entity bundle key.
   *
   * @var string|bool
   */
  protected $bundleKey = FALSE;

  /**
   * Name of the entity class.
   *
   * @var string
   */
  protected $entityClass;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, $entity_type, array $entity_info) {
    return new static(
      $entity_type,
      $entity_info,
      $container->get('database'),
      $container->get('field.info')
    );
  }

  /**
   * Constructs a DatabaseStorageController object.
   *
   * @param string $entity_type
   *   The entity type for which the instance is created.
   * @param array $entity_info
   *   An array of entity info for the entity type.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection to be used.
   * @param \Drupal\field\FieldInfo $field_info
   *   The field info service.
   */
  public function __construct($entity_type, array $entity_info, Connection $database, FieldInfo $field_info) {
    parent::__construct($entity_type, $entity_info);

    $this->database = $database;
    $this->fieldInfo = $field_info;
    $this->bundleKey = !empty($this->entityInfo['entity_keys']['bundle']) ? $this->entityInfo['entity_keys']['bundle'] : FALSE;
    $this->entityClass = $this->entityInfo['class'];

    // Check if the entity type supports IDs.
    if (isset($this->entityInfo['entity_keys']['id'])) {
      $this->idKey = $this->entityInfo['entity_keys']['id'];
    }

    // Check if the entity type supports UUIDs.
    if (!empty($this->entityInfo['entity_keys']['uuid'])) {
      $this->uuidKey = $this->entityInfo['entity_keys']['uuid'];
    }
    else {
      $this->uuidKey = FALSE;
    }

    // Check if the entity type supports revisions.
    if (!empty($this->entityInfo['entity_keys']['revision'])) {
      $this->revisionKey = $this->entityInfo['entity_keys']['revision'];
      $this->revisionTable = $this->entityInfo['revision_table'];
    }

    // Check if the entity type has a dedicated table for fields.
    if (!empty($this->entityInfo['data_table'])) {
      $this->dataTable = $this->entityInfo['data_table'];
      // Entity types having both revision and translation support should always
      // define a revision data table.
      if ($this->revisionTable && !empty($this->entityInfo['revision_data_table'])) {
        $this->revisionDataTable = $this->entityInfo['revision_data_table'];
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function create(array $values) {
    $entity_class = $this->entityClass;
    $entity_class::preCreate($this, $values);

    // We have to determine the bundle first.
    $bundle = FALSE;
    if ($this->bundleKey) {
      if (!isset($values[$this->bundleKey])) {
        throw new EntityStorageException(format_string('Missing bundle for entity type @type', array('@type' => $this->entityType)));
      }
      $bundle = $values[$this->bundleKey];
    }
    $entity = new $entity_class(array(), $this->entityType, $bundle);

    foreach ($entity as $name => $field) {
      if (isset($values[$name])) {
        $entity->$name = $values[$name];
      }
      elseif (!array_key_exists($name, $values)) {
        $entity->get($name)->applyDefaultValue();
      }
      unset($values[$name]);
    }

    // Set any passed values for non-defined fields also.
    foreach ($values as $name => $value) {
      $entity->$name = $value;
    }
    $entity->postCreate($this);

    // Modules might need to add or change the data initially held by the new
    // entity object, for instance to fill-in default values.
    $this->invokeHook('create', $entity);

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function loadMultiple(array $ids = NULL) {
    $entities = array();

    // Create a new variable which is either a prepared version of the $ids
    // array for later comparison with the entity cache, or FALSE if no $ids
    // were passed. The $ids array is reduced as items are loaded from cache,
    // and we need to know if it's empty for this reason to avoid querying the
    // database when all requested entities are loaded from cache.
    $passed_ids = !empty($ids) ? array_flip($ids) : FALSE;
    // Try to load entities from the static cache, if the entity type supports
    // static caching.
    if ($this->cache && $ids) {
      $entities += $this->cacheGet($ids);
      // If any entities were loaded, remove them from the ids still to load.
      if ($passed_ids) {
        $ids = array_keys(array_diff_key($passed_ids, $entities));
      }
    }

    // Load any remaining entities from the database. This is the case if $ids
    // is set to NULL (so we load all entities) or if there are any ids left to
    // load.
    if ($ids === NULL || $ids) {
      // Build and execute the query.
      $query_result = $this->buildQuery($ids)->execute();
      $queried_entities = $query_result->fetchAllAssoc($this->idKey);
    }

    // Pass all entities loaded from the database through $this->postLoad(),
    // which attaches fields (if supported by the entity type) and calls the
    // entity type specific load callback, for example hook_node_load().
    if (!empty($queried_entities)) {
      $this->postLoad($queried_entities);
      $entities += $queried_entities;
    }

    if ($this->cache) {
      // Add entities to the cache.
      if (!empty($queried_entities)) {
        $this->cacheSet($queried_entities);
      }
    }

    // Ensure that the returned array is ordered the same as the original
    // $ids array if this was passed in and remove any invalid ids.
    if ($passed_ids) {
      // Remove any invalid ids from the array.
      $passed_ids = array_intersect_key($passed_ids, $entities);
      foreach ($entities as $entity) {
        $passed_ids[$entity->id()] = $entity;
      }
      $entities = $passed_ids;
    }

    return $entities;
  }

  /**
   * {@inheritdoc}
   */
  public function load($id) {
    $entities = $this->loadMultiple(array($id));
    return isset($entities[$id]) ? $entities[$id] : NULL;
  }

  /**
   * Maps from storage records to entity objects.
   *
   * @param array $records
   *   Associative array of query results, keyed on the entity ID.
   *
   * @return array
   *   An array of entity objects implementing the EntityInterface.
   */
  protected function mapFromStorageRecords(array $records) {
    $entities = array();
    foreach ($records as $id => $record) {
      $entities[$id] = array();
      // Skip the item delta and item value levels (if possible) but let the
      // field assign the value as suiting. This avoids unnecessary array
      // hierarchies and saves memory here.
      foreach ($record as $name => $value) {
        // Handle columns named [field_name]__[column_name] (e.g for field types
        // that store several properties).
        if ($field_name = strstr($name, '__', TRUE)) {
          $property_name = substr($name, strpos($name, '__') + 2);
          $entities[$id][$field_name][Language::LANGCODE_DEFAULT][$property_name] = $value;
        }
        else {
          // Handle columns named directly after the field (e.g if the field
          // type only stores one property).
          $entities[$id][$name][Language::LANGCODE_DEFAULT] = $value;
        }
      }
      // If we have no multilingual values we can instantiate entity objecs
      // right now, otherwise we need to collect all the field values first.
      if (!$this->dataTable) {
        $bundle = $this->bundleKey ? $record->{$this->bundleKey} : FALSE;
        // Turn the record into an entity class.
        $entities[$id] = new $this->entityClass($entities[$id], $this->entityType, $bundle);
      }
    }
    $this->attachPropertyData($entities);
    return $entities;
  }

  /**
   * Attaches property data in all languages for translatable properties.
   *
   * @param array &$entities
   *   Associative array of entities, keyed on the entity ID.
   */
  protected function attachPropertyData(array &$entities) {
    if ($this->dataTable) {
      // If a revision table is available, we need all the properties of the
      // latest revision. Otherwise we fall back to the data table.
      $table = $this->revisionDataTable ?: $this->dataTable;
      $query = $this->database->select($table, 'data', array('fetch' => \PDO::FETCH_ASSOC))
        ->fields('data')
        ->condition($this->idKey, array_keys($entities))
        ->orderBy('data.' . $this->idKey);

      if ($this->revisionDataTable) {
        // Get the revision IDs.
        $revision_ids = array();
        foreach ($entities as $values) {
          $revision_ids[] = is_object($values) ? $values->getRevisionId() : $values[$this->revisionKey][Language::LANGCODE_DEFAULT];
        }
        $query->condition($this->revisionKey, $revision_ids);
      }

      $data = $query->execute();
      $field_definitions = \Drupal::entityManager()->getFieldDefinitions($this->entityType);
      $translations = array();
      if ($this->revisionDataTable) {
        $data_column_names = array_flip(array_diff(drupal_schema_fields_sql($this->entityInfo['revision_data_table']), drupal_schema_fields_sql($this->entityInfo['base_table'])));
      }
      else {
        $data_column_names = array_flip(drupal_schema_fields_sql($this->entityInfo['data_table']));
      }

      foreach ($data as $values) {
        $id = $values[$this->idKey];

        // Field values in default language are stored with
        // Language::LANGCODE_DEFAULT as key.
        $langcode = empty($values['default_langcode']) ? $values['langcode'] : Language::LANGCODE_DEFAULT;
        $translations[$id][$langcode] = TRUE;

        foreach (array_keys($field_definitions) as $field_name) {
          // Handle columns named directly after the field.
          if (isset($data_column_names[$field_name])) {
            $entities[$id][$field_name][$langcode] = $values[$field_name];
          }
          else {
            // @todo Change this logic to be based on a mapping of field
            // definition properties (translatability, revisionability) in
            // https://drupal.org/node/2144631.
            foreach ($data_column_names as $data_column_name) {
              // Handle columns named [field_name]__[column_name], for which we
              // need to look through all column names from the table that start
              // with the name of the field.
              if (($data_field_name = strstr($data_column_name, '__', TRUE)) && $data_field_name === $field_name) {
                $property_name = substr($data_column_name, strpos($data_column_name, '__') + 2);
                $entities[$id][$field_name][$langcode][$property_name] = $values[$data_column_name];
              }
            }
          }
        }
      }

      foreach ($entities as $id => $values) {
        $bundle = $this->bundleKey ? $values[$this->bundleKey][Language::LANGCODE_DEFAULT] : FALSE;
        // Turn the record into an entity class.
        $entities[$id] = new $this->entityClass($values, $this->entityType, $bundle, array_keys($translations[$id]));
      }
    }
  }

  /**
   * Implements \Drupal\Core\Entity\EntityStorageControllerInterface::loadRevision().
   */
  public function loadRevision($revision_id) {
    // Build and execute the query.
    $query_result = $this->buildQuery(array(), $revision_id)->execute();
    $queried_entities = $query_result->fetchAllAssoc($this->idKey);

    // Pass the loaded entities from the database through $this->postLoad(),
    // which attaches fields (if supported by the entity type) and calls the
    // entity type specific load callback, for example hook_node_load().
    if (!empty($queried_entities)) {
      $this->postLoad($queried_entities);
    }
    return reset($queried_entities);
  }

  /**
   * Implements \Drupal\Core\Entity\EntityStorageControllerInterface::deleteRevision().
   */
  public function deleteRevision($revision_id) {
    if ($revision = $this->loadRevision($revision_id)) {
      // Prevent deletion if this is the default revision.
      if ($revision->isDefaultRevision()) {
        throw new EntityStorageException('Default revision can not be deleted');
      }

      $this->database->delete($this->revisionTable)
        ->condition($this->revisionKey, $revision->getRevisionId())
        ->execute();
      $this->invokeFieldMethod('deleteRevision', $revision);
      $this->deleteFieldItemsRevision($revision);
      $this->invokeHook('revision_delete', $revision);
    }
  }

  /**
   * Implements \Drupal\Core\Entity\EntityStorageControllerInterface::loadByProperties().
   */
  public function loadByProperties(array $values = array()) {
    // Build a query to fetch the entity IDs.
    $entity_query = \Drupal::entityQuery($this->entityType);
    $this->buildPropertyQuery($entity_query, $values);
    $result = $entity_query->execute();
    return $result ? $this->loadMultiple($result) : array();
  }

  /**
   * Builds an entity query.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $entity_query
   *   EntityQuery instance.
   * @param array $values
   *   An associative array of properties of the entity, where the keys are the
   *   property names and the values are the values those properties must have.
   */
  protected function buildPropertyQuery(QueryInterface $entity_query, array $values) {
    if ($this->dataTable) {
      // @todo We should not be using a condition to specify whether conditions
      //   apply to the default language. See http://drupal.org/node/1866330.
      // Default to the original entity language if not explicitly specified
      // otherwise.
      if (!array_key_exists('default_langcode', $values)) {
        $values['default_langcode'] = 1;
      }
      // If the 'default_langcode' flag is explicitly not set, we do not care
      // whether the queried values are in the original entity language or not.
      elseif ($values['default_langcode'] === NULL) {
        unset($values['default_langcode']);
      }
    }

    foreach ($values as $name => $value) {
      $entity_query->condition($name, $value);
    }
  }

  /**
   * Builds the query to load the entity.
   *
   * This has full revision support. For entities requiring special queries,
   * the class can be extended, and the default query can be constructed by
   * calling parent::buildQuery(). This is usually necessary when the object
   * being loaded needs to be augmented with additional data from another
   * table, such as loading node type into comments or vocabulary machine name
   * into terms, however it can also support $conditions on different tables.
   * See Drupal\comment\CommentStorageController::buildQuery() for an example.
   *
   * @param array|null $ids
   *   An array of entity IDs, or NULL to load all entities.
   * @param $revision_id
   *   The ID of the revision to load, or FALSE if this query is asking for the
   *   most current revision(s).
   *
   * @return SelectQuery
   *   A SelectQuery object for loading the entity.
   */
  protected function buildQuery($ids, $revision_id = FALSE) {
    $query = $this->database->select($this->entityInfo['base_table'], 'base');

    $query->addTag($this->entityType . '_load_multiple');

    if ($revision_id) {
      $query->join($this->revisionTable, 'revision', "revision.{$this->idKey} = base.{$this->idKey} AND revision.{$this->revisionKey} = :revisionId", array(':revisionId' => $revision_id));
    }
    elseif ($this->revisionTable) {
      $query->join($this->revisionTable, 'revision', "revision.{$this->revisionKey} = base.{$this->revisionKey}");
    }

    // Add fields from the {entity} table.
    $entity_fields = drupal_schema_fields_sql($this->entityInfo['base_table']);

    if ($this->revisionTable) {
      // Add all fields from the {entity_revision} table.
      $entity_revision_fields = drupal_map_assoc(drupal_schema_fields_sql($this->entityInfo['revision_table']));
      // The ID field is provided by entity, so remove it.
      unset($entity_revision_fields[$this->idKey]);

      // Remove all fields from the base table that are also fields by the same
      // name in the revision table.
      $entity_field_keys = array_flip($entity_fields);
      foreach ($entity_revision_fields as $name) {
        if (isset($entity_field_keys[$name])) {
          unset($entity_fields[$entity_field_keys[$name]]);
        }
      }
      $query->fields('revision', $entity_revision_fields);

      // Compare revision ID of the base and revision table, if equal then this
      // is the default revision.
      $query->addExpression('base.' . $this->revisionKey . ' = revision.' . $this->revisionKey, 'isDefaultRevision');
    }

    $query->fields('base', $entity_fields);

    if ($ids) {
      $query->condition("base.{$this->idKey}", $ids, 'IN');
    }

    return $query;
  }

  /**
   * Attaches data to entities upon loading.
   *
   * This will attach fields, if the entity is fieldable. It calls
   * hook_entity_load() for modules which need to add data to all entities.
   * It also calls hook_TYPE_load() on the loaded entities. For example
   * hook_node_load() or hook_user_load(). If your hook_TYPE_load()
   * expects special parameters apart from the queried entities, you can set
   * $this->hookLoadArguments prior to calling the method.
   * See Drupal\node\NodeStorageController::attachLoad() for an example.
   *
   * @param $queried_entities
   *   Associative array of query results, keyed on the entity ID.
   */
  protected function postLoad(array &$queried_entities) {
    // Map the loaded records into entity objects and according fields.
    $queried_entities = $this->mapFromStorageRecords($queried_entities);

    // Attach field values.
    if ($this->entityInfo['fieldable']) {
      $this->loadFieldItems($queried_entities);
    }

    parent::postLoad($queried_entities);
  }

  /**
   * Implements \Drupal\Core\Entity\EntityStorageControllerInterface::delete().
   */
  public function delete(array $entities) {
    if (!$entities) {
      // If no IDs or invalid IDs were passed, do nothing.
      return;
    }

    $transaction = $this->database->startTransaction();
    try {
      $entity_class = $this->entityClass;
      $entity_class::preDelete($this, $entities);

      foreach ($entities as $entity) {
        $this->invokeHook('predelete', $entity);
      }
      $ids = array_keys($entities);

      $this->database->delete($this->entityInfo['base_table'])
        ->condition($this->idKey, $ids)
        ->execute();

      if ($this->revisionTable) {
        $this->database->delete($this->revisionTable)
          ->condition($this->idKey, $ids)
          ->execute();
      }

      if ($this->dataTable) {
        $this->database->delete($this->dataTable)
          ->condition($this->idKey, $ids)
          ->execute();
      }

      if ($this->revisionDataTable) {
        $this->database->delete($this->revisionDataTable)
          ->condition($this->idKey, $ids)
          ->execute();
      }

      foreach ($entities as $entity) {
        $this->invokeFieldMethod('delete', $entity);
        $this->deleteFieldItems($entity);
      }

      // Reset the cache as soon as the changes have been applied.
      $this->resetCache($ids);

      $entity_class::postDelete($this, $entities);
      foreach ($entities as $entity) {
        $this->invokeHook('delete', $entity);
      }
      // Ignore slave server temporarily.
      db_ignore_slave();
    }
    catch (\Exception $e) {
      $transaction->rollback();
      watchdog_exception($this->entityType, $e);
      throw new EntityStorageException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(EntityInterface $entity) {
    $transaction = $this->database->startTransaction();
    try {
      // Sync the changes made in the fields array to the internal values array.
      $entity->updateOriginalValues();

      // Load the stored entity, if any.
      if (!$entity->isNew() && !isset($entity->original)) {
        $entity->original = entity_load_unchanged($this->entityType, $entity->id());
      }

      $entity->preSave($this);
      $this->invokeFieldMethod('preSave', $entity);
      $this->invokeHook('presave', $entity);

      // Create the storage record to be saved.
      $record = $this->mapToStorageRecord($entity);

      if (!$entity->isNew()) {
        if ($entity->isDefaultRevision()) {
          $return = drupal_write_record($this->entityInfo['base_table'], $record, $this->idKey);
        }
        else {
          // @todo, should a different value be returned when saving an entity
          // with $isDefaultRevision = FALSE?
          $return = FALSE;
        }
        if ($this->revisionTable) {
          $record->{$this->revisionKey} = $this->saveRevision($entity);
        }
        if ($this->dataTable) {
          $this->savePropertyData($entity);
        }
        if ($this->revisionDataTable) {
          $this->savePropertyData($entity, 'revision_data_table');
        }
        $entity->setNewRevision(FALSE);
        $this->invokeFieldMethod('update', $entity);
        $this->saveFieldItems($entity, TRUE);
        $this->resetCache(array($entity->id()));
        $entity->postSave($this, TRUE);
        $this->invokeHook('update', $entity);
        if ($this->dataTable) {
          $this->invokeTranslationHooks($entity);
        }
      }
      else {
        // Ensure the entity is still seen as new after assigning it an id,
        // while storing its data.
        $entity->enforceIsNew();
        $return = drupal_write_record($this->entityInfo['base_table'], $record);
        $entity->{$this->idKey}->value = (string) $record->{$this->idKey};
        if ($this->revisionTable) {
          $entity->setNewRevision();
          $record->{$this->revisionKey} = $this->saveRevision($entity);
        }
        if ($this->dataTable) {
          $this->savePropertyData($entity);
        }
        if ($this->revisionDataTable) {
          $this->savePropertyData($entity, 'revision_data_table');
        }


        $entity->enforceIsNew(FALSE);
        $this->invokeFieldMethod('insert', $entity);
        $this->saveFieldItems($entity, FALSE);
        // Reset general caches, but keep caches specific to certain entities.
        $this->resetCache(array());
        $entity->postSave($this, FALSE);
        $this->invokeHook('insert', $entity);
      }

      // Ignore slave server temporarily.
      db_ignore_slave();
      unset($entity->original);

      return $return;
    }
    catch (\Exception $e) {
      $transaction->rollback();
      watchdog_exception($this->entityType, $e);
      throw new EntityStorageException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Stores the entity property language-aware data.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   * @param string $table_key
   *   (optional) The entity key identifying the target table. Defaults to
   *   'data_table'.
   */
  protected function savePropertyData(EntityInterface $entity, $table_key = 'data_table') {
    $table_name = $this->entityInfo[$table_key];
    $revision = $table_key != 'data_table';

    if (!$revision || !$entity->isNewRevision()) {
      $key = $revision ? $this->revisionKey : $this->idKey;
      $value = $revision ? $entity->getRevisionId() : $entity->id();
      // Delete and insert to handle removed values.
      $this->database->delete($table_name)
        ->condition($key, $value)
        ->execute();
    }

    $query = $this->database->insert($table_name);

    foreach ($entity->getTranslationLanguages() as $langcode => $language) {
      $translation = $entity->getTranslation($langcode);
      $record = $this->mapToDataStorageRecord($translation, $table_key);
      $values = (array) $record;
      $query
        ->fields(array_keys($values))
        ->values($values);
    }

    $query->execute();
  }

  /**
   * Maps from an entity object to the storage record.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   * @param string $table_key
   *   (optional) The entity key identifying the target table. Defaults to
   *   'base_table'.
   *
   * @return \stdClass
   *   The record to store.
   */
  protected function mapToStorageRecord(EntityInterface $entity, $table_key = 'base_table') {
    $record = new \stdClass();
    $values = array();
    $definitions = $entity->getPropertyDefinitions();
    $schema = drupal_get_schema($this->entityInfo[$table_key]);
    $is_new = $entity->isNew();

    $multi_column_fields = array();
    foreach (drupal_schema_fields_sql($this->entityInfo[$table_key]) as $name) {
      // Check for fields which store data in multiple columns and process them
      // separately.
      if ($field = strstr($name, '__', TRUE)) {
        $multi_column_fields[$field] = TRUE;
        continue;
      }
      $values[$name] = isset($definitions[$name]) && isset($entity->$name->value) ? $entity->$name->value : NULL;
    }

    // Handle fields that store multiple properties and match each property name
    // to its schema column name.
    foreach (array_keys($multi_column_fields) as $field_name) {
      $field_items = $entity->get($field_name);
      $field_value = $field_items->getValue();
      // @todo Reconsider the usage of getPropertyDefinitions() after
      // https://drupal.org/node/2144327.
      foreach (array_keys($field_items[0]->getPropertyDefinitions()) as $property_name) {
        if (isset($schema['fields'][$field_name . '__' . $property_name])) {
          $values[$field_name . '__' . $property_name] = isset($field_value[0][$property_name]) ? $field_value[0][$property_name] : NULL;
        }
      }
    }

    foreach ($values as $field_name => $value) {
      // If we are creating a new entity, we must not populate the record with
      // NULL values otherwise defaults would not be applied.
      if (isset($value) || !$is_new) {
        $record->$field_name = drupal_schema_get_field_value($schema['fields'][$field_name], $value);
      }
    }

    return $record;
  }

  /**
   * Maps from an entity object to the storage record of the field data.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   * @param string $table_key
   *   (optional) The entity key identifying the target table. Defaults to
   *   'data_table'.
   *
   * @return \stdClass
   *   The record to store.
   */
  protected function mapToDataStorageRecord(EntityInterface $entity, $table_key = 'data_table') {
    $record = $this->mapToStorageRecord($entity, $table_key);
    $record->langcode = $entity->language()->id;
    $record->default_langcode = intval($record->langcode == $entity->getUntranslated()->language()->id);
    return $record;
  }

  /**
   * Saves an entity revision.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   *
   * @return int
   *   The revision id.
   */
  protected function saveRevision(EntityInterface $entity) {
    $record = $this->mapToStorageRecord($entity, 'revision_table');

    // When saving a new revision, set any existing revision ID to NULL so as to
    // ensure that a new revision will actually be created.
    if ($entity->isNewRevision() && isset($record->{$this->revisionKey})) {
      $record->{$this->revisionKey} = NULL;
    }

    $entity->preSaveRevision($this, $record);

    if ($entity->isNewRevision()) {
      drupal_write_record($this->revisionTable, $record);
      if ($entity->isDefaultRevision()) {
        $this->database->update($this->entityInfo['base_table'])
          ->fields(array($this->revisionKey => $record->{$this->revisionKey}))
          ->condition($this->idKey, $record->{$this->idKey})
          ->execute();
      }
    }
    else {
      drupal_write_record($this->revisionTable, $record, $this->revisionKey);
    }

    // Make sure to update the new revision key for the entity.
    $entity->{$this->revisionKey}->value = $record->{$this->revisionKey};

    return $record->{$this->revisionKey};
  }

  /**
   * {@inheritdoc}
   */
  public function getQueryServiceName() {
    return 'entity.query.sql';
  }

  /**
   * {@inheritdoc}
   */
  protected function doLoadFieldItems($entities, $age) {
    $load_current = $age == static::FIELD_LOAD_CURRENT;

    // Collect entities ids, bundles and languages.
    $bundles = array();
    $ids = array();
    $default_langcodes = array();
    foreach ($entities as $key => $entity) {
      $bundles[$entity->bundle()] = TRUE;
      $ids[] = $load_current ? $key : $entity->getRevisionId();
      $default_langcodes[$key] = $entity->getUntranslated()->language()->id;
    }

    // Collect impacted fields.
    $fields = array();
    foreach ($bundles as $bundle => $v) {
      foreach ($this->fieldInfo->getBundleInstances($this->entityType, $bundle) as $field_name => $instance) {
        $fields[$field_name] = $instance->getField();
      }
    }

    // Load field data.
    $langcodes = array_keys(language_list(Language::STATE_ALL));
    foreach ($fields as $field_name => $field) {
      $table = $load_current ? static::_fieldTableName($field) : static::_fieldRevisionTableName($field);

      // Ensure that only values having valid languages are retrieved. Since we
      // are loading values for multiple entities, we cannot limit the query to
      // the available translations.
      $results = $this->database->select($table, 't')
        ->fields('t')
        ->condition($load_current ? 'entity_id' : 'revision_id', $ids, 'IN')
        ->condition('deleted', 0)
        ->condition('langcode', $langcodes, 'IN')
        ->orderBy('delta')
        ->execute();

      $delta_count = array();
      foreach ($results as $row) {

        // Ensure that records for non-translatable fields having invalid
        // languages are skipped.
        if ($row->langcode == $default_langcodes[$row->entity_id] || $field->isTranslatable()) {
          if (!isset($delta_count[$row->entity_id][$row->langcode])) {
            $delta_count[$row->entity_id][$row->langcode] = 0;
          }

          if ($field->getCardinality() == FieldInterface::CARDINALITY_UNLIMITED || $delta_count[$row->entity_id][$row->langcode] < $field->getCardinality()) {
            $item = array();
            // For each column declared by the field, populate the item from the
            // prefixed database column.
            foreach ($field->getColumns() as $column => $attributes) {
              $column_name = static::_fieldColumnName($field, $column);
              // Unserialize the value if specified in the column schema.
              $item[$column] = (!empty($attributes['serialize'])) ? unserialize($row->$column_name) : $row->$column_name;
            }

            // Add the item to the field values for the entity.
            $entities[$row->entity_id]->getTranslation($row->langcode)->{$field_name}[$delta_count[$row->entity_id][$row->langcode]] = $item;
            $delta_count[$row->entity_id][$row->langcode]++;
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function doSaveFieldItems(EntityInterface $entity, $update) {
    $vid = $entity->getRevisionId();
    $id = $entity->id();
    $bundle = $entity->bundle();
    $entity_type = $entity->entityType();
    $default_langcode = $entity->getUntranslated()->language()->id;
    $translation_langcodes = array_keys($entity->getTranslationLanguages());

    if (!isset($vid)) {
      $vid = $id;
    }

    foreach ($this->fieldInfo->getBundleInstances($entity_type, $bundle) as $field_name => $instance) {
      $field = $instance->getField();
      $table_name = static::_fieldTableName($field);
      $revision_name = static::_fieldRevisionTableName($field);

      // Delete and insert, rather than update, in case a value was added.
      if ($update) {
        // Only overwrite the field's base table if saving the default revision
        // of an entity.
        if ($entity->isDefaultRevision()) {
          $this->database->delete($table_name)
            ->condition('entity_id', $id)
            ->execute();
        }
        $this->database->delete($revision_name)
          ->condition('entity_id', $id)
          ->condition('revision_id', $vid)
          ->execute();
      }

      // Prepare the multi-insert query.
      $do_insert = FALSE;
      $columns = array('entity_id', 'revision_id', 'bundle', 'delta', 'langcode');
      foreach ($field->getColumns() as $column => $attributes) {
        $columns[] = static::_fieldColumnName($field, $column);
      }
      $query = $this->database->insert($table_name)->fields($columns);
      $revision_query = $this->database->insert($revision_name)->fields($columns);

      $langcodes = $field->isTranslatable() ? $translation_langcodes : array($default_langcode);
      foreach ($langcodes as $langcode) {
        $delta_count = 0;
        $items = $entity->getTranslation($langcode)->get($field_name);
        $items->filterEmptyValues();
        foreach ($items as $delta => $item) {
          // We now know we have someting to insert.
          $do_insert = TRUE;
          $record = array(
            'entity_id' => $id,
            'revision_id' => $vid,
            'bundle' => $bundle,
            'delta' => $delta,
            'langcode' => $langcode,
          );
          foreach ($field->getColumns() as $column => $attributes) {
            $column_name = static::_fieldColumnName($field, $column);
            // Serialize the value if specified in the column schema.
            $record[$column_name] = !empty($attributes['serialize']) ? serialize($item->$column) : $item->$column;
          }
          $query->values($record);
          $revision_query->values($record);

          if ($field->getCardinality() != FieldInterface::CARDINALITY_UNLIMITED && ++$delta_count == $field->getCardinality()) {
            break;
          }
        }
      }

      // Execute the query if we have values to insert.
      if ($do_insert) {
        // Only overwrite the field's base table if saving the default revision
        // of an entity.
        if ($entity->isDefaultRevision()) {
          $query->execute();
        }
        $revision_query->execute();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function doDeleteFieldItems(EntityInterface $entity) {
    foreach ($this->fieldInfo->getBundleInstances($entity->entityType(), $entity->bundle()) as $instance) {
      $field = $instance->getField();
      $table_name = static::_fieldTableName($field);
      $revision_name = static::_fieldRevisionTableName($field);
      $this->database->delete($table_name)
        ->condition('entity_id', $entity->id())
        ->execute();
      $this->database->delete($revision_name)
        ->condition('entity_id', $entity->id())
        ->execute();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function doDeleteFieldItemsRevision(EntityInterface $entity) {
    $vid = $entity->getRevisionId();
    if (isset($vid)) {
      foreach ($this->fieldInfo->getBundleInstances($entity->entityType(), $entity->bundle()) as $instance) {
        $revision_name = static::_fieldRevisionTableName($instance->getField());
        $this->database->delete($revision_name)
          ->condition('entity_id', $entity->id())
          ->condition('revision_id', $vid)
          ->execute();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onFieldCreate(FieldInterface $field) {
    $schema = $this->_fieldSqlSchema($field);
    foreach ($schema as $name => $table) {
      $this->database->schema()->createTable($name, $table);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onFieldUpdate(FieldInterface $field) {
    $original = $field->original;

    if (!$field->hasData()) {
      // There is no data. Re-create the tables completely.

      if ($this->database->supportsTransactionalDDL()) {
        // If the database supports transactional DDL, we can go ahead and rely
        // on it. If not, we will have to rollback manually if something fails.
        $transaction = $this->database->startTransaction();
      }

      try {
        $original_schema = $this->_fieldSqlSchema($original);
        foreach ($original_schema as $name => $table) {
          $this->database->schema()->dropTable($name, $table);
        }
        $schema = $this->_fieldSqlSchema($field);
        foreach ($schema as $name => $table) {
          $this->database->schema()->createTable($name, $table);
        }
      }
      catch (\Exception $e) {
        if ($this->database->supportsTransactionalDDL()) {
          $transaction->rollback();
        }
        else {
          // Recreate tables.
          $original_schema = $this->_fieldSqlSchema($original);
          foreach ($original_schema as $name => $table) {
            if (!$this->database->schema()->tableExists($name)) {
              $this->database->schema()->createTable($name, $table);
            }
          }
        }
        throw $e;
      }
    }
    else {
      if ($field->getColumns() != $original->getColumns()) {
        throw new FieldUpdateForbiddenException("The SQL storage cannot change the schema for an existing field with data.");
      }
      // There is data, so there are no column changes. Drop all the prior
      // indexes and create all the new ones, except for all the priors that
      // exist unchanged.
      $table = static::_fieldTableName($original);
      $revision_table = static::_fieldRevisionTableName($original);

      $schema = $field->getSchema();
      $original_schema = $original->getSchema();

      foreach ($original_schema['indexes'] as $name => $columns) {
        if (!isset($schema['indexes'][$name]) || $columns != $schema['indexes'][$name]) {
          $real_name = static::_fieldIndexName($field, $name);
          $this->database->schema()->dropIndex($table, $real_name);
          $this->database->schema()->dropIndex($revision_table, $real_name);
        }
      }
      $table = static::_fieldTableName($field);
      $revision_table = static::_fieldRevisionTableName($field);
      foreach ($schema['indexes'] as $name => $columns) {
        if (!isset($original_schema['indexes'][$name]) || $columns != $original_schema['indexes'][$name]) {
          $real_name = static::_fieldIndexName($field, $name);
          $real_columns = array();
          foreach ($columns as $column_name) {
            // Indexes can be specified as either a column name or an array with
            // column name and length. Allow for either case.
            if (is_array($column_name)) {
              $real_columns[] = array(
                static::_fieldColumnName($field, $column_name[0]),
                $column_name[1],
              );
            }
            else {
              $real_columns[] = static::_fieldColumnName($field, $column_name);
            }
          }
          $this->database->schema()->addIndex($table, $real_name, $real_columns);
          $this->database->schema()->addIndex($revision_table, $real_name, $real_columns);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onFieldDelete(FieldInterface $field) {
    // Mark all data associated with the field for deletion.
    $table = static::_fieldTableName($field);
    $revision_table = static::_fieldRevisionTableName($field);
    $this->database->update($table)
      ->fields(array('deleted' => 1))
      ->execute();

    // Move the table to a unique name while the table contents are being
    // deleted.
    $deleted_field = clone $field;
    $deleted_field->deleted = TRUE;
    $new_table = static::_fieldTableName($deleted_field);
    $revision_new_table = static::_fieldRevisionTableName($deleted_field);
    $this->database->schema()->renameTable($table, $new_table);
    $this->database->schema()->renameTable($revision_table, $revision_new_table);
  }

  /**
   * {@inheritdoc}
   */
  public function onInstanceDelete(FieldInstanceInterface $instance) {
    $field = $instance->getField();
    $table_name = static::_fieldTableName($field);
    $revision_name = static::_fieldRevisionTableName($field);
    $this->database->update($table_name)
      ->fields(array('deleted' => 1))
      ->condition('bundle', $instance->bundle)
      ->execute();
    $this->database->update($revision_name)
      ->fields(array('deleted' => 1))
      ->condition('bundle', $instance->bundle)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function onBundleRename($bundle, $bundle_new) {
    // We need to account for deleted fields and instances. The method runs
    // before the instance definitions are updated, so we need to fetch them
    // using the old bundle name.
    $instances = field_read_instances(array('entity_type' => $this->entityType, 'bundle' => $bundle), array('include_deleted' => TRUE));
    foreach ($instances as $instance) {
      $field = $instance->getField();
      $table_name = static::_fieldTableName($field);
      $revision_name = static::_fieldRevisionTableName($field);
      $this->database->update($table_name)
        ->fields(array('bundle' => $bundle_new))
        ->condition('bundle', $bundle)
        ->execute();
      $this->database->update($revision_name)
        ->fields(array('bundle' => $bundle_new))
        ->condition('bundle', $bundle)
        ->execute();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function readFieldItemsToPurge(EntityInterface $entity, FieldInstanceInterface $instance) {
    $field = $instance->getField();
    $table_name = static::_fieldTableName($field);
    $query = $this->database->select($table_name, 't', array('fetch' => \PDO::FETCH_ASSOC))
      ->condition('entity_id', $entity->id())
      ->orderBy('delta');
    foreach ($field->getColumns() as $column_name => $data) {
      $query->addField('t', static::_fieldColumnName($field, $column_name), $column_name);
    }
    return $query->execute()->fetchAll();
  }

  /**
   * {@inheritdoc}
   */
  public function purgeFieldItems(EntityInterface $entity, FieldInstanceInterface $instance) {
    $field = $instance->getField();
    $table_name = static::_fieldTableName($field);
    $revision_name = static::_fieldRevisionTableName($field);
    $this->database->delete($table_name)
      ->condition('entity_id', $entity->id())
      ->execute();
    $this->database->delete($revision_name)
      ->condition('entity_id', $entity->id())
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function onFieldPurge(FieldInterface $field) {
    $table_name = static::_fieldTableName($field);
    $revision_name = static::_fieldRevisionTableName($field);
    $this->database->schema()->dropTable($table_name);
    $this->database->schema()->dropTable($revision_name);
  }

  /**
   * Gets the SQL table schema.
   *
   * @private Calling this function circumvents the entity system and is
   * strongly discouraged. This function is not considered part of the public
   * API and modules relying on it might break even in minor releases.
   *
   * @param \Drupal\field\FieldInterface $field
   *   The field object
   * @param array $schema
   *   The field schema array. Mandatory for upgrades, omit otherwise.
   *
   * @return array
   *   The same as a hook_schema() implementation for the data and the
   *   revision tables.
   *
   * @see hook_schema()
   */
  public static function _fieldSqlSchema(FieldInterface $field, array $schema = NULL) {
    if ($field->deleted) {
      $description_current = "Data storage for deleted field {$field->uuid()} ({$field->entity_type}, {$field->getName()}).";
      $description_revision = "Revision archive storage for deleted field {$field->uuid()} ({$field->entity_type}, {$field->getName()}).";
    }
    else {
      $description_current = "Data storage for {$field->entity_type} field {$field->getName()}.";
      $description_revision = "Revision archive storage for {$field->entity_type} field {$field->getName()}.";
    }

    $current = array(
      'description' => $description_current,
      'fields' => array(
        'bundle' => array(
          'type' => 'varchar',
          'length' => 128,
          'not null' => TRUE,
          'default' => '',
          'description' => 'The field instance bundle to which this row belongs, used when deleting a field instance',
        ),
        'deleted' => array(
          'type' => 'int',
          'size' => 'tiny',
          'not null' => TRUE,
          'default' => 0,
          'description' => 'A boolean indicating whether this data item has been deleted'
        ),
        'entity_id' => array(
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'description' => 'The entity id this data is attached to',
        ),
        'revision_id' => array(
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => FALSE,
          'description' => 'The entity revision id this data is attached to, or NULL if the entity type is not versioned',
        ),
        'langcode' => array(
          'type' => 'varchar',
          'length' => 32,
          'not null' => TRUE,
          'default' => '',
          'description' => 'The language code for this data item.',
        ),
        'delta' => array(
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'description' => 'The sequence number for this data item, used for multi-value fields',
        ),
      ),
      'primary key' => array('entity_id', 'deleted', 'delta', 'langcode'),
      'indexes' => array(
        'bundle' => array('bundle'),
        'deleted' => array('deleted'),
        'entity_id' => array('entity_id'),
        'revision_id' => array('revision_id'),
        'langcode' => array('langcode'),
      ),
    );

    if (!$schema) {
      $schema = $field->getSchema();
    }

    // Add field columns.
    foreach ($schema['columns'] as $column_name => $attributes) {
      $real_name = static::_fieldColumnName($field, $column_name);
      $current['fields'][$real_name] = $attributes;
    }

    // Add indexes.
    foreach ($schema['indexes'] as $index_name => $columns) {
      $real_name = static::_fieldIndexName($field, $index_name);
      foreach ($columns as $column_name) {
        // Indexes can be specified as either a column name or an array with
        // column name and length. Allow for either case.
        if (is_array($column_name)) {
          $current['indexes'][$real_name][] = array(
            static::_fieldColumnName($field, $column_name[0]),
            $column_name[1],
          );
        }
        else {
          $current['indexes'][$real_name][] = static::_fieldColumnName($field, $column_name);
        }
      }
    }

    // Add foreign keys.
    foreach ($schema['foreign keys'] as $specifier => $specification) {
      $real_name = static::_fieldIndexName($field, $specifier);
      $current['foreign keys'][$real_name]['table'] = $specification['table'];
      foreach ($specification['columns'] as $column_name => $referenced) {
        $sql_storage_column = static::_fieldColumnName($field, $column_name);
        $current['foreign keys'][$real_name]['columns'][$sql_storage_column] = $referenced;
      }
    }

    // Construct the revision table.
    $revision = $current;
    $revision['description'] = $description_revision;
    $revision['primary key'] = array('entity_id', 'revision_id', 'deleted', 'delta', 'langcode');
    $revision['fields']['revision_id']['not null'] = TRUE;
    $revision['fields']['revision_id']['description'] = 'The entity revision id this data is attached to';

    return array(
      static::_fieldTableName($field) => $current,
      static::_fieldRevisionTableName($field) => $revision,
    );
  }

  /**
   * Generates a table name for a field data table.
   *
   * @private Calling this function circumvents the entity system and is
   * strongly discouraged. This function is not considered part of the public
   * API and modules relying on it might break even in minor releases. Only
   * call this function to write a query that \Drupal::entityQuery() does not
   * support. Always call entity_load() before using the data found in the
   * table.
   *
   * @param \Drupal\field\FieldInterface $field
   *   The field object.
   *
   * @return string
   *   A string containing the generated name for the database table.
   *
   */
  static public function _fieldTableName(FieldInterface $field) {
    if ($field->deleted) {
      // When a field is a deleted, the table is renamed to
      // {field_deleted_data_FIELD_UUID}. To make sure we don't end up with
      // table names longer than 64 characters, we hash the uuid and return the
      // first 10 characters so we end up with a short unique ID.
      return "field_deleted_data_" . substr(hash('sha256', $field->uuid()), 0, 10);
    }
    else {
      return static::_generateFieldTableName($field, FALSE);
    }
  }

  /**
   * Generates a table name for a field revision archive table.
   *
   * @private Calling this function circumvents the entity system and is
   * strongly discouraged. This function is not considered part of the public
   * API and modules relying on it might break even in minor releases. Only
   * call this function to write a query that \Drupal::entityQuery() does not
   * support. Always call entity_load() before using the data found in the
   * table.
   *
   * @param \Drupal\field\FieldInterface $field
   *   The field object.
   *
   * @return string
   *   A string containing the generated name for the database table.
   */
  static public function _fieldRevisionTableName(FieldInterface $field) {
    if ($field->deleted) {
      // When a field is a deleted, the table is renamed to
      // {field_deleted_revision_FIELD_UUID}. To make sure we don't end up with
      // table names longer than 64 characters, we hash the uuid and return the
      // first 10 characters so we end up with a short unique ID.
      return "field_deleted_revision_" . substr(hash('sha256', $field->uuid()), 0, 10);
    }
    else {
      return static::_generateFieldTableName($field, TRUE);
    }
  }

  /**
   * Generates a safe and unanbiguous field table name.
   *
   * The method accounts for a maximum table name length of 64 characters, and
   * takes care of disambiguation.
   *
   * @param \Drupal\field\FieldInterface $field
   *   The field object.
   * @param bool $revision
   *   TRUE for revision table, FALSE otherwise.
   *
   * @return string
   *   The final table name.
   */
  static protected function _generateFieldTableName(FieldInterface $field, $revision) {
    $separator = $revision ? '_revision__' : '__';
    $table_name = $field->entity_type . $separator .  $field->name;
    // Limit the string to 48 characters, keeping a 16 characters margin for db
    // prefixes.
    if (strlen($table_name) > 48) {
      // Use a shorter separator, a truncated entity_type, and a hash of the
      // field UUID.
      $separator = $revision ? '_r__' : '__';
      // Truncate to the same length for the current and revision tables.
      $entity_type = substr($field->entity_type, 0, 34);
      $field_hash = substr(hash('sha256', $field->uuid), 0, 10);
      $table_name = $entity_type . $separator . $field_hash;
    }
    return $table_name;
  }

  /**
   * Generates an index name for a field data table.
   *
   * @private Calling this function circumvents the entity system and is
   * strongly discouraged. This function is not considered part of the public
   * API and modules relying on it might break even in minor releases.
   *
   * @param \Drupal\field\FieldInterface $field
   *   The field structure
   * @param string $index
   *   The name of the index.
   *
   * @return string
   *   A string containing a generated index name for a field data table that is
   *   unique among all other fields.
   */
  static public function _fieldIndexName(FieldInterface $field, $index) {
    return $field->getName() . '_' . $index;
  }

  /**
   * Generates a column name for a field data table.
   *
   * @private Calling this function circumvents the entity system and is
   * strongly discouraged. This function is not considered part of the public
   * API and modules relying on it might break even in minor releases. Only
   * call this function to write a query that \Drupal::entityQuery() does not
   * support. Always call entity_load() before using the data found in the
   * table.
   *
   * @param \Drupal\field\FieldInterface $field
   *   The field object.
   * @param string $column
   *   The name of the column.
   *
   * @return string
   *   A string containing a generated column name for a field data table that is
   *   unique among all other fields.
   */
  static public function _fieldColumnName(FieldInterface $field, $column) {
    return in_array($column, Field::getReservedColumns()) ? $column : $field->getName() . '_' . $column;
  }

}
