<?php

declare(strict_types=1);

namespace Drupal\headless_patients;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\headless_access\PortalRoles;
use Drupal\headless_patients\Exception\PatientsException;
use Drupal\headless_progress\ProgressService;
use Drupal\flag\FlagServiceInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Composes existing patient, progress, and intake services for the doctor view.
 */
final class PatientSummaryService {
  private ?array $schemas = NULL;
  private const INITIAL_LOG_PAGE_SIZE = 5;
  private const LOG_PAGE_SIZE = 3;
  private const PACKAGES = ['zerona_profile' => 'Red Light Therapy Profile', 'lipolight_profile' => 'Lipo/Contour/Ideal Light', 'ultraslim_profile' => 'UltraSlim'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly ClinicScope $scope,
    private readonly PatientsService $patients,
    private readonly PatientPhaseMap $phases,
    private readonly ProgressService $progress,
    private readonly PatientIntakeService $intake,
    private readonly FlagServiceInterface $flags,
    private readonly Connection $database,
    private readonly FileSystemInterface $files,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * Scalar and user data only; no patient's historical entities are loaded.
   */
  public function roster(int $clinic): array {
    $storage = $this->entities->getStorage('user');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('status', 1)
      ->condition('field_clinic', $clinic)->condition('roles', PortalRoles::PATIENT_ENROLLED)
      ->sort('name')->execute();
    $users = $storage->loadMultiple($ids);
    $review = $this->flagged('reviewed_patients', 'user', array_keys($users));
    $referenceIds = [];
    foreach ($users as $user) {
      foreach (['field_clinic_location', 'field_laser_patient_status'] as $field) {
        if ($id = $this->reference($user, $field)) {
          $referenceIds[$field][$id] = $id;
        }
      }
    }
    $locations = empty($referenceIds['field_clinic_location']) ? [] : $this->entities->getStorage('clinic')->loadMultiple($referenceIds['field_clinic_location']);
    $statuses = empty($referenceIds['field_laser_patient_status']) ? [] : $this->entities->getStorage('taxonomy_term')->loadMultiple($referenceIds['field_laser_patient_status']);
    $rows = [];
    foreach ($users as $user) {
      $rows[] = $this->row($user, isset($review[(int) $user->id()]), $locations, $statuses);
    }
    return ['patients' => $rows, 'locations' => $this->patients->clinicLocations($clinic), 'phases' => $this->phases->all(), 'statuses' => $this->patients->laserStatuses(), 'attentionAvailable' => FALSE];
  }

  public function requirePatient(int $clinic, int $uid): UserInterface {
    $user = $this->scope->requireUser($clinic, $uid);
    if (!$user->isActive() || !array_intersect($user->getRoles(), [PortalRoles::PATIENT_ENROLLED, PortalRoles::PATIENT_ARCHIVED])) {
      throw PatientsException::notFound('patient');
    }
    return $user;
  }

  public function row(UserInterface $user, ?bool $needsReview = NULL, ?array $locations = NULL, ?array $statuses = NULL): array {
    $base = $this->patients->toRosterRow($user);
    $summary = $this->progress->summaryForAccount($user);
    $location = $this->reference($user, 'field_clinic_location');
    $status = $this->reference($user, 'field_laser_patient_status');
    $locationEntity = $locations !== NULL ? ($locations[$location] ?? NULL) : ($location ? $this->entities->getStorage('clinic')->load($location) : NULL);
    $statusEntity = $statuses !== NULL ? ($statuses[$status] ?? NULL) : ($status ? $this->entities->getStorage('taxonomy_term')->load($status) : NULL);
    $needsReview ??= isset($this->flagged('reviewed_patients', 'user', [(int) $user->id()])[(int) $user->id()]);
    $day = $base['programDay'];
    $loss = $summary['netWeightLoss'];
    return [
      'id' => (int) $user->id(),
      'name' => $base['name'],
      'email' => $base['email'],
      'avatar' => $this->profilePhoto($user) ? '/api/patients/' . $user->id() . '/summary/photo' : '',
      'day' => $day,
      'startDate' => $this->date($base['programStart']),
      'clinic' => $locationEntity ? (string) $locationEntity->label() : '',
      'phase' => $base['phaseLabel'] ?? '',
      'phaseCode' => $base['phase'],
      'program' => $statusEntity ? (string) $statusEntity->label() : '',
      'percentOfGoal' => $this->number($user, 'field_goal_achieved'),
      'netLoss' => $loss,
      'review' => $needsReview ? 'New' : 'Reviewed',
      'attention' => NULL,
      'startWeight' => $base['startWeight'],
      'goalWeight' => $summary['goalWeight'],
      'overallLoss' => $summary['overallWeightLoss'],
      'inchesLost' => $summary['netInchesLost'],
      'dailyLoss' => NULL,
      'avgLossPerDay' => ($loss !== NULL && $day > 0) ? $loss / $day : NULL,
      'lastSeen' => $this->date($this->value($user, 'field_last_seen')),
      'sessions' => [],
      'notesList' => [],
      'attachmentsList' => [],
      'weightHistory' => [],
      'measurementChanges' => [],
      'logsList' => [],
      'intake' => NULL,
    ];
  }

  public function section(int $clinic, int $uid, string $section, int $offset = 0): array {
    $user = $this->requirePatient($clinic, $uid);
    $offset = max(0, $offset);
    return match ($section) {
      'overview' => ['patient' => $this->row($user)],
      'logs' => $this->logs($user, $offset),
      'progress' => $this->chart($uid),
      'sessions' => $this->sessions($uid, $offset),
      'notes' => $this->notes($uid, $offset),
      'attachments' => $this->attachments($uid, $offset),
      'intake' => ['intake' => $this->patientIntake($clinic, $user)],
      default => throw PatientsException::invalid(['section' => 'Unknown patient section.']),
    };
  }

  private function logs(UserInterface $user, int $offset): array {
    $limit = $offset === 0 ? self::INITIAL_LOG_PAGE_SIZE : self::LOG_PAGE_SIZE;
    $snapshot = $this->progress->forAccount($user, $limit + 1, $offset, TRUE);
    $entries = $snapshot['entries'];
    $logs = [];
    foreach (array_slice($entries, 0, $limit) as $index => $entry) {
      $measurements = $entry['measurements'];
      $previous = $entries[$index + 1] ?? NULL;
      $doctorEntered = !empty($entry['authorId']) && (int) $entry['authorId'] !== (int) $user->id();
      $logs[] = [
        'id' => $entry['id'],
        'date' => $entry['date'],
        'dayNumber' => $entry['day'],
        'adherence' => $doctorEntered || ($entry['adherenceRecorded'] ?? TRUE) === FALSE ? NULL : $entry['adherence'],
        'doctorEntered' => $doctorEntered,
        'bloodSugar' => $entry['bloodSugar'] ?? NULL,
        'bloodPressure' => $entry['bloodPressure'] ?? '',
        'weight' => $entry['weight'],
        'weightDelta' => $previous ? $entry['weight'] - $previous['weight'] : NULL,
        'flag' => $entry['flags'][0] ?? NULL,
        'flags' => $entry['flags'],
        'water' => $entry['water'],
        'sleep' => (string) $entry['sleep'],
        'hasFood' => (bool) ($entry['lunch'] || $entry['dinner'] || $entry['other']),
        'hasMeasurements' => (bool) $measurements,
        'isOnPlan' => $entry['adherence'] >= 9,
        'foodDetails' => array_values(array_filter(array_map(fn($label, $text) => ['category' => $label, 'items' => $text === '' ? [] : [$text]], ['Lunch', 'Dinner', 'Other'], [$entry['lunch'], $entry['dinner'], $entry['other']]), fn($meal) => $meal['items'] !== [])),
        'measurementDetails' => array_map(fn($m) => ['area' => $m['area'], 'value' => (string) $m['value']], $measurements),
      ];
    }
    $total = (int) $this->entities->getStorage('contact_message')->getQuery()->accessCheck(FALSE)->condition('contact_form', 'tracking_weight')->condition('uid', $user->id())->count()->execute();
    return [
      'logsList' => $logs,
      'logsTotal' => $total,
      'hasMore' => $offset + count($logs) < $total,
      'nextOffset' => $offset + count($logs),
      'dailyLoss' => $offset === 0 && isset($logs[0]) ? $logs[0]['weightDelta'] : NULL,
    ];
  }

  private function chart(int $uid): array {
    // Read only chart columns; do not hydrate the complete historical entities.
    $query = $this->database->select('contact_message', 'm');
    $query->leftJoin('contact_message__field_weight', 'w', 'w.entity_id = m.id AND w.deleted = 0');
    $query->leftJoin('contact_message__field_date', 'd', 'd.entity_id = m.id AND d.deleted = 0');
    $query->fields('m', ['id', 'created'])->addField('w', 'field_weight_value', 'weight');
    $query->addField('d', 'field_date_value', 'date');
    $measurementFields = [];
    foreach ($this->progress->measurementFields(\Drupal::service('entity_field.manager')->getFieldDefinitions('contact_message', 'tracking_weight')) as $name => $label) {
      $alias = $query->leftJoin('contact_message__' . $name, $name, $name . '.entity_id=m.id AND ' . $name . '.deleted=0');
      $query->addField($alias, $name . '_value', $name);
      $measurementFields[$name] = $label;
    }
    $result = $query->condition('m.contact_form', 'tracking_weight')->condition('m.uid', $uid)->orderBy('m.created', 'ASC')->orderBy('m.id', 'ASC')->execute();
    $history = [];
    $measurements = [];
    foreach ($result as $record) {
      foreach ($measurementFields as $field => $label) {
        if ($record->$field !== NULL) {
          $measurements[$field] ??= ['area' => $label, 'first' => (float) $record->$field];
          $measurements[$field]['latest'] = (float) $record->$field;
        }
      }
      if ($record->weight !== NULL) {
        $history[] = ['date' => substr($record->date ?: date('Y-m-d', (int) $record->created), 0, 10), 'weight' => (float) $record->weight];
      }
    }
    return ['weightHistory' => $history, 'measurementChanges' => array_values($measurements)];
  }

  private function messageQuery(string $bundle, int $uid) {
    return $this->entities->getStorage('contact_message')->getQuery()->accessCheck(FALSE)->condition('contact_form', $bundle)->condition('field_patient', $uid)->sort('created', 'DESC')->sort('id', 'DESC');
  }

  /** The patient's single patient_photos contact message (one per patient). */
  private function patientPhotoMessage(UserInterface $user): ?FieldableEntityInterface {
    $ids = $this->messageQuery('patient_photos', (int) $user->id())->range(0, 1)->execute();
    return $ids ? $this->entities->getStorage('contact_message')->load(reset($ids)) : NULL;
  }

  /**
   * The profile photo the roster's avatar shows: the patient_photos entity's
   * field_profile_photo when set, otherwise the account's user_picture.
   */
  private function profilePhoto(UserInterface $user): ?int {
    $message = $this->patientPhotoMessage($user);
    if ($message) {
      $items = $message->get('field_profile_photo')->getValue();
      if ($items) {
        return (int) $items[0]['target_id'];
      }
    }
    return $this->reference($user, 'user_picture');
  }

  private function notes(int $uid, int $offset): array {
    $query = $this->messageQuery('chiropractor_notes', $uid);
    $total = (int) (clone $query)->count()->execute();
    $messages = $this->entities->getStorage('contact_message')->loadMultiple($query->range($offset, 100)->execute());
    $pinned = $this->flagged('pin_cs', 'contact_message', array_keys($messages));
    $rows = [];
    foreach ($messages as $msg) {
      $rows[] = ['id' => (int) $msg->id(), 'date' => substr((string) $this->value($msg, 'field_date'), 0, 10), 'text' => (string) $this->value($msg, 'field_notes'), 'isPinned' => isset($pinned[(int) $msg->id()])];
    }
    return ['notesList' => $rows, 'hasMore' => $offset + count($rows) < $total, 'nextOffset' => $offset + count($messages)];
  }

  private function attachments(int $uid, int $offset): array {
    $query = $this->messageQuery('patient_attachments', $uid);
    $total = (int) (clone $query)->count()->execute();
    $messages = $this->entities->getStorage('contact_message')->loadMultiple($query->range($offset, 100)->execute());
    $rows = [];
    foreach ($messages as $message) {
      foreach ($message->get('field_attachments')->referencedEntities() as $file) {
        $rows[] = ['id' => (int) $file->id(), 'messageId' => (int) $message->id(), 'name' => $file->getFilename(), 'size' => (int) $file->getSize(), 'date' => date('Y-m-d', (int) $this->value($message, 'created')), 'url' => '/api/patients/' . $uid . '/summary/files/' . $file->id()];
      }
    }
    return ['attachmentsList' => $rows, 'hasMore' => $offset + count($messages) < $total, 'nextOffset' => $offset + count($messages)];
  }

  private function sessions(int $uid, int $offset = 0): array {
    $storage = $this->entities->getStorage('patient_profile');
    $profiles = $storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)->condition('field_patient', $uid)->condition('type', array_keys(self::PACKAGES), 'IN')->sort('id', 'DESC')->execute());
    $chosen = [];
    foreach ($profiles as $p) {
      $chosen[$p->bundle()] ??= $p;
    }
    $rows = [];
    $hasMore = FALSE;
    foreach ($chosen as $bundle => $profile) {
      $query = $this->entities->getStorage('contact_message')->getQuery()->accessCheck(FALSE)->condition('contact_form', $bundle)->condition('field_patient_profile', $profile->id())->sort('created', 'DESC')->sort('id', 'DESC');
      $completed = (int) (clone $query)->count()->execute();
      $history = [];
      foreach ($this->entities->getStorage('contact_message')->loadMultiple($query->range($offset, 100)->execute()) as $message) {
        $areas = [];
        if ($message->hasField('field_treatment_area')) {
          foreach ($message->get('field_treatment_area')->referencedEntities() as $term) {
            $areas[] = (string) $term->label();
          }
        }
        $history[] = ['date' => substr((string) $this->value($message, 'field_date'), 0, 10), 'weight' => (string) $this->value($message, 'field_weight'), 'areas' => $areas, 'notes' => (string) $this->value($message, 'field_notes')];
      }
      $hasMore = $hasMore || $completed > $offset + count($history);
      $remaining = (int) $this->value($profile, 'field_sessions_available');
      $rows[] = ['id' => (int) $profile->id(), 'bundle' => $bundle, 'name' => self::PACKAGES[$bundle], 'count' => $remaining, 'total' => $remaining + $completed, 'completed' => $completed, 'history' => $history, 'historyTruncated' => $completed > 100];
    }
    return ['sessions' => $rows, 'sessionSchemas' => $this->sessionSchemas(), 'hasMore' => $hasMore, 'nextOffset' => $offset + 100];
  }

  private function sessionSchemas(): array {
    if ($this->schemas !== NULL) {
      return $this->schemas;
    }
    $schemas = [];
    $manager = \Drupal::service('entity_field.manager');
    foreach (array_keys(self::PACKAGES) as $bundle) {
      $fields = [];
      foreach ($manager->getFieldDefinitions('contact_message', $bundle) as $name => $definition) {
        if (!str_starts_with($name, 'field_') || in_array($name, ['field_author', 'field_patient_profile', 'field_before_and_after_photos'], TRUE)) {
          continue;
        }
        $type = $definition->getType();
        if (!in_array($type, ['decimal', 'float', 'integer', 'datetime', 'string', 'string_long', 'boolean', 'entity_reference'], TRUE)) {
          continue;
        }
        $options = [];
        if ($type === 'entity_reference') {
          if ($definition->getSetting('target_type') !== 'taxonomy_term') {
            continue;
          }
          $vids = array_keys($definition->getSetting('handler_settings')['target_bundles'] ?? []);
          if (!$vids) {
            continue;
          }
          $storage = $this->entities->getStorage('taxonomy_term');
          $terms = $storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)->condition('vid', $vids, 'IN')->sort('name')->execute());
          foreach ($terms as $term) {
            $options[] = ['id' => (int) $term->id(), 'label' => (string) $term->label()];
          }
        }
        $fields[] = ['name' => $name, 'label' => (string) $definition->getLabel(), 'type' => $type, 'multiple' => $definition->getFieldStorageDefinition()->getCardinality() !== 1, 'required' => $definition->isRequired(), 'options' => $options];
      }
      $schemas[$bundle] = $fields;
    }
    return $this->schemas = $schemas;
  }

  /**
   * Treatment insertion uses the existing entity hooks for calculations/counts. */
  public function session(int $clinic, int $uid, array $data, int $actor, array $uploads = []): array {
    $this->requirePatient($clinic, $uid);
    $id = $data['profileId'] ?? NULL;
    if (!is_int($id)||$id < 1) {
      throw PatientsException::invalid(['profileId' => 'Choose a session package.']);
    }
    $name = 'headless_patients.session.' . $id;
    if (!$this->lock->acquire($name, 120)) {
      throw new PatientsException('A session is already being saved. Please wait.', 409);
    }
    $transaction = $this->database->startTransaction();
    $savedFiles = [];
    try {
      $storage = $this->entities->getStorage('patient_profile');
      $storage->resetCache([$id]);
      $profile = $storage->load($id);
      if (!$profile||!isset(self::PACKAGES[$profile->bundle()])||$this->reference($profile, 'field_patient') !== $uid) {
        throw PatientsException::notFound('session package');
      }
      if ((int) $this->value($profile, 'field_sessions_available') < 1) {
        throw new PatientsException('No sessions remain in this package.', 409);
      }
      $values = $data['fields'] ?? [];
      if (!is_array($values)) {
        throw PatientsException::invalid(['fields' => 'Invalid session fields.']);
      }
      $message = $this->entities->getStorage('contact_message')->create(['contact_form' => $profile->bundle(), 'uid' => $actor, 'field_author' => $actor, 'field_patient_profile' => $id]);
      $allowed = array_column($this->sessionSchemas()[$profile->bundle()], 'name');
      foreach ($values as $field => $value) {
        if (!in_array($field, $allowed, TRUE)) {
          throw PatientsException::invalid(['fields' => 'Unknown session field.']);
        }
        $definition = $message->getFieldDefinition($field);
        $type = $definition->getType();
        if (in_array($type, ['decimal', 'integer', 'float'], TRUE)) {
          if (!is_numeric($value)||!is_finite((float) $value)||(float) $value < 0||(float) $value > ($field === 'field_weight' ? 1500 : 1000)) {
            throw PatientsException::invalid([$field => 'Enter a valid non-negative measurement.']);
          }
          $message->set($field, $type === 'integer' ? (int) $value : (float) $value);
        }
        elseif ($type === 'datetime') {
          $message->set($field, $this->validDate($value));
        }
        elseif ($type === 'boolean') {
          if (!is_bool($value)) {
            throw PatientsException::invalid([$field => 'Choose yes or no.']);

          }$message->set($field, $value);
        }
        elseif ($type === 'entity_reference') {
          $ids = is_array($value) ? $value : [$value];
          $valid = array_column($this->sessionSchemas()[$profile->bundle()][array_search($field, $allowed, TRUE)]['options'], 'id');
          foreach ($ids as $termId) {
            if (!is_int($termId)||!in_array($termId, $valid, TRUE)) {
              throw PatientsException::invalid([$field => 'Choose a valid treatment option.']);
            }
          }
          $message->set($field, array_map(fn($termId)=>['target_id' => $termId], $ids));
        }
        else {
          if (!is_string($value)||mb_strlen($value) > 20000) {
            throw PatientsException::invalid([$field => 'Enter valid text.']);

          }$message->set($field, $value);
        }
        if ($message->get($field)->validate()->count()) {
          throw PatientsException::invalid([$field => 'This value does not meet the field requirements.']);
        }
      }
      foreach ($this->sessionSchemas()[$profile->bundle()] as $schema) {
        if ($schema['required'] && $message->get($schema['name'])->isEmpty()) {
          throw PatientsException::invalid([$schema['name'] => 'This field is required.']);
        }
      }
      if (!$this->value($message, 'field_date')) {
        throw PatientsException::invalid(['field_date' => 'Choose a session date.']);
      }
      if (count($uploads) > 10) {
        throw PatientsException::invalid(['photos' => 'Choose up to 10 photos.']);
      }
      $total = 0;
      foreach ($uploads as $upload) {
        if (!$upload instanceof UploadedFile) {
          throw PatientsException::invalid(['photos' => 'Invalid photo.']);

        }$total += $upload->getSize();
      }
      if ($total > 100 * 1024 * 1024) {
        throw PatientsException::invalid(['photos' => 'Photos must total no more than 100 MB.']);
      }
      foreach ($uploads as $upload) {
        $savedFiles[] = $this->storeFile($uid, $upload, $actor, ['jpg', 'jpeg', 'png', 'gif'], 20 * 1024 * 1024);
      }
      if ($savedFiles) {
        $message->set('field_before_and_after_photos', array_map(fn($file)=>['target_id' => $file->id()], $savedFiles));
      }
      $message->save();
      return $this->sessions($uid);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      foreach ($savedFiles as $file) {
        $this->files->delete($file->getFileUri());

      }throw $e;
    }
    finally {$this->lock->release($name);
    }
  }

  private function storeFile(int $uid, UploadedFile $upload, int $actor, array $extensions, int $limit) {
    $extension = strtolower(pathinfo($upload->getClientOriginalName(), PATHINFO_EXTENSION));
    if (!$upload->isValid()||!in_array($extension, $extensions, TRUE)||$upload->getSize() > $limit) {
      throw PatientsException::invalid(['file' => 'Unsupported file type or size.']);
    }
    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'], TRUE)&&@getimagesize($upload->getPathname()) === FALSE) {
      throw PatientsException::invalid(['file' => 'Choose a valid image.']);
    }
    $directory = 'private://patient-summary/' . $uid;
    if (!$this->files->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new PatientsException('Private file storage is unavailable.', 503);
    }
    $path = $this->files->copy($upload->getPathname(), $directory . '/' . bin2hex(random_bytes(16)) . '.' . $extension, FileSystemInterface::EXISTS_ERROR);
    $file = $this->entities->getStorage('file')->create(['uri' => $path, 'filename' => basename($upload->getClientOriginalName()), 'uid' => $actor, 'status' => 1]);
    try {
      $file->save();

    }
    catch (\Throwable $e) {
      $this->files->delete($path);
      throw $e;

    }return $file;
  }

  private function patientIntake(int $clinic, UserInterface $user): ?array {
    $id = $this->value($user, 'field_intake_form');
    if (!$id || !ctype_digit((string) $id)) {
      return NULL;
    }
    try {
      return $this->intake->get($clinic, (int) $id);

    }
    catch (PatientsException $e) {
      if ($e->getStatusCode() === 404) {
        return NULL;

      }throw $e;
    }
  }

  public function update(int $clinic, int $uid, array $data, int $actor): array {
    $user = $this->requirePatient($clinic, $uid);
    switch ($data['action'] ?? '') {
      case 'review':
        if (!in_array($data['status'] ?? NULL, ['New', 'Reviewed'], TRUE)) {
          throw PatientsException::invalid(['status' => 'Choose New or Reviewed.']);
        }
        $this->setFlag('reviewed_patients', $user, $data['status'] === 'New', $actor);

        break;

      case 'phase':
        if (!is_string($data['phase'] ?? NULL)||!$this->phases->isValidCode($data['phase'])) {
          throw PatientsException::invalid(['phase' => 'Choose a valid phase.']);
        }
        $user->set('field_weight_loss_phase', $this->phases->stored($data['phase']));
        $user->save();

        break;

      case 'lastSeen':
        $user->set('field_last_seen', $this->validDate($data['date'] ?? NULL));
        $user->save();

        break;

      case 'account':
        // The patient's account writes live in PatientsService with the rest of
        // the account lifecycle (create/archive/enroll); this action only routes
        // to them. The row below is rebuilt from a fresh load so the response
        // reflects the saved values rather than the stale pre-save entity.
        $this->patients->updateAccount($clinic, $uid, $data, $actor);
        $user = $this->requirePatient($clinic, $uid);

        break;

      default:
        throw PatientsException::invalid(['action' => 'Unknown patient action.']);
    }
    return ['patient' => $this->row($user)];
  }

  public function note(int $clinic, int $uid, array $data, int $actor): array {
    $this->requirePatient($clinic, $uid);
    $action = $data['action'] ?? '';
    $storage = $this->entities->getStorage('contact_message');
    $id = $data['id'] ?? NULL;
    if ($id !== NULL) {
      if (!is_int($id)||$id < 1) {
        throw PatientsException::invalid(['id' => 'Choose a valid note.']);
      }
      $message = $storage->load($id);
      if (!$message || $message->bundle() !== 'chiropractor_notes' || $this->reference($message, 'field_patient') !== $uid) {
        throw PatientsException::notFound('note');
      }
    }
    else {
      $message = NULL;
    }
    if ($action === 'delete' && $message) {
      $message->delete();
    }
    elseif ($action === 'pin' && $message && is_bool($data['pinned'] ?? NULL)) {
      $this->setFlag('pin_cs', $message, $data['pinned'], $actor);
    }
    elseif ($action === 'save') {
      $text = is_string($data['text'] ?? NULL) ? trim($data['text']) : '';
      if ($text === ''||mb_strlen($text) > 20000) {
        throw PatientsException::invalid(['text' => 'Enter a note of up to 20,000 characters.']);
      }
      $date = $this->validDate($data['date'] ?? NULL);
      $message ??= $storage->create(['contact_form' => 'chiropractor_notes', 'uid' => $actor, 'field_patient' => $uid, 'field_author' => $actor]);
      $message->set('field_notes', $text)->set('field_date', $date)->save();
    }
    else {
      throw PatientsException::invalid(['action' => 'Unknown note action.']);
    }
    return $this->notes($uid, 0);
  }

  public function file(int $clinic, int $uid, int $fileId, bool $photo = FALSE) {
    $user = $this->requirePatient($clinic, $uid);
    if ($photo) {
      $fileId = $this->profilePhoto($user) ?? 0;
    }
    else {
      $ids = $this->messageQuery('patient_attachments', $uid)->condition('field_attachments', $fileId)->range(0, 1)->execute();
      if (!$ids) {
        throw PatientsException::notFound('attachment');
      }
    }
    $file = $this->entities->getStorage('file')->load($fileId);
    if (!$file) {
      throw PatientsException::notFound('file');
    }
    return $file;
  }

  public function upload(int $clinic, int $uid, UploadedFile $upload, int $actor): array {
    $this->requirePatient($clinic, $uid);
    $transaction = $this->database->startTransaction();
    $file = $this->storeFile($uid, $upload, $actor, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'], 20 * 1024 * 1024);
    try {
      $this->entities->getStorage('contact_message')->create(['contact_form' => 'patient_attachments', 'uid' => $actor, 'field_author' => $actor, 'field_patient' => $uid, 'field_attachments' => ['target_id' => $file->id()]])->save();
      return $this->attachments($uid, 0);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $this->files->delete($file->getFileUri());
      throw $e;
    }
  }

  public function deleteAttachment(int $clinic, int $uid, int $fileId): array {
    $file = $this->file($clinic, $uid, $fileId);
    $messages = $this->entities->getStorage('contact_message')->loadMultiple($this->messageQuery('patient_attachments', $uid)->condition('field_attachments', $fileId)->execute());
    foreach ($messages as $message) {
      $items = array_values(array_filter($message->get('field_attachments')->getValue(), fn($item)=>(int) $item['target_id'] !== $fileId));
      if ($items) {
        $message->set('field_attachments', $items)->save();

      }
      else {
        $message->delete();
      }
    }
    // Delete only uploads owned by this patient and no longer used elsewhere.
    if (str_starts_with($file->getFileUri(), 'private://patient-summary/' . $uid . '/') && !\Drupal::service('file.usage')->listUsage($file)) {
      $file->delete();
    }
    return $this->attachments($uid, 0);
  }

  /**
   * The photos the "Update patient photos" dialog opens with: the profile photo
   * the roster avatar resolves to, and the patient_photos gallery in order.
   */
  public function photos(int $clinic, int $uid): array {
    $user = $this->requirePatient($clinic, $uid);
    $message = $this->patientPhotoMessage($user);
    $gallery = [];
    if ($message) {
      foreach ($message->get('field_before_and_after_photos')->referencedEntities() as $file) {
        $gallery[] = ['id' => (int) $file->id(), 'url' => '/api/patients/' . $uid . '/summary/photos/' . $file->id()];
      }
    }
    $profile = $this->profilePhoto($user);
    return [
      'profilePhoto' => $profile ? ['id' => $profile, 'url' => '/api/patients/' . $uid . '/summary/photo'] : NULL,
      'photos' => $gallery,
    ];
  }

  /**
   * Serves one of the patient's before/after patient_photos images.
   */
  public function photoFile(int $clinic, int $uid, int $fileId) {
    $user = $this->requirePatient($clinic, $uid);
    $message = $this->patientPhotoMessage($user);
    $allowed = [];
    if ($message) {
      foreach ($message->get('field_before_and_after_photos')->referencedEntities() as $file) {
        $allowed[(int) $file->id()] = TRUE;
      }
    }
    if (!isset($allowed[$fileId])) {
      throw PatientsException::notFound('photo');
    }
    $file = $this->entities->getStorage('file')->load($fileId);
    if (!$file) {
      throw PatientsException::notFound('photo');
    }
    return $file;
  }

  /**
   * Upserts the patient_photos contact message from a "Update patient photos"
   * save. The before/after tag is a client-side display choice and is not
   * stored, so the gallery keeps order only.
   */
  public function updatePhotos(int $clinic, int $uid, array $data, int $actor, array $uploads = [], ?UploadedFile $profileUpload = NULL): array {
    $user = $this->requirePatient($clinic, $uid);
    $storage = $this->entities->getStorage('contact_message');
    $message = $this->patientPhotoMessage($user);
    $existingProfileId = 0;
    $existingPhotoIds = [];
    if ($message) {
      $items = $message->get('field_profile_photo')->getValue();
      $existingProfileId = $items ? (int) $items[0]['target_id'] : 0;
      $existingPhotoIds = array_map(fn($item) => (int) $item['target_id'], $message->get('field_before_and_after_photos')->getValue());
    }
    $keepProfileId = $data['keepProfileId'] ?? NULL;
    if ($keepProfileId !== NULL && (!is_int($keepProfileId) || $keepProfileId < 1 || $keepProfileId !== $existingProfileId)) {
      throw PatientsException::invalid(['profilePhoto' => 'Invalid profile photo.']);
    }
    $keepPhotoIds = $data['keepPhotoIds'] ?? [];
    if (!is_array($keepPhotoIds) || count($keepPhotoIds) > 10) {
      throw PatientsException::invalid(['photos' => 'Invalid photo list.']);
    }
    $kept = [];
    foreach ($keepPhotoIds as $keepId) {
      if (!is_int($keepId) || $keepId < 1 || in_array($keepId, $kept, TRUE) || !in_array($keepId, $existingPhotoIds, TRUE)) {
        throw PatientsException::invalid(['photos' => 'Invalid photo list.']);
      }
      $kept[] = $keepId;
    }
    if (count($uploads) > 10) {
      throw PatientsException::invalid(['photos' => 'Choose up to 10 photos.']);
    }
    $total = 0;
    foreach ($uploads as $upload) {
      if (!$upload instanceof UploadedFile) {
        throw PatientsException::invalid(['photos' => 'Invalid photo.']);

      }$total += $upload->getSize();
    }
    if ($total > 100 * 1024 * 1024) {
      throw PatientsException::invalid(['photos' => 'Photos must total no more than 100 MB.']);
    }
    if (count($kept) + count($uploads) > 10) {
      throw PatientsException::invalid(['photos' => 'Choose up to 10 photos.']);
    }
    $lock = 'headless_patients.photos.' . $uid;
    if (!$this->lock->acquire($lock, 120)) {
      throw new PatientsException('Photos are already being saved. Please wait.', 409);
    }
    $transaction = $this->database->startTransaction();
    $saved = [];
    try {
      $profileFile = NULL;
      if ($profileUpload instanceof UploadedFile) {
        $profileFile = $this->storeFile($uid, $profileUpload, $actor, ['jpg', 'jpeg', 'png', 'gif'], 20 * 1024 * 1024);
        $saved[] = $profileFile;
      }
      elseif ($keepProfileId !== NULL) {
        $profileFile = $this->entities->getStorage('file')->load($keepProfileId);
      }
      if (!$profileFile) {
        throw PatientsException::invalid(['profilePhoto' => 'A profile photo is required.']);
      }
      $newPhotos = [];
      foreach ($uploads as $upload) {
        $newPhotos[] = $this->storeFile($uid, $upload, $actor, ['jpg', 'jpeg', 'png', 'gif'], 20 * 1024 * 1024);
      }
      foreach ($newPhotos as $file) {
        $saved[] = $file;
      }
      $message ??= $storage->create(['contact_form' => 'patient_photos', 'uid' => $actor, 'field_author' => $actor, 'field_patient' => $uid]);
      $message->set('field_profile_photo', ['target_id' => $profileFile->id()]);
      $gallery = array_merge(array_map(fn($id) => ['target_id' => $id], $kept), array_map(fn($file) => ['target_id' => $file->id()], $newPhotos));
      $message->set('field_before_and_after_photos', $gallery);
      $message->save();
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      foreach ($saved as $file) {
        $this->files->delete($file->getFileUri());

      }throw $e;
    }
    finally {
      $this->lock->release($lock);
    }
    // Reclaim private uploads this patient stopped using (replaced or removed).
    $wanted = array_merge($kept, [$profileFile->id()], array_map(fn($file) => $file->id(), $newPhotos));
    foreach (array_values(array_diff(array_merge($existingPhotoIds, $existingProfileId ? [$existingProfileId] : []), $wanted)) as $orphanId) {
      $file = $this->entities->getStorage('file')->load($orphanId);
      if ($file && str_starts_with($file->getFileUri(), 'private://patient-summary/' . $uid . '/') && !\Drupal::service('file.usage')->listUsage($file)) {
        $file->delete();

      }
    }
    return ['patient' => $this->row($user)];
  }

  private function setFlag(string $id, $entity, bool $set, int $actor): void {
    $flag = $this->flags->getFlagById($id);
    if (!$flag) {
      throw new PatientsException('Review flag is unavailable.', 503);
    }
    $account = $this->entities->getStorage('user')->load($actor);
    $existing = $this->flags->getFlagging($flag, $entity, $account);
    if ($set && !$existing) {
      $this->flags->flag($flag, $entity, $account);

    }
    elseif (!$set && $existing) {
      $this->flags->unflag($flag, $entity, $account);
    }
  }

  private function flagged(string $flag, string $type, array $ids): array {
    if (!$ids) {
      return [];
    }
    $result = $this->database->select('flagging', 'f')->fields('f', ['entity_id'])->condition('flag_id', $flag)->condition('entity_type', $type)->condition('entity_id', $ids, 'IN')->execute()->fetchCol();
    return array_fill_keys(array_map('intval', $result), TRUE);
  }

  private function value(FieldableEntityInterface $entity, string $field) {
    return $entity->hasField($field) && !$entity->get($field)->isEmpty() ? $entity->get($field)->value : NULL;
  }

  private function reference(FieldableEntityInterface $entity, string $field): ?int {
    return $entity->hasField($field) && !$entity->get($field)->isEmpty() ? (int) $entity->get($field)->target_id : NULL;
  }

  private function number(FieldableEntityInterface $entity, string $field): ?float {
    $value = $this->value($entity, $field);
    return is_numeric($value) ? (float) $value : NULL;
  }

  private function date($value): string {
    return $value ? date('m/d/Y', strtotime((string) $value)) : '';
  }

  private function validDate($value): string {
    if (!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
      throw PatientsException::invalid(['date' => 'Choose a valid date.']);
    }
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date||$date->format('Y-m-d') !== $value) {
      throw PatientsException::invalid(['date' => 'Choose a valid date.']);
    }
    return $value;
  }

}
