<?php

namespace Drupal\custom_module\Plugin\Action;

use Drupal\user\Entity\User;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\contact\Entity\Message;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * @Action(
 *   id = "custom_module_mass_message_action",
 *   label = @Translation("Send Mass Message"),
 *   type = "user",
 *   confirm = TRUE
 * )
 */
class MassMessageAction extends ViewsBulkOperationsActionBase implements PluginFormInterface, ContainerFactoryPluginInterface
{
  use StringTranslationTrait;

  /**
   * The flag service.
   *
   * @var \Drupal\flag\FlagServiceInterface
   */
  protected $flagService;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->flagService = $container->get('flag');
    $instance->currentUser = $container->get('current_user');
    $instance->logger = $container->get('logger.factory')->get('custom_module');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, \Drupal\Core\Form\FormStateInterface $form_state)
  {
    $form['patient_message'] = [
      '#title' => t('Message'),
      '#type' => 'text_format',
      '#rows' => 10,
      '#cols' => 60,
      '#resizable' => TRUE,
      '#required' => TRUE,
      '#format' => 'full_html',
      '#allowed_formats' => ['full_html'],
      '#default_value' => $form_state->getValue('patient_message')['value'] ?? '',
      '#after_build' => [
        function ($element) {
          if (isset($element['format']['help'])) {
            $element['format']['help']['#access'] = FALSE;
          }
          return $element;
        }
      ],
    ];
    
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(ContentEntityInterface $entity = NULL)
  {
    // Return a simple string to avoid translation overhead during batch
    if (!$entity || !$entity->id()) {
      $this->logger->error('Invalid entity provided');
      return 'Error: Invalid entity';
    }

    try {
      $message = $this->configuration["patient_message"];
      $this->sendMassMessage($entity, $message);
      return sprintf('Message sent to %s', $entity->getAccountName());
    } catch (\Exception $e) {
      $this->logger->error('Error sending message to @user: @error', [
        '@user' => $entity->getAccountName(),
        '@error' => $e->getMessage()
      ]);
      return sprintf('Error sending to %s', $entity->getAccountName());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE)
  {
    $result = AccessResult::allowedIf($account && $account->hasRole('chiropractor_active_'));
    return $return_as_object ? $result : $result->isAllowed();
  }

  /**
   * Send mass message to user.
   */
  protected function sendMassMessage($user_entity, $message)
  {
    // Use dependency injection instead of \Drupal::
    $current_user_id = $this->currentUser->id();
    $user_id = $user_entity->id();

    // Create message entity
    $message_entity = Message::create([
      'contact_form' => 'message',
      'uid' => $user_id,
      'field_from' => $current_user_id,
      'field_to' => $user_id,
      'field_message' => $message,
      'field_patient_uid' => $user_id,
    ]);
    $message_entity->save();

    // Flag the message
    $flag = $this->flagService->getFlagById('message_status_contact_storage');
    if ($flag) {
      $flagging = $this->flagService->getFlagging($flag, $message_entity, $user_entity);
      if (!$flagging) {
        $this->flagService->flag($flag, $message_entity, $user_entity);
      }
    }

    // Send email (make sure this function is optimized too)
    messageEmail($user_id);

    return $message_entity->id();
  }
}