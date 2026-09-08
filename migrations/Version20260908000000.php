<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_favorite_foods, saved_meals, saved_meal_items tables and add user_id to foods';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_favorite_foods (
            id CHAR(36) NOT NULL,
            user_id CHAR(36) NOT NULL,
            food_id CHAR(36) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            INDEX IDX_FAV_USER (user_id),
            INDEX IDX_FAV_FOOD (food_id),
            UNIQUE KEY uniq_user_favorite_food (user_id, food_id),
            CONSTRAINT FK_FAV_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT FK_FAV_FOOD FOREIGN KEY (food_id) REFERENCES foods (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE saved_meals (
            id CHAR(36) NOT NULL,
            user_id CHAR(36) NOT NULL,
            name VARCHAR(255) NOT NULL,
            preferred_meal_type VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            INDEX IDX_SAVED_MEAL_USER (user_id),
            CONSTRAINT FK_SAVED_MEAL_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE saved_meal_items (
            id CHAR(36) NOT NULL,
            saved_meal_id CHAR(36) NOT NULL,
            serving_id CHAR(36) DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            brand VARCHAR(255) DEFAULT NULL,
            amount_grams DECIMAL(10, 2) NOT NULL,
            calories DECIMAL(10, 2) NOT NULL,
            proteins DECIMAL(10, 2) NOT NULL,
            carbs DECIMAL(10, 2) NOT NULL,
            fats DECIMAL(10, 2) NOT NULL,
            position INT DEFAULT 0 NOT NULL,
            PRIMARY KEY(id),
            INDEX IDX_SAVED_ITEM_MEAL (saved_meal_id),
            INDEX IDX_SAVED_ITEM_SERVING (serving_id),
            CONSTRAINT FK_SAVED_ITEM_MEAL FOREIGN KEY (saved_meal_id) REFERENCES saved_meals (id) ON DELETE CASCADE,
            CONSTRAINT FK_SAVED_ITEM_SERVING FOREIGN KEY (serving_id) REFERENCES servings (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE foods ADD user_id CHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE foods ADD CONSTRAINT FK_foods_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_foods_user ON foods (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE foods DROP FOREIGN KEY FK_foods_user');
        $this->addSql('DROP INDEX IDX_foods_user ON foods');
        $this->addSql('ALTER TABLE foods DROP COLUMN user_id');
        $this->addSql('DROP TABLE IF EXISTS saved_meal_items');
        $this->addSql('DROP TABLE IF EXISTS saved_meals');
        $this->addSql('DROP TABLE IF EXISTS user_favorite_foods');
    }
}
