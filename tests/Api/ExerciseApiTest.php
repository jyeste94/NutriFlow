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

    public function testDumbbellAbbreviationFindsInclineVariantsWithoutSubstringOrBarbellMatches(): void
    {
        $incline = $this->createExerciseFixture('Press de Banca Inclinado (Mancuerna)')
            ->setNameEn('Incline Bench Press (Dumbbell)')->setEquipment('Mancuernas');
        $hammer = $this->createExerciseFixture('Hammer Grip Incline DB Bench Press')->setEquipment(null);
        $this->createExerciseFixture('Press de Banca Inclinado (Barra)')->setEquipment('Barra Larga');
        $headband = $this->createExerciseFixture('Headband Neck Extension')->setEquipment('Ninguno');
        $this->createExerciseFixture('Deadbug')->setEquipment('Ninguno');
        $this->em->flush();
        $headers = $this->authHeaders('exercise-db-search');

        foreach (['Press inclinado DB', 'DB incline press', 'press inclinado mancuernas', 'db'] as $query) {
            $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => $query]), [], [], $headers);
            $this->assertResponseIsSuccessful();
            $this->assertEqualsCanonicalizing(
                [$incline->getId()->toRfc4122(), $hammer->getId()->toRfc4122()],
                array_column($this->jsonResponse(), 'id'),
                $query,
            );
        }

        $this->client->request('GET', '/v1/exercises/search?q=headband', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $this->assertSame([$headband->getId()->toRfc4122()], array_column($this->jsonResponse(), 'id'));
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

    public function testBenchPressShorthandIncludesCatalogNamesWithoutTheWordBench(): void
    {
        $flat = $this->createExerciseFixture('Dumbbell Bench Press')->setEquipment('dumbbell');
        $incline = $this->createExerciseFixture('Incline Dumbbell Press')->setEquipment('dumbbell');
        $official = $this->createExerciseFixture('Press de Banca Inclinado (Mancuerna)')
            ->setHevyId('07B38369')->setNameEn('Incline Bench Press (Dumbbell)')
            ->setNameEs('Press de Banca Inclinado (Mancuerna)')->setEquipment('dumbbell');
        $hammer = $this->createExerciseFixture('Hammer Grip Incline DB Bench Press')->setEquipment(null);
        $this->createExerciseFixture('Shoulder Press (Dumbbell)')->setEquipment('dumbbell');
        $this->createExerciseFixture('Arnold Dumbbell Press')->setEquipment('dumbbell')
            ->setDescription('An alternative to a dumbbell bench press.');
        $this->createExerciseFixture('Dumbbell Floor Press')->setEquipment('dumbbell');
        $this->createExerciseFixture('Incline Bench Press (Barbell)')->setEquipment('barbell');
        $this->em->flush();

        foreach (['press banca mancuernas', 'DB bench press'] as $query) {
            $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => $query]), [], [], $this->authHeaders('exercise-bench-compound'));
            $this->assertResponseIsSuccessful();
            $this->assertEqualsCanonicalizing(
                array_map(fn (Exercise $exercise) => $exercise->getId()->toRfc4122(), [$flat, $incline, $official, $hammer]),
                array_column($this->jsonResponse(), 'id'),
                $query,
            );
        }
    }

    public function testRearFlyColloquialismsPreserveEquipmentAndExcludeChestFly(): void
    {
        $machine = $this->createExerciseFixture('Vuelos Posteriores (Máquina)')
            ->setHevyId('D8281C62')->setNameEn('Rear Delt Reverse Fly (Machine)')
            ->setNameEs('Vuelos Posteriores (Máquina)')->setEquipment('machine');
        // Hevy deliberately labels this cable variant's metadata as "machine".
        $cable = $this->createExerciseFixture('Vuelos Posteriores (Cable)')
            ->setHevyId('C315DC2A')->setNameEn('Rear Delt Reverse Fly (Cable)')->setEquipment('machine');
        $dumbbell = $this->createExerciseFixture('Vuelos Posteriores (Mancuerna)')
            ->setHevyId('E5988A0A')->setNameEn('Rear Delt Reverse Fly (Dumbbell)')->setEquipment('dumbbell');
        $this->createExerciseFixture('Chest Fly (Machine)')->setEquipment('machine')
            ->setDescription('Unlike a rear delt reverse fly, this works the chest.');
        $this->createExerciseFixture('Barbell Rear Delt Row')->setEquipment('barbell');
        $this->em->flush();
        $headers = $this->authHeaders('exercise-rear-fly');

        foreach (['pajaros maquina', 'pájaros en máquina', 'rear delt reverse fly machine', 'aperturas posteriores maquina'] as $query) {
            $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => $query]), [], [], $headers);
            $this->assertResponseIsSuccessful();
            $this->assertSame([$machine->getId()->toRfc4122()], array_column($this->jsonResponse(), 'id'));
            $this->assertSame('D8281C62', $this->jsonResponse()[0]['hevyId']);
        }
        foreach ([
            ['pajaros cable', $cable],
            ['pajaros DB', $dumbbell],
        ] as [$query, $expected]) {
            $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => $query]), [], [], $headers);
            $this->assertResponseIsSuccessful();
            $this->assertSame([$expected->getId()->toRfc4122()], array_column($this->jsonResponse(), 'id'));
        }
    }

    public function testCatalogGroundedCompoundAliasesDoNotBroadenOtherMovements(): void
    {
        $pulldown = $this->createExerciseFixture('Wide-Grip Lat Pulldown')->setEquipment('cable');
        $this->createExerciseFixture('Triceps Pushdown')->setEquipment('cable');
        $this->createExerciseFixture('Chest Press')->setDescription('Finish with a lat pulldown.');
        $shoulder = $this->createExerciseFixture('Press de Hombros (Mancuerna)')
            ->setNameEn('Shoulder Press (Dumbbell)')->setEquipment('dumbbell');
        $curl = $this->createExerciseFixture('Curl de Piernas Acostado (Máquina)')
            ->setNameEn('Lying Leg Curl (Machine)')->setEquipment('machine');
        $this->createExerciseFixture('Hamstring Stretch');
        $lateral = $this->createExerciseFixture('Elevación Lateral (Cable)')
            ->setNameEn('Lateral Raise (Cable)')->setEquipment('machine');
        $skull = $this->createExerciseFixture('EZ-Bar Skullcrusher')->setEquipment('e-z curl bar');
        $this->em->flush();

        foreach ([
            ['Jalón al pecho', $pulldown],
            ['lat pull-down', $pulldown],
            ['press militar DB', $shoulder],
            ['curl femoral tumbado', $curl],
            ['curl isquios', $curl],
            ['curl isquiotibiales', $curl],
            ['laterales polea', $lateral],
            ['press francés', $skull],
        ] as [$query, $expected]) {
            $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => $query]), [], [], $this->authHeaders('exercise-compound-aliases'));
            $this->assertResponseIsSuccessful();
            $this->assertSame([$expected->getId()->toRfc4122()], array_column($this->jsonResponse(), 'id'), $query);
        }
    }

    public function testRankedSearchPrefersCanonicalNamesAndPagesWithoutLosingDistinctIds(): void
    {
        $regular = $this->createExerciseFixture('Incline Dumbbell Press')->setEquipment('dumbbell');
        $duplicate = $this->createExerciseFixture('Incline Dumbbell Press')->setEquipment('dumbbell');
        $official = $this->createExerciseFixture('Press de Banca Inclinado (Mancuerna)')
            ->setNameEn('Incline Bench Press (Dumbbell)')->setEquipment('dumbbell');
        $hammer = $this->createExerciseFixture('Hammer Grip Incline DB Bench Press')->setEquipment('dumbbell');
        $this->em->flush();
        $expected = [$regular->getId()->toRfc4122(), $duplicate->getId()->toRfc4122()];
        sort($expected, SORT_STRING);
        array_push($expected, $official->getId()->toRfc4122(), $hammer->getId()->toRfc4122());
        $headers = $this->authHeaders('exercise-ranking');

        foreach (['Press inclinado DB', 'DB incline press'] as $query) {
            $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => $query, 'limit' => 20]), [], [], $headers);
            $this->assertResponseIsSuccessful();
            $this->assertSame($expected, array_column($this->jsonResponse(), 'id'));
            $pagedIds = [];
            for ($page = 1; $page <= 2; ++$page) {
                $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => $query, 'limit' => 2, 'page' => $page]), [], [], $headers);
                $this->assertResponseIsSuccessful();
                $this->assertSame('4', $this->client->getResponse()->headers->get('X-Total-Count'));
                array_push($pagedIds, ...array_column($this->jsonResponse(), 'id'));
            }
            $this->assertSame($expected, $pagedIds);
            $this->assertCount(4, array_unique($pagedIds));
        }
        $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => 'incline DB bench press']), [], [], $headers);
        $this->assertResponseIsSuccessful();
        $this->assertSame(array_slice($expected, 0, 4), array_column($this->jsonResponse(), 'id'));
        $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => 'Incline Bench Press (Dumbbell)']), [], [], $headers);
        $this->assertResponseIsSuccessful();
        $this->assertSame($official->getId()->toRfc4122(), $this->jsonResponse()[0]['id']);
        $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => 'hammer grip incline db press']), [], [], $headers);
        $this->assertResponseIsSuccessful();
        $this->assertSame([$hammer->getId()->toRfc4122()], array_column($this->jsonResponse(), 'id'));
    }

    public function testEquipmentAbbreviationsAreBoundedNameTokens(): void
    {
        $db = $this->createExerciseFixture('Incline Press (DB)')->setEquipment(null);
        $bb = $this->createExerciseFixture('BB-Shoulder Press')->setEquipment(null);
        $kb = $this->createExerciseFixture('KB Swing')->setEquipment(null);
        $this->createExerciseFixture('Headband Neck Extension')->setEquipment(null);
        $this->createExerciseFixture('Deadbug')->setEquipment(null);
        $this->createExerciseFixture('Rubber Band Pull Apart')->setEquipment(null);
        $this->createExerciseFixture('Kickback')->setEquipment(null);
        $this->em->flush();
        foreach ([['db', $db], ['bb', $bb], ['kb', $kb]] as [$query, $expected]) {
            $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => $query]), [], [], $this->authHeaders('exercise-abbreviation-boundaries'));
            $this->assertResponseIsSuccessful();
            $this->assertSame([$expected->getId()->toRfc4122()], array_column($this->jsonResponse(), 'id'));
        }
    }

    public function testExplicitEquipmentAndAngleCannotComeFromAlternativeInstructions(): void
    {
        $incline = $this->createExerciseFixture('Incline Dumbbell Press')->setEquipment('dumbbell');
        $this->createExerciseFixture('Incline Barbell Bench Press')->setEquipment('barbell')
            ->setDescription('You can substitute dumbbells for the barbell.');
        $this->createExerciseFixture('Incline Bench Press (Smith Machine)')->setEquipment('machine')
            ->setDescription('An alternative to incline dumbbell press.');
        $this->createExerciseFixture('Dumbbell Bench Press')->setEquipment('dumbbell')
            ->setDescription('Can also be performed on an incline bench.');
        $romanian = $this->createExerciseFixture('Romanian Deadlift (Dumbbell)')->setEquipment('dumbbell');
        $this->createExerciseFixture('Romanian Deadlift (Barbell)')->setEquipment('barbell')
            ->setDescription('Dumbbells can be used as an alternative.');
        $rear = $this->createExerciseFixture('Rear Delt Reverse Fly (Dumbbell)')->setEquipment('dumbbell');
        $this->createExerciseFixture('Rear Delt Reverse Fly (Machine)')->setEquipment('machine')
            ->setDescription('Compare with a dumbbell reverse fly.');
        $military = $this->createExerciseFixture('Machine Shoulder (Military) Press')->setEquipment('machine');
        $this->em->flush();

        foreach ([
            ['press inclinado DB', $incline],
            ['peso muerto rumano DB', $romanian],
            ['pajaros mancuernas', $rear],
            ['Machine Shoulder (Military) Press', $military],
        ] as [$query, $expected]) {
            $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => $query]), [], [], $this->authHeaders('exercise-identity-qualifiers'));
            $this->assertResponseIsSuccessful();
            $this->assertSame([$expected->getId()->toRfc4122()], array_column($this->jsonResponse(), 'id'), $query);
        }
    }

    public function testNamedMovementsExcludeInstructionMentionsAndWordFragments(): void
    {
        $press = $this->createExerciseFixture('Incline Dumbbell Press')->setEquipment('dumbbell')
            ->setDescription('Keep the shoulders stable while pressing.');
        $shoulder = $this->createExerciseFixture('Shoulder Press (Dumbbell)')->setEquipment('dumbbell')
            ->setDescription('This shoulder press is not a chest press.');
        $this->createExerciseFixture('Incline Dumbbell Curl')->setEquipment('dumbbell')
            ->setDescription('Press the arm against the incline bench, then curl.');
        $thrust = $this->createExerciseFixture('Barbell Hip Thrust')->setEquipment('barbell');
        $this->createExerciseFixture('Power Clean')->setEquipment('barbell')
            ->setDescription('Thrust the hips and press the bar upward.');
        $row = $this->createExerciseFixture('Bent Over Barbell Row')->setEquipment('barbell');
        $this->createExerciseFixture('Backward Medicine Ball Throw')->setEquipment('medicine ball');
        $this->em->flush();

        foreach ([
            ['press inclinado DB', $press], ['hip thrust barra', $thrust], ['remo', $row],
            ['press hombros DB', $shoulder], ['shoulder press DB', $shoulder], ['chest press DB', $press],
        ] as [$query, $expected]) {
            $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => $query]), [], [], $this->authHeaders('exercise-named-movements'));
            $this->assertResponseIsSuccessful();
            $this->assertSame([$expected->getId()->toRfc4122()], array_column($this->jsonResponse(), 'id'));
        }
    }

    public function testColloquialRearFlySearchRetainsOriginalAndCustomNames(): void
    {
        $original = $this->createExerciseFixture('Reverse Machine Flyes')->setEquipment('machine');
        $custom = $this->createExerciseFixture('Pájaros en máquina')->setEquipment('Máquina');
        $this->createExerciseFixture('Chest Fly (Machine)')->setEquipment('machine');
        $this->em->flush();

        $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => 'pajaros maquina']), [], [], $this->authHeaders('exercise-original-alias'));
        $this->assertResponseIsSuccessful();
        $this->assertEqualsCanonicalizing(
            [$original->getId()->toRfc4122(), $custom->getId()->toRfc4122()],
            array_column($this->jsonResponse(), 'id'),
        );
    }

    public function testEquipmentAliasesRetainSpecializedBarsAndCustomKettlebellLabels(): void
    {
        $ez = $this->createExerciseFixture('Curl con Barra EZ')->setNameEn('EZ Bar Biceps Curl')->setEquipment('barbell');
        $customEz = $this->createExerciseFixture('Mi curl')->setEquipment('Barra Z');
        $this->createExerciseFixture('Bicep Curl (Barbell)')->setEquipment('barbell')
            ->setDescription('Can also be performed using an EZ bar.');
        $this->createExerciseFixture('Squeeze Barbell Curl')->setEquipment('barbell');
        $carry = $this->createExerciseFixture('Mi paseo')->setEquipment('Pesas rusas');
        $this->createExerciseFixture('Barbell Carry')->setEquipment('barbell')
            ->setDescription('Kettlebells can also be used.');
        $this->em->flush();

        foreach ([
            ['curl barra z', [$ez, $customEz]], ['EZ curl', [$ez, $customEz]],
            ['curl barra EZ', [$ez, $customEz]], ['KB', [$carry]], ['pesas rusas', [$carry]],
        ] as [$query, $expected]) {
            $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => $query]), [], [], $this->authHeaders('exercise-specialized-equipment'));
            $this->assertResponseIsSuccessful();
            $this->assertEqualsCanonicalizing(
                array_map(fn (Exercise $exercise) => $exercise->getId()->toRfc4122(), $expected),
                array_column($this->jsonResponse(), 'id'),
                $query,
            );
        }
    }

    public function testOfficialCableWordsRefineBroadMetadataWithoutChangingStoredEquipment(): void
    {
        $cable = $this->createExerciseFixture('Tirón entre piernas')
            ->setNameEn('Cable Pull Through')->setNameEs('Tirón con polea entre piernas')->setEquipment('machine');
        $machine = $this->createExerciseFixture('Cableless Fly')->setNameEn('Cableless Fly')->setEquipment('machine');
        $this->em->flush();

        foreach ([
            ['cable pull through', [$cable]], ['pull through maquina', []], ['cableless maquina', [$machine]],
        ] as [$query, $expected]) {
            $this->client->request('GET', '/v1/exercises/search?' . http_build_query(['q' => $query]), [], [], $this->authHeaders('exercise-official-equipment-words'));
            $this->assertResponseIsSuccessful();
            $this->assertSame(
                array_map(fn (Exercise $exercise) => $exercise->getId()->toRfc4122(), $expected),
                array_column($this->jsonResponse(), 'id'),
            );
        }
        $this->assertSame('machine', $cable->getEquipment());
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
