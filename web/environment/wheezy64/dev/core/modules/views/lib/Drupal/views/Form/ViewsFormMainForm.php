<?php

/**
 * @file
 * Contains \Drupal\views\Form\ViewsFormMainForm.
 */

namespace Drupal\views\Form;

use Drupal\Core\Form\FormInterface;
use Drupal\views\ViewExecutable;

class ViewsFormMainForm implements FormInterface {

  /**
   * {@inheritdoc}
   */
  public function getFormID() {
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, array &$form_state, ViewExecutable $view = NULL, $output = NULL) {
    $form['#prefix'] = '<div class="views-form">';
    $form['#suffix'] = '</div>';
    $form['#theme'] = 'form';
    $form['#pre_render'][] = 'views_pre_render_views_form_views_form';

    // Add the output markup to the form array so that it's included when the form
    // array is passed to the theme function.
    $form['output'] = array(
      '#markup' => $output,
      // This way any additional form elements will go before the view
      // (below the exposed widgets).
      '#weight' => 50,
    );

    $form['actions'] = array(
      '#type' => 'actions',
    );
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => t('Save'),
    );

    $substitutions = array();
    foreach ($view->field as $field_name => $field) {
      $form_element_name = $field_name;
      if (method_exists($field, 'form_element_name')) {
        $form_element_name = $field->form_element_name();
      }
      $method_form_element_row_id_exists = FALSE;
      if (method_exists($field, 'form_element_row_id')) {
        $method_form_element_row_id_exists = TRUE;
      }

      // If the field provides a views form, allow it to modify the $form array.
      $has_form = FALSE;
      if (property_exists($field, 'views_form_callback')) {
        $callback = $field->views_form_callback;
        $callback($view, $field, $form, $form_state);
        $has_form = TRUE;
      }
      elseif (method_exists($field, 'views_form')) {
        $field->views_form($form, $form_state);
        $has_form = TRUE;
      }

      // Build the substitutions array for use in the theme function.
      if ($has_form) {
        foreach ($view->result as $row_id => $row) {
          if ($method_form_element_row_id_exists) {
            $form_element_row_id = $field->form_element_row_id($row_id);
          }
          else {
            $form_element_row_id = $row_id;
          }

          $substitutions[] = array(
            'placeholder' => '<!--form-item-' . $form_element_name . '--' . $form_element_row_id . '-->',
            'field_name' => $form_element_name,
            'row_id' => $form_element_row_id,
          );
        }
      }
    }

    // Give the area handlers a chance to extend the form.
    $area_handlers = array_merge(array_values($view->header), array_values($view->footer));
    $empty = empty($view->result);
    foreach ($area_handlers as $area) {
      if (method_exists($area, 'views_form') && !$area->views_form_empty($empty)) {
        $area->views_form($form, $form_state);
      }
    }

    $form['#substitutions'] = array(
      '#type' => 'value',
      '#value' => $substitutions,
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, array &$form_state) {
    $view = $form_state['build_info']['args'][0];

    // Call the validation method on every field handler that has it.
    foreach ($view->field as $field) {
      if (method_exists($field, 'views_form_validate')) {
        $field->views_form_validate($form, $form_state);
      }
    }

    // Call the validate method on every area handler that has it.
    foreach (array('header', 'footer') as $area) {
      foreach ($view->{$area} as $area_handler) {
        if (method_exists($area_handler, 'views_form_validate')) {
          $area_handler->views_form_validate($form, $form_state);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, array &$form_state) {
    $view = $form_state['build_info']['args'][0];

    // Call the submit method on every field handler that has it.
    foreach ($view->field as $field) {
      if (method_exists($field, 'views_form_submit')) {
        $field->views_form_submit($form, $form_state);
      }
    }

    // Call the submit method on every area handler that has it.
    foreach (array('header', 'footer') as $area) {
      foreach ($view->{$area} as $area_handler) {
        if (method_exists($area_handler, 'views_form_submit')) {
          $area_handler->views_form_submit($form, $form_state);
        }
      }
    }
  }

}
