#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
BASE_URL="${BASE_URL:-http://localhost:8080}"
COOKIE_JAR="${COOKIE_JAR:-/tmp/mautic-evolution-smoke.cookies}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-MauticAdmin123!}"

pass=0
fail=0

ok() { echo "PASS: $*"; pass=$((pass + 1)); }
ko() { echo "FAIL: $*"; fail=$((fail + 1)); }

echo "== Evolution plugin smoke tests against ${BASE_URL} =="

code=$(curl -sS -o /tmp/smoke_home.html -w '%{http_code}' "${BASE_URL}/")
[[ "$code" == "200" || "$code" == "302" ]] && ok "Mautic HTTP responds ($code)" || ko "Mautic HTTP ($code)"

code=$(curl -sS -o /tmp/smoke_health.json -w '%{http_code}' "${BASE_URL}/webhook/evolution/health")
body=$(cat /tmp/smoke_health.json)
[[ "$code" == "200" && "$body" == *'"status":"ok"'* ]] && ok "webhook health" || ko "webhook health ($code) $body"

rm -f "$COOKIE_JAR"
csrf=$(curl -sS -c "$COOKIE_JAR" "${BASE_URL}/s/login" | sed -n 's/.*name="_csrf_token" value="\([^"]*\)".*/\1/p' | head -1)
curl -sS -b "$COOKIE_JAR" -c "$COOKIE_JAR" -X POST "${BASE_URL}/s/login_check" \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode "_username=${ADMIN_USER}" \
  --data-urlencode "_password=${ADMIN_PASS}" \
  --data-urlencode "_csrf_token=${csrf}" \
  -o /tmp/smoke_login.html -w '%{http_code}' | grep -qE '302|200' \
  && ok "admin login" || ko "admin login"

code=$(curl -sS -b "$COOKIE_JAR" -o /tmp/smoke_templates.html -w '%{http_code}' "${BASE_URL}/s/evolution/templates")
[[ "$code" == "200" ]] && ok "templates index" || ko "templates index ($code)"

code=$(curl -sS -b "$COOKIE_JAR" -o /tmp/smoke_config.html -w '%{http_code}' "${BASE_URL}/s/plugins/config/MauticEvolution")
[[ "$code" == "200" && "$(cat /tmp/smoke_config.html)" == *'evolution_instance'* ]] \
  && ok "integration config form" || ko "integration config form ($code)"

code=$(curl -sS -b "$COOKIE_JAR" -o /tmp/smoke_instances.json -w '%{http_code}' \
  "${BASE_URL}/s/evolution/ajax/instances")
body=$(cat /tmp/smoke_instances.json)
[[ "$code" == "200" && "$body" == *'baileys-instance'* && "$body" == *'cloud-instance'* ]] \
  && ok "ajax instances (all)" || ko "ajax instances ($code) $body"

code=$(curl -sS -b "$COOKIE_JAR" -o /tmp/smoke_instances_cloud.json -w '%{http_code}' \
  "${BASE_URL}/s/evolution/ajax/instances?cloudOnly=1")
body=$(cat /tmp/smoke_instances_cloud.json)
if [[ "$code" == "200" && "$body" == *'cloud-instance'* && "$body" != *'baileys-instance'* ]]; then
  ok "ajax instances cloudOnly filters Baileys"
else
  ko "ajax instances cloudOnly ($code) $body"
fi

code=$(curl -sS -b "$COOKIE_JAR" -o /tmp/smoke_tpl_cloud.json -w '%{http_code}' \
  "${BASE_URL}/s/evolution/ajax/templates/cloud-instance")
body=$(cat /tmp/smoke_tpl_cloud.json)
[[ "$code" == "200" && "$body" == *'welcome|en'* ]] \
  && ok "ajax templates for cloud instance" || ko "ajax templates cloud ($code) $body"

code=$(curl -sS -b "$COOKIE_JAR" -o /tmp/smoke_tpl_baileys.json -w '%{http_code}' \
  "${BASE_URL}/s/evolution/ajax/templates/baileys-instance")
body=$(cat /tmp/smoke_tpl_baileys.json)
[[ "$code" == "400" && "$body" == *'Baileys'* ]] \
  && ok "ajax templates rejects Baileys" || ko "ajax templates Baileys ($code) $body"

# Container-side service checks
if docker compose -f "${ROOT}/docker-compose.yml" ps --status running --services 2>/dev/null | grep -q mautic_web; then
  WEB=$(docker compose -f "${ROOT}/docker-compose.yml" ps -q mautic_web)
  docker exec "$WEB" bash -lc 'cd /var/www/html && php -r "
require \"vendor/autoload.php\";
use Symfony\Component\Dotenv\Dotenv;
" 2>/dev/null || true'
  docker exec "$WEB" bash -lc 'cd /var/www/html && php bin/console debug:router mautic_evolution_ajax_templates --no-interaction >/dev/null' \
    && ok "console router knows ajax templates" || ko "console router ajax templates"

  docker exec "$WEB" bash -lc 'cd /var/www/html && php bin/console dbal:run-sql "SELECT COUNT(*) AS c FROM evolution_messages" --no-interaction' \
    | grep -qiE 'c|[[:digit:]]' && ok "evolution_messages table queryable" || ko "evolution_messages table"

  docker exec "$WEB" bash -lc 'cd /var/www/html && php bin/console dbal:run-sql "SELECT COUNT(*) AS c FROM evolution_templates" --no-interaction' \
    | grep -qiE 'c|[[:digit:]]' && ok "evolution_templates table queryable" || ko "evolution_templates table"
fi

echo
echo "Result: ${pass} passed, ${fail} failed"
[[ "$fail" -eq 0 ]]
