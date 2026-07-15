<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Addon Registry
    |--------------------------------------------------------------------------
    |
    | Optional read-only remote catalog source. When enabled, the local
    | Marketplace UI will merge local and remote items and show registry
    | metadata such as remote version and source badges.
    |
    | No remote install, download, or code execution is performed in Phase 3.
    |
    */

    'enabled' => env('ADDONS_REGISTRY_ENABLED', false),

    'url' => env('ADDONS_REGISTRY_URL'),

    'timeout' => env('ADDONS_REGISTRY_TIMEOUT', 5),

    'connect_timeout' => env('ADDONS_REGISTRY_CONNECT_TIMEOUT', 3),

    'max_response_size' => (int) env('ADDONS_REGISTRY_MAX_RESPONSE_SIZE', 1048576),

    'allow_redirects' => env('ADDONS_REGISTRY_ALLOW_REDIRECTS', false),

    'cache_ttl' => env('ADDONS_REGISTRY_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Allowed hosts
    |--------------------------------------------------------------------------
    |
    | Explicit whitelist of registry endpoint hosts. If empty, external hosts
    | are NOT allowed. Only localhost/127.0.0.1/::1 may be allowed when
    | `allow_localhost` is true and the app environment is local/testing.
    |
    | Example:
    |   ADDONS_REGISTRY_ALLOWED_HOSTS=registry.example.com,registry.internal
    |
    */

    'allowed_hosts' => array_filter(explode(',', env('ADDONS_REGISTRY_ALLOWED_HOSTS', ''))),

    'verify_ssl' => env('ADDONS_REGISTRY_VERIFY_SSL', true),

    'allow_localhost' => env('ADDONS_REGISTRY_ALLOW_LOCALHOST', true),

    'mode' => 'read_only',

    /*
    |--------------------------------------------------------------------------
    | Trust policy
    |--------------------------------------------------------------------------
    |
    | Controls cryptographic signature verification for remote artifacts.
    |
    | - require_signature: when true, an unsigned artifact stays in quarantine
    |   and future install/unpack is blocked (trust_status = untrusted).
    | - trusted_keys: key_id => base64-encoded ed25519 public key. Signatures
    |   are verified with sodium_crypto_sign_verify_detached.
    |
    | The public key below is an INSECURE DEMO key used only for local testing
    | of the registry example fixtures. It is NOT a production secret. Do not
    | sign production artifacts with the matching private key. Generate your
    | own keypair for real registries.
    |
    |   $keypair = sodium_crypto_sign_keypair();
    |   $public  = base64_encode(sodium_crypto_sign_publickey($keypair));
    |
    */

    'trust' => [
        'require_signature' => env('ADDONS_REGISTRY_REQUIRE_SIGNATURE', true),
        'signature_verification_max_bytes' => (int) env('ADDONS_REGISTRY_SIGNATURE_MAX_BYTES', 20 * 1024 * 1024),
        'keys' => [],
        'trusted_keys' => [
            // Insecure demo ed25519 public key for docs/examples/artifacts.
            'alta-demo-key-1' => 'mCWvikNz7pfcagq5odfFiCa1nhsa17D4Up02EkZ4alM=',
        ],
        'legacy_publishers' => [
            'alta-demo-key-1' => '11111111-1111-4111-8111-111111111111',
        ],
    ],

    'downloads' => [
        'enabled' => env('ADDONS_REGISTRY_DOWNLOADS_ENABLED', false),
        'disk' => env('ADDONS_REGISTRY_DOWNLOAD_DISK', 'addons'),
        'quarantine_path' => env('ADDONS_REGISTRY_QUARANTINE_PATH', 'addons/quarantine'),
        'max_size' => (int) env('ADDONS_REGISTRY_MAX_ARTIFACT_SIZE', 20 * 1024 * 1024),
        'allowed_types' => ['zip'],
        'allowed_extensions' => ['zip'],
    ],

    /*
     |--------------------------------------------------------------------------
     | Review policy
     |--------------------------------------------------------------------------
     |
     | Manual quarantine review workflow for remote artifacts (Phase 3.3).
     |
     | - enabled: master switch for the review workflow.
     | - require_trusted: approve is only allowed for artifacts with
     |   trust_status = trusted (verified signature + valid manifest + checksum).
     | - require_note_on_reject: reject requires a non-empty reason.
     | - allow_revoke: previously approved artifacts can have approval revoked.
     |
     | Review is administrative metadata only. It never unpacks, installs, or
     | executes artifact code. Approving does NOT make the addon installable;
     | a later unpack/install phase is still required.
     */

    'review' => [
        'enabled' => env('ADDONS_REGISTRY_REVIEW_ENABLED', true),
        'require_trusted' => env('ADDONS_REGISTRY_REVIEW_REQUIRE_TRUSTED', true),
        'require_note_on_reject' => env('ADDONS_REGISTRY_REVIEW_REQUIRE_NOTE_ON_REJECT', true),
        'allow_revoke' => env('ADDONS_REGISTRY_REVIEW_ALLOW_REVOKE', true),
    ],

    'staging' => [
        'enabled' => env('ADDONS_REGISTRY_STAGING_ENABLED', false),
        'disk' => env('ADDONS_REGISTRY_STAGING_DISK', 'addons'),
        'path' => env('ADDONS_REGISTRY_STAGING_PATH', 'addons/staging'),
        'require_trusted' => env('ADDONS_REGISTRY_STAGING_REQUIRE_TRUSTED', true),
        'require_approved' => env('ADDONS_REGISTRY_STAGING_REQUIRE_APPROVED', true),
        'block_stale_approval' => env('ADDONS_REGISTRY_STAGING_BLOCK_STALE', true),
        'max_entries' => (int) env('ADDONS_REGISTRY_STAGING_MAX_ENTRIES', 2000),
        'max_uncompressed_size' => (int) env('ADDONS_REGISTRY_STAGING_MAX_UNCOMPRESSED_SIZE', 104857600),
        'max_single_file_size' => (int) env('ADDONS_REGISTRY_STAGING_MAX_FILE_SIZE', 20971520),
        'max_compression_ratio' => (int) env('ADDONS_REGISTRY_STAGING_MAX_COMPRESSION_RATIO', 100),
        'max_path_length' => (int) env('ADDONS_REGISTRY_STAGING_MAX_PATH_LENGTH', 240),
    ],

    'promotion' => [
        'enabled' => env('ADDONS_REGISTRY_PROMOTION_ENABLED', false),
        'require_trusted' => env('ADDONS_REGISTRY_PROMOTION_REQUIRE_TRUSTED', true),
        'require_approved' => env('ADDONS_REGISTRY_PROMOTION_REQUIRE_APPROVED', true),
        'require_staged' => env('ADDONS_REGISTRY_PROMOTION_REQUIRE_STAGED', true),
        'block_stale_approval' => env('ADDONS_REGISTRY_PROMOTION_BLOCK_STALE_APPROVAL', true),
        'block_stale_staging' => env('ADDONS_REGISTRY_PROMOTION_BLOCK_STALE_STAGING', true),
        'backup_enabled' => env('ADDONS_REGISTRY_PROMOTION_BACKUP_ENABLED', true),
        'backup_disk' => env('ADDONS_REGISTRY_PROMOTION_BACKUP_DISK', 'addons'),
        'backup_path' => env('ADDONS_REGISTRY_PROMOTION_BACKUP_PATH', 'addons/backups'),
        'journal_disk' => env('ADDONS_REGISTRY_PROMOTION_JOURNAL_DISK', 'addons'),
        'journal_path' => env('ADDONS_REGISTRY_PROMOTION_JOURNAL_PATH', 'addons/promotion-journal'),
        'lock_timeout' => (int) env('ADDONS_REGISTRY_PROMOTION_LOCK_TIMEOUT', 30),
        'keep_backups' => (int) env('ADDONS_REGISTRY_PROMOTION_KEEP_BACKUPS', 5),
    ],

    'cleanup' => [
        'enabled' => env('ADDONS_REGISTRY_CLEANUP_ENABLED', false),
        'backup_retention_min_count' => env('ADDONS_REGISTRY_BACKUP_RETENTION_MIN_COUNT', 1),
        'backup_retention_max_count' => env('ADDONS_REGISTRY_BACKUP_RETENTION_MAX_COUNT', 5),
        'backup_retention_days' => env('ADDONS_REGISTRY_BACKUP_RETENTION_DAYS', 30),
        'stale_after' => env('ADDONS_REGISTRY_STALE_CLEANUP_AFTER', 86400),
        'tombstone_path' => 'addons/cleanup-journal/backups',
    ],

    'live_roots' => [
        'modules_path' => env('ADDONS_REGISTRY_LIVE_MODULES_PATH', base_path('modules')),
        'extensions_path' => env('ADDONS_REGISTRY_LIVE_EXTENSIONS_PATH', base_path('extensions')),
    ],

];
