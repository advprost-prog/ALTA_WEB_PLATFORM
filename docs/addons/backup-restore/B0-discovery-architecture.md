# B0 — Backup & Restore discovery, functional specification and architecture

Status: proposal for owner approval; discovery only. Date: 2026-07-15. Audited platform commit: `775785e74c0d20f0f651270049579d983cb99af8`.

## Reading key

- **Fact** — confirmed in this repository or by the read-only runtime diagnostics in Appendix A.
- **Recommendation** — proposed product or engineering decision.
- **Decision required** — owner approval is required before B1.
- **Unresolved** — cannot be established from this repository and needs a deployment check or product decision.

## 1. Executive recommendation

**Recommendation.** Build Backup & Restore as one production **module** in a separate `ALTA_ADDON_BACKUP_RESTORE` repository, addon code `alta.backup-restore`, Composer package `alta/addon-backup-restore`, namespace `Alta\BackupRestore`, and manifest name `module.json`. Do not add its implementation to ALTA_WEB_PLATFORM or reuse Marketplace code-backup storage as business-backup storage.

The safest useful MVP is **selectable backup profiles with database plus explicitly selected user-file roots**. Ship it incrementally: SQLite manual CLI backup first, then selected files and archive integrity, then guarded same-install restore. The approved MVP boundary ends after B6: local/private destination, synchronous CLI, same-install restore, mandatory pre-restore safety backup, Filament controls, permissions and audit. PostgreSQL and MySQL/MariaDB, schedules/queues, remote storage, encryption, and fresh-install restore follow only after their gates.

MVP content defaults:

| Content | MVP | Reason |
|---|---:|---|
| Application database | Yes | Primary business state |
| Selected `storage/app/public` user uploads | Yes, opt-in root allowlist | Product images and uploaded content are not reproducible |
| Other configured disk/directories | No generic arbitrary paths | Avoid unsafe scope; add only named, validated roots |
| Installed/enabled addon metadata | Yes, manifest metadata only | Needed for compatibility/preflight; restore after files/DB only when compatible |
| Addon package archives and live addon code | No | Reinstallable supply-chain artifacts/source, separate lifecycle |
| Application source, `vendor`, built assets | No | Deployment artifacts, not business state |
| `.env`, credentials, signing/encryption private keys | Never in MVP | Secret boundary; Marketplace private keys must never be included |
| Marketplace quarantine/staging/backups/journals/recovery | No | Recursive/self-inclusion and recovery-domain collision |
| caches, sessions, logs, temp files | No | Reproducible or ephemeral runtime data |

**Recommendation.** Restore only into the same installation in MVP. A fresh compatible installation is a later, separately approved disaster-recovery workflow because bootstrapping source, `.env`, dependencies, signing trust and the Backup addon itself is outside an addon archive.

## 2. Evidence-based current-state audit

### 2.1 Addon architecture and lifecycle

**Facts.** `AddonDiscovery::manifestCandidates()` discovers `modules/*/*/module.json` and `extensions/*/*/extension.json`; `scan()` validates and SHA-256 hashes manifests, separates duplicate codes, and `sync()` persists them. `AddonManifestValidator` requires `code`, `type`, `name`, `version`, `vendor`, `enabled_by_default`, `dependencies`, `settings_schema`, and `compatibility`. Modules additionally require arrays for `permissions`, `menu`, `migrations`, `seeders`, and `routes`; extensions require `hooks`. Codes must match `/^[a-z0-9]+([._-][a-z0-9]+)*$/`. Compatibility is normalized with app, Laravel and PHP fields, although lifecycle currently enforces only PHP and Laravel constraints.

`AddonServiceProvider` registers runtime singletons and boots enabled addons only when `system_addons` exists. `AddonManager::bootAddon()` checks enabled state and manifest, then registers an allowed service provider, namespaced views, web/admin routes and hooks. Provider class/path must remain under the addon directory and expected `Modules\Vendor\Name\` or `Extensions\Vendor\Name\` namespace (`AddonLifecycle::serviceProviderPath()`); route files must resolve inside the application tree. Boot failure disables the addon and records sanitized failure evidence.

`AddonLifecycle` wraps local install/enable/disable/uninstall state changes in `DB::transaction()`. Install validates installed dependencies and compatibility; enable also requires enabled dependencies and a present manifest. Uninstall is soft: files and migrations are not removed. `dependencyIssues()` validates requirements, while Marketplace `DependencyResolver` provides remote dependency planning and version/cycle states. No dependent check was found in core local `disable()`/`uninstall()`; B1 must not claim core prevents breaking dependents.

`system_addons`, `system_addon_settings` and append-oriented `system_addon_events` persist identity/state/settings/events. `AddonEventLogger` writes event, level, message and JSON context and invalidates recovery-health cache for operation-related events. This is operational audit evidence, not cryptographically immutable audit storage.

**Facts / constraint gap.** Manifest migration and seeder arrays are validated but `AddonManager` does not execute them. A production addon provider must own isolated `loadMigrationsFrom()` behavior; migrations must be idempotently discovered and must not execute on provider boot. Installation/removal semantics for production-addon migrations require an explicit platform contract in B1.

Fixtures `core.products` and `core.theme-maker` prove only the minimal boot contract. `core.promotions`, `core.integrations`, and `core.seo` are catalog placeholders. None is a production design template.

### 2.2 Marketplace artifact, promotion and recovery

**Facts.** Remote archives are downloaded by `ArtifactDownloader` into the `addons` disk quarantine using an exclusive temporary file and size/checksum checks. `QuarantinedArtifactInspector`, `ArtifactSignatureVerifier`, `PublisherTrustStore`, and `ArtifactTrustEvaluator` enforce manifest identity, SHA-256 and optional Ed25519 trust. Production trusted keys are empty; the configured test public key exists only when `APP_ENV=testing`.

`ArchiveSafetyValidator` rejects absolute/drive/NUL/control/`..` paths, case collisions, symlinks and special files, nested/multiple root manifests, excessive entry/file/total sizes, path lengths and compression ratios. `SafeArchiveExtractor` streams each ZIP entry, recomputes sizes and SHA-256, restricts extraction to staging, and sets 0644 files. `ArtifactStagingManager` extracts to a temporary directory, writes evidence, then atomically renames it into final staging.

`ArtifactPromotionManager` obtains a cache lock, verifies staging again, creates and integrity-records a code rollback copy, builds a candidate tree, compares inventory hashes, and uses same-filesystem renames for promotion/compensation. `VerifiedAddonInstallOrchestrator` persists JSON state transitions, preserves pre-operation addon DB attributes, disables/promotes/discovers/registers/enables/verifies, and compensates through promotion rollback. `AddonRecoveryService`, `AddonRecoveryHealthService`, `BackupIntegrityService`, and `RecoveryDataCleanupService` assess incomplete operations, verify managed code backups, retain referenced/last-known-good copies, and require explicit safe recovery/cleanup.

**Recommendation.** Reuse these patterns, not these services or directories. Backup & Restore owns business-data locks, journals, integrity format and recovery. It may emit sanitized summary events through `AddonEventLogger` and expose an adapter to existing Marketplace recovery health, but must not recursively include `storage/app/addons/**` or make Marketplace core understand backup profiles/artifacts.

### 2.3 Database environment

**Facts.** `config/database.php` defines SQLite (default), MySQL, MariaDB, PostgreSQL and SQL Server. `.env.example` selects SQLite at `database/database.sqlite`. PostgreSQL has `search_path=public` and configurable SSL mode; MySQL/MariaDB use strict mode and `utf8mb4`. SQL Server is configured by the Laravel skeleton but is outside requested product scope.

The audited CLI has PHP 8.3.6, `PDO`, `pdo_sqlite`, `sqlite3`, `pdo_pgsql`, and `pgsql`; it lacks `pdo_mysql`. `/usr/bin/sqlite3`, `pg_dump`, and `psql` exist; `mysqldump` and `mysql` do not. These are workstation facts only. No application use of `exec`, `shell_exec`, `proc_open`, Symfony Process, Laravel Process, or native database dump utilities was found. `symfony/process` is a Laravel dependency but is not used by application code.

SQLite supports file snapshots with engine coordination; an uncoordinated copy can be inconsistent with WAL/journal files. PostgreSQL and InnoDB provide MVCC consistent snapshots, but native logical dump tools are the practical streaming route. MyISAM or other non-transactional MySQL tables cannot be promised a transactionally consistent online dump. Full database restore cannot generally be wrapped in one portable Laravel transaction, especially when DDL is present.

### 2.4 Storage and filesystem

**Facts.** `config/filesystems.php` defines private `local` at `storage/app/private`, broad local `addons` at `storage/app`, public at `storage/app/public`, and an S3 configuration. The public symlink maps `public/storage` to `storage/app/public`. `.env.example` selects `FILESYSTEM_DISK=public`. Product, variant, banner and theme uploads use the public disk; image services persist and resolve `storage_path`, so this is the confirmed business/user-data root. `database/database.sqlite` is business state. Application source and demo public images are deployment/reproducible content.

Marketplace owns at least `storage/app/addons/quarantine`, `staging`, `backups`, `promotion-journal`, `install-journal`, `recovery-journal`, and `cleanup-journal`. Framework cache, sessions/testing, and logs live under `storage/framework/**` and `storage/logs/**`. These and the Backup addon destination/temp/journal roots must be hard exclusions even if a selected root is their ancestor.

Laravel/Flysystem local streaming is available; current safe extraction uses `ZipArchive::getStream()`, `fopen`, bounded chunks and incremental hashes. ZIP support is present. The repository has no generic backup library and no central reusable path-allowlist helper; Marketplace safety is scoped to its classes. Local files are commonly created as 0644 and directories as 0755; actual web/CLI ownership parity remains a deployment check.

**Recommendation.** Canonicalize every selected root and entry using `lstat`/`realpath`; roots must equal a configured allowlist entry, not merely share a textual prefix. Reject symlinks (including root symlinks), sockets, devices, FIFOs and hard-link ambiguity; include only regular files/directories. Sort normalized UTF-8 forward-slash relative paths bytewise, reject duplicates/case collisions and `..`, and re-check containment immediately before opening. Track inode/device where available to detect replacement. Never follow the public symlink; inventory its target root directly. Exclude the artifact destination and all temp roots by canonical identity to prevent self-inclusion.

### 2.5 Queues and scheduler

**Facts.** Queue default is `database`; sync, database, Beanstalkd, SQS, Redis, deferred, background and failover configs exist. Jobs, batches and failed-jobs tables exist. No application `ShouldQueue` job was found: the notification “queue” is an application outbox processed by commands. `routes/console.php` contains no schedules, no `withoutOverlapping()` and only the sample command. Composer's `dev` script runs `queue:listen --tries=1 --timeout=0`, but that is local development, not hosting evidence. Failed jobs use `database-uuids`.

**Recommendation.** MVP must support synchronous CLI execution and chunk-stream within one controlled process; do not promise web-request execution. B7 adds queued and scheduled modes using the same persisted run state machine. Scheduler triggers a dispatcher with `withoutOverlapping()` plus the addon operation lock; a worker performs the run. Cron-only/no-worker hosting can invoke the synchronous command directly. Cancellation is cooperative: set `cancel_requested_at`, check between inventory/export/archive units, stop before the next unit, close streams, mark cancelled and clean partials. Never kill a database dump process blindly; request graceful termination, wait, then mark manual cleanup if process state is uncertain.

### 2.6 Libraries and dependencies

**Facts.** Direct production dependencies are Laravel 12 and Filament 4 on PHP 8.3. Installed core facilities include Flysystem local, Symfony Process, `ZipArchive`, hash/OpenSSL/sodium extensions. The configured S3 adapter package `league/flysystem-aws-s3-v3` is not installed. No dedicated backup/encryption/archive-streaming package is present.

**Recommendation.** B1–B6 need no backup framework. Use Laravel filesystem/locks, PHP streams/hash, `ZipArchive` for initial ZIP64-capable archives, and Symfony Process only behind database adapter capability checks. `ZipArchive` writes its central directory at close: artifacts are incomplete until close succeeds, ZIP64 support and seekable local temp space must be verified, and remote destinations require local finalization before streaming upload. Evaluate a streaming TAR implementation only if ZIP64/temporary-space constraints prove unacceptable; adding a library needs license/maintenance review. B9 may add `league/flysystem-aws-s3-v3` (MIT, ecosystem-standard but additional AWS SDK/maintenance surface) for S3-compatible destinations. Native sodium secretstream is sufficient for optional encryption; avoid a new crypto package unless envelope interoperability is required.

### 2.7 Filament, permissions, notifications and audit

**Facts.** Filament auto-discovers resources/pages. Resources use tables, filters, badges, confirmation actions and database notifications. `SystemAddonResource` displays status/errors/events and confirmation-gates lifecycle actions. `UserRole` has Admin, Manager and ContentManager; admin bypasses policies. Artifact promotion/review/staging policies accept Admin and legacy string `super_admin`. There is no general granular permission table; manifest permissions are exposed by `AddonRegistry::permissions()` but not a complete RBAC implementation. Marketplace and addon event UIs use Ukrainian labels, with Marketplace translations in `lang/uk` and `lang/en`.

**Recommendation.** Introduce explicit capabilities `alta.backup-restore.view`, `.create`, `.download`, `.verify`, `.manage-profiles`, `.manage-destinations`, `.manage-schedules`, and `.restore`. Until platform RBAC consumes them, fail closed to Admin for mutation/download and require a distinct restore authorization check plus recent password re-authentication. Never infer restore privilege merely from panel access.

UI boundaries: Profile resource; History table with state badges/progress/event timeline; manual “Run” action; protected streamed Download action; Verify action; Destinations and Retention settings; Schedules (B7); Diagnostics page. Restore is a wizard: archive selection, immutable preflight report, typed installation identifier and backup checksum suffix, re-authentication, explicit acknowledgement of maintenance/downtime and mandatory safety backup, then a separate execution action. No one-click restore.

### 2.8 Deployment and hosting constraints

**Facts.** Local defaults are SQLite, database queue/cache, file sessions, local/public disks. Maintenance mode uses a file. The codebase provides CLI commands and local long-lived queue dev tooling, but contains no HOSTiQ-specific contract, production cron/worker setup, memory/time limits, process-function policy or filesystem ownership guarantee.

**Assumptions.** Linux VPS commonly permits CLI workers/native clients; shared hosting may prohibit `proc_open`, omit binaries, enforce short HTTP timeouts, separate CLI/web users and limit disk/inodes/memory. These are not confirmed for HOSTiQ.

**Deployment checks required.** Writable/private roots; same filesystem for atomic rename; available/free bytes and inodes; maximum file/archive/count; 64-bit PHP and ZIP64; `disable_functions`; `proc_open`; exact native-client versions; DB credentials/permissions; table engines; PDO drivers; DB/network reachability from CLI; cron and worker availability; CLI/web UID/GID/umask; maintenance-mode control; Flysystem adapter/destination connectivity; temporary quota; PHP memory/time limits. Large data must be streamed with bounded buffers; web requests only dispatch and poll.

## 3. Proposed addon identity and repository boundary

| Item | Recommendation |
|---|---|
| Repository | `ALTA_ADDON_BACKUP_RESTORE`, one production addon per repository |
| Addon type/code | module / `alta.backup-restore` |
| Display name | `ALTA Backup & Restore` |
| Composer package | `alta/addon-backup-restore` |
| Namespace/provider | `Alta\BackupRestore`; `Alta\BackupRestore\BackupRestoreServiceProvider` |
| Manifest/location after install | `module.json`; `modules/Alta/BackupRestore` |
| Storage namespace | private disk root `alta-backup-restore/` |
| Tables | `abr_` prefix |
| Capabilities | `alta.backup-restore.*` |
| CLI prefix | `alta:backup-restore:*` |

**Facts.** Repository search found no conflicting `alta.backup-restore`, `abr_`, namespace, package or CLI prefix. The current provider path resolver expects a `Modules\...` namespace for locally promoted modules, which conflicts with the preferred vendor namespace.

**Decision required / B1 contract gap.** Approve the identity above and resolve packaging without weakening containment: either production manifest v2 maps a package namespace safely, or the installed provider uses `Modules\Alta\BackupRestore\BackupRestoreServiceProvider` while internal code remains `Alta\BackupRestore`. Do not bypass `AddonLifecycle::serviceProviderIsAllowed()` or patch a one-off exception into core.

Addon repository owns provider, configuration, migrations, domain/application/infrastructure/UI/CLI/jobs/tests and translations. Platform integrations are limited to the manifest/runtime, lifecycle event logging, Filament registration hooks, cache locks, maintenance mode and recovery-health summaries. Future SDK candidates are generic safe path policy, archive-limit validator, addon migration/RBAC contract and operation-health interface. Forbidden in first implementation: backup adapters, tables, UI, commands, archive semantics, destination credentials or restore orchestration in Marketplace core.

## 4. Recommended MVP scope and explicit exclusions

**Recommendation.** Selectable profiles are more operationally useful than “database only” while still bounded by named roots. Every profile always includes the database and may include `public_uploads`; custom absolute paths are not MVP. Default profile includes DB plus public uploads, local private destination, daily/count retention fields present but schedule execution deferred to B7.

MVP supports manual local backup and guarded same-install restore for SQLite. It records addon code/version and installed/enabled snapshots for compatibility; it does not archive addon code. Restoring addon state is limited to reapplying installed/enabled flags only for already-present, identity/version-compatible addons and only after health checks; otherwise preflight blocks or reports operator action.

Explicitly excluded: application/source deployment, `vendor`, `node_modules`, public build output, `.git`, `.env`, `APP_KEY`, DB passwords, AWS/API credentials, ordinary config secrets, private signing/encryption keys, Marketplace data, addon packages/live code, caches, views/config/routes cache, sessions, queues/jobs/failed jobs as runtime control data (business outbox tables remain part of DB), logs, temp files, safety backups as recursive inputs, arbitrary filesystem roots, remote/S3, encryption, schedules/queues, fresh-install restore, cross-engine restore and point-in-time/incremental backup.

## 5. Supported runtime/database matrix

| Engine | Backup / restore | Requirements | Consistency and streaming | Phase / limitations |
|---|---|---|---|---|
| SQLite | Use SQLite online backup API if exposed safely; otherwise acquire exclusive addon write gate, checkpoint WAL, create a same-filesystem snapshot via SQLite-aware mechanism, then stream file. Restore to candidate DB file, validate with SQLite integrity check, maintenance mode, atomic replace including journal cleanup | `pdo_sqlite`, `sqlite3`; optional compatible `sqlite3` binary only after capability check | Short write pause/exclusive coordination; file stream. Never raw-copy active WAL DB | MVP B2/B4. Same host/engine; disk space for source+safety+candidate+archive |
| PostgreSQL | Custom-format or plain streamed `pg_dump` via Symfony Process argv; restore with version-compatible `pg_restore`/`psql` into controlled target | `pdo_pgsql`; `pg_dump` and `pg_restore`/`psql`; `proc_open`; sufficient DB privileges | MVCC consistent dump; stream stdout/stderr separately with limits. `--no-owner --no-acl`; credentials via protected environment/passfile, never argv | B8. Block if binaries/version/privileges absent; no pure-PHP fallback promised |
| MySQL/MariaDB | Stream `mysqldump` and restore with `mysql` client | `pdo_mysql`; matching clients; `proc_open`; privileges | `--single-transaction` for transactional tables; metadata/DDL changes and non-InnoDB need lock/downtime strategy | B8. Detect engines; block online consistency when non-transactional tables exist |

SQL Server is unsupported. Cross-engine restore is unsupported. Native commands must be fixed argv arrays with validated executable paths; never concatenate shell commands. Runtime diagnostics must distinguish configured, PDO-capable, binary-capable and restore-capable.

## 6. Component architecture

| Component | Responsibility |
|---|---|
| `BackupRestoreServiceProvider` | Bind contracts, merge config, load isolated migrations/translations/views, register CLI/Filament/scheduler hooks only; no I/O or run on boot |
| Configuration/capabilities | Limits, allowlisted roots, temp/artifact roots, engine and destination capability probes |
| Profile service | Validate immutable run snapshot of include/exclude/retention/destination choices |
| `BackupRunOrchestrator` | State transitions and collaboration; no engine/archive implementation |
| DB adapters | `SqliteBackupAdapter`, later PostgreSQL/MySQL; preflight/export/verify/restore contracts |
| File inventory/streamer | Canonical safe traversal, deterministic inventory, bounded read, change detection |
| Archive writer | Versioned ZIP64 layout, bounded streams, partial marker/final close |
| Manifest/checksum writer | Canonical JSON, payload checksums, source/compatibility metadata |
| Encryption envelope | B9 decorator around finalized archive; no key persistence |
| Destination adapters | Local private MVP; S3-compatible B9; temp-to-final atomic promotion contract |
| Retention service | Protect active/safety/manual artifacts; two-phase deletion and tombstone/event |
| Scheduler/queue jobs | B7 dispatch/run/verify/retention with unique locks and persisted progress |
| Restore preflight | Read-only validation/dry-run plan and immutable fingerprint |
| `RestoreOrchestrator` | Journaled fail-closed mutation sequence and compensation |
| Maintenance coordinator | Enter/exit only when owned; preserve prior maintenance state |
| Safety backup service | Mandatory full selected-scope local safety artifact before mutation |
| Audit bridge | Addon run events plus sanitized platform `AddonEventLogger` summaries |
| UI/CLI | Thin authorization/validation adapters over application services |
| Cancellation | DB token checked at safe boundaries; no unsafe asynchronous kill |
| Health diagnostics | Stale locks/runs, missing artifacts, partials, capability drift, recovery needed |

Use interfaces for `DatabaseBackupAdapter`, `ArchiveWriter`, `Destination`, `OperationLock`, `MaintenanceCoordinator` and `HealthReporter`. Keep archive parsing separate from creation and backup separate from restore. No “BackupService” god object.

## 7. Data model

All tables use UUID/ULID public identifiers where artifacts/events leave the DB, timestamps, and foreign keys where deletion semantics are safe.

| Entity | Essential fields / relationships / indexes |
|---|---|
| `abr_backup_profiles` | id, name, enabled, include_database, selected_roots JSON (logical IDs only), exclusions JSON, destination_id, retention_count/days, created_by, timestamps; index enabled/destination; archive stores immutable snapshot |
| `abr_backup_destinations` | id, type (`local` MVP), name, config JSON containing non-secret logical references, credential_reference nullable, enabled, last_check fields; never raw keys; referenced by profiles/artifacts |
| `abr_backup_runs` | ULID, profile_id nullable, trigger, state, requested/started/finished/cancel_requested timestamps, actor, DB engine, progress counters, profile_snapshot JSON, failure_code/sanitized_error, lock token; indexes state+started, profile+created; state: requested→preflight→inventory→exporting→archiving→verifying→promoting→completed; any active→cancelling→cancelled or failed/manual_intervention |
| `abr_backup_artifacts` | id, run_id, destination_id, relative key, format/version, size, archive SHA-256, manifest SHA-256, encryption metadata without key, verification/status, expires/protected/deleted timestamps; unique destination+key; indexes checksum/status/expiry |
| `abr_backup_schedules` | id, profile_id, cron, timezone, enabled, last/next dispatch; present in B1 schema only if approved, execution B7; unique profile+cron+timezone |
| `abr_restore_runs` | ULID, artifact/source reference, state, preflight JSON+fingerprint, confirmation actor/time, safety_backup_run_id, maintenance ownership, journal cursor, failure/compensation/manual fields, timestamps; indexes state+started/artifact; states: requested→preflight_passed→confirmed→safety_backup→maintenance→db_restore→files_restore→addon_state→health_check→completed; compensation/failed/manual branches |
| `abr_run_events` | id, backup_run_id or restore_run_id, sequence, level, event, redacted context, created_at; unique run+sequence, indexes run/time and event/time; retention follows run but security events retained by policy |

**Recommendation.** Keep an addon-owned event table because high-volume progress and persisted restore journal do not fit `system_addon_events`; bridge only lifecycle summaries. Archive manifest holds full deterministic file inventory/checksums, source versions/schema list/exclusions and profile snapshot. DB holds searchable summaries, state and artifact locator. Destination secrets and encryption keys belong in deployment-owned secret storage via opaque reference; never in rows or manifests.

Deletion is soft/tombstoned before physical removal. Safety artifacts are protected until the restore and its recovery window close. Profile/destination deletion is blocked while referenced or converted to inactive records.

## 8. Storage and destination model

MVP uses a dedicated private local root such as `storage/app/private/alta-backup-restore/{artifacts,tmp,journals,safety}` with 0700 directories and 0600 files where supported. The destination path is configured as a logical disk plus addon-owned prefix; user-supplied absolute paths are forbidden. Temp and final artifact must be on the same filesystem for rename; otherwise use copy+fsync+checksum+rename semantics and report weaker atomicity.

Only logical source `public_uploads` maps to the canonical `storage/app/public` root. Future named roots require code/config review. Remote/S3 destination is B9 only after the adapter is installed: multipart streaming upload, server-side checksum where available, temporary object key, copy/promote to final key, and lifecycle/retention compatibility. FTP/SFTP are not recommended for v1 because atomic promotion and integrity semantics vary.

Downloads stream through an authorized controller/action from private storage; never expose a public symlink. Prefer a single-use, short-lived signed application route bound to user, artifact and checksum, with audit and `Content-Disposition`; local web-server acceleration is allowed only behind authorization.

## 9. Archive format

Stable identifier: `alta.backup-restore`; format version: `1.0`. Extension: `.abr.zip` (or `.abr.enc` for future encrypted envelope).

```text
abr-format.json                 # tiny identity/version, written first
manifest.json                   # canonical final metadata and inventory
database/database.meta.json
database/payload.sqlite         # or dump payload in later adapters
files/public_uploads/<relative paths>
checksums.sha256                # payload entries, sorted
```

Manifest fields: format ID/version; artifact/run UUID; `complete=true`; created UTC timestamp; source application name/version/build/commit when available; source installation fingerprint (non-secret); addon code/version; PHP/Laravel versions and required extensions; DB driver/server/client/encoding/schema and migrations fingerprint/list; immutable profile snapshot; logical root mappings; normalized exclusions; sorted file inventory (`logical_root`, path, size, mtime informational, SHA-256); DB payload size/SHA-256; checksum-file SHA-256; compression; consistency notes/files-changed warnings; compatibility minima; safety-backup flag and parent restore ID; optional encryption envelope metadata.

Every payload has SHA-256. The final archive SHA-256 and byte size are computed while reading the closed `.partial` ZIP for promotion and are stored in `abr_backup_artifacts` and a detached sidecar, because an archive cannot reliably contain its own hash. On import, the supplied/stored hash is checked before opening; internal checksums are checked before mutation.

Use deflate for text/dumps and store already-compressed media when detectable. Creation is chunk-streamed and never loads a whole payload into PHP memory. `ZipArchive` needs seekable local temp and writes the central directory only on close; preflight requires 64-bit/ZIP64 support for predicted limits. Keep `.partial` outside visible artifacts, write `complete=true` only in the final manifest immediately before close, fsync where supported, verify by reopen, then rename. Failed/incomplete ZIPs remain quarantined partials with DB failure state and are never downloadable/restorable.

Future encryption wraps the entire closed archive using libsodium `secretstream_xchacha20poly1305`: header with envelope version, algorithm, chunk size, random stream header, key ID and wrapped data-key metadata; authenticated chunks and final tag. Data keys are random per artifact and wrapped by an external operator-owned KEK/KMS. No password/key in archive, DB or logs.

## 10. Backup algorithm

1. Authorize capability and record actor/trigger; create `requested` run.
2. Load and validate profile, snapshot it, reject unknown roots/exclusions/destination.
3. Probe DB/PDO/binary/process/ZIP64/storage permissions and limits; validate destination by non-destructive probe.
4. Estimate DB/files/temp/final/safety space and inodes with safety margin; fail closed when unknown exceeds policy.
5. Canonicalize roots and destination/temp/Marketplace exclusions; prove no overlap or recursion.
6. Acquire global addon operation lock and per-profile/destination locks with owner token/TTL heartbeat; reject unresolved restore/backup state.
7. Establish DB consistency using engine adapter. For SQLite coordinate writers and snapshot/checkpoint; later engines use native snapshot-capable dump.
8. Inventory regular files deterministically without following links/special files; capture size, mtime and identity.
9. Stream DB export to a protected temporary payload while hashing; validate adapter output.
10. Create `.partial` archive; stream DB and each file with incremental SHA-256 and bounded buffer. Re-stat before/after read.
11. If an upload changes: retry that file once when stable; otherwise default profile policy fails the run. A future “best effort” profile may complete with explicit `inconsistent_files` warning but cannot be a restore-grade artifact.
12. Write canonical checksums/manifest and close archive; optional encryption decorator is skipped in MVP.
13. Hash and size the closed archive, reopen, validate identity, limits and all checksums.
14. Atomically promote temp artifact to final destination; persist artifact and `completed` state.
15. Emit redacted audit summary, apply retention only after success, then clean DB payload/partials and release owned locks. On any error close streams, persist failure and cleanup; never publish partials.

Database and files are not one atomic snapshot. MVP obtains a database-consistent snapshot and detects file changes; the manifest explicitly records the consistency window. Restore-grade success requires no unresolved changed files.

## 11. Restore algorithm

1. Admin with `.restore` selects an artifact; persist a restore run. No mutation occurs.
2. Quarantine/copy external input into private temp; validate size/hash, ZIP identity/version, central directory, path/count/ratio limits, no links/special entries, canonical manifest and all checksums. Decrypt first only in future B9.
3. Run read-only compatibility preflight: addon/format versions; platform version/build; same installation fingerprint; DB engine/version/encoding; migration/schema fingerprint; PHP/extensions/binaries; logical storage mappings; addon availability/versions; encryption key; free bytes/inodes; permissions/ownership; maintenance and queue capability. Produce dry-run plan and immutable fingerprint.
4. Fail closed on blockers. Operator re-authenticates, types installation identifier and checksum suffix, acknowledges downtime/data loss, and confirms the unchanged fingerprint.
5. Acquire restore/global/destination locks; re-run preflight and reject drift. Coordinate scheduler/queue: prevent new Backup jobs and wait for addon-owned active jobs; do not indiscriminately delete platform queues.
6. Create and fully verify a mandatory local pre-restore safety backup of current DB plus every file root that will mutate. If it fails, do not restore.
7. Enter maintenance mode while remembering whether the app was already down; heartbeat the journal. Extract DB/files only into protected candidates and validate again.
8. Restore DB with engine adapter. SQLite: validate candidate then atomic file swap under stopped application writers. Server DBs later use an explicit restore plan and safety artifact; do not promise universal transaction rollback.
9. Restore each file root by building/verifying a candidate tree and same-filesystem rename swap, retaining old tree until final health passes. Never overlay entry-by-entry when an atomic root swap is possible.
10. Reconcile addon installed/enabled state only for present compatible addons; never install package code from this archive.
11. Rebuild configuration/view/route caches only through an approved platform operation; preserve deployment-specific cache policy. Run DB connectivity/integrity, migrations fingerprint, storage read, critical application and addon health checks.
12. On success remove old candidates after recovery window, mark complete, release locks, and exit maintenance only if this run entered it. Audit and retention cleanup follow.

Failure behavior:

- Before mutation: mark failed, delete candidates, no safety compensation needed.
- After safety backup/before mutation: retain protected safety artifact, clean candidates, exit owned maintenance.
- During DB restore: stop; mark compensation required; restore DB from safety backup using journaled adapter steps. If outcome is uncertain, remain in maintenance/manual intervention.
- During files restore: retain old roots; swap them back. If DB already succeeded, restore DB from safety backup too, producing a coherent pre-restore state.
- After DB success/file failure: compensate both domains from the same safety snapshot; never leave a mixed state knowingly.
- Final health failure: compensate while retained old roots/safety DB are available; if compensation health fails, remain in maintenance and manual intervention.
- Maintenance exit failure: data may be healthy, but mark manual intervention and retain ownership token/instructions; never call `up` if maintenance pre-existed.

## 12. Failure and compensation model

`abr_restore_runs` plus sequenced `abr_run_events` is the authoritative journal. Each mutation step records intent, candidate/source/target identifiers and hashes before action, then completion after verification. Steps are idempotent: create candidate, validate candidate, swap old→rollback, swap candidate→live, verify, delete only after terminal success. On restart, health diagnostics derive the next safe action from journal plus filesystem/DB evidence, never merely from elapsed state.

Atomic rename applies only within one filesystem and does not make DB+files atomic. PostgreSQL/MySQL DDL/data restore may commit outside one transaction. Compensation therefore uses mandatory pre-restore safety backup and retained old file roots, not a false universal rollback claim. Retry only read/preflight/upload/verification and explicitly idempotent steps. Do not automatically retry an uncertain DB mutation. Such a run becomes `manual_intervention_required` with redacted operator steps.

Heartbeat timestamps identify stale operations, but TTL expiry never authorizes a second mutation. A recovery command/UI must inspect owner token, process evidence, candidates, safety artifact and journal, then offer only fingerprinted safe plans. Integrate a summary health provider with ALTA Marketplace recovery: healthy/degraded/manual, count, oldest age, run ID and sanitized reason. Marketplace does not execute Backup compensation.

## 13. Security model

- Least privilege: separate view/create/download/verify/configure/restore capabilities; Admin-only fallback; restore re-authentication and two-stage confirmation.
- Private storage: no public disk/symlink for artifacts; 0700/0600 target, ownership diagnostics, authorized streaming download and audit.
- Archive input: deny traversal, absolute/control/case-collision paths, symlinks/hard links/special files; cap compressed bytes, uncompressed bytes, ratio, entries, path length, nesting and per-file size; preflight before extraction and enforce limits while streaming.
- Filesystem: canonical allowlisted logical roots, no arbitrary paths, re-check containment/identity, exclude destination/temp/Marketplace/runtime roots and recursive self-inclusion.
- Commands: Symfony Process argv arrays, allowlisted absolute executable, no shell, no secrets in argv; protected environment/passfile, bounded stderr, timeout/cancellation and exit validation. Detect disabled process functions and block adapters.
- Secrets: never include `.env`, credentials, `APP_KEY`, API keys, destination keys, encryption keys or Marketplace private signing keys. Redact connection URLs, paths where sensitive, usernames, command environment and dump output from events.
- Integrity: SHA-256 detects corruption, not publisher authenticity. Same-install local artifacts rely on protected storage/access; imported/fresh-install trust needs a later signature/authenticity design.
- Audit: append-oriented run events with actor/time/fingerprint; restrict update/delete and export summaries to platform audit. Existing DB events are not immutable, so external append-only/WORM forwarding is a later option.
- Encryption: not MVP. B9 uses libsodium secretstream envelope and external key ownership/recovery ceremony. Losing KEK makes backups unrecoverable; rotation rewraps data keys, not payload. Never commit or package secrets.

## 14. Filament, CLI, queue and scheduler design

Filament resources/pages: Profiles, Backup History/Artifact detail, Destinations, Restore Preflight/Run, Schedules (B7), Diagnostics. Tables show status badge, profile, trigger/actor, started/duration, bytes/files, destination, integrity and expiry. Poll persisted progress; do not bind the request to the work. Destructive actions require confirmation; retention cannot delete protected/active/reference artifacts.

CLI names:

- `alta:backup-restore:backup {profile} [--wait]`
- `alta:backup-restore:verify {artifact}`
- `alta:backup-restore:restore:preflight {artifact}`
- `alta:backup-restore:restore {restore-run} --confirmation=...` (interactive by default; non-interactive requires a separately approved automation token policy)
- `alta:backup-restore:cancel {run}`
- `alta:backup-restore:retention [--dry-run] [--execute]`
- `alta:backup-restore:doctor`

B7 jobs are thin, unique, state-aware wrappers: `RunBackupJob`, `VerifyArtifactJob`, `RunRetentionJob`; restore remains synchronous CLI initially even after queues exist because operator control and maintenance recovery are safer. Jobs use explicit timeout greater than database client timeout, one controlled retry only before mutation, `failed()` to persist state, and queue `alta-backup-restore`. Scheduler only dispatches due profiles and uses both framework overlap mutex and persistent run lock.

## 15. Shared-hosting strategy

Provide a first-class CLI synchronous path callable by cron and resumable/stale diagnostics. SQLite backup/restore can operate without external process execution if the selected PHP/SQLite snapshot implementation passes capability tests. PostgreSQL/MySQL adapters are unavailable when native tools or `proc_open` are restricted; show a precise diagnostic, never fall back to unsafe SQL row iteration silently.

No-worker hosting runs manual/cron CLI backups; UI can create a request and instruct the operator/cron, but must not execute long work in HTTP. Low-memory behavior uses 1–8 MiB bounded chunks, paged inventory persistence or manifest spool, and no all-file arrays for large trees. Short web timeouts affect polling only. Large sites need preflight quotas, ZIP64, native dumps, upload-change policy and maintenance planning for restore. If CLI and web ownership differs, block restore until writable ownership/umask is proven.

## 16. Phased roadmap and effort

Estimates assume one senior Laravel engineer, code review, CI, Linux test environments, timely owner decisions, and no platform-runtime redesign. Ranges include automated tests and documentation, not waiting time or Marketplace publisher onboarding.

| Phase | Time | Deliverables / dependencies / test and manual gate | Key risk |
|---|---:|---|---|
| B1 | 1–2 weeks | Separate skeleton, production manifest contract, provider/config, isolated migrations/models/states, protected namespace, capabilities, contract tests; owner identity/schema gate | Namespace/migration/RBAC platform contract |
| B2 | 2–3 weeks | SQLite manual local DB backup, capability/space checks, run orchestration/CLI, checksum/partial promotion; unit+SQLite integration+large stream; restore not included | Safe live SQLite snapshot method |
| B3 | 2–3 weeks | Selected public uploads, safe deterministic inventory, ZIP64 archive v1, integrity/retention core; adversarial path/archive and mutation tests; approve file-change policy | Concurrent uploads, huge inventories |
| B4 | 3–4 weeks | Import/quarantine, preflight/dry run, same-install SQLite DB/file restore, maintenance and health; crash-point integration/manual rehearsal | Cross-domain atomicity |
| B5 | 2–3 weeks | Mandatory safety backup, journal, compensation, stale recovery/health bridge; fault injection at every mutation boundary; disaster drill gate | Compensation correctness |
| B6 | 2–3 weeks | Filament/CLI UX, download auth, granular permissions adapter, events/translations; policy/browser/CLI tests; security review | Current coarse RBAC |
| B7 | 2–3 weeks | Queue/schedule/cooperative cancel/retention diagnostics; worker/cron/no-worker tests; hosting gate | Worker timeout/lock drift |
| B8 | 4–6 weeks | PostgreSQL and MySQL/MariaDB native adapters and restores; version/privilege/non-InnoDB matrices and real-engine recovery drills | Tool availability/version semantics |
| B9 | 3–5 weeks | S3-compatible destination and optional sodium envelope/key-reference lifecycle if approved; multipart/failure/crypto recovery tests | Key loss and remote atomicity |
| BP | 2–4 weeks plus external lead time | Publisher identity, production signing/trust, artifact validation, release/runbook and publication gate | Production registry/trust is currently empty |

Recommended approved MVP (B1–B6): **12–18 engineering weeks**. Operational production-ready v1 including B7–B8: **18–27 weeks**. Optional B9: **3–5 weeks**; BP: **2–4 weeks plus external onboarding**. A narrower DB-only backup milestone through B2 is 3–5 weeks but is not the recommended restore-capable MVP.

## 17. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Production addon packaging contract is incomplete | Resolve namespace/migrations/RBAC in B1 contract tests before domain code |
| SQLite snapshot corrupt/inconsistent | SQLite-aware backup/checkpoint + writer coordination + integrity validation; never raw-copy active DB |
| DB and files diverge | Detect file mutations, fail restore-grade backup, explicit consistency window |
| Restore leaves mixed DB/files | Candidate roots, safety backup, journaled compensation, maintenance/manual state |
| Archive bomb/path escape | Dual preflight/runtime limits, no links/special files, canonical containment |
| Shared host lacks process/binaries | Capability matrix and safe block; SQLite PHP-first MVP/cron CLI |
| Disk exhaustion/recursive backup | Canonical exclusions, estimates/margins/inodes, partial cleanup |
| ZIP central directory/large archive | 64-bit/ZIP64 preflight, local seekable spool, verify after close |
| Secrets leak | Fixed exclusions, opaque credential refs, redaction tests, no `.env` |
| Existing audit/RBAC too coarse | Addon events + platform summaries; Admin fail-closed until capability integration |
| Operation locks expire mid-run | Persistent owner token/heartbeat plus mutex; expiry alone never permits mutation |
| Runtime source/addon mismatch | Same-install fingerprint/version/schema/addon preflight and fail closed |

## 18. Decisions requiring owner approval

1. Approve selectable profiles with database plus allowlisted public uploads as MVP, including fail-on-changing-file behavior.
2. Approve SQLite as the first-release engine and PostgreSQL/MySQL/MariaDB for B8, not MVP.
3. Approve same-install restore only for MVP; fresh-install restore is later.
4. Approve inclusion of `storage/app/public` user files and exclusion of arbitrary/custom roots.
5. Approve addon-state metadata inclusion but no addon packages/live code; only compatible present addon state may be reapplied.
6. Approve local private destination for MVP, S3-compatible storage in B9, and no FTP/SFTP in v1.
7. Approve no encryption in MVP and bounded libsodium envelope work in B9.
8. Approve identity/repository/package/table/capability/CLI names in Section 3 and select the provider namespace contract resolution.
9. Approve phased roadmap and 12–18 week MVP / 18–27 week production-ready v1 ranges.
10. Approve mandatory pre-restore safety backup, maintenance downtime and fail-closed/manual-intervention behavior.

## 19. B1 acceptance criteria

- A separate `ALTA_ADDON_BACKUP_RESTORE` repository contains exactly one production module; no implementation is hardcoded into ALTA_WEB_PLATFORM Marketplace core.
- Owner-approved unique addon/package/namespace identifiers and a production (not fixture-derived) manifest pass `AddonManifestValidator` plus artifact identity/compatibility contract tests.
- Provider registers/boots without network, filesystem, DB mutation, backup execution or other side effects and the addon is disabled by default.
- Addon migrations are isolated, explicitly loadable, reversible where safe, prefixed `abr_`, and never run merely because the provider boots.
- Minimum models and validated state enums/transitions exist for profiles, destinations, runs, artifacts, restore runs and events; constraints/indexes and secret-field prohibitions are tested.
- Capabilities are declared; until granular platform RBAC exists, policy tests prove Admin-only mutation/download/restore and deny other roles.
- A private `alta-backup-restore/` storage namespace and logical allowlist config exist; no public URL/symlink, `.env`, Marketplace roots or arbitrary path is accepted.
- B1 performs no backup or restore unless owner explicitly expands B1; commands/UI, if stubbed, report unavailable without side effects.
- Automated manifest/provider/migration/config/policy/state/storage-boundary contract tests pass after `php artisan config:clear`; no test relies on `core.products`, `core.theme-maker` or placeholders.
- No new platform dependency or core one-off bypass is added without an approved contract ADR; no production secrets or signing keys enter repository/artifact/history.

## 20. Evidence appendix

### A. Repository evidence

- Discovery/manifest: `app/Support/Addons/AddonDiscovery.php` (`scan`, `sync`, `manifestCandidates`); `AddonManifestValidator.php` (`validate`, `normalize`).
- Boot/lifecycle/dependencies: `app/Providers/AddonServiceProvider.php`; `app/Support/Addons/AddonManager.php` (`bootAddon`, provider/views/routes/hooks); `AddonLifecycle.php` (`install`, `enable`, `disable`, `uninstall`, `dependencyIssues`, path/compatibility checks); `AddonRegistry.php`.
- State/events: `database/migrations/2026_07_07_190750_create_system_addon_tables.php`; `app/Models/SystemAddon.php`; `SystemAddonEvent.php`; `app/Support/Addons/AddonEventLogger.php`.
- Fixture warning evidence: `config/addons-marketplace.php`; `modules/Core/Products/module.json`; `extensions/Core/ThemeMaker/extension.json`.
- Registry security/storage: `config/addons-registry.php`; `ArtifactDownloader.php`; `ArchiveSafetyValidator.php`; `SafeArchiveExtractor.php`; `ArtifactStagingManager.php`; `ArtifactSignatureVerifier.php`; `PublisherTrustStore.php`; `ArtifactTrustEvaluator.php`.
- Atomic/recovery patterns: `ArtifactPromotionManager.php`; `VerifiedAddonInstallOrchestrator.php`; `AddonRecoveryService.php`; `AddonRecoveryHealthService.php`; `BackupIntegrityService.php`; `RecoveryDataCleanupService.php`.
- Database/storage/queue: `config/database.php`, `config/filesystems.php`, `config/queue.php`, `.env.example`, `database/migrations/0001_01_01_000002_create_jobs_table.php`, `routes/console.php`.
- Uploaded data: Filament upload fields in `ProductResource.php`, `ProductVariantImagesRelationManager.php`, `BannerResource.php`, `StorefrontThemeResource.php`; `app/Services/Images/ImageConversionService.php` and `ProductImageImportService.php`.
- UI/auth: `app/Providers/Filament/AdminPanelProvider.php`; `SystemAddonResource.php`; `app/Filament/Pages/Marketplace.php`; `app/Policies/SystemAddonPolicy.php`; `AddonArtifactPromotionPolicy.php`; `app/Enums/UserRole.php`; `app/Models/User.php`; `lang/{uk,en}/marketplace.php`.
- Dependencies: `composer.json` and `composer.lock`; no dedicated backup package; Laravel transitively supplies local Flysystem and Symfony Process; S3 adapter is not installed.

### B. Sanitized runtime diagnostics (2026-07-15)

- Git baseline: branch `main`; HEAD and `origin/main` `775785e74c0d20f0f651270049579d983cb99af8`; ahead/behind `0/0`; clean worktree.
- `php -v`: PHP 8.3.6 CLI, 64-bit environment inferred from platform runtime; production must recheck.
- Relevant modules: PDO, `pdo_sqlite`, `sqlite3`, `pdo_pgsql`, `pgsql`, `zip`, `zlib`, `hash`, `openssl`, `sodium`, `pcntl`, `posix`; `pdo_mysql` absent.
- Binaries found: `/usr/bin/sqlite3`, `/usr/bin/pg_dump`, `/usr/bin/pg_restore`, `/usr/bin/psql`; `mysql` and `mysqldump` absent. Production paths and client/server version compatibility must be rechecked before B8.
- `php artisan about`: database SQLite, queue database, cache database, sessions file, logs stack/single.
- Installed versions: Laravel Framework 12.62.0, Filament 4.11.7; direct Composer requirements contain no backup package.
- Application search found no native dump utility or shell/process invocation and no `ShouldQueue` jobs/scheduled tasks/overlap guards.
- No `.env` content was inspected; only `.env.example`, configuration and sanitized Artisan diagnostics were used.

### C. Unresolved evidence and deployment checks

Production Registry/publisher/signing/trust configuration and installed production addons are reported externally as empty/none and are not inferable from source. HOSTiQ process policy, cron/worker availability, native binaries, limits, filesystem ownership/atomic rename, DB engines/privileges/size and upload volume remain deployment checks. Application semantic version/build fingerprint is not clearly exposed by the audited files and needs a stable platform contract before restore compatibility can be authoritative.
