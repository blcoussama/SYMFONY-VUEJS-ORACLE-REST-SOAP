<?php

declare(strict_types=1);

namespace App\Api\Dto;

use App\Entity\IncidentEvent;
use App\Entity\Intervention;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de POST /api/v1/incidents/{reference}/transitions/{transition}.
 */
final class TransitionInput
{
    public function __construct(
        #[Assert\Length(max: IncidentEvent::COMMENT_MAX_LENGTH)]
        public ?string $comment = null,

        /** « assigner » : e-mail du technicien. */
        #[Assert\Email]
        public ?string $assignee = null,

        /** « résoudre » : compte rendu d'intervention, si aucun n'a encore été saisi. */
        #[Assert\Length(max: Intervention::REPORT_MAX_LENGTH)]
        public ?string $report = null,

        /** « qualifier » : priorité confirmée. */
        #[Assert\Choice(callback: [CreateIncidentInput::class, 'priorities'], message: 'Priorité inconnue. Valeurs possibles : {{ choices }}.')]
        public ?string $priority = null,
    ) {
    }
}
