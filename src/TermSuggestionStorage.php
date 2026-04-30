<?php

namespace Drupal\ai_term_merge;

use Drupal\Core\State\StateInterface;

/**
 * Service for storing and retrieving term merge suggestions.
 */
class TermSuggestionStorage {

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * Constructs a TermSuggestionStorage object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(StateInterface $state) {
    $this->state = $state;
  }

  /**
   * Gets the state key for a vocabulary.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   *
   * @return string
   *   The state key.
   */
  protected function getKey(string $vocabulary_id): string {
    return "ai_term_merge.suggestions.$vocabulary_id";
  }

  /**
   * Stores suggestions for a vocabulary.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   * @param array $suggestions
   *   Array of suggestion groups.
   */
  public function storeSuggestions(string $vocabulary_id, array $suggestions): void {
    $this->state->set($this->getKey($vocabulary_id), [
      'timestamp' => time(),
      'suggestions' => $suggestions,
    ]);
  }

  /**
   * Gets all suggestions for a vocabulary.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   *
   * @return array
   *   Array with 'timestamp' and 'suggestions' keys, or empty array.
   */
  public function getSuggestions(string $vocabulary_id): array {
    return $this->state->get($this->getKey($vocabulary_id), []);
  }

  /**
   * Gets a single suggestion by ID.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   * @param string $suggestion_id
   *   The suggestion ID (index).
   *
   * @return array|null
   *   The suggestion or NULL if not found.
   */
  public function getSuggestion(string $vocabulary_id, string $suggestion_id): ?array {
    $data = $this->getSuggestions($vocabulary_id);
    return $data['suggestions'][$suggestion_id] ?? NULL;
  }

  /**
   * Removes a single suggestion.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   * @param string $suggestion_id
   *   The suggestion ID to remove.
   */
  public function removeSuggestion(string $vocabulary_id, string $suggestion_id): void {
    $data = $this->getSuggestions($vocabulary_id);
    if (!empty($data['suggestions']) && isset($data['suggestions'][$suggestion_id])) {
      unset($data['suggestions'][$suggestion_id]);
      // Reindex array.
      $data['suggestions'] = array_values($data['suggestions']);
      $this->state->set($this->getKey($vocabulary_id), $data);
    }
  }

  /**
   * Clears all suggestions for a vocabulary.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   */
  public function clearSuggestions(string $vocabulary_id): void {
    $this->state->delete($this->getKey($vocabulary_id));
  }

  /**
   * Checks if a vocabulary has suggestions.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   *
   * @return bool
   *   TRUE if suggestions exist.
   */
  public function hasSuggestions(string $vocabulary_id): bool {
    $data = $this->getSuggestions($vocabulary_id);
    return !empty($data['suggestions']);
  }

  /**
   * Gets the timestamp of last analysis.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   *
   * @return int|null
   *   The timestamp or NULL.
   */
  public function getLastAnalyzedTimestamp(string $vocabulary_id): ?int {
    $data = $this->getSuggestions($vocabulary_id);
    return $data['timestamp'] ?? NULL;
  }

}
