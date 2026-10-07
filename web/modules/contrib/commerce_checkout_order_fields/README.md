# Commerce checkout order

Commerce Checkout Order Fields exposes order form display modes as checkout panes, allowing you to collect additional order data during checkout.

The module provides a new checkout form view mode on orders so that at least one checkout pane is available. Customizing the form display mode will expose those fields at checkout

For a full description of the module, visit the
[project page](https://www.drupal.org/project/commerce_checkout_order_fields).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/commerce_checkout_order_fields).


## Table of contents

- Requirements
- Installation
- Maintainers


## Requirements

This module requires the following modules:

- [Commerce](https://drupal.org/project/commerce)


## Installation

Install as you would normally install a contributed Drupal module. For further
information, see
[Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).


## Configuration

- Open your order type in commerce configuration and switch to tab "Manage fields" (e.g. /admin/commerce/config/order-types/default/edit/fields).
- Now add the fields that you wish to add to your checkout process. E.g. add a "Text (plain, long)" field to allow users to provide order comments.
- You can create multiple fields but they will appear all at one defined location in the checkout process later.
- Next the display of the fields is configured. Switch to tab "Manage form display" (e.g. /admin/commerce/config/order-types/default/edit/form-display).
- Here activate the custom display settings for "Checkout" at the end of the page. Then save.
- Now you can edit the "Checkout" display settings (e.g. /admin/commerce/config/order-types/default/edit/form-display/checkout).
- Here disable all fields except your new fields.
- Finally, you can specify where the new fields should be displayed. This is on the checkout flow configuration page (e.g. /admin/commerce/config/checkout-flows/manage/default).
- Move the pane "Order fields: Checkout" to the right section, e.g. "Review".

Now you should see your new fields during the checkout process!
