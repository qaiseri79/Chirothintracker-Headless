<?php

namespace Drupal\headless_messages\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\flag\FlagServiceInterface;
use Psr\Log\LoggerInterface;
use Drupal\user\Entity\User;
use Drupal\contact\Entity\Message;
use Drupal\file\FileRepositoryInterface;
use Drupal\Component\Utility\Html;

/**
 * Service for headless messaging between patients and chiropractors.
 *
 * Messages are stored as contact_message entities with contact_form = 'message'.
 * Conversations are between two users (patient <-> chiropractor/staff).
 * The 'message' contact form has fields:
 * - field_from (uid reference)
 * - field_to (uid reference)
 * - field_message (formatted text)
 * - field_patient_uid (uid reference for querying)
 * - field_attachments (file reference)
 *
 * ## Read state
 *
 * Read state lives in the `message_status_contact_storage` flag, and it is
 * **per recipient, not per message**. A flagging on a message means "this
 * recipient has not opened it yet"; the absence of one means it has. So every
 * read check has to ask about a specific account — normally the recipient of
 * that message — and `isMessageRead()` takes that account explicitly rather
 * than assuming it is the current user. Asking on behalf of the sender answers a
 * different question and reports the sender's own outgoing messages as read,
 * which is how a sent message used to come back with `is_read: true`.
 *
 * ## One conversation per provider
 *
 * `field_patient_uid` is the conversation's patient, set on send by whichever
 * account sent it, so a chiropractor's own replies stay findable under the
 * patient's uid. That also means the query is patient-scoped, not caller-scoped:
 * a chiropractor calling `getConversations()` sees the patients assigned to them
 * rather than their own outbound mail.
 *
 * ## Newest first
 *
 * A thread renders newest-first (`created` DESC). Older code delivered threads
 * oldest-first so the newest sat at the bottom like an inbox; the patient asked
 * for the opposite, so what `mapMessage()` produces is already the order the
 * frontend renders.
 *
 * ## Bodies are formatted text, not plain text
 *
 * `field_message` stores a value plus a text `format` (`basic_html`). A
 * programmatic `Message::save()` never runs filters, so the value is handed
 * back as it was stored — some rows carry real markup (`<p>…</p>`), others are
 * plain strings. `mapMessage()` therefore renders the stored value through the
 * format's filter pipeline (this is what `check_markup()` does) rather than
 * emitting it raw, and the frontend renders that sanitised HTML. Rendering is
 * limited to a safe allowlist of formats: an unknown or unrestricted format
 * falls back to escaped plain text instead of being filtered through whatever
 * the row claims.
 *
 * ## Attachments
 *
 * `field_attachments` is a single-file field under `private://`. Files land
 * there via `storeAttachment()` (upload first, then reference the fid), and
 * are delivered on demand by `attachmentFile()` only to an account that is a
 * party to the thread referencing them — never by a guessable URL.
 */
class MessagesService {

  /**
   * The flag carrying read state.
   */
  protected const FLAG_ID = 'message_status_contact_storage';

  /**
   * The role that may perform operations as a patient.
   *
   * The counterpart — 'archived_patient' — is read-only: it may read its own
   * thread but may not send.
   */
  protected const ACTIVE_ROLE = 'enrolled_patient';

  /**
   * The role that may perform operations as a chiropractor.
   *
   * The counterpart — 'chiropractor_inactive_' — is read-only, matching
   * `lib/portal.ts`, which resolves the portal's two capability levels per
   * audience.
   */
  protected const ACTIVE_CHIROPRACTOR_ROLE = 'chiropractor_active_';

  /**
   * Roles permitted to use the messaging endpoints at all.
   *
   * A read-only account can still read its own history, so it is not excluded
   * here; the write paths check the active roles separately.
   */
  protected const READ_ROLES = ['enrolled_patient', 'archived_patient'];

  /**
   * Roles whose conversations are listed from both directions.
   *
   * A chiropractor's counterpart is a patient rather than a single provider, so
   * the patient-scoped query cannot serve them. See getConversations().
   */
  protected const CHIROPRACTOR_ROLES = ['chiropractor_active_', 'chiropractor_inactive_'];

  /**
   * Text formats a stored message body may be rendered through.
   *
   * `full_html`, `html`, `php_code`, and the like exist on this site and would
   * filter a stored value into arbitrarily permissive HTML. Only formats that
   * themselves constrain tags are trusted; anything else is escaped instead.
   */
  protected const RENDERABLE_FORMATS = ['basic_html'];

  const ALLOWED_ATTACHMENT_EXTENSIONS = ['pdf', 'jpeg', 'jpg', 'png', 'doc', 'docx', 'xls', 'xlsx'];

  const MAX_ATTACHMENT_BYTES = 20 * 1024 * 1024;

  /**
   * Ceiling on one bulk send.
   *
   * Every recipient is a separate entity save, so this bounds the work a single
   * request can do rather than the number of recipients a clinic may have.
   */
  const MASS_RECIPIENT_LIMIT = 500;

  /**
   * Constructs a MessagesService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Loads contact_message and user entities.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The account whose messages are being read or written.
   * @param \Drupal\flag\FlagServiceInterface $flag_service
   *   Reads and writes the per-recipient read state.
   * @param \Psr\Log\LoggerInterface $logger
   *   Records send failures.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   Renders stored bodies through the text-filter pipeline.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   Names private:// uploads and resolves real paths for downloads.
   * @param \Drupal\file\FileRepositoryInterface $file_repository
   *   Persists uploaded bytes as file entities under private://.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entity_type_manager,
    protected AccountProxyInterface $current_user,
    protected FlagServiceInterface $flag_service,
    protected LoggerInterface $logger,
    protected RendererInterface $renderer,
    protected FileSystemInterface $file_system,
    protected FileRepositoryInterface $file_repository,
  ) {}

  /**
   * Whether the current account may send messages.
   *
   * `archived_patient` and `chiropractor_inactive_` are read-only per
   * `lib/portal.ts`: they may view and download their own data and perform no
   * operations. The frontend disables the reply box for them, but the server is
   * what actually has to refuse the write.
   *
   * Both audiences are checked against their own active role rather than
   * against a single one. A chiropractor holding only `chiropractor_inactive_`
   * stays read-only, and a chiropractor holding `chiropractor_active_` — which
   * 92 accounts hold alongside the inactive one — may send, matching the
   * "active wins" rule the portal documents for an account holding both
   * capability levels of one audience.
   *
   * @return bool
   *   TRUE when the account is an active patient or an active chiropractor.
   */
  public function canSend(): bool {
    $account = $this->current_user;
    if (!$account->isAuthenticated()) {
      return FALSE;
    }
    if (\Drupal::hasContainer() && \Drupal::hasService('headless_access.portal_access')) {
      return (bool) (\Drupal::service('headless_access.portal_access')->resolve($account)['write'] ?? FALSE);
    }
    $roles = $account->getRoles();
    return in_array(self::ACTIVE_ROLE, $roles, TRUE)
      || in_array(self::ACTIVE_CHIROPRACTOR_ROLE, $roles, TRUE);
  }

  /**
   * Whether the current account is a chiropractor.
   *
   * Decides which side of the conversation list this is: a patient has one
   * counterpart and a chiropractor has one per patient.
   */
  public function isChiropractor(): bool {
    if (!$this->current_user->isAuthenticated()) {
      return FALSE;
    }
    return array_intersect($this->current_user->getRoles(), self::CHIROPRACTOR_ROLES) !== [];
  }

  /**
   * Counts the caller's unread inbound messages without loading message entities.
   */
  public function unreadCount(): int {
    $account = $this->current_user;
    if (!$account->isAuthenticated()) {
      return 0;
    }
    $flag = $this->flag_service->getFlagById(self::FLAG_ID);
    if (!$flag) {
      return 0;
    }
    $uid = (int) $account->id();
    $database = \Drupal::database();
    $query = $database->select('flagging', 'unread');
    $query->join('contact_message', 'm', 'm.id = unread.entity_id');
    $query->join('contact_message__field_to', 'recipient',
      'recipient.entity_id = m.id AND recipient.deleted = 0 AND recipient.delta = 0');
    $query->join('contact_message__field_from', 'sender',
      'sender.entity_id = m.id AND sender.deleted = 0 AND sender.delta = 0');
    $query->condition('unread.flag_id', self::FLAG_ID)
      ->condition('unread.entity_type', 'contact_message')
      ->condition('m.contact_form', 'message')
      ->condition('recipient.field_to_target_id', $uid)
      ->condition('sender.field_from_target_id', $uid, '<>')
      ->condition('sender.field_from_target_id', 0, '>');
    // Match FlagService::getEntityFlaggings(): global flags ignore the flagger.
    if (!$flag->isGlobal()) {
      $query->condition('unread.uid', $uid);
    }
    if (!$this->isChiropractor()) {
      $query->join('contact_message__field_patient_uid', 'patient',
        'patient.entity_id = m.id AND patient.deleted = 0 AND patient.delta = 0');
      $query->condition('patient.field_patient_uid_value', $uid);
    }
    $query->addExpression('COUNT(DISTINCT unread.entity_id)', 'unread_count');
    return (int) $query->execute()->fetchField();
  }

  /**
   * Gets all conversations for the current user.
   *
   * Summary mode returns only each latest message plus plain history search text.
   * It batches unread flags and skips older HTML and attachment rendering.
   *
   * A conversation is a group of messages between the current user and one other
   * user, grouped by "the other user". The shape is the same for both audiences —
   * the doctor page's sidebar and the patient page's single thread read the same
   * object — so only the query differs.
   *
   * - A **patient** is listed from `field_patient_uid`, because that is the
   *   conversation's patient on every message in it, whoever sent it. Their
   *   provider (user.field_chiropractor) is always present as a conversation,
   *   even with no messages, so the frontend can render the "start your
   *   conversation" state with a real name in it.
   * - A **chiropractor** is listed from both directions instead, because their
   *   counterpart is a patient and `field_patient_uid` on a patient's own
   *   message is the *patient's* uid, not the chiropractor's. Scoping on it would
   *   return only the chiropractor's outbound mail and no replies at all.
   *
   * @return array
   *   Array of conversation summaries, most recent message first.
   */
  public function getConversations(bool $summaryOnly = FALSE): array {
    $account = $this->current_user;
    $uid = $account->isAuthenticated() ? (int) $account->id() : 0;
    if (!$uid) {
      return [];
    }

    $is_chiropractor = $this->isChiropractor();
    // Patients still receive their full thread; only the doctor sidebar opts in.
    $summaryOnly = $summaryOnly && $is_chiropractor;
    if ($summaryOnly) {
      return $this->conversationSummaries($uid);
    }

    $storage = $this->entity_type_manager->getStorage('contact_message');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('contact_form', 'message');

    if ($is_chiropractor) {
      // Every message this chiropractor sent or received. `field_patient_uid`
      // cannot be used here: see the method docblock.
      // Separate reference queries avoid the costly cross-table OR joins.
      $sent = (clone $query)->condition('field_from', $uid)->execute();
      $received = (clone $query)->condition('field_to', $uid)->execute();
      $ids = array_values(array_unique(array_merge($sent, $received)));
    }
    else {
      $ids = $query->condition('field_patient_uid', $uid)
        ->sort('created', 'DESC')->execute();
    }

    $messages = $ids ? $storage->loadMultiple($ids) : [];
    if ($is_chiropractor) {
      // Combining directional results does not preserve chronological order.
      uasort($messages, fn(Message $a, Message $b) =>
        ($this->messageTime($b) <=> $this->messageTime($a))
        ?: ((int) $b->id() <=> (int) $a->id())
      );
    }

    $conversations = [];

    // Unread is tallied in its own pass, before any grouping happens.
    //
    // It used to be incremented inside the loop below, which meant a message
    // the loop skipped — one whose counterpart it could not resolve — was never
    // counted. That is how the sidebar could report 0 unread for a patient
    // whose thread, fetched separately, reported 1: the two endpoints walked
    // the same messages but the count was downstream of a `continue` that the
    // thread's own loop does not have. Whether the reader can send is
    // independent of who the other party is, so it is measured independently of
    // that too.
    $unread_by_partner = [];
    foreach ($messages as $msg) {
      $to_uid = (int) ($msg->get('field_to')->target_id ?? 0);
      if ($to_uid !== $uid) {
        continue;
      }
      if (!$this->isMessageRead($msg, $this->current_user)) {
        $from_uid = (int) ($msg->get('field_from')->target_id ?? 0);
        if ($from_uid) {
          $unread_by_partner[$from_uid] = ($unread_by_partner[$from_uid] ?? 0) + 1;
        }
      }
    }

    foreach ($messages as $msg) {
      $from_uid = (int) ($msg->get('field_from')->target_id ?? 0);
      $to_uid = (int) ($msg->get('field_to')->target_id ?? 0);
      if (!$from_uid || !$to_uid || $from_uid === $to_uid) {
        continue;
      }

      $other_uid = ($from_uid === $uid) ? $to_uid : $from_uid;
      if (!isset($conversations[$other_uid])) {
        $other_user = $this->entity_type_manager->getStorage('user')->load($other_uid);
        if (!$other_user) {
          continue;
        }
        $conversations[$other_uid] = $this->conversationShell($other_user);
      }

      $message_data = $this->mapMessage($msg);
      $conversations[$other_uid]['messages'][] = $message_data;

      // Messages are sorted DESC, so the first message seen is the newest.
      if ($conversations[$other_uid]['last_message_time'] === NULL) {
        $conversations[$other_uid]['last_message_time'] = (int) $this->messageTime($msg);
        $conversations[$other_uid]['last_message_preview'] = $this->truncateText($message_data['text'], 100);
      }

      // Only messages addressed to the reader count as unread. The tally lives
      // in the pre-pass above, so it is merged in here rather than incremented:
      // reaching this point means the message was successfully grouped, which is
      // a separate question from whether the reader has seen it.
      $conversations[$other_uid]['unread_count'] = $unread_by_partner[$other_uid] ?? 0;
    }

    // The provider is included even when the thread is empty, so the frontend
    // has a name to show in its "no messages yet" state. A chiropractor has no
    // single provider, so this is a patient-only step.
    if (!$is_chiropractor) {
      $provider_uid = $this->providerUid();
      if ($provider_uid && $provider_uid !== $uid && !isset($conversations[$provider_uid])) {
        $provider = $this->entity_type_manager->getStorage('user')->load($provider_uid);
        if ($provider) {
          $conversations[$provider_uid] = $this->conversationShell($provider);
        }
      }
    }

    uasort($conversations, function (array $a, array $b) {
      return ($b['last_message_time'] ?? 0) <=> ($a['last_message_time'] ?? 0);
    });

    foreach ($conversations as &$conv) {
      // Messages are sorted newest-first, so the first message seen for a
      // conversation is its newest and the thread is already in the order the
      // frontend renders — newest at the top.
      $this->formatConversation($conv);
    }
    unset($conv);

    return array_values($conversations);
  }

  /**
   * Reads scalar history rows; only the latest message per partner becomes an entity.
   *
   * Both directions are queried separately so each can filter its own reference
   * table before joining message fields. No caller-supplied tenant or account ID
   * enters this query: $uid comes from the authenticated account.
   */
  protected function sidebarRows(int $uid): iterable {
    $database = \Drupal::database();
    $queries = [];
    foreach (['field_from', 'field_to'] as $direction) {
      $query = $database->select('contact_message__' . $direction, 'participant');
      $query->join('contact_message', 'm', 'm.id = participant.entity_id');
      $query->join('contact_message__field_from', 'sender',
        'sender.entity_id = m.id AND sender.deleted = 0 AND sender.delta = 0');
      $query->join('contact_message__field_to', 'recipient',
        'recipient.entity_id = m.id AND recipient.deleted = 0 AND recipient.delta = 0');
      $query->leftJoin('contact_message__field_message', 'body',
        'body.entity_id = m.id AND body.deleted = 0 AND body.delta = 0');
      $flag = $this->flag_service->getFlagById(self::FLAG_ID);
      $flagJoin = 'unread.entity_id = m.id AND unread.entity_type = :sidebar_entity_type AND unread.flag_id = :sidebar_flag';
      $flagArgs = [':sidebar_entity_type' => 'contact_message', ':sidebar_flag' => self::FLAG_ID];
      if ($flag && !$flag->isGlobal()) {
        $flagJoin .= ' AND unread.uid = :sidebar_uid';
        $flagArgs[':sidebar_uid'] = $uid;
      }
      $query->leftJoin('flagging', 'unread', $flagJoin, $flagArgs);
      $query->condition('participant.' . $direction . '_target_id', $uid)
        ->condition('participant.deleted', 0)
        ->condition('participant.delta', 0)
        ->condition('m.contact_form', 'message');
      $query->addField('m', 'id', 'id');
      $query->addField('m', 'created', 'created');
      $query->addField('sender', 'field_from_target_id', 'from_uid');
      $query->addField('recipient', 'field_to_target_id', 'to_uid');
      $query->addField('body', 'field_message_value', 'body');
      $query->addField('unread', 'id', 'unread_id');
      $queries[] = $query;
    }
    // UNION removes duplicates for records appearing in both directional queries.
    $queries[0]->union($queries[1], 'UNION');
    return $database->select($queries[0], 'history')
      ->fields('history')
      ->orderBy('created', 'DESC')
      ->orderBy('id', 'DESC')
      ->execute();
  }

  /**
   * Builds sidebar previews, unread totals and search text without full histories.
   */
  protected function conversationSummaries(int $uid): array {
    $summaries = [];
    foreach ($this->sidebarRows($uid) as $row) {
      $from = (int) $row->from_uid;
      $to = (int) $row->to_uid;
      if (!$from || !$to || $from === $to || ($from !== $uid && $to !== $uid)) {
        continue;
      }
      $partner = $from === $uid ? $to : $from;
      if (!isset($summaries[$partner])) {
        $summaries[$partner] = ['latest_id' => (int) $row->id, 'unread_count' => 0, 'texts' => []];
      }
      $summaries[$partner]['texts'][] = $this->plainBody((string) ($row->body ?? ''));
      if ($to === $uid && $row->unread_id !== NULL) {
        $summaries[$partner]['unread_count']++;
      }
    }

    if (!$summaries) {
      return [];
    }
    $partners = $this->entity_type_manager->getStorage('user')->loadMultiple(array_keys($summaries));
    $latestIds = array_column($summaries, 'latest_id');
    $latest = $this->entity_type_manager->getStorage('contact_message')->loadMultiple($latestIds);
    $conversations = [];
    foreach ($summaries as $partner => $summary) {
      if (!isset($partners[$partner], $latest[$summary['latest_id']])) {
        continue;
      }
      $message = $latest[$summary['latest_id']];
      $mapped = $this->mapMessage($message);
      $conversation = $this->conversationShell($partners[$partner]);
      $conversation['messages'] = [$mapped];
      $conversation['unread_count'] = $summary['unread_count'];
      $conversation['search_text'] = implode("\n", $summary['texts']);
      $conversation['last_message_time'] = $this->messageTime($message);
      $conversation['last_message_preview'] = $this->truncateText($mapped['text'], 100);
      $this->formatConversation($conversation);
      $conversations[] = $conversation;
    }
    return $conversations;
  }

  /**
   * Gets the full thread between the current user and a target user.
   *
   * Returns the same shape as getConversations() — both directions of the
   * thread, plus the summary fields — so the frontend can swap one for the
   * other without the two disagreeing about which keys exist.
   *
   * @param int $target_uid
   *   The other user in the conversation.
   *
   * @return array|null
   *   Thread data, or NULL when the target does not exist or the caller is not
   *   party to any thread with them.
   */
  public function getThread(int $target_uid): ?array {
    $account = $this->current_user;
    $uid = $account->isAuthenticated() ? (int) $account->id() : 0;
    if (!$uid) {
      return NULL;
    }

    $target_user = $this->entity_type_manager->getStorage('user')->load($target_uid);
    if (!$target_user || $target_uid === $uid) {
      return NULL;
    }

    $storage = $this->entity_type_manager->getStorage('contact_message');

    // field_patient_uid is the patient, not the sender, so both directions are
    // found under the patient's uid when the reader is the patient and under the
    // chiropractor's when the reader is the chiropractor.
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('contact_form', 'message')
      ->condition('field_patient_uid', [$uid, $target_uid], 'IN')
      ->condition('field_from', [$uid, $target_uid], 'IN')
      ->condition('field_to', [$uid, $target_uid], 'IN')
      ->sort('created', 'DESC')
      ->execute();

    if (!$ids) {
      return NULL;
    }

    $messages = $storage->loadMultiple($ids);
    // A merged or IN-queried result set has no guaranteed order, and loadMultiple
    // preserves the order of what it was given, so sort on the entity value —
    // newest last, the order the thread renders.
    uasort($messages, fn(Message $a, Message $b) => $this->messageTime($b) <=> $this->messageTime($a));

    $conversation = $this->conversationShell($target_user);
    $newest_mapped = NULL;
    foreach ($messages as $msg) {
      $from_uid = (int) ($msg->get('field_from')->target_id ?? 0);
      $to_uid = (int) ($msg->get('field_to')->target_id ?? 0);
      // This query accepts `field_from IN (me, them) AND field_to IN (me, them)`,
      // which also matches a message addressed to nobody in the conversation —
      // from and to the same one of us. getConversations() drops those, so
      // without the same guard here the two endpoints disagreed about how many
      // messages a thread had (1495 against 1126 on one real account) and the
      // thread rendered rows the sidebar had never heard of.
      if (!$from_uid || !$to_uid || $from_uid === $to_uid) {
        continue;
      }
      $mapped = $this->mapMessage($msg);
      if ($newest_mapped === NULL) {
        $newest_mapped = $mapped;
      }
      $conversation['messages'][] = $mapped;
      if ($to_uid === $uid && !$mapped['is_read']) {
        $conversation['unread_count']++;
      }
    }

    if ($newest_mapped) {
      $newest = reset($messages);
      $conversation['last_message_time'] = (int) $this->messageTime($newest);
      $conversation['last_message_preview'] = $this->truncateText($newest_mapped['text'], 100);
    }

    $this->formatConversation($conversation);

    return $conversation;
  }

  /**
   * Sends a new message from current user to target user.
   *
   * @param int $target_uid
   *   Recipient user ID.
   * @param string $text
   *   Message body. Stored and returned as plain text — see sanitizeText().
   * @param int|string $attachment_fid
   *   Optional file entity ID of an uploaded attachment.
   *
   * @return array
   *   ['success' => bool, 'message' => mixed, 'id' => int|null]
   */
  public function sendMessage(int $target_uid, string $text, int|string $attachment_fid = 0): array {
    $account = $this->current_user;
    if (!$account->isAuthenticated()) {
      return ['success' => FALSE, 'message' => 'Unauthenticated'];
    }
    $uid = (int) $account->id();

    if (!$this->canSend()) {
      return [
        'success' => FALSE,
        'message' => 'Your account cannot send messages. Contact your clinic to re-enable messaging.',
      ];
    }

    $target_user = $this->entity_type_manager->getStorage('user')->load($target_uid);
    if (!$target_user) {
      return ['success' => FALSE, 'message' => 'Recipient not found'];
    }

    // Sanitise before the entity is built, so an empty result is refused without
    // touching storage at all.
    $text = $this->sanitizeText($text);
    if ($text === '' && (!$attachment_fid || $attachment_fid == 0)) {
      return ['success' => FALSE, 'message' => 'Message cannot be empty'];
    }

    $attachment_fid = (int) $attachment_fid;
    // Confirm the referenced file exists and belongs to this site before it is
    // pinned to a threaded record.
    $attachment = NULL;
    if ($attachment_fid > 0) {
      $attachment = $this->entity_type_manager->getStorage('file')->load($attachment_fid);
      if (!$attachment) {
        return ['success' => FALSE, 'message' => 'Attachment not found'];
      }
    }

    $build = [
      'contact_form' => 'message',
      'uid' => $uid,
      'field_from' => $uid,
      'field_to' => $target_uid,
      // Chiropractor sends to patient: patient_uid = recipient (patient).
      // Patient sends to chiropractor: patient_uid = sender (patient).
      'field_patient_uid' => $this->isChiropractor() ? $target_uid : $uid,
    ];
    if ($text !== '') {
      $build['field_message'] = [
        'value' => $text,
        'format' => 'basic_html',
      ];
    }
    if ($attachment) {
      $build['field_attachments'] = ['target_id' => $attachment->id()];
    }

    try {
      $message = Message::create($build);
      $message->save();

      // Flagged against the recipient, so only they see it as unread.
      $this->setReadState($message, $target_user, FALSE);

      return ['success' => TRUE, 'id' => $message->id(), 'message' => $this->mapMessage($message)];
    }
    catch (\Throwable $e) {
      $this->logger->error('Send failed: @message', ['@message' => $e->getMessage()]);
      return ['success' => FALSE, 'message' => 'Message could not be sent.'];
    }
  }

  /**
   * Sends one message to many patients on the chiropractor's behalf.
   *
   * Each recipient gets an independent Message entity, so one bad target does
   * not undo the rest of the batch: the caller is told exactly which recipients
   * succeeded. `{first_name}` is replaced per recipient using the same
   * first-token-of-display-name rule the portal previews with, so the text that
   * is sent cannot drift from the text that was previewed.
   *
   * @param array $uids
   *   Recipient user IDs. Duplicates, zeroes and the sender's own id are dropped.
   * @param string $text
   *   The body, possibly containing `{first_name}` placeholders.
   * @param array $attachment_fids
   *   File IDs attached to every send.
   *
   * @return array
   *   ['success' => bool, 'sent' => array, 'failed' => array, 'message' => string]
   *   where each `sent` entry is ['uid' => int, 'id' => int, 'message' => array]
   *   and each `failed` entry is ['uid' => int, 'reason' => string].
   */
  public function sendMassMessage(array $uids, string $text, array $attachment_fids = []): array {
    $account = $this->current_user;
    if (!$account->isAuthenticated()) {
      return ['success' => FALSE, 'message' => 'Unauthenticated'];
    }

    if (!$this->isChiropractor()) {
      return ['success' => FALSE, 'message' => 'Only a chiropractor can send a bulk message.'];
    }

    if (!$this->canSend()) {
      return [
        'success' => FALSE,
        'message' => 'Your account cannot send messages. Contact your clinic to re-enable messaging.',
      ];
    }

    $recipients = [];
    foreach ($uids as $uid) {
      $uid = (int) $uid;
      // The sender is filtered out server-side as well as in the UI, so a
      // hand-built request cannot mail the chiropractor their own notice.
      if ($uid > 0 && $uid !== (int) $account->id()) {
        $recipients[$uid] = TRUE;
      }
    }
    if (!$recipients) {
      return ['success' => FALSE, 'message' => 'No recipients selected'];
    }
    if (count($recipients) > self::MASS_RECIPIENT_LIMIT) {
      return [
        'success' => FALSE,
        'message' => 'A bulk message is limited to ' . self::MASS_RECIPIENT_LIMIT . ' recipients',
      ];
    }

    $fids = [];
    foreach ($attachment_fids as $fid) {
      $fid = (int) $fid;
      if ($fid > 0) {
        $fids[$fid] = TRUE;
      }
    }
    $fids = array_keys($fids);

    // Sanitised once, then personalised per recipient. Sanitising first means
    // a placeholder can never reintroduce markup that sanitising removed.
    $text = $this->sanitizeText($text);
    if ($text === '' && !$fids) {
      return ['success' => FALSE, 'message' => 'Message cannot be empty'];
    }

    $sent = [];
    $failed = [];
    foreach (array_keys($recipients) as $uid) {
      $recipient_name = $this->displayNameFor((int) $uid);
      if ($recipient_name === NULL) {
        $failed[] = ['uid' => (int) $uid, 'reason' => 'Recipient not found'];
        continue;
      }
      $result = $this->deliverMessage((int) $uid, $this->personalize($text, $recipient_name), $fids);
      if ($result['success']) {
        $sent[] = [
          'uid' => (int) $uid,
          'id' => $result['id'],
          'message' => $result['message'],
        ];
      }
      else {
        $failed[] = ['uid' => (int) $uid, 'reason' => (string) $result['message']];
      }
    }

    return [
      'success' => $sent !== [],
      'sent' => $sent,
      'failed' => $failed,
      'requested' => count($recipients),
      'message' => $sent
        ? sprintf('Sent to %d of %d recipients', count($sent), count($recipients))
        : 'No recipients could be reached',
    ];
  }

  /**
   * Substitutes `{first_name}` with the recipient's own first name token.
   */
  protected function personalize(string $text, string $name): string {
    $first = trim((string) strtok($name, ' '));
    if ($first === '') {
      return $text;
    }
    return (string) preg_replace('/\{first_name\}/i', $first, $text);
  }

  /**
   * Display name for a user id, or NULL when the account no longer exists.
   */
  protected function displayNameFor(int $uid): ?string {
    $user = $this->entity_type_manager->getStorage('user')->load($uid);
    if (!$user) {
      return NULL;
    }
    return (string) ($user->getDisplayName() ?: $user->getAccountName());
  }

  /**
   * Creates and saves one Message to a recipient, with any number of files.
   *
   * Deliberately narrower than sendMessage(): the caller has already run the
   * permission checks, and this returns a failure instead of throwing, which is
   * what lets the bulk loop carry on past a single bad target. Missing file IDs
   * are skipped rather than failing the send, so a dead upload does not cost
   * the recipient their message.
   *
   * @return array
   *   ['success' => TRUE, 'id' => int, 'message' => array] on success, or
   *   ['success' => FALSE, 'message' => string] on failure.
   */
  protected function deliverMessage(int $target_uid, string $text, array $attachment_fids = []): array {
    $uid = (int) $this->current_user->id();
    $target_user = $this->entity_type_manager->getStorage('user')->load($target_uid);
    if (!$target_user) {
      return ['success' => FALSE, 'message' => 'Recipient not found'];
    }

    $attachments = [];
    foreach ($attachment_fids as $fid) {
      $file = $this->entity_type_manager->getStorage('file')->load((int) $fid);
      if ($file) {
        $attachments[] = ['target_id' => $file->id()];
      }
    }

    if ($text === '' && !$attachments) {
      return ['success' => FALSE, 'message' => 'Message cannot be empty'];
    }

    $build = [
      'contact_form' => 'message',
      'uid' => $uid,
      'field_from' => $uid,
      'field_to' => $target_uid,
      // Chiropractor sends to patient: patient_uid = recipient (patient).
      'field_patient_uid' => $this->isChiropractor() ? $target_uid : $uid,
    ];
    if ($text !== '') {
      $build['field_message'] = [
        'value' => $text,
        'format' => 'basic_html',
      ];
    }
    if ($attachments) {
      $build['field_attachments'] = $attachments;
    }

    try {
      $message = Message::create($build);
      $message->save();

      // Flagged against the recipient, so only they see it as unread.
      $this->setReadState($message, $target_user, FALSE);

      return [
        'success' => TRUE,
        'id' => (int) $message->id(),
        'message' => $this->mapMessage($message),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Bulk send to @uid failed: @error', [
        '@uid' => $target_uid,
        '@error' => $e->getMessage(),
      ]);
      return ['success' => FALSE, 'message' => 'Message could not be sent.'];
    }
  }

  /**
   * Persists an uploaded attachment under private:// for later message use.
   *
   * @param string $filename
   *   The client-side filename, used for the extension allowlist.
   * @param string $data
   *   The raw file bytes.
   *
   * @return array
   *   ['success' => TRUE, 'fid' => int, 'name' => string] or
   *   ['success' => FALSE, 'message' => string].
   */
  public function storeAttachment(string $filename, string $data): array {
    $account = $this->current_user;
    if (!$account->isAuthenticated()) {
      return ['success' => FALSE, 'message' => 'Unauthenticated'];
    }

    if (!$this->canSend()) {
      return [
        'success' => FALSE,
        'message' => 'Your account cannot send messages. Contact your clinic to re-enable messaging.',
      ];
    }

    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($extension, self::ALLOWED_ATTACHMENT_EXTENSIONS, TRUE)) {
      return ['success' => FALSE, 'message' => 'File type not allowed'];
    }

    $length = strlen($data);
    if ($length === 0) {
      return ['success' => FALSE, 'message' => 'Empty file'];
    }
    if ($length > self::MAX_ATTACHMENT_BYTES) {
      return ['success' => FALSE, 'message' => 'File is larger than 20 MB'];
    }

    // The basename strips any client-supplied directory components; control
    // characters would break the stream wrapper URI.
    $basename = $this->file_system->basename(trim($filename));
    $basename = preg_replace('/[\x00-\x1F\x7F]+/', '_', $basename);
    if ($basename === '' || $basename === '.') {
      return ['success' => FALSE, 'message' => 'Invalid filename'];
    }

    try {
      // writeData() persists the bytes, derives the MIME type, and returns the
      // saved file entity. FileExists::Rename makes a conflicting name unique
      // (foo_0.pdf).
      $file = $this->file_repository->writeData($data, 'private://' . $basename, FileExists::Rename);

      return [
        'success' => TRUE,
        'fid' => (int) $file->id(),
        'name' => $file->getFilename(),
        'size' => $length,
        'mime' => $file->getMimeType() ?: 'application/octet-stream',
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Attachment upload failed: @message', ['@message' => $e->getMessage()]);
      return ['success' => FALSE, 'message' => 'File could not be stored.'];
    }
  }

  /**
   * Resolves an attachment for download, or refuses.
   *
   * Files live under private:// and are otherwise unreachable, so a download is
   * allowed only when the current account is party to a message that references
   * the file. That check hides every other file on the site.
   *
   * @return array{name: string, mime: string, path: string}|null
   *   Download details when the current user is authorised, NULL otherwise.
   */
  public function attachmentFile(int $fid): ?array {
    $account = $this->current_user;
    $uid = $account->isAuthenticated() ? (int) $account->id() : 0;
    if (!$uid || $fid <= 0) {
      return NULL;
    }

    $file = $this->entity_type_manager->getStorage('file')->load($fid);
    if (!$file) {
      return NULL;
    }

    // The caller has to be a party to the message that references the file.
    //
    // This checks field_from/field_to rather than field_patient_uid because the
    // latter names the *patient*, so on a chiropractor's own message it is the
    // chiropractor's uid and on the patient's reply it is the patient's — neither
    // is the other party. Requiring that this account appears on either end of a
    // message carrying the fid proves it is in the thread that owns the file, and
    // still hides every other file on the site.
    $query = $this->entity_type_manager->getStorage('contact_message')->getQuery()
      ->accessCheck(FALSE)
      ->condition('contact_form', 'message')
      ->condition('field_attachments.target_id', $fid);
    $query->condition(
      $query->orConditionGroup()
        ->condition('field_from', $uid)
        ->condition('field_to', $uid)
    );
    $matches = $query->range(0, 1)->execute();
    if (!$matches) {
      return NULL;
    }

    $path = $this->file_system->realpath($file->getFileUri());
    if ($path === FALSE) {
      return NULL;
    }

    return [
      'name' => $file->getFilename(),
      'mime' => $file->getMimeType() ?: 'application/octet-stream',
      'path' => $path,
    ];
  }

  /**
   * Marks all messages in a conversation as read for the current user.
   *
   * @param int $target_uid
   *   The other user in the conversation.
   *
   * @return int
   *   Number of messages marked as read.
   */
  public function markConversationRead(int $target_uid): int {
    $account = $this->current_user;
    $uid = $account->isAuthenticated() ? (int) $account->id() : 0;
    if (!$uid) {
      return 0;
    }

    $storage = $this->entity_type_manager->getStorage('contact_message');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('contact_form', 'message')
      ->condition('field_patient_uid', [$uid, $target_uid], 'IN')
      ->condition('field_from', $target_uid)
      ->condition('field_to', $uid)
      ->execute();

    if (!$ids) {
      return 0;
    }

    $count = 0;
    foreach ($storage->loadMultiple($ids) as $msg) {
      if ((int) ($msg->get('field_to')->target_id ?? 0) !== $uid) {
        continue;
      }
      $this->setReadState($msg, $account, TRUE);
      $count++;
    }

    return $count;
  }

  /**
   * Marks a single message as read for the current user.
   *
   * The caller must be the recipient, and must actually be party to the thread
   * the message belongs to. Without the second check any authenticated account
   * could mark any message read by guessing its sequential id, since message ids
   * are dense and there is no per-id access control behind the endpoint.
   *
   * @param int $message_id
   *   The message ID to mark as read.
   *
   * @return bool
   *   TRUE if the message was found and marked, FALSE otherwise.
   */
  public function markMessageRead(int $message_id): bool {
    $account = $this->current_user;
    $uid = $account->isAuthenticated() ? (int) $account->id() : 0;
    if (!$uid) {
      return FALSE;
    }

    $storage = $this->entity_type_manager->getStorage('contact_message');
    $message = $storage->load($message_id);
    if (!$message || $message->bundle() !== 'message') {
      return FALSE;
    }

    // Only the recipient can open a message.
    if ((int) ($message->get('field_to')->target_id ?? 0) !== $uid) {
      return FALSE;
    }

    // Party to the thread: the counterpart must be the provider or someone the
    // caller already has a conversation with.
    $from_uid = (int) ($message->get('field_from')->target_id ?? 0);
    if (!$this->hasThreadWith($uid, $from_uid)) {
      return FALSE;
    }

    $this->setReadState($message, $account, TRUE);

    return TRUE;
  }

  /**
   * Whether two accounts have any message between them.
   */
  protected function hasThreadWith(int $uid, int $other_uid): bool {
    if (!$uid || !$other_uid || $uid === $other_uid) {
      return FALSE;
    }

    $ids = $this->entity_type_manager->getStorage('contact_message')->getQuery()
      ->accessCheck(FALSE)
      ->condition('contact_form', 'message')
      ->condition('field_patient_uid', [$uid, $other_uid], 'IN')
      ->condition('field_from', [$uid, $other_uid], 'IN')
      ->condition('field_to', [$uid, $other_uid], 'IN')
      ->range(0, 1)
      ->execute();

    return (bool) $ids;
  }

  /**
   * The signed-in account's provider uid, from user.field_chiropractor.
   *
   * @return int
   *   0 when unset.
   */
  protected function providerUid(): int {
    $uid = (int) $this->current_user->id();
    if (!$uid) {
      return 0;
    }

    // current_user is an AccountProxy, which is not a content entity and has no
    // hasField()/get(). The field has to be read off a loaded User.
    $user = $this->entity_type_manager->getStorage('user')->load($uid);
    if (!$user || !$user->hasField('field_chiropractor')) {
      return 0;
    }
    $field = $user->get('field_chiropractor');
    return $field->isEmpty() ? 0 : (int) $field->target_id;
  }

  /**
   * The blank part of a conversation, shared by the list and the thread.
   */
  protected function conversationShell(User $partner): array {
    return [
      'partner_uid' => (int) $partner->id(),
      'partner_name' => $partner->getDisplayName() ?: $partner->getAccountName(),
      'partner_role' => $this->getRoleLabel($partner),
      'partner_avatar' => $this->getAvatarUrl($partner),
      'messages' => [],
      'unread_count' => 0,
      'last_message_time' => NULL,
      'last_message_preview' => '',
      'last_message_time_formatted' => '',
    ];
  }

  /**
   * Adds the derived display fields.
   *
   * Applied by both read methods so the two endpoints return byte-identical key
   * sets.
   *
   * @param array $conversation
   *   A conversation array, passed by reference and modified in place.
   */
  protected function formatConversation(array &$conversation): void {
    $time = $conversation['last_message_time'] ?? NULL;
    $conversation['last_message_time'] = $time ? (int) $time : NULL;
    $conversation['last_message_time_formatted'] = $time ? date('M d · H:i', (int) $time) : '';
    $conversation['unread_count'] = (int) $conversation['unread_count'];
  }

  /**
   * Maps a contact_message to the frontend message format.
   */
  protected function mapMessage(Message $msg): array {
    // The stored value may be plain text or real markup. Rich bodies are
    // rendered through the shared processed-text pipeline and delivered as
    // sanitised HTML; anything not in the trusted-format allowlist is escaped.
    $body = $this->messageBody($msg);
    $from_uid = (int) ($msg->get('field_from')->target_id ?? 0);
    $is_mine = $from_uid === (int) $this->current_user->id();

    return [
      'id' => (int) $msg->id(),
      'from' => $is_mine ? 'patient' : 'doctor',
      'time' => date('D, m/d · H:i', $this->messageTime($msg)),
      'text' => $body['text'],
      'html' => $body['html'],
      'attachments' => $this->mapAttachments($msg),
      // Read state belongs to the recipient. A message the reader sent has no
      // unread state of its own — they wrote it — so it reports read without
      // asking the flag service on their behalf.
      'is_read' => $is_mine ? TRUE : $this->isMessageRead($msg, $this->current_user),
    ];
  }

  /**
   * The plain-text and rendered versions of a message body.
   *
   * @return array{text: string, html: string}
   *   The plain `text` for search and previews, and the sanitised `html` for
   *   rendering.
   */
  protected function messageBody(Message $msg): array {
    $values = $msg->get('field_message')->getValue();
    $raw = (string) ($values[0]['value'] ?? '');
    $format = (string) ($values[0]['format'] ?? 'basic_html');

    if (in_array($format, self::RENDERABLE_FORMATS, TRUE)) {
      $elements = [
        '#type' => 'processed_text',
        '#text' => $raw,
        '#format' => $format,
      ];
      return [
        'text' => $this->plainBody($raw),
        'html' => (string) $this->renderer->renderInIsolation($elements),
      ];
    }

    // Unknown/untrusted format: never filter through whatever the row claims.
    // Deliver the value as escaped plain text, the same shape either way.
    $plain = $this->plainBody($raw);
    return [
      'text' => $plain,
      'html' => nl2br(Html::escape($plain)),
    ];
  }

  /**
   * A display-ready plain-text form of stored body markup.
   */
  protected function plainBody(string $raw): string {
    $plain = strip_tags($raw);
    // Drop control characters, keep newlines and tabs.
    $plain = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $plain);
    // Collapse runs of blank lines so long replies do not render as tall gaps.
    $plain = preg_replace("/\n{3,}/", "\n\n", $plain);
    return trim($plain);
  }

  /**
   * The attachments on a message, as the frontend renders them.
   *
   * @return array
   *   List of ['fid', 'name', 'size', 'mime'] arrays, empty when none.
   */
  protected function mapAttachments(Message $msg): array {
    $list = $msg->get('field_attachments')->getValue() ?: [];
    if (!$list) {
      return [];
    }

    $fids = [];
    foreach ($list as $item) {
      $fid = (int) ($item['target_id'] ?? 0);
      if ($fid > 0) {
        $fids[] = $fid;
      }
    }
    if (!$fids) {
      return [];
    }

    $files = $this->entity_type_manager->getStorage('file')->loadMultiple($fids);
    $attachments = [];
    // Keep the order the reference list used.
    foreach ($list as $item) {
      $fid = (int) ($item['target_id'] ?? 0);
      if ($fid <= 0 || !isset($files[$fid])) {
        continue;
      }
      $file = $files[$fid];
      $attachments[] = [
        'fid' => (int) $file->id(),
        'name' => $file->getFilename(),
        'size' => (int) $file->getSize(),
        'mime' => $file->getMimeType() ?: 'application/octet-stream',
      ];
    }
    return $attachments;
  }

  /**
   * A message's timestamp.
   *
   * Reads the base `created` field rather than EntityBase::getCreatedTime(),
   * which Message does not implement. A message saved without a `created` value
   * falls back to now rather than fataling.
   */
  protected function messageTime(Message $msg): int {
    $created = $msg->get('created')->value ?? NULL;
    return $created ? (int) $created : time();
  }

  /**
   * Checks whether a message is read *by a given account*.
   *
   * A flagging on the message means that account has not opened it yet.
   *
   * Takes the entity rather than an id so mapping a thread of N messages does
   * not reload all N of them just to ask about their flags.
   *
   * @param \Drupal\contact\Entity\Message $message
   *   The loaded message to check.
   * @param \Drupal\Core\Session\AccountInterface|\Drupal\user\UserInterface|null $account
   *   Whose read state to read. Passing NULL reports unread, since there is
   *   nobody whose read state could mean it has been read.
   */
  protected function isMessageRead(Message $message, $account): bool {
    if (!$account || !$account->id()) {
      return FALSE;
    }

    $flag = $this->flag_service->getFlagById(self::FLAG_ID);
    if (!$flag) {
      // No flag configured: nothing is ever flagged, so nothing is ever unread.
      return TRUE;
    }

    return !$this->flag_service->getFlagging($flag, $message, $account);
  }

  /**
   * Sets read state for a specific account.
   *
   * Takes the entity rather than an id so callers that already hold the loaded
   * message do not trigger a redundant reload.
   *
   * @param \Drupal\contact\Entity\Message $message
   *   The message whose read state is being set.
   * @param \Drupal\Core\Session\AccountInterface|\Drupal\user\UserInterface|null $account
   *   Whose read state to set.
   * @param bool $read
   *   TRUE to mark read (unflag), FALSE to mark unread (flag).
   */
  protected function setReadState(Message $message, $account, bool $read): void {
    if (!$account || !$account->id()) {
      return;
    }

    $flag = $this->flag_service->getFlagById(self::FLAG_ID);
    if (!$flag) {
      return;
    }

    $flagging = $this->flag_service->getFlagging($flag, $message, $account);
    if ($read) {
      if ($flagging) {
        $this->flag_service->unflag($flag, $message, $account);
      }
    }
    elseif (!$flagging) {
      $this->flag_service->flag($flag, $message, $account);
    }
  }

  /**
   * Gets a user-friendly role label.
   */
  protected function getRoleLabel(User $user): string {
    $roles = $user->getRoles();
    if (in_array('chiropractor_active_', $roles, TRUE) || in_array('chiropractor_inactive_', $roles, TRUE)) {
      return 'Your ChiroThin provider';
    }
    if (in_array('enrolled_patient', $roles, TRUE) || in_array('archived_patient', $roles, TRUE)) {
      return 'Patient';
    }
    return 'Clinic staff';
  }

  /**
   * Gets avatar URL for a user (using pravatar for now).
   *
   * NOTE: the frontend deliberately does not render this. `InitialsAvatar`
   * derives initials locally because a remote avatar service would leak every
   * portal visit to a third party and would need `remotePatterns` in
   * next.config.ts. Kept on the payload so a stored user_photo can be returned
   * without another contract change.
   */
  protected function getAvatarUrl(User $user): string {
    return 'https://i.pravatar.cc/64?u=' . $user->id();
  }

  /**
   * Truncates text for preview.
   */
  protected function truncateText(string $text, int $length): string {
    $plain = strip_tags($text);
    $plain = preg_replace('/\s+/', ' ', $plain);
    return strlen($plain) > $length ? substr($plain, 0, $length) . '...' : $plain;
  }

  /**
   * Normalises a submitted body to plain text.
   *
   * A programmatic Message::save() never runs text filters, so tagging a value
   * 'basic_html' stores whatever was submitted and hands it straight back to the
   * renderer. Anything markup-shaped is stripped here, at the only point that
   * sees untrusted input, and newlines are kept because the design renders the
   * body as `whitespace-pre-line`.
   */
  protected function sanitizeText(string $text): string {
    $text = strip_tags($text);
    // Drop control characters, keep newlines and tabs.
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    // Collapse runs of blank lines so long replies do not render as tall gaps.
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
  }

}
