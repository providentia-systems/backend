# Backend releases and container registries

The release source is
[Production image](https://github.com/providentia-systems/backend/actions/workflows/production-image.yml).
The repository already publishes containers to GitHub Container Registry
(GHCR); a Dockerfile on its own does not publish an image. The workflow builds,
checks and publishes three targets from
[`Dockerfile.production`](../../Dockerfile.production).

## Image addresses and versions

| Image address | Contents |
|---|---|
| `ghcr.io/providentia-systems/backend` | PHP-FPM API, CLI, migrations and ordinary background processes |
| `ghcr.io/providentia-systems/backend-web` | Caddy web server and public files |
| `ghcr.io/providentia-systems/backend-media-worker` | Runtime plus FFmpeg/FFprobe for video jobs |

All three publish Linux `amd64` and `arm64` manifests. Docker selects the
appropriate platform automatically. PHP **8.5** is the supported runtime;
Composer currently requires `~8.5.0`. There is no supported PHP 8.3/8.4 image.
Supporting another PHP series requires compatible dependencies, code and a
passing test matrix before adding release variants.

| Reference | Meaning | Use |
|---|---|---|
| `@sha256:...` | Content-addressed image manifest | Production deployments; use all three values from one release |
| `:0.1.0` | Semantic backend release tag, without `v` | Identifying a release; resolve and keep its recorded digest |
| `:0.1.0-php8.5` | Explicit PHP-series alias for runtime/media image | The same release bytes, not a separate PHP implementation |
| `:latest` | Most recently promoted backend release | Discovery or disposable testing |
| `:edge` | Most recently accepted `main` build | Integration testing |
| `:php8.5` | Most recently promoted PHP 8.5 runtime/media release | PHP-series discovery; mutable |
| `:sha-<12-character-commit>` | Accepted commit alias | Pre-merge testing; pin the resolved digest for exact image bytes |
| `:sha-<40-character-commit>-run-<run-id>-<attempt>` | Unique candidate for a workflow attempt | Investigation; may exist even when scans fail |

`0.1.0` is the first automatically assigned backend version, not a promise that
it has already been published. The
[Releases page](https://github.com/providentia-systems/backend/releases) is the
source for available versions. Before the first release, the release badge may
show no release. Static PHP/platform badges describe supported targets; they do
not assert that a particular workflow run passed.

## Automatic versioning on main

The default branch is **`main`**, not `master`. A successful release run for a
`main` commit:

1. Requires successful **Quality** and **Security** workflows for that same
   commit. A passing check on an older revision is insufficient.
2. Reserves **0.1.0** when no semantic backend release exists; subsequent
   automatic releases increment the patch (`0.1.1`, `0.1.2`, ...).
3. Builds the production targets, checks their non-root/tool boundaries and
   runs migration plus HTTP smoke tests.
4. Publishes candidate manifests, scans both architectures and verifies the
   actual digest-addressed images through Caddy and PHP-FPM.
5. Locks the deployment assets on the draft release, creates the `vX.Y.Z` Git
   tag, promotes the accepted images and publishes the complete GitHub Release.

Merging therefore initiates a release automatically; failed required checks
prevent promotion. A failed run can leave diagnostic candidate images. Version
reservation and rerun handling preserve the selected version and accepted
image digests; an older rerun must not move floating tags backwards. Do not
edit or replace a published release's image set.

For a deliberate minor or major release, set the repository Actions variable
`NEXT_RELEASE_VERSION` to, for example, `v0.2.0` **before merging the next
commit**. The next new main commit uses it when it is newer than every published
or reserved version. Remove the variable afterward for clarity; an already-used
or older hint is ignored and normal patch increments resume. A retry of an
existing commit always retains its reserved version.

You can also run **Production image** manually on `main`, optionally supplying
`release_version`. This explicit input is strict: it must be a new higher
version for a commit without a release, or the exact version already reserved
for that commit. It cannot assign another version to an already-released
commit. Use `NEXT_RELEASE_VERSION` ahead of a merge for routine minor/major
selection. Commit text does not automatically classify breaking changes;
coordinate API compatibility and store clients before choosing those changes.

The backend binary release and the OpenAPI contract have different lifecycles.
The current API contract version is **2.0.0**; it does not make the first
backend deployment release `2.0.0`. Existing API contract publication tags
continue to belong to the Contracts workflow.

## What a release contains

| Release asset | Purpose |
|---|---|
| `providentia-backend-vX.Y.Z.tar.gz` | Deployment files, helpers, docs, env example and image lock in a versioned directory |
| `images.env` | `APP_VERSION` plus the three digest-pinned application image references |
| `release-manifest.json` | Machine-readable release/commit/image coordinates |
| `SHA256SUMS` | Checksums for the downloadable release files |

The archive extracts into `providentia-backend-vX.Y.Z/`. Its own `images.env`
belongs to those deployment files. Keep the archive, manifest, image lock and
configuration version with the deployment record. The workflow also generates
SBOM/provenance attestations and scan/test artifacts; review the run associated
with the release for that evidence.

Creating a release does not deploy or restart your server. Upgrades are an
explicit operator action so you can take a backup, review migrations and choose
the maintenance window. A release archive includes every current deployment
document, including the [server quick start](server-quick-start.md),
[AI BYOK runbook](ai-byok.md) and
[post-release acceptance checklist](post-release-acceptance.md). Follow
[production deployment](production.md) for ongoing operations.

## Pull access

Public GHCR packages can be pulled without authentication. If your package is
private, authenticate on the deployment host using a token with package-read
access and an identity authorized for the package:

```bash
printf '%s' "$GHCR_TOKEN" | docker login ghcr.io \
  --username YOUR_GITHUB_LOGIN --password-stdin
```

Obtain the token from your protected credential mechanism; do not put it in a
Compose file or paste its value into shell history. Image publication uses the
repository's GitHub Actions token and package-write permission. Visibility and
organization policy may still need to be configured by a repository/package
administrator.

## Optional Docker Hub mirror

GHCR is the canonical registry and needs no Docker Hub account. To publish an
additional mirror, configure these repository Actions settings:

| Setting | Type | Example / purpose |
|---|---|---|
| `DOCKERHUB_IMAGE_NAMESPACE` | Variable | `youraccount/providentia-backend` (namespace and repository, no registry prefix) |
| `DOCKERHUB_USERNAME` | Secret | Docker Hub account or automation identity allowed to push those repositories |
| `DOCKERHUB_TOKEN` | Secret | Access token with write access to the configured image repositories |

The mirror uses `docker.io/youraccount/providentia-backend` plus the
`-web` and `-media-worker` suffixes. Configure permissions for all three
repositories. Leaving `DOCKERHUB_IMAGE_NAMESPACE` unset disables the mirror.
No Docker Hub credentials are needed on ordinary GHCR deployments.

Mirror publication copies accepted images; it does not create an untested PHP
variant. If deploying from another registry, retain registry-specific verified
digest references for all three images from the same release. The generated
release lock uses canonical GHCR references.

## Badge meanings

The README keeps its status badges in two compact rows. Release, CI / CLI,
Security and Docker link to live release or `main` workflow results. PR contracts
shows the latest applicable pull-request contract workflow, which runs only
when contract/tool files change. CI / CLI
is the Quality workflow, including application tests, static checks, migrations
and CLI database/queue proofs. It is not a separate invented CLI certification.
PHP, architecture and proprietary-license badges are descriptive links.

Release automation is evidence about a build. SMTP delivery, hostname/TLS
configuration, realistic load, monitoring, backup restoration and controlled
rollback still need to be proven on the intended infrastructure.
