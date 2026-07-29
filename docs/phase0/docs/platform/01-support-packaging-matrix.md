# Supported Platform and Packaging Matrix

**Status:** Phase 0 verified baseline; implementation evidence pending Phase 1

**Verification date:** 2026-07-29

**Architecture baseline:** Flutter for the authenticated application; Mezzio/`laminas-view` for the public site

## 1. Truth and publication rules

1. Publish only platform versions covered by the pinned Flutter toolchain and release testing.
2. “Builds” is not the same as “supported.” A support claim requires an install/launch smoke test and the relevant functional suite on the published baseline.
3. Re-verify the official matrix whenever Flutter stable changes.
4. Other Linux distributions are best-effort community targets unless promoted through CI and release testing.
5. Signing/notarization depends on credentials and release infrastructure. Unsigned development artifacts are not public production packages.
6. The backend runtime and database/broker versions are pinned only after Phase 1 compatibility testing; this document does not invent them.

## 2. Current Flutter deployment baseline

The V1 prompt cites Flutter 3.44.7. Current official documentation was checked for this Phase 0 package against [Flutter supported deployment platforms](https://docs.flutter.dev/reference/supported-platforms), which was updated 2026-07-17.

| Target | Current official deployment baseline | V1 relationship | Phase 1 verification required | Support label |
|---|---|---|---|---|
| Windows | Windows 10 and 11; x64 and Arm64 | Matches V1 | Build, installer, clean-VM install, launch, auth/local DB/sync smoke on every published architecture | First-class target, pending CI evidence |
| macOS | macOS 10.15 Catalina through macOS 26; x64 and Arm64 | Matches V1 | Universal build feasibility, clean install, signing/notarization, Gatekeeper, auth/local DB/sync smoke | First-class target, pending CI evidence |
| Debian Linux | Debian 10 through 13; x64 and Arm64 | Matches V1 | AppImage and `.deb` build/install, dependency declaration, desktop integration, upgrade/uninstall | First-class named Linux baseline, pending CI evidence |
| Ubuntu Linux | Ubuntu 20.04 LTS through 24.04 LTS; x64 and Arm64 | **Corrects V1**, which stated through 26.04 LTS | At least 20.04 and 24.04 deployment smoke; CI runner on documented tested release; do not advertise 26.04 without new official/toolchain evidence | First-class named Linux baseline, pending CI evidence |
| Chrome | Latest two supported releases | Matches V1 | Browser matrix with persisted SQLite/WASM, service worker, auth cookies, offline/reload/update | First-class web target, rolling versions |
| Firefox | Latest two supported releases | Matches V1 | Same web suite; feature differences documented | First-class web target, rolling versions |
| Safari | 15.6 and newer | Matches V1 | Oldest supported Safari plus current Safari; IndexedDB/SQLite/WASM persistence, storage eviction behavior | First-class web target, pending device/browser evidence |
| Edge | Latest two supported releases | Matches V1 | Same web suite and Windows integration | First-class web target, rolling versions |
| Android | API 24 through 37 | Matches V1 | Min/target SDK pin, emulator/device matrix, camera/file picker, secure store, offline DB, background restrictions, app bundle/test APK | Architecture target; explicit first-class release confirmation requested |
| iOS | iOS 13 through 26 | Matches V1 | Min deployment pin, simulator/device matrix, camera/file picker, Keychain, offline DB, background restrictions, signed archive when credentials exist | Architecture target; explicit first-class release confirmation requested |

### Corrected V1 claim

The current official Flutter page lists Ubuntu **20.04 LTS through 24.04 LTS**, not 26.04 LTS. The public support contract must use 24.04 as its upper Ubuntu bound until official documentation and the pinned toolchain say otherwise. Flutter's page currently identifies Ubuntu 22.04 as the CI-tested Ubuntu environment; that does not remove the documented 20.04–24.04 deployment range, but it does require our own oldest/newest deployment smoke tests.

## 3. Release packaging matrix

| Surface | Required artifact | Signing/trust requirement | Phase 1 proof | Public release gate |
|---|---|---|---|---|
| Windows x64/Arm64 | Signed installer or MSIX | Organization code-signing certificate; timestamping; protected signing identity | Reproducible build, install/upgrade/uninstall, SmartScreen behavior, per-architecture smoke | Signed artifact, checksum, SBOM/provenance, clean-VM acceptance |
| macOS x64/Arm64 | Universal `.app` where feasible, normally distributed in a signed disk image/package | Apple Developer ID, hardened runtime, notarization, stapling | Universal dependency audit; install/upgrade; Gatekeeper on supported oldest/current macOS | Signed, notarized and stapled artifact when Apple credentials are available |
| Debian/Ubuntu | AppImage and Debian package | Repository/package signing strategy to be selected; artifact checksums always | Install on supported floor/ceiling; desktop file/icon; required libraries; upgrade/uninstall | Published dependencies, checksum/signature, smoke tests |
| Flutter web/PWA | Versioned hosted web build with manifest and service worker | TLS, CSP, Subresource/asset integrity strategy where applicable, immutable asset caching | Chrome/Firefox/Safari/Edge matrix; offline shell; SQLite/WASM persistence; safe upgrade with pending operations | Browser support suite, rollbackable deployment, no private cache leakage |
| Android | Android App Bundle plus test APK | Protected Android upload/app-signing key | API 24/37 and representative device tests; permissions/privacy declarations; upgrade preserving Drift/outbox | Signed AAB, store validation, staged rollout/rollback, release notes |
| iOS | Signed archive/IPA through approved distribution | Apple distribution credentials and provisioning | iOS 13/current representative tests; privacy manifest; upgrade preserving Drift/outbox | Signed archive when credentials exist; TestFlight/store validation |
| Public website | Immutable backend/public-site deployment artifact | TLS; backend/container artifact signing policy | Semantic/accessibility/SEO/security header tests | Production host/domain decision, WCAG 2.2 AA and security gate |
| Backend API/workers | Same immutable application build, separately invoked roles | Signed/pinned container or package; secret injection outside image | Liveness/readiness, graceful worker shutdown, migration compatibility, SBOM | Three-DB and two-broker suites, backup/restore and rollback proof |

## 4. Functional platform acceptance

Every first-class application target must prove:

- installation or hosted launch;
- first-run account flow and secure session storage;
- home switching and server-enforced isolation;
- Drift database creation, migration, persistence, and pending-operation survival across upgrade;
- foreground synchronization and retry status;
- offline operation and process/browser restart recovery;
- file selection and local media metadata;
- camera workflows where hardware/platform supports them;
- explicit AI provider/privacy-mode presentation without embedding keys;
- responsive Fresh Market layouts, visible focus, keyboard use where relevant, screen-reader semantics, text scaling, contrast, and reduced motion;
- deep links and sign-in hand-off;
- update and rollback behavior;
- no secrets or private media in logs/crash output.

Platform limitations must be documented per target. Mobile background sync is best effort and may not be advertised as continuous.

## 5. Backend/supporting platform matrix

| Component | V1 requirement | Current-version status | Phase 1 action before claiming support |
|---|---|---|---|
| PHP | Current stable PHP satisfying published support policy | Official support policy confirms PHP 8.5 active support through 2027 and security support through 2029; the project pin still requires dependency compatibility proof | Prefer PHP 8.5 if the selected stable Mezzio/Laminas/Doctrine/Enqueue set passes; lock Composer and record the tested update horizon |
| Mezzio/Laminas | Current stable compatible releases | Exact versions **unverified/unpinned** | Resolve from official packages and support policies; dependency/architecture/licence tests |
| Doctrine ORM/DBAL/Migrations | Current stable compatible releases | Exact versions **unverified/unpinned** | Prove mapping/migration/transaction compatibility across three DB profiles |
| SQLite server profile | Development/demo/test only | SQLite library/runtime version **unverified/unpinned** | Pin with PHP/container; clean install and migration suite; label non-production |
| MySQL | Supported production release | Exact release **unverified/unpinned** | Choose maintained release after Doctrine/runtime tests; publish tested versions |
| MariaDB | Supported production release | Exact release **unverified/unpinned** | Choose maintained release after Doctrine/runtime tests; publish tested versions |
| Redis Open Source | Supported queue-broker profile | Exact open-source release/licence **unverified/unpinned** | Verify licence policy, Enqueue compatibility, persistence/security settings, redelivery tests |
| Valkey | Supported Redis-protocol queue-broker profile | Exact release **unverified/unpinned** | Verify Enqueue/protocol behavior, persistence/security settings, redelivery tests |
| Drift/SQLite client | All Flutter targets including web persistence | Exact Drift/sqlite/WASM versions and target behavior **unverified/unpinned** | Pin and run migration/persistence tests per target/browser |
| Reverse proxy/TLS | Reproducible deployment profile | Implementation/version **not selected** | Select open-source component, pin image, test TLS/headers/readiness/streaming |
| S3-compatible storage | Public assets; optional private media later | Product/version **not selected** | Define interface, compatibility contract, encryption and retention; no first-release private media without approval |

No version marked unverified above may appear as a public support promise.

## 6. Deployment-profile support

| Profile | Intended use | V1 delivery status | Support claim gate |
|---|---|---|---|
| External MySQL/MariaDB | Production and scalable self-hosting | Required | TLS/private networking, secret-file/manager use, migrations, backups, restore, failover/restart tests |
| Self-contained MySQL or MariaDB | Small self-hosted production | Required optional bundled profile | Persistent volumes, health checks, non-root app credentials, backup/restore, upgrade and external-DB migration path |
| Server SQLite | Local demonstration and automated tests | Required | Non-clustered/non-high-volume label, file permissions, backup and migration-to-production documentation |
| Redis Open Source broker | Queue deployment option | Required compatible profile | Auth/private network, persistence/restart, Enqueue contract, redelivery/dead-letter/metrics |
| Valkey broker | Queue deployment option | Required compatible profile | Same behavioral suite as Redis; no domain/application change |

The user still needs to select which production database path and broker profile are recommended defaults. The architecture can and should keep all V1-required profiles functional.

## 7. CI and release evidence matrix

| Evidence | Pull request | Release candidate | Public release |
|---|---:|---:|---:|
| Static analysis, formatting, architecture checks | Required | Required | Required |
| Unit/widget/golden/accessibility tests | Required | Required | Required |
| SQLite/MySQL/MariaDB integration | Required | Required | Required |
| Redis/Valkey queue/outbox contract | Required | Required | Required |
| Flutter target builds | Required for approved targets | Required | Required |
| Clean install/launch smoke | Representative | Full published matrix | Full published matrix |
| Upgrade with pending Drift operations | Required fixtures | Full target matrix | Full target matrix |
| Installer signing/notarization | Not for ordinary PRs | Required rehearsal | Required where credentials available |
| SBOM, licence and vulnerability reports | Required generation/check | Final artifacts | Published/retained |
| Backup and restore | Smoke | Full rehearsal | Passing evidence |
| Performance/load/security review | Targeted | Full release gate | Passing evidence |

## 8. Open decisions and limitations

- Android and iOS are locked architecture targets in V1, but their first-class release status is explicitly requested for user confirmation before Phase 1 is closed.
- Apple and Windows signing credentials are not evidenced. Packaging can be implemented and tested with development identities, but production signing cannot be claimed until credentials and protected release workflows exist.
- The official name is `Providentia`; final public hostnames still require
  Providentia domain acquisition, security, and legal due diligence.
- App-store availability, pricing, legal text, update channels, telemetry/crash reporting, and end-of-support policy are not decided.
- Hardware-specific support such as camera quality, biometric authentication, scanners, and background execution requires later device matrices.
- The handover evidence gate has passed. Platform build/install claims remain
  unavailable until Phase 1 creates and tests the relevant artifacts.
