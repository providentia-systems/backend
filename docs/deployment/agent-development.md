# Reproducible agent development environment

The backend must be testable by a new human or automated contributor without
guessing its PHP extensions, service versions, or permitted package hosts. The
canonical Linux entry point is:

```bash
bash tools/agent-setup.sh
source .agent-env
```

The setup is idempotent. On a clean Ubuntu host it installs only missing host
prerequisites first, then downloads the project-local pinned Node.js validator
and verifies its published SHA-256 before any Node-based contract check runs.
It then verifies all checked-in pins, pulls the declared service images, builds
a dedicated PHP 8.5 development image with Composer and Xdebug, and writes
non-secret session variables (including the local Node.js path) to the ignored
`.agent-env` file. It does not import a handover, create an account, reset
databases, or enable AI.

`tools/agent-requirements.json` is the machine-readable source for PHP,
Composer, extensions, Node.js archive/checksum, Linux packages, container
images, required network endpoints, and validation commands. Changes to a runtime pin must update that
manifest, the corresponding Dockerfiles, lockfiles when applicable, and the
structural assertions together.

## Network policy

Cloud development must allow HTTPS and DNS access to every hostname in the
manifest before setup begins. They cover Ubuntu packages, the pinned archive
at `nodejs.org`, GitHub source
archives, Packagist/PECL packages, Docker Hub/GHCR images, and the pinned Go
modules used by the production web-image build. The application, databases,
Redis/Valkey, Mailpit, and test brokers communicate only on local container
networks; their database and broker ports are not public deployment ports.

## Complete validation lane

Run the same full lane before handing work over:

```bash
bash tools/agent-setup.sh --doctor
```

The doctor executes structural and contract checks, coding standards, static
analysis, PHPUnit, coverage ratchets, mutation testing, the locked dependency
audit, Compose-model validation, the complete local compatibility matrix, and
source plus production runtime/media/web image builds. The compatibility
matrix creates an isolated Docker network, proves migration rollback/reapply,
authentication, and tests on the pinned MySQL and MariaDB images, and proves
idempotent outbox recovery against both the pinned Redis and Valkey images.
SQLite migration rollback/reapply is covered in the same lane. Containers and
the temporary network are removed on success or failure. GitHub Actions reruns
these same compatibility boundaries independently before merge.

To execute only that reproducible service matrix after a code or migration
change, run its first-class command:

```bash
bash tools/agent-setup.sh --matrix
```

`--matrix` provisions the pinned agent image before running the cases; it does
not depend on a developer's host PHP, Composer, database, or broker versions.

For a fast non-mutating startup check, use:

```bash
bash tools/agent-setup.sh --check
```

A managed environment that prevents package installation or access to a
Docker daemon is not evidence that tests pass. Record the restriction, run the
non-mutating check, and rely on the required clean-run CI jobs before merging.
Do not weaken or skip a repository gate to accommodate a restricted runner.
`--check` never installs or downloads anything: it uses the already-provisioned
project Node.js runtime or a host Node.js version at least 18.19.0, and fails
honestly when neither is available.

## Product development setup

After the contributor toolchain passes, use
[`local-development.md`](local-development.md) for the repeatable application
stack, verified handover import, developer account, Mailpit, and client handoff.
Handover files, generated secrets, household media, and `.agent-env` never
belong in Git.
