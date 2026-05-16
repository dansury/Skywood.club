<?php
// Т-Банк (Tinkoff) EACQ client — mirrors src/services/tinkoff.js.

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/http.php';

// Request signature: root scalar params (excl. Receipt/DATA/Token/Shops)
// + Password, sorted by key, values concatenated, SHA-256 hex.
function sw_tinkoff_token(array $params): string
{
    $pairs = $params;
    $pairs['Password'] = sw_config()['tinkoff']['password'];
    $exclude = ['Receipt', 'DATA', 'Token', 'Shops'];

    $keys = [];
    foreach ($pairs as $key => $value) {
        if (in_array($key, $exclude, true)) {
            continue;
        }
        if ($value === null || is_array($value) || is_object($value)) {
            continue;
        }
        $keys[] = $key;
    }
    sort($keys);

    $value = '';
    foreach ($keys as $key) {
        $raw = $pairs[$key];
        if (is_bool($raw)) {
            $raw = $raw ? 'true' : 'false';
        }
        $value .= (string)$raw;
    }
    return hash('sha256', $value);
}

// 54-FZ receipt: item lines + a separate delivery line.
function sw_tinkoff_build_receipt(array $order): array
{
    $cfg = sw_config()['tinkoff'];
    $items = [];
    foreach ($order['items'] as $item) {
        $price = (int)round($item['price'] * 100);
        $items[] = [
            'Name'          => mb_substr((string)$item['name'], 0, 128),
            'Price'         => $price,
            'Quantity'      => $item['qty'],
            'Amount'        => $price * $item['qty'],
            'Tax'           => $cfg['vat'],
            'PaymentMethod' => 'full_prepayment',
            'PaymentObject' => 'commodity',
        ];
    }
    if (($order['deliveryCost'] ?? 0) > 0) {
        $delivery = (int)round($order['deliveryCost'] * 100);
        $items[] = [
            'Name'          => 'Доставка СДЭК',
            'Price'         => $delivery,
            'Quantity'      => 1,
            'Amount'        => $delivery,
            'Tax'           => $cfg['vat'],
            'PaymentMethod' => 'full_prepayment',
            'PaymentObject' => 'service',
        ];
    }
    $receipt = ['Taxation' => $cfg['taxation'], 'Items' => $items];
    if (!empty($order['customer']['email'])) {
        $receipt['Email'] = $order['customer']['email'];
    }
    if (!empty($order['customer']['phone'])) {
        $receipt['Phone'] = $order['customer']['phone'];
    }
    return $receipt;
}

function sw_tinkoff_call(string $method, array $payload): array
{
    $cfg = sw_config()['tinkoff'];
    $res = sw_http_json('POST', $cfg['api'] . '/' . $method, [
        'headers' => ['Content-Type: application/json'],
        'body'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $data = $res['data'];
    if (empty($data['Success']) && !empty($data['ErrorCode']) && (string)$data['ErrorCode'] !== '0') {
        $msg = $data['Message'] ?? $data['Details'] ?? ("Tinkoff {$method} error " . $data['ErrorCode']);
        throw new RuntimeException((string)$msg);
    }
    return $data;
}

// Create a payment. Returns ['paymentId', 'paymentUrl', 'status'].
function sw_tinkoff_init_payment(array $order): array
{
    $cfg = sw_config();
    $base = $cfg['baseUrl'];
    $orderId = (string)$order['id'];

    $params = [
        'TerminalKey'     => $cfg['tinkoff']['terminalKey'],
        'Amount'          => (int)round($order['total'] * 100),
        'OrderId'         => $orderId,
        'Description'     => "Заказ №{$orderId} — Skywood.club",
        'NotificationURL' => $base . '/api/payment/notify',
        'SuccessURL'      => $base . '/order.html?order=' . rawurlencode($orderId) . '&status=success',
        'FailURL'         => $base . '/order.html?order=' . rawurlencode($orderId) . '&status=fail',
    ];
    $payload = array_merge($params, [
        'Token'   => sw_tinkoff_token($params),
        'DATA'    => [
            'Phone' => $order['customer']['phone'],
            'Email' => $order['customer']['email'] ?? '',
        ],
        'Receipt' => sw_tinkoff_build_receipt($order),
    ]);
    $data = sw_tinkoff_call('Init', $payload);
    return [
        'paymentId'  => $data['PaymentId'] ?? null,
        'paymentUrl' => $data['PaymentURL'] ?? null,
        'status'     => $data['Status'] ?? null,
    ];
}

// Verify an incoming Т-Банк notification by recomputing its token.
function sw_tinkoff_verify_notification(array $body): bool
{
    if (empty($body['Token'])) {
        return false;
    }
    $token = (string)$body['Token'];
    unset($body['Token']);
    return hash_equals(sw_tinkoff_token($body), $token);
}
