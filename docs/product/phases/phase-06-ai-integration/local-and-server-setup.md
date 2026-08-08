# Phase 6 local and remote verification

## Local server-proxy setup

Start from the Phase 5 development setup. Create a protected local override,
enable only the provider under test, and use synthetic or deliberately
redacted images.

For OpenAI:

```bash
openssl rand -base64 32
docker compose --env-file .env.development.local up -d --build

# Run quality tools from a host checkout with Composer development dependencies.
composer check
bash tests/structural/verify.sh
```

The protected environment must supply:

```dotenv
AI_SERVER_PROXY_ENABLED=1
AI_CREDENTIAL_KEK=<32-byte-base64-value>
AI_CREDENTIAL_KEY_VERSION=1
AI_MAX_IMAGE_BYTES=8388608
```

Provider credentials are entered through the authenticated home credential
endpoint, not environment variables. Configure a vision-capable model through
the home settings endpoint.

For a local Ollama server, additionally set:

```dotenv
AI_OLLAMA_ENDPOINT=http://ollama:11434
AI_ALLOW_PRIVATE_ENDPOINTS=1
```

Pull and smoke-test the chosen vision model directly in Ollama before enabling
the home. The ordinary CI suite uses fakes and synthetic fixtures; live
provider and local Ollama tests remain explicit opt-in checks.

## API smoke path

With an authenticated owner/manager token and a disposable home:

1. verify settings return `manual_only` before configuration;
2. enter a credential where required and confirm only `lastFour` returns;
3. enable `server_proxy` with settings revision `0`;
4. submit one metadata-free synthetic JPEG/PNG/WebP using multipart fields
   `kind`, optional `targetId`, `transmissionConsent=true`, and `image`;
5. retrieve the extraction and confirm `review_required`, schema/prompt
   versions, digest, duration, candidates, and no media field;
6. accept or reject a candidate with `expectedRevision=1`;
7. verify a stale repeat returns `409`;
8. verify inventory and receipt/count revisions are unchanged;
9. submit a corrected normal receipt-line or count-line command;
10. revoke the credential and return the home to `manual_only`.

Negative checks must cover oversized input, MIME/magic mismatch, EXIF-bearing
input, missing consent, unrelated/medical classification, invalid JSON,
refusal, timeout, response-size limit, unexpected schema fields, private-host
denial, and unauthorized cross-home IDs.

## Remote deployment

Before traffic:

- run migration `Version20260730000600` once;
- inject the KEK from a secret manager with least-privilege deployment access;
- restrict outbound network access to configured provider hosts;
- keep MySQL, Redis, metrics, and Ollama private;
- enable private endpoints only when required for a segmented self-hosted
  provider;
- set reverse-proxy request limits at or below the configured image cap plus
  multipart overhead;
- configure request timeouts above the adapter bound but below platform
  termination limits;
- alert on safe failure codes, latency, rate limits, and review backlog;
- test backup/restore of structured metadata and encrypted credentials without
  exporting the KEK into the database backup.

Post-deploy, use a disposable staging home and synthetic media. Test one cloud
provider and, when supported, one self-hosted provider. Confirm that logs,
traces, queue records, database rows, support exports, and backups contain no
image bytes or plaintext credentials.
