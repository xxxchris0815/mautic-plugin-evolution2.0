<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\SortableListType;
use MauticPlugin\MauticEvolutionBundle\Service\EvolutionApiService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Campaign action form: send plain WhatsApp text via Evolution API v2.
 */
class SendMessageActionType extends AbstractType
{
    public function __construct(private EvolutionApiService $evolutionApiService)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $formData = is_array($options['data'] ?? null) ? $options['data'] : [];
        $instanceChoices = $this->evolutionApiService->getInstanceChoices();
        $defaultInstance = (string) ($formData['instance'] ?? '');
        if ($defaultInstance !== '' && !in_array($defaultInstance, $instanceChoices, true)) {
            $instanceChoices[$defaultInstance] = $defaultInstance;
        }
        if ($defaultInstance === '' && $instanceChoices !== []) {
            $defaultInstance = (string) reset($instanceChoices);
        }

        $builder
            ->add('instance', ChoiceType::class, [
                'label' => 'mautic.evolution.campaign.action.instance',
                'label_attr' => ['class' => 'control-label required'],
                'attr' => [
                    'class' => 'form-control evolution-instance-select',
                    'tooltip' => 'mautic.evolution.campaign.action.instance.tooltip',
                ],
                'choices' => $instanceChoices,
                'data' => $defaultInstance !== '' ? $defaultInstance : null,
                'placeholder' => 'mautic.evolution.campaign.action.instance.placeholder',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'mautic.evolution.campaign.action.instance.notblank',
                    ]),
                ],
                'help' => 'mautic.evolution.campaign.action.instance.help',
            ])
            ->add('message', TextareaType::class, [
                'label' => 'mautic.evolution.campaign.action.message.content',
                'label_attr' => ['class' => 'control-label required'],
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 6,
                    'tooltip' => 'mautic.evolution.campaign.action.message.content.tooltip',
                    'placeholder' => 'mautic.evolution.campaign.action.message.content.placeholder',
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'mautic.evolution.campaign.action.message.content.notblank',
                    ]),
                    new Assert\Length([
                        'max' => 4096,
                        'maxMessage' => 'mautic.evolution.campaign.action.message.content.maxlength',
                    ]),
                ],
                'help' => 'mautic.evolution.campaign.action.message.content.help',
            ])
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
            )
            ->add(
                'data',
                SortableListType::class,
                [
                    'label' => 'mautic.evolution.campaign.action.data',
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
        return 'evolution_send_message_action';
    }
}
