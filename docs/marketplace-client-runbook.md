# Marketplace client operations runbook

## Boundary and prerequisites

The Marketplace Server is authoritative for catalog, publisher identity, release metadata, and signatures. The client is authoritative for local installation, enable state, managed files, journals, recovery, and audit. Never copy server storage or private signing keys to the client.

Production requires PHP 8.2+, Sodium, ZIP, JSON and SHA-256 support; a writable private local filesystem; a cache backend with atomic locks; and migrated `system_addons` and `system_addon_events` tables. Run on the target host:

```bash
php artisan addons:marketplace:preflight --production
```

The command is read-only. Resolve every blocker before deployment. Warnings require an operator decision.

## Production configuration

Use the production endpoint and exact host allowlist:

```dotenv
ADDONS_REGISTRY_ENABLED=true
ADDONS_REGISTRY_URL=https://mp.altaserv.com.ua/api/v1/registry
ADDONS_REGISTRY_ALLOWED_HOSTS=mp.altaserv.com.ua
ADDONS_REGISTRY_VERIFY_SSL=true
ADDONS_REGISTRY_ALLOW_REDIRECTS=false
ADDONS_REGISTRY_TIMEOUT=5
ADDONS_REGISTRY_CONNECT_TIMEOUT=3
ADDONS_REGISTRY_MAX_RESPONSE_SIZE=1048576
ADDONS_REGISTRY_DOWNLOADS_ENABLED=true
ADDONS_REGISTRY_MAX_ARTIFACT_SIZE=20971520
ADDONS_REGISTRY_SIGNATURE_MAX_BYTES=20971520
ADDONS_REGISTRY_STAGING_ENABLED=true
ADDONS_REGISTRY_PROMOTION_ENABLED=true
ADDONS_REGISTRY_CLEANUP_ENABLED=false
ADDONS_REGISTRY_BACKUP_RETENTION_MIN_COUNT=1
ADDONS_REGISTRY_BACKUP_RETENTION_MAX_COUNT=5
ADDONS_REGISTRY_BACKUP_RETENTION_DAYS=30
ADDONS_REGISTRY_STALE_CLEANUP_AFTER=86400
ADDONS_REGISTRY_RECOVERY_HEALTH_ENABLED=true
ADDONS_REGISTRY_RECOVERY_HEALTH_CACHE_TTL=60
ADDONS_REGISTRY_RECOVERY_STALE_AFTER=300
ADDONS_REGISTRY_RECOVERY_AUTO_SAFE=false
```

Quarantine, staging, backup, journal, module, and extension roots must be application-private, writable, non-symlinked, non-overlapping, and on compatible filesystems where atomic rename is required. Do not expose the `addons` disk through a public storage link.

## Publisher trust onboarding

Obtain the publisher public UUID, key ID, Ed25519 public key, and independently communicated fingerprint through the authorized process. Two operators should verify publisher identity and fingerprint through separate channels. Configure an explicit binding with algorithm `ed25519` and status `active` or `retiring`. Duplicate identities, malformed keys, test markers, unsupported status, or publisher mismatch fail closed.

Only public keys belong in client configuration. Never store a seed, secret key, signing service credential, or other private material in the client or repository. Key rotation should overlap an active and retiring public key only for an approved period.

## Normal workflow

1. Run preflight and `php artisan addons:recovery:health --refresh`.
2. Refresh Registry from Filament or the existing Marketplace CLI. A fresh snapshot is mandatory for remote actions; a valid empty catalog is healthy but has nothing to install.
3. Download to quarantine. Verify size, SHA-256, publisher/key binding, Ed25519 `raw-zip-v1`, manifest, and archive safety.
4. An authorized administrator reviews and approves. Trust never implies approval.
5. Stage the approved artifact, then install it. Installation is disabled by default unless enable is explicitly requested and dependencies permit it.
6. For update, repeat refresh, download, verification, review, and staging. Verify the rollback backup before promotion.
7. Use `addons:recovery:scan`, `addons:recovery:show`, and dry-run before safe recovery. Automatic recovery is never run at boot.
8. Use `addons:rollback-version` preflight before operational rollback. Dependency blockers must be resolved first.
9. Inspect retention with `addons:backups:scan` and `addons:backups:cleanup --dry-run`. Mutation requires `--execute` and protects last-known-good and referenced backups.
10. Inspect remnants with `addons:cleanup:scan` and `addons:cleanup:run --dry-run`. Unknown, symlinked, or unmanaged evidence is retained.

## Incidents and manual intervention

Stop mutations for the affected addon, preserve journals and evidence, refresh diagnostics, and record an operator reason. Never force-complete, broadly delete, follow symlinks, or run `migrate:fresh`. A manual-intervention marker blocks new verified install/update until evidence is reconciled. Keep the core application available unless its own integrity is affected.

## Deployment verification

- Back up application data and confirm a tested deployment rollback.
- Verify target PHP/Sodium/ZIP, cache locks, database schema, permissions, root isolation, and HTTPS policy.
- Run preflight, recovery health, backup scan, and stale scan read-only.
- Confirm Registry `200` then conditional `304`, schema `1`, and no artifact request when empty.
- Verify local-only catalog entries and installed/enabled state are unchanged.
- Render Marketplace as admin and confirm unauthorized roles cannot mutate.
- Monitor addon audit events and recovery health after release.

For deployment rollback, stop new Marketplace operations, inspect active journals, roll application code back through the approved deployment mechanism, retain all addon evidence, verify database compatibility, then rerun preflight and health. Do not use addon cleanup as deployment rollback.

## External signed-release gate and known debt

An empty production Registry is valid. The future production gate is: authorize publisher/key; ingest, validate, review, sign, approve, and publish on the server; expose the Registry item; configure the matching client public-key binding; then test download, verify, install, update, rollback, cleanup, and audit.

Ed25519 verification signs exact raw ZIP bytes and currently performs a bounded in-memory read capped at 20 MiB by default. No stable browser harness is installed, so HTTP and Filament/Livewire coverage is the current UI gate. Repository-wide unrelated formatter debt is outside this release. Sodium availability must always be verified on the actual target runtime; local/CI availability is not production evidence.
