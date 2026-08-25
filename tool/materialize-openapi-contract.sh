#!/usr/bin/env bash

set -Eeuo pipefail

readonly root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly archive="$root/contracts/source/providentia-v1.json.gz"
readonly output="$root/contracts/openapi/providentia-v1.json"
readonly archive_sha256='b17569f05e8384416498254e882c7e790399a4cde8677439a3efa52c74181d25'
readonly output_sha256='fb7f18cc8d2e0f7aaf3ec9f1bd3039316c6f44af0023110936778a8d616a6759'

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
  if (contract.info?.version !== "1.18.0"
      || Object.keys(contract.paths ?? {}).length !== 154
      || operations !== 177
      || Object.keys(contract.components?.schemas ?? {}).length !== 235
      || contract.paths?.["/api/v1/auth/login-links/{requestId}/decision"]?.post?.operationId
          !== "decideLoginLinkApproval"
      || contract.components?.schemas?.AiExtraction?.properties?.schemaVersion?.enum?.[0] !== 2
      || contract.components?.schemas?.RecordCountRequest?.properties?.expectedRevision?.minimum !== 0
      || contract.paths?.["/api/v1/homes/{homeId}/stock-count-sessions/{sessionId}/lines/{lineId}"]
          ?.put?.responses?.["200"]?.content?.["application/json"]?.schema?.$ref
          !== "#/components/schemas/StockCountLine") {
    throw new Error("The materialized OpenAPI document is not complete Providentia API 1.18.0.");
  }
' "$temporary"

mv "$temporary" "$output"
trap - EXIT
echo 'Materialized Providentia API 1.18.0 contract.'
