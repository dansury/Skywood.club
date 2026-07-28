# spec/admin.md

Admin panel and the data it manages. URL: `/admin` (rewrite to `admin.php`).

## admin.php

Session login. Password from the `.env` (`sw_env_path()`) key `ADMIN_PASS`
(fallback to legacy lowercase `adminpass`, then `"adminpass"`); optional
`ADMIN_LOGIN` adds a username field.
CSRF token per session; `noindex`. Mutations require auth + valid CSRF and a
working DB (`sw_db_available()`), else a flash warning.

Tabs:
- **Товары и остатки** — per product: price override (placeholder = base price
  from products.json), old price, availability checkbox, discount
  (percent + `discount_starts`/`discount_ends` as `datetime-local`), a
  stock input per colour (`stock[<colour>]`; empty = untracked, 0 = preorder),
  and the arrival date advertised while stock is zero («Узнать о поступлении»):
  `preorder_mode` (`default` / `custom` / `off`) + `preorder_date` for `custom`.
  Saved via `sw_db_product_ext_save()` + `sw_db_stock_set()`.
- **Заказы** — table from `data/orders.json` (newest first): id, date, customer,
  items, delivery, payment, total, status; preorder badge.
- **Клиенты** — aggregated from orders (by email/phone) + leads: contacts,
  order count, total spent, last activity, source.
- **Лиды / Re:plain** — `sw_leads_all()`: contact-form captures and Re:plain
  webhook events. Shows the Re:plain webhook URL to configure (`<baseUrl>/api/replain`).
- **Узнать о поступлении** — shop-wide waitlist settings (`action=save_preorder`,
  stored in `settings`); the offer itself follows the stock and needs no
  per-product enabling: master switch `preorder_enabled`, default arrival date
  `preorder_date` (ships as `2027-03-01`) and the notification recipient
  `preorder_email` (ships as `Dansury@gmail.com`). Below them the table of
  collected requests (`sw_preorders_all()`): date, product + colour, expected
  arrival, name, contact + the channel derived from it, address, comment.

Requests also feed the **Клиенты** tab (source `поступление`).

`settings.php` is a legacy minimal price/availability editor that writes
`products.json` directly; `admin.php` is the primary panel and edits the DB
overlay instead. It shares the same env loading (`sw_load_env(sw_env_path())`)
and the same password fallback chain.

## Data store

SQLite `data/skywood.sqlite` (`api/lib/db.php`). Created on first use, not in
git (survives `pull.php`). Degrades gracefully when `pdo_sqlite` is absent — the
storefront still runs off `products.json` (no stock tracking, no overrides).

## E-mail

`api/lib/mail.php` sends order/preorder confirmations to the customer (signed by
Яна, phone +7 977 508-45-85) and a notification to `ADMIN_EMAIL`. Templates are
documented in `/emails.md`. Sending is best-effort via PHP `mail()`.
«Узнать о поступлении» requests go to the `preorder_email` setting instead of
`ADMIN_EMAIL`, so the owner can redirect them from the admin panel.

## .env keys (optional)

`ADMIN_PASS`, `ADMIN_LOGIN`, `ADMIN_EMAIL`, `MAIL_FROM`, `MAIL_FROM_NAME`,
`MAIL_ENABLED`. All have safe defaults (company email / legacy `adminpass`).
The file itself lives one level above the web root — see `spec/deploy.md`.
