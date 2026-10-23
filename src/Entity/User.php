<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Utilisateur de l'application (agent NOC, technicien, superviseur, administrateur).
 *
 * Table « nop_user » : USER est un mot réservé Oracle, d'où le préfixe commun « nop_ ».
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'nop_user')]
#[ORM\UniqueConstraint(name: 'uniq_nop_user_email', columns: ['email'])]
#[ORM\Index(name: 'idx_nop_user_role', columns: ['role_code'])]
#[UniqueEntity(fields: ['email'], message: 'Cette adresse e-mail est déjà utilisée.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email = '';

    #[ORM\Column(name: 'first_name', length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $firstName = '';

    #[ORM\Column(name: 'last_name', length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $lastName = '';

    /** Mot de passe haché (algorithme « auto » : bcrypt ou argon2id). */
    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $password = '';

    #[ORM\Column(name: 'role_code', length: 30, enumType: UserRole::class)]
    private UserRole $role = UserRole::Agent;

    #[ORM\Column(name: 'is_active')]
    private bool $active = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email = '', string $firstName = '', string $lastName = '', UserRole $role = UserRole::Agent, ?\DateTimeImmutable $createdAt = null)
    {
        $this->email = mb_strtolower(trim($email));
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->role = $role;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = trim($firstName);

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = trim($lastName);

        return $this;
    }

    public function getFullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        if ('' === $this->email) {
            throw new \LogicException('Un utilisateur sans e-mail ne peut pas être authentifié.');
        }

        return $this->email;
    }

    /**
     * Rôle principal ; la hiérarchie (security.yaml) ajoute les rôles hérités.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return [$this->role->value];
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function setRole(UserRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): static
    {
        $this->password = $hashedPassword;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Aucune donnée sensible en clair à effacer (méthode retirée de l'interface en Symfony 8). */
    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }

    /**
     * Ne sérialise en session qu'une empreinte du hachage (recommandation Symfony 7.3+).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
    }

    public function __toString(): string
    {
        return $this->getFullName();
    }
}
