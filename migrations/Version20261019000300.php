<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist independent workout names, exact session durations and exercise order';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workout_sessions ADD name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE workout_sessions ADD duration_seconds INT DEFAULT NULL');
        $this->addSql('ALTER TABLE workout_set_logs ADD exercise_order INT DEFAULT 0 NOT NULL');
        $this->addSql("UPDATE workout_sessions SET name = COALESCE((SELECT routines.name FROM routines WHERE routines.id = workout_sessions.routine_id), 'Entrenamiento')");
        $this->addSql('UPDATE workout_sessions SET duration_seconds = duration_minutes * 60 WHERE duration_minutes IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workout_set_logs DROP COLUMN exercise_order');
        $this->addSql('ALTER TABLE workout_sessions DROP COLUMN duration_seconds');
        $this->addSql('ALTER TABLE workout_sessions DROP COLUMN name');
    }
}
