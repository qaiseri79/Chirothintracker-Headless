<?php

namespace Drupal\custom_module\Plugin\views\field;

use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\views\ResultRow;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\field\FieldPluginBase;

/**
 * A custom Views field for Red Light Therapy Profile management.
 *
 * @ViewsField("red_light_therapy_profile")
 */
class RedLightTherapyProfile extends FieldPluginBase
{

    /**
     * {@inheritdoc}
     */
    public function query()
    {
        // Leave empty to avoid changing the query.
    }

    /**
     * {@inheritdoc}
     */
    protected function defineOptions()
    {
        $options = parent::defineOptions();
        return $options;
    }

    /**
     * {@inheritdoc}
     */
    public function buildOptionsForm(&$form, FormStateInterface $form_state)
    {
        parent::buildOptionsForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function render(ResultRow $values)
    {
        $user = $values->_entity;
        $uid = $user->id();

        // Check if the patient has "Red Light" feature enabled.
        if (
            !$user->hasField('field_laser_patient_status') ||
            $user->get('field_laser_patient_status')->isEmpty() ||
            (
                ($term = $user->get('field_laser_patient_status')->entity) &&
                (empty($term->field_laser->value) || $term->field_laser->value == 0)
            )
        ) {
            return ['#markup' => ''];
        }

        // Query for existing zerona_profile entity.
        $entity_type_manager = \Drupal::entityTypeManager();
        $query = $entity_type_manager->getStorage('patient_profile')->getQuery()
            ->condition('type', 'zerona_profile')
            ->condition('field_patient', $uid)
            ->accessCheck(TRUE)
            ->sort('id', 'DESC')
            ->range(0, 1);

        $entity_ids = $query->execute();

        $build = ['#markup' => ''];

        if (!empty($entity_ids)) {
            // Entity exists - show details button and manage button.
            $entity_id = reset($entity_ids);
            $entity = $entity_type_manager->getStorage('patient_profile')->load($entity_id);

            // Get sessions available value.
            $sessions_available = 0;
            if ($entity->hasField('field_sessions_available') && !$entity->get('field_sessions_available')->isEmpty()) {
                $sessions_available = $entity->get('field_sessions_available')->value;
            }

            // Details button (view page).
            $details_url = Url::fromRoute('view.laser_patient_details.page_1', ['arg_0' => $uid]);
            $details_link = Link::fromTextAndUrl(
                $this->t('Red Light Therapy Profile <span class="badge button--primary">@count</span>', ['@count' => $sessions_available]),
                $details_url
            );
            $details_link = $details_link->toRenderable();
            $details_link['#attributes']['class'] = ['btn', 'btn-success', 'btn-xs', 'fs-12'];

            // Manage button.
            $manage_url = Url::fromUserInput('/zerona_profile', [
                'attributes' => [
                    'class' => ['use-ajax', 'btn', 'btn-danger', 'btn-xs', 'fs-12'],
                    'data-dialog-type' => "modal",
                    'data-dialog-options' => Json::encode([
                        'width' => 1000,
                        'position' => ['my' => 'center', 'at' => 'center'],
                    ]),
                ],
                'query' => [
                    'uid' => $uid,
                    'profile_id' => $entity_id,
                    'destination' => 'summary',
                ],
            ]);

            $build = [
                '#type' => 'container',
                '#attributes' => ['class' => ['red-light-therapy-profile-actions patient-details']],
                'details' => $details_link,
            ];

            // Add manage button only if user has access.
            if ($manage_url->access(\Drupal::currentUser())) {
                $manage_link = Link::fromTextAndUrl($this->t('<i class="fa-solid fa-bolt"></i>'), $manage_url)->toRenderable();
                $build['manage'] = $manage_link;
            }

        } else {
            // Entity doesn't exist - show create button.
            $create_url = Url::fromRoute('custom_module.red_light_therapy_profile', ['user' => $uid], [
                'attributes' => [
                    'class' => ['use-ajax', 'btn', 'btn-success', 'btn-xs', 'fs-12'],
                    'data-dialog-type' => "modal",
                    'data-dialog-options' => Json::encode([
                        'width' => 1000,
                        'position' => ['my' => 'center', 'at' => 'center'],
                    ]),
                ],
            ]);
            if ($create_url->access(\Drupal::currentUser())) {
                $create_link = Link::fromTextAndUrl(
                    $this->t('<i class="fa-solid fa-plus"></i> Add Red Light Therapy Profile'),
                    $create_url
                );
                $create_link = $create_link->toRenderable();

                $build = [
                    '#type' => 'container',
                    '#attributes' => ['class' => ['red-light-therapy-profile-actions patient-details']],
                    'create' => $create_link,
                ];
            }
        }

        return $build;
    }

}