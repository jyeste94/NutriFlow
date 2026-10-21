<?php

namespace App\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261021000200;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2) . '/migrations/Version20261021000200.php';

final class HevyExerciseMetadataMigrationTest extends TestCase
{
    public function testMigrationPreservesCatalogIdentitiesFieldsAndReferencesInBothDirections(): void
    {
        [$connection, $schema] = $this->existingCatalog();
        try {
            $before = $connection->fetchAllAssociative('SELECT * FROM exercises ORDER BY id');
            $this->migrate($connection, $schema, 'up');

            self::assertSame($before, $connection->fetchAllAssociative('SELECT id, name, source_id, source_name, tracking_type FROM exercises ORDER BY id'));
            self::assertSame([
                ['hevy_id' => null, 'name_en' => null, 'name_es' => null],
                ['hevy_id' => null, 'name_en' => null, 'name_es' => null],
            ], $connection->fetchAllAssociative('SELECT hevy_id, name_en, name_es FROM exercises ORDER BY id'));
            self::assertSame('existing-exercise', $connection->fetchOne('SELECT exercise_id FROM routine_exercises'));
            self::assertSame('existing-exercise', $connection->fetchOne('SELECT exercise_id FROM workout_set_logs'));

            $this->migrate($connection, $connection->createSchemaManager()->introspectSchema(), 'down');
            self::assertSame($before, $connection->fetchAllAssociative('SELECT * FROM exercises ORDER BY id'));
            self::assertSame('existing-exercise', $connection->fetchOne('SELECT exercise_id FROM routine_exercises'));
            self::assertSame('existing-exercise', $connection->fetchOne('SELECT exercise_id FROM workout_set_logs'));
        } finally {
            $connection->close();
        }
    }

    public function testMigrationAllowsUnknownHevyIdentitiesButRejectsDuplicateKnownIdentities(): void
    {
        [$connection, $schema] = $this->existingCatalog();
        try {
            $this->migrate($connection, $schema, 'up');
            $connection->executeStatement('UPDATE exercises SET hevy_id = ? WHERE id = ?', ['HEVY-1', 'existing-exercise']);
            $this->expectException(UniqueConstraintViolationException::class);
            $connection->executeStatement('UPDATE exercises SET hevy_id = ? WHERE id = ?', ['HEVY-1', 'other-exercise']);
        } finally {
            $connection->close();
        }
    }

    /** @return array{Connection, Schema} */
    private function existingCatalog(): array
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $schema = new Schema();
        $exercises = $schema->createTable('exercises');
        $exercises->addColumn('id', 'string', ['length' => 36]);
        $exercises->addColumn('name', 'string', ['length' => 255]);
        $exercises->addColumn('source_id', 'string', ['length' => 255, 'notnull' => false]);
        $exercises->addColumn('source_name', 'string', ['length' => 255, 'notnull' => false]);
        $exercises->addColumn('tracking_type', 'string', ['length' => 50, 'notnull' => false]);
        $exercises->setPrimaryKey(['id']);
        $exercises->addUniqueIndex(['source_id'], 'uniq_exercises_source_id');
        foreach (['routine_exercises', 'workout_set_logs'] as $name) {
            $references = $schema->createTable($name);
            $references->addColumn('id', 'integer');
            $references->addColumn('exercise_id', 'string', ['length' => 36]);
            $references->setPrimaryKey(['id']);
            $references->addForeignKeyConstraint('exercises', ['exercise_id'], ['id']);
        }
        foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
            $connection->executeStatement($sql);
        }
        $connection->insert('exercises', [
            'id' => 'existing-exercise', 'name' => 'Mi etiqueta personalizada',
            'source_id' => 'free-exercise-db:Example', 'source_name' => 'Original English name',
            'tracking_type' => 'DURATION',
        ]);
        $connection->insert('exercises', [
            'id' => 'other-exercise', 'name' => 'Otro ejercicio',
            'source_id' => null, 'source_name' => null, 'tracking_type' => null,
        ]);
        foreach (['routine_exercises', 'workout_set_logs'] as $name) {
            $connection->insert($name, ['id' => 1, 'exercise_id' => 'existing-exercise']);
        }

        return [$connection, $schema];
    }

    private function migrate(Connection $connection, Schema $schema, string $direction): void
    {
        $migration = new Version20261021000200($connection, new NullLogger());
        $migration->$direction($schema);
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
