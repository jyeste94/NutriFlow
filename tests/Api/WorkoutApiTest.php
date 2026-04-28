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
}
