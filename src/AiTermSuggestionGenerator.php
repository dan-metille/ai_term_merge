<?php

namespace Drupal\ai_term_merge;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;

/**
 * Service for AI-powered term duplicate detection.
 */
class AiTermSuggestionGenerator {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected AiProviderPluginManager $aiProvider;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructs an AiTermSuggestionGenerator object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AiProviderPluginManager $ai_provider,
    StateInterface $state,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->aiProvider = $ai_provider;
    $this->state = $state;
    $this->logger = $logger_factory->get('ai_term_merge');
    $this->configFactory = $config_factory;
  }

  /**
   * Generates duplicate suggestions for a vocabulary.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   *
   * @return array
   *   Array of suggestion groups.
   */
  public function generateSuggestions(string $vocabulary_id): array {
    $config = $this->configFactory->get('ai_term_merge.settings');
    $detection_modes = $config->get('detection_modes') ?: [];

    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    // Load all terms from vocabulary.
    $query = $term_storage->getQuery()
      ->condition('vid', $vocabulary_id)
      ->accessCheck(FALSE);
    
    $tids = $query->execute();
    $terms = $term_storage->loadMultiple($tids);

    if (empty($terms)) {
      return [];
    }

    $all_suggestions = [];

    // Step 1: Detect exact duplicates (no AI needed).
    if (!empty($detection_modes['exact_duplicates'])) {
      $exact_duplicates = $this->detectExactDuplicates($terms);
      foreach ($exact_duplicates as $group) {
        $all_suggestions[] = [
          'type' => 'exact_duplicate',
          'terms' => $group,
          'canonical_tid' => $this->selectCanonicalTermId($group, $terms),
        ];
      }
    }

    // Step 2: Detect semantic duplicates using AI.
    if (!empty($detection_modes['semantic_duplicates'])) {
      $semantic_duplicates = $this->detectSemanticDuplicates($vocabulary_id, $terms);
      foreach ($semantic_duplicates as $group) {
        // Skip if already covered by exact duplicates.
        if ($this->groupAlreadyFound($group, $all_suggestions)) {
          continue;
        }
        $all_suggestions[] = [
          'type' => 'semantic_duplicate',
          'terms' => $group,
          'canonical_tid' => $this->selectCanonicalTermId($group, $terms),
        ];
      }
    }

    // Store suggestions.
    $this->storeSuggestions($vocabulary_id, $all_suggestions);

    return $all_suggestions;
  }

  /**
   * Detects exact duplicate terms (same name).
   *
   * @param array $terms
   *   Array of term entities.
   *
   * @return array
   *   Array of duplicate groups (term IDs).
   */
  protected function detectExactDuplicates(array $terms): array {
    $name_groups = [];

    foreach ($terms as $term) {
      $name = strtolower(trim($term->getName()));
      $name_groups[$name][] = (int) $term->id();
    }

    // Filter to only groups with multiple terms.
    $duplicates = [];
    foreach ($name_groups as $tids) {
      if (count($tids) > 1) {
        $duplicates[] = $tids;
      }
    }

    return $duplicates;
  }

  /**
   * Detects semantic duplicates using AI.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   * @param array $terms
   *   Array of term entities.
   *
   * @return array
   *   Array of duplicate groups (term IDs).
   */
  protected function detectSemanticDuplicates(string $vocabulary_id, array $terms): array {
    $config = $this->configFactory->get('ai_term_merge.settings');
    $batch_size = $config->get('batch_size') ?: 50;

    // Get Schema.org type for context.
    $schema_type = $this->getSchemaType($vocabulary_id);

    // Prepare term data.
    $term_data = [];
    foreach ($terms as $term) {
      $term_data[$term->id()] = $term->getName();
    }

    // Get provider and model.
    $provider_model = $config->get('ai_provider_model');
    try {
      if ($provider_model) {
        $provider = $this->aiProvider->loadProviderFromSimpleOption($provider_model);
        $model_id = $this->aiProvider->getModelNameFromSimpleOption($provider_model);
      }
      else {
        // Use default chat provider.
        $default = $this->aiProvider->getDefaultProviderForOperationType('chat');
        if (!$default) {
          $this->logger->error('No default AI chat provider configured.');
          return [];
        }
        $provider = $this->aiProvider->createInstance($default['provider_id']);
        $model_id = $default['model_id'];
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to load AI provider: @error', ['@error' => $e->getMessage()]);
      return [];
    }

    // Process in batches.
    $batches = array_chunk($term_data, $batch_size, TRUE);
    $all_duplicates = [];

    foreach ($batches as $batch) {
      $duplicates = $this->analyzeBatchWithAi($batch, $schema_type, $provider, $model_id);
      if (!empty($duplicates)) {
        $all_duplicates = array_merge($all_duplicates, $duplicates);
      }
    }

    return $all_duplicates;
  }

  /**
   * Analyzes a batch of terms with AI.
   *
   * @param array $terms
   *   Array of term ID => name.
   * @param string|null $schema_type
   *   The Schema.org type or NULL.
   * @param mixed $provider
   *   The AI provider.
   * @param string $model_id
   *   The model ID.
   *
   * @return array
   *   Array of duplicate groups.
   */
  protected function analyzeBatchWithAi(array $terms, ?string $schema_type, $provider, string $model_id): array {
    $prompt = $this->buildPrompt($terms, $schema_type);

    $system_prompt = 'You are an expert at identifying duplicate or equivalent terms in taxonomies. You can recognize name variations, abbreviations, alternate spellings, and terms that refer to the same entity.';

    // Create ChatInput with ChatMessage objects (not raw arrays).
    $input = new ChatInput([
      new ChatMessage('user', $prompt),
    ]);
    $input->setSystemPrompt($system_prompt);

    try {
      $result = $provider->chat($input, $model_id, ['temperature' => 0.1]);
      $response = $result->getNormalized()->getText();

      // Parse JSON response.
      $duplicates = json_decode($response, TRUE);

      if (!is_array($duplicates)) {
        $this->logger->warning('Invalid AI response format: @response', ['@response' => substr($response, 0, 500)]);
        return [];
      }

      // Validate that all IDs exist in our batch.
      $valid_duplicates = [];
      foreach ($duplicates as $group) {
        if (is_array($group) && count($group) > 1) {
          $valid_group = array_filter($group, fn($tid) => isset($terms[$tid]));
          if (count($valid_group) > 1) {
            $valid_duplicates[] = array_values($valid_group);
          }
        }
      }

      return $valid_duplicates;

    }
    catch (\Exception $e) {
      $this->logger->error('AI analysis failed: @error', ['@error' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Builds the AI prompt for duplicate detection.
   *
   * @param array $terms
   *   Array of term ID => name.
   * @param string|null $schema_type
   *   The Schema.org type or NULL.
   *
   * @return string
   *   The prompt.
   */
  protected function buildPrompt(array $terms, ?string $schema_type): string {
    $term_list = [];
    foreach ($terms as $tid => $name) {
      $term_list[] = "$tid: $name";
    }

    $context = '';
    $extra_rules = '';
    if ($schema_type) {
      $contexts = [
        'Person' => 'These are person names used for photo credits and attribution.',
        'Place' => 'These are place names. Consider abbreviations, local vs official names, and alternate spellings.',
        'Organization' => 'These are organization names. Consider acronyms, full names, and trading names.',
      ];
      $context = $contexts[$schema_type] ?? "These are $schema_type entries.";

      // Add Person-specific rules.
      if ($schema_type === 'Person') {
        $extra_rules = "
IMPORTANT - For person names, also identify these patterns as duplicates:
- Names with company/brand suffixes: 'John Smith' = 'John Smith for Nomad\\'s Land' = 'John Smith Nomads Land'
- Attribution formats: 'Name for Company', 'Name by Company', 'Name @ Company', 'Name - Company'
- Usernames vs real names: 'john_smith' = 'John Smith' = 'johnsmith'
- Names with 'on Unsplash', 'on Pexels', or similar platform suffixes
- With/without middle names or initials: 'John Smith' = 'John A. Smith' = 'J. Smith'
- Accented vs non-accented: 'José' = 'Jose'
- Common typos and very similar spellings
";
      }
    }

    return "Analyze these taxonomy terms and identify groups of duplicates or equivalent terms.

$context

Terms:
" . implode("\n", $term_list) . "

Rules:
- Group terms that refer to the same person/entity
- Consider spelling variations, abbreviations, formatting differences
- Be AGGRESSIVE in grouping - if two terms likely refer to the same person, group them
- Each term can only appear in one group
$extra_rules
Return a JSON array of duplicate groups. Each group contains term IDs.
Format: [[tid1, tid2], [tid3, tid4, tid5]]

Only return the JSON array, no explanation. If no duplicates found, return [].";
  }

  /**
   * Gets the Schema.org type for a vocabulary.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   *
   * @return string|null
   *   The Schema.org type or NULL.
   */
  protected function getSchemaType(string $vocabulary_id): ?string {
    try {
      $mapping_storage = $this->entityTypeManager->getStorage('schemadotorg_mapping');
      $mapping = $mapping_storage->load("taxonomy_term.$vocabulary_id");
      if ($mapping) {
        return $mapping->getSchemaType();
      }
    }
    catch (\Exception $e) {
      // Schema.org module may not be installed.
    }
    return NULL;
  }

  /**
   * Selects the best canonical term ID from a group.
   *
   * @param array $tids
   *   Array of term IDs.
   * @param array $terms
   *   Array of term entities keyed by ID.
   *
   * @return int
   *   The canonical term ID.
   */
  protected function selectCanonicalTermId(array $tids, array $terms): int {
    $best_tid = reset($tids);
    $best_score = 0;

    foreach ($tids as $tid) {
      if (!isset($terms[$tid])) {
        continue;
      }
      $term = $terms[$tid];
      $score = 0;

      // Prefer terms with more usage.
      $usage = $this->getTermUsageCount($tid);
      $score += $usage * 10;

      // Prefer terms with field data.
      $field_count = $this->getTermFieldDataCount($term);
      $score += $field_count * 100;

      // Prefer older terms (lower tid = older term, since tids are sequential).
      // Taxonomy terms don't have a 'created' timestamp, so we use tid as proxy.
      $tid_age_score = max(0, 10000 - (int) $tid);
      $score += min($tid_age_score / 100, 100); // Up to 100 points for being older.

      // Prefer properly formatted names.
      $name = $term->getName();
      if (preg_match('/^[A-Z]/', $name)) {
        $score += 50; // Starts with uppercase.
      }

      if ($score > $best_score) {
        $best_score = $score;
        $best_tid = $tid;
      }
    }

    return (int) $best_tid;
  }

  /**
   * Gets usage count for a term.
   *
   * @param int $tid
   *   The term ID.
   *
   * @return int
   *   The usage count.
   */
  protected function getTermUsageCount(int $tid): int {
    $database = \Drupal::database();
    $count = 0;

    // Check common reference field table patterns.
    $tables = $database->schema()->findTables('node__%');
    foreach ($tables as $table) {
      // Look for target_id columns.
      try {
        $columns = $database->query("SHOW COLUMNS FROM {$table} LIKE '%target_id'")->fetchCol();
        foreach ($columns as $column) {
          $query = $database->select($table, 't')
            ->condition("t.$column", $tid)
            ->countQuery();
          $count += (int) $query->execute()->fetchField();
        }
      }
      catch (\Exception $e) {
        // Table doesn't exist or query failed.
      }
    }

    return $count;
  }

  /**
   * Gets count of fields with data on a term.
   *
   * @param \Drupal\taxonomy\Entity\Term $term
   *   The term entity.
   *
   * @return int
   *   Count of fields with data.
   */
  protected function getTermFieldDataCount($term): int {
    $skip_fields = [
      'tid', 'uuid', 'langcode', 'vid', 'name', 'description',
      'weight', 'parent', 'changed', 'default_langcode',
      'revision_created', 'revision_user', 'revision_log_message',
      'status', 'created', 'revision_default', 'revision_translation_affected',
      'metatag', 'path',
    ];

    $count = 0;
    foreach ($term->getFieldDefinitions() as $field_name => $definition) {
      if (in_array($field_name, $skip_fields)) {
        continue;
      }
      if ($term->hasField($field_name) && !$term->get($field_name)->isEmpty()) {
        $count++;
      }
    }

    return $count;
  }

  /**
   * Checks if a group is already found in suggestions.
   *
   * @param array $group
   *   The group to check.
   * @param array $suggestions
   *   Existing suggestions.
   *
   * @return bool
   *   TRUE if already found.
   */
  protected function groupAlreadyFound(array $group, array $suggestions): bool {
    foreach ($suggestions as $suggestion) {
      $overlap = array_intersect($group, $suggestion['terms']);
      if (count($overlap) > 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Stores suggestions in state.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   * @param array $suggestions
   *   The suggestions to store.
   */
  protected function storeSuggestions(string $vocabulary_id, array $suggestions): void {
    $key = "ai_term_merge.suggestions.$vocabulary_id";
    $this->state->set($key, [
      'timestamp' => time(),
      'suggestions' => $suggestions,
    ]);

    $this->logger->info('Generated @count suggestions for vocabulary @vid', [
      '@count' => count($suggestions),
      '@vid' => $vocabulary_id,
    ]);
  }

}
