<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ApiClientRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Système partenaire autorisé à appeler les API REST et SOAP.
 *
 * Seule l'empreinte SHA-256 de la clé est stockée : la clé (256 bits aléatoires)
 * n'est affichée qu'une fois, à sa création. Un hachage lent (bcrypt) n'apporte
 * rien pour un secret de cette entropie et coûterait à chaque appel.
 */
#[ORM\Entity(repositoryClass: ApiClientRepository::class)]
#[ORM\Table(name: 'nop_api_client')]
#[ORM\UniqueConstraint(name: 'uniq_nop_api_client_name', columns: ['name'])]
#[ORM\UniqueConstraint(name: 'uniq_nop_api_client_hash', columns: ['key_hash'])]
#[UniqueEntity(fields: ['name'], message: 'Un partenaire porte déjà ce nom.')]
class ApiClient implements UserInterface
{
    public const ROLE = 'ROLE_API_PARTNER';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name;

    /** Début de la clé, affiché pour reconnaître la clé sans la révéler. */
    #[ORM\Column(name: 'key_prefix', length: 16)]
    private string $keyPrefix;

    #[ORM\Column(name: 'key_hash', length: 64)]
    private string $keyHash;

    #[ORM\Column(name: 'is_active')]
    private bool $active = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_used_at', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(name: 'revoked_at', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(string $name, string $keyPrefix, string $keyHash, \DateTimeImmutable $createdAt)
    {
        $this->name = trim($name);
        $this->keyPrefix = $keyPrefix;
        $this->keyHash = $keyHash;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getKeyPrefix(): string
    {
        return $this->keyPrefix;
    }

    public function getKeyHash(): string
    {
        return $this->keyHash;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function revoke(\DateTimeImmutable $at): void
    {
        $this->active = false;
        $this->revokedAt = $at;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(\DateTimeImmutable $at): void
    {
        $this->lastUsedAt = $at;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return [self::ROLE];
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return 'api-client:'.($this->id ?? 0).':'.$this->name;
    }

    /** Aucune donnée sensible en clair à effacer (méthode retirée de l'interface en Symfony 8). */
    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
