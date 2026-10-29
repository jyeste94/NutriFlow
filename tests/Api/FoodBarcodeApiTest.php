<?php

namespace App\Tests\Api;

use App\Entity\Food;
use App\Entity\Serving;

final class FoodBarcodeApiTest extends ApiTestCase
{
    public function testBarcodeValidationRejectsMalformedCodes(): void
    {
        $headers = $this->authHeaders('barcode-test-user-1');

        // Letters
        $this->client->request('GET', '/v1/foods/barcode/84800001234A', [], [], $headers);
        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('Introduce un código de barras', $this->jsonResponse()['error'] ?? '');

        // Too short (5 digits)
        $this->client->request('GET', '/v1/foods/barcode/12345', [], [], $headers);
        $this->assertResponseStatusCodeSame(400);

        // POST also validates format
        $this->client->request('POST', '/v1/foods/barcode/12345', [], [], $headers);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testBarcodeReturnsDatabaseFoodIfAlreadyImported(): void
    {
        $headers = $this->authHeaders('barcode-test-user-2');
        $this->client->request('GET', '/v1/user/preferences', [], [], $headers);
        $user = $this->em->getRepository(\App\Entity\User::class)->findOneBy(['firebaseUid' => 'barcode-test-user-2']);
        $this->assertNotNull($user);

        $barcode = '8480000123456';
        $externalId = 'custom_off_' . $user->getId()->toRfc4122() . '_' . $barcode;

        // Seed food in DB with this externalId
        $food = new Food();
        $food->setUser($user);
        $food->setExternalId($externalId);
        $food->setName('Avena en Copos Hacendado');
        $food->setBrand('Hacendado');
        $food->setLastFetchedAt(new \DateTimeImmutable());

        $serving = new Serving();
        $serving->setFood($food);
        $serving->setDescription('100g');
        $serving->setAmount(100);
        $serving->setUnit('g');
        $serving->setCalories(375.0);
        $serving->setProteins(13.0);
        $serving->setCarbs(62.0);
        $serving->setFats(7.0);

        $food->addServing($serving);
        $this->em->persist($food);
        $this->em->persist($serving);
        $this->em->flush();

        $food->setBestServingId($serving->getId());
        $this->em->flush();

        // Query GET /v1/foods/barcode/8480000123456
        $this->client->request('GET', '/v1/foods/barcode/' . $barcode, [], [], $headers);
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();

        $this->assertSame('Avena en Copos Hacendado', $data['name']);
        $this->assertSame('Hacendado', $data['brand']);
        $this->assertNotEmpty($data['servings']);
        $this->assertEquals(375.0, $data['servings'][0]['calories']);

        // POST /v1/foods/barcode/8480000123456 returns the same food idempotently
        $this->client->request('POST', '/v1/foods/barcode/' . $barcode, [], [], $headers);
        $this->assertResponseIsSuccessful();
        $postData = $this->jsonResponse();
        $this->assertSame($data['id'], $postData['id']);
    }

    public function testBarcodeNotFoundReturnsJson404(): void
    {
        $headers = $this->authHeaders('barcode-test-user-3');

        // Query a dummy 13-digit code that does not exist in OFF mock or DB
        $this->client->request('GET', '/v1/foods/barcode/0000000000001', [], [], $headers);
        $this->assertResponseStatusCodeSame(404);
        $data = $this->jsonResponse();
        $this->assertSame('not_found', $data['status'] ?? null);
        $this->assertSame('0000000000001', $data['barcode'] ?? null);
    }
}
