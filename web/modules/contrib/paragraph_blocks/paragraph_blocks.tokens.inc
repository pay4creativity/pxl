<?php

/**
 * @file
 * Builds placeholder replacement tokens for paragraph summary.
 */

use Drupal\Core\Render\BubbleableMetadata;

/**
 * Implements hook_token_info_alter().
 */
function paragraph_blocks_token_info_alter(&$data) {
  $data['tokens']['paragraph']['summary'] = [
    'name' => t("Summary"),
    'description' => t("A generated summary of the paragraph fields"),
  ];
}

/**
 * Implements hook_tokens().
 */
function paragraph_blocks_tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
  $replacements = [];

  if ($type == 'entity' && $data["entity_type"] == 'paragraph' && !empty($data['entity'])) {
    /** @var \Drupal\paragraphs\ParagraphInterface $paragraph */
    $paragraph = $data['entity'];

    foreach ($tokens as $name => $original) {
      switch ($name) {
        case 'summary':
          $replacements[$original] = $paragraph->getSummary();
          break;
      }
    }
  }

  return $replacements;
}
