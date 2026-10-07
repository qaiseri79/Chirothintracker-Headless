<?php
namespace Drupal\custom_module\EventSubscriber;

use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\Core\Mail\MailManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\user\Entity\User;
use Drupal\flag\FlagServiceInterface;


class OrderPaidSubscriber implements EventSubscriberInterface {
  protected $mailManager;
  protected $logger;
  protected $languageManager;
  protected $flagService;

    /**
   * OrderPaidSubscriber constructor.
   *
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager service.
   * @param \Drupal\flag\FlagServiceInterface $flag_service
   *   The flag service.
   */
  public function __construct(MailManagerInterface $mailManager, LoggerChannelFactoryInterface $logger, LanguageManagerInterface $languageManager,  FlagServiceInterface $flagService) {
    $this->mailManager = $mailManager;
    $this->logger = $logger->get('custom_module');
    $this->languageManager = $languageManager;
    $this->flagService = $flagService;
  }

  public static function getSubscribedEvents() {
    return [
      OrderEvents::ORDER_PAID => 'onOrderPaid',
    ];
  }

  public function onOrderPaid(OrderEvent $event) {
    $order = $event->getOrder();
    // Check if the order contains chironutraceutical products and group notifications.
    $has_chironutraceutical = FALSE;
    $notifications = [];
    foreach ($order->getItems() as $order_item) {
      $purchased_entity = $order_item->getPurchasedEntity();
      if ($purchased_entity && ($purchased_entity->bundle() == 'chironutraceutical_variations' || $purchased_entity->bundle() == 'chironutraceutical_ns')) {
        $product = $purchased_entity->getProduct();
        if ($product->bundle() == 'chironutraceutical') {
          $has_chironutraceutical = TRUE;
          $owner = $product->getOwner();
          $owner_id = $owner->id();
          if (!isset($notifications[$owner_id])) {
            $notifications[$owner_id] = [
              'to' => $owner->getEmail(),
              'owner_name' => $owner->getDisplayName(),
              'products' => [],
            ];
          }
          $notifications[$owner_id]['products'][] = $product->label();
        }
    }
  }
   if ($has_chironutraceutical) {
      $account = User::load($order->getCustomer()->id());
      $chiropractor_id = $account->get("field_chiropractor")->getValue()[0]['target_id'];
      $chiropractor_account = User::load($chiropractor_id);
      $this->flagOrder($order, $chiropractor_account);
      if (!empty($notifications)) {
       $this->sendNotifications($order, $account, $notifications);
       }
  }
}

public function flagOrder($order, User $account) {
      $flag_order_status = $this->flagService->getFlagById('new_order_personal');
      //$flag_picked_up = $this->flagService->getFlagById('picked_up');
      //$flag_local_pickup = $this->flagService->getFlagById('local_pickup');
      // Check if the order is already flagged as new.
      if ($flag_order_status && !$this->flagService->getFlagging($flag_order_status, $order, $account)) {
        $this->flagService->flag($flag_order_status, $order, $account);
      }
      //  $shipments = \Drupal::entityTypeManager()
      //             ->getStorage('commerce_shipment')
      //             ->loadByProperties(['order_id' => $order->id()]);
      //   $shipment = reset($shipments);
      //   $shipping_method = $shipment->getShippingMethod();
      // if (!empty($shipping_method)) {
      //     $shipping_method_label = $shipping_method->label();
      //   if ($shipping_method_label == 'Flat Rate' && $flag_picked_up && !$this->flagService->getFlagging($flag_picked_up, $order, $account)) {
      //     $this->flagService->flag($flag_picked_up, $order, $account);
      //   }
      //   elseif ($shipping_method_label == 'Free Local Pickup' && $flag_local_pickup && !$this->flagService->getFlagging($flag_local_pickup, $order, $account)) {
      //     $this->flagService->flag($flag_local_pickup, $order, $account);
      //   }
      // }
  }

public function sendNotifications($order, User $account, array $notifications) {
    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $order_url = $order->toUrl('canonical', ['absolute' => TRUE])->toString();

    foreach ($notifications as $owner_id => $notification) {
      $to = $notification['to'];
      $subject = t('New Product Purchase Notification');
      $body = t("Dear @owner_name,\n\nWe would like to inform you that a new purchase has been made for the following product(s):\n- @products\n\nCustomer Information:\nName: @customer_name\nEmail: @customer_email\nOrder ID: @order_id\n\nPlease <a href=\"@order_url\">log in to your account</a> to view the order details.\n\n", [
        '@owner_name' => $notification['owner_name'],
        '@products' => implode("\n- ", $notification['products']),
        '@customer_name' => $account->getDisplayName(),
        '@customer_email' => $account->getEmail(),
        '@order_id' => $order->id(),
        '@order_url' => $order_url,
      ]);

      $params = [
        'subject' => $subject,
        'body' => $body,
      ];
        $result = $this->mailManager->mail('custom_module', 'order_notification', $to, $langcode, $params);
        if ($result['result']) {
          $this->logger->notice('Email sent to product owner @owner_id for order @order_id.', [
            '@owner_id' => $owner_id,
            '@order_id' => $order->id(),
          ]);
        }
        else {
          $this->logger->error('Failed to send email to product owner @owner_id for order @order_id.', [
            '@owner_id' => $owner_id,
            '@order_id' => $order->id(),
          ]);
        }
    }
  }
}
