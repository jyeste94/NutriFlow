<?php

namespace App\Tests\Api;

use App\Entity\User;

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
        $admin = (new User())->setFirebaseUid('exercise-admin')->setRoles(['ROLE_ADMIN']);
        $this->em->persist($admin);
        $this->em->flush();

        $payload = [
            'name' => 'Sentadilla Hack',
            'muscleGroup' => 'Piernas',
            'equipment' => 'Máquina',
            'imageUrl' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Hack_Squat/0.jpg',
        ];

        $this->client->request('POST', '/v1/exercises', [], [], $this->authHeaders('exercise-admin'), json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = $this->jsonResponse();
        $this->assertSame('Sentadilla Hack', $data['name']);
        $this->assertSame('https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Hack_Squat/0.jpg', $data['imageUrl']);
        $this->assertSame('https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Hack_Squat/0.jpg', $data['thumbnailUrl']);
    }

    public function testOrdinaryUserCannotMutateGlobalCatalog(): void
    {
        $id = $this->createExerciseFixture('Protected exercise')->getId()->toRfc4122();
        $headers = $this->authHeaders('exercise-reader');

        $this->client->request('POST', '/v1/exercises', [], [], $headers, json_encode(['name' => 'Unauthorized addition']));
        $this->assertResponseStatusCodeSame(403);
        $this->client->request('PUT', '/v1/exercises/' . $id, [], [], $headers, json_encode(['name' => 'Unauthorized edit']));
        $this->assertResponseStatusCodeSame(403);
        $this->client->request('DELETE', '/v1/exercises/' . $id, [], [], $headers);
        $this->assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/v1/exercises', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $catalog = $this->jsonResponse();
        $this->assertCount(1, $catalog);
        $this->assertSame('Protected exercise', $catalog[0]['name']);
    }

    public function testAdministratorCanUpdateAndDeleteCatalogExercise(): void
    {
        $admin = (new User())->setFirebaseUid('catalog-admin')->setRoles(['ROLE_ADMIN']);
        $this->em->persist($admin);
        $id = $this->createExerciseFixture('Original exercise')->getId()->toRfc4122();
        $headers = $this->authHeaders('catalog-admin');

        $this->client->request('PUT', '/v1/exercises/' . $id, [], [], $headers, json_encode(['name' => 'Updated exercise']));
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/v1/exercises/' . $id, [], [], $this->authHeaders('catalog-reader'));
        $this->assertResponseIsSuccessful();
        $this->assertSame('Updated exercise', $this->jsonResponse()['name']);

        $this->client->request('DELETE', '/v1/exercises/' . $id, [], [], $headers);
        $this->assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/v1/exercises/' . $id, [], [], $headers);
        $this->assertResponseStatusCodeSame(404);
    }
}
