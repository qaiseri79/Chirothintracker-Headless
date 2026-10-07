<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_patients\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\flag\FlagInterface;
use Drupal\flag\FlaggingInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\headless_intake\ClinicIntakeLinkService;
use Drupal\headless_intake\IntakeInviteService;
use Drupal\headless_patients\PatientIntakeService;
use Drupal\user\UserInterface;
use PHPUnit\Framework\TestCase;

/**
 * Review actions flag/unflag only owned submissions and remain idempotent.
 *
 * @group headless_patients
 */
class PatientIntakeReviewTest extends TestCase {

  /**
   * @dataProvider reviewStates
   */
  public function testReviewPersistsOnlyOwnedSubmissions(string $status, bool $alreadyFlagged, ?string $operation): void {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $flags = $this->createMock(FlagServiceInterface::class);
    $flag = $this->createMock(FlagInterface::class);
    $message = $this->createMock(EntityInterface::class);
    $actor = $this->createMock(UserInterface::class);
    $existing = $alreadyFlagged ? $this->createMock(FlaggingInterface::class) : NULL;
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $conditions = [];
    $query->method('condition')->willReturnCallback(
      function ($field, $value = NULL, $operator = NULL) use (&$conditions, $query) {
        $conditions[$field] = $value;
        return $query;
      }
    );
    // 999 belongs elsewhere and never reaches the flag service.
    $query->method('execute')->willReturn([9]);
    $messages = $this->createMock(EntityStorageInterface::class);
    $messages->method('getQuery')->willReturn($query);
    $messages->expects($this->once())->method('loadMultiple')->with([9])->willReturn([9 => $message]);
    $users = $this->createMock(EntityStorageInterface::class);
    $users->method('load')->with(10)->willReturn($actor);
    $manager->method('getStorage')->willReturnMap([
      ['contact_message', $messages], ['user', $users],
    ]);
    $flags->method('getFlagById')->with('intake_processed_cs')->willReturn($flag);
    $flags->expects($this->once())->method('getFlagging')->with($flag, $message, $actor)->willReturn($existing);
    foreach (['flag', 'unflag'] as $method) {
      $flags->expects($operation === $method ? $this->once() : $this->never())
        ->method($method)->with($flag, $message, $actor);
    }
    // These final dependencies are unused by review; no kernel is required.
    $invite = (new \ReflectionClass(IntakeInviteService::class))->newInstanceWithoutConstructor();
    $links = (new \ReflectionClass(ClinicIntakeLinkService::class))->newInstanceWithoutConstructor();
    $service = new PatientIntakeService($manager, $flags, $invite, $links, $this->createMock(LoggerChannelInterface::class));
    $result = $service->setReview(7, [9, 999], $status, 10);
    $this->assertSame(7, $conditions['field_clinic']);
    $this->assertSame([9, 999], $conditions['id']);
    $this->assertSame([999], $result['rejected']);
    $this->assertSame($operation === NULL ? [] : [9], $result['updated']);
    $this->assertSame($operation === NULL ? [9] : [], $result['unchanged']);
  }

  public function reviewStates(): array {
    return [
      'new to checked creates flag' => ['checked', FALSE, 'flag'],
      'checked to new removes flag' => ['new', TRUE, 'unflag'],
      'checked retry is unchanged' => ['checked', TRUE, NULL],
      'new retry is unchanged' => ['new', FALSE, NULL],
    ];
  }

}
