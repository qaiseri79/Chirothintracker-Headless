#!/bin/sh
echo "resolved from appserver: $(getent hosts chirothintrackerreact.lndo.site | head -1)"
ip=$(getent hosts chirothintrackerreact.lndo.site | awk '{print $1}' | head -1)
echo "IP: $ip"
echo "GET / via name -> $(curl -s -o /dev/null -w '%{http_code}' http://chirothintrackerreact.lndo.site/)"
echo "GET /user/login via name -> $(curl -s -o /dev/null -w '%{http_code}' http://chirothintrackerreact.lndo.site/user/login)"