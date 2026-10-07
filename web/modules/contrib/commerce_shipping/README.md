Commerce Shipping
=================

Provides shipping functionality for Drupal Commerce.

## Setup

1. Install the module.

2. Edit the product variation type(s) (Commerce -> Configuration -> Products ->
   Product variation types) and enable the 'Shippable' trait on each product
   variation type that is to be shippable.

3. Edit the order type (Commerce -> Configuration -> Orders -> Order types):
  - Check the "Enable shipping for this order type" option.
  - Select one of the "Shipping type" options.
  - Select the "Shipping" checkout flow.
    - Alternatively, edit the "Default" checkout flow to move the "Shipping
      information" pane to the "Order information" section.

4. If using Commerce Tax, create a "Shipping" tax type.
   This will ensure that shipping costs are taxed.
