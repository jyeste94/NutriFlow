<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Exercise;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ExerciseCatalogImporter
{
    public const REVISION = 'f00c92c7dcf1216a928a52c3706c7ce8e2f71ed5';
    public const SOURCE_PREFIX = 'free-exercise-db:';
    public const SNAPSHOT = '/resources/exercises/free-exercise-db.f00c92c7.json';
    public const EXPECTED_COUNT = 876;
    public const IMAGE_BASE = 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/'.self::REVISION.'/exercises/';

    private const MUSCLES = [
        'abdominals' => 'Abdomen', 'abductors' => 'Abductores', 'adductors' => 'Aductores',
        'biceps' => 'Bíceps', 'calves' => 'Gemelos', 'chest' => 'Pecho', 'forearms' => 'Antebrazo',
        'glutes' => 'Glúteos', 'hamstrings' => 'Isquios', 'lats' => 'Espalda',
        'lower back' => 'Lumbar', 'middle back' => 'Espalda', 'neck' => 'Cuello',
        'quadriceps' => 'Cuádriceps', 'shoulders' => 'Hombro', 'traps' => 'Trapecio', 'triceps' => 'Tríceps',
    ];
    private const EQUIPMENT = [
        'bands' => 'Bandas', 'barbell' => 'Barra Larga', 'body only' => 'Ninguno',
        'cable' => 'Polea', 'dumbbell' => 'Mancuernas', 'e-z curl bar' => 'Barra Z',
        'exercise ball' => 'Fitball', 'foam roll' => 'Rodillo de espuma',
        'kettlebells' => 'Pesas rusas', 'machine' => 'Máquina', 'medicine ball' => 'Balón medicinal', 'other' => 'Otro',
    ];
    // Deliberately curated, not machine-generated translations of unfamiliar variants.
    private const DISPLAY_NAMES = [
        'Dumbbell_Bench_Press' => 'Press banca plano con mancuernas',
        'Incline_Dumbbell_Press' => 'Press inclinado con mancuernas',
        'Decline_Dumbbell_Bench_Press' => 'Press declinado con mancuernas',
        'Barbell_Bench_Press_-_Medium_Grip' => 'Press banca plano',
        'Barbell_Incline_Bench_Press_-_Medium_Grip' => 'Press banca inclinado',
        'Dumbbell_Flyes' => 'Aperturas con mancuernas',
        'Incline_Dumbbell_Flyes' => 'Aperturas inclinadas con mancuernas',
        'Dumbbell_Shoulder_Press' => 'Press militar con mancuernas',
        'Arnold_Dumbbell_Press' => 'Press Arnold',
        'Side_Lateral_Raise' => 'Elevaciones laterales con mancuernas',
        'Front_Dumbbell_Raise' => 'Elevaciones frontales con mancuernas',
        'One-Arm_Dumbbell_Row' => 'Remo con mancuerna a una mano',
        'Bent_Over_Barbell_Row' => 'Remo con barra',
        'Barbell_Deadlift' => 'Peso muerto convencional',
        'Barbell_Full_Squat' => 'Sentadilla completa con barra',
        'Goblet_Squat' => 'Sentadilla goblet',
        'Leg_Press' => 'Prensa de piernas',
        'Leg_Extensions' => 'Extensiones de piernas',
        'Lying_Leg_Curls' => 'Curl femoral tumbado',
        'Seated_Leg_Curl' => 'Curl femoral sentado',
        'Barbell_Curl' => 'Curl de bíceps con barra',
        'Dumbbell_Bicep_Curl' => 'Curl de bíceps con mancuernas',
        'Hammer_Curls' => 'Curl martillo',
        'Concentration_Curls' => 'Curl concentrado',
        'Pushups' => 'Flexiones', 'Pullups' => 'Dominadas', 'Chin-Up' => 'Dominadas supinas',
        'Bench_Dips' => 'Fondos en banco', 'Crunches' => 'Encogimientos',
        'Plank' => 'Plancha', 'Side_Bridge' => 'Plancha lateral',
        'Hanging_Leg_Raise' => 'Elevación de piernas colgado',
        'Cable_Crunch' => 'Encogimientos con cable',
        'Dumbbell_Shrug' => 'Encogimientos de hombros con mancuernas',
        'Barbell_Shrug' => 'Encogimientos con barra',
        'Farmers_Walk' => 'Paseo del granjero',
        'Rope_Jumping' => 'Cuerda a saltar', 'Bicycling_Stationary' => 'Bicicleta estática',
        'Elliptical_Trainer' => 'Elíptica', 'Rowing_Stationary' => 'Remo en máquina',
        'Running_Treadmill' => 'Correr en cinta', 'Walking_Treadmill' => 'Caminar en cinta',
    ];
    private const BODYWEIGHT_IDS = [
        'Ab_Roller', 'Balance_Board', 'Band_Assisted_Pull-Up', 'Bodyweight_Mid_Row',
        'Decline_Push-Up', 'Dips_-_Chest_Version', 'Donkey_Calf_Raises', 'Floor_Glute-Ham_Raise',
        'Gironda_Sternum_Chins', 'Hyperextensions_Back_Extensions', 'Inverted_Row',
        'Inverted_Row_with_Straps', 'Kipping_Muscle_Up', 'Knee_Hip_Raise_On_Parallel_Bars',
        'London_Bridges', 'Mixed_Grip_Chin', 'Muscle_Up', 'One_Arm_Chin-Up', 'Parallel_Bar_Dip',
        'Platform_Hamstring_Slides', 'Ring_Dips', 'Rope_Climb', 'Scapular_Pull-Up',
        'Side_To_Side_Chins', 'Single-Leg_High_Box_Squat', 'Suspended_Fallout', 'Suspended_Push-Up',
        'Suspended_Reverse_Crunch', 'Suspended_Row', 'Suspended_Split_Squat',
    ];
    private const CARRY_IDS = [
        'Farmers_Walk', 'Rickshaw_Carry', 'Yoke_Walk', 'Conans_Wheel', 'Backward_Drag',
        'Bear_Crawl_Sled_Drags', 'Sled_Drag_-_Harness', 'Sled_Push', 'Sled_Overhead_Backward_Walk', 'Prowler_Sprint',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {}

    /**
     * The CLI always uses the bundled snapshot; an explicit path also supports offline validation/tests.
     * @return array{total: int, created: int, adopted: int, unchanged: int, ambiguous: int, dry_run: bool}
     */
    public function import(bool $dryRun = false, ?string $snapshotPath = null): array
    {
        $rows = $this->readSnapshot($snapshotPath ?? $this->projectDir.self::SNAPSHOT);
        if ($dryRun) {
            return $this->plan($rows, true)['result'];
        }

        return $this->em->wrapInTransaction(function () use ($rows): array {
            // Build the complete plan before changing even one managed entity.
            $plan = $this->plan($rows, false);
            foreach ($plan['actions'] as [$row, $existing]) {
                if ($existing instanceof Exercise) {
                    // Adoption adds provenance only: labels, media, instructions and tracking are user-owned.
                    $existing->setSourceId(self::SOURCE_PREFIX.$row['id'])->setSourceName($row['name']);
                    continue;
                }
                $exercise = (new Exercise())
                    ->setSourceId(self::SOURCE_PREFIX.$row['id'])
                    ->setSourceName($row['name'])
                    ->setName(self::DISPLAY_NAMES[$row['id']] ?? $row['name'])
                    ->setMuscleGroup($row['category'] === 'cardio' ? 'Cardio' : self::MUSCLES[$row['primaryMuscles'][0]])
                    ->setEquipment($row['equipment'] === null ? null : self::EQUIPMENT[$row['equipment']])
                    ->setDescription($row['instructions'] === [] ? null : implode("\n\n", $row['instructions']))
                    ->setImageUrl($row['images'] === [] ? null : self::IMAGE_BASE.$row['images'][0])
                    ->setTrackingType($this->trackingType($row));
                $this->em->persist($exercise);
            }
            // wrapInTransaction flushes and commits, or rolls back the entire import on any failure.
            return $plan['result'];
        });
    }

    private function readSnapshot(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('No se puede leer la instantánea local de ejercicios: '.$path);
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \InvalidArgumentException('No se pudo leer la instantánea local de ejercicios.');
        }
        try {
            $rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException('La instantánea no contiene JSON válido.', 0, $error);
        }
        if (!is_array($rows) || !array_is_list($rows) || count($rows) !== self::EXPECTED_COUNT) {
            throw new \InvalidArgumentException('La instantánea debe contener exactamente '.self::EXPECTED_COUNT.' ejercicios; no se importará un catálogo parcial.');
        }
        $ids = [];
        foreach ($rows as $index => $row) {
            $label = 'Ejercicio #'.($index + 1);
            if (!is_array($row)) {
                throw new \InvalidArgumentException($label.': registro inválido.');
            }
            foreach (['id', 'name', 'category', 'level'] as $field) {
                if (!isset($row[$field]) || !is_string($row[$field]) || trim($row[$field]) === '') {
                    throw new \InvalidArgumentException($label.': campo '.$field.' inválido.');
                }
            }
            $id = $row['id'];
            if (!preg_match('/^[A-Za-z0-9_-]+$/D', $id) || strlen(self::SOURCE_PREFIX.$id) > 255 || mb_strlen($row['name']) > 255) {
                throw new \InvalidArgumentException($label.': identidad o nombre inválido.');
            }
            if (isset($ids[$id])) {
                throw new \InvalidArgumentException('ID de origen duplicado: '.$id);
            }
            $ids[$id] = true;
            foreach (['equipment', 'force', 'mechanic'] as $field) {
                if (!array_key_exists($field, $row) || ($row[$field] !== null && !is_string($row[$field]))) {
                    throw new \InvalidArgumentException($id.': campo '.$field.' inválido.');
                }
            }
            if (($row['equipment'] !== null && !isset(self::EQUIPMENT[$row['equipment']]))
                || !in_array($row['force'], [null, 'push', 'pull', 'static'], true)
                || !in_array($row['mechanic'], [null, 'compound', 'isolation'], true)
                || !in_array($row['level'], ['beginner', 'intermediate', 'expert'], true)
                || !in_array($row['category'], ['strength', 'stretching', 'cardio', 'plyometrics', 'powerlifting', 'olympic weightlifting', 'strongman'], true)) {
                throw new \InvalidArgumentException($id.': clasificación desconocida; no se inventarán equivalencias.');
            }
            foreach (['primaryMuscles', 'secondaryMuscles', 'instructions', 'images'] as $field) {
                if (!isset($row[$field]) || !is_array($row[$field]) || !array_is_list($row[$field])
                    || ($field === 'primaryMuscles' && $row[$field] === [])) {
                    throw new \InvalidArgumentException($id.': lista '.$field.' inválida.');
                }
                foreach ($row[$field] as $value) {
                    if (!is_string($value) || ($field !== 'instructions' && trim($value) === '')) {
                        throw new \InvalidArgumentException($id.': valor de '.$field.' inválido.');
                    }
                    if (in_array($field, ['primaryMuscles', 'secondaryMuscles'], true) && !isset(self::MUSCLES[$value])) {
                        throw new \InvalidArgumentException($id.': músculo desconocido: '.$value);
                    }
                    if ($field === 'images' && (!preg_match('~^'.preg_quote($id, '~').'/[0-9]+\.jpg$~D', $value)
                        || strlen(self::IMAGE_BASE.$value) > 500)) {
                        throw new \InvalidArgumentException($id.': ruta de imagen inválida.');
                    }
                }
            }
            $instructions = array_values(array_filter(array_map('trim', $row['instructions']), static fn (string $instruction): bool => $instruction !== ''));
            $rows[$index]['instructions'] = $instructions;
            if (strlen(implode("\n\n", $instructions)) > 65535) {
                throw new \InvalidArgumentException($id.': instrucciones demasiado largas.');
            }
        }
        if (!isset($ids['Dumbbell_Bench_Press'])) {
            throw new \InvalidArgumentException('Falta Dumbbell_Bench_Press en la instantánea.');
        }
        return $rows;
    }

    private function plan(array $rows, bool $dryRun): array
    {
        $bySource = $byImage = $byName = [];
        foreach ($this->em->getRepository(Exercise::class)->findAll() as $exercise) {
            $sourceId = $exercise->getSourceId();
            if ($sourceId !== null) {
                if (isset($bySource[$sourceId])) {
                    throw new \RuntimeException('Identidad de origen duplicada en el catálogo: '.$sourceId);
                }
                $bySource[$sourceId] = $exercise;
                continue;
            }
            $key = spl_object_id($exercise);
            foreach (array_unique([$exercise->getImageUrl(), $exercise->getGifUrl()]) as $url) {
                $image = $this->sourceImagePath($url);
                if ($image !== null) {
                    $byImage[$image][$key] = $exercise;
                }
            }
            $equipment = $this->normalizeEquipment($exercise->getEquipment());
            if ($equipment !== null) {
                foreach ([$exercise->getName(), $exercise->getSourceName()] as $name) {
                    if ($name !== null && trim($name) !== '') {
                        $byName[$this->normalize($name).'|'.$equipment][$key] = $exercise;
                    }
                }
            }
        }
        $candidates = $claims = [];
        foreach ($rows as $row) {
            if (isset($bySource[self::SOURCE_PREFIX.$row['id']])) {
                continue;
            }
            $matches = [];
            foreach ($row['images'] as $image) {
                $matches += $byImage[$image] ?? [];
            }
            $equipment = $row['equipment'] === null ? null : $this->normalize(self::EQUIPMENT[$row['equipment']]);
            if ($equipment !== null) {
                foreach ([$row['name'], self::DISPLAY_NAMES[$row['id']] ?? $row['name']] as $name) {
                    $matches += $byName[$this->normalize($name).'|'.$equipment] ?? [];
                }
            }
            $candidates[$row['id']] = $matches;
            foreach ($matches as $key => $exercise) {
                $claims[$key] = ($claims[$key] ?? 0) + 1;
            }
        }
        $result = ['total' => count($rows), 'created' => 0, 'adopted' => 0, 'unchanged' => 0, 'ambiguous' => 0, 'dry_run' => $dryRun];
        $actions = [];
        foreach ($rows as $row) {
            if (isset($bySource[self::SOURCE_PREFIX.$row['id']])) {
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

    private function sourceImagePath(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'raw.githubusercontent.com'
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['port'])) {
            return null;
        }
        if (preg_match('~^/yuhonas/free-exercise-db/(?:main|'.self::REVISION.')/exercises/([A-Za-z0-9_-]+/[0-9]+\.jpg)$~D', $parts['path'] ?? '', $matches)) {
            return $matches[1];
        }
        return null;
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
        $normalized = $this->normalize($value);
        foreach (self::EQUIPMENT as $source => $display) {
            if ($normalized === $this->normalize($source) || $normalized === $this->normalize($display)) {
                return $this->normalize($display);
            }
        }
        return $normalized === 'bodyweight' || $normalized === 'peso corporal' ? $this->normalize('Ninguno') : $normalized;
    }

    private function trackingType(array $row): string
    {
        if ($row['category'] === 'stretching' || $row['force'] === 'static') {
            return Exercise::TRACKING_DURATION;
        }
        if (in_array($row['id'], self::CARRY_IDS, true)) {
            return Exercise::TRACKING_WEIGHT_DISTANCE;
        }
        if ($row['category'] === 'cardio') {
            return $row['id'] === 'Rope_Jumping' ? Exercise::TRACKING_DURATION : Exercise::TRACKING_DISTANCE_DURATION;
        }
        if ($row['id'] === 'Battling_Ropes') {
            return Exercise::TRACKING_DURATION;
        }
        if ($row['equipment'] === 'body only' || $row['equipment'] === null
            || ($row['category'] === 'plyometrics' && $row['equipment'] === 'other')
            || in_array($row['id'], self::BODYWEIGHT_IDS, true)) {
            return Exercise::TRACKING_BODYWEIGHT_REPS;
        }
        return Exercise::TRACKING_WEIGHT_REPS;
    }
}
