<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260304112051 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE exercises ADD description TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE exercises ADD gif_url VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE exercises ADD video_url VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE exercises DROP COLUMN description');
        $this->addSql('ALTER TABLE exercises DROP COLUMN gif_url');
        $this->addSql('ALTER TABLE exercises DROP COLUMN video_url');
    }
}
