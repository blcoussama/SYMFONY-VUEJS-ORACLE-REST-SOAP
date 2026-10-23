<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\ApiClient;
use App\Entity\Incident;
use App\Entity\User;
use App\Enum\IncidentStatus;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droits sur un incident donné (SPEC §1).
 *
 * @extends Voter<string, Incident>
 */
final class IncidentVoter extends Voter
{
    /** Consulter l'incident. */
    public const VIEW = 'INCIDENT_VIEW';
    /** Ajouter un commentaire au journal. */
    public const COMMENT = 'INCIDENT_COMMENT';
    /** Assigner / réassigner un technicien (superviseur, incident ouvert). */
    public const ASSIGN = 'INCIDENT_ASSIGN';
    /** Changer la priorité (superviseur, incident ouvert). */
    public const PRIORITIZE = 'INCIDENT_PRIORITIZE';
    /** Travailler sur l'incident : technicien assigné uniquement (démarrer, attente, résoudre, interventions). */
    public const WORK = 'INCIDENT_WORK';
    /** Annulation par le partenaire qui l'a déclaré (tant qu'il n'est pas assigné). */
    public const PARTNER_CANCEL = 'INCIDENT_PARTNER_CANCEL';

    private const ATTRIBUTES = [self::VIEW, self::COMMENT, self::ASSIGN, self::PRIORITIZE, self::WORK, self::PARTNER_CANCEL];

    public function __construct(private readonly AccessDecisionManagerInterface $accessDecisionManager)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, self::ATTRIBUTES, true) && $subject instanceof Incident;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $principal = $token->getUser();

        if ($principal instanceof ApiClient) {
            return match ($attribute) {
                self::VIEW => true,
                self::PARTNER_CANCEL => null !== $principal->getId()
                    && $subject->getApiClient()?->getId() === $principal->getId()
                    && \in_array($subject->getStatus(), [IncidentStatus::Nouveau, IncidentStatus::Qualifie], true),
                default => false,
            };
        }

        if (!$principal instanceof User || !$principal->isActive()) {
            return false;
        }

        return match ($attribute) {
            self::VIEW, self::COMMENT => $this->hasRole($token, 'ROLE_AGENT'),
            self::ASSIGN => $subject->isOpen() && IncidentStatus::Nouveau !== $subject->getStatus() && $this->hasRole($token, 'ROLE_SUPERVISEUR'),
            self::PRIORITIZE => $subject->isOpen() && $this->hasRole($token, 'ROLE_SUPERVISEUR'),
            self::WORK => $this->hasRole($token, 'ROLE_TECHNICIEN') && $subject->isAssignedTo($principal),
            default => false,
        };
    }

    private function hasRole(TokenInterface $token, string $role): bool
    {
        return $this->accessDecisionManager->decide($token, [$role]);
    }
}
