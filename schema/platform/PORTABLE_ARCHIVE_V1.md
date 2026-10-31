# Jyavani Portable Archive v1

Status: normative. Format ID: `org.jyavani.portable-archive`, version `1`.

## Scope and safety posture

Before persistent portable UUIDs exist, Archive v1 is explicitly create-only.
An importer MUST create a new site or import into an empty, isolated destination.
It MUST NOT merge, update, upsert, overwrite, deduplicate by title/slug/email, or
bind archive records to existing resources. Archive-local IDs MUST NOT be stored
or advertised as future portable identity.

The complete archive is untrusted input. An importer MUST validate structure,
limits, paths, hashes, references, capabilities, extension declarations, and all
required record types before making any persistent write. Validation failure
leaves no imported state. Commit MUST be atomic from the destination's point of
view, or staging MUST be discarded completely before reporting failure.

No executable code is permitted in an archive. Secrets MUST NOT be exported,
including passwords, password hashes, API keys, private keys, session material,
CSRF material, reset tokens, provider credentials, environment configuration,
or authentication factors. Personal data that is not necessary for attribution
SHOULD be omitted and remains subject to local policy.

## Container and paths

The transport is a ZIP file with media type
`application/vnd.jyavani.portable-v1+zip`. Its root contains:

```text
manifest.json
records/<safe-name>.json
blobs/sha256/<lowercase-64-hex-digest>
```

Names are UTF-8 and use `/`. Entries MUST be regular files with unique normalized
paths. Absolute paths, backslashes, empty segments, `.`/`..` segments, control
characters, symbolic/hard links, devices, encrypted entries, and data outside
the three paths above are invalid. Readers MUST enforce implementation-defined
compressed size, expanded size, entry count, JSON depth, string length, and
compression-ratio limits before extraction. Files MUST be staged outside a web
root and MUST never receive executable permission.

`manifest.json` conforms to `portable-archive-manifest-v1.schema.json`. Each
indexed core record conforms to `portable-core-record-v1.schema.json`. JSON is
UTF-8 without a byte-order mark. Duplicate JSON member names are invalid even if
a parser normally accepts them. Record and blob hashes cover exact file bytes.

## Identity and references

Every record has one archive-local ID matching the schema's `kind:value` form.
IDs are unique across the archive. Every reference resolves to one indexed
record in the same archive; external database IDs and source URLs do not establish
identity. An importer creates its own local identities and keeps an in-memory or
staged archive-ID mapping only for resolving this import.

The manifest record index declares `record_type`, safe path, required status,
and SHA-256 before any record is read as domain data. Core types are:

- `core.actor_profile.v1`
- `core.content.v1`
- `core.taxonomy_term.v1`
- `core.asset.v1`
- `core.relationship.v1`
- `core.navigation_item.v1`
- `core.setting.v1`

Non-core record types use a namespace owned by a declared extension. An importer
MUST fail closed on every unknown required record type. Unknown optional records
MAY be skipped only after path/hash validation and MUST be listed in the import
report. Marking a record optional never permits a dangling reference from an
accepted record.

## Hashes and blobs

SHA-256 is written as exactly 64 lowercase hexadecimal characters. Every record
file and blob has an index hash. Every `core.asset.v1` digest resolves to exactly
one manifest blob entry whose path is `blobs/sha256/<digest>`; unreferenced blob
entries are invalid. Importers MUST hash exact staged bytes with SHA-256 and
compare digest and declared byte count before parsing/persisting or publishing.
Blob filenames are digests. Filenames are display metadata, never blob identity,
archive paths, or trusted extraction names.

## Time values

Time kind is explicit at every field; field names alone are not sufficient.

- A UTC instant is `{"kind":"utc","value":"...Z"}` using RFC 3339 and a
  trailing uppercase `Z`. It identifies one timeline instant.
- A local civil value is
  `{"kind":"local_civil","value":"YYYY-MM-DDTHH:MM:SS[.fraction]","timezone":"Area/Location"}`.
  It preserves calendar fields and IANA timezone context but does not assert an
  instant. Importers MUST NOT silently convert it to UTC, especially across a
  daylight-saving gap or fold.

When the destination cannot preserve the represented kind, import fails unless
the affected record is optional and unreferenced. Display formatting is never
part of the stored timestamp value.

## Capabilities, extensions, and themes

The manifest declares every required semantic capability with exact contract
version. The importer compares these declarations with its distribution
descriptor. Missing, unsupported, partial, or version-incompatible required
capabilities fail before persistence.

Extension/theme declarations contain package kind, implementation ID, package
ID, package version, API contract version, required status, and owned record
types. They describe implementation identity; they do not carry or authorize
code. A required declaration MUST resolve through the destination's trusted
package policy with exact compatible identity. An archive filename, display
name, slug, or similar capability is not sufficient identity.

The complete identity is package kind, implementation ID, package ID, package version, API contract version.
Declarations resolve only through the destination's trusted package policy; they do not carry or authorize code.

The archive MUST NOT contain scripts, binaries intended for execution, PHP,
JavaScript, templates with executable semantics, migrations, hooks, commands,
installers, or extension/theme package files. Content markup is data and MUST
still pass destination sanitization before publication.

## Core records

Core record envelopes have exactly `schema_version`, `local_id`, `record_type`,
and `payload`. Unknown properties are invalid. Payloads are strict and typed.
Actor profiles contain attribution only, not login identities. Settings use a
closed allowlist and cannot encode arbitrary configuration. Relationships and
navigation targets use archive-local references. Visibility and access scope
are independently preserved for assets and content.

The schema is a syntactic floor. Importers additionally enforce:

- Unique IDs and paths, exact record index/envelope agreement, and all hashes.
- Reference existence and type compatibility.
- Acyclic taxonomy parents and navigation parents.
- Blob index/asset correspondence and safe media validation.
- Slug uniqueness according to destination rules without overwriting data.
- Local authorization, sanitization, quotas, and at-least-as-restrictive access.
- No secret-shaped keys or values, even in extension records.

## Import transaction

1. Open the container without extracting and reject unsafe entry metadata.
2. Parse and validate the manifest with strict duplicate-key detection.
3. Enforce resource limits and exact allowed path inventory.
4. Resolve required capabilities and exact extension/theme identities.
5. Hash every indexed record and blob; reject missing or extra entries.
6. Validate every known record and all cross-references. Fail closed for unknown
   required records; account for skipped optional records.
7. Apply destination sanitization and policy checks while still in private
   staging. Generate new destination-local IDs.
8. Persist all accepted data and blobs as one create-only transaction or guarded
   staged publication. On failure, remove the complete stage.
9. Emit an import report and `archive.imported.v1` only after successful commit.

An import report records archive format/version, source distribution identity,
created destination identities, optional skips/conversions, warnings, and final
status. It MUST NOT contain secrets or turn archive-local IDs into durable
cross-site identity.

## Versioning

The manifest `format_version` controls the whole archive contract. Readers MUST
reject unsupported versions rather than guess. Compatible clarifications may
revise these documents and schemas without changing version 1; any change that
accepts previously ambiguous meaning, changes identity, weakens validation, or
adds required behavior requires a new format version.
