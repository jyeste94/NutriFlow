<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add set_type to workout_set_logs and seed warmup/cardio exercises';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE workout_set_logs ADD set_type VARCHAR(20) NOT NULL DEFAULT 'normal'");

        $warmupExercises = [
            ['Bicicleta estática', 'Cardio', 'Máquina', 'Pedaleo suave o moderado en bicicleta estática para calentamiento cardiovascular o acondicionamiento.'],
            ['Elíptica', 'Cardio', 'Máquina', 'Calentamiento cardiovascular de bajo impacto articular que involucra tren superior e inferior.'],
            ['Cinta de correr', 'Cardio', 'Máquina', 'Caminata con inclinación o trote suave para elevación de temperatura corporal previa al entrenamiento.'],
            ['Movilidad articular y dinámica', 'Calentamiento', 'Ninguno', 'Rotaciones articulares, apertura de cadera y dorsiflexión de tobillo para preparar las articulaciones.'],
            ['Sentadilla con peso corporal (Air Squat)', 'Cuádriceps', 'Ninguno', 'Sentadilla libre sin carga adicional para activar glúteos, cuádriceps y verificar el rango de movimiento.'],
        ];

        foreach ($warmupExercises as $ex) {
            $this->addSql(
                "INSERT INTO exercises (id, name, muscle_group, equipment, description) VALUES (UUID(), ?, ?, ?, ?)",
                $ex
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workout_set_logs DROP COLUMN set_type');
        $this->addSql("DELETE FROM exercises WHERE name IN ('Bicicleta estática', 'Elíptica', 'Cinta de correr', 'Movilidad articular y dinámica', 'Sentadilla con peso corporal (Air Squat)')");
    }
}
