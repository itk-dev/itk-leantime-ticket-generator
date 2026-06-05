<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GithubImportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('rows', CollectionType::class, [
                'entry_type' => GithubImportRowType::class,
                'entry_options' => [
                    'priority_choices' => $options['priority_choices'],
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'label' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Create Tickets',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['priority_choices']);
        $resolver->setAllowedTypes('priority_choices', 'array');
    }
}
