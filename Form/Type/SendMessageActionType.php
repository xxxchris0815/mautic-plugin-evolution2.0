<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Form\Type;

use MauticPlugin\MauticEvolutionBundle\Service\EvolutionApiService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Mautic\CoreBundle\Form\Type\SortableListType;

/**
 * Class SendMessageActionType
 * 
 * Formulário para action de envio de mensagem simples
 */
class SendMessageActionType extends AbstractType
{
    private EvolutionApiService $evolutionApiService;

    public function __construct(EvolutionApiService $evolutionApiService)
    {
        $this->evolutionApiService = $evolutionApiService;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Carrega grupos habilitados da Evolution API
        $groupsResult = $this->evolutionApiService->getInstanceGroups();
        $groupsError = !$groupsResult['success'];
        $groupChoices = [];

        if ($groupsResult['success'] && !empty($groupsResult['groups'])) {
            foreach ($groupsResult['groups'] as $group) {
                // Exibir 'name' e enviar 'alias'
                if (!empty($group['name']) && !empty($group['alias'])) {
                    $groupChoices[$group['name']] = $group['alias'];
                }
            }
        }

        $builder
            ->add('group_alias', ChoiceType::class, [
                'label' => 'mautic.evolution.campaign.action.group.label',
                'label_attr' => ['class' => 'control-label'],
                'attr' => [
                    'class' => 'form-control',
                    'tooltip' => 'mautic.evolution.campaign.action.group.tooltip',
                    'data-groups-error' => $groupsError ? '1' : '0',
                ],
                'placeholder' => 'mautic.evolution.campaign.action.group.none',
                'choices' => $groupChoices,
                'required' => false,
                'help' => 'mautic.evolution.campaign.action.group.help',
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

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'messages',
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix(): string
    {
        return 'evolution_send_message_action';
    }
}