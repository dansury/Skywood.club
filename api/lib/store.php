<?php
// JSON-file order store — public/data/orders.json.
// The file is created on first order and is not tracked in git, so it
// survives pull.php deploys (pull.php only copies repo files in).

declare(strict_types=1);

function sw_orders_file(): string
{
    return __DIR__ . '/../../data/orders.json';
}

function sw_orders_load(): array
{
    $file = sw_orders_file();
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function sw_orders_save(array $orders): void
{
    $file = sw_orders_file();
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $json = json_encode(
        array_values($orders),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    file_put_contents($file, $json, LOCK_EX);
}

// Human-readable order number: SW<YYYYMMDD>-<NNN>.
function sw_order_next_id(array $orders): string
{
    $stamp = date('Ymd');
    $today = 0;
    foreach ($orders as $order) {
        if (strpos((string)($order['id'] ?? ''), $stamp) !== false) {
            $today++;
        }
    }
    return 'SW' . $stamp . '-' . str_pad((string)($today + 1), 3, '0', STR_PAD_LEFT);
}

function sw_order_create(array $data): array
{
    $orders = sw_orders_load();
    $order = array_merge([
        'id'        => sw_order_next_id($orders),
        'createdAt' => date('c'),
        'status'    => 'new',
    ], $data);
    $orders[] = $order;
    sw_orders_save($orders);
    return $order;
}

function sw_order_get(string $id): ?array
{
    foreach (sw_orders_load() as $order) {
        if (($order['id'] ?? null) === $id) {
            return $order;
        }
    }
    return null;
}

function sw_order_update(string $id, array $patch): ?array
{
    $orders = sw_orders_load();
    $updated = null;
    foreach ($orders as &$order) {
        if (($order['id'] ?? null) === $id) {
            $order = array_merge($order, $patch);
            $updated = $order;
            break;
        }
    }
    unset($order);
    if ($updated !== null) {
        sw_orders_save($orders);
    }
    return $updated;
}
