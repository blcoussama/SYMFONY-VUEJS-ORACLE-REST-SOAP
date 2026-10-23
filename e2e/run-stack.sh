#!/bin/bash
# Lance NetOps pour les tests navigateur, en conditions proches de la production :
#   base SQLite dédiée (var/data_e2e.db) recréée + données de démonstration (APP_ENV=dev),
#   puis application servie en APP_ENV=prod (assets compilés par AssetMapper) par le serveur PHP intégré.
#
#   E2E_PORT  port HTTP (défaut 8090)
#
# Exemple : npm run stack && npm test && npm run screenshots ; npm run stop
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
TMP="$HERE/.tmp"
PORT="${E2E_PORT:-8090}"
mkdir -p "$TMP"

if [ -f "$TMP/pids" ]; then xargs -r kill < "$TMP/pids" 2>/dev/null || true; rm -f "$TMP/pids"; sleep 1; fi
if curl -s -m 2 -o /dev/null "http://127.0.0.1:$PORT/"; then echo "Port $PORT déjà utilisé."; exit 1; fi

# Sans fichier .env (dépôt fraîchement cloné), les valeurs par défaut de config/ suffisent.
[ -f "$ROOT/.env" ] || export APP_RUNTIME_OPTIONS='{"disable_dotenv":true}'
export DATABASE_URL="sqlite:///$ROOT/var/data_e2e.db"
export DEFAULT_URI="http://127.0.0.1:$PORT"

cd "$ROOT"
APP_ENV=dev php bin/console doctrine:schema:drop --force --full-database -q
APP_ENV=dev php bin/console doctrine:schema:create -q
APP_ENV=dev php bin/console doctrine:fixtures:load -n -q
APP_ENV=dev php bin/console cache:pool:clear cache.rate_limiter -q

export APP_ENV=prod APP_DEBUG=0
APP_SECRET="$(php -r 'echo bin2hex(random_bytes(24));')"
export APP_SECRET
php bin/console importmap:install -q
php bin/console asset-map:compile -q
php bin/console cache:clear -q

# « exec » : le PID enregistré est celui du serveur PHP lui-même.
(cd "$ROOT" && exec nohup php -d variables_order=EGPCS -S "127.0.0.1:$PORT" -t public "$HERE/router.php" > "$TMP/server.log" 2>&1) &
echo $! >> "$TMP/pids"

for _ in $(seq 1 30); do
  code=$(curl -s -m 3 -o /dev/null -w "%{http_code}" "http://127.0.0.1:$PORT/api/v1/health" || true)
  if [ "$code" = "200" ]; then echo "NetOps prêt : http://127.0.0.1:$PORT"; exit 0; fi
  sleep 1
done
echo "Délai dépassé (health=$code)"; tail -20 "$TMP/server.log"; exit 2
