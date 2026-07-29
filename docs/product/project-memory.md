# Project memory and controlling decisions

## Owner decision — 29 July 2026

- `Providentia` is the official project name, product name, package base,
  namespace base, contract prefix, deployment prefix, and documentation name.
- `StockHome` identifies only the former React/TypeScript prototype and its
  historical evidence. It is not a production identifier.
- The canonical repositories are:
  - `vast-development-method/providentia-laminas`
  - `vast-development-method/providentia-flutter`
- The backend remains Mezzio plus selectively chosen Laminas components,
  explicit Laminas ServiceManager factories, Doctrine ORM/DBAL/Migrations, and
  a project-owned asynchronous bus with an Enqueue Redis adapter.
- The verified Flutter Linux support range records Ubuntu 20.04 through 24.04
  LTS. Ubuntu 26.04 is not claimed.
- The project is proprietary. No distribution licence is granted or selected
  yet; licensing will be decided before public distribution.
- The permanent application/distribution identifier is
  `com.vastdevelopmentmethod.providentia`.
- MySQL is the preferred production database. MariaDB remains a tested
  compatibility profile and SQLite remains the demonstration/test profile.
- Redis Open Source is the preferred production queue broker. Valkey remains a
  tested Redis-protocol-compatible deployment option.

These decisions supersede older working names without changing the verified
data, workflow, privacy, or architectural evidence.

## Still unresolved

- Pricing, free tier, and operator obligations
- Public domains and launch claims
- Authentication methods beyond the Phase 2 email/password baseline
- Supported offline duration, tombstone retention, and compaction boundary
- Durable transactional delivery and retry policy for account/invitation mail
- First-release media backup and AI privacy defaults

None of these unresolved choices is silently treated as a production decision.
Until retention is approved, sync tombstones are retained without automatic
compaction.
