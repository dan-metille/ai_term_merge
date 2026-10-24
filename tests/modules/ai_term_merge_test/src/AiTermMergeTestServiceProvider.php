<?php

namespace Drupal\ai_term_merge_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Replaces suggestion generation so functional tests cannot invoke providers.
 */
final class AiTermMergeTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $container->getDefinition('ai_term_merge.suggestion_generator')
      ->setClass(FakeSuggestionGenerator::class)
      ->setArguments([]);
  }

}
