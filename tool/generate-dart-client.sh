#!/usr/bin/env bash
set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
output_dir="${1:-${repo_root}/var/generated/providentia_api}"
generator_image="openapitools/openapi-generator-cli:v7.15.0"

mkdir -p "$(dirname "$output_dir")"
rm -rf "$output_dir"

docker run --rm \
  --user "$(id -u):$(id -g)" \
  --volume "${repo_root}:/workspace:ro" \
  --volume "$(dirname "$output_dir"):/output" \
  "$generator_image" generate \
  --input-spec /workspace/contracts/openapi/providentia-v1.json \
  --generator-name dart-dio \
  --config /workspace/tool/openapi-generator-config.json \
  --output "/output/$(basename "$output_dir")" \
  --global-property apiDocs=false,modelDocs=false

test -f "$output_dir/pubspec.yaml"
test -f "$output_dir/lib/api.dart"
printf 'Generated Dart API client at %s\n' "$output_dir"

