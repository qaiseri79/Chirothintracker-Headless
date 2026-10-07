<?php

namespace Drupal\fillpdf\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\fillpdf\FillPdfAdminFormHelperInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for the FillPdfFormFieldForm edit form.
 */
class FillPdfFormFieldForm extends ContentEntityForm {

  /**
   * The FillPdf admin form helper.
   *
   * @var \Drupal\fillpdf\FillPdfAdminFormHelperInterface
   */
  protected $adminFormHelper;

  /**
   * Constructs a FillPdfFormFieldForm object.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository service.
   * @param \Drupal\fillpdf\FillPdfAdminFormHelperInterface $admin_form_helper
   *   FillPdf admin form helper service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(EntityRepositoryInterface $entity_repository, FillPdfAdminFormHelperInterface $admin_form_helper, EntityTypeBundleInfoInterface $entity_type_bundle_info, TimeInterface $time) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->adminFormHelper = $admin_form_helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('fillpdf.admin_form_helper'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    $form['prefix']['widget']['0']['value']['#rows'] = 3;

    $form['value']['token_help'] = $this->adminFormHelper->getAdminTokenForm();

    $form['suffix']['widget']['0']['value']['#rows'] = 3;

    $form['extra'] = [
      '#type' => 'details',
      '#title' => $this->t('Transform values'),
    ];
    $form['replacements']['#group'] = 'extra';

    return $form;
  }

}
