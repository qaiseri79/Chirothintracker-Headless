# Drupal 8 - Hide Preview

A Drupal8 module to hide the preview button on some forms.

Hide Preview hides the preview button on some forms. Use regexp to select the forms where you want to hide the preview button.

## Installation

* Install as you would normally install a contributed Drupal module. Visit https://www.drupal.org/node/1897420 for further information.

## Requirements

This module requires no modules outside of Drupal core.

## Configuration

1. Navigate to Configuration > Hide Preview (`/admin/config/hide_preview`).
2. Fill in the form names for which you want to hide the button.

You can either use a string that represent the beginning of the the `form_id` or a regular expression that the `form_id` must match.

## Example

As an example: for the contact form, you can use the following regexp. `/contact_message_*/`
