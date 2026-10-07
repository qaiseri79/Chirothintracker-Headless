INTRODUCTION
------------

This module allows restricting the purchase of products for customers,
disabling purchase at the point of adding to cart
and displaying a message in place of the add to cart button.

A field referencing these restrictions can be added
to any product type or product variation type,
and when the product or product variation is edited
users can specify which conditions apply to purchase.

Restrictions can be applied on the product level
or product variation level, or both.

Included restriction plugins  can restrict purchase to:

- A specific date period
- Password protected
- Users with specified user roles
- Specific users
- Purchased quantity of the product or variation across all of the user's orders

INSTALLATION AND USE
--------------------

- Install and enable the module
- To a product type or product variation type,
  add a field of type Plugin -> Product restriction
- Important! In the "Manage form display", make sure
  the field has "Product restrictions" set as the widget.
- Edit a product or variation of that type to add restrictions.

CREATING A CUSTOM PLUGIN
------------------------
This module creates a new ProductRestrictionPlugin type,
which extends the existing Commerce Condition plugin type.

You just need to create a @ProductRestrictionPlugin
under Plugin/Commerce/ProductRestriction in which you:

- specify an "evaluate" function to determine whether the user has access
- provide a settings form to provide detail for the conditions
- optionally specify an error message function

Plugin requirements:

- Must be placed in Plugin/Commerce/ProductRestriction
- Must implement ProductRestrictionPluginInterface

See the included plugins for examples.
