# Contracts and release order

`contracts/openapi/providentia-v1.json` is authoritative. Dart request and
response types are generated from it; neither repository maintains a parallel
handwritten network model.

Every contract file has a SHA-256 lock manifest. Changing bytes requires:

1. Update the backend implementation and OpenAPI.
2. Run contract validation and the generated Dart client proof.
3. Review semantic compatibility.
4. Tag and publish the backend contract.
5. Copy the exact contract and lock into both `providentia-systems/client` and
   `providentia-systems/admin`, then regenerate both clients.
6. Release matching Flutter clients and backend together. Before the first
   deployment there is no legacy-client compatibility path to retain; after
   launch, deprecation and migration plans become explicit release requirements.

Phase 1 introduced liveness, readiness, safe system information, and
operational metrics. API 2.1.0 contains numeric email-code authentication, profiles, verified email
aliases, scoped groups, home membership and operator inspection alongside
inventory, catalog and synchronization operations. RFC 9457 problem
details include a request correlation ID. The implementation and contract must
move in the same backend commit.

Design tokens are a versioned data contract. Flutter generated values consume them; widgets remain independently
implemented in each client. The backend has no browser login templates.
