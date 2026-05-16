# spec.md — navigation index

Skywood.club — продающий лендинг-магазин подвесных палаток. Статический
HTML-фронтенд + PHP-бэкенд. Работает на обычном PHP-хостинге, файлы лежат в
корне сайта.

## File → spec mapping

| Area | Files | Spec |
|---|---|---|
| Фронтенд (лендинг, корзина, чекаут) | `index.html`, `order.html`, `css/*`, `js/*` | `spec/frontend.md` |
| Бэкенд (PHP API) | `api/index.php`, `api/lib/*.php` | `spec/backend.md` |
| Платежи и доставка | `api/lib/tinkoff.php`, `api/lib/cdek.php` | `spec/integrations.md` |
| Деплой на хостинг | `pull.php`, `pull-config.php`, `install.php`, `.htaccess` | `spec/deploy.md` |

## Catalog

Товары — `data/products.json`. Заказы — `data/orders.json` (создаётся при
первом заказе, в git не хранится).

## Rules

- Все спецификации и changelog — на английском (см. CLAUDE.md).
- Новый функционал — отдельные файлы; `api/index.php` не раздувать.
- Спеки описывают актуальную функциональность, не историю.

## File list

- `index.html` — лендинг; `order.html` — статус заказа.
- `css/styles.css`, `js/store.js` (каталог/корзина/чекаут),
  `js/main.js` (параллакс/анимации).
- `api/index.php` — PHP-роутер API; `api/lib/*.php` — модули.
- `install.php` — установщик/проверка хостинга.
- `.env` — доступы; `data/products.json` — каталог.
- `pull.php`, `pull-config.php` — обновление сайта на хостинге из GitHub.
- `1/*.mht` — архив старого сайта.
