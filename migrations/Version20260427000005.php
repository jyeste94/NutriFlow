<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed exercises catalog';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->executeQuery('SELECT COUNT(*) FROM exercises')->fetchOne() > 0) { return; }

        $exercises = [
            ['Press banca plano', 'Pecho', 'Barra Larga', 'Ejercicio compuesto para pectoral mayor. Acostado en banco plano, baja la barra al pecho y empuja hacia arriba.', 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Barbell_Bench_Press_-_Medium_Grip/1.jpg', 'https://www.youtube.com/embed/rT7DgCr-3pg'],
            ['Press inclinado con mancuernas', 'Pecho', 'Mancuernas', 'Variante inclinada que enfatiza la parte superior del pecho. Banco a 30-45 grados.', 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Dumbbell_Incline_Bench_Press/1.jpg', 'https://www.youtube.com/embed/8iP3nC0J_NQ'],
            ['Aperturas en polea', 'Pecho', 'Polea', 'Ejercicio de aislamiento para pectoral. De pie entre dos poleas, junta las manos al frente.', 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Cable_Crossover/1.jpg', NULL],
            ['Fondos en paralelas', 'Pecho', 'Ninguno', 'Ejercicio compuesto para pectoral inferior y tríceps. Cuerpo erguido, baja hasta que los brazos formen 90 grados.', 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Parallel_Bar_Dips/1.jpg', 'https://www.youtube.com/embed/2z8gDq7s1fQ'],
            ['Flexiones', 'Pecho', 'Ninguno', 'Ejercicio clásico de peso corporal. Mantén el cuerpo en línea recta, baja el pecho al suelo.', 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Push-Up/1.jpg', 'https://www.youtube.com/embed/IODxDxX7oi4'],
            ['Press militar con barra', 'Hombro', 'Barra Larga', 'Ejercicio compuesto para hombros. De pie, barra al frente, empuja verticalmente.', 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Barbell_Shoulder_Press/1.jpg', 'https://www.youtube.com/embed/2yjwXTZQDDI'],
            ['Press Arnold', 'Hombro', 'Mancuernas', 'Variante con rotación. Mancuernas al frente con palmas hacia ti, al subir rotar las palmas al frente.', NULL, 'https://www.youtube.com/embed/6Z15_WdXmVw'],
            ['Elevaciones laterales', 'Hombro', 'Mancuernas', 'Aislamiento para deltoides lateral. De pie, brazos a los lados, eleva las mancuernas hasta la altura de los hombros.', 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Dumbbell_Lateral_Raise/1.jpg', 'https://www.youtube.com/embed/3VcKaXpzqRo'],
            ['Elevaciones frontales', 'Hombro', 'Mancuernas', 'Aislamiento para deltoides frontal. Eleva las mancuernas al frente hasta la altura de los hombros.', NULL, 'https://www.youtube.com/embed/0G2_XVg1eAM'],
            ['Pájaros con mancuernas', 'Hombro', 'Mancuernas', 'Aislamiento para deltoides posterior. Torso inclinado, brazos caídos, eleva hacia los lados.', 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Dumbbell_Reverse_Fly/1.jpg', 'https://www.youtube.com/embed/5zTgHcJkXtM'],
            ['Peso muerto convencional', 'Espalda', 'Barra Larga', 'Ejercicio compuesto para cadena posterior. Desde el suelo, levanta la barra extendiendo caderas y rodillas.', 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Barbell_Deadlift/1.jpg', 'https://www.youtube.com/embed/1ZXsu9Vg0-w'],
            ['Peso muerto rumano', 'Espalda', 'Barra Larga', 'Variante que enfatiza femoral y glúteo. Barra en manos, baja deslizando por las piernas, manteniendo piernas semi-extendidas.', 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Barbell_Romanian_Deadlift/1.jpg', 'https://www.youtube.com/embed/JCXUYuzwXMg'],
            ['Dominadas', 'Espalda', 'Ninguno', 'Ejercicio compuesto para espalda. Colgado de una barra, eleva el cuerpo hasta que la barbilla pase la barra.', 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Pull-Up/1.jpg', 'https://www.youtube.com/embed/eGo4IYlbE5g'],
            ['Remo con barra', 'Espalda', 'Barra Larga', 'Ejercicio compuesto para espalda media. Torso inclinado, barra colgando, lleva al abdomen.', 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Barbell_Bent_Over_Row/1.jpg', 'https://www.youtube.com/embed/9ef2Ajldz8c'],
            ['Remo con mancuerna a una mano', 'Espalda', 'Mancuernas', 'Remo unilateral. Apoya rodilla y mano en banco, rema con la mancuerna.', 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Dumbbell_One_Arm_Row/1.jpg', 'https://www.youtube.com/embed/-pOgtGJ28Bk'],
            ['Jalón al pecho', 'Espalda', 'Polea', 'Ejercicio de espalda en polea alta. Agarre ancho, lleva la barra al pecho.', 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Lat_Pulldown/1.jpg', 'https://www.youtube.com/embed/OEBtR2KQiOM'],
            ['Curl de bíceps con barra', 'Bíceps', 'Barra Z', 'Ejercicio básico de bíceps. De pie, barra en manos, curl hacia los hombros.', 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Barbell_Bicep_Curl/1.jpg', 'https://www.youtube.com/embed/ykJmrZ5v0Oo'],
            ['Curl martillo', 'Bíceps', 'Mancuernas', 'Variante que trabaja braquial y antebrazo. Mancuernas en posición neutra (palmas enfrentadas).', 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Dumbbell_Hammer_Curl/1.jpg', 'https://www.youtube.com/embed/zC3nLlEvin4'],
            ['Curl inclinado', 'Bíceps', 'Mancuernas', 'Variante con mayor rango de movimiento. Sentado en banco inclinado, brazos caídos, curl.', NULL, 'https://www.youtube.com/embed/soxrZlIl35U'],
            ['Curl concentrado', 'Bíceps', 'Mancuernas', 'Aislamiento máximo. Sentado, codo apoyado en el muslo interno, curl lento.', NULL, 'https://www.youtube.com/embed/jD4C-Lg-JR8'],
            ['Extensión de tríceps en polea', 'Tríceps', 'Polea', 'Aislamiento para tríceps. Cuerda en polea alta, codos fijos, extiende hacia abajo.', 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Cable_Triceps_Pushdown/1.jpg', 'https://www.youtube.com/embed/2-LAMRzTlpE'],
            ['Press francés', 'Tríceps', 'Barra Z', 'Acostado en banco, barra Z al frente, baja hacia la cabeza flexionando codos.', NULL, 'https://www.youtube.com/embed/_gs4Q3MKBkk'],
            ['Fondos en banco', 'Tríceps', 'Banco Plano', 'Tríceps con peso corporal. Manos en un banco, cuerpo al frente, baja y sube.', NULL, 'https://www.youtube.com/embed/0326dy4C9vA'],
            ['Extensión de tríceps tras nuca', 'Tríceps', 'Mancuernas', 'De pie o sentado, mancuerna tras la cabeza, extiende hacia arriba.', NULL, 'https://www.youtube.com/embed/wy-e8MhR0f0'],
            ['Sentadilla trasera', 'Cuádriceps', 'Barra Larga', 'Ejercicio rey para piernas. Barra en la espalda, baja hasta paralela, sube.', 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Barbell_Squat/1.jpg', 'https://www.youtube.com/embed/bEv6CCg2BCk'],
            ['Sentadilla frontal', 'Cuádriceps', 'Barra Larga', 'Variante con barra al frente. Más énfasis en cuádriceps, torso más erguido.', 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Barbell_Front_Squat/1.jpg', 'https://www.youtube.com/embed/m0G8QN4GQ1A'],
            ['Prensa de piernas', 'Cuádriceps', 'Máquina', 'Empuje en máquina de piernas. Ajusta el asiento y empuja la plataforma.', NULL, 'https://www.youtube.com/embed/IZxyjW7MPJQ'],
            ['Sentadilla búlgara', 'Cuádriceps', 'Mancuernas', 'Split squat con pierna trasera elevada en un banco. Excelente para unilateral.', NULL, 'https://www.youtube.com/embed/2C-uNgKwP0U'],
            ['Zancadas', 'Cuádriceps', 'Mancuernas', 'Paso al frente y flexiona ambas rodillas. Alterna piernas.', 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Barbell_Lunge/1.jpg', 'https://www.youtube.com/embed/QOVaHmdQy64'],
            ['Curl femoral tumbado', 'Isquios', 'Máquina', 'Aislamiento para femoral. Acostado boca abajo, flexiona las piernas hacia los glúteos.', NULL, 'https://www.youtube.com/embed/NL2cFPQxSEU'],
            ['Curl femoral sentado', 'Isquios', 'Máquina', 'Variante sentado que enfatiza los isquios en posición acortada.', NULL, NULL],
            ['Hip thrust', 'Glúteos', 'Barra Larga', 'Empuje de cadera con barra. Espalda apoyada en banco, barra en caderas, empuja hacia arriba.', 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Barbell_Hip_Thrust/1.jpg', 'https://www.youtube.com/embed/SEdqd1n0s2o'],
            ['Puente de glúteo', 'Glúteos', 'Ninguno', 'Acostado boca arriba, rodillas flexionadas, eleva la cadera.', NULL, NULL],
            ['Elevación de gemelos de pie', 'Gemelos', 'Máquina', 'De pie en la máquina de gemelos, eleva los talones al máximo.', NULL, 'https://www.youtube.com/embed/-M4-G8p8GcQ'],
            ['Elevación de gemelos sentado', 'Gemelos', 'Mancuernas', 'Sentado con mancuernas en las rodillas, eleva los talones.', NULL, NULL],
            ['Plancha', 'Abdomen', 'Ninguno', 'Ejercicio isométrico. Antebrazos en el suelo, cuerpo en línea recta, aguanta.', 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/Plank/1.jpg', 'https://www.youtube.com/embed/pSHjTRCQxIw'],
            ['Plancha con peso', 'Abdomen', 'Ninguno', 'Plancha con un disco en la espalda para mayor resistencia.', NULL, NULL],
            ['Ab wheel', 'Abdomen', 'Ninguno', 'Rueda abdominal. De rodillas, rueda al frente y vuelve.', NULL, 'https://www.youtube.com/embed/WB7dDTn1nQo'],
            ['Elevación de piernas colgado', 'Abdomen', 'Ninguno', 'Colgado de una barra, eleva las piernas rectas hasta paralela.', NULL, 'https://www.youtube.com/embed/HD0vFvB2qC4'],
            ['Russian twist', 'Abdomen', 'Ninguno', 'Sentado con pies elevados, rota el torso a los lados con un disco.', NULL, 'https://www.youtube.com/embed/wkD8rjkodUI'],
            ['Encogimientos', 'Abdomen', 'Ninguno', 'Clásico crunch. Acostado boca arriba, eleva los hombros contrayendo el abdomen.', NULL, NULL],
            ['Burpees', 'Cardio', 'Ninguno', 'Ejercicio full-body. Flexión -> salto vertical -> repite.', NULL, 'https://www.youtube.com/embed/JZQA08SlJnM'],
            ['Saltos de tijera', 'Cardio', 'Ninguno', 'Jumping jacks. Abre y cierra piernas mientras subes y bajas brazos.', NULL, NULL],
            ['Cuerda a saltar', 'Cardio', 'Ninguno', 'Saltos continuos con cuerda. Mantén un ritmo constante.', NULL, 'https://www.youtube.com/embed/FJZJq5s7FcA'],
            ['Remo en máquina', 'Cardio', 'Máquina', 'Máquina de remo. Empuja con piernas, tira con brazos, desliza hacia atrás.', NULL, 'https://www.youtube.com/embed/0R0uiGjFR8M'],
            ['Paseo del granjero', 'Antebrazo', 'Mancuernas', 'Camina sujetando mancuernas pesadas a los lados. Mejora agarre y antebrazos.', NULL, 'https://www.youtube.com/embed/2G4kO5ZFx6E'],
            ['Curl de muñeca', 'Antebrazo', 'Mancuernas', 'Antebrazos apoyados en un banco, flexiona la muñeca hacia arriba con la mancuerna.', NULL, NULL],
        ];

        foreach ($exercises as $ex) {
            $this->addSql("INSERT INTO exercises (id, name, muscle_group, equipment, description, gif_url, video_url) VALUES (UUID(), ?, ?, ?, ?, ?, ?)", $ex);
        }
    }

    public function down(Schema $schema): void
    {
        $names = ['Press banca plano','Press inclinado con mancuernas','Aperturas en polea','Fondos en paralelas','Flexiones','Press militar con barra','Press Arnold','Elevaciones laterales','Elevaciones frontales','Pájaros con mancuernas','Peso muerto convencional','Peso muerto rumano','Dominadas','Remo con barra','Remo con mancuerna a una mano','Jalón al pecho','Curl de bíceps con barra','Curl martillo','Curl inclinado','Curl concentrado','Extensión de tríceps en polea','Press francés','Fondos en banco','Extensión de tríceps tras nuca','Sentadilla trasera','Sentadilla frontal','Prensa de piernas','Sentadilla búlgara','Zancadas','Curl femoral tumbado','Curl femoral sentado','Hip thrust','Puente de glúteo','Elevación de gemelos de pie','Elevación de gemelos sentado','Plancha','Plancha con peso','Ab wheel','Elevación de piernas colgado','Russian twist','Encogimientos','Burpees','Saltos de tijera','Cuerda a saltar','Remo en máquina','Paseo del granjero','Curl de muñeca'];
        foreach ($names as $name) {
            $this->addSql('DELETE FROM exercises WHERE name = ?', [$name]);
        }
    }
}
