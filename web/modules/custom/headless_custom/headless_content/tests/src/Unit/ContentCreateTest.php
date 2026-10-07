<?php

declare(strict_types=1);

namespace Drupal\headless_content;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\headless_content\Exception\ContentException;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Promise\CallbackPromise;
use Prophecy\Prophecy\ObjectProphecy;

/**
 * Tests for ContentService::create(), ContentService::options() and
 * ContentService::storeUploadedFile().
 *
 * ContentService::create() is the recipe, training and resource authoring write.
 * The properties that matter here are the ones a mistake would not surface loudly:
 * the audience is derived from the caller and never echoed from the payload, so
 * a field the route might one day expose has to be asserted absent rather than
 * trusted absent; and the vocabulary check runs per term, so an id from the
 * wrong vocabulary must be refused even when it resolves to a term that exists.
 * storeUploadedFile() is the upload half of a resource or training create and
 * must refuse a bad extension before any bytes are handed to the repository.
 *
 * @group headless_content
 * @coversDefaultClass \Drupal\headless_content\ContentService
 */
class ContentCreateTest extends TestCase {

  use ProphecyTrait;

  protected ObjectProphecy $entityTypeManager;

  protected ObjectProphecy $nodeStorage;

  protected ObjectProphecy $termStorage;

  protected ObjectProphecy $fileStorage;

  protected ObjectProphecy $fileUrlGenerator;

  protected ObjectProphecy $fileRepository;

  protected ObjectProphecy $fileSystem;

  protected ObjectProphecy $logger;

  protected ObjectProphecy $scope;

  /** The values passed to node storage create(), captured by the mock. */
  protected array $createdValues = [];

  /** The terms the taxonomy storage holds, keyed by id. */
  protected array $allTerms = [];

  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $this->nodeStorage = $this->prophesize(EntityStorageInterface::class);
    $this->termStorage = $this->prophesize(EntityStorageInterface::class);

    $this->entityTypeManager->getStorage('node')->willReturn($this->nodeStorage->reveal());
    $this->entityTypeManager->getStorage('taxonomy_term')->willReturn($this->termStorage->reveal());

    // Resource create with a file loads the managed file to prove it exists, and
    // the returned row re-loads it to serialise the reference.
    $this->fileStorage = $this->prophesize(EntityStorageInterface::class);
    $this->entityTypeManager->getStorage('file')->willReturn($this->fileStorage->reveal());

    // Author resolution in serialise(): the created node carries the caller as
    // owner, so author() loads the user by uid.
    $userStorage = $this->prophesize(EntityStorageInterface::class);
    $user = $this->prophesize(\Drupal\Core\Session\AccountInterface::class);
    $user->getAccountName()->willReturn('Dr. Author');
    $userStorage->load(7)->willReturn($user->reveal());
    $this->entityTypeManager->getStorage('user')->willReturn($userStorage->reveal());

    $this->allTerms = [
      12 => $this->term(12, 'recipe_category', 'Poultry'),
      14 => $this->term(14, 'recipe_category', 'Salads'),
      20 => $this->term(20, 'recipe_types', 'Free Item'),
      30 => $this->term(30, 'resource_category', 'Guides'),
    ];

    // loadMultiple() answers both halves of a create: the validation lookup and
    // the serialisation's term label resolution. It is a *loader*, so it returns
    // only the ids it was asked for — feeding every term back for every request
    // is what makes an options list mix its vocabularies. `CallbackPromise` is
    // the backward-compatible form of `willReturnCallback`, which this container's
    // prophecy predates — and it rebinds the closure's `$this` to the double, so
    // the term map is captured by value rather than read off the test.
    $allTerms = $this->allTerms;
    $this->termStorage->loadMultiple(Argument::type('array'))->will(new CallbackPromise(
      function (array $args) use ($allTerms): array {
        $terms = [];
        foreach ($args[0] as $id) {
          $id = (int) $id;
          if (isset($allTerms[$id])) {
            $terms[$id] = $allTerms[$id];
          }
        }
        return $terms;
      }
    ));

    $this->scope = $this->prophesize(ContentScope::class);
    $this->scope->requireClinicId(Argument::any())->willReturn(31);
    $this->scope->canSee(Argument::cetera())->willReturn(TRUE);
  }

  /**
   * A ContentService over the fixtures.
   *
   * The file dependencies are properties rather than one-off reveals so the
   * upload and file-field tests can stub them before calling in.
   */
  protected function service(): ContentService {
    $renderer = $this->prophesize(RendererInterface::class);
    $renderer->renderInIsolation(Argument::any())->willReturn('<p>Text.</p>');

    $favourites = $this->prophesize(ContentFavorites::class);
    $favourites->appliesTo('recipe')->willReturn(TRUE);
    $favourites->appliesTo('chirothin_resource')->willReturn(FALSE);
    $favourites->appliesTo('training')->willReturn(FALSE);

    $this->fileUrlGenerator = $this->prophesize(FileUrlGeneratorInterface::class);
    $this->fileUrlGenerator->generateAbsoluteString(Argument::any())
      ->willReturn('https://example.test/sites/default/files/protocol-guide.pdf');

    $this->fileRepository = $this->prophesize(FileRepositoryInterface::class);
    $this->fileSystem = $this->prophesize(FileSystemInterface::class);
    $this->logger = $this->prophesize(LoggerChannelInterface::class);

    return new ContentService(
      $this->entityTypeManager->reveal(),
      $this->scope->reveal(),
      $this->prophesize(ContentCapabilities::class)->reveal(),
      $favourites->reveal(),
      $this->fileUrlGenerator->reveal(),
      $renderer->reveal(),
      $this->logger->reveal(),
      $this->fileRepository->reveal(),
      $this->fileSystem->reveal(),
    );
  }

  /**
   * The caller, as the account path delivers it.
   */
  protected function account(): AccountInterface {
    $account = $this->prophesize(AccountInterface::class);
    $account->id()->willReturn('7');
    $account->isAuthenticated()->willReturn(TRUE);

    return $account->reveal();
  }

  /**
   * A taxonomy term of the given vocabulary.
   */
  protected function term(int $id, string $vid, string $label): TermInterface {
    $term = $this->prophesize(TermInterface::class);
    $term->id()->willReturn((string) $id);
    $term->bundle()->willReturn($vid);
    $term->label()->willReturn($label);

    return $term->reveal();
  }

  /**
   * A published recipe node whose fields the serialiser can read.
   *
   * The node is registered with the storage so `create()` returns it, and the
   * values passed to create() are captured for assertion. `save()` is a plain
   * stub rather than a `shouldBeCalled()`: the rejection tests reach `create()`
   * without a save, and a should-prediction on a shared fixture would fail them
   * at teardown. The rejection tests assert `create()` was never called instead,
   * which is the stronger claim.
   *
   * @param array<string, array<int, array<string, mixed>>> $fields
   *   `getValue()` result per field. Defaults to no body, two categories, one type.
   */
  protected function recipeNode(array $fields = []): NodeInterface {
    $node = $this->prophesize(NodeInterface::class);
    $node->id()->willReturn('77');
    $node->bundle()->willReturn('recipe');
    $node->label()->willReturn('Test Salad');
    $node->isPublished()->willReturn(TRUE);
    $node->getOwnerId()->willReturn(7);
    $node->save()->willReturn(NULL);

    // The catch-all first: the serialiser asks about every field the bundle might
    // have, and the fields it actually reads are named below.
    $node->hasField(Argument::any())->willReturn(FALSE);
    foreach (['body', 'field_recipe_category', 'field_recipe_type', 'field_recipe_ingredient'] as $field) {
      $node->hasField($field)->willReturn(TRUE);
    }

    $getValue = $fields + [
      'body' => [],
      'field_recipe_category' => [['target_id' => 12], ['target_id' => 14]],
      'field_recipe_type' => [['target_id' => 20]],
      'field_recipe_ingredient' => [['value' => 'Spinach'], ['value' => 'Olive oil']],
    ];

    foreach ($getValue as $field => $values) {
      $node->get($field)->willReturn(new FieldItemListDouble($values));
    }

    // Base fields, read as `->value` for created/updated.
    foreach (['created' => '1790598000', 'changed' => '1790598000'] as $base => $value) {
      $node->get($base)->willReturn(new BaseFieldDouble($value));
    }

    $revealed = $node->reveal();

    $this->nodeStorage->create(Argument::that(function (array $values): bool {
      $this->createdValues = $values;
      return TRUE;
    }))->willReturn($revealed);

    return $revealed;
  }

  /**
   * A valid create payload.
   *
   * @param array<string, mixed> $overrides
   */
  protected function payload(array $overrides = []): array {
    return $overrides + [
      'title' => 'Test Salad',
      'categories' => [12, 14],
      'types' => [20],
      'ingredients' => ['Spinach', 'Olive oil'],
      'body' => 'Mix it.',
    ];
  }

  /**
   * A managed file, as `file` storage load() returns it.
   */
  protected function managedFile(): FileInterface {
    $file = $this->prophesize(FileInterface::class);
    $file->getFileUri()->willReturn('public://files/protocol-guide.pdf');
    $file->getFilename()->willReturn('protocol-guide.pdf');
    $file->getMimeType()->willReturn('application/pdf');
    $file->getSize()->willReturn(2048);

    return $file->reveal();
  }

  /**
   * A publishable resource node whose fields the serialiser can read.
   *
   * Same contract as {@see recipeNode()}: registered with the storage so
   * `create()` returns it, values captured for assertion.
   *
   * @param array<string, array<int, array<string, mixed>>> $fields
   *   `getValue()` result per field. Defaults to one category, a description, a file.
   */
  protected function resourceNode(array $fields = []): NodeInterface {
    $node = $this->prophesize(NodeInterface::class);
    $node->id()->willReturn('78');
    $node->bundle()->willReturn('chirothin_resource');
    $node->label()->willReturn('Protocol Guide');
    $node->isPublished()->willReturn(TRUE);
    $node->getOwnerId()->willReturn(7);
    $node->save()->willReturn(NULL);

    $node->hasField(Argument::any())->willReturn(FALSE);
    foreach (['field_description', 'field_resource_type', 'field_resource'] as $field) {
      $node->hasField($field)->willReturn(TRUE);
    }

    $getValue = $fields + [
      'field_description' => [['value' => 'The steps.']],
      'field_resource_type' => [['target_id' => 30]],
      'field_resource' => [],
    ];

    foreach ($getValue as $field => $values) {
      $node->get($field)->willReturn(new FieldItemListDouble($values));
    }

    // Base fields, read as `->value` for created/updated.
    foreach (['created' => '1790598100', 'changed' => '1790598100'] as $base => $value) {
      $node->get($base)->willReturn(new BaseFieldDouble($value));
    }

    $revealed = $node->reveal();

    $this->nodeStorage->create(Argument::that(function (array $values): bool {
      $this->createdValues = $values;
      return TRUE;
    }))->willReturn($revealed);

    return $revealed;
  }

  /**
   * A valid resource create payload.
   *
   * @param array<string, mixed> $overrides
   */
  protected function resourcePayload(array $overrides = []): array {
    return $overrides + [
      'title' => 'Protocol Guide',
      'resourceTypes' => [30],
      'description' => 'The steps.',
      'resource' => 998,
    ];
  }

  /**
   * A valid training create payload.
   *
   * @param array<string, mixed> $overrides
   */
  protected function trainingPayload(array $overrides = []): array {
    return $overrides + [
      'title' => 'Phase One Video',
      'body' => 'Watch this.',
      'video' => 997,
    ];
  }

  /**
   * A publishable training node whose fields the serialiser can read.
   *
   * Same contract as {@see recipeNode()}: registered with the storage so
   * `create()` returns it, values captured for assertion.
   *
   * @param array<string, array<int, array<string, mixed>>> $fields
   *   `getValue()` result per field. Defaults to a basic_html body and a video.
   */
  protected function trainingNode(array $fields = []): NodeInterface {
    $node = $this->prophesize(NodeInterface::class);
    $node->id()->willReturn('80');
    $node->bundle()->willReturn('training');
    $node->label()->willReturn('Phase One Video');
    $node->isPublished()->willReturn(TRUE);
    $node->getOwnerId()->willReturn(7);
    $node->save()->willReturn(NULL);

    $node->hasField(Argument::any())->willReturn(FALSE);
    foreach (['body', 'field_video'] as $field) {
      $node->hasField($field)->willReturn(TRUE);
    }

    $getValue = $fields + [
      'body' => [['value' => 'Watch this.', 'format' => 'basic_html']],
      'field_video' => [],
    ];

    foreach ($getValue as $field => $values) {
      $node->get($field)->willReturn(new FieldItemListDouble($values));
    }

    // Base fields, read as `->value` for created/updated.
    foreach (['created' => '1790598200', 'changed' => '1790598200'] as $base => $value) {
      $node->get($base)->willReturn(new BaseFieldDouble($value));
    }

    $revealed = $node->reveal();

    $this->nodeStorage->create(Argument::that(function (array $values): bool {
      $this->createdValues = $values;
      return TRUE;
    }))->willReturn($revealed);

    return $revealed;
  }

  /**
   * A managed training video, as `file` storage load() returns it.
   */
  protected function trainingVideoFile(): FileInterface {
    $file = $this->prophesize(FileInterface::class);
    $file->getFileUri()->willReturn('public://videos/phase-one.mp4');
    $file->getFilename()->willReturn('phase-one.mp4');
    $file->getMimeType()->willReturn('video/mp4');
    $file->getSize()->willReturn(1048576);

    return $file->reveal();
  }

  /**
   * The happy path: a published recipe, scoped to the caller's clinic.
   */
  public function testCreatesRecipeScopedToCallerClinic(): void {
    $this->recipeNode();

    $row = $this->service()->create(
      'recipe',
      $this->account(),
      $this->payload(),
      ContentVisibility::clinicScoped('recipe', 31),
    );

    $this->assertSame(77, $row['id']);
    $this->assertSame('recipe', $row['bundle']);
    $this->assertSame('Test Salad', $row['title']);
    $this->assertSame(
      [['id' => 12, 'label' => 'Poultry'], ['id' => 14, 'label' => 'Salads']],
      $row['categories'],
    );

    $this->assertSame('Test Salad', $this->createdValues['title']);
    // The bundle key. A real node storage throws "Missing bundle" without it, so
    // this is asserted even though the mocked storage accepts anything — the mock
    // is precisely why a missing `type` shipped on the first live create.
    $this->assertSame('recipe', $this->createdValues['type']);
    $this->assertSame(7, $this->createdValues['uid']);
    $this->assertSame(1, $this->createdValues['status']);
    // The audience is the caller's clinic, never anything from the payload.
    $this->assertSame([31], $this->createdValues[ContentScope::FIELD_PUBLISHED_TO]);
    $this->assertSame([12, 14], $this->createdValues['field_recipe_category']);
    $this->assertSame([20], $this->createdValues['field_recipe_type']);
    $this->assertSame(['Spinach', 'Olive oil'], $this->createdValues['field_recipe_ingredient']);
    $this->assertSame([['value' => 'Mix it.', 'format' => 'basic_html']], $this->createdValues['body']);
  }

  /**
   * Client-supplied audience fields are ignored, not stored or echoed.
   */
  public function testIgnoresClientSuppliedAudienceFields(): void {
    $this->recipeNode();

    $this->service()->create(
      'recipe',
      $this->account(),
      $this->payload([
        'field_published_to' => [999],
        'field_my_clinic' => 999,
      ]),
      ContentVisibility::clinicScoped('recipe', 31),
    );

    $this->assertSame([31], $this->createdValues[ContentScope::FIELD_PUBLISHED_TO]);
    $this->assertArrayNotHasKey('field_my_clinic', $this->createdValues);
  }

  /**
   * A payload without a title is a 400, and nothing is created.
   */
  public function testRejectsMissingTitle(): void {
    $this->recipeNode();
    $this->nodeStorage->create(Argument::any())->shouldNotBeCalled();

    try {
      $this->service()->create(
        'recipe',
        $this->account(),
        $this->payload(['title' => '']),
        ContentVisibility::clinicScoped('recipe', 31),
      );
      $this->fail('Expected a ContentException');
    }
    catch (ContentException $e) {
      $this->assertSame(400, $e->getStatusCode());
      $this->assertStringContainsString('"title" is required', $e->getMessage());
    }
  }

  /**
   * More than the category field's cardinality is a 400.
   */
  public function testRejectsMoreThanTwoCategories(): void {
    $this->recipeNode();

    try {
      $this->service()->create(
        'recipe',
        $this->account(),
        $this->payload(['categories' => [12, 14, 20]]),
        ContentVisibility::clinicScoped('recipe', 31),
      );
      $this->fail('Expected a ContentException');
    }
    catch (ContentException $e) {
      $this->assertSame(400, $e->getStatusCode());
      $this->assertStringContainsString('"categories" accepts at most 2 terms', $e->getMessage());
    }
  }

  /**
   * A term from the wrong vocabulary is refused even when the term exists.
   *
   * 20 is a real `recipe_types` term, and the check must not stop at "the id
   * resolves". Passing it as a category would store a recipe whose group heading
   * is a type — exactly the drift between "the form offers" and "the field
   * accepts" that the options list exists to prevent.
   */
  public function testRejectsTermFromAnotherVocabulary(): void {
    $this->recipeNode();

    try {
      $this->service()->create(
        'recipe',
        $this->account(),
        $this->payload(['categories' => [20]]),
        ContentVisibility::clinicScoped('recipe', 31),
      );
      $this->fail('Expected a ContentException');
    }
    catch (ContentException $e) {
      $this->assertSame(400, $e->getStatusCode());
      $this->assertStringContainsString('Term 20 is not a "categories" option', $e->getMessage());
    }
  }

  /**
   * The training happy path: body and video stored, audience forced to the clinic.
   *
   * The `cannot create` guard in create() is deliberately left untested: every
   * bundle in {@see ContentService::BUNDLES} is now creatable, so the guard's
   * unreachable branch cannot be exercised through the public API. It stays in
   * the service as the half of the route regex that lives beside the code, for
   * the day a bundle is added to BUNDLES but not to CREATE_BUNDLES.
   */
  public function testCreatesTrainingScopedToCallerClinic(): void {
    $this->trainingNode(['field_video' => [['target_id' => 997]]]);
    $this->fileStorage->load(997)->willReturn($this->trainingVideoFile());

    $row = $this->service()->create(
      'training',
      $this->account(),
      $this->trainingPayload(),
      ContentVisibility::clinicScoped('training', 31),
    );

    $this->assertSame(80, $row['id']);
    $this->assertSame('training', $row['bundle']);
    $this->assertSame('Phase One Video', $row['title']);
    // Stored under basic_html, so the body serialises rendered like the imported
    // training items, not escaped like a resource description.
    $this->assertSame(['text' => 'Watch this.', 'html' => '<p>Text.</p>'], $row['body']);
    $this->assertSame('https://example.test/sites/default/files/protocol-guide.pdf', $row['video']['url']);
    $this->assertSame('phase-one.mp4', $row['video']['name']);
    $this->assertSame('video/mp4', $row['video']['mime']);
    $this->assertSame(1048576, $row['video']['size']);

    $this->assertSame('Phase One Video', $this->createdValues['title']);
    $this->assertSame('training', $this->createdValues['type']);
    $this->assertSame(7, $this->createdValues['uid']);
    $this->assertSame(1, $this->createdValues['status']);
    // The audience is the caller's clinic, never anything from the payload.
    $this->assertSame([31], $this->createdValues[ContentScope::FIELD_PUBLISHED_TO]);
    $this->assertSame([['value' => 'Watch this.', 'format' => 'basic_html']], $this->createdValues['body']);
    $this->assertSame(['target_id' => 997], $this->createdValues['field_video']);
  }

  /**
   * A training item without a video or body: both fields stay unset.
   *
   * `field_video` is not required on the bundle (a number of the existing
   * training items are body text and an embed), and a body is optional too, so
   * a create that names neither stores neither.
   */
  public function testCreatesTrainingWithoutVideoOrBody(): void {
    $this->trainingNode();
    $this->fileStorage->load(997)->shouldNotBeCalled();

    $this->service()->create(
      'training',
      $this->account(),
      $this->trainingPayload(['video' => NULL, 'body' => '   ']),
      ContentVisibility::clinicScoped('training', 31),
    );

    $this->assertArrayNotHasKey('field_video', $this->createdValues);
    $this->assertArrayNotHasKey('body', $this->createdValues);
  }

  /**
   * A video id that names no file is a 400, not a dangling reference.
   */
  public function testRejectsTrainingVideoThatDoesNotExist(): void {
    $this->trainingNode();

    try {
      $this->service()->create(
        'training',
        $this->account(),
        $this->trainingPayload(['video' => 999]),
        ContentVisibility::clinicScoped('training', 31),
      );
      $this->fail('Expected a ContentException');
    }
    catch (ContentException $e) {
      $this->assertSame(400, $e->getStatusCode());
      $this->assertStringContainsString('The "video" file does not exist', $e->getMessage());
    }
  }

  /**
   * A blank body is not stored; the salt is left the field's default.
   */
  public function testBlankBodyIsNotStored(): void {
    $this->recipeNode();

    $this->service()->create(
      'recipe',
      $this->account(),
      $this->payload(['body' => '   ']),
      ContentVisibility::clinicScoped('recipe', 31),
    );

    $this->assertArrayNotHasKey('body', $this->createdValues);
  }

  /**
   * Blank ingredient lines are dropped before storage.
   */
  public function testBlankIngredientsAreDropped(): void {
    $this->recipeNode();

    $this->service()->create(
      'recipe',
      $this->account(),
      $this->payload(['ingredients' => ['Spinach', '   ', ''], 'categories' => [12]]),
      ContentVisibility::clinicScoped('recipe', 31),
    );

    $this->assertSame(['Spinach'], $this->createdValues['field_recipe_ingredient']);
  }

  /**
   * The create form's option lists come from the two recipe vocabularies.
   *
   * Two separate vocabularies is the whole point of the options endpoint: a
   * category is checked against `recipe_category` and a type against
   * `recipe_types`. The `vid` condition is captured so this test exercises the
   * real branching rather than one vocabulary twice.
   */
  public function testOptionsReturnBothVocabularies(): void {
    $lastVid = null;

    $query = $this->prophesize(\Drupal\Core\Entity\Query\QueryInterface::class);
    $query->accessCheck(FALSE)->willReturn($query);
    $query->condition('vid', Argument::that(function (string $vid) use (&$lastVid): bool {
      $lastVid = $vid;
      return TRUE;
    }))->willReturn($query);
    $query->sort('name')->willReturn($query);
    $query->execute()->will(new CallbackPromise(function (array $args) use (&$lastVid): array {
      return $lastVid === 'recipe_category'
        ? [14 => 14, 12 => 12]
        : [20 => 20];
    }));
    $this->termStorage->getQuery()->willReturn($query->reveal());

    $options = $this->service()->options('recipe');

    $this->assertSame([
      'categories' => [
        ['id' => 14, 'label' => 'Salads'],
        ['id' => 12, 'label' => 'Poultry'],
      ],
      'types' => [
        ['id' => 20, 'label' => 'Free Item'],
      ],
    ], $options);
  }

  /**
   * A bundle with no form has no option list, and says so.
   */
  public function testOptionsRejectUnknownBundle(): void {
    try {
      $this->service()->options('training');
      $this->fail('Expected a ContentException');
    }
    catch (ContentException $e) {
      $this->assertSame(404, $e->getStatusCode());
    }
  }

  /**
   * The resource happy path: category, description and file all stored and echoed.
   */
  public function testCreatesResourceScopedToCallerClinic(): void {
    $this->resourceNode(['field_resource' => [['target_id' => 998]]]);
    $this->fileStorage->load(998)->willReturn($this->managedFile());

    $row = $this->service()->create(
      'chirothin_resource',
      $this->account(),
      $this->resourcePayload(),
      ContentVisibility::clinicScoped('chirothin_resource', 31),
    );

    $this->assertSame(78, $row['id']);
    $this->assertSame('chirothin_resource', $row['bundle']);
    $this->assertSame('Protocol Guide', $row['title']);
    $this->assertSame([['id' => 30, 'label' => 'Guides']], $row['resourceTypes']);
    // Stored without a format, so it serialises as escaped plain text either way.
    $this->assertSame(['text' => 'The steps.', 'html' => 'The steps.'], $row['description']);
    $this->assertSame('https://example.test/sites/default/files/protocol-guide.pdf', $row['resource']['url']);
    $this->assertSame('protocol-guide.pdf', $row['resource']['name']);
    $this->assertSame('application/pdf', $row['resource']['mime']);
    $this->assertSame(2048, $row['resource']['size']);

    $this->assertSame('Protocol Guide', $this->createdValues['title']);
    $this->assertSame('chirothin_resource', $this->createdValues['type']);
    $this->assertSame(7, $this->createdValues['uid']);
    $this->assertSame(1, $this->createdValues['status']);
    $this->assertSame([31], $this->createdValues[ContentScope::FIELD_PUBLISHED_TO]);
    $this->assertSame([30], $this->createdValues['field_resource_type']);
    $this->assertSame([['value' => 'The steps.']], $this->createdValues['field_description']);
    $this->assertSame(['target_id' => 998], $this->createdValues['field_resource']);
  }

  /**
   * A resource without a file: `field_resource` and empty descriptions stay unset.
   */
  public function testCreatesResourceWithoutFile(): void {
    $this->resourceNode();
    $this->fileStorage->load(998)->shouldNotBeCalled();

    $this->service()->create(
      'chirothin_resource',
      $this->account(),
      $this->resourcePayload(['resource' => NULL, 'description' => '   ']),
      ContentVisibility::clinicScoped('chirothin_resource', 31),
    );

    $this->assertArrayNotHasKey('field_resource', $this->createdValues);
    $this->assertArrayNotHasKey('field_description', $this->createdValues);
  }

  /**
   * A resource type from the wrong vocabulary is refused even when the term exists.
   *
   * 20 is a real `recipe_types` term, and `resource_category` must not accept it
   * just because it resolves.
   */
  public function testRejectsResourceTypeFromAnotherVocabulary(): void {
    $this->resourceNode();

    try {
      $this->service()->create(
        'chirothin_resource',
        $this->account(),
        $this->resourcePayload(['resourceTypes' => [20]]),
        ContentVisibility::clinicScoped('chirothin_resource', 31),
      );
      $this->fail('Expected a ContentException');
    }
    catch (ContentException $e) {
      $this->assertSame(400, $e->getStatusCode());
      $this->assertStringContainsString('Term 20 is not a "resourceTypes" option', $e->getMessage());
    }
  }

  /**
   * The resource category field is required (cardinality 1, but required).
   */
  public function testRejectsMissingResourceType(): void {
    $this->resourceNode();

    try {
      $this->service()->create(
        'chirothin_resource',
        $this->account(),
        $this->resourcePayload(['resourceTypes' => []]),
        ContentVisibility::clinicScoped('chirothin_resource', 31),
      );
      $this->fail('Expected a ContentException');
    }
    catch (ContentException $e) {
      $this->assertSame(400, $e->getStatusCode());
      $this->assertStringContainsString('"resourceTypes" needs at least 1 term', $e->getMessage());
    }
  }

  /**
   * The resource category field is cardinality 1: two terms are a 400.
   */
  public function testRejectsMoreThanOneResourceType(): void {
    $this->resourceNode();

    try {
      $this->service()->create(
        'chirothin_resource',
        $this->account(),
        $this->resourcePayload(['resourceTypes' => [30, 12]]),
        ContentVisibility::clinicScoped('chirothin_resource', 31),
      );
      $this->fail('Expected a ContentException');
    }
    catch (ContentException $e) {
      $this->assertSame(400, $e->getStatusCode());
      $this->assertStringContainsString('"resourceTypes" accepts at most 1 terms', $e->getMessage());
    }
  }

  /**
   * A resource file id that names no file is a 400, not a dangling reference.
   */
  public function testRejectsResourceFidThatDoesNotExist(): void {
    $this->resourceNode();

    try {
      $this->service()->create(
        'chirothin_resource',
        $this->account(),
        $this->resourcePayload(['resource' => 999]),
        ContentVisibility::clinicScoped('chirothin_resource', 31),
      );
      $this->fail('Expected a ContentException');
    }
    catch (ContentException $e) {
      $this->assertSame(400, $e->getStatusCode());
      $this->assertStringContainsString('The "resource" file does not exist', $e->getMessage());
    }
  }

  /**
   * A non-integer file id is a 400 before any storage lookup.
   */
  public function testRejectsMalformedResourceFid(): void {
    $this->resourceNode();

    try {
      $this->service()->create(
        'chirothin_resource',
        $this->account(),
        $this->resourcePayload(['resource' => '998']),
        ContentVisibility::clinicScoped('chirothin_resource', 31),
      );
      $this->fail('Expected a ContentException');
    }
    catch (ContentException $e) {
      $this->assertSame(400, $e->getStatusCode());
      $this->assertStringContainsString('"resource" must be a whole positive file id', $e->getMessage());
    }
  }

  /**
   * The resource form's option list comes from the `resource_category` vocabulary.
   */
  public function testResourceOptionsReturnTheCategoryVocabulary(): void {
    $lastVid = null;

    $query = $this->prophesize(\Drupal\Core\Entity\Query\QueryInterface::class);
    $query->accessCheck(FALSE)->willReturn($query);
    $query->condition('vid', Argument::that(function (string $vid) use (&$lastVid): bool {
      $lastVid = $vid;
      return TRUE;
    }))->willReturn($query);
    $query->sort('name')->willReturn($query);
    $query->execute()->will(new CallbackPromise(function (array $args) use (&$lastVid): array {
      return $lastVid === 'resource_category' ? [30 => 30] : [];
    }));
    $this->termStorage->getQuery()->willReturn($query->reveal());

    $options = $this->service()->options('chirothin_resource');

    $this->assertSame([
      'resourceTypes' => [
        ['id' => 30, 'label' => 'Guides'],
      ],
    ], $options);
  }

  /**
   * storeUploadedFile(): the resource upload path persists bytes under public://.
   */
  public function testStoreResourceFileStoresBytes(): void {
    $service = $this->service();
    $this->fileSystem->basename(Argument::cetera())->willReturn('guide.pdf');
    $stored = $this->prophesize(FileInterface::class);
    $stored->id()->willReturn('998');
    $stored->getFilename()->willReturn('guide.pdf');
    $stored->getMimeType()->willReturn('application/pdf');
    $this->fileRepository->writeData('bytes', 'public://guide.pdf', FileExists::Rename)
      ->willReturn($stored->reveal());

    $result = $service->storeUploadedFile('Guide.pdf', 'bytes');

    $this->assertSame([
      'fid' => 998,
      'name' => 'guide.pdf',
      'size' => 5,
      'mime' => 'application/pdf',
    ], $result);
  }

  /**
   * An extension outside the allowlist is refused before any bytes are written.
   */
  public function testStoreResourceFileRejectsDisallowedExtension(): void {
    $service = $this->service();
    $this->fileRepository->writeData(Argument::cetera())->shouldNotBeCalled();

    try {
      $service->storeUploadedFile('evil.php', '<?php echo "x";');
      $this->fail('Expected a ContentException');
    }
    catch (ContentException $e) {
      $this->assertSame(400, $e->getStatusCode());
      $this->assertStringContainsString('This file type is not allowed', $e->getMessage());
    }
  }

  /**
   * An empty upload is a 400, not a zero-byte file.
   */
  public function testStoreResourceFileRejectsEmptyFile(): void {
    $service = $this->service();
    $this->fileRepository->writeData(Argument::cetera())->shouldNotBeCalled();

    try {
      $service->storeUploadedFile('Guide.pdf', '');
      $this->fail('Expected a ContentException');
    }
    catch (ContentException $e) {
      $this->assertSame(400, $e->getStatusCode());
      $this->assertStringContainsString('The file is empty', $e->getMessage());
    }
  }

  /**
   * More than 20 MB is a 400 before any bytes are written.
   */
  public function testStoreResourceFileRejectsOversizeFile(): void {
    $service = $this->service();
    $this->fileRepository->writeData(Argument::cetera())->shouldNotBeCalled();

    try {
      $service->storeUploadedFile('Guide.pdf', str_repeat('x', 20 * 1024 * 1024 + 1));
      $this->fail('Expected a ContentException');
    }
    catch (ContentException $e) {
      $this->assertSame(400, $e->getStatusCode());
      $this->assertStringContainsString('The file is larger than 20 MB', $e->getMessage());
    }
  }

  /**
   * Names are basename()d and scrubbed of control characters before storing.
   *
   * The real basename() already strips the directory; this isolates the control
   * character scrub on top of it, which is what the stream wrapper URI rejects.
   */
  public function testStoreResourceFileSanitisesTheName(): void {
    $service = $this->service();
    $this->fileSystem->basename(Argument::cetera())->willReturn("evil\nname.pdf");
    $this->fileRepository->writeData('bytes', 'public://evil_name.pdf', FileExists::Rename)
      ->willReturn($this->prophesize(FileInterface::class)->reveal());

    $service->storeUploadedFile("C:\\tmp\\evil\nname.pdf", 'bytes');

    $this->assertTrue(TRUE);
  }

  /**
   * A store failure is logged and answers 400, not 500.
   */
  public function testStoreResourceFileLogsAndRejectsStoreFailure(): void {
    $service = $this->service();
    $this->fileSystem->basename(Argument::cetera())->willReturn('guide.pdf');
    $this->fileRepository->writeData(Argument::cetera())
      ->willThrow(new \RuntimeException('disk full'));
    $this->logger->error('Content upload failed: @message', ['@message' => 'disk full'])
      ->shouldBeCalled();

    try {
      $service->storeUploadedFile('Guide.pdf', 'bytes');
      $this->fail('Expected a ContentException');
    }
    catch (ContentException $e) {
      $this->assertSame(400, $e->getStatusCode());
      $this->assertStringContainsString('The file could not be stored', $e->getMessage());
    }
  }

  /**
   * The training upload path: MP4 persists, sharing the resource store.
   */
  public function testStoreTrainingVideoStoresBytes(): void {
    $service = $this->service();
    $this->fileSystem->basename(Argument::cetera())->willReturn('phase-one.mp4');
    $stored = $this->prophesize(FileInterface::class);
    $stored->id()->willReturn('997');
    $stored->getFilename()->willReturn('phase-one.mp4');
    $stored->getMimeType()->willReturn('video/mp4');
    $this->fileRepository->writeData('bytes', 'public://phase-one.mp4', FileExists::Rename)
      ->willReturn($stored->reveal());

    $result = $service->storeUploadedFile('Phase One.mp4', 'bytes', 'training');

    $this->assertSame([
      'fid' => 997,
      'name' => 'phase-one.mp4',
      'size' => 5,
      'mime' => 'video/mp4',
    ], $result);
  }

  /**
   * A training video must be an MP4 — the bundle's `file_extensions` setting.
   *
   * `.avi` is rejected even though it is a video, because `field_video` would
   * refuse the resulting reference on save and the create would 500.
   */
  public function testStoreTrainingVideoRejectsNonMp4(): void {
    $service = $this->service();
    $this->fileRepository->writeData(Argument::cetera())->shouldNotBeCalled();

    try {
      $service->storeUploadedFile('clip.avi', 'bytes', 'training');
      $this->fail('Expected a ContentException');
    }
    catch (ContentException $e) {
      $this->assertSame(400, $e->getStatusCode());
      $this->assertStringContainsString('Training videos must be MP4', $e->getMessage());
    }
  }

  /**
   * More than 100 MB is a 400 — the training field's `max_filesize` cap.
   */
  public function testStoreTrainingVideoRejectsOversizeFile(): void {
    $service = $this->service();
    $this->fileRepository->writeData(Argument::cetera())->shouldNotBeCalled();

    try {
      $service->storeUploadedFile('phase-one.mp4', str_repeat('x', 100 * 1024 * 1024 + 1), 'training');
      $this->fail('Expected a ContentException');
    }
    catch (ContentException $e) {
      $this->assertSame(400, $e->getStatusCode());
      $this->assertStringContainsString('The file is larger than 100 MB', $e->getMessage());
    }
  }

  /**
   * A training video store failure is logged and answers 400, not 500.
   */
  public function testStoreTrainingVideoLogsAndRejectsStoreFailure(): void {
    $service = $this->service();
    $this->fileSystem->basename(Argument::cetera())->willReturn('phase-one.mp4');
    $this->fileRepository->writeData(Argument::cetera())
      ->willThrow(new \RuntimeException('disk full'));
    $this->logger->error('Content upload failed: @message', ['@message' => 'disk full'])
      ->shouldBeCalled();

    try {
      $service->storeUploadedFile('phase-one.mp4', 'bytes', 'training');
      $this->fail('Expected a ContentException');
    }
    catch (ContentException $e) {
      $this->assertSame(400, $e->getStatusCode());
      $this->assertStringContainsString('The file could not be stored', $e->getMessage());
    }
  }

}

/**
 * A field item list the serialiser can actually walk.
 *
 * `ContentService::termLabels()` and `stringValues()` iterate the field with
 * `foreach` and read `target_id`/`value` off each item, while `formattedText()`
 * reads `getValue()` and `fileReference()` reads `->target_id` straight off the
 * field. A double that only implements `getValue()` silently yields zero items
 * on iteration and the row comes back missing its categories — which is exactly
 * the shape of failure this mock exists to rule out.
 */
final class FieldItemListDouble implements \IteratorAggregate {

  /**
   * First row's `target_id`, for the single-item read in `fileReference()`.
   */
  public readonly ?int $target_id;

  /**
   * @param array<int, array<string, mixed>> $values
   */
  public function __construct(private readonly array $values) {
    $row = $values[0] ?? [];
    $this->target_id = isset($row['target_id']) ? (int) $row['target_id'] : NULL;
  }

  public function getIterator(): \Traversable {
    $items = [];
    foreach ($this->values as $row) {
      $item = new \stdClass();
      foreach ($row as $name => $value) {
        $item->{$name} = $value;
      }
      $items[] = $item;
    }
    return new \ArrayIterator($items);
  }

  public function getValue(): array {
    return $this->values;
  }

}

/**
 * A base field double: a public `->value`, read by `timestamp()`.
 */
final class BaseFieldDouble {

  public function __construct(public readonly string $value) {}

}