<?php

namespace Drupal\Tests\headless_messages\Unit;

use Drupal\contact\Entity\Message;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\flag\FlagInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\flag\FlaggingInterface;
use Drupal\headless_messages\Service\MessagesService;
use Drupal\user\Entity\User;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;

/**
 * Tests for MessagesService.
 *
 * These cover the behaviour that was wrong before: the direction read state is
 * asked in, whether a message body is stored as markup, whether a read-only
 * account can send, and whether a stranger can mark someone else's message read.
 *
 * Entity *creation* is deliberately not exercised here. sendMessage() calls
 * Drupal\Core\Entity\EntityBase::create(), which needs a bootstrapped entity type
 * manager, so it belongs in a kernel test rather than a unit test. What is tested
 * instead is everything around it.
 *
 * @group headless_messages
 */
class MessagesServiceTest extends TestCase {

  use ProphecyTrait;

  /**
   * The uid the tests are signed in as.
   */
  protected const ME = 10;

  /**
   * The uid of the counterpart.
   */
  protected const THEM = 20;

  /**
   * A fixed created timestamp, so ordering assertions stay deterministic.
   */
  protected const CREATED = 1750000000;

  /**
   * The service under test.
   *
   * @var \Drupal\headless_messages\Service\MessagesService
   */
  protected $service;

  /**
   * The prophesied entity type manager.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy|\Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The prophesied current user.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy|\Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The prophesied flag service.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy|\Drupal\flag\FlagServiceInterface
   */
  protected $flagService;

  /**
   * The prophesied logger.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy|\Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The mocked renderer (PHPUnit mock to handle by-ref parameter).
   *
   * @var \PHPUnit\Framework\MockObject\MockObject|\Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The prophesied file system.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy|\Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The prophesied file repository.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy|\Drupal\file\FileRepositoryInterface
   */
  protected $fileRepository;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $this->currentUser = $this->prophesize(AccountProxyInterface::class);
    $this->flagService = $this->prophesize(FlagServiceInterface::class);
    $this->logger = $this->prophesize(LoggerInterface::class);
    $this->renderer = $this->createMock(RendererInterface::class);
    $this->fileSystem = $this->prophesize(FileSystemInterface::class);
    $this->fileRepository = $this->prophesize(FileRepositoryInterface::class);

    // The renderer is called by mapMessage via renderInIsolation with a
    // processed_text element. Return the raw text so assertions on is_read/from
    // work without exercising the full filter pipeline.
    $this->renderer->method('renderInIsolation')->willReturnCallback(function (array &$elements): string {
      return $elements['#text'] ?? '';
    });

    $this->service = new MessagesService(
      $this->entityTypeManager->reveal(),
      $this->currentUser->reveal(),
      $this->flagService->reveal(),
      $this->logger->reveal(),
      $this->renderer,
      $this->fileSystem->reveal(),
      $this->fileRepository->reveal(),
    );
  }

  /**
   * Signs the caller in with a set of roles.
   */
  protected function signIn(array $roles = ['enrolled_patient']): void {
    $this->currentUser->id()->willReturn(self::ME);
    $this->currentUser->isAuthenticated()->willReturn(TRUE);
    $this->currentUser->getRoles()->willReturn($roles);
  }

  /**
   * A field list stub readable through ->target_id, as production reads it.
   *
   * __get() is declared on the interface, so createMock() is what applies.
   * Prophecy cannot stub it, hence the PHPUnit mock.
   *
   * @param int|null $target_id
   *   The reference target, or NULL for an empty field.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface
   *   The stubbed field list.
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
   * `$field->target_id ?? 0`, and the null-coalescing operator consults __isset()
   * before __get(). Without it every read silently yields 0, which is exactly
   * the kind of stub that makes a test pass for the wrong reason.
   *
   * @param callable $values
   *   Receives a property name and returns its value.
   * @param bool $empty
   *   What isEmpty() reports.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface
   *   The stubbed field list.
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
   * A contact_message entity with the given direction and body.
   */
  protected function message(int $id, ?int $from, ?int $to, string $text = 'Hello'): ObjectProphecy {
    $message = $this->prophesize(Message::class);
    $message->id()->willReturn($id);
    $message->bundle()->willReturn('message');
    $message->get('field_from')->willReturn($this->referenceField($from));
    $message->get('field_to')->willReturn($this->referenceField($to));
    $message->get('field_patient_uid')->willReturn($this->referenceField($from));

    $body = $this->prophesize(FieldItemListInterface::class);
    $body->getValue()->willReturn([['value' => $text, 'format' => 'plain_text']]);
    $message->get('field_message')->willReturn($body->reveal());

    // field_attachments: empty reference (target_id = 0).
    $message->get('field_attachments')->willReturn($this->referenceField(0));

    $message->get('created')->willReturn($this->fieldList(
      static fn(string $name) => $name === 'value' ? self::CREATED : NULL
    ));

    return $message;
  }

  /**
   * Stubs the contact_message storage query to return a fixed id set.
   */
  protected function stubQuery(array $ids): ObjectProphecy {
    $query = $this->prophesize(QueryInterface::class);
    $query->accessCheck(FALSE)->willReturn($query->reveal());
    $query->condition(Argument::cetera())->willReturn($query->reveal());
    $query->sort(Argument::cetera())->willReturn($query->reveal());
    $query->range(Argument::cetera())->willReturn($query->reveal());
    $query->execute()->willReturn($ids);
    return $query;
  }


  /**
   * Sidebar summaries render only the newest body while preserving history search.
   */
  public function testSidebarSummarySkipsOlderMessageRendering(): void {
    $this->signIn(['chiropractor_active_']);
    $newest = $this->message(9, self::ME, self::THEM, 'Latest');

    $storage = $this->prophesize(EntityStorageInterface::class);
    $storage->getQuery()->shouldNotBeCalled();
    $storage->loadMultiple([9])->willReturn([9 => $newest->reveal()])->shouldBeCalledOnce();
    $this->entityTypeManager->getStorage('contact_message')->willReturn($storage->reveal());

    $partner = $this->prophesize(User::class);
    $partner->id()->willReturn(self::THEM);
    $partner->getDisplayName()->willReturn('Patient');
    $partner->getRoles()->willReturn(['enrolled_patient']);
    $users = $this->prophesize(EntityStorageInterface::class);
    $users->loadMultiple([self::THEM])->willReturn([self::THEM => $partner->reveal()]);
    $this->entityTypeManager->getStorage('user')->willReturn($users->reveal());

    $service = $this->getMockBuilder(MessagesService::class)
      ->setConstructorArgs([
        $this->entityTypeManager->reveal(),
        $this->currentUser->reveal(),
        $this->flagService->reveal(),
        $this->logger->reveal(),
        $this->renderer,
        $this->fileSystem->reveal(),
        $this->fileRepository->reveal(),
      ])
      ->onlyMethods(['mapMessage', 'sidebarRows'])
      ->getMock();

    $service->expects($this->once())->method('sidebarRows')
      ->with(self::ME)->willReturn([
        (object) ['id' => 9, 'from_uid' => self::ME, 'to_uid' => self::THEM, 'body' => 'Latest', 'unread_id' => NULL],
        (object) ['id' => 8, 'from_uid' => self::THEM, 'to_uid' => self::ME, 'body' => 'Older searchable text', 'unread_id' => 1],
        (object) ['id' => 7, 'from_uid' => 30, 'to_uid' => 40, 'body' => 'Unrelated', 'unread_id' => 2],
      ]);
    $service->expects($this->once())->method('mapMessage')
      ->with($newest->reveal())->willReturn(['id' => 9, 'text' => 'Latest']);
    // Older inbound messages must use the bulk unread result.
    $this->flagService->getFlagging(Argument::cetera())->shouldNotBeCalled();

    $conversations = $service->getConversations(TRUE);
    $this->assertCount(1, $conversations);
    $this->assertCount(1, $conversations[0]['messages']);
    $this->assertSame(9, $conversations[0]['messages'][0]['id']);
    $this->assertSame(1, $conversations[0]['unread_count']);
    $this->assertStringContainsString('Older searchable text', $conversations[0]['search_text']);
    $this->assertStringNotContainsString('Unrelated', $conversations[0]['search_text']);
  }

  // --- Capabilities ---------------------------------------------------------

  /**
   * Tests an active patient may send.
   */
  public function testCanSendForEnrolledPatient(): void {
    $this->signIn(['enrolled_patient', 'patient_chirothin']);
    $this->assertTrue($this->service->canSend());
  }

  /**
   * Tests an archived patient may not send.
   *
   * This is the read-only rule from lib/portal.ts. It has to hold on the server:
   * the frontend only disables the reply box.
   */
  public function testCanSendForArchivedPatient(): void {
    $this->signIn(['archived_patient', 'patient_chirothin']);
    $this->assertFalse($this->service->canSend());
  }

  /**
   * Tests an anonymous caller may not send.
   */
  public function testCanSendForAnonymous(): void {
    $this->currentUser->id()->willReturn(0);
    $this->currentUser->isAuthenticated()->willReturn(FALSE);
    $this->assertFalse($this->service->canSend());
  }

  /**
   * Tests an archived patient's send is refused before anything is written.
   */
  public function testSendMessageRefusedForArchivedPatient(): void {
    $this->signIn(['archived_patient']);

    $user_storage = $this->prophesize(EntityStorageInterface::class);
    $target = $this->prophesize(User::class);
    $target->id()->willReturn(self::THEM);
    $user_storage->load(self::THEM)->willReturn($target->reveal());
    $this->entityTypeManager->getStorage('user')->willReturn($user_storage->reveal());

    // Nothing may be written, and no flag touched.
    $this->entityTypeManager->getStorage('contact_message')->shouldNotBeCalled();
    $this->flagService->flag(Argument::cetera())->shouldNotBeCalled();

    $result = $this->service->sendMessage(self::THEM, 'Hello');
    $this->assertFalse($result['success']);
    $this->assertStringContainsString('cannot send', $result['message']);
  }

  // --- Stored text ----------------------------------------------------------

  /**
   * Tests markup is stripped from a stored body.
   *
   * A programmatic Message::save() never runs text filters, so whatever arrives
   * here is what gets stored and what the renderer later receives. The design
   * renders each body as text, and this is what makes that true.
   *
   * @dataProvider providerMarkupIsStripped
   */
  public function testSanitizeTextStripsMarkup(string $input, string $expected): void {
    $method = new \ReflectionMethod(MessagesService::class, 'sanitizeText');
    $method->setAccessible(TRUE);
    $this->assertSame($expected, $method->invoke($this->service, $input));
  }

  /**
   * Data provider for testSanitizeTextStripsMarkup.
   */
  public static function providerMarkupIsStripped(): array {
    return [
      'script tag' => [
        '<script>alert(1)</script>hello',
        'alert(1)hello',
      ],
      'img onerror' => [
        '<img src=x onerror=alert(1)>',
        '',
      ],
      'anchor' => [
        '<a href="https://evil.example">click</a>',
        'click',
      ],
      'newlines preserved' => [
        "line one\nline two",
        "line one\nline two",
      ],
      'blank runs collapsed' => [
        "one\n\n\n\ntwo",
        "one\n\ntwo",
      ],
      'surrounding whitespace trimmed' => [
        "  hi  ",
        'hi',
      ],
      'plain text untouched' => [
        'Just a normal message.',
        'Just a normal message.',
      ],
    ];
  }

  /**
   * Tests a body that is only tags is rejected rather than stored.
   *
   * Note that a body such as "<script>alert(1)</script>" is NOT empty after
   * strip_tags — it reduces to the text "alert(1)". The point being tested here
   * is that a body with no text content at all is refused, and that nothing is
   * written when it is.
   */
  public function testSendMessageRejectsMarkupOnlyBody(): void {
    $this->signIn(['enrolled_patient']);

    $user_storage = $this->prophesize(EntityStorageInterface::class);
    $target = $this->prophesize(User::class);
    $target->id()->willReturn(self::THEM);
    $user_storage->load(self::THEM)->willReturn($target->reveal());
    $this->entityTypeManager->getStorage('user')->willReturn($user_storage->reveal());

    $this->entityTypeManager->getStorage('contact_message')->shouldNotBeCalled();

    $result = $this->service->sendMessage(self::THEM, '<br><div></div>   ');
    $this->assertFalse($result['success']);
    $this->assertSame('Message cannot be empty', $result['message']);
  }

  /**
   * Tests markup is stripped to its text content rather than stored as markup.
   *
   * This is the security-relevant behaviour: the stored value is what the
   * frontend renders, and it must never be interpreted as HTML.
   */
  public function testSanitizeTextStripsMarkupButKeepsText(): void {
    $method = new \ReflectionMethod(MessagesService::class, 'sanitizeText');

    $this->assertSame('alert(1)', $method->invoke($this->service, '<script>alert(1)</script>'));
    $this->assertSame('click', $method->invoke($this->service, '<a href="https://evil.example">click</a>'));
    $this->assertSame('', $method->invoke($this->service, '<img src=x onerror=alert(1)>'));
  }

  /**
   * Tests newlines survive sanitising, since the design renders them.
   */
  public function testSanitizeTextKeepsNewlinesAndCollapsesRuns(): void {
    $method = new \ReflectionMethod(MessagesService::class, 'sanitizeText');

    $this->assertSame("line one\nline two", $method->invoke($this->service, "line one\nline two"));
    $this->assertSame("one\n\ntwo", $method->invoke($this->service, "one\n\n\n\ntwo"));
  }

  // --- Read state -----------------------------------------------------------

  /**
   * Tests marking a thread read UNFLAGS for the reader.
   *
   * A flagging means "not read yet", so read is the absence of one. The previous
   * implementation's test asserted flag() was called here, which described
   * marking a message *unread* — the assertion contradicted the method it was
   * testing, and passing it would have hidden the bug.
   */
  public function testMarkConversationReadUnflags(): void {
    $this->signIn(['enrolled_patient']);

    $storage = $this->prophesize(EntityStorageInterface::class);
    $storage->getQuery()->willReturn($this->stubQuery([123])->reveal());
    $message = $this->message(123, self::THEM, self::ME);
    $storage->loadMultiple([123])->willReturn([$message->reveal()]);
    $this->entityTypeManager->getStorage('contact_message')->willReturn($storage->reveal());

    $flag = $this->prophesize(FlagInterface::class);
    $flagging = $this->prophesize(FlaggingInterface::class);
    $this->flagService->getFlagById('message_status_contact_storage')->willReturn($flag->reveal());
    $this->flagService->getFlagging($flag->reveal(), $message->reveal(), Argument::any())
      ->willReturn($flagging->reveal());
    $this->flagService->unflag($flag->reveal(), $message->reveal(), $this->currentUser->reveal())
      ->shouldBeCalledOnce();
    $this->flagService->flag(Argument::cetera())->shouldNotBeCalled();

    $this->assertSame(1, $this->service->markConversationRead(self::THEM));
  }

  /**
   * Tests the reader's own outbound messages are not counted as newly read.
   *
   * Only messages addressed to the reader are opened; a message they sent was
   * never unread in the first place.
   */
  public function testMarkConversationReadSkipsOwnSentMessages(): void {
    $this->signIn(['enrolled_patient']);

    $storage = $this->prophesize(EntityStorageInterface::class);
    $storage->getQuery()->willReturn($this->stubQuery([123])->reveal());
    // field_to is THEM, not ME, so this one is skipped by the direction check.
    $message = $this->message(123, self::ME, self::THEM);
    $storage->loadMultiple([123])->willReturn([$message->reveal()]);
    $this->entityTypeManager->getStorage('contact_message')->willReturn($storage->reveal());

    $this->flagService->getFlagById('message_status_contact_storage')->willReturn(
      $this->prophesize(FlagInterface::class)->reveal()
    );
    $this->flagService->unflag(Argument::cetera())->shouldNotBeCalled();

    $this->assertSame(0, $this->service->markConversationRead(self::THEM));
  }

  /**
   * Tests a message sent by the reader reports is_read true.
   *
   * The flag service is not consulted, since there is no unread state on a
   * message you wrote.
   */
  public function testMapMessageTreatsOwnMessageAsRead(): void {
    $this->signIn(['enrolled_patient']);

    $this->flagService->getFlagging(Argument::cetera())->shouldNotBeCalled();

    $message = $this->message(7, self::ME, self::THEM);
    $method = new \ReflectionMethod(MessagesService::class, 'mapMessage');
    $method->setAccessible(TRUE);

    $mapped = $method->invoke($this->service, $message->reveal());
    $this->assertSame('patient', $mapped['from']);
    $this->assertTrue($mapped['is_read']);
  }

  /**
   * Tests an inbound message's is_read comes from the reader's own flagging.
   */
  public function testMapMessageReadsInboundStateForCurrentUser(): void {
    $this->signIn(['enrolled_patient']);

    $flag = $this->prophesize(FlagInterface::class);
    $flagging = $this->prophesize(FlaggingInterface::class);

    $storage = $this->prophesize(EntityStorageInterface::class);
    $message = $this->message(7, self::THEM, self::ME);
    $storage->load(7)->willReturn($message->reveal());
    $this->entityTypeManager->getStorage('contact_message')->willReturn($storage->reveal());

    $this->flagService->getFlagById('message_status_contact_storage')->willReturn($flag->reveal());
    // Flagged = not yet read.
    $this->flagService->getFlagging($flag->reveal(), $message->reveal(), $this->currentUser->reveal())
      ->willReturn($flagging->reveal());

    $method = new \ReflectionMethod(MessagesService::class, 'mapMessage');
    $method->setAccessible(TRUE);

    $mapped = $method->invoke($this->service, $message->reveal());
    $this->assertSame('doctor', $mapped['from']);
    $this->assertFalse($mapped['is_read']);
  }

  /**
   * Tests an unflagged inbound message reports read.
   */
  public function testMapMessageUnflaggedIsRead(): void {
    $this->signIn(['enrolled_patient']);

    $flag = $this->prophesize(FlagInterface::class);
    $storage = $this->prophesize(EntityStorageInterface::class);
    $message = $this->message(7, self::THEM, self::ME);
    $storage->load(7)->willReturn($message->reveal());
    $this->entityTypeManager->getStorage('contact_message')->willReturn($storage->reveal());

    $this->flagService->getFlagById('message_status_contact_storage')->willReturn($flag->reveal());
    $this->flagService->getFlagging($flag->reveal(), $message->reveal(), $this->currentUser->reveal())
      ->willReturn(NULL);

    $method = new \ReflectionMethod(MessagesService::class, 'mapMessage');
    $method->setAccessible(TRUE);

    $mapped = $method->invoke($this->service, $message->reveal());
    $this->assertTrue($mapped['is_read']);
  }

  // --- Authorization --------------------------------------------------------

  /**
   * Tests a stranger cannot mark a message read.
   *
   * Message ids are dense and the endpoint takes a bare id, so without the
   * thread-membership check any authenticated account could walk the id space
   * and mark other people's messages read.
   */
  public function testMarkMessageReadRejectsNonRecipient(): void {
    $this->signIn(['enrolled_patient']);

    $storage = $this->prophesize(EntityStorageInterface::class);
    // field_to is THEM: the caller is not the recipient.
    $message = $this->message(7, self::THEM, 99);
    $storage->load(7)->willReturn($message->reveal());
    $this->entityTypeManager->getStorage('contact_message')->willReturn($storage->reveal());

    $this->flagService->unflag(Argument::cetera())->shouldNotBeCalled();
    $this->flagService->flag(Argument::cetera())->shouldNotBeCalled();

    $this->assertFalse($this->service->markMessageRead(7));
  }

  /**
   * Tests the recipient still cannot mark a message read without a thread.
   *
   * They are addressed, but have never spoken to uid 4242.
   */
  public function testMarkMessageReadRejectsStranger(): void {
    $this->signIn(['enrolled_patient']);

    $storage = $this->prophesize(EntityStorageInterface::class);
    // I am the recipient, but I have never spoken to uid 4242.
    $message = $this->message(7, 4242, self::ME);
    $storage->load(7)->willReturn($message->reveal());
    $storage->getQuery()->willReturn($this->stubQuery([])->reveal());
    $this->entityTypeManager->getStorage('contact_message')->willReturn($storage->reveal());

    $this->flagService->unflag(Argument::cetera())->shouldNotBeCalled();

    $this->assertFalse($this->service->markMessageRead(7));
  }

  /**
   * Tests the recipient in a real thread may mark it read.
   */
  public function testMarkMessageReadAllowsParticipant(): void {
    $this->signIn(['enrolled_patient']);

    $flag = $this->prophesize(FlagInterface::class);
    $flagging = $this->prophesize(FlaggingInterface::class);

    $storage = $this->prophesize(EntityStorageInterface::class);
    $message = $this->message(7, self::THEM, self::ME);
    $storage->load(7)->willReturn($message->reveal());
    // There is a thread with them.
    $storage->getQuery()->willReturn($this->stubQuery([7])->reveal());
    $this->entityTypeManager->getStorage('contact_message')->willReturn($storage->reveal());

    $this->flagService->getFlagById('message_status_contact_storage')->willReturn($flag->reveal());
    $this->flagService->getFlagging($flag->reveal(), $message->reveal(), $this->currentUser->reveal())
      ->willReturn($flagging->reveal());
    $this->flagService->unflag($flag->reveal(), $message->reveal(), $this->currentUser->reveal())
      ->shouldBeCalledOnce();

    $this->assertTrue($this->service->markMessageRead(7));
  }

  /**
   * Tests a message of a different contact form is refused.
   */
  public function testMarkMessageReadRejectsOtherBundle(): void {
    $this->signIn(['enrolled_patient']);

    $storage = $this->prophesize(EntityStorageInterface::class);
    $message = $this->message(7, self::THEM, self::ME);
    $message->bundle()->willReturn('patient_intake');
    $storage->load(7)->willReturn($message->reveal());
    $this->entityTypeManager->getStorage('contact_message')->willReturn($storage->reveal());

    $this->assertFalse($this->service->markMessageRead(7));
  }

  // --- Roles ----------------------------------------------------------------

  /**
   * Tests getRoleLabel returns correct labels.
   */
  public function testGetRoleLabel(): void {
    $method = new \ReflectionMethod(MessagesService::class, 'getRoleLabel');
    $method->setAccessible(TRUE);

    $chiro = $this->prophesize(User::class);
    $chiro->getRoles()->willReturn(['chiropractor_active_']);
    $this->assertEquals('Your ChiroThin provider', $method->invoke($this->service, $chiro->reveal()));

    $patient = $this->prophesize(User::class);
    $patient->getRoles()->willReturn(['enrolled_patient']);
    $this->assertEquals('Patient', $method->invoke($this->service, $patient->reveal()));

    $staff = $this->prophesize(User::class);
    $staff->getRoles()->willReturn(['some_other_role']);
    $this->assertEquals('Clinic staff', $method->invoke($this->service, $staff->reveal()));
  }

}
