<?php

namespace Drupal\eca_webform\Event;

/**
 * Events dispatched by the eca_webform module.
 */
final class WebformEvents {

  /**
   * Dispatches on webform element info alter.
   *
   * @Event
   *
   * @var string
   */
  public const ELEMENT_INFO_ALTER = 'eca_webform.element_info_alter';

  /**
   * Dispatches on webform handler info alter.
   *
   * @Event
   *
   * @var string
   */
  public const HANDLER_INFO_ALTER = 'eca_webform.handler_info_alter';

  /**
   * Dispatches on webform variant info alter.
   *
   * @Event
   *
   * @var string
   */
  public const VARIANT_INFO_ALTER = 'eca_webform.variant_info_alter';

  /**
   * Dispatches on webform source entity info alter.
   *
   * @Event
   *
   * @var string
   */
  public const SOURCE_ENTITY_INFO_ALTER = 'eca_webform.source_entity_info_alter';

  /**
   * Dispatches on webform element default properties alter.
   *
   * @Event
   *
   * @var string
   */
  public const ELEMENT_DEFAULT_PROPERTIES_ALTER = 'eca_webform.element_default_properties_alter';

  /**
   * Dispatches on webform elemtne translatable properties alter.
   *
   * @Event
   *
   * @var string
   */
  public const ELEMENT_TRANSLATABLE_PROPERTIES_ALTER = 'eca_webform.element_translatable_properties_alter';

  /**
   * Dispatches on webform element configuration form alter.
   *
   * @Event
   *
   * @var string
   */
  public const ELEMENT_CONFIGURATION_FORM_ALTER = 'eca_webform.element_configuration_form_alter';

  /**
   * Dispatches on webform element alter.
   *
   * @Event
   *
   * @var string
   */
  public const ELEMENT_ALTER = 'eca_webform.element_alter';

  /**
   * Dispatches on webform element access.
   *
   * @Event
   *
   * @var string
   */
  public const ELEMENT_ACCESS = 'eca_webform.element_access';

  /**
   * Dispatches on webform element input masks.
   *
   * @Event
   *
   * @var string
   */
  public const ELEMENT_INPUT_MASKS = 'eca_webform.element_input_masks';

  /**
   * Dispatches on webform element input masks alter.
   *
   * @Event
   *
   * @var string
   */
  public const ELEMENT_INPUT_MASKS_ALTER = 'eca_webform.element_input_masks_alter';

  /**
   * Dispatches on webform options alter.
   *
   * @Event
   *
   * @var string
   */
  public const OPTIONS_ALTER = 'eca_webform.options_alter';

  /**
   * Dispatches on webform submissions pre-purge.
   *
   * @Event
   *
   * @var string
   */
  public const SUBMISSIONS_PRE_PURGE = 'eca_webform.submissions_pre_purge';

  /**
   * Dispatches on webform submissions post-purge.
   *
   * @Event
   *
   * @var string
   */
  public const SUBMISSIONS_POST_PURGE = 'eca_webform.submissions_post_purge';

  /**
   * Dispatches on webform submission form alter.
   *
   * @Event
   *
   * @var string
   */
  public const SUBMISSION_FORM_ALTER = 'eca_webform.submission_form_alter';

  /**
   * Dispatches on webform admin third party settings form alter.
   *
   * @Event
   *
   * @var string
   */
  public const ADMIN_THIRD_PARTY_SETTINGS_FORM_ALTER = 'eca_webform.admin_third_party_settings_form_alter';

  /**
   * Dispatches on webform third party settings form alter.
   *
   * @Event
   *
   * @var string
   */
  public const THIRD_PARTY_SETTINGS_FORM_ALTER = 'eca_webform.third_party_settings_form_alter';

  /**
   * Dispatches on webform handler invoke alter.
   *
   * @Event
   *
   * @var string
   */
  public const HANDLER_INVOKE_ALTER = 'eca_webform.handler_invoke_alter';

  /**
   * Dispatches on webform help info.
   *
   * @Event
   *
   * @var string
   */
  public const HELP_INFO = 'eca_webform.help_info';

  /**
   * Dispatches on webform help_info_alter.
   *
   * @Event
   *
   * @var string
   */
  public const HELP_INFO_ALTER = 'eca_webform.help_info_alter';

  /**
   * Dispatches on webform access rules.
   *
   * @Event
   *
   * @var string
   */
  public const ACCESS_RULES = 'eca_webform.access_rules';

  /**
   * Dispatches on webform access_rules_alter.
   *
   * @Event
   *
   * @var string
   */
  public const ACCESS_RULES_ALTER = 'eca_webform.access_rules_alter';

  /**
   * Dispatches on webform submission access.
   *
   * @Event
   *
   * @var string
   */
  public const SUBMISSION_ACCESS = 'eca_webform.submission_access';

  /**
   * Dispatches on webform submission query access alter.
   *
   * @Event
   *
   * @var string
   */
  public const SUBMISSION_QUERY_ACCESS_ALTER = 'eca_webform.submission_query_access_alter';

  /**
   * Dispatches on webform image select images alter.
   *
   * @Event
   *
   * @var string
   */
  public const IMAGE_SELECT_IMAGES_ALTER = 'eca_webform.image_select_images_alter';

}
