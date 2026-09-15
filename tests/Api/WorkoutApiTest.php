<?php

namespace App\Tests\Api;

final class WorkoutApiTest extends ApiTestCase
{
    public function testWorkoutSessionCrudFlow(): void
    {
        $headers = $this->authHeaders('workout-user-2');
        $exercise = $this->createExerciseFixture('Barbell Row');
        $exerciseId = $exercise->getId()?->toRfc4122();
        $this->assertNotNull($exerciseId);

        $this->client->jsonRequest('POST', '/v1/workouts', [], $headers);
        $this->assertResponseStatusCodeSame(201);
        $sessionId = (string) ($this->jsonResponse()['id'] ?? '');
        $this->assertNotSame('', $sessionId);

        $this->client->jsonRequest(
            'POST',
            '/v1/workouts/' . $sessionId . '/sets',
            [
                'exercise_id' => $exerciseId,
                'reps' => 12,
                'weight' => 60,
            ],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/v1/workouts/' . $sessionId, [], [], $headers);
        $this->assertResponseIsSuccessful();
        $session = $this->jsonResponse();
        $this->assertCount(1, $session['sets']);

        $this->client->jsonRequest(
            'PATCH',
            '/v1/workouts/' . $sessionId,
            ['duration_minutes' => 45],
            $headers
        );
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/v1/workouts?include_sets=1', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $list = $this->jsonResponse();
        $this->assertCount(1, $list);
        $this->assertSame(45, $list[0]['duration_minutes']);
        $this->assertCount(1, $list[0]['sets']);

        $this->client->request('DELETE', '/v1/workouts/' . $sessionId, [], [], $headers);
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/v1/workouts', [], [], $headers);
        $remaining = $this->jsonResponse();
        $this->assertCount(0, $remaining);
    }

    public function testStartSessionWithForeignRoutineIsForbidden(): void
    {
        $ownerHeaders = $this->authHeaders('workout-owner-1');
        $otherHeaders = $this->authHeaders('workout-other-1');

        $this->client->jsonRequest('POST', '/v1/routines', ['name' => 'Owner routine'], $ownerHeaders);
        $this->assertResponseStatusCodeSame(201);
        $routineId = (string) ($this->jsonResponse()['id'] ?? '');
        $this->assertNotSame('', $routineId);

        $this->client->jsonRequest('POST', '/v1/workouts', ['routine_id' => $routineId], $otherHeaders);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testLogSetRejectsInvalidReps(): void
    {
        $headers = $this->authHeaders('workout-user-1');
        $exercise = $this->createExerciseFixture();
        $exerciseId = $exercise->getId()?->toRfc4122();
        $this->assertNotNull($exerciseId);

        $this->client->jsonRequest('POST', '/v1/workouts', [], $headers);
        $this->assertResponseStatusCodeSame(201);
        $sessionId = (string) ($this->jsonResponse()['id'] ?? '');
        $this->assertNotSame('', $sessionId);

        $this->client->jsonRequest(
            'POST',
            '/v1/workouts/' . $sessionId . '/sets',
            [
                'exercise_id' => $exerciseId,
                'reps' => 0,
                'weight' => 30,
            ],
            $headers
        );

        $this->assertResponseStatusCodeSame(400);
    }

    public function testExerciseSummariesAndPrDetection(): void
    {
        $headers = $this->authHeaders('workout-pr-user-1');
        $exercise = $this->createExerciseFixture('Bench Press PR');
        $exerciseId = $exercise->getId()?->toRfc4122();
        $this->assertNotNull($exerciseId);

        // Session 1
        $this->client->jsonRequest('POST', '/v1/workouts', [], $headers);
        $this->assertResponseStatusCodeSame(201);
        $session1Id = (string) ($this->jsonResponse()['id'] ?? '');

        // Log set 1: 50kg x 10
        $this->client->jsonRequest(
            'POST',
            '/v1/workouts/' . $session1Id . '/sets',
            ['exercise_id' => $exerciseId, 'weight' => 50, 'reps' => 10],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);

        // Log set 2: 60kg x 8 (new max weight)
        $this->client->jsonRequest(
            'POST',
            '/v1/workouts/' . $session1Id . '/sets',
            ['exercise_id' => $exerciseId, 'weight' => 60, 'reps' => 8],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);
        $set2Res = $this->jsonResponse();
        $this->assertTrue($set2Res['is_pr']);
        $this->assertContains('max_weight', $set2Res['pr_types']);

        // Test exercise summaries endpoint
        $this->client->jsonRequest(
            'POST',
            '/v1/workouts/exercise-summaries',
            ['exercise_ids' => [$exerciseId]],
            $headers
        );
        $this->assertResponseIsSuccessful();
        $summariesRes = $this->jsonResponse();
        $this->assertArrayHasKey('summaries', $summariesRes);
        $this->assertArrayHasKey($exerciseId, $summariesRes['summaries']);

        $exSummary = $summariesRes['summaries'][$exerciseId];
        $this->assertNotNull($exSummary['last_session']);
        $this->assertCount(2, $exSummary['last_session']['sets']);
        $this->assertSame(50.0, (float) $exSummary['last_session']['sets'][0]['weight']);
        $this->assertSame(10, $exSummary['last_session']['sets'][0]['reps']);
        $this->assertSame(60.0, (float) $exSummary['records']['max_weight']);

        // Session 2
        $this->client->jsonRequest('POST', '/v1/workouts', [], $headers);
        $this->assertResponseStatusCodeSame(201);
        $session2Id = (string) ($this->jsonResponse()['id'] ?? '');

        // Log set: 65kg x 5 (breaks PR of 60kg)
        $this->client->jsonRequest(
            'POST',
            '/v1/workouts/' . $session2Id . '/sets',
            ['exercise_id' => $exerciseId, 'weight' => 65, 'reps' => 5],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);
        $prRes = $this->jsonResponse();
        $this->assertTrue($prRes['is_pr']);
        $this->assertContains('max_weight', $prRes['pr_types']);
        $this->assertSame(60.0, (float) $prRes['previous_max_weight']);
        $this->assertSame(65.0, (float) $prRes['new_weight']);

        // Log set: 60kg x 5 (does NOT break PR)
        $this->client->jsonRequest(
            'POST',
            '/v1/workouts/' . $session2Id . '/sets',
            ['exercise_id' => $exerciseId, 'weight' => 60, 'reps' => 5],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);
        $nonPrRes = $this->jsonResponse();
        $this->assertFalse($nonPrRes['is_pr']);

        // Log warm-up set: 100kg x 3 (even if heavier, warmup sets MUST NOT be marked as PR)
        $this->client->jsonRequest(
            'POST',
            '/v1/workouts/' . $session2Id . '/sets',
            ['exercise_id' => $exerciseId, 'weight' => 100, 'reps' => 3, 'set_type' => 'warmup'],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);
        $warmupRes = $this->jsonResponse();
        $this->assertFalse($warmupRes['is_pr']);
        $this->assertSame('warmup', $warmupRes['set_type']);
    }
}
