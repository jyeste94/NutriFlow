<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve saved ingredient amounts in grams or millilitres';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SqlitePlatform) {
            $this->addSql('ALTER TABLE saved_meal_items RENAME COLUMN amount_grams TO amount');
        } else {
            $this->addSql('ALTER TABLE saved_meal_items CHANGE amount_grams amount NUMERIC(10, 2) NOT NULL');
        }
        $this->addSql("ALTER TABLE saved_meal_items ADD unit VARCHAR(2) DEFAULT 'g' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne("SELECT COUNT(*) FROM saved_meal_items WHERE unit <> 'g'") > 0,
            'Cannot remove saved ingredient units while millilitre ingredients exist.'
        );
        $this->addSql('ALTER TABLE saved_meal_items DROP COLUMN unit');
        if ($this->connection->getDatabasePlatform() instanceof SqlitePlatform) {
            $this->addSql('ALTER TABLE saved_meal_items RENAME COLUMN amount TO amount_grams');
        } else {
            $this->addSql('ALTER TABLE saved_meal_items CHANGE amount amount_grams NUMERIC(10, 2) NOT NULL');
        }
    }
}
