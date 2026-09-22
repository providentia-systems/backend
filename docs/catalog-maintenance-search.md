# Catalog maintenance search

GET `/api/v1/catalog-admin/entities/{entityType}` accepts optional `q`, bounded
to 191 characters. Literal case-insensitive matching runs on the database before
the existing 100-row offset pagination. SQL wildcard characters in entered
text remain literal. Entity UUIDs and ordinary identity fields are searchable;
rule JSON and provenance are not searched. Existing role checks, product filters
and global-alias isolation remain in effect. This does not expose home aliases.

The backend-owned OpenAPI document and digest pins include the optional query;
paired Admin and Client contract copies must carry the same document digest.
