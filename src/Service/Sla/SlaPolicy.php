<?php

declare(strict_types=1);

namespace App\Service\Sla;

use App\Entity\Incident;
use App\Enum\IncidentPriority;
use App\Enum\IncidentStatus;
use App\Enum\SlaState;

/**
 * Politique de SLA : délai de résolution par priorité (configurable, SPEC §3).
 *
 * Service pur, sans dépendance à la base ni à l'horloge : « maintenant » est toujours
 * passé en argument, ce qui le rend entièrement testable.
 *
 * Simplification documentée : le temps passé EN_ATTENTE ne suspend pas le SLA, et une
 * réouverture conserve l'échéance d'origine.
 */
final class SlaPolicy
{
    /** En dessous de cette part du délai restant, l'incident est « à risque ». */
    public const AT_RISK_RATIO = 0.25;

    /** @var array<string, int> */
    private array $hoursByPriority = [];

    /**
     * @param array<array-key, mixed> $hoursByPriority délai en heures, indexé par priorité (P1..P4)
     */
    public function __construct(array $hoursByPriority)
    {
        foreach (IncidentPriority::cases() as $priority) {
            $hours = $hoursByPriority[$priority->value] ?? null;
            if (!\is_int($hours) && !(\is_string($hours) && ctype_digit($hours))) {
                throw new \InvalidArgumentException(\sprintf('Délai SLA manquant ou invalide pour la priorité %s.', $priority->value));
            }
            $hours = (int) $hours;
            if ($hours < 1 || $hours > 24 * 365) {
                throw new \InvalidArgumentException(\sprintf('Le délai SLA de %s doit être compris entre 1 h et 8760 h (%d reçu).', $priority->value, $hours));
            }
            $this->hoursByPriority[$priority->value] = $hours;
        }
    }

    public function hoursFor(IncidentPriority $priority): int
    {
        return $this->hoursByPriority[$priority->value];
    }

    /**
     * @return array<string, int> délai en heures par priorité
     */
    public function allHours(): array
    {
        return $this->hoursByPriority;
    }

    public function dueAt(IncidentPriority $priority, \DateTimeImmutable $createdAt): \DateTimeImmutable
    {
        return $createdAt->modify(\sprintf('+%d hours', $this->hoursFor($priority)));
    }

    public function assessIncident(Incident $incident, \DateTimeImmutable $now): SlaAssessment
    {
        return $this->assess($incident->getStatus(), $incident->getCreatedAt(), $incident->getSlaDueAt(), $incident->getResolvedAt(), $now);
    }

    public function assess(
        IncidentStatus $status,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $dueAt,
        ?\DateTimeImmutable $resolvedAt,
        \DateTimeImmutable $now,
    ): SlaAssessment {
        if (IncidentStatus::Annule === $status) {
            return new SlaAssessment(SlaState::NotApplicable, $dueAt, 0);
        }

        // Résolu (ou clos après résolution) : on compare la date de résolution à l'échéance.
        if (null !== $resolvedAt && !$status->isOpen()) {
            $margin = $dueAt->getTimestamp() - $resolvedAt->getTimestamp();

            return new SlaAssessment($margin >= 0 ? SlaState::Met : SlaState::Missed, $dueAt, $margin);
        }

        $remaining = $dueAt->getTimestamp() - $now->getTimestamp();
        if ($remaining < 0) {
            return new SlaAssessment(SlaState::Breached, $dueAt, $remaining);
        }

        $total = max(1, $dueAt->getTimestamp() - $createdAt->getTimestamp());
        $state = ($remaining / $total) <= self::AT_RISK_RATIO ? SlaState::AtRisk : SlaState::OnTrack;

        return new SlaAssessment($state, $dueAt, $remaining);
    }
}
