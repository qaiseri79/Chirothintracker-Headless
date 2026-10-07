<?php

namespace Drupal\Tests\headless_messages\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\flag\FlagInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\headless_messages\Service\MessagesService;
use Drupal\headless_messages\Service\NotificationService;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NotificationService.
 *
 * Covers the parts that can run without a bootstrapped entity system: the
 * template bank actually loading from the real JSON file, the brand-aware
 * section merge, and the send orchestration against prophesied storages.
 * Entity *creation* (saveCustom, deleteCustom) needs a live entity manager and
 * is deliberately left to a kernel test, matching MessagesServiceTest.
 *
 * @group headless_messages
 */
class NotificationServiceTest extends TestCase {

  use ProphecyTrait;

  /**
   * The uid the tests are signed in as, a chiropractor.
   */
  protected const CHIRO = 5;

  /**
   * The clinic the chiropractor belongs to.
   */
  protected const CLINIC = 7;

  /**
   * The recipient patient's uid.
   */
  protected const PATIENT = 10;

  /**
   * The service under test.
   *
   * @var \Drupal\headless_messages\Service\NotificationService
   */
  protected $service;

  /**
   * @var \Prophecy\Prophecy\ObjectProphecy|\Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var \Prophecy\Prophecy\ObjectProphecy|\Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * @var \Prophecy\Prophecy\ObjectProphecy|\Drupal\flag\FlagServiceInterface
   */
  protected $flagService;

  /**
   * @var \Prophecy\Prophecy\ObjectProphecy|\Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * @var \Prophecy\Prophecy\ObjectProphecy|\Drupal\headless_messages\Service\MessagesService
   */
  protected $messagesService;

  /**
   * The user storage prophecy, shared by every helper that loads users.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy|\Drupal\Core\Entity\EntityStorageInterface|null
   */
  protected $usersStorage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $this->currentUser = $this->prophesize(AccountProxyInterface::class);
    $this->flagService = $this->prophesize(FlagServiceInterface::class);
    $this->logger = $this->prophesize(LoggerInterface::class);
    $this->messagesService = $this->prophesize(MessagesService::class);

    $modules = $this->prophesize(ModuleExtensionList::class);
    $modules->getPath('headless_messages')->willReturn(dirname(__DIR__, 3));

    $this->service = new NotificationService(
      $this->entityTypeManager->reveal(),
      $this->currentUser->reveal(),
      $this->flagService->reveal(),
      $this->logger->reveal(),
      $this->messagesService->reveal(),
      $modules->reveal(),
    );
  }

  /**
   * A field list stub readable through ->target_id, as production reads it.
   *
   * @param int|null $target_id
   *   The reference target, or NULL for an empty field.
   */
  protected function referenceField(?int $target_id): FieldItemListInterface {
    return $this->fieldList(
      static fn(string $name) => $name === 'target_id' ? $target_id : NULL,
      $target_id === NULL
    );
  }

  /**
   * A field list stub whose magic properties come from a callback.
   *
   * __isset() has to be stubbed as well as __get(): the service reads fields as
   * `$field->target_id ?? 0`, and null-coalescing consults __isset() first.
   *
   * @param callable $values
   *   Receives a property name and returns its value.
   * @param bool $empty
   *   What isEmpty() reports.
   */
  protected function fieldList(callable $values, bool $empty = FALSE): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('__get')->willReturnCallback($values);
    $field->method('__isset')->willReturnCallback(
      static fn(string $name): bool => $values($name) !== NULL
    );
    $field->method('isEmpty')->willReturn($empty);
    return $field;
  }

  /**
   * A field list stub for a plain string field (e.g. field_brand).
   */
  protected function stringField(?string $value): FieldItemListInterface {
    return $this->fieldList(
      static fn(string $name) => $name === 'value' ? $value : NULL,
      $value === NULL
    );
  }

  /**
   * A field list stub whose getValue() returns raw value arrays.
   *
   * @param array $values
   *   The arrays getValue() should return (e.g. [['target_id' => 7]]).
   */
  protected function valueList(array $values, bool $empty = FALSE): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('getValue')->willReturn($values);
    $field->method('isEmpty')->willReturn($empty);
    return $field;
  }

  /**
   * A real NotificationService with a few side-effect internals stubbed.
   *
   * applyRoles writes the patient's magic changed field and flagLastSubmission
   * leans on a bootstrapped Views service; for unit tests we stub those and
   * assert the right call would have happened.
   *
   * @param string[] $onlyMethods
   *   Methods to stub.
   */
  protected function buildPartialService(array $onlyMethods): NotificationService {
    $modules = $this->prophesize(ModuleExtensionList::class);
    $modules->getPath('headless_messages')->willReturn(dirname(__DIR__, 3));
    return $this->getMockBuilder(NotificationService::class)
      ->setConstructorArgs([
        $this->entityTypeManager->reveal(),
        $this->currentUser->reveal(),
        $this->flagService->reveal(),
        $this->logger->reveal(),
        $this->messagesService->reveal(),
        $modules->reveal(),
      ])
      ->onlyMethods($onlyMethods)
      ->getMock();
  }

  /**
   * Signs the caller in as a chiropractor of a clinic with a brand.
   */
  protected function signInAsChiropractor(string $brand = 'ChiroThin', $clinic_id = self::CLINIC): void {
    $this->currentUser->id()->willReturn(self::CHIRO);
    $this->currentUser->isAnonymous()->willReturn(FALSE);

    $chiropractor = $this->prophesize(User::class);
    $chiropractor->hasField('field_clinic')->willReturn(TRUE);
    $chiropractor->get('field_clinic')->willReturn($this->referenceField($clinic_id));
    $chiropractor->getDisplayName()->willReturn('Dr. Dan');
    $users = $this->prophesize(EntityStorageInterface::class);
    $users->load(self::CHIRO)->willReturn($chiropractor->reveal());
    $this->usersStorage = $users;
    $this->entityTypeManager->getStorage('user')->willReturn($users->reveal());

    $clinic = $this->prophesize(Node::class);
    $clinic->get('field_brand')->willReturn($this->stringField($brand));
    $clinics = $this->prophesize(EntityStorageInterface::class);
    $clinics->load($clinic_id)->willReturn($clinic->reveal());
    $this->entityTypeManager->getStorage('clinic')->willReturn($clinics->reveal());
  }

  /**
   * Stubs the patient the notification is aimed at.
   */
  protected function stubPatient(string $name = 'Dan Patient'): ObjectProphecy {
    $patient = $this->prophesize(User::class);
    $patient->getDisplayName()->willReturn($name);
    $this->usersStorage->load(self::PATIENT)->willReturn($patient->reveal());
    return $patient;
  }

  /**
   * Stubs a chiropractor who may send and is not currently reviewed.
   */
  protected function stubActiveSender(): void {
    $this->messagesService->isChiropractor()->willReturn(TRUE);
    $this->messagesService->canSend()->willReturn(TRUE);
    // No review flagging exists, so nothing is unflagged.
    $flag = $this->prophesize(FlagInterface::class);
    $this->flagService->getFlagById('reviewed_patients')->willReturn($flag->reveal());
    $this->flagService->getFlagging(Argument::cetera())->willReturn(NULL);
  }

  // --- Template bank --------------------------------------------------------

  /**
   * Tests the real JSON bank loads every predefined message.
   *
   * This is what pins the user-editable file to the endpoints: a rename, a
   * broken escape, or a removed id breaks the build here rather than at runtime.
   */
  public function testTemplatesLoadsEveryPredefinedMessage(): void {
    $this->signInAsChiropractor();

    $templates = $this->service->templates();

    $this->assertGreaterThanOrEqual(18, count($templates));
    $ids = array_column($templates, 'id');
    foreach (['default_review', 'constipation', 'protein_day_today', 'apply_day_today', 'graduated', 'we_miss_you'] as $required) {
      $this->assertContains($required, $ids, "Missing predefined notification: $required");
    }

    $by_id = array_column($templates, NULL, 'id');
    $this->assertSame('Apple Day Today', $by_id['apply_day_today']['label']);
    $this->assertSame('Graduated', $by_id['graduated']['label']);
    foreach ($templates as $template) {
      $this->assertNotEmpty($template['label'], 'Template without label: ' . $template['id']);
      $this->assertNotEmpty($template['body'], 'Template without body: ' . $template['id']);
    }
  }

  /**
   * Tests the constipation message is brand-aware on the list endpoint.
   */
  public function testConstipationResolvesBrandTail(): void {
    $this->signInAsChiropractor('ChiroThin');
    $chirothin = $this->service->templates();
    $chirothin = array_column($chirothin, NULL, 'id')['constipation']['body'];
    $this->assertStringContainsString('Nature Ease', $chirothin);

    $this->signInAsChiropractor('SomeOtherBrand');
    $other = array_column($this->service->templates(), NULL, 'id')['constipation']['body'];
    $this->assertStringContainsString('please message us in the portal.', $other);
    $this->assertStringNotContainsString('Nature Ease', $other);
  }

  /**
   * Tests section merging follows the star/brand/other rule.
   *
   * @dataProvider providerSections
   */
  public function testRenderMergesSections(array $sections, string $brand, string $expected): void {
    $method = new \ReflectionMethod(NotificationService::class, 'render');
    $method->setAccessible(TRUE);
    $this->assertSame($expected, $method->invoke($this->service, ['sections' => $sections], $brand));
  }

  /**
   * Data provider for testRenderMergesSections.
   */
  public static function providerSections(): array {
    return [
      'star only' => [
        ['*' => ['a', 'b']],
        'ChiroThin',
        'ab',
      ],
      'brand matched replaces other' => [
        ['*' => ['a'], 'ChiroThin' => ['X'], 'other' => ['Y']],
        'ChiroThin',
        'aX',
      ],
      'unmatched brand uses other' => [
        ['*' => ['a'], 'ChiroThin' => ['X'], 'other' => ['Y']],
        'Unbranded',
        'aY',
      ],
      'missing star tolerated' => [
        ['ChiroThin' => ['X']],
        'ChiroThin',
        'X',
      ],
      'empty blocks' => [
        [],
        'ChiroThin',
        '',
      ],
    ];
  }

  // --- Send orchestration ---------------------------------------------------

  /**
   * Tests a predefined send clears the review flag and delivers the body.
   */
  public function testSendPredefinedDeliversResolvedBody(): void {
    $this->signInAsChiropractor();
    $this->stubActiveSender();
    $this->stubPatient();

    $this->messagesService->sendNotification(self::PATIENT, Argument::containingString('Your submission has been reviewed. Make it a great day!'))
      ->willReturn(['success' => TRUE, 'id' => 99]);

    $result = $this->service->send('default_review', self::PATIENT);

    $this->assertTrue($result['success']);
    $this->messagesService->sendNotification(Argument::cetera())->shouldHaveBeenCalledOnce();
  }

  /**
   * Tests a custom notification sends the node's body with the name filled in.
   */
  public function testSendCustomUsesNodeBodyAndPersonalizesName(): void {
    $this->signInAsChiropractor();
    $this->stubActiveSender();
    $this->stubPatient();

    $node = $this->prophesize(Node::class);
    $node->bundle()->willReturn('review_messages');
    $node->isPublished()->willReturn(TRUE);
    $node->get('field_published_to')->willReturn($this->valueList(
      [['target_id' => self::CLINIC]]
    ));
    $node->get('field_message')->willReturn($this->valueList(
      [['value' => 'Hi {name}, great job!']]
    ));
    $nodes = $this->prophesize(EntityStorageInterface::class);
    $nodes->load(42)->willReturn($node->reveal());
    $this->entityTypeManager->getStorage('node')->willReturn($nodes->reveal());

    $this->messagesService->sendNotification(self::PATIENT, 'Hi Dan, great job!')
      ->willReturn(['success' => TRUE, 'id' => 100]);

    $result = $this->service->send('custom:42', self::PATIENT);

    $this->assertTrue($result['success']);
    $this->messagesService->sendNotification(Argument::cetera())->shouldHaveBeenCalledOnce();
  }

  /**
   * Tests an unknown operation is refused before anything is written.
   */
  public function testSendRejectsUnknownOperation(): void {
    $this->signInAsChiropractor();
    $this->stubActiveSender();
    // send() resolves the recipient before it validates the operation id.
    $this->stubPatient();

    $result = $this->service->send('no_such_op', self::PATIENT);

    $this->assertFalse($result['success']);
    $this->messagesService->sendNotification(Argument::cetera())->shouldNotBeCalled();
  }

  /**
   * Tests a non-chiropractor (e.g. a patient) cannot send a notification.
   */
  public function testSendRejectsNonChiropractor(): void {
    $this->messagesService->isChiropractor()->willReturn(FALSE);
    $this->messagesService->canSend()->willReturn(TRUE);

    $result = $this->service->send('default_review', self::PATIENT);

    $this->assertFalse($result['success']);
    $this->messagesService->sendNotification(Argument::cetera())->shouldNotBeCalled();
    $this->flagService->getFlagById(Argument::cetera())->shouldNotBeCalled();
  }

  /**
   * Tests the graduated operation routes to the enrolment transition.
   *
   * applyRoles is stubbed (it writes the patient's magic changed field, which a
   * prophecy double cannot represent) and asserted to receive the exact role
   * map the template declares.
   */
  public function testSendGraduatedAppliesArchivedRoles(): void {
    $this->signInAsChiropractor();
    $this->stubActiveSender();
    $patient = $this->stubPatient();
    $service = $this->buildPartialService(['applyRoles']);

    $service->expects($this->once())->method('applyRoles')
      ->with($patient->reveal(), ['enrolled_patient' => 'archived_patient']);

    $this->messagesService->sendNotification(self::PATIENT, Argument::containingString('archived status'))
      ->willReturn(['success' => TRUE, 'id' => 101]);

    $result = $service->send('graduated', self::PATIENT);

    $this->assertTrue($result['success']);
  }

  /**
   * Tests a Protein Day sends tag the patient's last submission, flag 15.
   *
   * flagLastSubmission runs a Views query needing a bootstrapped Drupal, so it
   * is stubbed and asserted on.
   */
  public function testSendProteinDayTagsLastSubmissionWithFifteen(): void {
    $this->signInAsChiropractor();
    $this->stubActiveSender();
    $this->stubPatient();
    $service = $this->buildPartialService(['flagLastSubmission']);

    $service->expects($this->once())->method('flagLastSubmission')
      ->with(self::PATIENT, 15);

    $this->messagesService->sendNotification(self::PATIENT, Argument::containingString('Protein Day Instructions'))
      ->willReturn(['success' => TRUE, 'id' => 102]);

    $result = $service->send('protein_day_today', self::PATIENT);

    $this->assertTrue($result['success']);
  }

  // --- Custom save ----------------------------------------------------------

  /**
   * Tests saving a custom notification refuses blank fields.
   */
  public function testSaveCustomRejectsBlankFields(): void {
    $this->signInAsChiropractor();
    $this->messagesService->isChiropractor()->willReturn(TRUE);

    $result = $this->service->saveCustom('   ', 'Message');

    $this->assertFalse($result['success']);
    $this->assertSame('Add a name and a message first.', $result['message']);
  }

}