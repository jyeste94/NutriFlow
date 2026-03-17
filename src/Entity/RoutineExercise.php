<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'routine_exercises')]
class RoutineExercise
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(inversedBy: 'routineExercises')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Routine $routine = null;

    #[ORM\ManyToOne(targetEntity: Exercise::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Exercise $exercise = null;

    #[ORM\Column(type: 'integer')]
    private int $sets = 3;

    #[ORM\Column(type: 'integer')]
    private int $reps = 10;

    #[ORM\Column(type: 'integer')]
    private int $restSeconds = 60;

    #[ORM\Column(type: 'integer')]
    private int $orderIndex = 0; // To sort exercises within a routine

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getRoutine(): ?Routine
    {
        return $this->routine;
    }

    public function setRoutine(?Routine $routine): static
    {
        $this->routine = $routine;
        return $this;
    }

    public function getExercise(): ?Exercise
    {
        return $this->exercise;
    }

    public function setExercise(?Exercise $exercise): static
    {
        $this->exercise = $exercise;
        return $this;
    }

    public function getSets(): int
    {
        return $this->sets;
    }

    public function setSets(int $sets): static
    {
        $this->sets = $sets;
        return $this;
    }

    public function getReps(): int
    {
        return $this->reps;
    }

    public function setReps(int $reps): static
    {
        $this->reps = $reps;
        return $this;
    }

    public function getRestSeconds(): int
    {
        return $this->restSeconds;
    }

    public function setRestSeconds(int $restSeconds): static
    {
        $this->restSeconds = $restSeconds;
        return $this;
    }

    public function getOrderIndex(): int
    {
        return $this->orderIndex;
    }

    public function setOrderIndex(int $orderIndex): static
    {
        $this->orderIndex = $orderIndex;
        return $this;
    }
}
