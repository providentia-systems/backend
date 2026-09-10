# AI bring-your-own-key setup and acceptance

This runbook configures **Secure server AI** after the ordinary production
backend, household Client and Admin flows pass. It covers the currently
implemented providers: OpenAI, Anthropic, Gemini, xAI, OpenAI-compatible and
Ollama.

Providentia does not provide or fund a model account in this release. The
person or home supplies the provider credential (BYOK), selects the provider
and model, confirms transmission, and reviews every proposal. Provider charges,
terms, regional availability, model capabilities and retention policies remain
the account owner's responsibility.

## BYOK and platform-funded permissions are different

Home features and permissions remain the server-side authority:

| Permission | Current purpose |
|---|---|
| `ai.read` | Read the home's AI settings, profiles and review results |
| `ai.use` | Request an extraction after the disclosure and consent step |
| `ai.manage` | Manage settings, profiles and orchestration policy |
| `ai.credentials.use` | Enter and use household-managed BYOK credentials |
| `ai.platform.use` | Reserved for a future platform-funded route; disabled in the starter home policy |

Current Secure server AI requires the applicable AI permission **and**
`ai.credentials.use`. Enabling `ai.platform.use` does not fund requests and does
not replace BYOK. Paid platform AI and production billing enforcement remain
disabled. Home owners may share a profile with the home; managers with the
applicable permissions may manage their own private profile, but cannot turn it
into a home-shared credential.

## 1. Enable the server proxy without replacing keys

The production preparation helper already generated an independent
`AI_CREDENTIAL_KEK` and the media encryption keys in the protected env file.
Do not generate a new env file, replace those keys, or paste them into source
control. Edit the existing file:

```bash
sudoedit /etc/providentia/production.env
```

Set the deployment gate while retaining the generated key:

```dotenv
AI_SERVER_PROXY_ENABLED='1'
AI_CREDENTIAL_KEY_VERSION='1'
AI_ORCHESTRATION_MAX_ATTEMPTS='8'
AI_MAX_IMAGE_BYTES='8388608'
AI_MAX_IMAGES='8'
```

From the active release directory, validate and redeploy all roles:

```bash
bash scripts/setup-production.sh \
  --env-file /etc/providentia/production.env
```

No provider API key belongs in the server env file. People enter provider
credentials in the authenticated household Client; the backend stores
authenticated ciphertext and returns only the final four characters.

Allow outbound DNS/HTTPS only to the cloud endpoints in use. Keep Ollama and
other local endpoints on a segmented network that cannot reach instance
metadata, Docker control sockets, SQL, Redis or unrelated private services.

## 2. Configure one provider profile in the Client

Open the intended home in the household Client, then open **Household AI** →
**Secure server AI** → **Add provider**. Use a vision-capable model identifier
that the selected provider has enabled for the BYOK account. Providentia does
not guess or silently replace model names.

| Client provider | Credential | Endpoint field |
|---|---|---|
| OpenAI (`openai`) | OpenAI API key | Leave empty; backend uses the fixed Responses API endpoint with `store: false` |
| Anthropic (`anthropic`) | Anthropic API key | Leave empty; backend uses the fixed Messages endpoint |
| Gemini (`gemini`) | Google AI/Gemini API key | Leave empty; backend uses the fixed `generateContent` endpoint template |
| xAI (`xai`) | xAI API key | Leave empty; backend uses the fixed Chat Completions endpoint |
| OpenAI-compatible (`openai-compatible`) | Bearer credential, 16–500 characters | Enter the complete public HTTPS request URL, including `/v1/chat/completions` when that server uses the standard path |
| Ollama (`ollama`) | None | Enter the complete Ollama request URL, normally `http://OLLAMA_PRIVATE_IPV4:11434/api/chat`, or configure a deployment fallback |

Choose **Private to me** unless the home owner deliberately chooses **Shared
with this home**. Private profiles are visible and usable only by their owner;
even another home owner cannot address them. A person's active private profile
is preferred over a home-shared profile for the same provider.

Save the profile, select it, choose **Use selected provider**, then choose **Use
single-profile policy** for the first test. More complex policies can add
fallback and validation profiles later, within the attempt/token/cost limits.

### Custom endpoint policies

Only OpenAI-compatible and Ollama profiles accept an endpoint. Public custom
endpoints require HTTPS, no URL credentials/query/fragment, and cannot use a
literal private/loopback/link-local address.

To let a **profile-owned Ollama** endpoint use HTTP and a private/loopback host,
edit the existing env file:

```bash
sudoedit /etc/providentia/production.env
```

Set the separate profile policy and redeploy:

```dotenv
AI_ALLOW_PRIVATE_NETWORK_ENDPOINTS='1'
```

```bash
bash scripts/setup-production.sh \
  --env-file /etc/providentia/production.env
```

This exception applies only to Ollama profile endpoints. It does not permit
private OpenAI-compatible endpoints.

The older deployment-wide fallbacks are optional. `AI_COMPATIBLE_ENDPOINT` is
an HTTPS base to which the backend appends `/v1/chat/completions`;
`AI_OLLAMA_ENDPOINT` is a base to which it appends `/api/chat`. A private
deployment-wide Ollama fallback also requires
`AI_ALLOW_PRIVATE_ENDPOINTS='1'`. Prefer owner-scoped profiles so the endpoint
and credential share the same explicit private/home ownership boundary.

## 3. Understand the media and review boundary

Before a cloud/server extraction, the Client previews/crops selected media,
re-encodes supported images and removes embedded metadata. It displays the
selected provider and current handling policy, then requires explicit
transmission consent.

`transient_not_persisted` means direct extraction media is not added to
Providentia's private-media store. The bytes still leave the device, transit
the Providentia process and network stack, and are sent to the selected
provider. Providentia clears application-owned mutable buffers on success and
failure on a best-effort basis; it cannot promise to erase copies made by PHP,
TLS/HTTP libraries, operating systems, infrastructure or the external provider.
Review the provider's own data policy.

`explicit_encrypted_opt_in` is a separate private-media resource. It stores
authenticated ciphertext only after the user explicitly chooses transient or
retained storage. Retained media, its encryption key and quota-backed files
must be included in backup/restore planning.

Every extraction is a proposal with human review. Accepting an AI candidate
records a review decision; it does not silently mutate stock, purchase or count
revisions. Confirmed facts enter inventory through the normal revisioned,
idempotent receipt/count flow.

Never test with medical documents, private handover media or unrelated personal
images. Use a synthetic receipt or pantry image containing no real personal
information.

## 4. Acceptance test each enabled provider

Repeat this checklist separately for every provider you intend to support:

1. Confirm `https://api.example.net/health/ready` succeeds and all workers are
   running.
2. In the Client, add a private provider profile with the exact provider ID,
   vision-capable model and required credential. For Ollama, first prove the
   model supports image input directly on that server.
3. Reopen the profile list. Confirm provider, model, ownership badge and endpoint
   are correct; only `lastFour`/configured state may identify a stored secret.
4. Select the profile, choose **Use selected provider**, apply the
   single-profile policy, refresh, and confirm the settings revision and active
   provider survived read-back.
5. Start a receipt or open stock-count extraction using only synthetic media.
   Confirm the disclosure names the selected provider and handling mode before
   choosing the transmission-consent action.
6. Confirm the result is `review_required`, the candidate/schema information is
   bounded, and no source-media field or credential is returned.
7. Accept or reject one candidate. Confirm a stale repeat returns a revision
   conflict and no duplicate stock/purchase mutation occurs.
8. Complete the accepted facts through the ordinary reviewed receipt/count
   workflow and verify the expected inventory change exactly once.
9. Replace the credential and confirm only its new final four characters are
   visible. Then revoke it and confirm the profile fails closed until a valid
   credential is supplied. Ollama has no credential to revoke.
10. Inspect bounded application/worker logs and database/support exports. They
    must contain no image bytes, provider response body, plaintext credential,
    nonce or ciphertext.
11. Return the test home to `manual_only` until the provider is approved for
    normal use.

Use the complete Compose command for a bind-mounted production deployment when
checking processes and logs:

```bash
docker compose --env-file /etc/providentia/production.env \
  -f compose.production.yaml -f compose.production.bind.yaml \
  ps
docker compose --env-file /etc/providentia/production.env \
  -f compose.production.yaml -f compose.production.bind.yaml \
  logs --tail=200 api worker ai-video-worker
```

Direct local AI is a different Client mode: the device connects to a verified
LAN Ollama/OpenAI-compatible peer without routing media through this server.
Browser builds fail closed for direct local mode. Do not treat a direct-local
test as acceptance of Secure server AI or its encrypted BYOK storage.
