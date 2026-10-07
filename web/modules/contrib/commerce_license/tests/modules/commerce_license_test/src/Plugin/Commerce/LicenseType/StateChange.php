<?php

namespace Drupal\commerce_license_test\Plugin\Commerce\LicenseType;

use Drupal\commerce_license\Entity\LicenseInterface;

/**
 * Provides a test license type.
 *
 * @CommerceLicenseType(
 *   id = "state_change_test",
 *   label = @Translation("State change test"),
 * )
 */
class StateChange extends TestLicenseBase {

  /**
   * {@inheritdoc}
   */
  public function grantLicense(LicenseInterface $license) {
    $this->state->set('commerce_license_state_change_test', 'grantLicense');
  }

  /**
   * {@inheritdoc}
   */
  public function revokeLicense(LicenseInterface $license) {
    $this->state->set('commerce_license_state_change_test', 'revokeLicense');
  }

}
