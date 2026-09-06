<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\FormButtonsType;
use MauticPlugin\MauticEvolutionBundle\Entity\EvolutionTemplate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use MauticPlugin\MauticEvolutionBundle\Form\DataTransformer\JsonArrayTransformer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class EvolutionTemplateType
 * 
 * Formulário para templates do Evolution API
 */
class EvolutionTemplateType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'mautic.evolution.template.form.name',
                'label_attr' => ['class' => 'control-label required'],
                'attr' => [
                    'class' => 'form-control',
                    'tooltip' => 'mautic.evolution.template.form.name.tooltip',
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'mautic.evolution.template.name.notblank',
                    ]),
                    new Assert\Length([
                        'max' => 255,
                        'maxMessage' => 'mautic.evolution.template.name.maxlength',
                    ]),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'mautic.evolution.template.form.description',
                'label_attr' => ['class' => 'control-label'],
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'tooltip' => 'mautic.evolution.template.form.description.tooltip',
                ],
                'required' => false,
            ])
            ->add('content', TextareaType::class, [
                'label' => 'mautic.evolution.template.form.content',
                'label_attr' => ['class' => 'control-label required'],
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 8,
                    'tooltip' => 'mautic.evolution.template.form.content.tooltip',
                    'placeholder' => 'mautic.evolution.template.form.content.placeholder',
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'mautic.evolution.template.content.notblank',
                    ]),
                ],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'mautic.evolution.template.form.type',
                'label_attr' => ['class' => 'control-label required'],
                'attr' => [
                    'class' => 'form-control',
                    'tooltip' => 'mautic.evolution.template.form.type.tooltip',
                ],
                'choices' => [
                    'mautic.evolution.template.type.text' => 'text',
                    'mautic.evolution.template.type.media' => 'media',
                    'mautic.evolution.template.type.interactive' => 'interactive',
                    'mautic.evolution.template.type.template' => 'template',
                ],
                'placeholder' => 'mautic.evolution.template.form.type.placeholder',
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'mautic.evolution.template.type.notblank',
                    ]),
                ],
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'mautic.evolution.template.form.is_active',
                'label_attr' => ['class' => 'control-label'],
                'attr' => [
                    'tooltip' => 'mautic.evolution.template.form.is_active.tooltip',
                ],
                'required' => false,
                'data' => true,
            ])
            ->add('source', ChoiceType::class, [
                'label' => 'mautic.evolution.template.form.source',
                'label_attr' => ['class' => 'control-label'],
                'attr' => ['class' => 'form-control'],
                'choices' => [
                    'mautic.evolution.template.source.local' => 'local',
                    'mautic.evolution.template.source.evolution' => 'evolution',
                ],
            ])
            ->add('language', TextType::class, [
                'label' => 'mautic.evolution.template.form.language',
                'label_attr' => ['class' => 'control-label'],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'en_US',
                ],
                'required' => false,
            ])
            ->add('categoryType', ChoiceType::class, [
                'label' => 'mautic.evolution.template.form.category_type',
                'label_attr' => ['class' => 'control-label'],
                'attr' => ['class' => 'form-control'],
                'choices' => [
                    'UTILITY' => 'UTILITY',
                    'MARKETING' => 'MARKETING',
                    'AUTHENTICATION' => 'AUTHENTICATION',
                ],
                'placeholder' => '',
                'required' => false,
            ])
            ->add('status', TextType::class, [
                'label' => 'mautic.evolution.template.form.status',
                'label_attr' => ['class' => 'control-label'],
                'attr' => ['class' => 'form-control', 'readonly' => true],
                'required' => false,
            ])
            ->add(
                $builder->create('components', TextareaType::class, [
                    'label' => 'mautic.evolution.template.form.components',
                    'label_attr' => ['class' => 'control-label'],
                    'attr' => [
                        'class' => 'form-control',
                        'rows' => 8,
                        'placeholder' => '[{"type":"BODY","text":"Hello {{1}}"}]',
                    ],
                    'required' => false,
                ])->addViewTransformer(new JsonArrayTransformer())
            )
            ->add(
                $builder->create('parameterFields', TextareaType::class, [
                    'label' => 'mautic.evolution.template.form.parameter_fields',
                    'label_attr' => ['class' => 'control-label'],
                    'attr' => [
                        'class' => 'form-control',
                        'rows' => 4,
                        'placeholder' => '{"1":"{contactfield=firstname}"}',
                    ],
                    'required' => false,
                ])->addViewTransformer(new JsonArrayTransformer())
            )
            ->add(
                $builder->create('variables', TextareaType::class, [
                    'label' => 'mautic.evolution.template.form.variables',
                    'label_attr' => ['class' => 'control-label'],
                    'attr' => [
                        'class' => 'form-control',
                        'rows' => 4,
                        'tooltip' => 'mautic.evolution.template.form.variables.tooltip',
                        'placeholder' => 'mautic.evolution.template.form.variables.placeholder',
                    ],
                    'required' => false,
                    'help' => 'mautic.evolution.template.form.variables.help',
                ])
                ->addViewTransformer(new JsonArrayTransformer())
            );

        // Adiciona botões de ação
        $builder->add('buttons', FormButtonsType::class, [
            'mapped' => false,
        ]);

        if (!empty($options['action'])) {
            $builder->setAction($options['action']);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EvolutionTemplate::class,
            'translation_domain' => 'messages',
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix(): string
    {
        return 'evolution_template';
    }
}