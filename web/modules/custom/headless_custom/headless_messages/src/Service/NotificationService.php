<?php

namespace Drupal\headless_messages\Service;

use Drupal\contact\Entity\Message;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Drupal\views\Views;
use Psr\Log\LoggerInterface;

/**
 * Sends the doctor's predefined and custom patient notifications.
 *
 * The pre-written notification texts come from `notification-templates.json`
 * in this module so they can be edited without touching PHP. A template is
 * { "id", "label", "sections", "flag"?, "roles"? }:
 *
 * - `sections` is always an array of HTML/plain-text blocks concatenated in
 *   order, keyed by audience:
 *   - `*`      always present, rendered first.
 *   - a brand  machine name (e.g. "ChiroThin") is appended when the sending
 *     chiropractor's clinic carries that `field_brand`.
 *   - `other`  is appended when there is no matching brand key.
 * - `flag` (optional) tags the patient's last submission with a
 *   `field_chiropractor_flags` term (15 = protein day, 18 = apple day) after
 *   sending, matching legacy `MessageRequest`.
 * - `roles` (optional) maps a role to remove to a role to add on the patient,
 *   matching the legacy `graduated` operation.
 *
 * Every send also clears the patient's `reviewed_patients` flag (they drop off
 * the doctor's review list) and delivers the message as a normal conversation
 * message, so it shows up in the patient's thread exactly like any other
 * message from their provider.
 *
 * Custom notifications are `review_messages` nodes: `field_message` holds the
 * body and `field_published_to` scopes it to the clinics the author published
 * it to.
 */
class NotificationService {

  /**
   * The JSON file relative to this module's directory.
   */
  protected const TEMPLATE_FILE = 'notification-templates.json';

  /**
   * The node type holding the doctor's own saved notifications.
   */
  protected const CUSTOM_TYPE = 'review_messages';

  /**
   * The flag whose presence means a patient still needs review.
   */
  protected const REVIEW_FLAG = 'reviewed_patients';

  /**
   * The view resolving a patient's most recent submission.
   */
  protected const SUBMISSION_VIEW = 'last_submission_cs';

  /**
   * Constructs a NotificationService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Loads users, nodes, clinics and submissions.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The sending doctor.
   * @param \Drupal\flag\FlagServiceInterface $flag_service
   *   Clears the patient's review flag.
   * @param \Psr\Log\LoggerInterface $logger
   *   Records non-fatal side-effect failures.
   * @param \Drupal\headless_messages\Service\MessagesService $messages_service
   *   Delivers the notification into the patient's message thread.
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_extension_list
   *   Resolves this module's path so the template file has one home.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entity_type_manager,
    protected AccountProxyInterface $current_user,
    protected FlagServiceInterface $flag_service,
    protected LoggerInterface $logger,
    protected MessagesService $messages_service,
    protected ModuleExtensionList $module_extension_list,
  ) {}

  /**
   * The predefined notification templates, bodies resolved for this clinic.
   *
   * The brand-sensitive `constipation` message is resolved here so the body the
   * frontend previews is byte-identical to the one a send builds again later.
   *
   * @return array
   *   List of ['id', 'label', 'body'].
   */
  public function templates(): array {
    $brand = $this->clinicBrand();
    $templates = [];
    foreach ($this->templateData() as $entry) {
      if (empty($entry['id']) || empty($entry['label'])) {
        continue;
      }
      $templates[] = [
        'id' => (string) $entry['id'],
        'label' => (string) $entry['label'],
        'body' => $this->render($entry, $brand),
      ];
    }
    return $templates;
  }

  /**
   * The doctor's saved notifications for their own clinic.
   *
   * @return array
   *   List of ['nid', 'title', 'body'], newest first.
   */
  public function customList(): array {
    $clinic_id = $this->currentClinicId();
    if (!$clinic_id || !$this->messages_service->isChiropractor()) {
      return [];
    }

    $ids = $this->entity_type_manager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::CUSTOM_TYPE)
      ->condition('status', 1)
      ->condition('field_published_to.target_id', $clinic_id)
      ->sort('created', 'DESC')
      ->execute();

    if (!$ids) {
      return [];
    }

    $custom = [];
    /** @var \Drupal\node\Entity\Node $node */
    foreach ($this->entity_type_manager->getStorage('node')->loadMultiple($ids) as $node) {
      $custom[] = [
        'nid' => (int) $node->id(),
        'title' => (string) $node->label(),
        'body' => $this->customBody($node),
      ];
    }
    return $custom;
  }

  /**
   * Sends a notification to a patient.
   *
   * `$operation` is either a predefined template id or `custom:<nid>` for a
   * saved review_messages notification. The message, review flag, submission
   * tags and role change all happen here so the write is one coherent action.
   *
   * @param string $operation
   *   The notification to send.
   * @param int $patient_id
   *   The recipient patient's user ID.
   *
   * @return array
   *   ['success' => bool, 'message' => mixed] — the created Message on success.
   */
  public function send(string $operation, int $patient_id): array {
    if (!$this->messages_service->isChiropractor()) {
      return ['success' => FALSE, 'message' => 'Only a chiropractor can send a notification.'];
    }
    if (!$this->messages_service->canSend()) {
      return [
        'success' => FALSE,
        'message' => 'Your account cannot send messages. Contact your clinic to re-enable messaging.',
      ];
    }

    $patient = $this->entity_type_manager->getStorage('user')->load($patient_id);
    if (!$patient) {
      return ['success' => FALSE, 'message' => 'Recipient not found'];
    }

    if (str_starts_with($operation, 'custom:')) {
      $node = $this->customNode((int) substr($operation, 7));
      if (!$node) {
        return ['success' => FALSE, 'message' => 'Notification not found'];
      }
      $markup = $this->customBody($node);
      $flag = 0;
      $roles = [];
    }
    else {
      $template = $this->templateById($operation);
      if (!$template) {
        return ['success' => FALSE, 'message' => 'Unknown notification'];
      }
      $markup = $this->render($template, $this->clinicBrand());
      $flag = (int) ($template['flag'] ?? 0);
      $roles = $template['roles'] ?? [];
    }

    if ($markup === '') {
      return ['success' => FALSE, 'message' => 'Notification has no message'];
    }

    // Legacy parity, in the same order the old route applied them.
    $this->unflagReview($patient_id);
    if ($flag > 0) {
      $this->flagLastSubmission($patient_id, $flag);
    }
    if ($roles) {
      $this->applyRoles($patient, $roles);
    }

    $markup = $this->personalizeName($markup, $patient);
    return $this->messages_service->sendNotification($patient_id, $markup);
  }

  /**
   * Saves a new custom notification as a review_messages node for this clinic.
   *
   * @param string $title
   *   The notification's name, shown on the doctor's card.
   * @param string $message
   *   The notification body, possibly containing `{name}`.
   *
   * @return array
   *   ['success' => bool, 'message' => mixed, 'notification' => array|null]
   */
  public function saveCustom(string $title, string $message): array {
    $clinic_id = $this->currentClinicId();
    if (!$clinic_id || !$this->messages_service->isChiropractor()) {
      return ['success' => FALSE, 'message' => 'Only a chiropractor can save a notification.'];
    }

    $title = trim($title);
    $message = trim($message);
    if ($title === '' || $message === '') {
      return ['success' => FALSE, 'message' => 'Add a name and a message first.'];
    }

    $node = Node::create([
      'type' => self::CUSTOM_TYPE,
      'title' => $title,
      'uid' => (int) $this->current_user->id(),
      'status' => 1,
      'field_message' => $message,
      'field_published_to' => [['target_id' => $clinic_id]],
    ]);
    $node->save();

    return [
      'success' => TRUE,
      'notification' => [
        'nid' => (int) $node->id(),
        'title' => $title,
        'body' => $message,
      ],
    ];
  }

  /**
   * Updates one of the doctor's own saved notifications.
   *
   * @param int $nid
   *   The review_messages node to update.
   * @param string $title
   *   The notification's new name.
   * @param string $message
   *   The notification's new body, possibly containing `{name}`.
   *
   * @return array
   *   ['success' => bool, 'message' => mixed, 'notification' => array|null]
   */
  public function updateCustom(int $nid, string $title, string $message): array {
    $clinic_id = $this->currentClinicId();
    if (!$clinic_id || !$this->messages_service->isChiropractor()) {
      return ['success' => FALSE, 'message' => 'Only a chiropractor can edit a notification.'];
    }

    $title = trim($title);
    $message = trim($message);
    if ($title === '' || $message === '') {
      return ['success' => FALSE, 'message' => 'Add a name and a message first.'];
    }

    $node = $this->entity_type_manager->getStorage('node')->load($nid);
    if (!$node || $node->bundle() !== self::CUSTOM_TYPE) {
      return ['success' => FALSE, 'message' => 'Notification not found'];
    }

    $published_to = array_map(
      'intval',
      array_column($node->get('field_published_to')->getValue(), 'target_id')
    );
    if (!in_array($clinic_id, $published_to, TRUE)) {
      return ['success' => FALSE, 'message' => 'Notification not found'];
    }

    $node->set('title', $title);
    $node->set('field_message', $message);
    $node->save();

    return [
      'success' => TRUE,
      'notification' => [
        'nid' => (int) $node->id(),
        'title' => $title,
        'body' => $message,
      ],
    ];
  }

  /**
   * Deletes one of the doctor's own saved notifications.
   *
   * @param int $nid
   *   The review_messages node to delete.
   *
   * @return bool
   *   TRUE when a node the caller's clinic owns was deleted.
   */
  public function deleteCustom(int $nid): bool {
    $clinic_id = $this->currentClinicId();
    if (!$clinic_id || !$this->messages_service->isChiropractor()) {
      return FALSE;
    }

    $node = $this->entity_type_manager->getStorage('node')->load($nid);
    if (!$node || $node->bundle() !== self::CUSTOM_TYPE) {
      return FALSE;
    }

    // getValue() returns the reference target as quoted by the DB (a string), so
    // normalise both sides to ints before the strict membership test.
    $published_to = array_map(
      'intval',
      array_column($node->get('field_published_to')->getValue(), 'target_id')
    );
    if (!in_array($clinic_id, $published_to, TRUE)) {
      return FALSE;
    }

    $node->delete();
    return TRUE;
  }

  /**
   * Loads a review_messages node this doctor's clinic may use.
   */
  protected function customNode(int $nid): ?Node {
    $clinic_id = $this->currentClinicId();
    if (!$nid || !$clinic_id) {
      return NULL;
    }

    $node = $this->entity_type_manager->getStorage('node')->load($nid);
    if (!$node || $node->bundle() !== self::CUSTOM_TYPE || !$node->isPublished()) {
      return NULL;
    }

    $published_to = array_map(
      'intval',
      array_column($node->get('field_published_to')->getValue(), 'target_id')
    );
    return in_array($clinic_id, $published_to, TRUE) ? $node : NULL;
  }

  /**
   * The body of a review_messages node.
   */
  protected function customBody(Node $node): string {
    $values = $node->get('field_message')->getValue();
    return (string) ($values[0]['value'] ?? '');
  }

  /**
   * The signed-in account's clinic id, or 0 when unassigned.
   */
  protected function currentClinicId(): int {
    if ($this->current_user->isAnonymous()) {
      return 0;
    }
    $user = $this->entity_type_manager->getStorage('user')->load((int) $this->current_user->id());
    if (!$user || !$user->hasField('field_clinic') || $user->get('field_clinic')->isEmpty()) {
      return 0;
    }
    return (int) $user->get('field_clinic')->target_id;
  }

  /**
   * The sending clinic's brand machine name, or '' when it cannot be read.
   */
  protected function clinicBrand(): string {
    $clinic_id = $this->currentClinicId();
    if (!$clinic_id) {
      return '';
    }
    $clinic = $this->entity_type_manager->getStorage('clinic')->load($clinic_id);
    if (!$clinic || $clinic->get('field_brand')->isEmpty()) {
      return '';
    }
    return (string) $clinic->get('field_brand')->value;
  }

  /**
   * Renders a template's blocks for a clinic brand, `*` then the brand tail.
   */
  protected function render(array $template, string $brand): string {
    $sections = $template['sections'] ?? [];
    $blocks = is_array($sections['*'] ?? NULL) ? $sections['*'] : [];
    if (is_array($sections[$brand] ?? NULL)) {
      $blocks = array_merge($blocks, $sections[$brand]);
    }
    elseif (is_array($sections['other'] ?? NULL)) {
      $blocks = array_merge($blocks, $sections['other']);
    }
    return implode('', array_map('strval', $blocks));
  }

  /**
   * The decoded template file, or [] when absent or unparseable.
   */
  protected function templateData(): array {
    $path = $this->module_extension_list->getPath('headless_messages') . '/' . self::TEMPLATE_FILE;
    if (!is_file($path)) {
      return [];
    }
    $decoded = json_decode((string) file_get_contents($path), TRUE);
    return is_array($decoded) ? array_values($decoded) : [];
  }

  /**
   * A single predefined template by id, or NULL.
   */
  protected function templateById(string $id): ?array {
    foreach ($this->templateData() as $entry) {
      if (($entry['id'] ?? NULL) === $id) {
        return $entry;
      }
    }
    return NULL;
  }

  /**
   * Clears the patient's "needs review" flag held by the current chiropractor.
   */
  protected function unflagReview(int $patient_id): void {
    $flag = $this->flag_service->getFlagById(self::REVIEW_FLAG);
    if (!$flag || $this->current_user->isAnonymous()) {
      return;
    }
    $chiropractor = $this->entity_type_manager->getStorage('user')->load((int) $this->current_user->id());
    $patient = $this->entity_type_manager->getStorage('user')->load($patient_id);
    if (!$chiropractor || !$patient) {
      return;
    }
    $flagging = $this->flag_service->getFlagging($flag, $patient, $chiropractor);
    if ($flagging) {
      $this->flag_service->unflag($flag, $patient, $chiropractor);
    }
  }

  /**
   * Tags the patient's last submission with a chiropractor flag term.
   *
   * Mirrors the legacy route's use of the `last_submission_cs` view. Missing
   * view, empty result, or a submission without the field are logged and
   * ignored rather than aborting the send.
   *
   * @param int $patient_id
   *   The patient whose last submission to tag.
   * @param int $term_id
   *   The field_chiropractor_flags term to add (15 or 18).
   */
  protected function flagLastSubmission(int $patient_id, int $term_id): void {
    try {
      $view = Views::getView(self::SUBMISSION_VIEW);
      if (!$view) {
        $this->logger->notice('Notification cannot tag the last submission: view @view is unavailable.', ['@view' => self::SUBMISSION_VIEW]);
        return;
      }
      $view->setDisplay('block_1');
      $view->setArguments([(string) $patient_id]);
      $view->execute();

      $submission = $view->result[0]->_entity ?? NULL;
      if (!$submission || $submission->getEntityTypeId() !== 'contact_message') {
        return;
      }
      if (!$submission->hasField('field_chiropractor_flags')) {
        return;
      }

      $flags = $submission->get('field_chiropractor_flags')->getValue();
      $existing = array_column($flags, 'target_id');
      if (!in_array($term_id, $existing, TRUE)) {
        $flags[] = ['target_id' => $term_id];
        $submission->set('field_chiropractor_flags', $flags);
        $submission->save();
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Notification flag on last submission for UID @uid failed: @message', [
        '@uid' => $patient_id,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Applies a template's role transition (e.g. enrolled_patient → archived).
   *
   * @param \Drupal\user\UserInterface $patient
   *   The patient to transition.
   * @param array $roles
   *   Map of role to remove => role to add.
   */
  protected function applyRoles(UserInterface $patient, array $roles): void {
    foreach ($roles as $from => $to) {
      $patient->removeRole((string) $from);
      $patient->addRole((string) $to);
    }
    $patient->changed->preserve = TRUE;
    $patient->save();
  }

  /**
   * Substitutes `{name}` and `{first_name}` with the patient's first name.
   */
  protected function personalizeName(string $markup, UserInterface $patient): string {
    $first = trim((string) strtok($patient->getDisplayName() ?: '', ' '));
    if ($first === '') {
      return $markup;
    }
    return preg_replace('/\{(?:name|first_name)\}/i', $first, $markup) ?? $markup;
  }

}