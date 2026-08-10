<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadEventLog;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\MauticEvolutionBundle\Exception\EvolutionApiException;
use MauticPlugin\MauticEvolutionBundle\Exception\EvolutionDeliveryException;
use Psr\Log\LoggerInterface;

/**
 * Client for Evolution API v2.x
 *
 * @see https://github.com/evolution-foundation/evolution-api
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
     * Send a plain text message via Evolution API v2
     * POST /message/sendText/{instance}
     */
    public function sendTextMessage(string $number, string $message, Lead $contact = null, CampaignExecutionEvent $event = null, array $customHeaders = [], array $metadata = []): array
    {
        $data = $this->buildTextPayload($number, $message, $metadata);

        return $this->makeRequest('POST', '/message/sendText/' . $this->getInstance(), $data, $contact, $event, $customHeaders, true);
    }

    /**
     * Legacy / custom balancing endpoint (not part of stock Evolution API v2).
     * Falls back to sendText when balancing is unavailable.
     */
    public function sendTextWithBalancing(string $number, string $message, Lead $contact = null, CampaignExecutionEvent $event = null, array $customHeaders = [], array $metadata = []): array
    {
        $data = $this->buildTextPayload($number, $message, $metadata);

        try {
            return $this->makeRequest('POST', '/message/sendTextWithBalancing/', $data, $contact, $event, $customHeaders, true);
        } catch (EvolutionDeliveryException $e) {
            if ($e->getStatusCode() === 404) {
                $this->logger->warning('sendTextWithBalancing unavailable, falling back to sendText/{instance}');

                return $this->sendTextMessage($number, $message, $contact, $event, $customHeaders, $metadata);
            }

            throw $e;
        }
    }

    /**
     * Custom group balancing endpoint (optional extension). Falls back to sendText on 404.
     */
    public function sendTextWithGroupBalancing(string $alias, string $number, string $text, array $options = [], Lead $contact = null, CampaignExecutionEvent $event = null, array $customHeaders = [], array $metadata = []): array
    {
        $data = [
            'alias' => $alias,
            'number' => $this->formatPhoneNumber($number),
            'text' => $text,
        ];

        if (isset($options['delay'])) {
            $data['delay'] = (int) $options['delay'];
        }
        if (isset($options['mentionsEveryOne'])) {
            $data['mentionsEveryOne'] = (bool) $options['mentionsEveryOne'];
        }
        if (isset($options['mentioned']) && is_array($options['mentioned'])) {
            $data['mentioned'] = $options['mentioned'];
        }
        if (!empty($metadata)) {
            $data['metadata'] = $this->sanitizeKeyValueMap($metadata);
        }

        try {
            return $this->makeRequest('POST', '/message/sendTextWithGroupBalancing', $data, $contact, $event, $customHeaders, true);
        } catch (EvolutionDeliveryException $e) {
            if ($e->getStatusCode() === 404) {
                $this->logger->warning('sendTextWithGroupBalancing unavailable, falling back to sendText/{instance}', [
                    'alias' => $alias,
                ]);

                return $this->sendTextMessage($number, $text, $contact, $event, $customHeaders, $metadata);
            }

            throw $e;
        }
    }

    /**
     * Optional instance-group listing (custom extension). Returns empty list on 404.
     *
     * @return array{success: bool, groups: array<int, array{id: string, name: string, alias: string, enabled: bool}>, error?: string}
     */
    public function getInstanceGroups(): array
    {
        try {
            $result = $this->makeRequest('GET', '/instance-group', [], null, null, [], false);
        } catch (EvolutionApiException|EvolutionDeliveryException $e) {
            return [
                'success' => false,
                'groups' => [],
                'error' => $e->getMessage(),
            ];
        }

        if (!$result['success']) {
            return [
                'success' => false,
                'groups' => [],
                'error' => $result['error'] ?? 'Failed to fetch instance groups',
            ];
        }

        $groups = [];
        foreach (($result['data'] ?? []) as $item) {
            if (!is_array($item) || !isset($item['enabled']) || $item['enabled'] !== true) {
                continue;
            }
            $groups[] = [
                'id' => (string) ($item['id'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'alias' => (string) ($item['alias'] ?? ''),
                'enabled' => (bool) ($item['enabled'] ?? false),
            ];
        }

        return [
            'success' => true,
            'groups' => $groups,
        ];
    }

    /**
     * Send media via Evolution API v2
     * POST /message/sendMedia/{instance}
     *
     * v2 payload: { number, mediatype, media, caption?, fileName?, mimetype? }
     */
    public function sendMediaMessage(
        string $number,
        string $mediaUrl,
        string $caption = '',
        Lead $contact = null,
        CampaignExecutionEvent $event = null,
        string $mediaType = 'image',
        ?string $fileName = null,
        ?string $mimetype = null
    ): array {
        $allowedTypes = ['image', 'document', 'video', 'audio'];
        if (!in_array($mediaType, $allowedTypes, true)) {
            $mediaType = 'image';
        }

        $data = [
            'number' => $this->formatPhoneNumber($number),
            'mediatype' => $mediaType,
            'media' => $mediaUrl,
        ];

        if ($caption !== '') {
            $data['caption'] = $caption;
        }
        if ($fileName !== null && $fileName !== '') {
            $data['fileName'] = $fileName;
        }
        if ($mimetype !== null && $mimetype !== '') {
            $data['mimetype'] = $mimetype;
        }

        return $this->makeRequest('POST', '/message/sendMedia/' . $this->getInstance(), $data, $contact, $event, [], true);
    }

    /**
     * Configure webhook for the instance (Evolution API v2)
     * POST /webhook/set/{instance}
     */
    public function setWebhook(string $webhookUrl, Lead $contact = null, CampaignExecutionEvent $event = null): array
    {
        $data = [
            'webhook' => [
                'enabled' => true,
                'url' => $webhookUrl,
                'byEvents' => false,
                'base64' => false,
                'events' => [
                    'APPLICATION_STARTUP',
                    'QRCODE_UPDATED',
                    'CONNECTION_UPDATE',
                    'MESSAGES_UPSERT',
                    'MESSAGES_UPDATE',
                    'MESSAGES_DELETE',
                    'SEND_MESSAGE',
                ],
            ],
        ];

        return $this->makeRequest('POST', '/webhook/set/' . $this->getInstance(), $data, $contact, $event, [], true);
    }

    /**
     * Find messages in a chat
     * POST /chat/findMessages/{instance}
     */
    public function getMessages(string $remoteJid, int $limit = 20, Lead $contact = null, CampaignExecutionEvent $event = null): array
    {
        $data = [
            'where' => [
                'key' => [
                    'remoteJid' => $remoteJid,
                ],
            ],
            'limit' => $limit,
        ];

        return $this->makeRequest('POST', '/chat/findMessages/' . $this->getInstance(), $data, $contact, $event, [], false);
    }

    /**
     * Mark message as read
     * POST /chat/markMessageAsRead/{instance}
     */
    public function markAsRead(string $remoteJid, string $messageId, Lead $contact = null, CampaignExecutionEvent $event = null): array
    {
        $data = [
            'readMessages' => [
                [
                    'remoteJid' => $remoteJid,
                    'fromMe' => false,
                    'id' => $messageId,
                ],
            ],
        ];

        return $this->makeRequest('POST', '/chat/markMessageAsRead/' . $this->getInstance(), $data, $contact, $event, [], false);
    }

    /**
     * Check whether numbers exist on WhatsApp
     * POST /chat/whatsappNumbers/{instance}
     */
    public function checkWhatsAppNumber(string $number, Lead $contact = null, CampaignExecutionEvent $event = null): array
    {
        $normalized = $this->formatPhoneNumber($number);
        $data = [
            'numbers' => [$normalized],
        ];

        $endpoint = '/chat/whatsappNumbers/' . $this->getInstance();

        try {
            $result = $this->makeRequest('POST', $endpoint, $data, $contact, $event, [], false);
            if (!empty($result['data']) && is_array($result['data'])) {
                $first = $result['data'][0] ?? null;
                if (is_array($first) && array_key_exists('exists', $first)) {
                    $result['exists'] = (bool) $first['exists'];
                }
            }

            return $result;
        } catch (EvolutionApiException|EvolutionDeliveryException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
            ];
        }
    }

    /**
     * Perform an HTTP request against Evolution API v2.
     *
     * @param bool $throwOnError When true, throws EvolutionDeliveryException on HTTP/JSON errors
     *
     * @throws EvolutionApiException
     * @throws EvolutionDeliveryException
     */
    private function makeRequest(
        string $method,
        string $endpoint,
        array $data = [],
        Lead $contact = null,
        CampaignExecutionEvent $event = null,
        array $customHeaders = [],
        bool $throwOnError = true
    ): array {
        $apiUrl = $this->getApiUrl();
        $apiKey = $this->getApiKey();

        if (empty($apiUrl) || empty($apiKey)) {
            $errorMessage = 'Evolution API is not configured correctly (URL / API key missing)';
            $this->logger->error($errorMessage, [
                'api_url' => $apiUrl,
                'has_api_key' => !empty($apiKey),
            ]);

            if ($event instanceof CampaignExecutionEvent) {
                $event->setFailed($errorMessage);
            }

            $exception = new EvolutionApiException($errorMessage);
            if ($throwOnError) {
                throw $exception;
            }

            return [
                'success' => false,
                'error' => $errorMessage,
            ];
        }

        $instance = $this->getInstance();
        if ($instance === '') {
            $errorMessage = 'Evolution API instance name is not configured';
            if ($event instanceof CampaignExecutionEvent) {
                $event->setFailed($errorMessage);
            }
            $exception = new EvolutionApiException($errorMessage);
            if ($throwOnError) {
                throw $exception;
            }

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
        foreach ($customHeaders as $hKey => $hVal) {
            if (is_string($hKey) && $hKey !== '') {
                $headers[$hKey] = (string) $hVal;
            }
        }

        try {
            $this->logger->info('Evolution API Request', [
                'method' => $method,
                'endpoint' => $endpoint,
                'full_url' => $url,
                'instance' => $instance,
                'data' => $data,
            ]);

            $options = [
                'headers' => $headers,
                'timeout' => $this->getTimeout(),
                'http_errors' => false,
            ];

            if (!empty($data)) {
                $options['json'] = $data;
            }

            $response = $this->httpClient->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            $rawBody = (string) $response->getBody();
            $responseData = json_decode($rawBody, true);
            if (!is_array($responseData)) {
                $responseData = $rawBody !== '' ? ['raw' => $rawBody] : [];
            }

            $isSuccessStatus = in_array($statusCode, [200, 201], true)
                || ($statusCode >= 200 && $statusCode < 300);

            $jsonError = $this->extractJsonError($responseData, $statusCode);

            if ($isSuccessStatus && $jsonError === null) {
                $result = [
                    'success' => true,
                    'status_code' => $statusCode,
                    'data' => $responseData,
                ];

                if ($contact instanceof Lead) {
                    $action = $this->getActionFromEndpoint($endpoint);
                    if (in_array($action, ['Send Text Message', 'Send Media Message', 'Send Text With Group Balancing'], true)) {
                        $details = [
                            'action' => $action,
                            'status' => 'sent',
                            'timestamp' => new \DateTime(),
                            'request' => $data,
                            'response' => $responseData,
                            'messageId' => $this->extractMessageId($responseData),
                            'phone' => $data['number'] ?? null,
                            'template' => $data['caption'] ?? ($data['text'] ?? null),
                        ];
                        $this->logSuccessEvent($contact, $action, $details);
                    }
                }

                return $result;
            }

            $errorMessage = $jsonError ?? sprintf('Evolution API HTTP %d', $statusCode);
            $errorDetails = [
                'method' => $method,
                'endpoint' => $endpoint,
                'status_code' => $statusCode,
                'response' => $responseData,
            ];

            if ($statusCode === 404) {
                $this->logger->warning('Evolution API HTTP 404', $errorDetails);
            } else {
                $this->logger->error('Evolution API HTTP/JSON error', $errorDetails);
            }

            if ($event instanceof CampaignExecutionEvent) {
                $event->setFailed($errorMessage);
                $log = $event->getLogEntry();
                if ($log) {
                    $log->appendToMetadata([
                        'failed' => 1,
                        'reason' => $errorMessage,
                        'error_details' => $errorDetails,
                    ]);
                }
            }

            if ($contact instanceof Lead) {
                $this->logFailureEvent($contact, $this->getActionFromEndpoint($endpoint), $errorMessage, $errorDetails);
            }

            $exception = (new EvolutionDeliveryException($errorMessage, $statusCode))
                ->setStatusCode($statusCode)
                ->setResponseBody(is_array($responseData) ? $responseData : null)
                ->setEndpoint($endpoint)
                ->setContact($contact);

            if ($throwOnError) {
                throw $exception;
            }

            return [
                'success' => false,
                'error' => $errorMessage,
                'status_code' => $statusCode,
                'response' => $responseData,
            ];
        } catch (EvolutionDeliveryException|EvolutionApiException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            $statusCode = $e instanceof RequestException && $e->hasResponse()
                ? $e->getResponse()->getStatusCode()
                : (int) $e->getCode();
            $errorMessage = $e->getMessage();
            $context = [
                'method' => $method,
                'endpoint' => $endpoint,
                'data' => $data,
                'status_code' => $statusCode,
            ];

            $this->logger->error('Evolution API Error', $context);

            if ($event instanceof CampaignExecutionEvent) {
                $event->setFailed($errorMessage);
                $log = $event->getLogEntry();
                if ($log) {
                    $log->appendToMetadata([
                        'failed' => 1,
                        'reason' => $errorMessage,
                        'error_details' => $context,
                    ]);
                }
            }

            if ($contact instanceof Lead) {
                $this->logFailureEvent($contact, $this->getActionFromEndpoint($endpoint), $errorMessage, $context);
            }

            $exception = (new EvolutionDeliveryException($errorMessage, $statusCode, $e))
                ->setStatusCode($statusCode > 0 ? $statusCode : null)
                ->setEndpoint($endpoint)
                ->setContact($contact);

            if ($throwOnError) {
                throw $exception;
            }

            return [
                'success' => false,
                'error' => $errorMessage,
                'status_code' => $statusCode,
            ];
        }
    }

    /**
     * Detect error payloads even when HTTP status is misleading.
     */
    private function extractJsonError(array $responseData, int $statusCode): ?string
    {
        if (isset($responseData['status']) && is_numeric($responseData['status'])) {
            $payloadStatus = (int) $responseData['status'];
            if ($payloadStatus >= 400) {
                return $this->stringifyError($responseData) ?? sprintf('Evolution API status %d', $payloadStatus);
            }
        }

        if ($statusCode >= 400 && isset($responseData['error']) && $responseData['error'] !== '' && $responseData['error'] !== null) {
            return $this->stringifyError($responseData);
        }

        if (isset($responseData['success']) && $responseData['success'] === false) {
            return $this->stringifyError($responseData) ?? 'Evolution API reported success=false';
        }

        return null;
    }

    private function stringifyError(array $responseData): ?string
    {
        if (isset($responseData['error'])) {
            if (is_string($responseData['error'])) {
                return $responseData['error'];
            }
            if (is_array($responseData['error'])) {
                if (isset($responseData['error']['message']) && is_string($responseData['error']['message'])) {
                    return $responseData['error']['message'];
                }

                return json_encode($responseData['error'], JSON_UNESCAPED_UNICODE) ?: null;
            }
        }

        if (isset($responseData['message']) && is_string($responseData['message'])) {
            return $responseData['message'];
        }

        if (isset($responseData['response']['message']) && is_string($responseData['response']['message'])) {
            return $responseData['response']['message'];
        }

        return null;
    }

    /**
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

        $this->logger->info('Evolution API - Payload built', [
            'number' => $payload['number'],
            'text_len' => strlen($payload['text'] ?? ''),
            'has_metadata' => !empty($payload['metadata']),
        ]);

        return $payload;
    }

    /**
     * @param array<string,mixed> $map
     *
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
            if (is_numeric($trim)) {
                return strpos($trim, '.') !== false ? (float) $trim : (int) $trim;
            }

            return $value;
        }

        return $value;
    }

    private function extractMessageId(array $responseData): ?string
    {
        if (isset($responseData['key']['id']) && is_string($responseData['key']['id'])) {
            return $responseData['key']['id'];
        }
        if (isset($responseData['data']['key']['id']) && is_string($responseData['data']['key']['id'])) {
            return $responseData['data']['key']['id'];
        }

        return null;
    }

    private function getActionFromEndpoint(string $endpoint): string
    {
        if (str_contains($endpoint, '/message/sendTextWithGroupBalancing')) {
            return 'Send Text With Group Balancing';
        }
        if (str_contains($endpoint, '/message/sendText')) {
            return 'Send Text Message';
        }
        if (str_contains($endpoint, '/message/sendMedia')) {
            return 'Send Media Message';
        }
        if (str_contains($endpoint, '/webhook/set')) {
            return 'Set Webhook';
        }
        if (str_contains($endpoint, '/chat/findMessages')) {
            return 'Get Messages';
        }
        if (str_contains($endpoint, '/chat/markMessageAsRead')) {
            return 'Mark as Read';
        }
        if (str_contains($endpoint, '/chat/whatsappNumbers')) {
            return 'Check WhatsApp Number';
        }

        return 'API Request';
    }

    /**
     * Normalize phone numbers for Evolution API v2:
     * digits only, including country code, no leading '+'.
     * Example: +49 170 1234567 -> 491701234567
     */
    public function formatPhoneNumber(string $phoneNumber): string
    {
        $phoneNumber = trim($phoneNumber);
        if (str_starts_with($phoneNumber, '00')) {
            $phoneNumber = substr($phoneNumber, 2);
        }

        return preg_replace('/\D+/', '', $phoneNumber) ?? '';
    }

    private function getIntegrationSettings(): array
    {
        $integration = $this->integrationHelper->getIntegrationObject('MauticEvolution');

        if (!$integration || !$integration->getIntegrationSettings()->getIsPublished()) {
            return [];
        }

        $apiKeys = $integration->getDecryptedApiKeys();
        $featureSettings = $integration->getIntegrationSettings()->getFeatureSettings() ?? [];

        $mappedApiKeys = [];
        if (is_array($apiKeys)) {
            $mappedApiKeys = [
                'evolution_api_url' => (string) ($apiKeys['evolution_api_url'] ?? $apiKeys[0] ?? ''),
                'evolution_api_key' => (string) ($apiKeys['evolution_api_key'] ?? $apiKeys[1] ?? ''),
                'evolution_instance' => (string) ($apiKeys['evolution_instance'] ?? $apiKeys[2] ?? ''),
            ];
        }

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

    /**
     * Instance name used in v2 paths such as /message/sendText/{instance}
     */
    private function getInstance(): string
    {
        $settings = $this->getIntegrationSettings();
        $instance = trim((string) ($settings['evolution_instance'] ?? ''));

        return $instance;
    }

    private function getTimeout(): int
    {
        $settings = $this->getIntegrationSettings();

        return (int) ($settings['evolution_timeout'] ?? 30);
    }

    public function isConfigured(): bool
    {
        return !empty($this->getApiUrl())
            && !empty($this->getApiKey())
            && !empty($this->getInstance());
    }

    public function getInstanceStatus(CampaignExecutionEvent $event = null): array
    {
        return $this->makeRequest('GET', '/instance/connectionState/' . $this->getInstance(), [], null, $event, [], false);
    }

    public function testConnection(CampaignExecutionEvent $event = null): array
    {
        if (!$this->isConfigured()) {
            $errorMessage = 'Incomplete configuration. Check URL, API Key and Instance.';

            if ($event instanceof CampaignExecutionEvent) {
                $event->setFailed($errorMessage);
            }

            return [
                'success' => false,
                'error' => $errorMessage,
            ];
        }

        return $this->getInstanceStatus($event);
    }

    public function shouldCheckWhatsapp(): bool
    {
        $settings = $this->getIntegrationSettings();

        return (bool) ($settings['check_whatsapp_on_save'] ?? false);
    }

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
        } catch (\Exception $e) {
            $this->logger->error('Failed to log Evolution API failure event', [
                'contact_id' => $contact->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

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
        } catch (\Exception $e) {
            $this->logger->error('Failed to log Evolution API success event', [
                'contact_id' => $contact->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
