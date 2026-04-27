<?php

namespace App\Command;

use App\Entity\Exercise;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'app:seed-exercises')]
class SeedExercisesCommand extends Command
{
    private const JSON_URL = 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/dist/exercises.json';

    private const MUSCLE_MAP = [
        'chest' => 'Pecho', 'shoulders' => 'Hombro', 'biceps' => 'Biceps', 'triceps' => 'Triceps',
        'abdominals' => 'Abdomen', 'quadriceps' => 'Cuádriceps', 'hamstrings' => 'Isquios',
        'glutes' => 'Glúteos', 'calves' => 'Gemelos', 'lower back' => 'Lumbar', 'middle back' => 'Espalda',
        'lats' => 'Espalda', 'traps' => 'Trapecio', 'forearms' => 'Antebrazo', 'adductors' => 'Aductor',
        'abductors' => 'Abductor', 'neck' => 'Cuello',
    ];

    private const EQUIPMENT_MAP = [
        'barbell' => 'Barra Larga', 'dumbbell' => 'Mancuernas', 'cable' => 'Polea', 'machine' => 'Máquina',
        'bands' => 'Bandas', 'kettlebells' => 'Mancuernas', 'body only' => 'Ninguno',
        'exercise ball' => 'Fitball', 'foam roll' => 'Ninguno', 'other' => 'Otro',
        'e-z curl bar' => 'Barra Z', 'medicine ball' => 'Otro',
    ];

    private const BASE_IMAGE_URL = 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/';

    public function __construct(
        private HttpClientInterface $client,
        private EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Downloads and seeds exercises from the free-exercise-db (GitHub)')
            ->addOption('force', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Delete existing and re-seed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Seeding exercises from free-exercise-db...');

        $repo = $this->em->getRepository(Exercise::class);
        $existing = (int) $repo->createQueryBuilder('e')->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();

        if ($existing > 0) {
            if (!$input->getOption('force')) {
                $io->warning("Already have $existing exercises. Use --force to delete and re-seed.");
                $io->note("Run: php bin/console app:seed-exercises --force");
                return Command::SUCCESS;
            }
            $io->note("Deleting existing $existing exercises...");
            $repo->createQueryBuilder('e')->delete()->getQuery()->execute();
        }

        $io->note('Downloading exercise database...');
        $response = $this->client->request('GET', self::JSON_URL);
        $exercises = $response->toArray();

        $io->note('Processing ' . count($exercises) . ' exercises...');
        $progress = $io->createProgressBar(count($exercises));
        $progress->start();

        $added = 0;
        foreach ($exercises as $data) {
            try {
                $name = $data['name'] ?? null;
                if (!$name) { $progress->advance(); continue; }

                $primaryMuscle = $data['primaryMuscles'][0] ?? null;
                $muscleGroup = self::MUSCLE_MAP[strtolower($primaryMuscle ?? '')] ?? ucfirst($primaryMuscle ?? 'General');

                $equip = strtolower($data['equipment'] ?? '');
                $equipment = self::EQUIPMENT_MAP[$equip] ?? ($equip ? ucfirst($equip) : 'Ninguno');

                $instructions = $data['instructions'] ?? [];
                $description = $instructions ? implode(' ', array_slice($instructions, 0, 2)) : '';

                $imageId = $data['id'] ?? str_replace(' ', '_', $name);
                $images = $data['images'] ?? [];
                $gifUrl = !empty($images) ? self::BASE_IMAGE_URL . $images[0] : null;

                $exercise = new Exercise();
                $exercise->setName(substr($name, 0, 255));
                $exercise->setMuscleGroup($muscleGroup);
                $exercise->setEquipment($equipment);
                $exercise->setDescription(substr($description, 0, 2000));
                if ($gifUrl) $exercise->setGifUrl($gifUrl);

                $this->em->persist($exercise);
                $added++;

                if ($added % 50 === 0) {
                    $this->em->flush();
                }

                if ($progress) $progress->advance();
            } catch (\Exception) {
                // Skip individual failures
                if ($progress) $progress->advance();
            }
        }

        $this->em->flush();
        if ($progress) $progress->finish();

        $io->newLine(2);
        $io->success("Added $added new exercises!");

        return Command::SUCCESS;
    }
}
