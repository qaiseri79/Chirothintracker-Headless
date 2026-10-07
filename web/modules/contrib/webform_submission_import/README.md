## Webform Submission Import

Allows admin to upload a CSV of form submission data for import
into a forms existing submissions.

Install as normal. A new tab labeled "Import Submissions" will
be added to your webform
(i.e. /admin/structure/webform/manage/{my_form_id}).

From there, simply upload a CSV file with the first row containing
headers that match the field names from your form. Any extra columns
are ignored.

Form fields are validated on import, so any data from your CSV that
does not pass validation will be skipped and logged.

### Requirements
This module requires the Webform module:
https://www.drupal.org/project/webform

### Maintainers

George Anderson (geoanders)
https://www.drupal.org/u/geoanders

