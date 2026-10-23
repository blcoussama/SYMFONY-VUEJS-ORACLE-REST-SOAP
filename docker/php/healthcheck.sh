#!/bin/sh
# HEALTHCHECK de l'image PHP : interroge directement php-fpm en FastCGI (/ping).
set -eu
SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET \
  cgi-fcgi -bind -connect 127.0.0.1:9000 2>/dev/null | grep -q '^pong'
