#!/bin/bash
# Parse every module YAML in headless_custom, plus php -l the access module.
#
# `php -l` treats YAML as inline HTML, so lint.sh does not validate it and a typo in
# a routing file is only found by a cache rebuild — which is the user's step, not
# ours. This catches the parse error here instead.
set -u

cd "$(dirname "$0")" || exit 1

fail=0
for f in $(find . -name '*.yml' -not -path '*/node_modules/*' | sort); do
  if python3 -c "import yaml,sys; yaml.safe_load(open(sys.argv[1]))" "$f" 2>/tmp/yamlerr; then
    echo "ok   $f"
  else
    echo "FAIL $f"
    cat /tmp/yamlerr
    fail=1
  fi
done

for f in $(find . -name '*.php' -not -path '*/node_modules/*' | sort); do
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