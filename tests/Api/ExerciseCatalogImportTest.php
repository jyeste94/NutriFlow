<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Command\ImportExercisesCommand;
use App\Entity\Exercise;
use App\Entity\Routine;
use App\Entity\RoutineExercise;
use App\Entity\User;
use App\Entity\WorkoutSession;
use App\Entity\WorkoutSetLog;
use App\Service\ExerciseCatalogImporter;
use Symfony\Component\Console\Tester\CommandTester;

final class ExerciseCatalogImportTest extends ApiTestCase
{
    private function importer(): ExerciseCatalogImporter
    {
        return new ExerciseCatalogImporter($this->em, dirname(__DIR__, 2));
    }

    private function source(string $id): Exercise
    {
        $exercise = $this->em->getRepository(Exercise::class)->findOneBy(['sourceId' => ExerciseCatalogImporter::SOURCE_PREFIX.$id]);
        self::assertInstanceOf(Exercise::class, $exercise);
        return $exercise;
    }

    public function testFullSnapshotIsImportedAndRerunPreservesEditsIdsAndReferences(): void
    {
        $existing = (new Exercise())->setName('Mi press de siempre')->setMuscleGroup('Pecho')
            ->setEquipment('Mancuernas')->setDescription('Instrucciones personales')
            ->setGifUrl('https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Dumbbell_Bench_Press/0.jpg')
            ->setImageUrl('https://example.org/mi-foto.jpg')->setVideoUrl('https://example.org/mi-video.mp4')
            ->setTrackingType(Exercise::TRACKING_DURATION);
        $user = (new User())->setFirebaseUid('catalog-import-user');
        $routine = (new Routine())->setName('Mi rutina')->setUser($user);
        $routineExercise = (new RoutineExercise())->setExercise($existing)->setReps(8);
        $routine->addRoutineExercise($routineExercise);
        $session = (new WorkoutSession())->setUser($user)->setRoutine($routine);
        $set = (new WorkoutSetLog())->setExercise($existing)->setWeight(22)->setReps(8);
        $session->addSet($set);
        foreach ([$existing, $user, $routine, $session] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $oldId = $existing->getId()->toRfc4122();
        $routineExerciseId = $routineExercise->getId();
        $setId = $set->getId();

        $result = $this->importer()->import();
        self::assertSame(875, $result['created']);
        self::assertSame(1, $result['adopted']);
        self::assertSame(876, $this->em->getRepository(Exercise::class)->count([]));
        $snapshot = json_decode(file_get_contents(dirname(__DIR__, 2).ExerciseCatalogImporter::SNAPSHOT), true, 512, JSON_THROW_ON_ERROR);
        $ids = [];
        foreach ($snapshot as $row) {
            $exercise = $this->source($row['id']);
            $ids[$row['id']] = $exercise->getId()->toRfc4122();
            self::assertSame($row['name'], $exercise->getSourceName());
        }
        $press = $this->source('Dumbbell_Bench_Press');
        self::assertSame($oldId, $press->getId()->toRfc4122());
        self::assertSame('Mi press de siempre', $press->getName());
        self::assertSame('Instrucciones personales', $press->getDescription());
        self::assertSame('https://example.org/mi-foto.jpg', $press->getImageUrl());
        self::assertSame('https://example.org/mi-video.mp4', $press->getVideoUrl());
        self::assertSame(Exercise::TRACKING_DURATION, $press->getTrackingType());
        $this->source('Dumbbell_Flyes')->setName('Aperturas editadas')->setDescription('Mi ajuste')
            ->setTrackingType(Exercise::TRACKING_BODYWEIGHT_REPS);
        $this->em->flush();
        $rerun = $this->importer()->import();
        self::assertSame(0, $rerun['created']);
        self::assertSame(876, $rerun['unchanged']);
        $this->em->clear();
        foreach ($ids as $source => $id) {
            self::assertSame($id, $this->source($source)->getId()->toRfc4122());
        }
        self::assertSame('Aperturas editadas', $this->source('Dumbbell_Flyes')->getName());
        self::assertSame('Mi ajuste', $this->source('Dumbbell_Flyes')->getDescription());
        self::assertSame(Exercise::TRACKING_BODYWEIGHT_REPS, $this->source('Dumbbell_Flyes')->getTrackingType());
        self::assertSame($oldId, $this->em->find(RoutineExercise::class, $routineExerciseId)->getExercise()->getId()->toRfc4122());
        self::assertSame($oldId, $this->em->find(WorkoutSetLog::class, $setId)->getExercise()->getId()->toRfc4122());
    }

    public function testNewRecordsHaveRealInstructionsPinnedMediaAndAppropriateTracking(): void
    {
        $this->importer()->import();
        $press = $this->source('Dumbbell_Bench_Press');
        self::assertSame('Press banca plano con mancuernas', $press->getName());
        self::assertSame('Dumbbell Bench Press', $press->getSourceName());
        self::assertSame('Pecho', $press->getMuscleGroup());
        self::assertSame('Mancuernas', $press->getEquipment());
        self::assertSame(ExerciseCatalogImporter::IMAGE_BASE.'Dumbbell_Bench_Press/0.jpg', $press->getImageUrl());
        self::assertNull($press->getVideoUrl());
        self::assertNull($press->getGifUrl());
        self::assertStringContainsString('dumbbell', strtolower($press->getDescription()));
        foreach ([
            'Dumbbell_Bench_Press' => Exercise::TRACKING_WEIGHT_REPS,
            '3_4_Sit-Up' => Exercise::TRACKING_BODYWEIGHT_REPS,
            'Ab_Roller' => Exercise::TRACKING_BODYWEIGHT_REPS,
            'Plank' => Exercise::TRACKING_DURATION,
            '90_90_Hamstring' => Exercise::TRACKING_DURATION,
            'Running_Treadmill' => Exercise::TRACKING_DISTANCE_DURATION,
            'Bicycling_Stationary' => Exercise::TRACKING_DISTANCE_DURATION,
            'Rope_Jumping' => Exercise::TRACKING_DURATION,
            'Farmers_Walk' => Exercise::TRACKING_WEIGHT_DISTANCE,
            'Prowler_Sprint' => Exercise::TRACKING_WEIGHT_DISTANCE,
            'Dumbbell_Seated_Box_Jump' => Exercise::TRACKING_WEIGHT_REPS,
        ] as $id => $tracking) {
            self::assertSame($tracking, $this->source($id)->getTrackingType(), $id);
        }
        self::assertNull($this->source('Trail_Running_Walking')->getEquipment());
        self::assertSame('Cardio', $this->source('Running_Treadmill')->getMuscleGroup());
        self::assertSame('Cardio', $this->source('Rope_Jumping')->getMuscleGroup());
        self::assertNull($this->source('Iron_Cross')->getDescription());
        self::assertNull($this->source('Kettlebell_Halo')->getImageUrl());
    }

    public function testExactNormalizedSpanishNameAndEquipmentCanAdoptButAmbiguityNeverMerges(): void
    {
        $spanish = (new Exercise())->setName('  PRESS BANCA PLANO CON MANCUERNAS  ')->setMuscleGroup('Pecho')->setEquipment('dumbbell');
        // Legacy seed image directories do not match these authentic source IDs.
        $incline = (new Exercise())->setName('Press inclinado con mancuernas')->setMuscleGroup('Pecho')->setEquipment('Mancuernas')
            ->setGifUrl('https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Dumbbell_Incline_Bench_Press/0.jpg');
        $decline = (new Exercise())->setName('Press declinado con mancuernas')->setMuscleGroup('Pecho')->setEquipment('Mancuernas')
            ->setGifUrl('https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Dumbbell_Decline_Bench_Press/0.jpg');
        $this->em->persist($incline);
        $this->em->persist($decline);
        $ambiguous = [];
        foreach (['Uno', 'Dos'] as $name) {
            $exercise = (new Exercise())->setName($name)->setMuscleGroup('Pecho')->setEquipment('Mancuernas')
                ->setImageUrl(ExerciseCatalogImporter::IMAGE_BASE.'Dumbbell_Flyes/0.jpg');
            $this->em->persist($exercise);
            $ambiguous[] = $exercise;
        }
        $nearMatch = (new Exercise())->setName('Press banca plano con mancuernas especial')->setMuscleGroup('Pecho')->setEquipment('Mancuernas');
        $this->em->persist($spanish);
        $this->em->persist($nearMatch);
        $this->em->flush();
        $spanishId = $spanish->getId()->toRfc4122();
        $result = $this->importer()->import();
        self::assertSame(3, $result['adopted']);
        self::assertSame(1, $result['ambiguous']);
        self::assertSame($spanishId, $this->source('Dumbbell_Bench_Press')->getId()->toRfc4122());
        self::assertSame('  PRESS BANCA PLANO CON MANCUERNAS  ', $spanish->getName());
        self::assertSame($incline->getId()->toRfc4122(), $this->source('Incline_Dumbbell_Press')->getId()->toRfc4122());
        self::assertSame($decline->getId()->toRfc4122(), $this->source('Decline_Dumbbell_Bench_Press')->getId()->toRfc4122());
        foreach ($ambiguous as $exercise) {
            self::assertNull($exercise->getSourceId());
            self::assertNotSame($exercise->getId()->toRfc4122(), $this->source('Dumbbell_Flyes')->getId()->toRfc4122());
        }
        self::assertNull($nearMatch->getSourceId());
    }

    public function testDryRunShowsCompletePlanWithoutWritingOrAdopting(): void
    {
        $existing = (new Exercise())->setName('Press banca plano con mancuernas')->setMuscleGroup('Pecho')->setEquipment('Mancuernas');
        $this->em->persist($existing);
        $this->em->flush();
        $tester = new CommandTester(new ImportExercisesCommand($this->importer()));
        self::assertSame(0, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('876', $tester->getDisplay());
        self::assertStringContainsString('Simulación', $tester->getDisplay());
        self::assertSame(1, $this->em->getRepository(Exercise::class)->count([]));
        self::assertNull($existing->getSourceId());
    }

    public function testMalformedOrPartialSnapshotsFailBeforeAnyWrites(): void
    {
        $snapshot = json_decode(file_get_contents(dirname(__DIR__, 2).ExerciseCatalogImporter::SNAPSHOT), true, 512, JSON_THROW_ON_ERROR);
        $mutations = [
            static function (array &$rows): void { array_pop($rows); },
            static function (array &$rows): void { $rows[875]['id'] = $rows[0]['id']; },
            static function (array &$rows): void { $rows[875]['instructions'] = [null]; },
            static function (array &$rows): void { $rows[875]['equipment'] = 'invented'; },
            static function (array &$rows): void { $rows[875]['images'] = ['../fake.jpg']; },
            static function (array &$rows): void { $rows[875]['primaryMuscles'] = []; },
        ];
        foreach ($mutations as $mutate) {
            $rows = $snapshot;
            $mutate($rows);
            $path = tempnam(sys_get_temp_dir(), 'exercise-import-');
            try {
                file_put_contents($path, json_encode($rows, JSON_THROW_ON_ERROR));
                try {
                    $this->importer()->import(false, $path);
                    self::fail('Malformed snapshot must not import.');
                } catch (\InvalidArgumentException $error) {
                    self::assertNotSame('', $error->getMessage());
                }
                self::assertSame(0, $this->em->getRepository(Exercise::class)->count([]));
            } finally {
                unlink($path);
            }
        }
    }

    public function testDuplicatePersistedSourceIdentitiesAreRejectedWithoutArbitraryChoice(): void
    {
        $first = (new Exercise())->setName('Primero')->setMuscleGroup('Pecho')
            ->setSourceId(ExerciseCatalogImporter::SOURCE_PREFIX.'Dumbbell_Bench_Press');
        $second = (new Exercise())->setName('Segundo')->setMuscleGroup('Pecho')
            ->setSourceId($first->getSourceId());
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findAll')->willReturn([$first, $second]);
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Identidad de origen duplicada');
        (new ExerciseCatalogImporter($em, dirname(__DIR__, 2)))->import(true);
    }

    public function testFailureDuringFlushRollsBackAllCatalogChanges(): void
    {
        $connection = $this->em->getConnection();
        // SQLite-only test trigger: fail late during flush rather than before planning.
        $connection->executeStatement("CREATE TRIGGER reject_flat_press BEFORE INSERT ON exercises WHEN NEW.source_id = 'free-exercise-db:Dumbbell_Bench_Press' BEGIN SELECT RAISE(ABORT, 'forced import failure'); END");
        try {
            $this->importer()->import();
            self::fail('The database failure must propagate.');
        } catch (\Doctrine\DBAL\Exception $error) {
            self::assertStringContainsString('forced import failure', $error->getMessage());
        } finally {
            $connection->executeStatement('DROP TRIGGER reject_flat_press');
        }
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM exercises'));
    }
}
