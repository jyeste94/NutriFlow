<?php

namespace App\Tests\Api;

use App\Entity\Food;
use App\Entity\MealEntry;
use App\Entity\Serving;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

final class DiaryApiTest extends ApiTestCase
{
    public function testGetDiaryReturnsCreatedEntriesAndTotals(): void
    {
        $serving = $this->createServingFixture();
        $servingId = $serving->getId()?->toRfc4122();
        $this->assertNotNull($servingId);

        $headers = $this->authHeaders('diary-user-2');

        $this->client->jsonRequest(
            'POST',
            '/v1/diaries/2026-03-12/entries',
            [
                'serving_id' => $servingId,
                'mealType' => 'lunch',
                'multiplier' => 2,
            ],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/v1/diaries/2026-03-12', [], [], $headers);
        $this->assertResponseIsSuccessful();

        $data = $this->jsonResponse();
        $this->assertEquals(200.0, $data['totalCalories']);
        $this->assertCount(1, $data['entries']);
        $this->assertSame('lunch', $data['entries'][0]['mealType']);
    }

    public function testAddEntryRejectsInvalidMealType(): void
    {
        $serving = $this->createServingFixture();
        $servingId = $serving->getId()?->toRfc4122();
        $this->assertNotNull($servingId);

        $this->client->jsonRequest(
            'POST',
            '/v1/diaries/2026-03-10/entries',
            [
                'serving_id' => $servingId,
                'mealType' => 'brunch',
                'multiplier' => 1.5,
            ],
            $this->authHeaders('diary-user-1')
        );

        $this->assertResponseStatusCodeSame(400);
    }

    public function testDeleteEntryIsForbiddenForOtherUser(): void
    {
        $serving = $this->createServingFixture();
        $servingId = $serving->getId()?->toRfc4122();
        $this->assertNotNull($servingId);

        $ownerHeaders = $this->authHeaders('diary-owner-1');
        $otherHeaders = $this->authHeaders('diary-other-1');

        $this->client->jsonRequest(
            'POST',
            '/v1/diaries/2026-03-11/entries',
            [
                'serving_id' => $servingId,
                'mealType' => 'lunch',
                'multiplier' => 1,
            ],
            $ownerHeaders
        );
        $this->assertResponseStatusCodeSame(201);
        $entryId = (string) ($this->jsonResponse()['entryId'] ?? '');
        $this->assertNotSame('', $entryId);

        $this->client->request('DELETE', '/v1/diaries/entries/' . $entryId, [], [], $otherHeaders);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testDeleteEntryRemovesItFromDiary(): void
    {
        $serving = $this->createServingFixture('food-ext-delete');
        $servingId = $serving->getId()?->toRfc4122();
        $this->assertNotNull($servingId);

        $headers = $this->authHeaders('diary-user-3');
        $this->client->jsonRequest(
            'POST',
            '/v1/diaries/2026-03-13/entries',
            [
                'serving_id' => $servingId,
                'mealType' => 'dinner',
                'multiplier' => 1,
            ],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);
        $entryId = (string) ($this->jsonResponse()['entryId'] ?? '');
        $this->assertNotSame('', $entryId);

        $this->client->request('DELETE', '/v1/diaries/entries/' . $entryId, [], [], $headers);
        $this->assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/v1/diaries/2026-03-13', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();
        $this->assertCount(0, $data['entries']);
        $this->assertSame(0, $data['totalCalories']);
    }

    public function testDeleteEntryIsIdempotentWhenAlreadyDeleted(): void
    {
        $headers = $this->authHeaders('diary-user-idempotent');
        // Random non-existent UUID
        $fakeUuid = '00000000-0000-0000-0000-000000000001';

        $this->client->request('DELETE', '/v1/diaries/entries/' . $fakeUuid, [], [], $headers);
        $this->assertResponseStatusCodeSame(204);
    }

    public function testGetDiaryReturnsComputedEntryMacrosAndAmounts(): void
    {
        $serving = $this->createServingFixture('food-ext-macros');
        $servingId = $serving->getId()?->toRfc4122();
        $this->assertNotNull($servingId);

        $headers = $this->authHeaders('diary-user-macros');
        $this->client->jsonRequest(
            'POST',
            '/v1/diaries/2026-03-14/entries',
            [
                'serving_id' => $servingId,
                'mealType' => 'breakfast',
                'multiplier' => 2.5,
            ],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/v1/diaries/2026-03-14', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();

        $this->assertCount(1, $data['entries']);
        $entry = $data['entries'][0];
        $this->assertSame('breakfast', $entry['mealType']);
        $this->assertEquals(2.5, $entry['multiplier']);
        $this->assertEquals(250.0, $entry['calories']);
        $this->assertArrayHasKey('proteins', $entry);
        $this->assertArrayHasKey('carbs', $entry);
        $this->assertArrayHasKey('fats', $entry);
    }

    public function testUpdateEntryChangesMultiplierAndMealType(): void
    {
        $serving = $this->createServingFixture('food-ext-update');
        $servingId = $serving->getId()?->toRfc4122();
        $this->assertNotNull($servingId);

        $headers = $this->authHeaders('diary-user-update');
        $this->client->jsonRequest(
            'POST',
            '/v1/diaries/2026-03-15/entries',
            [
                'serving_id' => $servingId,
                'mealType' => 'breakfast',
                'multiplier' => 1.0,
            ],
            $headers
        );
        $this->assertResponseStatusCodeSame(201);
        $entryId = (string) ($this->jsonResponse()['entryId'] ?? '');
        $this->assertNotSame('', $entryId);

        // Update multiplier to 3.0 and move to lunch
        $this->client->jsonRequest(
            'PUT',
            '/v1/diaries/entries/' . $entryId,
            [
                'mealType' => 'lunch',
                'multiplier' => 3.0,
            ],
            $headers
        );
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();

        $this->assertSame('lunch', $data['entry']['mealType']);
        $this->assertEquals(3.0, $data['entry']['multiplier']);
        $this->assertEquals(300.0, $data['entry']['calories']);
        $this->assertEquals(300.0, $data['diary']['totalCalories']);

        // Verify with subsequent GET
        $this->client->request('GET', '/v1/diaries/2026-03-15', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $getData = $this->jsonResponse();
        $this->assertEquals(300.0, $getData['totalCalories']);
        $this->assertSame('lunch', $getData['entries'][0]['mealType']);
    }

    public function testUpdateEntryIsForbiddenForOtherUser(): void
    {
        $serving = $this->createServingFixture('food-ext-update-forbid');
        $servingId = $serving->getId()?->toRfc4122();
        $this->assertNotNull($servingId);

        $ownerHeaders = $this->authHeaders('diary-owner-update');
        $otherHeaders = $this->authHeaders('diary-other-update');

        $this->client->jsonRequest(
            'POST',
            '/v1/diaries/2026-03-16/entries',
            [
                'serving_id' => $servingId,
                'mealType' => 'dinner',
                'multiplier' => 1.0,
            ],
            $ownerHeaders
        );
        $this->assertResponseStatusCodeSame(201);
        $entryId = (string) ($this->jsonResponse()['entryId'] ?? '');

        $this->client->jsonRequest(
            'PUT',
            '/v1/diaries/entries/' . $entryId,
            [
                'multiplier' => 2.0,
            ],
            $otherHeaders
        );
        $this->assertResponseStatusCodeSame(403);
    }
    public function testClientIdentityRetriesOnceButPreservesSeparateIdenticalConsumptions(): void
    {
        $serving = $this->createServingFixture('diary-idempotency-serving');
        $serving->setAmount(150);
        $serving->setUnit('g');
        $serving->setCalories(300);
        $this->em->flush();
        $headers = $this->authHeaders('diary-idempotency');
        $payload = [
            'client_id' => '12ae161b-95a8-4ce1-aeb7-d37949ea7814',
            'serving_id' => $serving->getId()?->toRfc4122(), 'mealType' => 'breakfast',
            'amountGrams' => 150, 'multiplier' => 1.5,
        ];
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-19/entries', $payload, $headers);
        $this->assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-19/entries', $payload, $headers);
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/v1/diaries/2026-10-19', [], [], $headers);
        $diary = $this->jsonResponse();
        $this->assertCount(1, $diary['entries']);
        $this->assertEquals(150, $diary['entries'][0]['amount']);
        $this->assertEquals(300, $diary['totalCalories']);
        $payload['client_id'] = 'e843f14f-18d4-40ac-9adb-24e01b1c86c9';
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-19/entries', $payload, $headers);
        $this->assertResponseStatusCodeSame(201);
        $this->client->request('GET', '/v1/diaries/2026-10-19', [], [], $headers);
        $this->assertCount(2, $this->jsonResponse()['entries']);
        $this->assertEquals(600, $this->jsonResponse()['totalCalories']);
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-19/entries', $payload, $this->authHeaders('other-diary-user'));
        $this->assertResponseStatusCodeSame(409);
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-20/entries', $payload, $headers);
        $this->assertResponseStatusCodeSame(409);
    }

    public static function unavailableServingAmounts(): array
    {
        return [
            'missing gram amount' => [null, 'amountGrams', 'g'],
            'zero gram amount' => [0.0, 'amountGrams', 'g'],
            'missing millilitre amount' => [null, 'amountMl', 'ml'],
            'zero millilitre amount' => [0.0, 'amountMl', 'ml'],
        ];
    }

    #[DataProvider('unavailableServingAmounts')]
    public function testPendingMeasuredSnapshotSurvivesAnUnavailableServingAmountAndRetries(?float $sourceAmount, string $amountField, string $unit): void
    {
        $source = $this->createServingFixture('pending-yogurt');
        $source->setDescription('Ración sin peso conocido');
        $source->setAmount($sourceAmount);
        $source->setUnit($unit === 'ml' ? 'ml' : null);
        $source->setCalories(60);
        $source->setProteins(5.8);
        $source->setCarbs(4.72);
        $source->setFats(2);
        $this->em->flush();
        $sourceId = $source->getId()->toRfc4122();
        $headers = $this->authHeaders('diary-yogurt-recovery');
        $payload = [
            'client_id' => 'c5473fe4-ce80-40ef-94d8-0849a5931b8d',
            'serving_id' => $sourceId,
            'mealType' => 'breakfast',
            'multiplier' => 2.5,
            'name' => 'Yogur Griego Ligero',
            'brand' => 'Mercadona',
            $amountField => 250,
            'calories' => 150,
            'proteins' => 14.5,
            'carbs' => 11.8,
            'fats' => 5,
        ];

        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-20/entries', $payload, $headers);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame($payload['client_id'], $this->jsonResponse()['entryId']);
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-20/entries', $payload, $headers);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame($payload['client_id'], $this->jsonResponse()['entryId']);
        $this->client->request('GET', '/v1/diaries/2026-10-20', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $diary = $this->jsonResponse();
        $this->assertCount(1, $diary['entries']);
        $entry = $diary['entries'][0];
        $this->assertSame($payload['client_id'], $entry['id']);
        $this->assertEquals(250, $entry['amount']);
        $this->assertSame($unit, $entry['unit']);
        $this->assertEquals(1, $entry['multiplier']);
        foreach (['calories' => 'totalCalories', 'proteins' => 'totalProteins', 'carbs' => 'totalCarbs', 'fats' => 'totalFats'] as $field => $total) {
            $this->assertEquals($payload[$field], $entry[$field]);
            $this->assertEquals($payload[$field], $diary[$total]);
        }
        $this->assertSame($payload['name'], $entry['food']['name']);
        $this->assertSame($payload['brand'], $entry['food']['brand']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $stored = $em->find(MealEntry::class, $payload['client_id']);
        $this->assertSame('diary-yogurt-recovery', $stored->getServing()->getFood()->getUser()->getFirebaseUid());
        $this->assertNotSame($sourceId, $stored->getServing()->getId()->toRfc4122());
        $this->assertSame($sourceAmount, $em->find(Serving::class, $sourceId)->getAmount());
        $this->assertEquals(60, $em->find(Serving::class, $sourceId)->getCalories());
        $this->assertSame(2, $em->getRepository(Food::class)->count([]));
    }

    public function testKnownServingKeepsItsQuantityAndNutritionInsteadOfTheClientSnapshot(): void
    {
        $source = $this->createServingFixture('known-serving');
        $source->setAmount(125);
        $source->setUnit('g');
        $source->setCalories(80);
        $source->setProteins(7);
        $source->setCarbs(9);
        $source->setFats(2);
        $this->em->flush();
        $sourceId = $source->getId()->toRfc4122();
        $headers = $this->authHeaders('known-serving-diary');
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-20/entries', [
            'serving_id' => $sourceId, 'mealType' => 'breakfast', 'multiplier' => 2.5,
            'name' => 'Client snapshot', 'amountGrams' => 250,
            'calories' => 150, 'proteins' => 14.5, 'carbs' => 11.8, 'fats' => 5,
        ], $headers);
        $this->assertResponseStatusCodeSame(201);
        $this->client->request('GET', '/v1/diaries/2026-10-20', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $entry = $this->jsonResponse()['entries'][0];
        $this->assertSame($sourceId, $entry['serving']['id']);
        $this->assertEquals(2, $entry['multiplier']);
        $this->assertEquals(250, $entry['amount']);
        $this->assertEquals(160, $entry['calories']);
        $this->assertEquals(14, $entry['proteins']);
        $this->assertEquals(18, $entry['carbs']);
        $this->assertEquals(4, $entry['fats']);
    }

    public function testUnweighedServingCannotSaveAnIncompleteOrInvalidMeasuredSnapshot(): void
    {
        $source = $this->createServingFixture('unweighed-serving');
        $headers = $this->authHeaders('invalid-snapshot-diary');
        $payload = [
            'serving_id' => $source->getId()->toRfc4122(), 'mealType' => 'breakfast',
            'name' => 'Yogur', 'amountGrams' => 250,
            'calories' => 150, 'proteins' => 14.5, 'carbs' => 11.8, 'fats' => 5,
        ];
        foreach ([
            ['name' => ''],
            ['proteins' => null],
            ['carbs' => -1],
            ['amountGrams' => 0],
            ['amountGrams' => 10001],
        ] as $invalidFields) {
            $this->client->jsonRequest('POST', '/v1/diaries/2026-10-20/entries', array_replace($payload, $invalidFields), $headers);
            $this->assertResponseStatusCodeSame(400);
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->assertSame(1, $em->getRepository(Food::class)->count([]));
        $this->assertSame(0, $em->getRepository(MealEntry::class)->count([]));
    }

    public function testInlineMillilitreSnapshotKeepsItsUnitNutritionAndRetryIdentity(): void
    {
        $headers = $this->authHeaders('inline-oil-owner');
        $payload = [
            'client_id' => '8c992ce1-2f43-4a67-b613-b1ad14536f6d',
            'mealType' => 'breakfast', 'name' => 'Aceite Oliva Virgen Extra', 'brand' => 'Mercadona',
            'amountMl' => 5, 'calories' => 40, 'proteins' => 0, 'carbs' => 0, 'fats' => 4.5,
        ];
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-22/entries', $payload, $headers);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame($payload['client_id'], $this->jsonResponse()['entryId']);
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-22/entries', $payload, $headers);
        $this->assertResponseStatusCodeSame(200);
        $this->client->request('GET', '/v1/diaries/2026-10-22', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $diary = $this->jsonResponse();
        $this->assertCount(1, $diary['entries']);
        $entry = $diary['entries'][0];
        $this->assertSame($payload['client_id'], $entry['id']);
        $this->assertEquals(5, $entry['amount']);
        $this->assertSame('ml', $entry['unit']);
        foreach (['calories' => 'totalCalories', 'proteins' => 'totalProteins', 'carbs' => 'totalCarbs', 'fats' => 'totalFats'] as $field => $total) {
            $this->assertEquals($payload[$field], $entry[$field]);
            $this->assertEquals($payload[$field], $diary[$total]);
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $stored = $em->find(MealEntry::class, $payload['client_id']);
        $this->assertSame('inline-oil-owner', $stored->getServing()->getFood()->getUser()->getFirebaseUid());
    }

    public function testKnownMillilitreServingScalesFromItsMillilitreReference(): void
    {
        $source = $this->createServingFixture('known-oil-serving');
        $source->setAmount(20);
        $source->setUnit('ml');
        $source->setCalories(180);
        $source->setProteins(0);
        $source->setCarbs(0);
        $source->setFats(20);
        $this->em->flush();
        $sourceId = $source->getId()->toRfc4122();
        $headers = $this->authHeaders('known-oil-owner');
        $this->client->jsonRequest('POST', '/v1/diaries/2026-10-22/entries', [
            'serving_id' => $sourceId, 'mealType' => 'breakfast', 'amountMl' => 5, 'multiplier' => 99,
            'name' => 'Ignored client snapshot', 'calories' => 999, 'proteins' => 999, 'carbs' => 999, 'fats' => 999,
        ], $headers);
        $this->assertResponseStatusCodeSame(201);
        $this->client->request('GET', '/v1/diaries/2026-10-22', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $entry = $this->jsonResponse()['entries'][0];
        $this->assertSame($sourceId, $entry['serving']['id']);
        $this->assertEquals(5, $entry['amount']);
        $this->assertSame('ml', $entry['unit']);
        $this->assertEquals(0.25, $entry['multiplier']);
        $this->assertEquals(45, $entry['calories']);
        $this->assertEquals(5, $entry['fats']);
    }

    public function testIndividualDiaryRejectsCrossUnitAmountsWithoutWriting(): void
    {
        $sourceIds = [];
        foreach (['g', 'ml'] as $unit) {
            $source = $this->createServingFixture('diary-unit-mismatch-' . $unit);
            $source->setAmount(100);
            $source->setUnit($unit);
            $sourceIds[$unit] = $source->getId()->toRfc4122();
        }
        $this->em->flush();
        $headers = $this->authHeaders('diary-unit-mismatch');
        foreach (['g' => 'amountMl', 'ml' => 'amountGrams'] as $unit => $amountField) {
            $this->client->jsonRequest('POST', '/v1/diaries/2026-10-22/entries', [
                'serving_id' => $sourceIds[$unit], 'mealType' => 'breakfast', $amountField => 5,
                'name' => 'Do not convert', 'calories' => 40, 'proteins' => 0, 'carbs' => 0, 'fats' => 4.5,
            ], $headers);
            $this->assertResponseStatusCodeSame(400);
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->assertSame(2, $em->getRepository(Food::class)->count([]));
        $this->assertSame(0, $em->getRepository(MealEntry::class)->count([]));
    }

    public function testInlineMillilitreValidationRejectsConflictingOrInvalidAmountsWithoutWriting(): void
    {
        $headers = $this->authHeaders('invalid-inline-millilitres');
        $payload = [
            'mealType' => 'breakfast', 'name' => 'Aceite Oliva Virgen Extra', 'amountMl' => 5,
            'calories' => 40, 'proteins' => 0, 'carbs' => 0, 'fats' => 4.5,
        ];
        foreach ([
            ['amountGrams' => 5], ['amountMl' => 0], ['amountMl' => -1], ['amountMl' => 10001],
            ['amountMl' => null], ['amountMl' => 'NaN'], ['amountMl' => 'INF'], ['proteins' => null],
        ] as $invalidFields) {
            $this->client->jsonRequest('POST', '/v1/diaries/2026-10-22/entries', array_replace($payload, $invalidFields), $headers);
            $this->assertResponseStatusCodeSame(400);
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->assertSame(0, $em->getRepository(Food::class)->count([]));
        $this->assertSame(0, $em->getRepository(MealEntry::class)->count([]));
    }

}

