# Cloud Marketplace — Security & Signing

> Phase C1. Specification only. This locks the v1 signing contract to the
> behaviour already implemented in the ALTA client
> (`ArtifactSignatureVerifier`, `ArtifactTrustEvaluator`,
> `QuarantinedArtifactInspector`).

## 1. Signature v1

| Property | Value |
|---|---|
| **Payload** | The **exact raw ZIP bytes** of the artifact (not a hash, not canonical JSON, not a code/version/checksum tuple). |
| **Payload version** | `raw-zip-v1`. |
| **Algorithm** | Ed25519. |
| **Signature encoding** | Standard base64. |
| **Public key encoding** | Standard base64. |
| **Decoded public key length** | 32 bytes (`SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES`). |
| **Decoded signature length** | 64 bytes (`SODIUM_CRYPTO_SIGN_BYTES`). |

### Registry representation

```json
"signature": {
  "type": "ed25519",
  "value": "<standard base64 of the 64-byte detached signature>",
  "key_id": "<stable public-key identifier>",
  "payload_version": "raw-zip-v1"
}
```

The current client does **not** read `payload_version`; it is an **additive**
field and does not break backward compatibility. The verification the client
performs is:

```
sodium_crypto_sign_verify_detached(
    base64_decode(signature.value),        // 64 bytes
    raw_zip_bytes,                          // the exact downloaded body
    base64_decode(trusted_keys[key_id])     // 32 bytes
)
```

SHA-256 and the Ed25519 signature are computed over the **same exact bytes**.

## 2. Byte-stability rules

The signature must be produced **after** the final ZIP is assembled. After
signing, for a given artifact record it is **forbidden** to:

- repack the ZIP;
- change timestamps inside the ZIP;
- change the compression level;
- change the manifest;
- add or remove files;
- change byte order;
- regenerate the archive under the **same** artifact record.

Any such change produces different bytes and therefore requires a **new**
artifact record (new `object_storage_key`, new SHA-256, new signature) and, if
already published, a new addon version or pre-publication revision.

## 3. Key lifecycle

States (see `publisher_keys.state`): `pending → active → retiring →
revoked | expired`.

| State | May sign new releases? | Serve as source of a public current release? |
|---|---|---|
| `pending` | **No** — not yet activated. | No. |
| `active` | **Yes.** | Yes. |
| `retiring` | **No** new releases; still valid for verifying older releases. | Yes, for releases already signed while it was active. |
| `revoked` | **No.** | **No** — a release signed by a revoked key cannot be published/current. |
| `expired` | **No** new releases. | Per policy (OPEN BEFORE C4); by default treated like retiring for existing releases. |

Rules:

- The **private key is never** stored in the registry, never sent to ALTA
  clients, never stored as plaintext in PostgreSQL, never written to logs, never
  transmitted through the registry API.
- PostgreSQL stores the **public key** and metadata only. A `secret_ref` may
  store a **secret-manager identifier** for the private key — **not** key bytes.
- `key_id` is **immutable**.
- **Publisher binding is immutable** after the first signing with a key.
- `revoke` requires an **actor**, a **reason**, and an **audit event**.

## 4. Client trust distribution boundary

The ALTA client obtains trusted public keys **only** from its own configuration:

```php
config('addons-registry.trust.trusted_keys')   // [ key_id => base64 public key ]
```

The Cloud backend **cannot** modify this config. Therefore production key
onboarding and rotation require an **out-of-band client deployment / config
update**. There is **no** automatic trusted-key synchronisation in v1 (see
`decisions.md` — Future contract, not v1).

### Rotation process (operational)

1. Generate a new keypair (private key into the secret manager only).
2. Add the new **public** key to the client `trusted_keys` under a new `key_id`.
3. Deploy the client config (out-of-band).
4. Transition the new key to `active`.
5. Sign **new** releases with the new key.
6. Move the old key to `retiring`.
7. After the transition window, `revoke` or `expire` the old key.
8. Releases that must remain available are either re-published as a **new
   immutable artifact/version** or handled under a **formally defined re-sign
   policy** (OPEN BEFORE C4).

> Do **not** assume the signature of an already-published artifact can simply be
> replaced without an audit trail and a lifecycle policy. Changing what is served
> for a published artifact record violates immutability.

## 5. Metadata authenticity gap (accepted for v1)

The `raw-zip-v1` signature protects the **artifact bytes**, but it does **not**
sign the registry **metadata**:

- `description`
- `dependencies`
- `requires_platform`
- `category`
- URLs (`homepage_url`, `documentation_url`, `artifact.url`)
- `is_featured`
- publisher display data

The quarantine **manifest identity inspection** partially binds `code`,
`version`, and `type` to the ZIP contents, but the **full registry metadata
payload has no independent signature**. This is accepted as a backward-compatible
limitation for Registry API v1.

A future **metadata-signing** contract must receive its **own** payload version
and must **not** silently replace `raw-zip-v1`.

## 6. Client verification behaviour (as-is, for reference)

Documented so the backend does not over-claim client guarantees:

- The client's `ArtifactValidator` checks `sha256` **length = 64** and that
  `size` is a positive integer; it checks `type === 'zip'`. It does **not**
  enforce full lowercase-hex semantics — the **cloud schema/backend** enforces
  lowercase hex (server-side strengthening).
- The client checks `artifact.type === 'zip'`; it does **not** treat the HTTP
  response `Content-Type` (MIME) as a security control, and does **not** validate
  the response MIME. Integrity comes from SHA-256 + Ed25519, not from MIME.
- Manifest inspection (`QuarantinedArtifactInspector`) opens the ZIP read-only
  and performs an **implementation-dependent search** for a manifest by
  candidate filename (`module.json`, `extension.json`, `manifest.json`): first a
  direct `locateName`, then a scan of entries matching a candidate basename. It
  verifies **identity** (`code`/`version`/`type` consistency) only — it is
  **not** a full manifest schema validation, and the exact duplicate-manifest /
  nested-directory-depth policy is **implementation-dependent** in the client.
  The cloud publication validator must define the precise duplicate/depth policy
  explicitly (OPEN BEFORE C4) and must not rely on the client's search order as a
  guarantee.

## 7. Redirect / SSRF policy

- The **registry endpoint does not use redirects.**
- The **artifact endpoint does not use redirects.** Object-storage redirects are
  **forbidden**; the download proxy reads private object storage itself and
  streams bytes.
- `artifact.url` uses the **Marketplace host** only.
- Any **server-side URL import** (e.g. importing a publisher-supplied URL) must
  pass a dedicated **SSRF validator**:
  - DNS/IP validation, **not** a string-host allowlist alone;
  - **block** private, link-local, and loopback ranges for external imports;
  - re-validate after DNS resolution to mitigate rebinding.
- Automatic redirects are **not** treated as safe.
