<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Statut d'un incident ; les valeurs sont aussi les places du Workflow « incident ».
 */
enum IncidentStatus: string
{
    case Nouveau = 'NOUVEAU';
    case Qualifie = 'QUALIFIE';
    case Assigne = 'ASSIGNE';
    case EnCours = 'EN_COURS';
    case EnAttente = 'EN_ATTENTE';
    case Resolu = 'RESOLU';
    case Clos = 'CLOS';
    case Annule = 'ANNULE';

    public function label(): string
    {
        return match ($this) {
            self::Nouveau => 'Nouveau',
            self::Qualifie => 'Qualifié',
            self::Assigne => 'Assigné',
            self::EnCours => 'En cours',
            self::EnAttente => 'En attente',
            self::Resolu => 'Résolu',
            self::Clos => 'Clos',
            self::Annule => 'Annulé',
        };
    }

    /** Un incident « ouvert » n'est ni résolu, ni clos, ni annulé. */
    public function isOpen(): bool
    {
        return !\in_array($this, [self::Resolu, self::Clos, self::Annule], true);
    }

    /** Classe Bootstrap du badge de statut. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Nouveau => 'text-bg-info',
            self::Qualifie => 'text-bg-primary',
            self::Assigne => 'text-bg-secondary',
            self::EnCours => 'text-bg-warning',
            self::EnAttente => 'text-bg-light border',
            self::Resolu => 'text-bg-success',
            self::Clos => 'text-bg-dark',
            self::Annule => 'text-bg-light border text-decoration-line-through',
        };
    }

    /**
     * @return list<self>
     */
    public static function openStatuses(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $s): bool => $s->isOpen()));
    }
}
