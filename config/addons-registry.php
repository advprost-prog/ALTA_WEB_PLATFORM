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
        'trusted_keys' => [
            // Insecure demo ed25519 public key for docs/examples/artifacts.
            'alta-demo-key-1' => 'mCWvikNz7pfcagq5odfFiCa1nhsa17D4Up02EkZ4alM=',
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

];
