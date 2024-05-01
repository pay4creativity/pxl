<?php

namespace Drupal\paragraph_blocks\Entity;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Token;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Extend the Paragraph entity.
 */
class ParagraphBlocksEntity extends Paragraph {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getSummary(array $options = []) {
    $summary = '';

    $summary_items = $this->getSummaryItems($options);
    if (!empty($summary_items['content'])) {
      foreach ($summary_items['content'] as $item) {
        $summary .= trim(strip_tags(str_replace(["\r", "\n"], " ", $item))) . ' ';
      }
    }

    return Unicode::truncate(html_entity_decode($summary), 100, TRUE, TRUE);
  }

  /**
   * Get root parent entity for nested paragraphs.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|\Drupal\Core\Entity\EntityInterface|\Drupal\Core\TypedData\TranslatableInterface|null
   */
  public function getRootEntity() {
    $parent = $this->getParentEntity();
    if ($parent instanceof Paragraph) {
      return $parent->getRootEntity();
    }
    return $parent;
  }

  /**
   * Get the admin title value.
   *
   * @return string
   *   A cleaned up and truncated admin title.
   */
  public function getAdminTitle(): string {
    if (!$this->hasField('admin_title')) {
      return '';
    }
    $text = $this->get('admin_title')->value ?? '';

    $root_parent = $this->getRootEntity();

    $token_data['paragraph'] = $this;
    if ($root_parent && !$root_parent instanceof Paragraph) {
      $token_data[$root_parent->getEntityType()->id()] = $root_parent;
    }
    $text = \Drupal::token()->replacePlain($text, $token_data, ['clear' => TRUE]);

    return Unicode::truncate(trim(strip_tags($text)), 100);
  }

  /**
   * Paragraph has a non-empty value as admin title.
   *
   * @return bool
   *   TRUE if admin title exists.
   */
  public function hasAdminTitle(): bool {
    return strlen($this->getAdminTitle());
  }

}
