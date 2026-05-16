# spec/backend.md

PHP backend in `api/`. Runs on shared PHP hosting (PHP 7.x+). Reads `.env` and
`data/products.json`; stores orders in `data/orders.json`.

## API contract

Base path `/api`. All responses JSON unless noted.

### GET /api/products
→ `{ products, company, demo }`.

### GET /api/cdek/cities?q=
≥2 chars → `[{code,city,region,fias,postalCode}]`. Demo fallback: static cities.

### GET /api/cdek/points?city_code=
→ `[{code,name,address,workTime,lat,lon}]`. Demo fallback: 2 mock points.

### POST /api/cdek/calculate
`{deliveryType,toCityCode,items[{id,qty}]}` → `{cost,periodMin,periodMax,tariffCode}`.
Demo fallback: weight-based cost.

### POST /api/orders
`{items[{id,qty,color}], customer{name,phone,email,address,comment},
delivery{type,cityCode,cityName,pvzCode,pvzName,cost}, paymentMethod}`.
- Validates; computes `subtotal`, `total=subtotal+deliveryCost`.
- Creates order; registers CDEK order if enabled.
- `cod` → `status:'confirmed'`, returns `{redirect}`.
- `online` → Tinkoff `Init`, returns `{paymentUrl}`; demo → `{redirect}`.
- `redirect` is `order.html?order=<id>&status=success[&demo=1]` (relative,
  works in any subfolder).

### GET /api/orders/{id}
→ `{id,status,total,paymentMethod,payment}`.

### POST /api/payment/notify
Tinkoff webhook; verifies token; updates order `status/payment`; responds `OK`
as `text/plain`.

## Files — api/

- `index.php` — front controller. Parses the route from `REQUEST_URI` after
  `/api/` (fallback `?route=`); dispatches; demo fallbacks for CDEK; `try/catch`
  → JSON `{error}` 500. Helpers `sw_json`, `sw_text`, `sw_request_body`,
  `sw_validate_order`, `sw_resolve_items`.
- `lib/config.php` — `sw_load_env()` parses `.env`; `sw_config()` builds the
  config array (`baseUrl`, `tinkoff{}`, `cdek{}`, `company{}`, `demo`);
  `sw_detect_base_url()` derives the public URL from the request.
- `lib/http.php` — `sw_http_request/sw_http_json` — cURL wrapper.
- `lib/catalog.php` — `sw_catalog_all()`, `sw_catalog_get($id)`.
- `lib/store.php` — order store in `data/orders.json`:
  `sw_order_create/get/update`, `sw_order_next_id` (`SW<YYYYMMDD>-<NNN>`).
- `lib/tinkoff.php`, `lib/cdek.php` — see `spec/integrations.md`.

Routing depends on `.htaccess` mod_rewrite (`api/.+` → `api/index.php`);
`index.php` also accepts `?route=<route>` as a fallback.

## Product shape

`id, name, category, price, oldPrice, available, badge, tagline, short,
options.color[], specs{}, weightGrams, packLength/Width/Height, images[],
features[{title,text}]`.
