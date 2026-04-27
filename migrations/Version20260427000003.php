<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add option_group, notes to diet_plan_meals; supplement_protocol to diet_plans';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE diet_plan_meals ADD option_group VARCHAR(10) DEFAULT NULL, ADD notes TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE diet_plans ADD supplement_protocol TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE diet_plan_meals DROP option_group, DROP notes');
        $this->addSql('ALTER TABLE diet_plans DROP supplement_protocol');
    }
}
