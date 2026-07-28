<?php
// Skywood admin — /admin. Login (ADMIN_PASS in .env), then manage stock,
// prices, discounts and view orders, customers and leads. All mutable data
// lives in the SQLite DB (api/lib/db.php); the catalog text stays in
// data/products.json.

declare(strict_types=1);

require_once __DIR__ . '/api/lib/config.php';
require_once __DIR__ . '/api/lib/db.php';
require_once __DIR__ . '/api/lib/catalog.php';
require_once __DIR__ . '/api/lib/store.php';
require_once __DIR__ . '/api/lib/preorder.php';

session_start();

$env = sw_load_env(__DIR__ . '/.env');
// Accept ADMIN_PASS, then the legacy lowercase `adminpass`, then a safe default.
$adminPass = $env['ADMIN_PASS'] ?? $env['adminpass'] ?? 'adminpass';
$adminLogin = $env['ADMIN_LOGIN'] ?? '';

$flash = '';
$flashType = 'ok';

// CSRF token.
if (empty($_SESSION['sw_csrf'])) {
    $_SESSION['sw_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['sw_csrf'];

function sw_admin_post(string $key, $default = '')
{
    return $_POST[$key] ?? $default;
}

// ---- auth ----
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $okLogin = $adminLogin === '' || hash_equals($adminLogin, (string)sw_admin_post('login_name'));
    if ($okLogin && hash_equals($adminPass, (string)sw_admin_post('password'))) {
        session_regenerate_id(true);
        $_SESSION['sw_auth'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $flash = 'Неверный логин или пароль';
    $flashType = 'err';
}

$authed = !empty($_SESSION['sw_auth']);

// ---- mutations (auth + CSRF required) ----
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!hash_equals($csrf, (string)sw_admin_post('csrf'))) {
        $flash = 'Сессия устарела, повторите';
        $flashType = 'err';
    } elseif (!sw_db_available()) {
        $flash = 'База данных недоступна: включите расширение pdo_sqlite на хостинге';
        $flashType = 'err';
    } elseif ($_POST['action'] === 'save_product') {
        $pid = (string)sw_admin_post('product_id');
        $num = function ($k) {
            $v = trim((string)sw_admin_post($k, ''));
            return $v === '' ? null : (int)$v;
        };
        $mode = (string)sw_admin_post('preorder_mode', 'default');
        if (!in_array($mode, ['default', 'custom', 'off'], true)) {
            $mode = 'default';
        }
        sw_db_product_ext_save($pid, [
            'price'            => $num('price'),
            'old_price'        => $num('old_price'),
            'available'        => isset($_POST['available']) ? 1 : 0,
            'discount_percent' => (int)sw_admin_post('discount_percent', 0),
            'discount_starts'  => trim((string)sw_admin_post('discount_starts', '')) ?: null,
            'discount_ends'    => trim((string)sw_admin_post('discount_ends', '')) ?: null,
            'preorder_mode'    => $mode,
            'preorder_date'    => trim((string)sw_admin_post('preorder_date', '')) ?: null,
        ]);
        $stock = $_POST['stock'] ?? [];
        if (is_array($stock)) {
            foreach ($stock as $color => $qty) {
                sw_db_stock_set($pid, (string)$color, (int)$qty);
            }
        }
        $flash = 'Сохранено: ' . $pid;
    } elseif ($_POST['action'] === 'save_preorder') {
        // Общие настройки предзаказа «Узнать о поступлении».
        sw_setting_set('preorder_enabled', isset($_POST['preorder_enabled']) ? '1' : '0');
        $date = trim((string)sw_admin_post('preorder_date', ''));
        $email = trim((string)sw_admin_post('preorder_email', ''));
        if ($date !== '' && strtotime($date) === false) {
            $flash = 'Дата поступления указана неверно — сохранены остальные настройки';
            $flashType = 'err';
        } else {
            sw_setting_set('preorder_date', $date);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flash = 'E-mail для заявок указан неверно — сохранены остальные настройки';
            $flashType = 'err';
        } else {
            sw_setting_set('preorder_email', $email);
        }
        if ($flash === '') {
            $flash = 'Настройки предзаказа сохранены';
        }
    }
}

// ---- page helpers ----
function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function rub($n): string
{
    return number_format((float)$n, 0, ',', ' ') . ' ₽';
}

$tab = $_GET['tab'] ?? 'stock';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Админка — Skywood</title>
<style>
  :root { --ink:#0e1712; --accent:#7fbf5b; --line:#e3e7e3; }
  * { box-sizing: border-box; }
  body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
    margin:0; background:#f4f6f4; color:#1c241e; }
  header { background:var(--ink); color:#fff; padding:14px 22px; display:flex;
    align-items:center; justify-content:space-between; }
  header h1 { font-size:1.1rem; margin:0; }
  header a { color:#cfe6bf; text-decoration:none; }
  .wrap { max-width:1100px; margin:0 auto; padding:22px; }
  .tabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:18px; }
  .tabs a { padding:9px 16px; border-radius:8px; background:#fff; color:#1c241e;
    text-decoration:none; border:1px solid var(--line); font-weight:600; }
  .tabs a.active { background:var(--ink); color:#fff; border-color:var(--ink); }
  .card { background:#fff; border:1px solid var(--line); border-radius:12px;
    padding:18px 20px; margin-bottom:18px; }
  .card h2 { margin:0 0 14px; font-size:1.05rem; }
  label { display:block; font-size:.82rem; color:#5a665c; margin:8px 0 3px; }
  input[type=text], input[type=number], input[type=datetime-local], input[type=date],
  input[type=password], select {
    width:100%; padding:8px 10px; border:1px solid #ccd3cc; border-radius:7px;
    font-size:14px; background:#fff; }
  .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; }
  .row { display:flex; align-items:center; gap:8px; }
  button { background:var(--ink); color:#fff; border:0; padding:9px 18px; border-radius:8px;
    cursor:pointer; font-size:14px; font-weight:600; }
  button:hover { background:#1a2d1f; }
  table { width:100%; border-collapse:collapse; font-size:13px; }
  th, td { text-align:left; padding:9px 8px; border-bottom:1px solid var(--line); vertical-align:top; }
  th { background:#eef2ee; }
  .flash { padding:11px 15px; border-radius:9px; margin-bottom:16px; font-weight:600; }
  .flash.ok { background:#e6f4dd; color:#2f6b1e; }
  .flash.err { background:#fbe4e4; color:#a12727; }
  .muted { color:#7a857c; }
  .pill { display:inline-block; padding:2px 9px; border-radius:20px; font-size:12px; background:#eef2ee; }
  .pill.pre { background:#fff0d8; color:#8a5a00; }
  .pill.paid { background:#e6f4dd; color:#2f6b1e; }
  .login { max-width:360px; margin:8vh auto; }
  .login input { margin-bottom:10px; }
  .warn { background:#fff0d8; color:#8a5a00; padding:11px 15px; border-radius:9px; margin-bottom:16px; }
  fieldset { border:1px solid var(--line); border-radius:9px; margin:0 0 14px; padding:12px 14px; }
  legend { font-weight:700; padding:0 6px; }
</style>
</head>
<body>
<?php if (!$authed): ?>
  <div class="wrap">
    <form class="card login" method="post">
      <h2>Вход в админку</h2>
      <?php if ($flash): ?><div class="flash <?= h($flashType) ?>"><?= h($flash) ?></div><?php endif; ?>
      <?php if ($adminLogin !== ''): ?>
        <label>Логин</label><input type="text" name="login_name" autofocus>
      <?php endif; ?>
      <label>Пароль</label><input type="password" name="password" <?= $adminLogin === '' ? 'autofocus' : '' ?> required>
      <input type="hidden" name="login" value="1">
      <div style="margin-top:12px"><button type="submit">Войти</button></div>
    </form>
  </div>
  </body></html>
<?php exit; endif; ?>

<header>
  <h1>Skywood — админка</h1>
  <div><a href="index.html" target="_blank">Сайт ↗</a> &nbsp; <a href="?logout=1">Выйти</a></div>
</header>

<div class="wrap">
  <?php if ($flash): ?><div class="flash <?= h($flashType) ?>"><?= h($flash) ?></div><?php endif; ?>
  <?php if (!sw_db_available()): ?>
    <div class="warn">База данных (SQLite) недоступна. Включите расширение
      <b>pdo_sqlite</b> на хостинге — иначе изменения остатков, цен и скидок
      не сохраняются.</div>
  <?php endif; ?>

  <nav class="tabs">
    <a href="?tab=stock"   class="<?= $tab === 'stock' ? 'active' : '' ?>">Товары и остатки</a>
    <a href="?tab=orders"  class="<?= $tab === 'orders' ? 'active' : '' ?>">Заказы</a>
    <a href="?tab=clients" class="<?= $tab === 'clients' ? 'active' : '' ?>">Клиенты</a>
    <a href="?tab=leads"   class="<?= $tab === 'leads' ? 'active' : '' ?>">Лиды / Re:plain</a>
    <a href="?tab=preorder" class="<?= $tab === 'preorder' ? 'active' : '' ?>">Узнать о поступлении</a>
  </nav>

<?php if ($tab === 'stock'):
    $products = sw_catalog_all();
    $psettings = sw_preorder_settings();
    $globalDate = trim((string)$psettings['preorder_date']);
    $globalLabel = $globalDate !== '' ? sw_date_label_ru($globalDate) : 'не задана';
    foreach ($products as $p):
        $colors = $p['options']['color'] ?? [];
        if (!is_array($colors) || count($colors) === 0) { $colors = ['']; }
        $disc = $p['discount'] ?? null;
        $ext = sw_db_products_ext()[$p['id']] ?? [];
?>
  <form class="card" method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="save_product">
    <input type="hidden" name="product_id" value="<?= h($p['id']) ?>">
    <h2><?= h($p['name']) ?> <span class="muted">(<?= h($p['id']) ?>)</span>
      <?php if (!empty($p['preorder'])): ?><span class="pill pre">предзаказ</span><?php endif; ?>
    </h2>
    <div class="grid">
      <div><label>Цена, ₽ (база: <?= rub(sw_catalog_raw_price($p['id'])) ?>)</label>
        <input type="number" name="price" value="<?= h($ext['price'] ?? '') ?>" placeholder="<?= h(sw_catalog_raw_price($p['id'])) ?>"></div>
      <div><label>Старая цена, ₽</label>
        <input type="number" name="old_price" value="<?= h($ext['old_price'] ?? '') ?>"></div>
      <div><label>В продаже</label>
        <div class="row"><input type="checkbox" name="available" <?= $p['available'] ? 'checked' : '' ?>>
          <span class="muted">показывать как доступный</span></div></div>
    </div>
    <fieldset>
      <legend>Скидка</legend>
      <div class="grid">
        <div><label>Процент, %</label>
          <input type="number" name="discount_percent" min="0" max="95" value="<?= h($ext['discount_percent'] ?? 0) ?>"></div>
        <div><label>Действует с</label>
          <input type="datetime-local" name="discount_starts" value="<?= h($ext['discount_starts'] ?? '') ?>"></div>
        <div><label>Действует до</label>
          <input type="datetime-local" name="discount_ends" value="<?= h($ext['discount_ends'] ?? '') ?>"></div>
      </div>
      <?php if ($disc): ?><p class="muted">Сейчас активна: −<?= (int)$disc['percent'] ?>% → цена <?= rub($p['price']) ?></p><?php endif; ?>
    </fieldset>
    <fieldset>
      <legend>Остатки по вариантам</legend>
      <div class="grid">
        <?php foreach ($colors as $color):
            $q = sw_db_stock_get($p['id'], (string)$color); ?>
          <div><label><?= $color === '' ? 'Без цвета' : h($color) ?></label>
            <input type="number" name="stock[<?= h($color) ?>]" min="0" value="<?= $q === null ? '' : (int)$q ?>" placeholder="не учитывается"></div>
        <?php endforeach; ?>
      </div>
      <p class="muted">Пусто = остаток не отслеживается (товар всегда доступен). 0 = нет в наличии → предзаказ.</p>
    </fieldset>
    <fieldset>
      <legend>Предзаказ «Узнать о поступлении»</legend>
      <?php $pmode = (string)($ext['preorder_mode'] ?? 'default');
            // Дата товара без учёта остатка — витрина покажет её при нуле.
            $offer = sw_preorder_offer($ext ?: null); ?>
      <div class="grid">
        <div><label>Режим</label>
          <select name="preorder_mode">
            <option value="default" <?= $pmode === 'custom' || $pmode === 'off' ? '' : 'selected' ?>>Общая дата (<?= h($globalLabel) ?>)</option>
            <option value="custom" <?= $pmode === 'custom' ? 'selected' : '' ?>>Своя дата</option>
            <option value="off" <?= $pmode === 'off' ? 'selected' : '' ?>>Выключен</option>
          </select></div>
        <div><label>Своя дата поступления</label>
          <input type="date" name="preorder_date" value="<?= h($ext['preorder_date'] ?? '') ?>"></div>
      </div>
      <p class="muted">Кнопка «Узнать о поступлении» появляется на витрине сама,
        когда остаток становится нулевым — включать её отдельно не нужно.
        Сейчас:
        <?php if (!$offer): ?>выключена для этого товара<?php
              elseif (!empty($p['preorder'])): ?><b>показывается</b>, ожидаем <?= h($offer['label']) ?><?php
              else: ?>появится при нулевом остатке, ожидаем <?= h($offer['label']) ?><?php endif; ?>.
        Своя дата учитывается только в режиме «Своя дата».</p>
    </fieldset>
    <button type="submit">Сохранить</button>
  </form>
<?php endforeach; ?>

<?php elseif ($tab === 'orders'):
    $orders = array_reverse(sw_orders_load()); ?>
  <div class="card">
    <h2>Заказы (<?= count($orders) ?>)</h2>
    <table>
      <thead><tr><th>№</th><th>Дата</th><th>Клиент</th><th>Состав</th><th>Доставка</th><th>Оплата</th><th>Сумма</th><th>Статус</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o):
        $c = $o['customer'] ?? []; ?>
        <tr>
          <td><b><?= h($o['id'] ?? '') ?></b><?= !empty($o['preorder']) ? ' <span class="pill pre">предзаказ</span>' : '' ?></td>
          <td class="muted"><?= h(substr((string)($o['createdAt'] ?? ''), 0, 16)) ?></td>
          <td><?= h($c['name'] ?? '') ?><br><span class="muted"><?= h($c['phone'] ?? '') ?><br><?= h($c['email'] ?? '') ?></span></td>
          <td><?php foreach (($o['items'] ?? []) as $it): ?>
                <?= h($it['name'] ?? '') ?><?= !empty($it['color']) ? ', ' . h($it['color']) : '' ?> × <?= (int)($it['qty'] ?? 1) ?><br>
              <?php endforeach; ?></td>
          <td><?= h($o['cityName'] ?? '') ?><br><span class="muted"><?= ($o['deliveryType'] ?? '') === 'door' ? 'курьер' : h($o['pvzName'] ?? 'ПВЗ') ?></span></td>
          <td><?= ($o['paymentMethod'] ?? '') === 'cod' ? 'при получении' : 'онлайн' ?></td>
          <td><?= rub($o['total'] ?? 0) ?></td>
          <td><span class="pill <?= ($o['status'] ?? '') === 'paid' ? 'paid' : '' ?>"><?= h($o['status'] ?? '') ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($tab === 'clients'):
    // Агрегируем клиентов из заказов (по e-mail/телефону) + контакты из лидов.
    $clients = [];
    $key = function ($c) {
        return strtolower(trim((string)($c['email'] ?? ''))) ?: preg_replace('~\D~', '', (string)($c['phone'] ?? ''));
    };
    foreach (sw_orders_load() as $o) {
        $c = $o['customer'] ?? [];
        $k = $key($c);
        if ($k === '') { continue; }
        if (!isset($clients[$k])) {
            $clients[$k] = ['name' => $c['name'] ?? '', 'phone' => $c['phone'] ?? '', 'email' => $c['email'] ?? '', 'orders' => 0, 'sum' => 0, 'last' => '', 'source' => 'заказ'];
        }
        $clients[$k]['orders']++;
        $clients[$k]['sum'] += (float)($o['total'] ?? 0);
        $clients[$k]['last'] = max($clients[$k]['last'], (string)($o['createdAt'] ?? ''));
    }
    foreach (sw_leads_all() as $l) {
        $k = strtolower(trim((string)$l['email'])) ?: preg_replace('~\D~', '', (string)$l['phone']);
        if ($k === '' || isset($clients[$k])) { continue; }
        $clients[$k] = ['name' => $l['name'], 'phone' => $l['phone'], 'email' => $l['email'], 'orders' => 0, 'sum' => 0, 'last' => $l['created_at'], 'source' => $l['source']];
    }
    // Заявки «Узнать о поступлении» — тоже контакты клиентов.
    foreach (sw_preorders_all() as $r) {
        $isMail = ($r['contact_method'] ?? '') === 'email';
        $email = $isMail ? (string)$r['contact'] : '';
        $phone = $isMail ? '' : (string)$r['contact'];
        $k = strtolower(trim($email)) ?: preg_replace('~\D~', '', $phone) ?: strtolower(trim($phone));
        if ($k === '' || isset($clients[$k])) { continue; }
        $clients[$k] = ['name' => $r['name'], 'phone' => $phone, 'email' => $email, 'orders' => 0, 'sum' => 0, 'last' => $r['created_at'], 'source' => 'поступление'];
    } ?>
  <div class="card">
    <h2>Клиенты (<?= count($clients) ?>)</h2>
    <table>
      <thead><tr><th>Имя</th><th>Телефон</th><th>E-mail</th><th>Заказов</th><th>Сумма</th><th>Последняя активность</th><th>Источник</th></tr></thead>
      <tbody>
      <?php foreach ($clients as $cl): ?>
        <tr>
          <td><?= h($cl['name']) ?></td>
          <td><?= h($cl['phone']) ?></td>
          <td><?= h($cl['email']) ?></td>
          <td><?= (int)$cl['orders'] ?></td>
          <td><?= $cl['sum'] > 0 ? rub($cl['sum']) : '—' ?></td>
          <td class="muted"><?= h(substr((string)$cl['last'], 0, 16)) ?></td>
          <td><span class="pill"><?= h($cl['source']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($tab === 'leads'):
    $leads = sw_leads_all(); ?>
  <div class="card">
    <h2>Лиды и сообщения (<?= count($leads) ?>)</h2>
    <p class="muted">Контакты из форм и события чата Re:plain. Чтобы сюда
      попадала переписка Re:plain, настройте вебхук Re:plain на адрес
      <code><?= h(rtrim(sw_config()['baseUrl'], '/')) ?>/api/replain</code>.</p>
    <table>
      <thead><tr><th>Дата</th><th>Источник</th><th>Имя</th><th>Контакты</th><th>Сообщение</th></tr></thead>
      <tbody>
      <?php foreach ($leads as $l): ?>
        <tr>
          <td class="muted"><?= h(substr((string)$l['created_at'], 0, 16)) ?></td>
          <td><span class="pill"><?= h($l['source']) ?></span></td>
          <td><?= h($l['name']) ?></td>
          <td><?= h($l['phone']) ?><?= $l['phone'] && $l['email'] ? '<br>' : '' ?><?= h($l['email']) ?></td>
          <td><?= nl2br(h($l['message'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$leads): ?><tr><td colspan="5" class="muted">Пока нет записей.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($tab === 'preorder'):
    $ps = sw_preorder_settings();
    $requests = sw_preorders_all(); ?>
  <form class="card" method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="save_preorder">
    <h2>Настройки предзаказа</h2>
    <p class="muted">Когда остаток товара становится нулевым, на его карточке
      появляется кнопка «Узнать о поступлении»: клиент оставляет контакты и ждёт
      следующую партию. Ничего не оплачивается, включать по товарам не нужно.</p>
    <div class="grid">
      <div><label>Предлагать предзаказ</label>
        <div class="row"><input type="checkbox" name="preorder_enabled"
          <?= ($ps['preorder_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
          <span class="muted">показывать кнопку на сайте</span></div></div>
      <div><label>Дата поступления по умолчанию</label>
        <input type="date" name="preorder_date" value="<?= h($ps['preorder_date'] ?? '') ?>"></div>
      <div><label>Куда присылать заявки</label>
        <input type="text" name="preorder_email" value="<?= h($ps['preorder_email'] ?? '') ?>"
          placeholder="<?= h(SW_PREORDER_DEFAULTS['preorder_email']) ?>"></div>
    </div>
    <p class="muted">Дата действует для всех товаров; отдельному товару можно
      задать свою дату или выключить предзаказ во вкладке «Товары и остатки».
      Пустой e-mail = <?= h(SW_PREORDER_DEFAULTS['preorder_email']) ?>.</p>
    <div style="margin-top:12px"><button type="submit">Сохранить настройки</button></div>
  </form>

  <div class="card">
    <h2>Заявки (<?= count($requests) ?>)</h2>
    <table>
      <thead><tr><th>Дата</th><th>Товар</th><th>Ждёт к</th><th>Имя</th><th>Связь</th><th>Адрес</th><th>Комментарий</th></tr></thead>
      <tbody>
      <?php foreach ($requests as $r): ?>
        <tr>
          <td class="muted"><?= h(substr((string)$r['created_at'], 0, 16)) ?></td>
          <td><?= h($r['product_name']) ?><?= $r['color'] ? '<br><span class="muted">' . h($r['color']) . '</span>' : '' ?></td>
          <td><?= h($r['ready_date'] ? sw_date_label_ru((string)$r['ready_date']) : '—') ?></td>
          <td><?= h($r['name']) ?></td>
          <td><span class="pill"><?= h(SW_PREORDER_METHODS[$r['contact_method']] ?? $r['contact_method']) ?></span><br><?= h($r['contact']) ?></td>
          <td><?= h($r['address']) ?></td>
          <td><?= nl2br(h($r['comment'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$requests): ?><tr><td colspan="7" class="muted">Пока нет заявок.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

</div>
</body>
</html>
