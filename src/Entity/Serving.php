<?php

namespace App\Entity;

use App\Repository\ServingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ServingRepository::class)]
#[ORM\Table(name: 'servings')]
class Serving
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(inversedBy: 'servings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Food $food = null;

    #[ORM\Column(length: 255)]
    private ?string $description = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $amount = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $unit = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $calories = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $proteins = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $carbs = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $fats = null;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getFood(): ?Food
    {
        return $this->food;
    }

    public function setFood(?Food $food): static
    {
        $this->food = $food;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getAmount(): ?float
    {
        return $this->amount === null ? null : (float) $this->amount;
    }

    public function setAmount(?float $amount): static
    {
        $this->amount = $this->formatDecimal($amount);
        return $this;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(?string $unit): static
    {
        $this->unit = $unit;
        return $this;
    }

    public function getCalories(): ?float
    {
        return $this->calories === null ? null : (float) $this->calories;
    }

    public function setCalories(float $calories): static
    {
        $this->calories = $this->formatDecimal($calories);
        return $this;
    }

    public function getProteins(): ?float
    {
        return $this->proteins === null ? null : (float) $this->proteins;
    }

    public function setProteins(?float $proteins): static
    {
        $this->proteins = $this->formatDecimal($proteins);
        return $this;
    }

    public function getCarbs(): ?float
    {
        return $this->carbs === null ? null : (float) $this->carbs;
    }

    public function setCarbs(?float $carbs): static
    {
        $this->carbs = $this->formatDecimal($carbs);
        return $this;
    }

    public function getFats(): ?float
    {
        return $this->fats === null ? null : (float) $this->fats;
    }

    public function setFats(?float $fats): static
    {
        $this->fats = $this->formatDecimal($fats);
        return $this;
    }

    private function formatDecimal(?float $value): ?string
    {
        return $value === null ? null : number_format($value, 2, '.', '');
    }
}
