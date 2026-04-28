<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed common Spanish foods';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->executeQuery('SELECT COUNT(*) FROM foods')->fetchOne() > 0) { return; }

        $foods = [
            ['Leche entera', 'Lletges', 'Leche entera 3.5% grasa', 100, 'g', 65, 3.2, 4.8, 3.5],
            ['Leche semidesnatada', 'Lletges', 'Leche semidesnatada 1.5% grasa', 100, 'g', 48, 3.2, 4.8, 1.5],
            ['Leche desnatada', 'Lletges', 'Leche desnatada 0.1% grasa', 100, 'g', 35, 3.4, 4.8, 0.1],
            ['Yogur natural', 'Danone', 'Yogur natural sin azúcar', 100, 'g', 61, 4.5, 4.7, 3.3],
            ['Yogur griego ligero', 'Danone', 'Yogur griego 0% grasa', 100, 'g', 55, 8.5, 4.0, 0.3],
            ['Queso fresco batido 0%', 'HSN', 'Queso fresco batido desnatado', 100, 'g', 52, 10.0, 3.5, 0.2],
            ['Requesón', 'ElPozo', 'Requesón o cottage cheese', 100, 'g', 80, 11.0, 3.0, 2.5],
            ['Kéfir natural', 'Keﬁr', 'Kéfir de leche entera', 100, 'g', 64, 3.5, 5.0, 3.0],
            ['Huevo entero', 'Huevos', 'Huevo de gallina tamaño M (60g)', 60, 'g', 78, 6.5, 0.6, 5.5],
            ['Clara de huevo', 'Huevos', 'Clara de huevo pasteurizada', 100, 'g', 48, 11.0, 0.7, 0.0],
            ['Pechuga de pollo', 'Pollo', 'Pechuga de pollo sin piel', 100, 'g', 165, 31.0, 0.0, 3.6],
            ['Muslo de pollo', 'Pollo', 'Muslo de pollo con piel', 100, 'g', 209, 26.0, 0.0, 11.0],
            ['Pechuga de pavo', 'Pavo', 'Pechuga de pavo sin piel', 100, 'g', 155, 29.0, 0.0, 3.2],
            ['Ternera magra', 'Ternera', 'Carne de ternera magra picada', 100, 'g', 200, 26.0, 0.0, 10.0],
            ['Solomillo de cerdo', 'Cerdo', 'Solomillo de cerdo magro', 100, 'g', 160, 26.0, 0.0, 5.5],
            ['Salmón', 'Pescado', 'Salmón fresco o congelado', 100, 'g', 208, 20.0, 0.0, 13.0],
            ['Atún al natural', 'Pescado', 'Atún en lata al natural escurrido', 100, 'g', 115, 26.0, 0.0, 1.0],
            ['Arroz blanco', 'Arroz', 'Arroz blanco cocido', 100, 'g', 130, 2.7, 28.0, 0.3],
            ['Arroz integral', 'Arroz', 'Arroz integral cocido', 100, 'g', 123, 2.7, 26.0, 0.8],
            ['Pasta integral', 'Pasta', 'Espaguetis integrales cocidos', 100, 'g', 130, 4.5, 25.0, 0.8],
            ['Pasta blanca', 'Pasta', 'Espaguetis blancos cocidos', 100, 'g', 140, 5.0, 28.0, 0.5],
            ['Copos de avena', 'Avena', 'Avena en copos integral', 100, 'g', 366, 13.5, 59.0, 6.5],
            ['Pan integral', 'Pan', 'Pan integral de molde (50g)', 50, 'g', 120, 4.5, 20.0, 1.5],
            ['Pan blanco', 'Pan', 'Pan de molde blanco (50g)', 50, 'g', 130, 4.0, 24.0, 1.0],
            ['Boniato', 'Verdura', 'Boniato o batata cocido', 100, 'g', 86, 1.6, 20.0, 0.1],
            ['Patata cocida', 'Verdura', 'Patata cocida con piel', 100, 'g', 78, 2.0, 17.0, 0.1],
            ['Lentejas secas', 'Legumbres', 'Lenteja seca sin cocer', 100, 'g', 353, 25.0, 60.0, 1.0],
            ['Lentejas cocidas', 'Legumbres', 'Lenteja cocida en conserva', 100, 'g', 116, 8.0, 20.0, 0.4],
            ['Brócoli', 'Verdura', 'Brócoli fresco', 100, 'g', 34, 2.8, 7.0, 0.4],
            ['Espinacas', 'Verdura', 'Espinacas frescas', 100, 'g', 23, 2.9, 3.6, 0.4],
            ['Setas', 'Verdura', 'Champiñones o setas frescas', 100, 'g', 22, 3.1, 3.3, 0.3],
            ['Tomate natural', 'Verdura', 'Tomate fresco', 100, 'g', 18, 0.9, 3.9, 0.2],
            ['Pimiento rojo', 'Verdura', 'Pimiento rojo fresco', 100, 'g', 31, 1.0, 6.0, 0.3],
            ['Cebolla', 'Verdura', 'Cebolla fresca', 100, 'g', 40, 1.1, 9.0, 0.1],
            ['Aguacate', 'Verdura', 'Aguacate fresco', 100, 'g', 160, 2.0, 8.5, 14.7],
            ['Espárragos trigueros', 'Verdura', 'Espárragos verdes frescos', 100, 'g', 20, 2.2, 3.9, 0.1],
            ['Manzana', 'Fruta', 'Manzana roja o verde', 100, 'g', 52, 0.3, 14.0, 0.2],
            ['Plátano', 'Fruta', 'Plátano maduro', 100, 'g', 89, 1.1, 23.0, 0.3],
            ['Naranja', 'Fruta', 'Naranja dulce', 100, 'g', 47, 0.9, 12.0, 0.1],
            ['Pera', 'Fruta', 'Pera conferencia', 100, 'g', 57, 0.4, 15.0, 0.1],
            ['Arándanos', 'Fruta', 'Arándanos frescos', 100, 'g', 57, 0.7, 14.5, 0.3],
            ['Fresas', 'Fruta', 'Fresas frescas', 100, 'g', 32, 0.7, 7.7, 0.3],
            ['Uvas', 'Fruta', 'Uvas blancas o negras', 100, 'g', 69, 0.7, 18.0, 0.2],
            ['Kiwi', 'Fruta', 'Kiwi verde', 100, 'g', 61, 1.1, 15.0, 0.5],
            ['Almendras', 'Frutos Secos', 'Almendras crudas sin cáscara', 100, 'g', 579, 21.0, 20.0, 50.0],
            ['Nueces', 'Frutos Secos', 'Nueces peladas', 100, 'g', 654, 15.0, 14.0, 65.0],
            ['Semillas calabaza', 'Semillas', 'Pipas de calabaza peladas', 100, 'g', 559, 30.0, 11.0, 49.0],
            ['Semillas lino', 'Semillas', 'Semillas de lino molidas', 100, 'g', 534, 18.0, 29.0, 42.0],
            ['Semillas chía', 'Semillas', 'Semillas de chía', 100, 'g', 486, 17.0, 42.0, 31.0],
            ['Aceite oliva virgen extra', 'AOVE', 'Aceite de oliva virgen extra', 10, 'ml', 88, 0.0, 0.0, 10.0],
            ['Café solo', 'Café', 'Café solo sin azúcar', 100, 'ml', 2, 0.1, 0.0, 0.0],
            ['Leche de soja', 'Bebidas', 'Bebida vegetal de soja sin azúcar', 100, 'ml', 33, 3.3, 1.5, 1.8],
            ['Gazpacho', 'Bebidas', 'Gazpacho tradicional', 100, 'ml', 30, 0.5, 4.0, 1.5],
            ['Proteína whey HSN', 'HSN', 'Proteína whey isolate HSN (30g)', 30, 'g', 112, 26.0, 1.0, 0.5],
            ['Creatina HSN', 'HSN', 'Creatina monohidrato micronizada (5g)', 5, 'g', 0, 0.0, 0.0, 0.0],
            ['Magnesio HSN', 'HSN', 'Magnesio bisglicinato o citrato (5g)', 5, 'g', 0, 0.0, 0.0, 0.0],
            ['Cacao puro desgrasado', 'Cacao', 'Cacao en polvo sin azúcar (10g)', 10, 'g', 22, 2.0, 1.0, 0.5],
            ['Jengibre', 'Especias', 'Jengibre fresco o en polvo (10g)', 10, 'g', 8, 0.2, 1.8, 0.1],
        ];

        $conn = $this->connection;
        foreach ($foods as $f) {
            $foodId = $conn->fetchOne('SELECT UUID()');
            $servingId = $conn->fetchOne('SELECT UUID()');
            $conn->executeStatement("INSERT INTO foods (id, external_id, name, brand, last_fetched_at, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW(), NOW())", [
                $foodId, uniqid('food-', true), $f[0], $f[1],
            ]);
            $conn->executeStatement("INSERT INTO servings (id, food_id, description, amount, unit, calories, proteins, carbs, fats) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", [
                $servingId, $foodId, $f[2], $f[3], $f[4], $f[5], $f[6], $f[7], $f[8],
            ]);
            $conn->executeStatement("UPDATE foods SET best_serving_id = ? WHERE id = ?", [$servingId, $foodId]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM servings');
        $this->addSql('DELETE FROM foods');
    }
}
