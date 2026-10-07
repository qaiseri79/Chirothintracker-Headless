<?php

namespace Drupal\ctt_shop\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Batch form to create Chironutraceutical (Not Shippable) variations from
 * Chironutraceutical (Shippable) variations for all administrator-created
 * products.
 *
 * Source variation type : chironutraceutical_variations
 *   Label               : Chironutraceutical (Shippable)
 *
 * Target variation type : chironutraceutical_ns
 *   Label               : Chironutraceutical (Not Shippable)
 *
 * The new variation copies title and price from the source; "NS" is appended
 * to the original SKU.
 */
class ChironutraceuticalNotShippableBatchForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ctt_shop_chironutraceutical_not_shippable_batch_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#type' => 'markup',
      '#markup' => $this->t(
        '<p>Run a batch that creates <strong>Chironutraceutical (Not Shippable)</strong> '
        . 'variations from existing <strong>Chironutraceutical (Shippable)</strong> '
        . 'variations for every Chironutraceutical product created by an administrator.</p>'
        . '<p>The new variation keeps the same <strong>title</strong> and <strong>price</strong>. '
        . '<code>NS</code> is appended to the SKU (e.g. <code>ABC-123</code> → <code>ABC-123-NS</code>). '
        . 'Products that already have any Not Shippable variation are skipped entirely.</p>'
      ),
    ];

    // Build preview lists.
    $product_ids = $this->getAdminChironutraceuticalProductIds();
    $to_process    = [];
    $to_skip       = [];
    $no_shippable  = [];

    if (!empty($product_ids)) {
      /** @var \Drupal\commerce_product\Entity\ProductInterface[] $products */
      $products = \Drupal::entityTypeManager()
        ->getStorage('commerce_product')
        ->loadMultiple($product_ids);

      foreach ($products as $product) {
        $variations = $product->getVariations();

        $has_ns = array_filter($variations, fn($v) => $v->bundle() === 'chironutraceutical_ns');

        if (!empty($has_ns)) {
          $to_skip[] = $product;
          continue;
        }

        $shippable = array_filter($variations, fn($v) => $v->bundle() === 'chironutraceutical_variations');

        if (!empty($shippable)) {
          $to_process[] = ['product' => $product, 'variations' => $shippable];
        }
        else {
          $no_shippable[] = $product;
        }
      }
    }

    // Products to be processed
    $form['to_process_heading'] = [
      '#type' => 'markup',
      '#markup' => '<h3>' . $this->t('Products to be processed (@count)', ['@count' => \count($to_process)]) . '</h3>',
    ];

    if (empty($to_process)) {
      $form['to_process_empty'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('No products eligible for processing.') . '</p>',
      ];
    }
    else {
      $rows = [];
      foreach ($to_process as $item) {
        /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
        $product = $item['product'];
        $ns_skus = array_map(fn($v) => $v->getSku() . '-NS', $item['variations']);
        $rows[] = [
          ['data' => ['#type' => 'link', '#title' => $product->id(), '#url' => Url::fromRoute('entity.commerce_product.edit_form', ['commerce_product' => $product->id()])]],
          $product->getTitle(),
          implode(', ', $ns_skus),
        ];
      }

      $form['to_process_table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Product ID'),
          $this->t('Product Title'),
          $this->t('NS SKU(s) to be created'),
        ],
        '#rows' => $rows,
      ];
    }

    // Products to be skipped (already have NS variation)
    $form['to_skip_heading'] = [
      '#type' => 'markup',
      '#markup' => '<h3>' . $this->t('Products to be skipped — already have a Not Shippable variation (@count)', ['@count' => \count($to_skip)]) . '</h3>',
    ];

    if (empty($to_skip)) {
      $form['to_skip_empty'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('None.') . '</p>',
      ];
    }
    else {
      $rows = [];
      foreach ($to_skip as $product) {
        /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
        $ns_variations = array_filter($product->getVariations(), fn($v) => $v->bundle() === 'chironutraceutical_ns');
        $existing_skus = array_map(fn($v) => $v->getSku(), $ns_variations);
        $rows[] = [
          ['data' => ['#type' => 'link', '#title' => $product->id(), '#url' => Url::fromRoute('entity.commerce_product.edit_form', ['commerce_product' => $product->id()])]],
          $product->getTitle(),
          implode(', ', $existing_skus),
        ];
      }

      $form['to_skip_table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Product ID'),
          $this->t('Product Title'),
          $this->t('Existing NS SKU(s)'),
        ],
        '#rows' => $rows,
      ];
    }

    // Products with no Shippable variation (nothing to copy from)
    $form['no_shippable_heading'] = [
      '#type' => 'markup',
      '#markup' => '<h3>' . $this->t('Products with no Chironutraceutical (Shippable) variation (@count)', ['@count' => \count($no_shippable)]) . '</h3>',
    ];

    if (empty($no_shippable)) {
      $form['no_shippable_empty'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('None.') . '</p>',
      ];
    }
    else {
      $rows = [];
      foreach ($no_shippable as $product) {
        /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
        $rows[] = [
          ['data' => ['#type' => 'link', '#title' => $product->id(), '#url' => Url::fromRoute('entity.commerce_product.edit_form', ['commerce_product' => $product->id()])]],
          $product->getTitle(),
          $this->t('No Chironutraceutical (Shippable) variation found — nothing to copy from'),
        ];
      }

      $form['no_shippable_table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Product ID'),
          $this->t('Product Title'),
          $this->t('Note'),
        ],
        '#rows' => $rows,
      ];
    }

    $form['dry_run'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Dry run'),
      '#description' => $this->t('Preview what would be created without saving any changes.'),
      '#default_value' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run batch'),
      '#button_type' => 'primary',
      '#disabled' => empty($to_process),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $dry_run = (bool) $form_state->getValue('dry_run');

    $product_ids = $this->getAdminChironutraceuticalProductIds();

    if (empty($product_ids)) {
      $this->messenger()->addWarning($this->t('No administrator-created Chironutraceutical products were found.'));
      return;
    }

    $batch = (new BatchBuilder())
      ->setTitle($this->t('Creating Chironutraceutical (Not Shippable) variations'))
      ->setInitMessage($this->t('Starting batch…'))
      ->setProgressMessage($this->t('Processed @current of @total products.'))
      ->setFinishCallback([static::class, 'finishBatch']);

    foreach (array_chunk($product_ids, 10) as $chunk) {
      $batch->addOperation([static::class, 'processProductChunk'], [$chunk, $dry_run]);
    }

    batch_set($batch->toArray());
    $form_state->setRedirect('ctt_shop.ns_variations_batch');
  }

  /**
   * Batch operation: process a chunk of product IDs.
   *
   * For each product, iterates over Chironutraceutical (Shippable) variations
   * and creates a matching Chironutraceutical (Not Shippable) variation when
   * one does not already exist.
   */
  public static function processProductChunk(array $product_ids, bool $dry_run, array &$context): void {
    if (!isset($context['results']['created'])) {
      $context['results']['created'] = 0;
      $context['results']['skipped'] = 0;
      $context['results']['dry_run'] = $dry_run;
    }

    $product_storage = \Drupal::entityTypeManager()->getStorage('commerce_product');
    $variation_storage = \Drupal::entityTypeManager()->getStorage('commerce_product_variation');

    /** @var \Drupal\commerce_product\Entity\ProductInterface[] $products */
    $products = $product_storage->loadMultiple($product_ids);

    foreach ($products as $product) {
      // Skip the entire product if it already has any Chironutraceutical
      // (Not Shippable) variation — regardless of how it was created.
      $has_ns = array_filter(
        $product->getVariations(),
        fn($v) => $v->bundle() === 'chironutraceutical_ns'
      );

      if (!empty($has_ns)) {
        $context['results']['skipped']++;
        continue;
      }

      // Collect only Chironutraceutical (Shippable) variations to copy from.
      $shippable_variations = array_filter(
        $product->getVariations(),
        fn($v) => $v->bundle() === 'chironutraceutical_variations'
      );

      if (empty($shippable_variations)) {
        continue;
      }

      $context['results']['created']++;

      if ($dry_run) {
        continue;
      }

      foreach ($shippable_variations as $variation) {
        /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $ns_variation */
        $ns_variation = $variation_storage->create([
          'type'   => 'chironutraceutical_ns',
          'sku'    => $variation->getSku() . '-NS',
          'title'  => $variation->getTitle(),
          'price'  => $variation->getPrice(),
          'status' => $variation->isPublished(),
        ]);

        // Copy list price when present.
        if ($variation->hasField('list_price') && !$variation->get('list_price')->isEmpty()) {
          $ns_variation->set('list_price', $variation->get('list_price')->getValue());
        }

        $ns_variation->save();
        $product->addVariation($ns_variation);
      }

      $product->save();
    }

    $context['message'] = t('Processed @count products.', ['@count' => count($products)]);
  }

  /**
   * Batch finish callback.
   */
  public static function finishBatch(bool $success, array $results, array $operations): void {
    if (!$success) {
      \Drupal::messenger()->addError(t('The batch did not complete successfully.'));
      return;
    }

    $created = $results['created'] ?? 0;
    $skipped = $results['skipped'] ?? 0;
    $dry_run = !empty($results['dry_run']);

    if ($dry_run) {
      \Drupal::messenger()->addStatus(
        t('Dry run complete. @created product(s) would have Chironutraceutical (Not Shippable) variations created; @skipped product(s) already have a Not Shippable variation and would be skipped.', [
          '@created' => $created,
          '@skipped' => $skipped,
        ])
      );
    }
    else {
      \Drupal::messenger()->addStatus(
        t('Batch complete. Chironutraceutical (Not Shippable) variations created for @created product(s); @skipped product(s) already had a Not Shippable variation and were skipped.', [
          '@created' => $created,
          '@skipped' => $skipped,
        ])
      );
    }
  }

  /**
   * Returns IDs of Chironutraceutical products owned by administrator users.
   */
  protected function getAdminChironutraceuticalProductIds(): array {
    $admin_uids = \Drupal::entityQuery('user')
      ->accessCheck(FALSE)
      ->condition('roles', 'administrator')
      ->execute();

    if (empty($admin_uids)) {
      return [];
    }

    return array_values(
      \Drupal::entityQuery('commerce_product')
        ->accessCheck(FALSE)
        ->condition('type', 'chironutraceutical')
        ->condition('uid', array_values($admin_uids), 'IN')
        ->execute()
    );
  }

}
