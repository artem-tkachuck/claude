<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class AdminSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // General Settings
            ->add('platform_name', TextType::class, [
                'label' => 'Platform Name',
                'required' => true,
                'data' => $options['settings']['platform_name'] ?? 'Crypto Investment Platform',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(['max' => 100])
                ]
            ])
            ->add('maintenance_mode', CheckboxType::class, [
                'label' => 'Maintenance Mode',
                'required' => false,
                'data' => $options['settings']['maintenance_mode'] ?? false,
                'help' => 'Enable to prevent user access during maintenance'
            ])
            ->add('registration_enabled', CheckboxType::class, [
                'label' => 'Registration Enabled',
                'required' => false,
                'data' => $options['settings']['registration_enabled'] ?? true,
                'help' => 'Allow new users to register'
            ])

            // Crypto Settings
            ->add('crypto_network', ChoiceType::class, [
                'label' => 'Cryptocurrency Network',
                'required' => true,
                'choices' => [
                    'TRC20 (Tron)' => 'TRC20',
                    'ERC20 (Ethereum)' => 'ERC20',
                    'BEP20 (BSC)' => 'BEP20'
                ],
                'data' => $options['settings']['crypto_network'] ?? 'TRC20'
            ])
            ->add('crypto_currency', ChoiceType::class, [
                'label' => 'Accepted Currency',
                'required' => true,
                'choices' => [
                    'USDT' => 'USDT',
                    'USDC' => 'USDC',
                    'BUSD' => 'BUSD'
                ],
                'data' => $options['settings']['crypto_currency'] ?? 'USDT'
            ])
            ->add('hot_wallet_address', TextType::class, [
                'label' => 'Hot Wallet Address',
                'required' => true,
                'data' => $options['settings']['hot_wallet_address'] ?? '',
                'help' => 'Address for automatic withdrawals',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Regex([
                        'pattern' => '/^T[a-zA-Z0-9]{33}$/',
                        'message' => 'Invalid wallet address format'
                    ])
                ]
            ])
            ->add('cold_wallet_address', TextType::class, [
                'label' => 'Cold Wallet Address',
                'required' => true,
                'data' => $options['settings']['cold_wallet_address'] ?? '',
                'help' => 'Address for secure storage',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Regex([
                        'pattern' => '/^T[a-zA-Z0-9]{33}$/',
                        'message' => 'Invalid wallet address format'
                    ])
                ]
            ])
            ->add('hot_wallet_limit', NumberType::class, [
                'label' => 'Hot Wallet Limit (USDT)',
                'required' => true,
                'scale' => 2,
                'data' => $options['settings']['hot_wallet_limit'] ?? 10000,
                'help' => 'Maximum balance to keep in hot wallet',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\GreaterThan(['value' => 0])
                ]
            ])
            ->add('auto_transfer_to_cold', CheckboxType::class, [
                'label' => 'Auto Transfer to Cold Wallet',
                'required' => false,
                'data' => $options['settings']['auto_transfer_to_cold'] ?? true,
                'help' => 'Automatically transfer excess funds to cold wallet'
            ])

            // Deposit Settings
            ->add('min_deposit', NumberType::class, [
                'label' => 'Minimum Deposit (USDT)',
                'required' => true,
                'scale' => 2,
                'data' => $options['settings']['min_deposit'] ?? 100,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\GreaterThan(['value' => 0])
                ]
            ])
            ->add('max_deposit', NumberType::class, [
                'label' => 'Maximum Deposit (USDT)',
                'required' => true,
                'scale' => 2,
                'data' => $options['settings']['max_deposit'] ?? 100000,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\GreaterThan(['value' => 0])
                ]
            ])
            ->add('deposit_confirmations', IntegerType::class, [
                'label' => 'Required Confirmations',
                'required' => true,
                'data' => $options['settings']['deposit_confirmations'] ?? 19,
                'help' => 'Number of blockchain confirmations required',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(['min' => 1, 'max' => 100])
                ]
            ])

            // Withdrawal Settings
            ->add('withdrawal_min', NumberType::class, [
                'label' => 'Minimum Withdrawal (USDT)',
                'required' => true,
                'scale' => 2,
                'data' => $options['settings']['withdrawal_min'] ?? 10,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\GreaterThan(['value' => 0])
                ]
            ])
            ->add('withdrawal_fee', NumberType::class, [
                'label' => 'Withdrawal Fee (USDT)',
                'required' => true,
                'scale' => 2,
                'data' => $options['settings']['withdrawal_fee'] ?? 1,
                'help' => 'Fixed fee per withdrawal',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\GreaterThanOrEqual(['value' => 0])
                ]
            ])
            ->add('withdrawal_daily_limit', NumberType::class, [
                'label' => 'Daily Withdrawal Limit (USDT)',
                'required' => true,
                'scale' => 2,
                'data' => $options['settings']['withdrawal_daily_limit'] ?? 10000,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\GreaterThan(['value' => 0])
                ]
            ])
            ->add('withdrawal_auto_approve_limit', NumberType::class, [
                'label' => 'Auto-Approve Limit (USDT)',
                'required' => true,
                'scale' => 2,
                'data' => $options['settings']['withdrawal_auto_approve_limit'] ?? 100,
                'help' => 'Withdrawals below this amount can be auto-approved',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\GreaterThanOrEqual(['value' => 0])
                ]
            ])
            ->add('withdrawal_approvals_required', IntegerType::class, [
                'label' => 'Admin Approvals Required',
                'required' => true,
                'data' => $options['settings']['withdrawal_approvals_required'] ?? 2,
                'help' => 'Number of admin approvals needed',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(['min' => 1, 'max' => 5])
                ]
            ])

            // Bonus Settings
            ->add('bonus_enabled', CheckboxType::class, [
                'label' => 'Enable Daily Bonuses',
                'required' => false,
                'data' => $options['settings']['bonus_enabled'] ?? true
            ])
            ->add('bonus_distribution_time', TimeType::class, [
                'label' => 'Bonus Distribution Time',
                'required' => true,
                'input' => 'string',
                'widget' => 'single_text',
                'data' => $options['settings']['bonus_distribution_time'] ?? '00:00',
                'help' => 'Daily time for bonus calculation (UTC)'
            ])
            ->add('company_profit_percent', NumberType::class, [
                'label' => 'Company Profit Share (%)',
                'required' => true,
                'scale' => 2,
                'data' => $options['settings']['company_profit_percent'] ?? 30,
                'help' => 'Percentage of profits kept by company',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(['min' => 0, 'max' => 100])
                ]
            ])
            ->add('min_balance_for_bonus', NumberType::class, [
                'label' => 'Minimum Balance for Bonus (USDT)',
                'required' => true,
                'scale' => 2,
                'data' => $options['settings']['min_balance_for_bonus'] ?? 0,
                'help' => 'Minimum deposit balance to receive bonuses',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\GreaterThanOrEqual(['value' => 0])
                ]
            ])

            // Referral Settings
            ->add('referral_enabled', CheckboxType::class, [
                'label' => 'Enable Referral System',
                'required' => false,
                'data' => $options['settings']['referral_enabled'] ?? true
            ])
            ->add('referral_levels', IntegerType::class, [
                'label' => 'Referral Levels',
                'required' => true,
                'data' => $options['settings']['referral_levels'] ?? 2,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(['min' => 1, 'max' => 5])
                ]
            ])
            ->add('referral_level_1_percent', NumberType::class, [
                'label' => 'Level 1 Commission (%)',
                'required' => true,
                'scale' => 2,
                'data' => $options['settings']['referral_level_1_percent'] ?? 10,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(['min' => 0, 'max' => 50])
                ]
            ])
            ->add('referral_level_2_percent', NumberType::class, [
                'label' => 'Level 2 Commission (%)',
                'required' => true,
                'scale' => 2,
                'data' => $options['settings']['referral_level_2_percent'] ?? 5,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(['min' => 0, 'max' => 50])
                ]
            ])
            ->add('referral_bonus_on_registration', CheckboxType::class, [
                'label' => 'Bonus on Registration',
                'required' => false,
                'data' => $options['settings']['referral_bonus_on_registration'] ?? false,
                'help' => 'Give referral bonus immediately on registration'
            ])
            ->add('referral_min_deposit', NumberType::class, [
                'label' => 'Minimum Deposit for Referral Bonus',
                'required' => true,
                'scale' => 2,
                'data' => $options['settings']['referral_min_deposit'] ?? 100,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\GreaterThan(['value' => 0])
                ]
            ])

            // Security Settings
            ->add('require_2fa_for_admins', CheckboxType::class, [
                'label' => 'Require 2FA for Admins',
                'required' => false,
                'data' => $options['settings']['require_2fa_for_admins'] ?? true
            ])
            ->add('require_2fa_for_withdrawals', CheckboxType::class, [
                'label' => 'Require 2FA for Withdrawals',
                'required' => false,
                'data' => $options['settings']['require_2fa_for_withdrawals'] ?? false
            ])
            ->add('session_lifetime', IntegerType::class, [
                'label' => 'Session Lifetime (minutes)',
                'required' => true,
                'data' => $options['settings']['session_lifetime'] ?? 60,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(['min' => 5, 'max' => 1440])
                ]
            ])
            ->add('max_login_attempts', IntegerType::class, [
                'label' => 'Max Login Attempts',
                'required' => true,
                'data' => $options['settings']['max_login_attempts'] ?? 5,
                'help' => 'Before temporary lockout',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(['min' => 3, 'max' => 10])
                ]
            ])
            ->add('lockout_duration', IntegerType::class, [
                'label' => 'Lockout Duration (minutes)',
                'required' => true,
                'data' => $options['settings']['lockout_duration'] ?? 15,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(['min' => 5, 'max' => 60])
                ]
            ])

            // Geo-blocking Settings
            ->add('geo_blocking_enabled', CheckboxType::class, [
                'label' => 'Enable Geo-blocking',
                'required' => false,
                'data' => $options['settings']['geo_blocking_enabled'] ?? false
            ])
            ->add('blocked_countries', CollectionType::class, [
                'label' => 'Blocked Countries',
                'entry_type' => TextType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'required' => false,
                'data' => $options['settings']['blocked_countries'] ?? [],
                'entry_options' => [
                    'attr' => ['placeholder' => 'Country code (e.g., US)']
                ]
            ])
            ->add('allowed_countries', CollectionType::class, [
                'label' => 'Allowed Countries (if not empty, only these are allowed)',
                'entry_type' => TextType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'required' => false,
                'data' => $options['settings']['allowed_countries'] ?? [],
                'entry_options' => [
                    'attr' => ['placeholder' => 'Country code (e.g., UA)']
                ]
            ])

            // Notification Settings
            ->add('telegram_notifications_enabled', CheckboxType::class, [
                'label' => 'Enable Telegram Notifications',
                'required' => false,
                'data' => $options['settings']['telegram_notifications_enabled'] ?? true
            ])
            ->add('email_notifications_enabled', CheckboxType::class, [
                'label' => 'Enable Email Notifications',
                'required' => false,
                'data' => $options['settings']['email_notifications_enabled'] ?? true
            ])
            ->add('admin_notification_emails', CollectionType::class, [
                'label' => 'Admin Notification Emails',
                'entry_type' => EmailType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'required' => false,
                'data' => $options['settings']['admin_notification_emails'] ?? [],
                'entry_options' => [
                    'attr' => ['placeholder' => 'admin@example.com']
                ]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'admin_settings',
            'settings' => [],
            'attr' => [
                'id' => 'admin-settings-form',
                'class' => 'needs-validation',
                'novalidate' => true
            ]
        ]);

        $resolver->setRequired('settings');
        $resolver->setAllowedTypes('settings', 'array');
    }
}