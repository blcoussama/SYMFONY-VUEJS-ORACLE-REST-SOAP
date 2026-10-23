<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\IncidentCategory;
use App\Enum\IncidentPriority;
use App\Enum\IncidentSource;
use App\Enum\IncidentStatus;
use App\Repository\IncidentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Incident réseau. Le statut n'est modifié que par le Workflow « incident »
 * (config/packages/workflow.yaml) ; la colonne lock_version porte le verrou optimiste.
 */
#[ORM\Entity(repositoryClass: IncidentRepository::class)]
#[ORM\Table(name: 'nop_incident')]
#[ORM\UniqueConstraint(name: 'uniq_nop_incident_ref', columns: ['reference'])]
#[ORM\Index(name: 'idx_nop_incident_status', columns: ['status'])]
#[ORM\Index(name: 'idx_nop_incident_prio', columns: ['priority'])]
#[ORM\Index(name: 'idx_nop_incident_due', columns: ['sla_due_at'])]
#[ORM\Index(name: 'idx_nop_incident_created', columns: ['created_at'])]
class Incident
{
    public const TITLE_MAX_LENGTH = 150;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Référence lisible INC-AAAA-NNNNNN. */
    #[ORM\Column(length: 20)]
    private string $reference;

    #[ORM\Column(length: self::TITLE_MAX_LENGTH)]
    private string $title;

    /** CLOB sur Oracle, TEXT ailleurs : jamais indexé ni utilisé dans un GROUP BY / DISTINCT. */
    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'site_id', nullable: false)]
    private Site $site;

    #[ORM\Column(length: 2, enumType: IncidentPriority::class)]
    private IncidentPriority $priority;

    #[ORM\Column(length: 20, enumType: IncidentStatus::class)]
    private IncidentStatus $status = IncidentStatus::Nouveau;

    #[ORM\Column(length: 20, enumType: IncidentCategory::class)]
    private IncidentCategory $category;

    #[ORM\Column(name: 'source_channel', length: 10, enumType: IncidentSource::class)]
    private IncidentSource $source;

    /** Déclarant humain (null si l'incident vient d'un partenaire). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'reporter_id', nullable: true)]
    private ?User $reporter = null;

    /** Partenaire déclarant (API REST ou SOAP). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'api_client_id', nullable: true)]
    private ?ApiClient $apiClient = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assignee_id', nullable: true)]
    private ?User $assignee = null;

    /** Échéance SLA = création + délai de la priorité (App\Service\Sla\SlaPolicy). */
    #[ORM\Column(name: 'sla_due_at')]
    private \DateTimeImmutable $slaDueAt;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'resolved_at', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(name: 'closed_at', nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Version]
    #[ORM\Column(name: 'lock_version', type: Types::INTEGER)]
    private int $version = 1;

    /** @var Collection<int, Intervention> */
    #[ORM\OneToMany(targetEntity: Intervention::class, mappedBy: 'incident', cascade: ['persist'])]
    #[ORM\OrderBy(['plannedAt' => \SortDirection::Ascending, 'id' => \SortDirection::Ascending])]
    private Collection $interventions;

    /** @var Collection<int, IncidentEvent> */
    #[ORM\OneToMany(targetEntity: IncidentEvent::class, mappedBy: 'incident', cascade: ['persist'])]
    #[ORM\OrderBy(['createdAt' => \SortDirection::Ascending, 'id' => \SortDirection::Ascending])]
    private Collection $events;

    public function __construct(
        string $reference,
        string $title,
        string $description,
        Site $site,
        IncidentCategory $category,
        IncidentPriority $priority,
        IncidentSource $source,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $slaDueAt,
    ) {
        $this->reference = $reference;
        $this->title = trim($title);
        $this->description = trim($description);
        $this->site = $site;
        $this->category = $category;
        $this->priority = $priority;
        $this->source = $source;
        $this->createdAt = $createdAt;
        $this->updatedAt = $createdAt;
        $this->slaDueAt = $slaDueAt;
        $this->interventions = new ArrayCollection();
        $this->events = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getSite(): Site
    {
        return $this->site;
    }

    public function getPriority(): IncidentPriority
    {
        return $this->priority;
    }

    /**
     * Change la priorité et recalcule l'échéance SLA depuis la date de création.
     */
    public function reprioritize(IncidentPriority $priority, \DateTimeImmutable $slaDueAt, \DateTimeImmutable $at): void
    {
        $this->priority = $priority;
        $this->slaDueAt = $slaDueAt;
        $this->touch($at);
    }

    public function getStatus(): IncidentStatus
    {
        return $this->status;
    }

    /**
     * Réservé au marking store du Workflow (ne pas appeler directement).
     *
     * @param array<string, mixed> $context
     */
    public function setStatus(IncidentStatus $status, array $context = []): void
    {
        $this->status = $status;
    }

    public function getCategory(): IncidentCategory
    {
        return $this->category;
    }

    public function getSource(): IncidentSource
    {
        return $this->source;
    }

    public function getReporter(): ?User
    {
        return $this->reporter;
    }

    public function getApiClient(): ?ApiClient
    {
        return $this->apiClient;
    }

    public function declaredBy(?User $reporter, ?ApiClient $apiClient): void
    {
        $this->reporter = $reporter;
        $this->apiClient = $apiClient;
    }

    /** Libellé du déclarant pour l'affichage. */
    public function getReporterLabel(): string
    {
        if (null !== $this->reporter) {
            return $this->reporter->getFullName();
        }

        return null !== $this->apiClient ? 'Partenaire : '.$this->apiClient->getName() : 'Système';
    }

    public function getAssignee(): ?User
    {
        return $this->assignee;
    }

    public function assignTo(?User $technician, \DateTimeImmutable $at): void
    {
        $this->assignee = $technician;
        $this->touch($at);
    }

    public function isAssignedTo(?object $user): bool
    {
        return $user instanceof User && null !== $this->assignee
            && (
                $this->assignee === $user
                || (null !== $user->getId() && $this->assignee->getId() === $user->getId())
            );
    }

    public function getSlaDueAt(): \DateTimeImmutable
    {
        return $this->slaDueAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(\DateTimeImmutable $at): void
    {
        $this->updatedAt = $at;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function markResolved(\DateTimeImmutable $at): void
    {
        $this->resolvedAt = $at;
        $this->touch($at);
    }

    public function markReopened(\DateTimeImmutable $at): void
    {
        $this->resolvedAt = null;
        $this->touch($at);
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function markClosed(\DateTimeImmutable $at): void
    {
        $this->closedAt = $at;
        $this->touch($at);
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /** « En dépassement » : toujours ouvert après l'échéance SLA. */
    public function isSlaBreached(\DateTimeImmutable $now): bool
    {
        return $this->isOpen() && $now > $this->slaDueAt;
    }

    /**
     * @return Collection<int, Intervention>
     */
    public function getInterventions(): Collection
    {
        return $this->interventions;
    }

    public function addIntervention(Intervention $intervention): void
    {
        if (!$this->interventions->contains($intervention)) {
            $this->interventions->add($intervention);
        }
    }

    /** Intervention démarrée et non terminée du technicien donné, s'il y en a une. */
    public function findOpenInterventionOf(User $technician): ?Intervention
    {
        foreach ($this->interventions as $intervention) {
            if ($intervention->isInProgress() && $intervention->getTechnician() === $technician) {
                return $intervention;
            }
        }

        return null;
    }

    /** Garde « résoudre » : au moins une intervention terminée avec un compte rendu. */
    public function hasCompletedInterventionReport(): bool
    {
        foreach ($this->interventions as $intervention) {
            if ($intervention->isFinished() && '' !== trim((string) $intervention->getReport())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, IncidentEvent>
     */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    public function addEvent(IncidentEvent $event): void
    {
        if (!$this->events->contains($event)) {
            $this->events->add($event);
        }
    }
}
