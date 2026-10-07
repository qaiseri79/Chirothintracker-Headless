#!/usr/bin/env bash
cd /app/web || exit 1
php /app/vendor/bin/phpunit -c core/phpunit.xml.dist \
  modules/custom/headless_custom/headless_patients 2>&1
echo "exit=$?"