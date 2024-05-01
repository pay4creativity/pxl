<?php

namespace Drupal\paragraph_blocks;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\TempStore\TempStoreException;

/**
 * The entity presave helper.
 */
class ParagraphBlocksEntityPresaveHelper {

  /**
   * The private temp store factory service.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected PrivateTempStoreFactory $tempStoreFactory;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The paragraph_blocks_entity_presave temp store.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore|null
   */
  private ?PrivateTempStore $tempStore;

  /**
   * The key identifying the temp store.
   *
   * @var string
   */
  private string $tempStoreKey;

  /**
   * The entity to use the presave helper on.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  private EntityInterface $entity;

  /**
   * Constructs a ParagraphBlocksEntityPresaveHelper object.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The temp store service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The Drupal Logger Factory.
   */
  public function __construct(PrivateTempStoreFactory $temp_store_factory, LoggerChannelFactoryInterface $loggerFactory) {
    $this->tempStoreFactory = $temp_store_factory;
    $this->tempStore = $this->tempStoreFactory->get('paragraph_blocks_entity_presave');
    $this->loggerFactory = $loggerFactory;
  }

  /**
   * Getter for the entity property.
   */
  public function getEntity(): ?EntityInterface {
    return $this->entity;
  }

  /**
   * Setter for the entity property.
   */
  public function setEntity(EntityInterface $entity): void {
    $this->entity = $entity;
    $this->tempStoreKey = $entity->bundle() . '.' . $entity->id() . '.' . $entity->language()
      ->getId();
    $this->layout = $this->entity->get('layout_builder__layout');
  }

  /**
   * Update the layout builder configuration of an entity.
   *
   * This is necessary to prevent a broken layout when the paragraph references
   * change order or are deleted.
   *
   * @see: https://www.drupal.org/project/paragraph_blocks/issues/3099424
   */
  public function updateLayoutBuilderConfiguration(): void {
    // Use temp store to ignore delta changes if entity is being created/cloned.
    $new_paragraph_ids = $this->getTempStoreValue() ?? [];

    if (is_null($this->getEntity())) {
      $this->loggerFactory->get('paragraph_blocks')
        ->error('Could not perform presave helper method %method because entity was not set.', ['%method' => __FUNCTION__]);
      return;
    }

    if (!$this->entity->isNew()) {
      $sections = $this->layout->getIterator()->getArrayCopy();

      // Loop through paragraph blocks fields.
      foreach ($this->getParagraphBlocksFields() as $field) {
        // Collect the paragraph deltas before and after the entity is saved.
        $deltas_original = $this->getDeltasOriginal($field);
        $deltas = $this->getDeltas($field);

        // Collate delta changes.
        $deltas_reordered = $this->determineDeltasReordered($deltas_original, $deltas);

        // Collate any paragraphs that have been deleted.
        $deltas_deleted = $this->determineDeltasDeleted($deltas_original, $deltas, $new_paragraph_ids);

        // Check if any deltas have been changed or deleted.
        $delta_updates = [];
        $delta_deletes = [];
        if ($deltas_reordered || $deltas_deleted) {
          // Loop through the layout sections.
          foreach ($sections as $section_index => $section) {
            // Loop through each component in the section.
            foreach ($section->getValue()['section']->getComponents() as $component_index => $component) {
              $configuration = $section->getValue()['section']->getComponents()[$component_index]->get('configuration');
              $component_uuid = $section->getValue()['section']->getComponents()[$component_index]->getUuid();
              $this->prepareLayoutBuilderDeltaUpdates($delta_updates, $deltas_reordered, $field, $configuration, $section_index, $component_index);
              $this->prepareLayoutBuilderDeltaDeletes($delta_deletes, $deltas_deleted, $field, $configuration, $section_index, $component_index, $component_uuid);
            }
          }
        }

        // Loop through the paragraph delta updates applying them.
        $this->performLayoutBuilderDeltaUpdates($delta_updates);

        // Loop through the paragraph delta deletes removing them.
        $this->performLayoutBuilderDeltaDeletes($delta_deletes);
      }
    }

    // Collect paragraph ids to suppress deleting if entity is being cloned.
    $new_paragraph_ids = [];
    if ($this->entity->isNew()) {
      // Collect the paragraph ids of the new entity.
      foreach ($this->entity->getFields() as $fieldKey => $field) {
        if (method_exists($field->getFieldDefinition(), 'getThirdPartySetting') && $field->getFieldDefinition()
          ->getThirdPartySetting('paragraph_blocks', 'status')) {
          foreach ($this->entity->get($fieldKey)
            ->getIterator() as $item) {
            $new_paragraph_ids[] = $item->getValue()['target_id'];
          }
        }
      }
    }

    try {
      $this->setTempStoreValue($new_paragraph_ids);
    }
    catch (TempStoreException $e) {
      $this->loggerFactory->get('paragraph_blocks')->error($e->getMessage());
    }
  }

  /**
   * Get the value of the entity's paragraph_blocks_entity_presave temp store.
   *
   * @return mixed
   *   The data associated with the key, or NULL if the key does not exist.
   */
  private function getTempStoreValue() {
    return $this->tempStore->get($this->tempStoreKey);
  }

  /**
   * Set the value of the entity's paragraph_blocks_entity_presave temp store.
   *
   * @param mixed $value
   *   The data to store.
   *
   * @throws \Drupal\Core\TempStore\TempStoreException
   */
  private function setTempStoreValue($value): void {
    $this->tempStore->set($this->tempStoreKey, $value);
  }

  /**
   * Collect paragraph reference fields using paragraph blocks.
   *
   * @return array
   *   The fields with status settings.
   */
  private function getParagraphBlocksFields(): array {
    $fields = [];
    foreach ($this->entity->getFields() as $key => $field) {
      if (method_exists($field->getFieldDefinition(), 'getThirdPartySetting') && $field->getFieldDefinition()
        ->getThirdPartySetting('paragraph_blocks', 'status')) {
        $fields[] = $key;
      }
    }
    return $fields;
  }

  /**
   * Get a list of field deltas before the save action.
   *
   * @param mixed $field
   *   The list of fields.
   *
   * @return array
   *   The list of field deltas.
   */
  private function getDeltasOriginal(mixed $field): array {
    $deltas = [];
    foreach ($this->entity->original->get($field)
      ->getIterator() as $delta => $item) {
      $deltas[$item->getValue()['target_id']] = $delta;
    }
    return $deltas;
  }

  /**
   * Get a list of field deltas after the save action.
   *
   * @param mixed $field
   *   The list of fields.
   *
   * @return array
   *   The list of field deltas.
   */
  private function getDeltas(mixed $field): array {
    $deltas = [];
    foreach ($this->entity->get($field)->getIterator() as $delta => $item) {
      $deltas[$item->getValue()['target_id']] = $delta;
    }
    return $deltas;
  }

  /**
   * Determine reordered field deltas.
   *
   * Compare field deltas before and after the save action to determine which
   * paragraphs have been reordered.
   *
   * @param array $deltas_original
   *   The original delta fields.
   * @param array $deltas
   *   The list of field deltas.
   *
   * @return array
   *   The reordered list of original delta fields.
   */
  private function determineDeltasReordered(array $deltas_original, array $deltas): array {
    $reorders = [];
    foreach ($deltas as $paragraphEntityId => $delta) {
      if (isset($deltas_original[$paragraphEntityId]) && $delta != $deltas_original[$paragraphEntityId]) {
        $reorders[$delta] = $deltas_original[$paragraphEntityId];
      }
    }
    return $reorders;
  }

  /**
   * Determine deleted field deltas.
   *
   * Compare field deltas before and after the save action to determine which
   * paragraphs have been reordered.
   *
   * @param array $deltas_original
   *   The original delta fields.
   * @param array $deltas
   *   The list of field deltas.
   * @param array $new_paragraph_ids
   *   The list of paragraph IDs.
   *
   * @return array
   *   The list of deleted deltas.
   */
  private function determineDeltasDeleted(array $deltas_original, array $deltas, array $new_paragraph_ids): array {
    $deltas_deleted = [];
    foreach ($deltas_original as $paragraphEntityId => $delta) {
      if (!isset($deltas[$paragraphEntityId]) && !in_array($paragraphEntityId, $new_paragraph_ids)) {
        $deltas_deleted[] = $delta;
      }
    }
    return $deltas_deleted;
  }

  /**
   * Loop through delta reorders to see if section configuration needs updating.
   *
   * @param array $delta_updates
   *   The list of delta to be updated.
   * @param array $deltas_reordered
   *   The list of reordered deltas.
   * @param string $field
   *   The field.
   * @param array $configuration
   *   The configuration.
   * @param int|string $section_index
   *   The section index.
   * @param int|string $component_index
   *   The component index.
   */
  private function prepareLayoutBuilderDeltaUpdates(array &$delta_updates, array $deltas_reordered, string $field, array $configuration, $section_index, $component_index): void {
    if (!empty($deltas_reordered)) {
      foreach ($deltas_reordered as $delta_to => $delta_from) {
        $delta_old = 'paragraph_field:' . $this->entity->getEntityType()
          ->id() . ':' . $field . ':' . $delta_from . ':' . $this->entity->bundle();
        $delta_new = 'paragraph_field:' . $this->entity->getEntityType()
          ->id() . ':' . $field . ':' . $delta_to . ':' . $this->entity->bundle();

        // Collect the required paragraph delta updates.
        if ($configuration['id'] == $delta_old) {
          $delta_updates[] = [
            'section_index' => $section_index,
            'component_index' => $component_index,
            'configuration_id' => $delta_new,
          ];
        }
      }
    }
  }

  /**
   * Update paragraph deltas in the layout builder configuration.
   *
   * @param array $delta_updates
   *   The values of delta to update.
   */
  private function performLayoutBuilderDeltaUpdates(array $delta_updates): void {
    foreach ($delta_updates as [
      'section_index' => $section_index,
      'component_index' => $component_index,
      'configuration_id' => $configuration_id,
    ]
      ) {
      $configuration = $this->layout
        ->getIterator()
        ->offsetGet($section_index)
        ->getValue()['section']->getComponents()[$component_index]->get('configuration');
      $configuration['id'] = $configuration_id;
      $this->layout
        ->getIterator()
        ->offsetGet($section_index)
        ->getValue()['section']->getComponents()[$component_index]->setConfiguration($configuration);
    }
  }

  /**
   * Loop through deletes to see if section configuration needs updating.
   *
   * @param array $delta_deletes
   *   The values of delta to be deleted.
   * @param array $deltas_deleted
   *   The values of deleted deltas.
   * @param mixed $field
   *   The field.
   * @param array $configuration
   *   The configuration.
   * @param int|string $section_index
   *   The section index.
   * @param int|string $component_index
   *   The component index.
   * @param string $component_uuid
   *   The component UUID.
   */
  private function prepareLayoutBuilderDeltaDeletes(array &$delta_deletes, array $deltas_deleted, $field, array $configuration, $section_index, $component_index, string $component_uuid): void {
    foreach ($deltas_deleted as $delta) {
      $delta_old = 'paragraph_field:' . $this->entity->getEntityType()
        ->id() . ':' . $field . ':' . $delta . ':' . $this->entity->bundle();

      // Collect the required paragraph delta updates.
      if ($configuration['id'] == $delta_old) {
        $delta_deletes[] = [
          'section_index' => $section_index,
          'component_index' => $component_index,
          'component_uuid' => $component_uuid,
        ];
      }
    }
  }

  /**
   * Delete paragraph deltas from the layout builder configuration.
   *
   * @param array $delta_deletes
   *   The values of delta to be deleted.
   */
  private function performLayoutBuilderDeltaDeletes(array $delta_deletes): void {
    foreach ($delta_deletes as [
      'section_index' => $section_index,
      'component_index' => $component_index,
      'component_uuid' => $component_uuid,
    ]) {
      $this->layout
        ->getIterator()
        ->offsetGet($section_index)
        ->getValue()['section']->removeComponent($component_uuid);
    }
  }

}
