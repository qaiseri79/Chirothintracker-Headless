<?php
// phpcs:ignoreFile

/**
 * @file
 * Hooks provided by the entity_clone module.
 */

use Drupal\entity_clone\Event\EntityCloneEvent;
use Drupal\entity_clone\Event\EntityCloneEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @file
 * Entity Clone hooks and events.
 */
/**
 * Event subscribers for Entity Clone.
 *
 * Service definition for my_module.services.yml:
 * <code>
 * ```yaml
 *  my_module.my_event_subscriber:
 *    class: Drupal\my_module\EventSubscriber\MyEntityCloneEventSubscriber
 *    tags:
 *     - { name: event_subscriber }
 * ```
 * </code>
 *
 * Code for src/EventSubscriber/MyEntityCloneEventSubscriber.php
 * <code>
 * <?php
 * namespace Drupal\my_module\EventSubscriber;
 * ?>
 * </code>
 */
class MyEntityCloneEventSubscriber implements EventSubscriberInterface {

  /**
   * An example event subscriber.
   *
   * Dispatched before an entity is cloned and saved.
   *
   * @see \Drupal\entity_clone\Event\EntityCloneEvents::PRE_CLONE
   */
  public function myPreClone(EntityCloneEvent $event): void {
    $original = $event->getEntity();
    $newEntity = $event->getClonedEntity();
  }

  /**
   * An example event subscriber.
   *
   * Dispatched after an entity is cloned and saved.
   *
   * @see \Drupal\entity_clone\Event\EntityCloneEvents::POST_CLONE
   */
  public function myPostClone(EntityCloneEvent $event): void {
    $original = $event->getEntity();
    $newEntity = $event->getClonedEntity();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[EntityCloneEvents::PRE_CLONE][] = ['myPreClone'];
    $events[EntityCloneEvents::POST_CLONE][] = ['myPostClone'];
    return $events;
  }

}

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alter the entity clone settings.
 *
 * @param array $settings
 *   The settings from the Entity Clone form.
 */
function hook_entity_clone_settings_alter(array &$form_settings) {
  // Adding settings per entity bundle.
  $form_settings['taxonomy_term']['vocabulary_name']['default_value'] = false;
  $form_settings['taxonomy_term']['vocabulary_name']['disable'] = false;
  $form_settings['taxonomy_term']['vocabulary_name']['hidden'] = false;
}

/**
 * @} End of "addtogroup hooks".
 */
