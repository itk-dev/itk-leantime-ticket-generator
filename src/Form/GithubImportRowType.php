<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GithubImportRowType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('include', CheckboxType::class, [
                'label' => false,
                'required' => false,
            ])
            ->add('number', HiddenType::class)
            ->add('title', HiddenType::class)
            ->add('description', HiddenType::class)
            ->add('html_url', HiddenType::class)
            ->add('labels', HiddenType::class)
            ->add('planned_hours', NumberType::class, [
                'label' => false,
                'required' => true,
                'scale' => 2,
                'html5' => true,
                'attr' => ['min' => 0, 'step' => 0.25],
            ])
            ->add('priority', ChoiceType::class, [
                'label' => false,
                'choices' => $options['priority_choices'],
                'required' => true,
            ])
            ->add('due_date', DateType::class, [
                'label' => false,
                'widget' => 'single_text',
                'required' => true,
                'input' => 'datetime_immutable',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['priority_choices']);
        $resolver->setAllowedTypes('priority_choices', 'array');
    }
}
