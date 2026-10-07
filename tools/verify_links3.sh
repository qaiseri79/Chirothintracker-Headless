#!/bin/sh
echo "== chiropractor_active_ perms (manage intake) =="
drush config:get user.role.chiropractor_active_ permissions 2>/dev/null | grep -i "manage intake" || echo "(none)"
echo "== menu links for intake =="
drush sql:query "SELECT id, menu_name, title, weight, link__uri FROM menu_link_content_data WHERE link__uri LIKE '%intake%'"
echo "== admin login =="
drush user:login 1 --uri=http://127.0.0.1:55162