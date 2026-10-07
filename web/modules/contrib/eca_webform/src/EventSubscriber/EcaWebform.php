<?php

namespace Drupal\eca_webform\EventSubscriber;

use Drupal\eca\EcaEvents;
use Drupal\eca\Event\AfterInitialExecutionEvent;
use Drupal\eca\Event\BeforeInitialExecutionEvent;
use Drupal\eca\EventSubscriber\EcaBase;
use Drupal\eca\Plugin\DataType\DataTransferObject;
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
use Drupal\eca_webform\Event\WebformBaseEvent;
use Drupal\eca_webform\Plugin\ECA\Event\WebformEvent;

/**
 * ECA event subscriber.
 */
class EcaWebform extends EcaBase {

  /**
   * Subscriber method before initial execution.
   *
   * @param \Drupal\eca\Event\BeforeInitialExecutionEvent $before_event
   *   The according event.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function onBeforeInitialExecution(BeforeInitialExecutionEvent $before_event): void {
    $event = $before_event->getEvent();
    if ($event instanceof WebformBaseEvent) {
      $dto = DataTransferObject::create();
      if ($event instanceof AccessRules || $event instanceof AccessRulesAlter) {
        $dto->set('access_rules', $event->getAccessRules());
      }
      elseif ($event instanceof AdminThirdPartySettingsFormAlter) {
        $dto->set('form', $event->getForm());
        $dto->set('form_state', $event->getFormState());
      }
      elseif ($event instanceof ElementAccessAlter) {
        $dto->set('account', $event->getAccount());
        $dto->set('context', $event->getContext());
        $dto->set('element', $event->getElement());
        $dto->set('operation', $event->getOperation());
      }
      elseif ($event instanceof ElementAlter) {
        $dto->set('context', $event->getContext());
        $dto->set('element', $event->getElement());
        $dto->set('form_state', $event->getFormState());
      }
      elseif ($event instanceof ElementConfigurationFormAlter) {
        $dto->set('form', $event->getForm());
        $dto->set('form_state', $event->getFormState());
      }
      elseif ($event instanceof ElementDefaultPropertiesAlter) {
        $dto->set('definition', $event->getDefinition());
        $dto->set('properties', $event->getProperties());
      }
      elseif ($event instanceof ElementInfoAlter) {
        $dto->set('handlers', $event->getHandlers());
      }
      elseif ($event instanceof ElementInputMasks || $event instanceof ElementInputMasksAlter) {
        $dto->set('input_masks', $event->getInputMasks());
      }
      elseif ($event instanceof ElementTranslatablePropertiesAlter) {
        $dto->set('definition', $event->getDefinition());
        $dto->set('properties', $event->getProperties());
      }
      elseif ($event instanceof HandlerInfoAlter) {
        $dto->set('definitions', $event->getDefinitions());
      }
      elseif ($event instanceof HandlerInvokeAlter) {
        $dto->set('args', $event->getArgs());
        $dto->set('handler', $event->getHandler());
        $dto->set('method_name', $event->getMethodName());
      }
      elseif ($event instanceof HelpInfo || $event instanceof HelpInfoAlter) {
        $dto->set('help_info', $event->getHelp());
      }
      elseif ($event instanceof ImageSelectImagesAlter) {
        $dto->set('element', $event->getElement());
        $dto->set('images', $event->getImages());
        $dto->set('image_id', $event->getImagesId());
      }
      elseif ($event instanceof OptionsAlter) {
        $dto->set('element', $event->getElement());
        $dto->set('options', $event->getOptions());
        $dto->set('option_id', $event->getOptionsId());
      }
      elseif ($event instanceof SourceEntityInfoAlter) {
        $dto->set('definitions', $event->getDefinitions());
      }
      elseif ($event instanceof SubmissionAccess) {
        $dto->set('account', $event->getAccount());
        $dto->set('operation', $event->getOperation());
        $dto->set('submissions', $event->getWebformSubmission());
      }
      elseif ($event instanceof SubmissionFormAlter) {
        $dto->set('form', $event->getForm());
        $dto->set('form_id', $event->getFormId());
        $dto->set('form_state', $event->getFormState());
      }
      elseif ($event instanceof SubmissionQueryAccessAlter) {
        $dto->set('query', $event->getQuery());
        $dto->set('submission_tables', $event->getWebformSubmissionTables());
      }
      elseif ($event instanceof SubmissionsPostPurge) {
        $dto->set('submissions', $event->getWebformSubmissions());
      }
      elseif ($event instanceof SubmissionsPrePurge) {
        $dto->set('submissions', $event->getWebformSubmissions());
      }
      elseif ($event instanceof ThirdPartySettingsFormAlter) {
        $dto->set('form', $event->getForm());
        $dto->set('form_state', $event->getFormState());
      }
      elseif ($event instanceof VariantInfoAlter) {
        $dto->set('variants', $event->getVariants());
      }
      $this->tokenService->addTokenData('webform', $dto);
    }
  }

  /**
   * Subscriber method after initial execution.
   *
   * @param \Drupal\eca\Event\AfterInitialExecutionEvent $after_event
   *   The according event.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function onAfterInitialExecution(AfterInitialExecutionEvent $after_event): void {
    $event = $after_event->getEvent();
    if ($event instanceof WebformBaseEvent) {
      $dto = $this->tokenService->getTokenData('webform');
      if ($dto instanceof DataTransferObject) {
        if ($event instanceof AccessRules) {
          $event->setAccessRules($dto->get('access_rules'));
        }
        elseif ($event instanceof ElementInputMasks) {
          $event->setInputMasks($dto->get('input_masks'));
        }
        elseif ($event instanceof HelpInfo) {
          $event->setHelpInfo($dto->get('help_info'));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [];
    foreach (WebformEvent::definitions() as $definition) {
      $events[$definition['event_name']][] = ['onEvent'];
    }
    $events[EcaEvents::BEFORE_INITIAL_EXECUTION][] = [
      'onBeforeInitialExecution',
      -100,
    ];
    $events[EcaEvents::AFTER_INITIAL_EXECUTION][] = [
      'onAfterInitialExecution',
      100,
    ];
    return $events;
  }

}
