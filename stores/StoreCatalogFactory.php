<?php

require_once __DIR__ . '/StoreCatalogInterface.php';
require_once __DIR__ . '/CustomStoreCatalog.php';
require_once __DIR__ . '/WooCommerceStoreCatalog.php';
require_once __DIR__ . '/ShopifyStoreCatalog.php';

class StoreCatalogFactory
{
    /**
     * Build catalog adapter from sub-admin settings.
     *
     * @param array<string,mixed>|null $settings
     */
    public static function make(PDO $pdo, int $storeId, ?array $settings): StoreCatalogInterface
    {
        $cfg = self::decodeStoreConfig($settings);
        $provider = strtolower(trim((string) ($cfg['catalog_provider'] ?? '')));
        if ($provider === '') {
            $provider = self::guessProviderFromSettings($settings, $cfg);
        }

        if ($provider === 'shopify') {
            return new ShopifyStoreCatalog(
                (string) ($cfg['shopify_store_url'] ?? ($cfg['ecommerce_catalog_api_url'] ?? '')),
                (string) ($cfg['shopify_access_token'] ?? ($cfg['ecommerce_catalog_api_key'] ?? '')),
                (string) ($cfg['shopify_api_version'] ?? '2024-07')
            );
        }

        if ($provider === 'woo' || $provider === 'woocommerce') {
            return new WooCommerceStoreCatalog(
                (string) ($cfg['woo_base_url'] ?? ($cfg['woocommerce_base_url'] ?? '')),
                (string) ($cfg['woo_consumer_key'] ?? ($cfg['woocommerce_consumer_key'] ?? '')),
                (string) ($cfg['woo_consumer_secret'] ?? ($cfg['woocommerce_consumer_secret'] ?? ''))
            );
        }

        // default + fallback: local custom products table
        return new CustomStoreCatalog($pdo, $storeId);
    }

    /**
     * @param array<string,mixed>|null $settings
     * @return array<string,mixed>
     */
    protected static function decodeStoreConfig(?array $settings): array
    {
        if (!$settings) {
            return [];
        }
        $raw = isset($settings['store_type_config_json']) ? (string) $settings['store_type_config_json'] : '';
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed>|null $settings
     * @param array<string,mixed> $cfg
     */
    protected static function guessProviderFromSettings(?array $settings, array $cfg): string
    {
        $storeType = strtolower(trim((string) ($settings['store_type'] ?? '')));
        if (!empty($cfg['shopify_store_url']) || !empty($cfg['shopify_access_token'])) {
            return 'shopify';
        }
        if (!empty($cfg['woo_base_url']) || !empty($cfg['woo_consumer_key']) || !empty($cfg['woo_consumer_secret'])) {
            return 'woo';
        }
        if ($storeType === 'service') {
            return 'custom';
        }
        return 'custom';
    }
}

