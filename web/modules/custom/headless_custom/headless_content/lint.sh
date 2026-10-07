#!/bin/bash
# Syntax-check every PHP file in headless_content, found rather than listed.
#
# Listing them by hand meant every new class had to be added here too, and a file
# that was never added was silently unchecked — which is the failure this script
# exists to prevent.
#
# `php -l` treats YAML as inline HTML, so this only really validates PHP. The YAML
# is verified by the user with a cache rebuild / route listing.
cd "$(dirname "$0")" || exit 1

fail=0
for f in $(find src tests *.php -name '*.php' 2>/dev/null | sort); do
  out=$(php -l "$f" 2>&1)
  if [ $? -ne 0 ]; then
    echo "FAIL $f"
    echo "$out"
    fail=1
  else
    echo "ok   $f"
  fi
done

exit $fail
