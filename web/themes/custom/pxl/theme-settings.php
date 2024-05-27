<?php declare(strict_types = 1);

/**
 * @file
 * Theme settings form for pxl theme.
 */

use Drupal\Core\Form\FormState;

/**
 * Implements hook_form_system_theme_settings_alter().
 */
function pxl_form_system_theme_settings_alter(array &$form, FormState $form_state): void {

  $form['pxl'] = [
    '#type' => 'details',
    '#title' => t('pxl'),
    '#open' => TRUE,
  ];

  $form['pxl']['example'] = [
    '#type' => 'textfield',
    '#title' => t('Example'),
    '#default_value' => theme_get_setting('example'),
  ];

}
