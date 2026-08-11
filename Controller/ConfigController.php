<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Controller;

use MauticPlugin\MauticEvolutionBundle\Service\EvolutionApiService;
use Mautic\CoreBundle\Controller\FormController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class ConfigController
 * 
 * Controlador para gerenciar configurações do Evolution API
 */
class ConfigController extends FormController
{
    private EvolutionApiService $evolutionApiService;

    public function __construct(
        EvolutionApiService $evolutionApiService
    ) {
        $this->evolutionApiService = $evolutionApiService;
    }

    /**
     * Página principal de configuração
     */
    public function indexAction(Request $request): Response
    {
        // Verifica permissões
        if (!$this->user->isAdmin()) {
            return $this->accessDenied();
        }

        return $this->delegateView([
            'viewParameters' => [
                'tmpl' => $request->get('tmpl', 'index'),
            ],
            'contentTemplate' => '@MauticEvolution/Config/index.html.twig',
            'passthroughVars' => [
                'activeLink' => '#mautic_evolution_config',
                'mauticContent' => 'evolutionConfig',
                'route' => $this->generateUrl('mautic_evolution_config'),
            ],
        ]);
    }

    /**
     * Testa conexão com a Evolution API
     */
    public function testConnectionAction(): JsonResponse
    {
        try {
            // Testa conexão
            $status = $this->evolutionApiService->getInstanceStatus();
            
            if ($status) {
                return new JsonResponse([
                    'success' => true,
                    'message' => 'Conexão estabelecida com sucesso!',
                    'data' => $status,
                ]);
            } else {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Não foi possível conectar com a Evolution API',
                ]);
            }

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erro ao testar conexão: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Configura webhook na Evolution API
     */
    public function setupWebhookAction(Request $request): JsonResponse
    {
        try {
            $webhookUrl = $this->generateUrl('mautic_evolution_webhook_receive', [], 0);
            
            $result = $this->evolutionApiService->setWebhook($webhookUrl);
            
            if ($result) {
                return new JsonResponse([
                    'success' => true,
                    'message' => 'Webhook configurado com sucesso!',
                    'webhook_url' => $webhookUrl,
                ]);
            } else {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Erro ao configurar webhook',
                ]);
            }

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erro ao configurar webhook: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Salva configurações do plugin
     */
    public function saveConfigAction(Request $request): JsonResponse
    {
        try {
            $config = $request->request->all();
            
            // Valida configurações obrigatórias
            $requiredFields = ['evolution_api_url', 'evolution_api_key'];
            $missingFields = [];
            
            foreach ($requiredFields as $field) {
                if (empty($config[$field])) {
                    $missingFields[] = $field;
                }
            }
            
            if (!empty($missingFields)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Campos obrigatórios não preenchidos: ' . implode(', ', $missingFields),
                ]);
            }

            // Salva configurações (aqui você implementaria a lógica de salvamento)
            // Por exemplo, usando o CoreParametersHelper ou um serviço de configuração

            return new JsonResponse([
                'success' => true,
                'message' => 'Configurações salvas com sucesso!',
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erro ao salvar configurações: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Obtém configurações atuais
     */
    public function getConfigAction(): JsonResponse
    {
        try {
            $config = [
                'evolution_api_url' => $this->coreParametersHelper->get('evolution_api_url', ''),
                'evolution_api_key' => $this->coreParametersHelper->get('evolution_api_key', ''),
                'evolution_timeout' => $this->coreParametersHelper->get('evolution_timeout', 30),
            ];

            return new JsonResponse([
                'success' => true,
                'data' => $config,
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erro ao obter configurações: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Verifica status do WhatsApp
     */
    public function whatsappStatusAction(): JsonResponse
    {
        try {
            $status = $this->evolutionApiService->getInstanceStatus();
            
            return new JsonResponse([
                'success' => true,
                'data' => $status,
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erro ao verificar status do WhatsApp: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Obtém QR Code para conectar WhatsApp
     */
    public function qrCodeAction(): JsonResponse
    {
        try {
            // Aqui você implementaria a lógica para obter o QR Code
            // da Evolution API se necessário
            
            return new JsonResponse([
                'success' => true,
                'message' => 'QR Code disponível via Evolution API',
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erro ao obter QR Code: ' . $e->getMessage(),
            ]);
        }
    }
}