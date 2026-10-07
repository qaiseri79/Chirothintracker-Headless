<?php

namespace Drupal\Tests\commerce_recurring\Functional;

/**
 * Tests normal user operations with subscriptions.
 *
 * @group commerce_recurring
 */
class SubscriptionUserTest extends SubscriptionBrowserTestBase {


  /**
   * A test user with normal privileges.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $permissions = [
      'view own commerce_subscription',
    ];

    $this->user = $this->drupalCreateUser($permissions);
    $this->drupalLogin($this->user);
  }

  /**
   * Tests viewing a customer order page.
   */
  public function testViewCustomerOrder() {
    $uid = $this->loggedInUser->id();
    /** @var \Drupal\commerce_recurring\Entity\SubscriptionInterface $subscription */
    $subscription = $this->createEntity('commerce_subscription', [
      'title' => $this->randomString(),
      'uid' => $uid,
      'billing_schedule' => $this->billingSchedule,
      'type' => 'product_variation',
      'purchased_entity' => $this->variation,
      'store_id' => $this->store->id(),
      'unit_price' => $this->variation->getPrice(),
      'starts' => time() + 3600,
      'trial_starts' => time(),
      'state' => 'active',
    ]);
    // Check that we can view the orders page.
    $this->drupalGet('/user/' . $uid . '/subscriptions/');
    $this->assertSession()->statusCodeEquals(200);

    // Verify the subscription can be viewed.
    $this->assertSession()->linkByHrefExists('/user/' . $uid . '/subscriptions/' . $subscription->id());
    $this->drupalGet('/user/' . $uid . '/subscriptions/' . $subscription->id());
    $this->assertSession()->statusCodeEquals(200);

    // Click subscription and make sure it works.
    $this->drupalGet('/user/' . $uid . '/subscriptions/');
    $this->getSession()->getPage()->clickLink($subscription->getTitle());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($subscription->getTitle());
  }

}
