#!/usr/bin/env bash
curl -s -b /tmp/cookies.txt http://127.0.0.1:3000/dashboard | python3 -c '
import sys, re
html = sys.stdin.read()
matches = re.findall(r"(Goal Weight|Net Weight|Goal Achieved|Overall Weight|programDay|goalPercent|entries|180\.|23\.4|56|5\.29|Jícama|Vegan|Omar|Cid|Day \d+)", html)
print(matches)
'