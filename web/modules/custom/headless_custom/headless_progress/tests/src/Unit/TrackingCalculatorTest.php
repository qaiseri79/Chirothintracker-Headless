<?php

namespace Drupal\Tests\headless_progress\Unit;

use Drupal\headless_progress\TrackingCalculator;
use Drupal\headless_progress\PatientMessageService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\Entity\User;
use Drupal\contact\Entity\Message;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\flag\FlagServiceInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;

/**
 * Tests for the TrackingCalculator service.
 *
 * @group headless_progress
 */
class TrackingCalculatorTest extends TestCase {

  use ProphecyTrait;

  /**
   * @var TrackingCalculator
   */
  protected $calculator;

  /**
   * @var ObjectProphecy|EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var ObjectProphecy|PatientMessageService
   */
  protected $patientMessageService;

  /**
   * @var ObjectProphecy|User
   */
  protected $account;

  /**
   * @var ObjectProphecy|Message
   */
  protected $message;

  protected function setUp(): void {
    $this->entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $this->patientMessageService = $this->prophesize(PatientMessageService::class);
    $this->calculator = new TrackingCalculator(
      $this->entityTypeManager->reveal(),
      $this->patientMessageService->reveal()
    );

    $this->account = $this->prophesize(User::class);
    $this->message = $this->prophesize(Message::class);
  }

  /**
   * Tests computeProgramDay with valid dates.
   */
  public function testComputeProgramDay(): void {
    // Set up account with program start date
    $start_date_field = $this->prophesize(FieldItemListInterface::class);
    $start_date_field->getValue()->willReturn([['value' => '2024-01-01']]);
    $this->account->get('field_program_start_date')->willReturn($start_date_field->reveal());

    // Test same day = day 1
    $reflection = new \ReflectionClass($this->calculator);
    $method = $reflection->getMethod('computeProgramDay');
    $method->setAccessible(true);

    $result = $method->invoke($this->calculator, $this->account->reveal(), '2024-01-01');
    $this->assertEquals(1, $result);

    // Test next day = day 2
    $result = $method->invoke($this->calculator, $this->account->reveal(), '2024-01-02');
    $this->assertEquals(2, $result);

    // Test week later = day 8
    $result = $method->invoke($this->calculator, $this->account->reveal(), '2024-01-08');
    $this->assertEquals(8, $result);

    // Test date before start = 0
    $result = $method->invoke($this->calculator, $this->account->reveal(), '2023-12-31');
    $this->assertEquals(0, $result);
  }

  /**
   * Tests computeProgramDay with missing start date.
   */
  public function testComputeProgramDayNoStartDate(): void {
    $empty_field = $this->prophesize(FieldItemListInterface::class);
    $empty_field->getValue()->willReturn([]);
    $this->account->get('field_program_start_date')->willReturn($empty_field->reveal());

    $reflection = new \ReflectionClass($this->calculator);
    $method = $reflection->getMethod('computeProgramDay');
    $method->setAccessible(true);

    $result = $method->invoke($this->calculator, $this->account->reveal(), '2024-01-01');
    $this->assertNull($result);
  }

  /**
   * Tests computeWeightLoss with valid weights.
   */
  public function testComputeWeightLoss(): void {
    $start_weight_field = $this->prophesize(FieldItemListInterface::class);
    $start_weight_field->getValue()->willReturn([['value' => '200.0']]);
    $this->account->get('field_program_start_weight')->willReturn($start_weight_field->reveal());

    $reflection = new \ReflectionClass($this->calculator);
    $method = $reflection->getMethod('computeWeightLoss');
    $method->setAccessible(true);

    // Lost 10 lbs
    $result = $method->invoke($this->calculator, $this->account->reveal(), '190.0');
    $this->assertEquals(10.0, $result);

    // No loss
    $result = $method->invoke($this->calculator, $this->account->reveal(), '200.0');
    $this->assertEquals(0.0, $result);

    // Weight gain (should return 0, not negative)
    $result = $method->invoke($this->calculator, $this->account->reveal(), '210.0');
    $this->assertEquals(0.0, $result);
  }

  /**
   * Tests computeWeightLoss with missing start weight.
   */
  public function testComputeWeightLossNoStartWeight(): void {
    $empty_field = $this->prophesize(FieldItemListInterface::class);
    $empty_field->getValue()->willReturn([]);
    $this->account->get('field_program_start_weight')->willReturn($empty_field->reveal());

    $reflection = new \ReflectionClass($this->calculator);
    $method = $reflection->getMethod('computeWeightLoss');
    $method->setAccessible(true);

    $result = $method->invoke($this->calculator, $this->account->reveal(), '190.0');
    $this->assertNull($result);
  }

  /**
   * Tests computeTotalInches with submitted values.
   */
  public function testComputeTotalInches(): void {
    $reflection = new \ReflectionClass($this->calculator);
    $method = $reflection->getMethod('computeTotalInches');
    $method->setAccessible(true);

    $submitted_values = [
      'field_neck' => '15.0',
      'field_chest' => '40.0',
      'field_abdomen' => '35.0',
      // Other fields not provided
    ];

    $result = $method->invoke($this->calculator, $this->message->reveal(), $submitted_values);
    $this->assertEquals(90.0, $result);
  }

  /**
   * Tests computeTotalInches falls back to message values.
   */
  public function testComputeTotalInchesFallbackToMessage(): void {
    $reflection = new \ReflectionClass($this->calculator);
    $method = $reflection->getMethod('computeTotalInches');
    $method->setAccessible(true);

    // Message has values for fields not in submitted_values
    $field_neck = $this->prophesize(FieldItemListInterface::class);
    $field_neck->isEmpty()->willReturn(FALSE);
    $field_neck->getValue()->willReturn([['value' => '15.5']]);

    $field_chest = $this->prophesize(FieldItemListInterface::class);
    $field_chest->isEmpty()->willReturn(FALSE);
    $field_chest->getValue()->willReturn([['value' => '40.5']]);

    $this->message->get('field_neck')->willReturn($field_neck->reveal());
    $this->message->get('field_chest')->willReturn($field_chest->reveal());

    // Other fields return empty
    for ($i = 0; $i < 10; $i++) {
      $empty_field = $this->prophesize(FieldItemListInterface::class);
      $empty_field->isEmpty()->willReturn(TRUE);
      $this->message->get("field_other_$i")->willReturn($empty_field->reveal());
    }

    $submitted_values = [];
    $result = $method->invoke($this->calculator, $this->message->reveal(), $submitted_values);
    $this->assertEquals(56.0, $result); // 15.5 + 40.5
  }

  /**
   * Tests computeNetInchesLost.
   */
  public function testComputeNetInchesLost(): void {
    $start_inches_field = $this->prophesize(FieldItemListInterface::class);
    $start_inches_field->getValue()->willReturn([['value' => '300.0']]);
    $this->account->get('field_program_start_inches')->willReturn($start_inches_field->reveal());

    $reflection = new \ReflectionClass($this->calculator);
    $method = $reflection->getMethod('computeNetInchesLost');
    $method->setAccessible(true);

    // Lost 10 inches
    $result = $method->invoke($this->calculator, $this->account->reveal(), 290.0);
    $this->assertEquals(10.0, $result);

    // No change
    $result = $method->invoke($this->calculator, $this->account->reveal(), 300.0);
    $this->assertEquals(0.0, $result);
  }

  /**
   * Tests computeGoalAchieved.
   */
  public function testComputeGoalAchieved(): void {
    $goal_weight_field = $this->prophesize(FieldItemListInterface::class);
    $goal_weight_field->getValue()->willReturn([['value' => '150.0']]);
    $this->account->get('field_goal_weight')->willReturn($goal_weight_field->reveal());

    $start_weight_field = $this->prophesize(FieldItemListInterface::class);
    $start_weight_field->getValue()->willReturn([['value' => '200.0']]);
    $this->account->get('field_program_start_weight')->willReturn($start_weight_field->reveal());

    $reflection = new \ReflectionClass($this->calculator);
    $method = $reflection->getMethod('computeGoalAchieved');
    $method->setAccessible(true);

    // Lost 25 lbs out of 50 lbs to lose = 50%
    $result = $method->invoke($this->calculator, $this->account->reveal(), 25.0);
    $this->assertEquals(50.0, $result);

    // Lost 50 lbs out of 50 = 100%
    $result = $method->invoke($this->calculator, $this->account->reveal(), 50.0);
    $this->assertEquals(100.0, $result);

    // Lost 60 lbs (exceeds goal) = capped at 100%
    $result = $method->invoke($this->calculator, $this->account->reveal(), 60.0);
    $this->assertEquals(100.0, $result);
  }

  /**
   * Tests computeGoalAchieved with missing goal weight.
   */
  public function testComputeGoalAchievedNoGoal(): void {
    $empty_field = $this->prophesize(FieldItemListInterface::class);
    $empty_field->getValue()->willReturn([]);
    $this->account->get('field_goal_weight')->willReturn($empty_field->reveal());

    $start_weight_field = $this->prophesize(FieldItemListInterface::class);
    $start_weight_field->getValue()->willReturn([['value' => '200.0']]);
    $this->account->get('field_program_start_weight')->willReturn($start_weight_field->reveal());

    $reflection = new \ReflectionClass($this->calculator);
    $method = $reflection->getMethod('computeGoalAchieved');
    $method->setAccessible(true);

    $result = $method->invoke($this->calculator, $this->account->reveal(), 25.0);
    $this->assertNull($result);
  }

  /**
   * Tests computeCurrentProgramDay.
   */
  public function testComputeCurrentProgramDay(): void {
    $reflection = new \ReflectionClass($this->calculator);
    $method = $reflection->getMethod('computeCurrentProgramDay');
    $method->setAccessible(true);

    $result = $method->invoke($this->calculator, $this->account->reveal(), 5);
    $this->assertEquals(5, $result);

    $result = $method->invoke($this->calculator, $this->account->reveal(), null);
    $this->assertEquals(0, $result);
  }

  /**
   * Tests computeAll returns separated message and user derived fields.
   */
  public function testComputeAllStructure(): void {
    // Set up account with all required fields
    $setupField = function ($field_name, $value) {
      $field = $this->prophesize(FieldItemListInterface::class);
      $field->getValue()->willReturn([['value' => $value]]);
      $this->account->get($field_name)->willReturn($field->reveal());
    };

    $setupField('field_program_start_date', '2024-01-01');
    $setupField('field_program_start_weight', '200.0');
    $setupField('field_program_start_inches', '300.0');
    $setupField('field_goal_weight', '150.0');

    // Set up message with date field
    $date_field = $this->prophesize(FieldItemListInterface::class);
    $date_field->getValue()->willReturn([['value' => '2024-01-15']]);
    $this->message->get('field_date')->willReturn($date_field->reveal());

    $weight_field = $this->prophesize(FieldItemListInterface::class);
    $weight_field->getValue()->willReturn([['value' => '190.0']]);
    $this->message->get('field_weight')->willReturn($weight_field->reveal());

    // Mock measurement fields as empty
    $empty_field = $this->prophesize(FieldItemListInterface::class);
    $empty_field->isEmpty()->willReturn(TRUE);
    foreach (['field_neck', 'field_chest', 'field_shoulders', 'field_arm_left_bicep', 'field_arm_right_bicep', 'field_abdomen', 'field_hips', 'field_thigh_left', 'field_thigh_right', 'field_calf_left', 'field_calf_right'] as $field) {
      $this->message->get($field)->willReturn($empty_field->reveal());
    }

    $submitted_values = [
      'field_date' => '2024-01-15',
      'field_weight' => '190.0',
      'field_water_intake' => '100',
      'field_grade' => '10',
      'field_mind_set_work' => '1',
    ];

    $result = $this->calculator->computeAll($this->message->reveal(), $submitted_values, $this->account->reveal());

    // Check structure
    $this->assertArrayHasKey('message', $result);
    $this->assertArrayHasKey('user', $result);

    // Check message fields
    $this->assertEquals(15, $result['message']['field_program_day']);
    $this->assertEquals(15, $result['message']['field_program_day_computed']);
    $this->assertEquals(10.0, $result['message']['field_weight_loss_computed']);
    $this->assertEquals(10.0, $result['message']['field_weight_loss_to_date']);
    $this->assertEquals(0.0, $result['message']['field_total_measurements']); // No measurements submitted

    // Check user fields
    $this->assertEquals(10.0, $result['user']['field_net_weight_loss']);
    $this->assertEquals(0.0, $result['user']['field_net_inches_lost']); // 300 - 300 = 0
    $this->assertEquals(20.0, $result['user']['field_goal_achieved']); // 10/50 * 100 = 20%
    $this->assertEquals(10.0, $result['user']['field_gross_weight_loss']);
    $this->assertEquals(15, $result['user']['field_user_current_program_day_c']);
  }

  /**
   * Tests checkLateSubmission sets field_late on first submission.
   */
  public function testCheckLateSubmissionFirstSubmission(): void {
    $query = $this->prophesize(\Drupal\Core\Entity\Query\QueryInterface::class);
    $query->accessCheck(FALSE)->willReturn($query->reveal());
    $query->condition('contact_form', 'tracking_weight')->willReturn($query->reveal());
    $query->condition('uid', 1)->willReturn($query->reveal());
    $query->condition('id', 100, '<>')->willReturn($query->reveal());
    $query->sort('created', 'DESC')->willReturn($query->reveal());
    $query->range(0, 1)->willReturn($query->reveal());
    $query->execute()->willReturn([]);

    $storage = $this->prophesize(\Drupal\Core\Entity\EntityStorageInterface::class);
    $this->entityTypeManager->getStorage('contact_message')->willReturn($storage->reveal());

    $this->account->id()->willReturn(1);

    $message = $this->prophesize(Message::class);
    $message->id()->willReturn(100);

    $reflection = new \ReflectionClass($this->calculator);
    $method = $reflection->getMethod('checkLateSubmission');
    $method->setAccessible(true);

    $method->invoke($this->calculator, $this->account->reveal(), $message->reveal());

    // Should set field_late = 1 (first submission)
    $this->account->set('field_late', 1)->shouldHaveBeenCalled();
    $this->account->save()->shouldHaveBeenCalled();
  }

  /**
   * Tests runEvaluations calls flagForReview and createQuestionMessage.
   */
  public function testRunEvaluationsCallsPatientMessageService(): void {
    $this->account->id()->willReturn(42);

    // Mock message with field_question and field_date
    $question_field = $this->prophesize(FieldItemListInterface::class);
    $question_field->getValue()->willReturn([['value' => 'How do I handle cravings?']]);
    $this->message->get('field_question')->willReturn($question_field->reveal());

    $date_field = $this->prophesize(FieldItemListInterface::class);
    $date_field->getValue()->willReturn([['value' => '2024-01-15']]);
    $this->message->get('field_date')->willReturn($date_field->reveal());

    $submitted_values = [
      'field_question' => 'How do I handle cravings?',
      'field_date' => '2024-01-15',
    ];

    // Expect flagForReview to be called
    $this->patientMessageService->flagForReview(42)->shouldBeCalled()->willReturn(TRUE);

    // Expect createQuestionMessage to be called
    $this->patientMessageService->createQuestionMessage(42, 'How do I handle cravings?', '2024-01-15')
      ->shouldBeCalled()->willReturn(NULL);

    $reflection = new \ReflectionClass($this->calculator);
    $method = $reflection->getMethod('runEvaluations');
    $method->setAccessible(true);

    $method->invoke($this->calculator, $this->account->reveal(), $this->message->reveal(), $submitted_values);
  }

  /**
   * Tests runEvaluations does not create question message when field_question is empty.
   */
  public function testRunEvaluationsSkipsEmptyQuestion(): void {
    $this->account->id()->willReturn(42);

    // Mock message WITHOUT field_question
    $empty_field = $this->prophesize(FieldItemListInterface::class);
    $empty_field->getValue()->willReturn([]);
    $this->message->get('field_question')->willReturn($empty_field->reveal());

    $date_field = $this->prophesize(FieldItemListInterface::class);
    $date_field->getValue()->willReturn([['value' => '2024-01-15']]);
    $this->message->get('field_date')->willReturn($date_field->reveal());

    $submitted_values = [
      'field_date' => '2024-01-15',
    ];

    // flagForReview should still be called
    $this->patientMessageService->flagForReview(42)->shouldBeCalled()->willReturn(TRUE);

    // createQuestionMessage should NOT be called
    $this->patientMessageService->createQuestionMessage(null, null, null)->shouldNotBeCalled();

    $reflection = new \ReflectionClass($this->calculator);
    $method = $reflection->getMethod('runEvaluations');
    $method->setAccessible(true);

    $method->invoke($this->calculator, $this->account->reveal(), $this->message->reveal(), $submitted_values);
  }
}