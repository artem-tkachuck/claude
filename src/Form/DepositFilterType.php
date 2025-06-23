<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DepositFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('user', EntityType::class, [
                'class' => User::class,
                'label' => 'User',
                'required' => false,
                'placeholder' => 'All Users',
                'choice_label' => function (User $user) {
                    return sprintf('%s (ID: %d)', $user->getUsername(), $user->getId());
                },
                'attr' => [
                    'class' => 'form-select select2',
                    'data-placeholder' => 'Select user'
                ]
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Status',
                'required' => false,
                'placeholder' => 'All Statuses',
                'choices' => [
                    'Pending' => 'pending',
                    'Completed' => 'completed',
                    'Failed' => 'failed',
                    'Cancelled' => 'cancelled'
                ],
                'attr' => [
                    'class' => 'form-select'
                ]
            ])
            ->add('dateFrom', DateType::class, [
                'label' => 'From Date',
                'required' => false,
                'widget' => 'single_text',
                'attr' => [
                    'class' => 'form-control datepicker',
                    'placeholder' => 'From date'
                ]
            ])
            ->add('dateTo', DateType::class, [
                'label' => 'To Date',
                'required' => false,
                'widget' => 'single_text',
                'attr' => [
                    'class' => 'form-control datepicker',
                    'placeholder' => 'To date'
                ]
            ])
            ->add('amountMin', NumberType::class, [
                'label' => 'Min Amount',
                'required' => false,
                'scale' => 2,
                'attr' => [
                    'placeholder' => 'Min amount',
                    'min' => 0,
                    'step' => 0.01
                ]
            ])
            ->add('amountMax', NumberType::class, [
                'label' => 'Max Amount',
                'required' => false,
                'scale' => 2,
                'attr' => [
                    'placeholder' => 'Max amount',
                    'min' => 0,
                    'step' => 0.01
                ]
            ])
            ->add('txHash', TextType::class, [
                'label' => 'Transaction Hash',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Transaction hash',
                    'class' => 'form-control font-monospace'
                ]
            ])
            ->add('address', TextType::class, [
                'label' => 'Address',
                'required' => false,
                'attr' => [
                    'placeholder' => 'From/To address',
                    'class' => 'form-control font-monospace'
                ]
            ])
            ->add('sortBy', ChoiceType::class, [
                'label' => 'Sort By',
                'required' => false,
                'choices' => [
                    'Date (Newest)' => 'date_desc',
                    'Date (Oldest)' => 'date_asc',
                    'Amount (Highest)' => 'amount_desc',
                    'Amount (Lowest)' => 'amount_asc',
                    'User' => 'user'
                ],
                'data' => 'date_desc',
                'attr' => [
                    'class' => 'form-select'
                ]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method' => 'GET',
            'csrf_protection' => false,
            'attr' => [
                'class' => 'filter-form',
                'id' => 'deposit-filter-form'
            ]
        ]);
    }
}