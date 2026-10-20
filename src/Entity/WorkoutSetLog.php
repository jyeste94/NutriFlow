<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'workout_set_logs')]
#[ORM\UniqueConstraint(name: 'uniq_workout_client_set', columns: ['session_id', 'client_set_id'])]
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

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $clientSetId = null;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $setNumber = 1;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $exerciseOrder = 0;

    public function getExerciseOrder(): int
    {
        return $this->exerciseOrder;
    }

    public function setExerciseOrder(int $exerciseOrder): static
    {
        $this->exerciseOrder = $exerciseOrder;
        return $this;
    }

    public function getClientSetId(): ?string
    {
        return $this->clientSetId;
    }

    public function setClientSetId(?string $clientSetId): static
    {
        $this->clientSetId = $clientSetId;
        return $this;
    }

    public function getSetNumber(): int
    {
        return $this->setNumber;
    }

    public function setSetNumber(int $setNumber): static
    {
        $this->setNumber = $setNumber;
        return $this;
    }

    public static function estimateOneRepMax(float $weight, int $reps): ?float
    {
        if ($weight <= 0 || $reps < 1 || $reps > 12) {
            return null;
        }
        return round($reps === 1 ? $weight : $weight * (1 + $reps / 30), 1);
    }

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
