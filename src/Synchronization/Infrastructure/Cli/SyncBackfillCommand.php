<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Infrastructure\Cli;

use Providentia\Synchronization\Application\SyncBackfillService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sync:backfill', description: 'Backfill missing pantry resources into the sync feed.')]
final class SyncBackfillCommand extends Command
{
    public function __construct(private readonly SyncBackfillService $backfill)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('home', null, InputOption::VALUE_REQUIRED, 'Restrict backfill to one home UUID.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum resources per batch.', '250')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Process one bounded batch and exit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $requestedHome = $input->getOption('home');
        if ($requestedHome !== null && (! is_string($requestedHome) || ! $this->isUuid($requestedHome))) {
            $output->writeln('<error>--home must be a UUID.</error>');

            return Command::INVALID;
        }
        $limit = $this->limit($input->getOption('limit'));
        if ($limit === null) {
            $output->writeln('<error>--limit must be an integer between 1 and 1000.</error>');

            return Command::INVALID;
        }
        $homeId = is_string($requestedHome) ? strtolower($requestedHome) : null;
        $once = (bool) $input->getOption('once');
        $batches = 0;
        $scanned = 0;
        $appended = 0;
        $byType = [];
        do {
            $result = $this->backfill->run($homeId, $limit);
            $batches++;
            $scanned += $result['scanned'];
            $appended += $result['appended'];
            foreach ($result['byType'] as $type => $count) {
                $byType[$type] = ($byType[$type] ?? 0) + $count;
            }
            $hasMore = $result['hasMore'];
        } while (! $once && $hasMore);
        ksort($byType);
        $output->writeln(json_encode([
            'homeId' => $homeId,
            'batches' => $batches,
            'scanned' => $scanned,
            'appended' => $appended,
            'byType' => $byType,
            'complete' => ! $hasMore,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }

    private function limit(mixed $value): ?int
    {
        if (! is_scalar($value) || preg_match('/^[1-9][0-9]*$/', (string) $value) !== 1) {
            return null;
        }
        $limit = (int) $value;

        return $limit <= 1000 ? $limit : null;
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
