<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadEventLog;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\MauticEvolutionBundle\Helper\PhoneNumberHelper;
use Psr\Log\LoggerInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Class EvolutionApiService
 * 
 * Serviço para comunicação com a Evolution API
 */
class EvolutionApiService
{
    private Client $httpClient;
    private LoggerInterface $logger;
    private IntegrationHelper $integrationHelper;
    private UserHelper $userHelper;
    private EntityManagerInterface $entityManager;

    public function __construct(
        IntegrationHelper $integrationHelper,
        Client $httpClient,
        LoggerInterface $logger,
        UserHelper $userHelper,
        EntityManagerInterface $entityManager
    ) {
        $this->integrationHelper = $integrationHelper;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->userHelper = $userHelper;
        $this->entityManager = $entityManager;
    }

    /**
     * Envia mensagem de texto via Evolution API
     */
    public function sendTextMessage(string $number, string $message, Lead $contact = null, $event = null, array $customHeaders = [], array $metadata = []): array
    {
        $data = $this->buildTextPayload($number, $message, $metadata);

        return $this->makeRequest('POST', '/message/sendText/' . $this->getInstance(), $data, $contact, $event, $customHeaders);
    }

    /**
     * Envia mensagem de texto with balancing via Evolution API
     */
    public function sendTextWithBalancing(string $number, string $message, Lead $contact = null, $event = null, array $customHeaders = [], array $metadata = []): array
    {
        $data = $this->buildTextPayload($number, $message, $metadata);

        return $this->makeRequest('POST', '/message/sendTextWithBalancing/', $data, $contact, $event, $customHeaders);
    }

    /**
     * Envia texto utilizando balanceamento por grupo (usa alias do grupo)
     */
    public function sendTextWithGroupBalancing(string $alias, string $number, string $text, array $options = [], Lead $contact = null, $event = null, array $customHeaders = [], array $metadata = []): array
    {
        $data = [
            'alias' => $alias,
            'number' => $number,
            'text' => $text,
        ];

        // Campos opcionais do payload
        if (isset($options['delay'])) {
            $data['delay'] = (int) $options['delay'];
        }
        if (isset($options['mentionsEveryOne'])) {
            $data['mentionsEveryOne'] = (bool) $options['mentionsEveryOne'];
        }
        if (isset($options['mentioned']) && is_array($options['mentioned'])) {
            $data['mentioned'] = $options['mentioned'];
        }

        // Metadados opcionais
        if (!empty($metadata)) {
            $data['metadata'] = $this->sanitizeKeyValueMap($metadata);
        }
        return $this->makeRequest('POST', '/message/sendTextWithGroupBalancing', $data, $contact, $event, $customHeaders);
    }

    /**
     * Obtém lista de grupos da Evolution API e filtra apenas habilitados
     * @return array{success: bool, groups: array<int, array{ id: string, name: string, alias: string, enabled: bool }>, error?: string}
     */
    public function getInstanceGroups(): array
    {
        $result = $this->makeRequest('GET', '/instance-group');

        if (!$result['success']) {
            return [
                'success' => false,
                'groups' => [],
                'error' => $result['error'] ?? 'Falha ao obter grupos da Evolution API',
            ];
        }

        $groups = [];
        foreach (($result['data'] ?? []) as $item) {
            if (!isset($item['enabled']) || $item['enabled'] !== true) {
                continue;
            }
            $groups[] = [
                'id' => (string) ($item['id'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'alias' => (string) ($item['alias'] ?? ''),
                'enabled' => (bool) ($item['enabled'] ?? false),
            ];
        }

        // Log para depuração
        $this->logger->info('Evolution API - getInstanceGroups', [
            'count' => count($groups),
        ]);

        return [
            'success' => true,
            'groups' => $groups,
        ];
    }

    /**
     * Envia mensagem de mídia via Evolution API
     */
    public function sendMediaMessage(string $number, string $mediaUrl, string $caption = '', Lead $contact = null, $event = null, string $mediaType = 'image', ?string $fileName = null, ?string $mimetype = null): array
    {
        $data = [
            'number' => $this->formatPhoneNumber($number),
            'mediatype' => $mediaType,
            'media' => $mediaUrl,
            'caption' => $caption,
        ];
        if ($fileName) {
            $data['fileName'] = $fileName;
        }
        if ($mimetype) {
            $data['mimetype'] = $mimetype;
        }

        return $this->makeRequest('POST', '/message/sendMedia/' . $this->getInstance(), $data, $contact, $event);
    }

    /**
     * Send an official WhatsApp Business template via Evolution API.
     *
     * @param list<array<string, mixed>> $components
     */
    public function sendTemplate(string $number, string $name, string $language, array $components = [], Lead $contact = null, $event = null, array $customHeaders = [], array $metadata = []): array
    {
        $data = [
            'number' => $this->formatPhoneNumber($number),
            'name' => $name,
            'language' => $language,
        ];
        if ($components !== []) {
            $data['components'] = $components;
        }
        if ($metadata !== []) {
            $data['metadata'] = $this->sanitizeKeyValueMap($metadata);
        }

        return $this->makeRequest('POST', '/message/sendTemplate/' . $this->getInstance(), $data, $contact, $event, $customHeaders);
    }

    public function findTemplates(): array
    {
        return $this->makeRequest('GET', '/template/find/' . $this->getInstance());
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createTemplate(array $payload): array
    {
        return $this->makeRequest('POST', '/template/create/' . $this->getInstance(), $payload);
    }

    public function deleteTemplate(string $name, ?string $hsmId = null): array
    {
        $data = ['name' => $name];
        if ($hsmId) {
            $data['hsmId'] = $hsmId;
        }

        return $this->makeRequest('DELETE', '/template/delete/' . $this->getInstance(), $data);
    }

    public function sendAudio(string $number, string $audio, Lead $contact = null, $event = null): array
    {
        return $this->makeRequest('POST', '/message/sendWhatsAppAudio/' . $this->getInstance(), [
            'number' => $this->formatPhoneNumber($number),
            'audio' => $audio,
        ], $contact, $event);
    }

    public function sendLocation(string $number, float $latitude, float $longitude, string $name = '', string $address = '', Lead $contact = null, $event = null): array
    {
        return $this->makeRequest('POST', '/message/sendLocation/' . $this->getInstance(), [
            'number' => $this->formatPhoneNumber($number),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'name' => $name,
            'address' => $address,
        ], $contact, $event);
    }

    /**
     * @param list<array<string, mixed>> $buttons
     */
    public function sendButtons(string $number, string $title, array $buttons, string $description = '', string $footer = '', Lead $contact = null, $event = null): array
    {
        return $this->makeRequest('POST', '/message/sendButtons/' . $this->getInstance(), [
            'number' => $this->formatPhoneNumber($number),
            'title' => $title,
            'description' => $description,
            'footer' => $footer,
            'buttons' => $buttons,
        ], $contact, $event);
    }

    /**
     * @param list<array<string, mixed>> $sections
     */
    public function sendList(string $number, string $title, string $buttonText, array $sections, string $description = '', string $footer = '', Lead $contact = null, $event = null): array
    {
        return $this->makeRequest('POST', '/message/sendList/' . $this->getInstance(), [
            'number' => $this->formatPhoneNumber($number),
            'title' => $title,
            'description' => $description,
            'footerText' => $footer,
            'buttonText' => $buttonText,
            'sections' => $sections,
        ], $contact, $event);
    }

    /**
     * @param list<string> $values
     */
    public function sendPoll(string $number, string $name, array $values, int $selectableCount = 1, Lead $contact = null, $event = null): array
    {
        return $this->makeRequest('POST', '/message/sendPoll/' . $this->getInstance(), [
            'number' => $this->formatPhoneNumber($number),
            'name' => $name,
            'selectableCount' => $selectableCount,
            'values' => $values,
        ], $contact, $event);
    }

    /**
     * @param list<array<string, mixed>> $contacts
     */
    public function sendContact(string $number, array $contacts, Lead $contact = null, $event = null): array
    {
        return $this->makeRequest('POST', '/message/sendContact/' . $this->getInstance(), [
            'number' => $this->formatPhoneNumber($number),
            'contact' => $contacts,
        ], $contact, $event);
    }

    public function sendSticker(string $number, string $sticker, Lead $contact = null, $event = null): array
    {
        return $this->makeRequest('POST', '/message/sendSticker/' . $this->getInstance(), [
            'number' => $this->formatPhoneNumber($number),
            'sticker' => $sticker,
        ], $contact, $event);
    }

    public function sendPresence(string $number, string $presence = 'composing', int $delay = 1200, Lead $contact = null, $event = null): array
    {
        return $this->makeRequest('POST', '/chat/sendPresence/' . $this->getInstance(), [
            'number' => $this->formatPhoneNumber($number),
            'delay' => $delay,
            'presence' => $presence,
        ], $contact, $event);
    }

    public function fetchInstances(): array
    {
        return $this->makeRequest('GET', '/instance/fetchInstances');
    }

    public function getQrCode(): array
    {
        return $this->makeRequest('GET', '/instance/connect/' . $this->getInstance());
    }

    /**
     * Define webhook para receber mensagens
     */
    public function setWebhook(string $webhookUrl, Lead $contact = null, $event = null): array
    {
        $v2 = $this->makeRequest('POST', '/webhook/set/' . $this->getInstance(), [
            'webhook' => [
                'url' => $webhookUrl,
                'enabled' => true,
                'webhookByEvents' => false,
                'events' => [
                    'APPLICATION_STARTUP',
                    'QRCODE_UPDATED',
                    'CONNECTION_UPDATE',
                    'MESSAGES_SET',
                    'MESSAGES_UPSERT',
                    'MESSAGES_UPDATE',
                    'MESSAGES_DELETE',
                    'SEND_MESSAGE',
                    'CONTACTS_UPDATE',
                    'PRESENCE_UPDATE',
                ],
            ],
        ], $contact, $event);

        if (!empty($v2['success'])) {
            return $v2;
        }

        return $this->makeRequest('POST', '/webhook/set/' . $this->getInstance(), [
            'url' => $webhookUrl,
            'webhook_by_events' => false,
            'webhook_base64' => false,
            'events' => [
                'APPLICATION_STARTUP',
                'QRCODE_UPDATED',
                'CONNECTION_UPDATE',
                'MESSAGES_UPSERT',
                'MESSAGES_UPDATE',
                'SEND_MESSAGE',
            ],
        ], $contact, $event);
    }

    /**
     * Obtém mensagens de uma conversa
     */
    public function getMessages(string $remoteJid, int $limit = 20, Lead $contact = null, $event = null): array
    {
        $data = [
            'where' => [
                'remoteJid' => $remoteJid,
            ],
            'limit' => $limit,
        ];

        return $this->makeRequest('POST', '/chat/findMessages/' . $this->getInstance(), $data, $contact, $event);
    }

    /**
     * Marca mensagem como lida
     */
    public function markAsRead(string $remoteJid, string $messageId, Lead $contact = null, $event = null): array
    {
        $data = [
            'readMessages' => [
                [
                    'remoteJid' => $remoteJid,
                    'id' => $messageId,
                ],
            ],
        ];

        return $this->makeRequest('POST', '/chat/markMessageAsRead/' . $this->getInstance(), $data, $contact, $event);
    }

    /**
     * Verifica se um número é WhatsApp
     */
    public function checkWhatsAppNumber(string $number, Lead $contact = null, $event = null): array
    {
        // Sanitiza/normaliza número para formato aceito pela API
        $normalized = $this->formatPhoneNumber($number);
        $data = [
            'numbers' => [$normalized],
        ];

        $instance = $this->getInstance();
        $attempts = [
            // Sem instância na rota
            '/chat/whatsappNumbers',
            // Com instância em path
            '/chat/whatsappNumbers/' . $instance,
            // Com instância em query param
            '/chat/whatsappNumbers?instance=' . urlencode($instance),
            // Variação de rota
            '/chat/checkWhatsappNumbers/' . $instance,
        ];

        $lastResult = null;
        foreach ($attempts as $endpoint) {
            $this->logger->info('Evolution API - checkWhatsAppNumber attempt', [
                'endpoint' => $endpoint,
                'numbers' => $data['numbers'],
            ]);

            $result = $this->makeRequest('POST', $endpoint, $data, $contact, $event);
            $lastResult = $result;
            if (isset($result['success']) && $result['success'] === true) {
                return $result;
            }

            // Se não for 404, encerra tentativas
            if (isset($result['status_code']) && (int) $result['status_code'] !== 404) {
                break;
            }
        }

        // Retorna último resultado ou erro padrão
        return $lastResult ?? [
            'success' => false,
            'error' => 'WhatsApp check failed for all endpoints',
            'status_code' => 404,
        ];
    }

    /**
     * Faz requisição para a Evolution API
     */
    private function makeRequest(string $method, string $endpoint, array $data = [], Lead $contact = null, $event = null, array $customHeaders = []): array
    {
        $apiUrl = $this->getApiUrl();
        $apiKey = $this->getApiKey();

        if (empty($apiUrl) || empty($apiKey)) {
            $this->logger->error('Evolution API não configurada corretamente', [
                'api_url' => $apiUrl,
                'api_key' => $apiKey,
            ]);

            $errorMessage = 'Evolution API não configurada corretamente';

            $this->markEventFailed($event, $errorMessage);

            return [
                'success' => false,
                'error' => $errorMessage,
            ];
        }

        $url = rtrim($apiUrl, '/') . '/' . ltrim($endpoint, '/');
        $headers = [
            'Content-Type' => 'application/json',
            'apikey' => $apiKey,
        ];
        // Merge custom headers (without removing required defaults)
        foreach ($customHeaders as $hKey => $hVal) {
            if (is_string($hKey) && $hKey !== '') {
                $headers[$hKey] = (string) $hVal;
            }
        }

        try {
            $this->logger->info('Evolution API Request', [
                'method' => $method,
                'endpoint' => $endpoint,
                'has_payload' => $data !== [],
            ]);

            $options = [
                'headers' => $headers,
                'timeout' => $this->getTimeout(),
            ];

            if (!empty($data)) {
                $options['json'] = $data;
            }

            $response = $this->httpClient->request($method, $url, $options);
            $responseData = json_decode($response->getBody()->getContents(), true);


            // Verifica se o status da resposta é 2xx (sucesso)
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $result = [
                    'success' => true,
                    'status_code' => $response->getStatusCode(),
                    'data' => $responseData,
                ];

                // Log de sucesso no timeline do contato quando aplicável
                if ($contact instanceof Lead) {
                    $action = $this->getActionFromEndpoint($endpoint);
                    if (in_array($action, ['Send Text Message', 'Send Media Message', 'Send Text With Group Balancing', 'Send Template Message', 'Send Audio Message', 'Send Location', 'Send Buttons', 'Send List', 'Send Poll'], true)) {
                        $details = [
                            'action' => $action,
                            'status' => 'sent',
                            'timestamp' => new \DateTime(),
                            'request' => $data,
                            'response' => $responseData,
                            'messageId' => $this->extractResponseMessageId($responseData),
                            'phone' => $data['number'] ?? ($data['phoneNumber'] ?? null),
                            'template' => $data['name'] ?? ($data['template'] ?? ($data['caption'] ?? null)),
                        ];
                        $this->logSuccessEvent($contact, $action, $details);
                    }
                }

                return $result;
            } 

            // Exceção personalizada para erro HTTP (status 4xx ou 5xx)
            $errorDetails = [
                'method' => $method,
                'endpoint' => $endpoint,
                'data' => (string) $response->getBody(),
                'status_code' => $response->getStatusCode()
            ];
            
            // Caso o código de status não seja 2xx, retorna erro estruturado (sem lançar exceção)
            if ((int) $response->getStatusCode() === 404) {
                $this->logger->warning('Evolution API HTTP 404', $errorDetails);
            } else {
                $this->logger->error('Evolution API HTTP error', $errorDetails);
            }
            return [
                'success' => false,
                'error' => 'HTTP error',
                'status_code' => $response->getStatusCode(),
                'response' => $errorDetails['data'],
            ];
            
        } catch (GuzzleException $e) {
            $errorMessage = $e->getMessage();
            $context = [
                'method' => $method,
                'endpoint' => $endpoint,
                'data' => $data,
                'status_code' => $e->getCode(),
            ];

            $this->logger->error('Evolution API Error', $context);
            $this->markEventFailed($event, $errorMessage);

            if ($contact instanceof Lead) {
                $action = $this->getActionFromEndpoint($endpoint);
                $this->logFailureEvent($contact, $action, $errorMessage, $context);
            }

            return [
                'success' => false,
                'error' => $errorMessage,
                'status_code' => $e->getCode(),
            ];
        }
    }

    /**
     * Constrói payload para envio de texto, incluindo metadata
     * @return array{number: string, text: string, metadata?: array<string, mixed>}
     */
    public function buildTextPayload(string $number, string $text, array $metadata = []): array
    {
        $payload = [
            'number' => $this->formatPhoneNumber($number),
            'text' => $text,
        ];

        if (!empty($metadata)) {
            $payload['metadata'] = $this->sanitizeKeyValueMap($metadata);
        }

        // Log para depuração do payload
        $this->logger->info('Evolution API - Payload construído', [
            'payload_preview' => [
                'number' => $payload['number'],
                'text_len' => strlen($payload['text'] ?? ''),
                'has_metadata' => isset($payload['metadata']) && is_array($payload['metadata']) && count($payload['metadata']) > 0,
                'metadata_keys' => isset($payload['metadata']) ? array_keys($payload['metadata']) : [],
            ],
        ]);

        return $payload;
    }

    /**
     * Sanitiza e tipa valores para mapa chave-valor do payload/headers
     * Garante chaves string e valores escalares coerentes (bool/int/float/null/string)
     * @param array<string,mixed> $map
     * @return array<string,mixed>
     */
    private function sanitizeKeyValueMap(array $map): array
    {
        $result = [];
        foreach ($map as $key => $value) {
            if (!is_string($key) || trim($key) === '') {
                continue;
            }
            $result[trim($key)] = $this->castScalar($value);
        }
        return $result;
    }

    /**
     * Converte string para tipo escalar apropriado
     * - "true"/"false" -> bool
     * - números -> int/float
     * - "null" -> null
     * - JSON objects/arrays -> mantém string (compatibilidade) a menos que parsing seja estritamente necessário
     */
    private function castScalar(mixed $value): mixed
    {
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        if (is_string($value)) {
            $trim = trim($value);
            if (strcasecmp($trim, 'true') === 0) {
                return true;
            }
            if (strcasecmp($trim, 'false') === 0) {
                return false;
            }
            if (strcasecmp($trim, 'null') === 0) {
                return null;
            }
            // numérico
            if (is_numeric($trim)) {
                // int vs float
                return strpos($trim, '.') !== false ? (float) $trim : (int) $trim;
            }
            return $value; // mantém string
        }
        // arrays/objects: mantém como está (API pode aceitar)
        return $value;
    }

    /**
     * @param mixed $event
     */
    private function markEventFailed($event, string $errorMessage): void
    {
        if (is_object($event) && method_exists($event, 'setFailed')) {
            $event->setFailed($errorMessage);
        }
    }

    /**
     * @param mixed $responseData
     */
    public function extractResponseMessageId(mixed $responseData): ?string
    {
        if (!is_array($responseData)) {
            return null;
        }

        $candidates = [
            $responseData['key']['id'] ?? null,
            $responseData['data']['key']['id'] ?? null,
            $responseData['keyId'] ?? null,
            $responseData['id'] ?? null,
            $responseData['messageId'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Extrai a ação do endpoint para logging
     */
    private function getActionFromEndpoint(string $endpoint): string
    {
        return match (true) {
            str_contains($endpoint, '/message/sendTemplate/') => 'Send Template Message',
            str_contains($endpoint, '/message/sendTextWithGroupBalancing') => 'Send Text With Group Balancing',
            str_contains($endpoint, '/message/sendText') => 'Send Text Message',
            str_contains($endpoint, '/message/sendMedia/') => 'Send Media Message',
            str_contains($endpoint, '/message/sendWhatsAppAudio/') => 'Send Audio Message',
            str_contains($endpoint, '/message/sendLocation/') => 'Send Location',
            str_contains($endpoint, '/message/sendButtons/') => 'Send Buttons',
            str_contains($endpoint, '/message/sendList/') => 'Send List',
            str_contains($endpoint, '/message/sendPoll/') => 'Send Poll',
            str_contains($endpoint, '/message/sendContact/') => 'Send Contact',
            str_contains($endpoint, '/webhook/set/') => 'Set Webhook',
            str_contains($endpoint, '/chat/findMessages/') => 'Get Messages',
            str_contains($endpoint, '/chat/markMessageAsRead/') => 'Mark as Read',
            str_contains($endpoint, '/chat/whatsappNumbers') => 'Check WhatsApp Number',
            str_contains($endpoint, '/template/') => 'Template API',
            default => 'API Request',
        };
    }

    /**
     * Obtém headers para requisições
     */
    private function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'apikey' => $this->getApiKey(),
        ];
    }

    private function formatPhoneNumber(string $phoneNumber): string
    {
        return PhoneNumberHelper::normalize($phoneNumber, $this->getDefaultCountryCode());
    }

    public function getDefaultCountryCode(): string
    {
        $settings = $this->getIntegrationSettings();

        return (string) ($settings['evolution_country_code'] ?? '55');
    }

    /**
     * Obtém configurações da integração
     *
     * @return array<string, mixed>
     */
    public function getIntegrationSettings(): array
    {
        $integration = $this->integrationHelper->getIntegrationObject('MauticEvolution');

        if (!$integration || !$integration->getIntegrationSettings()->getIsPublished()) {
            return [];
        }

        $apiKeys = $integration->getDecryptedApiKeys();
        if (!is_array($apiKeys)) {
            $apiKeys = [];
        }

        $mappedApiKeys = [
            'evolution_api_url' => (string) ($apiKeys['evolution_api_url'] ?? $apiKeys[0] ?? ''),
            'evolution_api_key' => (string) ($apiKeys['evolution_api_key'] ?? $apiKeys[1] ?? ''),
        ];

        $featureSettings = $integration->getIntegrationSettings()->getFeatureSettings() ?: [];

        return array_merge($mappedApiKeys, is_array($featureSettings) ? $featureSettings : []);
    }

    private function getApiUrl(): string
    {
        $settings = $this->getIntegrationSettings();

        return rtrim((string) ($settings['evolution_api_url'] ?? ''), '/');
    }

    private function getApiKey(): string
    {
        $settings = $this->getIntegrationSettings();

        return (string) ($settings['evolution_api_key'] ?? '');
    }

    public function getInstance(): string
    {
        $settings = $this->getIntegrationSettings();
        $instance = trim((string) ($settings['evolution_instance'] ?? 'default'));

        return $instance !== '' ? $instance : 'default';
    }

    /**
     * Obtém timeout das requisições
     */
    private function getTimeout(): int
    {
        $settings = $this->getIntegrationSettings();
        return (int) ($settings['evolution_timeout'] ?? 30);
    }

    /**
     * Verifica se a configuração está válida
     */
    public function isConfigured(): bool
    {
        return !empty($this->getApiUrl()) && 
               !empty($this->getApiKey());
    }

    /**
     * Verifica status da instância
     */
    public function getInstanceStatus($event = null): array
    {
        return $this->makeRequest('GET', '/instance/connectionState/' . $this->getInstance(), [], null, $event);
    }

    /**
     * Testa conexão com a Evolution API
     */
    public function testConnection($event = null): array
    {
        if (!$this->isConfigured()) {
            $errorMessage = 'Configuração incompleta. Verifique URL, API Key e Instância.';
            $this->markEventFailed($event, $errorMessage);

            return [
                'success' => false,
                'error' => $errorMessage,
            ];
        }

        return $this->getInstanceStatus($event);
    }

    /**
     * Indica se a checagem de número WhatsApp deve ser realizada
     * Controlado via feature settings (ex.: check_whatsapp_on_save)
     */
    public function shouldCheckWhatsapp(): bool
    {
        $settings = $this->getIntegrationSettings();
        $enabled = (bool) ($settings['check_whatsapp_on_save'] ?? false);
        $this->logger->info('Evolution API - shouldCheckWhatsapp', [
            'check_whatsapp_on_save' => $enabled,
        ]);
        return $enabled;
    }

    /**
     * Registra evento de falha no timeline do contato
     */
    private function logFailureEvent(Lead $contact, string $action, string $errorMessage, array $context = []): void
    {
        try {
            $user = $this->userHelper->getUser();
            
            $eventLog = new LeadEventLog();
            $eventLog->setLead($contact);
            $eventLog->setBundle('EvolutionBundle');
            $eventLog->setObject('evolution_api');
            $eventLog->setObjectId($contact->getId());
            $eventLog->setAction('evolution_api_failure');
            $eventLog->setProperties([
                'action' => $action,
                'error' => $errorMessage,
                'context' => $context,
                'timestamp' => new \DateTime(),
            ]);
            $eventLog->setUserId($user ? $user->getId() : null);
            $eventLog->setUserName($user ? $user->getName() : 'System');
            $eventLog->setDateAdded(new \DateTime());

            $this->entityManager->persist($eventLog);
            $this->entityManager->flush();

            $this->logger->info('Evolution API failure event logged for contact', [
                'contact_id' => $contact->getId(),
                'action' => $action,
                'error' => $errorMessage,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to log Evolution API failure event', [
                'contact_id' => $contact->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Registra evento de sucesso no timeline do contato
     */
    private function logSuccessEvent(Lead $contact, string $action, array $details = []): void
    {
        try {
            $user = $this->userHelper->getUser();

            $eventLog = new LeadEventLog();
            $eventLog->setLead($contact);
            $eventLog->setBundle('EvolutionBundle');
            $eventLog->setObject('evolution_api');
            $eventLog->setObjectId($contact->getId());
            $eventLog->setAction('evolution_api_success');
            // Garantir propriedades com carimbo de data/hora
            $props = array_merge([
                'timestamp' => new \DateTime(),
            ], $details);
            $props['action'] = $action;
            $eventLog->setProperties($props);
            $eventLog->setUserId($user ? $user->getId() : null);
            $eventLog->setUserName($user ? $user->getName() : 'System');
            $eventLog->setDateAdded(new \DateTime());

            $this->entityManager->persist($eventLog);
            $this->entityManager->flush();

            $this->logger->info('Evolution API success event logged for contact', [
                'contact_id' => $contact->getId(),
                'action' => $action,
                'message_id' => $props['messageId'] ?? null,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to log Evolution API success event', [
                'contact_id' => $contact->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}