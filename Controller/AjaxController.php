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

    public function instancesAction(): JsonResponse
    {
        $result = $this->evolutionApiService->fetchInstances();

        return new JsonResponse([
            'success' => $result['success'],
            'instances' => $result['instances'],
            'choices' => $this->evolutionApiService->getInstanceChoices(),
            'default' => $this->evolutionApiService->getConfiguredInstance(),
            'error' => $result['error'] ?? null,
        ]);
    }

    public function templatesAction(Request $request, ?string $instance = null): JsonResponse
    {
        $instance = $instance ?: (string) $request->query->get('instance', '');
        if ($instance === '') {
            $instance = $this->evolutionApiService->getConfiguredInstance();
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
