<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\HevyExerciseCatalogImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:import-hevy-exercises', description: 'Importa nombres oficiales EN/ES y metadatos desde la instantánea local de Hevy, sin descargar contenido')]
final class ImportHevyExercisesCommand extends Command
{
    public function __construct(private HevyExerciseCatalogImporter $importer)
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
            ['Origen' => 'Hevy; instantánea local 2026-10-21 (nombres EN/ES y metadatos)'],
            ['Modo' => $result['dry_run'] ? 'Simulación; no se ha escrito ningún cambio' : 'Importación atómica completada'],
            ['Ejercicios de origen validados' => $result['total']],
            ['Nuevos' => $result['created']],
            ['Existentes vinculados; etiquetas personalizadas conservadas' => $result['adopted']],
            ['Ya importados; sin cambios' => $result['unchanged']],
            ['Coincidencias ambiguas; se crea un registro separado' => $result['ambiguous']],
        );
        $io->note('Escaleras/pasos se registran por duración, no como metros. No se importan imágenes, vídeos ni instrucciones de Hevy.');
        $io->success('Catálogo procesado sin borrar ejercicios, rutinas ni historial.');
        return Command::SUCCESS;
    }
}
