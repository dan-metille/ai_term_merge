<?php

namespace Drupal\ai_term_merge_test;

use Drupal\ai_term_merge\AiTermSuggestionGenerator;

/**
 * Records explicit analysis without constructing an AI provider dependency.
 */
final class FakeSuggestionGenerator extends AiTermSuggestionGenerator {

  /**
   * Constructs the test generator without parent services or providers.
   */
  public function __construct() {}

  /**
   * {@inheritdoc}
   */
  public function generateSuggestions(string $vocabulary_id): array {
    $state = \Drupal::state();
    $state->set('ai_term_merge_test.calls', $state->get('ai_term_merge_test.calls', 0) + 1);
    $suggestions = [['type' => 'exact_duplicate', 'terms' => [], 'canonical_tid' => 0]];
    \Drupal::service('ai_term_merge.suggestion_storage')->storeSuggestions($vocabulary_id, $suggestions);
    return $suggestions;
  }

}
