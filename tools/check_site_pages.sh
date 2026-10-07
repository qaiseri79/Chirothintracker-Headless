#!/bin/bash
for p in / /login /dashboard; do
  echo "== GET $p =="
  curl -s -o /dev/null -w 'HTTP %{http_code}\n' "http://127.0.0.1:3000$p"
done
echo '== home content =='
curl -s http://127.0.0.1:3000/ | grep -o 'ChiroThin\|Coming soon\|ChiroThin journey\|Log in to your portal' | sort -u
echo '== login content =='
curl -s http://127.0.0.1:3000/login | grep -o 'Log in\|you@example.com\|Logging in\|any email and password' | sort -u
echo '== dashboard content (SSR) =='
curl -s http://127.0.0.1:3000/dashboard | grep -o 'Loading\|Patient Dashboard' | sort -u