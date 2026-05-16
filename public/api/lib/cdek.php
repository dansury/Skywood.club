<?php
// СДЭК (CDEK) API v2 client — mirrors src/services/cdek.js.

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/http.php';

// Тарифы СДЭК — отправка со склада/ПВЗ продавца.
const SW_CDEK_TARIFF_PVZ  = 136; // склад-склад — выдача в ПВЗ
const SW_CDEK_TARIFF_DOOR = 137; // склад-дверь — курьером до адреса

// OAuth client_credentials token, cached to a file until it expires.
function sw_cdek_token(): string
{
    $cfg = sw_config()['cdek'];
    $cacheFile = __DIR__ . '/../../data/.cdek-token.json';

    if (is_file($cacheFile)) {
        $cache = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cache) && !empty($cache['value']) && (int)($cache['expiresAt'] ?? 0) > time()) {
            return (string)$cache['value'];
        }
    }

    $res = sw_http_json('POST', $cfg['api'] . '/oauth/token', [
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'body'    => http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $cfg['account'],
            'client_secret' => $cfg['securePassword'],
        ]),
    ]);
    $token = (string)($res['data']['access_token'] ?? '');
    if ($token === '') {
        throw new RuntimeException('CDEK: не удалось получить токен авторизации');
    }
    $expiresIn = (int)($res['data']['expires_in'] ?? 3600);
    @file_put_contents($cacheFile, json_encode([
        'value'     => $token,
        'expiresAt' => time() + $expiresIn - 60,
    ]), LOCK_EX);
    return $token;
}

function sw_cdek_api(string $path, array $opts = [])
{
    $cfg = sw_config()['cdek'];
    $method = $opts['method'] ?? 'GET';
    $url = $cfg['api'] . $path;

    if (!empty($opts['query'])) {
        $query = [];
        foreach ($opts['query'] as $key => $value) {
            if ($value !== null && $value !== '') {
                $query[$key] = $value;
            }
        }
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
    }

    $headers = ['Authorization: Bearer ' . sw_cdek_token(), 'Accept: application/json'];
    $body = null;
    if (isset($opts['body'])) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($opts['body'], JSON_UNESCAPED_UNICODE);
    }

    $res = sw_http_json($method, $url, ['headers' => $headers, 'body' => $body]);
    if ($res['status'] >= 400) {
        $msg = $res['data']['errors'][0]['message']
            ?? $res['data']['requests'][0]['errors'][0]['message']
            ?? ('CDEK ' . $res['status']);
        throw new RuntimeException((string)$msg);
    }
    return $res['data'];
}

// Подсказка городов по названию.
function sw_cdek_search_cities(string $query): array
{
    $list = sw_cdek_api('/location/cities', [
        'query' => ['city' => $query, 'country_codes' => 'RU', 'size' => 12],
    ]);
    $out = [];
    foreach ((is_array($list) ? $list : []) as $city) {
        $out[] = [
            'code'       => $city['code'] ?? null,
            'city'       => $city['city'] ?? '',
            'region'     => $city['region'] ?? '',
            'fias'       => $city['fias_guid'] ?? null,
            'postalCode' => $city['postal_code'] ?? null,
        ];
    }
    return $out;
}

// Пункты выдачи в городе.
function sw_cdek_pickup_points(int $cityCode): array
{
    $list = sw_cdek_api('/deliverypoints', [
        'query' => ['city_code' => $cityCode, 'type' => 'PVZ', 'country_code' => 'RU'],
    ]);
    $out = [];
    foreach ((is_array($list) ? $list : []) as $point) {
        $loc = $point['location'] ?? [];
        $out[] = [
            'code'     => $point['code'] ?? null,
            'name'     => $point['name'] ?? '',
            'address'  => $loc['address_full'] ?? ($loc['address'] ?? ''),
            'workTime' => $point['work_time'] ?? '',
            'lat'      => $loc['latitude'] ?? null,
            'lon'      => $loc['longitude'] ?? null,
        ];
    }
    return $out;
}

// Один пакет: суммарный вес + габариты из карточек товаров.
function sw_cdek_packages(array $items): array
{
    $weight = 0;
    $maxL = 0;
    $maxW = 0;
    $sumH = 0;
    foreach ($items as $item) {
        $qty = (int)$item['qty'];
        $weight += (int)($item['weightGrams'] ?? 1000) * $qty;
        $maxL = max($maxL, (int)($item['packLength'] ?? 0));
        $maxW = max($maxW, (int)($item['packWidth'] ?? 0));
        $sumH += (int)($item['packHeight'] ?? 0) * $qty;
    }
    return [[
        'weight' => $weight ?: 1000,
        'length' => $maxL ?: 50,
        'width'  => $maxW ?: 20,
        'height' => $sumH ?: 20,
    ]];
}

// Расчёт стоимости и срока доставки.
function sw_cdek_calculate(int $tariffCode, int $toCityCode, array $items): array
{
    $cfg = sw_config()['cdek'];
    $fromLocation = ['postal_code' => $cfg['senderPostalCode']];
    if ($cfg['senderCityCode']) {
        $fromLocation['code'] = $cfg['senderCityCode'];
    }
    $data = sw_cdek_api('/calculator/tariff', [
        'method' => 'POST',
        'body'   => [
            'tariff_code'   => $tariffCode,
            'from_location' => $fromLocation,
            'to_location'   => ['code' => $toCityCode],
            'packages'      => sw_cdek_packages($items),
        ],
    ]);
    return [
        'cost'      => (int)round((float)($data['total_sum'] ?? 0)),
        'periodMin' => $data['period_min'] ?? null,
        'periodMax' => $data['period_max'] ?? null,
    ];
}

// Создание заказа в СДЭК. При наложенном платеже (cod) СДЭК принимает
// деньги с получателя: товары — в items[].payment, доставка — в
// delivery_recipient_cost.
function sw_cdek_create_order(array $order): array
{
    $cfg = sw_config()['cdek'];
    $cod = ($order['paymentMethod'] ?? '') === 'cod';

    $pkgItems = [];
    foreach ($order['items'] as $item) {
        $color = $item['color'] ?? '';
        $pkgItems[] = [
            'name'     => $item['name'] . ($color !== '' ? ", {$color}" : ''),
            'ware_key' => mb_substr($item['id'] . ($color !== '' ? '-' . $color : ''), 0, 50),
            'cost'     => $item['price'],
            'payment'  => ['value' => $cod ? $item['price'] : 0],
            'weight'   => $item['weightGrams'] ?? 1000,
            'amount'   => $item['qty'],
        ];
    }

    $pkg = sw_cdek_packages($order['items'])[0];
    $fromLocation = ['postal_code' => $cfg['senderPostalCode']];
    if ($cfg['senderCityCode']) {
        $fromLocation['code'] = $cfg['senderCityCode'];
    }

    $recipient = [
        'name'   => $order['customer']['name'],
        'phones' => [['number' => $order['customer']['phone']]],
    ];
    if (!empty($order['customer']['email'])) {
        $recipient['email'] = $order['customer']['email'];
    }

    $body = [
        'tariff_code'   => $order['tariffCode'],
        'recipient'     => $recipient,
        'from_location' => $fromLocation,
        'packages'      => [[
            'number' => (string)$order['id'],
            'weight' => $pkg['weight'],
            'length' => $pkg['length'],
            'width'  => $pkg['width'],
            'height' => $pkg['height'],
            'items'  => $pkgItems,
        ]],
    ];
    if ($cfg['shipmentPoint'] !== '') {
        $body['shipment_point'] = $cfg['shipmentPoint'];
    }
    if ($cod) {
        $body['delivery_recipient_cost'] = ['value' => $order['deliveryCost']];
    }
    if (($order['deliveryType'] ?? 'pvz') === 'pvz') {
        $body['delivery_point'] = $order['pvzCode'];
    } else {
        $body['to_location'] = [
            'code'    => $order['toCityCode'],
            'address' => $order['customer']['address'],
        ];
    }

    $data = sw_cdek_api('/orders', ['method' => 'POST', 'body' => $body]);
    return ['uuid' => $data['entity']['uuid'] ?? null, 'raw' => $data];
}
