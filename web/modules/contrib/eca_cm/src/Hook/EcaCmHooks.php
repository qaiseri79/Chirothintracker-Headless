<?php

namespace Drupal\eca_cm\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Url;
use Drupal\eca_cm\EcaInfo;
use Drupal\eca_cm\Form\EcaDeleteConfirmForm;
use Drupal\eca_cm\Form\EcaForm;

/**
 * Hooks of ECA Classic modeler module.
 */
class EcaCmHooks {

  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array &$entity_types): void {
    /** @var \Drupal\Core\Entity\EntityTypeInterface[] $entity_types */
    if (!($entity_type = ($entity_types['eca'] ?? NULL))) {
      return;
    }
    if (!EcaInfo::service()->usesModelerApi()) {
      return;
    }
    $entity_type->setLinkTemplate('classic-form', '/admin/config/workflow/eca-cm/edit/{eca}');
    $entity_type->setFormClass('classic_form', EcaForm::class);
    if (!$entity_type->hasLinkTemplate('edit-form')) {
      $entity_type->setLinkTemplate('edit-form', '/admin/config/workflow/eca-cm/edit/{eca}');
    }
    if (!$entity_type->hasLinkTemplate('delete-form')) {
      $entity_type->setLinkTemplate('delete-form', '/admin/config/workflow/eca-cm/delete/{eca}');
      $entity_type->setFormClass('delete', EcaDeleteConfirmForm::class);
    }
    if (!$entity_type->hasLinkTemplate('collection')) {
      $entity_type->setLinkTemplate('collection', '/admin/config/workflow/eca-cm');
    }
  }

  #[Hook('entity_operation_alter')]
  public function entityOperation(&$operations, EntityInterface $entity): void {
    if ($entity->getEntityTypeId() !== 'eca') {
      return;
    }
    if (!EcaInfo::service()->usesModelerApi()) {
      return;
    }
    /** @var \Drupal\eca\Entity\Eca $eca */
    $eca = $entity;
    $usingModelerApi = 'fallback' !== ($eca->getThirdPartySetting('modeler_api', 'modeler_id') ?? 'fallback');
    $operations[$usingModelerApi ? 'edit_cm' : 'edit'] = [
      'title' => t('Edit with Classic modeler'),
      'url' => Url::fromRoute('entity.eca.classic_form', ['eca' => $eca->id()]),
      'weight' => ($operations['edit']['weight'] ?? -1) + 1,
    ];
    unset($operations['view']);
  }

}
