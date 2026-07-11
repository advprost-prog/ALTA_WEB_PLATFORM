# Cloud Marketplace — PostgreSQL Data Model

> Phase C1. Specification only. No migrations, no code. Column lists are the
> **required minimum**; a C2 implementation may add operational columns as long
> as the invariants below hold.

## Conventions

- **Primary key:** every table has a surrogate `id BIGINT GENERATED ALWAYS AS
  IDENTITY` (internal, never exposed publicly).
- **Public identifier:** externally referenced tables also have a stable,
  opaque `public_id TEXT` (e.g. ULID/prefixed), unique. Public APIs and the
  registry reference `public_id`, never `id`.
- **Timestamps:** `created_at TIMESTAMPTZ NOT NULL`, `updated_at TIMESTAMPTZ
  NOT NULL` unless a table is append-only (then only `created_at`).
- **Soft delete:** `deleted_at TIMESTAMPTZ NULL` where a business "delete" must
  not destroy history. **Business deletion never implies physical deletion of
  artifact bytes.**
- **Audit:** every state change and administrative action emits an append-only
  `audit_events` row.
- **Enums** are implemented as PostgreSQL enum types or `TEXT` + `CHECK`.

---

## marketplace_users
Admins/reviewers/operators of the Marketplace backend (distinct from ALTA app
users).

- **PK:** `id`. **Public id:** `public_id`.
- **Required:** `email` (unique, citext), `name`, `role` (enum:
  `admin|reviewer|operator`), `is_active` (bool).
- **Nullable:** `last_login_at`.
- **Timestamps:** created/updated; **soft delete** `deleted_at`.
- **Unique:** `email`.
- **Indexes:** `email`, `role`.
- **Audit:** create/disable/role-change → audit event.

## publishers
An entity that owns addons and signing keys.

- **PK:** `id`. **Public id:** `public_id`.
- **Required:** `slug` (unique), `display_name`, `status` (enum:
  `active|suspended`).
- **Nullable:** `homepage_url`, `contact_email`.
- **Timestamps:** created/updated; **soft delete** `deleted_at`.
- **Unique:** `slug`.
- **Indexes:** `slug`, `status`.
- **Immutable:** `public_id`, `slug` (after creation).

## publisher_keys
Ed25519 **public** keys and lifecycle state. **Never stores private key bytes.**

- **PK:** `id`. **Public id:** none required; `key_id` is the public reference.
- **FK:** `publisher_id → publishers.id`.
- **Required:** `key_id` (globally unique, stable, immutable — the value used in
  `signature.key_id`), `algorithm` (enum: `ed25519`; v1 fixed), `public_key`
  (TEXT, standard base64, decodes to 32 bytes), `state` (enum:
  `pending|active|retiring|revoked|expired`).
- **Nullable:** `secret_ref` (a **secret-manager identifier/URI** for the
  private key — **never** the key bytes), `activated_at`, `retiring_at`,
  `revoked_at`, `revoked_by` (FK → marketplace_users), `revoke_reason`,
  `expires_at`.
- **Timestamps:** created/updated. **No soft delete** (revoked/expired are
  states, not deletes).
- **Unique:** `key_id`; `(publisher_id, public_key)`.
- **Check:** `algorithm = 'ed25519'` for v1; `state` in enum;
  `octet_length(decode(public_key,'base64')) = 32` (enforced in app if not in DB).
- **Indexes:** `key_id`, `(publisher_id, state)`.
- **Immutable:** `key_id`, `public_key`, `algorithm`, `publisher_id`
  (publisher binding is immutable after first signing).
- **Audit:** every state transition (actor, reason) → `key_state_transitions`
  is folded into `release_state_transitions`? No — key transitions also go to
  `audit_events`.

## addons
A logical addon identified by a globally-unique `code`.

- **PK:** `id`. **Public id:** `public_id`.
- **FK:** `publisher_id → publishers.id`.
- **Required:** `code` (**globally unique**), `type` (enum: `module|extension`),
  `name`, `slug`.
- **Nullable:** `description`, `category`, `homepage_url`, `documentation_url`,
  `is_featured` (default false).
- **Timestamps:** created/updated; **soft delete** `deleted_at`.
- **Unique:** `code`.
- **Indexes:** `code`, `(publisher_id)`, `type`, `is_featured`.
- **Immutable invariant:** `type` is **immutable after the first publication**
  of any version of the addon.

## addon_versions
A specific version of an addon and its release state.

- **PK:** `id`. **Public id:** `public_id` (release public id).
- **FK:** `addon_id → addons.id`, `artifact_id → artifacts.id` (nullable until
  an artifact is attached), `signing_key_id → publisher_keys.id` (nullable until
  signed).
- **Required:** `version` (semver `\d+\.\d+\.\d+`), `state` (enum: see
  `release-lifecycle.md`: `draft|uploaded|validating|validation_failed|
  ready_for_review|approved|published|deprecated|revoked|unpublished`),
  `is_current_public` (bool, default false).
- **Nullable:** `published_at`, `deprecated_at`, `revoked_at`, `unpublished_at`,
  `changelog`.
- **Timestamps:** created/updated.
- **Unique:** `(addon_id, version)` — **version unique within an addon**;
  **partial unique** `(addon_id) WHERE is_current_public` — **at most one current
  public release per addon**.
- **Check:** `is_current_public = true` requires `state = 'published'` (a
  `deprecated`-but-published release that is current is represented with
  `state='published'` + a separate `deprecated` flag/timestamp, or the enum
  `deprecated` implies still-published — see lifecycle doc; the invariant is:
  **`revoked` and `unpublished` can never be `is_current_public`**).
- **Indexes:** `(addon_id, state)`, `(addon_id, version)`,
  `(addon_id) WHERE is_current_public`.
- **Immutable:** a **published** version's `artifact_id` and `version` — a
  published version cannot be overwritten. Fixing an artifact requires a new
  version or a new pre-publication revision.

## addon_dependencies
Declared dependencies of a specific addon version.

- **PK:** `id`.
- **FK:** `addon_version_id → addon_versions.id`.
- **Required:** `dependency_code` (slug), `required` (bool, **always true in v1**),
  `constraint` (TEXT nullable — null/`*` = any).
- **Timestamps:** created/updated.
- **Unique:** `(addon_version_id, dependency_code)`.
- **v1 constraints:** **optional dependencies are not supported** by the client
  contract (`required` is always true); **conflicts are not supported** in v1.
- **Indexes:** `(addon_version_id)`, `dependency_code`.

## addon_compatibility_rules
Normalised compatibility metadata for a version (published as metadata only; the
client enforces only `platform_constraint`).

- **PK:** `id`.
- **FK:** `addon_version_id → addon_versions.id` (1:1).
- **Nullable columns:** `platform_constraint`, `php_constraint`,
  `laravel_constraint`, `app_min_version`, `app_max_version`.
- **Timestamps:** created/updated.
- **Note:** projected to `requires_platform` in the registry;
  `php_constraint`/`laravel_constraint`/`app_min_version`/`app_max_version` are
  metadata the client does **not** enforce.

## artifacts
Immutable artifact byte records. One record = one exact immutable byte sequence.

- **PK:** `id`. **Public id:** `public_id` (used in the download URL).
- **FK:** `addon_id → addons.id`, `publisher_id → publishers.id`.
- **Required:** `object_storage_key` (immutable), `sha256` (lowercase hex, 64),
  `size` (integer > 0), `content_type` (default `application/zip`),
  `state` (enum: `stored|available|gone`).
- **Nullable:** `gone_at` (set when revoked/unpublished delivery is disabled).
- **Timestamps:** created/updated. **No physical delete on unpublish/revoke.**
- **Unique:** `object_storage_key`; `public_id`.
- **Check:** `size > 0`; `sha256 ~ '^[0-9a-f]{64}$'`.
- **Indexes:** `sha256` (**globally indexed**), `object_storage_key`, `addon_id`.
- **Immutable:** `object_storage_key`, `sha256`, `size` — an artifact record
  corresponds to exact immutable bytes; the bytes are never rewritten.

## artifact_signatures
Ed25519 signature bound to exact artifact bytes.

- **PK:** `id`.
- **FK:** `artifact_id → artifacts.id`, `publisher_key_id → publisher_keys.id`.
- **Required:** `algorithm` (enum `ed25519`), `payload_version` (TEXT, v1 =
  `raw-zip-v1`), `signature_value` (TEXT, standard base64, decodes to 64 bytes),
  `key_id` (denormalised copy of `publisher_keys.key_id`).
- **Timestamps:** created (append-only; **no update, no delete**).
- **Unique:** `(artifact_id, payload_version, publisher_key_id)`.
- **Check:** `algorithm = 'ed25519'`; `payload_version = 'raw-zip-v1'` for v1.
- **Indexes:** `artifact_id`, `key_id`.
- **Invariants:** a signature is over **exact artifact bytes**; the signing key
  **belongs to the artifact's publisher**; `key_id` is immutable.

## release_reviews
Manual review records for a release (approve/reject/notes).

- **PK:** `id`.
- **FK:** `addon_version_id → addon_versions.id`, `reviewer_id →
  marketplace_users.id`.
- **Required:** `decision` (enum: `approved|rejected`), `note` (required when
  `rejected`).
- **Timestamps:** created (append-only).
- **Indexes:** `addon_version_id`.

## release_state_transitions
Append-only history of every release state change.

- **PK:** `id`.
- **FK:** `addon_version_id → addon_versions.id`, `actor_id →
  marketplace_users.id` (nullable for system).
- **Required:** `from_state`, `to_state`, `reason`, `occurred_at`.
- **Append-only:** no update, no delete.
- **Indexes:** `(addon_version_id, occurred_at)`.

## audit_events
Global append-only audit log (all administrative and lifecycle actions,
including key transitions).

- **PK:** `id`.
- **FK (nullable):** `actor_id → marketplace_users.id`, plus polymorphic
  `subject_type` / `subject_public_id`.
- **Required:** `action` (TEXT), `occurred_at`, `metadata` (JSONB — must never
  contain private keys, credentials, or presigned URLs).
- **Append-only:** no update, no delete.
- **Indexes:** `(subject_type, subject_public_id)`, `occurred_at`, `action`.

## download_events
Observability of artifact downloads.

- **PK:** `id`.
- **FK (nullable):** `artifact_id → artifacts.id`.
- **Required:** `occurred_at`, `status` (int), `bytes_sent`.
- **Nullable:** `client_ip_hash` (hashed, not raw PII), `user_agent`.
- **Append-only.**
- **Indexes:** `(artifact_id, occurred_at)`.

---

## Consolidated invariants

- `addons.code` is **globally unique**.
- `addons.type` is **immutable after the first publication**.
- `version` is **unique within an addon** (`addon_versions (addon_id, version)`).
- A **published** addon version cannot be overwritten; corrections require a new
  version or a new pre-publication revision.
- `artifacts.sha256` is **globally indexed**; lowercase hex, 64 chars.
- One `artifacts` record ⇔ **exact immutable bytes**; `object_storage_key` is
  immutable; `size > 0`.
- One `artifact_signatures` row is bound to **exact artifact bytes**; signing key
  **belongs to the publisher**; `algorithm = ed25519` for v1.
- **At most one** `is_current_public` release per addon (partial unique index).
- A `revoked` release **cannot** be current; an `unpublished` release **cannot**
  be current; a release signed with a **revoked key cannot** be
  published/current.
- `audit_events` and `release_state_transitions` are **append-only**.
- Artifact bytes are **not physically deleted** during ordinary
  unpublish/revoke; business deletion ≠ physical byte deletion.

## Out of scope
No billing, licensing, subscription, or entitlement tables in v1.
