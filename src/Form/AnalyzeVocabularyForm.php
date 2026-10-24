<?php

declare(strict_types=1);

namespace Drupal\ai_term_merge\Form;

use Drupal\ai_term_merge\AiTermSuggestionGenerator;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\taxonomy\VocabularyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms vocabulary analysis before generating or storing suggestions.
 */
final class AnalyzeVocabularyForm extends ConfirmFormBase {

  /**
   * The vocabulary being analyzed.
   */
  protected VocabularyInterface $vocabulary;

  /**
   * Constructs the confirmation form.
   */
  public function __construct(protected AiTermSuggestionGenerator $suggestionGenerator) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new self($container->get('ai_term_merge.suggestion_generator'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_term_merge_analyze_vocabulary';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Analyze suggestions for @vocabulary?', ['@vocabulary' => $this->vocabulary->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This replaces the stored suggestions for this vocabulary. If semantic detection is enabled, analysis uses the configured AI provider. No terms will be merged or deleted.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Analyze');
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
  public function buildForm(array $form, FormStateInterface $form_state, ?VocabularyInterface $taxonomy_vocabulary = NULL) {
    if ($taxonomy_vocabulary === NULL) {
      throw new \InvalidArgumentException('Missing vocabulary for analysis confirmation.');
    }
    $this->vocabulary = $taxonomy_vocabulary;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    try {
      $suggestions = $this->suggestionGenerator->generateSuggestions($this->vocabulary->id());
      $this->messenger()->addStatus($this->t('Analysis complete. Found @count suggestion groups.', [
        '@count' => count($suggestions),
      ]));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Analysis failed: @error', ['@error' => $e->getMessage()]));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
