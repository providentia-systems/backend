#!/usr/bin/env bash

set -Eeuo pipefail

readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly PACKAGE_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd -P)"

readonly REQUIRED_FILES=(
	"README.md"
	"docs/evidence/01-source-evidence-audit.md"
	"docs/evidence/02-feature-parity-matrix.md"
	"docs/evidence/03-data-dictionary.md"
	"docs/evidence/04-media-classification-quarantine.md"
	"docs/evidence/05-device-data-cutover.md"
	"docs/architecture/01-target-data-model.md"
	"docs/architecture/02-api-resource-outline.md"
	"docs/architecture/03-synchronization-protocol.md"
	"docs/architecture/04-openapi-domain-outline.md"
	"docs/architecture/05-architecture-decisions.md"
	"docs/migration/01-import-reconciliation-specification.md"
	"docs/security/01-threat-model.md"
	"docs/security/02-authorization-test-matrix.md"
	"docs/platform/01-support-packaging-matrix.md"
	"docs/decisions/01-phase1-blockers.md"
	"docs/decisions/02-providentia-naming-decision.md"
	"docs/operations/01-phase0-risk-register.md"
	"docs/phase0-acceptance-report.md"
)

fail()
{
	printf 'FAIL: %s\n' "$1" >&2
	exit 1
}

require_pattern()
{
	local pattern="$1"
	local description="$2"

	if ! rg --quiet --glob '*.md' -- "${pattern}" "${PACKAGE_ROOT}"; then
		fail "Missing required coverage: ${description}"
	fi

	printf 'PASS: %s\n' "${description}"
}

require_in_file()
{
	local relative_path="$1"
	local pattern="$2"
	local description="$3"

	if ! rg --quiet -- "${pattern}" "${PACKAGE_ROOT}/${relative_path}"; then
		fail "Missing required coverage in ${relative_path}: ${description}"
	fi

	printf 'PASS: %s\n' "${description}"
}

readonly PACKAGE_BASENAME="$(basename "${PACKAGE_ROOT}")"
readonly PACKAGE_PARENT_BASENAME="$(basename "$(dirname "${PACKAGE_ROOT}")")"

if [[ "${PACKAGE_BASENAME}" != 'providentia-phase0' ]] \
	&& ! [[ "${PACKAGE_BASENAME}" == 'phase0' && "${PACKAGE_PARENT_BASENAME}" == 'docs' ]]; then
	fail "Package root must be providentia-phase0 or an embedded docs/phase0 copy"
fi

for relative_path in "${REQUIRED_FILES[@]}"; do
	absolute_path="${PACKAGE_ROOT}/${relative_path}"
	[[ -s "${absolute_path}" ]] || fail "Missing or empty file: ${relative_path}"
done

printf 'PASS: all %d required deliverables exist and are non-empty\n' \
	"${#REQUIRED_FILES[@]}"

if rg --line-number --glob '*.md' -- '(TODO|TBD|FIXME|lorem ipsum)' "${PACKAGE_ROOT}"; then
	fail 'Unresolved draft marker found'
fi

printf 'PASS: no unresolved draft markers found\n'

if rg --line-number --glob '*.md' -- \
	'(ZIP (is|was) unavailable|handover ZIP unavailable|re-upload the handover|BLOCKED UNTIL ZIP|NO HANDOVER MEDIA AVAILABLE|SOURCE VERIFICATION BLOCKED)' \
	"${PACKAGE_ROOT}"; then
	fail 'Stale missing-handover statement found'
fi

printf 'PASS: no stale missing-handover statements found\n'

if rg --line-number --glob '*.md' -- \
	'(stockhome-laminas|stockhome-flutter|urn:stockhome:|stockhome_session|stockhome-device-export|STOCKHOME_)' \
	"${PACKAGE_ROOT}"; then
	fail 'Unintended former project identifier found'
fi

printf 'PASS: no former repository/namespace/resource identifiers found\n'

readonly AUDIT='docs/evidence/01-source-evidence-audit.md'
readonly PARITY='docs/evidence/02-feature-parity-matrix.md'
readonly DICTIONARY='docs/evidence/03-data-dictionary.md'
readonly MEDIA='docs/evidence/04-media-classification-quarantine.md'
readonly DEVICE_CUTOVER='docs/evidence/05-device-data-cutover.md'
readonly MIGRATION='docs/migration/01-import-reconciliation-specification.md'
readonly ACCEPTANCE='docs/phase0-acceptance-report.md'
readonly NAMING='docs/decisions/02-providentia-naming-decision.md'

require_in_file "${AUDIT}" 'DIRECTLY VERIFIED — PHASE 0 EVIDENCE GATE PASSED' \
	'closed evidence gate'
require_in_file "${AUDIT}" \
	'c96abfc9a7cdde11da2f2484e2103101ff1044c668ce5842fc350737f9375641' \
	'outer handover SHA-256'
require_in_file "${AUDIT}" '159/159 digests passed' \
	'159-file checksum verification'
require_in_file "${AUDIT}" \
	'b01b5ef14783b4ad1c1bfc0be7ba0dba32629af8' \
	'verified source commit'

readonly BASELINE_PATTERNS=(
	'Product-and-pack entries in current item master [|] 292 [|] 292'
	'Current counted stock lines [|] 60 [|] 60'
	'Recent receipt-derived purchase lines [|] 16 [|] 16'
	'Historical shopping lines [|] 452 [|] 452'
	'Monthly-purchase summary rows [|] 261 [|] 261'
	'Hidden alias groups [|] 13 [|] 13'
	'Individual hidden aliases [|] 19 [|] 19'
	'Product-identity rules [|] 19 [|] 19'
	'Unresolved current-stock descriptions [|] 8 [|] 8'
	'Sum of current quantities [|] 159 [|] 159'
	'legacy `quantity <= 2` rule [|] 44 [|] 44'
)

for pattern in "${BASELINE_PATTERNS[@]}"; do
	require_in_file "${AUDIT}" "${pattern}" "reproduced baseline: ${pattern}"
done

require_in_file "${PARITY}" 'SOURCE-VERIFIED: 44/60' \
	'legacy 44-of-60 source parity'
require_in_file "${MIGRATION}" '44 of the 60 stock' \
	'legacy low-stock migration gate'

readonly UNRESOLVED_DESCRIPTIONS=(
	'Elbow Macaroni'
	'Elbow Pasta'
	'Tea'
	'Candi Soda'
	'Washing Powder - Sunlight'
	'Washing Powder - Bio Classic'
	'Insect Spray - Doom'
	'Trotters Jelly'
)

for description in "${UNRESOLVED_DESCRIPTIONS[@]}"; do
	require_in_file "${DICTIONARY}" "^[|] ${description//-/\\-} [|]" \
		"protected unresolved description: ${description}"
done

readonly LOCAL_STORAGE_KEYS=(
	'pantry-counts'
	'pantry-receipts'
	'pantry-stock-photos'
	'pantry-manual-list'
	'pantry-list-checks'
)

for storage_key in "${LOCAL_STORAGE_KEYS[@]}"; do
	require_in_file "${DEVICE_CUTOVER}" "^[|] \`${storage_key}\` [|]" \
		"browser-local recovery key: ${storage_key}"
done

identity_rule_count="$(
	rg --count-matches '^[|] ([1-9]|1[0-9]) [|]' \
		"${PACKAGE_ROOT}/${DICTIONARY}"
)"
[[ "${identity_rule_count}" == '19' ]] \
	|| fail "Expected exactly 19 identity-rule rows; found ${identity_rule_count}"
printf 'PASS: exactly 19 identity-rule rows\n'

jpeg_ledger_count="$(
	rg --count-matches '^[|] `[A-F0-9-]+\.jpeg` [|]' \
		"${PACKAGE_ROOT}/${MEDIA}"
)"
[[ "${jpeg_ledger_count}" == '26' ]] \
	|| fail "Expected exactly 26 JPEG ledger rows; found ${jpeg_ledger_count}"
printf 'PASS: exactly 26 JPEG ledger rows\n'

png_ledger_count="$(
	rg --count-matches '^[|] `[^`]+\.png` [|]' \
		"${PACKAGE_ROOT}/${MEDIA}"
)"
[[ "${png_ledger_count}" == '4' ]] \
	|| fail "Expected exactly 4 PNG ledger rows; found ${png_ledger_count}"
printf 'PASS: exactly 4 PNG ledger rows\n'

readonly MEDICAL_FILENAMES=(
	'18921C1A-73F4-4AD7-BAF6-47C23CBD8B8E.jpeg'
	'AA3872D9-34D5-422E-944F-F959EC6C3EA0.jpeg'
	'B2D90CCA-5E4A-4106-90CB-130E2775828A.jpeg'
	'EDD62B42-0883-4135-9481-397056901256.jpeg'
)

for filename in "${MEDICAL_FILENAMES[@]}"; do
	require_in_file "${MEDIA}" "${filename}" \
		"restricted medical filename record: ${filename}"
done

require_in_file "${MEDIA}" 'Pages 1–9' 'bounded PDF lineage'
require_in_file "${MEDIA}" 'Page 586' 'PDF error-page verification'
require_in_file "${NAMING}" \
	'vast-development-method/providentia-laminas' \
	'authoritative backend repository'
require_in_file "${NAMING}" \
	'vast-development-method/providentia-flutter' \
	'authoritative Flutter repository'
require_in_file "${ACCEPTANCE}" \
	'ACCEPTED — Phase 1 authorized' \
	'owner-approved Phase 1 gate'

require_pattern 'Providentia' 'official project name'
require_pattern 'ServiceManager' 'explicit ServiceManager factory architecture'
require_pattern 'Mezzio' 'Mezzio and Laminas architecture'
require_pattern 'Doctrine' 'Doctrine persistence strategy'
require_pattern 'Redis.*Valkey|Valkey.*Redis' 'Redis and Valkey queue profiles'
require_pattern 'transactional outbox' 'transactional outbox semantics'
require_pattern 'cross-home|Cross-home' 'cross-home isolation'
require_pattern 'UUIDv7' 'client/server identifier strategy'
require_pattern 'Problem Details' 'API Problem Details contract'
require_pattern 'tombstone' 'synchronization tombstones'
require_pattern 'SSRF' 'AI endpoint SSRF threat'
require_pattern '20\.04.*24\.04' 'verified Ubuntu support range'
require_pattern '26\.04.*erratum|erratum.*26\.04' 'V1 Ubuntu erratum'

while IFS= read -r markdown_file; do
	if [[ "$(head -n 1 "${markdown_file}")" != '# '* ]]; then
		fail "Markdown file lacks a leading H1: ${markdown_file#${PACKAGE_ROOT}/}"
	fi
done < <(find "${PACKAGE_ROOT}" -type f -name '*.md' -print | sort)

printf 'PASS: every Markdown document begins with an H1\n'

export PACKAGE_ROOT
python3 - <<'PY'
import os
import pathlib
import re

root = pathlib.Path(os.environ["PACKAGE_ROOT"])
link_pattern = re.compile(r"\[[^\]]+\]\(([^)]+)\)")

for markdown in sorted(root.rglob("*.md")):
    text = markdown.read_text(encoding="utf-8")
    if text.count("```") % 2:
        raise SystemExit(f"FAIL: unbalanced fenced code blocks in {markdown}")
    prose = re.sub(r"```.*?```", "", text, flags=re.DOTALL)
    for target in link_pattern.findall(prose):
        if "://" in target or target.startswith(("#", "mailto:", "urn:")):
            continue
        path_text = target.split("#", 1)[0]
        if not path_text:
            continue
        resolved = (markdown.parent / path_text).resolve()
        if not resolved.exists():
            raise SystemExit(
                f"FAIL: broken local link in {markdown}: {target}"
            )

print("PASS: fenced code blocks are balanced")
print("PASS: local Markdown links resolve")
PY

printf 'PASS: Providentia Phase 0 package validation completed\n'
