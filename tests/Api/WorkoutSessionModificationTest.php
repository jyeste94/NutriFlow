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
    public function testRetryUncheckRecheckAndFinishKeepOneSetAndExcludeDraftSessions(): void
    {
        $headers = $this->authHeaders('workout-retry-user');
        $exercise = $this->createExerciseFixture('Retry squat');
        $start = ['client_session_id' => '11111111-2222-4333-8444-555555555555'];
        $this->client->jsonRequest('POST', '/v1/workouts', $start, $headers);
        $sessionId = $this->jsonResponse()['id'];
        $this->client->jsonRequest('POST', '/v1/workouts', $start, $headers);
        $this->assertResponseIsSuccessful();
        $this->assertSame($sessionId, $this->jsonResponse()['id']);
        $payload = ['exercise_id' => $exercise->getId()->toRfc4122(), 'client_set_id' => 'one-visible-set', 'weight' => 40, 'reps' => 8];
        $this->client->jsonRequest('POST', '/v1/workouts/' . $sessionId . '/sets', $payload, $headers);
        $this->assertResponseStatusCodeSame(201);
        $setId = $this->jsonResponse()['setId'];
        $this->client->jsonRequest('POST', '/v1/workouts/' . $sessionId . '/sets', $payload, $headers);
        $this->assertResponseIsSuccessful();
        $this->assertSame($setId, $this->jsonResponse()['setId']);
        $this->client->jsonRequest('PATCH', '/v1/workouts/' . $sessionId . '/sets/' . $setId, ['completed' => false, 'weight' => 45, 'reps' => 12], $headers);
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/v1/workouts/' . $sessionId, [], [], $headers);
        $this->assertSame(0, $this->jsonResponse()['set_count']);
        $this->assertSame(0.0, (float) $this->jsonResponse()['total_volume']);
        $this->client->request('GET', '/v1/progress/training', [], [], $headers);
        $this->assertSame(0, $this->jsonResponse()['summary']['total_workouts']);
        $this->client->jsonRequest('PATCH', '/v1/workouts/' . $sessionId . '/sets/' . $setId, ['completed' => true], $headers);
        $this->assertResponseIsSuccessful();
        $this->client->jsonRequest('PATCH', '/v1/workouts/' . $sessionId, ['status' => 'completed', 'duration_minutes' => 7], $headers);
        $this->assertResponseIsSuccessful();
        $this->client->jsonRequest('PATCH', '/v1/workouts/' . $sessionId, ['status' => 'completed', 'duration_minutes' => 7], $headers);
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/v1/workouts?status=completed&include_sets=1', [], [], $headers);
        $history = $this->jsonResponse();
        $this->assertCount(1, $history);
        $this->assertCount(1, $history[0]['sets']);
        $this->assertSame($setId, $history[0]['sets'][0]['id']);
        $this->assertSame(540.0, (float) $history[0]['total_volume']);
        $this->client->jsonRequest('PATCH', '/v1/workouts/' . $sessionId . '/sets/' . $setId, ['weight' => 100], $headers);
        $this->assertResponseStatusCodeSame(409);
        $this->client->jsonRequest('POST', '/v1/workouts', [], $headers);
        $newSession = $this->jsonResponse()['id'];
        $this->assertNotSame($sessionId, $newSession);
        $this->client->request('DELETE', '/v1/workouts/' . $newSession, [], [], $headers);
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/v1/progress/training', [], [], $headers);
        $this->assertSame(1, $this->jsonResponse()['summary']['total_workouts']);
    }

    public function testSubMinuteCardioAndInvalidCorrectionPreserveRecordedInterval(): void
    {
        $headers = $this->authHeaders('cardio-seconds-user');
        $exercise = $this->createExerciseFixture('Short interval', 'Cardio', 'DURATION');
        $this->client->jsonRequest('POST', '/v1/workouts', [], $headers);
        $sessionId = $this->jsonResponse()['id'];
        $this->client->jsonRequest('POST', '/v1/workouts/' . $sessionId . '/sets', [
            'exercise_id' => $exercise->getId()->toRfc4122(), 'duration_seconds' => 45, 'set_number' => 2,
        ], $headers);
        $this->assertResponseStatusCodeSame(201);
        $setId = $this->jsonResponse()['setId'];
        $this->client->jsonRequest('PATCH', '/v1/workouts/' . $sessionId . '/sets/' . $setId, ['duration_seconds' => -10], $headers);
        $this->assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/v1/workouts/' . $sessionId, [], [], $headers);
        $this->assertSame(45, $this->jsonResponse()['sets'][0]['duration_seconds']);
        $this->client->request('DELETE', '/v1/workouts/' . $sessionId . '/sets/' . $setId, [], [], $headers);
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/v1/workouts/' . $sessionId, [], [], $headers);
        $this->assertSame([], $this->jsonResponse()['sets']);
    }

    public function testRecordsExcludeWarmupsButKeepHighRepWeightAndRepetitionMaxima(): void
    {
        $headers = $this->authHeaders('consistent-pr-user');
        $exercise = $this->createExerciseFixture('Consistent records');
        $exerciseId = $exercise->getId()->toRfc4122();
        $this->client->jsonRequest('POST', '/v1/workouts', [], $headers);
        $sessionId = $this->jsonResponse()['id'];
        foreach ([[100, 5, 'normal'], [120, 15, 'normal'], [200, 20, 'warmup']] as [$weight, $reps, $type]) {
            $this->client->jsonRequest('POST', '/v1/workouts/' . $sessionId . '/sets', [
                'exercise_id' => $exerciseId, 'weight' => $weight, 'reps' => $reps, 'set_type' => $type,
            ], $headers);
            $this->assertResponseStatusCodeSame(201);
        }
        $this->client->jsonRequest('PATCH', '/v1/workouts/' . $sessionId, ['status' => 'completed'], $headers);
        $this->assertResponseIsSuccessful();
        $this->client->jsonRequest('POST', '/v1/workouts/exercise-summaries', ['exercise_ids' => [$exerciseId]], $headers);
        $records = $this->jsonResponse()['summaries'][$exerciseId]['records'];
        $this->assertSame(120.0, (float) $records['max_weight']);
        $this->assertSame(15, $records['max_reps']);
        $this->assertSame(116.7, $records['max_e1rm']);
        $this->client->request('GET', '/v1/progress/strength?exercise_id=' . $exerciseId, [], [], $headers);
        $progress = $this->jsonResponse();
        $this->assertSame(120.0, (float) $progress['summary']['max_weight']);
        $this->assertSame(116.7, $progress['summary']['max_e1rm']);
        $repetitionPr = array_values(array_filter($progress['prs'], fn ($pr) => $pr['type'] === 'max_reps'))[0];
        $this->assertSame(15, $repetitionPr['reps']);
        $this->client->jsonRequest('POST', '/v1/workouts', [], $headers);
        $nextId = $this->jsonResponse()['id'];
        $this->client->jsonRequest('POST', '/v1/workouts/' . $nextId . '/sets', [
            'exercise_id' => $exerciseId, 'weight' => 130, 'reps' => 16,
        ], $headers);
        $this->assertContains('max_weight', $this->jsonResponse()['pr_types']);
        $this->assertContains('max_reps', $this->jsonResponse()['pr_types']);
        $this->assertNotContains('max_e1rm', $this->jsonResponse()['pr_types']);
    }
}
