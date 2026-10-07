## Views: Configuration Entities

Exposes configuration entities to views. Select which configuration entities
you would like exposed, and then you can create a new view, listing or
displaying those specific entities.

### Requirements

Requires Drupal core Views.

### Install

Installing via composer is recommended. See below:

```bash
composer require drupal/config_view
```

* Install as you would normally install a contributed Drupal module.


### Configuration

* Once module is enabled, you can visit the following page to
configure which configuration entities you would like exposed to Views:
**/admin/structure/views/settings/config_view**


* After saving configuration, visit the "Views" page:
**/admin/structure/views**

* Add new view.

* Under View Settings, select the configuration entity from the "Show"
select field. The configuration entities that you exposed, should now
appear in this list.

* Save & edit your view.

* Now you should be able to add fields to your view or change as you
see fit.


### Maintainers

* George Anderson (geoanders) - https://www.drupal.org/u/geoanders
* I. Peric - https://drupal.org/user/3430153
