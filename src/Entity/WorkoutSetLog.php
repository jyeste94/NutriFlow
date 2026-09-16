<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'workout_set_logs')]
class WorkoutSetLog
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(inversedBy: 'sets')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?WorkoutSession $session = null;

    #[ORM\ManyToOne(targetEntity: Exercise::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Exercise $exercise = null;

    #[ORM\Column(type: 'float')]
    private float $weight = 0.0;

    #[ORM\Column(type: 'integer')]
    private int $reps = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $completed = false;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'normal'])]
    private string $setType = 'normal';

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $durationSeconds = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $distanceKm = null;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getSession(): ?WorkoutSession
    {
        return $this->session;
    }

    public function setSession(?WorkoutSession $session): static
    {
        $this->session = $session;
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

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function setWeight(float $weight): static
    {
        $this->weight = $weight;
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

    public function isCompleted(): bool
    {
        return $this->completed;
    }

    public function setCompleted(bool $completed): static
    {
        $this->completed = $completed;
        return $this;
    }

    public function getSetType(): string
    {
        return $this->setType;
    }

    public function setSetType(string $setType): static
    {
        $this->setType = $setType;
        return $this;
    }

    public function getDurationSeconds(): ?int
    {
        return $this->durationSeconds;
    }

    public function setDurationSeconds(?int $durationSeconds): static
    {
        $this->durationSeconds = $durationSeconds;
        return $this;
    }

    public function getDistanceKm(): ?float
    {
        return $this->distanceKm;
    }

    public function setDistanceKm(?float $distanceKm): static
    {
        $this->distanceKm = $distanceKm;
        return $this;
    }
}
