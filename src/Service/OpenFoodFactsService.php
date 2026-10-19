<?php

namespace App\Service;

use Symfony\Component\HttpKernel\Exception\BadGatewayHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\GatewayTimeoutHttpException;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenFoodFactsService
{
    public function __construct(private HttpClientInterface $httpClient) {}

    public function lookup(string $barcode): ?array
    {
        if (!preg_match('/^(?:\d{8}|\d{12}|\d{13}|\d{14})$/D', $barcode)) {
            throw new BadRequestHttpException('Introduce un código de barras de 8, 12, 13 o 14 cifras.');
        }

        try {
            $response = $this->httpClient->request('GET', 'https://world.openfoodfacts.org/api/v2/product/' . $barcode . '.json', [
                'query' => ['fields' => 'code,product_name,product_name_es,brands,nutriments,nutrition_data,serving_quantity_unit,product_quantity_unit,quantity'],
                'headers' => ['User-Agent' => 'ATHLOS-FIT/1.0 (food barcode lookup)', 'Accept' => 'application/json'],
                'timeout' => 8,
                'max_duration' => 10,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            if ($status === 404) {
                return null;
            }
            if ($status !== 200) {
                throw new \RuntimeException('Provider HTTP ' . $status);
            }
            $data = $response->toArray(false);
        } catch (TimeoutExceptionInterface $error) {
            throw new GatewayTimeoutHttpException('Open Food Facts no ha respondido a tiempo. Inténtalo de nuevo.', $error);
        } catch (\Throwable $error) {
            throw new BadGatewayHttpException('No se ha podido consultar Open Food Facts. Inténtalo de nuevo.', $error);
        }

        if (($data['status'] ?? null) === 0) {
            return null;
        }
        if (($data['status'] ?? null) !== 1 || !is_array($data['product'] ?? null)) {
            throw new BadGatewayHttpException('Open Food Facts ha devuelto una respuesta no válida.');
        }

        return $this->normalizeProduct($data['product'], $barcode);
    }

    public function normalizeProduct(array $product, string $barcode): array
    {
        $nutriments = is_array($product['nutriments'] ?? null) ? $product['nutriments'] : [];
        // OFF uses *_100g for both 100 g and 100 ml. Never infer grams from the key alone.
        $unit = null;
        foreach (['serving_quantity_unit', 'product_quantity_unit'] as $key) {
            $value = strtolower(trim((string) ($product[$key] ?? '')));
            $candidate = match ($value) { 'g', 'kg' => 'g', 'ml', 'cl', 'dl', 'l' => 'ml', default => null };
            if ($candidate !== null) {
                if ($unit !== null && $candidate !== $unit) {
                    $unit = null;
                    break;
                }
                $unit = $candidate;
            }
        }

        $calories = $this->nutrient($nutriments, 'energy-kcal', 'kcal', 1000);
        if ($calories === null) {
            $energyKj = $this->nutrient($nutriments, 'energy-kj', 'kj', 4184)
                ?? $this->nutrient($nutriments, 'energy', 'kj', 4184);
            $calories = $energyKj === null ? null : round($energyKj / 4.184, 2);
        }
        $proteins = $this->nutrient($nutriments, 'proteins', 'g', 100);
        $carbs = $this->nutrient($nutriments, 'carbohydrates', 'g', 100);
        $fats = $this->nutrient($nutriments, 'fat', 'g', 100);
        $complete = $unit !== null && $calories !== null && $proteins !== null && $carbs !== null && $fats !== null;

        return [
            'barcode' => $barcode,
            'name' => mb_substr(trim((string) ($product['product_name_es'] ?? $product['product_name'] ?? '')), 0, 255) ?: 'Producto sin nombre',
            'brand' => mb_substr(trim((string) ($product['brands'] ?? '')), 0, 255) ?: null,
            'source' => 'Open Food Facts',
            'sourceUrl' => 'https://world.openfoodfacts.org/product/' . $barcode,
            'baseAmount' => 100,
            'unit' => $unit,
            'calories' => $calories,
            'proteins' => $proteins,
            'carbs' => $carbs,
            'fats' => $fats,
            'nutritionStatus' => $complete ? 'available' : 'unavailable',
            'canImport' => $complete,
        ];
    }

    private function nutrient(array $data, string $key, string $unit, float $maximum): ?float
    {
        $value = $data[$key . '_100g'] ?? null;
        if (!is_numeric($value) || strtolower((string) ($data[$key . '_unit'] ?? '')) !== $unit) {
            return null;
        }
        $value = (float) $value;
        return is_finite($value) && $value >= 0 && $value <= $maximum ? $value : null;
    }
}
