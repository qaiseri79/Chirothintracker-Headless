<?php

namespace Drupal\Tests\commerce_authnet\FunctionalJavascript;

use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\Tests\commerce\FunctionalJavascript\CommerceWebDriverTestBase;

/**
 * Tests the Authorize.net payment configuration form.
 *
 * @group commerce_authnet
 */
class ConfigurationFormTest extends CommerceWebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_authnet',
  ];

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return array_merge([
      'access content',
      'administer commerce_payment_gateway',
    ], parent::getAdministratorPermissions());
  }

  /**
   * Tests creating an Accept.JS payment gateway.
   */
  public function testCreateAcceptJsGateway() {
    $this->drupalGet('admin/commerce/config/payment-gateways');
    $this->getSession()->getPage()->clickLink('Add payment gateway');
    $this->assertSession()->addressEquals('admin/commerce/config/payment-gateways/add');
    $this->saveHtmlOutput();
    $radio_button = $this->getSession()->getPage()->findField('Authorize.net (Accept.js)');
    // This is usually the default selection so only click it and wait for AJAX
    // if it is not selected.
    if (!$radio_button->isSelected()) {
      $radio_button->click();
      $this->assertSession()->assertWaitOnAjaxRequest();
    }
    // Populate the label / machine name first.
    $this->getSession()->getPage()->fillField('label', 'Authorize.net AcceptJS');
    $this->assertJsCondition('jQuery(".machine-name-value:visible").length > 0');
    $api_configuration = $this->getApiConfiguration();
    $values = [
      'configuration[authorizenet_acceptjs][api_login]' => $api_configuration['api_login'],
      'configuration[authorizenet_acceptjs][transaction_key]' => $api_configuration['transaction_key'],
      'configuration[authorizenet_acceptjs][client_key]' => $api_configuration['client_key'],
      'configuration[authorizenet_acceptjs][mode]' => 'test',
      'status' => '1',
    ];
    $this->submitForm($values, 'Save');
    $this->assertSession()->pageTextContains('Saved the Authorize.net AcceptJS payment gateway.');
    $payment_gateway = PaymentGateway::load('authorize_net_acceptjs');
    $this->assertEquals('authorize_net_acceptjs', $payment_gateway->id());
    $this->assertEquals('Authorize.net AcceptJS', $payment_gateway->label());
    $this->assertEquals('authorizenet_acceptjs', $payment_gateway->getPluginId());
    $this->assertEquals(TRUE, $payment_gateway->status());
    $payment_gateway_plugin = $payment_gateway->getPlugin();
    $this->assertEquals('test', $payment_gateway_plugin->getMode());
    $config = $payment_gateway_plugin->getConfiguration();
    $this->assertEquals($api_configuration['api_login'], $config['api_login']);
    $this->assertEquals($api_configuration['transaction_key'], $config['transaction_key']);
    $this->assertEquals($api_configuration['client_key'], $config['client_key']);
  }

  /**
   * Tests creating an ECheck payment gateway.
   */
  public function testCreateEcheckGateway() {
    $this->drupalGet('admin/commerce/config/payment-gateways');
    $this->getSession()->getPage()->clickLink('Add payment gateway');
    $this->assertSession()->addressEquals('admin/commerce/config/payment-gateways/add');
    $radio_button = $this->getSession()->getPage()->findField('Authorize.net (Echeck)');
    $radio_button->click();
    $this->assertSession()->assertWaitOnAjaxRequest();
    // Populate the label / machine name first.
    $this->getSession()->getPage()->fillField('label', 'Authorize.net Echeck');
    $this->assertJsCondition('jQuery(".machine-name-value:visible").length > 0');
    $api_configuration = $this->getApiConfiguration();
    $values = [
      'configuration[authorizenet_echeck][api_login]' => $api_configuration['api_login'],
      'configuration[authorizenet_echeck][transaction_key]' => $api_configuration['transaction_key'],
      'configuration[authorizenet_echeck][client_key]' => $api_configuration['client_key'],
      'configuration[authorizenet_echeck][mode]' => 'test',
      'status' => '1',
    ];
    $this->submitForm($values, 'Save');
    $this->assertSession()->pageTextContains('Saved the Authorize.net Echeck payment gateway.');
    $payment_gateway = PaymentGateway::load('authorize_net_echeck');
    $this->assertEquals('authorize_net_echeck', $payment_gateway->id());
    $this->assertEquals('Authorize.net Echeck', $payment_gateway->label());
    $this->assertEquals('authorizenet_echeck', $payment_gateway->getPluginId());
    $this->assertEquals(TRUE, $payment_gateway->status());
    $payment_gateway_plugin = $payment_gateway->getPlugin();
    $this->assertEquals('test', $payment_gateway_plugin->getMode());
    $config = $payment_gateway_plugin->getConfiguration();
    $this->assertEquals($api_configuration['api_login'], $config['api_login']);
    $this->assertEquals($api_configuration['transaction_key'], $config['transaction_key']);
    $this->assertEquals($api_configuration['client_key'], $config['client_key']);
  }

  /**
   * Tests creating a Visa Checkout payment gateway.
   */
  public function testCreateVisaCheckoutGateway() {
    $this->drupalGet('admin/commerce/config/payment-gateways');
    $this->getSession()->getPage()->clickLink('Add payment gateway');
    $this->assertSession()->addressEquals('admin/commerce/config/payment-gateways/add');
    $radio_button = $this->getSession()->getPage()->findField('Authorize.net (Visa Checkout)');
    $radio_button->click();
    $this->assertSession()->assertWaitOnAjaxRequest();
    // Populate the label / machine name first.
    $this->getSession()->getPage()->fillField('label', 'Authorize.net Visa Checkout');
    $this->assertJsCondition('jQuery(".machine-name-value:visible").length > 0');
    $api_configuration = $this->getApiConfiguration();
    $values = [
      'configuration[authorizenet_visa_checkout][api_login]' => $api_configuration['api_login'],
      'configuration[authorizenet_visa_checkout][transaction_key]' => $api_configuration['transaction_key'],
      'configuration[authorizenet_visa_checkout][client_key]' => $api_configuration['client_key'],
      // cspell:disable-next-line
      'configuration[authorizenet_visa_checkout][visa_checkout_api_key]' => 'I3IW0JTMU1JOIY90KBT721-L5zdL2VQ7GQ6tPA-xvjmOLO-Xo',
      'configuration[authorizenet_visa_checkout][mode]' => 'test',
      'status' => '1',
    ];
    $this->submitForm($values, 'Save');
    $this->assertSession()->pageTextContains('Saved the Authorize.net Visa Checkout payment gateway.');
    $payment_gateway = PaymentGateway::load('authorize_net_visa_checkout');
    $this->assertEquals('authorize_net_visa_checkout', $payment_gateway->id());
    $this->assertEquals('Authorize.net Visa Checkout', $payment_gateway->label());
    $this->assertEquals('authorizenet_visa_checkout', $payment_gateway->getPluginId());
    $this->assertEquals(TRUE, $payment_gateway->status());
    $payment_gateway_plugin = $payment_gateway->getPlugin();
    $this->assertEquals('test', $payment_gateway_plugin->getMode());
    $config = $payment_gateway_plugin->getConfiguration();
    $this->assertEquals($api_configuration['api_login'], $config['api_login']);
    $this->assertEquals($api_configuration['transaction_key'], $config['transaction_key']);
    $this->assertEquals($api_configuration['client_key'], $config['client_key']);
  }

  /**
   * Tests creating an Accept Hosted payment gateway.
   */
  public function testCreateAcceptHostedGateway() {
    $this->drupalGet('admin/commerce/config/payment-gateways');
    $this->getSession()->getPage()->clickLink('Add payment gateway');
    $this->assertSession()->addressEquals('admin/commerce/config/payment-gateways/add');
    $this->saveHtmlOutput();
    $radio_button = $this->getSession()->getPage()->findField('Authorize.net (Accept Hosted)');
    // This is usually the default selection so only click it and wait for AJAX
    // if it is not selected.
    if (!$radio_button->isSelected()) {
      $radio_button->click();
      $this->assertSession()->assertWaitOnAjaxRequest();
    }
    // Populate the label / machine name first.
    $this->getSession()->getPage()->fillField('label', 'Authorize.net Accept Hosted');
    $this->assertJsCondition('jQuery(".machine-name-value:visible").length > 0');
    $api_configuration = $this->getApiConfiguration();
    $values = [
      'configuration[authorizenet_accept_hosted][api_login]' => $api_configuration['api_login'],
      'configuration[authorizenet_accept_hosted][transaction_key]' => $api_configuration['transaction_key'],
      'configuration[authorizenet_accept_hosted][client_key]' => $api_configuration['client_key'],
      'configuration[authorizenet_accept_hosted][mode]' => 'test',
      'status' => '1',
    ];
    $this->submitForm($values, 'Save');
    $this->assertSession()->pageTextContains('Saved the Authorize.net Accept Hosted payment gateway.');
    $payment_gateway = PaymentGateway::load('authorize_net_accept_hosted');
    $this->assertEquals('authorize_net_accept_hosted', $payment_gateway->id());
    $this->assertEquals('Authorize.net Accept Hosted', $payment_gateway->label());
    $this->assertEquals('authorizenet_accept_hosted', $payment_gateway->getPluginId());
    $this->assertEquals(TRUE, $payment_gateway->status());
    $payment_gateway_plugin = $payment_gateway->getPlugin();
    $this->assertEquals('test', $payment_gateway_plugin->getMode());
    $config = $payment_gateway_plugin->getConfiguration();
    $this->assertEquals($api_configuration['api_login'], $config['api_login']);
    $this->assertEquals($api_configuration['transaction_key'], $config['transaction_key']);
    $this->assertEquals($api_configuration['client_key'], $config['client_key']);
  }

  /**
   * Gets the API configuration settings.
   *
   * @return array
   *   An associative array containing API configuration settings.
   */
  protected function getApiConfiguration(): array {
    return [
      'api_login' => getenv('COMMERCE_AUTHNET_TEST_API_LOGIN'),
      'transaction_key' => getenv('COMMERCE_AUTHNET_TEST_TRANSACTION_KEY'),
      'client_key' => getenv('COMMERCE_AUTHNET_TEST_CLIENT_KEY'),
    ];
  }

}
