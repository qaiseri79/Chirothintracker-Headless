<?php

namespace Drupal\Tests\headless_progress\Unit;

use Drupal\headless_progress\ProgressService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\taxonomy\TermInterface;
use Prophecy\Argument;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;

/**
 * Tests for ProgressService::termLabels().
 *
 * @group headless_progress
 */
class ProgressServiceTermLabelsTest extends TestCase {

  use ProphecyTrait;

  protected ProgressService $service;

  protected ObjectProphecy $entityTypeManager;

  protected ObjectProphecy $termStorage;

  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $this->termStorage = $this->prophesize(EntityStorageInterface::class);

    $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->willReturn($this->termStorage->reveal());

    $this->service = new ProgressService(
      $this->entityTypeManager->reveal(),
      $this->prophesize(AccountProxyInterface::class)->reveal()
    );
  }

  /**
   * Calls the protected termLabels() through reflection.
   */
  protected function termLabels(?array $ids): array {
    $method = new \ReflectionMethod(ProgressService::class, 'termLabels');
    $method->setAccessible(TRUE);
    return $method->invoke($this->service, $ids);
  }

  /**
   * A term with the given label, keyed as loadMultiple() would return it.
   */
  protected function term(string $id, string $label): TermInterface {
    $term = $this->prophesize(TermInterface::class);
    $term->label()->willReturn($label);
    return $term->reveal();
  }

  /**
   * Labels resolve in the order the patient selected them.
   */
  public function testLabelsResolveInSelectionOrder(): void {
    $this->termStorage->loadMultiple([3, 9])->willReturn([
      3 => $this->term('3', 'Chicken breast'),
      9 => $this->term('9', 'Salmon'),
    ]);

    $this->assertSame(
      ['Chicken breast', 'Salmon'],
      $this->termLabels([3, 9])
    );
  }

  /**
   * The batch read goes through loadMultiple(), never load().
   *
   * load() takes a single ID and uses its argument as an array key internally
   * (EntityStorageBase::load(), line 263), so passing it a list of IDs raises
   * `TypeError: Illegal offset type` and 500s the whole dashboard. Prophecy makes
   * that distinction checkable rather than a matter of reading the source: an
   * unexpected load() call fails the test here.
   */
  public function testBatchReadUsesLoadMultiple(): void {
    $this->termStorage->loadMultiple([4])->willReturn([4 => $this->term('4', 'Rice')]);
    $this->termStorage->load(Argument::any())->shouldNotBeCalled();

    $this->assertSame(['Rice'], $this->termLabels([4]));
  }

  /**
   * Several terms resolve from one storage call.
   */
  public function testSeveralTermsResolveFromOneCall(): void {
    $this->termStorage->loadMultiple([1, 2, 3])->willReturn([
      1 => $this->term('1', 'Broccoli'),
      2 => $this->term('2', 'Carrots'),
      3 => $this->term('3', 'Spinach'),
    ]);

    $this->assertSame(
      ['Broccoli', 'Carrots', 'Spinach'],
      $this->termLabels([1, 2, 3])
    );
  }

  /**
   * An ID whose term was deleted is dropped, not emitted as a bare number.
   */
  public function testDeletedTermIsOmitted(): void {
    $this->termStorage->loadMultiple([5, 6])->willReturn([
      5 => $this->term('5', 'Tofu'),
    ]);

    $this->assertSame(['Tofu'], $this->termLabels([5, 6]));
  }

  /**
   * Nothing selected means no storage call at all.
   */
  public function testEmptySelectionDoesNotQuery(): void {
    $this->termStorage->loadMultiple(Argument::any())->shouldNotBeCalled();

    $this->assertSame([], $this->termLabels(NULL));
    $this->assertSame([], $this->termLabels([]));
  }

  /**
   * Non-scalar members are dropped before the read, not passed to it.
   *
   * This is the shape that produced the original "Illegal offset type" from the
   * caller side: getFieldValue() hands back a nested value array for a field
   * holding more than one item, and a nested array cannot be an array key.
   */
  public function testNonScalarMembersAreDropped(): void {
    $this->termStorage->loadMultiple([7])->willReturn([7 => $this->term('7', 'Eggs')]);

    $this->assertSame(['Eggs'], $this->termLabels([7, ['nested' => 8], NULL]));
  }

  /**
   * A selection of only non-scalars reads nothing and returns nothing.
   */
  public function testOnlyNonScalarsReturnsEmpty(): void {
    $this->termStorage->loadMultiple(Argument::any())->shouldNotBeCalled();

    $this->assertSame([], $this->termLabels([['nested' => 8]]));
  }

}
