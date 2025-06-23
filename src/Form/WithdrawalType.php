<?php

namespace App\Form;

use App\Entity\Withdrawal;
use App\Service\Blockchain\TronService;
use DateTime;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Validator\Constraints as Assert;

class WithdrawalType extends AbstractType
{
    private Security $security;
    private TronService $tronService;

    public function __construct(Security $security, TronService $tronService)
    {
        $this->security = $security;
        $this->tronService = $tronService;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $user = $this->security->getUser();

        $builder
            ->add('amount', NumberType::class, [
                'label' => 'Amount (USDT)',
                'required' => true,
                'scale' => 2,
                'attr' => [
                    'min' => 10,
                    'max' => 10000,
                    'step' => 0.01,
                    'placeholder' => 'Enter amount to withdraw'
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'Please enter an amount'
                    ]),
                    new Assert\GreaterThanOrEqual([
                        'value' => 10,
                        'message' => 'Minimum withdrawal amount is 10 USDT'
                    ]),
                    new Assert\LessThanOrEqual([
                        'value' => 10000,
                        'message' => 'Maximum withdrawal amount is 10,000 USDT per transaction'
                    ])
                ],
                'help' => 'Minimum: 10 USDT, Maximum: 10,000 USDT'
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Withdrawal Type',
                'required' => true,
                'choices' => [
                    'Bonus Balance' => 'bonus',
                    'Deposit Balance' => 'deposit'
                ],
                'placeholder' => 'Select withdrawal type',
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'Please select withdrawal type'
                    ]),
                    new Assert\Choice([
                        'choices' => ['bonus', 'deposit'],
                        'message' => 'Invalid withdrawal type'
                    ])
                ],
                'help' => 'Choose which balance to withdraw from'
            ])
            ->add('address', TextType::class, [
                'label' => 'Withdrawal Address',
                'required' => true,
                'attr' => [
                    'placeholder' => 'Enter your USDT (TRC20) wallet address',
                    'pattern' => '^T[a-zA-Z0-9]{33}$',
                    'maxlength' => 34
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'Please enter withdrawal address'
                    ]),
                    new Assert\Regex([
                        'pattern' => '/^T[a-zA-Z0-9]{33}$/',
                        'message' => 'Invalid TRC20 address format'
                    ])
                ],
                'help' => 'Enter your USDT TRC20 wallet address (starts with T)'
            ]);

        // Add 2FA code field if user has 2FA enabled
        if ($user && $user->isTwoFactorEnabled()) {
            $builder->add('twoFactorCode', TextType::class, [
                'label' => '2FA Code',
                'required' => true,
                'mapped' => false,
                'attr' => [
                    'placeholder' => 'Enter 6-digit code',
                    'maxlength' => 6,
                    'pattern' => '[0-9]{6}',
                    'autocomplete' => 'off'
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => '2FA code is required'
                    ]),
                    new Assert\Regex([
                        'pattern' => '/^[0-9]{6}$/',
                        'message' => '2FA code must be 6 digits'
                    ])
                ],
                'help' => 'Enter the 6-digit code from your authenticator app'
            ]);
        }

        // Add memo/note field
        $builder->add('note', TextType::class, [
            'label' => 'Note (Optional)',
            'required' => false,
            'attr' => [
                'placeholder' => 'Add a note for this withdrawal',
                'maxlength' => 255
            ],
            'constraints' => [
                new Assert\Length([
                    'max' => 255,
                    'maxMessage' => 'Note cannot be longer than 255 characters'
                ])
            ]
        ]);

        // Add form event listeners
        $builder->addEventListener(FormEvents::POST_SET_DATA, [$this, 'onPostSetData']);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, [$this, 'onPreSubmit']);
        $builder->addEventListener(FormEvents::POST_SUBMIT, [$this, 'onPostSubmit']);
    }

    /**
     * Handle post set data event
     */
    public function onPostSetData(FormEvent $event): void
    {
        $form = $event->getForm();
        $withdrawal = $event->getData();

        if ($withdrawal && $withdrawal->getId()) {
            // If editing existing withdrawal, make fields read-only
            $form->get('amount')->setDisabled(true);
            $form->get('type')->setDisabled(true);
            $form->get('address')->setDisabled(true);
        }
    }

    /**
     * Handle pre-submit event
     */
    public function onPreSubmit(FormEvent $event): void
    {
        $data = $event->getData();

        // Clean address format
        if (isset($data['address'])) {
            $data['address'] = trim($data['address']);
            $event->setData($data);
        }
    }

    /**
     * Handle post-submit event
     */
    public function onPostSubmit(FormEvent $event): void
    {
        $form = $event->getForm();
        $withdrawal = $event->getData();

        if (!$withdrawal instanceof Withdrawal) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user) {
            return;
        }

        // Validate balance
        $type = $withdrawal->getType();
        $amount = $withdrawal->getAmount();

        if ($type === 'bonus') {
            if ($amount > $user->getBonusBalance()) {
                $form->get('amount')->addError(new FormError(
                    sprintf('Insufficient bonus balance. Available: %.2f USDT', $user->getBonusBalance())
                ));
            }
        } elseif ($type === 'deposit') {
            if ($amount > $user->getDepositBalance()) {
                $form->get('amount')->addError(new FormError(
                    sprintf('Insufficient deposit balance. Available: %.2f USDT', $user->getDepositBalance())
                ));
            }

            // Check 1-year lock for deposits
            $firstDeposit = $user->getDeposits()->first();
            if ($firstDeposit) {
                $oneYearAgo = new DateTime('-1 year');
                if ($firstDeposit->getCreatedAt() > $oneYearAgo) {
                    $unlockDate = clone $firstDeposit->getCreatedAt();
                    $unlockDate->modify('+1 year');

                    $form->get('type')->addError(new FormError(
                        sprintf('Deposits can only be withdrawn after 1 year. Unlock date: %s',
                            $unlockDate->format('Y-m-d'))
                    ));
                }
            }
        }

        // Validate address
        $address = $withdrawal->getAddress();
        if ($address && !$this->tronService->validateAddress($address)) {
            $form->get('address')->addError(new FormError('Invalid TRON address'));
        }

        // Validate 2FA code if provided
        if ($form->has('twoFactorCode')) {
            $code = $form->get('twoFactorCode')->getData();
            if ($code && $user->isTwoFactorEnabled()) {
                // This would validate against the 2FA service
                // Placeholder for actual implementation
                if (!$this->validate2FACode($user, $code)) {
                    $form->get('twoFactorCode')->addError(new FormError('Invalid 2FA code'));
                }
            }
        }

        // Check daily limits
        $dailyTotal = $this->getDailyWithdrawalTotal($user);
        if ($dailyTotal + $amount > 10000) {
            $form->get('amount')->addError(new FormError(
                sprintf('Daily withdrawal limit exceeded. You can withdraw up to %.2f USDT today',
                    10000 - $dailyTotal)
            ));
        }

        // Check for pending withdrawals
        if ($this->hasPendingWithdrawals($user)) {
            $form->addError(new FormError('You have pending withdrawals. Please wait for them to be processed.'));
        }
    }

    /**
     * Validate 2FA code (placeholder)
     */
    private function validate2FACode($user, string $code): bool
    {
        // This would use the TwoFactorService
        return true;
    }

    /**
     * Get daily withdrawal total (placeholder)
     */
    private function getDailyWithdrawalTotal($user): float
    {
        // This would query the withdrawal repository
        return 0.0;
    }

    /**
     * Check if user has pending withdrawals (placeholder)
     */
    private function hasPendingWithdrawals($user): bool
    {
        // This would query the withdrawal repository
        return false;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Withdrawal::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'withdrawal',
            'attr' => [
                'id' => 'withdrawal-form',
                'class' => 'needs-validation',
                'novalidate' => true
            ]
        ]);
    }
}