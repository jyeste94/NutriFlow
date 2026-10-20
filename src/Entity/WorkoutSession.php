<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'workout_sessions')]
#[ORM\UniqueConstraint(name: 'uniq_workout_client_session', columns: ['user_id', 'client_session_id'])]
class WorkoutSession
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Routine::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Routine $routine = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $durationMinutes = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $durationSeconds = null;

    #[ORM\Column(type: 'string', length: 16, options: ['default' => 'active'])]
    private string $status = 'active';

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $clientSessionId = null;

    public function setClientSessionId(?string $clientSessionId): static
    {
        $this->clientSessionId = $clientSessionId;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function complete(): static
    {
        $this->status = 'completed';
        return $this;
    }

    #[ORM\OneToMany(mappedBy: 'session', targetEntity: WorkoutSetLog::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $sets;

    public function __construct()
    {
        $this->sets = new ArrayCollection();
        $this->date = new \DateTimeImmutable();
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

    public function getRoutine(): ?Routine
    {
        return $this->routine;
    }

    public function setRoutine(?Routine $routine): static
    {
        $this->routine = $routine;
        return $this;
    }

    public function getName(): string
    {
        return $this->name ?? $this->routine?->getName() ?? 'Entrenamiento';
    }

    public function setName(string $name): static
    {
        $this->name = $name;
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

    public function getDurationMinutes(): ?int
    {
        return $this->durationMinutes;
    }

    public function setDurationMinutes(?int $durationMinutes): static
    {
        $this->durationMinutes = $durationMinutes;
        $this->durationSeconds = $durationMinutes !== null ? $durationMinutes * 60 : null;
        return $this;
    }

    public function getDurationSeconds(): ?int
    {
        return $this->durationSeconds ?? ($this->durationMinutes !== null ? $this->durationMinutes * 60 : null);
    }

    public function setDurationSeconds(?int $durationSeconds): static
    {
        $this->durationSeconds = $durationSeconds;
        $this->durationMinutes = $durationSeconds !== null ? intdiv($durationSeconds, 60) : null;
        return $this;
    }

    /**
     * @return Collection<int, WorkoutSetLog>
     */
    public function getSets(): Collection
    {
        return $this->sets;
    }

    public function addSet(WorkoutSetLog $set): static
    {
        if (!$this->sets->contains($set)) {
            $this->sets->add($set);
            $set->setSession($this);
        }

        return $this;
    }

    public function removeSet(WorkoutSetLog $set): static
    {
        if ($this->sets->removeElement($set)) {
            if ($set->getSession() === $this) {
                $set->setSession(null);
            }
        }

        return $this;
    }
}
