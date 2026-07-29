# Phase 0 risk register

Status: **Active**

Scoring: likelihood and impact are Low, Medium, High, or Critical. A risk
remains open until its closure evidence is attached.

| ID | Risk | Likelihood | Impact | Required control | Closure evidence | State |
|---|---|---:|---:|---|---|---|
| R-001 | Handover package integrity/evidence cannot be reproduced | High | Critical | Verify archive, both manifests, source, workbooks, exports, media, PDF, and bundle | 159/159 checksum/manifest pass plus direct evidence report | **Closed Phase 0** |
| R-002 | Prompt baseline counts are mistaken for reproduced evidence | Medium | High | Independently count JSON/CSV rows and invariants; retain later import reconciliation as a separate gate | Exact 292/60/16/452/261/13/19/19/8, 159-unit, and 44/60 results | **Closed Phase 0; import gate remains later** |
| R-003 | Earlier workbooks overwrite approved consolidation decisions | Medium | High | Enforce source precedence and record decision authority | Conflict tests and source map | Open |
| R-004 | Eight unresolved descriptions are guessed during import | Medium | High | Protected unresolved set and hard matching guard | Import test asserting unresolved state | Open |
| R-005 | Medical leaflets are treated as grocery receipts | Medium | Critical | Byte/visual classification and quarantine before AI/import | 30-file visual ledger and restricted four-filename record | **Mitigated Phase 0; import rejection must be tested** |
| R-006 | The 586-page PDF creates false purchase records | High | High | Use 452-line CSV; restrict PDF to pages 1–9 lineage review | Verified 586 pages and sampled noise/error pages | **Mitigated Phase 0; importer test remains** |
| R-007 | Browser-local operational changes are lost at cutover | High | Critical | Export all five local keys from every relevant profile | Validated device export and staging reconciliation | Open/cutover blocker, **not Phase 1 start blocker** |
| R-008 | Cross-home object access leaks private data | Medium | Critical | Server-derived active home, compound scoping, deny-first policies | Horizontal/vertical/cross-home matrix passing | Open |
| R-009 | Platform/catalog staff gain implicit home access | Medium | Critical | Separate platform roles; time-limited user-granted support only | RBAC and support-grant tests | Open |
| R-010 | Offline retry duplicates stock or purchase movements | Medium | Critical | Client operation IDs, idempotency records, transactional writes | Lost-response and replay tests | Open |
| R-011 | Queue publish fails after database commit | Medium | High | Transactional outbox and idempotent consumer | Commit/publish failure tests | Open |
| R-012 | Queue payload exposes private media or credentials | Low | Critical | Minimal references, payload schema validation, redacted metrics | Payload contract/security tests | Open |
| R-013 | AI output silently mutates inventory | Medium | Critical | Proposal-only extraction and explicit human approval | No-auto-mutation tests | Open |
| R-014 | Cloud privacy copy says images remain on device | Medium | High | Exact mode-specific disclosure and consent | Content review and UI acceptance test | Open |
| R-015 | Provider credentials leak from browser/mobile/logs | Medium | Critical | Proxy vault or OS vault; no web local storage; log redaction | Secret scanning and credential-path tests | Open |
| R-016 | Custom AI endpoint enables SSRF | Medium | Critical | Scheme/host policy, private-address rules, DNS revalidation, egress controls | SSRF test suite | Open |
| R-017 | Catalog proposal leaks home, price, receipt, or image data | Medium | Critical | Explicit sanitizer and publish schema allowlist | Proposal privacy contract tests | Open |
| R-018 | Catalog merge orphans historical records | Low | Critical | Audited reversible relink transaction, no destructive delete | Merge rollback/integrity tests | Open |
| R-019 | SQLite behavior masks MySQL/MariaDB defects | High | High | Three-database CI and portable migrations | Clean/upgrade/down suite on all databases | Open |
| R-020 | MySQL/MariaDB exposed publicly or used without TLS | Low | Critical | Private network/allowlist and verified TLS | Deployment security test | Open |
| R-021 | Unsupported operating systems are promised | Medium | Medium | Publish only official and CI-tested matrix | Release matrix attached to build artifacts | Open |
| R-022 | Ubuntu 26.04 is incorrectly listed as Flutter-supported | High | Medium | Correct matrix to current official 20.04–24.04 range | Official Flutter 3.44.7 page verified 2026-07-29 | **Closed; V1 erratum recorded** |
| R-023 | Dependency or container licensing creates lock-in | Medium | High | Approved OSI allowlist, SBOM, prohibited-license policy | CI licence and SBOM artifacts | Open |
| R-024 | Work crosses a privacy, authentication, catalog-publication, localization, or commercial boundary before its recorded decision deadline | Medium | High | Permit neutral Phase 1 foundations after Phase 0 approval; enforce D-01/D-09/D-10 before Phase 1 acceptance and D-03–D-08/D-11 before their dependent work | Dated decision records linked to affected phase gates | Open |
| R-025 | Empty repositories receive unreviewable scaffolding | Medium | High | Close Phase 0 first; keep backend/Flutter Phase 1 work separately reviewable | Phase 0 approved and two authoritative repositories recorded | **Mitigated; enforce scoped PRs** |
| R-026 | Repository publication fails or targets a former repository name | Medium | High | Use the connected Git workflow and verify repository identity before every write | Commit/PR URLs for `providentia-laminas` and `providentia-flutter` | Open/Phase 1 publication |
| R-027 | Malicious, replayed, or mis-bound invitations grant unintended membership | Medium | Critical | Hashed single-use tokens, expiry, intended-recipient binding, transactional acceptance | Threat-model TM-03 and invitation state tests | Open |
| R-028 | Stolen device or refresh credentials retain access | Medium | Critical | Device-bound rotation, replay detection, session listing/revocation, short access lifetime | Threat-model TM-04 and revoked-device tests | Open |
| R-029 | Alias takeover or unsafe catalog merge redirects identities | Medium | Critical | Moderated revision checks, conflict policy, reversible relink transaction | Threat-model TM-08 and alias/merge authorization tests | Open |
| R-030 | Receipt or label text injects instructions into an AI workflow | High | Critical | Treat media text as untrusted data, strict output schema, proposal-only result, human confirmation | Threat-model TM-11 and prompt-injection tests | Open |
| R-031 | Malicious image/PDF/archive causes parser abuse or decompression exhaustion | Medium | Critical | Signature validation, safe decoder limits, bounded pages/pixels/ratios, isolated processing | Threat-model TM-12 and malicious-media fixtures | Open |
| R-032 | Product-icon upload enables stored content attacks | Medium | High | Decode/re-encode approved raster/vector subset, content-type enforcement, isolated asset host | Threat-model TM-16 and upload/content-security tests | Open |
| R-033 | Backup, export, snapshot, or restore leaks private home data | Medium | Critical | Encryption, least privilege, scoped exports, restore isolation, access audit, tested destruction policy | Threat-model TM-17 and backup/restore security rehearsal | Open |
| R-034 | Offline device or OS/cloud backup exposes cached household data after revocation | Medium | Critical | App-private storage, backup classification/exclusions, encryption evaluation, local purge flows, truthful no-remote-wipe disclosure | Threat-model TM-25 and per-platform local-data tests | Open |
| R-035 | Public Providentia launch proceeds without domain/app-store/trademark due diligence | Medium | High | Treat the owner-selected name as final while retaining legal/domain/store launch gates | Dated due-diligence record and approved identifiers | Open/public-launch blocker |
| R-036 | New namespaces/packages/resources retain the former `StockHome` base | Medium | High | CI prohibited-identifier scan with explicit historical-evidence allowlist | Zero unintended former-name occurrences in generated/release artifacts | Open/Phase 1 |

## Review cadence

Review the register:

- before Phase 1 acceptance;
- at the end of every phase;
- after any privacy, tenancy, migration, or platform-contract change;
- after a security incident or failed restore.

New risks receive an owner, target phase, mitigation, tests, and closure
evidence. Do not close a risk merely because implementation exists.
