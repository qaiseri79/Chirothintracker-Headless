<?php

declare(strict_types=1);

namespace Drupal\headless_commerce\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\headless_clinic\ClinicException;

class CommerceController extends ControllerBase {

  public function handle(Request $request, string $action): JsonResponse {
    try {
      $actor = $this->currentUser();

      $body = [];
      if ($request->getMethod() === 'POST' || $request->getMethod() === 'PATCH') {
        try {
          $body = json_decode($request->getContent(), TRUE, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
          throw new ClinicException('Provide a valid JSON request body.', 400);
        }
      }

      $service = \Drupal::service('headless_commerce.commerce_service');
      $id = $request->attributes->get('id');

      $result = match ($action) {
        'payment_settings' => $service->savePaymentSettings($actor, $body),
        'fulfillment_settings' => $service->saveFulfillmentSettings($actor, $body),
        'catalog_read' => $service->readCatalog($actor),
        'catalog_override' => $service->saveCatalogOverride($actor, $body),
        'custom_products_read' => $service->readCustomProducts($actor),
        'custom_products_create' => $service->createCustomProduct($actor, $body),
        'custom_products_delete' => $service->deleteCustomProduct($actor, (int) $id),
        'add_to_cart' => $service->addToCart($actor, $body),
        default => throw new ClinicException('Unknown commerce operation.', 404),
      };

      return new JsonResponse($result, 200, ['Cache-Control' => 'private, no-store']);
    } catch (ClinicException $e) {
      return new JsonResponse(['message' => $e->getMessage(), 'fields' => $e->fields ?? NULL], $e->status, ['Cache-Control' => 'private, no-store']);
    } catch (\Throwable $e) {
      \Drupal::logger('headless_commerce')->error('Action @action failed: @message (@class, line @line).', [
        '@action' => $action,
        '@message' => $e->getMessage(),
        '@class' => get_class($e),
        '@line' => $e->getLine()
      ]);
      return new JsonResponse(['message' => 'The commerce change could not be completed. Please try again.'], 500, ['Cache-Control' => 'private, no-store']);
    }
  }

}
