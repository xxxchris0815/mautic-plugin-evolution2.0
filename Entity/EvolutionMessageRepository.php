<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

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
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Alias for findByEvolutionMessageId
     */
    public function findByMessageId(string $messageId): ?EvolutionMessage
    {
        return $this->findByEvolutionMessageId($messageId);
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
}