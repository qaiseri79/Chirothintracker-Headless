#!/bin/sh
apachectl -S 2>&1 | head -12
echo ---
ls /etc/apache2/mods-enabled/ | grep -i rewrite || echo "no rewrite mod file"
echo ---
echo "GET / -> $(curl -s -o /dev/null -w '%{http_code}' http://localhost/)"
echo "GET /index.php -> $(curl -s -o /dev/null -w '%{http_code}' http://localhost/index.php)"
echo "GET /user/login -> $(curl -s -o /dev/null -w '%{http_code}' http://localhost/user/login)"
echo "GET /api -> $(curl -s -o /dev/null -w '%{http_code}' http://localhost/api/intake/invite/4c903765bd3652912b67d41a86110039893b)"
echo ---
tail -8 /var/log/apache2/error.log 2>/dev/null