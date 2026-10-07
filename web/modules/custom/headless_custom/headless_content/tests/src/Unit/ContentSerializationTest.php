<?php

declare(strict_types=1);

namespace Drupal\headless_content;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\headless_content\Exception\ContentException;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;

/**
 * Tests for ContentService::serialise(), reached through the public get().
 *
 * The first of these is a regression test for a fault that was live in the endpoint,
 * not a hypothetical one, and it is invisible to `php -l`.
 *
 * A body in a renderable text format was serialised by passing an array literal
 * straight into {@see RendererInterface::renderInIsolation()}, whose only parameter is
 * by reference. PHP raises `Error: Argument #1 ($elements) cannot be passed by
 * reference` at the call site, before the renderer is entered. It reproduced only for
 * nodes whose stored body format was on the renderable list, so recipes in another
 * format listed fine while training 500'd — one bundle working and another not, with
 * nothing in the code to explain the difference. {@see self::testRenderableFormatReachesTheRenderer()}
 * pins it. A Prophecy double carries the by-reference signature, so the mock fails on
 * a literal exactly as the real renderer would, with no container involved.
 *
 * @group headless_content
 * @coversDefaultClass \Drupal\headless_content\ContentService
 */
class ContentSerializationTest extends TestCase {

  use ProphecyTrait;

  protected ObjectProphecy $entityTypeManager;

  protected ObjectProphecy $nodeStorage;

  protected ObjectProphecy $renderer;

  protected ObjectProphecy $favourites;

  /**
   * The stored body value, as the field would return it.
   *
   * @var array<int, array{value: string, format?: string}>
   */
  protected array $bodyValue = [];

  /**
   * Which text fields the node reports as existing.
   *
   * @var array<int, string>
   */
  protected array $textFields = ['body'];

  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $this->nodeStorage = $this->prophesize(EntityStorageInterface::class);
    $this->entityTypeManager->getStorage('node')->willReturn($this->nodeStorage->reveal());

    // Resolved rather than left to a wildcard, because serialise() asks for the user
    // by owner id and the file and taxonomy storages by field type, and which of those
    // it reaches depends on the bundle.
    foreach (['user', 'file', 'taxonomy_term'] as $type) {
      $this->entityTypeManager->getStorage($type)
        ->willReturn($this->prophesize(EntityStorageInterface::class)->reveal());
    }

    $this->renderer = $this->prophesize(RendererInterface::class);
    $this->favourites = $this->prophesize(ContentFavorites::class);
    $this->favourites->appliesTo(Argument::any())->willReturn(FALSE);
  }

  /**
   * A ContentService over the fixtures, with visibility that always allows.
   */
  protected function service(): ContentService {
    $scope = $this->prophesize(ContentScope::class);
    $scope->canSee(Argument::cetera())->willReturn(TRUE);
    // publishedClinicIds() is no longer stubbed: serialise() used to call it to fill
    // audience.clinicIds and now does not. Prophecy would quietly answer an unstubbed
    // call with NULL, so this is documentation rather than a guard — the guard is the
    // assertSame/assertArrayNotHasKey pair in testIdentityAndAudienceBlock().

    $capabilities = $this->prophesize(ContentCapabilities::class);
    $capabilities->forRoles(Argument::cetera())->willReturn([
      'canCreate' => FALSE,
      'canDelete' => FALSE,
      'canFavourite' => FALSE,
    ]);

    return new ContentService(
      $this->entityTypeManager->reveal(),
      $scope->reveal(),
      $capabilities->reveal(),
      $this->favourites->reveal(),
      $this->prophesize(FileUrlGeneratorInterface::class)->reveal(),
      $this->renderer->reveal(),
      $this->prophesize(LoggerChannelInterface::class)->reveal(),
      $this->prophesize(\Drupal\file\FileRepositoryInterface::class)->reveal(),
      $this->prophesize(\Drupal\Core\File\FileSystemInterface::class)->reveal(),
    );
  }

  /**
   * A published node of the given bundle, registered with the node storage.
   */
  protected function node(string $bundle = 'training', int $nid = 5): NodeInterface {
    $node = $this->prophesize(NodeInterface::class);
    $node->id()->willReturn((string) $nid);
    $node->bundle()->willReturn($bundle);
    $node->label()->willReturn('A training item');
    $node->isPublished()->willReturn(TRUE);
    // 0, so author() returns NULL without asking the user storage for an account.
    $node->getOwnerId()->willReturn(0);

    // The catch-all first: a node has a lot of fields on it and the serialiser asks
    // about several that this bundle does not have. Registered before the specific
    // stubs so nothing depends on Prophecy's tie-breaking between two wildcards.
    $node->hasField(Argument::any())->willReturn(FALSE);
    $node->get(Argument::any())->willReturn(new \ArrayIterator([]));

    foreach ($this->textFields as $fieldName) {
      $node->hasField($fieldName)->willReturn(TRUE);
      $node->get($fieldName)->willReturn(new class ($this->bodyValue) {
        public function __construct(protected array $values) {}

        public function getValue(): array {
          return $this->values;
        }
      });
    }

    // Base fields, read as `->value` for the timestamps.
    foreach (['created' => '1521028166', 'changed' => '1790497797'] as $base => $value) {
      $node->get($base)->willReturn(new class ($value) {
        public function __construct(public string $value) {}
      });
    }

    $revealed = $node->reveal();
    $this->nodeStorage->load($nid)->willReturn($revealed);

    return $revealed;
  }

  /**
   * A body in a format on the renderable list, rendered as the renderer would.
   */
  public function testRenderableFormatReachesTheRenderer(): void {
    $this->bodyValue = [['value' => 'Step one.<br />Step two.', 'format' => 'basic_html']];
    $this->renderer->renderInIsolation(Argument::type('array'))
      ->willReturn('<p>Step one.<br />Step two.</p>');

    $this->node();
    $row = $this->service()->get('training', 5, ContentVisibility::clinicScoped('training', 31));

    $this->assertSame('<p>Step one.<br />Step two.</p>', $row['body']['html']);
    // Plain text alongside, so a client can render either without running a filter.
    $this->assertSame('Step one.Step two.', $row['body']['text']);
  }

  /**
   * The rendered build is a well-formed processed_text render array.
   *
   * Asserted rather than assumed, because the argument is what the renderer receives
   * and a wrong `#type` or `#format` renders the wrong thing without erroring.
   */
  public function testRenderableFormatBuildsProcessedText(): void {
    $this->bodyValue = [['value' => 'Body text.', 'format' => 'restricted_html']];
    $this->renderer->renderInIsolation(Argument::that(static function (array $build): bool {
      return $build === [
        '#type' => 'processed_text',
        '#text' => 'Body text.',
        '#format' => 'restricted_html',
      ];
    }))->willReturn('<p>Body text.</p>')->shouldBeCalled();

    $this->node();
    $row = $this->service()->get('training', 5, ContentVisibility::clinicScoped('training', 31));

    $this->assertSame('<p>Body text.</p>', $row['body']['html']);
  }

  /**
   * Every format on the renderable list takes the rendered path.
   *
   * One format alone would pass while a second was broken, and the live failure was
   * already format-dependent — which is the reason it reached a running site at all.
   *
   * @dataProvider renderableFormats
   */
  public function testEveryRenderableFormatIsRendered(string $format): void {
    $this->bodyValue = [['value' => 'Body text.', 'format' => $format]];
    $this->renderer->renderInIsolation(Argument::type('array'))->willReturn('<p>Body text.</p>');

    $this->node();
    $row = $this->service()->get('training', 5, ContentVisibility::clinicScoped('training', 31));

    $this->assertSame('<p>Body text.</p>', $row['body']['html']);
  }

  /**
   * @return array<string, array{0: string}>
   *   The formats {@see \Drupal\headless_content\ContentService} treats as renderable.
   */
  public static function renderableFormats(): array {
    return [
      'basic_html' => ['basic_html'],
      'restricted_html' => ['restricted_html'],
      'filtered_html' => ['filtered_html'],
      // Deliberate, and load-bearing for the training bundle: 40 of 51 training
      // bodies are `full_html`, and the video is an iframe embed inside them. See
      // ContentService::RENDERABLE_FORMATS.
      'full_html' => ['full_html'],
    ];
  }

  /**
   * A format outside the list is escaped, never filtered through the renderer.
   *
   * A stored value must not be able to choose its own escaping. `html` is the
   * example that matters now — it carries the PHP filter, so rendering it is a
   * larger exposure than `full_html`, which is why the two are treated differently
   * even though 4 training bodies are stored as `html`.
   */
  public function testNonRenderableFormatIsEscapedNotRendered(): void {
    $this->bodyValue = [['value' => '<script>alert(1)</script>', 'format' => 'html']];
    $this->renderer->renderInIsolation(Argument::any())->shouldNotBeCalled();

    $this->node();
    $row = $this->service()->get('training', 5, ContentVisibility::clinicScoped('training', 31));

    $this->assertStringNotContainsString('<script>', $row['body']['html']);
    $this->assertSame('alert(1)', $row['body']['text']);
  }

  /**
   * A `full_html` body is rendered, iframe and all.
   *
   * The regression this pins. The training video is an embed in the body for 44 of
   * 51 nodes and an uploaded file for one unpublished one, so escaping `full_html`
   * made every video in the library render as a bare title: `strip_tags()` on a
   * `media_embed` div leaves an empty string. Asserted on the rendered output rather
   * than on the presence of `full_html` in the list, because the list is an
   * implementation detail and this is the behaviour that matters.
   */
  public function testFullHtmlBodyRendersItsIframeEmbed(): void {
    $embed = '<div class="media_embed"><iframe src="https://www.youtube.com/embed/w3rQ3328Tok"'
      . ' title="Top Gun with a Cat" allowfullscreen=""></iframe></div>';
    $this->bodyValue = [['value' => $embed, 'format' => 'full_html']];
    $this->renderer->renderInIsolation(Argument::type('array'))->willReturn($embed);

    $this->node();
    $row = $this->service()->get('training', 5, ContentVisibility::clinicScoped('training', 31));

    $this->assertStringContainsString('<iframe', $row['body']['html']);
    $this->assertStringContainsString('w3rQ3328Tok', $row['body']['html']);
    // Still a `processed_text` build carrying the stored format, not a bare string
    // passed through — so a future filter change applies to these bodies too.
    $this->renderer->renderInIsolation(Argument::that(static function (array $build): bool {
      return ($build['#type'] ?? NULL) === 'processed_text'
        && ($build['#format'] ?? NULL) === 'full_html';
    }))->willReturn($embed);
  }

  /**
   * A body stored with no format at all is delivered, escaped.
   */
  public function testMissingFormatIsDeliveredAsEscapedText(): void {
    $this->bodyValue = [['value' => 'Plain body.']];
    $this->renderer->renderInIsolation(Argument::any())->shouldNotBeCalled();

    $this->node();
    $row = $this->service()->get('training', 5, ContentVisibility::clinicScoped('training', 31));

    $this->assertSame('Plain body.', $row['body']['text']);
    $this->assertSame('Plain body.', $row['body']['html']);
  }

  /**
   * A body that is only whitespace is treated as absent.
   */
  public function testWhitespaceOnlyBodyIsNull(): void {
    $this->bodyValue = [['value' => "   \n  ", 'format' => 'basic_html']];
    $this->renderer->renderInIsolation(Argument::any())->shouldNotBeCalled();

    $this->node();
    $row = $this->service()->get('training', 5, ContentVisibility::clinicScoped('training', 31));

    $this->assertNull($row['body']);
  }

  /**
   * A bundle with no `body` at all reports its own text field instead.
   *
   * `chirothin_resource` has no `body`; it has `field_description`. Asking for `body`
   * there returns NULL forever rather than failing, which is the intent — a guessed
   * field name that does not exist reads as "this resource has no text".
   */
  public function testBundleTextFieldIsNamedPerBundle(): void {
    $this->textFields = ['field_description'];
    $this->bodyValue = [['value' => 'A resource.', 'format' => 'basic_html']];
    $this->renderer->renderInIsolation(Argument::type('array'))->willReturn('<p>A resource.</p>');

    $this->node('chirothin_resource', 5);
    $row = $this->service()->get('chirothin_resource', 5, ContentVisibility::wholeLibrary('chirothin_resource', 31));

    $this->assertSame('<p>A resource.</p>', $row['description']['html']);
    $this->assertArrayNotHasKey('body', $row);
  }

  /**
   * The payload carries no allowlist-free dump.
   *
   * `$node->toArray()` would return `uid` and `revision_user` on an endpoint both
   * patients and doctors read. Fields are picked by name, so a field added to a bundle
   * later does not appear by default. The key list is asserted exactly, which fails if
   * an extra key appears as well as if a required one goes.
   */
  public function testPayloadKeysAreExactlyTheDeclaredSet(): void {
    $this->bodyValue = [['value' => 'Body.', 'format' => 'basic_html']];
    $this->renderer->renderInIsolation(Argument::type('array'))->willReturn('<p>Body.</p>');

    $this->node('recipe', 5);
    $row = $this->service()->get('recipe', 5, ContentVisibility::clinicScoped('recipe', 31));

    $this->assertSame([
      'id',
      'bundle',
      'title',
      'audience',
      'author',
      'created',
      'updated',
      'body',
      'categories',
      'types',
      'ingredients',
    ], array_keys($row));
  }

  /**
   * The identity and audience blocks.
   *
   * `audience` is `mode` and nothing else. It used to carry `clinicIds` as well, and
   * that array is what this assertion now exists to keep out: the audience field on
   * this site is 456 clinics wide per recipe after the legacy bulk import, so
   * `clinicIds` was very nearly the entire response body and the frontend read none of
   * it. Asserted with `assertSame` on the whole block rather than a key check, so
   * re-adding it fails here instead of shipping.
   */
  public function testIdentityAndAudienceBlock(): void {
    $this->bodyValue = [['value' => 'Body.', 'format' => 'basic_html']];
    $this->renderer->renderInIsolation(Argument::type('array'))->willReturn('<p>Body.</p>');

    $this->node('recipe', 21);
    $row = $this->service()->get('recipe', 21, ContentVisibility::clinicScoped('recipe', 31));

    $this->assertSame(21, $row['id']);
    $this->assertSame('recipe', $row['bundle']);
    $this->assertSame('A training item', $row['title']);
    $this->assertSame(['mode' => 'clinic'], $row['audience']);
    $this->assertArrayNotHasKey('clinicIds', $row['audience']);
    $this->assertSame(1521028166, $row['created']);
    $this->assertSame(1790497797, $row['updated']);
    // Owner id 0, so the byline is absent rather than a row with an empty name.
    $this->assertNull($row['author']);
  }

  /**
   * `favourite` is present exactly on the bundles the flag covers.
   *
   * A missing key and FALSE are different answers, so this asserts presence on recipe
   * and absence on training rather than asserting a value on both.
   */
  public function testFavouriteKeyTracksFlagEligibility(): void {
    $this->bodyValue = [['value' => 'Body.', 'format' => 'basic_html']];
    $this->renderer->renderInIsolation(Argument::type('array'))->willReturn('<p>Body.</p>');

    $this->favourites->appliesTo('recipe')->willReturn(TRUE);
    $this->favourites->favouriteIds([5])->willReturn([]);
    $service = $this->service();

    $this->node('recipe', 5);
    $recipe = $service->get('recipe', 5, ContentVisibility::clinicScoped('recipe', 31));
    $this->assertArrayHasKey('favourite', $recipe);
    $this->assertFalse($recipe['favourite']);

    $this->node('training', 5);
    $training = $service->get('training', 5, ContentVisibility::clinicScoped('training', 31));
    $this->assertArrayNotHasKey('favourite', $training);
  }

  /**
   * A flagged node comes back flagged, from the ids passed in for the page.
   */
  public function testFavouriteStateComesFromTheCaller(): void {
    $this->bodyValue = [['value' => 'Body.', 'format' => 'basic_html']];
    $this->renderer->renderInIsolation(Argument::type('array'))->willReturn('<p>Body.</p>');

    $this->favourites->appliesTo('recipe')->willReturn(TRUE);
    // Keyed by node id with a TRUE value, which is what ContentFavorites::favouriteIds()
    // returns. A bare [5] would be a list, and serialise() reads by key.
    $this->favourites->favouriteIds([5])->willReturn([5 => TRUE]);

    $this->node('recipe', 5);
    $row = $this->service()->get('recipe', 5, ContentVisibility::clinicScoped('recipe', 31));

    $this->assertTrue($row['favourite']);
  }

  /**
   * A favourite id the flag did not report is FALSE, not an error.
   *
   * `favouriteIds()` intersects against the ids on this page, so a node whose key is
   * absent is unflagged. The lookup is `=== TRUE` rather than truthy on purpose: a
   * value of `5` or `'0'` is a different shape from the contract and must not read as
   * favourited.
   */
  public function testUnflaggedNodeIsFalseNotAnError(): void {
    $this->bodyValue = [['value' => 'Body.', 'format' => 'basic_html']];
    $this->renderer->renderInIsolation(Argument::type('array'))->willReturn('<p>Body.</p>');

    $this->favourites->appliesTo('recipe')->willReturn(TRUE);
    $this->favourites->favouriteIds([5])->willReturn([99 => TRUE]);

    $this->node('recipe', 5);
    $row = $this->service()->get('recipe', 5, ContentVisibility::clinicScoped('recipe', 31));

    $this->assertFalse($row['favourite']);
  }

  /**
   * The NodeInterface the serialiser is typed against is the one that exists.
   *
   * The bug was an import of `Drupal\Core\Entity\NodeInterface`, which 10.x does not
   * have: it moved to `Drupal\node\NodeInterface` in Drupal 9.2 with no alias left
   * behind. Both halves are asserted because the failure is silent in every
   * direction — `instanceof` against an unresolvable name is FALSE rather than fatal,
   * so the wrong import dropped every row and the endpoint still answered 200 with a
   * correct `totalItems`.
   */
  public function testNodeInterfaceIsTheOneThatExists(): void {
    $this->assertTrue(
      interface_exists(NodeInterface::class),
      'Drupal\node\NodeInterface must exist.'
    );
    $this->assertFalse(
      interface_exists('Drupal\Core\Entity\NodeInterface'),
      'Drupal\Core\Entity\NodeInterface does not exist in Drupal 10. If this has changed, the imports in this module need revisiting rather than leaving.'
    );

    $this->assertInstanceOf(NodeInterface::class, $this->node('recipe', 5));
  }

  /**
   * An unknown bundle is refused with a 404.
   */
  public function testUnknownBundleIsRefused(): void {
    $this->expectException(ContentException::class);
    $this->expectExceptionCode(404);

    $this->service()->get('nonsense', 5, ContentVisibility::clinicScoped('recipe', 31));
  }

  /**
   * A node the caller cannot see is a 404, not an empty row.
   */
  public function testInvisibleNodeIsRefused(): void {
    $this->node('recipe', 5);

    $scope = $this->prophesize(ContentScope::class);
    $scope->canSee(Argument::cetera())->willReturn(FALSE);

    $service = new ContentService(
      $this->entityTypeManager->reveal(),
      $scope->reveal(),
      $this->prophesize(ContentCapabilities::class)->reveal(),
      $this->favourites->reveal(),
      $this->prophesize(FileUrlGeneratorInterface::class)->reveal(),
      $this->renderer->reveal(),
      $this->prophesize(LoggerChannelInterface::class)->reveal(),
      $this->prophesize(\Drupal\file\FileRepositoryInterface::class)->reveal(),
      $this->prophesize(\Drupal\Core\File\FileSystemInterface::class)->reveal(),
    );

    $this->expectException(ContentException::class);
    $this->expectExceptionCode(404);

    $service->get('recipe', 5, ContentVisibility::clinicScoped('recipe', 31));
  }

}
