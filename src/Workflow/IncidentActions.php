<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Incident;
use Symfony\Component\Workflow\TransitionBlockerList;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Actions proposées à l'utilisateur courant pour un incident : transitions possibles depuis le
 * statut actuel et autorisées par les gardes. « Résoudre » reste proposé sans compte rendu
 * existant, puisque le formulaire de résolution le demande.
 */
final class IncidentActions
{
    public function __construct(private readonly WorkflowInterface $incidentStateMachine)
    {
    }

    /**
     * @return list<string>
     */
    public function available(Incident $incident): array
    {
        $available = [];
        foreach ($this->incidentStateMachine->getDefinition()->getTransitions() as $transition) {
            $name = $transition->getName();
            if (\in_array($name, $available, true) || !\in_array($incident->getStatus()->value, $transition->getFroms(), true)) {
                continue;
            }
            if (self::onlySatisfiableBlockers($this->incidentStateMachine->buildTransitionBlockerList($incident, $name))) {
                $available[] = $name;
            }
        }

        return $available;
    }

    /** Bloqueurs que l'utilisateur peut lever en complétant le formulaire (compte rendu). */
    public static function onlySatisfiableBlockers(TransitionBlockerList $blockers): bool
    {
        foreach ($blockers as $blocker) {
            if (IncidentGuardListener::CODE_REPORT_REQUIRED !== $blocker->getCode()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Définition du Workflow pour le Kanban : [{name, from, to, label}].
     *
     * @return list<array{name: string, from: string, to: string, label: string}>
     */
    public function definition(): array
    {
        $result = [];
        $metadata = $this->incidentStateMachine->getMetadataStore();
        foreach ($this->incidentStateMachine->getDefinition()->getTransitions() as $transition) {
            $label = $metadata->getMetadata('label', $transition);
            $result[] = [
                'name' => $transition->getName(),
                'from' => $transition->getFroms()[0],
                'to' => $transition->getTos()[0],
                'label' => \is_string($label) ? $label : $transition->getName(),
            ];
        }

        return $result;
    }

    public function label(string $transition): string
    {
        foreach ($this->incidentStateMachine->getDefinition()->getTransitions() as $t) {
            if ($t->getName() === $transition) {
                $label = $this->incidentStateMachine->getMetadataStore()->getMetadata('label', $t);

                return \is_string($label) ? $label : $transition;
            }
        }

        return $transition;
    }
}
