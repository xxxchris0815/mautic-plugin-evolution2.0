<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\SortableListType;
use MauticPlugin\MauticEvolutionBundle\Model\TemplateModel;
use MauticPlugin\MauticEvolutionBundle\Service\EvolutionApiService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class SendTemplateActionType
 * 
 * Formulário para action de envio de template
 */
class SendTemplateActionType extends AbstractType
{
    public function __construct(
        private TemplateModel $templateModel,
        private EvolutionApiService $evolutionApiService
    ) {
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
                'placeholder' => 'mautic.evolution.campaign.action.group.placeholder',
                'choices' => $groupChoices,
                // Optional: when empty, uses Evolution API v2 /message/sendText/{instance}
                'required' => false,
                'help' => 'mautic.evolution.campaign.action.group.help',
            ])
            ->add('template', ChoiceType::class, [
                'label' => 'mautic.evolution.campaign.action.template.select',
                'label_attr' => ['class' => 'control-label required'],
                'attr' => [
                    'class' => 'form-control',
                    'tooltip' => 'mautic.evolution.campaign.action.template.select.tooltip',
                ],
                'choices' => $this->getTemplateChoices(),
                'placeholder' => 'mautic.evolution.campaign.action.template.select.placeholder',
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'mautic.evolution.campaign.action.template.select.notblank',
                    ]),
                ],
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
        return 'evolution_send_template_action';
    }

    /**
     * Retorna choices de templates ativos
     */
    private function getTemplateChoices(): array
    {
        try {
            return $this->templateModel->getTemplateChoices();
        } catch (\Exception $e) {
            return [];
        }
    }
}