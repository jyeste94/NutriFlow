<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create diet_plans, diet_plan_days, diet_plan_meals tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE diet_plans (id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, is_default TINYINT(1) DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', user_id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('CREATE INDEX IDX_DIET_PLANS_USER ON diet_plans (user_id)');
        $this->addSql('ALTER TABLE diet_plans ADD CONSTRAINT FK_DIET_PLANS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql("CREATE TABLE diet_plan_days (id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', day_of_week VARCHAR(20) NOT NULL, sort_order INT NOT NULL, plan_id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('CREATE INDEX IDX_DIET_PLAN_DAYS_PLAN ON diet_plan_days (plan_id)');
        $this->addSql('ALTER TABLE diet_plan_days ADD CONSTRAINT FK_DIET_PLAN_DAYS_PLAN FOREIGN KEY (plan_id) REFERENCES diet_plans (id) ON DELETE CASCADE');

        $this->addSql("CREATE TABLE diet_plan_meals (id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', multiplier DOUBLE NOT NULL, meal_type VARCHAR(50) NOT NULL, sort_order INT NOT NULL, day_id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', serving_id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('CREATE INDEX IDX_DIET_PLAN_MEALS_DAY ON diet_plan_meals (day_id)');
        $this->addSql('CREATE INDEX IDX_DIET_PLAN_MEALS_SERVING ON diet_plan_meals (serving_id)');
        $this->addSql('ALTER TABLE diet_plan_meals ADD CONSTRAINT FK_DIET_PLAN_MEALS_DAY FOREIGN KEY (day_id) REFERENCES diet_plan_days (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE diet_plan_meals ADD CONSTRAINT FK_DIET_PLAN_MEALS_SERVING FOREIGN KEY (serving_id) REFERENCES servings (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE diet_plan_meals DROP FOREIGN KEY FK_DIET_PLAN_MEALS_DAY');
        $this->addSql('ALTER TABLE diet_plan_meals DROP FOREIGN KEY FK_DIET_PLAN_MEALS_SERVING');
        $this->addSql('ALTER TABLE diet_plan_days DROP FOREIGN KEY FK_DIET_PLAN_DAYS_PLAN');
        $this->addSql('ALTER TABLE diet_plans DROP FOREIGN KEY FK_DIET_PLANS_USER');
        $this->addSql('DROP TABLE diet_plan_meals');
        $this->addSql('DROP TABLE diet_plan_days');
        $this->addSql('DROP TABLE diet_plans');
    }
}
