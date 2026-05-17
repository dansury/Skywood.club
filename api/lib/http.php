<?php
// Minimal cURL wrapper for the Tinkoff / CDEK API calls.

declare(strict_types=1);

require_once __DIR__ . '/debug.php';

function sw_http_request(string $method, string $url, array $opts = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('На хостинге не включено расширение PHP cURL');
    }
    $headers = $opts['headers'] ?? [];
    $body = $opts['body'] ?? null;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $errstr = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    sw_debug_add('http', [
        'method'   => $method,
        'url'      => sw_debug_mask($url),
        'status'   => $status,
        'curlErr'  => $errno !== 0 ? "{$errno} {$errstr}" : null,
        'request'  => $body !== null ? mb_substr(sw_debug_mask((string)$body), 0, 600) : null,
        'response' => mb_substr((string)$raw, 0, 1500),
    ]);

    if ($errno !== 0) {
        throw new RuntimeException("Сетевая ошибка: {$errstr}");
    }
    return ['status' => $status, 'body' => (string)$raw];
}

function sw_http_json(string $method, string $url, array $opts = []): array
{
    $res = sw_http_request($method, $url, $opts);
    $data = json_decode($res['body'], true);
    return [
        'status' => $res['status'],
        'data'   => is_array($data) ? $data : [],
        'raw'    => $res['body'],
    ];
}
