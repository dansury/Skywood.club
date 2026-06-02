<?php
// Transactional email — order/preorder confirmations to the customer and a
// notification to the shop owner. Templates live in /emails.md; the builders
// below mirror them. Customer mail is signed personally by Яна.
//
// Uses PHP mail() (available on virtually all shared hosting). When mail is not
// configured/available the send is a no-op that returns false — order creation
// never fails because of email.

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Reads optional mail settings from .env (see sw_config for the rest):
//   MAIL_FROM   — From address (default info@skywood.club)
//   MAIL_FROM_NAME — display name (default "Яна — Skywood.club")
//   ADMIN_EMAIL — owner notification recipient (default company email)
//   MAIL_ENABLED — "0" disables sending entirely (default enabled)
function sw_mail_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $env = sw_load_env(__DIR__ . '/../../.env');
    $get = function ($k, $d = '') use ($env) {
        $v = $env[$k] ?? getenv($k);
        return ($v === false || $v === null || $v === '') ? $d : (string)$v;
    };
    $company = sw_config()['company'];
    $cfg = [
        'from'      => $get('MAIL_FROM', $company['email']),
        'fromName'  => $get('MAIL_FROM_NAME', 'Яна — Skywood.club'),
        'admin'     => $get('ADMIN_EMAIL', $company['email']),
        'enabled'   => $get('MAIL_ENABLED', '1') !== '0' && function_exists('mail'),
        'phone'     => $company['phone'],
    ];
    return $cfg;
}

function sw_mail_signature(): string
{
    $phone = sw_mail_config()['phone'];
    return "\n\n—\nС уважением,\nЯна, команда skywood.club\n"
        . "По всем вопросам звоните: {$phone}\n"
        . "WhatsApp / Telegram: {$phone}\nskywood.club";
}

// Low-level send. Returns true on success. text/plain, UTF-8.
function sw_mail_send(string $to, string $subject, string $body): bool
{
    $cfg = sw_mail_config();
    if (!$cfg['enabled'] || trim($to) === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $fromName = '=?UTF-8?B?' . base64_encode($cfg['fromName']) . '?=';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: ' . $fromName . ' <' . $cfg['from'] . '>',
        'Reply-To: ' . $cfg['from'],
        'X-Mailer: Skywood',
    ];
    $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    try {
        return @mail($to, $encSubject, $body, implode("\r\n", $headers));
    } catch (Throwable $e) {
        return false;
    }
}

/* ---------- order rendering helpers ---------- */

function sw_mail_money($n): string
{
    return number_format((float)$n, 0, ',', ' ') . ' ₽';
}

function sw_mail_items(array $order): string
{
    $lines = [];
    foreach (($order['items'] ?? []) as $it) {
        $name = (string)($it['name'] ?? '');
        $color = trim((string)($it['color'] ?? ''));
        $qty = (int)($it['qty'] ?? 1);
        $sum = sw_mail_money(((float)($it['price'] ?? 0)) * $qty);
        $lines[] = '• ' . $name . ($color !== '' ? ', ' . $color : '') . ' × ' . $qty . ' — ' . $sum;
    }
    return implode("\n", $lines);
}

function sw_mail_delivery(array $order): string
{
    $city = trim((string)($order['cityName'] ?? ''));
    $type = ($order['deliveryType'] ?? 'pvz') === 'door' ? 'курьер до двери' : 'пункт выдачи СДЭК';
    $cost = (int)($order['deliveryCost'] ?? 0);
    $where = $city !== '' ? (', ' . $city) : '';
    if (($order['deliveryType'] ?? '') === 'pvz' && !empty($order['pvzName'])) {
        $where .= ' (' . $order['pvzName'] . ')';
    }
    return 'СДЭК — ' . $type . $where . ' — ' . sw_mail_money($cost);
}

function sw_mail_payment_label(array $order): string
{
    return ($order['paymentMethod'] ?? 'online') === 'cod'
        ? 'при получении (наложенный платёж СДЭК)'
        : 'картой онлайн (Т-Банк)';
}

// Fills the order confirmation / preorder body for the customer.
function sw_mail_order_body(array $order, bool $preorder): string
{
    $name = trim((string)($order['customer']['name'] ?? '')) ?: 'друг';
    $id = (string)($order['id'] ?? '');
    $items = sw_mail_items($order);
    $delivery = sw_mail_delivery($order);
    $payment = sw_mail_payment_label($order);
    $total = sw_mail_money($order['total'] ?? 0);

    if ($preorder) {
        $body = "Здравствуйте, {$name}!\n\n"
            . "Спасибо за предзаказ в Skywood!\n\n"
            . "Сейчас этих палаток нет в наличии, но новая партия уже в пути. "
            . "Вы оформили предзаказ {$id}:\n{$items}\n\n"
            . "Доставка: {$delivery}\nСпособ оплаты: {$payment}\nИтого: {$total}\n\n"
            . "Как только товар поступит на склад (обычно 1–3 недели), мы сразу свяжемся с вами, "
            . "подтвердим заказ и отправим его в первую очередь. Если сроки окажутся дольше — предупредим заранее.\n\n"
            . "Спасибо, что дождётесь — это правда лучшие палатки для сна среди деревьев.";
    } else {
        $body = "Здравствуйте, {$name}!\n\n"
            . "Спасибо за заказ в Skywood — рады, что вы выбрали нас.\n\n"
            . "Ваш заказ {$id}:\n{$items}\n\n"
            . "Доставка: {$delivery}\nСпособ оплаты: {$payment}\nИтого: {$total}\n\n"
            . "Мы свяжемся с вами для подтверждения и отправим палатку со склада СДЭК — "
            . "после отправки пришлём трек-номер для отслеживания.\n\n"
            . "Если у вас есть вопросы — просто ответьте на это письмо или позвоните мне.";
    }
    return $body . sw_mail_signature();
}

function sw_mail_admin_body(array $order): string
{
    $c = $order['customer'] ?? [];
    return "Новый заказ на сайте.\n\n"
        . 'Номер: ' . ($order['id'] ?? '') . "\n"
        . 'Клиент: ' . ($c['name'] ?? '') . ', ' . ($c['phone'] ?? '') . ', ' . ($c['email'] ?? '') . "\n"
        . "Состав:\n" . sw_mail_items($order) . "\n"
        . 'Доставка: ' . sw_mail_delivery($order) . "\n"
        . 'Оплата: ' . sw_mail_payment_label($order) . "\n"
        . 'Итого: ' . sw_mail_money($order['total'] ?? 0) . "\n"
        . 'Комментарий: ' . trim((string)($c['comment'] ?? ''));
}

// Sends the customer confirmation (or preorder) email and the owner
// notification. Best-effort: returns ['customer'=>bool,'admin'=>bool].
function sw_mail_order(array $order, bool $preorder = false): array
{
    $cfg = sw_mail_config();
    $id = (string)($order['id'] ?? '');
    $subjCustomer = $preorder ? "Предзаказ {$id} оформлен — Skywood" : "Заказ {$id} принят — Skywood";
    $custEmail = trim((string)($order['customer']['email'] ?? ''));

    $customerOk = $custEmail !== ''
        ? sw_mail_send($custEmail, $subjCustomer, sw_mail_order_body($order, $preorder))
        : false;
    $adminOk = sw_mail_send(
        $cfg['admin'],
        'Новый заказ ' . $id . ' (' . sw_mail_payment_label($order) . ')',
        sw_mail_admin_body($order)
    );
    return ['customer' => $customerOk, 'admin' => $adminOk];
}
