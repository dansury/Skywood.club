<?php
// Skywood.club — API front controller (PHP port of the Node backend).
// Routes /api/* requests; see public/.htaccess for the rewrite rule.

declare(strict_types=1);

require_once __DIR__ . '/lib/debug.php';
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/catalog.php';
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/cdek.php';
require_once __DIR__ . '/lib/tinkoff.php';
require_once __DIR__ . '/lib/mail.php';
require_once __DIR__ . '/lib/preorder.php';
require_once __DIR__ . '/lib/autopull.php';

// В debug-режиме (?debug=1) собираем PHP-ошибки в трассу запроса. В поток их
// не печатаем — иначе они ломают JSON-ответ; в обычном режиме они скрыты.
if (sw_debug_enabled()) {
    @ini_set('display_errors', '0');
    error_reporting(E_ALL);
    sw_debug_install_handlers();
}

// Автообновление кода: пока в админке стоит галочка, каждый запрос тихо
// проверяет head в GitHub и выкладывает новый коммит через pull.php. Вызовам
// API редирект не нужен — им отвечают данными (spec/backend.md).
sw_autopull_run();

// ---------- helpers ----------

function sw_json($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (sw_debug_enabled()) {
        $log = sw_debug_dump();
        // Заголовок — ASCII-safe (\u-эскейпы), чтобы кириллица не ломала его.
        $hdr = json_encode($log);
        if (is_string($hdr)) {
            header('X-Sw-Debug: ' . str_replace(["\r", "\n"], ' ', mb_substr($hdr, 0, 6000)));
        }
        // В тело трассу добавляем только для объектов — список (города и т.п.)
        // должен остаться массивом.
        if (is_array($data) && count($data) > 0
            && array_keys($data) !== range(0, count($data) - 1)) {
            $data['_debug'] = $log;
        }
    }
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
        if (!empty($product['soldOut'])) {
            $errors[] = "Товар «{$product['name']}» сейчас нет в наличии";
            continue;
        }
        $qty = max(1, min(10, (int)($row['qty'] ?? 1)));
        $colors = $product['options']['color'] ?? [];
        $color = '';
        if (is_array($colors) && count($colors) > 0) {
            $color = in_array($row['color'] ?? null, $colors, true) ? $row['color'] : $colors[0];
        }
        // Stock-tracked variant short on units → preorder (allowed, flagged),
        // never blocked. Untracked variants (stock === null) ship normally.
        $stock = sw_catalog_stock($product['id'], $color);
        $preorder = $stock !== null && $stock < $qty;
        $items[] = [
            'id'          => $product['id'],
            'name'        => $product['name'],
            'price'       => $product['price'],
            'qty'         => $qty,
            'color'       => $color,
            'preorder'    => $preorder,
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

    // Personal-data consent (152-ФЗ) is mandatory to place an order.
    $consent = !empty($body['consent']);
    if (!$consent) {
        $errors[] = 'Подтвердите согласие на обработку персональных данных';
    }

    $isPreorder = false;
    foreach ($items as $it) {
        if (!empty($it['preorder'])) {
            $isPreorder = true;
            break;
        }
    }

    return [
        'errors'        => $errors,
        'items'         => $items,
        'customer'      => $customer,
        'consent'       => $consent,
        'preorder'      => $isPreorder,
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
            'consent'       => $v['consent'],
            'preorder'      => $v['preorder'],
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

        // Письмо клиенту и уведомление владельцу — при оформлении любого заказа
        // (обычного или предзаказа). Best-effort, ошибки почты заказ не ломают.
        sw_mail_order($order, !empty($v['preorder']));

        // Оплата при получении — заказ сразу подтверждён, остатки списываем.
        if ($v['paymentMethod'] === 'cod') {
            sw_inventory_apply_order($order);
            sw_order_update($order['id'], ['cdek' => $cdek, 'status' => 'confirmed', 'stockApplied' => true]);
            sw_json(['ok' => true, 'orderId' => $order['id'], 'preorder' => !empty($v['preorder']), 'redirect' => 'order.html?order=' . rawurlencode($order['id']) . '&status=success']);
        }

        // Демо-режим без доступов Т-Банк — считаем заказ оформленным, остатки списываем.
        if (!$cfg['tinkoff']['enabled']) {
            sw_inventory_apply_order($order);
            sw_order_update($order['id'], ['cdek' => $cdek, 'payment' => ['status' => 'demo'], 'stockApplied' => true]);
            sw_json(['ok' => true, 'orderId' => $order['id'], 'preorder' => !empty($v['preorder']), 'redirect' => 'order.html?order=' . rawurlencode($order['id']) . '&status=success&demo=1']);
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
            $patch = [
                'status'  => $paid ? 'paid' : $order['status'],
                'payment' => array_merge(is_array($order['payment'] ?? null) ? $order['payment'] : [], [
                    'status'    => $body['Status'] ?? null,
                    'paymentId' => $body['PaymentId'] ?? null,
                    'updatedAt' => date('c'),
                ]),
            ];
            // Списываем остатки один раз, когда оплата подтверждена.
            if ($paid && empty($order['stockApplied'])) {
                sw_inventory_apply_order($order);
                $patch['stockApplied'] = true;
            }
            sw_order_update($order['id'], $patch);
        }
        // Т-Банк ожидает ответ "OK".
        sw_text('OK');
    }

    // GET /api/cdek/diag — самодиагностика интеграции СДЭК.
    // Проверяет доступы и официальный API; добавьте ?debug=1 для полной трассы.
    if ($route === 'cdek/diag' && $method === 'GET') {
        $cdek = sw_config()['cdek'];
        $report = [
            'time' => date('c'),
            'php'  => ['version' => PHP_VERSION, 'curl' => function_exists('curl_init')],
            'cdek' => [
                'enabled'          => $cdek['enabled'],
                'api'              => $cdek['api'],
                'officialApi'      => in_array($cdek['api'], ['https://api.cdek.ru/v2', 'https://api.edu.cdek.ru/v2'], true),
                'account'          => $cdek['account'],
                'securePassword'   => $cdek['securePassword'] !== ''
                    ? 'задан (' . mb_strlen($cdek['securePassword']) . ' симв.)' : 'НЕ ЗАДАН',
                'senderCityCode'   => $cdek['senderCityCode'],
                'senderPostalCode' => $cdek['senderPostalCode'],
            ],
        ];
        if (!$cdek['enabled']) {
            $report['result'] = 'CDEK выключен — в .env заданы не все доступы (CDEK_ACCOUNT / CDEK_SECURE_PASSWORD).';
            sw_json($report, 200);
        }
        try {
            $token = sw_cdek_token();
            $report['oauth'] = ['ok' => true, 'tokenLength' => strlen($token)];
        } catch (Throwable $e) {
            $report['oauth'] = ['ok' => false, 'error' => $e->getMessage()];
            $report['result'] = 'Авторизация СДЭК не прошла — проблема в КЛЮЧЕ (CDEK_ACCOUNT / CDEK_SECURE_PASSWORD).';
            sw_json($report, 502);
        }
        try {
            $cities = sw_cdek_search_cities('Москва');
            $report['cities'] = ['ok' => true, 'count' => count($cities), 'sample' => $cities[0] ?? null];
            $report['result'] = $cities
                ? 'СДЭК отвечает — авторизация и поиск городов работают.'
                : 'Авторизация прошла, но поиск городов вернул пустой список.';
        } catch (Throwable $e) {
            $report['cities'] = ['ok' => false, 'error' => $e->getMessage()];
            $report['result'] = 'Ключ рабочий (авторизация прошла), но запрос городов падает — проблема в КОДЕ или endpoint СДЭК.';
            sw_json($report, 502);
        }
        sw_json($report, 200);
    }

    // POST /api/preorder — заявка «Узнать о поступлении»: контакт клиента,
    // который ждёт следующую партию. Заказ не создаётся, оплата не нужна.
    if ($route === 'preorder' && $method === 'POST') {
        $result = sw_preorder_submit(sw_request_body());
        if (!empty($result['errors'])) {
            sw_json(['errors' => $result['errors']], 400);
        }
        sw_json([
            'ok'         => true,
            'id'         => $result['id'],
            'readyDate'  => $result['readyDate'],
            'readyLabel' => $result['readyLabel'],
        ]);
    }

    // POST /api/lead — захват контакта (форма обратной связи). Сохраняется в БД,
    // владельцу уходит уведомление. {name?,phone?,email?,message?,source?}.
    if ($route === 'lead' && $method === 'POST') {
        $body = sw_request_body();
        $name = trim((string)($body['name'] ?? ''));
        $phone = trim((string)($body['phone'] ?? ''));
        $email = trim((string)($body['email'] ?? ''));
        $message = trim((string)($body['message'] ?? ''));
        if ($name === '' && $phone === '' && $email === '') {
            sw_json(['error' => 'Укажите телефон или e-mail'], 400);
        }
        $id = sw_lead_create([
            'source'  => 'contact',
            'name'    => $name,
            'phone'   => $phone,
            'email'   => $email,
            'message' => $message,
        ]);
        $cfg = sw_mail_config();
        sw_mail_send($cfg['admin'], 'Новый контакт с сайта Skywood',
            "Имя: {$name}\nТелефон: {$phone}\nE-mail: {$email}\nСообщение: {$message}");
        sw_json(['ok' => true, 'id' => $id]);
    }

    // POST /api/replain — вебхук Re:plain. Сохраняет сообщения/контакты клиентов
    // как лиды, чтобы они были видны в админке. Формат события Re:plain кладём
    // в raw; вытаскиваем имя/контакты/текст «по возможности».
    if ($route === 'replain' && $method === 'POST') {
        $body = sw_request_body();
        $visitor = is_array($body['visitor'] ?? null) ? $body['visitor'] : [];
        $msg = $body['message'] ?? ($body['text'] ?? '');
        if (is_array($msg)) {
            $msg = $msg['text'] ?? json_encode($msg, JSON_UNESCAPED_UNICODE);
        }
        sw_lead_create([
            'source'  => 'replain',
            'name'    => trim((string)($visitor['name'] ?? ($body['name'] ?? ''))),
            'phone'   => trim((string)($visitor['phone'] ?? ($body['phone'] ?? ''))),
            'email'   => trim((string)($visitor['email'] ?? ($body['email'] ?? ''))),
            'message' => (string)$msg,
            'raw'     => $body,
        ]);
        sw_text('OK');
    }

    sw_json(['error' => 'Маршрут не найден'], 404);
} catch (Throwable $e) {
    sw_debug_add('exception', [
        'class'   => get_class($e),
        'message' => $e->getMessage(),
        'where'   => $e->getFile() . ':' . $e->getLine(),
        'trace'   => explode("\n", $e->getTraceAsString()),
    ]);
    sw_json(['error' => $e->getMessage() !== '' ? $e->getMessage() : 'Внутренняя ошибка сервера'], 500);
}
