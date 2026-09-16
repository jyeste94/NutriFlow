<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260916140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add image_url to exercises and populate CDN image URLs for exercise catalog';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exercises ADD image_url VARCHAR(500) DEFAULT NULL');

        // Copy existing gif_url to image_url where gif_url ends with image format
        $this->addSql("UPDATE exercises SET image_url = gif_url WHERE gif_url IS NOT NULL AND (gif_url LIKE '%.jpg' OR gif_url LIKE '%.png' OR gif_url LIKE '%.jpeg' OR gif_url LIKE '%.webp')");

        $imageMappings = [
            'Press banca plano' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Barbell_Bench_Press_-_Medium_Grip/0.jpg',
            'Press inclinado con mancuernas' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Dumbbell_Incline_Bench_Press/0.jpg',
            'Aperturas en polea' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Cable_Crossover/0.jpg',
            'Fondos en paralelas' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Parallel_Bar_Dips/0.jpg',
            'Flexiones' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Push-Up/0.jpg',
            'Press militar con barra' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Barbell_Shoulder_Press/0.jpg',
            'Press Arnold' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Arnold_Press/0.jpg',
            'Elevaciones laterales' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Dumbbell_Lateral_Raise/0.jpg',
            'Elevaciones frontales' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Front_Dumbbell_Raise/0.jpg',
            'Pájaros con mancuernas' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Dumbbell_Reverse_Fly/0.jpg',
            'Peso muerto convencional' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Barbell_Deadlift/0.jpg',
            'Peso muerto rumano' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Barbell_Romanian_Deadlift/0.jpg',
            'Dominadas' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Pull-Up/0.jpg',
            'Remo con barra' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Barbell_Bent_Over_Row/0.jpg',
            'Remo con mancuerna a una mano' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Dumbbell_One_Arm_Row/0.jpg',
            'Jalón al pecho' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Lat_Pulldown/0.jpg',
            'Curl de bíceps con barra' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Barbell_Bicep_Curl/0.jpg',
            'Curl martillo' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Dumbbell_Hammer_Curl/0.jpg',
            'Curl inclinado' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Incline_Dumbbell_Curl/0.jpg',
            'Curl concentrado' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Concentration_Curl/0.jpg',
            'Extensión de tríceps en polea' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Cable_Triceps_Pushdown/0.jpg',
            'Press francés' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Lying_Close-Grip_Barbell_Triceps_Extension_Behind_The_Head/0.jpg',
            'Fondos en banco' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Bench_Dips/0.jpg',
            'Extensión de tríceps tras nuca' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Standing_Dumbbell_Triceps_Extension/0.jpg',
            'Sentadilla trasera' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Barbell_Squat/0.jpg',
            'Sentadilla frontal' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Barbell_Front_Squat/0.jpg',
            'Sentadilla búlgara' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Single_Leg_Split_Squat/0.jpg',
            'Sentadilla libre' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Bodyweight_Squat/0.jpg',
            'Sentadilla con peso corporal (Air Squat)' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Bodyweight_Squat/0.jpg',
            'Prensa de piernas' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Leg_Press/0.jpg',
            'Zancadas' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Barbell_Lunge/0.jpg',
            'Curl femoral tumbado' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Lying_Leg_Curls/0.jpg',
            'Curl femoral sentado' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Seated_Leg_Curl/0.jpg',
            'Hip thrust' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Barbell_Hip_Thrust/0.jpg',
            'Puente de glúteo' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Glute_Bridge/0.jpg',
            'Elevación de gemelos de pie' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Standing_Calf_Raises/0.jpg',
            'Elevación de gemelos sentado' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Seated_Calf_Raise/0.jpg',
            'Plancha' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Plank/0.jpg',
            'Plancha con peso' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Plank/0.jpg',
            'Ab wheel' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Ab_Roller/0.jpg',
            'Elevación de piernas colgado' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Hanging_Leg_Raise/0.jpg',
            'Russian twist' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Russian_Twist/0.jpg',
            'Encogimientos' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Crunches/0.jpg',
            'Burpees' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Burpee/0.jpg',
            'Saltos de tijera' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Jumping_Jacks/0.jpg',
            'Cuerda a saltar' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Rope_Jumping/0.jpg',
            'Remo en máquina' => 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main/exercises/Seated_Cable_Rows/0.jpg',
        ];

        foreach ($imageMappings as $name => $url) {
            $this->addSql('UPDATE exercises SET image_url = ? WHERE name = ?', [$url, $name]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exercises DROP COLUMN image_url');
    }
}
