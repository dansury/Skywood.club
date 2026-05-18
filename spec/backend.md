# spec/backend.md

PHP backend in `api/`. Runs on shared PHP hosting (PHP 7.x+). Reads `.env` and
`data/products.json`; stores orders in `data/orders.json`.

## API contract

Base path `/api`. All responses JSON unless noted.

### GET /api/products
→ `{ products, company, demo }`.

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
