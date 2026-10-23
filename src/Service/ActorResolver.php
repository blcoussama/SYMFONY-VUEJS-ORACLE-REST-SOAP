<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiClient;
use App\Entity\User;
use App\Enum\IncidentSource;
use Symfony\Bundle\SecurityBundle\Security;

/** Construit l'Actor correspondant à l'utilisateur (ou au partenaire) authentifié. */
final class ActorResolver
{
    public function __construct(private readonly Security $security)
    {
    }

    public function current(): Actor
    {
        $principal = $this->security->getUser();

        return match (true) {
            $principal instanceof User => Actor::user($principal),
            $principal instanceof ApiClient => Actor::client($principal, IncidentSource::Rest),
            default => Actor::system(),
        };
    }
}
