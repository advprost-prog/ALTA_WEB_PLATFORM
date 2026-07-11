# Cloud Marketplace — Architecture & Contract Specification

> **Status:** Phase C1 — Documentation only.
> This directory contains the **specification** of a future Cloud Marketplace
> backend. It contains **no application code, controllers, routes, migrations,
> Filament resources, storage adapters, signing services or deployment
> pipelines.** Nothing here is executed by ALTA Web Platform at runtime.

## 1. Purpose

These documents define a precise, internally consistent and machine-checkable
contract between:

- the **ALTA Web Platform client** (the addon marketplace consumer that already
  exists in this repository under `app/Support/Addons/`), and
- a **future standalone Cloud Marketplace backend** that will publish a remote
  registry and serve signed addon artifacts.

The specification is written so that a later **Phase C2 (backend foundation)**
can be started from it without re-deriving the contract, and so that the future
backend remains **backward compatible** with the client that ships today.

## 2. Boundary between ALTA client and Cloud Marketplace

| Concern | Owner |
|---|---|
| Fetching the registry JSON, host allowlist, caching, merge with local catalog | **ALTA client** (`RegistryClient`, `RegistryCatalog`, `MarketplaceManager`) |
| Downloading an artifact into quarantine, SHA-256, Ed25519 verification, manifest inspection, trust, review, staging, promotion, rollback | **ALTA client** (`app/Support/Addons/Registry/*`) |
| Publishing the registry JSON, storing artifacts immutably, signing raw ZIP bytes, publisher & key lifecycle, release lifecycle, audit | **Cloud Marketplace backend** (future) |
| Automatic discover / register / install / enable of a promoted addon | **Neither** — explicitly out of scope (manual lifecycle only) |

The client **consumes**; the backend **publishes**. The backend never installs,
enables, or executes addon code on the client.

## 3. Phase status

- **Phase C0 — Completed.** Full audit of the client-side registry contract:
  Git state, registry JSON contract, artifact download contract, SHA-256
  contract, Ed25519 signed payload, trusted-key model, manifest contract,
  compatibility & dependency formats, config/env keys, fixtures inventory,
  gaps. The C0 findings are the empirical basis for this specification.
- **Phase C1 — This document set.** Architecture decision, Registry API v1,
  JSON Schema, PostgreSQL data model, Ed25519 signing model, publisher key
  lifecycle, release lifecycle, security boundaries, deployment topology, and a
  decisions log. Documentation only; no backend implementation.

## 4. Document index

| File | Content |
|---|---|
| [README.md](./README.md) | This overview, source-of-truth hierarchy, scope. |
| [architecture.md](./architecture.md) | Target production architecture, component diagram, data flows. |
| [registry-api-v1.md](./registry-api-v1.md) | `GET /api/v1/registry` and artifact download contract. |
| [registry-v1.schema.json](./registry-v1.schema.json) | JSON Schema (Draft 2020-12) for the registry document. |
| [examples/registry-v1.example.json](./examples/registry-v1.example.json) | Schema-conforming illustrative example. |
| [data-model.md](./data-model.md) | PostgreSQL entities, constraints, invariants. |
| [security-and-signing.md](./security-and-signing.md) | Signature v1, key lifecycle, trust distribution, SSRF policy. |
| [release-lifecycle.md](./release-lifecycle.md) | Release states, transitions, current-public-release projection. |
| [deployment-and-operations.md](./deployment-and-operations.md) | Topology, health/readiness, backups, migration policy. |
| [decisions.md](./decisions.md) | Decision log: accepted / open / future-not-v1. |

## 5. Source-of-truth hierarchy

When two documents disagree, resolve in this order (highest wins):

1. **Current client implementation** (this repository, `app/Support/Addons/`) —
   for backward compatibility of **client-required** fields and behaviour.
2. **Registry API v1 specification** (`registry-api-v1.md`).
3. **JSON Schema** (`registry-v1.schema.json`).
4. **PostgreSQL model specification** (`data-model.md`).
5. **Security / signing specification** (`security-and-signing.md`).
6. **Release lifecycle** (`release-lifecycle.md`).
7. **Deployment / operations specification** (`deployment-and-operations.md`).

**Hard rule:** the Registry API v1 spec and the JSON Schema **must not**
contradict the current client contract for **client-required fields**
(`code`, `type`, `version`, `artifact.url`, `artifact.type`, `artifact.sha256`,
`artifact.size`, `signature.type`, `signature.value`, `signature.key_id`).
Server-side rules may be **stricter** than the client (e.g. enforcing lowercase
hex for SHA-256) as long as any value the server would reject is also a value
the client would never have accepted as trusted.

## 6. Backward-compatibility policy

- The registry root shape stays `{ "registry": {…}, "items": [ … ] }`.
- New fields are **additive** and **optional**; the current client ignores
  unknown fields, so additive fields must never be marked client-required.
- The signature payload stays **raw ZIP bytes** (`payload_version = "raw-zip-v1"`).
  Any future change to the signed payload must introduce a **new** payload
  version and must not silently replace `raw-zip-v1`.
- The artifact download endpoint returns **exact immutable ZIP bytes** for which
  the published SHA-256 and Ed25519 signature were computed.
- Breaking changes require a new API version (`/api/v2/...`) — not a mutation of
  v1.

## 7. Out of scope (explicitly not part of Cloud Marketplace core)

- Automatic discover / register / install / enable (Phase 3.6 behaviour).
- Running addon service providers, addon migrations, `composer`, or `npm`.
- Backup & Restore System — a **future separate addon**, not Marketplace core.
- Billing, licensing, subscriptions, private/paid marketplaces.
- Automatic synchronisation of client `trusted_keys` from the backend.

## 8. Future phase sequence (C2–C8, indicative, no time estimates)

- **C2 — Backend foundation:** standalone Laravel app skeleton, PostgreSQL
  schema from `data-model.md`, config, secret-manager wiring (no publishing yet).
- **C3 — Signing service:** Ed25519 signing process over raw ZIP bytes, key
  lifecycle, secret custody.
- **C4 — Publisher & release workflow:** upload → validate → review → approve;
  publisher onboarding; deprecated-current policy confirmation.
- **C5 — Public Registry API + artifact download proxy:** `GET /api/v1/registry`
  and `GET /api/v1/artifacts/{public_id}/download`, current-public-release
  projection, caching headers.
- **C6 — Admin (Filament) & audit surfaces.**
- **C7 — Operations:** observability, backups, disaster-recovery boundary,
  migration governance.
- **C8 — Client onboarding:** out-of-band trusted-key distribution, config
  migration guidance, optional future client hardening (schema_version /
  payload_version enforcement).
