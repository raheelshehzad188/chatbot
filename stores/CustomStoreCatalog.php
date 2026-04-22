<?php

require_once __DIR__ . '/StoreCatalogInterface.php';

class CustomStoreCatalog implements StoreCatalogInterface
{
    /** @var PDO */
    protected $pdo;

    /** @var int */
    protected $storeId;

    public function __construct(PDO $pdo, int $storeId)
    {
        $this->pdo = $pdo;
        $this->storeId = $storeId;
    }

    public function getSearch(string $term, int $limit = 5): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $limit = max(1, min(20, $limit));
        $like = '%' . $term . '%';
        $sql = 'SELECT id, slug, name, description, price, stock, currency
                FROM products
                WHERE sub_admin_id = ? AND (slug LIKE ? OR LOWER(name) LIKE LOWER(?))
                ORDER BY id ASC
                LIMIT ' . (int) $limit;
        $st = $this->pdo->prepare($sql);
        $st->execute([$this->storeId, $like, $like]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return array_map([$this, 'normalizeRow'], $rows);
    }

    public function getSingle(string $slug): ?array
    {
        $slug = $this->normalizeSlug($slug);
        if ($slug === '') {
            return null;
        }
        $sql = 'SELECT id, slug, name, description, price, stock, currency
                FROM products
                WHERE sub_admin_id = ? AND slug = ?
                LIMIT 1';
        $st = $this->pdo->prepare($sql);
        $st->execute([$this->storeId, $slug]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return $this->normalizeRow($row);
    }

    protected function normalizeSlug(string $slug): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9\-]+/', '-', trim($slug)));
        return trim($slug, '-');
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    protected function normalizeRow(array $row): array
    {
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'slug' => (string) ($row['slug'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'price' => isset($row['price']) ? (string) $row['price'] : '',
            'stock' => isset($row['stock']) ? (int) $row['stock'] : null,
            'currency' => (string) ($row['currency'] ?? 'PKR'),
        ];
    }
}

