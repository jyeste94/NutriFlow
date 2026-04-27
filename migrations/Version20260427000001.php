<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create measurements table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE measurements (id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', date DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', weight_kg DOUBLE NOT NULL, body_fat_pct DOUBLE DEFAULT NULL, chest_cm DOUBLE DEFAULT NULL, waist_cm DOUBLE DEFAULT NULL, hips_cm DOUBLE DEFAULT NULL, arm_cm DOUBLE DEFAULT NULL, thigh_cm DOUBLE DEFAULT NULL, calf_cm DOUBLE DEFAULT NULL, notes TEXT DEFAULT NULL, user_id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('CREATE INDEX IDX_MEASUREMENTS_USER ON measurements (user_id)');
        $this->addSql('ALTER TABLE measurements ADD CONSTRAINT FK_MEASUREMENTS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE measurements DROP FOREIGN KEY FK_MEASUREMENTS_USER');
        $this->addSql('DROP TABLE measurements');
    }
}
