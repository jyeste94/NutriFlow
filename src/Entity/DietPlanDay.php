<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'diet_plan_days')]
#[ORM\Index(name: 'idx_diet_plan_days_plan', columns: ['plan_id'])]
class DietPlanDay
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(inversedBy: 'days')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?DietPlan $plan = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $dayOfWeek = '';

    #[ORM\Column(type: 'integer')]
    private int $sortOrder = 0;

    #[ORM\OneToMany(mappedBy: 'day', targetEntity: DietPlanMeal::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $meals;

    public function __construct()
    {
        $this->meals = new ArrayCollection();
    }

    public function getId(): ?Uuid { return $this->id; }
    public function getPlan(): ?DietPlan { return $this->plan; }
    public function setPlan(?DietPlan $plan): static { $this->plan = $plan; return $this; }
    public function getDayOfWeek(): string { return $this->dayOfWeek; }
    public function setDayOfWeek(string $dayOfWeek): static { $this->dayOfWeek = $dayOfWeek; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): static { $this->sortOrder = $sortOrder; return $this; }
    public function getMeals(): Collection { return $this->meals; }
    public function addMeal(DietPlanMeal $meal): static { if (!$this->meals->contains($meal)) { $this->meals->add($meal); $meal->setDay($this); } return $this; }
    public function removeMeal(DietPlanMeal $meal): static { if ($this->meals->removeElement($meal)) { if ($meal->getDay() === $this) $meal->setDay(null); } return $this; }
}
