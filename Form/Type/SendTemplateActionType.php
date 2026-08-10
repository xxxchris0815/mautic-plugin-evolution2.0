<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\SortableListType;
use MauticPlugin\MauticEvolutionBundle\Service\EvolutionApiService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Campaign action form: send WhatsApp Business Cloud templates via Evolution API v2.
 *
 * Template name/language are free-text because Evolution's GET /template/find/{instance}
 * often returns an empty body (not fully implemented / not synced).
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

        // Backward compatible with older configs that stored "name|language"
        $templateName = (string) ($formData['template'] ?? '');
        $language = (string) ($formData['language'] ?? '');
        if ($language === '' && str_contains($templateName, '|')) {
            [$templateName, $language] = array_pad(explode('|', $templateName, 2), 2, '');
            $templateName = trim($templateName);
            $language = trim($language);
        }

        $builder
            ->add('instance', ChoiceType::class, [
                'label' => 'mautic.evolution.campaign.action.instance.cloud',
                'label_attr' => ['class' => 'control-label required'],
                'attr' => [
                    'class' => 'form-control evolution-instance-select',
                    'tooltip' => 'mautic.evolution.campaign.action.instance.cloud.tooltip',
                    'data-cloud-only' => '1',
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
            ->add('template', TextType::class, [
                'label' => 'mautic.evolution.campaign.action.template.name',
                'label_attr' => ['class' => 'control-label required'],
                'attr' => [
                    'class' => 'form-control evolution-template-name',
                    'tooltip' => 'mautic.evolution.campaign.action.template.name.tooltip',
                    'placeholder' => 'vsl_challenge_1_q',
                ],
                'data' => $templateName !== '' ? $templateName : null,
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'mautic.evolution.campaign.action.template.name.notblank',
                    ]),
                    new Assert\Regex([
                        'pattern' => '/^[A-Za-z0-9_]+$/',
                        'message' => 'mautic.evolution.campaign.action.template.name.invalid',
                    ]),
                ],
                'help' => 'mautic.evolution.campaign.action.template.name.help',
            ])
            ->add('language', TextType::class, [
                'label' => 'mautic.evolution.campaign.action.template.language',
                'label_attr' => ['class' => 'control-label required'],
                'attr' => [
                    'class' => 'form-control evolution-template-language',
                    'tooltip' => 'mautic.evolution.campaign.action.template.language.tooltip',
                    'placeholder' => 'de',
                ],
                'data' => $language !== '' ? $language : null,
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'mautic.evolution.campaign.action.template.language.notblank',
                    ]),
                    new Assert\Regex([
                        'pattern' => '/^[A-Za-z]{2}(?:_[A-Za-z]{2})?$/',
                        'message' => 'mautic.evolution.campaign.action.template.language.invalid',
                    ]),
                ],
                'help' => 'mautic.evolution.campaign.action.template.language.help',
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
