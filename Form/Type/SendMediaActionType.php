<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Form\Type;

use MauticPlugin\MauticEvolutionBundle\Service\EvolutionApiService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class SendMediaActionType extends AbstractType
{
    public function __construct(private EvolutionApiService $evolutionApiService)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $groupsResult = $this->evolutionApiService->getInstanceGroups();
        $groupChoices = [];
        if (!empty($groupsResult['success'])) {
            foreach ($groupsResult['groups'] ?? [] as $group) {
                if (!empty($group['name']) && !empty($group['alias'])) {
                    $groupChoices[$group['name']] = $group['alias'];
                }
            }
        }

        $builder
            ->add('group_alias', ChoiceType::class, [
                'label' => 'mautic.evolution.campaign.action.group.label',
                'label_attr' => ['class' => 'control-label'],
                'attr' => ['class' => 'form-control'],
                'placeholder' => 'mautic.evolution.campaign.action.group.none',
                'choices' => $groupChoices,
                'required' => false,
            ])
            ->add('media_url', TextType::class, [
                'label' => 'mautic.evolution.campaign.action.media_url',
                'label_attr' => ['class' => 'control-label required'],
                'attr' => [
                    'class' => 'form-control',
                    'tooltip' => 'mautic.evolution.campaign.action.media_url.tooltip',
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'mautic.evolution.campaign.action.media_url.notblank',
                    ]),
                ],
            ])
            ->add('media_type', ChoiceType::class, [
                'label' => 'mautic.evolution.campaign.action.media_type',
                'label_attr' => ['class' => 'control-label'],
                'attr' => ['class' => 'form-control'],
                'choices' => [
                    'mautic.evolution.template.type.media.image' => 'image',
                    'mautic.evolution.template.type.media.video' => 'video',
                    'mautic.evolution.template.type.media.document' => 'document',
                    'mautic.evolution.template.type.media.audio' => 'audio',
                ],
                'data' => 'image',
            ])
            ->add('caption', TextareaType::class, [
                'label' => 'mautic.evolution.campaign.action.media_caption',
                'label_attr' => ['class' => 'control-label'],
                'attr' => ['class' => 'form-control', 'rows' => 4],
                'required' => false,
            ])
            ->add('phone_field', ChoiceType::class, [
                'label' => 'mautic.evolution.campaign.action.phone_field',
                'label_attr' => ['class' => 'control-label'],
                'attr' => ['class' => 'form-control'],
                'choices' => [
                    'mautic.evolution.campaign.action.phone_field.mobile' => 'mobile',
                    'mautic.evolution.campaign.action.phone_field.phone' => 'phone',
                ],
                'data' => 'mobile',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'messages',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'evolution_send_media_action';
    }
}
