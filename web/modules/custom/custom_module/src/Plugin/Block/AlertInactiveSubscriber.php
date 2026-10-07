<?php

namespace Drupal\custom_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * Provides a block to alert inactive subscribers.
 *
 * @Block(
 *   id = "alert_inactive_subscriber",
 *   admin_label = @Translation("Alert Inactive Subscriber"),
 * )
 */
class AlertInactiveSubscriber extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a new AlertInactiveSubscriber block.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MessengerInterface $messenger,
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUser
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->messenger = $messenger;
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('messenger'),
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {

    if (!$this->currentUser->hasRole('chiropractor_active_')) {
      $subscription_storage = $this->entityTypeManager->getStorage('commerce_subscription');

      $query = $subscription_storage->getQuery()
        ->condition('uid', $this->currentUser->id())
        ->condition('state', 'active')
        ->accessCheck(FALSE);

      $subscription_ids = $query->execute();

      if (empty($subscription_ids)) {
        $this->messenger->addError(t('It appears that your subscription is currently inactive. To regain full access to all features, please <a href=":url">reactivate your subscription</a>.', [
          ':url' => '/pricing/listing',
        ]));
      }
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

}
