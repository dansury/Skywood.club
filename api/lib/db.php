<?php
// SQLite data store (PDO) — public/data/skywood.sqlite.
// Holds mutable shop data the admin edits: per-variant stock, price/discount
// overrides and captured leads (contact forms, Re:plain). The catalog text and
// images stay in data/products.json; this DB only overlays the parts that
// change at runtime, so it can be wiped and rebuilt from the admin at any time.
//
// The DB file is created on first use and is NOT tracked in git, so it survives
// pull.php deploys (like data/orders.json). Every public-path call degrades
// gracefully: if pdo_sqlite is missing or the file cannot be opened, the
// helpers return empty/neutral values and the storefront keeps working off
// products.json alone.

declare(strict_types=1);

function sw_db_file(): string
{
    return __DIR__ . '/../../data/skywood.sqlite';
}

// Returns a shared PDO handle, or null when SQLite is unavailable. Never throws
// on the public path — callers treat null as "no overrides configured".
function sw_db(): ?PDO
{
    static $pdo = false; // false = not yet attempted, null = unavailable
    if ($pdo !== false) {
        return $pdo;
    }
    $pdo = null;
    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return $pdo;
    }
    $file = sw_db_file();
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    try {
        $h = new PDO('sqlite:' . $file);
        $h->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $h->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $h->exec('PRAGMA journal_mode = WAL');
        $h->exec('PRAGMA busy_timeout = 4000');
        sw_db_migrate($h);
        $pdo = $h;
    } catch (Throwable $e) {
        $pdo = null;
    }
    return $pdo;
}

function sw_db_available(): bool
{
    return sw_db() !== null;
}

function sw_db_migrate(PDO $h): void
{
    // Product-level overrides + discount window.
    $h->exec('CREATE TABLE IF NOT EXISTS products_ext (
        product_id        TEXT PRIMARY KEY,
        price             INTEGER,
        old_price         INTEGER,
        available         INTEGER,
        discount_percent  INTEGER NOT NULL DEFAULT 0,
        discount_starts   TEXT,
        discount_ends     TEXT,
        updated_at        TEXT
    )');
    // Per-variant (colour) stock. color = "" for products without colours.
    $h->exec('CREATE TABLE IF NOT EXISTS stock (
        product_id  TEXT NOT NULL,
        color       TEXT NOT NULL DEFAULT "",
        qty         INTEGER NOT NULL DEFAULT 0,
        updated_at  TEXT,
        PRIMARY KEY (product_id, color)
    )');
    // Leads: contact captures and Re:plain chat events.
    $h->exec('CREATE TABLE IF NOT EXISTS leads (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at  TEXT,
        source      TEXT,
        name        TEXT,
        phone       TEXT,
        email       TEXT,
        message     TEXT,
        raw         TEXT
    )');
}

/* ---------- product overrides & stock ---------- */

// product_id => ['price'?,'old_price'?,'available'?,'discount_percent',
//                'discount_starts','discount_ends'].
function sw_db_products_ext(): array
{
    $db = sw_db();
    if ($db === null) {
        return [];
    }
    $out = [];
    foreach ($db->query('SELECT * FROM products_ext') as $row) {
        $out[(string)$row['product_id']] = $row;
    }
    return $out;
}

// product_id => [color => qty]. Only products that have stock rows appear.
function sw_db_stock_map(): array
{
    $db = sw_db();
    if ($db === null) {
        return [];
    }
    $out = [];
    foreach ($db->query('SELECT product_id, color, qty FROM stock') as $row) {
        $out[(string)$row['product_id']][(string)$row['color']] = (int)$row['qty'];
    }
    return $out;
}

function sw_db_stock_get(string $productId, string $color): ?int
{
    $db = sw_db();
    if ($db === null) {
        return null;
    }
    $st = $db->prepare('SELECT qty FROM stock WHERE product_id = ? AND color = ?');
    $st->execute([$productId, $color]);
    $val = $st->fetchColumn();
    return $val === false ? null : (int)$val;
}

function sw_db_stock_set(string $productId, string $color, int $qty): void
{
    $db = sw_db();
    if ($db === null) {
        return;
    }
    $st = $db->prepare('INSERT INTO stock (product_id, color, qty, updated_at)
        VALUES (?, ?, ?, ?)
        ON CONFLICT(product_id, color) DO UPDATE SET qty = excluded.qty, updated_at = excluded.updated_at');
    $st->execute([$productId, $color, max(0, $qty), date('c')]);
}

// Atomically decrement stock for one variant, never below zero. Returns the new qty.
function sw_db_stock_decrement(string $productId, string $color, int $by): ?int
{
    $db = sw_db();
    if ($db === null) {
        return null;
    }
    $cur = sw_db_stock_get($productId, $color);
    if ($cur === null) {
        return null; // not tracked
    }
    $next = max(0, $cur - max(0, $by));
    sw_db_stock_set($productId, $color, $next);
    return $next;
}

function sw_db_product_ext_save(string $productId, array $fields): void
{
    $db = sw_db();
    if ($db === null) {
        return;
    }
    $cols = ['price', 'old_price', 'available', 'discount_percent', 'discount_starts', 'discount_ends'];
    $cur = sw_db_products_ext()[$productId] ?? [];
    $vals = [];
    foreach ($cols as $c) {
        $vals[$c] = array_key_exists($c, $fields) ? $fields[$c] : ($cur[$c] ?? null);
    }
    $st = $db->prepare('INSERT INTO products_ext
        (product_id, price, old_price, available, discount_percent, discount_starts, discount_ends, updated_at)
        VALUES (:id, :price, :old_price, :available, :dp, :ds, :de, :ts)
        ON CONFLICT(product_id) DO UPDATE SET
            price = :price, old_price = :old_price, available = :available,
            discount_percent = :dp, discount_starts = :ds, discount_ends = :de, updated_at = :ts');
    $st->execute([
        ':id'        => $productId,
        ':price'     => $vals['price'],
        ':old_price' => $vals['old_price'],
        ':available' => $vals['available'],
        ':dp'        => (int)($vals['discount_percent'] ?? 0),
        ':ds'        => $vals['discount_starts'],
        ':de'        => $vals['discount_ends'],
        ':ts'        => date('c'),
    ]);
}

// True when a discount row is non-zero and the current time is inside its window
// (empty start/end bounds mean "open-ended").
function sw_discount_active(array $ext, ?int $now = null): bool
{
    $percent = (int)($ext['discount_percent'] ?? 0);
    if ($percent <= 0) {
        return false;
    }
    $now = $now ?? time();
    $starts = trim((string)($ext['discount_starts'] ?? ''));
    $ends = trim((string)($ext['discount_ends'] ?? ''));
    if ($starts !== '' && ($t = strtotime($starts)) !== false && $now < $t) {
        return false;
    }
    if ($ends !== '' && ($t = strtotime($ends)) !== false && $now > $t) {
        return false;
    }
    return true;
}

/* ---------- leads ---------- */

function sw_lead_create(array $data): int
{
    $db = sw_db();
    if ($db === null) {
        return 0;
    }
    $st = $db->prepare('INSERT INTO leads (created_at, source, name, phone, email, message, raw)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    $st->execute([
        date('c'),
        (string)($data['source'] ?? 'contact'),
        (string)($data['name'] ?? ''),
        (string)($data['phone'] ?? ''),
        (string)($data['email'] ?? ''),
        (string)($data['message'] ?? ''),
        isset($data['raw']) ? (is_string($data['raw']) ? $data['raw'] : json_encode($data['raw'], JSON_UNESCAPED_UNICODE)) : '',
    ]);
    return (int)$db->lastInsertId();
}

function sw_leads_all(int $limit = 500): array
{
    $db = sw_db();
    if ($db === null) {
        return [];
    }
    $st = $db->prepare('SELECT * FROM leads ORDER BY id DESC LIMIT ?');
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}
