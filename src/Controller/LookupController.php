<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Site;
use App\Repository\SiteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/** Point d'accès JSON léger pour la sélection dépendante région → sites (jQuery). */
final class LookupController extends AbstractController
{
    #[Route('/ajax/sites', name: 'app_ajax_sites', methods: ['GET'])]
    public function sites(SiteRepository $sites, #[MapQueryParameter] ?string $region = null): JsonResponse
    {
        $items = array_map(static fn (Site $s): array => [
            'id' => $s->getId(),
            'code' => $s->getCode(),
            'label' => $s->getLabel(),
            'region' => $s->getRegion(),
        ], $sites->findActive($region));

        return $this->json($items);
    }
}
