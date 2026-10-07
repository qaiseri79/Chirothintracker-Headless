#!/usr/bin/env bash
cd /home/danielsudenfield/chirothintracker_react/frontend
setsid npm run dev > /tmp/next-dev2.log 2>&1 < /dev/null &
sleep 8
curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:3000/
echo
tail -20 /tmp/next-dev2.log