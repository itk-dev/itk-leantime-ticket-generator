<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TicketType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Issue Title',
                'required' => true,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
            ])
            ->add('projects', ChoiceType::class, [
                'label' => 'Projects',
                'choices' => $options['project_choices'],
                'multiple' => true,
                'expanded' => true,
                'required' => true,
            ])
            ->add('due_date', DateType::class, [
                'label' => 'Due Date',
                'widget' => 'single_text',
                'required' => true,
                'data' => new \DateTime(),
            ])
            ->add('planned_hours', ChoiceType::class, [
                'label' => 'Planned Hours',
                'choices' => [
                    '1 hour' => '1',
                    '1 day (7.5 hours)' => '7.5',
                    '1 friday (7 hours)' => '7',
                    'Manual' => 'manual',
                ],
                'data' => '1',
                'required' => true,
            ])
            ->add('manual_hours', IntegerType::class, [
                'label' => 'Manual hours',
                'required' => false,
                'attr' => ['min' => 1],
            ])
            ->add('tags', TextType::class, [
                'label' => 'Tags',
                'required' => false,
            ])
            ->add('milestone', ChoiceType::class, [
                'label' => 'Milestone',
                'choices' => [
                    'None' => '',
                    'Cybersikkerhed' => 'Cybersikkerhed',
                ],
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Create Tickets',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('project_choices');
        $resolver->setAllowedTypes('project_choices', 'array');
    }
}
