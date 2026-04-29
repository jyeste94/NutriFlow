<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260429000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_preferences, shopping_list_items, and fasting_logs tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_preferences (
            id CHAR(36) NOT NULL,
            user_id CHAR(36) NOT NULL,
            preferences JSON NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY UNIQ_USER_PREFS (user_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE shopping_list_items (
            id CHAR(36) NOT NULL,
            user_id CHAR(36) NOT NULL,
            food_name VARCHAR(255) NOT NULL,
            quantity VARCHAR(50) DEFAULT NULL,
            unit VARCHAR(50) DEFAULT NULL,
            checked TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY IDX_SHOPPING_USER (user_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE fasting_logs (
            id CHAR(36) NOT NULL,
            user_id CHAR(36) NOT NULL,
            date DATE NOT NULL,
            start_time TIME DEFAULT NULL,
            end_time TIME DEFAULT NULL,
            duration_hours DECIMAL(5,2) DEFAULT 0,
            notes TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY IDX_FASTING_USER (user_id),
            KEY IDX_FASTING_DATE (date)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS fasting_logs');
        $this->addSql('DROP TABLE IF EXISTS shopping_list_items');
        $this->addSql('DROP TABLE IF EXISTS user_preferences');
    }
}
