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
            if ($entity->getDescription() === null || $entity->getDescription() === '' || str_starts_with((string) $entity->getDescription(), 'Evolution template (')) {
                $entity->setDescription(sprintf(
                    'Evolution template (%s / %s)',
                    $item['category'] ?: 'n/a',
                    $item['status'] ?: 'UNKNOWN'
                ));
            }

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
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        if ($data === [] && isset($result['response'])) {
            $decoded = is_array($result['response']) ? $result['response'] : json_decode((string) $result['response'], true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        $apiError = TemplatePayloadBuilder::extractErrorMessage($data)
            ?? TemplatePayloadBuilder::extractErrorMessage($result['error'] ?? null);
        if (empty($result['success']) || ($apiError !== null && $this->responseIndicatesError($data))) {
            return [
                'success' => false,
                'error' => $apiError ?: (string) ($result['error'] ?? 'Unable to create template on Evolution API'),
                'data' => $data,
            ];
        }

        $created = is_array($data['template'] ?? null) ? $data['template'] : $data;
        if (is_array($created)) {
            $id = $created['id'] ?? ($created['hsm_id'] ?? null);
            if ($id !== null && $id !== '') {
                $template->setEvolutionId((string) $id);
            }
            $template->setStatus(strtoupper((string) ($created['status'] ?? 'PENDING')));
        } else {
            $template->setStatus('PENDING');
        }
        $template->setSource('evolution');
        $this->templateModel->saveEntity($template);

        return [
            'success' => true,
            'data' => $data,
        ];
    }

    /**
     * Remove a Meta template on Evolution API, then drop the local copy.
     *
     * @return array{success: bool, error?: string}
     */
    public function deleteFromApi(EvolutionTemplate $template): array
    {
        $name = (string) $template->getName();
        $result = $this->evolutionApiService->deleteTemplate($name, $template->getEvolutionId());
        $status = (int) ($result['status_code'] ?? 0);
        if (empty($result['success']) && $status !== 404) {
            $error = TemplatePayloadBuilder::extractErrorMessage($result['response'] ?? $result)
                ?? (string) ($result['error'] ?? 'Unable to delete template on Evolution API');

            return [
                'success' => false,
                'error' => $error,
            ];
        }

        $this->templateModel->deleteEntity($template);

        return ['success' => true];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function responseIndicatesError(array $data): bool
    {
        if (array_key_exists('error', $data) && $data['error'] === true) {
            return true;
        }
        if (isset($data['status']) && is_numeric($data['status']) && (int) $data['status'] >= 400) {
            return true;
        }

        return false;
    }
}
