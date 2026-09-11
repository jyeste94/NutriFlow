<?php

namespace App\Tests\Api;

use App\Entity\Food;
use App\Entity\Serving;
use App\Service\FatSecretScraperService;
use App\Service\FoodService;

final class FoodSearchAndDetailIntegrationTest extends ApiTestCase
{
    public function testSearchStoresInitialServingWithCaloriesAndDetailHydratesFullMacros(): void
    {
        $headers = $this->authHeaders('food-test-search-user');

        // Create a mock scraper that simulates FatSecret responses
        $mockScraper = $this->createMock(FatSecretScraperService::class);
        $mockScraper->expects($this->any())
            ->method('search')
            ->willReturn([
                [
                    'id' => 59995643,
                    'title' => '100% Integral Molde',
                    'brand_name' => 'Hacendado',
                    'portion_id' => 0,
                    'portion_amount' => 1,
                    'portion_description' => '100g',
                    'energy' => 251.000,
                ],
                [
                    'id' => 51773251,
                    'title' => 'Pan Molde 100% Integral',
                    'brand_name' => 'Hacendado',
                    'portion_id' => 0,
                    'portion_amount' => 1,
                    'portion_description' => '1 rebanada (32g)',
                    'energy' => 79.000,
                ],
            ]);

        $mockScraper->expects($this->any())
            ->method('getInfo')
            ->willReturnCallback(function (string $id) {
                if ($id === '59995643') {
                    return [
                        'calories' => 253.0,
                        'proteins' => 11.0,
                        'carbs' => 38.0,
                        'fats' => 3.9,
                    ];
                }
                return null;
            });

        // Disable reboot so container mocks persist between requests
        $this->client->disableReboot();
        static::getContainer()->set(FatSecretScraperService::class, $mockScraper);

        // 1. Search for "Molde 100% integral"
        $this->client->request('GET', '/v1/foods/search?q=Molde', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $results = $this->jsonResponse();

        $this->assertCount(2, $results);

        // Check first result (100% Integral Molde)
        $first = $results[0];
        $this->assertSame('100% Integral Molde', $first['name']);
        $this->assertSame('Hacendado', $first['brand']);
        $this->assertSame(251.0, (float) $first['calories']);
        $this->assertSame('requires_detail', $first['nutritionStatus']);
        $this->assertTrue($first['hasNutritionInfo']);
        $this->assertNotNull($first['id']);
        $this->assertCount(1, $first['servings']);
        $this->assertSame(100.0, (float) $first['servings'][0]['amount']);
        $this->assertSame(251.0, (float) $first['servings'][0]['calories']);

        // Check second result (Pan Molde 100% Integral with slice portion)
        $second = $results[1];
        $this->assertSame('Pan Molde 100% Integral', $second['name']);
        $this->assertSame('Hacendado', $second['brand']);
        $this->assertSame(79.0, (float) $second['calories']);
        $this->assertSame('requires_detail', $second['nutritionStatus']);
        $this->assertSame(32.0, (float) $second['servings'][0]['amount']);
        $this->assertSame('1 rebanada (32g)', $second['servings'][0]['description']);

        $foodId = $first['id'];

        // 2. Fetch detail for first food: GET /v1/foods/{id}
        $this->client->request('GET', '/v1/foods/' . $foodId, [], [], $headers);
        $this->assertResponseIsSuccessful();
        $detail = $this->jsonResponse();

        $this->assertSame($foodId, $detail['id']);
        $this->assertSame('available', $detail['nutritionStatus']);
        $this->assertSame(253.0, (float) $detail['calories']);
        $this->assertSame(11.0, (float) $detail['proteins']);
        $this->assertSame(38.0, (float) $detail['carbs']);
        $this->assertSame(3.9, (float) $detail['fats']);
        $this->assertSame(100.0, (float) $detail['baseServingGrams']);

        // 3. Second detail fetch uses DB cache and returns the exact same data
        $this->client->request('GET', '/v1/foods/' . $foodId, [], [], $headers);
        $this->assertResponseIsSuccessful();
        $cachedDetail = $this->jsonResponse();

        $this->assertSame('available', $cachedDetail['nutritionStatus']);
        $this->assertSame(253.0, (float) $cachedDetail['calories']);
        $this->assertSame(11.0, (float) $cachedDetail['proteins']);
    }

    public function testScraperFailureDoesNotCrashAndPreservesExistingSearchCalories(): void
    {
        $headers = $this->authHeaders('food-test-fail-user');

        $mockScraper = $this->createMock(FatSecretScraperService::class);
        $mockScraper->expects($this->any())
            ->method('search')
            ->willReturn([
                [
                    'id' => 999999,
                    'title' => 'Alimento Test Fallo',
                    'portion_description' => '100g',
                    'energy' => 150.0,
                ]
            ]);

        // getInfo fails (returns null e.g. 404 or scraper down)
        $mockScraper->expects($this->any())
            ->method('getInfo')
            ->willReturn(null);

        $this->client->disableReboot();
        static::getContainer()->set(FatSecretScraperService::class, $mockScraper);

        $this->client->request('GET', '/v1/foods/search?q=Fallo', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $results = $this->jsonResponse();

        $foodId = $results[0]['id'];
        $this->assertSame(150.0, (float) $results[0]['calories']);

        // Requesting detail when scraper fails returns the food with search calories and requires_detail
        $this->client->request('GET', '/v1/foods/' . $foodId, [], [], $headers);
        $this->assertResponseIsSuccessful();
        $detail = $this->jsonResponse();

        $this->assertSame($foodId, $detail['id']);
        $this->assertSame(150.0, (float) $detail['calories']);
        $this->assertSame('requires_detail', $detail['nutritionStatus']);
        $this->assertNull($detail['proteins']);
    }

    public function testLiveFatSecretSearchAndDetailMolde(): void
    {
        $headers = $this->authHeaders('food-test-live-user');

        $liveScraper = new class(\Symfony\Component\HttpClient\HttpClient::create()) extends FatSecretScraperService {
            public function search(string $query, int $page = 0): array
            {
                $url = 'https://www.fatsecret.es/ajax/JsonRecipeSearch.aspx';
                $client = \Symfony\Component\HttpClient\HttpClient::create();
                $response = $client->request('GET', $url, [
                    'query' => ['exp' => $query, 'pg' => $page],
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36',
                        'Accept' => 'application/json',
                    ],
                    'timeout' => 8,
                ]);
                if ($response->getStatusCode() !== 200) return [];
                $data = json_decode($response->getContent(false), true);
                return $data['recipes'] ?? [];
            }
        };

        $this->client->disableReboot();
        static::getContainer()->set(FatSecretScraperService::class, $liveScraper);

        $this->client->request('GET', '/v1/foods/search?q=Molde%20100%25%20integral', [], [], $headers);
        $this->assertResponseIsSuccessful();
        $results = $this->jsonResponse();

        $this->assertNotEmpty($results);

        // Find Hacendado or first result
        $hacendado = null;
        foreach ($results as $item) {
            if (stripos($item['name'], 'Molde') !== false && ($item['brand'] === 'Hacendado' || stripos($item['brand'] ?? '', 'Hacendado') !== false)) {
                $hacendado = $item;
                break;
            }
        }
        $target = $hacendado ?? $results[0];

        $this->assertNotNull($target);
        $this->assertGreaterThan(0, (float) $target['calories']);
        $this->assertNotSame('0 kcal', $target['calories'] . ' kcal');
        $this->assertSame('requires_detail', $target['nutritionStatus']);

        // Now fetch detail
        $this->client->request('GET', '/v1/foods/' . $target['id'], [], [], $headers);
        $this->assertResponseIsSuccessful();
        $detail = $this->jsonResponse();

        $this->assertSame($target['id'], $detail['id']);
        $this->assertSame('available', $detail['nutritionStatus']);
        $this->assertGreaterThan(0, (float) $detail['calories']);
        $this->assertGreaterThan(0, (float) $detail['proteins']);
        $this->assertGreaterThan(0, (float) $detail['carbs']);
        $this->assertGreaterThan(0, (float) $detail['fats']);
    }
}
