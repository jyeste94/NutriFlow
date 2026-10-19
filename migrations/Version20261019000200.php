<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extend saved meals with recipe yield and preparation instructions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE saved_meals ADD servings INT DEFAULT 1 NOT NULL');
        if ($this->connection->getDatabasePlatform() instanceof SqlitePlatform) {
            $this->addSql("ALTER TABLE saved_meals ADD instructions CLOB DEFAULT '' NOT NULL");
        } else {
            $this->addSql('ALTER TABLE saved_meals ADD instructions LONGTEXT DEFAULT NULL');
            $this->addSql("UPDATE saved_meals SET instructions = '' WHERE instructions IS NULL");
            $this->addSql('ALTER TABLE saved_meals MODIFY instructions LONGTEXT NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE saved_meals DROP COLUMN instructions');
        $this->addSql('ALTER TABLE saved_meals DROP COLUMN servings');
    }
}
