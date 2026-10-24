<?php

namespace Drupal\ai_term_merge\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\taxonomy\VocabularyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ai_term_merge\TermSuggestionStorage;

/**
 * Controller for suggestion review interface.
 */
class SuggestionReviewController extends ControllerBase {

  /**
   * The suggestion storage service.
   *
   * @var \Drupal\ai_term_merge\TermSuggestionStorage
   */
  protected TermSuggestionStorage $suggestionStorage;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->suggestionStorage = $container->get('ai_term_merge.suggestion_storage');
    $instance->dateFormatter = $container->get('date.formatter');
    return $instance;
  }

  /**
   * Review suggestions page.
   *
   * @param \Drupal\taxonomy\VocabularyInterface $taxonomy_vocabulary
   *   The vocabulary.
   *
   * @return array
   *   Render array.
   */
  public function reviewSuggestions(VocabularyInterface $taxonomy_vocabulary): array {
    $vocabulary_id = $taxonomy_vocabulary->id();
    $config = $this->config('ai_term_merge.settings');
    $enabled_vocabularies = $config->get('enabled_vocabularies') ?: [];

    // Check if vocabulary is enabled.
    if (!in_array($vocabulary_id, $enabled_vocabularies)) {
      $this->messenger()->addWarning($this->t('This vocabulary is not enabled for AI Term Merge. <a href=":url">Configure settings</a>.', [
        ':url' => Url::fromRoute('ai_term_merge.settings')->toString(),
      ]));
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-term-merge-review']],
    ];

    // Header.
    $build['header'] = [
      '#markup' => '<h2>' . $this->t('AI Merge Suggestions for @vocab', ['@vocab' => $taxonomy_vocabulary->label()]) . '</h2>',
    ];

    // Get existing suggestions.
    $data = $this->suggestionStorage->getSuggestions($vocabulary_id);
    $suggestions = $data['suggestions'] ?? [];
    $timestamp = $data['timestamp'] ?? NULL;

    // Summary.
    if ($timestamp) {
      $build['summary'] = [
        '#markup' => '<p>' . $this->t('Last analyzed: @date. Found @count suggestion groups.', [
          '@date' => $this->dateFormatter->format($timestamp, 'short'),
          '@count' => count($suggestions),
        ]) . '</p>',
      ];
    }
    else {
      $build['summary'] = [
        '#markup' => '<p>' . $this->t('This vocabulary has not been analyzed yet.') . '</p>',
      ];
    }

    // Actions.
    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['form-actions', 'margin-bottom']],
    ];

    $build['actions']['analyze'] = [
      '#type' => 'link',
      '#title' => $this->t('Analyze Now'),
      '#url' => Url::fromRoute('ai_term_merge.analyze', ['taxonomy_vocabulary' => $vocabulary_id]),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
    ];

    // Display suggestions table.
    if (!empty($suggestions)) {
      $build['suggestions'] = $this->buildSuggestionsTable($suggestions, $taxonomy_vocabulary);
    }
    else {
      $build['no_suggestions'] = [
        '#markup' => '<p><em>' . $this->t('No suggestions found. Click "Analyze Now" to detect duplicates.') . '</em></p>',
      ];
    }

    return $build;
  }

  /**
   * Builds the suggestions table.
   *
   * @param array $suggestions
   *   Array of suggestions.
   * @param \Drupal\taxonomy\VocabularyInterface $vocabulary
   *   The vocabulary.
   *
   * @return array
   *   Render array.
   */
  protected function buildSuggestionsTable(array $suggestions, VocabularyInterface $vocabulary): array {
    $term_storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $vocabulary_id = $vocabulary->id();

    $rows = [];
    foreach ($suggestions as $index => $suggestion) {
      $terms = $term_storage->loadMultiple($suggestion['terms']);
      $canonical_tid = $suggestion['canonical_tid'];

      // Build term list.
      $term_items = [];
      foreach ($terms as $term) {
        $name = $term->getName();
        $link = $term->toLink()->toString();
        if ((int) $term->id() === (int) $canonical_tid) {
          $term_items[] = '<strong>' . $link . '</strong> (canonical)';
        }
        else {
          $term_items[] = $link;
        }
      }

      // Type badge.
      $type_labels = [
        'exact_duplicate' => $this->t('Exact'),
        'semantic_duplicate' => $this->t('Semantic'),
        'quality_issue' => $this->t('Quality'),
      ];
      $type = $type_labels[$suggestion['type']] ?? $suggestion['type'];

      // Actions.
      $operations = [
        'merge' => [
          'title' => $this->t('Merge'),
          'url' => Url::fromRoute('ai_term_merge.merge_confirm', [
            'taxonomy_vocabulary' => $vocabulary_id,
            'suggestion_id' => $index,
          ]),
        ],
        'reject' => [
          'title' => $this->t('Reject'),
          'url' => Url::fromRoute('ai_term_merge.reject', [
            'taxonomy_vocabulary' => $vocabulary_id,
            'suggestion_id' => $index,
          ]),
        ],
      ];

      $rows[] = [
        'terms' => [
          'data' => ['#markup' => implode('<br>', $term_items)],
        ],
        'type' => $type,
        'count' => count($terms),
        'operations' => [
          'data' => [
            '#type' => 'operations',
            '#links' => $operations,
          ],
        ],
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Terms'),
        $this->t('Type'),
        $this->t('Count'),
        $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No suggestions found.'),
    ];
  }

}
