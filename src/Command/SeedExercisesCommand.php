<?php

namespace App\Command;

use App\Service\FitcronScraperService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-exercises',
    description: 'Scrapes fitcron.com to populate the Exercise database catalog.',
)]
class SeedExercisesCommand extends Command
{
    public function __construct(
        private FitcronScraperService $scraper
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('pages', InputArgument::OPTIONAL, 'Number of pages to scrape', 5);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pages = (int) $input->getArgument('pages');

        $io->title("Starting Fitcron Exercise Scraper ($pages pages maximum)");
        $io->note("This process will connect to fitcron.com, fetch HTML, parse WordPress DOM elements, and insert/update them in the configured database.");

        $progress = $io->createProgressBar();
        $progress->setFormat(' %current% [%bar%] %elapsed:6s% %memory:6s% | %message%');
        
        // Start without max steps since we don't know total exercises
        $progress->start();

        $stats = $this->scraper->scrapeAll($pages, function(string $name) use ($progress) {
            $progress->setMessage("Scraped: $name");
            $progress->advance();
        });

        $progress->finish();
        $io->newLine(2);

        $io->success([
            'Scraping completed!',
            "New Exercises Added: {$stats['added']}",
            "Existing Exercises Updated: {$stats['updated']}",
            "Failed processing: {$stats['failed']}"
        ]);

        return Command::SUCCESS;
    }
}
