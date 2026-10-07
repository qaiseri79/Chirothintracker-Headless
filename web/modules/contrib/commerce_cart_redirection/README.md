## Commerce Cart Redirection

Installation
* Same as standard Drupal modules. Composer is ideal.

Set up
* When first installed the module won't redirect any requests.
* To redirect add to cart events navigate to /admin/commerce/config/commerce_cart_redirection
and either select all Product Variation Bundles, OR select the 'Negate the Bundles condition' box and submit
the form.  **NOTE** if you both select all product bundles AND negate then you will end up with
nothing being redirected.
* You can redirect to Checkout, Cart or your own arbitrary URL. When entering your own URL you must check that it works,
 the module doesn't do this for you.
* You can select to remove all order items except the item currently being added. This will allow your users
to add multiples of one variant type eg 3 x Red T-shirt will be added and all other items removed.
* If you need to limit customers to a single (or other) quantity of a specific item you should use   
  https://www.drupal.org/project/commerce_product_limits

  
Thanks to Steve Oliver for the inspiration for this module:
* https://www.drupal.org/u/steveoliver
* https://steveoliver.github.io/2017/11/09/drupal-commerce-2-checkout-after-add-to-cart.html

