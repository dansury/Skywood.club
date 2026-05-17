<?php
// Reads public/.env and builds the runtime config array.
// Mirrors src/config.js (the Node backend) so both stay interchangeable.

declare(strict_types=1);

function sw_load_env(string $path): array
{
    $env = [];
    if (!is_file($path)) {
        return $env;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        $len = strlen($val);
        if ($len >= 2) {
            $first = $val[0];
            $last = $val[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $val = substr($val, 1, -1);
            }
        }
        if ($key !== '') {
            $env[$key] = $val;
        }
    }
    return $env;
}

// Public site URL derived from the live request (used when BASE_URL is empty).
function sw_detect_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $pos = strpos($uri, '/api/');
    $basePath = $pos === false ? rtrim(str_replace('\\', '/', dirname($uri)), '/') : substr($uri, 0, $pos);
    return $scheme . '://' . $host . $basePath;
}

function sw_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $env = sw_load_env(__DIR__ . '/../../.env');
    $get = function (string $key, string $default = '') use ($env): string {
        $val = $env[$key] ?? getenv($key);
        return ($val === false || $val === null || $val === '') ? $default : (string)$val;
    };

    $baseUrl = rtrim($get('BASE_URL', ''), '/');
    if ($baseUrl === '') {
        $baseUrl = sw_detect_base_url();
    }

    $tinkoff = [
        'terminalKey' => $get('TINKOFF_TERMINAL_KEY'),
        'password'    => $get('TINKOFF_PASSWORD'),
        'api'         => rtrim($get('TINKOFF_API', 'https://securepay.tinkoff.ru/v2'), '/'),
        'taxation'    => $get('TINKOFF_TAXATION', 'usn_income'),
        'vat'         => $get('TINKOFF_VAT', 'none'),
    ];
    $tinkoff['enabled'] = $tinkoff['terminalKey'] !== '' && $tinkoff['password'] !== '';

    $cdek = [
        'account'          => $get('CDEK_ACCOUNT'),
        'securePassword'   => $get('CDEK_SECURE_PASSWORD'),
        'api'              => rtrim($get('CDEK_API', 'https://api.cdek.ru/v2'), '/'),
        'senderCityCode'   => (int)$get('CDEK_SENDER_CITY_CODE', '0'),
        'senderPostalCode' => $get('CDEK_SENDER_POSTAL_CODE', '143405'),
        'shipmentPoint'    => $get('CDEK_SHIPMENT_POINT'),
    ];
    $cdek['enabled'] = $cdek['account'] !== '' && $cdek['securePassword'] !== '';

    $config = [
        'baseUrl' => $baseUrl,
        'tinkoff' => $tinkoff,
        'cdek'    => $cdek,
        'company' => [
            'legalName' => 'ИП Сурков К.А.',
            'inn'       => '773135420168',
            'phone'     => '+7 977 508-45-85',
            'phoneRaw'  => '+79775084585',
            'email'     => 'info@skywood.club',
            'telegram'  => 'https://t.me/skywood_club',
            'whatsapp'  => 'https://wa.me/79775084585',
        ],
    ];
    // demo — true when either integration is not configured (calc/payment stubbed).
    $config['demo'] = !$tinkoff['enabled'] || !$cdek['enabled'];

    return $config;
}
