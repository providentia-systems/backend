# Contracts and release order

`contracts/openapi/providentia-v1.json` is authoritative. Dart request and
response types are generated from it; neither repository maintains a parallel
handwritten network model.

Every contract file has a SHA-256 lock manifest. Changing bytes requires:

1. Update the backend implementation and OpenAPI.
2. Run contract validation and the generated Dart client proof.
3. Review semantic compatibility.
4. Tag and publish the backend contract.
5. Update the Flutter repository's pinned lock and regenerate.
6. Release compatible Flutter clients before removing any deprecated server
   behavior.

Phase 1 introduced liveness, readiness, safe system information, and
operational metrics. The current additive contract also contains the Phase 2
identity/home/catalog and Phase 4 synchronization operations. RFC 9457 problem
details include a request correlation ID. The implementation and contract must
move in the same backend commit.

Design tokens are a versioned data contract. The public CSS and Flutter
generated values may consume them, but PHP templates and Dart widgets are not
shared across repositories.
