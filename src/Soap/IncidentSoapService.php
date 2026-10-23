<?php

declare(strict_types=1);

namespace App\Soap;

use App\Api\Dto\CreateIncidentInput;
use App\Api\Normalizer\IncidentNormalizer;
use App\Dto\IncidentDraft;
use App\Entity\ApiClient;
use App\Entity\Incident;
use App\Enum\IncidentCategory;
use App\Enum\IncidentPriority;
use App\Enum\IncidentSource;
use App\Repository\IncidentRepository;
use App\Repository\SiteRepository;
use App\Security\ApiClientResolver;
use App\Service\Actor;
use App\Service\IncidentManager;
use App\Service\Reference\IncidentReference;
use Psr\Clock\ClockInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Opérations du service SOAP (SPEC §6), indépendantes du protocole :
 * reçoivent des scalaires, renvoient des tableaux conformes aux types du WSDL.
 */
final class IncidentSoapService
{
    public function __construct(
        private readonly ApiClientResolver $clients,
        private readonly IncidentRepository $incidents,
        private readonly SiteRepository $sites,
        private readonly IncidentManager $manager,
        private readonly ValidatorInterface $validator,
        private readonly ClockInterface $clock,
    ) {
    }

    public function authenticate(?string $apiKey): ApiClient
    {
        return $this->clients->resolve($apiKey)
            ?? throw new SoapServiceException(SoapServiceException::UNAUTHORIZED, 'Clé d’API absente, invalide ou révoquée.');
    }

    /**
     * @return array<string, string|bool>
     */
    public function getIncidentStatus(ApiClient $client, string $reference): array
    {
        $reference = strtoupper(trim($reference));
        if (!IncidentReference::isValid($reference)) {
            throw new SoapServiceException(SoapServiceException::VALIDATION, 'Référence invalide (format attendu : INC-AAAA-NNNNNN).');
        }
        $incident = $this->incidents->findOneByReference($reference)
            ?? throw new SoapServiceException(SoapServiceException::NOT_FOUND, \sprintf('Incident « %s » introuvable.', $reference));

        return $this->toIncidentInfo($incident);
    }

    public function createIncident(ApiClient $client, string $siteCode, string $title, string $description, string $category, string $priority): string
    {
        $input = new CreateIncidentInput(trim($title), trim($description), trim($siteCode), strtoupper(trim($category)), strtoupper(trim($priority)));
        $violations = $this->validator->validate($input);
        if (\count($violations) > 0) {
            $messages = [];
            foreach ($violations as $violation) {
                $messages[] = $violation->getPropertyPath().' : '.$violation->getMessage();
            }
            throw new SoapServiceException(SoapServiceException::VALIDATION, implode(' ; ', $messages));
        }

        $site = $this->sites->findOneActiveByCode((string) $input->siteCode)
            ?? throw new SoapServiceException(SoapServiceException::VALIDATION, \sprintf('siteCode : site inconnu ou inactif « %s ».', $input->siteCode));

        $draft = new IncidentDraft();
        $draft->title = $input->title;
        $draft->description = $input->description;
        $draft->site = $site;
        $draft->category = IncidentCategory::from((string) $input->category);
        $draft->priority = IncidentPriority::from((string) $input->priority);

        return $this->manager->declare($draft, Actor::client($client, IncidentSource::Soap))->getReference();
    }

    /**
     * @return list<array<string, string|bool>>
     */
    public function listOpenIncidentsBySite(ApiClient $client, string $siteCode): array
    {
        $site = $this->sites->findOneBy(['code' => strtoupper(trim($siteCode))])
            ?? throw new SoapServiceException(SoapServiceException::NOT_FOUND, \sprintf('Site « %s » introuvable.', $siteCode));

        return array_map($this->toIncidentInfo(...), $this->incidents->findOpenBySite($site));
    }

    /**
     * @return array<string, string|bool>
     */
    private function toIncidentInfo(Incident $incident): array
    {
        $info = [
            'reference' => $incident->getReference(),
            'title' => $incident->getTitle(),
            'status' => $incident->getStatus()->value,
            'priority' => $incident->getPriority()->value,
            'siteCode' => $incident->getSite()->getCode(),
            'slaDueAt' => (string) IncidentNormalizer::date($incident->getSlaDueAt()),
            'slaBreached' => $incident->isSlaBreached($this->clock->now()),
        ];
        if (null !== $incident->getAssignee()) {
            $info['technician'] = $incident->getAssignee()->getFullName();
        }

        return $info;
    }
}
