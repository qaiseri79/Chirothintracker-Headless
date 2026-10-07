#!/bin/sh
echo "== permission rows for chiropractor_active_ =="
drush sql:query "SELECT permission FROM role_permission WHERE role='chiropractor_active_' AND permission LIKE '%intake%invite%'"
echo "== menu link exists =="
drush sql:query "SELECT id, menu_name, title, weight, uri FROM menu_link_content_data WHERE uri LIKE '%intake-links%'"
echo "== route /manage/intake-links accessible by perm =="
drush sql:query "SELECT permission FROM role_permission WHERE role='anonymous' AND permission='manage intake invite links'" "(should be empty)"
echo "== login urls =="
drush user:login 1 --uri=http://127.0.0.1:55162