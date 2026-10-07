<?php

namespace Drupal\Tests\headless_progress\Unit;

use Drupal\headless_progress\FlagService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\flag\FlagInterface;
use Drupal\flag\FlaggingInterface;
use Drupal\Core\Entity\EntityInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;

/**
 * Tests for the generic FlagService.
 *
 * @group headless_progress
 */
class FlagServiceTest extends TestCase {

  use ProphecyTrait;

  /**
   * @var FlagService
   */
  protected $service;

  /**
   * @var ObjectProphecy|EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var ObjectProphecy|FlagServiceInterface
   */
  protected $flagService;

  /**
   * @var ObjectProphecy|EntityInterface
   */
  protected $entity;

  /**
   * @var ObjectProphecy|EntityInterface
   */
  protected $flagger;

  /**
   * @var ObjectProphecy|FlagInterface
   */
  protected $flag;

  protected function setUp(): void {
    $this->entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $this->flagService = $this->prophesize(FlagServiceInterface::class);
    $this->service = new FlagService(
      $this->entityTypeManager->reveal(),
      $this->flagService->reveal()
    );

    $this->entity = $this->prophesize(EntityInterface::class);
    $this->flagger = $this->prophesize(EntityInterface::class);
    $this->flag = $this->prophesize(FlagInterface::class);
  }

  /**
   * Tests flagEntity succeeds when all entities exist and flag is valid.
   */
  public function testFlagEntitySuccess(): void {
    $entityStorage = $this->prophesize(\Drupal\Core\Entity\EntityStorageInterface::class);
    $entityStorage->load(10)->willReturn($this->entity->reveal());
    $entityStorage->load(20)->willReturn($this->flagger->reveal());
    $this->entityTypeManager->getStorage('user')->willReturn($entityStorage->reveal());

    $this->flagService->getFlagById('test_flag')->willReturn($this->flag->reveal());
    $this->flagService->getFlagging($this->flag->reveal(), $this->entity->reveal(), $this->flagger->reveal())
      ->willReturn(NULL);
    $this->flagService->flag($this->flag->reveal(), $this->entity->reveal(), $this->flagger->reveal())
      ->shouldBeCalled();

    $result = $this->service->flagEntity('test_flag', 'user', 10, 'user', 20);
    $this->assertTrue($result);
  }

  /**
   * Tests flagEntity returns FALSE when entity not found.
   */
  public function testFlagEntityEntityNotFound(): void {
    $entityStorage = $this->prophesize(\Drupal\Core\Entity\EntityStorageInterface::class);
    $entityStorage->load(999)->willReturn(NULL);
    $this->entityTypeManager->getStorage('user')->willReturn($entityStorage->reveal());

    $result = $this->service->flagEntity('test_flag', 'user', 999, 'user', 20);
    $this->assertFalse($result);
  }

  /**
   * Tests flagEntity returns FALSE without touching the Flag module when the
   * flagger id is 0.
   *
   * A caller that could not resolve a flagger used to pass 0, which loads user
   * 0 — the anonymous account. `FlagServiceInterface::flag()` then throws
   * ("Anonymous users must be identified by session_id") out of the submit
   * path, turning a saved log into a 500. Nothing may be flagged here.
   */
  public function testFlagEntityWithZeroFlaggerReturnsFalse(): void {
    // No stub for flag() on purpose: reaching the Flag module at all would be
    // an unexpected-call failure from the prophecy double.
    $this->flagService->getFlagById('test_flag')->shouldNotBeCalled();

    $result = $this->service->flagEntity('test_flag', 'user', 10, 'user', 0);

    $this->assertFalse($result);
  }

  /**
   * Tests flagEntity returns FALSE for a negative flagger id.
   */
  public function testFlagEntityWithNegativeFlaggerReturnsFalse(): void {
    $this->flagService->getFlagById('test_flag')->shouldNotBeCalled();

    $this->assertFalse($this->service->flagEntity('test_flag', 'user', 10, 'user', -1));
  }

  /**
   * Tests flagEntity returns FALSE when flagger not found.
   */
  public function testFlagEntityFlaggerNotFound(): void {
    $entityStorage = $this->prophesize(\Drupal\Core\Entity\EntityStorageInterface::class);
    $entityStorage->load(10)->willReturn($this->entity->reveal());
    $entityStorage->load(999)->willReturn(NULL);
    $this->entityTypeManager->getStorage('user')->willReturn($entityStorage->reveal());

    $result = $this->service->flagEntity('test_flag', 'user', 10, 'user', 999);
    $this->assertFalse($result);
  }

  /**
   * Tests flagEntity returns FALSE when flag not found.
   */
  public function testFlagEntityFlagNotFound(): void {
    $entityStorage = $this->prophesize(\Drupal\Core\Entity\EntityStorageInterface::class);
    $entityStorage->load(10)->willReturn($this->entity->reveal());
    $entityStorage->load(20)->willReturn($this->flagger->reveal());
    $this->entityTypeManager->getStorage('user')->willReturn($entityStorage->reveal());

    $this->flagService->getFlagById('nonexistent_flag')->willReturn(NULL);

    $result = $this->service->flagEntity('nonexistent_flag', 'user', 10, 'user', 20);
    $this->assertFalse($result);
  }

  /**
   * Tests flagEntity returns TRUE (idempotent) when already flagged.
   */
  public function testFlagEntityAlreadyFlagged(): void {
    $entityStorage = $this->prophesize(\Drupal\Core\Entity\EntityStorageInterface::class);
    $entityStorage->load(10)->willReturn($this->entity->reveal());
    $entityStorage->load(20)->willReturn($this->flagger->reveal());
    $this->entityTypeManager->getStorage('user')->willReturn($entityStorage->reveal());

    $this->flagService->getFlagById('test_flag')->willReturn($this->flag->reveal());

    $existingFlagging = $this->prophesize(FlaggingInterface::class);
    $this->flagService->getFlagging($this->flag->reveal(), $this->entity->reveal(), $this->flagger->reveal())
      ->willReturn($existingFlagging->reveal());

    $this->flagService->flag($this->flag->reveal(), $this->entity->reveal(), $this->flagger->reveal())
      ->shouldNotBeCalled();

    $result = $this->service->flagEntity('test_flag', 'user', 10, 'user', 20);
    $this->assertTrue($result);
  }

  /**
   * Tests unflagEntity succeeds when flagged.
   */
  public function testUnflagEntitySuccess(): void {
    $entityStorage = $this->prophesize(\Drupal\Core\Entity\EntityStorageInterface::class);
    $entityStorage->load(10)->willReturn($this->entity->reveal());
    $entityStorage->load(20)->willReturn($this->flagger->reveal());
    $this->entityTypeManager->getStorage('user')->willReturn($entityStorage->reveal());

    $this->flagService->getFlagById('test_flag')->willReturn($this->flag->reveal());

    $existingFlagging = $this->prophesize(FlaggingInterface::class);
    $this->flagService->getFlagging($this->flag->reveal(), $this->entity->reveal(), $this->flagger->reveal())
      ->willReturn($existingFlagging->reveal());
    $this->flagService->unflag($this->flag->reveal(), $this->entity->reveal(), $this->flagger->reveal())
      ->shouldBeCalled();

    $result = $this->service->unflagEntity('test_flag', 'user', 10, 'user', 20);
    $this->assertTrue($result);
  }

  /**
   * Tests unflagEntity returns FALSE when not flagged.
   */
  public function testUnflagEntityNotFlagged(): void {
    $entityStorage = $this->prophesize(\Drupal\Core\Entity\EntityStorageInterface::class);
    $entityStorage->load(10)->willReturn($this->entity->reveal());
    $entityStorage->load(20)->willReturn($this->flagger->reveal());
    $this->entityTypeManager->getStorage('user')->willReturn($entityStorage->reveal());

    $this->flagService->getFlagById('test_flag')->willReturn($this->flag->reveal());
    $this->flagService->getFlagging($this->flag->reveal(), $this->entity->reveal(), $this->flagger->reveal())
      ->willReturn(NULL);

    $result = $this->service->unflagEntity('test_flag', 'user', 10, 'user', 20);
    $this->assertFalse($result);
  }

  /**
   * Tests isFlagged returns TRUE when flagged.
   */
  public function testIsFlaggedTrue(): void {
    $entityStorage = $this->prophesize(\Drupal\Core\Entity\EntityStorageInterface::class);
    $entityStorage->load(10)->willReturn($this->entity->reveal());
    $entityStorage->load(20)->willReturn($this->flagger->reveal());
    $this->entityTypeManager->getStorage('user')->willReturn($entityStorage->reveal());

    $this->flagService->getFlagById('test_flag')->willReturn($this->flag->reveal());

    $existingFlagging = $this->prophesize(FlaggingInterface::class);
    $this->flagService->getFlagging($this->flag->reveal(), $this->entity->reveal(), $this->flagger->reveal())
      ->willReturn($existingFlagging->reveal());

    $result = $this->service->isFlagged('test_flag', 'user', 10, 'user', 20);
    $this->assertTrue($result);
  }

  /**
   * Tests isFlagged returns FALSE when not flagged.
   */
  public function testIsFlaggedFalse(): void {
    $entityStorage = $this->prophesize(\Drupal\Core\Entity\EntityStorageInterface::class);
    $entityStorage->load(10)->willReturn($this->entity->reveal());
    $entityStorage->load(20)->willReturn($this->flagger->reveal());
    $this->entityTypeManager->getStorage('user')->willReturn($entityStorage->reveal());

    $this->flagService->getFlagById('test_flag')->willReturn($this->flag->reveal());
    $this->flagService->getFlagging($this->flag->reveal(), $this->entity->reveal(), $this->flagger->reveal())
      ->willReturn(NULL);

    $result = $this->service->isFlagged('test_flag', 'user', 10, 'user', 20);
    $this->assertFalse($result);
  }

  /**
   * Tests isFlagged returns FALSE when flag not found.
   */
  public function testIsFlaggedFlagNotFound(): void {
    $entityStorage = $this->prophesize(\Drupal\Core\Entity\EntityStorageInterface::class);
    $entityStorage->load(10)->willReturn($this->entity->reveal());
    $entityStorage->load(20)->willReturn($this->flagger->reveal());
    $this->entityTypeManager->getStorage('user')->willReturn($entityStorage->reveal());

    $this->flagService->getFlagById('nonexistent_flag')->willReturn(NULL);

    $result = $this->service->isFlagged('nonexistent_flag', 'user', 10, 'user', 20);
    $this->assertFalse($result);
  }
}