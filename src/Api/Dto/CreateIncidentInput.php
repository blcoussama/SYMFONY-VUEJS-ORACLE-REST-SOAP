<?php

declare(strict_types=1);

namespace App\Api\Dto;

use App\Entity\Incident;
use App\Enum\IncidentCategory;
use App\Enum\IncidentPriority;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de POST /api/v1/incidents (déclaration par un partenaire ou par l'application).
 */
final class CreateIncidentInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Le titre est obligatoire.')]
        #[Assert\Length(min: 5, max: Incident::TITLE_MAX_LENGTH)]
        public ?string $title = null,

        #[Assert\NotBlank(message: 'La description est obligatoire.')]
        #[Assert\Length(min: 10, max: 4000)]
        public ?string $description = null,

        #[Assert\NotBlank(message: 'Le code du site est obligatoire.')]
        #[Assert\Length(max: 20)]
        public ?string $siteCode = null,

        #[Assert\NotBlank(message: 'La catégorie est obligatoire.')]
        #[Assert\Choice(callback: [self::class, 'categories'], message: 'Catégorie inconnue. Valeurs possibles : {{ choices }}.')]
        public ?string $category = null,

        /** Priorité suggérée par le partenaire ; le superviseur la confirme en qualifiant. */
        #[Assert\Choice(callback: [self::class, 'priorities'], message: 'Priorité inconnue. Valeurs possibles : {{ choices }}.')]
        public ?string $priority = null,
    ) {
    }

    /** @return list<string> */
    public static function categories(): array
    {
        return array_map(static fn (IncidentCategory $c): string => $c->value, IncidentCategory::cases());
    }

    /** @return list<string> */
    public static function priorities(): array
    {
        return array_map(static fn (IncidentPriority $p): string => $p->value, IncidentPriority::cases());
    }
}
