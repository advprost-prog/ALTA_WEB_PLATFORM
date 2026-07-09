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

];
