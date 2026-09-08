<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'saved_meal_items')]
class SavedMealItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: SavedMeal::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?SavedMeal $savedMeal = null;

    #[ORM\ManyToOne(targetEntity: Serving::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Serving $serving = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $brand = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amountGrams = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $calories = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $proteins = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $carbs = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $fats = '0.00';

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $position = 0;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getSavedMeal(): ?SavedMeal
    {
        return $this->savedMeal;
    }

    public function setSavedMeal(?SavedMeal $savedMeal): static
    {
        $this->savedMeal = $savedMeal;
        return $this;
    }

    public function getServing(): ?Serving
    {
        return $this->serving;
    }

    public function setServing(?Serving $serving): static
    {
        $this->serving = $serving;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getBrand(): ?string
    {
        return $this->brand;
    }

    public function setBrand(?string $brand): static
    {
        $this->brand = $brand;
        return $this;
    }

    public function getAmountGrams(): float
    {
        return (float) $this->amountGrams;
    }

    public function setAmountGrams(float $amountGrams): static
    {
        $this->amountGrams = number_format($amountGrams, 2, '.', '');
        return $this;
    }

    public function getCalories(): float
    {
        return (float) $this->calories;
    }

    public function setCalories(float $calories): static
    {
        $this->calories = number_format($calories, 2, '.', '');
        return $this;
    }

    public function getProteins(): float
    {
        return (float) $this->proteins;
    }

    public function setProteins(float $proteins): static
    {
        $this->proteins = number_format($proteins, 2, '.', '');
        return $this;
    }

    public function getCarbs(): float
    {
        return (float) $this->carbs;
    }

    public function setCarbs(float $carbs): static
    {
        $this->carbs = number_format($carbs, 2, '.', '');
        return $this;
    }

    public function getFats(): float
    {
        return (float) $this->fats;
    }

    public function setFats(float $fats): static
    {
        $this->fats = number_format($fats, 2, '.', '');
        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;
        return $this;
    }
}
