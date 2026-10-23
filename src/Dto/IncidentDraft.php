<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Incident;
use App\Entity\Site;
use App\Enum\IncidentCategory;
use App\Enum\IncidentPriority;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données de déclaration d'un incident (formulaire web ; l'API et le SOAP s'y ramènent).
 */
final class IncidentDraft
{
    #[Assert\NotBlank(message: 'Le titre est obligatoire.')]
    #[Assert\Length(min: 5, max: Incident::TITLE_MAX_LENGTH)]
    public ?string $title = null;

    #[Assert\NotBlank(message: 'La description est obligatoire.')]
    #[Assert\Length(min: 10, max: 4000)]
    public ?string $description = null;

    #[Assert\NotNull(message: 'Choisissez un site.')]
    public ?Site $site = null;

    #[Assert\NotNull(message: 'Choisissez une catégorie.')]
    public ?IncidentCategory $category = null;

    #[Assert\NotNull(message: 'Choisissez une priorité.')]
    public ?IncidentPriority $priority = IncidentPriority::P3;

    /** Région choisie dans le formulaire pour filtrer les sites (non persistée). */
    public ?string $region = null;
}
