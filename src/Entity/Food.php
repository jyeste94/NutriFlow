<?php

namespace App\Entity;

use App\Repository\FoodRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: FoodRepository::class)]
#[ORM\Table(name: 'foods')]
#[ORM\UniqueConstraint(name: 'uniq_external_id', columns: ['external_id'])]
class Food
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $externalId = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $brand = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $bestServingId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $lastFetchedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\OneToMany(mappedBy: 'food', targetEntity: Serving::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $servings;

    public function __construct()
    {
        $this->servings = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(string $externalId): static
    {
        $this->externalId = $externalId;
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

    public function getBestServingId(): ?Uuid
    {
        return $this->bestServingId;
    }

    public function setBestServingId(?Uuid $bestServingId): static
    {
        $this->bestServingId = $bestServingId;
        return $this;
    }

    public function getLastFetchedAt(): ?\DateTimeImmutable
    {
        return $this->lastFetchedAt;
    }

    public function setLastFetchedAt(\DateTimeImmutable $lastFetchedAt): static
    {
        $this->lastFetchedAt = $lastFetchedAt;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
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
     * @return Collection<int, Serving>
     */
    public function getServings(): Collection
    {
        return $this->servings;
    }

    public function addServing(Serving $serving): static
    {
        if (!$this->servings->contains($serving)) {
            $this->servings->add($serving);
            $serving->setFood($this);
        }

        return $this;
    }

    public function removeServing(Serving $serving): static
    {
        if ($this->servings->removeElement($serving)) {
            // set the owning side to null (unless already changed)
            if ($serving->getFood() === $this) {
                $serving->setFood(null);
            }
        }

        return $this;
    }
}
