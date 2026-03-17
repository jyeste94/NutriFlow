<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'meal_diaries')]
#[ORM\UniqueConstraint(name: 'uniq_user_date', columns: ['user_id', 'date'])]
class MealDiary
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: 'date_immutable')]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private float $totalCalories = 0.0;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private float $totalProteins = 0.0;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private float $totalCarbs = 0.0;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private float $totalFats = 0.0;

    #[ORM\OneToMany(mappedBy: 'diary', targetEntity: MealEntry::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $entries;

    public function __construct()
    {
        $this->entries = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;
        return $this;
    }

    public function getTotalCalories(): float
    {
        return $this->totalCalories;
    }

    public function setTotalCalories(float $totalCalories): static
    {
        $this->totalCalories = $totalCalories;
        return $this;
    }

    public function getTotalProteins(): float
    {
        return $this->totalProteins;
    }

    public function setTotalProteins(float $totalProteins): static
    {
        $this->totalProteins = $totalProteins;
        return $this;
    }

    public function getTotalCarbs(): float
    {
        return $this->totalCarbs;
    }

    public function setTotalCarbs(float $totalCarbs): static
    {
        $this->totalCarbs = $totalCarbs;
        return $this;
    }

    public function getTotalFats(): float
    {
        return $this->totalFats;
    }

    public function setTotalFats(float $totalFats): static
    {
        $this->totalFats = $totalFats;
        return $this;
    }

    /**
     * @return Collection<int, MealEntry>
     */
    public function getEntries(): Collection
    {
        return $this->entries;
    }

    public function addEntry(MealEntry $entry): static
    {
        if (!$this->entries->contains($entry)) {
            $this->entries->add($entry);
            $entry->setDiary($this);
            $this->recalculateTotals();
        }

        return $this;
    }

    public function removeEntry(MealEntry $entry): static
    {
        if ($this->entries->removeElement($entry)) {
            if ($entry->getDiary() === $this) {
                $entry->setDiary(null);
            }
            $this->recalculateTotals();
        }

        return $this;
    }

    public function recalculateTotals(): void
    {
        $this->totalCalories = 0.0;
        $this->totalProteins = 0.0;
        $this->totalCarbs = 0.0;
        $this->totalFats = 0.0;

        foreach ($this->entries as $entry) {
            $serving = $entry->getServing();
            $multiplier = $entry->getMultiplier();
            
            if ($serving && $multiplier > 0) {
                $this->totalCalories += ($serving->getCalories() * $multiplier);
                $this->totalProteins += ($serving->getProteins() * $multiplier);
                $this->totalCarbs += ($serving->getCarbs() * $multiplier);
                $this->totalFats += ($serving->getFats() * $multiplier);
            }
        }
    }
}
