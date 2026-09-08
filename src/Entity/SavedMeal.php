<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'saved_meals')]
class SavedMeal
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $preferredMealType = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\OneToMany(mappedBy: 'savedMeal', targetEntity: SavedMealItem::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getPreferredMealType(): ?string
    {
        return $this->preferredMealType;
    }

    public function setPreferredMealType(?string $preferredMealType): static
    {
        $this->preferredMealType = $preferredMealType;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * @return Collection<int, SavedMealItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(SavedMealItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setSavedMeal($this);
        }

        return $this;
    }

    public function removeItem(SavedMealItem $item): static
    {
        if ($this->items->removeElement($item)) {
            if ($item->getSavedMeal() === $this) {
                $item->setSavedMeal(null);
            }
        }

        return $this;
    }

    public function getTotalCalories(): float
    {
        $total = 0.0;
        foreach ($this->items as $item) {
            $total += $item->getCalories();
        }
        return round($total, 2);
    }

    public function getTotalProteins(): float
    {
        $total = 0.0;
        foreach ($this->items as $item) {
            $total += $item->getProteins();
        }
        return round($total, 2);
    }

    public function getTotalCarbs(): float
    {
        $total = 0.0;
        foreach ($this->items as $item) {
            $total += $item->getCarbs();
        }
        return round($total, 2);
    }

    public function getTotalFats(): float
    {
        $total = 0.0;
        foreach ($this->items as $item) {
            $total += $item->getFats();
        }
        return round($total, 2);
    }
}
