<?php declare(strict_types = 1);
use Drupal\file\Entity\File;

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
  $form['pxl']['breadcrumbs_background'] = [
    '#type' => 'managed_file',
    '#title' => 'Breadcrumbs backgroung image',
    '#name' => 'breadcrumbs_background',
    '#description' => t('Upload the Background image for Breadcrumb area.'),
    '#default_value' => theme_get_setting('breadcrumbs_background'),
    '#upload_location' => 'public://breadcrumbs/',
    '#upload_validators' => [
      'file_validate_extensions' => array('jpg jpeg png svg'),
    ],
  ];
  $form['#submit'][] = 'pxl_settings_form_submit';

}

function pxl_settings_form_submit(&$form, $form_state) {
  if ($file_id = $form_state->getValue(['breadcrumbs_background', '0'])) {
    $file = File::load($file_id);
    $file->setPermanent();
    $file->save();
  }
  }
