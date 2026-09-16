<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260916160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tracking_type to exercises, add duration_seconds, distance_km, set_details and nullable reps to routine_exercises';
    }

    public function up(Schema $schema): void
    {
        $isSqlite = $this->connection->getDatabasePlatform() instanceof SqlitePlatform;

        if ($isSqlite) {
            $this->addSql("ALTER TABLE exercises ADD tracking_type VARCHAR(50) DEFAULT 'WEIGHT_REPS'");
            $this->addSql("ALTER TABLE routine_exercises ADD duration_seconds INT DEFAULT NULL");
            $this->addSql("ALTER TABLE routine_exercises ADD distance_km DOUBLE PRECISION DEFAULT NULL");
            $this->addSql("ALTER TABLE routine_exercises ADD set_details CLOB DEFAULT NULL");
        } else {
            $this->addSql("ALTER TABLE exercises ADD tracking_type VARCHAR(50) DEFAULT 'WEIGHT_REPS'");
            $this->addSql("ALTER TABLE routine_exercises MODIFY reps INT DEFAULT NULL");
            $this->addSql("ALTER TABLE routine_exercises ADD duration_seconds INT DEFAULT NULL, ADD distance_km DOUBLE PRECISION DEFAULT NULL, ADD set_details JSON DEFAULT NULL");
        }

        $this->addSql("UPDATE exercises SET tracking_type = 'DISTANCE_DURATION' WHERE LOWER(muscle_group) = 'cardio'");
    }

    public function down(Schema $schema): void
    {
        $isSqlite = $this->connection->getDatabasePlatform() instanceof SqlitePlatform;

        if (!$isSqlite) {
            $this->addSql("ALTER TABLE routine_exercises DROP COLUMN duration_seconds, DROP COLUMN distance_km, DROP COLUMN set_details");
            $this->addSql("ALTER TABLE routine_exercises MODIFY reps INT NOT NULL");
            $this->addSql("ALTER TABLE exercises DROP COLUMN tracking_type");
        }
    }
}
