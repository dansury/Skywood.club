# spec.md — navigation index

Skywood.club — продающий лендинг-магазин подвесных палаток. Node.js/Express + статический фронтенд.

## File → spec mapping

| Area | Files | Spec |
|---|---|---|
| Фронтенд (лендинг, корзина, чекаут) | `public/**` | `spec/frontend.md` |
| Бэкенд (сервер, маршруты, хранилище) | `src/server.js`, `src/routes/*`, `src/store.js`, `src/catalog.js`, `src/config.js` | `spec/backend.md` |
| Платежи и доставка | `src/services/tinkoff.js`, `src/services/cdek.js` | `spec/integrations.md` |

## Catalog

Товары — `data/products.json`. Заказы — `data/orders.json` (создаётся при первом заказе, в git не хранится).

## Rules

- Все спецификации и changelog — на английском (см. CLAUDE.md).
- Новый функционал — отдельные файлы; `src/server.js` не раздувать.
- Спеки описывают актуальную функциональность, не историю.

## File list

- `public/index.html` — лендинг; `public/order.html` — статус заказа.
- `public/css/styles.css`, `public/js/store.js` (каталог/корзина/чекаут), `public/js/main.js` (параллакс/анимации).
- `src/server.js` — Express app; `src/routes/{cdek,orders,payment}.js`.
- `src/services/{tinkoff,cdek}.js` — API-клиенты.
- `data/products.json` — каталог.
- `1/*.mht` — архив старого сайта.
