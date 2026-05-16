# spec/backend.md

The backend has one HTTP API contract and two interchangeable implementations:

- **PHP** — `public/api/**`. Production; runs on shared PHP hosting.
- **Node.js/Express** — `src/**`. Local development (`npm start`).

Both read the same `.env` (`public/.env`) and `public/data/products.json`,
expose the same routes and JSON shapes. Change them together.

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

## PHP implementation — public/api/

- `index.php` — front controller. Parses the route from `REQUEST_URI` after
  `/api/` (fallback `?route=`); dispatches; demo fallbacks for CDEK; `try/catch`
  → JSON `{error}` 500. Helpers `sw_json`, `sw_text`, `sw_request_body`,
  `sw_validate_order`, `sw_resolve_items`.
- `lib/config.php` — `sw_load_env()` parses `public/.env`; `sw_config()` builds
  the config array (`baseUrl`, `tinkoff{}`, `cdek{}`, `company{}`, `demo`);
  `sw_detect_base_url()` derives the public URL from the request.
- `lib/http.php` — `sw_http_request/sw_http_json` — cURL wrapper.
- `lib/catalog.php` — `sw_catalog_all()`, `sw_catalog_get($id)`.
- `lib/store.php` — order store in `public/data/orders.json`:
  `sw_order_create/get/update`, `sw_order_next_id` (`SW<YYYYMMDD>-<NNN>`).
- `lib/tinkoff.php`, `lib/cdek.php` — see `spec/integrations.md`.

Routing depends on `public/.htaccess` mod_rewrite (`api/.+` → `api/index.php`).

## Node implementation — src/

Node.js 18+ / Express, ESM. Entry `src/server.js`.
- `config.js` — env-driven config (`dotenv` reads `public/.env`); `demoMode`.
- `catalog.js` — loads `public/data/products.json`.
- `store.js` — order store `public/data/orders.json`.
- `routes/{cdek,orders,payment}.js` — same routes as the contract above.
- `server.js` — `express.json` → API routes → `express.static(public)` →
  `/order/{success,fail}` → SPA 404 fallback → error handler.

## Product shape

`id, name, category, price, oldPrice, available, badge, tagline, short,
options.color[], specs{}, weightGrams, packLength/Width/Height, images[],
features[{title,text}]`.
