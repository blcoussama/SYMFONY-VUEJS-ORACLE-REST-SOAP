<?php

declare(strict_types=1);

namespace App\Api\Dto;

use App\Enum\IncidentStatus;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Paramètres de GET /api/v1/incidents.
 */
final class IncidentListQuery
{
    public function __construct(
        /** Un ou plusieurs statuts séparés par des virgules, ex. « NOUVEAU,QUALIFIE ». */
        public ?string $status = null,

        #[Assert\Choice(callback: [CreateIncidentInput::class, 'priorities'], message: 'Priorité inconnue. Valeurs possibles : {{ choices }}.')]
        public ?string $priority = null,

        /** Code du site, ex. « TNG-0042 ». */
        #[Assert\Length(max: 20)]
        public ?string $site = null,

        #[Assert\Positive]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 100, notInRangeMessage: 'limit doit être compris entre {{ min }} et {{ max }}.')]
        public int $limit = 20,
    ) {
    }

    /**
     * @return list<IncidentStatus>
     */
    public function statuses(): array
    {
        if (null === $this->status || '' === trim($this->status)) {
            return [];
        }

        $statuses = [];
        foreach (explode(',', $this->status) as $value) {
            $status = IncidentStatus::tryFrom(strtoupper(trim($value)));
            if (null !== $status) {
                $statuses[] = $status;
            }
        }

        return $statuses;
    }

    #[Assert\Callback]
    public function validateStatuses(ExecutionContextInterface $context): void
    {
        if (null === $this->status || '' === trim($this->status)) {
            return;
        }
        foreach (explode(',', $this->status) as $value) {
            if (null === IncidentStatus::tryFrom(strtoupper(trim($value)))) {
                $context->buildViolation(\sprintf('Statut inconnu : « %s ».', trim($value)))->atPath('status')->addViolation();
            }
        }
    }
}
