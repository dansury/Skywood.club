# spec.md — navigation index

Skywood.club — продающий лендинг-магазин подвесных палаток. Гибрид: статический
фронтенд + бэкенд в двух реализациях — PHP (боевой хостинг) и Node.js/Express
(локальная разработка).

## File → spec mapping

| Area | Files | Spec |
|---|---|---|
| Фронтенд (лендинг, корзина, чекаут) | `public/index.html`, `public/order.html`, `public/css/*`, `public/js/*` | `spec/frontend.md` |
| Бэкенд — контракт API + обе реализации | `public/api/**` (PHP), `src/**` (Node) | `spec/backend.md` |
| Платежи и доставка | `public/api/lib/{tinkoff,cdek}.php`, `src/services/{tinkoff,cdek}.js` | `spec/integrations.md` |
| Деплой на хостинг | `pull.php`, `pull-config.php`, `public/install.php`, `public/.htaccess` | `spec/deploy.md` |

## Catalog

Товары — `public/data/products.json`. Заказы — `public/data/orders.json`
(создаётся при первом заказе, в git не хранится).

## Rules

- Все спецификации и changelog — на английском (см. CLAUDE.md).
- Новый функционал — отдельные файлы; `src/server.js` / `public/api/index.php`
  не раздувать.
- Спеки описывают актуальную функциональность, не историю.
- Контракт API единый для PHP и Node — менять обе реализации синхронно.

## File list

- `public/` — единственная разворачиваемая на хостинг папка (см. `spec/deploy.md`).
- `public/index.html` — лендинг; `public/order.html` — статус заказа.
- `public/css/styles.css`, `public/js/store.js` (каталог/корзина/чекаут),
  `public/js/main.js` (параллакс/анимации).
- `public/api/index.php` — PHP-роутер API; `public/api/lib/*.php` — модули.
- `public/install.php` — установщик/проверка хостинга.
- `public/.env` — доступы; `public/data/products.json` — каталог.
- `src/server.js` — Express app; `src/routes/*`, `src/services/*` — Node-бэкенд.
- `pull.php`, `pull-config.php` — обновление сайта на хостинге из GitHub.
- `1/*.mht` — архив старого сайта.
