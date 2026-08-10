<?php

/**
 * Run inside mautic_web after configure-integration.sh:
 *   php /var/www/html/docroot/plugins/MauticEvolutionBundle/docker/mautic-apache/smoke-api.php
 */

declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

$kernel = new AppKernel('prod', false);
$kernel->boot();
$api = $kernel->getContainer()->get('mautic.evolution.service.evolution_api');

$checks = [];
$checks['configured_instance'] = $api->getConfiguredInstance();
$checks['all_instances'] = array_values($api->getInstanceChoices(false));
$checks['cloud_instances'] = array_values($api->getInstanceChoices(true));
$checks['baileys_filtered'] = !in_array('baileys-instance', $checks['cloud_instances'], true)
    && in_array('cloud-instance', $checks['cloud_instances'], true);
$checks['templates_ok'] = (bool) ($api->findTemplates('cloud-instance')['success'] ?? false);
$checks['send_text_ok'] = (bool) ($api->sendTextMessage('491701234567', 'smoke')['success'] ?? false);
$checks['send_template_ok'] = (bool) (
    $api->sendTemplateMessage('491701234567', 'welcome', 'en', [], null, null, [], 'cloud-instance')['success'] ?? false
);
try {
    $api->sendTemplateMessage('491701234567', 'welcome', 'en', [], null, null, [], 'baileys-instance');
    $checks['baileys_blocked'] = false;
} catch (Throwable $e) {
    $checks['baileys_blocked'] = str_contains($e->getMessage(), 'Baileys')
        || str_contains($e->getMessage(), 'WHATSAPP-BUSINESS');
}

echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
$ok = $checks['baileys_filtered'] && $checks['templates_ok'] && $checks['send_text_ok']
    && $checks['send_template_ok'] && $checks['baileys_blocked'];
exit($ok ? 0 : 1);
