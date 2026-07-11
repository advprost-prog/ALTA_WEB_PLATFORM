# Registry API v1

> Phase C1. Documentation only. Backward-compatible with the current ALTA client
> (`RegistryClient`, `RegistryCatalog`, `RegistryItem`, `ArtifactDownloader`).

## 1. Registry endpoint

```
GET /api/v1/registry
```

### Transport requirements

| Requirement | v1 contract |
|---|---|
| Scheme | **HTTPS only** in production. |
| Method | **GET only.** |
| Body encoding | **JSON, UTF-8.** |
| Access | **Public, read-only** (no authentication required to read). |
| Redirects | **None.** The endpoint must not 3xx-redirect. |
| Success | **`200`** for a valid, complete registry. |
| Temporary failure | **`503`** when the registry cannot currently be generated. |
| Partial results | **Never** return a partial registry as a success. A `200` body is always the complete current projection. |
| `Content-Type` | `application/json`. |
| Caching | `Cache-Control`, `ETag`, `Last-Modified` **must** be sent. |
| Conditional GET | Support `If-None-Match` and `If-Modified-Since`; return **`304`** with **no body** on cache hit. |

> **Client note:** the current ALTA client (`RegistryClient::fetch`) does **not**
> send `If-None-Match` / `If-Modified-Since` and does not read `ETag` /
> `Last-Modified`. These headers are part of the **server** contract for future
> client compatibility; they must not become client-required.

## 2. Registry document shape

Backward-compatible root shape (unchanged from the client contract):

```json
{
  "registry": {},
  "items": []
}
```

### `registry` metadata object

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | recommended | Human-readable registry name. |
| `version` | string | recommended | **Retained for backward compatibility** with the demo contract. Decorative; the current client does not enforce it. |
| `schema_version` | string | **v1: present** | **Additive.** Value **`"1"`** for this version. The current client ignores it; a future client may enforce it. Must not be client-required. |
| `generated_at` | string (ISO-8601 UTC) | recommended | Projection generation timestamp. |

## 3. Item projection rule

The current ALTA client supports **one version per addon `code`**. Therefore
Registry API v1 **must**:

- return **at most one item per addon `code`**;
- select exactly **one current public release** per addon (see
  `release-lifecycle.md` §"Current-public-release projection");
- **not** return an `available_versions` array;
- **not** return `draft`, `uploaded`, `validating`, `validation_failed`,
  `ready_for_review`, or `approved`-but-not-`published` releases;
- **not** return `revoked` releases;
- **not** return `unpublished` releases;
- **not** return releases signed with a **revoked signing key**;
- **not** return releases without a valid **immutable** artifact;
- **not** return releases without a **signature**;
- **not** return releases that failed **publication validation**.

### Deprecated releases

- A `deprecated` release may remain listed **only while it is still published**.
- The API may add an **additive** field `deprecated: true`. The current client
  ignores it.
- A `deprecated` release is **not** selected as current when a newer
  non-deprecated **compatible published** release exists for the same addon.
- If **no other** release exists, whether the deprecated release remains current
  is a **product-owner policy decision** — recorded as **OPEN BEFORE C4** in
  `decisions.md`. This spec does not silently pick a final business policy.

## 4. Current client fields (supported today)

Item fields the current client reads (`RegistryItem::fromArray`):

| Field | Type | Required (client) | Nullable | Default/fallback |
|---|---|---|---|---|
| `code` | string | **yes** | no | — (item dropped if missing) |
| `type` | string | **yes** | no | `"extension"` in DTO, but projection always sets it |
| `vendor` | string | no | no | `""` |
| `name` | string | no | no | `= code` |
| `description` | string | no | no | `""` |
| `version` | string | no | no | `""` |
| `category` | string | no | **yes** | `null` |
| `tags` | string[] | no | no | `[]` |
| `requires_platform` | string | no | **yes** | `null` |
| `dependencies` | array | no | no | `[]` |
| `is_featured` | bool | no | no | `false` |
| `homepage_url` | string | no | **yes** | `null` |
| `documentation_url` | string | no | **yes** | `null` |
| `artifact` | object | no | **yes** | `null` |

Artifact fields (`RegistryItem::normalizeArtifact`):

| Field | Type | Notes |
|---|---|---|
| `url` | string | absolute HTTPS, Marketplace host (see §6) |
| `type` | string | must be `"zip"` |
| `sha256` | string | 64-char lowercase hex |
| `size` | integer | > 0 |
| `signature` | object | see below |

Signature fields (`RegistryItem::normalizeSignature`):

| Field | Type | Notes |
|---|---|---|
| `type` | string | `"ed25519"` |
| `value` | string | standard base64 of the 64-byte detached signature |
| `key_id` | string | stable public-key identifier |

## 5. Allowed additive v1 fields

These are **optional** and **ignored by the current client**. They **must not**
be marked client-required.

| Field | Placement | Meaning |
|---|---|---|
| `deprecated` | item | `true` when the current release is deprecated-but-published. |
| `artifact.signature.payload_version` | signature | `"raw-zip-v1"` — documents what was signed. |
| `publisher` | item | Publisher display metadata (e.g. `{ "public_id": …, "name": … }`). Not signed (see metadata authenticity gap). |
| `published_at` | item | ISO-8601 publication timestamp of the current release. |

## 6. Artifact URL rule

`artifact.url` **must**:

- be an **absolute HTTPS** URL;
- use a **Marketplace-controlled host** (the download proxy), never object storage;
- be **stable**;
- **not** be a presigned object-storage URL;
- **not** contain credentials;
- **not** change after publication without creating a **new artifact/version**;
- **not** redirect;
- return the **exact immutable bytes** for which the published SHA-256 and
  Ed25519 signature were computed.

Example:

```
https://marketplace.example.com/api/v1/artifacts/{public_id}/download
```

## 7. Artifact download contract

```
GET /api/v1/artifacts/{artifact_public_id}/download
```

| Aspect | v1 contract |
|---|---|
| Success status | **`200`**. |
| `Content-Type` | `application/zip`. Compatibility fallback: `application/octet-stream`. |
| `Content-Length` | **Required.** |
| `Content-Disposition` | `attachment` (e.g. `attachment; filename="{code}-{version}.zip"`). |
| Redirects | **None.** No object-storage redirect. |
| `ETag` | **Immutable** ETag for the artifact bytes. |
| `Cache-Control` | `public, max-age=31536000, immutable`. |
| Body | **Exact raw ZIP bytes** — the same bytes hashed and signed. |
| Delivery | Backend **streams** bytes from private object storage (proxy). |
| Host allowlist | The artifact host must be added to the ALTA client host allowlist. |

### `Accept: application/json` backward-compatibility quirk

The current ALTA `ArtifactDownloader` issues the artifact GET with
`Accept: application/json` (it reuses the JSON HTTP client). This is a
**backward-compatibility quirk**, **not** recommended behaviour for a new client.

- The cloud artifact endpoint **must not** perform content negotiation to choose
  a JSON response.
- Regardless of the incoming `Accept` header, a valid artifact download endpoint
  **must return the ZIP bytes**.
- A future client should send `Accept: application/zip` (or omit `Accept`); this
  is documented as a quirk to preserve, not a control to rely on.

## 8. Error contract (problem-details compatible)

For both the registry and the artifact endpoints, errors are returned as
`application/problem+json` (RFC 9457 style):

```json
{
  "type": "https://marketplace.example.com/problems/{slug}",
  "title": "Human readable summary",
  "status": 404,
  "detail": "Specifics for diagnostics.",
  "instance": "/api/v1/artifacts/{public_id}/download"
}
```

| Status | Meaning (Marketplace) |
|---|---|
| `400` | Malformed request. |
| `404` | Unknown addon / artifact / route. |
| `409` | Conflicting state (e.g. duplicate publication attempt). |
| `410` | **Gone** — artifact was revoked/unpublished (see lifecycle). |
| `422` | Validation error (admin/publish flows). |
| `429` | Rate limited. |
| `500` | Internal error. |
| `503` | Registry temporarily cannot be generated. |

> **Client note:** the current `ArtifactDownloader` treats only `2xx` as success
> and **does not parse a structured error body** (`$response->successful()` →
> otherwise a diagnostic string). The problem-details JSON is for
> **admin/diagnostics/future clients**; it is **not** a client-required contract.
> In particular, on `410 Gone` today's client simply records an
> `HTTP error: 410` diagnostic and does not store the artifact.

## 9. Example

A schema-conforming example is provided at
[examples/registry-v1.example.json](./examples/registry-v1.example.json). It
contains a module item, an extension item, both dependency forms (string and
object), an artifact, and Ed25519 signature fields including
`payload_version = "raw-zip-v1"`.

> **The cryptographic values in the example are illustrative only.** The
> `sha256` and `signature.value` fields are placeholder/test values and are
> **not** cryptographically valid; the example uses the reserved documentation
> domain `marketplace.example.com` and a test `key_id`. Do not treat the example
> signature as verifiable, and never place a real production key or any private
> key in an example.
