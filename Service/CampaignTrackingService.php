<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\LeadEventLog as CampaignLeadEventLog;
use Mautic\CampaignBundle\Executioner\RealTimeExecutioner;
use Mautic\LeadBundle\Tracker\ContactTracker;
use MauticPlugin\MauticEvolutionBundle\Entity\EvolutionMessage;
use MauticPlugin\MauticEvolutionBundle\EvolutionEvents;
use MauticPlugin\MauticEvolutionBundle\Helper\WebhookStatusMapper;
use Psr\Log\LoggerInterface;

/**
 * Writes WhatsApp delivery/read/reply results back into campaign logs, analysis and decisions.
 */
class CampaignTrackingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RealTimeExecutioner $realTimeExecutioner,
        private ContactTracker $contactTracker,
        private LoggerInterface $logger
    ) {
    }

    public function applyStatus(EvolutionMessage $message, string $status, array $receipt = []): void
    {
        $now = new \DateTime();
        $previous = $message->getStatus();

        if (!WebhookStatusMapper::isTerminalUpgrade($previous, $status) && $status !== WebhookStatusMapper::STATUS_FAILED) {
            return;
        }

        $message->setStatus($status);

        switch ($status) {
            case WebhookStatusMapper::STATUS_SENT:
                if (!$message->getSentAt()) {
                    $message->setSentAt($now);
                }
                $message->setSentReceipt($receipt);
                break;
            case WebhookStatusMapper::STATUS_DELIVERED:
                if (!$message->getDeliveredAt()) {
                    $message->setDeliveredAt($now);
                }
                $message->setDeliveredReceipt($receipt);
                if (!$message->getSentAt()) {
                    $message->setSentAt($now);
                }
                break;
            case WebhookStatusMapper::STATUS_READ:
                if (!$message->getReadAt()) {
                    $message->setReadAt($now);
                }
                $message->setReadReceipt($receipt);
                if (!$message->getDeliveredAt()) {
                    $message->setDeliveredAt($now);
                }
                if (!$message->getSentAt()) {
                    $message->setSentAt($now);
                }
                break;
            case WebhookStatusMapper::STATUS_FAILED:
                $message->setErrorMessage((string) ($receipt['error'] ?? $receipt['status'] ?? 'failed'));
                break;
        }

        $this->entityManager->persist($message);
        $this->appendCampaignLogMetadata($message, $status, $now);
        $this->entityManager->flush();

        $this->triggerDecision($message, $status);
    }

    public function triggerReply(EvolutionMessage $relatedOutgoing, string $replyText): void
    {
        if (!$relatedOutgoing->getRepliedAt()) {
            $relatedOutgoing->setRepliedAt(new \DateTime());
            $this->entityManager->persist($relatedOutgoing);
        }
        $this->appendCampaignLogMetadata($relatedOutgoing, 'replied', new \DateTime(), ['reply' => $replyText]);
        $this->entityManager->flush();
        $this->executeRealtime($relatedOutgoing, EvolutionEvents::ON_CAMPAIGN_DECISION, 'evolution.replied');
    }

    private function appendCampaignLogMetadata(EvolutionMessage $message, string $status, \DateTimeInterface $at, array $extra = []): void
    {
        $logId = $message->getCampaignEventLogId();
        if (!$logId) {
            return;
        }

        $log = $this->entityManager->getRepository(CampaignLeadEventLog::class)->find($logId);
        if (!$log instanceof CampaignLeadEventLog) {
            return;
        }

        $metadata = [
            'whatsapp_status' => $status,
            'whatsapp_status_at' => $at->format(DATE_ATOM),
            'whatsapp_message_id' => $message->getMessageId(),
        ];

        $log->appendToMetadata(array_merge($metadata, $extra));
        $this->entityManager->persist($log);
    }

    private function triggerDecision(EvolutionMessage $message, string $status): void
    {
        $type = match ($status) {
            WebhookStatusMapper::STATUS_DELIVERED => 'evolution.delivered',
            WebhookStatusMapper::STATUS_READ => 'evolution.read',
            default => null,
        };

        if ($type === null) {
            return;
        }

        $this->executeRealtime($message, EvolutionEvents::ON_CAMPAIGN_DECISION, $type);
    }

    private function executeRealtime(EvolutionMessage $message, string $unusedEvent, string $type): void
    {
        $lead = $message->getLead();
        if (!$lead) {
            return;
        }

        try {
            $this->contactTracker->setSystemContact($lead);
            $this->realTimeExecutioner->execute($type, $message, 'whatsapp', $message->getId());
        } catch (\Throwable $e) {
            $this->logger->warning('Evolution campaign decision could not be executed', [
                'type' => $type,
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
