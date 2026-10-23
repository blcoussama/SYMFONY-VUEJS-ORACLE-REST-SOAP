#!/bin/sh
# Point d'entrée de l'image PHP NetOps.
# Pour php-fpm uniquement (toute autre commande est lancée telle quelle) :
#   - RUN_MIGRATIONS=1 : attend la base puis applique les migrations Doctrine
#     (celles d'une autre plateforme sont ignorées par leur skipIf) ;
#   - LOAD_FIXTURES=1  : recharge les fixtures de démonstration (PURGE la base ;
#     DoctrineFixturesBundle n'existe que dans les cibles dev/test).
set -eu

if [ "${1#-}" != "$1" ]; then
  set -- php-fpm "$@"
fi

if [ "$1" = 'php-fpm' ]; then
  if [ "${RUN_MIGRATIONS:-0}" = '1' ] || [ "${LOAD_FIXTURES:-0}" = '1' ]; then
    netops-wait-db
  fi

  if [ "${RUN_MIGRATIONS:-0}" = '1' ]; then
    php bin/console --no-interaction doctrine:migrations:migrate --allow-no-migration
  fi

  if [ "${LOAD_FIXTURES:-0}" = '1' ]; then
    if php bin/console list --raw | grep -q '^doctrine:fixtures:load '; then
      php bin/console --no-interaction doctrine:fixtures:load
    else
      echo "netops: LOAD_FIXTURES=1 ignoré (DoctrineFixturesBundle absent de cette image)." >&2
    fi
  fi
fi

exec "$@"
