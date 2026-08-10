<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\CoreBundle\Translation\Translator;
use MauticPlugin\MauticEvolutionBundle\Service\EvolutionApiService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Authenticated AJAX endpoints for campaign action forms.
 */
class AjaxController extends CommonController
{
    public function __construct(
        private EvolutionApiService $evolutionApiService,
        ManagerRegistry $doctrine,
        ModelFactory $modelFactory,
        UserHelper $userHelper,
        CoreParametersHelper $coreParametersHelper,
        EventDispatcherInterface $dispatcher,
        Translator $translator,
        FlashBag $flashBag,
        RequestStack $requestStack,
        CorePermissions $security
    ) {
        parent::__construct(
            $doctrine,
            $modelFactory,
            $userHelper,
            $coreParametersHelper,
            $dispatcher,
            $translator,
            $flashBag,
            $requestStack,
            $security
        );
    }

    public function instancesAction(Request $request): JsonResponse
    {
        $cloudOnly = $request->query->getBoolean('cloudOnly')
            || $request->query->getBoolean('cloud_only');
        $result = $this->evolutionApiService->fetchInstances();
        $instances = $result['instances'] ?? [];
        if ($cloudOnly) {
            $instances = array_values(array_filter(
                $instances,
                fn (array $instance): bool => $this->evolutionApiService->isWhatsAppBusinessIntegration($instance['integration'] ?? null)
            ));
        }

        return new JsonResponse([
            'success' => $result['success'],
            'instances' => $instances,
            'choices' => $this->evolutionApiService->getInstanceChoices($cloudOnly),
            'default' => $this->evolutionApiService->getConfiguredInstance(),
            'cloud_only' => $cloudOnly,
            'error' => $result['error'] ?? null,
        ]);
    }

    public function templatesAction(Request $request, ?string $instance = null): JsonResponse
    {
        $instance = $instance ?: (string) $request->query->get('instance', '');
        if ($instance === '') {
            $instance = $this->evolutionApiService->getConfiguredInstance();
        }

        if ($instance !== '' && !$this->evolutionApiService->isCloudTemplateInstance($instance)) {
            return new JsonResponse([
                'success' => false,
                'instance' => $instance,
                'choices' => [],
                'templates' => [],
                'error' => 'Templates are only available for WhatsApp Cloud/Business instances (WHATSAPP-BUSINESS). Baileys is not supported.',
            ], 400);
        }

        $result = $this->evolutionApiService->findTemplates($instance, true);
        $catalog = [];
        foreach ($result['templates'] ?? [] as $template) {
            $name = (string) ($template['name'] ?? '');
            $language = (string) ($template['language'] ?? '');
            if ($name === '' || $language === '') {
                continue;
            }
            $key = $name . '|' . $language;
            $catalog[$key] = [
                'name' => $name,
                'language' => $language,
                'status' => $template['status'] ?? null,
                'category' => $template['category'] ?? null,
                'variables' => $this->evolutionApiService->getTemplateHelper()->extractVariables($template),
                'components' => $template['components'] ?? [],
            ];
        }

        return new JsonResponse([
            'success' => $result['success'] ?? false,
            'instance' => $instance,
            'choices' => $result['choices'] ?? [],
            'templates' => $catalog,
            'error' => $result['error'] ?? null,
        ]);
    }
}
