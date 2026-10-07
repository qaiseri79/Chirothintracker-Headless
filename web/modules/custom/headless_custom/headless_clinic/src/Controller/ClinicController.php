<?php
declare(strict_types=1);
namespace Drupal\headless_clinic\Controller;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Site\Settings;
use Drupal\headless_clinic\ClinicException;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};

/** Thin transport; scope, ownership, validation and writes live in ClinicService. */
final class ClinicController extends ControllerBase {
  public function handle(Request $request, string $action, string $id = '0'): JsonResponse {
    try {
      $body = [];
      if (!$request->isMethodSafe()) {
        $allowed = array_merge([$request->getSchemeAndHttpHost()], Settings::get('headless_subscriptions_allowed_origins', []));
        $origin = $request->headers->get('Origin');
        if (($origin !== NULL && !in_array($origin, $allowed, TRUE)) || $request->headers->get('Sec-Fetch-Site') === 'cross-site') throw new ClinicException('This request origin is not allowed.', 403);
        if (!str_starts_with(strtolower($request->headers->get('Content-Type', '')), 'application/json')) throw new ClinicException('Provide a JSON request.', 415);
        if (strlen($request->getContent()) > 8192) throw new ClinicException('The request is too large.', 413);
        try { $body = json_decode($request->getContent(), TRUE, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new ClinicException('Provide a valid JSON object.'); }
        if (!is_array($body) || !str_starts_with(ltrim($request->getContent()), '{')) throw new ClinicException('Provide a JSON object.');
      }
      $service = \Drupal::service('headless_clinic.clinic');
      $actor = $this->currentUser();
      $result = match ($action) {
        'snapshot' => $service->snapshot($actor),
        'doctor_create' => $service->createDoctor($actor, $body),
        'doctor_update' => $service->updateDoctor($actor, (int) $id, $body),
        'doctor_access' => $service->setDoctorAccess($actor, (int) $id, $body),
        'location_create' => $service->saveLocation($actor, NULL, $body),
        'location_update' => $service->saveLocation($actor, (int) $id, $body),
        default => throw new ClinicException('Unknown clinic operation.', 404),
      };
      return $this->json($result, $action === 'doctor_create' || $action === 'location_create' ? 201 : 200);
    } catch (ClinicException $e) {
      return $this->json(['message' => $e->getMessage(), 'fields' => $e->fields ?: NULL], $e->status);
    } catch (\Throwable $e) {
      \Drupal::logger('headless_clinic')->error('Clinic action @action failed for @uid (@class, line @line).', ['@action' => $action, '@uid' => $this->currentUser()->id(), '@class' => get_class($e), '@line' => $e->getLine()]);
      return $this->json(['message' => 'The clinic change could not be completed. Please try again.'], 500);
    }
  }
  private function json(array $data, int $status): JsonResponse {
    return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store']);
  }
}
