<?php

namespace Drupal\paragraph_blocks;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The paragraph blocks entity manager.
 */
class ParagraphBlocksEntityManager implements ContainerInjectionInterface {

  /**
   * The path alias manager.
   *
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  protected AliasManagerInterface $aliasManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The content moderation information service.
   *
   * @var \Drupal\content_moderation\ModerationInformationInterface|null
   */
  protected ?ModerationInformationInterface $moderationInformation = NULL;

  /**
   * Constructs a new ParagraphBlocksEntityManager object.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, RequestStack $request_stack, ModuleHandlerInterface $module_handler) {
    $this->entityTypeManager = $entityTypeManager;
    $this->requestStack = $request_stack;
    $this->moduleHandler = $module_handler;
    if ($this->moduleHandler->moduleExists('content_moderation')) {
      $this->moderationInformation = \Drupal::service('content_moderation.moderation_information');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ParagraphBlocksEntityManager {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
      $container->get('module_handler')
    );
  }

  /**
   * Return the entity from Layout Builders section storage in request.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity of the referer, or null if none.
   *
   * @throws \Drupal\Component\Plugin\Exception\ContextException
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getRefererEntity() {
    /** @var \Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage $section_storage */
    $section_storage = $this->requestStack->getCurrentRequest()->attributes->get('section_storage');

    // If section storage is for a single entity override there will be an
    // entity to return.
    if ('overrides' === $section_storage->getPluginId()) {
      $entity = $section_storage->getContext('entity')
        ->getContextData()
        ->getEntity();
      if (!is_null($this->moderationInformation) && $this->moderationInformation->isModeratedEntity($entity)) {
        return $this->getLatestEntityRevision($entity);
      }
      return $entity;
    }
    return NULL;
  }

  /**
   * Load the latest entity revision when using content moderation.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity interface.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The latest revision of the content entity.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getLatestEntityRevision(ContentEntityInterface &$entity): ContentEntityInterface {
    $default_published = $this->moderationInformation->isDefaultRevisionPublished($entity);
    $pending = $this->moderationInformation->hasPendingRevision($entity);
    if (!$default_published || $pending || !$entity->isLatestRevision()) {
      /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
      $entity_type_id = $entity->getEntityTypeId();
      /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $latest_revision_id = $storage->getLatestTranslationAffectedRevisionId($entity->id(), $entity->language()
        ->getId());

      $entity = $this->entityTypeManager->getStorage($entity_type_id)
        ->loadRevision($latest_revision_id);
    }

    return $entity;
  }

}
