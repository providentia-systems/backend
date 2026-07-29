<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Cli;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Interop\Queue\Context;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'queue:consume', description: 'Consume and idempotently dispatch asynchronous messages.')]
final class QueueConsumeCommand extends Command
{
    public function __construct(
        private readonly Context $context,
        private readonly Connection $connection,
        private readonly string $queueName,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Poll once and exit.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Receive timeout in milliseconds.', '1000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $consumer = $this->context->createConsumer($this->context->createQueue($this->queueName));
        $timeout = max(1, (int) $input->getOption('timeout'));
        $handled = false;
        $failed = false;

        do {
            $transportMessage = $consumer->receive($timeout);
            if ($transportMessage === null) {
                continue;
            }
            $handled = true;

            $messageId = $transportMessage->getMessageId() ?: hash('sha256', $transportMessage->getBody());
            try {
                /** @var array{id: string, type: string, occurredAt: string, payload: array<string, mixed>} $message */
                $message = json_decode($transportMessage->getBody(), true, 512, JSON_THROW_ON_ERROR);
                if (($message['id'] ?? '') === '' || ($message['type'] ?? '') === '') {
                    throw new \UnexpectedValueException('Message envelope identity or type is invalid.');
                }
                $messageId = $message['id'];
                if ($message['type'] !== 'foundation.recorded.v1') {
                    throw new \UnexpectedValueException('No handler is registered for ' . $message['type']);
                }

                $this->connection->transactional(function (Connection $connection) use ($messageId): void {
                    $connection->insert('async_processed_messages', [
                        'message_id' => $messageId,
                        'processed_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                            ->format('Y-m-d H:i:s.u'),
                        'handler_name' => 'foundation-proof',
                    ]);
                });
                $consumer->acknowledge($transportMessage);
                $output->writeln('Processed ' . $messageId);
            } catch (UniqueConstraintViolationException) {
                $consumer->acknowledge($transportMessage);
                $output->writeln('Acknowledged duplicate ' . $messageId);
            } catch (Throwable $error) {
                $failed = true;
                $this->connection->executeStatement(
                    'INSERT INTO async_failed_messages
                        (id, source_message_id, failed_at, reason, resolved_at)
                     VALUES (:id, :source, :failed, :reason, NULL)',
                    [
                        'id' => Uuid::uuid7()->toString(),
                        'source' => mb_substr($messageId, 0, 36),
                        'failed' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                            ->format('Y-m-d H:i:s.u'),
                        'reason' => mb_substr($error->getMessage(), 0, 2000),
                    ],
                );
                $consumer->acknowledge($transportMessage);
                $output->writeln('<error>Moved message to persistent failed review: ' . $messageId . '</error>');
            }
        } while (! $input->getOption('once'));

        return $handled && ! $failed ? Command::SUCCESS : Command::FAILURE;
    }
}
