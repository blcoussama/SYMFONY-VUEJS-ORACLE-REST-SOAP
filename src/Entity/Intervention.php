<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InterventionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Intervention d'un technicien sur un incident : planifiée, démarrée, terminée avec compte rendu.
 */
#[ORM\Entity(repositoryClass: InterventionRepository::class)]
#[ORM\Table(name: 'nop_intervention')]
#[ORM\Index(name: 'idx_nop_interv_planned', columns: ['planned_at'])]
class Intervention
{
    public const REPORT_MAX_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'interventions')]
    #[ORM\JoinColumn(name: 'incident_id', nullable: false, onDelete: 'CASCADE')]
    private Incident $incident;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'technician_id', nullable: false)]
    private User $technician;

    #[ORM\Column(name: 'planned_at')]
    private \DateTimeImmutable $plannedAt;

    #[ORM\Column(name: 'started_at', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'ended_at', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    /**
     * Compte rendu (≤ 2000 caractères). Colonne de 4000 : VARCHAR2 Oracle compte en octets
     * par défaut et un caractère accentué en UTF-8 en occupe deux.
     */
    #[ORM\Column(name: 'report_text', length: 4000, nullable: true)]
    private ?string $report = null;

    /** Durée en minutes, calculée à la fin de l'intervention. */
    #[ORM\Column(name: 'duration_min', nullable: true)]
    private ?int $durationMinutes = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Incident $incident, User $technician, \DateTimeImmutable $plannedAt, \DateTimeImmutable $createdAt)
    {
        $this->incident = $incident;
        $this->technician = $technician;
        $this->plannedAt = $plannedAt;
        $this->createdAt = $createdAt;
        $incident->addIntervention($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIncident(): Incident
    {
        return $this->incident;
    }

    public function getTechnician(): User
    {
        return $this->technician;
    }

    public function getPlannedAt(): \DateTimeImmutable
    {
        return $this->plannedAt;
    }

    public function reschedule(\DateTimeImmutable $plannedAt): void
    {
        if (null !== $this->startedAt) {
            throw new \LogicException('Une intervention démarrée ne peut plus être replanifiée.');
        }
        $this->plannedAt = $plannedAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function start(\DateTimeImmutable $at): void
    {
        if (null !== $this->startedAt) {
            throw new \LogicException('Intervention déjà démarrée.');
        }
        $this->startedAt = $at;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    /**
     * Termine l'intervention et calcule sa durée (minutes entières, arrondi inférieur).
     */
    public function finish(\DateTimeImmutable $at, string $report): void
    {
        if (null === $this->startedAt) {
            throw new \LogicException('Une intervention doit être démarrée avant d’être terminée.');
        }
        if (null !== $this->endedAt) {
            throw new \LogicException('Intervention déjà terminée.');
        }
        if ($at < $this->startedAt) {
            throw new \InvalidArgumentException('La fin ne peut pas précéder le début de l’intervention.');
        }
        $report = trim($report);
        if ('' === $report) {
            throw new \InvalidArgumentException('Le compte rendu est obligatoire.');
        }
        if (mb_strlen($report) > self::REPORT_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('Le compte rendu dépasse %d caractères.', self::REPORT_MAX_LENGTH));
        }

        $this->endedAt = $at;
        $this->report = $report;
        $this->durationMinutes = self::minutesBetween($this->startedAt, $at);
    }

    public static function minutesBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return intdiv(max(0, $to->getTimestamp() - $from->getTimestamp()), 60);
    }

    public function getReport(): ?string
    {
        return $this->report;
    }

    public function getDurationMinutes(): ?int
    {
        return $this->durationMinutes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isPlanned(): bool
    {
        return null === $this->startedAt;
    }

    public function isInProgress(): bool
    {
        return null !== $this->startedAt && null === $this->endedAt;
    }

    public function isFinished(): bool
    {
        return null !== $this->endedAt;
    }
}
