<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\SiteRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class SiteController extends AbstractApiController
{
    /** Sites actifs, éventuellement filtrés par région. */
    #[Route('/api/v1/sites', name: 'api_sites_list', methods: ['GET'])]
    public function list(SiteRepository $sites, #[MapQueryParameter] ?string $region = null): JsonResponse
    {
        $items = $sites->findActive($region);

        return $this->json(['items' => $items, 'total' => \count($items)]);
    }
}
