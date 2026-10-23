<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SiteType;
use App\Repository\SiteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/** Site du réseau (antenne, central, NRO fibre, datacenter). */
#[ORM\Entity(repositoryClass: SiteRepository::class)]
#[ORM\Table(name: 'nop_site')]
#[ORM\UniqueConstraint(name: 'uniq_nop_site_code', columns: ['code'])]
#[ORM\Index(name: 'idx_nop_site_region', columns: ['region'])]
#[UniqueEntity(fields: ['code'], message: 'Ce code de site existe déjà.')]
class Site
{
    public const CODE_PATTERN = '/^[A-Z]{3}-\d{4}$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Code lisible, ex. « TNG-0042 » (3 lettres de ville, 4 chiffres). */
    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: self::CODE_PATTERN, message: 'Format attendu : 3 lettres majuscules, un tiret, 4 chiffres (ex. TNG-0042).')]
    private string $code = '';

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    private string $name = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $city = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $region = '';

    #[ORM\Column(name: 'site_type', length: 20, enumType: SiteType::class)]
    private SiteType $type = SiteType::Antenne4G;

    #[ORM\Column(name: 'is_active')]
    private bool $active = true;

    public function __construct(string $code = '', string $name = '', string $city = '', string $region = '', SiteType $type = SiteType::Antenne4G)
    {
        $this->code = strtoupper(trim($code));
        $this->name = $name;
        $this->city = $city;
        $this->region = $region;
        $this->type = $type;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = strtoupper(trim($code));

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function setCity(string $city): static
    {
        $this->city = trim($city);

        return $this;
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    public function setRegion(string $region): static
    {
        $this->region = trim($region);

        return $this;
    }

    public function getType(): SiteType
    {
        return $this->type;
    }

    public function setType(SiteType $type): static
    {
        $this->type = $type;

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

    public function getLabel(): string
    {
        return $this->code.' — '.$this->name;
    }

    public function __toString(): string
    {
        return $this->getLabel();
    }
}
