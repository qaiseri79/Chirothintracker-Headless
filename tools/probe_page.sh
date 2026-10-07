#!/bin/bash
echo "== identity markers in chiropractor page =="
grep -o 'drjohn[^"<]*' /tmp/chiro.html | sort -u | head -5
grep -o 'admin[^"<]*' /tmp/chiro.html | sort -u | head -5
echo "== how many option lines total in clinic select =="
python3 - <<'EOF'
import re
html = open('/tmp/chiro.html').read()
opts = re.findall(r'<option value="(\d+)"[^>]*>', html)
print("option count:", len(opts))
print("first 6:", opts[:6])
EOF