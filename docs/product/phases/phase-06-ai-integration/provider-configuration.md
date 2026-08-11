# Phase 6 provider configuration and operations

## Deployment gate

AI is disabled twice by default: the deployment has
`AI_SERVER_PROXY_ENABLED=0`, and every home without settings behaves as
`manual_only`. Enabling the deployment gate only makes configured adapters
available; it does not enable AI for a home.

Generate the 32-byte credential-encryption key outside the repository:

```bash
openssl rand -base64 32
```

Inject that value as `AI_CREDENTIAL_KEK` using the environment's secret
manager. Never put a populated key in an image, Compose file, shell history,
support bundle, or committed `.env` file.

## Provider environment

| Provider ID | Environment | Notes |
|---|---|---|
| `openai` | fixed endpoint, no base URL setting | Uses the Responses API and `store: false` |
| `openai-compatible` | `AI_COMPATIBLE_ENDPOINT=https://host/base` | The server appends `/v1/chat/completions` |
| `ollama` | `AI_OLLAMA_ENDPOINT=http://host:11434` | The server appends `/api/chat`; private HTTP also requires `AI_ALLOW_PRIVATE_ENDPOINTS=1` |

Set `AI_SERVER_PROXY_ENABLED=1`, `AI_CREDENTIAL_KEY_VERSION=1`, and an
appropriate `AI_MAX_IMAGE_BYTES` between 1 MiB and 16 MiB. Public provider
endpoints must use HTTPS. Keep private Ollama endpoints on a segmented network
with no route to instance metadata, control planes, databases, or other tenant
services.

The adapters follow the current official provider contracts for
[OpenAI image input](https://developers.openai.com/api/docs/guides/images-vision),
[OpenAI Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs),
[OpenAI Responses](https://developers.openai.com/api/docs/guides/migrate-to-responses),
and [Ollama structured output](https://docs.ollama.com/capabilities/structured-outputs).
Pin and test a vision-capable model in each environment; the server does not
guess a model name.

## Home configuration

An owner or manager performs these steps:

1. `PUT /api/v1/homes/{homeId}/ai/credentials/{providerId}` with the provider
   credential when the adapter requires one.
2. `PUT /api/v1/homes/{homeId}/ai/settings` with `mode`, `provider`, `model`,
   and `expectedRevision`.
3. Read settings back and verify the selected mode/provider/model before a
   client offers transmission.

The settings `mediaHandling` object is the client disclosure source. Direct
extraction is `transient_not_persisted`: it transits the application process
and selected provider after consent but is not added to application media
storage. `explicit_encrypted_opt_in` identifies the separate private-media
resource, which requires a `transient` or `retained` choice and stores
authenticated ciphertext only.

Only the credential's final four characters are returned after entry. Reads
never return ciphertext, nonce, or plaintext. `DELETE` revokes the credential
and overwrites the encrypted fields in the active row.

## Key rotation

The key version is part of every encrypted record and associated data binds
the ciphertext to one home and provider. This release deliberately fails
closed when a stored version differs from the active key version.

Rotation runbook:

1. inventory all active provider credentials by provider/home count without
   exporting ciphertext;
2. schedule a maintenance window or temporarily return homes to
   `manual_only`;
3. have authorized owners/managers re-enter credentials under the new
   deployment key and version;
4. confirm extraction against synthetic fixtures;
5. remove the old key from the secret manager;
6. audit homes still reporting credential rotation required.

Do not increment the version or replace the key before credentials are
re-entered; doing so intentionally makes old ciphertext undecryptable.

## Incident controls

- Revoke a provider credential through the API and rotate it at the provider.
- Set the home to `manual_only` or disable `AI_SERVER_PROXY_ENABLED`.
- Use extraction digests, safe error codes, provider/model, processing time,
  and token counts for investigation.
- Never add provider response bodies or image data to application logs.
- Treat repeated sensitive-document, schema, rate-limit, timeout, or endpoint
  failures as operational signals without exposing household content.
