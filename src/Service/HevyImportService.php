<?php

namespace App\Service;

use App\Entity\Exercise;
use App\Entity\User;
use App\Entity\WorkoutSession;
use App\Entity\WorkoutSetLog;
use Doctrine\ORM\EntityManagerInterface;

class HevyImportService
{
    private const LBS_TO_KG = 2.20462;

    private const EXPECTED_HEADERS = [
        'title', 'start_time', 'end_time', 'description',
        'exercise_title', 'superset_id', 'exercise_notes',
        'set_index', 'set_type', 'weight_lbs', 'reps',
        'distance_miles', 'duration_seconds', 'rpe',
    ];

    /** @var array<string, Exercise> */
    private array $exerciseCache = [];

    public function __construct(
        private EntityManagerInterface $em
    ) {}

    /**
     * @return array{sessions_created: int, sets_imported: int, exercises_created: int, sessions_skipped: int, errors: list<string>}
     */
    public function importWorkoutCsv(User $user, string $csvContent): array
    {
        $result = [
            'sessions_created' => 0,
            'sets_imported' => 0,
            'exercises_created' => 0,
            'sessions_skipped' => 0,
            'errors' => [],
        ];

        $rows = $this->parseCsv($csvContent);
        if (empty($rows)) {
            $result['errors'][] = 'No data rows found in CSV';
            return $result;
        }

        $headers = array_keys($rows[0]);
        $missing = array_diff(self::EXPECTED_HEADERS, $headers);
        if (!empty($missing)) {
            $result['errors'][] = 'Missing required columns: ' . implode(', ', $missing);
            return $result;
        }

        $workoutGroups = $this->groupBySession($rows);
        $this->warmExerciseCache();
        $existingSessionDates = $this->loadExistingSessionDates($user);

        $batchSize = 50;
        $count = 0;

        foreach ($workoutGroups as $key => $group) {
            $firstRow = $group[0];
            $startDate = $this->parseHevyDate($firstRow['start_time']);
            $endDate = $this->parseHevyDate($firstRow['end_time']);

            if (!$startDate) {
                $result['errors'][] = "Could not parse date: {$firstRow['start_time']}";
                continue;
            }

            $dateKey = $startDate->format('Y-m-d H:i');
            if (isset($existingSessionDates[$dateKey])) {
                $result['sessions_skipped']++;
                continue;
            }

            $session = new WorkoutSession();
            $session->setUser($user);
            $session->setDate($startDate);

            if ($endDate && $startDate) {
                $diffMinutes = (int) round(($endDate->getTimestamp() - $startDate->getTimestamp()) / 60);
                if ($diffMinutes > 0 && $diffMinutes < 600) {
                    $session->setDurationMinutes($diffMinutes);
                }
            }

            $this->em->persist($session);

            foreach ($group as $row) {
                $exerciseTitle = trim($row['exercise_title'] ?? '');
                if (empty($exerciseTitle)) {
                    continue;
                }

                $exercise = $this->resolveExercise($exerciseTitle, $result);

                $weightLbs = (float) ($row['weight_lbs'] ?? 0);
                $reps = (int) ($row['reps'] ?? 0);

                if ($reps < 1) {
                    continue;
                }

                $weightKg = round($weightLbs / self::LBS_TO_KG, 2);

                $setLog = new WorkoutSetLog();
                $setLog->setExercise($exercise);
                $setLog->setWeight($weightKg);
                $setLog->setReps($reps);
                $setLog->setCompleted(true);

                $session->addSet($setLog);
                $this->em->persist($setLog);

                $result['sets_imported']++;
            }

            $result['sessions_created']++;
            $existingSessionDates[$dateKey] = true;

            $count++;
            if ($count % $batchSize === 0) {
                $this->em->flush();
            }
        }

        $this->em->flush();

        return $result;
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseCsv(string $content): array
    {
        $rows = [];
        $lines = str_getcsv($content, "\n");
        if (empty($lines)) {
            return [];
        }

        $headerLine = array_shift($lines);
        $headers = str_getcsv($headerLine, ',');
        $headers = array_map('trim', $headers);
        if (!empty($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        }

        $headerCount = count($headers);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $fields = str_getcsv($line, ',');
            if (count($fields) < $headerCount) {
                $fields = array_pad($fields, $headerCount, '');
            } elseif (count($fields) > $headerCount) {
                $fields = array_slice($fields, 0, $headerCount);
            }

            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header] = trim($fields[$i] ?? '');
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param list<array<string, string>> $rows
     * @return array<string, list<array<string, string>>>
     */
    private function groupBySession(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = ($row['title'] ?? '') . '|' . ($row['start_time'] ?? '') . '|' . ($row['end_time'] ?? '');
            $groups[$key][] = $row;
        }
        return $groups;
    }

    private function parseHevyDate(string $dateStr): ?\DateTimeImmutable
    {
        $dateStr = trim($dateStr, '" ');
        if (empty($dateStr)) {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat('d M Y, H:i', $dateStr);
        if ($dt !== false) {
            return $dt;
        }

        $dt = \DateTimeImmutable::createFromFormat('d M Y H:i', $dateStr);
        if ($dt !== false) {
            return $dt;
        }

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateStr);
        if ($dt !== false) {
            return $dt;
        }

        return null;
    }

    private function resolveExercise(string $exerciseTitle, array &$result): Exercise
    {
        $normalized = mb_strtolower($exerciseTitle);

        if (isset($this->exerciseCache[$normalized])) {
            return $this->exerciseCache[$normalized];
        }

        $exercise = $this->em->createQueryBuilder()
            ->select('e')
            ->from(Exercise::class, 'e')
            ->where('LOWER(e.name) = :name')
            ->setParameter('name', $normalized)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($exercise) {
            $this->exerciseCache[$normalized] = $exercise;
            return $exercise;
        }

        $name = $exerciseTitle;
        $equipment = null;

        if (preg_match('/^(.+?)\s*\(([^)]+)\)\s*$/', $exerciseTitle, $matches)) {
            $name = trim($matches[1]);
            $equipment = trim($matches[2]);
        }

        $muscleGroup = $this->inferMuscleGroup($name);

        $exercise = new Exercise();
        $exercise->setName($name);
        $exercise->setMuscleGroup($muscleGroup);
        $exercise->setEquipment($equipment);

        $this->em->persist($exercise);
        $this->exerciseCache[$normalized] = $exercise;
        $result['exercises_created']++;

        return $exercise;
    }

    private function warmExerciseCache(): void
    {
        $exercises = $this->em->getRepository(Exercise::class)->findAll();
        foreach ($exercises as $exercise) {
            $key = mb_strtolower($exercise->getName());
            $this->exerciseCache[$key] = $exercise;
        }
    }

    /**
     * @return array<string, bool>
     */
    private function loadExistingSessionDates(User $user): array
    {
        $sessions = $this->em->getRepository(WorkoutSession::class)->findBy(['user' => $user]);
        $map = [];
        foreach ($sessions as $session) {
            $dateKey = $session->getDate()->format('Y-m-d H:i');
            $map[$dateKey] = true;
        }
        return $map;
    }

    private function inferMuscleGroup(string $name): string
    {
        $lower = mb_strtolower($name);

        $patterns = [
            'Chest' => ['bench press', 'chest', 'fly', 'flye', 'pec ', 'push up', 'push-up', 'pushup', 'incline press', 'decline press'],
            'Back' => ['row', 'pull up', 'pull-up', 'pullup', 'lat pull', 'deadlift', 'pulldown', 'back ext', 'chin up', 'chin-up'],
            'Shoulders' => ['shoulder', 'overhead press', 'ohp', 'lateral raise', 'front raise', 'face pull', 'upright row', 'military press', 'arnold'],
            'Legs' => ['squat', 'leg press', 'lunge', 'leg curl', 'leg ext', 'calf', 'hip thrust', 'glute', 'hamstring', 'quad', 'hack squat', 'bulgarian'],
            'Arms' => ['bicep', 'curl', 'tricep', 'pushdown', 'push down', 'skull crush', 'hammer', 'preacher', 'dip'],
            'Core' => ['ab ', 'abs ', 'crunch', 'plank', 'sit up', 'sit-up', 'leg raise', 'oblique', 'core', 'cable twist'],
            'Cardio' => ['treadmill', 'bike', 'elliptical', 'stair', 'rowing machine', 'run', 'jog', 'cycle', 'swim'],
        ];

        foreach ($patterns as $group => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return $group;
                }
            }
        }

        return 'Other';
    }
}
