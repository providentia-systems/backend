<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Doctrine;

use Closure;
use Doctrine\DBAL\Connection;
use PDO;
use Throwable;

/** Keeps native SQLite and DBAL transaction state aligned after a failed COMMIT. */
final class SqliteConnection extends Connection
{
    public function commit(): void
    {
        $outermost = $this->getTransactionNestingLevel() === 1;
        try {
            parent::commit();
        } catch (Throwable $error) {
            if ($outermost && ! $this->isTransactionActive()) {
                // SQLite retains the transaction when COMMIT is busy, whereas
                // DBAL 4.4 has already decremented its nesting level to zero.
                // A failure record must never be written inside that transaction
                // and then mistaken for a durable reason to acknowledge a job.
                try {
                    $native = $this->getNativeConnection();
                    if ($native instanceof PDO && $native->inTransaction()) {
                        $native->rollBack();
                    }
                } catch (Throwable) {
                    // Discard the connection even if native cleanup fails.
                    $this->close();
                }
            }
            throw $error;
        }
    }

    /**
     * @template T
     * @param Closure(Connection): T $func
     * @return T
     */
    public function transactional(Closure $func): mixed
    {
        if ($this->isTransactionActive()) {
            return parent::transactional($func);
        }
        $this->beginTransaction();
        try {
            $result = $func($this);
            $this->commit();
            return $result;
        } catch (Throwable $error) {
            // A failed outer commit has already cleaned up. DBAL's unconditional
            // rollback here would hide the retryable failure as NoActiveTransaction.
            if ($this->isTransactionActive()) {
                try {
                    $this->rollBack();
                } catch (Throwable) {
                    $this->close();
                }
            }
            throw $error;
        }
    }
}
