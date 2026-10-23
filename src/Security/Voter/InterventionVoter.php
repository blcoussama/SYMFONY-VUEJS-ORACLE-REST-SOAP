<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Intervention;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Un technicien ne modifie que ses propres interventions, et seulement si elles ne sont pas terminées.
 *
 * @extends Voter<string, Intervention>
 */
final class InterventionVoter extends Voter
{
    public const EDIT = 'INTERVENTION_EDIT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::EDIT === $attribute && $subject instanceof Intervention;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User
            && $user->isActive()
            && !$subject->isFinished()
            && $subject->getTechnician()->getId() === $user->getId()
            && $subject->getIncident()->isAssignedTo($user);
    }
}
