<?php
// Next-season preorder — «Узнать о поступлении».
//
// A product can advertise an expected arrival date; visitors leave a contact
// instead of placing an order. Nothing here touches the cart, payments or
// delivery: the request is stored in the DB (table `preorders`, see lib/db.php)
// and e-mailed to the address configured in the admin panel.
//
// The date is shop-wide by default and can be overridden or switched off per
// product (products_ext.preorder_mode / preorder_date).

declare(strict_types=1);

require_once __DIR__ . '/db.php';

// Shop-wide defaults, overridable in the admin («Узнать о поступлении» tab).
const SW_PREORDER_DEFAULTS = [
    'preorder_enabled' => '1',
    'preorder_date'    => '2027-03-01',
    'preorder_email'   => 'Dansury@gmail.com',
];

// Contact channels. The visitor types one contact into a single field and the
// channel is derived from it (see sw_preorder_detect_method) — `whatsapp` only
// appears on rows stored by an earlier version of the form.
const SW_PREORDER_METHODS = [
    'phone'    => 'Телефон',
    'whatsapp' => 'WhatsApp',
    'telegram' => 'Telegram',
    'email'    => 'E-mail',
    'other'    => 'Контакт',
];

// Which channel the visitor left: e-mail, Telegram nick/link, phone number, or
// something else we simply pass through to the owner as written.
function sw_preorder_detect_method(string $contact): string
{
    if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
        return 'email';
    }
    if (preg_match('~^@[\w.]{3,}$~u', $contact) || stripos($contact, 't.me/') !== false) {
        return 'telegram';
    }
    if (preg_match('~^\+?[0-9\s\-()]{10,18}$~', $contact)) {
        return 'phone';
    }
    return 'other';
}

function sw_preorder_settings(): array
{
    return sw_settings(SW_PREORDER_DEFAULTS);
}

// Recipient of the request notifications. An empty setting falls back to the
// shipped default, so clearing the field in the admin never loses requests.
function sw_preorder_email(): string
{
    return trim(sw_setting('preorder_email', SW_PREORDER_DEFAULTS['preorder_email']));
}

const SW_MONTHS_RU = [
    1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
    5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
    9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
];

// 'YYYY-MM-DD' → '1 марта 2027'. Returns the input unchanged when unparseable.
function sw_date_label_ru(string $ymd): string
{
    $ts = strtotime($ymd);
    if ($ts === false) {
        return $ymd;
    }
    return (int)date('j', $ts) . ' ' . SW_MONTHS_RU[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

// The offer shown for one product: ['date' => 'YYYY-MM-DD', 'label' => '…'] or
// null when it is switched off (per product or shop-wide). $ext is the row from
// products_ext, or null when the product has no overrides yet.
function sw_preorder_offer(?array $ext): ?array
{
    $s = sw_preorder_settings();
    if (($s['preorder_enabled'] ?? '1') !== '1') {
        return null;
    }
    $mode = trim((string)($ext['preorder_mode'] ?? ''));
    if ($mode === 'off') {
        return null;
    }
    $date = $mode === 'custom'
        ? trim((string)($ext['preorder_date'] ?? ''))
        : trim((string)($s['preorder_date'] ?? ''));
    if ($date === '' || strtotime($date) === false) {
        return null;
    }
    return ['date' => $date, 'label' => sw_date_label_ru($date)];
}

// Validates a request body and normalises it into a storable record.
// Returns ['errors' => [...], 'record' => [...]].
function sw_preorder_validate(array $body): array
{
    $errors = [];
    $product = sw_catalog_get((string)($body['productId'] ?? ''));
    $offer = null;
    if ($product === null) {
        $errors[] = 'Товар не найден';
    } else {
        $offer = $product['preorderOffer'] ?? null;
        if ($offer === null) {
            $errors[] = 'Предзаказ на этот товар сейчас не открыт';
        }
    }

    // Colour is optional; an unknown one is dropped rather than rejected.
    $colors = $product['options']['color'] ?? [];
    $color = (string)($body['color'] ?? '');
    if (!is_array($colors) || !in_array($color, $colors, true)) {
        $color = '';
    }

    $name = trim((string)($body['name'] ?? ''));
    if (mb_strlen($name) < 2) {
        $errors[] = 'Укажите, как к вам обращаться';
    }

    // One free-form contact field: телефон, Telegram или e-mail. We accept
    // whatever the visitor prefers and only catch a clearly mistyped e-mail —
    // an unreachable contact is worse than a strict form.
    $contact = trim((string)($body['contact'] ?? ''));
    $method = sw_preorder_detect_method($contact);
    if (mb_strlen($contact) < 3) {
        $errors[] = 'Укажите телефон, Telegram или e-mail';
    } elseif ($method === 'other' && strpos(ltrim($contact, '@'), '@') !== false) {
        $errors[] = 'Проверьте адрес e-mail';
    }

    if (empty($body['consent'])) {
        $errors[] = 'Подтвердите согласие на обработку персональных данных';
    }

    return [
        'errors' => $errors,
        'record' => [
            'productId'     => $product['id'] ?? '',
            'productName'   => $product['name'] ?? '',
            'color'         => $color,
            'readyDate'     => $offer['date'] ?? '',
            'readyLabel'    => $offer['label'] ?? '',
            'name'          => $name,
            'contactMethod' => $method,
            'contact'       => $contact,
            'address'       => mb_substr(trim((string)($body['address'] ?? '')), 0, 300),
            'comment'       => mb_substr(trim((string)($body['comment'] ?? '')), 0, 1000),
        ],
    ];
}

// Validates, stores and notifies. Mail is best-effort — a failing send never
// discards a request that is already in the DB.
function sw_preorder_submit(array $body): array
{
    $v = sw_preorder_validate($body);
    if (count($v['errors']) > 0) {
        return ['errors' => $v['errors']];
    }
    $record = $v['record'];
    $id = sw_preorder_create($record);
    sw_mail_preorder($record);
    return [
        'id'         => $id,
        'readyDate'  => $record['readyDate'],
        'readyLabel' => $record['readyLabel'],
    ];
}
