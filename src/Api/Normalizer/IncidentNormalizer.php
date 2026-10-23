<?php

declare(strict_types=1);

namespace App\Api\Normalizer;

use App\Entity\Incident;
use App\Entity\IncidentEvent;
use App\Entity\Intervention;
use App\Service\Sla\SlaPolicy;
use App\Workflow\IncidentActions;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Représentation JSON d'un incident (contrat de l'API REST, voir docs/openapi.yaml).
 * Contexte « detail » : ajoute le journal et les interventions.
 */
final class IncidentNormalizer implements NormalizerInterface
{
    public const DETAIL = 'netops_detail';

    public function __construct(
        private readonly SlaPolicy $slaPolicy,
        private readonly ClockInterface $clock,
        private readonly IncidentActions $actions,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof Incident);
        $sla = $this->slaPolicy->assessIncident($data, $this->clock->now());
        $assignee = $data->getAssignee();

        $result = [
            'reference' => $data->getReference(),
            'title' => $data->getTitle(),
            'description' => $data->getDescription(),
            'status' => $data->getStatus()->value,
            'statusLabel' => $data->getStatus()->label(),
            'priority' => $data->getPriority()->value,
            'category' => $data->getCategory()->value,
            'source' => $data->getSource()->value,
            'site' => SiteNormalizer::toArray($data->getSite()),
            'reporter' => $data->getReporterLabel(),
            'assignee' => null === $assignee ? null : ['email' => $assignee->getEmail(), 'name' => $assignee->getFullName()],
            'sla' => [
                'dueAt' => self::date($sla->dueAt),
                'state' => $sla->state->value,
                'breached' => $sla->isBreached(),
                'label' => $sla->label(),
            ],
            'createdAt' => self::date($data->getCreatedAt()),
            'updatedAt' => self::date($data->getUpdatedAt()),
            'resolvedAt' => self::date($data->getResolvedAt()),
            'closedAt' => self::date($data->getClosedAt()),
            'version' => $data->getVersion(),
            'allowedTransitions' => $this->actions->available($data),
            '_links' => [
                'self' => $this->urlGenerator->generate('api_incidents_show', ['reference' => $data->getReference()]),
                'html' => $this->urlGenerator->generate('app_incident_show', ['reference' => $data->getReference()]),
            ],
        ];

        if (true === ($context[self::DETAIL] ?? false)) {
            $result['interventions'] = array_values(array_map(static fn (Intervention $i): array => [
                'technician' => $i->getTechnician()->getFullName(),
                'plannedAt' => self::date($i->getPlannedAt()),
                'startedAt' => self::date($i->getStartedAt()),
                'endedAt' => self::date($i->getEndedAt()),
                'durationMinutes' => $i->getDurationMinutes(),
                'report' => $i->getReport(),
            ], $data->getInterventions()->toArray()));
            $result['events'] = array_values(array_map(static fn (IncidentEvent $e): array => [
                'type' => $e->getType()->value,
                'from' => $e->getFromStatus()?->value,
                'to' => $e->getToStatus()?->value,
                'author' => $e->getAuthorLabel(),
                'comment' => $e->getComment(),
                'at' => self::date($e->getCreatedAt()),
            ], $data->getEvents()->toArray()));
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Incident;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Incident::class => true];
    }

    public static function date(?\DateTimeImmutable $date): ?string
    {
        return $date?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
