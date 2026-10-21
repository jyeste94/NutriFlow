<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Command\ImportHevyExercisesCommand;
use App\Entity\Exercise;
use App\Entity\Routine;
use App\Entity\RoutineExercise;
use App\Entity\User;
use App\Entity\WorkoutSession;
use App\Entity\WorkoutSetLog;
use App\Service\ExerciseCatalogImporter;
use App\Service\HevyExerciseCatalogImporter;
use Symfony\Component\Console\Tester\CommandTester;

final class HevyExerciseCatalogImportTest extends ApiTestCase
{
    private function importer(): HevyExerciseCatalogImporter
    {
        return new HevyExerciseCatalogImporter($this->em, dirname(__DIR__, 2));
    }

    private function snapshot(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).HevyExerciseCatalogImporter::SNAPSHOT), true, 512, JSON_THROW_ON_ERROR);
    }

    private function hevy(string $id): Exercise
    {
        $exercise = $this->em->getRepository(Exercise::class)->findOneBy(['hevyId' => $id]);
        self::assertInstanceOf(Exercise::class, $exercise);
        return $exercise;
    }

    public function testFullSnapshotHas454SeparateIdentitiesRealNamesAndNoHevyMedia(): void
    {
        $snapshot = $this->snapshot();
        $result = $this->importer()->import();
        self::assertSame(454, $result['total']);
        self::assertSame(454, $result['created']);
        self::assertCount(454, array_unique(array_column($snapshot['exercises'], 'id')));
        self::assertCount(3, array_filter($snapshot['exercises'], static fn (array $row): bool => $row['is_archived']));
        $ids = [];
        foreach ($snapshot['exercises'] as $row) {
            $exercise = $this->hevy($row['id']);
            $ids[] = $exercise->getId()->toRfc4122();
            self::assertSame($row['name_es'], $exercise->getName());
            self::assertSame($row['name_es'], $exercise->getNameEs());
            self::assertSame($row['name_en'], $exercise->getNameEn());
            self::assertNull($exercise->getSourceId());
            self::assertNull($exercise->getSourceName());
            self::assertNull($exercise->getDescription());
            self::assertNull($exercise->getImageUrl());
            self::assertNull($exercise->getGifUrl());
            self::assertNull($exercise->getVideoUrl());
            if (in_array($row['exercise_type'], ['floors_duration', 'steps_duration'], true)) {
                self::assertSame(Exercise::TRACKING_DURATION, $exercise->getTrackingType());
            }
        }
        self::assertCount(454, array_unique($ids));
        self::assertSame('Máquina Escaladora', $this->hevy('4377A52A')->getName());
        self::assertSame('Máquina Escaladora', $this->hevy('4377A52B')->getName());
        self::assertSame('Máquina Escaladora', $this->hevy('4377A52C')->getName());
        self::assertSame(Exercise::TRACKING_DISTANCE_DURATION, $this->hevy('4377A52A')->getTrackingType());
        self::assertSame(Exercise::TRACKING_DURATION, $this->hevy('4377A52B')->getTrackingType());
        self::assertSame(Exercise::TRACKING_DURATION, $this->hevy('4377A52C')->getTrackingType());
        self::assertNotSame($this->hevy('6AC96645')->getId()->toRfc4122(), $this->hevy('878CD1D0')->getId()->toRfc4122());
    }

    public function testImportOverWholeFreeCatalogNeverReplacesItsMetadataOrDeletesVariants(): void
    {
        (new ExerciseCatalogImporter($this->em, dirname(__DIR__, 2)))->import();
        $before = [];
        foreach ($this->em->getRepository(Exercise::class)->findAll() as $exercise) {
            $before[$exercise->getSourceId()] = [
                $exercise->getId()->toRfc4122(), $exercise->getSourceName(), $exercise->getMuscleGroup(),
                $exercise->getEquipment(), $exercise->getDescription(), $exercise->getImageUrl(),
                $exercise->getGifUrl(), $exercise->getVideoUrl(), $exercise->getTrackingType(),
            ];
        }
        $result = $this->importer()->import();
        self::assertGreaterThanOrEqual(34, $result['adopted']);
        self::assertSame(454, $result['created'] + $result['adopted']);
        self::assertSame(876 + $result['created'], $this->em->getRepository(Exercise::class)->count([]));
        $this->em->clear();
        foreach ($before as $sourceId => $metadata) {
            $exercise = $this->em->getRepository(Exercise::class)->findOneBy(['sourceId' => $sourceId]);
            self::assertInstanceOf(Exercise::class, $exercise);
            self::assertSame($metadata, [
                $exercise->getId()->toRfc4122(), $exercise->getSourceName(), $exercise->getMuscleGroup(),
                $exercise->getEquipment(), $exercise->getDescription(), $exercise->getImageUrl(),
                $exercise->getGifUrl(), $exercise->getVideoUrl(), $exercise->getTrackingType(),
            ]);
        }
        self::assertSame('Press de Banca (Mancuerna)', $this->hevy('3601968B')->getName());
        self::assertSame(0, $this->importer()->import()['created']);
    }

    public function testAdoptionKeepsFreeMetadataUuidRoutineAndHistoryAndRerunNeverResetsEdits(): void
    {
        $press = (new Exercise())->setName('Press banca plano con mancuernas')->setMuscleGroup('Pecho')
            ->setSourceId(ExerciseCatalogImporter::SOURCE_PREFIX.'Dumbbell_Bench_Press')->setSourceName('Dumbbell Bench Press')
            ->setEquipment('Mancuernas')->setDescription('Instrucciones existentes')
            ->setImageUrl('https://example.org/press.jpg')->setGifUrl('https://example.org/press.gif')
            ->setVideoUrl('https://example.org/press.mp4')->setTrackingType(Exercise::TRACKING_DURATION);
        $user = (new User())->setFirebaseUid('hevy-catalog-user');
        $routine = (new Routine())->setName('Rutina existente')->setUser($user);
        $routineExercise = (new RoutineExercise())->setExercise($press)->setReps(8);
        $routine->addRoutineExercise($routineExercise);
        $session = (new WorkoutSession())->setUser($user)->setRoutine($routine);
        $set = (new WorkoutSetLog())->setExercise($press)->setWeight(20)->setReps(8);
        $session->addSet($set);
        foreach ([$press, $user, $routine, $session] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $oldId = $press->getId()->toRfc4122();
        $routineExerciseId = $routineExercise->getId();
        $setId = $set->getId();
        $result = $this->importer()->import();
        self::assertSame(453, $result['created']);
        self::assertSame(1, $result['adopted']);
        self::assertSame($oldId, $this->hevy('3601968B')->getId()->toRfc4122());
        self::assertSame('Press de Banca (Mancuerna)', $press->getName());
        self::assertSame(ExerciseCatalogImporter::SOURCE_PREFIX.'Dumbbell_Bench_Press', $press->getSourceId());
        self::assertSame('Dumbbell Bench Press', $press->getSourceName());
        self::assertSame('Instrucciones existentes', $press->getDescription());
        self::assertSame('https://example.org/press.jpg', $press->getImageUrl());
        self::assertSame('https://example.org/press.gif', $press->getGifUrl());
        self::assertSame('https://example.org/press.mp4', $press->getVideoUrl());
        self::assertSame('Mancuernas', $press->getEquipment());
        self::assertSame(Exercise::TRACKING_DURATION, $press->getTrackingType());
        $press->setName('Mi press editado')->setNameEn('English edit')->setNameEs('Edición española')
            ->setDescription('Edición')->setEquipment('Mi equipo')->setTrackingType(Exercise::TRACKING_BODYWEIGHT_REPS);
        $new = $this->hevy('79D0BB3A');
        $newId = $new->getId()->toRfc4122();
        $new->setName('Press barra personal')->setDescription('Personal');
        $this->em->flush();
        $rerun = $this->importer()->import();
        self::assertSame(0, $rerun['created']);
        self::assertSame(0, $rerun['adopted']);
        self::assertSame(454, $rerun['unchanged']);
        $this->em->clear();
        $press = $this->hevy('3601968B');
        self::assertSame('Mi press editado', $press->getName());
        self::assertSame('English edit', $press->getNameEn());
        self::assertSame('Edición española', $press->getNameEs());
        self::assertSame('Edición', $press->getDescription());
        self::assertSame('Mi equipo', $press->getEquipment());
        self::assertSame(Exercise::TRACKING_BODYWEIGHT_REPS, $press->getTrackingType());
        self::assertSame($newId, $this->hevy('79D0BB3A')->getId()->toRfc4122());
        self::assertSame('Press barra personal', $this->hevy('79D0BB3A')->getName());
        self::assertSame($oldId, $this->em->find(RoutineExercise::class, $routineExerciseId)->getExercise()->getId()->toRfc4122());
        self::assertSame($oldId, $this->em->find(WorkoutSetLog::class, $setId)->getExercise()->getId()->toRfc4122());
    }

    public function testExplicitMappingPreservesCustomLabelsAndDistinctBenchAndSquatQualifiers(): void
    {
        $map = [
            'Dumbbell_Bench_Press' => '3601968B',
            'Incline_Dumbbell_Press' => '07B38369',
            'Decline_Dumbbell_Bench_Press' => '18487FA7',
            'Barbell_Bench_Press_-_Medium_Grip' => '79D0BB3A',
            'Barbell_Incline_Bench_Press_-_Medium_Grip' => '50DFDFAB',
            'Decline_Barbell_Bench_Press' => 'DA0F0470',
            'Barbell_Squat' => 'D04AC939',
            'Barbell_Full_Squat' => '1283BBA6',
            'Bent_Over_Barbell_Row' => '55E6546F',
            'Barbell_Curl' => 'A5AC6449',
            'Dumbbell_Bicep_Curl' => '37FCC2BB',
        ];
        $existing = [];
        foreach ($map as $freeId => $hevyId) {
            $exercise = (new Exercise())->setName('Mi variante '.$freeId)->setMuscleGroup('Personal')
                ->setSourceId(ExerciseCatalogImporter::SOURCE_PREFIX.$freeId)->setSourceName('Source '.$freeId);
            $this->em->persist($exercise);
            $existing[$hevyId] = $exercise;
        }
        $this->em->flush();
        $result = $this->importer()->import();
        self::assertSame(count($map), $result['adopted']);
        foreach ($existing as $id => $exercise) {
            self::assertSame($exercise->getId()->toRfc4122(), $this->hevy($id)->getId()->toRfc4122());
            self::assertSame('Mi variante '.array_search($id, $map, true), $exercise->getName());
            self::assertSame('Personal', $exercise->getMuscleGroup());
        }
    }

    public function testExactNamesRequireCompatibleEquipmentAndAmbiguitiesStaySeparate(): void
    {
        $exact = (new Exercise())->setName('  PRESS BANCA PLANO CON MANCUERNAS  ')->setMuscleGroup('Pecho')->setEquipment('dumbbell');
        $near = (new Exercise())->setName('Press banca plano con mancuernas especial')->setMuscleGroup('Pecho')->setEquipment('dumbbell');
        $wrongEquipment = (new Exercise())->setName('Incline Bench Press (Dumbbell)')->setMuscleGroup('Pecho')->setEquipment('barbell');
        $sharedSpanish = (new Exercise())->setName('Press de Hombros (Mancuerna)')->setMuscleGroup('Hombro')->setEquipment('Mancuernas');
        $duplicates = [];
        for ($i = 0; $i < 2; ++$i) {
            $duplicates[] = (new Exercise())->setName('Bicep Curl (Barbell)')->setMuscleGroup('Bíceps')->setEquipment('barbell');
        }
        foreach ([$exact, $near, $wrongEquipment, $sharedSpanish, ...$duplicates] as $exercise) {
            $this->em->persist($exercise);
        }
        $this->em->flush();
        $result = $this->importer()->import();
        self::assertSame(1, $result['adopted']);
        self::assertSame(3, $result['ambiguous']);
        self::assertSame($exact->getId()->toRfc4122(), $this->hevy('3601968B')->getId()->toRfc4122());
        self::assertSame('Press de Banca (Mancuerna)', $exact->getName());
        foreach ([$near, $wrongEquipment, $sharedSpanish, ...$duplicates] as $exercise) {
            self::assertNull($exercise->getHevyId());
        }
    }

    public function testDryRunValidatesAndPlansWithoutWrites(): void
    {
        $existing = (new Exercise())->setName('Press banca plano con mancuernas')->setMuscleGroup('Pecho')->setEquipment('Mancuernas');
        $this->em->persist($existing);
        $this->em->flush();
        $tester = new CommandTester(new ImportHevyExercisesCommand($this->importer()));
        self::assertSame(0, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('454', $tester->getDisplay());
        self::assertStringContainsString('Simulación', $tester->getDisplay());
        self::assertSame(1, $this->em->getRepository(Exercise::class)->count([]));
        self::assertNull($existing->getHevyId());
        self::assertNull($existing->getNameEs());
        self::assertSame('Press banca plano con mancuernas', $existing->getName());
    }

    public function testAllValidationPrecedesAnyWrites(): void
    {
        $snapshot = $this->snapshot();
        $mutations = [
            static function (array &$data): void { array_pop($data['exercises']); },
            static function (array &$data): void { $data['expectedCount'] = '454'; },
            static function (array &$data): void { $data['exercises'][453]['id'] = $data['exercises'][0]['id']; },
            static function (array &$data): void { $data['exercises'][453]['name_es'] = ''; },
            static function (array &$data): void { $data['exercises'][453]['name_en'] = null; },
            static function (array &$data): void { $data['exercises'][453]['name_en'] = str_repeat('x', 256); },
            static function (array &$data): void { $data['exercises'][453]['equipment'] = 'invented'; },
            static function (array &$data): void { $data['exercises'][453]['exercise_type'] = 'invented'; },
            static function (array &$data): void { $data['exercises'][453]['primary_muscle_group'] = 'invented'; },
            static function (array &$data): void { $data['exercises'][453]['secondary_muscle_groups'] = [null]; },
            static function (array &$data): void { $data['exercises'][453]['is_archived'] = 1; },
            static function (array &$data): void { $data['exercises'][453]['full_bodyweight'] = 'false'; },
        ];
        $existing = (new Exercise())->setName('Press banca plano con mancuernas')->setMuscleGroup('Pecho')->setEquipment('Mancuernas');
        $this->em->persist($existing);
        $this->em->flush();
        foreach ($mutations as $mutate) {
            $data = $snapshot;
            $mutate($data);
            $path = tempnam(sys_get_temp_dir(), 'hevy-catalog-');
            try {
                file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
                try {
                    $this->importer()->import(false, $path);
                    self::fail('Malformed snapshots must fail before adoption or insertion.');
                } catch (\InvalidArgumentException $error) {
                    self::assertNotSame('', $error->getMessage());
                }
                self::assertSame(1, $this->em->getRepository(Exercise::class)->count([]));
                self::assertNull($existing->getHevyId());
                self::assertSame('Press banca plano con mancuernas', $existing->getName());
            } finally {
                unlink($path);
            }
        }
    }

    public function testLateFlushFailureRollsBackAdoptionAndInserts(): void
    {
        $existing = (new Exercise())->setName('Press banca plano con mancuernas')->setMuscleGroup('Pecho')->setEquipment('Mancuernas');
        $this->em->persist($existing);
        $this->em->flush();
        $connection = $this->em->getConnection();
        $connection->executeStatement("CREATE TRIGGER reject_hevy BEFORE INSERT ON exercises WHEN NEW.hevy_id = '79D0BB3A' BEGIN SELECT RAISE(ABORT, 'forced Hevy failure'); END");
        try {
            $this->importer()->import();
            self::fail('A late database failure must propagate.');
        } catch (\Doctrine\DBAL\Exception $error) {
            self::assertStringContainsString('forced Hevy failure', $error->getMessage());
        } finally {
            $connection->executeStatement('DROP TRIGGER reject_hevy');
        }
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM exercises'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM exercises WHERE hevy_id IS NOT NULL'));
        self::assertSame('Press banca plano con mancuernas', $connection->fetchOne('SELECT name FROM exercises'));
    }
}
