<?php

namespace App\Support\DatabaseTransition;

final class SqliteToPostgreSqlPolicy
{
    public const TABLES = [
        'ai_runs', 'ai_settings', 'ai_suggestions', 'ai_usage_snapshots', 'attributes', 'banners', 'brands', 'cache', 'cache_locks', 'categories',
        'category_attributes', 'commerce_settings', 'currencies', 'customer_addresses', 'customers', 'delivery_methods', 'failed_jobs', 'job_batches', 'jobs',
        'migrations', 'notification_mail_settings', 'notification_outbox', 'notification_templates', 'order_items', 'order_status_histories', 'orders',
        'password_reset_tokens', 'payment_methods', 'product_attribute_values', 'product_barcodes', 'product_category', 'product_image_candidates', 'product_images',
        'product_prices', 'product_specifications', 'product_variants', 'products', 'promotions', 'sessions', 'site_settings', 'stock_balances', 'stock_movements',
        'storefront_theme_versions', 'storefront_themes', 'system_addon_events', 'system_addon_settings', 'system_addons', 'tax_profiles', 'theme_generation_runs',
        'units', 'users', 'variant_packages', 'warehouses',
    ];

    public const EXCLUDED = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    public const SEEDED_CLEANUP_ORDER = [
        'commerce_settings', 'warehouses', 'currencies', 'delivery_methods', 'notification_mail_settings',
        'notification_templates', 'payment_methods', 'tax_profiles', 'units',
    ];

    public const SEEDED = [
        'commerce_settings', 'currencies', 'delivery_methods', 'notification_mail_settings', 'notification_templates',
        'payment_methods', 'tax_profiles', 'units', 'warehouses',
    ];

    public static function importedTables(): array
    {
        return array_values(array_diff(self::TABLES, self::EXCLUDED, ['migrations']));
    }
}
