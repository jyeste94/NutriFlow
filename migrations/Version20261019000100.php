<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track workout completion and idempotent ordered set identities';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE workout_sessions ADD status VARCHAR(16) DEFAULT 'active' NOT NULL");
        $this->addSql('ALTER TABLE workout_sessions ADD client_session_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_workout_client_session ON workout_sessions (user_id, client_session_id)');
        $this->addSql('ALTER TABLE workout_set_logs ADD client_set_id VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE workout_set_logs ADD set_number INT DEFAULT 1 NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_workout_client_set ON workout_set_logs (session_id, client_set_id)');
        // Legacy finish wrote a duration; a completed set alone does not prove the session ended.
        $this->addSql("UPDATE workout_sessions SET status = 'completed' WHERE duration_minutes IS NOT NULL AND EXISTS (SELECT 1 FROM workout_set_logs WHERE workout_set_logs.session_id = workout_sessions.id AND workout_set_logs.completed = 1)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->connection->getDatabasePlatform() instanceof SqlitePlatform
            ? 'DROP INDEX uniq_workout_client_session'
            : 'DROP INDEX uniq_workout_client_session ON workout_sessions');
        $this->addSql('ALTER TABLE workout_sessions DROP COLUMN client_session_id');
        $this->addSql($this->connection->getDatabasePlatform() instanceof SqlitePlatform
            ? 'DROP INDEX uniq_workout_client_set'
            : 'DROP INDEX uniq_workout_client_set ON workout_set_logs');
        $this->addSql('ALTER TABLE workout_set_logs DROP COLUMN client_set_id');
        $this->addSql('ALTER TABLE workout_set_logs DROP COLUMN set_number');
        $this->addSql('ALTER TABLE workout_sessions DROP COLUMN status');
    }
}
