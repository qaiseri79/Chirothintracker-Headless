<?php

namespace Drupal\eca_webform\Plugin\ECA\Event;

use Drupal\eca\Plugin\ECA\Event\EventBase;
use Drupal\eca_webform\Event\AccessRules;
use Drupal\eca_webform\Event\AccessRulesAlter;
use Drupal\eca_webform\Event\AdminThirdPartySettingsFormAlter;
use Drupal\eca_webform\Event\ElementAccessAlter;
use Drupal\eca_webform\Event\ElementAlter;
use Drupal\eca_webform\Event\ElementConfigurationFormAlter;
use Drupal\eca_webform\Event\ElementDefaultPropertiesAlter;
use Drupal\eca_webform\Event\ElementInfoAlter;
use Drupal\eca_webform\Event\ElementInputMasks;
use Drupal\eca_webform\Event\ElementInputMasksAlter;
use Drupal\eca_webform\Event\ElementTranslatablePropertiesAlter;
use Drupal\eca_webform\Event\HandlerInfoAlter;
use Drupal\eca_webform\Event\HandlerInvokeAlter;
use Drupal\eca_webform\Event\HelpInfo;
use Drupal\eca_webform\Event\HelpInfoAlter;
use Drupal\eca_webform\Event\ImageSelectImagesAlter;
use Drupal\eca_webform\Event\OptionsAlter;
use Drupal\eca_webform\Event\SourceEntityInfoAlter;
use Drupal\eca_webform\Event\SubmissionAccess;
use Drupal\eca_webform\Event\SubmissionFormAlter;
use Drupal\eca_webform\Event\SubmissionQueryAccessAlter;
use Drupal\eca_webform\Event\SubmissionsPostPurge;
use Drupal\eca_webform\Event\SubmissionsPrePurge;
use Drupal\eca_webform\Event\ThirdPartySettingsFormAlter;
use Drupal\eca_webform\Event\VariantInfoAlter;
use Drupal\eca_webform\Event\WebformEvents;

/**
 * Plugin implementation of the ECA Events for the webform API.
 *
 * @EcaEvent(
 *   id = "webform",
 *   deriver = "Drupal\eca_webform\Plugin\ECA\Event\WebformEventDeriver"
 * )
 */
class WebformEvent extends EventBase {

  /**
   * {@inheritdoc}
   */
  public static function definitions(): array {
    $events = [];
    $events['access_rules'] = [
      'label' => 'Access rules',
      'event_name' => WebformEvents::ACCESS_RULES,
      'event_class' => AccessRules::class,
    ];
    $events['access_rules_alter'] = [
      'label' => 'Alter access rules',
      'event_name' => WebformEvents::ACCESS_RULES_ALTER,
      'event_class' => AccessRulesAlter::class,
    ];
    $events['admin_third_party_settings_form_alter'] = [
      'label' => 'Alter admin third party settings form',
      'event_name' => WebformEvents::ADMIN_THIRD_PARTY_SETTINGS_FORM_ALTER,
      'event_class' => AdminThirdPartySettingsFormAlter::class,
    ];
    $events['element_access'] = [
      'label' => 'Element access',
      'event_name' => WebformEvents::ELEMENT_ACCESS,
      'event_class' => ElementAccessAlter::class,
    ];
    $events['element_alter'] = [
      'label' => 'Alter element',
      'event_name' => WebformEvents::ELEMENT_ALTER,
      'event_class' => ElementAlter::class,
    ];
    $events['element_configuration_form_alter'] = [
      'label' => 'Alter element configuration form',
      'event_name' => WebformEvents::ELEMENT_CONFIGURATION_FORM_ALTER,
      'event_class' => ElementConfigurationFormAlter::class,
    ];
    $events['element_default_properties_alter'] = [
      'label' => 'Alter element default properties',
      'event_name' => WebformEvents::ELEMENT_DEFAULT_PROPERTIES_ALTER,
      'event_class' => ElementDefaultPropertiesAlter::class,
    ];
    $events['element_info_alter'] = [
      'label' => 'Alter element info',
      'event_name' => WebformEvents::ELEMENT_INFO_ALTER,
      'event_class' => ElementInfoAlter::class,
    ];
    $events['element_input_masks'] = [
      'label' => 'Element input masks',
      'event_name' => WebformEvents::ELEMENT_INPUT_MASKS,
      'event_class' => ElementInputMasks::class,
    ];
    $events['element_input_masks_alter'] = [
      'label' => 'Alter element input masks',
      'event_name' => WebformEvents::ELEMENT_INPUT_MASKS_ALTER,
      'event_class' => ElementInputMasksAlter::class,
    ];
    $events['element_translatable_properties_alter'] = [
      'label' => 'Alter element translatable properties',
      'event_name' => WebformEvents::ELEMENT_TRANSLATABLE_PROPERTIES_ALTER,
      'event_class' => ElementTranslatablePropertiesAlter::class,
    ];
    $events['handler_info_alter'] = [
      'label' => 'Alter handler info',
      'event_name' => WebformEvents::HANDLER_INFO_ALTER,
      'event_class' => HandlerInfoAlter::class,
    ];
    $events['handler_invoke_alter'] = [
      'label' => 'Alter handler invoke',
      'event_name' => WebformEvents::HANDLER_INVOKE_ALTER,
      'event_class' => HandlerInvokeAlter::class,
    ];
    $events['help_info'] = [
      'label' => 'Help info',
      'event_name' => WebformEvents::HELP_INFO,
      'event_class' => HelpInfo::class,
    ];
    $events['help_info_alter'] = [
      'label' => 'Alter help info',
      'event_name' => WebformEvents::HELP_INFO_ALTER,
      'event_class' => HelpInfoAlter::class,
    ];
    $events['image_select_images_alter'] = [
      'label' => 'Alter image select images',
      'event_name' => WebformEvents::IMAGE_SELECT_IMAGES_ALTER,
      'event_class' => ImageSelectImagesAlter::class,
    ];
    $events['options_alter'] = [
      'label' => 'Alter options',
      'event_name' => WebformEvents::OPTIONS_ALTER,
      'event_class' => OptionsAlter::class,
    ];
    $events['source_entity_info_alter'] = [
      'label' => 'Alter source entity info',
      'event_name' => WebformEvents::SOURCE_ENTITY_INFO_ALTER,
      'event_class' => SourceEntityInfoAlter::class,
    ];
    $events['submission_access'] = [
      'label' => 'Submission access',
      'event_name' => WebformEvents::SUBMISSION_ACCESS,
      'event_class' => SubmissionAccess::class,
    ];
    $events['submission_form_alter'] = [
      'label' => 'Alter submission form',
      'event_name' => WebformEvents::SUBMISSION_FORM_ALTER,
      'event_class' => SubmissionFormAlter::class,
    ];
    $events['submission_query_access_alter'] = [
      'label' => 'Alter submission query access',
      'event_name' => WebformEvents::SUBMISSION_QUERY_ACCESS_ALTER,
      'event_class' => SubmissionQueryAccessAlter::class,
    ];
    $events['submissions_post_purge'] = [
      'label' => 'Submissions post-purge',
      'event_name' => WebformEvents::SUBMISSIONS_POST_PURGE,
      'event_class' => SubmissionsPostPurge::class,
    ];
    $events['submissions_pre_purge'] = [
      'label' => 'Submissions pre-purge',
      'event_name' => WebformEvents::SUBMISSIONS_PRE_PURGE,
      'event_class' => SubmissionsPrePurge::class,
    ];
    $events['third_party_settings_form_alter'] = [
      'label' => 'Alter third party settings form',
      'event_name' => WebformEvents::THIRD_PARTY_SETTINGS_FORM_ALTER,
      'event_class' => ThirdPartySettingsFormAlter::class,
    ];
    $events['variant_info_alter'] = [
      'label' => 'Alter variant info',
      'event_name' => WebformEvents::VARIANT_INFO_ALTER,
      'event_class' => VariantInfoAlter::class,
    ];
    return $events;
  }

}
