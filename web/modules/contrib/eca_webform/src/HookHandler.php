<?php

namespace Drupal\eca_webform;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\eca\Event\BaseHookHandler;
use Drupal\eca\Event\TriggerEvent;
use Drupal\webform\Plugin\WebformHandlerInterface;
use Drupal\webform\WebformSubmissionInterface;

/**
 * The handler for hook implementations within the eca_webform.module file.
 *
 * @internal
 *   This class is not meant to be used as a public API. It is subject for name
 *   change or may be removed completely, also on minor version updates.
 */
class HookHandler extends BaseHookHandler {
  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * Constructs a new HookHandler object.
   *
   * @param \Drupal\eca\Event\TriggerEvent $trigger_event
   *   The service for triggering ECA-related events.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user account.
   */
  public function __construct(TriggerEvent $trigger_event, AccountInterface $current_user) {
    $this->currentUser = $current_user;
    parent::__construct($trigger_event);
  }

  /**
   * Get the hook handler as service instance.
   *
   * @return \Drupal\eca_webform\HookHandler
   *   The hook handler as service instance.
   */
  public static function get(): HookHandler {
    return \Drupal::service('eca_webform.hook_handler');
  }

  /**
   * Triggers the event to alter element info.
   *
   * @param array $definitions
   *   Array of webform elements, keyed on the machine-readable element name.
   */
  public function alterElementInfo(array &$definitions): void {
    static::get()->triggerEvent->dispatchFromPlugin('eca_webform.element_info_alter', $definitions);
  }

  /**
   * Triggers the event to alter handler info.
   *
   * @param array $handlers
   *   Array of webform handlers, keyed on the machine-readable handler name.
   */
  public function alterHandlerInfo(array &$handlers): void {
    static::get()->triggerEvent->dispatchFromPlugin('eca_webform.handler_info_alter', $handlers);
  }

  /**
   * Triggers the event to alter variant info.
   *
   * @param array $variants
   *   Array of webform variants, keyed on the machine-readable handler name.
   */
  public function alterVariantInfo(array &$variants): void {
    static::get()->triggerEvent->dispatchFromPlugin('eca_webform.variant_info_alter', $variants);
  }

  /**
   * Triggers the event to alter source entity info.
   *
   * @param array $definitions
   *   The array of plugin definitions.
   */
  public function alterSourceEntityInfo(array &$definitions): void {
    static::get()->triggerEvent->dispatchFromPlugin('eca_webform.source_entity_info_alter', $definitions);
  }

  /**
   * Triggers the event to alter element default properties.
   *
   * @param array &$properties
   *   An associative array containing an element's default properties.
   * @param array $definition
   *   The webform element's definition.
   */
  public function alterElementDefaultProperties(array &$properties, array &$definition): void {
    static::get()->triggerEvent->dispatchFromPlugin('eca_webform.element_default_properties_alter', $properties, $definition);
  }

  /**
   * Triggers the event to alter element translatable properties.
   *
   * @param array &$properties
   *   An indexed array containing an element's translatable properties.
   * @param array $definition
   *   The webform element's definition.
   */
  public function alterElementTranslatableProperties(array &$properties, array &$definition): void {
    static::get()->triggerEvent->dispatchFromPlugin('eca_webform.element_translatable_properties_alter', $properties, $definition);
  }

  /**
   * Triggers the event to alter element configuration form.
   *
   * @param array $form
   *   Nested array of form elements that comprise the webform element
   *   properties.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function alterElementConfigurationForm(array &$form, FormStateInterface $form_state): void {
    static::get()->triggerEvent->dispatchFromPlugin('eca_webform.element_configuration_form_alter', $form, $form_state);
  }

  /**
   * Triggers the event to alter element.
   *
   * @param array $element
   *   Webform specific element properties.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param array $context
   *   An associative array containing the following key-value pairs:
   *   - form: The form structure to which elements is being attached.
   */
  public function alterElement(array &$element, FormStateInterface $form_state, array $context): void {
    static::get()->triggerEvent->dispatchFromPlugin('eca_webform.element_alter', $element, $form_state, $context);
  }

  /**
   * Triggers the event to alter element access.
   *
   * @param string $operation
   *   An element create, view, or update operation.
   * @param array $element
   *   Webform specific element properties.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   (optional) If provided, only those formats that are allowed for this user
   *   account will be returned. All enabled formats will be returned otherwise.
   *   Defaults to NULL.
   * @param array $context
   *   The element's current context which include the webform and
   *   webform submission entity.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function alterElementAccess(string $operation, array &$element, AccountInterface $account = NULL, array $context = []): AccessResultInterface {
    $result = NULL;
    if ($account === NULL) {
      $account = $this->currentUser;
    }
    /** @var \Drupal\eca_webform\Event\ElementAccessAlter $event */
    if ($event = static::get()->triggerEvent->dispatchFromPlugin('eca_webform.element_access', $operation, $element, $account, $context)) {
      $result = $event->getAccessResult();
    }
    return $result ?? AccessResult::neutral();
  }

  /**
   * Triggers the event to receive element input masks.
   *
   * @return array
   *   Return an array of input masks for text based webform elements.
   */
  public function elementInputMasks(): array {
    /**
     * @var \Drupal\eca_webform\Event\ElementInputMasks|null $event
     */
    $event = static::get()->triggerEvent->dispatchFromPlugin('eca_webform.element_input_masks');
    if ($event !== NULL) {
      return $event->getInputMasks();
    }
    return [];
  }

  /**
   * Triggers the event to alter element input masks.
   *
   * @param array $input_masks
   *   An array of input masks for text based webform elements.
   */
  public function alterElementInputMasks(array &$input_masks): void {
    static::get()->triggerEvent->dispatchFromPlugin('eca_webform.element_input_masks_alter', $input_masks);
  }

  /**
   * Triggers the event to alter options.
   *
   * @param array $options
   *   An associative array of options.
   * @param array $element
   *   The webform element that the options is for.
   * @param string|null $options_id
   *   The webform options id. Set to NULL if the options are custom.
   */
  public function alterOptions(array &$options, array &$element, string $options_id = NULL): void {
    static::get()->triggerEvent->dispatchFromPlugin('eca_webform.options_alter', $options, $element, $options_id);
  }

  /**
   * Triggers the event to pre-purge submissions.
   *
   * @param \Drupal\webform\WebformSubmissionInterface[] $webform_submissions
   *   The webform submissions being purged.
   */
  public function submissionsPrePurge(array $webform_submissions): void {
    static::get()->triggerEvent->dispatchFromPlugin('eca_webform.submissions_pre_purge', $webform_submissions);
  }

  /**
   * Triggers the event to post-purge submissions.
   *
   * @param \Drupal\webform\WebformSubmissionInterface[] $webform_submissions
   *   The webform submissions being purged.
   */
  public function submissionsPostPurge(array $webform_submissions): void {
    static::get()->triggerEvent->dispatchFromPlugin('eca_webform.submissions_post_purge', $webform_submissions);
  }

  /**
   * Triggers the event to alter submission form.
   *
   * @param array $form
   *   Nested array of form elements that comprise the webform.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param string $form_id
   *   String representing the webform's id.
   */
  public function alterSubmissionForm(array &$form, FormStateInterface $form_state, string $form_id): void {
    static::get()->triggerEvent->dispatchFromPlugin('eca_webform.submission_form_alter', $form, $form_state, $form_id);
  }

  /**
   * Triggers the event to alter admin third party settings form.
   *
   * @param array $form
   *   Nested array of form elements that comprise the webform.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function alterAdminThirdPartySettingsForm(array &$form, FormStateInterface $form_state): void {
    static::get()->triggerEvent->dispatchFromPlugin('eca_webform.admin_third_party_settings_form_alter', $form, $form_state);
  }

  /**
   * Triggers the event to alter third party settings form.
   *
   * @param array $form
   *   Nested array of form elements that comprise the webform.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function alterThirdPartySettingsForm(array &$form, FormStateInterface $form_state): void {
    static::get()->triggerEvent->dispatchFromPlugin('eca_webform.third_party_settings_form_alter', $form, $form_state);
  }

  /**
   * Triggers the event to alter handler invoke.
   *
   * @param \Drupal\webform\Plugin\WebformHandlerInterface $handler
   *   A webform handler attached to a webform.
   * @param string $method_name
   *   The invoked method name converted to snake case.
   * @param array $args
   *   Argument being passed to the handler's method.
   */
  public function alterHandlerInvoke(WebformHandlerInterface $handler, string $method_name, array &$args): void {
    static::get()->triggerEvent->dispatchFromPlugin('eca_webform.handler_invoke_alter', $handler, $method_name, $args);
  }

  /**
   * Triggers the event to collect help info.
   *
   * @return array
   *   Extra webform help provided to the users.
   */
  public function helpInfo(): array {
    /**
     * @var \Drupal\eca_webform\Event\HelpInfo|null $event
     */
    $event = static::get()->triggerEvent->dispatchFromPlugin('eca_webform.help_info');
    if ($event !== NULL) {
      return $event->getHelpInfo();
    }
    return [];
  }

  /**
   * Triggers the event to alter help info.
   *
   * @param array $help
   *   Webform help data as collected from hook_webform_help_info().
   */
  public function alterHelpInfo(array &$help): void {
    static::get()->triggerEvent->dispatchFromPlugin('eca_webform.help_info_alter', $help);
  }

  /**
   * Triggers the event to collect access rules.
   *
   * @return array
   *   Array of metadata about additional access rules to be managed on per
   *   webform basis.
   */
  public function accessRules(): array {
    /**
     * @var \Drupal\eca_webform\Event\AccessRules|null $event
     */
    $event = static::get()->triggerEvent->dispatchFromPlugin('eca_webform.access_rules');
    if ($event !== NULL) {
      return $event->getAccessRules();
    }
    return [];
  }

  /**
   * Triggers the event to alter access rules.
   *
   * @param array $access_rules
   *   Array of known access rules. Its structure is identical to the return of
   *   hook_webform_access_rules().
   */
  public function alterAccessRules(array &$access_rules): void {
    static::get()->triggerEvent->dispatchFromPlugin('eca_webform.access_rules_alter', $access_rules);
  }

  /**
   * Triggers the event to submission access.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission.
   * @param string $operation
   *   The operation.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function submissionAccess(WebformSubmissionInterface $webform_submission, string $operation, AccountInterface $account): AccessResultInterface {
    $result = NULL;
    /** @var \Drupal\eca_webform\Event\SubmissionAccess $event */
    if ($event = static::get()->triggerEvent->dispatchFromPlugin('eca_webform.submission_access', $webform_submission, $operation, $account)) {
      $result = $event->getAccessResult();
    }
    return $result ?? AccessResult::neutral();
  }

  /**
   * Triggers the event to alter submission query access.
   *
   * @param \Drupal\Core\Database\Query\AlterableInterface $query
   *   An Query object describing the composite parts of a SQL query.
   * @param array $webform_submission_tables
   *   An array webform submission tables that contains the table's alias and
   *   OR conditions which are used to build the alter query.
   */
  public function alterSubmissionQueryAccess(AlterableInterface $query, array $webform_submission_tables): void {
    static::get()->triggerEvent->dispatchFromPlugin('eca_webform.submission_query_access_alter', $query, $webform_submission_tables);
  }

  /**
   * Triggers the event to alter image select images.
   *
   * @param array $images
   *   An associative array of images.
   * @param array $element
   *   The webform element that the images is for.
   * @param string|null $images_id
   *   The webform image select images id. Set to NULL if the images are custom.
   */
  public function alterImageSelectImages(array &$images, array &$element, string $images_id = NULL): void {
    static::get()->triggerEvent->dispatchFromPlugin('eca_webform.image_select_images_alter', $images, $element, $images_id);
  }

}
