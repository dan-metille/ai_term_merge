<?php

declare(strict_types=1);

namespace Drupal\ai_term_merge\Form;

use Drupal\ai_term_merge\TermSuggestionStorage;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\taxonomy\VocabularyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirms rejection without modifying suggestions during GET requests.
 */
final class RejectSuggestionForm extends ConfirmFormBase {

  /**
   * The vocabulary owning the suggestion.
   */
  protected VocabularyInterface $vocabulary;

  /**
   * The suggestion's storage index.
   */
  protected string $suggestionId;

  /**
   * Constructs the confirmation form.
   */
  public function __construct(protected TermSuggestionStorage $suggestionStorage) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new self($container->get('ai_term_merge.suggestion_storage'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_term_merge_reject_suggestion';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Reject this suggestion for @vocabulary?', ['@vocabulary' => $this->vocabulary->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Only this stored suggestion will be removed. No taxonomy terms will be changed.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Reject');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('ai_term_merge.suggestions', ['taxonomy_vocabulary' => $this->vocabulary->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?VocabularyInterface $taxonomy_vocabulary = NULL,
    ?string $suggestion_id = NULL,
  ) {
    $suggestion = $taxonomy_vocabulary && $suggestion_id !== NULL
      ? $this->suggestionStorage->getSuggestion($taxonomy_vocabulary->id(), $suggestion_id) : NULL;
    if ($suggestion === NULL) {
      throw new NotFoundHttpException('Suggestion not found.');
    }
    $this->vocabulary = $taxonomy_vocabulary;
    $this->suggestionId = $suggestion_id;
    $form['suggestion_fingerprint'] = [
      '#type' => 'hidden',
      '#default_value' => hash('sha256', serialize($suggestion)),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Rejection reindexes storage; never reject a stale suggestion index.
    $current = $this->suggestionStorage->getSuggestion($this->vocabulary->id(), $this->suggestionId);
    if ($form_state->getValue('suggestion_fingerprint') !== hash('sha256', serialize($current))) {
      $this->messenger()->addWarning($this->t('Suggestions have changed. Please review them again.'));
    }
    else {
      $this->suggestionStorage->removeSuggestion($this->vocabulary->id(), $this->suggestionId);
      $this->messenger()->addStatus($this->t('Suggestion rejected.'));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
