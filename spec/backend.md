# spec/backend.md

PHP backend in `api/`. Runs on shared PHP hosting (PHP 7.x+). Reads `.env` and
`data/products.json`; stores orders in `data/orders.json`; overlays mutable shop
data (stock, price/discount overrides, leads) from the SQLite DB
`data/skywood.sqlite` (`lib/db.php`).

## API contract

Base path `/api`. All responses JSON unless noted.

### GET /api/products
→ `{ products, company, demo }`. Each product carries the DB overlay (see
*Product shape*): effective `price`, `oldPrice`, `available`, `discount`,
`stockTotal`, `stockByColor`, `preorder`, `preorderOffer`.

### GET /api/cdek/cities?q=
≥2 chars → `[{code,city,region,fias,postalCode}]`. Demo fallback: static cities.
Results cached per query (`data/.cdek-cities.json`); `?debug=1` refreshes them.

### GET /api/cdek/diag
CDEK self-check → `{time,php,cdek{enabled,api,officialApi,account,...},
oauth{ok,error?},cities{ok,count,sample?},result}`. `result` states whether a
failure is in the key or in the code. 502 on OAuth/cities failure.

### GET /api/cdek/points?city_code=
→ `[{code,name,address,workTime,lat,lon}]`. Demo fallback: 2 mock points.

### POST /api/cdek/calculate
`{deliveryType,toCityCode,items[{id,qty}]}` → `{cost,periodMin,periodMax,tariffCode}`.
Demo fallback: weight-based cost.

### POST /api/orders
`{items[{id,qty,color}], customer{name,phone,email,address,comment},
delivery{type,cityCode,cityName,pvzCode,pvzName,cost}, paymentMethod, consent}`.
- Validates; computes `subtotal`, `total=subtotal+deliveryCost`.
- `consent` (personal-data consent, 152-ФЗ) is **required** — missing → error.
- Per line, when stock is tracked and short → `preorder:true` (never blocked).
  Order-level `preorder` = any line preorder.
- Creates order; registers CDEK order if enabled.
- Sends e-mail (`lib/mail.php`): customer confirmation (or preorder variant) +
  owner notification. Best-effort — mail failure never fails the order.
- `cod` → `status:'confirmed'`, decrements stock, returns `{redirect}`.
- `online` → Tinkoff `Init`, returns `{paymentUrl}`; demo → `{redirect}`.
- Stock decrement: COD/demo immediately; online on the paid webhook. Guarded by
  the order's `stockApplied` flag (idempotent). Untracked variants unaffected.
- `redirect` is `order.html?order=<id>&status=success[&demo=1]` (relative,
  works in any subfolder). Responses also carry `preorder`.

### POST /api/preorder
Waitlist request for an out-of-stock product («Узнать о поступлении»). Collects
a contact, does not create an order and never touches payment or delivery.

`{productId, color?, name, contact, address?, comment?, consent}`
- `productId` must resolve to a product whose `preorderOffer` is not null,
  else → error (the product is in stock, or the offer is off for it/shop-wide).
- `name` ≥ 2 chars.
- `contact` — one free-form field (phone, Telegram nick or e-mail), ≥ 3 chars.
  The channel is **derived**, not asked: `sw_preorder_detect_method()` returns
  `email` (valid e-mail syntax), `telegram` (`@nick` or a `t.me/` link),
  `phone` (`^\+?[0-9\s\-()]{10,18}$`) or `other` (stored as written). The only
  rejected shape is an `other` value containing `@` — a mistyped e-mail.
- `address`, `comment` — optional free text (trimmed, capped at 300/1000).
- `consent` (152-ФЗ) is **required** — missing → error.
- Stores the request in the DB (`preorders`), stamped with the product's
  expected arrival date; notifies the owner and, when the contact is an e-mail,
  confirms to the customer (`sw_mail_preorder()`). Mail is best-effort.
→ `{ok:true, id, readyDate, readyLabel}`; `{errors:[…]}` 400 on validation.

### POST /api/lead
`{name?,phone?,email?,message?}` → stores a lead in the DB and notifies the
owner. Needs at least one of phone/email. → `{ok,id}`.

### POST /api/replain
Re:plain webhook. Stores chat contacts/messages as `source:'replain'` leads
(raw event kept in `raw`). Responds `OK` (text/plain). Configure the webhook URL
in Re:plain to `<baseUrl>/api/replain`.

### GET /api/orders/{id}
→ `{id,status,total,paymentMethod,payment}`.

### POST /api/payment/notify
Tinkoff webhook; verifies token; updates order `status/payment`; on
CONFIRMED/AUTHORIZED decrements stock once (`stockApplied`); responds `OK`
as `text/plain`.

## Debug mode

`?debug=1` (or header `X-Sw-Debug: 1`) on any `/api/*` request enables it.
`api/lib/debug.php`: `sw_debug_enabled()`, `sw_debug_add(tag,data)` (append to a
per-request trace), `sw_debug_dump()`, `sw_debug_mask()` (hides secrets).
`lib/http.php` traces every cURL call; `cdek.php` traces OAuth. When enabled:
`sw_debug_install_handlers()` routes PHP warnings/notices/fatals into the trace
(never printed — that would corrupt the JSON); `sw_json` emits the trace in
response header `X-Sw-Debug` (ASCII JSON) and, for object responses, as a
`_debug` key; the top-level `catch` records the exception.

## Files — api/

- `index.php` — front controller. Parses the route from `REQUEST_URI` after
  `/api/` (fallback `?route=`); dispatches; demo fallbacks for CDEK; `try/catch`
  → JSON `{error}` 500. Helpers `sw_json`, `sw_text`, `sw_request_body`,
  `sw_validate_order`, `sw_resolve_items`.
- `lib/debug.php` — debug-mode helpers (see *Debug mode*).
- `lib/config.php` — `sw_load_env()` parses `.env`; `sw_config()` builds the
  config array (`baseUrl`, `tinkoff{}`, `cdek{}`, `company{}`, `demo`);
  `sw_detect_base_url()` derives the public URL from the request.
- `lib/http.php` — `sw_http_request/sw_http_json` — cURL wrapper.
- `lib/catalog.php` — `sw_catalog_raw()` (products.json as-is),
  `sw_catalog_all()`/`sw_catalog_get($id)` (with DB overlay),
  `sw_catalog_stock($id,$color)`, `sw_inventory_apply_order($order)` (decrement).
- `lib/db.php` — SQLite (PDO) store; never throws on the public path (returns
  empty/neutral when `pdo_sqlite` missing). Tables `products_ext` (price,
  old_price, available, discount_percent/starts/ends, preorder_mode,
  preorder_date), `stock` (product_id,color,qty), `leads`, `settings`
  (name,value), `preorders`. Helpers `sw_db()`, `sw_db_available()`,
  `sw_db_products_ext()`, `sw_db_stock_*()`, `sw_db_product_ext_save()`,
  `sw_discount_active()`, `sw_lead_create()`, `sw_leads_all()`,
  `sw_settings()`, `sw_setting()`, `sw_setting_set()`, `sw_preorder_create()`,
  `sw_preorders_all()`. `sw_db_migrate()` also back-fills columns added later
  via `sw_db_add_column()`, so existing databases upgrade in place.
- `lib/preorder.php` — next-season preorder offer and requests.
  `SW_PREORDER_METHODS` (contact-channel labels), `sw_preorder_detect_method()`,
  `SW_PREORDER_DEFAULTS`
  (`preorder_enabled=1`, `preorder_date=2027-03-01`,
  `preorder_email=Dansury@gmail.com`), `sw_preorder_settings()`,
  `sw_preorder_offer($ext)` → `{date,label}|null`, `sw_date_label_ru()`,
  `sw_preorder_submit($body)`.
- `lib/mail.php` — `sw_mail_order($order,$preorder)` (customer + owner),
  `sw_mail_preorder($request)` (owner at `preorder_email` + customer when the
  contact is an e-mail), `sw_mail_send()`; templates mirror `/emails.md`,
  signed by Яна. Uses PHP `mail()`; reads `MAIL_FROM`, `MAIL_FROM_NAME`,
  `ADMIN_EMAIL`, `MAIL_ENABLED` from `.env`.
- `lib/store.php` — order store in `data/orders.json`:
  `sw_order_create/get/update`, `sw_order_next_id` (`SW<YYYYMMDD>-<NNN>`).
- `lib/tinkoff.php`, `lib/cdek.php` — see `spec/integrations.md`.

Routing depends on `.htaccess` mod_rewrite (`api/.+` → `api/index.php`);
`index.php` also accepts `?route=<route>` as a fallback.

## Product shape

From `products.json`: `id, name, category, price, oldPrice, available, badge,
tagline, short, options.color[], specs{}, weightGrams, packLength/Width/Height,
images[], video?, features[{title,text}]`.

Added by the DB overlay in `sw_catalog_all()`:
- `price` — effective (override + active discount); `oldPrice` — struck-through
  (override, or base price when discounted).
- `available` — admin flag (override of products.json).
- `discount` — `{percent,endsAt}` when an active discount applies, else `null`.
- `stockTotal` — units across colours, or `null` when stock is untracked.
- `stockByColor` — `{colour:units}` when tracked, else `null`.
- `preorder` — `true` when stock is tracked and totals zero.
- `preorderOffer` — `{date:'YYYY-MM-DD', label:'1 марта 2027'}` while the
  product is out of stock (`preorder` is true), else `null`. The offer needs no
  per-product switch: it follows the stock. Only the date is configurable,
  resolved from `products_ext.preorder_mode`: `off` → null (never offered);
  `custom` → `preorder_date` of the product; anything else → the shop-wide
  `preorder_date` setting. Always null when the shop-wide `preorder_enabled`
  setting is off or the date is empty.
