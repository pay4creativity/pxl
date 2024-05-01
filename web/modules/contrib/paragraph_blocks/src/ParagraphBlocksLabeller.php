<?php

namespace Drupal\paragraph_blocks;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Labels the paragraph blocks once the entity context is known.
 */
class ParagraphBlocksLabeller {

  use StringTranslationTrait;

  /**
   * The plugin type id.
   *
   * @var string
   */
  const PLUGIN_TYPE_ID = 'paragraph_field';

  /**
   * The label format.
   *
   * @var string
   */
  const LABEL_FORMAT = 'Page: @label';

  /**
   * The current entity, or NULL.
   *
   * @var \Drupal\Core\Entity\EntityInterface|null
   */
  protected ?EntityInterface $entity;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The paragraph blocks entity manager.
   *
   * @var \Drupal\paragraph_blocks\ParagraphBlocksEntityManager
   */
  protected ParagraphBlocksEntityManager $paragraphBlocksEntityManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * Constructs a new ParagraphBlocksLabeller object.
   *
   * @param \Drupal\paragraph_blocks\ParagraphBlocksEntityManager $paragraph_blocks_entity_manager
   *   The Paragraph blocks entity manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The interface for an entity field manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(ParagraphBlocksEntityManager $paragraph_blocks_entity_manager, EntityFieldManagerInterface $entity_field_manager, ModuleHandlerInterface $module_handler) {
    $this->paragraphBlocksEntityManager = $paragraph_blocks_entity_manager;
    $this->entity = $this->paragraphBlocksEntityManager->getRefererEntity();
    $this->entityFieldManager = $entity_field_manager;
    $this->moduleHandler = $module_handler;
  }

  /**
   * Removed unused paragraphs and update the layout builder title.
   *
   * @param array $definitions
   *   The plugin definitions.
   */
  public function hookLayoutBuilderChooseBlocksAlter(array &$definitions) {
    // Loop through all of the plugin definitions.
    foreach ($definitions as $plugin_id => $definition) {
      $enabled = TRUE;
      $title = $this->getTitle($plugin_id, $enabled);
      if ($title === FALSE) {
        // Remove the block if there is no paragraph data for the delta.
        if ($this->entity || !$enabled) {
          unset($definitions[$plugin_id]);
        }
      }
      elseif ($title) {
        // Replace the title.
        $definitions[$plugin_id]['admin_label'] = $title;
      }
    }
  }

  /**
   * Returns the plugin's paragraph title.
   *
   * @param string $plugin_id
   *   The plugin id.
   * @param bool $enabled
   *   Return if the field is enabled or disabled.
   *
   * @return string
   *   The paragraph title.
   */
  public function getTitle(string $plugin_id, bool &$enabled = TRUE) {
    $plugin_parts = explode(':', $plugin_id);
    if (count($plugin_parts) < 4) {
      return NULL;
    }
    [
      $plugin_type_id,
      $plugin_entity_type_id,
      $plugin_field_name,
      $plugin_field_delta,
    ] = $plugin_parts;
    if ($plugin_type_id != self::PLUGIN_TYPE_ID) {
      return NULL;
    }
    $plugin_field_bundle = count($plugin_parts) ? $plugin_parts[4] : '';

    // Only check the field bundle if it exists. This is new to the 2.x branch.
    // So this check exists for backwards compatability with plugins saved
    // using the 1.x branch.
    if ($plugin_field_bundle) {
      // Remove if this paragraph field is not enabled.
      $field_definitions = $this->entityFieldManager->getFieldDefinitions($plugin_entity_type_id, $plugin_field_bundle);
      $field_config = $field_definitions[$plugin_field_name]->getConfig($plugin_field_bundle);
      if (!$field_config->getThirdPartySetting('paragraph_blocks', 'status', TRUE)) {
        $enabled = FALSE;
        return FALSE;
      }
    }

    // Return if this plugin should be removed from the list.
    if (!$this->entity || $this->entity->bundle() != $plugin_field_bundle || $plugin_field_delta >= $this->entity->get($plugin_field_name)
      ->count()) {
      return FALSE;
    }

    // Get the referenced paragraph.
    /** @var \Drupal\paragraph_blocks\Entity\ParagraphBlocksEntity $paragraph */
    $paragraph = $this->entity->get($plugin_field_name)
      ->referencedEntities()[$plugin_field_delta];

    // Use admin title as block label.
    if ($paragraph->hasAdminTitle()) {
      return $paragraph->getAdminTitle();
    }

    // Fallback to paragraph summary title behavior.
    return $paragraph->getSummary();
  }

  /**
   * Set add/edit form title and display properties.
   *
   * @param array $form
   *   The form values.
   * @param string $title
   *   The title.
   */
  public function setFormTitle(array &$form, string $title) {
    $section = &$form['flipper']['front']['settings'];
    $section['admin_label']['#type'] = 'hidden';
    $section['label']['#type'] = 'hidden';
    $section['label']['#required'] = FALSE;
    $section['label_display']['#type'] = 'hidden';
    $section['label_display']['#value'] = 0;
    $section['label']['#value'] = $title;
  }

}
