<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Mautic\CoreBundle\Controller\FormController;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\FormBundle\Helper\FormFieldHelper;
use MauticPlugin\MauticEvolutionBundle\Entity\EvolutionTemplate;
use MauticPlugin\MauticEvolutionBundle\Form\Type\MetaBusinessTemplateType;
use MauticPlugin\MauticEvolutionBundle\Form\Type\MetaTemplateMappingType;
use MauticPlugin\MauticEvolutionBundle\Helper\TemplatePayloadBuilder;
use MauticPlugin\MauticEvolutionBundle\Model\TemplateModel;
use MauticPlugin\MauticEvolutionBundle\Service\TemplateSyncService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dedicated Channels area for WhatsApp Business / Meta Cloud API templates.
 */
class MetaTemplateController extends FormController
{
    public function __construct(
        private FormFactoryInterface $metaFormFactory,
        FormFieldHelper $fieldHelper,
        ManagerRegistry $doctrine,
        ModelFactory $modelFactory,
        UserHelper $userHelper,
        CoreParametersHelper $coreParametersHelper,
        EventDispatcherInterface $dispatcher,
        Translator $translator,
        FlashBag $flashBag,
        ?RequestStack $requestStack,
        ?CorePermissions $security
    ) {
        parent::__construct($this->metaFormFactory, $fieldHelper, $doctrine, $modelFactory, $userHelper, $coreParametersHelper, $dispatcher, $translator, $flashBag, $requestStack, $security);
    }

    public function indexAction(TemplateModel $templateModel): Response
    {
        if (!$this->security->isGranted('evolution:templates:view')) {
            return $this->accessDenied();
        }

        return $this->delegateView([
            'viewParameters' => [
                'items' => $templateModel->getTemplatesBySource('evolution'),
                'permissions' => $this->security->isGranted(
                    [
                        'evolution:templates:view',
                        'evolution:templates:create',
                        'evolution:templates:edit',
                        'evolution:templates:delete',
                    ],
                    'RETURN_ARRAY'
                ),
            ],
            'contentTemplate' => '@MauticEvolution/MetaTemplate/index.html.twig',
            'passthroughVars' => [
                'activeLink' => '#mautic_evolution_meta_templates',
                'mauticContent' => 'evolutionMetaTemplate',
                'route' => $this->generateUrl('mautic_evolution_meta_template_index'),
            ],
        ]);
    }

    public function newAction(Request $request, TemplateModel $templateModel, TemplateSyncService $syncService): Response
    {
        if (!$this->security->isGranted('evolution:templates:create')) {
            return $this->accessDenied();
        }

        $form = $this->metaFormFactory->create(MetaBusinessTemplateType::class, [
            'language' => 'de',
            'category' => 'UTILITY',
            'allowCategoryChange' => true,
            'headerType' => 'NONE',
            'button1Type' => 'NONE',
            'button2Type' => 'NONE',
            'button3Type' => 'NONE',
        ], [
            'action' => $this->generateUrl('mautic_evolution_meta_template_new'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $built = TemplatePayloadBuilder::fromFormData($form->getData());
            $payload = $built['payload'];
            $name = (string) $payload['name'];
            $language = (string) $payload['language'];

            $existing = $templateModel->getTemplateByNameAndLanguage($name, $language);
            if ($existing !== null && !$existing->isEvolutionTemplate()) {
                $this->addFlashMessage(
                    'mautic.evolution.meta_template.error.local_name_taken',
                    ['%name%' => $name],
                    FlashBag::LEVEL_ERROR,
                    'messages'
                );
            } else {
                $entity = $existing ?? new EvolutionTemplate();
                $entity->setName($name);
                $entity->setLanguage($language);
                $entity->setCategoryType((string) $payload['category']);
                $entity->setComponents($payload['components']);
                $entity->setContent(TemplatePayloadBuilder::extractBodyText($payload['components']));
                $entity->setVariables(TemplatePayloadBuilder::extractBodyPlaceholders($payload['components']));
                $entity->setParameterFields($built['parameterFields']);
                $entity->setSource('evolution');
                $entity->setType('template');
                $entity->setStatus('PENDING');
                $entity->setIsActive(false);
                $entity->setDescription($this->translator->trans('mautic.evolution.meta_template.pending_description'));

                $result = $syncService->createOnApi($entity, $payload);
                if (!empty($result['success'])) {
                    $this->addFlashMessage(
                        'mautic.evolution.meta_template.notice.created',
                        [
                            '%name%' => $name,
                            '%status%' => $entity->getStatus() ?: 'PENDING',
                        ],
                        FlashBag::LEVEL_NOTICE,
                        'messages'
                    );

                    return $this->postActionRedirect([
                        'returnUrl' => $this->generateUrl('mautic_evolution_meta_template_view', ['objectId' => $entity->getId()]),
                        'viewParameters' => ['objectId' => $entity->getId()],
                        'contentTemplate' => 'MauticPlugin\MauticEvolutionBundle\Controller\MetaTemplateController::viewAction',
                        'passthroughVars' => [
                            'activeLink' => '#mautic_evolution_meta_templates',
                            'mauticContent' => 'evolutionMetaTemplate',
                        ],
                    ]);
                }

                $this->addFlashMessage(
                    'mautic.evolution.meta_template.error.create',
                    ['%error%' => (string) ($result['error'] ?? 'unknown')],
                    FlashBag::LEVEL_ERROR,
                    'messages'
                );
            }
        }

        return $this->delegateView([
            'viewParameters' => [
                'form' => $form->createView(),
                'entity' => null,
            ],
            'contentTemplate' => '@MauticEvolution/MetaTemplate/form.html.twig',
            'passthroughVars' => [
                'activeLink' => '#mautic_evolution_meta_templates',
                'mauticContent' => 'evolutionMetaTemplate',
                'route' => $this->generateUrl('mautic_evolution_meta_template_new'),
            ],
        ]);
    }

    public function viewAction(Request $request, int $objectId, TemplateModel $templateModel): Response
    {
        if (!$this->security->isGranted('evolution:templates:view')) {
            return $this->accessDenied();
        }

        $entity = $templateModel->getEntity($objectId);
        if ($entity === null || !$entity->isEvolutionTemplate()) {
            return $this->notFound();
        }

        $mappingDefaults = [];
        foreach ($entity->getParameterFields() ?? [] as $index => $token) {
            $field = $this->contactFieldFromToken((string) $token);
            if ($field !== null) {
                $mappingDefaults['paramField'.$index] = $field;
            }
        }

        $mapForm = $this->metaFormFactory->create(MetaTemplateMappingType::class, $mappingDefaults, [
            'action' => $this->generateUrl('mautic_evolution_meta_template_map', ['objectId' => $entity->getId()]),
        ]);
        $mapForm->handleRequest($request);
        if ($mapForm->isSubmitted() && $mapForm->isValid()) {
            if (!$this->security->isGranted('evolution:templates:edit')) {
                return $this->accessDenied();
            }
            $entity->setParameterFields(TemplatePayloadBuilder::extractParameterFields($mapForm->getData()));
            $templateModel->saveEntity($entity);
            $this->addFlashMessage(
                'mautic.evolution.meta_template.notice.mapping_saved',
                [],
                FlashBag::LEVEL_NOTICE,
                'messages'
            );

            return $this->redirect($this->generateUrl('mautic_evolution_meta_template_view', ['objectId' => $entity->getId()]));
        }

        return $this->delegateView([
            'viewParameters' => [
                'item' => $entity,
                'mapForm' => $mapForm->createView(),
                'permissions' => $this->security->isGranted(
                    [
                        'evolution:templates:view',
                        'evolution:templates:create',
                        'evolution:templates:edit',
                        'evolution:templates:delete',
                    ],
                    'RETURN_ARRAY'
                ),
            ],
            'contentTemplate' => '@MauticEvolution/MetaTemplate/details.html.twig',
            'passthroughVars' => [
                'activeLink' => '#mautic_evolution_meta_templates',
                'mauticContent' => 'evolutionMetaTemplate',
                'route' => $this->generateUrl('mautic_evolution_meta_template_view', ['objectId' => $objectId]),
            ],
        ]);
    }

    public function mapAction(Request $request, int $objectId, TemplateModel $templateModel): Response
    {
        return $this->viewAction($request, $objectId, $templateModel);
    }

    public function deleteAction(int $objectId, TemplateModel $templateModel, TemplateSyncService $syncService): Response
    {
        if (!$this->security->isGranted('evolution:templates:delete')) {
            return $this->accessDenied();
        }

        $entity = $templateModel->getEntity($objectId);
        if ($entity === null || !$entity->isEvolutionTemplate()) {
            return $this->notFound();
        }

        $name = (string) $entity->getName();
        $result = $syncService->deleteFromApi($entity);
        if (!empty($result['success'])) {
            $this->addFlashMessage(
                'mautic.evolution.meta_template.notice.deleted',
                ['%name%' => $name],
                FlashBag::LEVEL_NOTICE,
                'messages'
            );
        } else {
            $this->addFlashMessage(
                'mautic.evolution.meta_template.error.delete',
                ['%error%' => (string) ($result['error'] ?? 'unknown')],
                FlashBag::LEVEL_ERROR,
                'messages'
            );
        }

        return $this->postActionRedirect([
            'returnUrl' => $this->generateUrl('mautic_evolution_meta_template_index'),
            'contentTemplate' => 'MauticPlugin\MauticEvolutionBundle\Controller\MetaTemplateController::indexAction',
            'passthroughVars' => [
                'activeLink' => '#mautic_evolution_meta_templates',
                'mauticContent' => 'evolutionMetaTemplate',
            ],
        ]);
    }

    public function syncAction(TemplateSyncService $syncService): Response
    {
        if (!$this->security->isGranted('evolution:templates:create')) {
            return $this->accessDenied();
        }

        $result = $syncService->syncFromApi();
        if (!empty($result['success'])) {
            $this->addFlashMessage(
                'mautic.evolution.meta_template.notice.synced',
                [
                    '%imported%' => (string) ($result['imported'] ?? 0),
                    '%updated%' => (string) ($result['updated'] ?? 0),
                ],
                FlashBag::LEVEL_NOTICE,
                'messages'
            );
        } else {
            $this->addFlashMessage(
                'mautic.evolution.meta_template.error.sync',
                ['%error%' => (string) ($result['error'] ?? 'unknown')],
                FlashBag::LEVEL_ERROR,
                'messages'
            );
        }

        return $this->postActionRedirect([
            'returnUrl' => $this->generateUrl('mautic_evolution_meta_template_index'),
            'contentTemplate' => 'MauticPlugin\MauticEvolutionBundle\Controller\MetaTemplateController::indexAction',
            'passthroughVars' => [
                'activeLink' => '#mautic_evolution_meta_templates',
                'mauticContent' => 'evolutionMetaTemplate',
            ],
        ]);
    }

    private function contactFieldFromToken(string $token): ?string
    {
        $token = trim($token);
        if (preg_match('/\{contactfield=([a-z0-9_]+)\}/i', $token, $matches)) {
            return strtolower($matches[1]);
        }
        if (isset(MetaBusinessTemplateType::contactFieldChoices()[$token])) {
            return $token;
        }

        return null;
    }
}
