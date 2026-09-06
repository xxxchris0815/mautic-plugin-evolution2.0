<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;
use MauticPlugin\MauticEvolutionBundle\Helper\MessageStatsCalculator;

/**
 * Class EvolutionMessageRepository
 * 
 * Repositório para gerenciar consultas da entidade EvolutionMessage
 */
class EvolutionMessageRepository extends CommonRepository
{
    /**
     * Busca mensagens por lead
     */
    public function findByLead(int $leadId): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.lead = :leadId')
            ->setParameter('leadId', $leadId)
            ->orderBy('m.dateAdded', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Busca mensagens por status
     */
    public function findByStatus(string $status): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.status = :status')
            ->setParameter('status', $status)
            ->orderBy('m.dateAdded', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Busca mensagens pendentes
     */
    public function findPendingMessages(): array
    {
        return $this->findByStatus('pending');
    }

    /**
     * Busca mensagem por ID da Evolution API
     */
    public function findByEvolutionMessageId(string $evolutionMessageId): ?EvolutionMessage
    {
        return $this->createQueryBuilder('m')
            ->where('m.messageId = :evolutionMessageId')
            ->setParameter('evolutionMessageId', $evolutionMessageId)
            ->orderBy('m.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByMessageId(string $messageId): ?EvolutionMessage
    {
        return $this->findByEvolutionMessageId($messageId);
    }

    /**
     * @return array{
     *     pending: int,
     *     sent: int,
     *     delivered: int,
     *     read: int,
     *     failed: int,
     *     replied: int,
     *     total: int,
     *     accepted: int,
     *     reached: int,
     *     delivery_rate: float,
     *     read_rate: float,
     *     reply_rate: float,
     *     fail_rate: float
     * }
     */
    public function getStatsSummaryForCampaignEvent(int $campaignEventId): array
    {
        return $this->buildStatsFromRows(
            $this->createQueryBuilder('m')
                ->select('m.status, COUNT(m.id) as cnt')
                ->where('m.campaignEventId = :eventId')
                ->setParameter('eventId', $campaignEventId)
                ->groupBy('m.status')
                ->getQuery()
                ->getArrayResult(),
            $this->countReplied('m.campaignEventId = :eventId', ['eventId' => $campaignEventId])
        );
    }

    /**
     * @return array{
     *     pending: int,
     *     sent: int,
     *     delivered: int,
     *     read: int,
     *     failed: int,
     *     replied: int,
     *     total: int,
     *     accepted: int,
     *     reached: int,
     *     delivery_rate: float,
     *     read_rate: float,
     *     reply_rate: float,
     *     fail_rate: float
     * }
     */
    public function getStatsForTemplate(int $templateId, ?string $templateName = null, ?string $language = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select('m.status, COUNT(m.id) as cnt')
            ->where('m.templateId = :templateId')
            ->setParameter('templateId', $templateId);

        $repliedWhere = 'm.templateId = :templateId';
        $repliedParams = ['templateId' => $templateId];

        if ($templateName) {
            $qb->orWhere('m.templateName = :templateName AND m.templateId IS NULL')
                ->setParameter('templateName', $templateName);
            $repliedWhere = '(m.templateId = :templateId OR (m.templateName = :templateName AND m.templateId IS NULL))';
            $repliedParams['templateName'] = $templateName;
            if ($language) {
                $qb->andWhere('(m.templateId = :templateId OR m.templateLanguage IS NULL OR m.templateLanguage = :language)')
                    ->setParameter('language', $language);
                $repliedWhere = '(m.templateId = :templateId OR (m.templateName = :templateName AND m.templateId IS NULL AND (m.templateLanguage IS NULL OR m.templateLanguage = :language)))';
                $repliedParams['language'] = $language;
            }
        }

        $qb->groupBy('m.status');

        return $this->buildStatsFromRows(
            $qb->getQuery()->getArrayResult(),
            $this->countReplied($repliedWhere, $repliedParams)
        );
    }

    /**
     * Stats keyed by template id for list views.
     *
     * @param list<int> $templateIds
     *
     * @return array<int, array<string, float|int>>
     */
    public function getStatsIndexedByTemplateId(array $templateIds): array
    {
        $indexed = [];
        foreach ($templateIds as $id) {
            $indexed[(int) $id] = MessageStatsCalculator::empty();
        }
        if ($templateIds === []) {
            return $indexed;
        }

        $rows = $this->createQueryBuilder('m')
            ->select('m.templateId AS templateId, m.status AS status, COUNT(m.id) AS cnt')
            ->where('m.templateId IN (:ids)')
            ->setParameter('ids', $templateIds)
            ->groupBy('m.templateId, m.status')
            ->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            $id = (int) ($row['templateId'] ?? 0);
            if ($id < 1) {
                continue;
            }
            if (!isset($byId[$id])) {
                $byId[$id] = [
                    'pending' => 0,
                    'sent' => 0,
                    'delivered' => 0,
                    'read' => 0,
                    'failed' => 0,
                    'replied' => 0,
                    'total' => 0,
                ];
            }
            $status = (string) ($row['status'] ?? '');
            $count = (int) ($row['cnt'] ?? 0);
            if (isset($byId[$id][$status])) {
                $byId[$id][$status] = $count;
            }
            $byId[$id]['total'] += $count;
        }

        $repliedRows = $this->createQueryBuilder('m')
            ->select('m.templateId AS templateId, COUNT(m.id) AS cnt')
            ->where('m.templateId IN (:ids)')
            ->andWhere('m.repliedAt IS NOT NULL')
            ->setParameter('ids', $templateIds)
            ->groupBy('m.templateId')
            ->getQuery()
            ->getArrayResult();

        foreach ($repliedRows as $row) {
            $id = (int) ($row['templateId'] ?? 0);
            if (isset($byId[$id])) {
                $byId[$id]['replied'] = (int) ($row['cnt'] ?? 0);
            }
        }

        foreach ($byId as $id => $counts) {
            $indexed[$id] = MessageStatsCalculator::withRates($counts);
        }

        return $indexed;
    }

    /**
     * Conta mensagens por status
     */
    public function countByStatus(string $status): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Busca estatísticas de mensagens
     */
    public function getMessageStats(): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select('m.status, COUNT(m.id) as count')
            ->groupBy('m.status');

        $results = $qb->getQuery()->getResult();
        
        $stats = [
            'pending' => 0,
            'sent' => 0,
            'delivered' => 0,
            'read' => 0,
            'failed' => 0,
        ];

        foreach ($results as $result) {
            $stats[$result['status']] = (int) $result['count'];
        }

        return $stats;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, float|int>
     */
    private function buildStatsFromRows(array $rows, int $replied): array
    {
        $counts = [
            'pending' => 0,
            'sent' => 0,
            'delivered' => 0,
            'read' => 0,
            'failed' => 0,
            'replied' => $replied,
            'total' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            $count = (int) ($row['cnt'] ?? 0);
            if (isset($counts[$status])) {
                $counts[$status] = $count;
            }
            $counts['total'] += $count;
        }

        return MessageStatsCalculator::withRates($counts);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function countReplied(string $where, array $params): int
    {
        $qb = $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where($where)
            ->andWhere('m.repliedAt IS NOT NULL');

        foreach ($params as $key => $value) {
            $qb->setParameter($key, $value);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}