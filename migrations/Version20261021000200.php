<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional unique Hevy identity and official English and Spanish exercise names without changing catalog records';
    }

    public function up(Schema $schema): void
    {
        // Separate ADDs and index creation avoid SQLite's table-rebuild path with live foreign keys.
        $this->addSql('ALTER TABLE exercises ADD COLUMN hevy_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE exercises ADD COLUMN name_en VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE exercises ADD COLUMN name_es VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_exercises_hevy_id ON exercises (hevy_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropIndexSQL('uniq_exercises_hevy_id', 'exercises'));
        $this->addSql('ALTER TABLE exercises DROP COLUMN hevy_id');
        $this->addSql('ALTER TABLE exercises DROP COLUMN name_en');
        $this->addSql('ALTER TABLE exercises DROP COLUMN name_es');
    }
}
