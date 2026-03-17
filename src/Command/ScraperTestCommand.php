<?php

namespace App\Command;

use App\Service\FatSecretScraperService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:scraper-test',
    description: 'Test the FatSecret Scraper Service',
)]
class ScraperTestCommand extends Command
{
    public function __construct(
        private FatSecretScraperService $scraper
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Testing Search (hacendado, pg 0):');
        $results = $this->scraper->search('hacendado', 0);
        $output->writeln('Got ' . count($results) . ' results. First one:');
        dump(array_slice($results, 0, 1));
        
        $output->writeln("\nTesting HTML Scraper Info (id: 1277275):");
        $macros = $this->scraper->getInfo('1277275');
        dump($macros);

        return Command::SUCCESS;
    }
}
