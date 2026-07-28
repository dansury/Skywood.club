<?php
// Product catalog loader — reads public/data/products.json and overlays the
// runtime database (api/lib/db.php): price/availability/discount overrides and
// per-variant stock. When the DB is empty or unavailable, products are returned
// exactly as written in products.json (no stock tracking).

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/preorder.php';

// Raw catalog straight from products.json (no DB overlay).
function sw_catalog_raw(): array
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

// Catalog with the DB overlay applied. Adds, per product:
//   price       — effective price (override + active discount)
//   oldPrice    — strike-through price (override, or base price when discounted)
//   available   — admin availability flag
//   discount    — {percent,endsAt} when an active discount applies, else null
//   stockTotal  — total units across colours, or null when stock is untracked
//   stockByColor— {colour: units} when tracked, else null
//   preorder    — true when stock is tracked and totals zero
//   preorderOffer — {date,label} of the next-season preorder, or null when off
function sw_catalog_all(): array
{
    static $merged = null;
    if ($merged !== null) {
        return $merged;
    }
    $ext = sw_db_products_ext();
    $stock = sw_db_stock_map();
    $merged = [];
    foreach (sw_catalog_raw() as $p) {
        $merged[] = sw_catalog_apply_overlay($p, $ext[$p['id'] ?? ''] ?? null, $stock[$p['id'] ?? ''] ?? null);
    }
    return $merged;
}

function sw_catalog_apply_overlay(array $p, ?array $ext, ?array $stockRows): array
{
    $base = (int)($p['price'] ?? 0);
    $oldPrice = $p['oldPrice'] ?? null;
    $available = !empty($p['available']);

    if ($ext !== null) {
        if ($ext['price'] !== null && $ext['price'] !== '') {
            $base = (int)$ext['price'];
        }
        if ($ext['old_price'] !== null && $ext['old_price'] !== '') {
            $oldPrice = (int)$ext['old_price'];
        }
        if ($ext['available'] !== null) {
            $available = (int)$ext['available'] === 1;
        }
    }

    $effective = $base;
    $discount = null;
    if ($ext !== null && sw_discount_active($ext)) {
        $percent = (int)$ext['discount_percent'];
        $effective = (int)round($base * (100 - $percent) / 100);
        if ($oldPrice === null || (int)$oldPrice < $base) {
            $oldPrice = $base; // show the pre-discount price struck through
        }
        $discount = ['percent' => $percent, 'endsAt' => $ext['discount_ends'] ?: null];
    }

    $p['price'] = $effective;
    $p['oldPrice'] = $oldPrice;
    $p['available'] = $available;
    $p['discount'] = $discount;
    $p['preorderOffer'] = sw_preorder_offer($ext);

    if (is_array($stockRows) && count($stockRows) > 0) {
        $total = 0;
        foreach ($stockRows as $q) {
            $total += max(0, (int)$q);
        }
        $p['stockTotal'] = $total;
        $p['stockByColor'] = $stockRows;
        $p['preorder'] = $total <= 0;
    } else {
        $p['stockTotal'] = null;
        $p['stockByColor'] = null;
        $p['preorder'] = false;
    }
    return $p;
}

// Base price as written in products.json (before any DB override/discount).
function sw_catalog_raw_price(string $id): int
{
    foreach (sw_catalog_raw() as $p) {
        if (($p['id'] ?? null) === $id) {
            return (int)($p['price'] ?? 0);
        }
    }
    return 0;
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

// Units available for a specific variant, or null when the product/variant is
// not stock-tracked (treated as "always available").
function sw_catalog_stock(string $id, string $color): ?int
{
    $p = sw_catalog_get($id);
    if ($p === null || $p['stockByColor'] === null) {
        return null;
    }
    return (int)($p['stockByColor'][$color] ?? 0);
}

// Decrement stock for every line of a paid/confirmed order, once. Idempotent:
// guarded by the order's `stockApplied` flag (set by the caller via the store).
function sw_inventory_apply_order(array $order): array
{
    $applied = [];
    foreach (($order['items'] ?? []) as $item) {
        $id = (string)($item['id'] ?? '');
        $color = (string)($item['color'] ?? '');
        $qty = (int)($item['qty'] ?? 0);
        if ($id === '' || $qty <= 0) {
            continue;
        }
        if (sw_db_stock_get($id, $color) === null) {
            continue; // untracked variant — nothing to decrement
        }
        $new = sw_db_stock_decrement($id, $color, $qty);
        $applied[] = ['id' => $id, 'color' => $color, 'qty' => $qty, 'stockLeft' => $new];
    }
    return $applied;
}
