<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use MauticPlugin\MauticEvolutionBundle\Exception\EvolutionApiException;
use MauticPlugin\MauticEvolutionBundle\Exception\EvolutionDeliveryException;
use MauticPlugin\MauticEvolutionBundle\Model\MessageModel;
use MauticPlugin\MauticEvolutionBundle\Service\EvolutionApiService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Campaign builder integration for Evolution API v2.
 */
class CampaignSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MessageModel $messageModel,
        private EvolutionApiService $evolutionApiService
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD => ['onCampaignBuild', 0],
            'mautic.evolution.send_message' => ['onSendMessage', 0],
            'mautic.evolution.send_template' => ['onSendTemplate', 0],
        ];
    }

    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        $event->addAction(
            'evolution.send_message',
            [
                'label' => 'mautic.evolution.campaign.action.send_message',
                'description' => 'mautic.evolution.campaign.action.send_message.tooltip',
                'eventName' => 'mautic.evolution.send_message',
                'formType' => 'MauticPlugin\MauticEvolutionBundle\Form\Type\SendMessageActionType',
                'formTheme' => '@MauticEvolution/FormTheme/SendMessageAction/theme.html.twig',
                'channel' => 'whatsapp',
                'channelIdField' => 'phone',
            ]
        );

        $event->addAction(
            'evolution.send_template',
            [
                'label' => 'mautic.evolution.campaign.action.send_template',
                'description' => 'mautic.evolution.campaign.action.send_template.tooltip',
                'eventName' => 'mautic.evolution.send_template',
                'formType' => 'MauticPlugin\MauticEvolutionBundle\Form\Type\SendTemplateActionType',
                'formTheme' => '@MauticEvolution/FormTheme/SendTemplateAction/theme.html.twig',
                'channel' => 'whatsapp',
                'channelIdField' => 'phone',
            ]
        );
    }

    public function onSendMessage(CampaignExecutionEvent $event): void
    {
        $config = $event->getConfig();
        $lead = $event->getLead();

        try {
            $message = $config['message'] ?? '';
            $phoneField = $config['phone_field'] ?? 'mobile';
            $instance = $config['instance'] ?? null;
            $headers = $this->normalizeKeyValueCollection($config['headers'] ?? []);
            $metadata = $this->normalizeKeyValueCollection($config['data'] ?? []);

            if (empty($message)) {
                $event->setResult(false);
                $event->setFailed('Message content is not configured');

                return;
            }

            if (empty($instance)) {
                $event->setResult(false);
                $event->setFailed('Instance is required');

                return;
            }

            $result = $this->messageModel->sendMessage(
                $lead,
                $message,
                null,
                (string) $instance,
                $phoneField,
                $headers,
                $metadata
            );

            if ($result === null) {
                $event->setResult(false);
                $event->setFailed('Contact has no valid phone number');

                return;
            }

            if ($result->getStatus() !== 'failed') {
                $event->setResult(true);
                $event->setChannel('whatsapp', $lead->getId());
            } else {
                $event->setResult(false);
                $event->setFailed($result->getErrorMessage() ?? 'Failed to send WhatsApp message');
            }
        } catch (EvolutionDeliveryException|EvolutionApiException $e) {
            $event->setResult(false);
            $event->setFailed($e->getMessage());
        } catch (\Exception $e) {
            $event->setResult(false);
            $event->setFailed('Error sending message: ' . $e->getMessage());
        }
    }

    public function onSendTemplate(CampaignExecutionEvent $event): void
    {
        $config = $event->getConfig();
        $lead = $event->getLead();

        try {
            $templateName = trim((string) ($config['template'] ?? ''));
            $language = trim((string) ($config['language'] ?? ''));
            $phoneField = $config['phone_field'] ?? 'mobile';
            $instance = $config['instance'] ?? null;
            $headers = $this->normalizeKeyValueCollection($config['headers'] ?? []);
            $variables = $this->normalizeKeyValueCollection($config['variables'] ?? []);

            // Backward compatible with older configs that stored "name|language"
            if ($language === '' && str_contains($templateName, '|')) {
                [$templateName, $language] = array_pad(explode('|', $templateName, 2), 2, '');
                $templateName = trim($templateName);
                $language = trim($language);
            }

            if ($templateName === '') {
                $event->setResult(false);
                $event->setFailed('Template name is required');

                return;
            }

            if ($language === '') {
                $event->setResult(false);
                $event->setFailed('Template language is required (e.g. de, en_US)');

                return;
            }

            if (empty($instance)) {
                $event->setResult(false);
                $event->setFailed('Instance is required');

                return;
            }

            if (!$this->evolutionApiService->isCloudTemplateInstance((string) $instance)) {
                $event->setResult(false);
                $event->setFailed(
                    'Send Template requires a WhatsApp Cloud/Business instance (WHATSAPP-BUSINESS). Baileys instances are not supported.'
                );

                return;
            }

            $result = $this->messageModel->sendWhatsAppTemplate(
                $lead,
                $templateName,
                $language,
                $variables,
                (string) $instance,
                $phoneField,
                $headers
            );

            if ($result === null) {
                $event->setResult(false);
                $event->setFailed('Contact has no valid phone number');

                return;
            }

            if ($result->getStatus() !== 'failed') {
                $event->setResult(true);
                $event->setChannel('whatsapp', $lead->getId());
            } else {
                $event->setResult(false);
                $event->setFailed($result->getErrorMessage() ?? 'Failed to send WhatsApp template');
            }
        } catch (EvolutionDeliveryException|EvolutionApiException $e) {
            $event->setResult(false);
            $event->setFailed($e->getMessage());
        } catch (\Exception $e) {
            $event->setResult(false);
            $event->setFailed('Error sending template: ' . $e->getMessage());
        }
    }

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
