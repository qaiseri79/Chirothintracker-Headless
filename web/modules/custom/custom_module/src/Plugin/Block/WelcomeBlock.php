<?php

namespace Drupal\custom_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * Provides a 'Welcome' custom block.
 *
 * @Block(
 *   id = "welcome_block",
 *   admin_label = @Translation("Welcome (Custom Block)"),
 * )
 */
class WelcomeBlock extends BlockBase implements ContainerFactoryPluginInterface
{

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructor.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, AccountProxyInterface $current_user, EntityTypeManagerInterface $entity_type_manager)
  {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
  {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build()
  {
    $html = '';
    $uid = $this->currentUser->id();
    $account = $this->entityTypeManager->getStorage('user')->load($uid);

    if (!$account) {
      return ['#markup' => $this->t('Unable to load user.')];
    }

    $fullname_field = $account->get('field_full_name')->getString();
    if ($fullname_field) {
      $html .= "<h3><span><i class='fa-solid fa-handshake'></i></span>&nbsp;Welcome, <span class='welcome-user-name'>" .
        htmlspecialchars($fullname_field) .
        "</span>&nbsp;<span><i class='fa-solid fa-exclamation'></i></span></h3>";
    }

    if ($account->hasRole('archived_patient') || $account->hasRole('enrolled_patient')) {
      $get_field_value = function ($field_name, $decimals = 0, $suffix = '') use ($account) {
        $val = $account->get($field_name)->getValue();
        if (!empty($val[0]['value'])) {
          $number = (float) $val[0]['value'];
          return number_format($number, $decimals, '.', '') . $suffix;
        }
        return 'N/A';
      };

      $html .= '<div class="patient-logs-info">';
      $html .= $this->buildInfoField("Goal Weight", $get_field_value('field_goal_weight', 2, 'lbs'));
      $html .= $this->buildInfoField("Net Weight Loss", $get_field_value('field_net_weight_loss', 1, 'lbs'));
      $html .= $this->buildInfoField("Net Inches Lost", $get_field_value('field_net_inches_lost', 1, 'In'));
      $html .= $this->buildInfoField("Goal Achieved", $get_field_value('field_goal_achieved', 0, '%'));
      $html .= $this->buildInfoField("Overall Weight Loss", $get_field_value('field_gross_weight_loss', 1, 'lbs'));
      $html .= '</div>'; // Close patient-logs-info

      $html .= '<div class="row g-0 justify-content-center patients-btn">';
      if ($account->hasRole('enrolled_patient')) {
        $html .= '<div class="col-auto views-field"><a href="/track">Log My Weight</a></div>';
        $html .= '<div class="col-auto views-field"><a href="/meet">Meet Now</a></div>';
      }

      // Load menu items
      $menu_items = $this->getChiropractorMenuItems($account);
      if (!empty($menu_items)) {
        foreach ($menu_items as $menu_item) {
          $html .= '<div class="col-auto views-field"><a href="' . $menu_item['field_link'] . '" target="_blank">' . $menu_item['title'] . '</a></div>';
        }
      }

      $html .= '</div>'; // Close patients-btn
    }

    return [
      '#markup' => $html,
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Helper to build a single info field line.
   */
  private function buildInfoField($label, $value)
  {
    return '<div class="views-field">
              <div class="info-key">' . $label . ': </div>
              <div class="info-val">' . $value . '</div>
            </div>';
  }

  /**
   * Get title and field_link from menu_items associated with the user's chiropractor's clinic.
   */
  private function getChiropractorMenuItems(EntityInterface $user)
  {
    if (!$user->hasField('field_chiropractor') || $user->get('field_chiropractor')->isEmpty()) {
      return [];
    }

    $chiropractor = $user->get('field_chiropractor')->entity;
    if (!$chiropractor instanceof User) {
      return [];
    }

    if ($chiropractor->hasField('field_chiropractor_subscribers') && !$chiropractor->get('field_chiropractor_subscribers')->isEmpty()) {
      $uid = $chiropractor->get('field_chiropractor_subscribers')->target_id;
    } else {
      $uid = $chiropractor->id();
    }

    $menu_item_storage = $this->entityTypeManager->getStorage('menu_item');
    $menu_item_ids = $menu_item_storage->getQuery()
      ->condition('uid', $uid)
      // ->condition('field_my_clinic.target_id', $clinic->id())
      ->accessCheck(FALSE)
      ->execute();

    $menu_items = $menu_item_storage->loadMultiple($menu_item_ids);

    $output = [];

    foreach ($menu_items as $menu_item) {
      $title = $menu_item->label();
      $link_value = '';

      if ($menu_item->hasField('field_link') && !$menu_item->get('field_link')->isEmpty()) {
        $link_value = $menu_item->get('field_link')->value;
      }

      $output[] = [
        'title' => $title,
        'field_link' => $link_value,
      ];
    }

    return $output;
  }

}
