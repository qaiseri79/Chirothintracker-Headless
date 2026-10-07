<?php

declare(strict_types=1);

namespace Drupal\headless_patients\Controller;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Drupal\Core\Controller\ControllerBase;
use Drupal\headless_patients\ClinicScope;
use Drupal\headless_patients\PatientSummaryService;
use Drupal\headless_patients\Exception\PatientsException;
use Drupal\headless_progress\ProgressLogWriter;
use Drupal\headless_progress\Exception\ProgressValidationException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/** Clinic-scoped reads and writes for the doctor summary. */
final class PatientSummaryController extends ControllerBase {

  public function __construct(private readonly PatientSummaryService $summary, private readonly ClinicScope $scope, private readonly ProgressLogWriter $progressWriter) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('headless_patients.summary'), $container->get('headless_patients.clinic_scope'), $container->get('headless_progress.log_writer'));
  }

  private function clinic(): int {
    return $this->scope->requireClinicId($this->currentUser());
  }

  public function roster(): JsonResponse {
    return $this->respond(fn()=>$this->summary->roster($this->clinic()));
  }

  public function section(int $user, Request $request): JsonResponse {
    return $this->respond(fn()=>$this->summary->section($this->clinic(), $user, (string) $request->query->get('section', 'logs'), max(0, (int) $request->query->get('offset', 0))));
  }

  public function update(int $user, Request $request): JsonResponse {
    return $this->respond(fn()=>$this->summary->update($this->clinic(), $user, $this->payload($request), (int) $this->currentUser()->id()));
  }

  public function note(int $user, Request $request): JsonResponse {
    return $this->respond(fn()=>$this->summary->note($this->clinic(), $user, $this->payload($request), (int) $this->currentUser()->id()));
  }

  public function session(int $user, Request $request): JsonResponse {
    return $this->respond(function () use ($user, $request) {
      $multipart = str_contains((string) $request->headers->get('Content-Type'), 'multipart/form-data');
      if ($multipart) {
        try {
          $data = json_decode((string) $request->request->get('payload'), TRUE, 16, JSON_THROW_ON_ERROR);

        }
        catch (\JsonException) {
          throw PatientsException::invalid(['body' => 'Invalid session request.']);
        }
      }
      else {
        $data = $this->payload($request);
      }
      if (!is_array($data)) {
        throw PatientsException::invalid(['body' => 'Invalid session request.']);
      }
      return $this->summary->session($this->clinic(), $user, $data, (int) $this->currentUser()->id(), $request->files->all('photos'));
    });
  }

  public function progress(int $user, Request $request): JsonResponse {
    try {
      $patient = $this->summary->requirePatient($this->clinic(), $user);
      $data = $this->payload($request);
      if (!is_array($data['fields'] ?? NULL) || !$data['fields']) {
        throw PatientsException::invalid(['fields' => 'Enter progress details.']);
      }
      return new JsonResponse($this->progressWriter->createForDoctor($patient, $data['fields'], (int) $this->currentUser()->id()), 201, ['Cache-Control' => 'private, no-store']);
    }
    catch (ProgressValidationException $error) {
      return new JsonResponse(['error' => 'invalid_fields', 'issues' => $error->getIssues()], 422, ['Cache-Control' => 'private, no-store']);
    }
    catch (PatientsException $error) {
      return $this->error($error);
    }
  }

  public function upload(int $user, Request $request): JsonResponse {
    return $this->respond(function () use ($user, $request) {
      $file = $request->files->get('file');
      if (!$file instanceof UploadedFile) {
        throw PatientsException::invalid(['file' => 'Choose a file.']);

      }return $this->summary->upload($this->clinic(), $user, $file, (int) $this->currentUser()->id());

    });
  }

  public function deleteAttachment(int $user, int $file): JsonResponse {
    return $this->respond(fn()=>$this->summary->deleteAttachment($this->clinic(), $user, $file));
  }

  public function file(int $user, int $file = 0): BinaryFileResponse|JsonResponse {
    try {
      return $this->binary($this->summary->file($this->clinic(), $user, $file, $file === 0), $file === 0);
    }
    catch (PatientsException $e) {
      return $this->error($e);
    }
  }

  public function photoFile(int $user, int $file): BinaryFileResponse|JsonResponse {
    try {
      return $this->binary($this->summary->photoFile($this->clinic(), $user, $file), TRUE);
    }
    catch (PatientsException $e) {
      return $this->error($e);
    }
  }

  public function photos(int $user): JsonResponse {
    return $this->respond(fn()=>$this->summary->photos($this->clinic(), $user));
  }

  public function updatePhotos(int $user, Request $request): JsonResponse {
    return $this->respond(function () use ($user, $request) {
      $multipart = str_contains((string) $request->headers->get('Content-Type'), 'multipart/form-data');
      if ($multipart) {
        try {
          $data = json_decode((string) $request->request->get('payload'), TRUE, 16, JSON_THROW_ON_ERROR);

        }
        catch (\JsonException) {
          throw PatientsException::invalid(['body' => 'Invalid photos request.']);
        }
      }
      else {
        $data = $this->payload($request);
      }
      if (!is_array($data)) {
        throw PatientsException::invalid(['body' => 'Invalid photos request.']);
      }
      $profileUpload = $request->files->get('profilePhoto');
      return $this->summary->updatePhotos($this->clinic(), $user, $data, (int) $this->currentUser()->id(), $request->files->all('photos'), $profileUpload instanceof UploadedFile ? $profileUpload : NULL);
    });
  }

  private function binary($entity, bool $inline): BinaryFileResponse {
    $path = \Drupal::service('file_system')->realpath($entity->getFileUri());
    if (!$path||!is_file($path)) {
      throw PatientsException::notFound('file');
    }
    $response = new BinaryFileResponse($path);
    $response->setContentDisposition($inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $entity->getFilename(), 'download');
    $response->headers->set('Content-Type', $entity->getMimeType());
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    return $response;
  }

  private function payload(Request $request): array {
    try {
      $data = json_decode($request->getContent(), TRUE, 16, JSON_THROW_ON_ERROR);

    }
    catch (\JsonException) {
      throw PatientsException::invalid(['body' => 'Invalid JSON request.']);
    }
    if (!is_array($data)||array_is_list($data)) {
      throw PatientsException::invalid(['body' => 'Expected a JSON object.']);

    }return $data;
  }

  private function error(PatientsException $e): JsonResponse {
    return new JsonResponse(['error' => $e->getMessage(), 'errors' => $e->getErrors()], $e->getStatusCode(), ['Cache-Control' => 'private, no-store']);
  }

  private function respond(callable $action): JsonResponse {
    try {
      return new JsonResponse($action(), 200, ['Cache-Control' => 'private, no-store']);

    }
    catch (PatientsException $e) {
      return $this->error($e);
    }
  }

}
