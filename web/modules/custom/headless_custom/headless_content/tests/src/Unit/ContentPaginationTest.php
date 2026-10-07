<?php

declare(strict_types=1);

namespace Drupal\headless_content;

use Drupal\headless_content\Exception\ContentException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ContentService::normalisePaging().
 *
 * The recipe library is 634 published rows and was previously truncated at 200 with
 * no way to reach the other 434 — a third of the library was simply unreachable, and
 * the response's `total` reported the truncated count so nothing looked wrong.
 * Pagination fixed that, and the two ends of the range are deliberately different
 * answers: too large is clamped, because that is what bounds the query, and below 1 is
 * a 400, because a page number that names no page has no honest default to fold into.
 *
 * @group headless_content
 * @coversDefaultClass \Drupal\headless_content\ContentService
 */
class ContentPaginationTest extends TestCase {

  /**
   * The documented defaults.
   */
  public function testDefaults(): void {
    $this->assertSame(ContentService::DEFAULT_PAGE_SIZE, 50);
    $this->assertSame(ContentService::MAX_PAGE_SIZE, 200);
  }

  /**
   * A normal request is untouched.
   */
  public function testValidPagingPassesThrough(): void {
    $this->assertSame([1, 50], ContentService::normalisePaging(1, 50));
    $this->assertSame([3, 25], ContentService::normalisePaging(3, 25));
    $this->assertSame([13, 200], ContentService::normalisePaging(13, 200));
  }

  /**
   * Page numbers below one are refused rather than folded into page one.
   *
   * This test used to assert the opposite, and the change is the point of it.
   *
   * `listBundle()` turns the page into `range(($page - 1) * $pageSize, $pageSize)`,
   * so `page=0` is `range(-30, 30)`. MySQL reads that negative offset as zero and
   * returns page one. A client indexing pages from 0 and walking `0..pageCount`
   * therefore receives page one twice and concatenates it.
   *
   * Measured on this site: a clinic with 116 recipes at `page_size=30` reached the
   * frontend as 146 rows — 116 plus one duplicated page of 30. Every individual
   * response was valid, `totalItems` said 116, `pageCount` said 4, and the only
   * symptom was duplicated recipes in the list. Clamping destroyed the one signal
   * that would have shown the caller had counted wrong.
   *
   * @see \Drupal\headless_content\Exception\ContentException::pagingOutOfRange()
   */
  public function testPageBelowOneIsRefused(): void {
    foreach ([0, -1, -5] as $bad) {
      try {
        ContentService::normalisePaging($bad, 50);
        $this->fail(sprintf('page=%d was accepted, but it names no page.', $bad));
      }
      catch (ContentException $e) {
        $this->assertSame(400, $e->getStatusCode());
        $this->assertStringContainsString('"page"', $e->getMessage());
        $this->assertStringContainsString('1 or greater', $e->getMessage());
      }
    }
  }

  /**
   * An oversized page is capped, so a client cannot uncap the query.
   *
   * Still clamped rather than refused: this is the one adjustable value, and the
   * asymmetry with {@see self::testPageBelowOneIsRefused()} is deliberate. This is
   * why the old cap of 200 was not simply raised. A cap that a caller cannot
   * exceed bounds the worst case; a default that a caller can raise does not.
   */
  public function testPageSizeIsCappedAtTheMaximum(): void {
    $this->assertSame([1, 200], ContentService::normalisePaging(1, 100000));
    $this->assertSame([1, 200], ContentService::normalisePaging(1, PHP_INT_MAX));
  }

  /**
   * A zero or negative page size is refused, not answered with one arbitrary row.
   *
   * `range($offset, 0)` is legal and returns no rows, so this used to clamp to 1 —
   * which reads to a caller as "the library has one recipe in it" rather than "you
   * sent me a bad number". A filter that computed zero rows deserves to be told so.
   */
  public function testPageSizeBelowOneIsRefused(): void {
    foreach ([0, -10] as $bad) {
      try {
        ContentService::normalisePaging(1, $bad);
        $this->fail(sprintf('page_size=%d was accepted, but it fits no page.', $bad));
      }
      catch (ContentException $e) {
        $this->assertSame(400, $e->getStatusCode());
        $this->assertStringContainsString('"page_size"', $e->getMessage());
        $this->assertStringContainsString('1 or greater', $e->getMessage());
      }
    }
  }

  /**
   * The list order ends in a field that is unique.
   *
   * The regression this test exists for: `listBundle()` sorted by `sticky` then
   * `title`, which is not a total order. This library has 634 published recipes
   * sharing 221 distinct titles, so a row sorted on (sticky, title) has no fixed
   * position — MySQL may order tied rows differently for two queries.
   *
   * That makes `range()` unsafe: pages overlap and rows fall between them. Measured
   * on this site, walking the library at 50 rows a page returned two nodes on two
   * pages each and silently dropped two others. The endpoint reported 634, the
   * frontend rendered 632, and nothing anywhere reported an error. The visible
   * symptom was a React duplicate-key crash over one duplicated id.
   *
   * `nid` is unique, so adding it last makes the order total. Asserted against
   * ContentService::LIST_SORT rather than by re-running a query, because the
   * property under test is "the declared order ends in something unique" and a test
   * that provisions duplicate titles only proves it for the fixtures it chose.
   *
   * @see \Drupal\headless_content\ContentService::LIST_SORT
   */
  public function testListOrderEndsInAUniqueField(): void {
    $sort = ContentService::LIST_SORT;

    $this->assertNotEmpty($sort, 'The list order must not be empty.');

    $lastField = array_key_last($sort);
    $this->assertSame(
      'nid',
      $lastField,
      'The list order must end in `nid`. Without a unique final field the order is not total, so LIMIT/OFFSET pages overlap and silently drop rows.',
    );

    // A unique final field is only useful if nothing after it can re-introduce the
    // tie, so the tiebreaker has to be last rather than merely present.
    $this->assertSame(
      ['sticky', 'title', 'nid'],
      array_keys($sort),
      'Unexpected list order. `nid` is the tiebreaker and belongs last.',
    );
  }

  /**
   * The declared order is strictly descending-then-ascending, as the Views order it.
   *
   * Pins the readable half of LIST_SORT so a reordering that keeps `nid` last but
   * changes what the library looks like is still a deliberate act.
   */
  public function testListOrderIsStickyThenTitleAscending(): void {
    $this->assertSame(
      ['sticky' => 'DESC', 'title' => 'ASC', 'nid' => 'ASC'],
      ContentService::LIST_SORT,
    );
  }

  /**
   * Titles repeat, which is why the order needs a tiebreaker at all.
   *
   * A fixture, not a test of production behaviour: it records the shape of the data
   * that made the tiebreaker necessary. If real recipe titles ever became unique,
   * this would be the test to notice — and the honest response would be to leave
   * `nid` in place anyway, since a future duplicate title must not be able to
   * reproduce the fault.
   */
  public function testDuplicateTitlesWouldOverlapWithoutATiebreaker(): void {
    // What the library actually looks like: recipes sharing a title, and the
    // pagination walking them in title order.
    $rows = [
      ['nid' => 21, 'title' => 'Apple & Balsamic Vinaigrette Salad'],
      ['nid' => 2296, 'title' => 'Bang Bang Cauliflower'],
      ['nid' => 2427, 'title' => 'Bang Bang Cauliflower'],
      ['nid' => 2554, 'title' => 'Bang Bang Cauliflower'],
    ];

    // Two rows share a sort key, so the pair is unordered without a tiebreaker:
    // either could be written first, which is the whole mechanism.
    $tied = array_filter(
      $rows,
      static fn (array $a): bool => (bool) array_filter(
        $rows,
        static fn (array $b): bool => $b['title'] === $a['title'] && $b['nid'] !== $a['nid'],
      ),
    );
    $this->assertCount(3, $tied, 'The fixture must contain tied rows to mean anything.');

    // With `nid` appended the tied group has one deterministic order, and paging by
    // (sticky, title, nid) is stable. This is the property LIST_SORT relies on.
    $order = static fn (array $a, array $b): int => [$a['title'], $a['nid']] <=> [$b['title'], $b['nid']];
    usort($rows, $order);

    $tiedOrder = array_column(
      array_values(array_filter($rows, static fn (array $r): bool => $r['title'] === 'Bang Bang Cauliflower')),
      'nid',
    );
    $this->assertSame([2296, 2427, 2554], $tiedOrder, 'The tied group must have one order.');

    // Sorting is idempotent, which is what "no fixed position" would break: run it
    // again on the already-sorted rows and nothing may move.
    $once = $rows;
    usort($rows, $order);
    $this->assertSame($once, $rows, 'Sorting by (title, nid) must be idempotent.');
  }

  /**
   * Every reachable page of the recipe library is addressable.
   *
   * The regression this test exists for: with the old LIST_LIMIT of 200 and no
   * paging, a clinic's recipes beyond row 200 could not be fetched by any request.
   *
   * Note what this does *not* cover: it asserts that the arithmetic covers every
   * offset exactly once, which holds for any page size. It cannot detect a query
   * whose ordering is not total, which is why it stayed green while the real
   * endpoint dropped rows. See {@see self::testListOrderEndsInAUniqueField()}.
   */
  public function testEveryPageOfTheFullLibraryIsReachable(): void {
    // 634 published recipes, the site-wide count, at the maximum page size.
    $totalItems = 634;
    $lastPage = (int) ceil($totalItems / ContentService::MAX_PAGE_SIZE);

    $this->assertSame(4, $lastPage);

    // Every page returns rows, and together they cover the library with no gap and
    // no overlap.
    $seen = [];
    for ($page = 1; $page <= $lastPage; $page++) {
      $offset = ($page - 1) * ContentService::MAX_PAGE_SIZE;
      $rows = min(ContentService::MAX_PAGE_SIZE, $totalItems - $offset);
      $this->assertGreaterThan(0, $rows, "page $page returned nothing");

      for ($i = 0; $i < $rows; $i++) {
        $id = $offset + $i + 1;
        $this->assertArrayNotHasKey($id, $seen, "recipe $id appeared on two pages");
        $seen[$id] = TRUE;
      }
    }

    $this->assertCount($totalItems, $seen);
  }

}