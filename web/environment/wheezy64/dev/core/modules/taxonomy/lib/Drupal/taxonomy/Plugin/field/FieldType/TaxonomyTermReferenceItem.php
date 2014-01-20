<?php

/**
 * @file
 * Contains \Drupal\taxonomy\Plugin\field\field_type\TaxonomyTermReferenceItem.
 */

namespace Drupal\taxonomy\Plugin\Field\FieldType;

use Drupal\Core\Field\ConfigEntityReferenceItemBase;
use Drupal\Core\Field\ConfigFieldItemInterface;
use Drupal\field\FieldInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\AllowedValuesInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Plugin implementation of the 'term_reference' field type.
 *
 * @FieldType(
 *   id = "taxonomy_term_reference",
 *   label = @Translation("Term Reference"),
 *   description = @Translation("This field stores a reference to a taxonomy term."),
 *   settings = {
 *     "options_list_callback" = NULL,
 *     "allowed_values" = {
 *       {
 *         "vocabulary" = "",
 *         "parent" = "0"
 *       }
 *     }
 *   },
 *   instance_settings = { },
 *   default_widget = "options_select",
 *   default_formatter = "taxonomy_term_reference_link",
 *   list_class = "\Drupal\taxonomy\Plugin\Field\FieldType\TaxonomyTermReferenceFieldItemList"
 * )
 */
class TaxonomyTermReferenceItem extends ConfigEntityReferenceItemBase implements ConfigFieldItemInterface, AllowedValuesInterface {

  /**
   * {@inheritdoc}
   */
  public function getPossibleValues(AccountInterface $account = NULL) {
    // Flatten options firstly, because Possible Options may contain group
    // arrays.
    $flatten_options = $this->flattenOptions($this->getPossibleOptions($account));
    return array_keys($flatten_options);
  }

  /**
   * {@inheritdoc}
   */
  public function getPossibleOptions(AccountInterface $account = NULL) {
    return $this->getSettableOptions($account);
  }

  /**
   * {@inheritdoc}
   */
  public function getSettableValues(AccountInterface $account = NULL) {
   // Flatten options firstly, because Settable Options may contain group
   // arrays.
    $flatten_options = $this->flattenOptions($this->getSettableOptions($account));
    return array_keys($flatten_options);
  }

  /**
   * {@inheritdoc}
   */
  public function getSettableOptions(AccountInterface $account = NULL) {
    $instance = $this->getFieldDefinition();
    $entity = $this->getParent()->getParent();
    $function = $this->getFieldSetting('options_list_callback') ? $this->getFieldSetting('options_list_callback') : array($this, 'getDefaultOptions');
    return call_user_func_array($function, array($instance, $entity));
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions() {
    $this->definition['settings']['target_type'] = 'taxonomy_term';
    return parent::getPropertyDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldInterface $field) {
    return array(
      'columns' => array(
        'target_id' => array(
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => FALSE,
        ),
      ),
      'indexes' => array(
        'target_id' => array('target_id'),
      ),
      'foreign keys' => array(
        'target_id' => array(
          'table' => 'taxonomy_term_data',
          'columns' => array('target_id' => 'tid'),
        ),
      ),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, array &$form_state, $has_data) {
    // Get proper values for 'allowed_values_function', which is a core setting.
    $vocabularies = entity_load_multiple('taxonomy_vocabulary');
    $options = array();
    foreach ($vocabularies as $vocabulary) {
      $options[$vocabulary->id()] = $vocabulary->name;
    }

    $settings = $this->getFieldSettings();
    $element = array();
    $element['#tree'] = TRUE;

    foreach ($settings['allowed_values'] as $delta => $tree) {
      $element['allowed_values'][$delta]['vocabulary'] = array(
        '#type' => 'select',
        '#title' => t('Vocabulary'),
        '#default_value' => $tree['vocabulary'],
        '#options' => $options,
        '#required' => TRUE,
        '#description' => t('The vocabulary which supplies the options for this field.'),
        '#disabled' => $has_data,
      );
      $element['allowed_values'][$delta]['parent'] = array(
        '#type' => 'value',
        '#value' => $tree['parent'],
      );
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function instanceSettingsForm(array $form, array &$form_state) {
    return array();
  }

  /**
  * Flattens an array of allowed values.
  *
  * @todo Define this function somewhere else, so we don't have to redefine it
  *   when other field type classes, e.g. list option, need it too.
  *   https://drupal.org/node/2138803
  *
  * @param array $array
  *   A single or multidimensional array.
  *
  * @return array
  *   The flattened array.
  */
  protected function flattenOptions(array $array) {
    $result = array();
    array_walk_recursive($array, function($a, $b) use (&$result) { $result[$b] = $a; });
    return $result;
  }

  /**
   * Returns default set of valid terms for a taxonomy field.
   *
   * Contrib code could make use of field setting's "options_list_callback" to
   * provide custom options for taxonomy term reference field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object the field is attached to.
   *
   * @return array
   *   The array of valid terms for this field, keyed by term id.
   */
  public function getDefaultOptions(FieldDefinitionInterface $field_definition, EntityInterface $entity) {
    $options = array();
    foreach ($field_definition->getSetting('allowed_values') as $tree) {
      if ($vocabulary = entity_load('taxonomy_vocabulary', $tree['vocabulary'])) {
        if ($terms = taxonomy_get_tree($vocabulary->id(), $tree['parent'], NULL, TRUE)) {
          foreach ($terms as $term) {
            $options[$term->id()] = str_repeat('-', $term->depth) . $term->label();
          }
        }
      }
    }
    return $options;
  }

}
