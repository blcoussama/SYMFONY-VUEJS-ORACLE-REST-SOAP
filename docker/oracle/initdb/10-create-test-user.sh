#!/bin/bash
# Exécuté une seule fois, à la première initialisation de la base, par
# l'entrypoint de l'image gvenzl/oracle-xe (/container-entrypoint-initdb.d).
# Crée un second schéma, réservé à la suite PHPUnit, pour que les tests
# (qui suppriment et recréent les tables) ne touchent pas aux données de démo.
set -Eeuo pipefail

if [ -z "${ORACLE_TEST_USER:-}" ] || [ -z "${ORACLE_TEST_PASSWORD:-}" ]; then
  echo "NETOPS: ORACLE_TEST_USER / ORACLE_TEST_PASSWORD absents, pas de schéma de test."
  exit 0
fi

/opt/oracle/createAppUser "${ORACLE_TEST_USER}" "${ORACLE_TEST_PASSWORD}" "${ORACLE_DATABASE:-XEPDB1}"
echo "NETOPS: schéma de test ${ORACLE_TEST_USER} créé."
