<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Providentia\Identity\Infrastructure\Cli\EmailCodePurgeCommand;
use Providentia\Identity\Infrastructure\Doctrine\DbalAuthenticationRateLimitStore;
use Providentia\Identity\Infrastructure\Doctrine\DbalEmailCodeStore;
use Providentia\SharedKernel\Application\Clock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class EmailCodePurgeCommandTest extends TestCase
{
    private Connection $connection;
    private DbalEmailCodeStore $codes;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE email_code_challenges (id VARCHAR(36) PRIMARY KEY, expires_at DATETIME NOT NULL)',
        );
        $this->connection->executeStatement(
            'CREATE TABLE authentication_rate_limits (
                bucket_hash VARCHAR(64) PRIMARY KEY,
                attempts INTEGER NOT NULL,
                window_started_at DATETIME NOT NULL,
                blocked_until DATETIME NULL,
                updated_at DATETIME NOT NULL
            )',
        );
        $this->codes = new DbalEmailCodeStore($this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testBoundedPassPreservesLiveCodesRecentBucketsAndActiveBlocks(): void
    {
        $this->code('oldest', '2026-09-04 12:00:00');
        $this->code('expired', '2026-09-07 11:59:59');
        $this->code('deadline', '2026-09-07 12:00:00');
        $this->code('live', '2026-09-07 12:00:01');
        $this->bucket('inactive', '2026-09-01 12:00:00');
        $this->bucket('recent', '2026-09-06 12:00:00');
        $this->bucket('blocked', '2026-09-01 12:00:00', '2026-09-07 12:00:01');
        $tester = $this->command();

        self::assertSame(Command::SUCCESS, $tester->execute(['--limit' => '2']));
        self::assertSame(['deadline', 'live'], $this->remainingCodes());
        self::assertSame(['blocked', 'recent'], $this->remainingBuckets());
        self::assertSame([
            'emailCodesDeleted' => 2,
            'rateLimitBucketsDeleted' => 1,
            'completedAt' => '2026-09-07T12:00:00+00:00',
        ], json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR));

        self::assertSame(Command::SUCCESS, $tester->execute(['--limit' => '2']));
        self::assertSame(['live'], $this->remainingCodes());
        self::assertSame(['blocked', 'recent'], $this->remainingBuckets());
        self::assertSame(Command::SUCCESS, $tester->execute(['--limit' => '2']));
        self::assertStringContainsString('"emailCodesDeleted":0', $tester->getDisplay());
    }

    public function testConfiguredRetentionAndCurrentTimezoneUseOneUtcCutoff(): void
    {
        $this->code('expired', '2026-09-07 11:59:59');
        $this->code('live', '2026-09-07 12:00:01');
        $this->bucket('retention-boundary', '2026-09-06 12:00:00');
        $this->bucket('recent', '2026-09-06 12:00:01');
        $this->bucket('block-expired', '2026-09-01 12:00:00', '2026-09-07 12:00:00');
        $this->bucket('blocked', '2026-09-01 12:00:00', '2026-09-07 12:00:01');

        $tester = $this->command(1, new DateTimeImmutable('2026-09-07T14:00:00+02:00'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(['live'], $this->remainingCodes());
        self::assertSame(['blocked', 'recent'], $this->remainingBuckets());
    }

    public function testDefaultAndMaximumPassesAreBoundedEvenForDirectStoreCallers(): void
    {
        $statement = $this->connection->prepare('INSERT INTO email_code_challenges (id, expires_at) VALUES (?, ?)');
        $statement->bindValue(2, '2026-09-01 12:00:00');
        $this->connection->beginTransaction();
        for ($number = 0; $number < 11002; $number++) {
            $statement->bindValue(1, sprintf('%05d', $number));
            $statement->executeStatement();
        }
        $this->connection->commit();

        self::assertSame(1000, $this->codes->purge('2026-09-07 12:00:00'));
        self::assertSame(10000, $this->codes->purge('2026-09-07 12:00:00', PHP_INT_MAX));
        self::assertSame(['11000', '11001'], $this->remainingCodes());
    }

    #[DataProvider('invalidLimits')]
    public function testInvalidLimitLeavesAllAuthenticationStateUntouched(string $limit): void
    {
        $this->code('expired', '2026-09-01 12:00:00');
        $this->bucket('inactive', '2026-09-01 12:00:00');

        $tester = $this->command();

        self::assertSame(Command::INVALID, $tester->execute(['--limit' => $limit]));
        self::assertSame(['expired'], $this->remainingCodes());
        self::assertSame(['inactive'], $this->remainingBuckets());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLimits(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'fraction' => ['1.5'];
        yield 'non-numeric' => ['all'];
        yield 'over maximum' => ['10001'];
        yield 'integer overflow' => ['999999999999999999999999'];
    }

    private function command(int $retentionDays = 2, ?DateTimeImmutable $now = null): CommandTester
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now ?? new DateTimeImmutable('2026-09-07T12:00:00+00:00'));

        return new CommandTester(new EmailCodePurgeCommand(
            $this->codes,
            new DbalAuthenticationRateLimitStore($this->connection),
            $clock,
            $retentionDays,
        ));
    }

    private function code(string $id, string $expiresAt): void
    {
        $this->connection->insert('email_code_challenges', ['id' => $id, 'expires_at' => $expiresAt]);
    }

    private function bucket(string $id, string $updatedAt, ?string $blockedUntil = null): void
    {
        $this->connection->insert('authentication_rate_limits', [
            'bucket_hash' => $id,
            'attempts' => 1,
            'window_started_at' => $updatedAt,
            'blocked_until' => $blockedUntil,
            'updated_at' => $updatedAt,
        ]);
    }

    /** @return list<string> */
    private function remainingCodes(): array
    {
        return $this->connection->fetchFirstColumn('SELECT id FROM email_code_challenges ORDER BY id');
    }

    /** @return list<string> */
    private function remainingBuckets(): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT bucket_hash FROM authentication_rate_limits ORDER BY bucket_hash',
        );
    }
}
