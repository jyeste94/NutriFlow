<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260304081609 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql("CREATE TABLE foods (id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', external_id VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, brand VARCHAR(255) DEFAULT NULL, best_serving_id CHAR(36) DEFAULT NULL COMMENT '(DC2Type:uuid)', last_fetched_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('CREATE UNIQUE INDEX uniq_external_id ON foods (external_id)');
        $this->addSql("CREATE TABLE servings (id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', description VARCHAR(255) NOT NULL, amount NUMERIC(10, 2) DEFAULT NULL, unit VARCHAR(50) DEFAULT NULL, calories NUMERIC(10, 2) NOT NULL, proteins NUMERIC(10, 2) DEFAULT NULL, carbs NUMERIC(10, 2) DEFAULT NULL, fats NUMERIC(10, 2) DEFAULT NULL, food_id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('CREATE INDEX IDX_49DC10B8BA8E87C4 ON servings (food_id)');
        $this->addSql('ALTER TABLE servings ADD CONSTRAINT FK_49DC10B8BA8E87C4 FOREIGN KEY (food_id) REFERENCES foods (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE servings DROP FOREIGN KEY FK_49DC10B8BA8E87C4');
        $this->addSql('DROP TABLE servings');
        $this->addSql('DROP TABLE foods');
    }
}
