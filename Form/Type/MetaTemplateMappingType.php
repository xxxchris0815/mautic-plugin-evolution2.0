<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\FormButtonsType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Maps Meta template placeholders ({{1}}, {{2}}, ...) to Mautic contact fields.
 */
class MetaTemplateMappingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            $builder->add('paramField'.$i, ChoiceType::class, [
                'label' => sprintf('{{%d}}', $i),
                'label_attr' => ['class' => 'control-label'],
                'attr' => ['class' => 'form-control'],
                'choices' => MetaBusinessTemplateType::contactFieldChoices(),
                'placeholder' => 'mautic.evolution.meta_template.form.param_none',
                'required' => false,
            ]);
        }

        $builder->add('buttons', FormButtonsType::class, [
            'mapped' => false,
            'apply_text' => false,
            'save_text' => 'mautic.evolution.meta_template.mapping.save',
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
        return 'meta_template_mapping';
    }
}
