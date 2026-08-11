<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class EvolutionConfigType
 * 
 * Formulário de configuração do Evolution API
 */
class EvolutionConfigType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('evolution_api_url', UrlType::class, [
                'label' => 'mautic.evolution.config.form.api_url',
                'label_attr' => ['class' => 'control-label required'],
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'tooltip' => 'mautic.evolution.config.form.api_url.tooltip',
                    'placeholder' => 'https://api.evolution.com',
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'mautic.evolution.config.api_url.notblank',
                    ]),
                    new Assert\Url([
                        'message' => 'mautic.evolution.config.api_url.invalid',
                    ]),
                ],
            ])
            ->add('evolution_api_key', TextType::class, [
                'label' => 'mautic.evolution.config.form.api_key',
                'label_attr' => ['class' => 'control-label required'],
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'tooltip' => 'mautic.evolution.config.form.api_key.tooltip',
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'mautic.evolution.config.api_key.notblank',
                    ]),
                ],
            ])
            ->add('evolution_timeout', NumberType::class, [
                'label' => 'mautic.evolution.config.form.timeout',
                'label_attr' => ['class' => 'control-label'],
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'tooltip' => 'mautic.evolution.config.form.timeout.tooltip',
                    'min' => 5,
                    'max' => 300,
                ],
                'data' => 30,
                'constraints' => [
                    new Assert\Range([
                        'min' => 5,
                        'max' => 300,
                        'notInRangeMessage' => 'mautic.evolution.config.timeout.range',
                    ]),
                ],
            ])
            ->add('evolution_webhook_enabled', CheckboxType::class, [
                'label' => 'mautic.evolution.config.form.webhook_enabled',
                'label_attr' => ['class' => 'control-label'],
                'required' => false,
                'attr' => [
                    'tooltip' => 'mautic.evolution.config.form.webhook_enabled.tooltip',
                ],
                'data' => false,
            ])
            ->add('evolution_debug_mode', CheckboxType::class, [
                'label' => 'mautic.evolution.config.form.debug_mode',
                'label_attr' => ['class' => 'control-label'],
                'required' => false,
                'attr' => [
                    'tooltip' => 'mautic.evolution.config.form.debug_mode.tooltip',
                ],
                'data' => false,
            ]);
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
        return 'evolution_config';
    }
}