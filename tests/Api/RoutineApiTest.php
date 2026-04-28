<?php

namespace App\Tests\Api;

final class RoutineApiTest extends ApiTestCase
{
    public function testCreateRoutineRejectsInvalidDaysOfWeek(): void
    {
        $this->client->jsonRequest(
            'POST',
            '/v1/routines',
            ['name' => 'My routine', 'daysOfWeek' => [1, 8]],
            $this->authHeaders('routine-user-1')
        );

        $this->assertResponseStatusCodeSame(400);
    }

    public function testGetAndUpdateRoutineWithExercises(): void
    {
        $headers = $this->authHeaders('routine-user-2');
        $exercise = $this->createExerciseFixture('Pull Up');
        $exerciseId = $exercise->getId()?->toRfc4122();
        $this->assertNotNull($exerciseId);

        $this->client->jsonRequest(
            'POST',
            '/v1/routines',
            [
                'name' => 'Initial routine',
                'daysOfWeek' => [2, 4],
                'exercises' => [
                    [
                        'exercise_id' => $exerciseId,
                        'sets' => 4,
                        'reps' => 8,
                        'restSeconds' => 75,
                    ],
                ],
            ],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);
        $routineId = (string) ($this->jsonResponse()['id'] ?? '');
        $this->assertNotSame('', $routineId);

        $this->client->request('GET', '/v1/routines/' . $routineId, [], [], $headers);
        $this->assertResponseIsSuccessful();
        $routine = $this->jsonResponse();
        $this->assertSame('Initial routine', $routine['name']);
        $this->assertCount(1, $routine['exercises']);

        $this->client->jsonRequest(
            'PUT',
            '/v1/routines/' . $routineId,
            [
                'name' => 'Updated routine',
                'daysOfWeek' => [1, 3, 5],
                'exercises' => [
                    [
                        'exercise_id' => $exerciseId,
                        'sets' => 5,
                        'reps' => 5,
                        'restSeconds' => 120,
                    ],
                ],
            ],
            $headers
        );
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/v1/routines/' . $routineId, [], [], $headers);
        $updated = $this->jsonResponse();
        $this->assertSame('Updated routine', $updated['name']);
        $this->assertSame([1, 3, 5], $updated['daysOfWeek']);
        $this->assertSame(5, $updated['exercises'][0]['sets']);
    }

    public function testDeleteRoutineIsForbiddenForOtherUser(): void
    {
        $ownerHeaders = $this->authHeaders('routine-owner-1');
        $otherHeaders = $this->authHeaders('routine-other-1');

        $this->client->jsonRequest('POST', '/v1/routines', ['name' => 'Owner routine'], $ownerHeaders);
        $this->assertResponseStatusCodeSame(201);
        $createData = $this->jsonResponse();
        $routineId = (string) ($createData['id'] ?? '');
        $this->assertNotSame('', $routineId);

        $this->client->request('DELETE', '/v1/routines/' . $routineId, [], [], $otherHeaders);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testRoutinesPaginationIncludesHeaders(): void
    {
        $headers = $this->authHeaders('routine-paged-user');

        $this->client->jsonRequest('POST', '/v1/routines', ['name' => 'A'], $headers);
        $this->assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/v1/routines', ['name' => 'B'], $headers);
        $this->assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/v1/routines', ['name' => 'C'], $headers);
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/v1/routines?page=2&limit=1', [], [], $headers);
        $this->assertResponseIsSuccessful();

        $response = $this->client->getResponse();
        $this->assertSame('3', $response->headers->get('X-Total-Count'));
        $this->assertSame('2', $response->headers->get('X-Page'));
        $this->assertSame('1', $response->headers->get('X-Per-Page'));

        $data = $this->jsonResponse();
        $this->assertCount(1, $data);
    }

    public function testCreateRoutineWithExercisesPersistsChildren(): void
    {
        $headers = $this->authHeaders('routine-ex-user');
        $exercise = $this->createExerciseFixture('Bench Press');
        $exerciseId = $exercise->getId()?->toRfc4122();
        $this->assertNotNull($exerciseId);

        $this->client->jsonRequest(
            'POST',
            '/v1/routines',
            [
                'name' => 'Routine with exercise',
                'daysOfWeek' => [1, 3, 5],
                'exercises' => [
                    [
                        'exercise_id' => $exerciseId,
                        'sets' => 3,
                        'reps' => 10,
                        'restSeconds' => 90,
                    ],
                ],
            ],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);
        $routineId = (string) ($this->jsonResponse()['id'] ?? '');
        $this->assertNotSame('', $routineId);

        $this->client->request('GET', '/v1/routines/' . $routineId, [], [], $headers);
        $this->assertResponseIsSuccessful();
        $routine = $this->jsonResponse();
        $this->assertCount(1, $routine['exercises'] ?? []);
        $this->assertSame($exerciseId, $routine['exercises'][0]['exercise']['id'] ?? null);
    }

    public function testCreateRoutineRejectsUnknownExerciseId(): void
    {
        $headers = $this->authHeaders('routine-ex-user-2');

        $this->client->jsonRequest(
            'POST',
            '/v1/routines',
            [
                'name' => 'Routine invalid exercise',
                'exercises' => [
                    [
                        'exercise_id' => '11111111-1111-1111-1111-111111111111',
                        'sets' => 3,
                        'reps' => 10,
                        'restSeconds' => 90,
                    ],
                ],
            ],
            $headers
        );

        $this->assertResponseStatusCodeSame(400);
    }
}
