<?php

namespace Drupal\eca_cm\Controller;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\eca\Entity\Eca;
use Drupal\eca\PluginManager\Action;
use Drupal\eca\PluginManager\Condition;
use Drupal\eca\PluginManager\Event;
use Drupal\eca_cm\Form\EcaActionDeleteForm;
use Drupal\eca_cm\Form\EcaActionForm;
use Drupal\eca_cm\Form\EcaConditionDeleteForm;
use Drupal\eca_cm\Form\EcaConditionForm;
use Drupal\eca_cm\Form\EcaEventDeleteForm;
use Drupal\eca_cm\Form\EcaEventForm;
use Drupal\eca_cm\Form\EcaForm;
use Drupal\eca_cm\Form\EcaListForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller class for Classic modeler integration into ECA.
 */
class CoreModeller extends ControllerBase {

  /**
   * ECA modeler service.
   *
   * @var \Drupal\eca\Service\Modellers|null
   */
  protected $modellerServices = NULL;

  /**
   * The event plugin manager.
   *
   * @var \Drupal\eca\PluginManager\Event
   */
  protected Event $eventManager;

  /**
   * The condition manager.
   *
   * @var \Drupal\eca\PluginManager\Condition
   */
  protected Condition $conditionManager;

  /**
   * The action manager.
   *
   * @var \Drupal\eca\PluginManager\Action
   */
  protected Action $actionManager;

  /**
   * Constructs a new CoreModeller object.
   *
   * @param \Drupal\eca\Service\Modellers|null $modeller_services
   *   The ECA modeler service.
   * @param \Drupal\eca\PluginManager\Event $event_manager
   *   The event plugin manager.
   * @param \Drupal\eca\PluginManager\Condition $condition manager
   *   The condition manager.
   * @param \Drupal\eca\PluginManager\Action $action_manager
   *   The action manager.
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder.
   */
  public function __construct($modeller_services, Event $event_manager, Condition $condition_manager, Action $action_manager, FormBuilderInterface $form_builder) {
    $this->modellerServices = $modeller_services;
    $this->eventManager = $event_manager;
    $this->conditionManager = $condition_manager;
    $this->actionManager = $action_manager;
    $this->formBuilder = $form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->has('eca.service.modeller') ? $container->get('eca.service.modeller') : NULL,
      $container->get('plugin.manager.eca.event'),
      $container->get('plugin.manager.eca.condition'),
      $container->get('plugin.manager.eca.action'),
      $container->get('form_builder')
    );
  }

  /**
   * Callback to add a new Core model.
   *
   * @return array
   *   The render array for editing the new model.
   */
  public function add(): array {
    if ($this->modellerServices) {
      // This is the logic for ECA v1 and v2.
      /** @var \Drupal\eca_cm\Plugin\ECA\Modeller\Core $modeller */
      $modeller = $this->modellerServices->getModeller('core');
      $modeller->setConfigEntity(Eca::create([
        'status' => FALSE,
        'modeller' => 'core',
      ]));
      $modeller->createNewModel('', '');
      return $modeller->edit();
    }
    else {
      // This is logic for ECA v3 (and possibly above).
      $eca = Eca::create([
        'status' => FALSE,
        'modeller' => 'core',
        'third_party_settings' => [
          'modeler_api' => ['label' => ''],
        ],
      ]);
      $form_object = EcaForm::create(\Drupal::getContainer());
      $form_object->setEntity($eca);
      return $this->formBuilder->getForm($form_object);
    }
  }

  public function addEvent(Eca $eca, string $eca_event_plugin): array {
    /** @var \Drupal\eca\Plugin\ECA\Event\EventInterface $plugin */
    $plugin = $this->eventManager->createInstance($eca_event_plugin);

    $config_key = str_replace(':', '_', $plugin->getPluginId());
    $config_key = substr($config_key, 0, 32);
    $plugins_array = $eca->get('events') ?? [];
    if (isset($plugins_array[$config_key])) {
      $i = 1;
      while (isset($plugins_array[$config_key . '_' . $i])) {
        $i++;
      }
      if (strlen($config_key) + strlen('_' . $i) > 32) {
        $config_key = substr($config_key, 0, - strlen('_' . $i));
      }
      $config_key .= '_' . $i;
    }

    $config_array = [
      'label' => $plugin->getPluginDefinition()['label'],
      'configuration' => $plugin instanceof ConfigurableInterface ? $plugin->getConfiguration() : [],
    ];
    return $this->formBuilder->getForm(EcaEventForm::class, $eca, $plugin, $config_key, $config_array);
  }

  public function editEvent(Eca $eca, string $eca_event_id): array {
    $config_key = $eca_event_id;

    $plugins_array = $eca->get('events') ?? [];
    if (!isset($plugins_array[$config_key])) {
      throw new NotFoundHttpException();
    }

    $config_array = $plugins_array[$config_key];
    $plugins = $eca->getPluginCollections();
    foreach ($plugins['events.' . $config_key] as $plugin) {}

    return $this->formBuilder->getForm(EcaEventForm::class, $eca, $plugin, $config_key, $config_array);
  }

  public function deleteEvent(Eca $eca, string $eca_event_id): array {
    $config_key = $eca_event_id;

    $plugins_array = $eca->get('events') ?? [];
    if (!isset($plugins_array[$config_key])) {
      throw new NotFoundHttpException();
    }

    $config_array = $plugins_array[$config_key];
    $plugins = $eca->getPluginCollections();
    foreach ($plugins['events.' . $config_key] as $plugin) {}

    return $this->formBuilder->getForm(EcaEventDeleteForm::class, $eca, $plugin, $config_key, $config_array);
  }

  public function addCondition(Eca $eca, string $eca_condition_plugin): array {
    /** @var \Drupal\eca\Plugin\ECA\Condition\ConditionInterface $plugin */
    $plugin = $this->conditionManager->createInstance($eca_condition_plugin);

    $config_key = str_replace(':', '_', $plugin->getPluginId());
    $config_key = substr($config_key, 0, 32);
    $plugins_array = $eca->get('conditions') ?? [];
    if (isset($plugins_array[$config_key])) {
      $i = 1;
      while (isset($plugins_array[$config_key . '_' . $i])) {
        $i++;
      }
      if (strlen($config_key) + strlen('_' . $i) > 32) {
        $config_key = substr($config_key, 0, - strlen('_' . $i));
      }
      $config_key .= '_' . $i;
    }

    $config_array = [
      'label' => $plugin->getPluginDefinition()['label'],
      'configuration' => $plugin instanceof ConfigurableInterface ? $plugin->getConfiguration() : [],
    ];
    return $this->formBuilder->getForm(EcaConditionForm::class, $eca, $plugin, $config_key, $config_array);
  }

  public function editCondition(Eca $eca, string $eca_condition_id): array {
    $config_key = $eca_condition_id;

    $plugins_array = $eca->get('conditions') ?? [];
    if (!isset($plugins_array[$config_key])) {
      throw new NotFoundHttpException();
    }

    $config_array = $plugins_array[$config_key];
    $plugins = $eca->getPluginCollections();
    foreach ($plugins['conditions.' . $config_key] as $plugin) {}

    return $this->formBuilder->getForm(EcaConditionForm::class, $eca, $plugin, $config_key, $config_array);
  }

  public function deleteCondition(Eca $eca, string $eca_condition_id): array {
    $config_key = $eca_condition_id;

    $plugins_array = $eca->get('conditions') ?? [];
    if (!isset($plugins_array[$config_key])) {
      throw new NotFoundHttpException();
    }

    $config_array = $plugins_array[$config_key];
    $plugins = $eca->getPluginCollections();
    foreach ($plugins['conditions.' . $config_key] as $plugin) {}

    return $this->formBuilder->getForm(EcaConditionDeleteForm::class, $eca, $plugin, $config_key, $config_array);
  }

  public function addAction(Eca $eca, string $eca_action_plugin): array {
    /** @var \Drupal\Core\Action\ActionInterface $plugin */
    $plugin = $this->actionManager->createInstance($eca_action_plugin);

    $config_key = str_replace(':', '_', $plugin->getPluginId());
    $config_key = substr($config_key, 0, 32);
    $plugins_array = $eca->get('actions') ?? [];
    if (isset($plugins_array[$config_key])) {
      $i = 1;
      while (isset($plugins_array[$config_key . '_' . $i])) {
        $i++;
      }
      if (strlen($config_key) + strlen('_' . $i) > 32) {
        $config_key = substr($config_key, 0, - strlen('_' . $i));
      }
      $config_key .= '_' . $i;
    }

    $config_array = [
      'label' => $plugin->getPluginDefinition()['label'],
      'configuration' => $plugin instanceof ConfigurableInterface ? $plugin->getConfiguration() : [],
    ];
    return $this->formBuilder->getForm(EcaActionForm::class, $eca, $plugin, $config_key, $config_array);
  }

  public function editAction(Eca $eca, string $eca_action_id): array {
    $config_key = $eca_action_id;

    $plugins_array = $eca->get('actions') ?? [];
    if (!isset($plugins_array[$config_key])) {
      throw new NotFoundHttpException();
    }

    $config_array = $plugins_array[$config_key];
    $plugins = $eca->getPluginCollections();
    foreach ($plugins['actions.' . $config_key] as $plugin) {}

    return $this->formBuilder->getForm(EcaActionForm::class, $eca, $plugin, $config_key, $config_array);
  }

  public function deleteAction(Eca $eca, string $eca_action_id): array {
    $config_key = $eca_action_id;

    $plugins_array = $eca->get('actions') ?? [];
    if (!isset($plugins_array[$config_key])) {
      throw new NotFoundHttpException();
    }

    $config_array = $plugins_array[$config_key];
    $plugins = $eca->getPluginCollections();
    foreach ($plugins['actions.' . $config_key] as $plugin) {}

    return $this->formBuilder->getForm(EcaActionDeleteForm::class, $eca, $plugin, $config_key, $config_array);
  }

  /**
   * Builds up a listing for ECA processes that are using the Core Modeler.
   *
   * Being available from ECA v3 and only when Modeler API is not installed.
   *
   * @return array
   *   Renderable array build.
   */
  public function list(): array {
    $entityType = $this->entityTypeManager()->getDefinition('eca');
    $list = EcaListForm::createInstance(\Drupal::getContainer(), $entityType);
    $build = $list->render();

    return $build;
  }

  public function edit(Eca $eca): array {
    $form_object = EcaForm::create(\Drupal::getContainer());
    $form_object->setEntity($eca);
    return $this->formBuilder->getForm($form_object);
  }

}
