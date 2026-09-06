<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Service;

use MauticPlugin\MauticEvolutionBundle\Entity\EvolutionTemplate;
use MauticPlugin\MauticEvolutionBundle\Helper\TemplatePayloadBuilder;
use MauticPlugin\MauticEvolutionBundle\Model\TemplateModel;
use Psr\Log\LoggerInterface;

class TemplateSyncService
{
    public function __construct(
        private EvolutionApiService $evolutionApiService,
        private TemplateModel $templateModel,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Pull official WhatsApp templates from Evolution API into Mautic.
     *
     * @return array{success: bool, imported: int, updated: int, error?: string}
     */
    public function syncFromApi(): array
    {
        $result = $this->evolutionApiService->findTemplates();
        if (empty($result['success'])) {
            return [
                'success' => false,
                'imported' => 0,
                'updated' => 0,
                'error' => (string) ($result['error'] ?? 'Unable to fetch templates from Evolution API'),
            ];
        }

        $templates = TemplatePayloadBuilder::normalizeFindResponse($result['data'] ?? $result);
        $imported = 0;
        $updated = 0;

        foreach ($templates as $item) {
            $existing = $this->templateModel->getTemplateByNameAndLanguage($item['name'], $item['language'] ?: null);
            $isNew = $existing === null;
            $entity = $existing ?? new EvolutionTemplate();

            $body = TemplatePayloadBuilder::extractBodyText($item['components']);
            $placeholders = TemplatePayloadBuilder::extractBodyPlaceholders($item['components']);

            $entity->setName($item['name']);
            $entity->setSource('evolution');
            $entity->setLanguage($item['language'] ?: 'en');
            $entity->setCategoryType($item['category'] ?: null);
            $entity->setStatus($item['status'] ?: 'UNKNOWN');
            $entity->setEvolutionId($item['id'] ?: null);
            $entity->setComponents($item['components']);
            $entity->setContent($body !== '' ? $body : $entity->getContent());
            $entity->setType('template');
            $entity->setVariables($placeholders);
            $entity->setIsActive(strtoupper((string) $item['status']) === 'APPROVED');
            $entity->setDescription(sprintf(
                'Evolution template (%s / %s)',
                $item['category'] ?: 'n/a',
                $item['status'] ?: 'UNKNOWN'
            ));

            $this->templateModel->saveEntity($entity);
            $isNew ? ++$imported : ++$updated;
        }

        $this->logger->info('Evolution templates synced', [
            'imported' => $imported,
            'updated' => $updated,
            'total' => count($templates),
        ]);

        return [
            'success' => true,
            'imported' => $imported,
            'updated' => $updated,
        ];
    }

    /**
     * Create a WhatsApp Business template on Evolution API and store it locally.
     *
     * @param array<string, mixed> $payload
     */
    public function createOnApi(EvolutionTemplate $template, array $payload): array
    {
        $result = $this->evolutionApiService->createTemplate($payload);
        if (empty($result['success'])) {
            return $result;
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $created = $data['template'] ?? $data;
        if (is_array($created)) {
            $template->setEvolutionId((string) ($created['id'] ?? $template->getEvolutionId()));
            $template->setStatus(strtoupper((string) ($created['status'] ?? 'PENDING')));
        } else {
            $template->setStatus('PENDING');
        }
        $template->setSource('evolution');
        $this->templateModel->saveEntity($template);

        return $result;
    }
}
