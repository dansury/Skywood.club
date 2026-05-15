# spec/backend.md

Node.js 18+ / Express, ESM. Entry: `src/server.js`.

## config.js

`config` from env (`.env` via dotenv):
- `port`, `baseUrl`
- `tinkoff{ terminalKey, password, api, taxation, vat, enabled }`
- `cdek{ account, securePassword, api, senderCityCode, senderPostalCode, shipmentPoint, enabled }`
- `company{ legalName, inn, address, phone, ... }`
- `demoMode` — true if any integration not configured.

## catalog.js

Loads `data/products.json`. `getProducts()`, `getProduct(id)`.

Product: `id, name, category, price, oldPrice, available, badge, tagline, short, options.color[], specs{}, weightGrams, packLength/Width/Height, images[], features[{title,text}]`.

## store.js

JSON-file order store `data/orders.json`.
- `createOrder(data)->order` — assigns `id` (`SW<YYYYMMDD>-<NNN>`), `createdAt`, `status:'new'`.
- `getOrder(id)`, `updateOrder(id, patch)` — Object.assign + persist.

## Routes

### /api/products  (server.js)
GET → `{ products, company, demo }`.

### /api/cdek  (routes/cdek.js)
- `GET /cities?q=` → `[{code,city,region,postalCode}]` (≥2 chars).
- `GET /points?city_code=` → `[{code,name,address,workTime,lat,lon}]`.
- `POST /calculate` `{deliveryType,toCityCode,items[{id,qty}]}` → `{cost,periodMin,periodMax,tariffCode}`.
- Demo fallback when `!cdek.enabled`: static cities, mock points, weight-based cost.

### /api/orders  (routes/orders.js)
- `POST /` `{items[{id,qty,color}], customer{name,phone,email,address,comment}, delivery{type,cityCode,cityName,pvzCode,pvzName,cost}, paymentMethod}`.
  - Validates; computes `subtotal`, `total=subtotal+deliveryCost`.
  - Creates order; registers CDEK order if enabled.
  - `cod` → `status:'confirmed'`, returns `{redirect}`.
  - `online` → Tinkoff `Init`, returns `{paymentUrl}`; demo → `{redirect}`.
- `GET /:id` → `{id,status,total,paymentMethod,payment}`.

### /api/payment  (routes/payment.js)
- `POST /notify` — Tinkoff webhook; verifies token; updates order `status/payment`; responds `OK`.

## server.js

`express.json` → API routes → `express.static(public)` → `/order/{success,fail}` serve `order.html` → SPA 404 fallback → error handler.
