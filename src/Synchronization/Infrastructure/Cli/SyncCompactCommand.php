<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Infrastructure\Cli;

use Providentia\SharedKernel\Application\Clock;
use Providentia\Synchronization\Application\SyncStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sync:compact', description: 'Safely compact expired synchronization tombstones.')]
final class SyncCompactCommand extends Command
{
    public function __construct(
        private readonly SyncStore $store,
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Process one pass and exit.')
            ->addOption('home', null, InputOption::VALUE_REQUIRED, 'Restrict the pass to one home UUID.')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Maximum tombstones per home.', '250')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between continuous passes.', '3600');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = $this->positiveInteger($input->getOption('batch-size'), 'batch-size', 1000);
        $interval = $this->positiveInteger($input->getOption('interval'), 'interval', 86400);
        $requestedHome = $input->getOption('home');
        if ($requestedHome !== null && (! is_string($requestedHome) || ! $this->isUuid($requestedHome))) {
            $output->writeln('<error>--home must be a UUID.</error>');

            return Command::INVALID;
        }

        do {
            $at = $this->clock->now();
            $homes = is_string($requestedHome)
                ? [strtolower($requestedHome)]
                : $this->store->homesWithExpiredTombstones($at, 1000);
            $deleted = 0;
            foreach ($homes as $homeId) {
                $result = $this->store->compactTombstones($homeId, $at, $batchSize);
                $deleted += $result['deleted'];
            }
            $output->writeln(json_encode([
                'homes' => count($homes),
                'tombstonesDeleted' => $deleted,
                'completedAt' => $at->format(DATE_ATOM),
            ], JSON_THROW_ON_ERROR));

            if (! $input->getOption('once')) {
                sleep($interval);
            }
        } while (! $input->getOption('once'));

        return Command::SUCCESS;
    }

    private function positiveInteger(mixed $value, string $name, int $maximum): int
    {
        if (! is_scalar($value) || preg_match('/^[1-9][0-9]*$/', (string) $value) !== 1) {
            throw new \InvalidArgumentException('--' . $name . ' must be a positive integer.');
        }
        $integer = (int) $value;
        if ($integer > $maximum) {
            throw new \InvalidArgumentException('--' . $name . ' exceeds its safe maximum.');
        }

        return $integer;
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
