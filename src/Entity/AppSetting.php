<?php

namespace App\Entity;

use App\Repository\AppSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réglage de la boutique, rangé par nom (« public » = paramètres de GET /settings/public).
 * Modifiable dans l'administration (P8).
 */
#[ORM\Entity(repositoryClass: AppSettingRepository::class)]
#[ORM\Table(name: 'app_settings')]
class AppSetting
{
    public const PUBLIC = 'public';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 60)]
    private string $name;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $value = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** @param array<string, mixed> $value */
    public function __construct(string $name, array $value = [])
    {
        $this->name = $name;
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getName(): string { return $this->name; }

    /** @return array<string, mixed> */
    public function getValue(): array { return $this->value; }

    /** @param array<string, mixed> $v */
    public function setValue(array $v): static
    {
        $this->value = $v;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
