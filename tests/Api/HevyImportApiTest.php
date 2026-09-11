<?php

namespace App\Tests\Api;

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
        $headers = $this->authHeaders('hevy-user-1');
        $file = $this->makeCsv($this->sampleCsv());

        $this->client->request('POST', '/v1/import/hevy', [], ['file' => $file], $headers);
        $this->assertResponseIsSuccessful();

        $json = $this->jsonResponse();
        $this->assertSame(1, $json['sessions_created']);
        $this->assertSame(3, $json['sets_imported']);
        $this->assertSame(0, $json['sessions_skipped']);
        $this->assertGreaterThanOrEqual(0, $json['exercises_created']);
    }

    public function testImportConvertsPoundsToKg(): void
    {
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

        $json = $this->jsonResponse();
        $this->assertStringContainsString('No file', $json['error']);
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
}