<?php

namespace App\Tests\Api;

use App\Entity\User;
use App\Service\HevyImportService;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class HevyImportApiTest extends ApiTestCase
{
    private function makeCsv(string $content): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hevy_test_');
        file_put_contents($tmp, $content);
        return new UploadedFile($tmp, 'workout_data.csv', 'text/csv', null, true);
    }

    private function sampleCsv(): string
    {
        return implode("\n", [
            'title,start_time,end_time,description,exercise_title,superset_id,exercise_notes,set_index,set_type,weight_lbs,reps,distance_miles,duration_seconds,rpe',
            '"Push Day","15 Jan 2024, 17:00","15 Jan 2024, 18:15","","Bench Press (Barbell)",,"",0,warmup,135,10,,,',
            '"Push Day","15 Jan 2024, 17:00","15 Jan 2024, 18:15","","Bench Press (Barbell)",,"",1,normal,185,8,,,8',
            '"Push Day","15 Jan 2024, 17:00","15 Jan 2024, 18:15","","Incline Dumbbell Press",,"",0,normal,70,10,,,',
        ]);
    }

    public function testImportValidCsv(): void
    {
        $this->createExerciseFixture('Bench Press (Barbell)');
        $this->createExerciseFixture('Incline Dumbbell Press');
        $headers = $this->authHeaders('hevy-user-1');
        $file = $this->makeCsv($this->sampleCsv());

        $this->client->request('POST', '/v1/import/hevy', [], ['file' => $file], $headers);
        $this->assertResponseIsSuccessful();

        $json = $this->jsonResponse();
        $this->assertSame(1, $json['sessions_created']);
        $this->assertSame(3, $json['sets_imported']);
        $this->assertSame(0, $json['sessions_skipped']);
        $this->assertSame(0, $json['exercises_created']);
    }

    public function testImportResolvesOfficialEnglishSpanishAndOriginalAliasesWithoutDuplicating(): void
    {
        $press = $this->createExerciseFixture('Mi press de banca')
            ->setHevyId('PRESS-BARBELL')
            ->setNameEn('Bench Press (Barbell)')
            ->setNameEs('Press de Banca (Barra)')
            ->setSourceName('Barbell Bench Press')
            ->setEquipment('Barra');
        $id = $press->getId()->toRfc4122();
        $this->em->flush();
        $headers = $this->authHeaders('hevy-bilingual');
        $csv = implode("\n", [
            'title,start_time,end_time,description,exercise_title,superset_id,exercise_notes,set_index,set_type,weight_lbs,reps,distance_miles,duration_seconds,rpe',
            '"Test","20 Feb 2024, 10:00","20 Feb 2024, 10:30","","Bench Press (Barbell)",,"",0,normal,220,5,,,',
            '"Test","20 Feb 2024, 10:00","20 Feb 2024, 10:30","","Press de Banca (Barra)",,"",1,normal,220,5,,,',
            '"Test","20 Feb 2024, 10:00","20 Feb 2024, 10:30","","Barbell Bench Press",,"",2,normal,220,5,,,',
        ]);

        $this->client->request('POST', '/v1/import/hevy', [], ['file' => $this->makeCsv($csv)], $headers);
        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $this->jsonResponse()['exercises_created']);
        $this->assertSame(3, $this->jsonResponse()['sets_imported']);
        $this->client->request('GET', '/v1/workouts?include_sets=1', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $sets = $this->jsonResponse()[0]['sets'];
        $this->assertCount(3, $sets);
        $this->assertSame([$id], array_values(array_unique(array_column($sets, 'exercise_id'))));
        $this->client->request('POST', '/v1/import/hevy', [], ['file' => $this->makeCsv($csv)], $headers);
        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $this->jsonResponse()['sessions_created']);
        $this->assertSame(1, $this->jsonResponse()['sessions_skipped']);
        $this->assertSame(0, $this->jsonResponse()['exercises_created']);
    }

    public function testImportRejectsAmbiguousSpanishAliasesBeforeWritingForReadersAndAdmins(): void
    {
        $this->createExerciseFixture('Press de Hombros (Mancuerna)')
            ->setHevyId('6AC96645')
            ->setNameEn('Overhead Press (Dumbbell)')
            ->setNameEs('Press de Hombros (Mancuerna)')
            ->setEquipment('Mancuernas');
        $this->createExerciseFixture('Press de Hombros (Mancuerna)')
            ->setHevyId('878CD1D0')
            ->setNameEn('Shoulder Press (Dumbbell)')
            ->setNameEs('Press de Hombros (Mancuerna)')
            ->setEquipment('Mancuernas');
        $admin = (new User())->setFirebaseUid('hevy-ambiguous-admin')->setRoles(['ROLE_ADMIN']);
        $this->em->persist($admin);
        $this->em->flush();
        $csv = implode("\n", [
            'title,start_time,end_time,description,exercise_title,superset_id,exercise_notes,set_index,set_type,weight_lbs,reps,distance_miles,duration_seconds,rpe',
            '"Test","20 Feb 2024, 10:00","20 Feb 2024, 10:30","","Overhead Press (Dumbbell)",,"",0,normal,50,5,,,',
            '"Test","20 Feb 2024, 10:00","20 Feb 2024, 10:30","","Press de Hombros (Mancuerna)",,"",1,normal,50,5,,,',
        ]);
        foreach (['hevy-ambiguous-reader', 'hevy-ambiguous-admin'] as $uid) {
            $headers = $this->authHeaders($uid);
            $this->client->request('POST', '/v1/import/hevy', [], ['file' => $this->makeCsv($csv)], $headers);
            $this->assertResponseStatusCodeSame(400);
            $this->client->request('GET', '/v1/workouts', [], [], $headers);
            $this->assertResponseIsSuccessful();
            $this->assertSame([], $this->jsonResponse());
            $this->client->request('GET', '/v1/exercises', [], [], $headers);
            $this->assertResponseIsSuccessful();
            $this->assertCount(2, $this->jsonResponse());
        }
    }

    public function testImportKeepsVariantsWithSharedSpanishNamesDistinctThroughEnglishNames(): void
    {
        $overhead = $this->createExerciseFixture('Press de Hombros (Mancuerna)')
            ->setHevyId('6AC96645')
            ->setNameEn('Overhead Press (Dumbbell)')
            ->setNameEs('Press de Hombros (Mancuerna)');
        $shoulder = $this->createExerciseFixture('Press de Hombros (Mancuerna)')
            ->setHevyId('878CD1D0')
            ->setNameEn('Shoulder Press (Dumbbell)')
            ->setNameEs('Press de Hombros (Mancuerna)');
        $ids = [$overhead->getId()->toRfc4122(), $shoulder->getId()->toRfc4122()];
        $this->em->flush();
        $headers = $this->authHeaders('hevy-distinct-variants');
        $csv = implode("\n", [
            'title,start_time,end_time,description,exercise_title,superset_id,exercise_notes,set_index,set_type,weight_lbs,reps,distance_miles,duration_seconds,rpe',
            '"Test","20 Feb 2024, 10:00","20 Feb 2024, 10:30","","Overhead Press (Dumbbell)",,"",0,normal,50,5,,,',
            '"Test","20 Feb 2024, 10:00","20 Feb 2024, 10:30","","Shoulder Press (Dumbbell)",,"",1,normal,50,5,,,',
        ]);
        $this->client->request('POST', '/v1/import/hevy', [], ['file' => $this->makeCsv($csv)], $headers);
        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $this->jsonResponse()['exercises_created']);
        $this->client->request('GET', '/v1/workouts?include_sets=1', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $this->assertEqualsCanonicalizing($ids, array_column($this->jsonResponse()[0]['sets'], 'exercise_id'));
    }

    public function testImportConvertsPoundsToKg(): void
    {
        $squat = $this->createExerciseFixture('Squat');
        $squat->setEquipment('Barbell');
        $this->em->flush();
        $headers = $this->authHeaders('hevy-user-2');
        $csv = implode("\n", [
            'title,start_time,end_time,description,exercise_title,superset_id,exercise_notes,set_index,set_type,weight_lbs,reps,distance_miles,duration_seconds,rpe',
            '"Test","20 Feb 2024, 10:00","20 Feb 2024, 10:30","","Squat (Barbell)",,"",0,normal,220,5,,,',
        ]);
        $file = $this->makeCsv($csv);

        $this->client->request('POST', '/v1/import/hevy', [], ['file' => $file], $headers);
        $this->assertResponseIsSuccessful();

        $json = $this->jsonResponse();
        $this->assertSame(1, $json['sessions_created']);
        $this->assertSame(1, $json['sets_imported']);

        // Verify weight was converted: 220 lbs / 2.20462 = ~99.79 kg
        $this->client->request('GET', '/v1/workouts?include_sets=1', [], [], $headers);
        $sessions = $this->jsonResponse();
        $this->assertNotEmpty($sessions);
        $weight = $sessions[0]['sets'][0]['weight'] ?? 0;
        $this->assertGreaterThan(99.0, $weight);
        $this->assertLessThan(100.5, $weight);
    }

    public function testImportSkipsDuplicates(): void
    {
        $this->createExerciseFixture('Bench Press (Barbell)');
        $this->createExerciseFixture('Incline Dumbbell Press');
        $headers = $this->authHeaders('hevy-user-3');
        $file1 = $this->makeCsv($this->sampleCsv());
        $this->client->request('POST', '/v1/import/hevy', [], ['file' => $file1], $headers);
        $this->assertResponseIsSuccessful();
        $json1 = $this->jsonResponse();
        $this->assertSame(1, $json1['sessions_created']);

        // Import same CSV again
        $file2 = $this->makeCsv($this->sampleCsv());
        $this->client->request('POST', '/v1/import/hevy', [], ['file' => $file2], $headers);
        $this->assertResponseIsSuccessful();
        $json2 = $this->jsonResponse();
        $this->assertSame(0, $json2['sessions_created']);
        $this->assertSame(1, $json2['sessions_skipped']);
    }

    public function testImportRejectsNoFile(): void
    {
        $headers = $this->authHeaders('hevy-user-4');
        $this->client->request('POST', '/v1/import/hevy', [], [], $headers);
        $this->assertResponseStatusCodeSame(400);

    }

    public function testImportRejectsInvalidHeaders(): void
    {
        $headers = $this->authHeaders('hevy-user-5');
        $csv = "wrong_col1,wrong_col2\nval1,val2\n";
        $file = $this->makeCsv($csv);

        $this->client->request('POST', '/v1/import/hevy', [], ['file' => $file], $headers);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testImportRequiresAuth(): void
    {
        $file = $this->makeCsv($this->sampleCsv());
        $this->client->request('POST', '/v1/import/hevy', [], ['file' => $file]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testImportCreatesNewExercises(): void
    {
        $admin = (new User())->setFirebaseUid('hevy-user-6')->setRoles(['ROLE_ADMIN']);
        $this->em->persist($admin);
        $this->em->flush();
        $headers = $this->authHeaders('hevy-user-6');
        $csv = implode("\n", [
            'title,start_time,end_time,description,exercise_title,superset_id,exercise_notes,set_index,set_type,weight_lbs,reps,distance_miles,duration_seconds,rpe',
            '"Workout","01 Mar 2024, 09:00","01 Mar 2024, 09:45","","Bulgarian Split Squat (Dumbbell)",,"",0,normal,50,12,,,',
        ]);
        $file = $this->makeCsv($csv);

        $this->client->request('POST', '/v1/import/hevy', [], ['file' => $file], $headers);
        $this->assertResponseIsSuccessful();

        $json = $this->jsonResponse();
        $this->assertGreaterThanOrEqual(1, $json['exercises_created']);

        // Verify exercise was created with parsed name and equipment
        $this->client->request('GET', '/v1/exercises/search?q=Bulgarian', [], [], $headers);
        $exercises = $this->jsonResponse();
        $this->assertNotEmpty($exercises);
        $this->assertSame('Bulgarian Split Squat', $exercises[0]['name']);
        $this->assertSame('Dumbbell', $exercises[0]['equipment']);
    }

    public function testOrdinaryUserCannotCreateCatalogExercisesThroughImport(): void
    {
        $this->createExerciseFixture('Bench Press (Barbell)');
        $headers = $this->authHeaders('hevy-catalog-reader');
        $file = $this->makeCsv($this->sampleCsv());
        $this->client->request('POST', '/v1/import/hevy', [], ['file' => $file], $headers);
        $this->assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/v1/exercises', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $catalog = $this->jsonResponse();
        $this->assertCount(1, $catalog);
        $this->assertSame('Bench Press (Barbell)', $catalog[0]['name']);
        $this->client->request('GET', '/v1/workouts', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $this->jsonResponse());
    }

    public function testImportInternalFailureDoesNotExposeDetails(): void
    {
        $importer = $this->createMock(HevyImportService::class);
        $importer->method('importWorkoutCsv')->willThrowException(new \RuntimeException('SQL private_database password=private-secret'));
        static::getContainer()->set(HevyImportService::class, $importer);

        $this->client->request('POST', '/v1/import/hevy', [], [
            'file' => $this->makeCsv($this->sampleCsv()),
        ], $this->authHeaders('hevy-failure-user'));
        $this->assertResponseStatusCodeSame(500);
        $response = $this->jsonResponse();
        $this->assertArrayHasKey('error', $response);
        $this->assertArrayNotHasKey('details', $response);
        $this->assertStringNotContainsString('private_database', $this->client->getResponse()->getContent());
        $this->assertStringNotContainsString('private-secret', $this->client->getResponse()->getContent());
    }
}