#!/bin/sh
# Attend que la base désignée par DATABASE_URL réponde (DB_WAIT_TIMEOUT secondes, 120 par défaut).
set -eu

timeout="${DB_WAIT_TIMEOUT:-120}"
case "${DATABASE_URL:-}" in
  oci8://*|oracle://*) ping_sql='SELECT 1 FROM DUAL' ;;
  *) ping_sql='SELECT 1' ;;
esac

start=$(date +%s)
until php bin/console --no-interaction dbal:run-sql -q "${ping_sql}" > /dev/null 2>&1; do
  if [ $(( $(date +%s) - start )) -ge "${timeout}" ]; then
    echo "netops: base injoignable après ${timeout} s" >&2
    php bin/console --no-interaction dbal:run-sql "${ping_sql}" >&2 || true
    exit 1
  fi
  echo "netops: attente de la base de données..."
  sleep 3
done
echo "netops: base de données joignable."
