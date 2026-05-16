<?php
// Product catalog loader — reads public/data/products.json.

declare(strict_types=1);

function sw_catalog_all(): array
{
    static $products = null;
    if ($products !== null) {
        return $products;
    }
    $products = [];
    $file = __DIR__ . '/../../data/products.json';
    if (is_file($file)) {
        $data = json_decode((string)file_get_contents($file), true);
        if (isset($data['products']) && is_array($data['products'])) {
            $products = $data['products'];
        }
    }
    return $products;
}

function sw_catalog_get(string $id): ?array
{
    foreach (sw_catalog_all() as $product) {
        if (($product['id'] ?? null) === $id) {
            return $product;
        }
    }
    return null;
}
