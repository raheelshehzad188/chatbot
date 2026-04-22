<?php

interface StoreCatalogInterface
{
    /**
     * Search products by keyword/slug/title.
     *
     * @return array<int, array<string,mixed>>
     */
    public function getSearch(string $term, int $limit = 5): array;

    /**
     * Fetch one product by slug/handle.
     *
     * @return array<string,mixed>|null
     */
    public function getSingle(string $slug): ?array;
}

