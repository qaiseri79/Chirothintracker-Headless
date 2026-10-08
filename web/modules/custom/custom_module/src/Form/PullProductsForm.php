<?php

namespace Drupal\custom_module\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Provides a form to pull chironutraceutical products for a given user.
 */
class PullProductsForm extends FormBase {

  /**
   * Constructs a PullProductsForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'pull_products_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $uid = $this->getRequest()->query->get('uid');

    if (!$uid) {
      return $form;
    }

    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user) {
      return $form;
    }

    $has_pulled = (bool) $user->get('field_products_pulled')->value;

    if ($has_pulled) {
      $this->messenger()->addWarning($this->t('You have already pulled the products. You cannot pull them again.'));
    }

    $fulfillment_type = self::getFulfillmentType((int) $uid);

    if ($fulfillment_type === NULL) {
      $this->messenger()->addError($this->t('This user needs to select a Product Fulfillment Type on their clinic before products can be pulled.'));
    }

    $products  = $this->getAvailableProducts($fulfillment_type);
    $pullable  = array_filter($products, fn($p) => $p['has_vars']);
    $skippable = array_filter($products, fn($p) => !$p['has_vars']);

    $form['info'] = [
      '#type' => 'container',
    ];

    $form['info']['summary'] = [
      '#markup' => '<h3>' . $this->t('Product Pull Summary') . '</h3>',
    ];

    $form['info']['stats'] = [
      '#theme' => 'item_list',
      '#items' => [
        $this->t('<strong>@count</strong> total products found', ['@count' => count($products)]),
        $this->t('<strong>@count</strong> products will be pulled', ['@count' => count($pullable)]),
        $this->t('<strong>@count</strong> products will be skipped (no variations)', ['@count' => count($skippable)]),
      ],
    ];

    if (!empty($products)) {
      $form['info']['product_table'] = [
        '#type'    => 'table',
        '#caption' => $this->t('Products available for pull'),
        '#header'  => [
          $this->t('Product Name'),
          $this->t('Variations'),
          $this->t('Status'),
        ],
        '#rows'  => array_map(fn($p) => [
          $p['title'],
          $p['variations'],
          $p['has_vars'] ? $this->t('Will be pulled') : $this->t('Will be skipped'),
        ], $products),
        '#empty' => $this->t('No products found.'),
      ];
    }

    $form['submit'] = [
      '#type'     => 'submit',
      '#value'    => $this->t('Pull @count Products', ['@count' => count($pullable)]),
      '#disabled' => $has_pulled || $fulfillment_type === NULL || empty($pullable),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = $this->getRequest()->query->get('uid');

    if (!$uid) {
      $this->messenger()->addError($this->t('User ID not found!'));
      return;
    }

    if (self::getFulfillmentType((int) $uid) === NULL) {
      $this->messenger()->addError($this->t('This user needs to select a Product Fulfillment Type on their clinic before products can be pulled.'));
      return;
    }

    $pids = $this->getAvailableProductIds();

    if (empty($pids)) {
      $this->messenger()->addWarning($this->t('No products available to pull.'));
      return;
    }

    $batch = [
      'title'        => $this->t('Pulling Products...'),
      'init_message' => $this->t('Starting product pull...'),
      'finished'     => [__CLASS__, 'batchFinished'],
      'operations'   => array_map(fn($pid) => [[__CLASS__, 'processPullProduct'], [$pid, $uid]], $pids),
    ];

    batch_set($batch);
  }

  /**
   * Batch operation callback to duplicate a product and its variations.
   *
   * Uses loadUnchanged() to bypass the static entity cache, preventing the
   * original product from being mutated by Commerce's variation back-reference
   * logic during the batch. The static cache is reset at the end of each
   * operation so subsequent batch items cannot see stale in-memory state.
   *
   * @param int $pid
   *   The product ID to duplicate.
   * @param int $uid
   *   The user ID who is pulling the product.
   * @param array $context
   *   The batch context array passed by reference.
   */
  public static function processPullProduct(int $pid, int $uid, array &$context): void {
    // DEPRECATED: With the multi-merchant shared catalog architecture, we no
    // longer clone products per doctor. The master product remains as-is, and
    // clinic-specific settings are handled via ProductOverride entities in headless_commerce.
    // This function is kept for historical reference or migration purposes
    // but should no longer actively clone entities.
    $storage = \Drupal::entityTypeManager()->getStorage('commerce_product');
    $product = $storage->loadUnchanged($pid);

    if (!$product) {
      return;
    }

    $title = $product->getTitle();

    // In the future, this is where we would automatically create an empty
    // ProductOverride for the master variations into the user's specific store,
    // rather than cloning the actual product and variation entities.

    $context['results']['uid']      = $uid;
    $context['results']['pulled'][] = $title . ' (Override Linked)';
    $context['message'] = t('Linking <strong>@title</strong>...', ['@title' => $title]);
  }

  /**
   * Batch finished callback.
   *
   * @param bool $success
   *   Whether the batch completed without errors.
   * @param array $results
   *   Results accumulated during batch processing.
   * @param array $operations
   *   Any unprocessed operations, in case of failure.
   */
  public static function batchFinished(bool $success, array $results, array $operations): void {
    if (!$success) {
      \Drupal::messenger()->addError(t('Some errors occurred during the product pull.'));
      return;
    }

    if (!empty($results['skipped'])) {
      \Drupal::messenger()->addWarning(t('Skipped (no variations): @list', [
        '@list' => implode(', ', $results['skipped']),
      ]));
    }

    $uid = $results['uid'] ?? NULL;

    if (!empty($results['pulled']) && $uid) {
      $user = User::load($uid);
      if ($user) {
        $user->set('field_products_pulled', 1);
        $user->save();
        \Drupal::messenger()->addMessage(t('All products pulled successfully for User ID: @uid!', ['@uid' => $uid]));
      }
    }
    else {
      \Drupal::messenger()->addWarning(t('No products were pulled. field_products_pulled was not updated.'));
    }

    (new RedirectResponse('/admin/people/active-subscribers'))->send();
  }

  /**
   * Gets the product fulfillment type configured on the user's clinic.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return string|null
   *   The fulfillment type ('shipping', 'pickup' or 'both'), or NULL if the
   *   user, their clinic, or the field is missing or empty.
   */
  protected static function getFulfillmentType(int $uid): ?string {
    $user = User::load($uid);
    if (!$user || $user->get('field_clinic')->isEmpty()) {
      return NULL;
    }

    $clinic = $user->get('field_clinic')->entity;
    if (!$clinic || !$clinic->hasField('field_fulfillment_type') || $clinic->get('field_fulfillment_type')->isEmpty()) {
      return NULL;
    }

    return $clinic->get('field_fulfillment_type')->value;
  }

  /**
   * Filters product variations according to a clinic's fulfillment type.
   *
   * @param array $variations
   *   The product variation entities to filter.
   * @param string|null $fulfillment_type
   *   The clinic's fulfillment type ('shipping', 'pickup', 'both' or NULL).
   *
   * @return array
   *   The filtered list of variations.
   */
  protected static function filterVariationsByFulfillment(array $variations, ?string $fulfillment_type): array {
    return array_filter($variations, function ($variation) use ($fulfillment_type) {
      return match ($variation->bundle()) {
        'chironutraceutical_variations' => $fulfillment_type !== 'pickup',
        'chironutraceutical_ns' => $fulfillment_type !== 'shipping',
        default => TRUE,
      };
    });
  }

  /**
   * Builds a SKU prefix from the initials of the user's clinic title.
   *
   * Falls back to the user's display name if no clinic is found.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return string
   *   The uppercase initials string, e.g. "OLCAWL".
   */
  protected static function buildSkuPrefix(int $uid): string {
    $user = User::load($uid);
    if (!$user) {
      return 'USER-' . $uid;
    }

    $clinic_field = $user->get('field_clinic');
    $clinic_title = !$clinic_field->isEmpty() ? ($clinic_field->entity?->get('title')->value ?? '') : '';

    return self::initialsFromTitle($clinic_title ?: $user->getDisplayName());
  }

  /**
   * Extracts uppercase initials from each word in a given string.
   *
   * @param string $title
   *   The string to extract initials from.
   *
   * @return string
   *   The uppercase initials, e.g. "One Light Clinic" returns "OLC".
   */
  protected static function initialsFromTitle(string $title): string {
    return implode('', array_map(
      fn($word) => strtoupper($word[0]),
      array_filter(preg_split('/\s+/', trim($title)))
    ));
  }

  /**
   * Returns UIDs of all users with the administrator role.
   *
   * @return array
   *   An array of administrator user IDs.
   */
  private function getAdminUids(): array {
    return array_values(
      $this->entityTypeManager->getStorage('user')->getQuery()
        ->condition('roles', 'administrator')
        ->accessCheck(FALSE)
        ->execute()
    );
  }

  /**
   * Returns product IDs of published chironutraceutical products owned by admins.
   *
   * @return array
   *   An array of product IDs.
   */
  private function getAvailableProductIds(): array {
    $admin_uids = $this->getAdminUids();
    if (empty($admin_uids)) {
      return [];
    }

    return array_values(
      $this->entityTypeManager->getStorage('commerce_product')->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'chironutraceutical')
        ->condition('uid', $admin_uids, 'IN')
        ->condition('status', 1)
        ->execute()
    );
  }

  /**
   * Returns a structured list of available products with variation counts.
   *
   * @param string|null $fulfillment_type
   *   The clinic's fulfillment type ('shipping', 'pickup', 'both' or NULL),
   *   used to filter which variations are counted as pullable.
   *
   * @return array
   *   An array of associative arrays with keys: title, variations, has_vars.
   */
  private function getAvailableProducts(?string $fulfillment_type): array {
    $pids = $this->getAvailableProductIds();
    if (empty($pids)) {
      return [];
    }

    $products = $this->entityTypeManager->getStorage('commerce_product')->loadMultiple($pids);

    return array_map(function ($product) use ($fulfillment_type) {
      $variation_count = count(self::filterVariationsByFulfillment($product->getVariations(), $fulfillment_type));
      return [
        'title'      => $product->getTitle(),
        'variations' => $variation_count,
        'has_vars'   => $variation_count > 0,
      ];
    }, $products);
  }

}