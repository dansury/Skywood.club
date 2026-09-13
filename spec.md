# spec.md — navigation index

Skywood.club — продающий лендинг-магазин подвесных палаток. Статический
HTML-фронтенд + PHP-бэкенд. Работает на обычном PHP-хостинге, файлы лежат в
корне сайта.

## File → spec mapping

| Area | Files | Spec |
|---|---|---|
| Фронтенд (лендинг, корзина, чекаут) | `index.html`, `order.html`, `privacy.html`, `css/*`, `js/*` | `spec/frontend.md` |
| Бэкенд (PHP API) | `api/index.php`, `api/lib/*.php` | `spec/backend.md` |
| Платежи и доставка | `api/lib/tinkoff.php`, `api/lib/cdek.php` | `spec/integrations.md` |
| Админка (товары/остатки/скидки, заказы, клиенты, лиды, предзаказы, обновление кода) | `admin.php`, `api/lib/db.php`, `api/lib/mail.php`, `api/lib/preorder.php` | `spec/admin.md` |
| Деплой и SEO | `pull.php`, `pull-config.php`, `api/lib/autopull.php`, `.htaccess`, `robots.txt`, `sitemap.xml` | `spec/deploy.md` |

## Catalog

Текст и фото товаров — `data/products.json`. Изменяемые данные (остатки по
вариантам, переопределения цен, скидки, лиды) — SQLite-БД `data/skywood.sqlite`
(`api/lib/db.php`). Заказы — `data/orders.json`. Все `data/*` создаются в рантайме
и не хранятся в git.

## Rules

- Все спецификации и changelog — на английском (см. CLAUDE.md).
- Новый функционал — отдельные файлы; `api/index.php` не раздувать.
- Спеки описывают актуальную функциональность, не историю.

## File list

- `index.html` — лендинг; `order.html` — статус заказа; `privacy.html` —
  политика конфиденциальности (152-ФЗ).
- `css/styles.css`, `js/store.js` (каталог/корзина/чекаут),
  `js/main.js` (параллакс/анимации), `js/cookies.js` (cookie-баннер).
- `api/index.php` — PHP-роутер API; `api/lib/*.php` — модули
  (`db.php` — SQLite, `mail.php` — письма, `preorder.php` — предзаказ
  на следующий сезон).
- `admin.php` — админка (`/admin`): остатки, цены, скидки, заказы, клиенты,
  лиды, заявки «Узнать о поступлении».
- `emails.md` — шаблоны писем; `SEOrecommend.md` — SEO-аудит и рекомендации.
- `robots.txt`, `sitemap.xml` — для поисковиков.
- `settings.php` — устаревшая мини-панель цен/наличия (правит products.json).
- `../.env` — доступы (на уровень выше корня сайта, вне `public_html`);
  `.env.example` — шаблон без секретов; `data/products.json` — каталог;
  `data/skywood.sqlite` — БД.
- `pull.php`, `pull-config.php` — обновление сайта на хостинге из GitHub;
  `api/lib/autopull.php` — тихая проверка «есть ли новый коммит» на каждом
  PHP-запросе (галочка в админке).
- `1/*.mht` — архив старого сайта.
