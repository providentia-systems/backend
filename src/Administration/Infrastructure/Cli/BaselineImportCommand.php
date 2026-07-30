<?php

declare(strict_types=1);

namespace Providentia\Administration\Infrastructure\Cli;

use Providentia\Administration\Application\BaselineImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'baseline:import',
    description: 'Verify, reconcile and idempotently import the Providentia v1 household baseline.',
)]
final class BaselineImportCommand extends Command
{
    public function __construct(private readonly BaselineImportService $imports)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('data', null, InputOption::VALUE_REQUIRED, 'Path to pantry-data.json.')
            ->addOption('rules', null, InputOption::VALUE_REQUIRED, 'Path to product-rules.json.')
            ->addOption('home', null, InputOption::VALUE_REQUIRED, 'Target home UUID.')
            ->addOption('actor-user', null, InputOption::VALUE_REQUIRED, 'Active owner or manager UUID.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Verify and reconcile without writes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $data = (string) $input->getOption('data');
        $rules = (string) $input->getOption('rules');
        $home = (string) $input->getOption('home');
        $actor = (string) $input->getOption('actor-user');
        $dryRun = (bool) $input->getOption('dry-run');
        if ($data === '' || $rules === '' || (! $dryRun && ($home === '' || $actor === ''))) {
            $output->writeln(
                '<error>--data and --rules are required; commit mode also requires --home and --actor-user.</error>',
            );

            return Command::INVALID;
        }
        $report = $this->imports->validateAndImport($data, $rules, $home, $actor, $dryRun);
        $output->writeln(json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));

        return Command::SUCCESS;
    }
}
