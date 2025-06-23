<?php

namespace App\Form;

use DateTime;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class BroadcastMessageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('subject', TextType::class, [
                'label' => 'Subject',
                'required' => true,
                'attr' => [
                    'placeholder' => 'Message subject',
                    'maxlength' => 200
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'Please enter a subject'
                    ]),
                    new Assert\Length([
                        'max' => 200,
                        'maxMessage' => 'Subject cannot be longer than 200 characters'
                    ])
                ],
                'help' => 'Subject for email notifications (not shown in Telegram)'
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Message',
                'required' => true,
                'attr' => [
                    'placeholder' => 'Enter your message here...',
                    'rows' => 10,
                    'maxlength' => 4000,
                    'class' => 'form-control message-editor'
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'Please enter a message'
                    ]),
                    new Assert\Length([
                        'min' => 10,
                        'max' => 4000,
                        'minMessage' => 'Message must be at least 10 characters long',
                        'maxMessage' => 'Message cannot be longer than 4000 characters'
                    ])
                ],
                'help' => 'You can use basic HTML for email or Telegram markdown'
            ])
            ->add('channels', ChoiceType::class, [
                'label' => 'Send Via',
                'required' => true,
                'expanded' => true,
                'multiple' => true,
                'choices' => [
                    'Telegram' => 'telegram',
                    'Email' => 'email',
                    'In-App Notification' => 'app'
                ],
                'data' => ['telegram'],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'Please select at least one channel'
                    ])
                ]
            ])
            ->add('targetAudience', ChoiceType::class, [
                'label' => 'Target Audience',
                'required' => true,
                'choices' => [
                    'All Active Users' => 'all_active',
                    'All Users (including inactive)' => 'all',
                    'Users with Deposits' => 'with_deposits',
                    'Users without Deposits' => 'without_deposits',
                    'VIP Users (>$10k deposits)' => 'vip',
                    'New Users (joined last 30 days)' => 'new_users',
                    'Inactive Users (no activity 30+ days)' => 'inactive',
                    'Custom Selection' => 'custom'
                ],
                'data' => 'all_active',
                'attr' => [
                    'class' => 'form-select audience-selector'
                ]
            ])
            ->add('userIds', TextType::class, [
                'label' => 'Specific User IDs',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Enter user IDs separated by commas',
                    'class' => 'form-control user-ids-input',
                    'style' => 'display:none;'
                ],
                'help' => 'Example: 1,2,3,4,5'
            ])
            ->add('priority', ChoiceType::class, [
                'label' => 'Priority',
                'required' => true,
                'choices' => [
                    'Low' => 'low',
                    'Normal' => 'normal',
                    'High' => 'high',
                    'Urgent' => 'urgent'
                ],
                'data' => 'normal',
                'help' => 'High priority messages are highlighted'
            ])
            ->add('scheduledAt', DateTimeType::class, [
                'label' => 'Schedule Send Time',
                'required' => false,
                'widget' => 'single_text',
                'html5' => true,
                'attr' => [
                    'min' => (new DateTime())->format('Y-m-d\TH:i'),
                    'class' => 'form-control'
                ],
                'help' => 'Leave empty to send immediately'
            ])
            ->add('includeUnsubscribed', CheckboxType::class, [
                'label' => 'Include users who opted out of marketing',
                'required' => false,
                'help' => 'Only for critical system messages'
            ])
            ->add('testMode', CheckboxType::class, [
                'label' => 'Test Mode',
                'required' => false,
                'help' => 'Send only to admins for testing'
            ])
            ->add('trackOpens', CheckboxType::class, [
                'label' => 'Track Opens',
                'required' => false,
                'data' => true,
                'help' => 'Track email opens and Telegram message views'
            ])
            ->add('includeActionButton', CheckboxType::class, [
                'label' => 'Include Action Button',
                'required' => false,
                'attr' => [
                    'class' => 'action-button-toggle'
                ]
            ])
            ->add('actionButtonText', TextType::class, [
                'label' => 'Button Text',
                'required' => false,
                'attr' => [
                    'placeholder' => 'e.g., View Details',
                    'class' => 'form-control action-button-field',
                    'style' => 'display:none;'
                ],
                'constraints' => [
                    new Assert\Length([
                        'max' => 50,
                        'maxMessage' => 'Button text cannot be longer than 50 characters'
                    ])
                ]
            ])
            ->add('actionButtonUrl', TextType::class, [
                'label' => 'Button URL',
                'required' => false,
                'attr' => [
                    'placeholder' => 'https://example.com',
                    'class' => 'form-control action-button-field',
                    'style' => 'display:none;'
                ],
                'constraints' => [
                    new Assert\Url([
                        'message' => 'Please enter a valid URL'
                    ])
                ]
            ])
            ->add('messageTemplate', ChoiceType::class, [
                'label' => 'Use Template',
                'required' => false,
                'placeholder' => '-- Select Template --',
                'choices' => [
                    'System Maintenance' => 'maintenance',
                    'New Feature Announcement' => 'feature',
                    'Security Alert' => 'security',
                    'Promotion' => 'promotion',
                    'Platform Update' => 'update',
                    'Important Notice' => 'notice'
                ],
                'attr' => [
                    'class' => 'form-select template-selector'
                ]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'broadcast_message',
            'attr' => [
                'id' => 'broadcast-form',
                'class' => 'needs-validation broadcast-form',
                'novalidate' => true
            ]
        ]);
    }
}
