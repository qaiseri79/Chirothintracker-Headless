<?php

namespace Drupal\commerce_product_restriction\Plugin\Commerce\ProductRestriction;

use Drupal\commerce_product_restriction\Plugin\ProductRestrictionPluginBase;
use Drupal\commerce_product_restriction\Plugin\ProductRestrictionPluginInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides product restriction by user role.
 *
 * @ProductRestrictionPlugin(
 *   id = "restrict_to_dates",
 *   label = @Translation("Restrict to specified date ranges"),
 *   category = @Translation("Dates"),
 *   entity_type = "commerce_product",
 *   weight = -1
 * )
 */
class DatesRestriction extends ProductRestrictionPluginBase implements ProductRestrictionPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'start' => NULL,
      'end' => NULL,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $start = $this->configuration['start'] ? implode(" ", $this->configuration['start']) : NULL;
    $end = $this->configuration['end'] ? implode(" ", $this->configuration['end']) : NULL;

    $form['start'] = [
      '#type' => 'datetime',
      '#title' => 'On sale from',
      '#default_value' => $start && strtotime($start) ? DrupalDateTime::createFromTimestamp(strtotime($start)) : NULL,
      '#required' => TRUE,
      '#date_time_format' => 'H:m',
      // Needed to avoid weird bug with datetime not havijng an object.
      '#element_validate' => [],
    ];

    $form['end'] = [
      '#type' => 'datetime',
      '#title' => 'Taken off sale',
      '#default_value' => $start && strtotime($end)  ? DrupalDateTime::createFromTimestamp(strtotime($end)) : NULL,
      '#required' => TRUE,
      '#date_time_format' => 'H:m',
      '#element_validate' => [],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    // @todo validate end date is after start date
    $values = $form_state->getValue($form['#parents']);
    $this->configuration['start'] = $values['start'];
    $this->configuration['end'] = $values['end'];
  }

  /**
   * Return.
   */
  public function getSaleStatus(EntityInterface $entity) {
    $this->assertEntity($entity);
    $now = new DrupalDateTime();
    $nextOnSale = [];
    $previousEndOfSale = [];

    // Hopefully in future we can have multiple date ranges
    // currently Daterange is not available in Form API
    // So limited to one set of dates for now.
    $date_ranges = [
      [
        'value' => $this->configuration['start'] ? implode("T", $this->configuration['start']) : NULL,
        'end_value' => $this->configuration['end'] ? implode("T", $this->configuration['end']) : NULL,
      ],
    ];

    foreach ($date_ranges as $date_range) {
      $start = $date_range['value'] ? new DrupalDateTime($date_range['value']) : NULL;
      $end = $date_range['end_value'] ? new DrupalDateTime($date_range['end_value']) : NULL;

      // If now is in between two date ranges, allow purchase.
      if ((!$start || $start->hasErrors() || $start <= $now) && (!$end || $end->hasErrors() || $end >= $now)) {
        return ['status' => 'on sale now'];
        // Otherwise gather future on sale dates.
      }
      elseif ($start && !$start->hasErrors() && $start > $now) {
        $nextOnSale[] = [
          'status' => 'on sale in future',
          'timestamp' => $start->getTimestamp(),
        ];
        // Otherwise gather previous off-sale dates.
      }
      elseif ($end && !$end->hasErrors() && $end < $now) {
        $previousEndOfSale[] = [
          'status' => 'no longer on sale',
          'timestamp' => $end->getTimestamp(),
        ];
      }
    }

    // If there's a future date for sale, return the soonest.
    if (count($nextOnSale) > 0) {
      usort($nextOnSale, function ($a, $b) {
        return $a['timestamp'] <=> $b['timestamp'];
      });
      reset($nextOnSale);
      return $nextOnSale[0];
    }

    // If there's a previous date where it went off sale, return most recent.
    if (count($previousEndOfSale) > 0) {
      usort($previousEndOfSale, function ($a, $b) {
        return $b['timestamp'] <=> $a['timestamp'];
      });
      reset($previousEndOfSale);
      return $previousEndOfSale[0];
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(EntityInterface $entity) {
    $this->assertEntity($entity);

    $saleStatus = $this->getSaleStatus($entity);
    return $saleStatus && $saleStatus['status'] == 'on sale now';
  }

  /**
   * {@inheritdoc}
   */
  public function accessErrorMessage($product_or_variation) {
    $saleStatus = $this->getSaleStatus($product_or_variation);
    if ($saleStatus['status'] == 'on sale in future') {
      return new TranslatableMarkup(
        "This product will go on sale @hence (@datetime).",
        [
          '@hence' => 'in ' . \Drupal::service('date.formatter')->formatInterval($saleStatus['timestamp'] - \Drupal::time()->getRequestTime()),
          '@datetime' => \Drupal::service('date.formatter')->format($saleStatus['timestamp'], 'long'),
        ]
      );
    }
    elseif ($saleStatus['status'] == 'no longer on sale') {
      return new TranslatableMarkup(
        "This product is no longer on sale. It ended sales @ago (@datetime).",
        [
          '@ago' => \Drupal::service('date.formatter')->formatInterval(\Drupal::time()->getRequestTime() - $saleStatus['timestamp']) . ' ago',
          '@datetime' => \Drupal::service('date.formatter')->format($saleStatus['timestamp'], 'long'),
        ]
          );
    }
    else {
      return new TranslatableMarkup("This product is not on sale due to date restrictions.");
    }
  }

}
