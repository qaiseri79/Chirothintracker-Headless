#!/bin/bash
# Stop anything on :3000, wipe .next, rebuild and restart cleanly.
set -u
cd /home/danielsudenfield/chirothintracker_react/frontend || exit 1

echo "=== stopping listeners on 3000 ==="
pkill -f 'next-server' 2>/dev/null && echo "killed next-server" || echo "none running"
pkill -f 'next start' 2>/dev/null && echo "killed next start" || true
sleep 3
ss -ltn 2>/dev/null | grep -q ':3000' && echo "WARNING: still listening" || echo "port 3000 free"

echo
echo "=== wiping .next ==="
rm -rf .next && echo "removed"

echo
echo "=== clean build ==="
if npm run build > /tmp/next-build.log 2>&1; then
  echo "BUILD_PASS"
else
  echo "BUILD_FAIL"
  tail -40 /tmp/next-build.log
  exit 1
fi

echo
echo "=== css emitted ==="
find .next/static -name '*.css' -printf '%s\t%p\n' 2>/dev/null

echo
echo "=== build id ==="
cat .next/BUILD_ID; echo

echo
echo "=== starting server ==="
nohup npm run start > /tmp/next-start.log 2>&1 &
echo "launched"

# Wait for readiness rather than guessing a fixed sleep.
for i in $(seq 1 40); do
  code=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:3000/design-preview/subscribe 2>/dev/null)
  if [ "$code" = "200" ]; then
    echo "ready after ${i}s (subscribe=$code)"
    break
  fi
  sleep 1
done

echo
echo "=== verify every referenced asset resolves ==="
curl -s http://127.0.0.1:3000/design-preview/subscribe \
  | grep -o '/_next/static/[^"]*\.css' | sort -u > /tmp/refs.txt
while read -r f; do
  [ -z "$f" ] && continue
  code=$(curl -s -o /tmp/a.css -w '%{http_code}' "http://127.0.0.1:3000$f")
  echo "$code  $(wc -c < /tmp/a.css)b  $f"
done < /tmp/refs.txt

echo
echo "=== index route ==="
curl -s -o /dev/null -w 'index=%{http_code}\n' http://127.0.0.1:3000/design-preview