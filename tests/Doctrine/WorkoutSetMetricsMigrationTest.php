<?php

namespace App\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use DoctrineMigrations\Version20261023000100;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2) . '/migrations/Version20261023000100.php';

final class WorkoutSetMetricsMigrationTest extends TestCase
{
    public static function existingMetricColumns(): array
    {
        return [
            'neither metric was migrated' => [false, false],
            'duration was repaired manually' => [true, false],
            'distance was repaired manually' => [false, true],
            'both metrics already exist' => [true, true],
        ];
    }

    #[DataProvider('existingMetricColumns')]
    public function testRepairPreservesSetsReferencesAndExistingMetrics(bool $durationExists, bool $distanceExists): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $connection->executeStatement('PRAGMA foreign_keys = ON');
            $schema = new Schema();
            foreach (['workout_sessions', 'exercises'] as $name) {
                $table = $schema->createTable($name);
                $table->addColumn('id', 'string', ['length' => 36]);
                $table->setPrimaryKey(['id']);
            }
            $sets = $schema->createTable('workout_set_logs');
            foreach (['id', 'session_id', 'exercise_id', 'client_set_id'] as $name) {
                $sets->addColumn($name, 'string', ['length' => 100]);
            }
            $sets->addColumn('weight', 'float');
            $sets->addColumn('reps', 'integer');
            $sets->addColumn('completed', 'boolean');
            $sets->addColumn('set_number', 'integer');
            $sets->addColumn('exercise_order', 'integer');
            $sets->setPrimaryKey(['id']);
            $sets->addForeignKeyConstraint('workout_sessions', ['session_id'], ['id']);
            $sets->addForeignKeyConstraint('exercises', ['exercise_id'], ['id']);
            if ($durationExists) $sets->addColumn('duration_seconds', 'integer', ['notnull' => false]);
            if ($distanceExists) $sets->addColumn('distance_km', 'float', ['notnull' => false]);
            foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) $connection->executeStatement($sql);
            $connection->insert('workout_sessions', ['id' => 'existing-session']);
            $connection->insert('exercises', ['id' => 'existing-exercise']);
            $row = ['id' => 'existing-set', 'session_id' => 'existing-session', 'exercise_id' => 'existing-exercise',
                'client_set_id' => 'pending-client-identity', 'weight' => 10.0, 'reps' => 10, 'completed' => true,
                'set_number' => 1, 'exercise_order' => 2];
            if ($durationExists) $row['duration_seconds'] = 125;
            if ($distanceExists) $row['distance_km'] = 1.75;
            $connection->insert('workout_set_logs', $row);
            $before = $connection->fetchAssociative('SELECT * FROM workout_set_logs');

            $this->repair($connection);
            $after = $connection->fetchAssociative('SELECT * FROM workout_set_logs');
            self::assertSame($before, array_intersect_key($after, $before));
            self::assertSame($durationExists ? 125 : null, $after['duration_seconds']);
            self::assertSame($distanceExists ? 1.75 : null, $after['distance_km']);
            self::assertSame([], $connection->fetchAllAssociative('PRAGMA foreign_key_check'));
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM workout_set_logs'));

            $this->repair($connection);
            self::assertSame($after, $connection->fetchAssociative('SELECT * FROM workout_set_logs'));
        } finally {
            $connection->close();
        }
    }

    public function testRollbackCannotEraseRecordedOrPreviouslyExistingMetrics(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $migration = new Version20261023000100($connection, new NullLogger());
            $this->expectException(IrreversibleMigration::class);
            $migration->down(new Schema());
        } finally {
            $connection->close();
        }
    }

    private function repair(Connection $connection): void
    {
        $migration = new Version20261023000100($connection, new NullLogger());
        $migration->up($connection->createSchemaManager()->introspectSchema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
