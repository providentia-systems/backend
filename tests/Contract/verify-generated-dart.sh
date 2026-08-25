#!/usr/bin/env bash

set -Eeuo pipefail

if [[ "$#" -ne 1 ]]; then
  echo 'Usage: verify-generated-dart.sh <generated Dart package>' >&2
  exit 64
fi

readonly package="$1"
readonly inventory_api="$package/lib/src/api/inventory_api.dart"
readonly record_request="$package/lib/src/model/record_count_request.dart"
readonly count_line="$package/lib/src/model/stock_count_line.dart"

require_literal() {
  local file="$1"
  local literal="$2"

  if [[ ! -f "$file" ]] || ! grep -Fq -- "$literal" "$file"; then
    echo "Generated Dart contract is missing: $literal ($file)" >&2
    exit 1
  fi
}

require_literal "$inventory_api" 'Future<Response<StockCountLine>> putStockCountLine({'
require_literal "$inventory_api" 'required RecordCountRequest recordCountRequest,'
require_literal "$record_request" '// minimum: 0'
require_literal "$record_request" 'final int expectedRevision;'
require_literal "$count_line" 'final String id;'
require_literal "$count_line" 'final String homeProductId;'
require_literal "$count_line" 'final String quantity;'
require_literal "$count_line" 'final String status;'
require_literal "$count_line" 'final int revision;'

echo 'Generated Dart stock-count line contract passed.'
