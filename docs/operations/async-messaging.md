# Asynchronous messaging operations

Domain and application code use `AsyncMessageBus`. Enqueue and the
Redis-compatible context exist only in Infrastructure.

## Delivery sequence

1. The use case persists its aggregate and outbox envelope in one Doctrine
   transaction.
2. `outbox:relay` conditionally claims pending rows and publishes them.
3. A successful publish marks the outbox row published.
4. A publish failure applies bounded exponential delay.
5. Exhausted publication attempts enter `async_failed_messages`.
6. `queue:consume` validates the envelope and records the message ID within its
   handler transaction before broker acknowledgement.
7. Duplicate delivery is acknowledged without repeating handler work.
8. Invalid or unregistered messages enter persistent failed review.

Commands:

```bash
php bin/providentia outbox:relay
php bin/providentia queue:consume
```

Run both as separately supervised long-lived processes. Graceful process
supervision, alert routing, and the operational failed-message review UI are
deployment/Phase 7 work. The metrics endpoint provides outbox pending
depth, failure count, oldest pending age, and metrics dependency status.

Never delete a failed record to simulate recovery. Resolve it through a future
audited replay or dismissal operation.
