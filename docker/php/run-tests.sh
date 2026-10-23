#!/bin/sh
# Suite PHPUnit de l'image « test », contre la base de DATABASE_URL (schéma DÉDIÉ aux tests :
# il est vidé).
#   1. attend la base ;
#   2. NETOPS_TEST_MIGRATIONS=1 (défaut) : vide le schéma, applique les migrations de la
#      plateforme courante, puis vérifie qu'elles produisent exactement le schéma du mapping
#      Doctrine (doctrine:schema:validate) ;
#   3. lance PHPUnit (arguments transmis) ; tests/bootstrap.php recrée alors le schéma depuis
#      le mapping et charge les fixtures.
set -eu

netops-wait-db

if [ "${NETOPS_TEST_MIGRATIONS:-1}" = '1' ]; then
  php bin/console --no-interaction doctrine:schema:drop --force --full-database
  php bin/console --no-interaction doctrine:migrations:migrate --allow-no-migration
  php bin/console --no-interaction doctrine:schema:validate
fi

exec php vendor/bin/phpunit "$@"
