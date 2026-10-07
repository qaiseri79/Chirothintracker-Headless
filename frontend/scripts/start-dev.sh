#!/usr/bin/env bash
cd /home/danielsudenfield/chirothintracker_react/frontend
nohup npm run dev > /tmp/next-dev.log 2>&1 &
sleep 10
curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:3000/
echo
tail -10 /tmp/next-dev.log