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

        $this->client->request('GET', '/v1/exercises/' . $exerciseId, [], [], $headers);
        $this->assertResponseIsSuccessful();
        $detail = $this->jsonResponse();
        $this->assertSame($exerciseId, $detail['id']);
        $this->assertSame('Leg Curl', $detail['name']);
    }

    public function testExerciseGetOneRejectsInvalidIdFormat(): void
    {
        $this->client->request('GET', '/v1/exercises/not-a-uuid', [], [], $this->authHeaders('exercise-user-3'));
        $this->assertResponseStatusCodeSame(400);
    }
}
