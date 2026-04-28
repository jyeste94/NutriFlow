<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'diet_plan_meals')]
#[ORM\Index(name: 'idx_diet_plan_meals_day', columns: ['day_id'])]
#[ORM\Index(name: 'idx_diet_plan_meals_serving', columns: ['serving_id'])]
class DietPlanMeal
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(inversedBy: 'meals')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?DietPlanDay $day = null;

    #[ORM\ManyToOne(targetEntity: Serving::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Serving $serving = null;

    #[ORM\Column(type: 'float')]
    private float $multiplier = 1.0;

    #[ORM\Column(type: 'string', length: 50)]
    private string $mealType = '';

    #[ORM\Column(type: 'integer')]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $optionGroup = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function getId(): ?Uuid { return $this->id; }
    public function getDay(): ?DietPlanDay { return $this->day; }
    public function setDay(?DietPlanDay $day): static { $this->day = $day; return $this; }
    public function getServing(): ?Serving { return $this->serving; }
    public function setServing(?Serving $serving): static { $this->serving = $serving; return $this; }
    public function getMultiplier(): float { return $this->multiplier; }
    public function setMultiplier(float $multiplier): static { $this->multiplier = $multiplier; return $this; }
    public function getMealType(): string { return $this->mealType; }
    public function setMealType(string $mealType): static { $this->mealType = $mealType; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): static { $this->sortOrder = $sortOrder; return $this; }
    public function getOptionGroup(): ?string { return $this->optionGroup; }
    public function setOptionGroup(?string $optionGroup): static { $this->optionGroup = $optionGroup; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }
}
