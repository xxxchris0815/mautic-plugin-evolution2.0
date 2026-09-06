<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\EventListener;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Mautic\PluginBundle\Event\PluginInstallEvent;
use Mautic\PluginBundle\PluginEvents;
use MauticPlugin\MauticEvolutionBundle\Entity\EvolutionMessage;
use MauticPlugin\MauticEvolutionBundle\Entity\EvolutionTemplate;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PluginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Connection $db,
        private LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        $events = [
            PluginEvents::ON_PLUGIN_INSTALL => ['onInstall', 0],
        ];

        if (defined(PluginEvents::class.'::ON_PLUGIN_UPDATE')) {
            $events[PluginEvents::ON_PLUGIN_UPDATE] = ['onUpdate', 0];
        }

        return $events;
    }

    public function onInstall(PluginInstallEvent $event): void
    {
        if (!$event->checkContext('MauticEvolution') && !$event->checkContext('MauticEvolutionBundle')) {
            return;
        }

        $this->updateSchema();
    }

    public function onUpdate(object $event): void
    {
        if (method_exists($event, 'checkContext')
            && !$event->checkContext('MauticEvolution')
            && !$event->checkContext('MauticEvolutionBundle')
        ) {
            return;
        }

        $this->updateSchema();
    }

    private function updateSchema(): void
    {
        try {
            $metadata = [
                $this->em->getClassMetadata(EvolutionMessage::class),
                $this->em->getClassMetadata(EvolutionTemplate::class),
            ];
            $schemaTool = new SchemaTool($this->em);
            $schemaTool->updateSchema($metadata, true);
        } catch (\Throwable $e) {
            $this->logger->error('Evolution plugin schema update failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
