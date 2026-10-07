#!/bin/sh
echo "== cr =="
drush cr 2>&1 | tail -2
echo "== permission on chiropractor_active_ =="
drush role:perm chiropractor_active_ | grep -i "intake invite links" || echo "NOT GRANTED"
echo "== admin login URL =="
drush user:login 1 --uri=http://127.0.0.1:55162