<?php

/**
 * @file
 * Contains \Drupal\aggregator\CategoryStorageController.
 */

namespace Drupal\aggregator;

use Drupal\Core\Database\Connection;

/**
 * Storage controller for aggregator categories.
 */
class CategoryStorageController implements CategoryStorageControllerInterface {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * A cache of loaded categories.
   *
   * @var \stdClass[]
   */
  protected $categories;

  /**
   * Creates a new CategoryStorageController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public function load($cid) {
    if (!isset($this->categories[$cid])) {
      $this->categories[$cid] = $this->database->query("SELECT * FROM {aggregator_category} WHERE cid = :cid", array(':cid' => $cid))->fetchObject();
    }
    return $this->categories[$cid];
  }

  /**
   * {@inheritdoc}
   */
  public function save($category) {
    $cid = $this->database->insert('aggregator_category')
      ->fields(array(
        'title' => $category->title,
        'description' => $category->description,
        'block' => 5,
      ))
      ->execute();
    return $cid;
  }

  /**
   * {@inheritdoc}
   */
  public function update($category) {
    $this->database->merge('aggregator_category')
      ->key(array('cid' => $category->cid))
      ->fields(array(
        'title' => $category->title,
        'description' => $category->description,
      ))
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function delete($cid) {
    $this->database->delete('aggregator_category')
      ->condition('cid', $cid)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function isUnique($title, $cid = NULL) {
    $query = $this->database->select('aggregator_category', 'ac')
      ->fields('ac', array('title'))
      ->condition('title', $title);
    if (!empty($cid)) {
      $query->condition('cid', $cid, '<>');
    }
    $rows = $query->execute()->fetchCol();
    return (empty($rows));
  }

  /**
   * {@inheritdoc}
   */
  public function loadByItem($item_id) {
    return $this->database->query('SELECT c.cid, c.title, ci.iid FROM {aggregator_category} c LEFT JOIN {aggregator_category_item} ci ON c.cid = ci.cid AND ci.iid = :iid', array(':iid' => $item_id));
  }

  /**
   * {@inheritdoc}
   */
  public function updateItem($iid, array $cids) {
    // Remove all existing category items.
    $this->database->delete('aggregator_category_item')
      ->condition('iid', $iid)
      ->execute();

    // Insert new category items.
    if (!empty($cids)) {
      $insert = $this->database->insert('aggregator_category_item')
        ->fields(array('iid', 'cid'));
      foreach ($cids as $cid) {
        $insert->values(array('iid' => $iid, 'cid' => $cid));
      }
      $insert->execute();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function loadAllKeyed() {
    return $this->database->query('SELECT c.cid, c.title FROM {aggregator_category} c ORDER BY title')->fetchAllKeyed();
  }

}
