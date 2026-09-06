<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\EventListener;

use Mautic\ChannelBundle\ChannelEvents;
use Mautic\ChannelBundle\Event\ChannelEvent;
use Mautic\ChannelBundle\Model\MessageModel as ChannelMessageModel;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\ReportBundle\Model\ReportModel;
use MauticPlugin\MauticEvolutionBundle\Entity\EvolutionMessage;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ChannelSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ChannelEvents::ADD_CHANNEL => ['onAddChannel', 80],
        ];
    }

    public function onAddChannel(ChannelEvent $event): void
    {
        $event->addChannel('whatsapp', [
            ChannelMessageModel::CHANNEL_FEATURE => [
                'campaignAction' => [
                    'evolution.send_message',
                    'evolution.send_template',
                    'evolution.send_media',
                ],
                'campaignDecisionsSupported' => [
                    'evolution.delivered',
                    'evolution.read',
                    'evolution.replied',
                ],
                'repository' => EvolutionMessage::class,
            ],
            LeadModel::CHANNEL_FEATURE => [],
            ReportModel::CHANNEL_FEATURE => [
                'table' => 'evolution_messages',
            ],
        ]);
    }
}
