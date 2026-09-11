<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DomCrawler\Crawler;

class FatSecretScraperService
{
    private HttpClientInterface $httpClient;

    public function __construct(HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    public function search(string $query, int $page = 0): array
    {
        if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? '') === 'test') {
            return [];
        }

        $url = 'https://www.fatsecret.es/ajax/JsonRecipeSearch.aspx';

        try {
            $response = $this->httpClient->request('GET', $url, [
                'query' => [
                    'exp' => $query,
                    'pg' => $page,
                ],
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'application/json',
                ],
                'timeout' => 5,
            ]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $content = $response->getContent(false);
            $data = json_decode($content, true);
            
            return $data['recipes'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function getInfo(string $recipeId, int $portionId = 0, int $portionAmount = 1): ?array
    {
        $url = 'https://www.fatsecret.es/ajax/RecipeInfo.aspx';

        try {
            $response = $this->httpClient->request('GET', $url, [
                'query' => [
                    'rid' => $recipeId,
                    'pid' => $portionId,
                    'pa' => $portionAmount,
                ],
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html, */*; q=0.01',
                ],
                'timeout' => 6,
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $html = $response->getContent(false);
            if (empty($html)) {
                return null;
            }

            $macros = [
                'calories' => null,
                'proteins' => null,
                'carbs' => null,
                'fats' => null,
            ];

            // Calorías: <div><b>Calor&#237;as:</b> 110</div>
            if (preg_match('/Calor(?:&\#237;|í)as:<\/b>\s*([\d,.]+)/ui', $html, $matches)) {
                $macros['calories'] = $this->parseFloat($matches[1]);
            }

            // Proteína: <div><b>Prote&#237;na:</b> 23g</div>
            if (preg_match('/Prote(?:&\#237;|í)na:<\/b>\s*([\d,.]+)/ui', $html, $matches)) {
                $macros['proteins'] = $this->parseFloat($matches[1]);
            }

            // Carbohidratos: <div><b>Total de Carbohidratos:</b> 1,9g</div>
            if (preg_match('/Total de Carbohidratos:<\/b>\s*([\d,.]+)/ui', $html, $matches)) {
                $macros['carbs'] = $this->parseFloat($matches[1]);
            }

            // Grasas: <div><b>Grasa Total:</b> 1g</div>
            if (preg_match('/Grasa Total:<\/b>\s*([\d,.]+)/ui', $html, $matches)) {
                $macros['fats'] = $this->parseFloat($matches[1]);
            }

            if ($macros['calories'] === null && $macros['proteins'] === null && $macros['carbs'] === null && $macros['fats'] === null) {
                return null;
            }

            $macros['calories'] = $macros['calories'] ?? 0.0;
            $macros['proteins'] = $macros['proteins'] ?? 0.0;
            $macros['carbs'] = $macros['carbs'] ?? 0.0;
            $macros['fats'] = $macros['fats'] ?? 0.0;

            return $macros;
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseFloat(string $text): float
    {
        $text = str_replace(',', '.', trim($text));
        return (float) $text;
    }
}
