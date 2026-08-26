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
| `openai-compatible` | optional `AI_COMPATIBLE_ENDPOINT=https://host/base` | The server appends `/v1/chat/completions`; the deployment endpoint is only a legacy fallback — provider profiles own their endpoints |
| `ollama` | optional `AI_OLLAMA_ENDPOINT=http://host:11434` | The server appends `/api/chat`; a private deployment endpoint also requires `AI_ALLOW_PRIVATE_ENDPOINTS=1` |

Set `AI_SERVER_PROXY_ENABLED=1`, `AI_CREDENTIAL_KEY_VERSION=1`, and an
appropriate `AI_MAX_IMAGE_BYTES` between 1 MiB and 16 MiB. Public provider
endpoints must use HTTPS. Keep private Ollama endpoints on a segmented network
with no route to instance metadata, control planes, databases, or other tenant
services.

## Person-scoped provider profiles and owned endpoints

Provider profiles are person-scoped by default. Every profile carries an
`ownerScope`:

- `private` (the default) stores the profile for the requesting person only.
  Any member the `ai.manage` permission admits creates, updates, and deletes
  their own private profiles; nobody else — not even the home owner — can see
  or address them (listings omit them and direct access answers 404).
- `home` deliberately shares the profile with the home. Creating, updating, or
  deleting a home-shared profile requires the home-owner role: sharing is an
  explicit owner choice, never inferred from storage scope.

Scans prefer the requesting person's own active private profile over a
home-shared one for the same provider, so each person's images run on their
own key by default. Each encrypted profile credential is bound to
`providentia-ai-profile:v2:{homeId}:{ownerUserId|home}:{profileId}` associated
data; changing a profile's provider or owner scope therefore requires
re-entering the credential.

Profiles for the `openai-compatible` and `ollama` providers may own a custom
`endpoint` (at the same scope as the credential; every other provider rejects
the field). Write-time validation requires an absolute HTTPS URL without
userinfo, query, or fragment, and always rejects literal private, loopback,
and link-local hosts; the same rule is re-asserted before every request. The
deliberately separate LAN policy `AI_ALLOW_PRIVATE_NETWORK_ENDPOINTS=1`
(default `0`) additionally lets **Ollama** profile endpoints use plain HTTP
and private or loopback hosts — keep such endpoints on a segmented network as
described above.

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

For direct extraction, request-owned upload streams are closed and the
mutable upload, observation, provider-request, and decrypted-credential
variables owned by the application are erased in `finally` paths after both
successful and failed processing. This is a best-effort process-memory
boundary: PHP, the HTTP/TLS stack, extensions, and the selected provider may
create copies outside those variables, so Providentia does not claim that
every engine-, transport-, or provider-owned copy can be zeroized.

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
