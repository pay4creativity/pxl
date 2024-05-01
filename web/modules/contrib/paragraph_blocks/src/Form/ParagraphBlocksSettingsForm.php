<?php

namespace Drupal\paragraph_blocks\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for Paragraph Blocks settings.
 */
class ParagraphBlocksSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'paragraph_blocks_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['paragraph_blocks.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('paragraph_blocks.settings');
    $form['max_cardinality'] = [
      '#type' => 'number',
      '#title' => $this->t('Max cardinality of paragraphs you want to see in Layout Builder.'),
      '#default_value' => $config->get('max_cardinality'),
      '#description' => $this->t('Layout Builder allows you to place each item in a multi-value paragraphs field as its own block. This sets the max number you think you need to see. You can change it later.'),
    ];
    $form['individual_block_ui'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display individual paragraph blocks in the UI.'),
      '#default_value' => $config->get('individual_block_ui'),
      '#description' => $this->t('When configuring layout builder restrictions on an entity type or placing blocks in the global block UI we filter out individual paragraph blocks to prevent UI clutter. Enabling this option exposes all blocks in the interface. The number of available paragraph blocks can be immense so only enable this if you really need to.'),
    ];
    $form['suppress_label'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Suppress label field on layout manager block placement.'),
      '#default_value' => $config->get('suppress_label'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $config = $this->config('paragraph_blocks.settings');
    $config->set('max_cardinality', $form_state->getValue('max_cardinality'));
    $config->set('individual_block_ui', $form_state->getValue('individual_block_ui'));
    $config->set('suppress_label', $form_state->getValue('suppress_label'));
    $config->save();
  }

}
