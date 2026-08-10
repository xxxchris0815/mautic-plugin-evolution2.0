<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use MauticPlugin\MauticEvolutionBundle\Exception\EvolutionApiException;
use MauticPlugin\MauticEvolutionBundle\Exception\EvolutionDeliveryException;
use MauticPlugin\MauticEvolutionBundle\Model\MessageModel;
use MauticPlugin\MauticEvolutionBundle\Model\TemplateModel;
use MauticPlugin\MauticEvolutionBundle\Service\EvolutionApiService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Class CampaignSubscriber
 * 
 * Event listener para integração com campanhas do Mautic
 */
class CampaignSubscriber implements EventSubscriberInterface
{
    private MessageModel $messageModel;
    private TemplateModel $templateModel;
    private EvolutionApiService $evolutionApiService;

    public function __construct(
        MessageModel $messageModel,
        TemplateModel $templateModel,
        EvolutionApiService $evolutionApiService
    ) {
        $this->messageModel = $messageModel;
        $this->templateModel = $templateModel;
        $this->evolutionApiService = $evolutionApiService;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD => ['onCampaignBuild', 0],
            'mautic.evolution.send_message' => ['onSendMessage', 0],
            'mautic.evolution.send_template' => ['onSendTemplate', 0],
        ];
    }

    /**
     * Adiciona actions do Evolution API ao campaign builder
     */
    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        // Action para enviar mensagem simples
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

        // Action para enviar template
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

    /**
     * Executa action de envio de mensagem simples
     */
    public function onSendMessage(CampaignExecutionEvent $event): void
    {
        $config = $event->getConfig();
        $lead = $event->getLead();

        try {
            // Obtém configurações da action
            $message = $config['message'] ?? '';
            $phoneField = $config['phone_field'] ?? 'mobile';
            $groupAlias = $config['group_alias'] ?? null;
            $headers = $this->normalizeKeyValueCollection($config['headers'] ?? []);
            $metadata = $this->normalizeKeyValueCollection($config['data'] ?? []);

            if (empty($message)) {
                $event->setResult(false);
                $event->setFailed('Message content is not configured');
                return;
            }

            // groupAlias is optional: empty => Evolution v2 /message/sendText/{instance}
            $result = $this->messageModel->sendMessage(
                $lead,
                $message,
                null,
                !empty($groupAlias) ? (string) $groupAlias : null,
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

    /**
     * Executa action de envio de template
     */
    public function onSendTemplate(CampaignExecutionEvent $event): void
    {
        $config = $event->getConfig();
        $lead = $event->getLead();

        try {
            // Obtém configurações da action
            $templateId = $config['template'] ?? null;
            $phoneField = $config['phone_field'] ?? 'mobile';
            $groupAlias = $config['group_alias'] ?? null;
            $headers = $this->normalizeKeyValueCollection($config['headers'] ?? []);
            $metadata = $this->normalizeKeyValueCollection($config['data'] ?? []);

            if (empty($templateId)) {
                $event->setResult(false);
                $event->setFailed('Template not selected');
                return;
            }

            $template = $this->templateModel->getEntity($templateId);

            if (!$template) {
                $event->setResult(false);
                $event->setFailed('Template not found');
                return;
            }

            $templateContent = $template->getContent();

            $result = $this->messageModel->sendMessage(
                $lead,
                $templateContent,
                $template->getName(),
                !empty($groupAlias) ? (string) $groupAlias : null,
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

        // Support SortableListType transformer that may wrap values under 'list'
        $items = isset($pairs['list']) && is_array($pairs['list']) ? $pairs['list'] : $pairs;

        // If associative array (key_value_pairs => true), map directly
        $hasZeroIndex = array_key_exists(0, $items);
        $hasStringKeys = !empty(array_filter(array_keys($items), fn($k) => is_string($k)));
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

        // Otherwise expect array of arrays with either 'key'/'value' or 'label'/'value'
        foreach ($items as $pair) {
            if (is_array($pair)) {
                $k = isset($pair['key']) ? (string) $pair['key'] : (isset($pair['label']) ? (string) $pair['label'] : '');
                $v = isset($pair['value']) ? (string) $pair['value'] : '';
                $k = trim($k);
                $v = trim($v);
                if ($k !== '' && $v !== '') {
                    $assoc[$k] = $v;
                }
            }
        }

        return $assoc;
    }
}