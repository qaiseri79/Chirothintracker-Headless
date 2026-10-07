#!/usr/bin/env bash
# Throwaway: run the headless_intake unit tests.
cd /app/web || exit 1
php /app/vendor/bin/phpunit -c core/phpunit.xml.dist \
  modules/custom/headless_custom/headless_intake 2>&1
echo "exit=$?"