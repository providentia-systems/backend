# Canonical OpenAPI source archive

`providentia-v1.json.gz` is the deterministic (`gzip -n -9`) source archive
for the generated `contracts/openapi/providentia-v1.json` working copy. Run
`bash tool/materialize-openapi-contract.sh` before validation or generation.
The materializer verifies both archive and output SHA-256 digests and parses
the complete JSON document before replacing the working copy atomically.
