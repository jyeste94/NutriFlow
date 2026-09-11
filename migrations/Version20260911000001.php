<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add target_weight column to routine_exercises';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE routine_exercises ADD target_weight DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE routine_exercises DROP COLUMN target_weight');
    }
}