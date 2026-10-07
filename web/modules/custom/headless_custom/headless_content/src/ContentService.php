<?php

declare(strict_types=1);

namespace Drupal\headless_content;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\headless_content\Exception\ContentException;
use Drupal\taxonomy\TermInterface;
// Drupal\node\NodeInterface, not Drupal\Core\Entity\NodeInterface. The interface
// moved out of core into the node module in Drupal 9.2, and there is no class alias
// left behind in 10.x.
//
// Two failures from the wrong name, neither of which is loud:
//   - `!$node instanceof NodeInterface` is TRUE for every real node, because
//     instanceof against a name that does not resolve is simply FALSE. Every row
//     the query returned was dropped by the re-check in listBundle(), so the
//     endpoint answered 200 with an empty list and a correct totalItems.
//   - `serialise(NodeInterface $node)` is a TypeError for any node that reached it.
// See README §1.7.
use Drupal\node\NodeInterface;

/**
 * Reads the doctor-and-patient content library.
 *
 * Three node bundles, one visibility rule, read-only apart from one write: a
 * chiropractor may add a recipe or a resource of their own. The interesting part
 * is not the serialisation but what the rows are: {@see ContentScope} decides,
 * and this class never decides anything itself about who may read.
 *
 * ## Field map
 *
 * Each bundle has its own fields and this class carries that per bundle rather
 * than guessing, because a guessed field name that does not exist is a missing key
 * in the response and reads as "this recipe has no ingredients" instead of "the
 * serialiser is wrong".
 *
 * - `recipe`: `body` (text_with_summary, label "Instructions"),
 *   `field_recipe_ingredient` (multi string), `field_recipe_category` and
 *   `field_recipe_type` (taxonomy terms, resolved to labels).
 * - `training`: `body`, `field_video` (file).
 * - `chirothin_resource`: `field_description` (text_long), `field_resource`
 *   (file), `field_resource_type` (taxonomy, label "Resource Category").
 *
 * `field_my_clinic` exists on all three bundles and is deliberately never read: it
 * is Drupal 7 residue and `field_published_to` is the live audience field. See
 * {@see ContentScope} for why that is load-bearing.
 *
 * ## No allowlist dump
 *
 * `$node->toArray()` would return `uid`, `revision_user` and any field the site
 * adds later, on an endpoint both patients and doctors read. Fields are picked by
 * name instead, so a field added to a bundle later does not leak into the payload
 * by default.
 *
 * @see \Drupal\headless_content\ContentScope for who may read what.
 */
class ContentService {

  /**
   * Bundles this module serves, and the label each appears under.
   *
   * An allowlist rather than "any node type": the endpoint is reached by both
   * patients and doctors, so a bundle added here becomes visible to the entire
   * portal by being added.
   */
  public const BUNDLES = [
    'recipe' => 'Recipes',
    'training' => 'Training',
    'chirothin_resource' => 'Resources',
  ];

  /**
   * Formatted text fields, payload key => node field, per bundle.
   *
   * `body` on recipe and training, `description` on resources — a resource has no
   * `body` at all, and asking for one would return NULL forever rather than fail.
   */
  private const TEXT_FIELDS = [
    'recipe' => ['body' => 'body'],
    'training' => ['body' => 'body'],
    'chirothin_resource' => ['description' => 'field_description'],
  ];

  /**
   * Taxonomy fields, payload key => node field, per bundle.
   */
  private const TERM_FIELDS = [
    'recipe' => [
      'categories' => 'field_recipe_category',
      'types' => 'field_recipe_type',
    ],
    'training' => [],
    'chirothin_resource' => ['resourceTypes' => 'field_resource_type'],
  ];

  /**
   * File fields, payload key => node field, per bundle.
   */
  private const FILE_FIELDS = [
    'recipe' => [],
    'training' => ['video' => 'field_video'],
    'chirothin_resource' => ['resource' => 'field_resource'],
  ];

  /**
   * Multi-value string fields, payload key => node field, per bundle.
   */
  private const STRING_FIELDS = [
    'recipe' => ['ingredients' => 'field_recipe_ingredient'],
  ];

  /**
   * The vocabulary each taxonomy payload key draws its options from.
   *
   * Payload key => vocabulary machine name, per bundle. This is the form's source
   * of options ({@see self::options()}) and the create validation's definition of
   * which vocabulary a term id must belong to ({@see self::requireTerms()}).
   *
   * The keys mirror {@see self::TERM_FIELDS}, so the option list for `categories`
   * and the validation of a submitted `categories` cannot name different
   * vocabularies.
   */
  private const OPTION_VOCABULARIES = [
    'recipe' => [
      'categories' => 'recipe_category',
      'types' => 'recipe_types',
    ],
    'chirothin_resource' => [
      'resourceTypes' => 'resource_category',
    ],
  ];

  /**
   * Bundles a chiropractor may create through the API.
   *
   * The create route is constrained to these bundles in the routing file; this is
   * the service-side half of the same rule, checked before any field is validated
   * so a bundle cannot be created in a state that bypasses it. Every bundle this
   * module serves is now creatable — the guard is kept because the routing file
   * and this list are two files, and the check must stay even when one of them
   * drifts.
   */
  private const CREATE_BUNDLES = ['recipe', 'training', 'chirothin_resource'];

  /**
   * File extensions a chiropractor may attach to a resource.
   *
   * The published library is pdf, jpeg, png, docx, doc, xlsx and mp4; the list is
   * the union of that set and the obvious office siblings. Web content (html, svg)
   * is deliberately excluded: it is served from the public scheme and would render
   * in the caller's browser rather than download.
   */
  private const RESOURCE_EXTENSIONS = [
    'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp',
    'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt',
    'mp4', 'mov', 'webm',
  ];

  /**
   * Largest resource file a create request may store, matching the messages
   * attachment cap.
   */
  private const MAX_RESOURCE_BYTES = 20 * 1024 * 1024;

  /**
   * File extensions a chiropractor may upload as a training video.
   *
   * The training bundle's `field_video` is configured for MP4 only
   * (`field.field.node.training.field_video.yml`, `file_extensions: mp4`), so the
   * upload endpoint is pinned to the same single extension rather than the
   * broader video list resources accept.
   */
  private const TRAINING_EXTENSIONS = ['mp4'];

  /**
   * Largest training video a create request may store.
   *
   * Mirrors the same `field_video` config's `max_filesize: 100MB`.
   */
  private const MAX_TRAINING_BYTES = 100 * 1024 * 1024;

  /**
   * The format new recipe bodies are stored under.
   *
   * `basic_html` rather than `full_html`: the create API accepts plain text, so
   * there is nothing that needs the freedom full_html exists for, and restricting
   * the format is the one place an API can be the control that "who may use the
   * format" normally is. It is on {@see self::RENDERABLE_FORMATS}, so the body
   * serialises exactly like the imported ones.
   */
  private const CREATE_BODY_FORMAT = 'basic_html';

  /**
   * Most terms a create request may put on the category field.
   *
   * A recipe must carry between one and two category terms — the Drupal form shows
   * the field as a checkbox set and a food can belong to a phase *and* a group.
   */
  private const CATEGORY_MAX = 2;

  /**
   * The node title and each ingredient are single strings of bounded length.
   *
   * The title base field and the ingredient field config both store at most 255,
   * and writing past a field's length surfaces as a constraint violation on save —
   * a 500 that should have been a 400 explaining which field. Validated rather
   * than silently truncated, so the message names the edit.
   */
  private const STRING_MAX_LENGTH = 255;

/**
   * Stored formats whose value may be rendered as HTML.
   *
   * `basic_html`, `restricted_html` and `filtered_html` run a filter pipeline that
   * strips scripts and event handlers, so rendering them is safe by construction.
   *
   * ## Why `full_html` is on this list
   *
   * Added deliberately, and the reasoning is worth keeping because it is the
   * opposite of the rule the list originally encoded. Anything NOT listed is
   * delivered as escaped plain text — {@see self::formattedText()} runs it through
   * `strip_tags()`, on the sound principle that a value must not choose its own
   * escaping.
   *
   * That is correct for prose and useless for the training bundle. Measured on this
   * site: 51 training nodes, and the video is an `<iframe>` embed in the body for 44
   * of them — only one node has an uploaded file, and it is unpublished. 40 of the
   * 51 bodies are stored as `full_html` and 4 as `html` precisely because Drupal's
   * restricted formats strip iframes, so the embed could not be entered at all.
   *
   * `strip_tags()` on one of those bodies leaves an empty string: a `media_embed`
   * div wrapping an iframe has no text content, only attributes. So the endpoint
   * returned a title and nothing else for every video in the library, while the
   * legacy View — which declares no formatter and so falls back to the field default
   * — rendered the same bodies correctly. `Maverick with a Cat` was the visible
   * symptom; it was not the only one.
   *
   * ## What this costs
   *
   * `full_html` is unfiltered by design, so a body stored in it is delivered to the
   * portal as authored. Anyone who can put `full_html` on a training body can
   * therefore inject script into the portal. That exposure is accepted here, and the
   * control for it is who may use the format — a content-authoring permission in
   * Drupal, not something an API can enforce. Do not widen this list further on the
   * same reasoning: `html` (4 training nodes use it) carries the PHP filter, which
   * is a different and larger exposure than `full_html` and is deliberately still
   * excluded. Those 4 nodes render as title-only until that is a separate decision.
   */
  private const RENDERABLE_FORMATS = [
    'basic_html',
    'restricted_html',
    'filtered_html',
    'full_html',
  ];

  /**
   * Number of items a page returns when the caller does not say.
   *
   * 50 rather than 200 because this replaced a View that rendered everything: the
   * recipe library is 634 published rows, and a page is what a list UI can render
   * without a spinner. A caller asking for more gets {@see self::MAX_PAGE_SIZE}.
   */
  public const DEFAULT_PAGE_SIZE = 50;

  /**
   * Largest page a caller may ask for.
   *
   * A ceiling rather than a page size, so a client cannot turn a bounded query into
   * an unbounded one by sending `page_size=100000`. Raised from the old 200 only in
   * the sense that 200 was previously a *silent* truncation; nothing in the frontend
   * can reach more than a page at a time now.
   */
  public const MAX_PAGE_SIZE = 200;

  /**
   * The sort fields {@see self::listBundle()} orders by, in order.
   *
   * Named so the ordering can be asserted rather than inferred. `nid` must stay last
   * and must never be dropped: the two leading fields are not a total order, and
   * without a unique final field `LIMIT`/`OFFSET` pages overlap and drop rows
   * without any error. See the note at the call site, and
   * {@see \Drupal\Tests\headless_content\Unit\ContentPaginationTest}.
   *
   * @see self::listBundle()
   */
  public const LIST_SORT = ['sticky' => 'DESC', 'title' => 'ASC', 'nid' => 'ASC'];

  /**
   * Term labels resolved this request, keyed by term id.
   *
   * @var array<int, string|null>
   */
  private array $termLabels = [];

  /**
   * Account names resolved this request, keyed by uid.
   *
   * @var array<int, string|null>
   */
  private array $authorNames = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ContentScope $scope,
    private readonly ContentCapabilities $capabilities,
    private readonly ContentFavorites $favourites,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly RendererInterface $renderer,
    private readonly LoggerChannelInterface $logger,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * The library for one bundle, one page of it.
   *
   * @param string $bundle
   *   Bundle machine name.
   * @param \Drupal\headless_content\ContentVisibility $visibility
   *   The caller's decision, from ContentScope::visibility().
   * @param array<int, string> $roles
   *   The caller's roles, for the capabilities block.
   * @param int $page
   *   1-based page number. Below 1 is a 400 rather than a clamp to page one; see
   *   {@see self::normalisePaging()} for the measurement that made the clamp untenable.
   * @param int $pageSize
   *   Requested page size. Below 1 is a 400; above {@see self::MAX_PAGE_SIZE} is
   *   clamped to it.
   *
   * @return array{bundle: string, label: string, visibility: string, items: array<int, array<string, mixed>>, pagination: array{page: int, pageSize: int, pageCount: int, totalItems: int}, capabilities: array<string, mixed>}
   *   The list response.
   *
   * @throws \Drupal\headless_content\Exception\ContentException
   *   When the bundle is not served by this module, or a paging value is below 1.
   */
  public function listBundle(
    string $bundle,
    ContentVisibility $visibility,
    array $roles,
    int $page = 1,
    int $pageSize = self::DEFAULT_PAGE_SIZE,
  ): array {
    $label = self::BUNDLES[$bundle] ?? NULL;
    if ($label === NULL) {
      throw ContentException::unknownBundle($bundle);
    }

    [$page, $pageSize] = self::normalisePaging($page, $pageSize);

    $base = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      // Unpublished is not content. Kept as an explicit condition rather than left
      // to node access: a draft aimed at a clinic must not leak to that clinic
      // either, and this endpoint decides rows itself.
      ->condition('status', 1);

    // The whole point of ContentVisibility. In `shared` mode there is deliberately
    // no audience condition at all — that is what the resources View's embed_2 does
    // when it is handed an empty argument.
    if ($visibility->clinicOnly) {
      $base->condition(ContentScope::FIELD_PUBLISHED_TO, $visibility->clinicId);
    }

    // One condition set, cloned rather than hand-copied. Two traps here:
    //
    // 1. `count()` is destructive. It sets `$this->count = TRUE` and nothing ever
    //    resets it, so calling `count()->execute()` on `$base` and then reusing `$base`
    //    for the row query would make the second `execute()` return the same integer
    //    again — and `loadMultiple()` handed an integer is `TypeError: Illegal offset
    //    type`, the same error class as README §4.1. Core hits this too and solves it
    //    the same way: `QueryBase::initializePager()` does `$count_query = clone $this`
    //    before counting. Cloned *before* the sort and range are added, so the count
    //    covers the whole match set rather than one page of it.
    //
    // 2. Hand-writing the conditions a second time would be a second implementation
    //    of "which rows match", and it is the one the pagination numbers come from.
    //    If it drifts from the row query, the frontend renders "page 3 of 5" over an
    //    empty list.
    $countQuery = clone $base;
    $totalItems = (int) $countQuery->count()->execute();

    // Sticky then title, as the training and resources Views order theirs. The
    // recipes master display sorts `random`, which is not reproducible and would make
    // a cached list disagree with itself; the random "Meal Suggestion" single-item
    // block is a page feature, not an API list.
    //
    // nid last, and it must stay last.
    //
    // sticky + title is NOT a total order, and this library makes that concrete:
    // 634 published recipes carry only 221 distinct titles, with `Beet and Orange
    // Salad`, `Bhindi (Okra) Bhajee` and `Braised Cabbage and Beets` appearing eight
    // times each. MySQL may return tied rows in any order, and it does not have to
    // choose the same order for two queries.
    //
    // So a row sorted on (sticky, title) has no fixed position, and the offset below
    // becomes unsafe: pages overlap and rows fall between them. Measured on this site,
    // walking the library at 50 rows a page produced two nodes on two pages each
    // *and* silently dropped two others — the library rendered 632 of 634 recipes
    // with no error anywhere. At 200 rows a page the boundary lands in a different
    // place, which is why this looked intermittent.
    //
    // nid is unique, so it makes the order total and every row gets a deterministic
    // position. Verified: 634 rows, 634 distinct, zero overlap across every page.
    //
    // Do not drop it. The failure mode is a short list rather than a 500, so it will
    // ship silently and look like a content problem. LIST_SORT exists so
    // ContentPaginationTest can assert this rather than trusting this comment.
    $ordered = $base;
    foreach (self::LIST_SORT as $field => $direction) {
      $ordered->sort($field, $direction);
    }

    $ids = $ordered
      // offset, not page index: EntityQuery has no page parameter, and range()'s
      // first argument is a row count to skip.
      ->range(($page - 1) * $pageSize, $pageSize)
      ->execute();

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($ids);

    // Every node id on this page, so the favourite state is one query for the page
    // rather than one per row. Batched for the same reason term labels are — see
    // ProgressService::termLabels() and the note on resolveTermLabels() below.
    $favourites = $this->favourites->appliesTo($bundle)
      ? $this->favourites->favouriteIds(array_keys($nodes))
      : [];

    $items = [];
    foreach ($nodes as $node) {
      // Re-checked here even though the query already filtered. The query and
      // canSee() are two implementations of one rule; the query is the fast path
      // and this is the definition. They agree today, and if they ever stop
      // agreeing this is the one that is right.
      if (!$node instanceof NodeInterface || !$this->scope->canSee($node, $visibility)) {
        continue;
      }
      $items[] = $this->serialise($node, $visibility, $favourites);
    }

    // pageCount is 1 rather than 0 for an empty library. "Page 1 of 0" makes a
    // frontend divide by zero or render an empty pager; an empty pager driven by
    // totalItems is the same information without the trap.
    $pageCount = $totalItems > 0 ? (int) ceil($totalItems / $pageSize) : 1;

    // Rows the query selected but the PHP re-check refused. This is the one place
    // the two implementations of the visibility rule can disagree, and when they do
    // the symptom is a page that looks short for no visible reason: the frontend
    // reports 634 items and renders 12, with no indication which 12. Logged rather
    // than raised, because canSee() is the definition and refusing the row is the
    // correct behaviour; the count is a data problem, not a request problem.
    //
    // Measured against what loaded, not against what the query asked for: a row that
    // vanished between the query and the load is a different fault, and attributing
    // it to the re-check would send the next reader looking at ContentScope for a
    // node that no longer exists.
    $refused = count($nodes) - count($items);
    if ($refused > 0) {
      $this->logger->warning(
        'Content list for @bundle in @mode mode loaded @loaded nodes but serialised @kept: @refused failed the ContentScope re-check. The pagination total is unaffected, so this page will look short.',
        [
          '@bundle' => $bundle,
          '@mode' => $visibility->mode(),
          '@loaded' => count($nodes),
          '@kept' => count($items),
          '@refused' => $refused,
        ]
      );
    }

    return [
      'bundle' => $bundle,
      'label' => $label,
      // Echoed so the frontend can show which library it received. In `clinic` mode
      // this is a restatement of the caller's own clinic; in `shared` mode it is
      // the explanation for rows the reader did not expect.
      'visibility' => $visibility->mode(),
      'items' => $items,
      // `total` was the count of what was returned, which made a truncated list
      // indistinguishable from a complete one. `totalItems` is the count of what
      // exists and matches; a page that came back short because of a node access or
      // data change rather than the cap is now visible as items.length < pageSize.
      'pagination' => [
        'page' => $page,
        'pageSize' => $pageSize,
        'pageCount' => $pageCount,
        'totalItems' => $totalItems,
      ],
      'capabilities' => $this->capabilities->forRoles($roles, $bundle),
    ];
  }

  /**
   * Paging into a shape a query can run, or refusing it with a 400.
   *
   * Two rules, and the asymmetry between them is deliberate:
   *
   * - Too large is *adjusted*. `page_size=99999` becomes {@see self::MAX_PAGE_SIZE}.
   *   Clamping is what stops a client turning a bounded query into an unbounded one,
   *   and asking for more than the ceiling is a reasonable thing for a UI to do while
   *   it is still working out its own layout.
   * - Below 1 is *refused*. Pages are 1-based, so `page=0` names no page, and the old
   *   clamp to 1 answered it with page one anyway. That is not cosmetic:
   *   {@see self::listBundle()} turns the page into
   *   `range(($page - 1) * $pageSize, $pageSize)`, so `page=0` is `range(-30, 30)`,
   *   and MySQL reads the negative offset as zero. A client indexing pages from 0 and
   *   walking `0..pageCount` receives page one twice. Measured here at
   *   `page_size=30`: a 116-recipe clinic came back as 146 rows to the frontend, with
   *   every individual response valid and `totalItems` and `pageCount` both truthful.
   *
   *   The clamp removed the only evidence that the caller had counted wrong. The 400
   *   reports it once, in the request that caused it.
   *
   * `pageSize=0` is refused for the same reason. It clamped to 1, and a filter that
   * computed zero rows should not be answered with an arbitrary single recipe.
   *
   * @return array{0: int, 1: int}
   *   The page and page size to run with, in that order.
   *
   * @throws \Drupal\headless_content\Exception\ContentException
   *   400 when either value is below 1.
   */
  public static function normalisePaging(int $page, int $pageSize): array {
    if ($page < 1) {
      throw ContentException::pagingOutOfRange('page', $page);
    }

    if ($pageSize < 1) {
      throw ContentException::pagingOutOfRange('page_size', $pageSize);
    }

    return [$page, min($pageSize, self::MAX_PAGE_SIZE)];
  }

  /**
   * One item, in full.
   *
   * @param string $bundle
   *   Bundle machine name, from the path.
   * @param int $nid
   *   Node id, from the path.
   * @param \Drupal\headless_content\ContentVisibility $visibility
   *   The caller's decision.
   *
   * @return array<string, mixed>
   *   The serialised node.
   *
   * @throws \Drupal\headless_content\Exception\ContentException
   *   When the bundle is unknown, or the node is missing, unpublished, another
   *   bundle, or not visible to this caller. All the same 404 on purpose.
   */
  public function get(string $bundle, int $nid, ContentVisibility $visibility): array {
    if (!isset(self::BUNDLES[$bundle])) {
      throw ContentException::unknownBundle($bundle);
    }

    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface
      || $node->bundle() !== $bundle
      || !$node->isPublished()
      || !$this->scope->canSee($node, $visibility)) {
      throw ContentException::notFound();
    }

    // One node, so the batched lookup is a lookup of one. Same code path as the list
    // rather than a second way of asking, so `favourite` cannot disagree between the
    // detail response and the row the caller tapped to reach it.
    $favourites = $this->favourites->appliesTo($bundle)
      ? $this->favourites->favouriteIds([$node->id()])
      : [];

    return $this->serialise($node, $visibility, $favourites);
  }

  /**
   * Flags or unflags a node and returns the updated row.
   *
   * Lives here rather than in the controller because the node load and the visibility
   * check already live here, and re-implementing them for the write path is how the
   * read rules and the write rules end up disagreeing. This calls the same
   * {@see self::get()} the detail route calls, so "can you see it" and "can you
   * favourite it" cannot have different answers.
   *
   * Order matters: visibility before bundle eligibility. A caller must not be able to
   * learn a recipe exists by getting a 400 "recipes cannot be favourited" for a
   * training node, nor to favourite a node belonging to another clinic.
   *
   * @param string $bundle
   *   Bundle machine name, from the path.
   * @param int $nid
   *   Node id, from the path.
   * @param \Drupal\headless_content\ContentVisibility $visibility
   *   The caller's decision.
   * @param bool|null $wanted
   *   TRUE to favourite, FALSE to unfavourite, NULL to flip. See
   *   {@see ContentFavorites::set()}.
   *
   * @return array<string, mixed>
   *   The serialised node, with `favourite` set to the resulting state.
   *
   * @throws \Drupal\headless_content\Exception\ContentException
   *   404 for a node the caller cannot see, 400 for a bundle the flag does not cover,
   *   403 for an account without `flag favorites`.
   */
  public function setFavourite(
    string $bundle,
    int $nid,
    ContentVisibility $visibility,
    ?bool $wanted = NULL,
  ): array {
    if (!isset(self::BUNDLES[$bundle])) {
      throw ContentException::unknownBundle($bundle);
    }

    // The same load and the same 404 as get(). get() returns a serialised row, and
    // what the flag write needs is the entity, so the node is loaded once here and
    // both the visibility decision and the serialisation are taken from it. Calling
    // get() and then loading again would be two loads and two chances to disagree.
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface
      || $node->bundle() !== $bundle
      || !$node->isPublished()
      || !$this->scope->canSee($node, $visibility)) {
      throw ContentException::notFound();
    }

    $state = $this->favourites->set($node, $wanted);

    // Serialised *after* the write, and with the resulting state, so the frontend
    // replaces the row it sent rather than refetching a list it would have to
    // re-paginate. favouriteIds() inside serialise() would otherwise query again and
    // could in principle disagree with $state; passing it through is both cheaper
    // and unambiguous.
    $row = $this->serialise($node, $visibility, [(int) $node->id() => $state]);

    return $row + [
      'bundle' => $bundle,
      'id' => (int) $nid,
      'favourite' => $state,
    ];
  }

  /**
   * The option lists a create form needs, per taxonomy payload key.
   *
   * Served by the GET route behind `headless_content.options`, which any portal
   * member may read: the lists are taxonomy labels, the same labels the library
   * rows already carry. Only keys in {@see self::OPTION_VOCABULARIES} are
   * answered; a bundle with no form (training, resources) is a 404 rather than an
   * empty object, so the frontend is not silently handed a form with no choices.
   *
   * @param string $bundle
   *   Bundle machine name, from the path.
   *
   * @return array<string, array<int, array{id: int, label: string}>>
   *   Payload key => terms, alphabetically by label.
   *
   * @throws \Drupal\headless_content\Exception\ContentException
   *   When the bundle has no option lists.
   */
  public function options(string $bundle): array {
    $vocabularies = self::OPTION_VOCABULARIES[$bundle] ?? NULL;
    if ($vocabularies === NULL) {
      throw ContentException::unknownBundle($bundle);
    }

    $options = [];
    foreach ($vocabularies as $key => $vid) {
      $options[$key] = $this->vocabularyTerms($vid);
    }

    return $options;
  }

  /**
   * Creates a recipe authored by the caller and scoped to the caller's clinic.
   *
   * ## The audience is never caller-supplied
   *
   * `field_published_to` is set to the caller's own clinic
   * ({@see ContentScope::requireClinicId()}) and `field_my_clinic` is left
   * untouched. A payload that tries to name either is ignored field by field,
   * because the create whitelist below simply has no key for them: the node's
   * audience and the "which clinic am I" residue field are both derived, and a
   * client-supplied clinic would replace the tenant boundary the read side exists
   * to enforce. See ContentScope's docblock for why `field_published_to` is
   * load-bearing.
   *
   * The node is published immediately, because this is the doctor's own authoring
   * surface and the recipe is theirs to see as soon as they submit it — the list
   * they came from filters unpublished rows out, so a draft would silently vanish.
   * There is no edit or unpublish route, so "published" is the only state here;
   * revising that needs an edit route first.
   *
   * @param string $bundle
   *   Bundle machine name, from the path. Only the {@see self::CREATE_BUNDLES}
   *   bundles are creatable.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The caller. Owner of the new node, and the source of the clinic.
   * @param array<string, mixed> $payload
   *   Decoded JSON body. For `recipe`: `title` (required), `categories` (1-2 term
   *   ids from the `recipe_category` vocabulary), `types` (1+ term ids from
   *   `recipe_types`), `ingredients` (list of strings), `body` (plain-text
   *   instructions). For `training`: `title` (required), `body` (plain text) and
   *   `video` (a file id returned by {@see self::storeUploadedFile}); a training
   *   item may be published without a video, and several of the existing ones
   *   are body text plus an embed. For `chirothin_resource`: `title` (required),
   *   `resourceTypes` (exactly 1 term id from `resource_category`),
   *   `description` (plain text) and `resource` (a file id returned by
   *   {@see self::storeUploadedFile}). Any other key is ignored.
   * @param \Drupal\headless_content\ContentVisibility $visibility
   *   The caller's decision, for the audience block on the returned row.
   *
   * @return array<string, mixed>
   *   The serialised node, plus the `bundle` and `id` keys the favourite route
   *   adds to its own.

   * @throws \Drupal\headless_content\Exception\ContentException
   *   400 for a bundle that cannot be created or a field that fails validation,
   *   403 for an account with no clinic.
   */
  public function create(
    string $bundle,
    AccountInterface $account,
    array $payload,
    ContentVisibility $visibility,
  ): array {
    if (!isset(self::BUNDLES[$bundle])) {
      throw ContentException::unknownBundle($bundle);
    }

    if (!in_array($bundle, self::CREATE_BUNDLES, TRUE)) {
      throw ContentException::cannotCreate($bundle);
    }

    $title = $this->createString($payload['title'] ?? NULL, 'title', TRUE);
    $clinicId = $this->scope->requireClinicId($account);

    $values = [
      // `type` is node storage's bundle key. Without it `create()` throws
      // "Missing bundle for entity type node" before any field is written — the
      // fatal this module shipped on its first live create, and a 500 (logged as
      // "Content create failed") rather than a validation message because the
      // exception is not a ContentException.
      'type' => $bundle,
      'title' => $title,
      'uid' => (int) $account->id(),
      'status' => 1,
      ContentScope::FIELD_PUBLISHED_TO => [$clinicId],
    ];

    if ($bundle === 'recipe') {
      $body = $this->createString($payload['body'] ?? NULL, 'body', FALSE);
      $ingredients = $this->createIngredients($payload['ingredients'] ?? NULL);

      $values['field_recipe_category'] = $this->requireTerms(
        $payload['categories'] ?? [],
        'categories',
        'recipe_category',
        1,
        self::CATEGORY_MAX,
      );
      $values['field_recipe_type'] = $this->requireTerms($payload['types'] ?? [], 'types', 'recipe_types', 1, NULL);
      $values['field_recipe_ingredient'] = $ingredients;

      if ($body !== '') {
        $values['body'] = [['value' => $body, 'format' => self::CREATE_BODY_FORMAT]];
      }
    }
    elseif ($bundle === 'training') {
      // An optional plain-text body and an optional video that
      // {@see self::storeUploadedFile()} already made into a managed file for
      // this caller. field_video is not required on the bundle (several existing
      // training items are body-only), so both fields are left unset when the
      // payload names neither.
      $body = $this->createString($payload['body'] ?? NULL, 'body', FALSE);
      if ($body !== '') {
        $values['body'] = [['value' => $body, 'format' => self::CREATE_BODY_FORMAT]];
      }

      $fid = $this->optionalFileFid($payload['video'] ?? NULL, 'video');
      if ($fid !== NULL) {
        $values['field_video'] = ['target_id' => $fid];
      }
    }
    else {
      // chirothin_resource. One category term (the field is cardinality 1) from
      // the `resource_category` vocabulary, an optional plain-text description,
      // and an optional file that {@see self::storeUploadedFile()} already made
      // into a managed file for this caller.
      $values['field_resource_type'] = $this->requireTerms(
        $payload['resourceTypes'] ?? [],
        'resourceTypes',
        'resource_category',
        1,
        1,
      );

      $description = $this->createDescription($payload['description'] ?? NULL);
      if ($description !== '') {
        $values['field_description'] = [['value' => $description]];
      }

      $fid = $this->optionalFileFid($payload['resource'] ?? NULL, 'resource');
      if ($fid !== NULL) {
        $values['field_resource'] = ['target_id' => $fid];
      }
    }

    $node = $this->entityTypeManager->getStorage('node')->create($values);
    $node->save();

    // Serialised after the save, the same way setFavourite() is, so the frontend
    // can prepend the exact row the list would have shown. The new node is its
    // author's own, so the favourite state is FALSE.
    $row = $this->serialise($node, $visibility, [(int) $node->id() => FALSE]);

    return $row + [
      'bundle' => $bundle,
      'id' => (int) $node->id(),
    ];
  }

  /**
   * All terms of one vocabulary, as the option list the create form renders.
   *
   * @param string $vid
   *   Vocabulary machine name.
   *
   * @return array<int, array{id: int, label: string}>
   */
  private function vocabularyTerms(string $vid): array {
    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery();
    $ids = $query
      ->accessCheck(FALSE)
      ->condition('vid', $vid)
      ->sort('name')
      ->execute();

    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($ids);

    $options = [];
    foreach ($terms as $term) {
      $options[] = ['id' => (int) $term->id(), 'label' => $term->label()];
    }

    return $options;
  }

  /**
   * Stores a chiropractor-uploaded content file under the public scheme.
   *
   * The mirror of `MessagesService::storeAttachment()` for the resource and
   * training bundles, with the important difference that this writes to
   * `public://`: content files are the library's documents and videos, published
   * to the caller's clinic and read from the Drupal origin directly (the list
   * rows carry an absolute URL), exactly the way the existing resources are
   * stored.
   *
   * Which extension allowlist and size cap apply is the bundle's: resources take
   * the 20 MB document/video union from {@see self::RESOURCE_EXTENSIONS}, and
   * training takes the MP4-only, 100 MB rules of its `field_video` field config
   * ({@see self::TRAINING_EXTENSIONS}, {@see self::MAX_TRAINING_BYTES}). The
   * field a fid will land on is decided by {@see self::create()}, not here.
   *
   * The route that reaches this is gated by `WriteChiropractorAccess`, so the
   * role check is the route's, not this method's — the same arrangement as
   * {@see self::create()}. The returned fids are plain integers a caller can put
   * in a subsequent create payload; a fid is not yet attached to anything until
   * {@see self::create()} stores it on a node, so a create that never happens
   * leaves an orphaned file under public://. Accepted deliberately: the messages
   * module makes the same trade for attachment uploads.
   *
   * @param string $filename
   *   The client-side filename, used for the extension allowlist and the stored
   *   basename.
   * @param string $data
   *   The raw file bytes.
   * @param string $bundle
   *   The bundle the file will be attached to. Chooses the extension allowlist
   *   and size cap. Defaults to `chirothin_resource`; every caller passes it.
   *
   * @return array{fid: int, name: string, size: int, mime: string}
   *
   * @throws \Drupal\headless_content\Exception\ContentException
   *   400 for an unallowed extension, an empty body, an oversized file, or a
   *   store failure.
   */
  public function storeUploadedFile(string $filename, string $data, string $bundle = 'chirothin_resource'): array {
    $rules = $this->uploadRules($bundle);

    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($extension, $rules['extensions'], TRUE)) {
      throw ContentException::invalidCreate($rules['disallowedMessage']);
    }

    $length = strlen($data);
    if ($length === 0) {
      throw ContentException::invalidCreate('The file is empty.');
    }
    if ($length > $rules['maxBytes']) {
      throw ContentException::invalidCreate($rules['oversizeMessage']);
    }

    // basename() strips any client-supplied directory components; control
    // characters would break the stream wrapper URI.
    $basename = $this->fileSystem->basename(trim($filename));
    $basename = preg_replace('/[\x00-\x1F\x7F]+/', '_', $basename);
    if ($basename === '' || $basename === '.') {
      throw ContentException::invalidCreate('The filename is not usable.');
    }

    try {
      // writeData() persists the bytes, derives the MIME type, and returns the
      // saved file entity. FileExists::Rename makes a conflicting name unique
      // (foo_0.pdf).
      $file = $this->fileRepository->writeData($data, 'public://' . $basename, FileExists::Rename);
    }
    catch (\Throwable $e) {
      $this->logger->error('Content upload failed: @message', ['@message' => $e->getMessage()]);
      throw ContentException::invalidCreate('The file could not be stored.');
    }

    return [
      'fid' => (int) $file->id(),
      'name' => $file->getFilename(),
      'size' => $length,
      'mime' => $file->getMimeType() ?: 'application/octet-stream',
    ];
  }

  /**
   * The upload rules one bundle's files are stored under.
   *
   * One answer per bundle so the allowlist, the cap and the two error messages
   * cannot drift from each other or from the field config they mirror.
   *
   * @param string $bundle
   *
   * @return array{extensions: string[], maxBytes: int, disallowedMessage: string, oversizeMessage: string}
   */
  private function uploadRules(string $bundle): array {
    if ($bundle === 'training') {
      return [
        'extensions' => self::TRAINING_EXTENSIONS,
        'maxBytes' => self::MAX_TRAINING_BYTES,
        'disallowedMessage' => 'This file type is not allowed. Training videos must be MP4.',
        'oversizeMessage' => 'The file is larger than 100 MB.',
      ];
    }

    return [
      'extensions' => self::RESOURCE_EXTENSIONS,
      'maxBytes' => self::MAX_RESOURCE_BYTES,
      'disallowedMessage' => 'This file type is not allowed. Use a PDF, image, document or video.',
      'oversizeMessage' => 'The file is larger than 20 MB.',
    ];
  }

  /**
   * A plain-text field the create form may leave blank, without a length cap.
   *
   * The recipe `body` is bounded at {@see self::STRING_MAX_LENGTH} by
   * {@see self::createString()}; a resource description is stored in a `text_long`
   * field with no ceiling, so it is only shaped, not truncated.
   *
   * @param mixed $given
   *   The raw payload value.
   * @param string $field
   *   Payload key, for the error message.
   *
   * @return string
   *   The trimmed value, or '' when absent or blank.
   *
   * @throws \Drupal\headless_content\Exception\ContentException
   *   400 when the value is present but not a string.
   */
  private function createDescription(mixed $given, string $field = 'description'): string {
    if ($given === NULL || $given === '') {
      return '';
    }

    if (!is_string($given)) {
      throw ContentException::invalidCreate(sprintf('"%s" must be text.', $field));
    }

    return trim($given);
  }

  /**
   * An optional managed-file id from the payload, verified to exist.
   *
   * A resource may arrive without a file (`field_resource` is optional on the
   * bundle — 12 of the published resources have none), so NULL is a valid answer.
   * A present id must be a whole positive number naming an existing file, or the
   * create is a 400 rather than a node that saves with a dangling reference.
   *
   * @param mixed $given
   *   The raw payload value.
   * @param string $field
   *   Payload key, for the error message.
   *
   * @return int|null
   *   The verified file id, or NULL when the payload named no file.
   *
   * @throws \Drupal\headless_content\Exception\ContentException
   *   400 when the value is malformed or names no existing file.
   */
  private function optionalFileFid(mixed $given, string $field): ?int {
    if ($given === NULL || $given === '') {
      return NULL;
    }

    if (!is_int($given) || $given < 1) {
      throw ContentException::invalidCreate(sprintf('"%s" must be a whole positive file id.', $field));
    }

    $file = $this->entityTypeManager->getStorage('file')->load($given);
    if ($file === NULL) {
      throw ContentException::invalidCreate(sprintf('The "%s" file does not exist.', $field));
    }

    return $given;
  }

  /**
   * A single bounded string field from the payload, trimmed and validated.
   *
   * @param mixed $given
   *   The raw payload value.
   * @param string $field
   *   Payload key, for the error message.
   * @param bool $required
   *   Whether an absent or blank value is a 400.
   * @param int $maxLength
   *   Ceiling, from the field the value will be stored into.
   *
   * @return string
   *   The trimmed value, or '' when optional and blank.
   *
   * @throws \Drupal\headless_content\Exception\ContentException
   *   400 when the value is not a string, or violates {@see self::STRING_MAX_LENGTH}.
   */
  private function createString(mixed $given, string $field, bool $required, int $maxLength = self::STRING_MAX_LENGTH): string {
    if ($given === NULL || $given === '') {
      if ($required) {
        throw ContentException::invalidCreate(sprintf('"%s" is required.', $field));
      }
      return '';
    }

    if (!is_string($given)) {
      throw ContentException::invalidCreate(sprintf('"%s" must be text.', $field));
    }

    $trimmed = trim($given);
    if ($trimmed === '' && $required) {
      throw ContentException::invalidCreate(sprintf('"%s" is required.', $field));
    }

    if (mb_strlen($trimmed) > $maxLength) {
      throw ContentException::invalidCreate(
        sprintf('"%s" may be at most %d characters.', $field, $maxLength),
      );
    }

    return $trimmed;
  }

  /**
   * The ingredient list from the payload, cleaned up for storage.
   *
   * Blank and whitespace-only lines are dropped rather than stored, so a form
   * that splits on newlines does not leave empty rows in the field. Each kept
   * ingredient is bounded at {@see self::STRING_MAX_LENGTH}, matching the field
   * config's `max_length`.
   *
   * @param mixed $given
   *   The raw payload value.
   *
   * @return string[]
   *
   * @throws \Drupal\headless_content\Exception\ContentException
   *   400 when the value is not a list, or an entry is not a bounded string.
   */
  private function createIngredients(mixed $given): array {
    if ($given === NULL) {
      return [];
    }

    if (!is_array($given)) {
      throw ContentException::invalidCreate('"ingredients" must be a list of text.');
    }

    $ingredients = [];
    foreach ($given as $item) {
      if (!is_string($item)) {
        throw ContentException::invalidCreate('Each ingredient must be a single line of text.');
      }

      $trimmed = trim($item);
      if ($trimmed === '') {
        continue;
      }

      if (mb_strlen($trimmed) > self::STRING_MAX_LENGTH) {
        throw ContentException::invalidCreate(
          sprintf('An ingredient may be at most %d characters.', self::STRING_MAX_LENGTH),
        );
      }

      $ingredients[] = $trimmed;
    }

    return $ingredients;
  }

  /**
   * Term ids for one field, deduplicated and verified against its vocabulary.
   *
   * Every id must resolve to a term in the vocabulary the field draws from. The
   * check is per-id rather than a count after filtering, so an id from the wrong
   * vocabulary is refused by name instead of silently making the field shy by N
   * options. The count rules (min, and the category max of two) are enforced on
   * the *submitted* set, before lookups, so the caller's arithmetic and the
   * field's cardinality are judged separately.
   *
   * @param mixed $given
   *   The raw payload value, `categories` or `types`.
   * @param string $field
   *   Payload key, for the error message.
   * @param string $vocabulary
   *   Vocabulary the ids must belong to, from {@see self::OPTION_VOCABULARIES}.
   * @param int $min
   *   Fewest terms allowed.
   * @param int|null $max
   *   Most terms allowed, or NULL for unlimited.
   *
   * @return int[]
   *   The validated, deduplicated term ids.
   *
   * @throws \Drupal\headless_content\Exception\ContentException
   *   400 when the field is absent/malformed, out of the count range, or names a
   *   term from another vocabulary.
   */
  private function requireTerms(mixed $given, string $field, string $vocabulary, int $min, ?int $max): array {
    if (!is_array($given) || $given === []) {
      throw ContentException::invalidCreate(
        sprintf('"%s" needs at least %d term.', $field, $min),
      );
    }

    $ids = [];
    foreach ($given as $item) {
      // A term id is a whole positive number. Anything else is refused rather
      // than coerced, so `"1"` and `1.0` do not quietly become something the
      // field will store.
      if (!is_int($item) || $item < 1) {
        throw ContentException::invalidCreate(
          sprintf('"%s" terms must be whole positive numbers.', $field),
        );
      }
      $ids[] = $item;
    }

    $ids = array_values(array_unique($ids));

    if (count($ids) < $min) {
      throw ContentException::invalidCreate(
        sprintf('"%s" needs at least %d term.', $field, $min),
      );
    }

    if ($max !== NULL && count($ids) > $max) {
      throw ContentException::invalidCreate(
        sprintf('"%s" accepts at most %d terms.', $field, $max),
      );
    }

    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($ids);

    foreach ($ids as $id) {
      $term = $terms[$id] ?? NULL;
      // `bundle()` on a taxonomy term is the vocabulary machine name. A term id
      // that exists in another vocabulary must not pass as an option of this one:
      // the option list is what the form renders, and a value outside it is a
      // guess the caller should not be able to win.
      if (!$term instanceof TermInterface || $term->bundle() !== $vocabulary) {
        throw ContentException::invalidCreate(
          sprintf('Term %d is not a "%s" option.', $id, $field),
        );
      }
    }

    return $ids;
  }

  /**
   * Turns a node into the payload shape.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param \Drupal\headless_content\ContentVisibility $visibility
   *   The caller's decision, for the audience block.
   * @param array<int, bool> $favourites
   *   The caller's favourites among the ids being serialised, from
   *   {@see ContentFavorites::favouriteIds()}. Passed in rather than looked up per
   *   node so a page of rows costs one flagging query.
   *
   * @return array<string, mixed>
   *   The serialised node.
   */
  private function serialise(NodeInterface $node, ContentVisibility $visibility, array $favourites = []): array {
    $bundle = $node->bundle();

    $row = [
      'id' => (int) $node->id(),
      'bundle' => $bundle,
      'title' => (string) $node->label(),
      'audience' => [
        // What the reader is looking at, not who the node was written for.
        //
        // The clinic ids the node was written for are deliberately gone. A reader
        // belongs to at most one clinic, so the list can only confirm what `mode` and
        // the caller's own scope already established; it cannot tell them anything they
        // can act on, and naming other clinics would tell a patient about clinics they
        // have no relationship with.
        //
        // They were also almost the whole response. A legacy bulk import left recipes
        // tagged to 456 clinics each — `field_published_to` on node 21 spans clinic 1
        // to 620, and 450 of the 456 clinics resolve to the same 116 recipes — so
        // `clinicIds` was ~27 KB of a 27 KB page at `page_size=10`. See README §4.14.
        'mode' => $visibility->mode(),
      ],
      'author' => $this->author($node),
      'created' => $this->timestamp($node, 'created'),
      'updated' => $this->timestamp($node, 'changed'),
    ];

    // Only on bundles the `favorites` flag covers, so "false" and "this cannot be
    // favourited" are different responses. A missing key means the latter: the
    // recipes View wired the flag relationship into `block_3` only, so a training
    // row has never had a star on it in the legacy UI either.
    if ($this->favourites->appliesTo($bundle)) {
      $id = (int) $node->id();
      $row['favourite'] = ($favourites[$id] ?? FALSE) === TRUE;
    }

    // Body on every row, not just the detail one. The legacy library blocks render
    // the body inline in the list (a `panel-body` row in the semanticviews row
    // settings for both recipes displays), so omitting it here would be a smaller
    // payload the frontend cannot actually use.
    foreach (self::TEXT_FIELDS[$bundle] ?? [] as $key => $fieldName) {
      $row[$key] = $this->formattedText($node, $fieldName);
    }

    foreach (self::TERM_FIELDS[$bundle] ?? [] as $key => $fieldName) {
      $row[$key] = $this->termLabels($node, $fieldName);
    }

    foreach (self::FILE_FIELDS[$bundle] ?? [] as $key => $fieldName) {
      $row[$key] = $this->fileReference($node, $fieldName);
    }

    foreach (self::STRING_FIELDS[$bundle] ?? [] as $key => $fieldName) {
      $row[$key] = $this->stringValues($node, $fieldName);
    }

    return $row;
  }

  /**
   * A formatted text field as plain text plus rendered HTML.
   *
   * @return array{text: string, html: string}|null
   *   NULL when the field is absent or empty.
   */
  private function formattedText(FieldableEntityInterface $node, string $fieldName): ?array {
    if (!$node->hasField($fieldName)) {
      return NULL;
    }

    $values = $node->get($fieldName)->getValue();
    if ($values === []) {
      return NULL;
    }

    $raw = (string) ($values[0]['value'] ?? '');
    $format = (string) ($values[0]['format'] ?? '');
    if (trim($raw) === '') {
      return NULL;
    }

    if (in_array($format, self::RENDERABLE_FORMATS, TRUE)) {
      // Built into a variable first, because renderInIsolation() takes its argument
      // **by reference**. Passing an array literal inline is a fatal
      // `Error: Argument #1 ($elements) cannot be passed by reference`, raised at the
      // call site before the renderer runs.
      //
      // This was a live 500, not a latent one: the stored body format decides whether
      // this branch is reached at all, so recipes whose bodies were in a non-renderable
      // format listed fine while every training node with a `basic_html` body took the
      // endpoint down. `php -l` cannot see it, and the symptom arrived as one bundle
      // working and another not.
      //
      // It is covered by ContentSerializationTest::testRenderableFormatReachesTheRenderer(),
      // which fails on the literal without any Drupal container. A Prophecy double of
      // RendererInterface carries the by-reference signature, so the mock is a faithful
      // stand-in for exactly this.
      $build = [
        '#type' => 'processed_text',
        '#text' => $raw,
        '#format' => $format,
      ];

      return [
        'text' => $this->plainText($raw),
        'html' => (string) $this->renderer->renderInIsolation($build),
      ];
    }

    // Unknown or unrestricted format: never filter through whatever the row claims.
    // Delivered as escaped plain text, the same shape either way.
    $plain = $this->plainText($raw);

    return [
      'text' => $plain,
      'html' => nl2br(Html::escape($plain)),
    ];
  }

  /**
   * A display-ready plain-text form of stored body markup.
   */
  private function plainText(string $raw): string {
    $plain = strip_tags($raw);
    // Drop control characters, keep newlines and tabs.
    $plain = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $plain);
    // Collapse runs of blank lines so long recipes do not render as tall gaps.
    $plain = preg_replace("/\n{3,}/", "\n\n", $plain);

    return trim($plain);
  }

  /**
   * A taxonomy field as [{id, label}], in stored order.
   *
   * Labels rather than tids because the vocabulary names are the UI's grouping key
   * (`recipe_category` is how the semanticviews display groups rows into headings)
   * and the ids are an implementation detail of the vocabularies. A term id with no
   * term behind it is skipped rather than emitted as a nameless entry.
   *
   * @return array<int, array{id: int, label: string}>
   */
  private function termLabels(FieldableEntityInterface $node, string $fieldName): array {
    if (!$node->hasField($fieldName)) {
      return [];
    }

    $tids = [];
    foreach ($node->get($fieldName) as $item) {
      $target = $item->target_id ?? NULL;
      if ($target !== NULL && $target !== '') {
        $tids[] = (int) $target;
      }
    }

    if ($tids === []) {
      return [];
    }

    $labels = $this->resolveTermLabels(array_values(array_unique($tids)));

    $terms = [];
    foreach ($tids as $tid) {
      if (isset($labels[$tid]) && $labels[$tid] !== NULL) {
        $terms[] = ['id' => $tid, 'label' => $labels[$tid]];
      }
    }

    return $terms;
  }

  /**
   * Term labels for a set of ids, loaded once per request.
   *
   * `loadMultiple()` and not `load()`: a per-item `load()` is one query per term
   * per row, and 200 recipes with two taxonomy fields each is 800 queries. Same
   * defect as the one fixed in ProgressService::termLabels().
   *
   * @param int[] $tids
   *
   * @return array<int, string|null>
   */
  private function resolveTermLabels(array $tids): array {
    $missing = array_values(array_filter(
      $tids,
      fn (int $tid): bool => !array_key_exists($tid, $this->termLabels)
    ));

    if ($missing !== []) {
      foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($missing) as $tid => $term) {
        $this->termLabels[(int) $tid] = $term->label();
      }
      // A requested term that does not exist is cached as NULL so the next row does
      // not query for it again.
      foreach ($missing as $tid) {
        $this->termLabels[$tid] = $this->termLabels[$tid] ?? NULL;
      }
    }

    return array_intersect_key($this->termLabels, array_flip($tids));
  }

  /**
   * A file field as {url, name, mime, size}, or NULL.
   *
   * The file is loaded rather than trusted: a field pointing at a deleted file is a
   * broken video player in the UI, and the frontend wants one "no file" shape.
   */
  private function fileReference(FieldableEntityInterface $node, string $fieldName): ?array {
    if (!$node->hasField($fieldName)) {
      return NULL;
    }

    $targetId = $node->get($fieldName)->target_id ?? NULL;
    if ($targetId === NULL || $targetId === '') {
      return NULL;
    }

    $file = $this->entityTypeManager->getStorage('file')->load((int) $targetId);
    if ($file === NULL) {
      return NULL;
    }

    $uri = $file->getFileUri();
    if (!is_string($uri)) {
      return NULL;
    }

    return [
      'url' => $this->fileUrlGenerator->generateAbsoluteString($uri),
      'name' => $file->getFilename(),
      'mime' => $file->getMimeType() ?: 'application/octet-stream',
      'size' => (int) $file->getSize(),
    ];
  }

  /**
   * A multi-value string field, in stored order.
   *
   * @return string[]
   */
  private function stringValues(FieldableEntityInterface $node, string $fieldName): array {
    if (!$node->hasField($fieldName)) {
      return [];
    }

    $values = [];
    foreach ($node->get($fieldName) as $item) {
      $value = $item->value ?? NULL;
      if ($value !== NULL && trim((string) $value) !== '') {
        $values[] = (string) $value;
      }
    }

    return $values;
  }

  /**
   * Who wrote it, for the byline the legacy list rendered.
   *
   * @return array{uid: int, name: string}|null
   *   NULL for an unattributed node — an authorless recipe still has to render.
   */
  private function author(NodeInterface $node): ?array {
    $uid = (int) $node->getOwnerId();
    if ($uid <= 0) {
      return NULL;
    }

    if (!array_key_exists($uid, $this->authorNames)) {
      $account = $this->entityTypeManager->getStorage('user')->load($uid);
      $this->authorNames[$uid] = $account?->getAccountName() ?? NULL;
    }

    $name = $this->authorNames[$uid];

    return $name === NULL ? ['uid' => $uid, 'name' => ''] : ['uid' => $uid, 'name' => $name];
  }

  /**
   * A UNIX timestamp from a base field, or NULL.
   */
  private function timestamp(NodeInterface $node, string $fieldName): ?int {
    $value = $node->get($fieldName)->value ?? NULL;

    return is_numeric($value) ? (int) $value : NULL;
  }

}
