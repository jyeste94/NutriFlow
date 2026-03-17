<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260304104837 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql("CREATE TABLE workout_sessions (id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', date DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', duration_minutes INT DEFAULT NULL, user_id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', routine_id CHAR(36) DEFAULT NULL COMMENT '(DC2Type:uuid)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('CREATE INDEX IDX_421170A5A76ED395 ON workout_sessions (user_id)');
        $this->addSql('CREATE INDEX IDX_421170A5F27A94C7 ON workout_sessions (routine_id)');
        $this->addSql("CREATE TABLE workout_set_logs (id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', weight DOUBLE NOT NULL, reps INT NOT NULL, completed TINYINT(1) NOT NULL, session_id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', exercise_id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('CREATE INDEX IDX_BD39C963613FECDF ON workout_set_logs (session_id)');
        $this->addSql('CREATE INDEX IDX_BD39C963E934951A ON workout_set_logs (exercise_id)');
        $this->addSql('ALTER TABLE workout_sessions ADD CONSTRAINT FK_421170A5A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workout_sessions ADD CONSTRAINT FK_421170A5F27A94C7 FOREIGN KEY (routine_id) REFERENCES routines (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE workout_set_logs ADD CONSTRAINT FK_BD39C963613FECDF FOREIGN KEY (session_id) REFERENCES workout_sessions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workout_set_logs ADD CONSTRAINT FK_BD39C963E934951A FOREIGN KEY (exercise_id) REFERENCES exercises (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE workout_sessions DROP FOREIGN KEY FK_421170A5A76ED395');
        $this->addSql('ALTER TABLE workout_sessions DROP FOREIGN KEY FK_421170A5F27A94C7');
        $this->addSql('ALTER TABLE workout_set_logs DROP FOREIGN KEY FK_BD39C963613FECDF');
        $this->addSql('ALTER TABLE workout_set_logs DROP FOREIGN KEY FK_BD39C963E934951A');
        $this->addSql('DROP TABLE workout_set_logs');
        $this->addSql('DROP TABLE workout_sessions');
    }
}
