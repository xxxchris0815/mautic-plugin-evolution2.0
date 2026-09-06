<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\DecisionEvent;
use Mautic\CampaignBundle\Event\EventPreview;
use Mautic\CampaignBundle\Event\PendingEvent;
use MauticPlugin\MauticEvolutionBundle\Entity\EvolutionMessage;
use MauticPlugin\MauticEvolutionBundle\EvolutionEvents;
use MauticPlugin\MauticEvolutionBundle\Form\Type\SendMediaActionType;
use MauticPlugin\MauticEvolutionBundle\Form\Type\SendMessageActionType;
use MauticPlugin\MauticEvolutionBundle\Form\Type\SendTemplateActionType;
use MauticPlugin\MauticEvolutionBundle\Helper\TemplatePayloadBuilder;
use MauticPlugin\MauticEvolutionBundle\Helper\TokenHelper;
use MauticPlugin\MauticEvolutionBundle\Model\MessageModel;
use MauticPlugin\MauticEvolutionBundle\Model\TemplateModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class CampaignSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MessageModel $messageModel,
        private TemplateModel $templateModel,
        private TranslatorInterface $translator
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        $events = [
            CampaignEvents::CAMPAIGN_ON_BUILD => ['onCampaignBuild', 0],
            EvolutionEvents::ON_CAMPAIGN_BATCH_ACTION => ['onCampaignBatchAction', 0],
            EvolutionEvents::ON_CAMPAIGN_DECISION => ['onCampaignDecision', 0],
        ];

        if (class_exists(EventPreview::class)) {
            $events[EventPreview::class] = ['onEventPreviewRequest', 0];
        }

        return $events;
    }

    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        $event->addAction('evolution.send_message', [
            'label' => 'mautic.evolution.campaign.action.send_message',
            'description' => 'mautic.evolution.campaign.action.send_message.tooltip',
            'batchEventName' => EvolutionEvents::ON_CAMPAIGN_BATCH_ACTION,
            'formType' => SendMessageActionType::class,
            'formTheme' => '@MauticEvolution/FormTheme/SendMessageAction/theme.html.twig',
            'channel' => 'whatsapp',
            'channelIdField' => 'phone_field',
        ]);

        $event->addAction('evolution.send_template', [
            'label' => 'mautic.evolution.campaign.action.send_template',
            'description' => 'mautic.evolution.campaign.action.send_template.tooltip',
            'batchEventName' => EvolutionEvents::ON_CAMPAIGN_BATCH_ACTION,
            'formType' => SendTemplateActionType::class,
            'formTheme' => '@MauticEvolution/FormTheme/SendTemplateAction/theme.html.twig',
            'channel' => 'whatsapp',
            'channelIdField' => 'phone_field',
        ]);

        $event->addAction('evolution.send_media', [
            'label' => 'mautic.evolution.campaign.action.send_media',
            'description' => 'mautic.evolution.campaign.action.send_media.tooltip',
            'batchEventName' => EvolutionEvents::ON_CAMPAIGN_BATCH_ACTION,
            'formType' => SendMediaActionType::class,
            'formTheme' => '@MauticEvolution/FormTheme/SendMediaAction/theme.html.twig',
            'channel' => 'whatsapp',
            'channelIdField' => 'phone_field',
        ]);

        $sourceRestriction = [
            'source' => [
                'action' => [
                    'evolution.send_message',
                    'evolution.send_template',
                    'evolution.send_media',
                ],
            ],
        ];

        $event->addDecision('evolution.delivered', [
            'label' => 'mautic.evolution.campaign.decision.delivered',
            'description' => 'mautic.evolution.campaign.decision.delivered.tooltip',
            'eventName' => EvolutionEvents::ON_CAMPAIGN_DECISION,
            'connectionRestrictions' => $sourceRestriction,
        ]);

        $event->addDecision('evolution.read', [
            'label' => 'mautic.evolution.campaign.decision.read',
            'description' => 'mautic.evolution.campaign.decision.read.tooltip',
            'eventName' => EvolutionEvents::ON_CAMPAIGN_DECISION,
            'connectionRestrictions' => $sourceRestriction,
        ]);

        $event->addDecision('evolution.replied', [
            'label' => 'mautic.evolution.campaign.decision.replied',
            'description' => 'mautic.evolution.campaign.decision.replied.tooltip',
            'eventName' => EvolutionEvents::ON_CAMPAIGN_DECISION,
            'connectionRestrictions' => $sourceRestriction,
        ]);
    }

    public function onCampaignBatchAction(PendingEvent $event): void
    {
        if ($event->checkContext('evolution.send_message')) {
            $this->sendMessages($event);
            return;
        }
        if ($event->checkContext('evolution.send_template')) {
            $this->sendTemplates($event);
            return;
        }
        if ($event->checkContext('evolution.send_media')) {
            $this->sendMedia($event);
        }
    }

    public function onCampaignDecision(DecisionEvent $event): void
    {
        $passthrough = method_exists($event, 'getPassthrough') ? $event->getPassthrough() : $event->getEventDetails();
        if (!$passthrough instanceof EvolutionMessage) {
            return;
        }

        if (method_exists($event, 'setChannel')) {
            $event->setChannel('whatsapp', $passthrough->getId());
        }
        $event->setAsApplicable();
    }

    public function onEventPreviewRequest(EventPreview $eventPreview): void
    {
        if (
            !$eventPreview->isType('evolution.send_message')
            && !$eventPreview->isType('evolution.send_template')
            && !$eventPreview->isType('evolution.send_media')
        ) {
            return;
        }

        $eventId = $eventPreview->event->getId();
        if (!$eventId) {
            return;
        }

        $stats = $this->messageModel->getCampaignEventStats((int) $eventId);
        $sent = $stats['sent'] + $stats['delivered'] + $stats['read'];
        $delivered = $stats['delivered'] + $stats['read'];
        $read = $stats['read'];
        $failed = $stats['failed'];

        $eventPreview->addEventStat('sent_count', $sent);
        $eventPreview->addEventStat('delivered_count', $delivered);
        $eventPreview->addEventStat('read_count', $read);
        $eventPreview->addEventStat('failed_count', $failed);
        $eventPreview->addEventStat('delivery_rate', $sent > 0 ? round(($delivered / $sent) * 100, 2).'%' : '0%');
        $eventPreview->addEventStat('read_rate', $sent > 0 ? round(($read / $sent) * 100, 2).'%' : '0%');
    }

    private function sendMessages(PendingEvent $event): void
    {
        $config = $event->getEvent()->getProperties();
        $message = trim((string) ($config['message'] ?? ''));
        if ($message === '') {
            $event->passAllWithError($this->translator->trans('mautic.evolution.campaign.action.message.content.notblank'));

            return;
        }

        $event->setChannel('whatsapp');
        foreach ($event->getPending() as $log) {
            $lead = $log->getLead();
            $result = $this->messageModel->sendMessage(
                $lead,
                $message,
                null,
                $this->optionalGroupAlias($config),
                (string) ($config['phone_field'] ?? 'mobile'),
                $this->normalizeKeyValueCollection($config['headers'] ?? []),
                $this->normalizeKeyValueCollection($config['data'] ?? []),
                $this->campaignContext($event, $log)
            );

            $this->settleLog($event, $log, $result);
        }
    }

    private function sendTemplates(PendingEvent $event): void
    {
        $config = $event->getEvent()->getProperties();
        $templateId = $config['template'] ?? null;
        if (empty($templateId)) {
            $event->passAllWithError($this->translator->trans('mautic.evolution.campaign.action.template.select.notblank'));

            return;
        }

        $template = $this->templateModel->getEntity($templateId);
        if (!$template) {
            $event->passAllWithError($this->translator->trans('mautic.evolution.message.template_not_found'));

            return;
        }

        $event->setChannel('whatsapp', $template->getId());
        $parameterMap = $this->normalizeKeyValueCollection($config['body_parameters'] ?? []);

        foreach ($event->getPending() as $log) {
            $lead = $log->getLead();
            $context = $this->campaignContext($event, $log);

            if ($template->isEvolutionTemplate()) {
                $values = $this->resolveTemplateParameters($template, $lead, $parameterMap);
                $components = TemplatePayloadBuilder::buildSendComponents($template->getComponents() ?? [], $values);
                $result = $this->messageModel->sendOfficialTemplate(
                    $lead,
                    $template,
                    $components,
                    (string) ($config['phone_field'] ?? 'mobile'),
                    $this->optionalGroupAlias($config),
                    $this->normalizeKeyValueCollection($config['headers'] ?? []),
                    $this->normalizeKeyValueCollection($config['data'] ?? []),
                    $context
                );
            } else {
                $content = $template->getContent() ?? '';
                $result = $this->messageModel->sendMessage(
                    $lead,
                    $content,
                    $template->getName(),
                    $this->optionalGroupAlias($config),
                    (string) ($config['phone_field'] ?? 'mobile'),
                    $this->normalizeKeyValueCollection($config['headers'] ?? []),
                    $this->normalizeKeyValueCollection($config['data'] ?? []),
                    $context
                );
            }

            $this->settleLog($event, $log, $result);
        }
    }

    private function sendMedia(PendingEvent $event): void
    {
        $config = $event->getEvent()->getProperties();
        $mediaUrl = trim((string) ($config['media_url'] ?? ''));
        if ($mediaUrl === '') {
            $event->passAllWithError($this->translator->trans('mautic.evolution.campaign.action.media_url.notblank'));

            return;
        }

        $event->setChannel('whatsapp');
        foreach ($event->getPending() as $log) {
            $lead = $log->getLead();
            $caption = TokenHelper::replaceForLead((string) ($config['caption'] ?? ''), $lead);
            $url = TokenHelper::replaceForLead($mediaUrl, $lead);
            $result = $this->messageModel->sendMedia(
                $lead,
                $url,
                $caption,
                (string) ($config['media_type'] ?? 'image'),
                (string) ($config['phone_field'] ?? 'mobile'),
                $this->campaignContext($event, $log)
            );
            $this->settleLog($event, $log, $result);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function optionalGroupAlias(array $config): ?string
    {
        $alias = trim((string) ($config['group_alias'] ?? ''));

        return $alias !== '' ? $alias : null;
    }

    /**
     * @return array{campaignId: ?int, campaignEventId: ?int, campaignEventLogId: ?int}
     */
    private function campaignContext(PendingEvent $event, $log): array
    {
        return [
            'campaignId' => $event->getEvent()->getCampaign()?->getId(),
            'campaignEventId' => $event->getEvent()->getId(),
            'campaignEventLogId' => $log->getId(),
        ];
    }

    private function settleLog(PendingEvent $event, $log, ?EvolutionMessage $result): void
    {
        if ($result && in_array($result->getStatus(), ['sent', 'pending', 'delivered', 'read'], true) && $result->getMessageId()) {
            $log->appendToMetadata([
                'whatsapp_status' => $result->getStatus(),
                'whatsapp_message_id' => $result->getMessageId(),
                'whatsapp_phone' => $result->getPhoneNumber(),
            ]);
            $event->pass($log);

            return;
        }

        if ($result && $result->getStatus() === 'sent') {
            $log->appendToMetadata([
                'whatsapp_status' => $result->getStatus(),
                'whatsapp_message_id' => $result->getMessageId(),
            ]);
            $event->pass($log);

            return;
        }

        $reason = $result?->getErrorMessage() ?: $this->translator->trans('mautic.evolution.message.failed');
        $event->fail($log, $reason);
    }

    /**
     * @param array<string, mixed> $parameterMap
     *
     * @return array<string, string>
     */
    private function resolveTemplateParameters($template, $lead, array $parameterMap): array
    {
        $values = [];
        $fields = $template->getParameterFields() ?? [];
        $merged = $parameterMap + (is_array($fields) ? $fields : []);

        foreach ($merged as $key => $token) {
            $values[(string) $key] = is_string($token)
                ? TokenHelper::replaceForLead($token, $lead)
                : (string) $token;
        }

        $profile = $lead->getProfileFields();
        $placeholders = TemplatePayloadBuilder::extractBodyPlaceholders($template->getComponents() ?? []);
        foreach ($placeholders as $index) {
            if (!isset($values[$index])) {
                $alias = $merged[$index] ?? null;
                if (is_string($alias) && isset($profile[$alias])) {
                    $values[$index] = (string) $profile[$alias];
                }
            }
        }

        return $values;
    }

    /**
     * @param array<mixed> $pairs
     *
     * @return array<string, string>
     */
    private function normalizeKeyValueCollection(array $pairs): array
    {
        $assoc = [];
        $items = isset($pairs['list']) && is_array($pairs['list']) ? $pairs['list'] : $pairs;

        $hasZeroIndex = array_key_exists(0, $items);
        $hasStringKeys = !empty(array_filter(array_keys($items), static fn ($k) => is_string($k)));
        if (!$hasZeroIndex && $hasStringKeys) {
            foreach ($items as $key => $value) {
                $k = trim((string) $key);
                $v = trim((string) ($value ?? ''));
                if ($k !== '' && $v !== '') {
                    $assoc[$k] = $v;
                }
            }

            return $assoc;
        }

        foreach ($items as $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $k = isset($pair['key']) ? (string) $pair['key'] : (isset($pair['label']) ? (string) $pair['label'] : '');
            $v = isset($pair['value']) ? (string) $pair['value'] : '';
            $k = trim($k);
            $v = trim($v);
            if ($k !== '' && $v !== '') {
                $assoc[$k] = $v;
            }
        }

        return $assoc;
    }
}
