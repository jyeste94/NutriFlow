<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'measurements')]
class Measurement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(type: 'float')]
    private float $weightKg = 0.0;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $bodyFatPct = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $chestCm = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $waistCm = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $hipsCm = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $armCm = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $thighCm = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $calfCm = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function __construct()
    {
        $this->date = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    public function getDate(): ?\DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $date): static { $this->date = $date; return $this; }
    public function getWeightKg(): float { return $this->weightKg; }
    public function setWeightKg(float $weightKg): static { $this->weightKg = $weightKg; return $this; }
    public function getBodyFatPct(): ?float { return $this->bodyFatPct; }
    public function setBodyFatPct(?float $bodyFatPct): static { $this->bodyFatPct = $bodyFatPct; return $this; }
    public function getChestCm(): ?float { return $this->chestCm; }
    public function setChestCm(?float $chestCm): static { $this->chestCm = $chestCm; return $this; }
    public function getWaistCm(): ?float { return $this->waistCm; }
    public function setWaistCm(?float $waistCm): static { $this->waistCm = $waistCm; return $this; }
    public function getHipsCm(): ?float { return $this->hipsCm; }
    public function setHipsCm(?float $hipsCm): static { $this->hipsCm = $hipsCm; return $this; }
    public function getArmCm(): ?float { return $this->armCm; }
    public function setArmCm(?float $armCm): static { $this->armCm = $armCm; return $this; }
    public function getThighCm(): ?float { return $this->thighCm; }
    public function setThighCm(?float $thighCm): static { $this->thighCm = $thighCm; return $this; }
    public function getCalfCm(): ?float { return $this->calfCm; }
    public function setCalfCm(?float $calfCm): static { $this->calfCm = $calfCm; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }
}
