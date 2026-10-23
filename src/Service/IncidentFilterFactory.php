<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\IncidentFilter;
use App\Enum\IncidentPriority;
use App\Enum\IncidentStatus;
use App\Repository\SiteRepository;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Construit les critères de la liste des incidents à partir de la requête (formulaire GET),
 * en ignorant silencieusement les valeurs inconnues.
 */
final class IncidentFilterFactory
{
    /** Valeur spéciale du filtre de statut : tous les statuts ouverts. */
    public const OPEN = 'OUVERTS';

    public function __construct(
        private readonly SiteRepository $sites,
        private readonly UserRepository $users,
    ) {
    }

    public function fromRequest(Request $request): IncidentFilter
    {
        $q = $request->query;
        $filter = new IncidentFilter();

        $status = strtoupper(trim($q->getString('status')));
        if (self::OPEN === $status) {
            $filter->statuses = IncidentStatus::openStatuses();
        } elseif (null !== $s = IncidentStatus::tryFrom($status)) {
            $filter->statuses = [$s];
        }

        $filter->priority = IncidentPriority::tryFrom(strtoupper(trim($q->getString('priority'))));
        $region = trim($q->getString('region'));
        $filter->region = '' !== $region ? $region : null;

        $siteId = $q->getInt('site');
        if ($siteId > 0) {
            $filter->site = $this->sites->find($siteId);
        }
        $assigneeId = $q->getInt('assignee');
        if ($assigneeId > 0) {
            $filter->assignee = $this->users->find($assigneeId);
        }

        $filter->breachedOnly = $q->getBoolean('breached');
        $query = trim($q->getString('q'));
        $filter->query = '' !== $query ? mb_substr($query, 0, 100) : null;

        $sort = $q->getString('sort');
        $filter->sort = \in_array($sort, IncidentFilter::SORTS, true) ? $sort : 'createdAt';
        $filter->direction = 'asc' === strtolower($q->getString('dir')) ? 'asc' : 'desc';
        $filter->page = max(1, $q->getInt('page', 1));

        return $filter;
    }
}
