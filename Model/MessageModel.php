<?php

namespace MauticPlugin\MauticEvolutionBundle\Model;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Model\FormModel;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticEvolutionBundle\Helper\PhoneNumberHelper;
use MauticPlugin\MauticEvolutionBundle\Helper\TemplatePayloadBuilder;
use MauticPlugin\MauticEvolutionBundle\Helper\TokenHelper;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\MauticEvolutionBundle\Entity\EvolutionMessage;
use MauticPlugin\MauticEvolutionBundle\Entity\EvolutionMessageRepository;
use MauticPlugin\MauticEvolutionBundle\Service\EvolutionApiService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @extends FormModel<EvolutionMessage>
 */
class MessageModel extends FormModel
{
    public function __construct(
        protected LeadModel $leadModel,
        protected EvolutionApiService $evolutionApiService,
        EntityManagerInterface $em,
        CorePermissions $security,
        EventDispatcherInterface $dispatcher,
        UrlGeneratorInterface $router,
        Translator $translator,
        UserHelper $userHelper,
        LoggerInterface $mauticLogger,
        CoreParametersHelper $coreParametersHelper
    ) {
        parent::__construct($em, $security, $dispatcher, $router, $translator, $userHelper, $mauticLogger, $coreParametersHelper);
    }

    /**
     * {@inheritdoc}
     */
    public function getRepository(): EvolutionMessageRepository
    {
        return $this->em->getRepository(EvolutionMessage::class);
    }

    /**
     * {@inheritdoc}
     */
    public function getPermissionBase(): string
    {
        return 'evolution:messages';
    }

    /**
     * Send message via Evolution API
     *
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $metadata
     * @param array{campaignId?: ?int, campaignEventId?: ?int, campaignEventLogId?: ?int} $campaignContext
     */
    public function sendMessage(Lead $lead, string $message, ?string $templateName = null, ?string $groupAlias = null, string $phoneField = 'mobile', array $headers = [], array $metadata = [], array $campaignContext = []): ?EvolutionMessage
    {
        $phoneNumber = $this->getLeadPhoneNumber($lead, $phoneField);

        if (empty($phoneNumber)) {
            $this->logger->warning('Cannot send Evolution message: Lead has no phone number', ['leadId' => $lead->getId()]);
            return null;
        }

        try {
            $interpolatedMessage = TokenHelper::replaceForLead($message, $lead);
            $parsedHeaders = TokenHelper::replaceMap($headers, $lead, true);
            $parsedMetadata = TokenHelper::replaceMap($metadata, $lead, false);

            $evolutionMessage = $this->createPendingMessage($lead, $phoneNumber, $interpolatedMessage, $templateName, 'text', $parsedMetadata, $campaignContext);

            $response = !empty($groupAlias)
                ? $this->evolutionApiService->sendTextWithGroupBalancing($groupAlias, $phoneNumber, $interpolatedMessage, [], $lead, null, $parsedHeaders, $parsedMetadata)
                : $this->evolutionApiService->sendTextMessage($phoneNumber, $interpolatedMessage, $lead, null, $parsedHeaders, $parsedMetadata);

            $this->applySendResponse($evolutionMessage, $response);
            $this->saveEntity($evolutionMessage);

            return $evolutionMessage;
        } catch (\Exception $e) {
            $this->logger->error('Error sending Evolution message', [
                'leadId' => $lead->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param list<array<string, mixed>> $components
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $metadata
     * @param array{campaignId?: ?int, campaignEventId?: ?int, campaignEventLogId?: ?int} $campaignContext
     */
    public function sendOfficialTemplate(
        Lead $lead,
        \MauticPlugin\MauticEvolutionBundle\Entity\EvolutionTemplate $template,
        array $components,
        string $phoneField = 'mobile',
        ?string $groupAlias = null,
        array $headers = [],
        array $metadata = [],
        array $campaignContext = []
    ): ?EvolutionMessage {
        $phoneNumber = $this->getLeadPhoneNumber($lead, $phoneField);
        if (empty($phoneNumber)) {
            return null;
        }

        $content = TemplatePayloadBuilder::extractBodyText($template->getComponents() ?? []) ?: (string) $template->getContent();
        $parsedHeaders = TokenHelper::replaceMap($headers, $lead, true);
        $parsedMetadata = TokenHelper::replaceMap($metadata, $lead, false);

        $evolutionMessage = $this->createPendingMessage(
            $lead,
            $phoneNumber,
            $content,
            $template->getName(),
            'template',
            $parsedMetadata,
            $campaignContext
        );

        $response = $this->evolutionApiService->sendTemplate(
            $phoneNumber,
            (string) $template->getName(),
            (string) ($template->getLanguage() ?: 'en'),
            $components,
            $lead,
            null,
            $parsedHeaders,
            $parsedMetadata
        );

        $this->applySendResponse($evolutionMessage, $response);
        $this->saveEntity($evolutionMessage);

        return $evolutionMessage;
    }

    /**
     * @param array{campaignId?: ?int, campaignEventId?: ?int, campaignEventLogId?: ?int} $campaignContext
     */
    public function sendMedia(
        Lead $lead,
        string $mediaUrl,
        string $caption = '',
        string $mediaType = 'image',
        string $phoneField = 'mobile',
        array $campaignContext = []
    ): ?EvolutionMessage {
        $phoneNumber = $this->getLeadPhoneNumber($lead, $phoneField);
        if (empty($phoneNumber)) {
            return null;
        }

        $evolutionMessage = $this->createPendingMessage($lead, $phoneNumber, $caption, null, $mediaType, ['media_url' => $mediaUrl], $campaignContext);
        $response = $this->evolutionApiService->sendMediaMessage($phoneNumber, $mediaUrl, $caption, $lead, null, $mediaType);
        $this->applySendResponse($evolutionMessage, $response);
        $this->saveEntity($evolutionMessage);

        return $evolutionMessage;
    }

    /**
     * @return array{sent: int, delivered: int, read: int, failed: int, pending: int}
     */
    public function getCampaignEventStats(int $campaignEventId): array
    {
        return $this->getRepository()->getStatsSummaryForCampaignEvent($campaignEventId);
    }

    /**
     * Interpolate tokens inside a key-value map using lead data.
     * Converts simple tokens {firstname} to {contactfield=firstname} before replacement.
     * If $headersMode is true, keeps values as strings (HTTP header semantics).
     */
    private function interpolateTokensInMap(array $map, Lead $lead, bool $headersMode = true): array
    {
        if (empty($map)) {
            return [];
        }

        $leadData = $lead->getProfileFields();
        $out = [];
        foreach ($map as $key => $value) {
            if (!is_string($key) || trim((string) $key) === '') {
                continue;
            }
            $k = trim((string) $key);
            $v = is_string($value) ? $value : (string) $value;
            // Convert {field} -> {contactfield=field}
            $v = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '{contactfield=$1}', $v);
            $v = TokenHelper::replace($v, $leadData);
            // Cast types for metadata; keep strings for headers
            if ($headersMode) {
                $out[$k] = $v;
            } else {
                $out[$k] = $this->castScalar($v);
            }
        }

        // Log preview for debugging
        $this->logger->info('Evolution MessageModel - parsed pairs', [
            'headers_mode' => $headersMode,
            'keys' => array_keys($out),
        ]);

        return $out;
    }

    /**
     * Cast a scalar string to bool/int/float/null when appropriate.
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
            if (is_numeric($trim)) {
                return strpos($trim, '.') !== false ? (float) $trim : (int) $trim;
            }
            return $value;
        }
        return $value;
    }

    /**
     * Get messages by lead
     */
    public function getMessagesByLead(Lead $lead): array
    {
        return $this->getRepository()->findByLead($lead);
    }

    /**
     * Get messages by status
     */
    public function getMessagesByStatus(string $status): array
    {
        return $this->getRepository()->findByStatus($status);
    }

    /**
     * Get pending messages
     */
    public function getPendingMessages(): array
    {
        return $this->getRepository()->findPendingMessages();
    }

    /**
     * Update message status from webhook
     */
    public function updateMessageStatus(string $MessageId, string $status, ?\DateTime $timestamp = null): bool
    {
        $message = $this->getRepository()->findByMessageId($MessageId);
        
        if (!$message) {
            return false;
        }

        $message->setStatus($status);
        
        switch ($status) {
            case 'delivered':
                $message->setDeliveredAt($timestamp ?: new \DateTime());
                break;
            case 'read':
                $message->setReadAt($timestamp ?: new \DateTime());
                break;
        }

        $this->saveEntity($message);
        
        return true;
    }

    /**
     * Get message statistics
     */
    public function getMessageStats(): array
    {
        return $this->getRepository()->getMessageStats();
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array{campaignId?: ?int, campaignEventId?: ?int, campaignEventLogId?: ?int} $campaignContext
     */
    private function createPendingMessage(
        Lead $lead,
        string $phoneNumber,
        string $content,
        ?string $templateName,
        string $messageType,
        array $metadata,
        array $campaignContext
    ): EvolutionMessage {
        $evolutionMessage = new EvolutionMessage();
        $evolutionMessage->setLead($lead);
        $evolutionMessage->setPhoneNumber($phoneNumber);
        $evolutionMessage->setMessageContent($content);
        $evolutionMessage->setTemplateName($templateName);
        $evolutionMessage->setStatus('pending');
        $evolutionMessage->setMessageType($messageType);
        $evolutionMessage->setInstance($this->evolutionApiService->getInstance());
        if ($metadata !== []) {
            $evolutionMessage->setMetadata($metadata);
        }
        if (!empty($campaignContext['campaignId'])) {
            $evolutionMessage->setCampaignId((int) $campaignContext['campaignId']);
        }
        if (!empty($campaignContext['campaignEventId'])) {
            $evolutionMessage->setCampaignEventId((int) $campaignContext['campaignEventId']);
        }
        if (!empty($campaignContext['campaignEventLogId'])) {
            $evolutionMessage->setCampaignEventLogId((int) $campaignContext['campaignEventLogId']);
        }

        return $evolutionMessage;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function applySendResponse(EvolutionMessage $evolutionMessage, array $response): void
    {
        $messageId = $this->evolutionApiService->extractResponseMessageId($response['data'] ?? null);
        if (!$messageId && !empty($response['success'])) {
            $messageId = $this->evolutionApiService->extractResponseMessageId($response);
        }

        if (!empty($response['success']) || $messageId) {
            $evolutionMessage->setMessageId($messageId);
            $evolutionMessage->setStatus('sent');
            $evolutionMessage->setSentAt(new \DateTime());
            $evolutionMessage->setSentReceipt(is_array($response['data'] ?? null) ? $response['data'] : $response);
        } else {
            $evolutionMessage->setStatus('failed');
            $evolutionMessage->setErrorMessage((string) ($response['error'] ?? 'Failed to send message via Evolution API'));
        }
    }

    /**
     * Get the contact's phone number honoring selected field
     */
    private function getLeadPhoneNumber(Lead $lead, string $phoneField = 'mobile'): ?string
    {
        $fieldsOrder = array_unique(array_filter([$phoneField, 'mobile', 'phone', 'whatsapp']));
        $country = $this->evolutionApiService->getDefaultCountryCode();
        foreach ($fieldsOrder as $field) {
            $phone = method_exists($lead, 'getFieldValue') ? $lead->getFieldValue($field) : null;
            if (!empty($phone)) {
                $clean = PhoneNumberHelper::normalize((string) $phone, $country);
                return $clean !== '' ? $clean : null;
            }
        }

        $fallback = method_exists($lead, 'getLeadPhoneNumber') ? $lead->getLeadPhoneNumber() : null;

        return $fallback ? PhoneNumberHelper::normalize((string) $fallback, $country) : null;
    }
}