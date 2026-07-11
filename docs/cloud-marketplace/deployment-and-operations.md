# Cloud Marketplace — Deployment & Operations

> Phase C1. Specification only. No pipeline, no infra code, no provider choice.

## 1. Production topology

The Cloud Marketplace is a **standalone Laravel application**, deployed as a
**separate deployment unit** from ALTA Web Platform.

| Process / resource | Role |
|---|---|
| **Web process** | Serves the public read-only Registry API, the artifact download proxy, and the authenticated Filament Admin. |
| **Queue worker** | Async validation, checksum/inspection, signing orchestration, registry regeneration, cache invalidation, audit fan-out. |
| **Scheduler** | Periodic maintenance (e.g. registry regeneration heartbeats, key-lifecycle checks, retention jobs). |
| **PostgreSQL** | Metadata + public keys. |
| **Private S3-compatible object storage** | Immutable artifact bytes; no public URLs. |
| **Secret manager / KMS** | Custody of private signing keys; referenced by identifier only. |
| **HTTPS termination** | TLS at the edge/load balancer; HTTPS-only in production. |
| **Domain** | Dedicated Marketplace domain (e.g. `marketplace.example.com`). |

## 2. Endpoints for operations

- **Health (liveness):** `GET /healthz` — process is up. **No secrets exposed.**
- **Readiness:** `GET /readyz` — dependencies reachable (DB, object storage,
  secret manager). Returns booleans/status only; **never** leaks connection
  strings, keys, or storage URLs.

## 3. Observability

- **Structured logs** (JSON). Logs **must not** contain private keys, signature
  values treated as secrets, credentials, or presigned/object-storage URLs.
- **Audit logs** — append-only (`audit_events`), separate from operational logs.
- **Metrics** — request rates, latencies, download counts, queue depth, error
  rates, registry regeneration timing.
- **Alerting** — on `5xx`/`503` spikes, queue backlog, readiness failures,
  key-lifecycle events (e.g. key nearing expiry, revoke).

## 4. Database migration policy

- Migrations run in a **controlled** deployment step, reviewed and forward-only
  in production.
- **Forbidden in production deployment:** `migrate:fresh`, `migrate:refresh`,
  `migrate:reset`, `db:wipe`, and any destructive seed against the production DB.
- Rollbacks of schema are **restricted** and manual; a deployment rollback of the
  application **must not** implicitly roll back already-applied,
  already-published DB state in an uncontrolled way.

## 5. Object storage lifecycle & artifact durability

- **Published artifacts are not deleted automatically.**
- Object-storage **versioning or equivalent immutability** is desired.
- `object_storage_key` is immutable; bytes are write-once for a given artifact
  record.
- Retention of unpublished/revoked bytes is a policy decision (OPEN BEFORE C4);
  the default is **retain** (business deletion ≠ physical deletion).

## 6. Backups & disaster recovery

- **PostgreSQL:** regular automated backups + point-in-time recovery.
- **Artifact durability:** provided by the object-storage provider's durability
  guarantees plus versioning/immutability.
- **Disaster-recovery boundary:** recovering the Marketplace backend restores
  DB + object storage + secret-manager references. It does **not** restore or
  mutate any ALTA client state.
- **Infrastructure backup of the Marketplace itself is an operational
  responsibility**, described here as operations — it is **not** the
  ALTA "Backup & Restore" addon and is **not** part of Marketplace core.

## 7. Rollback

- **Deployment rollback:** revert the application artifact/version.
- **Application rollback** must not uncontrollably roll back already-published DB
  state; published releases and their transitions are append-only history.
- **Migration rollback** is restricted (see §4).

## 8. Signing permission boundary

The **signing process** has an **isolated permission boundary**: it is the only
component with access to private signing key material (via the secret manager),
runs separately from the public web surface, and its access is audited.

## 9. Explicitly excluded

- No Backup & Restore **addon** implementation here.
- No automatic discover/register/install/enable.
- No billing/licensing/subscription infrastructure.
