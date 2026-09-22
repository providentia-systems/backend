#!/usr/bin/env bash

set -Eeuo pipefail

readonly root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly archive="$root/contracts/source/providentia-v1.json.gz"
readonly output="$root/contracts/openapi/providentia-v1.json"
readonly archive_sha256='d20ba3f9b769b5e30e59f38ecb83816ff6825a9bb646509440fb731cfc012ff1'
readonly output_sha256='ef5714a6298326d6fb449b966117e8b61c74de67d1bfc274ad8ec431aecd802d'

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
  if (contract.info?.version !== "2.2.0"
      || Object.keys(contract.paths ?? {}).length !== 194
      || operations !== 235
      || Object.keys(contract.components?.schemas ?? {}).length !== 285
      || contract.paths?.["/api/v1/auth/email-codes/verify"]?.post?.operationId
          !== "verifyEmailCode"
      || contract.components?.schemas?.AiExtraction?.properties?.schemaVersion?.enum?.[0] !== 2
      || contract.components?.schemas?.RecordCountRequest?.properties?.expectedRevision?.minimum !== 0
      || contract.paths?.["/api/v1/homes/{homeId}/stock-count-sessions/{sessionId}/lines/{lineId}"]
          ?.put?.responses?.["200"]?.content?.["application/json"]?.schema?.$ref
          !== "#/components/schemas/StockCountLine") {
    throw new Error("The materialized OpenAPI document is not complete Providentia API 2.2.0.");
  }
' "$temporary"

mv "$temporary" "$output"
trap - EXIT
echo 'Materialized Providentia API 2.2.0 contract.'
