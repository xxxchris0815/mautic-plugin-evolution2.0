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
use MauticPlugin\MauticEvolutionBundle\Helper\PhoneNumberHelper;
use MauticPlugin\MauticEvolutionBundle\Helper\WebhookStatusMapper;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class WebhookService
{
    public function __construct(
        private LeadModel $leadModel,
        private NoteModel $noteModel,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
        private CampaignTrackingService $campaignTrackingService,
        private EvolutionApiService $evolutionApiService
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{success: bool, data?: array<string, mixed>, error?: string}
     */
    public function processWebhook(array $payload): array
    {
        try {
            $event = strtolower((string) ($payload['event'] ?? ''));
            $this->logger->info('Processando webhook Evolution API', ['event' => $event]);

            return match ($event) {
                'messages.upsert', 'messages_upsert', 'messages.set', 'messages_set' => $this->processIncomingMessage($payload),
                'messages.update', 'messages_update', 'send.message', 'send_message' => $this->processMessageUpdate($payload),
                'connection.update', 'connection_update' => $this->processConnectionUpdate($payload),
                default => ['success' => true, 'data' => ['message' => 'Evento não processado', 'event' => $event]],
            };
        } catch (\Exception $e) {
            $this->logger->error('Erro ao processar webhook', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{success: bool, error?: string, data?: array<string, mixed>}
     */
    public function processIncomingMessage(array $payload): array
    {
        $data = $payload['data'] ?? [];
        if (empty($data)) {
            return ['success' => false, 'error' => 'Dados da mensagem não encontrados'];
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

        return ['success' => true, 'data' => ['message' => 'Mensagens processadas']];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{success: bool, error?: string, data?: array<string, mixed>}
     */
    public function processMessageUpdate(array $payload): array
    {
        $data = $payload['data'] ?? [];
        if (empty($data)) {
            return ['success' => false, 'error' => 'Dados da atualização não encontrados'];
        }

        if (!is_array($data)) {
            return ['success' => false, 'error' => 'Formato de dados inválido'];
        }

        if (!isset($data[0]) || !is_array($data[0])) {
            $data = [$data];
        }

        foreach ($data as $updateData) {
            if (is_array($updateData)) {
                $this->updateMessageStatus($updateData);
            }
        }

        return ['success' => true, 'data' => ['message' => 'Status das mensagens atualizados']];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{success: bool, error?: string, data?: array<string, mixed>}
     */
    public function processConnectionUpdate(array $payload): array
    {
        $data = $payload['data'] ?? [];
        $state = is_array($data) ? ($data['state'] ?? 'unknown') : 'unknown';

        $this->logger->info('Status de conexão atualizado', [
            'instance' => $payload['instance'] ?? 'unknown',
            'state' => $state,
        ]);

        return ['success' => true, 'data' => ['message' => 'Status de conexão atualizado']];
    }

    /**
     * @param array<string, mixed> $messageData
     */
    private function processMessage(array $messageData): void
    {
        $key = $messageData['key'] ?? [];
        if (($key['fromMe'] ?? false) === true) {
            $this->updateMessageStatus($messageData);

            return;
        }

        $phoneNumber = PhoneNumberHelper::fromJid((string) ($key['remoteJid'] ?? ''));
        $messageContent = $this->extractMessageContent($messageData['message'] ?? []);
        if ($phoneNumber === '' || $messageContent === '') {
            return;
        }

        $lead = $this->findOrCreateLead($phoneNumber);
        if (!$lead) {
            return;
        }

        $this->addLeadNote($lead, $messageContent, $messageData);

        $outgoing = $this->findLatestOutgoingMessage($lead, $phoneNumber);
        if ($outgoing) {
            $this->campaignTrackingService->triggerReply($outgoing, $messageContent);
        }
    }

    /**
     * @param array<string, mixed> $updateData
     */
    private function updateMessageStatus(array $updateData): void
    {
        $messageId = WebhookStatusMapper::extractMessageId($updateData);
        $rawStatus = WebhookStatusMapper::extractRawStatus($updateData);
        if (!$messageId || !$rawStatus) {
            return;
        }

        $status = WebhookStatusMapper::map($rawStatus);
        if ($status === null) {
            $this->logger->info('Status não processado', [
                'message_id' => $messageId,
                'status' => $rawStatus,
            ]);

            return;
        }

        /** @var EvolutionMessageRepository $repository */
        $repository = $this->entityManager->getRepository(EvolutionMessage::class);
        $evolutionMessage = $repository->findByEvolutionMessageId($messageId);
        if (!$evolutionMessage) {
            $this->logger->warning('Mensagem não encontrada na base de dados', [
                'message_id' => $messageId,
                'status' => $status,
            ]);

            return;
        }

        $this->campaignTrackingService->applyStatus($evolutionMessage, $status, $updateData);
    }

    private function findLatestOutgoingMessage(Lead $lead, string $phoneNumber): ?EvolutionMessage
    {
        return $this->entityManager->getRepository(EvolutionMessage::class)
            ->createQueryBuilder('m')
            ->where('m.lead = :lead')
            ->andWhere('m.campaignEventId IS NOT NULL')
            ->setParameter('lead', $lead->getId())
            ->orderBy('m.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param array<string, mixed> $message
     */
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
        if (isset($message['templateMessage'])) {
            return '[Template]';
        }
        if (isset($message['buttonsResponseMessage']['selectedDisplayText'])) {
            return (string) $message['buttonsResponseMessage']['selectedDisplayText'];
        }
        if (isset($message['listResponseMessage']['title'])) {
            return (string) $message['listResponseMessage']['title'];
        }
        if (isset($message['audioMessage'])) {
            return '[Áudio]';
        }
        if (isset($message['imageMessage'])) {
            return '[Imagem]';
        }
        if (isset($message['videoMessage'])) {
            return '[Vídeo]';
        }
        if (isset($message['documentMessage'])) {
            return '[Documento]';
        }
        if (isset($message['stickerMessage'])) {
            return '[Sticker]';
        }
        if (isset($message['locationMessage'])) {
            return '[Localização]';
        }

        return '';
    }

    private function findOrCreateLead(string $phoneNumber): ?Lead
    {
        $country = $this->evolutionApiService->getDefaultCountryCode();
        $normalized = PhoneNumberHelper::normalize($phoneNumber, $country);
        $candidates = array_unique(array_filter([$phoneNumber, $normalized]));

        foreach (['mobile', 'phone', 'whatsapp'] as $field) {
            foreach ($candidates as $candidate) {
                $leads = $this->leadModel->getRepository()->findBy([$field => $candidate]);
                if (!empty($leads)) {
                    return $leads[0];
                }
            }
        }

        try {
            $lead = new Lead();
            $lead->addUpdatedField('mobile', $normalized ?: $phoneNumber);
            $this->leadModel->saveEntity($lead);

            return $lead;
        } catch (\Exception $e) {
            $this->logger->error('Erro ao criar lead via WhatsApp', [
                'phone' => $phoneNumber,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $messageData
     */
    private function addLeadNote(Lead $lead, string $messageContent, array $messageData): void
    {
        try {
            $note = new LeadNote();
            $note->setText(sprintf("WhatsApp reply:\n%s\n\n%s", $messageContent, date('Y-m-d H:i:s')));
            $note->setType('general');
            $note->setLead($lead);
            $note->setDateTime(new \DateTime());
            $this->noteModel->saveEntity($note);
        } catch (\Exception $e) {
            $this->logger->error('Erro ao adicionar nota ao lead', [
                'lead_id' => $lead->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function validateWebhook(array $payload): bool
    {
        return isset($payload['event']);
    }
}
