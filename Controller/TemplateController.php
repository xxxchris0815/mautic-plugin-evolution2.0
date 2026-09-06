<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use MauticPlugin\MauticEvolutionBundle\Entity\EvolutionTemplate;
use MauticPlugin\MauticEvolutionBundle\Model\MessageModel;
use MauticPlugin\MauticEvolutionBundle\Model\TemplateModel;
use MauticPlugin\MauticEvolutionBundle\Service\TemplateSyncService;
use Mautic\CoreBundle\Controller\FormController;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\FormBundle\Helper\FormFieldHelper;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class TemplateController
 * 
 * Controlador para gerenciar templates do Evolution API
 */
class TemplateController extends FormController
{
    public function __construct(
        FormFactoryInterface $formFactory,
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
        $this->setStandardParameters(
            'evolution.template',
            'evolution:templates',
            'mautic_evolution_template',
            'mautic.evolution.template',
            'mautic.evolution.template',
            '@MauticEvolution/Template',
            'mautic_evolution_templates',
            'evolutionTemplate'
        );

        parent::__construct($formFactory, $fieldHelper, $doctrine, $modelFactory, $userHelper, $coreParametersHelper, $dispatcher, $translator, $flashBag, $requestStack, $security);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    protected function getViewArguments(array $args, $action): array
    {
        $args = parent::getViewArguments($args, $action);
        $item = $args['viewParameters']['item'] ?? ($args['item'] ?? null);
        if ($action === 'view' && $item instanceof EvolutionTemplate) {
            /** @var MessageModel $messageModel */
            $messageModel = $this->getModel('evolution.message');
            if (isset($args['viewParameters'])) {
                $args['viewParameters']['stats'] = $messageModel->getTemplateStats($item);
            } else {
                $args['stats'] = $messageModel->getTemplateStats($item);
            }
        }

        return $args;
    }

    /**
     * Lista templates
     */
    public function indexAction(Request $request, int $page = 1): Response
    {
        return parent::indexStandard($request, $page);
    }

    /**
     * Cria novo template
     */
    public function newAction(Request $request): JsonResponse|Response
    {
        return parent::newStandard($request);
    }

    /**
     * Edita template
     */
    public function editAction(Request $request, int $objectId, bool $ignorePost = false): JsonResponse|Response
    {
        return parent::editStandard($request, $objectId, $ignorePost);
    }

    /**
     * Visualiza template
     */
    public function viewAction(Request $request, int $objectId): Response
    {
        return parent::viewStandard($request, $objectId);
    }

    /**
     * Deleta template
     */
    public function deleteAction(Request $request, int $objectId): JsonResponse|Response
    {
        return parent::deleteStandard($request, $objectId);
    }

    /**
     * Clona template
     */
    public function cloneAction(Request $request, int $objectId): JsonResponse|Response
    {
        return parent::cloneStandard($request, $objectId);
    }

    /**
     * Alterna status do template
     */
    public function toggleAction(Request $request, int $objectId): JsonResponse
    {
        /** @var TemplateModel $model */
        $model = $this->getModel('evolution.template');
        $entity = $model->getEntity($objectId);

        if (!$entity) {
            return new JsonResponse(['success' => false, 'message' => 'Template not found']);
        }

        if (!$this->security->isGranted('evolution:templates:edit')) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied']);
        }

        $entity->setIsActive(!$entity->isActive());
        $model->saveEntity($entity);

        return new JsonResponse([
            'success' => true,
            'active' => $entity->isActive()
        ]);
    }

    /**
     * Preview do template
     */
    public function previewAction(Request $request, int $objectId): Response
    {
        /** @var TemplateModel $model */
        $model = $this->getModel('evolution.template');
        $entity = $model->getEntity($objectId);

        if (!$entity) {
            return $this->notFound();
        }

        if (!$this->security->isGranted('evolution:templates:view')) {
            return $this->accessDenied();
        }

        return $this->render('@MauticEvolution/Template/preview.html.twig', [
            'template' => $entity,
        ]);
    }

    public function syncAction(TemplateSyncService $syncService): JsonResponse
    {
        if (!$this->security->isGranted('evolution:templates:create')) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $result = $syncService->syncFromApi();

        return new JsonResponse($result, !empty($result['success']) ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    }
}