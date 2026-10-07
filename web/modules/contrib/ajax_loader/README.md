DESCRIPTION
-----------

This module allows you to change the default drupal ajax throbber.


REQUIREMENTS
------------

This module requires no modules outside of Drupal core.


INSTALLATION
------------

Install this module as you would normally install a contributed
Drupal module. Visit https://www.drupal.org/node/1897420 for further
information.


CONFIGURATION
-------------

To enable and configure this module do the following:

    1. Go to Admin -> Extend, and enable "Ajax loader".

    2. Go to Admin -> Configuration -> User Interface -> Ajax loader,
       and make any necessary configuration changes.

    3. You are done, the default ajax throbber is now sexy!


EXTEND
------

Define your own "throbber" plugins to add custom throbbers.
See `src/Plugin/ajax_loader` for examples.
