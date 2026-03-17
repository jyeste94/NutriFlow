<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260311080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds indexes for exercise listing and routine pagination queries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_exercises_name ON exercises (name)');
        $this->addSql('CREATE INDEX idx_routines_user_name ON routines (user_id, name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_exercises_name ON exercises');
        $this->addSql('DROP INDEX idx_routines_user_name ON routines');
    }
}
