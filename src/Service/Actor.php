<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiClient;
use App\Entity\User;
use App\Enum\IncidentSource;

/**
 * Auteur d'une action : utilisateur connecté, partenaire API (REST ou SOAP) ou système.
 */
final readonly class Actor
{
    private function __construct(
        public ?User $user,
        public ?ApiClient $client,
        public IncidentSource $channel,
    ) {
    }

    public static function user(User $user): self
    {
        return new self($user, null, IncidentSource::Web);
    }

    public static function client(ApiClient $client, IncidentSource $channel): self
    {
        return new self(null, $client, $channel);
    }

    public static function system(): self
    {
        return new self(null, null, IncidentSource::Web);
    }

    public function label(): string
    {
        if (null !== $this->user) {
            return $this->user->getFullName();
        }
        if (null !== $this->client) {
            return $this->client->getName().' ('.$this->channel->label().')';
        }

        return 'Système';
    }
}
