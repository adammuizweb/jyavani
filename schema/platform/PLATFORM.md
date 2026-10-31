# Jyavani Platform Phase 0

Status: normative Phase 0 contract. The key words MUST, MUST NOT, REQUIRED,
SHOULD, SHOULD NOT, and MAY are to be interpreted as requirement levels.

## Product philosophy

Jyavani is a portable publishing platform, not one runtime, framework, database,
or administrative interface. A distribution is successful when people can own
their content, understand its behavior, extend it deliberately, and move a safe
subset of site state without depending on its original implementation.

Phase 0 standardizes meaning before standardizing APIs. It favors explicit,
inspectable data and conservative interoperability over loss-prone automation.
Distributions may innovate in implementation and presentation while preserving
the shared semantic contracts below.

## Platform invariants

1. Content and assets remain user-owned data; a distribution MUST NOT make
   ordinary export contingent on a proprietary runtime or remote service.
2. Authorization is enforced by the receiving implementation. Descriptive
   roles, capabilities, archive metadata, or event fields never grant authority.
3. Stored identity, event identity, package identity, and presentation identity
   are distinct. Similar labels, slugs, folders, or UI positions do not prove
   equivalence.
4. Public, authenticated, restricted, and private resources remain distinct.
   Import MUST NOT broaden visibility when an exact policy cannot be represented.
5. Instants and local civil times are different domain values. A distribution
   MUST NOT silently reinterpret one as the other.
6. Extensions and themes are optional implementations around stable semantics.
   Their executable code is never part of a portable content archive.
7. Unsupported required semantics fail closed. Partial success MUST be explicit,
   limited to records marked optional, and reported to the operator.
8. Archive input is untrusted. Validation, authorization, limits, and safe file
   handling precede persistence or publication.

## Shared and distribution-specific boundary

The shared platform contract owns:

- Domain meanings and vocabulary in this document.
- Semantic event names and minimum event meaning.
- Capability identifiers and the parity vocabulary.
- Portable asset identity and visibility classes.
- Extension/theme implementation identity rules.
- The versioned portable archive contract and JSON Schemas.

A distribution owns:

- Programming language, runtime, framework, database schema, query strategy,
  cache, queue, filesystem layout, deployment model, and process topology.
- Dashboard design, theme renderer, editor engine, URLs, and internal hooks.
- Local primary keys and all persistent identity until a later platform phase
  defines portable UUIDs.
- Authentication, authorization policy, secret storage, audit retention, and
  implementation-specific extension/theme APIs.
- Features outside declared capabilities and any conversion explicitly reported
  as distribution-specific.

A shared concept does not require identical storage. A distribution-specific
feature does not become portable merely because another distribution has a
feature with the same name.

## Domain vocabulary

| Term | Meaning |
| --- | --- |
| Platform | These technology-neutral semantic contracts. |
| Distribution | A runnable implementation of the platform. |
| Site | One independently administered publishing domain. Archives do not carry a tenant identifier. |
| Actor profile | Non-authentication attribution data for a person or system. |
| Content | An authored resource such as an article, page, or theme content item. |
| Taxonomy term | A named classification node in a vocabulary. |
| Asset | Metadata for one logical media/file resource and its immutable blob bytes. |
| Blob | Opaque bytes addressed by a lowercase SHA-256 digest. |
| Relationship | A typed edge between archive-local records. |
| Navigation item | An ordered label targeting a record or an explicit URI. |
| Setting | An allowlisted, non-secret portable site preference. |
| Capability | A versioned semantic behavior a distribution declares it can provide. |
| Extension | An implementation-specific feature package; it is not a portable semantic identity. |
| Theme | An implementation-specific presentation package; it is not content identity. |
| Semantic event | A versioned statement that a domain fact occurred, independent of an internal hook name. |
| Archive-local ID | A temporary identifier meaningful only inside one archive. |
| UTC instant | A unique point on the timeline serialized with a trailing `Z`. |
| Local civil time | Calendar and clock fields plus IANA timezone context, without an implied UTC conversion. |

## Semantic events

Event names are lowercase, dot-separated facts ending in a major version. They
MUST describe completed domain facts rather than commands. Internal hook names,
queue topics, and callback signatures MAY differ. A later event version is a new
contract and MUST NOT be treated as compatible by string prefix.

| Event | Minimum meaning |
| --- | --- |
| `content.created.v1` | A content identity was first persisted. |
| `content.updated.v1` | Portable content state changed. |
| `content.published.v1` | Content became publicly publishable under local policy. |
| `content.unpublished.v1` | Previously publishable content ceased to be publishable. |
| `content.deleted.v1` | Content entered a deleted state or was removed under local policy. |
| `taxonomy_term.created.v1` | A classification identity was first persisted. |
| `taxonomy_term.updated.v1` | Portable classification state changed. |
| `asset.created.v1` | Asset metadata and its verified blob became managed. |
| `asset.updated.v1` | Portable asset metadata changed without redefining old blob bytes. |
| `asset.deleted.v1` | An asset ceased to be available under local policy. |
| `relationship.created.v1` | A typed domain edge was established. |
| `relationship.deleted.v1` | A typed domain edge was removed. |
| `archive.imported.v1` | A complete archive import committed successfully. |

Events MUST carry a distribution-local event identifier, the semantic event
name, a UTC occurrence instant, the affected local resource identity, and an
optional actor identity. They MUST NOT carry credentials or imply that a remote
consumer is authorized. Event transport and replay are outside Phase 0.

## Asset model

An asset record is the logical resource; a blob is immutable content-addressed
bytes. The same blob MAY back multiple assets without merging their titles,
attribution, visibility, or access policy. Blob identity is exactly the SHA-256
digest of stored bytes, not a filename, URL, database key, decoded image, or
distribution-specific derivative.

Portable asset metadata includes archive-local identity, original filename,
media type, byte count, blob digest, visibility, access scope, optional alt text,
optional dimensions, and explicit timestamps. Filenames are display metadata,
never extraction paths. Derivatives MAY be exported as independent assets but
MUST NOT be assumed reproducible across distributions.

Importers MUST verify every blob digest and size before persistence, apply local
media validation, keep non-public blobs outside direct public serving, and map
an unknown access policy to an equally or more restrictive state or reject it.

## Extension and theme implementation identity

Extension/theme implementation identity is the tuple of package kind,
implementation ID, package ID, package version, and API contract version. Every
element MUST match for executable implementations to be considered the same.
A display name, slug, folder, Store listing, capability ID, or similar output is
not implementation identity and MUST NOT be used to select or execute a package.

Portable archives declare extension/theme dependencies only to explain data and
required semantics. They contain no packages or executable code. An importer
MUST resolve a required declaration through its own trusted package policy and
MUST fail closed when the exact compatible implementation is unavailable.
Optional unknown extension records MAY be skipped with a report; they MUST NOT
be passed to a similarly named package.

Executable package manifests declare this tuple in the optional `technology`
object defined by `implementation-package-profile.schema.json`. A missing object
identifies a legacy package under distribution-local policy. Once the object is
present it is atomic: malformed or partial values, an unknown schema, a different
distribution or implementation ID, an unsupported API constraint, or Store and
package disagreement MUST fail closed. Legacy and aware metadata MUST NOT be
silently mixed. Distribution Stores may use different installation workflows,
but they MUST preserve and revalidate the complete profile through discovery,
download, staging, activation, assignment, update, and executable loading.

Core release manifests before `2.3.196` may omit product and distribution
metadata for upgrade and reinstall compatibility. Core release `2.3.196` and
later MUST publish the complete aware metadata tuple; omission is not a legacy
escape hatch for future or foreign distribution packages.

## Capability parity matrix

`required` means every conforming Phase 0 distribution implements the semantic
contract. `declared` means support is allowed but MUST be advertised explicitly.
`local` means no cross-distribution behavior is promised.

| Capability ID | Contract | Parity | Archive v1 |
| --- | --- | --- | --- |
| `content.lifecycle` | `1.0.0` | required | core records |
| `taxonomy.hierarchy` | `1.0.0` | required | core records |
| `asset.blob-sha256` | `1.0.0` | required | core records and blobs |
| `relationship.typed` | `1.0.0` | required | core records |
| `navigation.ordered` | `1.0.0` | required | core records |
| `setting.portable-safe` | `1.0.0` | required | allowlisted core records |
| `time.explicit-civil-utc` | `1.0.0` | required | all timestamp values |
| `archive.create-only` | `1.0.0` | required | import mode |
| `content.scheduling` | `1.0.0` | declared | UTC schedule value when supported |
| `content.translation` | `1.0.0` | declared | extension declaration/records |
| `workflow.editorial` | `1.0.0` | declared | extension declaration/records |
| `theme.rendering` | distribution API | local | identity declaration only |
| `extension.runtime` | distribution API | local | identity declaration only |
| `authentication` | local policy | local | excluded |
| `authorization` | local policy | local | excluded |

Capability parity means equivalent observable semantics, not shared source code,
database tables, routes, user interface, or package compatibility. The
distribution descriptor is the machine-readable declaration; claims marked
`partial` MUST state their limitations and MUST NOT satisfy an archive's required
capability without explicit operator-approved conversion outside this contract.

## Non-goals

Phase 0 does not define runtime exporter/importer code, database migration,
cross-site synchronization, persistent portable UUIDs, executable package
distribution, authentication migration, or a universal theme rendering API.
Portable Archive v1 is specified in `PORTABLE_ARCHIVE_V1.md`.
