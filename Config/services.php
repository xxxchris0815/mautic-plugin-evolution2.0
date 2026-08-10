<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    // Auto-load bundle classes, but exclude Models to define them explicitly with correct arguments
    $services->load('MauticPlugin\\MauticEvolutionBundle\\', '../')
        ->exclude('../{Config,Resources,Model,composer.json,MauticEvolutionBundle.php,README.md}');

    $services->set(MauticPlugin\MauticEvolutionBundle\Helper\WhatsAppTemplateHelper::class)
        ->public();

    // Evolution API service
    $services->set(MauticPlugin\MauticEvolutionBundle\Service\EvolutionApiService::class)
        ->public()
        ->args([
            service('mautic.helper.integration'),
            service('mautic.http.client'),
            service('monolog.logger.mautic'),
            service('mautic.helper.user'),
            service('doctrine.orm.entity_manager'),
            service(MauticPlugin\MauticEvolutionBundle\Helper\WhatsAppTemplateHelper::class),
        ]);
    $services->alias('mautic.evolution.service.evolution_api', MauticPlugin\MauticEvolutionBundle\Service\EvolutionApiService::class);

    // TemplateModel with explicit core dependencies
    $services->set(MauticPlugin\MauticEvolutionBundle\Model\TemplateModel::class)
        ->public()
        ->args([
            service('doctrine.orm.entity_manager'),
            service('mautic.security'),
            service('event_dispatcher'),
            service('router'),
            service('translator'),
            service('mautic.helper.user'),
            service('monolog.logger.mautic'),
            service('mautic.helper.core_parameters'),
        ]);
    $services->alias('mautic.evolution.model.template', MauticPlugin\MauticEvolutionBundle\Model\TemplateModel::class);

    // MessageModel requires LeadModel and EvolutionApiService before core dependencies
    $services->set(MauticPlugin\MauticEvolutionBundle\Model\MessageModel::class)
        ->public()
        ->args([
            service('mautic.lead.model.lead'),
            service('mautic.evolution.service.evolution_api'),
            service('doctrine.orm.entity_manager'),
            service('mautic.security'),
            service('event_dispatcher'),
            service('router'),
            service('translator'),
            service('mautic.helper.user'),
            service('monolog.logger.mautic'),
            service('mautic.helper.core_parameters'),
        ]);
    $services->alias('mautic.evolution.model.message', MauticPlugin\MauticEvolutionBundle\Model\MessageModel::class);

    // Form Types: explicitly wire dependencies and tag as form.type
    $services->set(MauticPlugin\MauticEvolutionBundle\Form\Type\SendTemplateActionType::class)
        ->public()
        ->args([
            service('mautic.evolution.service.evolution_api'),
        ])
        ->tag('form.type');

    $services->set(MauticPlugin\MauticEvolutionBundle\Form\Type\SendMessageActionType::class)
        ->public()
        ->args([
            service('mautic.evolution.service.evolution_api'),
        ])
        ->tag('form.type');
};