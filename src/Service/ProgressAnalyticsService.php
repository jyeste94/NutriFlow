<?php

namespace App\Service;

use App\Entity\Exercise;
use App\Entity\MealDiary;
use App\Entity\Measurement;
use App\Entity\User;
use App\Entity\WorkoutSession;
use App\Entity\WorkoutSetLog;
use Doctrine\ORM\EntityManagerInterface;

class ProgressAnalyticsService
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    /**
     * Resolve start and end dates for a period token and its preceding comparison period.
     *
     * @return array{
     *     period: string,
     *     current: array{start: \DateTimeImmutable, end: \DateTimeImmutable, days: int},
     *     previous: array{start: \DateTimeImmutable, end: \DateTimeImmutable, days: int}
     * }
     */
    public function resolveDateRanges(string $period = '4w'): array
    {
        $now = new \DateTimeImmutable('today 23:59:59');

        $days = match ($period) {
            '3m' => 90,
            '6m' => 180,
            '1y' => 365,
            default => 28, // '4w'
        };

        $currentStart = $now->modify(sprintf('-%d days', $days - 1))->setTime(0, 0, 0);
        $currentEnd = $now;

        $prevEnd = $currentStart->modify('-1 second');
        $prevStart = $prevEnd->modify(sprintf('-%d days', $days - 1))->setTime(0, 0, 0);

        return [
            'period' => in_array($period, ['4w', '3m', '6m', '1y'], true) ? $period : '4w',
            'current' => [
                'start' => $currentStart,
                'end' => $currentEnd,
                'days' => $days,
            ],
            'previous' => [
                'start' => $prevStart,
                'end' => $prevEnd,
                'days' => $days,
            ],
        ];
    }

    /**
     * Top-level summary of all 4 pillars for quick glance cards.
     */
    public function getSummary(User $user, string $period = '4w'): array
    {
        $ranges = $this->resolveDateRanges($period);

        $body = $this->getBodyProgress($user, $period, 'weight_kg');
        $training = $this->getTrainingProgress($user, $period);
        $nutrition = $this->getNutritionProgress($user, $period);
        $strength = $this->getStrengthProgress($user, $period);

        return [
            'period' => $ranges['period'],
            'weight_trend' => [
                'current_kg' => $body['summary']['current_value'],
                'initial_kg' => $body['summary']['initial_value'],
                'delta_kg' => $body['summary']['delta'],
                'direction' => $body['summary']['direction'],
                'data_points' => count($body['data_points']),
            ],
            'training_consistency' => [
                'total_workouts' => $training['summary']['total_workouts'],
                'workouts_per_week' => $training['summary']['workouts_per_week'],
                'prev_total_workouts' => $training['comparison']['previous_workouts'],
                'delta_workouts' => $training['comparison']['delta_workouts'],
                'delta_pct' => $training['comparison']['delta_pct'],
            ],
            'nutrition_adherence' => [
                'days_tracked' => $nutrition['summary']['days_tracked'],
                'calorie_adherence_pct' => $nutrition['summary']['calorie_adherence_pct'],
                'protein_adherence_pct' => $nutrition['summary']['protein_adherence_pct'],
                'overall_adherence_pct' => $nutrition['summary']['overall_adherence_pct'],
            ],
            'strength_progression' => [
                'exercise_name' => $strength['exercise'] ? $strength['exercise']['name'] : null,
                'current_e1rm' => $strength['summary']['current_e1rm'],
                'delta_e1rm' => $strength['summary']['delta_e1rm'],
                'prs_count' => count($strength['prs']),
            ],
        ];
    }

    /**
     * Body composition progress with real points and 7-day rolling average trendline.
     */
    public function getBodyProgress(User $user, string $period = '4w', string $metric = 'weight_kg'): array
    {
        $ranges = $this->resolveDateRanges($period);
        $currentStart = $ranges['current']['start'];
        $currentEnd = $ranges['current']['end'];

        $allowedMetrics = [
            'weight_kg' => 'weightKg',
            'body_fat_pct' => 'bodyFatPct',
            'waist_cm' => 'waistCm',
            'chest_cm' => 'chestCm',
            'hips_cm' => 'hipsCm',
            'arm_cm' => 'armCm',
            'thigh_cm' => 'thighCm',
            'calf_cm' => 'calfCm',
        ];

        $field = $allowedMetrics[$metric] ?? 'weightKg';

        /** @var Measurement[] $measurements */
        $measurements = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Measurement::class, 'm')
            ->where('m.user = :user')
            ->andWhere('m.date >= :start')
            ->andWhere('m.date <= :end')
            ->setParameter('user', $user)
            ->setParameter('start', $currentStart)
            ->setParameter('end', $currentEnd)
            ->orderBy('m.date', 'ASC')
            ->getQuery()
            ->getResult();

        $dataPoints = [];
        $getter = 'get' . ucfirst($field);

        foreach ($measurements as $m) {
            $val = $m->$getter();
            if ($val !== null && $val > 0) {
                $dataPoints[] = [
                    'date' => $m->getDate()->format('Y-m-d'),
                    'datetime' => $m->getDate()->format(\DateTimeInterface::ATOM),
                    'value' => round((float) $val, 2),
                ];
            }
        }

        // Compute 7-day rolling average trendline when we have at least 4 points
        $trendline = [];
        $count = count($dataPoints);
        if ($count >= 4) {
            for ($i = 0; $i < $count; $i++) {
                $currentDate = new \DateTimeImmutable($dataPoints[$i]['date']);
                $windowStart = $currentDate->modify('-6 days');

                $windowValues = [];
                for ($j = 0; $j <= $i; $j++) {
                    $ptDate = new \DateTimeImmutable($dataPoints[$j]['date']);
                    if ($ptDate >= $windowStart && $ptDate <= $currentDate) {
                        $windowValues[] = $dataPoints[$j]['value'];
                    }
                }

                if (!empty($windowValues)) {
                    $avg = array_sum($windowValues) / count($windowValues);
                    $trendline[] = [
                        'date' => $dataPoints[$i]['date'],
                        'value' => round($avg, 2),
                    ];
                }
            }
        }

        $initial = $count > 0 ? $dataPoints[0]['value'] : null;
        $current = $count > 0 ? $dataPoints[$count - 1]['value'] : null;
        $delta = ($initial !== null && $current !== null) ? round($current - $initial, 2) : null;

        $values = array_column($dataPoints, 'value');
        $min = !empty($values) ? min($values) : null;
        $max = !empty($values) ? max($values) : null;

        $direction = 'stable';
        if ($delta !== null) {
            if ($delta > 0.3) {
                $direction = 'up';
            } elseif ($delta < -0.3) {
                $direction = 'down';
            }
        }

        return [
            'period' => $ranges['period'],
            'metric' => $metric,
            'data_points' => $dataPoints,
            'trendline' => $trendline,
            'summary' => [
                'initial_value' => $initial,
                'current_value' => $current,
                'delta' => $delta,
                'direction' => $direction,
                'min' => $min,
                'max' => $max,
                'count' => $count,
            ],
        ];
    }

    /**
     * Training consistency, weekly distribution bars, volume, and period comparison.
     */
    public function getTrainingProgress(User $user, string $period = '4w'): array
    {
        $ranges = $this->resolveDateRanges($period);
        $currentStart = $ranges['current']['start'];
        $currentEnd = $ranges['current']['end'];
        $prevStart = $ranges['previous']['start'];
        $prevEnd = $ranges['previous']['end'];
        $days = $ranges['current']['days'];

        // Current sessions
        /** @var WorkoutSession[] $currentSessions */
        $currentSessions = $this->em->createQueryBuilder()
            ->select('s', 'sl', 'e')
            ->from(WorkoutSession::class, 's')
            ->leftJoin('s.sets', 'sl')
            ->leftJoin('sl.exercise', 'e')
            ->where('s.user = :user')
            ->andWhere('s.date >= :start')
            ->andWhere('s.date <= :end')
            ->setParameter('user', $user)
            ->setParameter('start', $currentStart)
            ->setParameter('end', $currentEnd)
            ->orderBy('s.date', 'ASC')
            ->getQuery()
            ->getResult();

        // Previous sessions count and volume for comparison
        /** @var WorkoutSession[] $prevSessions */
        $prevSessions = $this->em->createQueryBuilder()
            ->select('s', 'sl')
            ->from(WorkoutSession::class, 's')
            ->leftJoin('s.sets', 'sl')
            ->where('s.user = :user')
            ->andWhere('s.date >= :start')
            ->andWhere('s.date <= :end')
            ->setParameter('user', $user)
            ->setParameter('start', $prevStart)
            ->setParameter('end', $prevEnd)
            ->getQuery()
            ->getResult();

        $totalWorkouts = count($currentSessions);
        $totalDuration = 0;
        $totalVolume = 0.0;

        foreach ($currentSessions as $session) {
            $totalDuration += $session->getDurationMinutes() ?? 0;
            foreach ($session->getSets() as $set) {
                if ($set->isCompleted() && $set->getWeight() > 0 && $set->getReps() > 0) {
                    $totalVolume += $set->getWeight() * $set->getReps();
                }
            }
        }

        $prevWorkouts = count($prevSessions);
        $prevVolume = 0.0;
        foreach ($prevSessions as $session) {
            foreach ($session->getSets() as $set) {
                if ($set->isCompleted() && $set->getWeight() > 0 && $set->getReps() > 0) {
                    $prevVolume += $set->getWeight() * $set->getReps();
                }
            }
        }

        $deltaWorkouts = $totalWorkouts - $prevWorkouts;
        $deltaPct = $prevWorkouts > 0 ? round(($deltaWorkouts / $prevWorkouts) * 100, 1) : null;
        $workoutsPerWeek = round($totalWorkouts / ($days / 7.0), 1);

        // Weekly distribution buckets
        $numWeeks = max(1, (int) ceil($days / 7));
        $weeklyBuckets = [];

        for ($w = 0; $w < $numWeeks; $w++) {
            $bucketStart = $currentStart->modify(sprintf('+%d days', $w * 7));
            $bucketEnd = $bucketStart->modify('+6 days 23:59:59');
            if ($bucketEnd > $currentEnd) {
                $bucketEnd = $currentEnd;
            }

            $wCount = 0;
            $wVolume = 0.0;
            $wDuration = 0;

            foreach ($currentSessions as $s) {
                $sDate = $s->getDate();
                if ($sDate >= $bucketStart && $sDate <= $bucketEnd) {
                    $wCount++;
                    $wDuration += $s->getDurationMinutes() ?? 0;
                    foreach ($s->getSets() as $set) {
                        if ($set->isCompleted() && $set->getWeight() > 0 && $set->getReps() > 0) {
                            $wVolume += $set->getWeight() * $set->getReps();
                        }
                    }
                }
            }

            $weeklyBuckets[] = [
                'week_number' => $w + 1,
                'label' => sprintf('Sem %d', $w + 1),
                'date_start' => $bucketStart->format('Y-m-d'),
                'date_end' => $bucketEnd->format('Y-m-d'),
                'count' => $wCount,
                'volume_kg' => round($wVolume, 1),
                'duration_minutes' => $wDuration,
            ];
        }

        return [
            'period' => $ranges['period'],
            'summary' => [
                'total_workouts' => $totalWorkouts,
                'workouts_per_week' => $workoutsPerWeek,
                'total_duration_minutes' => $totalDuration,
                'avg_duration_minutes' => $totalWorkouts > 0 ? (int) round($totalDuration / $totalWorkouts) : 0,
                'total_volume_kg' => round($totalVolume, 1),
            ],
            'comparison' => [
                'previous_workouts' => $prevWorkouts,
                'delta_workouts' => $deltaWorkouts,
                'delta_pct' => $deltaPct,
                'previous_volume_kg' => round($prevVolume, 1),
            ],
            'weekly_distribution' => $weeklyBuckets,
        ];
    }

    /**
     * Strength progression using Epley e1RM formula for exercises performed by user.
     */
    public function getStrengthProgress(User $user, string $period = '4w', ?string $exerciseId = null): array
    {
        $ranges = $this->resolveDateRanges($period);
        $currentStart = $ranges['current']['start'];
        $currentEnd = $ranges['current']['end'];

        // Find all distinct exercises with completed sets for this user
        $exercisesRaw = $this->em->createQueryBuilder()
            ->select('DISTINCT e.id, e.name, e.muscleGroup, COUNT(sl.id) as set_count')
            ->from(WorkoutSetLog::class, 'sl')
            ->join('sl.session', 's')
            ->join('sl.exercise', 'e')
            ->where('s.user = :user')
            ->andWhere('sl.completed = :completed')
            ->andWhere('sl.weight > 0')
            ->andWhere('sl.reps >= 1')
            ->setParameter('user', $user)
            ->setParameter('completed', true)
            ->groupBy('e.id, e.name, e.muscleGroup')
            ->orderBy('set_count', 'DESC')
            ->getQuery()
            ->getResult();

        $exercisesList = [];
        $selectedExerciseId = $exerciseId;

        foreach ($exercisesRaw as $row) {
            $idStr = (string) $row['id'];
            $exercisesList[] = [
                'id' => $idStr,
                'name' => $row['name'],
                'category' => $row['muscleGroup'] ?? 'General',
                'set_count' => (int) $row['set_count'],
            ];
            if ($selectedExerciseId === null) {
                $selectedExerciseId = $idStr;
            }
        }

        if (empty($exercisesList) || !$selectedExerciseId) {
            return [
                'period' => $ranges['period'],
                'available_exercises' => [],
                'exercise' => null,
                'history' => [],
                'prs' => [],
                'summary' => [
                    'current_e1rm' => null,
                    'initial_e1rm' => null,
                    'delta_e1rm' => null,
                    'max_e1rm' => null,
                    'max_weight' => null,
                ],
            ];
        }

        // Fetch sets for selected exercise within period and all-time
        /** @var WorkoutSetLog[] $periodSets */
        $periodSets = $this->em->createQueryBuilder()
            ->select('sl', 's', 'e')
            ->from(WorkoutSetLog::class, 'sl')
            ->join('sl.session', 's')
            ->join('sl.exercise', 'e')
            ->where('s.user = :user')
            ->andWhere('e.id = :exerciseId')
            ->andWhere('sl.completed = :completed')
            ->andWhere('sl.weight > 0')
            ->andWhere('sl.reps >= 1')
            ->andWhere('sl.reps <= 12') // Valid physiological range for e1RM
            ->andWhere('s.date >= :start')
            ->andWhere('s.date <= :end')
            ->setParameter('user', $user)
            ->setParameter('exerciseId', $selectedExerciseId)
            ->setParameter('completed', true)
            ->setParameter('start', $currentStart)
            ->setParameter('end', $currentEnd)
            ->orderBy('s.date', 'ASC')
            ->getQuery()
            ->getResult();

        // Calculate e1RM timeline (best e1RM per session date)
        $dailyBest = [];
        $maxWeightRecord = null;
        $maxE1rmRecord = null;
        $maxRepsRecord = null;

        foreach ($periodSets as $set) {
            $weight = $set->getWeight();
            $reps = $set->getReps();
            // Epley formula: 1RM = weight * (1 + reps / 30)
            $e1rm = $reps === 1 ? $weight : round($weight * (1.0 + $reps / 30.0), 1);
            $dateStr = $set->getSession()->getDate()->format('Y-m-d');

            if (!isset($dailyBest[$dateStr]) || $e1rm > $dailyBest[$dateStr]['e1rm']) {
                $dailyBest[$dateStr] = [
                    'date' => $dateStr,
                    'e1rm' => $e1rm,
                    'best_weight' => $weight,
                    'best_reps' => $reps,
                ];
            }

            // Track period PRs
            if ($maxWeightRecord === null || $weight > $maxWeightRecord['weight']) {
                $maxWeightRecord = [
                    'type' => 'max_weight',
                    'label' => 'Carga máxima',
                    'value' => sprintf('%.1f kg', $weight),
                    'weight' => $weight,
                    'reps' => $reps,
                    'date' => $dateStr,
                ];
            }

            if ($maxE1rmRecord === null || $e1rm > $maxE1rmRecord['e1rm']) {
                $maxE1rmRecord = [
                    'type' => 'max_e1rm',
                    'label' => '1RM Estimado',
                    'value' => sprintf('%.1f kg', $e1rm),
                    'e1rm' => $e1rm,
                    'weight' => $weight,
                    'reps' => $reps,
                    'date' => $dateStr,
                ];
            }

            if ($maxRepsRecord === null || $reps > $maxRepsRecord['reps']) {
                $maxRepsRecord = [
                    'type' => 'max_reps',
                    'label' => 'Más repeticiones',
                    'value' => sprintf('%d reps (%g kg)', $reps, $weight),
                    'reps' => $reps,
                    'weight' => $weight,
                    'date' => $dateStr,
                ];
            }
        }

        $history = array_values($dailyBest);
        $historyCount = count($history);

        $initialE1rm = $historyCount > 0 ? $history[0]['e1rm'] : null;
        $currentE1rm = $historyCount > 0 ? $history[$historyCount - 1]['e1rm'] : null;
        $deltaE1rm = ($initialE1rm !== null && $currentE1rm !== null) ? round($currentE1rm - $initialE1rm, 1) : null;
        $e1rmValues = array_column($history, 'e1rm');
        $maxE1rm = !empty($e1rmValues) ? max($e1rmValues) : null;

        $prs = array_filter([$maxE1rmRecord, $maxWeightRecord, $maxRepsRecord]);

        // Find selected exercise metadata
        $selectedMeta = null;
        foreach ($exercisesList as $e) {
            if ($e['id'] === $selectedExerciseId) {
                $selectedMeta = $e;
                break;
            }
        }

        return [
            'period' => $ranges['period'],
            'available_exercises' => $exercisesList,
            'exercise' => $selectedMeta,
            'history' => $history,
            'prs' => array_values($prs),
            'summary' => [
                'current_e1rm' => $currentE1rm,
                'initial_e1rm' => $initialE1rm,
                'delta_e1rm' => $deltaE1rm,
                'max_e1rm' => $maxE1rm,
                'max_weight' => $maxWeightRecord ? $maxWeightRecord['weight'] : null,
            ],
        ];
    }

    /**
     * Nutrition adherence comparing tracked diaries with user goals (+-10% tolerance).
     */
    public function getNutritionProgress(User $user, string $period = '4w'): array
    {
        $ranges = $this->resolveDateRanges($period);
        $currentStart = $ranges['current']['start'];
        $currentEnd = $ranges['current']['end'];
        $prevStart = $ranges['previous']['start'];
        $prevEnd = $ranges['previous']['end'];
        $daysInPeriod = $ranges['current']['days'];

        // Get user preferences / goals
        $goals = $this->getUserGoals($user);
        $targetCalories = $goals['calorie_goal'] ?? 2000;
        $targetProteins = $goals['protein_goal'] ?? 140;

        /** @var MealDiary[] $diaries */
        $diaries = $this->em->createQueryBuilder()
            ->select('d')
            ->from(MealDiary::class, 'd')
            ->where('d.user = :user')
            ->andWhere('d.date >= :start')
            ->andWhere('d.date <= :end')
            ->setParameter('user', $user)
            ->setParameter('start', $currentStart->setTime(0, 0, 0))
            ->setParameter('end', $currentEnd->setTime(23, 59, 59))
            ->orderBy('d.date', 'ASC')
            ->getQuery()
            ->getResult();

        $dailyLogs = [];
        $calAdherentDays = 0;
        $protAdherentDays = 0;
        $overallAdherentDays = 0;
        $sumCalories = 0.0;
        $sumProteins = 0.0;
        $sumCarbs = 0.0;
        $sumFats = 0.0;

        foreach ($diaries as $d) {
            $cal = (float) $d->getTotalCalories();
            $prot = (float) $d->getTotalProteins();
            $carbs = (float) $d->getTotalCarbs();
            $fats = (float) $d->getTotalFats();

            // Calorie adherence: +-10% of target
            $isCalAdherent = false;
            if ($targetCalories > 0) {
                $diffPct = abs($cal - $targetCalories) / $targetCalories;
                $isCalAdherent = $diffPct <= 0.10;
            }

            // Protein adherence: >= 90% of target
            $isProtAdherent = false;
            if ($targetProteins > 0) {
                $isProtAdherent = $prot >= (0.90 * $targetProteins);
            }

            $isOverallAdherent = $isCalAdherent && $isProtAdherent;

            if ($isCalAdherent) $calAdherentDays++;
            if ($isProtAdherent) $protAdherentDays++;
            if ($isOverallAdherent) $overallAdherentDays++;

            $sumCalories += $cal;
            $sumProteins += $prot;
            $sumCarbs += $carbs;
            $sumFats += $fats;

            $dailyLogs[] = [
                'date' => $d->getDate()->format('Y-m-d'),
                'calories' => round($cal),
                'target_calories' => $targetCalories,
                'proteins' => round($prot, 1),
                'target_proteins' => $targetProteins,
                'carbs' => round($carbs, 1),
                'fats' => round($fats, 1),
                'is_calorie_adherent' => $isCalAdherent,
                'is_protein_adherent' => $isProtAdherent,
                'is_overall_adherent' => $isOverallAdherent,
            ];
        }

        $daysTracked = count($diaries);
        $calAdherencePct = $daysTracked > 0 ? round(($calAdherentDays / $daysTracked) * 100, 1) : 0;
        $protAdherencePct = $daysTracked > 0 ? round(($protAdherentDays / $daysTracked) * 100, 1) : 0;
        $overallAdherencePct = $daysTracked > 0 ? round(($overallAdherentDays / $daysTracked) * 100, 1) : 0;

        // Previous period for comparison
        /** @var MealDiary[] $prevDiaries */
        $prevDiaries = $this->em->createQueryBuilder()
            ->select('d')
            ->from(MealDiary::class, 'd')
            ->where('d.user = :user')
            ->andWhere('d.date >= :start')
            ->andWhere('d.date <= :end')
            ->setParameter('user', $user)
            ->setParameter('start', $prevStart->setTime(0, 0, 0))
            ->setParameter('end', $prevEnd->setTime(23, 59, 59))
            ->getQuery()
            ->getResult();

        $prevDaysTracked = count($prevDiaries);
        $prevCalAdherentDays = 0;
        foreach ($prevDiaries as $pd) {
            $pCal = (float) $pd->getTotalCalories();
            if ($targetCalories > 0 && abs($pCal - $targetCalories) / $targetCalories <= 0.10) {
                $prevCalAdherentDays++;
            }
        }
        $prevCalAdherencePct = $prevDaysTracked > 0 ? round(($prevCalAdherentDays / $prevDaysTracked) * 100, 1) : 0;

        return [
            'period' => $ranges['period'],
            'targets' => [
                'calorie_goal' => $targetCalories,
                'protein_goal' => $targetProteins,
            ],
            'summary' => [
                'days_in_period' => $daysInPeriod,
                'days_tracked' => $daysTracked,
                'calorie_adherent_days' => $calAdherentDays,
                'calorie_adherence_pct' => $calAdherencePct,
                'protein_adherent_days' => $protAdherentDays,
                'protein_adherence_pct' => $protAdherencePct,
                'overall_adherent_days' => $overallAdherentDays,
                'overall_adherence_pct' => $overallAdherentDays > 0 && $daysTracked > 0 ? round(($overallAdherentDays / $daysTracked) * 100, 1) : 0,
                'avg_calories' => $daysTracked > 0 ? round($sumCalories / $daysTracked) : 0,
                'avg_proteins' => $daysTracked > 0 ? round($sumProteins / $daysTracked, 1) : 0,
                'avg_carbs' => $daysTracked > 0 ? round($sumCarbs / $daysTracked, 1) : 0,
                'avg_fats' => $daysTracked > 0 ? round($sumFats / $daysTracked, 1) : 0,
            ],
            'comparison' => [
                'prev_days_tracked' => $prevDaysTracked,
                'prev_calorie_adherence_pct' => $prevCalAdherencePct,
                'delta_adherence_pct' => round($calAdherencePct - $prevCalAdherencePct, 1),
            ],
            'daily_logs' => $dailyLogs,
        ];
    }

    private function getUserGoals(User $user): array
    {
        try {
            $row = $this->em->getConnection()->fetchAssociative(
                'SELECT preferences FROM user_preferences WHERE user_id = ?',
                [$user->getId()->toRfc4122()]
            );
        } catch (\Throwable) {
            $row = null;
        }

        if (!$row || empty($row['preferences'])) {
            return [
                'calorie_goal' => 2000,
                'protein_goal' => 140,
            ];
        }

        $prefs = json_decode($row['preferences'], true);
        return [
            'calorie_goal' => !empty($prefs['calorie_goal']) ? (int) $prefs['calorie_goal'] : 2000,
            'protein_goal' => !empty($prefs['protein_goal']) ? (int) $prefs['protein_goal'] : 140,
        ];
    }
}
