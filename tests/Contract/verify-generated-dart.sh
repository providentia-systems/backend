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

readonly identity_api="$package/lib/src/api/identity_api.dart"
readonly platform_api="$package/lib/src/api/platform_api.dart"
readonly email_challenge="$package/lib/src/model/email_code_challenge.dart"
readonly email_verification="$package/lib/src/model/email_code_verification.dart"
readonly access_group="$package/lib/src/model/access_group.dart"
require_literal "$identity_api" 'Future<Response<EmailCodeChallenge>> requestEmailCode({'
require_literal "$identity_api" 'required EmailCodeRequest emailCodeRequest,'
require_literal "$identity_api" 'Future<Response<SessionCredentials>> verifyEmailCode({'
require_literal "$identity_api" 'required EmailCodeVerification emailCodeVerification,'
require_literal "$identity_api" 'Future<Response<AccountProfile>> completeAccountOnboarding({'
require_literal "$email_challenge" 'final String challengeId;'
require_literal "$email_challenge" 'final String bindingToken;'
require_literal "$email_verification" 'final String code;'
require_literal "$email_verification" 'final String bindingToken;'
require_literal "$access_group" 'final Map<String, bool> features;'
require_literal "$access_group" 'final Map<String, int> limits;'
require_literal "$platform_api" 'Future<Response<EffectiveAccess>> getAccessAssignment({'
if grep -Eq 'startLoginLink|proveLoginLinkApproval|exchangeLoginLink' "$identity_api"; then
  echo 'Generated Dart identity API retains retired login-link operations.' >&2
  exit 1
fi

echo 'Generated Dart stock, email-code, profile and scoped-access contracts passed.'
