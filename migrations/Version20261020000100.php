<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional unique exercise source identity and original source name without changing catalog records';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('exercises');
        $table->addColumn('source_id', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('source_name', 'string', ['length' => 255, 'notnull' => false]);
        $table->addUniqueIndex(['source_id'], 'uniq_exercises_source_id');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('exercises');
        $table->dropIndex('uniq_exercises_source_id');
        $table->dropColumn('source_id');
        $table->dropColumn('source_name');
    }
}
