# Synchronization failure classifications

The existing problem `type` and operation-result `code` fields carry stable
recovery classifications; status codes and authorization decisions are not
relaxed. Envelope/status device mismatch uses `sync_device_mismatch`.
Only the synchronization membership guard emits `sync_home_access_denied`.
A denied command permission is distinct from a missing referenced record;
ordinary command-level 404 failures use `resource_unavailable` rather than
asserting membership revocation. Service failures retain sanitized details.

Clients must not purge a home, rebind immutable queued work, refresh credentials,
or create replacement operation IDs solely because of a generic 403/404 or
English error text. Authentication remains a request-level 401. Lost outcomes
remain recoverable through the existing exact account/home/device-scoped
operation receipts. This classification change does not itself recover any
historical queue or establish authorship for schema-2 records.
