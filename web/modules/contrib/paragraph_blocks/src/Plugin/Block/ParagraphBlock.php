<?php

namespace Drupal\paragraph_blocks\Plugin\Block;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraph_blocks\ParagraphBlocksEntityManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a block to a paragraph value on an entity.
 *
 * @Block(
 *   id = "paragraph_field",
 *   deriver = "Drupal\paragraph_blocks\Plugin\Deriver\ParagraphBlocksDeriver",
 *   category = @Translation("Paragraphs")
 * )
 */
class ParagraphBlock extends BlockBase implements ContextAwarePluginInterface, ContainerFactoryPluginInterface {

  /**
   * The entity type id.
   *
   * @var string
   */
  protected string $entityTypeId;

  /**
   * The field name.
   *
   * @var string
   */
  protected string $fieldName;

  /**
   * The field delta.
   *
   * @var int
   */
  protected int $fieldDelta;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The Paragraph Blocks Entity Manager.
   *
   * @var \Drupal\paragraph_blocks\ParagraphBlocksEntityManager
   */
  protected ParagraphBlocksEntityManager $paragraphBlocksManager;

  /**
   * The content moderation information service.
   *
   * @var \Drupal\content_moderation\ModerationInformationInterface|null
   */
  protected ?ModerationInformationInterface $moderationInformation = NULL;

  /**
   * Constructs a new ParagraphBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\paragraph_blocks\ParagraphBlocksEntityManager $paragraph_blocks_manager
   *   The config factory service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, ModuleHandlerInterface $module_handler, ParagraphBlocksEntityManager $paragraph_blocks_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
    $this->paragraphBlocksManager = $paragraph_blocks_manager;
    if ($this->moduleHandler->moduleExists('content_moderation')) {
      $this->moderationInformation = \Drupal::service('content_moderation.moderation_information');
    }

    // Get the field delta from the plugin.
    [, $entity_type_id, $field_name, $field_delta] = explode(':', $plugin_id);
    $this->entityTypeId = $entity_type_id;
    $this->fieldName = $field_name;
    $this->fieldDelta = $field_delta;

    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('module_handler'),
      $container->get('paragraph_blocks.entity_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'label' => '',
      'label_display' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);
    $entity = $this->paragraphBlocksManager->getRefererEntity();

    if (isset($entity)) {
      $config = $this->configFactory->get('paragraph_blocks.settings');
      $paragraph = $this->getLatestParagraph($entity);
      if (empty($paragraph)) {
        $admin_title = \Drupal::service('uuid')->generate();
      }
      elseif (!$paragraph->hasAdminTitle()) {
        $admin_title = $paragraph->getSummary();
      }
      else {
        $admin_title = $paragraph->getAdminTitle();
      }
      $orig_title = $form['admin_label']['#plain_text'];
      $form['admin_label']['#plain_text'] = $admin_title;
      // Only change if it's currently the default title.
      if ($form['label']['#default_value'] == $orig_title) {
        $form['label']['#default_value'] = $admin_title;
        if ($config->get('suppress_label')) {
          $form['label']['#type'] = 'hidden';
          $form['label_display']['#type'] = 'hidden';
        }
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $paragraph = NULL;
    // Get the referencing and referenced entity.
    $entity = $this->getContextEntity();

    if ($entity) {
      $paragraph = $this->getLatestParagraph($entity);
    }
    if (!$paragraph) {
      // The Paragraphs group block exists on the page, but the page's
      // Paragraphs group has been removed.
      return [
        '#markup' => $this->t('This block is broken. The Paragraphs group or the paragraph does not exist.'),
      ];
    }

    // Build the render array.
    /** @var \Drupal\Core\Entity\EntityViewBuilder $view_builder */
    $view_builder = $this->entityTypeManager->getViewBuilder($paragraph->getEntityTypeId());
    $build = $view_builder->view($paragraph, 'default');

    // Set the cache data appropriately.
    CacheableMetadata::createFromObject($this->getContext('entity'))
      ->applyTo($build);

    return $build;
  }

  /**
   * Return the entity that contains the paragraph.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity that holds the paragraph field.
   *
   * @throws \Drupal\Component\Plugin\Exception\ContextException
   */
  protected function getContextEntity(): EntityInterface {
    return $this->getContextValue('entity');
  }

  /**
   * Get latest published paragraph from entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity that holds the paragraph field.
   *
   * @return \Drupal\paragraphs\Entity\Paragraph|null
   *   The paragraph.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function getLatestParagraph(ContentEntityInterface $entity): ?Paragraph {
    $paragraph = $this->getReferencedParagraph($entity);

    // Check if we have a pending version of the entity we want to preview.
    if (is_null($paragraph) && !is_null($this->moderationInformation) && $this->moderationInformation->isModeratedEntity($entity)) {
      $entity_manager = $this->paragraphBlocksManager;
      $entity = $entity_manager->getLatestEntityRevision($entity);
      $paragraph = $this->getReferencedParagraph($entity);
    }

    return $paragraph;
  }

  /**
   * Get referenced paragraph from entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity that holds the entity field.
   *
   * @return \Drupal\paragraphs\Entity\Paragraph|null
   *   The paragraph.
   */
  private function getReferencedParagraph(ContentEntityInterface $entity): ?Paragraph {
    $referenced_entities = $entity
      ->get($this->fieldName)
      ->referencedEntities();
    if (isset($referenced_entities[$this->fieldDelta])) {
      $paragraph = $referenced_entities[$this->fieldDelta];
    }
    return $paragraph ?? NULL;
  }

}
