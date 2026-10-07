<?php

namespace Drupal\headless_messages\Controller;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Drupal\headless_messages\Service\MessagesService;

/**
 * Controller for the headless messages endpoints.
 */
class MessagesController extends ControllerBase {

  /**
   * Ceiling on attachments in one bulk send, matching the composer's own limit.
   */
  const MASS_MAX_UPLOADS = 10;

  /**
   * The messages service.
   *
   * @var \Drupal\headless_messages\Service\MessagesService
   */
  protected $messagesService;

  /**
   * Constructs a MessagesController object.
   *
   * @param \Drupal\headless_messages\Service\MessagesService $messages_service
   *   The messages service.
   */
  public function __construct(MessagesService $messages_service) {
    $this->messagesService = $messages_service;
  }

  /**
   * Creates a new controller instance.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('headless_messages.messages_service')
    );
  }

  /**
   * {@inheritdoc}
   *
   * Lists conversations on GET and sends on POST.
   *
   * These used to be two routes with an identical path. Drupal matches one route
   * per path, so `conversations` won for every method: a POST came back with the
   * conversation list and the message was never written, which surfaced in the
   * frontend as a send that failed for every account. One route that branches on
   * the method is what makes both halves reachable.
   */
  public function messages(Request $request): JsonResponse {
    if ($request->getMethod() === 'POST') {
      return $this->send($request);
    }
    if ($request->query->getBoolean('unread_count')) {
      return new JsonResponse(['unread_count' => $this->messagesService->unreadCount()]);
    }
    return $this->conversations($request->query->getBoolean('summary'));
  }

  /**
   * {@inheritdoc}
   */
  public function conversations(bool $summaryOnly = FALSE): JsonResponse {
    return new JsonResponse($this->messagesService->getConversations($summaryOnly));
  }

  /**
   * {@inheritdoc}
   */
  public function thread(int $target_uid): JsonResponse {
    $thread = $this->messagesService->getThread($target_uid);
    if (!$thread) {
      return new JsonResponse(['error' => 'not_found'], JsonResponse::HTTP_NOT_FOUND);
    }
    return new JsonResponse($thread);
  }

  /**
   * {@inheritdoc}
   */
  public function send(Request $request): JsonResponse {
    $content_type = $request->headers->get('Content-Type') ?? '';
    if (str_contains($content_type, 'multipart/form-data')) {
      $text = (string) ($request->request->get('text') ?? '');
      $target_uid = (int) ($request->request->get('target_uid') ?? 0);
      $attachment_fid = 0;
      $files = $request->files->get('files', []);
      if ($files && isset($files['attachment'])) {
        $attachment = $files['attachment'];
        if ($attachment->isValid()) {
          $data = file_get_contents($attachment->getPathname());
          $result = $this->messagesService->storeAttachment($attachment->getClientOriginalName(), $data);
          if ($result['success']) {
            $attachment_fid = $result['fid'];
          }
        }
      }
    }
    else {
      $data = json_decode($request->getContent(), TRUE);
      if (!is_array($data) || empty($data['target_uid'])) {
        return new JsonResponse(['error' => 'bad_request', 'message' => 'target_uid is required'], JsonResponse::HTTP_BAD_REQUEST);
      }
      $target_uid = (int) $data['target_uid'];
      $text = (string) ($data['text'] ?? '');
      $attachment_fid = (int) ($data['attachment_fid'] ?? 0);
    }

    if (!$this->messagesService->canSend()) {
      $reason = 'Your account cannot send messages. Contact your clinic to re-enable messaging.';
      return new JsonResponse(
        [
          'success' => FALSE,
          'error' => 'forbidden',
          'message' => $reason,
          'message_text' => $reason,
        ],
        JsonResponse::HTTP_FORBIDDEN
      );
    }

    $result = $this->messagesService->sendMessage($target_uid, $text, $attachment_fid);

    if ($result['success']) {
      return new JsonResponse($result, JsonResponse::HTTP_CREATED);
    }

    $reason = (string) ($result['message'] ?? '');
    $status = $reason === 'Recipient not found'
      ? JsonResponse::HTTP_NOT_FOUND
      : JsonResponse::HTTP_BAD_REQUEST;

    return new JsonResponse(
      $result + ['error' => 'send_failed', 'message_text' => $reason],
      $status
    );
  }

  /**
   * Bulk-send one message to many patients.
   *
   * Accepts multipart/form-data (uids as a JSON array, text, and repeated
   * `files[]` uploads) or JSON (uids, text, and existing attachment_fids).
   * Uploads are stored once and referenced by every send, rather than copied
   * per recipient, so ten attachments cost ten files rather than 10 x N.
   */
  public function massSend(Request $request): JsonResponse {
    $content_type = $request->headers->get('Content-Type') ?? '';
    $attachment_fids = [];
    $uploads = [];

    if (str_contains($content_type, 'multipart/form-data')) {
      $text = (string) ($request->request->get('text') ?? '');
      $raw_uids = (string) ($request->request->get('uids') ?? '');
      $decoded = json_decode($raw_uids, TRUE);
      $uids = is_array($decoded) ? $decoded : [];

      // `files[]` is what makes repeated keys survive to PHP; a bare `files`
      // key collapses to the last upload only.
      foreach ($request->files->get('files', []) as $file) {
        if ($file && $file->isValid()) {
          $uploads[] = $file;
        }
      }
    }
    else {
      $data = json_decode($request->getContent(), TRUE);
      if (!is_array($data)) {
        return new JsonResponse(['error' => 'bad_request', 'message' => 'Invalid request body'], JsonResponse::HTTP_BAD_REQUEST);
      }
      $text = (string) ($data['text'] ?? '');
      $uids = is_array($data['uids'] ?? NULL) ? $data['uids'] : [];
      $attachment_fids = is_array($data['attachment_fids'] ?? NULL) ? $data['attachment_fids'] : [];
    }

    if (!$uids) {
      return new JsonResponse(
        ['success' => FALSE, 'error' => 'bad_request', 'message' => 'At least one recipient is required'],
        JsonResponse::HTTP_BAD_REQUEST
      );
    }

    if (!$this->messagesService->isChiropractor()) {
      return new JsonResponse(
        [
          'success' => FALSE,
          'error' => 'forbidden',
          'message' => 'Only a chiropractor can send a bulk message.',
        ],
        JsonResponse::HTTP_FORBIDDEN
      );
    }

    // The UI caps the composer at 10 files; enforce the same ceiling here so
    // the limit is not something only the browser knows about.
    if (count($uploads) > self::MASS_MAX_UPLOADS) {
      return new JsonResponse(
        [
          'success' => FALSE,
          'error' => 'bad_request',
          'message' => 'A bulk message is limited to ' . self::MASS_MAX_UPLOADS . ' files',
        ],
        JsonResponse::HTTP_BAD_REQUEST
      );
    }

    $rejected = [];
    foreach ($uploads as $file) {
      $stored = $this->messagesService->storeAttachment(
        $file->getClientOriginalName(),
        (string) file_get_contents($file->getPathname())
      );
      if ($stored['success']) {
        $attachment_fids[] = $stored['fid'];
      }
      else {
        $rejected[] = [
          'name' => $file->getClientOriginalName(),
          'reason' => (string) $stored['message'],
        ];
      }
    }

    $result = $this->messagesService->sendMassMessage($uids, $text, $attachment_fids);
    if (!empty($rejected)) {
      $result['rejected_files'] = $rejected;
    }

    // A batch where nothing landed is a failure; a partial send is a success
    // carrying its own failure list, so the UI can report both.
    return new JsonResponse(
      $result + ['error' => $result['success'] ? NULL : 'mass_send_failed'],
      $result['success'] ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_BAD_REQUEST
    );
  }

  /**
   * Upload a file and return its fid.
   */
  public function uploadAttachment(Request $request): JsonResponse {
    $files = $request->files->get('files', []);
    $file = $files['attachment'] ?? NULL;

    if (!$file || !$file->isValid()) {
      return new JsonResponse(['success' => FALSE, 'message' => 'No valid file uploaded'], JsonResponse::HTTP_BAD_REQUEST);
    }

    $data = file_get_contents($file->getPathname());
    $result = $this->messagesService->storeAttachment($file->getClientOriginalName(), $data);

    if ($result['success']) {
      return new JsonResponse($result, JsonResponse::HTTP_CREATED);
    }

    return new JsonResponse($result, JsonResponse::HTTP_BAD_REQUEST);
  }

  /**
   * Download an attachment (authorised).
   */
  public function downloadAttachment(int $fid) {
    $fileInfo = $this->messagesService->attachmentFile($fid);
    if (!$fileInfo || empty($fileInfo['path'])) {
      return new JsonResponse(['error' => 'not_found'], JsonResponse::HTTP_NOT_FOUND);
    }

    $response = new BinaryFileResponse($fileInfo['path']);
    $response->headers->set('Content-Type', $fileInfo['mime']);
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $fileInfo['name'] . '"');
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function markRead(int $target_uid): JsonResponse {
    $count = $this->messagesService->markConversationRead($target_uid);
    return new JsonResponse(['success' => TRUE, 'marked_read' => $count]);
  }

  /**
   * {@inheritdoc}
   */
  public function markMessageRead(int $message_id): JsonResponse {
    $result = $this->messagesService->markMessageRead($message_id);
    return new JsonResponse(['success' => $result, 'marked_read' => $result ? 1 : 0], $result ? JsonResponse::HTTP_OK : JsonResponse::HTTP_FORBIDDEN);
  }

}
