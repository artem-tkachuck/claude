<?php

namespace App\Service\Security\Voter;

use App\Entity\User;
use App\Entity\Withdrawal;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class WithdrawalVoter extends Voter
{
    public const VIEW = 'VIEW';
    public const CREATE = 'CREATE';
    public const APPROVE = 'APPROVE';
    public const CANCEL = 'CANCEL';

    protected function supports(string $attribute, mixed $subject): bool
    {
        // For CREATE, we don't need a specific withdrawal
        if ($attribute === self::CREATE) {
            return true;
        }

        // For other actions, we need a Withdrawal object
        return in_array($attribute, [self::VIEW, self::APPROVE, self::CANCEL])
            && $subject instanceof Withdrawal;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        // User must be logged in
        if (!$user instanceof User) {
            return false;
        }

        switch ($attribute) {
            case self::CREATE:
                return $this->canCreate($user);

            case self::VIEW:
                return $this->canView($subject, $user);

            case self::APPROVE:
                return $this->canApprove($subject, $user);

            case self::CANCEL:
                return $this->canCancel($subject, $user);
        }

        return false;
    }

    private function canCreate(User $user): bool
    {
        // Any authenticated user can create withdrawals
        return true;
    }

    private function canView(Withdrawal $withdrawal, User $user): bool
    {
        // Users can view their own withdrawals
        if ($withdrawal->getUser() === $user) {
            return true;
        }

        // Admins can view all withdrawals
        return in_array('ROLE_ADMIN', $user->getRoles());
    }

    private function canApprove(Withdrawal $withdrawal, User $user): bool
    {
        // Only admins can approve
        if (!in_array('ROLE_ADMIN', $user->getRoles())) {
            return false;
        }

        // Cannot approve if already approved by this admin
        if ($withdrawal->isApprovedBy($user)) {
            return false;
        }

        // Cannot approve if not pending
        return $withdrawal->getStatus() === 'pending';
    }

    private function canCancel(Withdrawal $withdrawal, User $user): bool
    {
        // Admins can always cancel pending withdrawals
        if (in_array('ROLE_ADMIN', $user->getRoles()) && $withdrawal->getStatus() === 'pending') {
            return true;
        }

        // Users can cancel their own pending withdrawals
        return $withdrawal->getUser() === $user && $withdrawal->getStatus() === 'pending';
    }
}