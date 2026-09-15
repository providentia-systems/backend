<?php

declare(strict_types=1);

namespace Providentia\Inventory\Infrastructure\Cli;

use Providentia\Inventory\Application\HomeProductIdentityReconciler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'inventory:reconcile-identities',
    description: 'Preview or explicitly apply bounded home-product identity repairs.',
)]
final class ReconcileHomeProductIdentitiesCommand extends Command
{
    public function __construct(private readonly HomeProductIdentityReconciler $reconciler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('home', null, InputOption::VALUE_REQUIRED, 'Required home UUID.')
            ->addOption('after', null, InputOption::VALUE_REQUIRED, 'Continue after this home-product UUID.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Bounded page size, 1-1000.', '250')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Opt in to repairs; omitted means read-only dry-run.')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Active home-owner UUID; required with --apply.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $home = $input->getOption('home');
        $after = $input->getOption('after');
        $actor = $input->getOption('actor');
        $apply = (bool) $input->getOption('apply');
        $limit = filter_var(
            $input->getOption('limit'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 1000]],
        );
        if (
            ! $this->uuid($home) || ($after !== null && ! $this->uuid($after)) || $limit === false
            || ($apply && ! $this->uuid($actor)) || (! $apply && $actor !== null)
        ) {
            $output->writeln(
                '<error>Use --home UUID [--after UUID] [--limit 1..1000]; --apply requires --actor UUID.</error>',
            );

            return Command::INVALID;
        }
        try {
            $result = $this->reconciler->run(
                strtolower((string) $home),
                is_string($after) ? strtolower($after) : null,
                $limit,
                $apply && is_string($actor) ? strtolower($actor) : null,
            );
        } catch (\DomainException) {
            $output->writeln('<error>The actor must be an active owner of this home.</error>');

            return Command::FAILURE;
        }
        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }

    private function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value,
        ) === 1;
    }
}
