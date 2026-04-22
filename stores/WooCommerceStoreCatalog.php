<?php

require_once __DIR__ . '/StoreCatalogInterface.php';

class WooCommerceStoreCatalog implements StoreCatalogInterface
{
    /** @var string */
    protected $baseUrl;
    /** @var string */
    protected $consumerKey;
    /** @var string */
    protected $consumerSecret;

    public function __construct(string $baseUrl, string $consumerKey, string $consumerSecret)
    {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $this->consumerKey = trim($consumerKey);
        $this->consumerSecret = trim($consumerSecret);
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
        $url = $this->buildApiUrl('/products', [
            'search' => $term,
            'per_page' => $limit,
        ]);
        $rows = $this->getJson($url);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $this->normalizeRow($row);
            }
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
        $url = $this->buildApiUrl('/products', [
            'slug' => $slug,
            'per_page' => 1,
        ]);
        $rows = $this->getJson($url);
        if (!is_array($rows) || empty($rows[0]) || !is_array($rows[0])) {
            return null;
        }
        return $this->normalizeRow($rows[0]);
    }

    protected function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->consumerKey !== '' && $this->consumerSecret !== '';
    }

    /**
     * @param array<string,mixed> $params
     */
    protected function buildApiUrl(string $path, array $params): string
    {
        $params['consumer_key'] = $this->consumerKey;
        $params['consumer_secret'] = $this->consumerSecret;
        return $this->baseUrl . '/wp-json/wc/v3' . $path . '?' . http_build_query($params);
    }

    /**
     * @return mixed
     */
    protected function getJson(string $url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300 || $raw === false || $raw === '') {
            return null;
        }
        return json_decode((string) $raw, true);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    protected function normalizeRow(array $row): array
    {
        $currency = isset($row['currency']) ? (string) $row['currency'] : 'PKR';
        $stockQty = null;
        if (isset($row['stock_quantity']) && $row['stock_quantity'] !== null) {
            $stockQty = (int) $row['stock_quantity'];
        } elseif (!empty($row['in_stock'])) {
            $stockQty = 999;
        } else {
            $stockQty = 0;
        }
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'slug' => (string) ($row['slug'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['short_description'] ?? ($row['description'] ?? '')),
            'price' => (string) ($row['price'] ?? ''),
            'stock' => $stockQty,
            'currency' => $currency,
        ];
    }
}

