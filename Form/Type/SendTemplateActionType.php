<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\SortableListType;
use MauticPlugin\MauticEvolutionBundle\Service\EvolutionApiService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Campaign action form: send WhatsApp Business Cloud templates via Evolution API v2.
 */
class SendTemplateActionType extends AbstractType
{
    public function __construct(private EvolutionApiService $evolutionApiService)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $formData = is_array($options['data'] ?? null) ? $options['data'] : [];
        // Templates require WhatsApp Cloud API instances only (no Baileys)
        $instanceChoices = $this->evolutionApiService->getInstanceChoices(true);
        $configured = $this->evolutionApiService->getConfiguredInstance();
        $defaultInstance = (string) ($formData['instance'] ?? '');

        if ($defaultInstance !== '' && !in_array($defaultInstance, $instanceChoices, true)) {
            // Keep previously saved value only if it is still a Cloud instance
            if ($this->evolutionApiService->isCloudTemplateInstance($defaultInstance)) {
                $instanceChoices[$defaultInstance] = $defaultInstance;
            } else {
                $defaultInstance = '';
            }
        }

        if ($defaultInstance === '') {
            if ($configured !== '' && in_array($configured, $instanceChoices, true)) {
                $defaultInstance = $configured;
            } elseif ($instanceChoices !== []) {
                $defaultInstance = (string) reset($instanceChoices);
            }
        }

        $templatesResult = ($defaultInstance !== '' && $this->evolutionApiService->isCloudTemplateInstance($defaultInstance))
            ? $this->evolutionApiService->findTemplates($defaultInstance, true)
            : ['success' => true, 'templates' => [], 'choices' => []];
        $templateChoices = $templatesResult['choices'] ?? [];
        $existingTemplate = (string) ($formData['template'] ?? '');
        if ($existingTemplate !== '' && !in_array($existingTemplate, $templateChoices, true)) {
            $templateChoices[$existingTemplate] = $existingTemplate;
        }

        $catalog = [];
        foreach ($templatesResult['templates'] ?? [] as $template) {
            $name = (string) ($template['name'] ?? '');
            $language = (string) ($template['language'] ?? '');
            if ($name === '' || $language === '') {
                continue;
            }
            $key = $name . '|' . $language;
            $catalog[$key] = [
                'name' => $name,
                'language' => $language,
                'status' => $template['status'] ?? null,
                'category' => $template['category'] ?? null,
                'variables' => $this->evolutionApiService->getTemplateHelper()->extractVariables($template),
            ];
        }

        $builder
            ->add('instance', ChoiceType::class, [
                'label' => 'mautic.evolution.campaign.action.instance.cloud',
                'label_attr' => ['class' => 'control-label required'],
                'attr' => [
                    'class' => 'form-control evolution-instance-select',
                    'tooltip' => 'mautic.evolution.campaign.action.instance.cloud.tooltip',
                    'data-cloud-only' => '1',
                    'data-templates-url' => '/s/evolution/ajax/templates',
                ],
                'choices' => $instanceChoices,
                'data' => $defaultInstance !== '' ? $defaultInstance : null,
                'placeholder' => 'mautic.evolution.campaign.action.instance.cloud.placeholder',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'mautic.evolution.campaign.action.instance.cloud.notblank',
                    ]),
                ],
                'help' => 'mautic.evolution.campaign.action.instance.cloud.help',
            ])
            ->add('template', ChoiceType::class, [
                'label' => 'mautic.evolution.campaign.action.template.select',
                'label_attr' => ['class' => 'control-label required'],
                'attr' => [
                    'class' => 'form-control evolution-template-select',
                    'tooltip' => 'mautic.evolution.campaign.action.template.select.tooltip',
                    'data-template-catalog' => json_encode($catalog, JSON_UNESCAPED_UNICODE) ?: '{}',
                    'data-templates-error' => !empty($templatesResult['success']) ? '0' : '1',
                ],
                'choices' => $templateChoices,
                'placeholder' => 'mautic.evolution.campaign.action.template.select.placeholder',
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'mautic.evolution.campaign.action.template.select.notblank',
                    ]),
                ],
                'help' => 'mautic.evolution.campaign.action.template.select.help',
            ])
            ->add(
                'variables',
                SortableListType::class,
                [
                    'label' => 'mautic.evolution.campaign.action.template.variables',
                    'required' => false,
                    'option_required' => false,
                    'with_labels' => true,
                    'key_value_pairs' => true,
                    'attr' => [
                        'class' => 'evolution-template-variables',
                    ],
                ]
            )
            ->add('phone_field', ChoiceType::class, [
                'label' => 'mautic.evolution.campaign.action.phone_field',
                'label_attr' => ['class' => 'control-label'],
                'attr' => [
                    'class' => 'form-control',
                    'tooltip' => 'mautic.evolution.campaign.action.phone_field.tooltip',
                ],
                'choices' => [
                    'mautic.evolution.campaign.action.phone_field.mobile' => 'mobile',
                    'mautic.evolution.campaign.action.phone_field.phone' => 'phone',
                ],
                'data' => 'mobile',
                'required' => false,
            ])
            ->add('template_catalog_json', HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'data' => json_encode($catalog, JSON_UNESCAPED_UNICODE) ?: '{}',
                'attr' => [
                    'class' => 'evolution-template-catalog-json',
                ],
            ])
            ->add(
                'headers',
                SortableListType::class,
                [
                    'label' => 'mautic.evolution.campaign.action.headers',
                    'required' => false,
                    'option_required' => false,
                    'with_labels' => true,
                    'key_value_pairs' => true,
                ]
            );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'messages',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'evolution_send_template_action';
    }
}
