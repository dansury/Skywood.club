# spec/integrations.md

## Tinkoff — src/services/tinkoff.js

Т-Банк EACQ. API base `securepay.tinkoff.ru/v2`.

- `makeToken(params)` — root string/number params (excl. Receipt/DATA/Token/Shops) + Password, sort by key, concat values, SHA-256 hex.
- `initPayment(order)->{paymentId,paymentUrl,status}` — `POST /Init` with `TerminalKey, Amount(kopecks), OrderId, Description, NotificationURL, SuccessURL, FailURL, Token, DATA, Receipt`.
- `buildReceipt(order)` — 54-FZ receipt: item lines + delivery line; `Taxation`, `Email`/`Phone`.
- `getState(paymentId)` — `POST /GetState`.
- `verifyNotification(body)` — recompute token over body minus `Token`.

Webhook → `routes/payment.js`: `Status` `CONFIRMED`/`AUTHORIZED` → order `paid`.

## CDEK — src/services/cdek.js

CDEK API v2. Base `api.cdek.ru/v2` (test: `api.edu.cdek.ru/v2`).

- OAuth `client_credentials` → bearer token, cached until `expires_in`.
- `TARIFFS` — `pvz:136` (склад-склад), `door:137` (склад-дверь).
- `searchCities(q)` — `GET /location/cities`.
- `pickupPoints(cityCode)` — `GET /deliverypoints?type=PVZ`.
- `calculate({tariffCode,toCityCode,items})` — `POST /calculator/tariff`; `packagesFor()` derives weight/dimensions from catalog.
- `createOrder(order)` — `POST /orders`:
  - `pvz` → `delivery_point`; `door` → `to_location{code,address}`.
  - COD (`paymentMethod==='cod'`): each `packages[].items[].payment.value` = item price; `delivery_recipient_cost.value` = delivery cost. CDEK collects from recipient.
  - Online: `payment.value = 0`.
  - Sender: `from_location` + `shipment_point` from config.
