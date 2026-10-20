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
    public function testPartialFinishPersistsEditedSummaryAndExerciseOrderWithoutChangingRoutine(): void
    {
        $headers = $this->authHeaders('finish-summary-user');
        $press = $this->createExerciseFixture('Finish press')->getId()->toRfc4122();
        $row = $this->createExerciseFixture('Finish row')->getId()->toRfc4122();
        $this->client->jsonRequest('POST', '/v1/routines', [
            'name' => 'Original template',
            'exercises' => [
                ['exercise_id' => $press, 'sets' => 3, 'reps' => 8, 'restSeconds' => 90],
                ['exercise_id' => $row, 'sets' => 3, 'reps' => 6, 'restSeconds' => 60],
            ],
        ], $headers);
        $this->assertResponseStatusCodeSame(201);
        $routineId = $this->jsonResponse()['id'];
        $this->client->jsonRequest('POST', '/v1/workouts', ['routine_id' => $routineId], $headers);
        $this->assertResponseStatusCodeSame(201);
        $sessionId = $this->jsonResponse()['id'];
        $this->assertSame('Original template', $this->jsonResponse()['name']);
        $this->client->jsonRequest('PUT', '/v1/routines/' . $routineId, ['name' => 'Renamed template'], $headers);
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/v1/routines/' . $routineId, [], [], $headers);
        $routineBeforeFinish = $this->jsonResponse();
        $this->client->request('GET', '/v1/workouts/' . $sessionId, [], [], $headers);
        $this->assertSame('Original template', $this->jsonResponse()['name']);

        $setIds = [];
        foreach ([
            ['exercise_id' => $press, 'weight' => 50, 'reps' => 8, 'set_number' => 2, 'exercise_order' => 0],
            ['exercise_id' => $press, 'weight' => 40, 'reps' => 10, 'set_number' => 1, 'exercise_order' => 0, 'completed' => false],
            ['exercise_id' => $row, 'weight' => 60, 'reps' => 6, 'set_number' => 1, 'exercise_order' => 1],
        ] as $payload) {
            $this->client->jsonRequest('POST', '/v1/workouts/' . $sessionId . '/sets', $payload, $headers);
            $this->assertResponseStatusCodeSame(201);
            $setIds[] = $this->jsonResponse()['id'];
        }
        foreach ($setIds as $index => $setId) {
            $this->client->jsonRequest('PATCH', '/v1/workouts/' . $sessionId . '/sets/' . $setId, [
                'exercise_order' => $index === 2 ? 0 : 1,
            ], $headers);
            $this->assertResponseIsSuccessful();
        }
        $finish = [
            'status' => 'completed', 'name' => '  Sesion editada  ',
            'date' => '2026-10-18T10:30:00+02:00', 'duration_seconds' => 125, 'duration_minutes' => 99,
        ];
        $this->client->jsonRequest('PATCH', '/v1/workouts/' . $sessionId, $finish, $headers);
        $this->assertResponseIsSuccessful();
        $summary = $this->jsonResponse();
        $this->assertSame('completed', $summary['status']);
        $this->assertSame('Sesion editada', $summary['name']);
        $this->assertSame('2026-10-18T08:30:00+00:00', $summary['date']);
        $this->assertSame(125, $summary['duration_seconds']);
        $this->assertSame(2, $summary['duration_minutes']);
        $this->assertSame(2, $summary['set_count']);
        $this->assertSame(2, $summary['exercise_count']);
        $this->assertSame(760.0, (float) $summary['total_volume']);
        $this->assertSame([$setIds[2], $setIds[1], $setIds[0]], array_column($summary['sets'], 'id'));
        $this->assertSame([0, 1, 1], array_column($summary['sets'], 'exercise_order'));
        $this->assertFalse($summary['sets'][1]['completed']);
        $this->assertSame([], $summary['personal_records']);
        $this->client->jsonRequest('PATCH', '/v1/workouts/' . $sessionId, $finish, $headers);
        $this->assertResponseIsSuccessful();
        $this->assertSame($summary, $this->jsonResponse());
        $this->client->request('GET', '/v1/workouts/' . $sessionId, [], [], $headers);
        $this->assertSame($summary, $this->jsonResponse());
        $this->client->request('GET', '/v1/workouts?status=completed&include_sets=1', [], [], $headers);
        $this->assertSame([$summary], $this->jsonResponse());
        $this->client->request('GET', '/v1/workouts?status=completed', [], [], $headers);
        $this->assertSame('Sesion editada', $this->jsonResponse()[0]['name']);
        $this->assertSame(125, $this->jsonResponse()[0]['duration_seconds']);
        $this->client->request('GET', '/v1/routines/' . $routineId, [], [], $headers);
        $this->assertSame($routineBeforeFinish, $this->jsonResponse());
    }

    public function testFinishMetadataAndExerciseOrderValidationAreAtomicAndKeepMinuteCompatibility(): void
    {
        $headers = $this->authHeaders('finish-validation-user');
        $exerciseId = $this->createExerciseFixture('Finish validation')->getId()->toRfc4122();
        $this->client->jsonRequest('POST', '/v1/workouts', [], $headers);
        $sessionId = $this->jsonResponse()['id'];
        $this->client->jsonRequest('PATCH', '/v1/workouts/' . $sessionId, ['duration_minutes' => 2], $headers);
        $this->assertResponseIsSuccessful();
        $this->assertSame(120, $this->jsonResponse()['duration_seconds']);
        $this->client->jsonRequest('POST', '/v1/workouts/' . $sessionId . '/sets', [
            'exercise_id' => $exerciseId, 'weight' => 10, 'reps' => 8, 'completed' => false,
        ], $headers);
        $this->assertResponseStatusCodeSame(201);
        $setId = $this->jsonResponse()['id'];
        $this->assertSame(0, $this->jsonResponse()['exercise_order']);
        $this->client->request('GET', '/v1/workouts/' . $sessionId, [], [], $headers);
        $before = $this->jsonResponse();
        foreach ([
            ['name' => '   '], ['name' => str_repeat('a', 256)], ['name' => null], ['name' => 123],
            ['date' => 'tomorrow'], ['date' => '2026-02-30'], ['date' => '0000-01-01'],
            ['date' => '2026-10-18T25:00:00Z'], ['date' => '2026-10-18T10:30:00'],
            ['date' => '2026-10-18 trailing'], ['date' => []], ['date' => null],
            ['duration_seconds' => -1], ['duration_seconds' => 604801], ['duration_seconds' => 1.5],
            ['duration_seconds' => '45'], ['duration_seconds' => true], ['duration_seconds' => null],
            ['duration_minutes' => -1], ['duration_minutes' => 10081], ['status' => 'active'],
            ['status' => 'completed', 'name' => 'Must not persist', 'date' => '2026-10-01', 'duration_seconds' => 45],
        ] as $invalid) {
            $this->client->jsonRequest('PATCH', '/v1/workouts/' . $sessionId, $invalid, $headers);
            $this->assertResponseStatusCodeSame(400);
            $this->client->request('GET', '/v1/workouts/' . $sessionId, [], [], $headers);
            $this->assertSame($before, $this->jsonResponse());
        }
        foreach ([-1, 1.5, '1', null, true, 2147483648] as $invalidOrder) {
            $this->client->jsonRequest('PATCH', '/v1/workouts/' . $sessionId . '/sets/' . $setId, [
                'exercise_order' => $invalidOrder, 'weight' => 99,
            ], $headers);
            $this->assertResponseStatusCodeSame(400);
            $this->client->jsonRequest('POST', '/v1/workouts/' . $sessionId . '/sets', [
                'exercise_id' => $exerciseId, 'weight' => 99, 'reps' => 8, 'exercise_order' => $invalidOrder,
            ], $headers);
            $this->assertResponseStatusCodeSame(400);
        }
        $this->client->request('GET', '/v1/workouts/' . $sessionId, [], [], $headers);
        $this->assertSame($before, $this->jsonResponse());
        $this->client->jsonRequest('PATCH', '/v1/workouts/' . $sessionId, [
            'name' => str_repeat('a', 255), 'date' => '2024-02-29', 'duration_seconds' => 0,
        ], $headers);
        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $this->jsonResponse()['duration_seconds']);
        $this->assertSame(0, $this->jsonResponse()['duration_minutes']);
        $this->assertSame('2024-02-29T00:00:00+00:00', $this->jsonResponse()['date']);
        $this->client->jsonRequest('PATCH', '/v1/workouts/' . $sessionId . '/sets/' . $setId, ['completed' => true], $headers);
        $this->assertResponseIsSuccessful();
        $this->client->jsonRequest('PATCH', '/v1/workouts/' . $sessionId, [
            'status' => 'completed', 'duration_seconds' => 604800,
        ], $headers);
        $this->assertResponseIsSuccessful();
        $this->assertSame(604800, $this->jsonResponse()['duration_seconds']);
        $this->assertSame(10080, $this->jsonResponse()['duration_minutes']);
    }

    public function testSummaryRecordsUseOnlyEarlierCompletedSessionsAndSurviveLaterWorkouts(): void
    {
        $headers = $this->authHeaders('finish-pr-user');
        $exerciseId = $this->createExerciseFixture('Durable summary records')->getId()->toRfc4122();
        $startWithSets = function (array $sessionHeaders, array $sets): array {
            $this->client->jsonRequest('POST', '/v1/workouts', [], $sessionHeaders);
            $this->assertResponseStatusCodeSame(201);
            $sessionId = $this->jsonResponse()['id'];
            $ids = [];
            foreach ($sets as $payload) {
                $this->client->jsonRequest('POST', '/v1/workouts/' . $sessionId . '/sets', $payload, $sessionHeaders);
                $this->assertResponseStatusCodeSame(201);
                $ids[] = $this->jsonResponse()['id'];
            }
            return [$sessionId, $ids];
        };
        [$baselineId] = $startWithSets($headers, [
            ['exercise_id' => $exerciseId, 'weight' => 100, 'reps' => 5],
            ['exercise_id' => $exerciseId, 'weight' => 120, 'reps' => 15],
            ['exercise_id' => $exerciseId, 'weight' => 500, 'reps' => 20, 'set_type' => 'warmup'],
            ['exercise_id' => $exerciseId, 'weight' => 900, 'reps' => 20, 'completed' => false],
        ]);
        $this->client->jsonRequest('PATCH', '/v1/workouts/' . $baselineId, ['status' => 'completed', 'date' => '2026-10-16'], $headers);
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $this->jsonResponse()['personal_records']);
        $startWithSets($headers, [['exercise_id' => $exerciseId, 'weight' => 950, 'reps' => 20]]);
        [$futureId] = $startWithSets($headers, [['exercise_id' => $exerciseId, 'weight' => 400, 'reps' => 12]]);
        $this->client->jsonRequest('PATCH', '/v1/workouts/' . $futureId, ['status' => 'completed', 'date' => '2026-10-20'], $headers);
        $this->assertResponseIsSuccessful();
        $otherHeaders = $this->authHeaders('finish-pr-other-user');
        [$otherId] = $startWithSets($otherHeaders, [['exercise_id' => $exerciseId, 'weight' => 700, 'reps' => 12]]);
        $this->client->jsonRequest('PATCH', '/v1/workouts/' . $otherId, ['status' => 'completed', 'date' => '2026-10-17'], $otherHeaders);
        $this->assertResponseIsSuccessful();

        [$sessionId, $setIds] = $startWithSets($headers, [
            ['exercise_id' => $exerciseId, 'weight' => 125, 'reps' => 15],
            ['exercise_id' => $exerciseId, 'weight' => 130, 'reps' => 16],
            ['exercise_id' => $exerciseId, 'weight' => 110, 'reps' => 5],
            ['exercise_id' => $exerciseId, 'weight' => 900, 'reps' => 20, 'set_type' => 'warmup'],
            ['exercise_id' => $exerciseId, 'weight' => 950, 'reps' => 20, 'completed' => false],
        ]);
        $this->client->request('GET', '/v1/workouts/' . $sessionId, [], [], $headers);
        $this->assertSame([], $this->jsonResponse()['personal_records']);
        $this->client->jsonRequest('PATCH', '/v1/workouts/' . $sessionId, [
            'status' => 'completed', 'date' => '2026-10-18', 'duration_seconds' => 45,
        ], $headers);
        $this->assertResponseIsSuccessful();
        $records = $this->jsonResponse()['personal_records'];
        $this->assertCount(3, $records);
        $byType = array_column($records, null, 'type');
        foreach ([
            'max_weight' => [130, 120, $setIds[1]],
            'max_reps' => [16, 15, $setIds[1]],
            'max_e1rm' => [128.3, 116.7, $setIds[2]],
        ] as $type => [$value, $previous, $setId]) {
            $this->assertSame((float) $value, (float) $byType[$type]['value']);
            $this->assertSame((float) $previous, (float) $byType[$type]['previous_value']);
            $this->assertSame($setId, $byType[$type]['set_id']);
            $this->assertSame($exerciseId, $byType[$type]['exercise_id']);
            $this->assertSame('Durable summary records', $byType[$type]['exercise_name']);
        }
        [$laterId] = $startWithSets($headers, [['exercise_id' => $exerciseId, 'weight' => 800, 'reps' => 12]]);
        $this->client->jsonRequest('PATCH', '/v1/workouts/' . $laterId, ['status' => 'completed', 'date' => '2026-10-21'], $headers);
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/v1/workouts/' . $sessionId, [], [], $headers);
        $this->assertSame($records, $this->jsonResponse()['personal_records']);
        $this->client->request('GET', '/v1/workouts/' . $baselineId, [], [], $headers);
        $this->assertSame([], $this->jsonResponse()['personal_records']);
    }
}
