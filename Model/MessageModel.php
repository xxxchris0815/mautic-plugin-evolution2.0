<?php

namespace MauticPlugin\MauticEvolutionBundle\Model;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Model\FormModel;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Helper\TokenHelper;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\MauticEvolutionBundle\Entity\EvolutionMessage;
use MauticPlugin\MauticEvolutionBundle\Entity\EvolutionMessageRepository;
use MauticPlugin\MauticEvolutionBundle\Exception\EvolutionApiException;
use MauticPlugin\MauticEvolutionBundle\Exception\EvolutionDeliveryException;
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
     */
    public function sendMessage(Lead $lead, string $message, ?string $templateName = null, ?string $groupAlias = null, string $phoneField = 'mobile', array $headers = [], array $metadata = []): ?EvolutionMessage
    {
        $phoneNumber = $this->getLeadPhoneNumber($lead, $phoneField);
        
        if (empty($phoneNumber)) {
            $this->logger->warning('Cannot send Evolution message: Lead has no phone number', ['leadId' => $lead->getId()]);
            return null;
        }

        try {
            // Interpolate tokens in message content using lead data
            $leadData = $lead->getProfileFields();
            
            // First, handle simple tokens like {firstname} by converting them to {contactfield=firstname}
            $message = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '{contactfield=$1}', $message);
            
            // Then use TokenHelper to replace the tokens
            $interpolatedMessage = TokenHelper::findLeadTokens($message, $leadData, true);

            // Parse headers and metadata with lead tokens
            $parsedHeaders = $this->interpolateTokensInMap($headers, $lead);
            $parsedMetadata = $this->interpolateTokensInMap($metadata, $lead, false);

            // Create message entity
            $evolutionMessage = new EvolutionMessage();
            $evolutionMessage->setLead($lead);
            $evolutionMessage->setPhoneNumber($phoneNumber);
            $evolutionMessage->setMessageContent($interpolatedMessage);
            $evolutionMessage->setTemplateName($templateName);
            $evolutionMessage->setStatus('pending');
            if (!empty($parsedMetadata)) {
                $evolutionMessage->setMetadata($parsedMetadata);
            }

            // Evolution API v2: POST /message/sendText/{instance}
            // Optional custom group balancing when alias is provided
            if (!empty($groupAlias)) {
                $response = $this->evolutionApiService->sendTextWithGroupBalancing(
                    $groupAlias,
                    $phoneNumber,
                    $interpolatedMessage,
                    [],
                    $lead,
                    null,
                    $parsedHeaders,
                    $parsedMetadata
                );
            } else {
                $response = $this->evolutionApiService->sendTextMessage(
                    $phoneNumber,
                    $interpolatedMessage,
                    $lead,
                    null,
                    $parsedHeaders,
                    $parsedMetadata
                );
            }

            $messageId = $response['data']['key']['id']
                ?? ($response['data']['data']['key']['id'] ?? null);

            if ($messageId) {
                $evolutionMessage->setMessageId($messageId);
                $evolutionMessage->setStatus('sent');
                $evolutionMessage->setSentAt(new \DateTime());
                if (method_exists($evolutionMessage, 'setSentReceipt')) {
                    $evolutionMessage->setSentReceipt(is_array($response['data'] ?? null) ? $response['data'] : null);
                }
            } else {
                $evolutionMessage->setStatus('failed');
                $evolutionMessage->setErrorMessage($response['error'] ?? 'Failed to send message via Evolution API');
            }

            $this->saveEntity($evolutionMessage);

            if ($evolutionMessage->getStatus() === 'failed') {
                throw new EvolutionDeliveryException(
                    $evolutionMessage->getErrorMessage() ?? 'Failed to send message via Evolution API'
                );
            }

            return $evolutionMessage;
        } catch (EvolutionDeliveryException|EvolutionApiException $e) {
            $this->logger->error('Evolution delivery failed', [
                'leadId' => $lead->getId(),
                'error' => $e->getMessage(),
            ]);

            if (isset($evolutionMessage) && $evolutionMessage instanceof EvolutionMessage) {
                $evolutionMessage->setStatus('failed');
                $evolutionMessage->setErrorMessage($e->getMessage());
                try {
                    $this->saveEntity($evolutionMessage);
                } catch (\Exception $saveException) {
                    $this->logger->error('Failed to persist failed Evolution message', [
                        'error' => $saveException->getMessage(),
                    ]);
                }
            }

            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Error sending Evolution message', [
                'leadId' => $lead->getId(),
                'error' => $e->getMessage(),
            ]);

            if (isset($evolutionMessage) && $evolutionMessage instanceof EvolutionMessage) {
                $evolutionMessage->setStatus('failed');
                $evolutionMessage->setErrorMessage($e->getMessage());
                try {
                    $this->saveEntity($evolutionMessage);
                } catch (\Exception $saveException) {
                    $this->logger->error('Failed to persist failed Evolution message', [
                        'error' => $saveException->getMessage(),
                    ]);
                }
            }

            throw new EvolutionDeliveryException($e->getMessage(), (int) $e->getCode(), $e);
        }
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
            $v = TokenHelper::findLeadTokens($v, $leadData, true);
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
        $message = $this->getRepository()->findByEvolutionMessageId($MessageId);
        
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
     * Get the contact's phone number honoring selected field.
     * Digits only with country code, no leading '+'.
     */
    private function getLeadPhoneNumber(Lead $lead, string $phoneField = 'mobile'): ?string
    {
        $fieldsOrder = array_unique(array_filter([$phoneField, 'mobile', 'phone', 'whatsapp']));
        foreach ($fieldsOrder as $field) {
            $phone = method_exists($lead, 'getFieldValue') ? $lead->getFieldValue($field) : null;
            if (!empty($phone)) {
                return $this->evolutionApiService->formatPhoneNumber((string) $phone);
            }
        }

        if (method_exists($lead, 'getLeadPhoneNumber')) {
            $fallback = $lead->getLeadPhoneNumber();
            if (!empty($fallback)) {
                return $this->evolutionApiService->formatPhoneNumber((string) $fallback);
            }
        }

        return null;
    }
}