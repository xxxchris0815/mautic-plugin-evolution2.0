<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\EventListener;

use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\MauticEvolutionBundle\Service\EvolutionApiService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Class LeadSubscriber
 * 
 * Event listener para integração com leads
 */
class LeadSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EvolutionApiService $evolutionApiService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            LeadEvents::LEAD_POST_SAVE => ['onLeadPostSave', 0],
            LeadEvents::LEAD_POST_DELETE => ['onLeadPostDelete', 0],
        ];
    }

    /**
     * Processa lead após salvamento
     */
    public function onLeadPostSave(LeadEvent $event): void
    {
        $lead = $event->getLead();
        
        if (!$lead) {
            return;
        }

        // Verifica se o lead tem número de WhatsApp
        $phoneNumber = $lead->getMobile() ?: $lead->getPhone();
        
        if (empty($phoneNumber)) {
            return;
        }

        // Respeita configuração para evitar checagem de WhatsApp
        if (method_exists($this->evolutionApiService, 'shouldCheckWhatsapp') && !$this->evolutionApiService->shouldCheckWhatsapp()) {
            $this->logger->info('WhatsApp check skipped by configuration', [
                'lead_id' => $lead->getId(),
            ]);
            return;
        }

        try {
            // Verifica se o número existe no WhatsApp
            $this->checkWhatsAppNumber($phoneNumber, $lead->getId());
        } catch (\Exception $e) {
            $this->logger->error('Erro ao verificar número do WhatsApp', [
                'lead_id' => $lead->getId(),
                'phone' => $phoneNumber,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Processa lead após exclusão
     */
    public function onLeadPostDelete(LeadEvent $event): void
    {
        $lead = $event->getLead();
        
        if (!$lead) {
            return;
        }

        $this->logger->info('Lead excluído', [
            'lead_id' => $lead->getId(),
            'email' => $lead->getEmail(),
        ]);
    }

    /**
     * Verifica se número existe no WhatsApp
     */
    private function checkWhatsAppNumber(string $phoneNumber, int $leadId): void
    {
        try {
            $instance = $this->evolutionApiService->getFirstAvailableInstance();
            if ($instance === '') {
                $this->logger->info('WhatsApp check skipped: no Evolution instance available', [
                    'lead_id' => $leadId,
                ]);

                return;
            }

            $result = $this->evolutionApiService->checkWhatsAppNumber($phoneNumber, null, null, $instance);
            
            if ($result && isset($result['exists'])) {
                $this->logger->info('Número WhatsApp verificado', [
                    'lead_id' => $leadId,
                    'phone' => $phoneNumber,
                    'instance' => $instance,
                    'exists' => $result['exists'],
                ]);
            }
            // Loga status-code quando houver falha para suporte
            if ($result && isset($result['success']) && $result['success'] === false) {
                $this->logger->warning('WhatsApp check failed', [
                    'lead_id' => $leadId,
                    'phone' => $phoneNumber,
                    'instance' => $instance,
                    'status_code' => $result['status_code'] ?? null,
                    'error' => $result['error'] ?? null,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Falha na verificação do WhatsApp', [
                'lead_id' => $leadId,
                'phone' => $phoneNumber,
                'error' => $e->getMessage(),
            ]);
        }
    }
}