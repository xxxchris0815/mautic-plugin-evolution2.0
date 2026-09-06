<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\FormButtonsType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * User-facing form to create a WhatsApp Business (Meta) template via Evolution API.
 */
class MetaBusinessTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'mautic.evolution.meta_template.form.name',
                'label_attr' => ['class' => 'control-label required'],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'order_update',
                    'tooltip' => 'mautic.evolution.meta_template.form.name.tooltip',
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'mautic.evolution.meta_template.name.notblank']),
                    new Assert\Regex([
                        'pattern' => '/^[a-z0-9_]+$/',
                        'message' => 'mautic.evolution.meta_template.name.invalid',
                    ]),
                    new Assert\Length(['max' => 512]),
                ],
            ])
            ->add('language', ChoiceType::class, [
                'label' => 'mautic.evolution.meta_template.form.language',
                'label_attr' => ['class' => 'control-label required'],
                'attr' => ['class' => 'form-control'],
                'choices' => self::languageChoices(),
                'constraints' => [
                    new Assert\NotBlank(),
                ],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'mautic.evolution.meta_template.form.category',
                'label_attr' => ['class' => 'control-label required'],
                'attr' => [
                    'class' => 'form-control',
                    'tooltip' => 'mautic.evolution.meta_template.form.category.tooltip',
                ],
                'choices' => [
                    'mautic.evolution.meta_template.category.utility' => 'UTILITY',
                    'mautic.evolution.meta_template.category.marketing' => 'MARKETING',
                    'mautic.evolution.meta_template.category.authentication' => 'AUTHENTICATION',
                ],
            ])
            ->add('allowCategoryChange', CheckboxType::class, [
                'label' => 'mautic.evolution.meta_template.form.allow_category_change',
                'label_attr' => ['class' => 'control-label'],
                'required' => false,
            ])
            ->add('headerType', ChoiceType::class, [
                'label' => 'mautic.evolution.meta_template.form.header_type',
                'label_attr' => ['class' => 'control-label'],
                'attr' => [
                    'class' => 'form-control js-meta-header-type',
                ],
                'choices' => [
                    'mautic.evolution.meta_template.header.none' => 'NONE',
                    'mautic.evolution.meta_template.header.text' => 'TEXT',
                    'mautic.evolution.meta_template.header.image' => 'IMAGE',
                    'mautic.evolution.meta_template.header.video' => 'VIDEO',
                    'mautic.evolution.meta_template.header.document' => 'DOCUMENT',
                ],
            ])
            ->add('headerText', TextType::class, [
                'label' => 'mautic.evolution.meta_template.form.header_text',
                'label_attr' => ['class' => 'control-label'],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Order {{1}}',
                ],
                'required' => false,
                'row_attr' => ['class' => 'js-meta-header-text'],
            ])
            ->add('headerExample', TextType::class, [
                'label' => 'mautic.evolution.meta_template.form.header_example',
                'label_attr' => ['class' => 'control-label'],
                'attr' => ['class' => 'form-control'],
                'required' => false,
                'row_attr' => ['class' => 'js-meta-header-text'],
            ])
            ->add('headerMediaUrl', TextType::class, [
                'label' => 'mautic.evolution.meta_template.form.header_media_url',
                'label_attr' => ['class' => 'control-label'],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'https://',
                    'tooltip' => 'mautic.evolution.meta_template.form.header_media_url.tooltip',
                ],
                'required' => false,
                'row_attr' => ['class' => 'js-meta-header-media'],
            ])
            ->add('body', TextareaType::class, [
                'label' => 'mautic.evolution.meta_template.form.body',
                'label_attr' => ['class' => 'control-label required'],
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 6,
                    'placeholder' => 'Hello {{1}}, your order {{2}} is on the way.',
                    'tooltip' => 'mautic.evolution.meta_template.form.body.tooltip',
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'mautic.evolution.meta_template.body.notblank']),
                ],
            ])
            ->add('exampleValues', TextareaType::class, [
                'label' => 'mautic.evolution.meta_template.form.examples',
                'label_attr' => ['class' => 'control-label'],
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => "Anna\n12345",
                    'tooltip' => 'mautic.evolution.meta_template.form.examples.tooltip',
                ],
                'required' => false,
            ])
            ->add('footer', TextType::class, [
                'label' => 'mautic.evolution.meta_template.form.footer',
                'label_attr' => ['class' => 'control-label'],
                'attr' => ['class' => 'form-control'],
                'required' => false,
            ]);

        for ($i = 1; $i <= 3; ++$i) {
            $builder
                ->add('button'.$i.'Type', ChoiceType::class, [
                    'label' => 'mautic.evolution.meta_template.form.button_type',
                    'label_attr' => ['class' => 'control-label'],
                    'attr' => [
                        'class' => 'form-control js-meta-button-type',
                        'data-button-index' => (string) $i,
                    ],
                    'choices' => [
                        'mautic.evolution.meta_template.button.none' => 'NONE',
                        'mautic.evolution.meta_template.button.quick_reply' => 'QUICK_REPLY',
                        'mautic.evolution.meta_template.button.url' => 'URL',
                        'mautic.evolution.meta_template.button.phone' => 'PHONE_NUMBER',
                    ],
                    'required' => false,
                ])
                ->add('button'.$i.'Text', TextType::class, [
                    'label' => 'mautic.evolution.meta_template.form.button_text',
                    'label_attr' => ['class' => 'control-label'],
                    'attr' => ['class' => 'form-control'],
                    'required' => false,
                    'row_attr' => ['class' => 'js-meta-button-text js-meta-button-'.$i],
                ])
                ->add('button'.$i.'Url', TextType::class, [
                    'label' => 'mautic.evolution.meta_template.form.button_url',
                    'label_attr' => ['class' => 'control-label'],
                    'attr' => [
                        'class' => 'form-control',
                        'placeholder' => 'https://example.com/{{1}}',
                    ],
                    'required' => false,
                    'row_attr' => ['class' => 'js-meta-button-url js-meta-button-'.$i],
                ])
                ->add('button'.$i.'UrlExample', TextType::class, [
                    'label' => 'mautic.evolution.meta_template.form.button_url_example',
                    'label_attr' => ['class' => 'control-label'],
                    'attr' => ['class' => 'form-control'],
                    'required' => false,
                    'row_attr' => ['class' => 'js-meta-button-url js-meta-button-'.$i],
                ])
                ->add('button'.$i.'Phone', TextType::class, [
                    'label' => 'mautic.evolution.meta_template.form.button_phone',
                    'label_attr' => ['class' => 'control-label'],
                    'attr' => [
                        'class' => 'form-control',
                        'placeholder' => '+4915112345678',
                    ],
                    'required' => false,
                    'row_attr' => ['class' => 'js-meta-button-phone js-meta-button-'.$i],
                ]);
        }

        for ($i = 1; $i <= 10; ++$i) {
            $builder->add('paramField'.$i, ChoiceType::class, [
                'label' => sprintf('{{%d}}', $i),
                'label_attr' => ['class' => 'control-label'],
                'attr' => ['class' => 'form-control'],
                'choices' => self::contactFieldChoices(),
                'placeholder' => 'mautic.evolution.meta_template.form.param_none',
                'required' => false,
            ]);
        }

        $builder->add('buttons', FormButtonsType::class, [
            'mapped' => false,
        ]);

        if (!empty($options['action'])) {
            $builder->setAction($options['action']);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'messages',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'meta_business_template';
    }

    /**
     * @return array<string, string>
     */
    public static function languageChoices(): array
    {
        return [
            'German (de)' => 'de',
            'German (de_DE)' => 'de_DE',
            'English (en)' => 'en',
            'English (en_US)' => 'en_US',
            'English (en_GB)' => 'en_GB',
            'Portuguese (pt_BR)' => 'pt_BR',
            'Spanish (es)' => 'es',
            'French (fr)' => 'fr',
            'Italian (it)' => 'it',
            'Dutch (nl)' => 'nl',
            'Polish (pl)' => 'pl',
            'Turkish (tr)' => 'tr',
            'Arabic (ar)' => 'ar',
            'Hindi (hi)' => 'hi',
            'Indonesian (id)' => 'id',
            'Russian (ru)' => 'ru',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function contactFieldChoices(): array
    {
        return [
            'firstname' => 'firstname',
            'lastname' => 'lastname',
            'email' => 'email',
            'company' => 'company',
            'mobile' => 'mobile',
            'phone' => 'phone',
            'city' => 'city',
            'country' => 'country',
            'address1' => 'address1',
            'website' => 'website',
            'title' => 'title',
        ];
    }
}
