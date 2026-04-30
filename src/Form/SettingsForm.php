<?php

namespace Drupal\ai_term_merge\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ai\AiProviderPluginManager;

/**
 * Settings form for AI Term Merge module.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected AiProviderPluginManager $aiProvider;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->aiProvider = $container->get('ai.provider');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ai_term_merge.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_term_merge_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ai_term_merge.settings');

    // Vocabulary selection.
    $vocabularies = Vocabulary::loadMultiple();
    $options = [];
    foreach ($vocabularies as $vid => $vocabulary) {
      $options[$vid] = $vocabulary->label();
    }

    $form['enabled_vocabularies'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enabled Vocabularies'),
      '#description' => $this->t('Select which vocabularies should have AI duplicate detection enabled.'),
      '#options' => $options,
      '#default_value' => $config->get('enabled_vocabularies') ?: [],
    ];

    // AI Provider/Model selection.
    $form['ai_provider_model'] = [
      '#type' => 'select',
      '#title' => $this->t('AI Provider / Model'),
      '#description' => $this->t('Select the AI provider and model to use for duplicate detection. Leave empty to use default chat provider.'),
      '#options' => $this->aiProvider->getSimpleProviderModelOptions('chat'),
      '#default_value' => $config->get('ai_provider_model') ?: '',
    ];

    // Batch size.
    $form['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch Size'),
      '#description' => $this->t('Number of terms to analyze per AI request. Lower values are more accurate but slower.'),
      '#min' => 10,
      '#max' => 200,
      '#default_value' => $config->get('batch_size') ?: 50,
    ];

    // Detection modes.
    $form['detection_modes'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Detection Modes'),
    ];

    $detection_modes = $config->get('detection_modes') ?: [];

    $form['detection_modes']['exact_duplicates'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Exact Duplicates'),
      '#description' => $this->t('Detect terms with identical names.'),
      '#default_value' => $detection_modes['exact_duplicates'] ?? TRUE,
    ];

    $form['detection_modes']['semantic_duplicates'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Semantic Duplicates'),
      '#description' => $this->t('Use AI to detect terms that refer to the same entity (e.g., "John Smith" vs "Smith, John").'),
      '#default_value' => $detection_modes['semantic_duplicates'] ?? TRUE,
    ];

    $form['detection_modes']['quality_issues'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Quality Issues'),
      '#description' => $this->t('Detect terms that may need sanitization (e.g., usernames, photo credits).'),
      '#default_value' => $detection_modes['quality_issues'] ?? TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('ai_term_merge.settings')
      ->set('enabled_vocabularies', array_filter($form_state->getValue('enabled_vocabularies')))
      ->set('ai_provider_model', $form_state->getValue('ai_provider_model'))
      ->set('batch_size', $form_state->getValue('batch_size'))
      ->set('detection_modes', [
        'exact_duplicates' => (bool) $form_state->getValue('exact_duplicates'),
        'semantic_duplicates' => (bool) $form_state->getValue('semantic_duplicates'),
        'quality_issues' => (bool) $form_state->getValue('quality_issues'),
      ])
      ->save();

    parent::submitForm($form, $form_state);
  }

}
