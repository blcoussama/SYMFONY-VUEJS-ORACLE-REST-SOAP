<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\IncidentStatus;
use App\Report\DashboardStatistics;
use App\Report\NativeSqlReports;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class DashboardController extends AbstractController
{
    #[Route('/tableau-de-bord', name: 'app_dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_SUPERVISEUR')]
    public function __invoke(DashboardStatistics $statistics, NativeSqlReports $reports, ClockInterface $clock): Response
    {
        $now = $clock->now();
        $since = $now->modify('-30 days');

        return $this->render('dashboard/index.html.twig', [
            'open_by_priority' => $statistics->openByPriority(),
            'by_status' => $statistics->countByStatus(),
            'statuses' => IncidentStatus::cases(),
            'breached' => $statistics->breachedOpenCount($now),
            'compliance' => $statistics->slaCompliance($since),
            'trend' => $statistics->trend($now, 30),
            'top_sites' => $reports->topSites($since, 5),
            'top_sites_sql' => $reports->topSitesSql(5),
            'resolution' => $reports->resolutionTime($since),
            'resolution_sql' => $reports->resolutionTimeSql(),
        ]);
    }
}
