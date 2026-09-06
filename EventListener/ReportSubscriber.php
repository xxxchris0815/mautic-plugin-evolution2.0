<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\EventListener;

use Mautic\ReportBundle\Event\ReportBuilderEvent;
use Mautic\ReportBundle\Event\ReportGeneratorEvent;
use Mautic\ReportBundle\Event\ReportGraphEvent;
use Mautic\ReportBundle\ReportEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ReportSubscriber implements EventSubscriberInterface
{
    public const CONTEXT = 'evolution.messages';

    public const TEMPLATE_CONTEXT = 'evolution.templates';

    public static function getSubscribedEvents(): array
    {
        return [
            ReportEvents::REPORT_ON_BUILD => ['onReportBuild', 0],
            ReportEvents::REPORT_ON_GENERATE => ['onReportGenerate', 0],
            ReportEvents::REPORT_ON_GRAPH_GENERATE => ['onReportGraphGenerate', 0],
        ];
    }

    public function onReportBuild(ReportBuilderEvent $event): void
    {
        $messageColumns = $this->getMessageColumns();
        $filters = $messageColumns;
        $filters['em.status']['type'] = 'select';
        $filters['em.status']['list'] = [
            'pending' => 'pending',
            'sent' => 'sent',
            'delivered' => 'delivered',
            'read' => 'read',
            'failed' => 'failed',
        ];
        $filters['em.message_type']['type'] = 'select';
        $filters['em.message_type']['list'] = [
            'text' => 'text',
            'template' => 'template',
            'image' => 'image',
            'video' => 'video',
            'document' => 'document',
            'audio' => 'audio',
        ];
        $filters['em.template_source']['type'] = 'select';
        $filters['em.template_source']['list'] = [
            'local' => 'local',
            'evolution' => 'evolution',
        ];

        if ($event->checkContext(self::CONTEXT)) {
            $event->addTable(self::CONTEXT, [
                'display_name' => 'mautic.evolution.report.messages',
                'group' => 'channels',
                'columns' => $messageColumns,
                'filters' => $filters,
            ]);
            $event->addGraph(self::CONTEXT, 'line', 'mautic.evolution.graph.messages.line');
            $event->addGraph(self::CONTEXT, 'pie', 'mautic.evolution.graph.messages.pie');
            $event->addGraph(self::CONTEXT, 'pie', 'mautic.evolution.graph.messages.templates');
            $event->addGraph(self::CONTEXT, 'pie', 'mautic.evolution.graph.messages.types');
        }

        if ($event->checkContext(self::TEMPLATE_CONTEXT)) {
            $event->addTable(self::TEMPLATE_CONTEXT, [
                'display_name' => 'mautic.evolution.report.template_performance',
                'group' => 'channels',
                'columns' => $this->getTemplatePerformanceColumns(),
                'filters' => [
                    'em.template_name' => $filters['em.template_name'],
                    'em.template_language' => $filters['em.template_language'],
                    'em.template_source' => $filters['em.template_source'],
                    'em.message_type' => $filters['em.message_type'],
                    'em.campaign_id' => $filters['em.campaign_id'],
                    'em.date_added' => $messageColumns['em.date_added'],
                ],
            ]);
            $event->addGraph(self::TEMPLATE_CONTEXT, 'pie', 'mautic.evolution.graph.messages.templates');
            $event->addGraph(self::TEMPLATE_CONTEXT, 'pie', 'mautic.evolution.graph.messages.pie');
        }
    }

    public function onReportGenerate(ReportGeneratorEvent $event): void
    {
        if (!$event->checkContext([self::CONTEXT, self::TEMPLATE_CONTEXT])) {
            return;
        }

        $qb = $event->getQueryBuilder();
        $qb->from(MAUTIC_TABLE_PREFIX.'evolution_messages', 'em');
        $event->addLeadLeftJoin($qb, 'em');
        $qb->leftJoin('em', MAUTIC_TABLE_PREFIX.'campaigns', 'cmp', 'cmp.id = em.campaign_id');
        $event->applyDateFilters($qb, 'date_added', 'em');

        if ($event->checkContext(self::TEMPLATE_CONTEXT)) {
            $qb->groupBy('em.template_name, em.template_language, em.template_source, em.message_type');
        }

        $event->setQueryBuilder($qb);
    }

    public function onReportGraphGenerate(ReportGraphEvent $event): void
    {
        if (!$event->checkContext([self::CONTEXT, self::TEMPLATE_CONTEXT])) {
            return;
        }

        $graphs = $event->getRequestedGraphs();
        $qb = clone $event->getQueryBuilder();

        foreach ($graphs as $graph) {
            $options = $event->getOptions($graph);
            $queryBuilder = clone $qb;

            switch ($graph) {
                case 'mautic.evolution.graph.messages.line':
                    if (empty($options['chartQuery'])) {
                        break;
                    }
                    $chartQuery = clone $options['chartQuery'];
                    $chartQuery->applyDateFilters($queryBuilder, 'date_added', 'em');
                    $chartQuery->modifyTimeDataQuery($queryBuilder, 'date_added', 'em');
                    $event->setGraph($graph, [
                        'data' => $chartQuery->loadAndBuildTimeData($queryBuilder),
                        'name' => $graph,
                    ]);
                    break;
                case 'mautic.evolution.graph.messages.pie':
                    $this->setPieGraph($event, $queryBuilder, $graph, 'em.status', 'em.status');
                    break;
                case 'mautic.evolution.graph.messages.templates':
                    $this->setPieGraph(
                        $event,
                        $queryBuilder,
                        $graph,
                        "COALESCE(NULLIF(em.template_name, ''), em.message_type)",
                        'em.template_name, em.message_type'
                    );
                    break;
                case 'mautic.evolution.graph.messages.types':
                    $this->setPieGraph($event, $queryBuilder, $graph, 'em.message_type', 'em.message_type');
                    break;
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getMessageColumns(): array
    {
        return [
            'em.id' => [
                'label' => 'mautic.evolution.report.id',
                'type' => 'int',
                'alias' => 'message_row_id',
            ],
            'em.date_added' => [
                'label' => 'mautic.evolution.report.date_added',
                'type' => 'datetime',
            ],
            'em.phone_number' => [
                'label' => 'mautic.evolution.report.phone',
                'type' => 'string',
            ],
            'em.status' => [
                'label' => 'mautic.evolution.report.status',
                'type' => 'string',
            ],
            'em.template_name' => [
                'label' => 'mautic.evolution.report.template',
                'type' => 'string',
            ],
            'em.template_id' => [
                'label' => 'mautic.evolution.report.template_id',
                'type' => 'int',
            ],
            'em.template_language' => [
                'label' => 'mautic.evolution.report.template_language',
                'type' => 'string',
            ],
            'em.template_source' => [
                'label' => 'mautic.evolution.report.template_source',
                'type' => 'string',
            ],
            'em.message_type' => [
                'label' => 'mautic.evolution.report.message_type',
                'type' => 'string',
            ],
            'em.instance' => [
                'label' => 'mautic.evolution.report.instance',
                'type' => 'string',
            ],
            'em.sent_at' => [
                'label' => 'mautic.evolution.report.sent_at',
                'type' => 'datetime',
            ],
            'em.delivered_at' => [
                'label' => 'mautic.evolution.report.delivered_at',
                'type' => 'datetime',
            ],
            'em.read_at' => [
                'label' => 'mautic.evolution.report.read_at',
                'type' => 'datetime',
            ],
            'em.replied_at' => [
                'label' => 'mautic.evolution.report.replied_at',
                'type' => 'datetime',
            ],
            'em.campaign_id' => [
                'label' => 'mautic.evolution.report.campaign_id',
                'type' => 'int',
                'link' => 'mautic_campaign_action',
            ],
            'cmp.name' => [
                'label' => 'mautic.evolution.report.campaign_name',
                'type' => 'string',
                'alias' => 'campaign_name',
            ],
            'em.campaign_event_id' => [
                'label' => 'mautic.evolution.report.campaign_event_id',
                'type' => 'int',
            ],
            'em.error_message' => [
                'label' => 'mautic.evolution.report.error',
                'type' => 'string',
            ],
            'l.id' => [
                'label' => 'mautic.lead.report.contact_id',
                'type' => 'int',
                'link' => 'mautic_contact_action',
            ],
            'l.firstname' => [
                'label' => 'mautic.core.firstname',
                'type' => 'string',
            ],
            'l.lastname' => [
                'label' => 'mautic.core.lastname',
                'type' => 'string',
            ],
            'l.email' => [
                'label' => 'mautic.core.email',
                'type' => 'email',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getTemplatePerformanceColumns(): array
    {
        return [
            'em.template_name' => [
                'label' => 'mautic.evolution.report.template',
                'type' => 'string',
                'groupByFormula' => 'em.template_name',
            ],
            'em.template_language' => [
                'label' => 'mautic.evolution.report.template_language',
                'type' => 'string',
                'groupByFormula' => 'em.template_language',
            ],
            'em.template_source' => [
                'label' => 'mautic.evolution.report.template_source',
                'type' => 'string',
                'groupByFormula' => 'em.template_source',
            ],
            'em.message_type' => [
                'label' => 'mautic.evolution.report.message_type',
                'type' => 'string',
                'groupByFormula' => 'em.message_type',
            ],
            'total_count' => [
                'alias' => 'total_count',
                'label' => 'mautic.evolution.report.total',
                'type' => 'int',
                'formula' => 'COUNT(em.id)',
            ],
            'sent_count' => [
                'alias' => 'sent_count',
                'label' => 'mautic.evolution.report.sent_count',
                'type' => 'int',
                'formula' => "SUM(CASE WHEN em.status IN ('sent', 'delivered', 'read') THEN 1 ELSE 0 END)",
            ],
            'delivered_count' => [
                'alias' => 'delivered_count',
                'label' => 'mautic.evolution.report.delivered_count',
                'type' => 'int',
                'formula' => "SUM(CASE WHEN em.status IN ('delivered', 'read') THEN 1 ELSE 0 END)",
            ],
            'read_count' => [
                'alias' => 'read_count',
                'label' => 'mautic.evolution.report.read_count',
                'type' => 'int',
                'formula' => "SUM(CASE WHEN em.status = 'read' THEN 1 ELSE 0 END)",
            ],
            'replied_count' => [
                'alias' => 'replied_count',
                'label' => 'mautic.evolution.report.replied_count',
                'type' => 'int',
                'formula' => 'SUM(CASE WHEN em.replied_at IS NOT NULL THEN 1 ELSE 0 END)',
            ],
            'failed_count' => [
                'alias' => 'failed_count',
                'label' => 'mautic.evolution.report.failed_count',
                'type' => 'int',
                'formula' => "SUM(CASE WHEN em.status = 'failed' THEN 1 ELSE 0 END)",
            ],
        ];
    }

    /**
     * @param \Doctrine\DBAL\Query\QueryBuilder $queryBuilder
     */
    private function setPieGraph($event, $queryBuilder, string $graph, string $selectExpr, string $groupBy): void
    {
        $queryBuilder->resetQueryParts(['orderBy', 'groupBy', 'select']);
        $queryBuilder->select($selectExpr.' as label, COUNT(em.id) as value')
            ->groupBy($groupBy);
        $stmt = $queryBuilder->executeQuery();
        $event->setGraph($graph, [
            'data' => $stmt->fetchAllAssociative(),
            'name' => $graph,
        ]);
    }
}
