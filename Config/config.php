<?php

declare(strict_types=1);

return [
    'name'        => 'Evolution Bundle',
    'description' => 'Mautic integration for Evolution API v2.x (WhatsApp messaging).',
    'version'     => '2.1.1',
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
            'mautic_evolution_ajax_instances' => [
                'path'       => '/evolution/ajax/instances',
                'controller' => 'MauticPlugin\MauticEvolutionBundle\Controller\AjaxController::instancesAction',
            ],
            'mautic_evolution_ajax_templates' => [
                'path'       => '/evolution/ajax/templates/{instance}',
                'controller' => 'MauticPlugin\MauticEvolutionBundle\Controller\AjaxController::templatesAction',
                'defaults'   => ['instance' => null],
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
        'models' => [
            'mautic.evolution.model.template' => [
                'class' => 'MauticPlugin\MauticEvolutionBundle\Model\TemplateModel',
                'arguments' => [
                    'doctrine.orm.entity_manager',
                    'mautic.security',
                    'event_dispatcher',
                    'router',
                    'translator',
                    'mautic.helper.user',
                    'monolog.logger.mautic',
                    'mautic.helper.core_parameters',
                ],
            ],
        ],
        'other' => [
            'mautic.evolution.helper.template' => [
                'class' => 'MauticPlugin\MauticEvolutionBundle\Helper\TemplateHelper',
                'arguments' => [
                    'mautic.evolution.model.template',
                    'twig',
                ],
            ],
        ],
        'event_subscribers' => [
            'mautic.evolution.plugin.subscriber' => [
                'class' => 'MauticPlugin\MauticEvolutionBundle\EventListener\PluginSubscriber',
                'arguments' => [
                    'doctrine.orm.entity_manager',
                    '@Mautic\PluginBundle\Bundle\PluginDatabase',
                ],
            ],
        ],
        'integrations' => [
            // Serviço de integração do MauticEvolution
            'mautic.integration.mauticevolution' => [
                'class'     => MauticPlugin\MauticEvolutionBundle\Integration\MauticEvolutionIntegration::class, // Classe do serviço
                'arguments' => [
                    // Lista de dependências que serão injetadas no construtor da classe
                    'event_dispatcher',                                         // Para disparar eventos
                    'mautic.helper.cache_storage',                             // Para cache
                    'doctrine.orm.entity_manager',                             // Para banco de dados
                    'request_stack',                                           // Para acessar dados da requisição HTTP
                    'router',                                                  // Para gerar URLs
                    'translator',                                              // Para tradução de textos
                    'monolog.logger.mautic',                                   // Para logs
                    'mautic.helper.encryption',                                // Para criptografia
                    'mautic.lead.model.lead',                                  // Para trabalhar com contatos
                    'mautic.lead.model.company',                               // Para trabalhar com empresas
                    'mautic.helper.paths',                                     // Para caminhos de arquivos
                    'mautic.core.model.notification',                          // Para notificações
                    'mautic.lead.model.field',                                 // Para campos customizados
                    'mautic.plugin.model.integration_entity',                  // Para entidades de integração
                    'mautic.lead.model.dnc',                                   // Para lista de não contatar
                    'mautic.lead.field.fields_with_unique_identifier',         // Para campos únicos
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
        ],
    ],

    'categories' => [
        'plugin:evolution' => [
            'label' => 'mautic.evolution.templates',
            'class' => 'MauticPlugin\MauticEvolutionBundle\Entity\Template',
        ],
    ],
];