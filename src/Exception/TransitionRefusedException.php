<?php

declare(strict_types=1);

namespace App\Exception;

use App\Workflow\IncidentGuardListener;
use Symfony\Component\Workflow\TransitionBlocker;
use Symfony\Component\Workflow\TransitionBlockerList;

/**
 * Transition refusée par le Workflow : statut incompatible, droit manquant ou règle métier.
 */
final class TransitionRefusedException extends \DomainException
{
    /** @var list<array{code: string, message: string}> */
    private array $blockers = [];

    public function __construct(
        public readonly string $transition,
        TransitionBlockerList|string $blockers,
    ) {
        if (\is_string($blockers)) {
            $this->blockers[] = ['code' => TransitionBlocker::UNKNOWN, 'message' => $blockers];
        } else {
            foreach ($blockers as $blocker) {
                $this->blockers[] = ['code' => $blocker->getCode(), 'message' => self::humanMessage($blocker)];
            }
        }

        parent::__construct(implode(' ', array_column($this->blockers, 'message')) ?: 'Transition refusée.');
    }

    /** Le refus vient d'un droit manquant (403 côté API). */
    public function isForbidden(): bool
    {
        return \in_array(IncidentGuardListener::CODE_FORBIDDEN, array_column($this->blockers, 'code'), true);
    }

    /**
     * @return list<array{code: string, message: string}>
     */
    public function getBlockers(): array
    {
        return $this->blockers;
    }

    private static function humanMessage(TransitionBlocker $blocker): string
    {
        return TransitionBlocker::BLOCKED_BY_MARKING === $blocker->getCode()
            ? 'Cette action n’est pas possible dans le statut actuel de l’incident.'
            : $blocker->getMessage();
    }
}
