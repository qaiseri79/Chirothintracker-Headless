<?php

namespace Drupal\Tests\commerce_authnet\FunctionalJavascript;

use Drupal\commerce\Response\NeedsRedirectException;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\Component\Serialization\Json;
use Symfony\Component\HttpFoundation\Request;

/**
 * Checkout and Payment test.
 *
 * @group commerce_authnet
 */
class AcceptHostedCheckoutAndPaymentTest extends AcceptHostedTestBase {

  /**
   * Tests whether a customer can check out.
   *
   * @param bool $authenticated
   *   Whether the customer is authenticated.
   *
   * @throws \Behat\Mink\Exception\ElementNotFoundException
   * @throws \Behat\Mink\Exception\ResponseTextException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Exception
   *
   * @dataProvider dataProviderUserAuthenticated
   */
  public function testCheckoutAndPayment(bool $authenticated): void {
    if ($authenticated) {
      $customer = $this->createUser();
      $this->drupalLogin($customer);
    }

    $this->drupalGet($this->product->toUrl()->toString());
    $this->submitForm([], 'Add to cart');
    $this->drupalGet('checkout/1');
    if (!$authenticated) {
      $this->submitForm([], 'Continue as Guest');
      $this->getSession()->getPage()->fillField('contact_information[email]', 'guest@example.com');
      $this->getSession()->getPage()->fillField('contact_information[email_confirm]', 'guest@example.com');
    }

    $this->submitForm([
      'payment_information[billing_information][address][0][address][given_name]' => 'Johnny',
      'payment_information[billing_information][address][0][address][family_name]' => 'Appleseed',
      'payment_information[billing_information][address][0][address][address_line1]' => '123 New York Drive',
      'payment_information[billing_information][address][0][address][locality]' => 'New York City',
      'payment_information[billing_information][address][0][address][administrative_area]' => 'NY',
      'payment_information[billing_information][address][0][address][postal_code]' => '10001',
    ], 'Continue to review');

    // Verify that the iframe is loaded.
    $iframe_id = 'accept-hosted-iframe';
    $this->getSession()->switchToIFrame($iframe_id);
    $this->assertSession()->waitForElement('css', "iframe#$iframe_id");
    $this->assertSession()->fieldExists('cardNum');
    $this->assertSession()->fieldExists('expiryDate');
    $this->assertSession()->fieldExists('cvv');
    $this->assertSession()->buttonExists('Pay');
    $this->assertSession()->buttonExists('cancelBtn');

    /** @var \Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway\AcceptHosted $plugin */
    $plugin = $this->gateway->getPlugin();
    $order = Order::load(1);

    // Create a transaction using the API instead of an iframe.
    $transaction_response = $this->createAcceptPaymentTransaction($order);
    $formatted_response = $this->formatTransactionResponse($transaction_response, $authenticated);
    $request = new Request(['response' => Json::encode($formatted_response)]);
    try {
      $plugin->onReturn($order, $request);
    }
    catch (NeedsRedirectException $e) {
      // Catch the redirect and continue with the checkout.
      $this->drupalGet("/checkout/{$order->id()}/complete");
    }

    $this->assertSession()->pageTextContains('Your order number is 1. You can view your order on your account page when logged in.');
    $this->assertEquals('completed', $order->getState()->getId());
    $order = Order::load(1);
    $this->assertEquals('authorizenet_accept_hosted', $order->get('payment_gateway')->target_id);
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $payment_method = $order->get('payment_method')->entity;
    $this->assertEquals('0015', $payment_method->get('card_number')->value);
    if ($authenticated) {
      $this->assertEquals(TRUE, $payment_method->isReusable());
    }
    else {
      $this->assertEquals(FALSE, $payment_method->isReusable());
    }
    // Verify that a payment was created.
    $payment = Payment::load(1);
    $this->assertNotNull($payment);
    $this->assertEquals($payment->getAmount(), $order->getTotalPrice());
    $this->assertEquals('completed', $payment->getState()->getId());
    $this->assertEquals($payment_method->id(), $payment->getPaymentMethodId());
    $this->assertEquals($order->id(), $payment->getOrderId());
  }

}
