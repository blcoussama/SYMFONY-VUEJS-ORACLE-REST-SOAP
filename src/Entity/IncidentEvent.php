<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\IncidentEventType;
use App\Enum\IncidentStatus;
use App\Repository\IncidentEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entrée du journal d'un incident. Immuable : aucun setter, et l'entité est en
 * lecture seule pour Doctrine (jamais mise à jour après insertion).
 */
#[ORM\Entity(repositoryClass: IncidentEventRepository::class, readOnly: true)]
#[ORM\Table(name: 'nop_incident_event')]
#[ORM\Index(name: 'idx_nop_event_created', columns: ['created_at'])]
class IncidentEvent
{
    public const COMMENT_MAX_LENGTH = 1000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'events')]
    #[ORM\JoinColumn(name: 'incident_id', nullable: false, onDelete: 'CASCADE')]
    private Incident $incident;

    #[ORM\Column(name: 'event_type', length: 20, enumType: IncidentEventType::class)]
    private IncidentEventType $type;

    #[ORM\Column(name: 'from_status', length: 20, nullable: true, enumType: IncidentStatus::class)]
    private ?IncidentStatus $fromStatus;

    #[ORM\Column(name: 'to_status', length: 20, nullable: true, enumType: IncidentStatus::class)]
    private ?IncidentStatus $toStatus;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'author_id', nullable: true)]
    private ?User $author;

    /** Libellé figé de l'auteur (utilisateur ou partenaire API), conservé même si le compte change. */
    #[ORM\Column(name: 'author_label', length: 150)]
    private string $authorLabel;

    /** « comment » est un mot réservé Oracle. */
    #[ORM\Column(name: 'comment_text', length: 4000, nullable: true)]
    private ?string $comment;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Incident $incident,
        IncidentEventType $type,
        string $authorLabel,
        \DateTimeImmutable $createdAt,
        ?User $author = null,
        ?IncidentStatus $fromStatus = null,
        ?IncidentStatus $toStatus = null,
        ?string $comment = null,
    ) {
        $comment = null !== $comment ? trim($comment) : null;
        $this->incident = $incident;
        $this->type = $type;
        $this->authorLabel = '' !== trim($authorLabel) ? mb_substr(trim($authorLabel), 0, 150) : 'Système';
        $this->createdAt = $createdAt;
        $this->author = $author;
        $this->fromStatus = $fromStatus;
        $this->toStatus = $toStatus;
        // Oracle confond chaîne vide et NULL : on stocke NULL explicitement.
        $this->comment = '' === $comment ? null : $comment;
        $incident->addEvent($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIncident(): Incident
    {
        return $this->incident;
    }

    public function getType(): IncidentEventType
    {
        return $this->type;
    }

    public function getFromStatus(): ?IncidentStatus
    {
        return $this->fromStatus;
    }

    public function getToStatus(): ?IncidentStatus
    {
        return $this->toStatus;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getAuthorLabel(): string
    {
        return $this->authorLabel;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
