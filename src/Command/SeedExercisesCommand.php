<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:seed-exercises')]
class SeedExercisesCommand extends Command
{
    private const BASE_IMAGE_URL = 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/';

    public function __construct(
        private EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Seed exercises in Spanish')
            ->addOption('force', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Delete existing and re-seed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Sembrando ejercicios en español...');

        $repo = $this->em->getRepository(\App\Entity\Exercise::class);
        $existing = (int) $repo->createQueryBuilder('e')->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();

        if ($existing > 0) {
            if (!$input->getOption('force')) {
                $io->warning("Ya existen $existing ejercicios. Usa --force para borrar y volver a sembrar.");
                return Command::SUCCESS;
            }
            $io->note("Borrando $existing ejercicios existentes...");
            $repo->createQueryBuilder('e')->delete()->getQuery()->execute();
        }

        $conn = $this->em->getConnection();
        $added = 0;

        $exercises = [
            // ===== PECHO =====
            ['Press banca plano', 'Pecho', 'Barra Larga', 'Acostado en banco plano, baja la barra al pecho y empuja hacia arriba. Compuesto para pectoral mayor.', 'Barbell_Bench_Press_-_Medium_Grip/0.jpg'],
            ['Press banca inclinado', 'Pecho', 'Barra Larga', 'En banco inclinado a 30-45°. Enfatiza la parte superior del pecho.', 'Barbell_Incline_Bench_Press/0.jpg'],
            ['Press inclinado con mancuernas', 'Pecho', 'Mancuernas', 'Mancuernas en banco inclinado. Mayor rango de movimiento que con barra.', 'Dumbbell_Incline_Bench_Press/0.jpg'],
            ['Press declinado con mancuernas', 'Pecho', 'Mancuernas', 'En banco declinado. Enfatiza la parte inferior del pecho.', 'Dumbbell_Decline_Bench_Press/0.jpg'],
            ['Aperturas con mancuernas', 'Pecho', 'Mancuernas', 'Acostado en banco plano, brazos extendidos, abre y cierra.', 'Dumbbell_Flyes/0.jpg'],
            ['Aperturas en polea', 'Pecho', 'Polea', 'De pie entre dos poleas, junta las manos al frente. Aislamiento pectoral.', 'Cable_Crossover/0.jpg'],
            ['Pull-over con mancuerna', 'Pecho', 'Mancuernas', 'Acostado en banco, mancuerna sobre la cabeza, baja detrás y vuelve.', 'Dumbbell_Pullover/0.jpg'],
            ['Fondos en paralelas', 'Pecho', 'Ninguno', 'Cuerpo erguido, baja hasta que los brazos formen 90°. Pectoral inferior y tríceps.', 'Parallel_Bar_Dips/0.jpg'],
            ['Flexiones', 'Pecho', 'Ninguno', 'Cuerpo en línea recta, baja el pecho al suelo. Clásico de peso corporal.', 'Push-Up/0.jpg'],
            ['Flexiones diamante', 'Pecho', 'Ninguno', 'Manos juntas formando un diamante. Mayor énfasis en tríceps y pecho interno.', 'Diamond_Push-Up/0.jpg'],
            ['Press en máquina', 'Pecho', 'Máquina', 'Press de pecho en máquina guiada. Ideal para principiantes o aislamiento.', 'Chest_Press_Machine/0.jpg'],
            ['Contractor de pecho en máquina', 'Pecho', 'Máquina', 'Máquina de pectoral. Siéntate y junta los brazos al frente.', 'Seated_Cable_Crossover/0.jpg'],

            // ===== HOMBRO =====
            ['Press militar con barra', 'Hombro', 'Barra Larga', 'De pie, barra al frente, empuja verticalmente. Compuesto para hombros.', 'Barbell_Shoulder_Press/0.jpg'],
            ['Press militar con mancuernas', 'Hombro', 'Mancuernas', 'Sentado o de pie, mancuernas a la altura de los hombros, empuja arriba.', 'Dumbbell_Shoulder_Press/0.jpg'],
            ['Press Arnold', 'Hombro', 'Mancuernas', 'Mancuernas al frente con palmas hacia ti, al subir rotar las palmas al frente.', 'Arnold_Dumbbell_Press/0.jpg'],
            ['Elevaciones laterales', 'Hombro', 'Mancuernas', 'De pie, brazos a los lados, eleva las mancuernas hasta la altura de los hombros.', 'Dumbbell_Lateral_Raise/0.jpg'],
            ['Elevaciones frontales', 'Hombro', 'Mancuernas', 'Eleva las mancuernas al frente hasta la altura de los hombros.', 'Dumbbell_Front_Raise/0.jpg'],
            ['Pájaros con mancuernas', 'Hombro', 'Mancuernas', 'Torso inclinado, brazos caídos, eleva hacia los lados. Deltoides posterior.', 'Dumbbell_Reverse_Fly/0.jpg'],
            ['Elevaciones laterales en polea', 'Hombro', 'Polea', 'De pie, polea baja al lado, eleva el brazo lateralmente.', 'Cable_Lateral_Raise/0.jpg'],
            ['Face pull', 'Hombro', 'Polea', 'Polea alta, tira hacia la cara con los codos altos. Deltoides posterior y trapecio.', 'Face_Pull/0.jpg'],
            ['Encogimientos de hombros', 'Hombro', 'Mancuernas', 'De pie, mancuernas a los lados, eleva los hombros hacia las orejas. Trapecio.', 'Dumbbell_Shrug/0.jpg'],
            ['Press tras nuca', 'Hombro', 'Barra Larga', 'Barra tras la cabeza, empuja verticalmente. Mayor énfasis en deltoides posterior.', 'Barbell_Behind_Neck_Shoulder_Press/0.jpg'],
            ['Rotación externa de hombro', 'Hombro', 'Mancuernas', 'Acostado de lado, codo pegado al cuerpo, rota el brazo externamente.', 'Side_Lying_External_Rotation/0.jpg'],

            // ===== ESPALDA =====
            ['Peso muerto convencional', 'Espalda', 'Barra Larga', 'Desde el suelo, levanta la barra extendiendo caderas y rodillas. Cadena posterior completa.', 'Barbell_Deadlift/0.jpg'],
            ['Peso muerto rumano', 'Espalda', 'Barra Larga', 'Barra en manos, baja deslizando por las piernas, manteniendo piernas semi-extendidas.', 'Barbell_Romanian_Deadlift/0.jpg'],
            ['Peso muerto sumo', 'Espalda', 'Barra Larga', 'Postura amplia, brazos dentro de las piernas. Menos tensión lumbar.', 'Barbell_Sumo_Deadlift/0.jpg'],
            ['Dominadas', 'Espalda', 'Ninguno', 'Colgado de una barra, eleva el cuerpo hasta que la barbilla pase la barra.', 'Pull-Up/0.jpg'],
            ['Dominadas supinas', 'Espalda', 'Ninguno', 'Agarre supino (palmas hacia ti). Más bíceps, menos espalda ancha.', 'Chin-Up/0.jpg'],
            ['Remo con barra', 'Espalda', 'Barra Larga', 'Torso inclinado, barra colgando, lleva al abdomen. Espalda media.', 'Barbell_Bent_Over_Row/0.jpg'],
            ['Remo con mancuerna a una mano', 'Espalda', 'Mancuernas', 'Apoya rodilla y mano en banco, rema con la mancuerna. Unilateral.', 'Dumbbell_One_Arm_Row/0.jpg'],
            ['Remo en polea baja', 'Espalda', 'Polea', 'Sentado en máquina de polea, tira del agarre hacia el abdomen.', 'Seated_Cable_Row/0.jpg'],
            ['Remo en T', 'Espalda', 'Barra Larga', 'Con barra anclada en un extremo o máquina específica, rema hacia el pecho.', 'T-Bar_Row/0.jpg'],
            ['Jalón al pecho', 'Espalda', 'Polea', 'Agarre ancho, lleva la barra al pecho. Dorsales.', 'Lat_Pulldown/0.jpg'],
            ['Jalón agarre estrecho', 'Espalda', 'Polea', 'Agarre estrecho en V, tira hacia el pecho. Énfasis en dorsales bajos.', 'Close-Grip_Lat_Pulldown/0.jpg'],
            ['Jalón tras nuca', 'Espalda', 'Polea', 'Barra tras la cabeza, tira hacia la nuca. Mayor rango para dorsales superiores.', 'Behind_Neck_Lat_Pulldown/0.jpg'],
            ['Hiperextensiones', 'Espalda', 'Ninguno', 'Acostado boca abajo en banco de hiperextensiones, sube el torso. Lumbar.', 'Back_Extension/0.jpg'],
            ['Buenos días', 'Espalda', 'Barra Larga', 'Barra en la espalda, flexiona el torso hacia adelante. Femoral y lumbar.', 'Barbell_Good_Morning/0.jpg'],

            // ===== BÍCEPS =====
            ['Curl de bíceps con barra', 'Bíceps', 'Barra Z', 'De pie, barra en manos, curl hacia los hombros. Bíceps braquial.', 'Barbell_Curl/0.jpg'],
            ['Curl con barra Z', 'Bíceps', 'Barra Z', 'Barra Z, curl hacia los hombros. Menos tensión en muñecas.', 'EZ-Bar_Curl/0.jpg'],
            ['Curl con mancuernas alternado', 'Bíceps', 'Mancuernas', 'Alterna brazos, curl de mancuerna hacia el hombro rotando la palma.', 'Dumbbell_Curl/0.jpg'],
            ['Curl martillo', 'Bíceps', 'Mancuernas', 'Palmas enfrentadas, curl hacia el hombro. Braquial y antebrazo.', 'Dumbbell_Hammer_Curl/0.jpg'],
            ['Curl inclinado', 'Bíceps', 'Mancuernas', 'Sentado en banco inclinado, brazos caídos, curl. Mayor rango de movimiento.', 'Incline_Dumbbell_Curl/0.jpg'],
            ['Curl concentrado', 'Bíceps', 'Mancuernas', 'Sentado, codo apoyado en el muslo interno, curl lento. Aislamiento máximo.', 'Concentration_Curl/0.jpg'],
            ['Curl en polea baja', 'Bíceps', 'Polea', 'De pie frente a la polea baja, curl hacia los hombros. Tensión constante.', 'Cable_Curl/0.jpg'],
            ['Curl predicador', 'Bíceps', 'Barra Z', 'En banco Scott, curl de barra Z. Aislamiento sin balanceo.', 'Preacher_Curl/0.jpg'],
            ['Curl inverso', 'Bíceps', 'Mancuernas', 'Palmas hacia abajo, curl hacia los hombros. Antebrazo y braquial.', 'Reverse_Curl/0.jpg'],
            ['Curl araña', 'Bíceps', 'Mancuernas', 'Acostado boca abajo en banco inclinado, curl de mancuernas.', 'Spider_Curl/0.jpg'],

            // ===== TRÍCEPS =====
            ['Extensión de tríceps en polea', 'Tríceps', 'Polea', 'Cuerda en polea alta, codos fijos, extiende hacia abajo.', 'Cable_Triceps_Pushdown/0.jpg'],
            ['Extensión de tríceps con barra Z', 'Tríceps', 'Barra Z', 'Acostado, barra Z al frente, baja hacia la cabeza flexionando codos.', 'Skull_Crusher/0.jpg'],
            ['Press francés', 'Tríceps', 'Barra Z', 'Acostado, barra Z tras la cabeza, extiende hacia arriba.', 'Lying_Triceps_Extension/0.jpg'],
            ['Fondos en banco', 'Tríceps', 'Ninguno', 'Manos en un banco, cuerpo al frente, baja y sube. Tríceps con peso corporal.', 'Bench_Dips/0.jpg'],
            ['Extensión de tríceps tras nuca', 'Tríceps', 'Mancuernas', 'De pie o sentado, mancuerna tras la cabeza, extiende hacia arriba.', 'Overhead_Triceps_Extension/0.jpg'],
            ['Patada de tríceps', 'Tríceps', 'Mancuernas', 'Torso inclinado, codo fijo, extiende el brazo hacia atrás.', 'Dumbbell_Kickback/0.jpg'],
            ['Fondos en paralelas (tríceps)', 'Tríceps', 'Ninguno', 'Cuerpo recto, baja y sube. Tríceps, a diferencia de fondos para pecho.', 'Triceps_Dips/0.jpg'],
            ['Extensión de tríceps en polea inversa', 'Tríceps', 'Polea', 'Cuerda en polea alta, agarre inverso, extiende hacia abajo.', 'Reverse_Grip_Triceps_Pushdown/0.jpg'],

            // ===== CUÁDRICEPS =====
            ['Sentadilla trasera', 'Cuádriceps', 'Barra Larga', 'Barra en la espalda, baja hasta paralela, sube. El rey de los ejercicios.', 'Barbell_Squat/0.jpg'],
            ['Sentadilla frontal', 'Cuádriceps', 'Barra Larga', 'Barra al frente, torso más erguido. Énfasis en cuádriceps.', 'Barbell_Front_Squat/0.jpg'],
            ['Sentadilla búlgara', 'Cuádriceps', 'Mancuernas', 'Split squat con pierna trasera elevada en un banco. Excelente unilateral.', 'Bulgarian_Split_Squat/0.jpg'],
            ['Sentadilla goblet', 'Cuádriceps', 'Mancuernas', 'Mancuerna al pecho, sentadilla profunda. Ideal para aprender técnica.', 'Goblet_Squat/0.jpg'],
            ['Prensa de piernas', 'Cuádriceps', 'Máquina', 'Empuje en máquina de piernas. Ajusta el asiento y empuja la plataforma.', 'Leg_Press/0.jpg'],
            ['Zancadas', 'Cuádriceps', 'Mancuernas', 'Paso al frente y flexiona ambas rodillas. Alterna piernas.', 'Barbell_Lunge/0.jpg'],
            ['Zancadas laterales', 'Cuádriceps', 'Mancuernas', 'Paso lateral, flexiona la pierna. Aductores y cuádriceps.', 'Side_Lunge/0.jpg'],
            ['Zancadas traseras', 'Cuádriceps', 'Mancuernas', 'Paso atrás, flexiona ambas rodillas. Menos tensión en rodillas.', 'Reverse_Lunge/0.jpg'],
            ['Extensiones de piernas', 'Cuádriceps', 'Máquina', 'Sentado en máquina, extiende las piernas. Aislamiento de cuádriceps.', 'Leg_Extension/0.jpg'],
            ['Sentadilla con salto', 'Cuádriceps', 'Ninguno', 'Sentadilla explosiva, salta al subir. Pliométrico.', 'Jump_Squat/0.jpg'],

            // ===== ISQUIOS =====
            ['Curl femoral tumbado', 'Isquios', 'Máquina', 'Acostado boca abajo, flexiona las piernas hacia los glúteos.', 'Lying_Leg_Curl/0.jpg'],
            ['Curl femoral sentado', 'Isquios', 'Máquina', 'Sentado, flexiona las piernas. Diferente ángulo que tumbado.', 'Seated_Leg_Curl/0.jpg'],
            ['Peso muerto a una pierna', 'Isquios', 'Mancuernas', 'De pie a una pierna, baja el torso manteniendo la pierna extendida.', 'Single_Leg_Deadlift/0.jpg'],
            ['Buenos días', 'Isquios', 'Barra Larga', 'Barra en la espalda, flexión de cadera hacia adelante.', 'Barbell_Good_Morning/0.jpg'],
            ['Nordic curl', 'Isquios', 'Ninguno', 'Arrodillado, baja el torso controladamente. Isquios sin peso.', 'Nordic_Hamstring_Curl/0.jpg'],
            ['Curl femoral con fitball', 'Isquios', 'Fitball', 'Acostado, pies sobre fitball, flexiona las rodillas llevando la pelota.', 'Ball_Leg_Curl/0.jpg'],

            // ===== GLÚTEOS =====
            ['Hip thrust', 'Glúteos', 'Barra Larga', 'Espalda apoyada en banco, barra en caderas, empuja hacia arriba.', 'Barbell_Hip_Thrust/0.jpg'],
            ['Puente de glúteo', 'Glúteos', 'Ninguno', 'Acostado boca arriba, rodillas flexionadas, eleva la cadera.', 'Glute_Bridge/0.jpg'],
            ['Puente a una pierna', 'Glúteos', 'Ninguno', 'Puente con una pierna elevada. Unilateral.', 'Single_Leg_Glute_Bridge/0.jpg'],
            ['Patada de glúteo', 'Glúteos', 'Ninguno', 'A cuatro patas, eleva la pierna hacia atrás.', 'Quadruped_Hip_Extension/0.jpg'],
            ['Abducción de cadera', 'Glúteos', 'Máquina', 'Sentado en máquina, abre las piernas. Glúteo medio.', 'Seated_Hip_Abduction/0.jpg'],
            ['Peso muerto a una pierna', 'Glúteos', 'Mancuernas', 'De pie a una pierna, torso baja, pierna atrás se eleva. Glúteo e isquios.', 'Single_Leg_Deadlift/0.jpg'],
            ['Sentadilla profunda con pausa', 'Glúteos', 'Barra Larga', 'Sentadilla completa con pausa de 2 segundos abajo. Glúteos al máximo.', 'Barbell_Squat/0.jpg'],

            // ===== GEMELOS =====
            ['Elevación de gemelos de pie', 'Gemelos', 'Máquina', 'De pie en máquina, eleva los talones al máximo. Gemelos.', 'Standing_Calf_Raise/0.jpg'],
            ['Elevación de gemelos sentado', 'Gemelos', 'Mancuernas', 'Sentado con mancuernas en las rodillas, eleva los talones. Sóleo.', 'Seated_Calf_Raise/0.jpg'],
            ['Elevación de gemelos en prensa', 'Gemelos', 'Máquina', 'En prensa de piernas, empuja con las puntas de los pies.', 'Leg_Press_Calf_Raise/0.jpg'],
            ['Saltos a la pata coja', 'Gemelos', 'Ninguno', 'Saltos sobre un pie. Gemelos y propiocepción.', 'Single_Leg_Hop/0.jpg'],
            ['Subida de puntillas', 'Gemelos', 'Ninguno', 'De pie, sube y baja lentamente sobre las puntas. Sin peso.', 'Calf_Raise/0.jpg'],

            // ===== ABDOMEN =====
            ['Plancha', 'Abdomen', 'Ninguno', 'Antebrazos en el suelo, cuerpo en línea recta, aguanta.', 'Plank/0.jpg'],
            ['Plancha lateral', 'Abdomen', 'Ninguno', 'De lado, antebrazo en suelo, cuerpo recto. Oblicuos.', 'Side_Plank/0.jpg'],
            ['Plancha con peso', 'Abdomen', 'Ninguno', 'Plancha con un disco en la espalda. Mayor resistencia.', 'Weighted_Plank/0.jpg'],
            ['Ab wheel', 'Abdomen', 'Ninguno', 'Rueda abdominal. De rodillas, rueda al frente y vuelve.', 'Ab_Roller/0.jpg'],
            ['Elevación de piernas colgado', 'Abdomen', 'Ninguno', 'Colgado de una barra, eleva las piernas rectas hasta paralela.', 'Hanging_Leg_Raise/0.jpg'],
            ['Elevación de rodillas colgado', 'Abdomen', 'Ninguno', 'Colgado de una barra, eleva las rodillas al pecho.', 'Hanging_Knee_Raise/0.jpg'],
            ['Russian twist', 'Abdomen', 'Ninguno', 'Sentado con pies elevados, rota el torso a los lados con un disco.', 'Russian_Twist/0.jpg'],
            ['Encogimientos', 'Abdomen', 'Ninguno', 'Acostado, eleva los hombros contrayendo el abdomen. Crunch clásico.', 'Crunches/0.jpg'],
            ['Encogimientos con cable', 'Abdomen', 'Polea', 'Arrodillado frente a polea alta, torso hacia abajo contrayendo abdomen.', 'Cable_Crunch/0.jpg'],
            ['Bicicleta', 'Abdomen', 'Ninguno', 'Acostado, pedalea en el aire tocando codo-rodilla alternado.', 'Bicycle_Crunch/0.jpg'],
            ['V-up', 'Abdomen', 'Ninguno', 'Acostado, eleva piernas y torso simultáneamente formando una V.', 'V-Up/0.jpg'],
            ['Escalador de montaña', 'Abdomen', 'Ninguno', 'En posición de flexión, lleva rodillas al pecho alternando.', 'Mountain_Climber/0.jpg'],
            ['Flexión lateral de pie', 'Abdomen', 'Mancuernas', 'De pie, mancuerna en una mano, flexión lateral del torso. Oblicuos.', 'Standing_Oblique_Crunch/0.jpg'],
            ['Puente abdominal', 'Abdomen', 'Ninguno', 'Acostado boca arriba, eleva cadera manteniendo abdomen contraído.', 'Glute_Bridge/0.jpg'],

            // ===== CARDIO =====
            ['Burpees', 'Cardio', 'Ninguno', 'Flexión → salto vertical → repite. Full-body cardiovascular.', 'Burpee/0.jpg'],
            ['Saltos de tijera', 'Cardio', 'Ninguno', 'Abre y cierra piernas mientras subes y bajas brazos. Jumping jacks.', 'Jumping_Jacks/0.jpg'],
            ['Cuerda a saltar', 'Cardio', 'Ninguno', 'Saltos continuos con cuerda. Mantén un ritmo constante.', 'Jump_Rope/0.jpg'],
            ['Remo en máquina', 'Cardio', 'Máquina', 'Empuja con piernas, tira con brazos, desliza hacia atrás. Full-body cardio.', 'Row_Machine/0.jpg'],
            ['Bicicleta estática', 'Cardio', 'Máquina', 'Pedaleo continuo. Cardio de bajo impacto.', 'Stationary_Bike/0.jpg'],
            ['Cinta de correr', 'Cardio', 'Máquina', 'Correr o caminar. Cardio básico.', 'Treadmill/0.jpg'],
            ['Elíptica', 'Cardio', 'Máquina', 'Movimiento elíptico suave. Cardio sin impacto.', 'Elliptical/0.jpg'],
            ['High knees', 'Cardio', 'Ninguno', 'Correr en el sitio elevando rodillas al máximo.', 'High_Knee_Run/0.jpg'],
            ['Saltos de estrella', 'Cardio', 'Ninguno', 'Salta abriendo brazos y piernas simultáneamente.', 'Star_Jumps/0.jpg'],

            // ===== ANTERBAZO / AGARRE =====
            ['Paseo del granjero', 'Antebrazo', 'Mancuernas', 'Camina sujetando mancuernas pesadas a los lados. Agarre y antebrazos.', 'Farmers_Walk/0.jpg'],
            ['Curl de muñeca', 'Antebrazo', 'Mancuernas', 'Antebrazos apoyados en banco, flexiona la muñeca hacia arriba.', 'Wrist_Curl/0.jpg'],
            ['Curl de muñeca inverso', 'Antebrazo', 'Mancuernas', 'Palmas hacia abajo, flexiona la muñeca hacia arriba.', 'Reverse_Wrist_Curl/0.jpg'],
            ['Colgada muerta', 'Antebrazo', 'Ninguno', 'Cuelga de una barra el máximo tiempo posible. Agarre.', 'Dead_Hang/0.jpg'],
            ['Pinch grip hold', 'Antebrazo', 'Mancuernas', 'Sostén discos con los dedos (pinza). Fuerza de dedos.', 'Plate_Pinch/0.jpg'],

            // ===== TRAPECIO =====
            ['Encogimientos con barra', 'Trapecio', 'Barra Larga', 'Barra al frente, eleva los hombros hacia las orejas.', 'Barbell_Shrug/0.jpg'],
            ['Encogimientos con mancuernas', 'Trapecio', 'Mancuernas', 'Mancuernas a los lados, eleva los hombros.', 'Dumbbell_Shrug/0.jpg'],
            ['Face pull', 'Trapecio', 'Polea', 'Polea alta, tira hacia la cara. Trapecio medio y deltoides posterior.', 'Face_Pull/0.jpg'],
            ['Pájaros con mancuernas', 'Trapecio', 'Mancuernas', 'Torso inclinado, brazos a los lados, eleva. Trapecio medio.', 'Dumbbell_Reverse_Fly/0.jpg'],

            // ===== LUMBAR =====
            ['Hiperextensiones', 'Lumbar', 'Ninguno', 'Acostado boca abajo en banco de hiperextensiones, sube el torso.', 'Back_Extension/0.jpg'],
            ['Peso muerto', 'Lumbar', 'Barra Larga', 'Desde el suelo, levanta la barra. Lumbar y cadena posterior.', 'Barbell_Deadlift/0.jpg'],
            ['Buenos días', 'Lumbar', 'Barra Larga', 'Barra en la espalda, flexión de cadera. Lumbar y femoral.', 'Barbell_Good_Morning/0.jpg'],
            ['Pájaro perro', 'Lumbar', 'Ninguno', 'A cuatro patas, extiende brazo y pierna opuestos. Estabilidad lumbar.', 'Bird_Dog/0.jpg'],
            ['Superman', 'Lumbar', 'Ninguno', 'Acostado boca abajo, eleva brazos y piernas simultáneamente.', 'Superman/0.jpg'],
        ];

        $io->note('Insertando ' . count($exercises) . ' ejercicios...');
        $progress = $io->createProgressBar(count($exercises));
        $progress->start();

        foreach ($exercises as $ex) {
            try {
                $gifUrl = self::BASE_IMAGE_URL . $ex[4];
                $conn->executeStatement(
                    'INSERT INTO exercises (id, name, muscle_group, equipment, description, gif_url) VALUES (UUID(), ?, ?, ?, ?, ?)',
                    [$ex[0], $ex[1], $ex[2], $ex[3], $gifUrl]
                );
                $added++;
            } catch (\Exception) {
                // ignorar errores individuales
            }
            if ($progress) $progress->advance();
        }

        if ($progress) $progress->finish();
        $io->newLine(2);
        $io->success("$added ejercicios añadidos en español!");

        return Command::SUCCESS;
    }
}
