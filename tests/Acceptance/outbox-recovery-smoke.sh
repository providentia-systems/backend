#!/usr/bin/env bash
set -Eeuo pipefail

proof="phase-10-recovery-$(date -u +%Y%m%d%H%M%S)-$$"

php bin/doctrine-migrations migrations:migrate --no-interaction
php bin/providentia foundation:prove "$proof"
php bin/providentia outbox:relay --once
php bin/providentia queue:consume --once --timeout=5000
php tests/Acceptance/outbox-recovery.php prepare-redelivery "$proof"
php bin/providentia outbox:relay --once
php bin/providentia queue:consume --once --timeout=5000
php tests/Acceptance/outbox-recovery.php verify "$proof"
