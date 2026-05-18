# spec/integrations.md

## Tinkoff — api/lib/tinkoff.php

Т-Банк EACQ. API base `securepay.tinkoff.ru/v2`. Functions prefixed `sw_tinkoff_`.

- `sw_tinkoff_token(params)` — root scalar params (excl. Receipt/DATA/Token/Shops)
  + Password, sort by key, concat values, SHA-256 hex.
- `sw_tinkoff_init_payment(order)` → `{paymentId,paymentUrl,status}` — `POST /Init`
  with `TerminalKey, Amount(kopecks), OrderId, Description, NotificationURL,
  SuccessURL, FailURL, Token, DATA, Receipt`.
- `sw_tinkoff_build_receipt(order)` — 54-FZ receipt: item lines + delivery line;
  `Taxation`, `Email`/`Phone`.
- `sw_tinkoff_verify_notification(body)` — recompute token over body minus `Token`.

Webhook → `index.php` `payment/notify`: `Status` `CONFIRMED`/`AUTHORIZED` →
order `paid`.

## CDEK — api/lib/cdek.php

Official CDEK API v2. Base `https://api.cdek.ru/v2` (test:
`https://api.edu.cdek.ru/v2`). Functions prefixed `sw_cdek_`.

- OAuth `client_credentials` → bearer token, cached to `data/.cdek-token.json`
  until `expires_in`. On failure throws `CDEK OAuth: <error_description>` so the
  real cause (bad key vs. other) is visible.
- `SW_CDEK_TARIFF_PVZ` 136 (склад-склад), `SW_CDEK_TARIFF_DOOR` 137 (склад-дверь).
- `SW_CDEK_CITIES_TTL` 2592000 (30 дней) — TTL городского кэша.
- `sw_cdek_search_cities(q)` — `GET /location/suggest/cities` (подбор по
  частично введённому названию). Per-query results cached to
  `data/.cdek-cities.json` (`{ "<query>": {at,cities} }`). Normal mode serves
  from cache; debug mode (`?debug=1`) ignores the cache and rewrites it fresh.
- `sw_cdek_pickup_points(cityCode)` — `GET /deliverypoints?type=PVZ`.
- `sw_cdek_calculate(tariffCode,toCityCode,items)` — `POST /calculator/tariff`;
  `sw_cdek_packages()` derives weight/dimensions from catalog.
- `sw_cdek_create_order(order)` — `POST /orders`:
  - `pvz` → `delivery_point`; `door` → `to_location{code,address}`.
  - COD (`paymentMethod==='cod'`): each `packages[].items[].payment.value` = item
    price; `delivery_recipient_cost.value` = delivery cost. CDEK collects from
    recipient.
  - Online: `payment.value = 0`.
  - Sender: `from_location` + `shipment_point` from config.
