<?php

namespace Drupal\Tests\headless_progress\Unit;

use Drupal\headless_progress\PatientMessageService;
use Drupal\headless_progress\FlagService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\Entity\User;
use Drupal\contact\Entity\Message;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;

/**
 * Tests for the PatientMessageService.
 *
 * @group headless_progress
 */
class PatientMessageServiceTest extends TestCase {

  use ProphecyTrait;

  /**
   * @var PatientMessageService
   */
  protected $service;

  /**
   * @var ObjectProphecy|EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var ObjectProphecy|FlagService
   */
  protected $flagService;

  /**
   * @var ObjectProphecy|User
   */
  protected $patient;

  /**
   * @var ObjectProphecy|User
   */
  protected $chiropractor;

  protected function setUp(): void {
    $this->entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $this->flagService = $this->prophesize(FlagService::class);
    $this->service = new PatientMessageService(
      $this->entityTypeManager->reveal(),
      $this->flagService->reveal()
    );

    $this->patient = $this->prophesize(User::class);
    $this->chiropractor = $this->prophesize(User::class);
  }

  /**
   * Tests flagForReview delegates to FlagService.
   */
  public function testFlagForReviewDelegatesToFlagService(): void {
    $this->flagService->flagEntity('reviewed_patients', 'user', 10, 'user', 20)
      ->shouldBeCalled()->willReturn(TRUE);

    $result = $this->service->flagForReview(10);
    $this->assertTrue($result);
  }

  /**
   * Tests flagForReview returns FALSE when FlagService returns FALSE.
   */
  public function testFlagForReviewReturnsFalse(): void {
    $this->flagService->flagEntity('reviewed_patients', 'user', 999, 'user', 0)
      ->shouldBeCalled()->willReturn(FALSE);

    $result = $this->service->flagForReview(999);
    $this->assertFalse($result);
  }

  /**
   * Tests createQuestionMessage returns NULL when patient not found.
   */
  public function testCreateQuestionMessagePatientNotFound(): void {
    $userStorage = $this->prophesize(\Drupal\Core\Entity\EntityStorageInterface::class);
    $userStorage->load(999)->willReturn(NULL);
    $this->entityTypeManager->getStorage('user')->willReturn($userStorage->reveal());

    $result = $this->service->createQuestionMessage(999, 'Test question');
    $this->assertNull($result);
  }

  /**
   * Tests createQuestionMessage returns NULL when chiropractor not assigned.
   */
  public function testCreateQuestionMessageNoChiropractor(): void {
    $this->patient->get('field_chiropractor')->willReturn($this->createEmptyFieldProphecy());

    $userStorage = $this->prophesize(\Drupal\Core\Entity\EntityStorageInterface::class);
    $userStorage->load(10)->willReturn($this->patient->reveal());
    $this->entityTypeManager->getStorage('user')->willReturn($userStorage->reveal());

    $result = $this->service->createQuestionMessage(10, 'Test question');
    $this->assertNull($result);
  }

  /**
   * Helper to create a field prophecy with value.
   */
  private function createFieldProphecy(array $value): ObjectProphecy {
    $field = $this->prophesize(\Drupal\Core\Field\FieldItemListInterface::class);
    $field->getValue()->willReturn([$value]);
    $field->target_id = $value['target_id'];
    return $field;
  }

  /**
   * Helper to create an empty field prophecy.
   */
  private function createEmptyFieldProphecy(): ObjectProphecy {
    $field = $this->prophesize(\Drupal\Core\Field\FieldItemListInterface::class);
    $field->getValue()->willReturn([]);
    $field->target_id = NULL;
    return $field;
  }
}