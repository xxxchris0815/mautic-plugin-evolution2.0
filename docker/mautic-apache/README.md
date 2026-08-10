# Mautic Apache Docker test harness

Local stack to verify `MauticEvolutionBundle` on official `mautic/mautic:7-apache`.

## Start

```bash
cd docker/mautic-apache
docker compose up -d
```

Services:

- Mautic: http://localhost:8080 (`admin` / `MauticAdmin123!`)
- MySQL: `localhost:3306` (`mautic` / `mautic`, root `rootpass`)
- Mock Evolution API v2: http://localhost:8081 (`apikey: test-evolution-key`)

The plugin source is bind-mounted at `/var/www/html/docroot/plugins/MauticEvolutionBundle`.

Mautic reaches MySQL and the mock API through `host.docker.internal` (published host ports) because container bridge networking is unreliable in some Cloud Agent VMs.

## Configure plugin

In **Settings → Plugins → Evolution Plugin**:

- API URL: `http://host.docker.internal:8081`
- API Key: `test-evolution-key`
- Instance: `cloud-instance`
- Publish / enable the integration

Or run:

```bash
./configure-integration.sh
```

## Smoke tests

HTTP + UI/AJAX:

```bash
./smoke-test.sh
```

API calls via the plugin service (inside the Mautic container):

```bash
docker compose exec mautic_web php /var/www/html/docroot/plugins/MauticEvolutionBundle/docker/mautic-apache/smoke-api.php
```
