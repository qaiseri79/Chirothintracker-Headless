#!/bin/bash
cd /home/danielsudenfield/chirothintracker_react/web/modules/custom || exit 1
grep -rn "chiropractor_active_" --include='*.php' --include='*.yml' . \
  | grep -viE 'test|roster|docblock' | head -30