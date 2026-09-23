<?php

namespace App\Tests\Api;

final class WorkoutSessionModificationTest extends ApiTestCase
{
    public function testUpdateAndRemoveSetFromSession(): void
    {
        $headers = $this->authHeaders('workout-mod-user');
        $exercise = $this->createExerciseFixture('Incline Dumbbell Press');
        $exerciseId = $exercise->getId()?->toRfc4122();
        $this->assertNotNull($exerciseId);

        // 1. Iniciar sesión
        $this->client->jsonRequest('POST', '/v1/workouts', [], $headers);
        $this->assertResponseStatusCodeSame(201);
        $sessionId = (string) ($this->jsonResponse()['id'] ?? '');
        $this->assertNotSame('', $sessionId);

        // 2. Registrar dos sets
        $this->client->jsonRequest(
            'POST',
            '/v1/workouts/' . $sessionId . '/sets',
            [
                'exercise_id' => $exerciseId,
                'reps' => 10,
                'weight' => 24.0,
            ],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);
        $set1Id = (string) ($this->jsonResponse()['setId'] ?? '');
        $this->assertNotSame('', $set1Id);

        $this->client->jsonRequest(
            'POST',
            '/v1/workouts/' . $sessionId . '/sets',
            [
                'exercise_id' => $exerciseId,
                'reps' => 8,
                'weight' => 26.0,
            ],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);
        $set2Id = (string) ($this->jsonResponse()['setId'] ?? '');
        $this->assertNotSame('', $set2Id);

        // 3. Comprobar que hay 2 sets en la sesión
        $this->client->request('GET', '/v1/workouts/' . $sessionId, [], [], $headers);
        $this->assertResponseIsSuccessful();
        $sessionData = $this->jsonResponse();
        $this->assertCount(2, $sessionData['sets']);

        // 4. Actualizar set 1 (PATCH)
        $this->client->jsonRequest(
            'PATCH',
            '/v1/workouts/' . $sessionId . '/sets/' . $set1Id,
            [
                'weight' => 25.0,
                'reps' => 12,
            ],
            $headers
        );
        $this->assertResponseIsSuccessful();
        $patchResponse = $this->jsonResponse();
        $this->assertSame(25.0, (float) $patchResponse['weight']);
        $this->assertSame(12, $patchResponse['reps']);

        // 5. Eliminar set 2 (DELETE)
        $this->client->request(
            'DELETE',
            '/v1/workouts/' . $sessionId . '/sets/' . $set2Id,
            [],
            [],
            $headers
        );
        $this->assertResponseIsSuccessful();

        // 6. Verificar que solo queda set 1 y con los datos actualizados
        $this->client->request('GET', '/v1/workouts/' . $sessionId, [], [], $headers);
        $this->assertResponseIsSuccessful();
        $updatedSession = $this->jsonResponse();
        $this->assertCount(1, $updatedSession['sets']);
        $this->assertSame($set1Id, $updatedSession['sets'][0]['id']);
        $this->assertSame(25.0, (float) $updatedSession['sets'][0]['weight']);
        $this->assertSame(12, $updatedSession['sets'][0]['reps']);
    }

    public function testCannotModifyOtherUserSessionSet(): void
    {
        $ownerHeaders = $this->authHeaders('workout-owner-user');
        $attackerHeaders = $this->authHeaders('workout-attacker-user');
        $exercise = $this->createExerciseFixture('Lateral Raise');
        $exerciseId = $exercise->getId()?->toRfc4122();

        // Owner crea sesión y set
        $this->client->jsonRequest('POST', '/v1/workouts', [], $ownerHeaders);
        $this->assertResponseStatusCodeSame(201);
        $sessionId = (string) ($this->jsonResponse()['id'] ?? '');

        $this->client->jsonRequest(
            'POST',
            '/v1/workouts/' . $sessionId . '/sets',
            [
                'exercise_id' => $exerciseId,
                'reps' => 15,
                'weight' => 10.0,
            ],
            $ownerHeaders
        );
        $this->assertResponseStatusCodeSame(201);
        $setId = (string) ($this->jsonResponse()['setId'] ?? '');

        // Attacker intenta hacer PATCH
        $this->client->jsonRequest(
            'PATCH',
            '/v1/workouts/' . $sessionId . '/sets/' . $setId,
            ['reps' => 99],
            $attackerHeaders
        );
        $this->assertResponseStatusCodeSame(403);

        // Attacker intenta hacer DELETE
        $this->client->request(
            'DELETE',
            '/v1/workouts/' . $sessionId . '/sets/' . $setId,
            [],
            [],
            $attackerHeaders
        );
        $this->assertResponseStatusCodeSame(403);
    }
}
