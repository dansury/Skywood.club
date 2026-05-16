# spec/frontend.md

Статический фронтенд в корне сайта. Без сборки. Шрифты — Google Fonts (Manrope, Unbounded).

## index.html — лендинг

Секции: header (fixed) → hero (видео + параллакс) → marquee → products → parallax-блок → why (преимущества) → compare → how (доставка/оплата) → reviews → faq → finale → footer. Плюс корзина-drawer, модалка товара, модалка чекаута, toast.

Hero: `<video autoplay muted loop playsinline>` c `poster`, источник `/assets/video/hero.mp4`. Параллакс-фоны — `background-attachment:fixed` + `data-parallax` на hero-контенте.

## css/styles.css

Тёмная лесная палитра (`--bg`, `--accent` #7fbf5b, `--warm`). Параллакс, `.reveal`-анимации появления, адаптив (980/760 px), `prefers-reduced-motion`.

## js/main.js

Параллакс героя (`data-parallax`, rAF), фон шапки при скролле, бургер-меню, плавный скролл с offset, IntersectionObserver для `.reveal` (+ MutationObserver на `#productGrid`), автозапуск видео.

## js/store.js

- `init()` — `GET /api/products`; рендер каталога, футера; демо-баннер.
- Каталог: карточки товаров (слайдер фото по точкам), модалка товара (галерея).
- Корзина: `localStorage 'sw_cart'` `[{id,color,qty}]`; drawer.
- Чекаут — модалка, 3 шага:
  1. контакты (имя, телефон, email, комментарий);
  2. доставка — автокомплит города (`/api/cdek/cities`), тип (ПВЗ/курьер), выбор ПВЗ (`/api/cdek/points`) или адрес, авторасчёт (`/api/cdek/calculate`);
  3. оплата — онлайн (Т-Банк) / при получении (наложенный платёж СДЭК), итог, отправка `POST /api/orders`.
- Ответ заказа: `paymentUrl` → редирект на оплату; `redirect` → страница статуса.

## order.html

Страница `/order/success` и `/order/fail`. Читает `?order=`, запрашивает `/api/orders/:id`, показывает статус (оплачено / при получении / демо / ошибка оплаты).
