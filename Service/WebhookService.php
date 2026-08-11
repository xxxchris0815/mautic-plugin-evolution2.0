<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadNote;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Model\NoteModel;
use MauticPlugin\MauticEvolutionBundle\Entity\EvolutionMessage;
use MauticPlugin\MauticEvolutionBundle\Entity\EvolutionMessageRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Processes webhooks from Evolution API v2.x
 *
 * Event names arrive as dotted lowercase values (e.g. messages.update),
 * matching Evolution's Events enum. Uppercase aliases (MESSAGES_UPDATE) are also accepted.
 */
class WebhookService
{
    private LeadModel $leadModel;
    private NoteModel $noteModel;
    private EventDispatcherInterface $eventDispatcher;
    private LoggerInterface $logger;
    private EntityManagerInterface $entityManager;

    /**
     * Map Evolution delivery statuses to internal statuses.
     *
     * @see https://github.com/EvolutionAPI/evolution-api/blob/main/src/utils/renderStatus.ts
     */
    private const STATUS_MAP = [
        'ERROR' => 'failed',
        'PENDING' => 'pending',
        'SERVER_ACK' => 'sent',
        'DELIVERY_ACK' => 'delivered',
        'READ' => 'read',
        'PLAYED' => 'read',
        // numeric Baileys statuses sometimes leak through
        '0' => 'failed',
        '1' => 'pending',
        '2' => 'sent',
        '3' => 'delivered',
        '4' => 'read',
        '5' => 'read',
    ];

    public function __construct(
        LeadModel $leadModel,
        NoteModel $noteModel,
        EventDispatcherInterface $eventDispatcher,
        LoggerInterface $logger,
        EntityManagerInterface $entityManager
    ) {
        $this->leadModel = $leadModel;
        $this->noteModel = $noteModel;
        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger;
        $this->entityManager = $entityManager;
    }

    public function processWebhook(array $payload): array
    {
        try {
            $this->logger->info('Processing Evolution API webhook', [
                'event' => $payload['event'] ?? null,
                'instance' => $payload['instance'] ?? null,
            ]);

            $event = $this->normalizeEventName((string) ($payload['event'] ?? ''));

            switch ($event) {
                case 'messages.upsert':
                    return $this->processIncomingMessage($payload);

                case 'messages.update':
                case 'send.message.update':
                    return $this->processMessageUpdate($payload);

                case 'send.message':
                    return $this->processSendMessage($payload);

                case 'connection.update':
                    return $this->processConnectionUpdate($payload);

                default:
                    $this->logger->info('Webhook event not handled', ['event' => $event]);

                    return ['success' => true, 'data' => ['message' => 'Event ignored']];
            }
        } catch (\Exception $e) {
            $this->logger->error('Error processing webhook', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Normalize Evolution event identifiers to dotted lowercase form.
     */
    private function normalizeEventName(string $event): string
    {
        $event = trim($event);
        if ($event === '') {
            return '';
        }

        // MESSAGES_UPDATE / messages-update / messages.update -> messages.update
        $normalized = strtolower(str_replace(['_', '-'], '.', $event));

        // Collapse accidental double dots
        return preg_replace('/\.+/', '.', $normalized) ?? $normalized;
    }

    public function processIncomingMessage(array $payload): array
    {
        $data = $payload['data'] ?? [];

        if (empty($data)) {
            return ['success' => false, 'error' => 'Message data missing'];
        }

        if (isset($data['key'])) {
            $this->processMessage($data);
        } else {
            foreach ($data as $messageData) {
                if (is_array($messageData)) {
                    $this->processMessage($messageData);
                }
            }
        }

        return ['success' => true, 'data' => ['message' => 'Messages processed']];
    }

    public function processMessageUpdate(array $payload): array
    {
        $data = $payload['data'] ?? [];

        if (empty($data)) {
            return ['success' => false, 'error' => 'Update data missing'];
        }

        if (!is_array($data)) {
            $this->logger->warning('Update data is not an array', [
                'data_type' => gettype($data),
            ]);

            return ['success' => false, 'error' => 'Invalid data format'];
        }

        // Single object vs list of updates
        if (!isset($data[0]) || !is_array($data[0])) {
            $data = [$data];
        }

        foreach ($data as $updateData) {
            if (is_array($updateData)) {
                $this->updateMessageStatus($updateData);
            }
        }

        return ['success' => true, 'data' => ['message' => 'Message statuses updated']];
    }

    /**
     * Handle send.message confirmation from Evolution API.
     */
    public function processSendMessage(array $payload): array
    {
        $data = $payload['data'] ?? [];
        if (empty($data) || !is_array($data)) {
            return ['success' => true, 'data' => ['message' => 'Send event ignored']];
        }

        $items = isset($data[0]) && is_array($data[0]) ? $data : [$data];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $messageId = $this->extractMessageId($item);
            if ($messageId === null) {
                continue;
            }

            /** @var EvolutionMessageRepository $repository */
            $repository = $this->entityManager->getRepository(EvolutionMessage::class);
            $evolutionMessage = $repository->findByEvolutionMessageId($messageId);
            if (!$evolutionMessage) {
                continue;
            }

            if ($evolutionMessage->getStatus() === 'pending') {
                $evolutionMessage->setStatus('sent');
                if (!$evolutionMessage->getSentAt()) {
                    $evolutionMessage->setSentAt(new \DateTime());
                }
                $evolutionMessage->setSentReceipt($item);
                $this->entityManager->persist($evolutionMessage);
            }
        }

        $this->entityManager->flush();

        return ['success' => true, 'data' => ['message' => 'Send confirmations processed']];
    }

    public function processConnectionUpdate(array $payload): array
    {
        $data = $payload['data'] ?? [];

        if (empty($data)) {
            return ['success' => false, 'error' => 'Connection data missing'];
        }

        $state = $data['state'] ?? ($data['status'] ?? 'unknown');
        $instance = $payload['instance'] ?? 'unknown';

        $this->logger->info('Connection status updated', [
            'instance' => $instance,
            'state' => $state,
        ]);

        return ['success' => true, 'data' => ['message' => 'Connection status updated', 'state' => $state]];
    }

    private function processMessage(array $messageData): void
    {
        $key = $messageData['key'] ?? [];
        $message = $messageData['message'] ?? [];

        if (($key['fromMe'] ?? false) === true) {
            return;
        }

        $phoneNumber = $this->extractPhoneNumber((string) ($key['remoteJid'] ?? ''));
        $messageContent = $this->extractMessageContent(is_array($message) ? $message : []);

        if ($phoneNumber === '' || $messageContent === '') {
            return;
        }

        $lead = $this->findOrCreateLead($phoneNumber);

        if ($lead) {
            $this->addLeadNote($lead, $messageContent, $messageData);
        }
    }

    private function updateMessageStatus(array $updateData): void
    {
        try {
            $this->logger->info('Processing message status update', [
                'updateData' => $updateData,
            ]);

            $messageId = $this->extractMessageId($updateData);
            $rawStatus = $updateData['status'] ?? null;

            if ($messageId === null || $messageId === '') {
                $this->logger->warning('Message id not found in update payload', [
                    'updateData' => $updateData,
                ]);

                return;
            }

            if ($rawStatus === null || $rawStatus === '') {
                $this->logger->warning('Status not found in update payload', [
                    'updateData' => $updateData,
                ]);

                return;
            }

            $statusKey = strtoupper((string) $rawStatus);
            $mappedStatus = self::STATUS_MAP[$statusKey] ?? self::STATUS_MAP[(string) $rawStatus] ?? null;

            if ($mappedStatus === null) {
                $this->logger->info('Unhandled message status', [
                    'message_id' => $messageId,
                    'status' => $rawStatus,
                ]);

                return;
            }

            /** @var EvolutionMessageRepository $repository */
            $repository = $this->entityManager->getRepository(EvolutionMessage::class);
            $evolutionMessage = $repository->findByEvolutionMessageId($messageId);

            if (!$evolutionMessage) {
                $this->logger->warning('Message not found in database', [
                    'message_id' => $messageId,
                    'status' => $rawStatus,
                ]);

                return;
            }

            $updated = false;
            $currentDateTime = new \DateTime();

            switch ($mappedStatus) {
                case 'failed':
                    $evolutionMessage->setStatus('failed');
                    if (method_exists($evolutionMessage, 'setErrorMessage')) {
                        $error = $updateData['error'] ?? $updateData['message'] ?? 'Delivery failed';
                        $evolutionMessage->setErrorMessage(is_string($error) ? $error : json_encode($error));
                    }
                    $updated = true;
                    break;

                case 'sent':
                    // Do not downgrade delivered/read
                    if (!in_array($evolutionMessage->getStatus(), ['delivered', 'read'], true)) {
                        $evolutionMessage->setStatus('sent');
                        if (!$evolutionMessage->getSentAt()) {
                            $evolutionMessage->setSentAt($currentDateTime);
                        }
                        $evolutionMessage->setSentReceipt($updateData);
                        $updated = true;
                    }
                    break;

                case 'delivered':
                    if (!$evolutionMessage->getDeliveredAt()) {
                        $evolutionMessage->setStatus('delivered');
                        $evolutionMessage->setDeliveredAt($currentDateTime);
                        $evolutionMessage->setDeliveredReceipt($updateData);
                        $updated = true;
                    }
                    break;

                case 'read':
                    if (!$evolutionMessage->getReadAt()) {
                        $evolutionMessage->setStatus('read');
                        $evolutionMessage->setReadAt($currentDateTime);
                        $evolutionMessage->setReadReceipt($updateData);
                        if (!$evolutionMessage->getDeliveredAt()) {
                            $evolutionMessage->setDeliveredAt($currentDateTime);
                        }
                        $updated = true;
                    }
                    break;
            }

            if ($updated) {
                $this->entityManager->persist($evolutionMessage);
                $this->entityManager->flush();

                $this->logger->info('Message status updated successfully', [
                    'message_id' => $messageId,
                    'raw_status' => $rawStatus,
                    'mapped_status' => $mappedStatus,
                    'delivered_at' => $evolutionMessage->getDeliveredAt()?->format('Y-m-d H:i:s'),
                    'read_at' => $evolutionMessage->getReadAt()?->format('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error updating message status', [
                'error' => $e->getMessage(),
                'updateData' => $updateData,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Extract WhatsApp message id from v2 webhook payloads.
     * Supports keyId (messages.update) and key.id (messages.upsert / send.message).
     */
    private function extractMessageId(array $data): ?string
    {
        if (!empty($data['keyId']) && is_string($data['keyId'])) {
            return $data['keyId'];
        }
        if (!empty($data['key']['id']) && is_string($data['key']['id'])) {
            return $data['key']['id'];
        }
        if (!empty($data['messageId']) && is_string($data['messageId'])) {
            // Internal Evolution DB id — ignore for matching outgoing messages
            // unless keyId was missing; still return for completeness
            return null;
        }
        if (!empty($data['id']) && is_string($data['id'])) {
            return $data['id'];
        }

        return null;
    }

    private function extractPhoneNumber(string $jid): string
    {
        $phone = preg_replace('/@.*$/', '', $jid) ?? '';

        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function extractMessageContent(array $message): string
    {
        if (isset($message['conversation'])) {
            return (string) $message['conversation'];
        }

        if (isset($message['extendedTextMessage']['text'])) {
            return (string) $message['extendedTextMessage']['text'];
        }

        if (isset($message['imageMessage']['caption'])) {
            return (string) $message['imageMessage']['caption'];
        }

        if (isset($message['videoMessage']['caption'])) {
            return (string) $message['videoMessage']['caption'];
        }

        if (isset($message['documentMessage']['caption'])) {
            return (string) $message['documentMessage']['caption'];
        }

        if (isset($message['audioMessage'])) {
            return '[Audio]';
        }

        if (isset($message['imageMessage'])) {
            return '[Image]';
        }

        if (isset($message['videoMessage'])) {
            return '[Video]';
        }

        if (isset($message['documentMessage'])) {
            return '[Document]';
        }

        if (isset($message['stickerMessage'])) {
            return '[Sticker]';
        }

        if (isset($message['locationMessage'])) {
            return '[Location]';
        }

        return '';
    }

    private function findOrCreateLead(string $phoneNumber): ?Lead
    {
        $phoneFields = ['mobile', 'phone'];

        foreach ($phoneFields as $field) {
            $leads = $this->leadModel->getRepository()->findBy([$field => $phoneNumber]);
            if (!empty($leads)) {
                return $leads[0];
            }
        }

        try {
            $lead = new Lead();
            $lead->addUpdatedField('mobile', $phoneNumber);

            $this->leadModel->saveEntity($lead);

            $this->logger->info('New lead created via WhatsApp', [
                'lead_id' => $lead->getId(),
                'phone' => $phoneNumber,
            ]);

            return $lead;
        } catch (\Exception $e) {
            $this->logger->error('Error creating lead via WhatsApp', [
                'phone' => $phoneNumber,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function addLeadNote(Lead $lead, string $messageContent, array $messageData): void
    {
        try {
            $note = new LeadNote();

            $noteText = sprintf(
                "WhatsApp message received:\n%s\n\nReceived at: %s",
                $messageContent,
                date('Y-m-d H:i:s')
            );

            $note->setText($noteText);
            $note->setType('whatsapp');
            $note->setLead($lead);
            $note->setDateTime(new \DateTime());

            $this->noteModel->saveEntity($note);

            $this->logger->info('Note added to lead', [
                'lead_id' => $lead->getId(),
                'note_id' => $note->getId(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error adding note to lead', [
                'lead_id' => $lead->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function validateWebhook(array $payload): bool
    {
        return isset($payload['event']) && isset($payload['instance']);
    }
}
