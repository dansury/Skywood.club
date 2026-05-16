<?php
// Skywood.club — installer / environment check.
// Open this file in a browser once after deploying the site to the hosting:
//   https://<your-host>/install.php
// It verifies PHP, prepares the data folder and reports the configuration.
// Safe to re-run. Delete it once the site works.
//
// This file is intentionally PHP 5.6+ compatible so it still loads on a host
// with an outdated PHP and can report that the version must be raised.

$checks = [];
$ok = true;

function sw_check($label, $pass, $detail, $fatal = true)
{
    global $checks, $ok;
    $checks[] = ['label' => $label, 'pass' => $pass, 'detail' => $detail, 'fatal' => $fatal];
    if (!$pass && $fatal) {
        $ok = false;
    }
}

// --- PHP runtime ---
$phpOk = version_compare(PHP_VERSION, '7.2.0', '>=');
sw_check('PHP версия', $phpOk, PHP_VERSION . ($phpOk ? ' — подходит' : ' — нужна 7.2 или новее (выберите в панели хостинга)'));

sw_check('Расширение cURL', extension_loaded('curl'), extension_loaded('curl') ? 'есть' : 'нет — без него не работают платежи и СДЭК');
sw_check('Расширение JSON', extension_loaded('json'), extension_loaded('json') ? 'есть' : 'нет');
sw_check('Расширение mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'есть' : 'нет — нужно для корректной работы с кириллицей');
sw_check('Расширение ZipArchive', class_exists('ZipArchive'), class_exists('ZipArchive') ? 'есть' : 'нет — нужно только для pull.php (обновление сайта)', false);
sw_check('mod_rewrite (.htaccess)', in_array('mod_rewrite', function_exists('apache_get_modules') ? apache_get_modules() : ['mod_rewrite'], true), 'если API возвращает 404 — включите mod_rewrite в панели хостинга', false);

// --- data folder ---
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0775, true);
}
$writable = is_dir($dataDir) && is_writable($dataDir);
sw_check('Папка data/ доступна для записи', $writable, $writable ? $dataDir : 'нет прав на запись — выставьте права 775 на папку data/');

// --- .env / configuration ---
$envFile = __DIR__ . '/.env';
$envExists = is_file($envFile);
sw_check('Файл .env', $envExists, $envExists ? 'найден' : 'не найден рядом с install.php — загрузите .env с доступами', false);

// The API code requires PHP 7.x — only load it when the version allows,
// otherwise this installer itself would fail to parse it.
$config = null;
if ($envExists && version_compare(PHP_VERSION, '7.1.0', '>=') && is_file(__DIR__ . '/api/lib/config.php')) {
    require_once __DIR__ . '/api/lib/config.php';
    $config = sw_config();
    sw_check('Доступы Т-Банк', $config['tinkoff']['enabled'], $config['tinkoff']['enabled'] ? 'заданы' : 'не заданы — онлайн-оплата работает в демо-режиме', false);
    sw_check('Доступы СДЭК', $config['cdek']['enabled'], $config['cdek']['enabled'] ? 'заданы' : 'не заданы — расчёт доставки работает в демо-режиме', false);
}

if ($ok && $writable) {
    @file_put_contents($dataDir . '/.installed', date('c'));
}

$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Skywood.club — установка</title>
<style>
  :root{--bg:#1f1f1f;--card:#2b2b2b;--fg:#e6e6e6;--mute:#9aa0a6;--green:#7CFFB2;--red:#ff6b6b;--line:#444}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--fg);
    font-family:'JetBrains Mono',Menlo,Consolas,monospace;font-size:14px;line-height:1.6}
  .wrap{max-width:720px;margin:2.5rem auto;padding:0 1rem}
  .card{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:24px 26px;margin-bottom:18px}
  h1{font-size:1.3rem;margin:0 0 4px}
  .sub{color:var(--mute);margin:0 0 18px}
  .row{display:flex;gap:10px;padding:9px 0;border-bottom:1px solid #353535}
  .row:last-child{border-bottom:0}
  .mark{flex:0 0 22px;font-weight:700}
  .pass{color:var(--green)} .fail{color:var(--red)}
  .label{flex:0 0 220px}
  .detail{color:var(--mute);flex:1}
  .verdict{font-size:1.05rem;font-weight:700;padding:14px 18px;border-radius:6px}
  .verdict.good{background:rgba(124,255,178,.12);color:var(--green)}
  .verdict.bad{background:rgba(255,107,107,.12);color:var(--red)}
  code{background:#1a1a1a;padding:2px 6px;border-radius:3px}
  ul{margin:8px 0 0;padding-left:20px;color:var(--mute)}
  a{color:var(--green)}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Skywood.club — установка</h1>
    <p class="sub">Проверка хостинга и подготовка сайта.</p>
    <?php foreach ($checks as $c): ?>
      <div class="row">
        <span class="mark <?= $c['pass'] ? 'pass' : 'fail' ?>"><?= $c['pass'] ? '✓' : '✕' ?></span>
        <span class="label"><?= $h($c['label']) ?></span>
        <span class="detail"><?= $h($c['detail']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <?php if ($ok): ?>
      <div class="verdict good">✓ Сайт готов к работе.</div>
      <ul>
        <li>Откройте главную: <a href="index.html">index.html</a></li>
        <li>Каталог редактируется в <code>data/products.json</code>.</li>
        <li>Доступы Т-Банк и СДЭК — в файле <code>.env</code>.</li>
        <li>Обновление сайта с GitHub — через <code>pull.php</code>.</li>
        <li>Для безопасности удалите <code>install.php</code> после проверки.</li>
      </ul>
      <?php if ($config && $config['demo']): ?>
        <p class="sub">Сейчас активен демо-режим (часть доступов не задана): оплата и/или расчёт доставки заменены заглушками.</p>
      <?php endif; ?>
    <?php else: ?>
      <div class="verdict bad">✕ Не всё готово — исправьте отмеченные ✕ пункты выше и обновите страницу.</div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
