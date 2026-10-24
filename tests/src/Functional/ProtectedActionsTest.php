<?php

namespace Drupal\Tests\ai_term_merge\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies action confirmation and CSRF validation without provider calls.
 */
#[Group('ai_term_merge')]
#[RunTestsInSeparateProcesses]
final class ProtectedActionsTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ai_term_merge_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The existing module settings have no schema; unrelated to action CSRF.
   *
   * @var string[]
   */
  protected static $configSchemaCheckerExclusions = ['ai_term_merge.settings'];

  /**
   * Test actual permissions rather than the special UID 1 bypass.
   */
  protected bool $usesSuperUserAccessPolicy = FALSE;

  /**
   * Isolate autoload paths for the test site's module alias.
   *
   * @var bool
   */
  protected $apcuEnsureUniquePrefix = TRUE;

  /**
   * Prefer this package over any duplicate in the developer's modules/custom.
   */
  protected function prepareEnvironment() {
    parent::prepareEnvironment();
    $directory = $this->siteDirectory . '/modules';
    mkdir($directory, 0775, TRUE);
    symlink(dirname(__DIR__, 3), $directory . '/ai_term_merge');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    Vocabulary::create(['vid' => 'topics', 'name' => 'Topics'])->save();
    $this->config('ai_term_merge.settings')->set('enabled_vocabularies', ['topics'])->save();
    $this->drupalLogin($this->drupalCreateUser(['administer taxonomy']));
  }

  /**
   * GET/cancel and invalid POSTs cannot analyze; a confirmed POST can.
   */
  public function testAnalyzeRequiresConfirmationAndToken(): void {
    $path = '/admin/structure/taxonomy/manage/topics/ai-suggestions/analyze';
    $before = [['type' => 'quality_issue', 'terms' => [], 'canonical_tid' => 0]];
    $this->container->get('ai_term_merge.suggestion_storage')->storeSuggestions('topics', $before);
    $route = $this->container->get('router.route_provider')->getRouteByName('ai_term_merge.analyze');
    $this->assertSame('\\Drupal\\ai_term_merge\\Form\\AnalyzeVocabularyForm', $route->getDefault('_form'), $this->container->get('extension.list.module')->getPath('ai_term_merge'));
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('configured AI provider');
    $this->assertSession()->elementExists('css', 'form[method="post"]');
    $this->assertNoAnalysis();
    $this->assertSuggestions($before);
    $this->clickLink('Cancel');
    $this->assertNoAnalysis();

    foreach ([NULL, 'invalid-token'] as $token) {
      $this->postWithToken($path, $token);
      $this->assertNoAnalysis();
      $this->assertSuggestions($before);
    }
    $this->drupalGet($path);
    $this->submitForm([], 'Analyze');
    $this->container->get('state')->resetCache();
    $this->assertSame(1, $this->container->get('state')->get('ai_term_merge_test.calls'));
    $this->assertCount(1, $this->container->get('ai_term_merge.suggestion_storage')->getSuggestions('topics')['suggestions']);
    $this->assertSession()->addressEquals('/admin/structure/taxonomy/manage/topics/ai-suggestions');
  }

  /**
   * Reject removes only a confirmed suggestion, never terms or other records.
   */
  public function testRejectRequiresConfirmationAndToken(): void {
    $storage = $this->container->get('ai_term_merge.suggestion_storage');
    $suggestions = [
      ['type' => 'exact_duplicate', 'terms' => [], 'canonical_tid' => 0],
      ['type' => 'quality_issue', 'terms' => [], 'canonical_tid' => 0],
    ];
    $storage->storeSuggestions('topics', $suggestions);
    $path = '/admin/structure/taxonomy/manage/topics/ai-suggestions/0/reject';
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('No taxonomy terms will be changed');
    $this->assertSuggestions($suggestions);
    $this->clickLink('Cancel');
    $this->assertSuggestions($suggestions);
    foreach ([NULL, 'invalid-token'] as $token) {
      $this->postWithToken($path, $token);
      $this->assertSuggestions($suggestions);
    }
    $this->drupalGet($path);
    $this->submitForm([], 'Reject');
    $this->assertSuggestions([$suggestions[1]]);
    $this->assertNoAnalysis();
    // A cached confirmation must not remove a replacement at the same index.
    $this->drupalGet($path);
    $storage->storeSuggestions('topics', [$suggestions[0]]);
    $this->submitForm([], 'Reject');
    $this->assertSuggestions([$suggestions[0]]);
    $this->assertSession()->pageTextContains('Suggestions have changed');
    $this->drupalGet('/admin/structure/taxonomy/manage/topics/ai-suggestions/99/reject');
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Both actions remain inaccessible without taxonomy administration rights.
   */
  public function testPermissions(): void {
    $this->drupalLogout();
    foreach (['analyze', '0/reject'] as $action) {
      $this->drupalGet('/admin/structure/taxonomy/manage/topics/ai-suggestions/' . $action);
      $this->assertSession()->statusCodeEquals(403);
    }
    $this->assertNoAnalysis();
  }

  /**
   * Posts an otherwise valid browser form with a missing or invalid token.
   */
  private function postWithToken(string $path, ?string $token): void {
    $this->drupalGet($path);
    $page = $this->getSession()->getPage();
    $data = ['confirm' => '1', 'op' => $page->find('css', 'input[type="submit"]')->getValue()];
    foreach (['form_id', 'form_build_id'] as $name) {
      $data[$name] = $page->find('css', 'input[name="' . $name . '"]')->getValue();
    }
    if ($fingerprint = $page->find('css', 'input[name="suggestion_fingerprint"]')) {
      $data['suggestion_fingerprint'] = $fingerprint->getValue();
    }
    if ($token !== NULL) {
      $data['form_token'] = $token;
    }
    $this->getSession()->getDriver()->getClient()->request('POST', $this->getAbsoluteUrl($path), $data);
    $this->assertSession()->pageTextContains('The form has become outdated');
  }

  /**
   * Checks that no synthetic or real generator has been invoked.
   */
  private function assertNoAnalysis(): void {
    $this->container->get('state')->resetCache();
    $this->assertSame(0, $this->container->get('state')->get('ai_term_merge_test.calls', 0));
  }

  /**
   * Reads state fresh after a browser request.
   */
  private function assertSuggestions(array $expected): void {
    $this->container->get('state')->resetCache();
    $this->assertSame($expected, $this->container->get('ai_term_merge.suggestion_storage')->getSuggestions('topics')['suggestions']);
  }

}
