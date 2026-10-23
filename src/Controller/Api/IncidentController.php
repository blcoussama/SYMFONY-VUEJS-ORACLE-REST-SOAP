<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\Dto\CreateIncidentInput;
use App\Api\Dto\IncidentListQuery;
use App\Api\Dto\TransitionInput;
use App\Api\Normalizer\IncidentNormalizer;
use App\Dto\IncidentDraft;
use App\Dto\IncidentFilter;
use App\Entity\Incident;
use App\Enum\IncidentCategory;
use App\Enum\IncidentPriority;
use App\Exception\BusinessRuleException;
use App\Repository\IncidentRepository;
use App\Repository\SiteRepository;
use App\Repository\UserRepository;
use App\Security\Voter\IncidentVoter;
use App\Service\ActorResolver;
use App\Service\IncidentManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API REST des incidents (SPEC §5). Erreurs au format RFC 7807 : App\EventSubscriber\ApiExceptionSubscriber.
 */
#[Route('/api/v1/incidents', name: 'api_incidents_')]
final class IncidentController extends AbstractApiController
{
    private const REFERENCE = 'INC-\d{4}-\d{6}';

    public function __construct(
        private readonly IncidentRepository $incidents,
        private readonly IncidentManager $manager,
        private readonly ActorResolver $actorResolver,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(#[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] IncidentListQuery $query = new IncidentListQuery()): JsonResponse
    {
        $filter = new IncidentFilter();
        $filter->statuses = $query->statuses();
        $filter->priority = null !== $query->priority ? IncidentPriority::from($query->priority) : null;
        $filter->siteCode = $query->site;
        $filter->page = $query->page;
        $filter->perPage = $query->limit;

        $page = $this->incidents->search($filter, $this->clock->now());

        return $this->json([
            'items' => $page->items,
            'page' => $page->page,
            'limit' => $page->perPage,
            'total' => $page->total,
            'pages' => $page->pageCount(),
        ]);
    }

    #[Route('/{reference}', name: 'show', requirements: ['reference' => self::REFERENCE], methods: ['GET'])]
    public function show(string $reference): JsonResponse
    {
        $incident = $this->findIncident($reference);
        $this->denyAccessUnlessGranted(IncidentVoter::VIEW, $incident);

        return $this->incidentResponse($incident);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(#[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] CreateIncidentInput $input, SiteRepository $sites): JsonResponse
    {
        $site = $sites->findOneActiveByCode((string) $input->siteCode);
        if (null === $site) {
            throw new BusinessRuleException(\sprintf('Site inconnu ou inactif : « %s ».', $input->siteCode), 'siteCode');
        }

        $draft = new IncidentDraft();
        $draft->title = $input->title;
        $draft->description = $input->description;
        $draft->site = $site;
        $draft->category = IncidentCategory::from((string) $input->category);
        $draft->priority = null !== $input->priority ? IncidentPriority::from($input->priority) : IncidentPriority::P3;

        $incident = $this->manager->declare($draft, $this->actorResolver->current());

        $response = $this->incidentResponse($incident, Response::HTTP_CREATED);
        $response->headers->set('Location', $this->generateUrl('api_incidents_show', ['reference' => $incident->getReference()]));

        return $response;
    }

    /**
     * Applique une transition du Workflow. En-tête optionnel « If-Match: "<version>" » (verrou optimiste).
     */
    #[Route('/{reference}/transitions/{transition}', name: 'transition', requirements: ['reference' => self::REFERENCE, 'transition' => '[a-z_]+'], methods: ['POST'])]
    public function transition(
        string $reference,
        string $transition,
        Request $request,
        UserRepository $users,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ?TransitionInput $input = null,
    ): JsonResponse {
        $incident = $this->findIncident($reference);
        $this->denyAccessUnlessGranted(IncidentVoter::VIEW, $incident);
        $input ??= new TransitionInput();

        $assignee = null;
        if (null !== $input->assignee && '' !== $input->assignee) {
            $assignee = $users->findActiveTechnicianByEmail($input->assignee)
                ?? throw new BusinessRuleException(\sprintf('Aucun technicien actif avec l’adresse « %s ».', $input->assignee), 'assignee');
        }

        $this->manager->applyTransition(
            $incident,
            $transition,
            $this->actorResolver->current(),
            $input->comment,
            self::versionFromIfMatch($request),
            [
                'report' => $input->report,
                'assignee' => $assignee,
                'priority' => null !== $input->priority ? IncidentPriority::from($input->priority) : null,
            ],
        );

        return $this->incidentResponse($incident);
    }

    private function findIncident(string $reference): Incident
    {
        return $this->incidents->findOneByReference($reference)
            ?? throw $this->createNotFoundException(\sprintf('Incident « %s » introuvable.', $reference));
    }

    private function incidentResponse(Incident $incident, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = $this->json($incident, $status, [], [IncidentNormalizer::DETAIL => true]);
        $response->setEtag((string) $incident->getVersion());

        return $response;
    }

    /** « If-Match: "3" » ou « If-Match: 3 » → 3 ; absent ou « * » → null. */
    public static function versionFromIfMatch(Request $request): ?int
    {
        $header = trim((string) $request->headers->get('If-Match', ''));
        if ('' === $header || '*' === $header) {
            return null;
        }
        $value = trim(str_replace('W/', '', $header), '"');

        return ctype_digit($value) ? (int) $value : -1;
    }
}
