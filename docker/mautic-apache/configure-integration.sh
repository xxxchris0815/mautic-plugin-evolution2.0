#!/usr/bin/env bash
# Publish Evolution integration (defaults target the local mock API).
set -euo pipefail

export BASE_URL="${BASE_URL:-http://localhost:8080}"
export ADMIN_USER="${ADMIN_USER:-admin}"
export ADMIN_PASS="${ADMIN_PASS:-MauticAdmin123!}"
export EVOLUTION_API_URL="${EVOLUTION_API_URL:-http://host.docker.internal:8081}"
export EVOLUTION_API_KEY="${EVOLUTION_API_KEY:-test-evolution-key}"
export EVOLUTION_INSTANCE="${EVOLUTION_INSTANCE:-cloud-instance}"

python3 <<'PY'
import os
import re
import urllib.error
import urllib.parse
import urllib.request
import http.cookiejar

BASE = os.environ["BASE_URL"]
USER = os.environ["ADMIN_USER"]
PASS = os.environ["ADMIN_PASS"]
API_URL = os.environ["EVOLUTION_API_URL"]
API_KEY = os.environ["EVOLUTION_API_KEY"]
INSTANCE = os.environ["EVOLUTION_INSTANCE"]

cj = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))

login = opener.open(BASE + "/s/login").read().decode()
csrf = re.search(r'name="_csrf_token" value="([^"]+)"', login).group(1)
data = urllib.parse.urlencode(
    {"_username": USER, "_password": PASS, "_csrf_token": csrf}
).encode()
req = urllib.request.Request(BASE + "/s/login_check", data=data, method="POST")
try:
    opener.open(req)
except urllib.error.HTTPError:
    pass

form = opener.open(BASE + "/s/plugins/config/MauticEvolution").read().decode()
token = re.search(
    r'id="integration_details__token"[\s\S]*?value="([^"]+)"', form
).group(1)
payload = {
    "integration_details[isPublished]": "1",
    "integration_details[apiKeys][evolution_api_url]": API_URL,
    "integration_details[apiKeys][evolution_api_key]": API_KEY,
    "integration_details[apiKeys][evolution_instance]": INSTANCE,
    "integration_details[featureSettings][evolution_timeout]": "30",
    "integration_details[featureSettings][check_whatsapp_on_save]": "0",
    "integration_details[name]": "MauticEvolution",
    "integration_details[in_auth]": "0",
    "integration_details[buttons][save]": "",
    "integration_details[_token]": token,
}
req = urllib.request.Request(
    BASE + "/s/plugins/config/MauticEvolution",
    data=urllib.parse.urlencode(payload).encode(),
    method="POST",
)
req.add_header("Content-Type", "application/x-www-form-urlencoded")
req.add_header("Referer", BASE + "/s/plugins/config/MauticEvolution")
resp = opener.open(req)
body = resp.read().decode()
print(
    "save:",
    "enabled" if '"enabled":1' in body or '"enabled": 1' in body else body[:300],
)
print(f"Configured Evolution -> {API_URL} instance={INSTANCE}")
PY
