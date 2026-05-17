<?php
require_once __DIR__ . '/api/lib/config.php';

$env = [];
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        if ($key !== '') $env[$key] = $val;
    }
}

$adminPass = $env['ADMIN_PASS'] ?? 'adminpass';
$authenticated = false;
$message = '';
$products = [];

if (is_file(__DIR__ . '/data/products.json')) {
    $data = json_decode(file_get_contents(__DIR__ . '/data/products.json'), true);
    $products = $data['products'] ?? [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === $adminPass) {
        $authenticated = true;
        $_SESSION = ['authenticated' => true];
        setcookie('admin_session', md5($adminPass . date('Y-m-d')), time() + 86400 * 30, '/');
    } else {
        $message = '<p style="color: #d32f2f;">Неверный пароль</p>';
    }
}

if (isset($_COOKIE['admin_session']) && $_COOKIE['admin_session'] === md5($adminPass . date('Y-m-d'))) {
    $authenticated = true;
}

if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update') {
        $productId = $_POST['product_id'] ?? '';
        $newPrice = (int)($_POST['price'] ?? 0);
        $available = isset($_POST['available']) ? true : false;

        foreach ($products as &$product) {
            if ($product['id'] === $productId) {
                if ($newPrice > 0) {
                    $product['price'] = $newPrice;
                }
                $product['available'] = $available;
                break;
            }
        }

        $dataFile = __DIR__ . '/data/products.json';
        file_put_contents($dataFile, json_encode(['products' => $products], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $message = '<p style="color: #388e3c;">Товар обновлён</p>';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Настройки — Skywood</title>
<style>
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
    max-width: 1000px;
    margin: 0 auto;
    padding: 20px;
    background: #f5f5f5;
    color: #333;
  }
  h1 { margin-top: 0; }
  .login-form {
    background: white;
    padding: 30px;
    border-radius: 8px;
    max-width: 400px;
    margin: 0 auto;
  }
  .login-form input {
    width: 100%;
    padding: 10px;
    margin: 10px 0;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 16px;
  }
  .login-form button {
    width: 100%;
    padding: 10px;
    background: #0e1712;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 16px;
    margin-top: 10px;
  }
  .login-form button:hover { background: #1a2d1f; }
  .message { text-align: center; margin: 20px 0; }
  .products-table {
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 20px;
  }
  table {
    width: 100%;
    border-collapse: collapse;
  }
  th, td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #eee;
  }
  th {
    background: #0e1712;
    color: white;
    font-weight: 600;
  }
  tr:hover { background: #f9f9f9; }
  .product-form {
    display: grid;
    gap: 10px;
    align-items: center;
  }
  .product-form input {
    padding: 6px;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 14px;
  }
  .product-form button {
    padding: 6px 12px;
    background: #0e1712;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
  }
  .product-form button:hover { background: #1a2d1f; }
  .checkbox-wrapper {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .checkbox-wrapper input[type="checkbox"] {
    cursor: pointer;
    width: auto;
  }
</style>
</head>
<body>

<h1>Skywood — Управление товарами</h1>

<?php if (!$authenticated): ?>
  <div class="login-form">
    <h2>Вход</h2>
    <?php echo $message; ?>
    <form method="POST">
      <input type="password" name="password" placeholder="Пароль администратора" required autofocus>
      <button type="submit">Войти</button>
    </form>
  </div>
<?php else: ?>
  <p><a href="?logout=1" style="color: #0e1712;">Выход</a></p>

  <?php if ($message) echo '<div class="message">' . $message . '</div>'; ?>

  <div class="products-table">
    <table>
      <thead>
        <tr>
          <th>Товар</th>
          <th>Цена (₽)</th>
          <th>Доступен</th>
          <th>Действие</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($products as $product): ?>
          <tr>
            <td><?= htmlspecialchars($product['name']) ?></td>
            <td>
              <form method="POST" class="product-form" style="grid-template-columns: 100px auto auto;">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="product_id" value="<?= htmlspecialchars($product['id']) ?>">
                <input type="number" name="price" value="<?= $product['price'] ?>" min="0" required>
                <div class="checkbox-wrapper">
                  <input type="checkbox" name="available" id="avail_<?= htmlspecialchars($product['id']) ?>" <?= $product['available'] ? 'checked' : '' ?>>
                  <label for="avail_<?= htmlspecialchars($product['id']) ?>" style="margin: 0;">В наличии</label>
                </div>
                <button type="submit">Сохранить</button>
              </form>
            </td>
            <td><?= $product['available'] ? '✓' : '✗' ?></td>
            <td></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php endif; ?>

</body>
</html>
