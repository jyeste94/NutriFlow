<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ExerciseCatalogImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:import-exercises', description: 'Amplía el catálogo sin borrar datos usando la instantánea local libre de free-exercise-db')]
final class ImportExercisesCommand extends Command
{
    public function __construct(private ExerciseCatalogImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Valida y muestra el plan completo sin escribir cambios');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $result = $this->importer->import((bool) $input->getOption('dry-run'));
        } catch (\Throwable $error) {
            $io->error('Importación cancelada, sin cambios parciales: '.$error->getMessage());
            return Command::FAILURE;
        }

        $io->definitionList(
            ['Origen' => 'free-exercise-db @ '.ExerciseCatalogImporter::REVISION],
            ['Modo' => $result['dry_run'] ? 'Simulación; no se ha escrito ningún cambio' : 'Importación atómica completada'],
            ['Ejercicios de origen validados' => $result['total']],
            ['Nuevos' => $result['created']],
            ['Existentes vinculados sin modificar sus datos' => $result['adopted']],
            ['Ya importados; sin cambios' => $result['unchanged']],
            ['Coincidencias ambiguas; se crea un registro separado' => $result['ambiguous']],
        );
        $io->success('Catálogo procesado sin borrar ejercicios, rutinas ni historial.');
        return Command::SUCCESS;
    }
}
