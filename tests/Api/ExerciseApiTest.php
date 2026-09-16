<?php

namespace App\Tests\Api;

final class ExerciseApiTest extends ApiTestCase
{
    public function testExerciseListingSupportsFiltersAndPagination(): void
    {
        $this->createExerciseFixture('Chest Press');
        $back = $this->createExerciseFixture('Cable Row');
        $back->setMuscleGroup('back');
        $back->setEquipment('cable');
        $this->em->flush();

        $headers = $this->authHeaders('exercise-user-1');
        $this->client->request('GET', '/v1/exercises?muscleGroup=back&limit=1&page=1', [], [], $headers);
        $this->assertResponseIsSuccessful();

        $response = $this->client->getResponse();
        $this->assertSame('1', $response->headers->get('X-Total-Count'));
        $this->assertSame('1', $response->headers->get('X-Page'));
        $this->assertSame('1', $response->headers->get('X-Per-Page'));

        $data = $this->jsonResponse();
        $this->assertCount(1, $data);
        $this->assertSame('back', $data[0]['muscleGroup']);
    }

    public function testExerciseSearchAndGetOne(): void
    {
        $exercise = $this->createExerciseFixture('Leg Curl');
        $exerciseId = $exercise->getId()?->toRfc4122();
        $this->assertNotNull($exerciseId);

        $headers = $this->authHeaders('exercise-user-2');

        $this->client->request('GET', '/v1/exercises/search?q=leg', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $results = $this->jsonResponse();
        $this->assertCount(1, $results);
        $this->assertSame('Leg Curl', $results[0]['name']);
        $this->assertArrayHasKey('imageUrl', $results[0]);
        $this->assertArrayHasKey('thumbnailUrl', $results[0]);

        $this->client->request('GET', '/v1/exercises/' . $exerciseId, [], [], $headers);
        $this->assertResponseIsSuccessful();
        $detail = $this->jsonResponse();
        $this->assertSame($exerciseId, $detail['id']);
        $this->assertSame('Leg Curl', $detail['name']);
        $this->assertArrayHasKey('imageUrl', $detail);
        $this->assertArrayHasKey('thumbnailUrl', $detail);
    }

    public function testExerciseGetOneRejectsInvalidIdFormat(): void
    {
        $this->client->request('GET', '/v1/exercises/not-a-uuid', [], [], $this->authHeaders('exercise-user-3'));
        $this->assertResponseStatusCodeSame(400);
    }

    public function testExerciseCreateSupportsImageUrl(): void
    {
        $payload = [
            'name' => 'Sentadilla Hack',
            'muscleGroup' => 'Piernas',
            'equipment' => 'Máquina',
            'imageUrl' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Hack_Squat/0.jpg',
        ];

        $this->client->request('POST', '/v1/exercises', [], [], $this->authHeaders('exercise-user-4'), json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = $this->jsonResponse();
        $this->assertSame('Sentadilla Hack', $data['name']);
        $this->assertSame('https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Hack_Squat/0.jpg', $data['imageUrl']);
        $this->assertSame('https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Hack_Squat/0.jpg', $data['thumbnailUrl']);
    }
}
