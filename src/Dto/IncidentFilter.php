<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Site;
use App\Entity\User;
use App\Enum\IncidentPriority;
use App\Enum\IncidentStatus;

/**
 * Critères de recherche de la liste des incidents (page web et API REST).
 */
final class IncidentFilter
{
    public const SORTS = ['createdAt', 'slaDueAt', 'priority', 'reference', 'status', 'updatedAt'];
    public const DEFAULT_PER_PAGE = 15;

    /** @var list<IncidentStatus> */
    public array $statuses = [];
    public ?IncidentPriority $priority = null;
    public ?string $region = null;
    public ?Site $site = null;
    public ?string $siteCode = null;
    public ?User $assignee = null;
    /** Uniquement les incidents ouverts dont l'échéance SLA est dépassée. */
    public bool $breachedOnly = false;
    /** Recherche plein texte simple (référence, titre, code ou nom de site). */
    public ?string $query = null;
    public string $sort = 'createdAt';
    public string $direction = 'desc';
    public int $page = 1;
    public int $perPage = self::DEFAULT_PER_PAGE;

    public function normalizedSort(): string
    {
        return \in_array($this->sort, self::SORTS, true) ? $this->sort : 'createdAt';
    }

    public function normalizedDirection(): string
    {
        return 'asc' === strtolower($this->direction) ? 'ASC' : 'DESC';
    }
}
