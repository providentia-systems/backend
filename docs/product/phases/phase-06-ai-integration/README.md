# Phase 6 — privacy-controlled receipt and stock-photo intelligence

Status: delivered backend capability, extended by the Phase 9/10 encrypted
private-media retention policy.

Phase 6 adds an optional, provider-neutral extraction boundary for receipt and
stock photographs. Providentia remains fully useful in `manual_only` mode.
When an operator enables the server proxy, owners or managers can configure an
OpenAI, OpenAI-compatible, or Ollama provider for a home. Every result is
validated against the same strict schema and remains an untrusted proposal
until a home member records a human decision.

## Delivered surfaces

- Home-scoped, revision-controlled AI settings
- `manual_only`, `server_proxy`, and client-owned `local_direct` privacy modes
- OpenAI Responses, OpenAI-compatible Chat Completions, and Ollama adapters
- XChaCha20-Poly1305 credential encryption with deployment-key separation
- HTTPS/private-endpoint allowlisting, redirect denial, timeouts, and size caps
- Verified JPEG, PNG, and WebP input with explicit transmission consent
- EXIF-bearing input rejection for extraction input
- Explicit transient or retained private-media storage, encrypted before
  object persistence, quota controlled, home scoped, and revision managed
- Strict receipt/stock schema, prompt-injection instruction, and application
  revalidation independent of provider claims
- Sensitive/unrelated document rejection
- Candidate review with optimistic revisions and recorded reviewer identity
- Provider/model, schema/prompt version, digest, duration, and bounded token
  metadata for operational audit
- OpenAPI 1.5 contracts and provider/privacy tests

## Safety boundary

AI output never creates a receipt line, catalog match, price observation,
count line, or stock movement. An accepted candidate records the human
decision only. The client must then submit the chosen product and corrected
values through the normal Phase 5 receipt or count commands. Those commands
retain their own authorization, revisions, validation, and idempotency.

No plaintext image bytes, data URLs, EXIF, provider credentials, hidden
reasoning, or raw provider error bodies are stored in the database, queue,
audit export, or API history. When a household explicitly uploads transient or
retained private media, the object store receives ciphertext; database records
contain bounded metadata and encryption material, and every read is rechecked
against current home permission.

## Reading order

1. [Architecture and interaction flows](architecture-and-flows.md)
2. [Provider configuration and operations](provider-configuration.md)
3. [Local and remote verification](local-and-server-setup.md)
4. [Acceptance checklist](acceptance.md)

Phase 6 does not implement global catalog moderation or predictive shopping.
Those responsibilities remain in Phases 7 and 8.
