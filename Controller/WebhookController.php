<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use MauticPlugin\MauticEvolutionBundle\Service\WebhookService;
use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\CoreBundle\Translation\Translator;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class WebhookController
 * 
 * Controlador para processar webhooks da Evolution API
 */
class WebhookController extends CommonController
{
    public function __construct(
        private WebhookService $webhookService,
        private LoggerInterface $logger,
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
        parent::__construct($doctrine, $modelFactory, $userHelper, $coreParametersHelper, $dispatcher, $translator, $flashBag, $requestStack, $security);
    }

    /**
     * Processa webhook recebido da Evolution API
     */
    public function receiveAction(Request $request): JsonResponse
    {

        $this->logger->info('Recebido webhook Evolution API', ['content' => json_decode($request->getContent(), true)]);

        try {
            // Verifica se é uma requisição POST
            if (!$request->isMethod('POST')) {
                return new JsonResponse([
                    'status' => 'error',
                    'message' => 'Método não permitido'
                ], Response::HTTP_METHOD_NOT_ALLOWED);
            }

            // Obtém dados do webhook
            $payload = json_decode($request->getContent(), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error('Erro ao decodificar JSON do webhook', [
                    'error' => json_last_error_msg(),
                    'content' => $request->getContent()
                ]);

                return new JsonResponse([
                    'status' => 'error',
                    'message' => 'JSON inválido'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Basic payload validation (Evolution v2 always sends event + instance)
            if (!isset($payload['event'])) {
                $this->logger->warning('Webhook with invalid structure', ['payload' => $payload]);

                return new JsonResponse([
                    'status' => 'error',
                    'message' => 'Invalid payload structure',
                ], Response::HTTP_BAD_REQUEST);
            }

            // data may be absent for some lifecycle events; normalize to array
            if (!isset($payload['data'])) {
                $payload['data'] = [];
            }

            $result = $this->webhookService->processWebhook($payload);

            if ($result['success']) {
                $this->logger->info('Webhook processado com sucesso', [
                    'event' => $payload['event'],
                    'result' => $result
                ]);

                return new JsonResponse([
                    'status' => 'success',
                    'message' => 'Webhook processado com sucesso',
                    'data' => $result['data'] ?? null
                ]);
            } else {
                $this->logger->error('Erro ao processar webhook', [
                    'event' => $payload['event'],
                    'error' => $result['error'] ?? 'Erro desconhecido'
                ]);

                return new JsonResponse([
                    'status' => 'error',
                    'message' => $result['error'] ?? 'Erro ao processar webhook'
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

        } catch (\Exception $e) {
            $this->logger->error('Exceção ao processar webhook', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JsonResponse([
                'status' => 'error',
                'message' => 'Erro interno do servidor'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Endpoint para testar conectividade
     */
    public function healthCheckAction(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'message' => 'Evolution API Webhook endpoint is working',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
}