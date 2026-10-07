Migrate: Skip on 404
--------------------
Normally when a file is being migrated using core's APIs during an upgrade it
uses the file_copy process plugin to copy from a remote server to the current
site. If the file doesn't exist this will result in a migration failure, which
will cause the current migration step to fail.

This module services two purposes:

1. It provides a plugin called "skip_on_404". Adding this to the process steps
   on a file migration will cause the record to do a "skip" instead of a "fail",
   which lets the migration continue instead of stoppping. A message will be
   left for that record, so it will still be easy to identify which files were
   missing.

2. Integration with core's Migrate Drupal UI and the contrib Migrate Upgrade
   module to automatically add the skip_on_404 plugin to the normal file
   migration. This does not require any configuration to enable it, as soon as
   the module is enabled it will modify the migration system from Migrate Drupal
   UI or Migrate Upgrade, whichever is available.

This works with both public and private files.


Related modules
--------------------------------------------------------------------------------
The following modules are very useful for dealing with migrations:

* Migrate Upgrade
  https://www.drupal.org/project/migrate_upgrade
  Lets you run the Drupal 6 or 7 upgrade process to D8 or 9 via Drush.

* Migrate Tools
  https://www.drupal.org/project/migrate_tools
  Provides Drush integration for running Migrate commands.


Credits / contact
--------------------------------------------------------------------------------
Currently maintained by Damien McKenna [1] and sorlov [2], using the plugin
code originally writte by sorlov.

The best way to contact the authors is to submit an issue, be it a support
request, a feature request or a bug report, in the project issue queue:
  https://www.drupal.org/project/issues/migrate_skip_on_404


References
--------------------------------------------------------------------------------
1: https://www.drupal.org/u/damienmckenna
2: https://www.drupal.org/u/sorlov
