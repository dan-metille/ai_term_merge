<?php

namespace Drupal\ai_term_merge\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\taxonomy\VocabularyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ai_term_merge\TermSuggestionStorage;
use Drupal\term_merge\TermMergerInterface;

/**
 * Confirmation form for merging terms.
 */
class MergeSuggestionForm extends ConfirmFormBase {

  /**
   * The suggestion storage service.
   *
   * @var \Drupal\ai_term_merge\TermSuggestionStorage
   */
  protected TermSuggestionStorage $suggestionStorage;

  /**
   * The term merger service.
   *
   * @var \Drupal\term_merge\TermMergerInterface
   */
  protected TermMergerInterface $termMerger;

  /**
   * The vocabulary.
   *
   * @var \Drupal\taxonomy\VocabularyInterface
   */
  protected VocabularyInterface $vocabulary;

  /**
   * The suggestion ID.
   *
   * @var string
   */
  protected string $suggestionId;

  /**
   * The suggestion data.
   *
   * @var array
   */
  protected array $suggestion;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->suggestionStorage = $container->get('ai_term_merge.suggestion_storage');
    $instance->termMerger = $container->get('term_merge.term_merger');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_term_merge_merge_suggestion';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to merge these terms?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('ai_term_merge.suggestions', [
      'taxonomy_vocabulary' => $this->vocabulary->id(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Merge');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, VocabularyInterface $taxonomy_vocabulary = NULL, string $suggestion_id = NULL) {
    $this->vocabulary = $taxonomy_vocabulary;
    $this->suggestionId = $suggestion_id;

    // Load the suggestion.
    $this->suggestion = $this->suggestionStorage->getSuggestion($taxonomy_vocabulary->id(), $suggestion_id);

    if (!$this->suggestion) {
      $this->messenger()->addError($this->t('Suggestion not found.'));
      return $this->redirect('ai_term_merge.suggestions', ['taxonomy_vocabulary' => $taxonomy_vocabulary->id()]);
    }

    $form = parent::buildForm($form, $form_state);

    // Load terms.
    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $terms = $term_storage->loadMultiple($this->suggestion['terms']);
    $canonical_tid = $this->suggestion['canonical_tid'];

    // Build options for canonical term selection.
    $options = [];
    foreach ($terms as $term) {
      $options[$term->id()] = $term->getName();
    }

    $form['terms_info'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Term'),
        $this->t('ID'),
        $this->t('Status'),
      ],
      '#weight' => -10,
    ];

    foreach ($terms as $tid => $term) {
      $is_canonical = (int) $tid === (int) $canonical_tid;
      $form['terms_info'][$tid] = [
        'name' => [
          '#markup' => $term->toLink()->toString(),
        ],
        'id' => [
          '#markup' => $tid,
        ],
        'status' => [
          '#markup' => $is_canonical
            ? '<strong>' . $this->t('Will be kept (canonical)') . '</strong>'
            : $this->t('Will be merged and deleted'),
        ],
      ];
    }

    $form['canonical_tid'] = [
      '#type' => 'radios',
      '#title' => $this->t('Select canonical term'),
      '#description' => $this->t('Choose which term to keep. All other terms will be merged into this one.'),
      '#options' => $options,
      '#default_value' => $canonical_tid,
      '#required' => TRUE,
    ];

    $form['description'] = [
      '#markup' => '<p>' . $this->t('All references to other terms will be updated to point to the canonical term, then the duplicate terms will be deleted.') . '</p>',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $canonical_tid = $form_state->getValue('canonical_tid');
    $vocabulary_id = $this->vocabulary->id();

    // Load terms.
    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $all_terms = $term_storage->loadMultiple($this->suggestion['terms']);

    if (!isset($all_terms[$canonical_tid])) {
      $this->messenger()->addError($this->t('Canonical term not found.'));
      return;
    }

    $canonical_term = $all_terms[$canonical_tid];
    unset($all_terms[$canonical_tid]);

    // Merge remaining terms into canonical.
    try {
      if (!empty($all_terms)) {
        $this->termMerger->mergeIntoTerm(array_values($all_terms), $canonical_term);
      }

      // Remove the suggestion.
      $this->suggestionStorage->removeSuggestion($vocabulary_id, $this->suggestionId);

      $this->messenger()->addStatus($this->t('Successfully merged @count terms into "@name".', [
        '@count' => count($all_terms),
        '@name' => $canonical_term->getName(),
      ]));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Merge failed: @error', [
        '@error' => $e->getMessage(),
      ]));
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
