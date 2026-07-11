# Cloud Marketplace — Decisions Log

> Phase C1. Each decision lists: **status**, **rationale**, **impact**,
> **owner**, **phase deadline**, **client change required?**. No time estimates
> in days/hours.

## ACCEPTED FOR C1

| Decision | Rationale | Impact | Owner | Deadline | Client change? |
|---|---|---|---|---|---|
| Standalone application | Isolation of blast radius, secret custody, independent deploy | New service | Architecture | C1 | No |
| Separate deployment unit | Independent scaling/rollout from ALTA | Ops topology | Architecture | C1 | No |
| Production target = separate repository (not created in C1) | Clean separation; C1 stays doc-only | Future repo | Product owner | before C2 | No |
| Laravel + Filament | Matches stack; reuse conventions | Backend/admin | Architecture | C1 | No |
| PostgreSQL metadata DB | Relational metadata, constraints, indexes | Data model | Architecture | C1 | No |
| Private S3-compatible artifact storage | Immutable, scalable byte storage | Storage model | Architecture | C1 | No |
| Stable Marketplace download proxy | Client needs stable, non-expiring HTTPS URL on Marketplace host | API/storage | Architecture | C1 | No |
| Registry API v1 (`GET /api/v1/registry`) | Formalises the consumed contract | API | API owner | C1 | No |
| raw-ZIP Ed25519 compatibility (`raw-zip-v1`) | Matches existing client verification exactly | Signing | Security | C1 | No |
| One current public release per addon in v1 | Client supports one version per code | API/projection | API owner | C1 | No |
| No automatic client install/enable | Manual lifecycle preserved | Scope | Product owner | C1 | No |
| No billing/licensing | Out of scope for marketplace core | Scope | Product owner | C1 | No |
| No Backup & Restore implementation in core Marketplace | Separate future addon | Scope | Product owner | C1 | No |

## OPEN BEFORE C2

| Decision | Rationale | Impact | Owner | Deadline | Client change? |
|---|---|---|---|---|---|
| Cloud provider | Not chosen in C1 | Infra | Ops/Product owner | before C2 | No |
| Production domain | Needed for URLs/TLS | Infra/API | Product owner | before C2 | No (allowlist update only) |
| PostgreSQL provider | Managed vs self-hosted | Infra | Ops | before C2 | No |
| S3-compatible provider (AWS S3 / R2 / B2 / Hetzner / MinIO / other) | Deliberately deferred | Storage | Ops/Product owner | before C2 | No |
| CI/CD provider | Deployment pipeline | Ops | Ops | before C2 | No |
| Authentication provider (admin) | Admin identity | Security | Security | before C2 | No |
| Secret manager / KMS implementation | Private key custody | Security | Security | before C2 | No |
| Public access rate limits | Abuse protection (`429`) | API | API owner | before C2 | No |
| Monitoring provider | Observability | Ops | Ops | before C2 | No |

## OPEN BEFORE C4

| Decision | Rationale | Impact | Owner | Deadline | Client change? |
|---|---|---|---|---|---|
| Publisher onboarding | Who may publish, how | Workflow | Product owner | before C4 | No |
| Publisher verification | Trust of publishers | Security/workflow | Security | before C4 | No |
| Deprecated-only current release policy | When only a deprecated release exists, is it emitted as current? | Projection | Product owner | before C4 | No (additive `deprecated` only) |
| Emergency revoke policy | How fast, who authorises | Security | Security | before C4 | No |
| Re-sign vs republish policy | Keeping releases available across key rotation | Signing/lifecycle | Security | before C4 | No |
| Key rotation operational window | Length/overlap of retiring period | Key lifecycle | Security/Ops | before C4 | No (public key onboarding only) |
| Artifact retention period (unpublished/revoked bytes) | Storage cost vs forensics | Storage/legal | Product owner | before C4 | No |
| Download audit retention | Privacy vs analytics | Ops/legal | Product owner | before C4 | No |
| Publication validation duplicate-manifest / nested-directory-depth policy | Client search is implementation-dependent; backend must define it explicitly | Validation | API owner | before C4 | No |

## FUTURE CONTRACT, NOT V1

| Item | Rationale | Impact | Owner | Deadline | Client change? |
|---|---|---|---|---|---|
| Multiple versions in client response | Client supports one version today | API v2 | API owner | future | **Yes** |
| Automatic trusted-key synchronisation | Client keys are local config only | Security | Security | future | **Yes** |
| Metadata signature | raw-zip-v1 signs bytes, not metadata | Security | Security | future | **Yes** (new payload version) |
| Optional dependencies | Not in client contract | API/data | API owner | future | **Yes** |
| Conflicts | Not in client contract | API/data | API owner | future | **Yes** |
| Licensing | Out of scope | Commercial | Product owner | future | **Yes** |
| Billing | Out of scope | Commercial | Product owner | future | **Yes** |
| Subscriptions | Out of scope | Commercial | Product owner | future | **Yes** |
| Private Marketplace | Out of scope | Access model | Product owner | future | **Yes** |
| Temporary download authorization | v1 uses stable public download proxy | API/security | Security | future | **Yes** (client lacks URL-expiry handling) |
