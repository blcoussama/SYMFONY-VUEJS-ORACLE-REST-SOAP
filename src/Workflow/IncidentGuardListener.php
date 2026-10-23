<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Incident;
use App\Security\Voter\IncidentVoter;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;

/**
 * Gardes du Workflow « incident » (SPEC §3) :
 *  - qualifier / assigner / clore / rouvrir / annuler : superviseur
 *    (exception : un partenaire peut annuler l'incident qu'il a lui-même déclaré, tant qu'il n'est pas assigné) ;
 *  - démarrer / mettre en attente / reprendre / résoudre : technicien assigné uniquement ;
 *  - résoudre : exige une intervention terminée avec compte rendu.
 *
 * Les bloqueurs portent un code, traduit en 403 (droit) ou 409 (règle métier) par l'API.
 */
#[AsEventListener(event: 'workflow.incident.guard')]
final class IncidentGuardListener
{
    public const CODE_FORBIDDEN = 'netops.forbidden';
    public const CODE_REPORT_REQUIRED = 'netops.report_required';

    public function __construct(private readonly AuthorizationCheckerInterface $authorizationChecker)
    {
    }

    public function __invoke(GuardEvent $event): void
    {
        $incident = $event->getSubject();
        if (!$incident instanceof Incident) {
            return;
        }

        $transition = $event->getTransition()->getName();

        if (IncidentTransition::isSupervisorTransition($transition) && !$this->authorizationChecker->isGranted('ROLE_SUPERVISEUR')) {
            $partnerCancel = IncidentTransition::ANNULER === $transition
                && $this->authorizationChecker->isGranted(IncidentVoter::PARTNER_CANCEL, $incident);
            if (!$partnerCancel) {
                $event->addTransitionBlocker(new TransitionBlocker('Action réservée au superviseur.', self::CODE_FORBIDDEN));

                return;
            }
        }

        if (IncidentTransition::isTechnicianTransition($transition) && !$this->authorizationChecker->isGranted(IncidentVoter::WORK, $incident)) {
            $event->addTransitionBlocker(new TransitionBlocker('Action réservée au technicien assigné à cet incident.', self::CODE_FORBIDDEN));

            return;
        }

        if (IncidentTransition::RESOUDRE === $transition && !$incident->hasCompletedInterventionReport()) {
            $event->addTransitionBlocker(new TransitionBlocker('Un compte rendu d’intervention est obligatoire pour résoudre l’incident.', self::CODE_REPORT_REQUIRED));
        }
    }
}
