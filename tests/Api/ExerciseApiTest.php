<?php

namespace App\Tests\Api;

use App\Entity\Exercise;
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

    public function testOfficialBilingualNamesResolveToTheSameSpanishDefaultExercise(): void
    {
        $exercise = $this->createExerciseFixture('Sentadilla Zercher (Barra)')
            ->setHevyId('ZERCHER')
            ->setNameEn('Zercher Squat (Barbell)')
            ->setNameEs('Sentadilla Zercher (Barra)');
        $admin = (new User())->setFirebaseUid('bilingual-admin')->setRoles(['ROLE_ADMIN']);
        $this->em->persist($admin);
        $this->em->flush();
        $id = $exercise->getId()->toRfc4122();
        $headers = $this->authHeaders('exercise-official-names');

        foreach ([
            '/v1/exercises',
            '/v1/exercises/' . $id,
            '/v1/exercises/search?' . http_build_query(['q' => 'Zercher Squat (Barbell)']),
            '/v1/exercises/search?' . http_build_query(['q' => 'Sentadilla Zercher (Barra)']),
        ] as $endpoint) {
            $this->client->request('GET', $endpoint, [], [], $headers);
            $this->assertResponseIsSuccessful();
            $data = $this->jsonResponse();
            $row = $endpoint === '/v1/exercises/' . $id ? $data : $data[0];
            $this->assertSame($id, $row['id']);
            $this->assertSame('Sentadilla Zercher (Barra)', $row['name']);
            $this->assertSame('ZERCHER', $row['hevyId']);
            $this->assertSame('Zercher Squat (Barbell)', $row['nameEn']);
            $this->assertSame('Sentadilla Zercher (Barra)', $row['nameEs']);
        }

        // A custom label must not make either official name unsearchable.
        $this->client->request('PUT', '/v1/exercises/' . $id, [], [], $this->authHeaders('bilingual-admin'), json_encode(['name' => 'Mi ejercicio']));
        $this->assertResponseIsSuccessful();
        $updated = $this->jsonResponse();
        $this->assertSame('Mi ejercicio', $updated['name']);
        $this->assertSame('ZERCHER', $updated['hevyId']);
        $this->assertSame('Zercher Squat (Barbell)', $updated['nameEn']);
        $this->assertSame('Sentadilla Zercher (Barra)', $updated['nameEs']);
        foreach (['Zercher Squat', 'Sentadilla Zercher'] as $query) {
            $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => $query]), [], [], $headers);
            $this->assertResponseIsSuccessful();
            $this->assertSame([$id], array_column($this->jsonResponse(), 'id'));
        }
    }

    public function testSearchTreatsWildcardsInOfficialNamesLiterally(): void
    {
        $literal = $this->createExerciseFixture('Etiqueta local')
            ->setNameEn('Official_100%!')
            ->setNameEs('Oficial_100%!');
        $this->createExerciseFixture('Otra etiqueta')
            ->setNameEn('OfficialX100X')
            ->setNameEs('OficialX100X');
        $this->em->flush();

        foreach (['Official_', 'Oficial_', '100%', '%', '!'] as $query) {
            $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => $query]), [], [], $this->authHeaders('exercise-official-literal'));
            $this->assertResponseIsSuccessful();
            $this->assertSame([$literal->getId()->toRfc4122()], array_column($this->jsonResponse(), 'id'));
        }
    }

    public function testCatalogAndSearchCanPageBeyondFiftyWithStableDuplicateNameOrdering(): void
    {
        $expectedIds = [];
        for ($index = 0; $index < 205; ++$index) {
            $exercise = (new Exercise())->setName('Pagination Press')->setMuscleGroup('chest');
            $this->em->persist($exercise);
            $expectedIds[] = $exercise;
        }
        $this->em->flush();
        $expectedIds = array_map(fn (Exercise $exercise) => $exercise->getId()->toRfc4122(), $expectedIds);
        sort($expectedIds, SORT_STRING);
        $headers = $this->authHeaders('exercise-pagination');

        foreach (['/v1/exercises?', '/v1/exercises/search?q=pagination&'] as $endpoint) {
            $this->client->request('GET', $endpoint . 'limit=999&page=1', [], [], $headers);
            $this->assertResponseIsSuccessful();
            $response = $this->client->getResponse();
            $this->assertSame('205', $response->headers->get('X-Total-Count'));
            $this->assertSame('1', $response->headers->get('X-Page'));
            $this->assertSame('200', $response->headers->get('X-Per-Page'));
            $firstPage = $this->jsonResponse();
            $this->assertCount(200, $firstPage);

            $this->client->request('GET', $endpoint . 'limit=200&page=2', [], [], $headers);
            $this->assertResponseIsSuccessful();
            $this->assertSame('205', $this->client->getResponse()->headers->get('X-Total-Count'));
            $this->assertSame('2', $this->client->getResponse()->headers->get('X-Page'));
            $secondPage = $this->jsonResponse();
            $this->assertCount(5, $secondPage);
            $ids = array_column(array_merge($firstPage, $secondPage), 'id');
            $this->assertSame($expectedIds, $ids);
            $this->assertCount(205, array_unique($ids));

            $this->client->request('GET', $endpoint . 'limit=200&page=3', [], [], $headers);
            $this->assertResponseIsSuccessful();
            $this->assertSame([], $this->jsonResponse());
            $this->assertSame('205', $this->client->getResponse()->headers->get('X-Total-Count'));

            $this->client->request('GET', $endpoint . 'limit=0&page=0', [], [], $headers);
            $this->assertResponseIsSuccessful();
            $this->assertCount(1, $this->jsonResponse());
            $this->assertSame('1', $this->client->getResponse()->headers->get('X-Page'));
            $this->assertSame('1', $this->client->getResponse()->headers->get('X-Per-Page'));
        }
    }

    public function testSearchMatchesBilingualTokensAcrossFieldsWithoutMergingEquipmentOrVariants(): void
    {
        $flat = $this->createExerciseFixture('Localized label')
            ->setSourceName('Dumbbell Bench Press')
            ->setEquipment('dumbbell')
            ->setDescription('Start on a flat bench.');
        $incline = $this->createExerciseFixture('Incline Dumbbell Bench Press')
            ->setEquipment('dumbbell')
            ->setDescription('Use an inclined bench.');
        $decline = $this->createExerciseFixture('Decline Dumbbell Bench Press')
            ->setEquipment('dumbbell')
            ->setDescription('Use a decline bench.');
        $barbell = $this->createExerciseFixture('Barbell Bench Press')
            ->setEquipment('barbell')
            ->setDescription('Use a flat bench.');
        $spanish = $this->createExerciseFixture('Press de PÉCHO')
            ->setEquipment('MÁNCUERNAS')
            ->setDescription('Press sobre banca plana.');
        $this->em->flush();

        $cases = [
            ['press banca mancuernas', [$flat, $incline, $decline, $spanish]],
            ['MÁNCUERNAS en BÁNCA con PRESS', [$flat, $incline, $decline, $spanish]],
            ['plano press mancuernas banca', [$flat, $spanish]],
            ['flat dumbbell press', [$flat, $spanish]],
            ['press inclinado mancuernas', [$incline]],
            ['mancuernas declinado press', [$decline]],
            ['barra banca press', [$barbell]],
            ['pecho press mancuernas', [$spanish]],
        ];
        foreach ($cases as [$query, $expected]) {
            $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => $query]), [], [], $this->authHeaders('exercise-bilingual'));
            $this->assertResponseIsSuccessful();
            $this->assertEqualsCanonicalizing(
                array_map(fn (Exercise $exercise) => $exercise->getId()->toRfc4122(), $expected),
                array_column($this->jsonResponse(), 'id'),
                $query
            );
            $this->assertSame((string) count($expected), $this->client->getResponse()->headers->get('X-Total-Count'));
        }
    }

    public function testSearchExpandsMovementVocabularyInEitherLanguageAndTokenOrder(): void
    {
        $row = $this->createExerciseFixture('Bent Over Barbell Row')->setEquipment('barbell');
        $squat = $this->createExerciseFixture('Sentadilla con barra')->setEquipment('barra');
        $deadlift = $this->createExerciseFixture('Romanian Deadlift')->setEquipment('dumbbell');
        $this->em->flush();

        foreach ([
            ['remo con barra', $row],
            ['barbell squat', $squat],
            ['peso muerto rumano con mancuernas', $deadlift],
            ['muerto mancuernas peso rumano', $deadlift],
        ] as [$query, $expected]) {
            $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => $query]), [], [], $this->authHeaders('exercise-movements'));
            $this->assertResponseIsSuccessful();
            $this->assertSame([$expected->getId()->toRfc4122()], array_column($this->jsonResponse(), 'id'), $query);
        }
    }

    public function testSearchTreatsSqlWildcardsAndEscapeCharactersLiterally(): void
    {
        $percent = $this->createExerciseFixture('Literal 100% Press');
        $this->createExerciseFixture('Literal 100X Press');
        $underscore = $this->createExerciseFixture('Literal_under');
        $this->createExerciseFixture('LiteralXunder');
        $bang = $this->createExerciseFixture('Bang! Press');

        foreach ([['%', $percent], ['100%', $percent], ['_', $underscore], ['literal_', $underscore], ['bang!', $bang]] as [$query, $expected]) {
            $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => $query]), [], [], $this->authHeaders('exercise-literal'));
            $this->assertResponseIsSuccessful();
            $this->assertSame([$expected->getId()->toRfc4122()], array_column($this->jsonResponse(), 'id'), $query);
        }
    }

    public function testSearchRequiresAQueryAndDoesNotMatchEverythingForConnectorOnlyQueries(): void
    {
        $this->createExerciseFixture('Press');
        $headers = $this->authHeaders('exercise-empty-search');
        $this->client->request('GET', '/v1/exercises/search', [], [], $headers);
        $this->assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/v1/exercises/search?q=de+la+con', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $this->jsonResponse());
        $this->assertSame('0', $this->client->getResponse()->headers->get('X-Total-Count'));
    }

    public function testSearchRejectsOversizedQueriesAndExcessiveDistinctTerms(): void
    {
        foreach ([implode(' ', range(1, 1100)), implode(' ', range(1, 21)), str_repeat('ñ', 201)] as $query) {
            $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => $query]), [], [], $this->authHeaders('exercise-bounded-search'));
            $this->assertResponseStatusCodeSame(400);
        }
    }

    public function testSearchAcceptsTwoHundredUnicodeCharactersAndTwentyDistinctTerms(): void
    {
        $name = str_repeat('ñ', 199) . 'a';
        $unicodeExercise = $this->createExerciseFixture($name);
        $terms = implode(' ', range(1, 20));
        $termsExercise = $this->createExerciseFixture($terms);
        foreach ([[$name, $unicodeExercise], [$terms, $termsExercise]] as [$query, $expected]) {
            $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => $query]), [], [], $this->authHeaders('exercise-bounded-search'));
            $this->assertResponseIsSuccessful();
            $this->assertSame([$expected->getId()->toRfc4122()], array_column($this->jsonResponse(), 'id'));
        }
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
        $this->assertArrayHasKey('hevyId', $data);
        $this->assertArrayHasKey('nameEn', $data);
        $this->assertArrayHasKey('nameEs', $data);
        $this->assertNull($data['hevyId']);
        $this->assertNull($data['nameEn']);
        $this->assertNull($data['nameEs']);
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
