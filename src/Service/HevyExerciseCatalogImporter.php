<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Exercise;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class HevyExerciseCatalogImporter
{
    public const SNAPSHOT = '/resources/exercises/hevy.20261021.metadata.json';
    public const EXPECTED_COUNT = 454;

    private const MUSCLES = [
        'abdominals' => 'Abdomen', 'abductors' => 'Abductores', 'adductors' => 'Aductores',
        'biceps' => 'Bíceps', 'calves' => 'Gemelos', 'cardio' => 'Cardio', 'chest' => 'Pecho',
        'forearms' => 'Antebrazo', 'full_body' => 'Cuerpo completo', 'glutes' => 'Glúteos',
        'hamstrings' => 'Isquios', 'lats' => 'Espalda', 'lower_back' => 'Lumbar', 'neck' => 'Cuello',
        'other' => 'Otro', 'quadriceps' => 'Cuádriceps', 'shoulders' => 'Hombro', 'traps' => 'Trapecio',
        'triceps' => 'Tríceps', 'upper_back' => 'Espalda',
    ];
    private const EQUIPMENT = [
        'barbell' => 'Barra Larga', 'dumbbell' => 'Mancuernas', 'kettlebell' => 'Pesas rusas',
        'machine' => 'Máquina', 'none' => 'Ninguno', 'other' => 'Otro', 'plate' => 'Disco',
        'resistance_band' => 'Bandas', 'suspension' => 'Suspensión',
    ];
    private const TRACKING = [
        'weight_reps' => Exercise::TRACKING_WEIGHT_REPS,
        'reps_only' => Exercise::TRACKING_BODYWEIGHT_REPS,
        'bodyweight_reps' => Exercise::TRACKING_BODYWEIGHT_REPS,
        'bodyweight_assisted_reps' => Exercise::TRACKING_BODYWEIGHT_REPS,
        'duration' => Exercise::TRACKING_DURATION,
        'distance_duration' => Exercise::TRACKING_DISTANCE_DURATION,
        // The application has no floors/steps metric. Never reinterpret those counts as metres.
        'floors_duration' => Exercise::TRACKING_DURATION,
        'steps_duration' => Exercise::TRACKING_DURATION,
        'short_distance_weight' => Exercise::TRACKING_WEIGHT_DISTANCE,
    ];

    // Reviewed identities, not fuzzy translations. Grip, incline, implement and depth remain distinct.
    // Optional labels are the stock Spanish names shipped by ExerciseCatalogImporter, not new translations.
    private const FREE_EQUIVALENTS = [
        'Dumbbell_Bench_Press' => ['3601968B', 'Press banca plano con mancuernas'],
        'Incline_Dumbbell_Press' => ['07B38369', 'Press inclinado con mancuernas'],
        'Decline_Dumbbell_Bench_Press' => ['18487FA7', 'Press declinado con mancuernas'],
        'Barbell_Bench_Press_-_Medium_Grip' => ['79D0BB3A', 'Press banca plano'],
        'Barbell_Incline_Bench_Press_-_Medium_Grip' => ['50DFDFAB', 'Press banca inclinado'],
        'Decline_Barbell_Bench_Press' => ['DA0F0470', null],
        'Close-Grip_Barbell_Bench_Press' => ['35B51B87', null],
        'Wide-Grip_Barbell_Bench_Press' => ['E644F828', null],
        'Barbell_Squat' => ['D04AC939', null],
        'Barbell_Full_Squat' => ['1283BBA6', 'Sentadilla completa con barra'],
        'Bodyweight_Squat' => ['9694DA61', null],
        'Dumbbell_Squat' => ['DCFF3E9F', null],
        'Goblet_Squat' => ['A127DA73', 'Sentadilla goblet'],
        'Box_Squat' => ['38FC1AB9', null],
        'Overhead_Squat' => ['2CFED196', null],
        'Zercher_Squats' => ['40C6A9FC', null],
        'Bent_Over_Barbell_Row' => ['55E6546F', 'Remo con barra'],
        'Bent_Over_Two-Dumbbell_Row' => ['23E92538', null],
        'Upright_Barbell_Row' => ['7AB9A362', null],
        'Barbell_Curl' => ['A5AC6449', 'Curl de bíceps con barra'],
        'Dumbbell_Bicep_Curl' => ['37FCC2BB', 'Curl de bíceps con mancuernas'],
        'Hammer_Curls' => ['7E3BC8B6', 'Curl martillo'],
        'Concentration_Curls' => ['724CDE60', 'Curl concentrado'],
        'Cross_Body_Hammer_Curl' => ['32C4D4A2', null],
        'Zottman_Curl' => ['123EE239', null],
        'Reverse_Barbell_Curl' => ['112FC6B7', null],
        'Drag_Curl' => ['D950429E', null],
        'Incline_Dumbbell_Curl' => ['8BAB2735', null],
        'Incline_Hammer_Curls' => ['C01F58D1', null],
        'Barbell_Deadlift' => ['C6272009', 'Peso muerto convencional'],
        'Romanian_Deadlift' => ['2B4B7310', null],
        'Sumo_Deadlift' => ['D20D7BBE', null],
        'Lying_Leg_Curls' => ['B8127AD1', 'Curl femoral tumbado'],
        'Seated_Leg_Curl' => ['11A123F3', 'Curl femoral sentado'],
    ];

    public function __construct(
        private EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {}

    /** @return array{total: int, created: int, adopted: int, unchanged: int, ambiguous: int, dry_run: bool} */
    public function import(bool $dryRun = false, ?string $snapshotPath = null): array
    {
        $rows = $this->readSnapshot($snapshotPath ?? $this->projectDir.self::SNAPSHOT);
        if ($dryRun) {
            return $this->plan($rows, true)['result'];
        }
        return $this->em->wrapInTransaction(function () use ($rows): array {
            $plan = $this->plan($rows, false);
            foreach ($plan['actions'] as [$row, $existing]) {
                if ($existing instanceof Exercise) {
                    if ($this->isStockName($existing, $row)) {
                        $existing->setName($row['name_es']);
                    }
                    $existing->setHevyId($row['id'])->setNameEn($row['name_en'])->setNameEs($row['name_es']);
                    continue;
                }
                $exercise = (new Exercise())->setHevyId($row['id'])
                    ->setName($row['name_es'])->setNameEn($row['name_en'])->setNameEs($row['name_es'])
                    ->setMuscleGroup(self::MUSCLES[$row['primary_muscle_group']])
                    ->setEquipment(self::EQUIPMENT[$row['equipment']])
                    ->setTrackingType(self::TRACKING[$row['exercise_type']]);
                $this->em->persist($exercise);
            }
            return $plan['result'];
        });
    }

    private function readSnapshot(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('No se puede leer la instantánea local de Hevy: '.$path);
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \InvalidArgumentException('No se pudo leer la instantánea local de Hevy.');
        }
        try {
            $snapshot = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException('La instantánea de Hevy no contiene JSON válido.', 0, $error);
        }
        if (!is_array($snapshot) || ($snapshot['expectedCount'] ?? null) !== self::EXPECTED_COUNT
            || !isset($snapshot['exercises']) || !is_array($snapshot['exercises'])
            || !array_is_list($snapshot['exercises']) || count($snapshot['exercises']) !== self::EXPECTED_COUNT) {
            throw new \InvalidArgumentException('La instantánea debe contener exactamente 454 ejercicios Hevy; no se importará un catálogo parcial.');
        }
        $ids = [];
        foreach ($snapshot['exercises'] as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Registro Hevy inválido.');
            }
            foreach (['id', 'name_en', 'name_es', 'primary_muscle_group', 'exercise_type', 'equipment'] as $field) {
                if (!isset($row[$field]) || !is_string($row[$field]) || trim($row[$field]) === '') {
                    throw new \InvalidArgumentException('Campo Hevy inválido: '.$field);
                }
            }
            if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $row['id']) || isset($ids[$row['id']])
                || mb_strlen($row['name_en']) > 255 || mb_strlen($row['name_es']) > 255) {
                throw new \InvalidArgumentException('Identidad Hevy duplicada o identidad/nombres inválidos: '.$row['id']);
            }
            $ids[$row['id']] = true;
            if (!isset(self::MUSCLES[$row['primary_muscle_group']], self::TRACKING[$row['exercise_type']], self::EQUIPMENT[$row['equipment']])) {
                throw new \InvalidArgumentException('Clasificación Hevy desconocida: '.$row['id']);
            }
            foreach (['is_archived', 'full_bodyweight'] as $field) {
                if (!array_key_exists($field, $row) || !is_bool($row[$field])) {
                    throw new \InvalidArgumentException('Campo Hevy booleano inválido: '.$field);
                }
            }
            if (!isset($row['secondary_muscle_groups']) || !is_array($row['secondary_muscle_groups']) || !array_is_list($row['secondary_muscle_groups'])) {
                throw new \InvalidArgumentException('Lista de músculos Hevy inválida.');
            }
            foreach ($row['secondary_muscle_groups'] as $muscle) {
                if (!is_string($muscle) || !isset(self::MUSCLES[$muscle])) {
                    throw new \InvalidArgumentException('Músculo secundario Hevy desconocido.');
                }
            }
        }
        return $snapshot['exercises'];
    }

    private function plan(array $rows, bool $dryRun): array
    {
        $byHevy = $bySource = $byName = [];
        foreach ($this->em->getRepository(Exercise::class)->findAll() as $exercise) {
            if ($exercise->getHevyId() !== null) {
                if (isset($byHevy[$exercise->getHevyId()])) {
                    throw new \RuntimeException('Identidad Hevy duplicada en el catálogo: '.$exercise->getHevyId());
                }
                $byHevy[$exercise->getHevyId()] = $exercise;
                continue;
            }
            $key = spl_object_id($exercise);
            if ($exercise->getSourceId() !== null) {
                $bySource[$exercise->getSourceId()][$key] = $exercise;
            }
            $equipment = $this->normalizeEquipment($exercise->getEquipment());
            if ($equipment !== null) {
                foreach ([$exercise->getName(), $exercise->getSourceName(), $exercise->getNameEn(), $exercise->getNameEs()] as $name) {
                    if ($name !== null && trim($name) !== '') {
                        $byName[$this->normalize($name).'|'.$equipment][$key] = $exercise;
                    }
                }
            }
        }
        $candidates = $claims = [];
        foreach ($rows as $row) {
            if (isset($byHevy[$row['id']])) {
                continue;
            }
            $matches = [];
            $names = [$row['name_en'], $row['name_es']];
            foreach (self::FREE_EQUIVALENTS as $freeId => [$hevyId, $stockName]) {
                if ($hevyId === $row['id']) {
                    $matches += $bySource[ExerciseCatalogImporter::SOURCE_PREFIX.$freeId] ?? [];
                    if ($stockName !== null) {
                        $names[] = $stockName;
                    }
                }
            }
            foreach ($names as $name) {
                $matches += $byName[$this->normalize($name).'|'.$row['equipment']] ?? [];
            }
            $candidates[$row['id']] = $matches;
            foreach ($matches as $key => $exercise) {
                $claims[$key] = ($claims[$key] ?? 0) + 1;
            }
        }
        $result = ['total' => count($rows), 'created' => 0, 'adopted' => 0, 'unchanged' => 0, 'ambiguous' => 0, 'dry_run' => $dryRun];
        $actions = [];
        foreach ($rows as $row) {
            if (isset($byHevy[$row['id']])) {
                ++$result['unchanged'];
                continue;
            }
            $matches = $candidates[$row['id']];
            $existing = count($matches) === 1 && $claims[array_key_first($matches)] === 1 ? reset($matches) : null;
            if ($existing instanceof Exercise) {
                ++$result['adopted'];
            } else {
                ++$result['created'];
                if ($matches !== []) {
                    ++$result['ambiguous'];
                }
            }
            $actions[] = [$row, $existing];
        }
        return ['result' => $result, 'actions' => $actions];
    }

    private function isStockName(Exercise $exercise, array $row): bool
    {
        $names = [$row['name_en'], $row['name_es'], $exercise->getSourceName()];
        foreach (self::FREE_EQUIVALENTS as $freeId => [$hevyId, $stockName]) {
            if ($hevyId === $row['id']) {
                $names[] = $stockName;
            }
        }
        $current = $this->normalize($exercise->getName() ?? '');
        foreach ($names as $name) {
            if ($name !== null && $current === $this->normalize($name)) {
                return true;
            }
        }
        return false;
    }

    private function normalize(string $value): string
    {
        $value = strtr(mb_strtolower(trim($value)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        return preg_replace('/\s+/u', ' ', $value);
    }

    private function normalizeEquipment(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = $this->normalize($value);
        foreach (self::EQUIPMENT as $source => $display) {
            if ($value === $source || $value === $this->normalize($display)) {
                return $source;
            }
        }
        return match ($value) {
            'body only', 'bodyweight', 'peso corporal' => 'none',
            'bands' => 'resistance_band', 'kettlebells' => 'kettlebell',
            default => $value,
        };
    }
}
