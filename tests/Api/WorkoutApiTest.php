<?php

namespace App\Tests\Api;

final class WorkoutApiTest extends ApiTestCase
{
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
