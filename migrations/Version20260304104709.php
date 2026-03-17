<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260304104709 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql("CREATE TABLE exercises (id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', name VARCHAR(255) NOT NULL, muscle_group VARCHAR(100) NOT NULL, equipment VARCHAR(100) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE routine_exercises (id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', sets INT NOT NULL, reps INT NOT NULL, rest_seconds INT NOT NULL, order_index INT NOT NULL, routine_id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', exercise_id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('CREATE INDEX IDX_C0E5C8EDF27A94C7 ON routine_exercises (routine_id)');
        $this->addSql('CREATE INDEX IDX_C0E5C8EDE934951A ON routine_exercises (exercise_id)');
        $this->addSql("CREATE TABLE routines (id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', name VARCHAR(255) NOT NULL, days_of_week JSON DEFAULT NULL, user_id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('CREATE INDEX IDX_74F54E32A76ED395 ON routines (user_id)');
        $this->addSql('ALTER TABLE routine_exercises ADD CONSTRAINT FK_C0E5C8EDF27A94C7 FOREIGN KEY (routine_id) REFERENCES routines (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE routine_exercises ADD CONSTRAINT FK_C0E5C8EDE934951A FOREIGN KEY (exercise_id) REFERENCES exercises (id)');
        $this->addSql('ALTER TABLE routines ADD CONSTRAINT FK_74F54E32A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE routine_exercises DROP FOREIGN KEY FK_C0E5C8EDF27A94C7');
        $this->addSql('ALTER TABLE routine_exercises DROP FOREIGN KEY FK_C0E5C8EDE934951A');
        $this->addSql('ALTER TABLE routines DROP FOREIGN KEY FK_74F54E32A76ED395');
        $this->addSql('DROP TABLE routine_exercises');
        $this->addSql('DROP TABLE routines');
        $this->addSql('DROP TABLE exercises');
    }
}
