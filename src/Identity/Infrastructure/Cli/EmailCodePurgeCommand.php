<?php

declare(strict_types=1);

namespace Providentia\Identity\Infrastructure\Cli;

use DateTimeZone;
use Providentia\Identity\Application\AuthenticationRateLimitStore;
use Providentia\Identity\Application\EmailCodeStore;
use Providentia\SharedKernel\Application\Clock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'email-code:purge',
    description: 'Purge expired email codes and inactive authentication rate limits.',
)]
final class EmailCodePurgeCommand extends Command
{
    public function __construct(
        private readonly EmailCodeStore $codes,
        private readonly AuthenticationRateLimitStore $rateLimits,
        private readonly Clock $clock,
        private readonly int $rateLimitRetentionDays = 2,
    ) {
        if ($rateLimitRetentionDays < 1 || $rateLimitRetentionDays > 30) {
            throw new \InvalidArgumentException('Rate-limit retention must be between 1 and 30 days.');
        }
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Maximum expired codes per pass (1–10000); inactive rate buckets are capped at 1000.',
            '1000',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $value = $input->getOption('limit');
        if (
            ! is_string($value)
            || preg_match('/^[1-9][0-9]{0,4}$/D', $value) !== 1
            || (int) $value > 10000
        ) {
            $output->writeln('<error>--limit must be an integer between 1 and 10000.</error>');

            return Command::INVALID;
        }

        $limit = (int) $value;
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $deletedCodes = $this->codes->purge($now->format('Y-m-d H:i:s'), $limit);
        $deletedBuckets = $this->rateLimits->purgeInactive(
            $now,
            $now->modify('-' . $this->rateLimitRetentionDays . ' days'),
            min($limit, 1000),
        );
        $output->writeln(json_encode([
            'emailCodesDeleted' => $deletedCodes,
            'rateLimitBucketsDeleted' => $deletedBuckets,
            'completedAt' => $now->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
