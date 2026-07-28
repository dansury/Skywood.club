# Email templates — Skywood.club

All transactional emails are sent from the shop and **signed personally by Яна**
from the Skywood team. The PHP sender (`api/lib/mail.php`) renders these
templates; edit the wording here and mirror it in `sw_mail_*` template builders.

Placeholders use `{{name}}` syntax and are filled per order:

| Placeholder        | Meaning                                    |
|--------------------|--------------------------------------------|
| `{{order_id}}`     | Order number, e.g. `SW20260601-001`        |
| `{{customer_name}}`| Recipient name                             |
| `{{items}}`        | Line list: «Название, Цвет × N — Сумма»    |
| `{{subtotal}}`     | Items subtotal                             |
| `{{delivery}}`     | Delivery line (СДЭК + city/PVZ + cost)     |
| `{{total}}`        | Grand total                                |
| `{{payment}}`      | Payment method, human-readable             |
| `{{product}}`      | Product name (+ colour) of a preorder request |
| `{{ready_date}}`   | Expected arrival, e.g. `1 марта 2027`      |
| `{{contact_method}}` | Preferred channel: Телефон / WhatsApp / Telegram / E-mail |
| `{{contact}}`      | The contact itself (phone, nick or e-mail) |

Signature appended to every customer email:

```
С уважением,
Яна, команда skywood.club
По всем вопросам звоните: +7 977 508-45-85
WhatsApp / Telegram: +7 977 508-45-85
skywood.club
```

---

## 1. Order confirmation (`order`) — to customer

**Subject:** `Заказ {{order_id}} принят — Skywood`

```
Здравствуйте, {{customer_name}}!

Спасибо за заказ в Skywood — рады, что вы выбрали нас.

Ваш заказ {{order_id}}:
{{items}}

Доставка: {{delivery}}
Способ оплаты: {{payment}}
Итого: {{total}}

Мы свяжемся с вами для подтверждения и отправим палатку со склада СДЭК —
после отправки пришлём трек-номер для отслеживания.

Если у вас есть вопросы — просто ответьте на это письмо или позвоните мне.
```

---

## 2. Preorder confirmation (`preorder`) — to customer

Sent when one or more items are out of stock (stock = 0) and the customer
chooses **«Оформить предзаказ»**.

**Subject:** `Предзаказ {{order_id}} оформлен — Skywood`

```
Здравствуйте, {{customer_name}}!

Спасибо за предзаказ в Skywood!

Сейчас этих палаток нет в наличии, но новая партия уже в пути. Вы оформили
предзаказ {{order_id}}:
{{items}}

Доставка: {{delivery}}
Способ оплаты: {{payment}}
Итого: {{total}}

Как только товар поступит на склад (обычно 1–3 недели), мы сразу свяжемся с
вами, подтвердим заказ и отправим его в первую очередь. Если сроки окажутся
дольше — обязательно предупредим заранее.

Спасибо, что дождётесь — это правда лучшие палатки для сна среди деревьев.
```

---

## 3. New-order notification (`admin`) — to shop owner

Internal copy, not signed. Sent to `ADMIN_EMAIL`.

**Subject:** `Новый заказ {{order_id}} ({{payment}})`

```
Новый заказ на сайте.

Номер: {{order_id}}
Клиент: {{customer_name}}, {{phone}}, {{email}}
Состав:
{{items}}
Доставка: {{delivery}}
Оплата: {{payment}}
Итого: {{total}}
Комментарий: {{comment}}
```

---

## 4. Next-season preorder (`preorder_request`) — to customer

Sent when a visitor leaves a «Узнать о поступлении» request **and** picked
`E-mail` as the preferred contact channel. No order and no payment involved.

**Subject:** `Сообщим о поступлении — Skywood`

```
Здравствуйте, {{customer_name}}!

Спасибо за интерес к Skywood — мы записали вас в лист ожидания.

Товар: {{product}}
Ждём новую партию: {{ready_date}}

Как только палатки приедут на склад, я напишу вам первой волной — до того, как
они появятся в открытой продаже. Если сроки сдвинутся, предупрежу заранее.

Ничего оплачивать сейчас не нужно, и от записи всегда можно отказаться —
просто ответьте на это письмо.
```

---

## 5. Preorder-request notification (`preorder_admin`) — to shop owner

Internal copy, not signed. Sent to the `preorder_email` setting from the admin
panel (ships as `Dansury@gmail.com`), **not** to `ADMIN_EMAIL`.

**Subject:** `Узнать о поступлении: {{product}}`

```
Заявка «Узнать о поступлении».

Товар: {{product}}
Ожидаемое поступление: {{ready_date}}
Имя: {{customer_name}}
Способ связи: {{contact_method}} — {{contact}}
Адрес: {{address}}
Комментарий: {{comment}}

Заявка сохранена в админке — вкладка «Узнать о поступлении».
```

`Адрес` and `Комментарий` lines are omitted when empty.
