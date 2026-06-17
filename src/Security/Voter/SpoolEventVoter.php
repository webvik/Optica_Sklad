<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\SpoolEvent;
use App\Entity\User;
use App\Security\WarehouseRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Úprava záznamu deníku: autor záznamu (libovolná úroveň oprávnění) nebo správce skladu.
 */
final class SpoolEventVoter extends Voter
{
    public const EDIT = 'SPOOL_EVENT_EDIT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::EDIT === $attribute && $subject instanceof SpoolEvent;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        \assert($subject instanceof SpoolEvent);

        if ($this->isWarehouseAdmin($token)) {
            return true;
        }

        $author = $subject->getCreatedBy();
        if (null === $author || null === $author->getId() || null === $user->getId()) {
            return false;
        }

        return $author->getId() === $user->getId();
    }

    private function isWarehouseAdmin(TokenInterface $token): bool
    {
        return \in_array(WarehouseRole::ADMIN, $token->getRoleNames(), true)
            || \in_array(WarehouseRole::APP_ADMIN, $token->getRoleNames(), true);
    }
}
