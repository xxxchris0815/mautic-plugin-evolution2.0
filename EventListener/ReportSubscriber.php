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
        if (!$event->checkContext(self::CONTEXT)) {
            return;
        }

        $columns = [
            'em.id' => [
                'label' => 'mautic.evolution.report.id',
                'type' => 'int',
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
            'em.message_type' => [
                'label' => 'mautic.evolution.report.message_type',
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
            'em.campaign_id' => [
                'label' => 'mautic.evolution.report.campaign_id',
                'type' => 'int',
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

        $event->addTable(self::CONTEXT, [
            'display_name' => 'mautic.evolution.report.messages',
            'group' => 'contacts',
            'columns' => $columns,
        ]);

        $event->addGraph(self::CONTEXT, 'line', 'mautic.evolution.graph.messages.line');
        $event->addGraph(self::CONTEXT, 'pie', 'mautic.evolution.graph.messages.pie');
    }

    public function onReportGenerate(ReportGeneratorEvent $event): void
    {
        if (!$event->checkContext(self::CONTEXT)) {
            return;
        }

        $qb = $event->getQueryBuilder();
        $qb->from(MAUTIC_TABLE_PREFIX.'evolution_messages', 'em');
        $event->addLeadLeftJoin($qb, 'em');
        $event->applyDateFilters($qb, 'date_added', 'em');
        $event->setQueryBuilder($qb);
    }

    public function onReportGraphGenerate(ReportGraphEvent $event): void
    {
        if (!$event->checkContext(self::CONTEXT)) {
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
                    $queryBuilder->resetQueryParts(['orderBy', 'groupBy', 'select']);
                    $queryBuilder->select('em.status as label, COUNT(em.id) as value')
                        ->groupBy('em.status');
                    $stmt = $queryBuilder->executeQuery();
                    $event->setGraph($graph, [
                        'data' => $stmt->fetchAllAssociative(),
                        'name' => $graph,
                    ]);
                    break;
            }
        }
    }
}
