<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimezoneType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Validator\Constraints as Assert;

class UserSettingsType extends AbstractType
{
    private UserPasswordEncoderInterface $passwordEncoder;

    public function __construct(UserPasswordEncoderInterface $passwordEncoder)
    {
        $this->passwordEncoder = $passwordEncoder;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Profile section
            ->add('email', EmailType::class, [
                'label' => 'Email Address',
                'required' => false,
                'attr' => [
                    'placeholder' => 'your@email.com'
                ],
                'constraints' => [
                    new Assert\Email([
                        'message' => 'Please enter a valid email address'
                    ])
                ],
                'help' => 'Used for important notifications and account recovery'
            ])
            ->add('firstName', TextType::class, [
                'label' => 'First Name',
                'required' => false,
                'attr' => [
                    'placeholder' => 'John'
                ],
                'constraints' => [
                    new Assert\Length([
                        'max' => 50,
                        'maxMessage' => 'First name cannot be longer than 50 characters'
                    ])
                ]
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Last Name',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Doe'
                ],
                'constraints' => [
                    new Assert\Length([
                        'max' => 50,
                        'maxMessage' => 'Last name cannot be longer than 50 characters'
                    ])
                ]
            ])

            // Preferences section
            ->add('preferredLocale', ChoiceType::class, [
                'label' => 'Language',
                'required' => true,
                'choices' => [
                    'English' => 'en',
                    'Українська' => 'uk',
                    'Русский' => 'ru'
                ],
                'help' => 'Interface language for bot and emails'
            ])
            ->add('timezone', TimezoneType::class, [
                'label' => 'Timezone',
                'required' => true,
                'preferred_choices' => ['UTC', 'Europe/Kiev', 'Europe/Moscow', 'Europe/London'],
                'help' => 'Used for displaying times and scheduling'
            ])

            // Notification settings
            ->add('notificationsEnabled', CheckboxType::class, [
                'label' => 'Enable Telegram Notifications',
                'required' => false,
                'help' => 'Receive notifications about deposits, withdrawals, and bonuses'
            ])
            ->add('emailNotificationsEnabled', CheckboxType::class, [
                'label' => 'Enable Email Notifications',
                'required' => false,
                'help' => 'Receive important updates via email'
            ])
            ->add('marketingEmailsEnabled', CheckboxType::class, [
                'label' => 'Marketing Communications',
                'required' => false,
                'help' => 'Receive news, promotions, and platform updates'
            ])

            // Security settings
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Current Password',
                'required' => false,
                'mapped' => false,
                'attr' => [
                    'autocomplete' => 'current-password'
                ],
                'help' => 'Required to change password or enable 2FA'
            ])
            ->add('newPassword', PasswordType::class, [
                'label' => 'New Password',
                'required' => false,
                'mapped' => false,
                'attr' => [
                    'autocomplete' => 'new-password'
                ],
                'constraints' => [
                    new Assert\Length([
                        'min' => 8,
                        'minMessage' => 'Password must be at least 8 characters long',
                        'max' => 4096,
                    ]),
                    new Assert\Regex([
                        'pattern' => '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/',
                        'message' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number'
                    ])
                ]
            ])
            ->add('confirmPassword', PasswordType::class, [
                'label' => 'Confirm New Password',
                'required' => false,
                'mapped' => false,
                'attr' => [
                    'autocomplete' => 'new-password'
                ]
            ])

            // Withdrawal settings
            ->add('autoWithdrawalEnabled', CheckboxType::class, [
                'label' => 'Enable Auto-Withdrawal',
                'required' => false,
                'help' => 'Automatically withdraw bonus balance when threshold is reached'
            ])
            ->add('autoWithdrawMinAmount', NumberType::class, [
                'label' => 'Auto-Withdrawal Threshold',
                'required' => false,
                'scale' => 2,
                'attr' => [
                    'min' => 10,
                    'max' => 10000,
                    'step' => 10,
                    'placeholder' => '100.00'
                ],
                'constraints' => [
                    new Assert\GreaterThanOrEqual([
                        'value' => 10,
                        'message' => 'Minimum auto-withdrawal amount is 10 USDT'
                    ])
                ],
                'help' => 'Minimum balance to trigger auto-withdrawal (USDT)'
            ])
            ->add('defaultWithdrawalAddress', TextType::class, [
                'label' => 'Default Withdrawal Address',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Your USDT TRC20 wallet address',
                    'pattern' => '^T[a-zA-Z0-9]{33}$'
                ],
                'constraints' => [
                    new Assert\Regex([
                        'pattern' => '/^T[a-zA-Z0-9]{33}$/',
                        'message' => 'Invalid TRC20 address format'
                    ])
                ],
                'help' => 'Default address for withdrawals (can be changed per withdrawal)'
            ])

            // Privacy settings
            ->add('profileVisibility', ChoiceType::class, [
                'label' => 'Profile Visibility',
                'required' => true,
                'choices' => [
                    'Public' => 'public',
                    'Private' => 'private'
                ],
                'help' => 'Control who can see your profile statistics'
            ])
            ->add('showInLeaderboard', CheckboxType::class, [
                'label' => 'Show in Leaderboard',
                'required' => false,
                'help' => 'Display your username in public rankings'
            ]);

        // Add event listeners
        $builder->addEventListener(FormEvents::POST_SUBMIT, [$this, 'onPostSubmit']);
    }

    /**
     * Handle post-submit validation
     */
    public function onPostSubmit(FormEvent $event): void
    {
        $form = $event->getForm();
        $user = $event->getData();

        if (!$user instanceof User) {
            return;
        }

        // Validate password change
        $currentPassword = $form->get('currentPassword')->getData();
        $newPassword = $form->get('newPassword')->getData();
        $confirmPassword = $form->get('confirmPassword')->getData();

        if ($newPassword || $confirmPassword) {
            // Current password is required for password change
            if (!$currentPassword) {
                $form->get('currentPassword')->addError(
                    new FormError('Current password is required to change password')
                );
                return;
            }

            // Verify current password
            if (!$this->passwordEncoder->isPasswordValid($user, $currentPassword)) {
                $form->get('currentPassword')->addError(
                    new FormError('Current password is incorrect')
                );
                return;
            }

            // Check if passwords match
            if ($newPassword !== $confirmPassword) {
                $form->get('confirmPassword')->addError(
                    new FormError('Passwords do not match')
                );
                return;
            }

            // Check if new password is different from current
            if ($this->passwordEncoder->isPasswordValid($user, $newPassword)) {
                $form->get('newPassword')->addError(
                    new FormError('New password must be different from current password')
                );
                return;
            }
        }

        // Validate auto-withdrawal settings
        if ($user->isAutoWithdrawalEnabled()) {
            if (!$user->getAutoWithdrawMinAmount()) {
                $form->get('autoWithdrawMinAmount')->addError(
                    new FormError('Please set auto-withdrawal threshold')
                );
            }

            if (!$user->getDefaultWithdrawalAddress()) {
                $form->get('defaultWithdrawalAddress')->addError(
                    new FormError('Default withdrawal address is required for auto-withdrawal')
                );
            }
        }

        // Validate email if notifications are enabled
        if ($user->isEmailNotificationsEnabled() && !$user->getEmail()) {
            $form->get('email')->addError(
                new FormError('Email address is required for email notifications')
            );
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'user_settings',
            'validation_groups' => ['Default', 'settings'],
            'attr' => [
                'id' => 'user-settings-form',
                'class' => 'needs-validation',
                'novalidate' => true
            ]
        ]);
    }
}