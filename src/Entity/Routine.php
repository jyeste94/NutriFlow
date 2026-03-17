<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'routines')]
class Routine
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
    private ?string $name = null; // e.g. "Día 1: Pecho y Tríceps"

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $daysOfWeek = []; // e.g. [1, 3, 5] for Mon, Wed, Fri

    #[ORM\OneToMany(mappedBy: 'routine', targetEntity: RoutineExercise::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['orderIndex' => 'ASC'])]
    private Collection $routineExercises;

    public function __construct()
    {
        $this->routineExercises = new ArrayCollection();
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

    public function getDaysOfWeek(): ?array
    {
        return $this->daysOfWeek;
    }

    public function setDaysOfWeek(?array $daysOfWeek): static
    {
        $this->daysOfWeek = $daysOfWeek;
        return $this;
    }

    /**
     * @return Collection<int, RoutineExercise>
     */
    public function getRoutineExercises(): Collection
    {
        return $this->routineExercises;
    }

    public function addRoutineExercise(RoutineExercise $routineExercise): static
    {
        if (!$this->routineExercises->contains($routineExercise)) {
            $this->routineExercises->add($routineExercise);
            $routineExercise->setRoutine($this);
        }

        return $this;
    }

    public function removeRoutineExercise(RoutineExercise $routineExercise): static
    {
        if ($this->routineExercises->removeElement($routineExercise)) {
            if ($routineExercise->getRoutine() === $this) {
                $routineExercise->setRoutine(null);
            }
        }

        return $this;
    }
}
