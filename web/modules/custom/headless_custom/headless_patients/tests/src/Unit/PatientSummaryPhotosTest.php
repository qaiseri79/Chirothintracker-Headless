<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_patients\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\file\FileInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\headless_patients\ClinicScope;
use Drupal\headless_patients\PatientIntakeService;
use Drupal\headless_patients\PatientPhaseMap;
use Drupal\headless_patients\PatientSummaryService;
use Drupal\headless_patients\PatientsService;
use Drupal\headless_patients\Exception\PatientsException;
use Drupal\headless_progress\ProgressService;
use Drupal\user\UserInterface;
use PHPUnit\Framework\TestCase;

/**
 * The photos read: the avatar source preference and the gallery mapping.
 *
 * @group headless_patients */
class PatientSummaryPhotosTest extends TestCase {

  /**
   * The patient_photos profile photo wins over the account picture.
   */
  public function testRosterAvatarComesFromPatientPhotosProfile(): void {
    $service = $this->summary(88, [7], [7 => $this->photoMessage(5, [9, 12])]);
    $this->assertSame([
      'profilePhoto' => ['id' => 5, 'url' => '/api/patients/98/summary/photo'],
      'photos' => [
        ['id' => 9, 'url' => '/api/patients/98/summary/photos/9'],
        ['id' => 12, 'url' => '/api/patients/98/summary/photos/12'],
      ],
    ], $service->photos(44, 98));
  }

  /**
   * A patient_photos message without a profile falls back to user_picture.
   */
  public function testProfilePhotoFallsBackToAccountPicture(): void {
    $service = $this->summary(88, [7], [7 => $this->photoMessage(NULL, [])]);
    $this->assertSame(['id' => 88, 'url' => '/api/patients/98/summary/photo'], $service->photos(44, 98)['profilePhoto']);
  }

  /**
   * No patient_photos message at all still resolves the account picture.
   */
  public function testProfilePhotoWithoutPatientPhotosMessage(): void {
    $service = $this->summary(88, [], []);
    $result = $service->photos(44, 98);
    $this->assertSame(['id' => 88, 'url' => '/api/patients/98/summary/photo'], $result['profilePhoto']);
    $this->assertSame([], $result['photos']);
  }

  /**
   * photoFile serves only files that belong to the patient's photos message.
   */
  public function testPhotoFileScopesToThePatientsMessage(): void {
    $service = $this->summary(88, [7], [7 => $this->photoMessage(5, [9, 12])], [5 => $this->file(5), 9 => $this->file(9), 12 => $this->file(12)]);
    $this->assertSame(9, (int) $service->photoFile(44, 98, 9)->id());
    foreach ([5, 999] as $unknown) {
      try {
        $service->photoFile(44, 98, $unknown);
        $this->fail('A non-gallery file was served as a patient photo.');
      }
      catch (PatientsException $e) {
        $this->assertSame(404, $e->getStatusCode());
      }
    }
  }

  private function summary(int $pictureId, array $messageIds, array $byId, array $filesById = []): PatientSummaryService {
    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->method('load')->with(98)->willReturn($this->patientUser($pictureId));
    $photoStorage = $this->photoStorage($messageIds, $byId);
    $fileStorage = $this->createMock(EntityStorageInterface::class);
    $fileStorage->method('load')->willReturnCallback(fn($id) => $filesById[$id] ?? NULL);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnCallback(fn($type) => $type === 'user' ? $userStorage : ($type === 'file' ? $fileStorage : $photoStorage));
    $unused = fn(string $class) => (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    return new PatientSummaryService($manager, new ClinicScope($manager),
      $unused(PatientsService::class), new PatientPhaseMap(), $unused(ProgressService::class),
      $unused(PatientIntakeService::class), $this->createMock(FlagServiceInterface::class),
      $this->createMock(Connection::class), $this->createMock(FileSystemInterface::class),
      $this->createMock(LockBackendInterface::class));
  }

  private function patientUser(int $pictureId): UserInterface {
    $user = $this->createMock(UserInterface::class);
    $user->method('isActive')->willReturn(TRUE);
    $user->method('getRoles')->willReturn(['enrolled_patient']);
    $clinic = $this->createMock(FieldItemListInterface::class);
    $clinic->method('__get')->with('target_id')->willReturn(44);
    $picture = $this->createMock(FieldItemListInterface::class);
    $picture->method('isEmpty')->willReturn(FALSE);
    $picture->method('__get')->with('target_id')->willReturn($pictureId);
    $fields = ['field_clinic' => $clinic, 'user_picture' => $picture];
    $user->method('hasField')->willReturnCallback(fn($field) => isset($fields[$field]));
    $user->method('get')->willReturnCallback(fn($field) => $fields[$field]);
    return $user;
  }

  private function photoMessage(?int $profileId, array $galleryIds) {
    $profile = $this->createMock(EntityReferenceFieldItemList::class);
    $profile->method('getValue')->willReturn($profileId === NULL ? [] : [['target_id' => $profileId]]);
    $profile->method('referencedEntities')->willReturn($profileId === NULL ? [] : [$this->file($profileId)]);
    $gallery = $this->createMock(EntityReferenceFieldItemList::class);
    $gallery->method('referencedEntities')->willReturn(array_map(fn($id) => $this->file($id), $galleryIds));
    $message = $this->createMock(\Drupal\Core\Entity\FieldableEntityInterface::class);
    $message->method('get')->willReturnMap([
      ['field_profile_photo', $profile],
      ['field_before_and_after_photos', $gallery],
    ]);
    return $message;
  }

  private function file(int $id): FileInterface {
    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn($id);
    return $file;
  }

  private function photoStorage(array $messageIds, array $byId): EntityStorageInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn($messageIds);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('load')->willReturnCallback(fn($id) => $byId[$id] ?? NULL);
    return $storage;
  }

}