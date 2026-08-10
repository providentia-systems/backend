# Phase 6 acceptance checklist

## Functional

- [ ] No provider is available until the deployment gate is enabled.
- [ ] An unconfigured home reports `manual_only`.
- [ ] OpenAI, compatible, and Ollama adapters satisfy the same schema.
- [ ] Receipt output includes header, money, warning, line, pack, product, and
  per-field confidence data.
- [ ] Stock output supports quantity, ambiguity warnings, and bounding regions.
- [ ] Accepted/rejected candidate reviews use optimistic revisions.
- [ ] Review decisions alone do not mutate Phase 5 data.
- [ ] Corrected normal receipt/count commands retain their Phase 5 behavior.

## Privacy and security

- [ ] Explicit transmission consent is required.
- [ ] MIME magic, size, and metadata policy is enforced.
- [ ] Medical/unrelated classifications create no candidates.
- [ ] Plaintext media and data URLs are absent from database rows, queues,
  logs, audit exports, and API history; explicitly retained object data is
  ciphertext with home-bound access control.
- [ ] Transient expiry, retained-media quota, revision-bound retention change,
  deletion, export bounds, and cross-home denial pass.
- [ ] Cloud wording never claims on-device processing.
- [ ] Credentials are authenticated-encrypted with home/provider binding.
- [ ] Ciphertext is unusable after revocation or incorrect key/version.
- [ ] Provider URLs are operator configuration and pass endpoint policy.
- [ ] Public endpoints require HTTPS; redirects and unsafe URL parts fail.
- [ ] Provider errors expose only stable safe codes/details.
- [ ] Prompt instructions treat visible document text as untrusted data.

## Quality and operations

- [ ] OpenAPI route parity and contract digests pass.
- [ ] Provider request/refusal/invalid-output tests use synthetic data.
- [ ] Schema tests cover unexpected fields, sensitive documents, and bounds.
- [ ] Credential and endpoint policy tests pass.
- [ ] PHPStan level 8, PHPCS, PHPUnit, architecture, and migration jobs pass.
- [ ] Migration clean-install, up/down, MySQL, MariaDB, and SQLite profiles pass
  in CI.
- [ ] Remote smoke testing confirms no plaintext media or secret leakage.
- [ ] Operational dashboards expose latency/failure/review counts without
  private payloads.

Live provider tests are opt-in and never run with private handover media. The
four medicine-information images identified in Phase 0 remain quarantined and
must not be sent to any AI provider.
