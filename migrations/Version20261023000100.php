<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261023000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add missing workout set duration and distance columns without replacing existing data';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$schema->hasTable('workout_set_logs'), 'The workout_set_logs table must exist before repairing its metrics.');
        $sets = $schema->getTable('workout_set_logs');
        if (!$sets->hasColumn('duration_seconds')) {
            $this->addSql('ALTER TABLE workout_set_logs ADD duration_seconds INT DEFAULT NULL');
        }
        if (!$sets->hasColumn('distance_km')) {
            $this->addSql('ALTER TABLE workout_set_logs ADD distance_km DOUBLE PRECISION DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Workout metrics may contain training history or predate this repair; they must not be dropped automatically.');
    }
}
