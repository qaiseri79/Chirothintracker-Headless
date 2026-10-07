# CTT GoHighLevel Integration

A Drupal module that automatically syncs license subscription activations and cancellations with GoHighLevel CRM.

## Features

- **License Subscription Tracking**: Automatically creates/updates contacts when license subscriptions become active
- **Cancellation Tracking**: Tags contacts when subscriptions are cancelled (payment failure or manual cancellation)
- **Configurable Tags**: Separate tags for active subscriptions and cancellations
- **Source Tracking**: Track where leads come from with customizable source values
- **Field Mapping**: Automatically maps Drupal user fields to GoHighLevel contact fields
- **Recurring Subscription Support**: Handles Drupal Commerce recurring subscriptions
- **API Connection Testing**: Built-in test to verify API credentials
- **Comprehensive Logging**: Detailed logs for troubleshooting and monitoring
- **GoHighLevel Automation Integration**: Tags trigger your automations for welcome sequences and follow-ups

## Requirements

- Drupal 10 or 11
- Drupal Commerce with Commerce Recurring (for subscriptions)
- Commerce License module
- GoHighLevel account with API access
- PHP 7.4 or higher
- Guzzle HTTP client (included in Drupal core)

## Installation

1. Download or clone this module into your Drupal `modules/custom` directory
2. Enable the module:
   ```bash
   drush en ctt_gohighlevel
   ```
   Or via the UI: **Administration > Extend**

3. Configure the module at **Administration > Configuration > Web Services > CTT GoHighLevel**

## Configuration

### Getting Your API Credentials

1. Log in to your GoHighLevel account
2. Navigate to **Settings > Business Profile**
3. Find your **API Key** (starts with `sk-`)
4. Find your **Location ID** in your account settings

### Module Settings

Navigate to **Administration > Configuration > Web Services > CTT GoHighLevel**

#### API Settings
- **Enable GoHighLevel Integration**: Toggle the integration on/off
- **GoHighLevel API Key**: Your API key from GoHighLevel (required)
- **Location ID**: Your GoHighLevel Location ID (required)
- **Test Connection**: Click to verify your API credentials

#### License Subscription Settings
- **Active Subscription Tag**: Tag applied when a license subscription becomes active (default: "CTT - New Subscriber")
- **Cancelled Subscription Tag**: Tag applied when a subscription is cancelled (default: "CTT Cancelled")
- **Default Source**: Track where leads come from (default: "License Subscription")

### Field Mapping

The module automatically maps these Drupal user fields to GoHighLevel:

| Drupal Field | GoHighLevel Field | Notes |
|--------------|-------------------|-------|
| `mail` (email) | `email` | Required - used for lookup |
| `field_full_name` | `firstName`, `lastName` | Automatically split on space |
| `field_phone_number` | `phone` | Direct mapping |

## How It Works

### On License Subscription Activation
This happens when:
- A new subscription is created with state "active"
- An existing subscription state changes to "active"

Process:
1. Checks if integration is enabled
2. Verifies subscription type is "license"
3. Searches GoHighLevel for existing contact by email
4. **If contact doesn't exist**: Creates new contact with active subscription tag
5. **If contact exists**: Adds active subscription tag (if not already present)
6. Logs the activation event
7. GoHighLevel automation is triggered by the tag

### On License Subscription Cancellation
This happens when subscription state changes to "canceled" (reasons include):
- Payment failure
- Manual cancellation by user
- Manual cancellation by admin

Process:
1. Checks if integration is enabled
2. Verifies subscription type is "license"
3. Searches GoHighLevel for existing contact by email
4. **If contact doesn't exist**: Creates new contact with cancellation tag
5. **If contact exists**: Adds cancellation tag to existing tags
6. Logs the cancellation event
7. GoHighLevel automation is triggered by the tag

## Workflow Example

### New License Subscription Flow
1. User purchases a license subscription
2. Subscription state becomes "active"
3. Module searches for contact in GoHighLevel by email
4. Contact is created or updated with:
   - User details (name, email, phone)
   - Tag: "CTT - New Subscriber"
   - Source value: "License Subscription"
5. GoHighLevel automation triggered by "CTT - New Subscriber" tag sends welcome sequence

### Subscription Cancellation Flow
1. Subscription is cancelled (payment failure or manual)
2. Subscription state changes to "canceled"
3. Module finds contact in GoHighLevel
4. Contact is tagged with "CTT Cancelled"
5. GoHighLevel automation triggered by "CTT Cancelled" tag (if configured)

## API Endpoints Used

- **GET /contacts/** - Search for contacts by email
- **POST /contacts/** - Create new contacts
- **PUT /contacts/{contactId}** - Update existing contacts

## Logging

The module logs all actions to the `ctt_gohighlevel` log channel:

- License subscription activations
- License subscription cancellations
- Tag additions
- API connection failures
- Contact creation/updates

View logs at **Administration > Reports > Recent log messages** and filter by "ctt_gohighlevel"

## Troubleshooting

### Connection Test Fails

**Problem**: "Connection failed" message when testing API credentials

**Solutions**:
- Verify your API key is correct and starts with `sk-`
- Ensure Location ID is correct
- Check that your GoHighLevel subscription includes API access
- Verify your server can connect to `https://services.leadconnectorhq.com`

### Contacts Not Syncing on Subscription

**Problem**: Subscriptions are activated but contacts aren't updated in GoHighLevel

**Solutions**:
- Verify integration is enabled in settings
- Check that the subscription type is "license"
- Ensure subscriptions are reaching "active" state
- Review logs for error messages (Reports > Recent log messages > filter by ctt_gohighlevel)
- Verify Location ID is set correctly
- Verify API key has permission to create/update contacts

### Tags Not Applying

**Problem**: Tags aren't being added to contacts in GoHighLevel

**Solutions**:
- Verify subscription_tag and cancellation_tag are configured
- Check that tags are properly formatted (no extra spaces)
- Review GoHighLevel logs to see if tag is being received
- Ensure tags exist in your GoHighLevel account (they'll be created if not)
- Check module logs for API errors

### Subscription Not Detected

**Problem**: Module doesn't detect license subscriptions

**Solutions**:
- Verify subscription type is exactly "license"
- Check that Commerce Recurring and Commerce License modules are enabled
- Review subscription entity structure in database
- Enable debug logging and check for errors

### Multiple Tags Added

**Problem**: Same tag added multiple times

**Solution**: The module checks for existing tags before adding. If duplicates appear:
- Check logs to see if multiple events are firing
- Verify subscription state transitions are working correctly
- Ensure only one instance of the module is installed

## Setting Up GoHighLevel Automations

### Welcome Automation (Active Subscriptions)
1. In GoHighLevel, create a new workflow
2. Set trigger: "Contact Tag Added"
3. Select tag: "CTT - New Subscriber"
4. Add your welcome sequence (emails, SMS, tasks, etc.)
5. Add notification actions if you want to be notified
6. Save and activate the workflow

### Cancellation Follow-up
1. In GoHighLevel, create a new workflow
2. Set trigger: "Contact Tag Added"
3. Select tag: "CTT Cancelled"
4. Add your follow-up sequence (surveys, win-back offers, etc.)
5. Add notification actions if you want to be notified
6. Save and activate the workflow

## Security Considerations

- API keys are stored in Drupal configuration
- All API calls use HTTPS
- API key is only visible to users with "administer site configuration" permission
- Consider using Drupal's config encryption module for additional security

## Performance

- API calls are made synchronously during subscription state changes
- Each operation typically takes 200-500ms
- For high-volume sites, consider implementing a queue system
- The module checks for existing tags before adding to minimize API calls

## Customization

### Adding Custom Fields

To map additional Drupal user fields to GoHighLevel:

1. Edit `ctt_gohighlevel.module`
2. In the `_ctt_gohighlevel_prepare_contact_data()` function
3. Add field checks similar to existing fields:

```php
// Add custom field
if ($account->hasField('field_custom') && !$account->get('field_custom')->isEmpty()) {
  $contact_data['customField'] = $account->get('field_custom')->value;
}
```

### Changing Subscription Type

If you need to handle a different subscription type besides "license":

1. Edit `ctt_gohighlevel.module`
2. In the `_ctt_gohighlevel_is_license_subscription()` function
3. Update the type check:

```php
// Change 'license' to your subscription type machine name
return $subscription_type === 'your_type_name';
```

### Customizing Notification Emails

If you want to receive email notifications, set them up in GoHighLevel automations:
1. In your workflow triggered by "CTT - New Subscriber" or "CTT Cancelled"
2. Add an "Send Email" or "Send Internal Notification" action
3. Configure the notification with the details you want
4. This way all notifications are handled in one place (GoHighLevel)

## Support

For issues, questions, or feature requests:
- Check the Drupal logs first (Reports > Recent log messages)
- Review the GoHighLevel API documentation: https://marketplace.gohighlevel.com/docs
- Ensure all requirements are met
- Verify Drupal Commerce and Commerce Recurring are properly configured
- Verify Commerce License module is installed and configured

## Developer Notes

### Service Architecture
- Main API service: `ctt_gohighlevel.api`
- Uses Guzzle HTTP client
- Implements proper error handling and logging

### Commerce Integration
- Hooks into `commerce_subscription_insert` for new subscriptions
- Hooks into `commerce_subscription_update` for state changes
- Checks subscription type for "license"
- Monitors state transitions to "active" and "canceled"

### Subscription States
The module responds to these subscription states:
- **active**: Triggers contact creation/update with subscription tag
- **canceled**: Triggers contact tagging with cancellation tag

### API Version
- Uses GoHighLevel API version: `2021-07-28`
- Base URL: `https://services.leadconnectorhq.com`

### Testing
Before deploying to production:
1. Test API connection in settings
2. Create a test license subscription
3. Verify it reaches "active" state
4. Verify contact is created/updated in GoHighLevel with correct tag
5. Verify GoHighLevel automation is triggered (if configured)
6. Cancel the test subscription
7. Verify cancellation tag is added
8. Verify GoHighLevel automation is triggered (if configured)
9. Review logs for any errors

## Uninstallation

When uninstalling the module:
1. All configuration will be removed
2. Existing contacts in GoHighLevel will remain
3. No data is deleted from Drupal

To uninstall:
```bash
drush pm:uninstall ctt_gohighlevel
```

## License

This module is provided as-is for use with CTT projects.

## Version History

- **1.0.0** - Initial release
  - License subscription activation tracking with "CTT - New Subscriber" tag
  - License subscription cancellation tracking with "CTT Cancelled" tag
  - Configurable tags and source
  - API connection testing
  - Comprehensive logging
  - Support for recurring subscriptions
  - GoHighLevel automation integration

## Credits

Developed for Chirothintracker
