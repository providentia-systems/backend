<?php

declare(strict_types=1);

namespace Providentia\Catalog\Infrastructure\Cli;

use Providentia\Catalog\Application\CatalogSeedService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'catalog:seed', description: 'Validate and idempotently import the authoritative global catalog seed.')]
final class CatalogSeedCommand extends Command
{
    public function __construct(private readonly CatalogSeedService $service)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('data', null, InputOption::VALUE_REQUIRED, 'Path to pantry-data.json.')
            ->addOption('rules', null, InputOption::VALUE_REQUIRED, 'Path to product-rules.json.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and reconcile without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $data = (string) $input->getOption('data');
        $rules = (string) $input->getOption('rules');
        if ($data === '' || $rules === '') {
            $output->writeln('<error>Both --data and --rules are required.</error>');

            return Command::INVALID;
        }

        $report = $this->service->validateAndImport($data, $rules, (bool) $input->getOption('dry-run'));
        $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
