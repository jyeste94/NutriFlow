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
    private string $totalCalories = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $totalProteins = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $totalCarbs = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $totalFats = '0.00';

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
        return (float) $this->totalCalories;
    }

    public function setTotalCalories(float $totalCalories): static
    {
        $this->totalCalories = $this->formatDecimal($totalCalories);
        return $this;
    }

    public function getTotalProteins(): float
    {
        return (float) $this->totalProteins;
    }

    public function setTotalProteins(float $totalProteins): static
    {
        $this->totalProteins = $this->formatDecimal($totalProteins);
        return $this;
    }

    public function getTotalCarbs(): float
    {
        return (float) $this->totalCarbs;
    }

    public function setTotalCarbs(float $totalCarbs): static
    {
        $this->totalCarbs = $this->formatDecimal($totalCarbs);
        return $this;
    }

    public function getTotalFats(): float
    {
        return (float) $this->totalFats;
    }

    public function setTotalFats(float $totalFats): static
    {
        $this->totalFats = $this->formatDecimal($totalFats);
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
        $totalCalories = 0.0;
        $totalProteins = 0.0;
        $totalCarbs = 0.0;
        $totalFats = 0.0;

        foreach ($this->entries as $entry) {
            $serving = $entry->getServing();
            $multiplier = $entry->getMultiplier();
            
            if ($serving && $multiplier > 0) {
                $totalCalories += (($serving->getCalories() ?? 0.0) * $multiplier);
                $totalProteins += (($serving->getProteins() ?? 0.0) * $multiplier);
                $totalCarbs += (($serving->getCarbs() ?? 0.0) * $multiplier);
                $totalFats += (($serving->getFats() ?? 0.0) * $multiplier);
            }
        }

        $this->totalCalories = $this->formatDecimal($totalCalories);
        $this->totalProteins = $this->formatDecimal($totalProteins);
        $this->totalCarbs = $this->formatDecimal($totalCarbs);
        $this->totalFats = $this->formatDecimal($totalFats);
    }

    private function formatDecimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
