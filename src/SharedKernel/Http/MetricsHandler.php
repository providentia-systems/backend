<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Http;

use Laminas\Diactoros\Response\TextResponse;
use Providentia\SharedKernel\Application\Async\OutboxStore;
use Providentia\SharedKernel\Application\Async\QueueMetricsProbe;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use Providentia\SharedKernel\Application\Health\SyncMetricsProbe;

final class MetricsHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly OutboxStore $outbox,
        private readonly QueueMetricsProbe $queue,
        private readonly SyncMetricsProbe $sync,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $metrics = $this->outbox->metrics();
            $up = 1;
        } catch (Throwable) {
            $metrics = ['pending' => 0, 'failed' => 0, 'oldest_pending_seconds' => 0.0];
            $up = 0;
        }
        $queue = $this->queue->measure();
        try {
            $sync = $this->sync->metrics();
        } catch (Throwable) {
            $sync = [
                'operations' => 0,
                'accepted' => 0,
                'conflicts' => 0,
                'tombstones' => 0,
                'changes' => 0,
                'cursors' => 0,
            ];
        }

        $lines = [
            '# HELP providentia_metrics_up Whether the metrics dependencies are queryable.',
            '# TYPE providentia_metrics_up gauge',
            'providentia_metrics_up ' . $up,
            '# HELP providentia_queue_up Whether broker queue depth is queryable.',
            '# TYPE providentia_queue_up gauge',
            'providentia_queue_up ' . $queue['up'],
            '# HELP providentia_queue_depth Messages currently waiting in the configured broker queue.',
            '# TYPE providentia_queue_depth gauge',
            'providentia_queue_depth ' . $queue['depth'],
            '# HELP providentia_outbox_pending Messages waiting for broker publication.',
            '# TYPE providentia_outbox_pending gauge',
            'providentia_outbox_pending ' . $metrics['pending'],
            '# HELP providentia_outbox_failed Messages moved to persistent failed review.',
            '# TYPE providentia_outbox_failed gauge',
            'providentia_outbox_failed ' . $metrics['failed'],
            '# HELP providentia_outbox_oldest_pending_seconds Age of the oldest unpublished event.',
            '# TYPE providentia_outbox_oldest_pending_seconds gauge',
            'providentia_outbox_oldest_pending_seconds ' . $metrics['oldest_pending_seconds'],
            '# HELP providentia_sync_operations_total Persisted synchronization operation receipts.',
            '# TYPE providentia_sync_operations_total gauge',
            'providentia_sync_operations_total ' . $sync['operations'],
            '# HELP providentia_sync_accepted_total Accepted synchronization operations.',
            '# TYPE providentia_sync_accepted_total gauge',
            'providentia_sync_accepted_total ' . $sync['accepted'],
            '# HELP providentia_sync_conflicts_total Synchronization conflicts.',
            '# TYPE providentia_sync_conflicts_total gauge',
            'providentia_sync_conflicts_total ' . $sync['conflicts'],
            '# HELP providentia_sync_tombstones Current retained synchronization tombstones.',
            '# TYPE providentia_sync_tombstones gauge',
            'providentia_sync_tombstones ' . $sync['tombstones'],
            '# HELP providentia_sync_changes_total Home change-log rows.',
            '# TYPE providentia_sync_changes_total gauge',
            'providentia_sync_changes_total ' . $sync['changes'],
            '# HELP providentia_sync_cursors Current device/home cursor observations.',
            '# TYPE providentia_sync_cursors gauge',
            'providentia_sync_cursors ' . $sync['cursors'],
            '',
        ];

        return new TextResponse(
            implode("\n", $lines),
            200,
            ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8'],
        );
    }
}
