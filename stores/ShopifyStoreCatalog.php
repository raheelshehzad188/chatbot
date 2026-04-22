<?php

require_once __DIR__ . '/StoreCatalogInterface.php';

class ShopifyStoreCatalog implements StoreCatalogInterface
{
    /** @var string */
    protected $storeUrl;
    /** @var string */
    protected $accessToken;
    /** @var string */
    protected $apiVersion;

    public function __construct(string $storeUrl, string $accessToken, string $apiVersion = '2024-07')
    {
        $this->storeUrl = rtrim(trim($storeUrl), '/');
        $this->accessToken = trim($accessToken);
        $this->apiVersion = trim($apiVersion) !== '' ? trim($apiVersion) : '2024-07';
    }

    public function getSearch(string $term, int $limit = 5): array
    {
        if (!$this->isConfigured()) {
            return [];
        }
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $limit = max(1, min(20, $limit));
        $url = $this->apiBase() . '/products.json?' . http_build_query([
            'limit' => $limit,
            'fields' => 'id,title,handle,body_html,variants',
            'title' => $term,
        ]);
        $data = $this->getJson($url);
        $rows = isset($data['products']) && is_array($data['products']) ? $data['products'] : [];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = $this->normalizeRow($row);
        }
        return $out;
    }

    public function getSingle(string $slug): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }
        $url = $this->apiBase() . '/products.json?' . http_build_query([
            'limit' => 1,
            'fields' => 'id,title,handle,body_html,variants',
            'handle' => $slug,
        ]);
        $data = $this->getJson($url);
        $rows = isset($data['products']) && is_array($data['products']) ? $data['products'] : [];
        if (empty($rows[0]) || !is_array($rows[0])) {
            return null;
        }
        return $this->normalizeRow($rows[0]);
    }

    protected function isConfigured(): bool
    {
        return $this->storeUrl !== '' && $this->accessToken !== '';
    }

    protected function apiBase(): string
    {
        return $this->storeUrl . '/admin/api/' . $this->apiVersion;
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function getJson(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-Shopify-Access-Token: ' . $this->accessToken,
            ],
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300 || $raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    protected function normalizeRow(array $row): array
    {
        $variant = [];
        if (!empty($row['variants']) && is_array($row['variants']) && !empty($row['variants'][0]) && is_array($row['variants'][0])) {
            $variant = $row['variants'][0];
        }
        $stock = null;
        if (isset($variant['inventory_quantity'])) {
            $stock = (int) $variant['inventory_quantity'];
        }
        $price = isset($variant['price']) ? (string) $variant['price'] : '';
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'slug' => (string) ($row['handle'] ?? ''),
            'name' => (string) ($row['title'] ?? ''),
            'description' => (string) ($row['body_html'] ?? ''),
            'price' => $price,
            'stock' => $stock,
            'currency' => 'PKR',
        ];
    }
}

