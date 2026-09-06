<?php

declare(strict_types=1);

use MauticPlugin\MauticEvolutionBundle\Entity\EvolutionTemplate;
use MauticPlugin\MauticEvolutionBundle\Integration\MauticEvolutionIntegration;
use MauticPlugin\MauticEvolutionBundle\Security\Permissions\EvolutionPermissions;

return [
    'name'        => 'Evolution Bundle',
    'description' => 'WhatsApp messaging via Evolution API with templates, campaign tracking and reporting.',
    'version'     => '2.0.0',
    'author'      => 'Evolution Team',

    'routes' => [
        'main' => [
            'mautic_evolution_template_index' => [
                'path'       => '/evolution/templates/{page}',
                'controller' => 'MauticPlugin\MauticEvolutionBundle\Controller\TemplateController::indexAction',
            ],
            'mautic_evolution_template_action' => [
                'path'       => '/evolution/templates/{objectAction}/{objectId}',
                'controller' => 'MauticPlugin\MauticEvolutionBundle\Controller\TemplateController::executeAction',
            ],
            'mautic_evolution_template_view' => [
                'path'       => '/evolution/templates/view/{objectId}',
                'controller' => 'MauticPlugin\MauticEvolutionBundle\Controller\TemplateController::viewAction',
            ],
            'mautic_evolution_template_delete' => [
                'path'       => '/evolution/templates/delete/{objectId}',
                'controller' => 'MauticPlugin\MauticEvolutionBundle\Controller\TemplateController::deleteAction',
            ],
            'mautic_evolution_template_clone' => [
                'path'       => '/evolution/templates/clone/{objectId}',
                'controller' => 'MauticPlugin\MauticEvolutionBundle\Controller\TemplateController::cloneAction',
            ],
            'mautic_evolution_template_toggle' => [
                'path'       => '/evolution/templates/toggle/{objectId}',
                'controller' => 'MauticPlugin\MauticEvolutionBundle\Controller\TemplateController::toggleAction',
            ],
            'mautic_evolution_template_preview' => [
                'path'       => '/evolution/templates/preview/{objectId}',
                'controller' => 'MauticPlugin\MauticEvolutionBundle\Controller\TemplateController::previewAction',
            ],
            'mautic_evolution_template_sync' => [
                'path'       => '/evolution/templates/sync',
                'controller' => 'MauticPlugin\MauticEvolutionBundle\Controller\TemplateController::syncAction',
                'method'     => 'POST',
            ],
            'mautic_evolution_meta_template_sync' => [
                'path'       => '/evolution/business-templates/sync',
                'controller' => 'MauticPlugin\MauticEvolutionBundle\Controller\MetaTemplateController::syncAction',
            ],
            'mautic_evolution_meta_template_new' => [
                'path'       => '/evolution/business-templates/new',
                'controller' => 'MauticPlugin\MauticEvolutionBundle\Controller\MetaTemplateController::newAction',
            ],
            'mautic_evolution_meta_template_view' => [
                'path'       => '/evolution/business-templates/view/{objectId}',
                'controller' => 'MauticPlugin\MauticEvolutionBundle\Controller\MetaTemplateController::viewAction',
                'requirements' => ['objectId' => '\d+'],
            ],
            'mautic_evolution_meta_template_map' => [
                'path'       => '/evolution/business-templates/map/{objectId}',
                'controller' => 'MauticPlugin\MauticEvolutionBundle\Controller\MetaTemplateController::mapAction',
                'requirements' => ['objectId' => '\d+'],
            ],
            'mautic_evolution_meta_template_delete' => [
                'path'       => '/evolution/business-templates/delete/{objectId}',
                'controller' => 'MauticPlugin\MauticEvolutionBundle\Controller\MetaTemplateController::deleteAction',
                'requirements' => ['objectId' => '\d+'],
            ],
            'mautic_evolution_meta_template_index' => [
                'path'       => '/evolution/business-templates/{page}',
                'controller' => 'MauticPlugin\MauticEvolutionBundle\Controller\MetaTemplateController::indexAction',
                'defaults'   => ['page' => 1],
                'requirements' => ['page' => '\d+'],
            ],
        ],
        'public' => [
            'mautic_evolution_webhook_receive' => [
                'path'       => '/webhook/evolution/receive',
                'controller' => 'MauticPlugin\MauticEvolutionBundle\Controller\WebhookController::receiveAction',
            ],
            'mautic_evolution_webhook_health' => [
                'path'       => '/webhook/evolution/health',
                'controller' => 'MauticPlugin\MauticEvolutionBundle\Controller\WebhookController::healthCheckAction',
            ],
        ],
    ],

    'services' => [
        'integrations' => [
            'mautic.integration.mauticevolution' => [
                'class'     => MauticEvolutionIntegration::class,
                'arguments' => [
                    'event_dispatcher',
                    'mautic.helper.cache_storage',
                    'doctrine.orm.entity_manager',
                    'request_stack',
                    'router',
                    'translator',
                    'monolog.logger.mautic',
                    'mautic.helper.encryption',
                    'mautic.lead.model.lead',
                    'mautic.lead.model.company',
                    'mautic.helper.paths',
                    'mautic.core.model.notification',
                    'mautic.lead.model.field',
                    'mautic.plugin.model.integration_entity',
                    'mautic.lead.model.dnc',
                    'mautic.lead.field.fields_with_unique_identifier',
                ],
            ],
        ],
    ],

    'menu' => [
        'main' => [
            'mautic.evolution.templates' => [
                'route'     => 'mautic_evolution_template_index',
                'access'    => 'evolution:templates:view',
                'parent'    => 'mautic.core.channels',
                'priority'  => 100,
                'id'        => 'mautic_evolution_templates',
            ],
            'mautic.evolution.meta_templates' => [
                'route'     => 'mautic_evolution_meta_template_index',
                'access'    => 'evolution:templates:view',
                'parent'    => 'mautic.core.channels',
                'priority'  => 99,
                'id'        => 'mautic_evolution_meta_templates',
            ],
        ],
    ],

    'categories' => [
        'plugin:evolution' => [
            'label' => 'mautic.evolution.templates',
            'class' => EvolutionTemplate::class,
        ],
    ],

    'permissions' => [
        'evolution' => [
            'class' => EvolutionPermissions::class,
        ],
    ],
];
