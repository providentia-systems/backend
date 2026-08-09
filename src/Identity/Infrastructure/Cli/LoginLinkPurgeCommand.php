<?php

declare(strict_types=1);

namespace Providentia\Identity\Infrastructure\Cli;

use DateInterval;
use Providentia\Identity\Application\AuthenticationRateLimitStore;
use Providentia\Identity\Application\LoginLinkStore;
use Providentia\SharedKernel\Application\Clock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'login-link:purge',
    description: 'Expire login requests and purge terminal records after the configured retention.',
)]
final class LoginLinkPurgeCommand extends Command
{
    public function __construct(
        private readonly LoginLinkStore $requests,
        private readonly AuthenticationRateLimitStore $rateLimits,
        private readonly Clock $clock,
        private readonly int $retentionDays,
        private readonly int $rateLimitRetentionDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum records to purge.', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var((string) $input->getOption('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $output->writeln('<error>--limit must be an integer from 1 to 1000.</error>');

            return Command::INVALID;
        }
        $now = $this->clock->now();
        $result = $this->requests->purgeExpired(
            $now,
            $now->sub(new DateInterval('P' . $this->retentionDays . 'D')),
            $limit,
        );
        $rateLimitBucketsPurged = $this->rateLimits->purgeInactive(
            $now,
            $now->sub(new DateInterval('P' . $this->rateLimitRetentionDays . 'D')),
            $limit,
        );
        $output->writeln(sprintf(
            'Login-link maintenance: %d expired, %d requests purged, %d rate-limit buckets purged.',
            $result['expired'],
            $result['purged'],
            $rateLimitBucketsPurged,
        ));

        return Command::SUCCESS;
    }
}
