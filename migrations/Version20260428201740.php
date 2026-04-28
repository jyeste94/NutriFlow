<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428201740 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align MySQL schema with current Doctrine mapping after uuid-char and entity mapping normalization';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on mysql.'
        );

        $this->addSql('ALTER TABLE diet_plans CHANGE id id CHAR(36) NOT NULL, CHANGE description description LONGTEXT DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL, CHANGE user_id user_id CHAR(36) NOT NULL, CHANGE supplement_protocol supplement_protocol LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE diet_plan_days CHANGE id id CHAR(36) NOT NULL, CHANGE plan_id plan_id CHAR(36) NOT NULL');
        $this->addSql('ALTER TABLE diet_plan_meals CHANGE id id CHAR(36) NOT NULL, CHANGE day_id day_id CHAR(36) NOT NULL, CHANGE serving_id serving_id CHAR(36) NOT NULL, CHANGE option_group option_group VARCHAR(10) DEFAULT NULL, CHANGE notes notes LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE error_logs CHANGE id id CHAR(36) NOT NULL, CHANGE message message LONGTEXT NOT NULL, CHANGE stack_trace stack_trace LONGTEXT DEFAULT NULL, CHANGE context context JSON DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE exercises CHANGE id id CHAR(36) NOT NULL, CHANGE equipment equipment VARCHAR(100) DEFAULT NULL, CHANGE description description LONGTEXT DEFAULT NULL, CHANGE gif_url gif_url VARCHAR(500) DEFAULT NULL, CHANGE video_url video_url VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE foods CHANGE id id CHAR(36) NOT NULL, CHANGE brand brand VARCHAR(255) DEFAULT NULL, CHANGE best_serving_id best_serving_id CHAR(36) DEFAULT NULL, CHANGE last_fetched_at last_fetched_at DATETIME NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE meal_diaries CHANGE id id CHAR(36) NOT NULL, CHANGE date date DATE NOT NULL, CHANGE user_id user_id CHAR(36) NOT NULL');
        $this->addSql('ALTER TABLE meal_entries CHANGE id id CHAR(36) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE diary_id diary_id CHAR(36) NOT NULL, CHANGE serving_id serving_id CHAR(36) NOT NULL');
        $this->addSql('ALTER TABLE measurements CHANGE id id CHAR(36) NOT NULL, CHANGE date date DATETIME NOT NULL, CHANGE body_fat_pct body_fat_pct DOUBLE PRECISION DEFAULT NULL, CHANGE chest_cm chest_cm DOUBLE PRECISION DEFAULT NULL, CHANGE waist_cm waist_cm DOUBLE PRECISION DEFAULT NULL, CHANGE hips_cm hips_cm DOUBLE PRECISION DEFAULT NULL, CHANGE arm_cm arm_cm DOUBLE PRECISION DEFAULT NULL, CHANGE thigh_cm thigh_cm DOUBLE PRECISION DEFAULT NULL, CHANGE calf_cm calf_cm DOUBLE PRECISION DEFAULT NULL, CHANGE notes notes LONGTEXT DEFAULT NULL, CHANGE user_id user_id CHAR(36) NOT NULL');
        $this->addSql('ALTER TABLE routines CHANGE id id CHAR(36) NOT NULL, CHANGE days_of_week days_of_week JSON DEFAULT NULL, CHANGE user_id user_id CHAR(36) NOT NULL');
        $this->addSql('ALTER TABLE routine_exercises CHANGE id id CHAR(36) NOT NULL, CHANGE routine_id routine_id CHAR(36) NOT NULL, CHANGE exercise_id exercise_id CHAR(36) NOT NULL');
        $this->addSql('ALTER TABLE servings CHANGE id id CHAR(36) NOT NULL, CHANGE amount amount NUMERIC(10, 2) DEFAULT NULL, CHANGE unit unit VARCHAR(50) DEFAULT NULL, CHANGE proteins proteins NUMERIC(10, 2) DEFAULT NULL, CHANGE carbs carbs NUMERIC(10, 2) DEFAULT NULL, CHANGE fats fats NUMERIC(10, 2) DEFAULT NULL, CHANGE food_id food_id CHAR(36) NOT NULL');
        $this->addSql('ALTER TABLE users CHANGE id id CHAR(36) NOT NULL, CHANGE email email VARCHAR(255) DEFAULT NULL, CHANGE roles roles JSON NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE workout_sessions CHANGE id id CHAR(36) NOT NULL, CHANGE date date DATETIME NOT NULL, CHANGE user_id user_id CHAR(36) NOT NULL, CHANGE routine_id routine_id CHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE workout_set_logs CHANGE id id CHAR(36) NOT NULL, CHANGE session_id session_id CHAR(36) NOT NULL, CHANGE exercise_id exercise_id CHAR(36) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('This alignment migration is intentionally one-way.');
    }
}
