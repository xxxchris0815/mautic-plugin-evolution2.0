<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle;

final class EvolutionEvents
{
    public const ON_CAMPAIGN_BATCH_ACTION = 'mautic.evolution.on_campaign_batch_action';

    public const ON_CAMPAIGN_DECISION = 'mautic.evolution.on_campaign_decision';

    public const MESSAGE_STATUS_UPDATED = 'mautic.evolution.message_status_updated';

    public const MESSAGE_RECEIVED = 'mautic.evolution.message_received';
}
