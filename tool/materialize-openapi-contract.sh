#!/usr/bin/env bash

set -Eeuo pipefail

readonly root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly archive="$root/contracts/source/providentia-v1.json.gz"
readonly output="$root/contracts/openapi/providentia-v1.json"
readonly archive_sha256='efd446df4878c1e58c381e2b1a8dead3be55380d389be70e3ff789eead1ec4c0'
readonly output_sha256='7e13d550e7a4438297766f654fadbd1e75894efac989229da6fcd0d9f7f97dda'

sha256_file() {
  sha256sum "$1" | cut -d' ' -f1
}

if [[ "$(sha256_file "$archive")" != "$archive_sha256" ]]; then
  echo 'Pinned backend OpenAPI archive checksum mismatch.' >&2
  exit 1
fi

if [[ -f "$output" && "$(sha256_file "$output")" == "$output_sha256" ]]; then
  exit 0
fi

temporary="$(mktemp "$output.part.XXXXXX")"
trap 'rm -f "$temporary"' EXIT
gzip --decompress --stdout "$archive" > "$temporary"

if [[ "$(sha256_file "$temporary")" != "$output_sha256" ]]; then
  echo 'Materialized backend OpenAPI checksum mismatch.' >&2
  exit 1
fi

node -e '
  const fs = require("node:fs");
  const contract = JSON.parse(fs.readFileSync(process.argv[1], "utf8"));
  const operations = Object.values(contract.paths ?? {}).reduce(
    (count, path) => count + ["get", "post", "put", "patch", "delete"]
      .filter((method) => path?.[method]).length,
    0,
  );
  if (contract.info?.version !== "1.19.0"
      || Object.keys(contract.paths ?? {}).length !== 148
      || operations !== 172
      || Object.keys(contract.components?.schemas ?? {}).length !== 229
      || contract.paths?.["/api/v1/auth/login-links/{requestId}/decision"]?.post?.operationId
          !== "decideLoginLinkApproval"
      || contract.components?.schemas?.AiExtraction?.properties?.schemaVersion?.enum?.[0] !== 2
      || contract.components?.schemas?.RecordCountRequest?.properties?.expectedRevision?.minimum !== 0
      || contract.paths?.["/api/v1/homes/{homeId}/stock-count-sessions/{sessionId}/lines/{lineId}"]
          ?.put?.responses?.["200"]?.content?.["application/json"]?.schema?.$ref
          !== "#/components/schemas/StockCountLine") {
    throw new Error("The materialized OpenAPI document is not complete Providentia API 1.19.0.");
  }
' "$temporary"

mv "$temporary" "$output"
trap - EXIT
echo 'Materialized Providentia API 1.19.0 contract.'
