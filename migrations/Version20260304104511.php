<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260304104511 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql("CREATE TABLE meal_diaries (id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', date DATE NOT NULL COMMENT '(DC2Type:date_immutable)', total_calories NUMERIC(10, 2) NOT NULL, total_proteins NUMERIC(10, 2) NOT NULL, total_carbs NUMERIC(10, 2) NOT NULL, total_fats NUMERIC(10, 2) NOT NULL, user_id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('CREATE INDEX IDX_47392222A76ED395 ON meal_diaries (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_date ON meal_diaries (user_id, date)');
        $this->addSql("CREATE TABLE meal_entries (id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', meal_type VARCHAR(50) NOT NULL, multiplier DOUBLE NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', diary_id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', serving_id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('CREATE INDEX IDX_544B01DCE020E47A ON meal_entries (diary_id)');
        $this->addSql('CREATE INDEX IDX_544B01DCBFC5A5DC ON meal_entries (serving_id)');
        $this->addSql('ALTER TABLE meal_diaries ADD CONSTRAINT FK_47392222A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE meal_entries ADD CONSTRAINT FK_544B01DCE020E47A FOREIGN KEY (diary_id) REFERENCES meal_diaries (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE meal_entries ADD CONSTRAINT FK_544B01DCBFC5A5DC FOREIGN KEY (serving_id) REFERENCES servings (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE meal_diaries DROP FOREIGN KEY FK_47392222A76ED395');
        $this->addSql('ALTER TABLE meal_entries DROP FOREIGN KEY FK_544B01DCE020E47A');
        $this->addSql('ALTER TABLE meal_entries DROP FOREIGN KEY FK_544B01DCBFC5A5DC');
        $this->addSql('DROP TABLE meal_entries');
        $this->addSql('DROP TABLE meal_diaries');
    }
}
