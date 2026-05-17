<?php
// Skywood.club — API front controller (PHP port of the Node backend).
// Routes /api/* requests; see public/.htaccess for the rewrite rule.

declare(strict_types=1);

require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/catalog.php';
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/cdek.php';
require_once __DIR__ . '/lib/tinkoff.php';

// ---------- helpers ----------

function sw_json($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sw_text(string $text, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $text;
    exit;
}

function sw_request_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode((string)$raw, true);
    if (is_array($data)) {
        return $data;
    }
    return is_array($_POST) ? $_POST : [];
}

// Демо-города для просмотра без доступов СДЭК.
const SW_DEMO_CITIES = [
    ['code' => 44,  'city' => 'Москва',          'region' => 'Москва',                    'postalCode' => '101000'],
    ['code' => 137, 'city' => 'Санкт-Петербург', 'region' => 'Санкт-Петербург',           'postalCode' => '190000'],
    ['code' => 270, 'city' => 'Новосибирск',     'region' => 'Новосибирская область',     'postalCode' => '630000'],
    ['code' => 250, 'city' => 'Екатеринбург',    'region' => 'Свердловская область',      'postalCode' => '620000'],
    ['code' => 428, 'city' => 'Казань',          'region' => 'Республика Татарстан',      'postalCode' => '420000'],
    ['code' => 35,  'city' => 'Краснодар',       'region' => 'Краснодарский край',        'postalCode' => '350000'],
];

function sw_resolve_items($rawItems): array
{
    $items = [];
    foreach (is_array($rawItems) ? $rawItems : [] as $row) {
        $product = sw_catalog_get((string)($row['id'] ?? ''));
        if ($product === null) {
            continue;
        }
        $qty = max(1, min(10, (int)($row['qty'] ?? 1)));
        $items[] = array_merge($product, ['qty' => $qty]);
    }
    return $items;
}

// Валидация и нормализация заказа (порт validate() из routes/orders.js).
function sw_validate_order(array $body): array
{
    $errors = [];
    $items = [];
    foreach (is_array($body['items'] ?? null) ? $body['items'] : [] as $row) {
        $product = sw_catalog_get((string)($row['id'] ?? ''));
        if ($product === null) {
            continue;
        }
        if (empty($product['available'])) {
            $errors[] = "Товар «{$product['name']}» сейчас недоступен";
            continue;
        }
        $qty = max(1, min(10, (int)($row['qty'] ?? 1)));
        $colors = $product['options']['color'] ?? [];
        $color = '';
        if (is_array($colors) && count($colors) > 0) {
            $color = in_array($row['color'] ?? null, $colors, true) ? $row['color'] : $colors[0];
        }
        $items[] = [
            'id'          => $product['id'],
            'name'        => $product['name'],
            'price'       => $product['price'],
            'qty'         => $qty,
            'color'       => $color,
            'weightGrams' => $product['weightGrams'] ?? 1000,
            'packLength'  => $product['packLength'] ?? 0,
            'packWidth'   => $product['packWidth'] ?? 0,
            'packHeight'  => $product['packHeight'] ?? 0,
        ];
    }
    if (count($items) === 0) {
        $errors[] = 'Корзина пуста';
    }

    $c = is_array($body['customer'] ?? null) ? $body['customer'] : [];
    $customer = [
        'name'    => trim((string)($c['name'] ?? '')),
        'phone'   => trim((string)($c['phone'] ?? '')),
        'email'   => trim((string)($c['email'] ?? '')),
        'address' => trim((string)($c['address'] ?? '')),
        'comment' => trim((string)($c['comment'] ?? '')),
    ];
    if (mb_strlen($customer['name']) < 2) {
        $errors[] = 'Укажите имя получателя';
    }
    if (!preg_match('~^\+?[0-9\s\-()]{10,18}$~', $customer['phone'])) {
        $errors[] = 'Укажите корректный телефон';
    }

    $d = is_array($body['delivery'] ?? null) ? $body['delivery'] : [];
    $deliveryType = ($d['type'] ?? '') === 'door' ? 'door' : 'pvz';
    $toCityCode = (int)($d['cityCode'] ?? 0);
    if (!$toCityCode) {
        $errors[] = 'Выберите город доставки';
    }
    if ($deliveryType === 'pvz' && empty($d['pvzCode'])) {
        $errors[] = 'Выберите пункт выдачи СДЭК';
    }
    if ($deliveryType === 'door' && mb_strlen($customer['address']) < 5) {
        $errors[] = 'Укажите адрес доставки';
    }

    $paymentMethod = ($body['paymentMethod'] ?? '') === 'cod' ? 'cod' : 'online';
    $deliveryCost = max(0, (int)round((float)($d['cost'] ?? 0)));

    return [
        'errors'        => $errors,
        'items'         => $items,
        'customer'      => $customer,
        'paymentMethod' => $paymentMethod,
        'deliveryCost'  => $deliveryCost,
        'deliveryType'  => $deliveryType,
        'toCityCode'    => $toCityCode,
        'cityName'      => trim((string)($d['cityName'] ?? '')),
        'pvzCode'       => !empty($d['pvzCode']) ? (string)$d['pvzCode'] : '',
        'pvzName'       => trim((string)($d['pvzName'] ?? '')),
        'tariffCode'    => $deliveryType === 'door' ? SW_CDEK_TARIFF_DOOR : SW_CDEK_TARIFF_PVZ,
    ];
}

// ---------- routing ----------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$pos = strpos($path, '/api/');
$route = $pos === false ? '' : trim(substr($path, $pos + 5), '/');
// Fallback for hosts without mod_rewrite: api/index.php?route=<route>.
if ($route === '' || $route === 'index.php') {
    $route = trim((string)($_GET['route'] ?? ''), '/');
}

try {
    // GET /api/products — каталог + публичная конфигурация.
    if ($route === 'products' && $method === 'GET') {
        $cfg = sw_config();
        sw_json([
            'products' => sw_catalog_all(),
            'company'  => $cfg['company'],
            'demo'     => $cfg['demo'],
        ]);
    }

    // GET /api/cdek/cities?q=
    if ($route === 'cdek/cities' && $method === 'GET') {
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            sw_json([]);
        }
        if (!sw_config()['cdek']['enabled']) {
            $ql = mb_strtolower($q);
            $hit = [];
            foreach (SW_DEMO_CITIES as $city) {
                if (mb_strpos(mb_strtolower($city['city']), $ql) !== false) {
                    $hit[] = $city;
                }
            }
            sw_json($hit);
        }
        try {
            sw_json(sw_cdek_search_cities($q));
        } catch (Throwable $e) {
            sw_json(['error' => $e->getMessage()], 502);
        }
    }

    // GET /api/cdek/points?city_code=
    if ($route === 'cdek/points' && $method === 'GET') {
        $cityCode = (int)($_GET['city_code'] ?? 0);
        if (!$cityCode) {
            sw_json(['error' => 'city_code обязателен'], 400);
        }
        if (!sw_config()['cdek']['enabled']) {
            sw_json([
                ['code' => "DEMO-{$cityCode}-1", 'name' => 'ПВЗ СДЭК на Центральной', 'address' => 'ул. Центральная, 1', 'workTime' => 'Пн-Пт 10:00-20:00, Сб 10:00-18:00'],
                ['code' => "DEMO-{$cityCode}-2", 'name' => 'ПВЗ СДЭК в ТЦ «Маяк»', 'address' => 'пр. Ленина, 42, 2 этаж', 'workTime' => 'Ежедневно 10:00-21:00'],
            ]);
        }
        sw_json(sw_cdek_pickup_points($cityCode));
    }

    // POST /api/cdek/calculate
    if ($route === 'cdek/calculate' && $method === 'POST') {
        $body = sw_request_body();
        $deliveryType = ($body['deliveryType'] ?? '') === 'door' ? 'door' : 'pvz';
        $toCityCode = (int)($body['toCityCode'] ?? 0);
        $items = sw_resolve_items($body['items'] ?? null);
        if (!$toCityCode || count($items) === 0) {
            sw_json(['error' => 'Укажите город и состав заказа'], 400);
        }
        $tariffCode = $deliveryType === 'door' ? SW_CDEK_TARIFF_DOOR : SW_CDEK_TARIFF_PVZ;
        if (!sw_config()['cdek']['enabled']) {
            $grams = 0;
            foreach ($items as $item) {
                $grams += (int)($item['weightGrams'] ?? 1000) * (int)$item['qty'];
            }
            $cost = 250 + (int)ceil($grams / 1000) * 120 + ($deliveryType === 'door' ? 150 : 0);
            sw_json(['cost' => $cost, 'periodMin' => 2, 'periodMax' => 5, 'demo' => true, 'tariffCode' => $tariffCode]);
        }
        $result = sw_cdek_calculate($tariffCode, $toCityCode, $items);
        $result['tariffCode'] = $tariffCode;
        sw_json($result);
    }

    // POST /api/orders — создание заказа.
    if ($route === 'orders' && $method === 'POST') {
        $cfg = sw_config();
        $v = sw_validate_order(sw_request_body());
        if (count($v['errors']) > 0) {
            sw_json(['errors' => $v['errors']], 400);
        }

        $subtotal = 0;
        foreach ($v['items'] as $item) {
            $subtotal += $item['price'] * $item['qty'];
        }
        $total = $subtotal + $v['deliveryCost'];

        $order = sw_order_create([
            'items'         => $v['items'],
            'customer'      => $v['customer'],
            'deliveryType'  => $v['deliveryType'],
            'toCityCode'    => $v['toCityCode'],
            'cityName'      => $v['cityName'],
            'pvzCode'       => $v['pvzCode'],
            'pvzName'       => $v['pvzName'],
            'tariffCode'    => $v['tariffCode'],
            'deliveryCost'  => $v['deliveryCost'],
            'subtotal'      => $subtotal,
            'total'         => $total,
            'paymentMethod' => $v['paymentMethod'],
            'payment'       => ['status' => $v['paymentMethod'] === 'cod' ? 'cod_pending' : 'pending'],
            'cdek'          => ['status' => 'pending'],
        ]);

        // Регистрация заказа в СДЭК.
        if ($cfg['cdek']['enabled']) {
            try {
                $created = sw_cdek_create_order($order);
                $cdek = ['status' => 'created', 'uuid' => $created['uuid']];
            } catch (Throwable $e) {
                $cdek = ['status' => 'error', 'error' => $e->getMessage()];
            }
        } else {
            $cdek = ['status' => 'demo'];
        }

        // Оплата при получении — заказ сразу подтверждён.
        if ($v['paymentMethod'] === 'cod') {
            sw_order_update($order['id'], ['cdek' => $cdek, 'status' => 'confirmed']);
            sw_json(['ok' => true, 'orderId' => $order['id'], 'redirect' => 'order.html?order=' . rawurlencode($order['id']) . '&status=success']);
        }

        // Демо-режим без доступов Т-Банк.
        if (!$cfg['tinkoff']['enabled']) {
            sw_order_update($order['id'], ['cdek' => $cdek, 'payment' => ['status' => 'demo']]);
            sw_json(['ok' => true, 'orderId' => $order['id'], 'redirect' => 'order.html?order=' . rawurlencode($order['id']) . '&status=success&demo=1']);
        }

        // Онлайн-оплата картой через Т-Банк.
        try {
            $pay = sw_tinkoff_init_payment($order);
            sw_order_update($order['id'], ['cdek' => $cdek, 'payment' => ['status' => 'pending', 'paymentId' => $pay['paymentId']]]);
            sw_json(['ok' => true, 'orderId' => $order['id'], 'paymentUrl' => $pay['paymentUrl']]);
        } catch (Throwable $e) {
            sw_order_update($order['id'], ['cdek' => $cdek]);
            sw_json(['error' => $e->getMessage()], 500);
        }
    }

    // GET /api/orders/{id} — статус заказа.
    if (preg_match('~^orders/(.+)$~', $route, $m) && $method === 'GET') {
        $order = sw_order_get(rawurldecode($m[1]));
        if ($order === null) {
            sw_json(['error' => 'Заказ не найден'], 404);
        }
        sw_json([
            'id'            => $order['id'],
            'status'        => $order['status'],
            'total'         => $order['total'] ?? 0,
            'paymentMethod' => $order['paymentMethod'] ?? 'online',
            'payment'       => $order['payment'] ?? null,
        ]);
    }

    // POST /api/payment/notify — вебхук Т-Банк.
    if ($route === 'payment/notify' && $method === 'POST') {
        $body = sw_request_body();
        if (!sw_tinkoff_verify_notification($body)) {
            sw_text('BAD TOKEN', 400);
        }
        $order = sw_order_get((string)($body['OrderId'] ?? ''));
        if ($order !== null) {
            $paid = ($body['Status'] ?? '') === 'CONFIRMED' || ($body['Status'] ?? '') === 'AUTHORIZED';
            sw_order_update($order['id'], [
                'status'  => $paid ? 'paid' : $order['status'],
                'payment' => array_merge(is_array($order['payment'] ?? null) ? $order['payment'] : [], [
                    'status'    => $body['Status'] ?? null,
                    'paymentId' => $body['PaymentId'] ?? null,
                    'updatedAt' => date('c'),
                ]),
            ]);
        }
        // Т-Банк ожидает ответ "OK".
        sw_text('OK');
    }

    sw_json(['error' => 'Маршрут не найден'], 404);
} catch (Throwable $e) {
    sw_json(['error' => $e->getMessage() !== '' ? $e->getMessage() : 'Внутренняя ошибка сервера'], 500);
}
