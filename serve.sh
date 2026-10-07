#!/bin/bash
# Start the preview server fully detached so it survives the launching session.
set -u
cd /home/danielsudenfield/chirothintracker_react/frontend || exit 1

pkill -f 'next-server' 2>/dev/null
pkill -f 'next start' 2>/dev/null
sleep 2

setsid nohup npm run start > /tmp/next-start.log 2>&1 < /dev/null &
disown 2>/dev/null || true

for i in $(seq 1 40); do
  code=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:3000/design-preview/subscribe 2>/dev/null)
  if [ "$code" = "200" ]; then
    echo "ready after ${i}s"
    exit 0
  fi
  sleep 1
done

echo "did not become ready"
tail -20 /tmp/next-start.log
exit 1