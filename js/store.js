/* Skywood — каталог, корзина, оформление заказа */
(() => {
  'use strict';

  const $ = (s, r = document) => r.querySelector(s);
  const money = (n) => Math.round(n).toLocaleString('ru-RU') + ' ₽';
  const debounce = (fn, ms = 300) => {
    let t;
    return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
  };

  /* ---------- debug-режим (?debug=1) ---------- */
  const DEBUG = new URLSearchParams(location.search).get('debug') === '1';
  const dbg = { entries: [] };
  // Пробрасываем ?debug=1 на бэкенд, чтобы он вернул трассу запроса.
  const apiPath = (p) =>
    'api/' + p + (DEBUG ? (p.includes('?') ? '&' : '?') + 'debug=1' : '');

  let PRODUCTS = [];
  let COMPANY = {};
  let DEMO = false;
  let cart = [];
  try { cart = JSON.parse(localStorage.getItem('sw_cart') || '[]'); } catch { cart = []; }

  const saveCart = () => localStorage.setItem('sw_cart', JSON.stringify(cart));
  const product = (id) => PRODUCTS.find((p) => p.id === id);

  // Профиль покупателя: сохраняем введённые данные, чтобы подставить их в
  // следующий раз (требует согласия на cookies — баннер в js/cookies.js).
  function loadProfile() {
    try { return JSON.parse(localStorage.getItem('sw_profile') || '{}') || {}; }
    catch { return {}; }
  }
  function saveProfile() {
    try {
      localStorage.setItem('sw_profile', JSON.stringify({
        customer: ck.customer,
        city: ck.city,
        deliveryType: ck.deliveryType,
      }));
    } catch { /* приватный режим — игнорируем */ }
  }
  // Любая корзина с хотя бы одной позицией без остатка — предзаказ.
  const cartHasPreorder = () =>
    cartLines().some((l) => l.p.preorder || (l.p.stockByColor &&
      (l.p.stockByColor[l.color] || 0) < l.qty));

  /* ---------- тост ---------- */
  let toastTimer;
  function toast(msg) {
    const t = $('#toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 2600);
  }

  /* ---------- запуск ---------- */
  async function init() {
    debugInit();
    try {
      const { data } = await apiFetch('products');
      PRODUCTS = (data && data.products) || [];
      COMPANY = (data && data.company) || {};
      DEMO = !!(data && data.demo);
    } catch {
      toast('Не удалось загрузить каталог');
      return;
    }
    renderProducts();
    renderFooter();
    renderCart();
    if (DEMO) showDemoBar();
    bindGlobal();
    if (document.readyState === 'complete') loadProductVideos();
    else window.addEventListener('load', loadProductVideos, { once: true });
  }

  // Видео карточек грузим лениво — только после полной загрузки страницы.
  function loadProductVideos() {
    $$('.product__video[data-src]').forEach((v) => {
      v.preload = 'auto';
      v.src = v.dataset.src;
      v.removeAttribute('data-src');
      v.load();
    });
  }

  function showDemoBar() {
    const bar = document.createElement('div');
    bar.className = 'demo-bar';
    bar.textContent = 'Демо-режим: оплата Т-Банк и API СДЭК работают на расчётных заглушках. Укажите доступы в .env для боевого режима.';
    document.body.prepend(bar);
  }

  /* ---------- каталог ---------- */
  function renderProducts() {
    const grid = $('#productGrid');
    grid.innerHTML = '';
    PRODUCTS.forEach((p) => grid.appendChild(productCard(p)));
  }

  function productCard(p) {
    const art = document.createElement('article');
    art.className = 'product reveal';
    const save = p.oldPrice ? p.oldPrice - p.price : 0;
    const specs = Object.values(p.specs || {}).slice(0, 3)
      .map((v) => `<span>${v}</span>`).join('');
    // Кадры галереи: первое фото, затем видео (если есть), затем остальные фото.
    const frames = p.video
      ? [{ type: 'img', src: p.images[0] },
         { type: 'video', src: p.video },
         ...p.images.slice(1).map((src) => ({ type: 'img', src }))]
      : p.images.map((src) => ({ type: 'img', src }));
    art.innerHTML = `
      <div class="product__media">
        <img src="assets/img/${p.images[0]}" alt="${p.name}" loading="lazy">
        ${p.video ? `<video class="product__video" muted loop playsinline controls preload="none"
          poster="assets/img/${p.images[0]}" data-src="assets/video/${p.video}"></video>` : ''}
        ${p.badge ? `<span class="product__badge ${p.oldPrice ? 'product__badge--sale' : ''}">${p.badge}</span>` : ''}
        ${p.preorder ? '<span class="product__badge product__badge--sale">Предзаказ</span>' : ''}
        ${p.soldOut ? '<span class="product__badge product__badge--off">Нет в наличии</span>' : ''}
        ${p.available ? '' : '<div class="product__soldout">Под заказ</div>'}
        ${frames.length > 1 ? `
        <button class="gallery-arrow gallery-arrow--prev" type="button" aria-label="Предыдущий кадр">‹</button>
        <button class="gallery-arrow gallery-arrow--next" type="button" aria-label="Следующий кадр">›</button>` : ''}
        <div class="product__dots">${frames.map((f, i) =>
          `<button data-i="${i}" class="${i === 0 ? 'active' : ''}" aria-label="${f.type === 'video' ? 'Видео' : 'Фото ' + (i + 1)}"></button>`).join('')}</div>
      </div>
      <div class="product__body">
        <span class="product__cat">${p.category}</span>
        <h3 class="product__name">${p.name}</h3>
        <p class="product__tagline">${p.tagline}</p>
        <div class="product__specs">${specs}</div>
        <div class="product__price">
          <b>${money(p.price)}</b>
          ${p.oldPrice ? `<del>${money(p.oldPrice)}</del>` : ''}
          ${save > 0 ? `<span class="save">−${money(save)}</span>` : ''}
        </div>
        <div class="product__actions">
          <button class="btn btn--ghost btn--sm" data-act="details">Подробнее</button>
          <button class="btn btn--primary btn--sm" data-act="buy" ${p.soldOut ? 'disabled' : ''}>${p.preorder ? 'Предзаказ' : (p.soldOut ? 'Нет в наличии' : 'В корзину')}</button>
        </div>
        ${p.preorderOffer ? `
        <button class="btn btn--ghost btn--sm product__notify" data-act="notify">Узнать о поступлении</button>
        <div class="product__eta">Следующая партия — ${esc(p.preorderOffer.label)}</div>` : ''}
      </div>`;
    const media = $('.product__media', art);
    const img = $('img', media);
    const video = $('.product__video', media);
    const dots = $$('.product__dots button', media);
    let curIdx = 0;
    const setImage = (idx) => {
      idx = Math.max(0, Math.min(frames.length - 1, idx));
      if (idx === curIdx) return;
      curIdx = idx;
      const f = frames[idx];
      if (f.type === 'video') {
        if (!video.src && video.dataset.src) {
          video.src = video.dataset.src;
          video.removeAttribute('data-src');
        }
        video.classList.add('show');
        video.play().catch(() => {});
      } else {
        if (video) { video.classList.remove('show'); video.pause(); }
        img.src = `assets/img/${f.src}`;
      }
      media.querySelector('.product__dots .active')?.classList.remove('active');
      dots[idx]?.classList.add('active');
    };
    $('.product__dots', art).addEventListener('click', (e) => {
      const b = e.target.closest('button'); if (!b) return;
      setImage(+b.dataset.i);
    });
    if (frames.length > 1) bindGalleryNav(media, frames, setImage, () => curIdx);
    $('[data-act="details"]', art).addEventListener('click', () => openProduct(p.id));
    $('[data-act="buy"]', art).addEventListener('click', () => {
      addToCart(p.id);
    });
    $('[data-act="notify"]', art)?.addEventListener('click', () => openPreorder(p.id));
    return art;
  }

  /* Навигация по галерее карточки: полукруглые стрелки влево/вправо
     поверх медиа (с зацикливанием) и свайп на тач-устройствах. */
  function bindGalleryNav(media, frames, setImage, getIdx) {
    // Предзагрузка фото-кадров (видео грузится отдельно после загрузки страницы).
    frames.forEach((f, i) => {
      if (i > 0 && f.type === 'img') new Image().src = `assets/img/${f.src}`;
    });

    let startX = 0, startY = 0, swiping = false;
    media.addEventListener('touchstart', (e) => {
      startX = e.touches[0].clientX;
      startY = e.touches[0].clientY;
      swiping = true;
    }, { passive: true });
    media.addEventListener('touchend', (e) => {
      if (!swiping) return;
      swiping = false;
      const dx = e.changedTouches[0].clientX - startX;
      const dy = e.changedTouches[0].clientY - startY;
      if (Math.abs(dx) < 40 || Math.abs(dx) <= Math.abs(dy)) return;
      setImage(getIdx() + (dx < 0 ? 1 : -1));
    }, { passive: true });

    const step = (dir) => setImage((getIdx() + dir + frames.length) % frames.length);
    media.querySelector('.gallery-arrow--prev')?.addEventListener('click', (e) => {
      e.stopPropagation();
      step(-1);
    });
    media.querySelector('.gallery-arrow--next')?.addEventListener('click', (e) => {
      e.stopPropagation();
      step(1);
    });
  }

  /* ---------- модалка товара ---------- */
  function openProduct(id) {
    const p = product(id);
    if (!p) return;
    const box = $('#productModalBox');
    box.innerHTML = `
      <button class="modal__close" data-close>✕</button>
      <div class="pm">
        <div class="pm__gallery">
          ${p.video ? `<video class="pm__video" autoplay muted loop playsinline controls preload="auto"><source src="assets/video/${p.video}" type="video/mp4"></video>` : ''}
          <div class="pm__main">
            <img src="assets/img/${p.images[0]}" alt="${p.name}" id="pmMain">
            <div class="pm__nav">
              <button id="pmPrev" aria-label="Предыдущее фото">❮</button>
              <button id="pmNext" aria-label="Следующее фото">❯</button>
            </div>
          </div>
          <div class="pm__thumbs">${p.images.map((im, i) =>
            `<img src="assets/img/${im}" data-i="${i}" class="${i === 0 ? 'active' : ''}" alt="">`).join('')}</div>
        </div>
        <div class="pm__info">
          <span class="product__cat">${p.category}</span>
          <h3>${p.name}</h3>
          <p class="pm__desc">${p.short}</p>
          <div class="product__specs">${Object.entries(p.specs || {}).map(([k, v]) =>
            `<span>${k}: ${v}</span>`).join('')}</div>
          <ul class="pm__feat">${p.features.slice(0, 4).map((f) =>
            `<li><b>${f.title}</b>${f.text}</li>`).join('')}</ul>
          <div class="pm__price">
            <b>${money(p.price)}</b>
            ${p.oldPrice ? `<del>${money(p.oldPrice)}</del>` : ''}
          </div>
          <button class="btn btn--primary btn--block" id="pmBuy" ${p.soldOut ? 'disabled' : ''}>${p.preorder ? 'Оформить предзаказ' : (p.soldOut ? 'Нет в наличии' : 'В корзину')}</button>
          ${p.preorderOffer ? `
          <button class="btn btn--ghost btn--block" id="pmNotify">Узнать о поступлении</button>
          <div class="product__eta">Следующая партия — ${esc(p.preorderOffer.label)}</div>` : ''}
        </div>
      </div>`;
    const mainImg = $('#pmMain', box);
    const thumbs = box.querySelector('.pm__thumbs');
    const thumbImgs = Array.from(thumbs.querySelectorAll('img'));

    const updateImage = (idx) => {
      mainImg.src = thumbImgs[idx].src;
      box.querySelector('.pm__thumbs .active')?.classList.remove('active');
      thumbImgs[idx].classList.add('active');
      thumbs.scrollLeft = Math.max(0, (idx - 1) * 62);
    };

    thumbs.addEventListener('click', (e) => {
      const t = e.target.closest('img'); if (!t) return;
      const idx = thumbImgs.indexOf(t);
      updateImage(idx);
    });

    $('#pmPrev', box).addEventListener('click', () => {
      const activeIdx = thumbImgs.findIndex(img => img.classList.contains('active'));
      if (activeIdx > 0) updateImage(activeIdx - 1);
    });

    $('#pmNext', box).addEventListener('click', () => {
      const activeIdx = thumbImgs.findIndex(img => img.classList.contains('active'));
      if (activeIdx < thumbImgs.length - 1) updateImage(activeIdx + 1);
    });

    // Swipe support
    let touchStartX = 0;
    mainImg.addEventListener('touchstart', (e) => { touchStartX = e.touches[0].clientX; });
    mainImg.addEventListener('touchend', (e) => {
      const touchEndX = e.changedTouches[0].clientX;
      const activeIdx = thumbImgs.findIndex(img => img.classList.contains('active'));
      if (touchEndX < touchStartX - 50 && activeIdx < thumbImgs.length - 1) {
        thumbImgs[activeIdx + 1].click();
      } else if (touchEndX > touchStartX + 50 && activeIdx > 0) {
        thumbImgs[activeIdx - 1].click();
      }
    });

    // Keyboard navigation
    const handleKeyboard = (e) => {
      if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
        const activeIdx = thumbImgs.findIndex(img => img.classList.contains('active'));
        if (e.key === 'ArrowRight' && activeIdx < thumbImgs.length - 1) {
          thumbImgs[activeIdx + 1].click();
        } else if (e.key === 'ArrowLeft' && activeIdx > 0) {
          thumbImgs[activeIdx - 1].click();
        }
      }
    };
    box.addEventListener('keydown', handleKeyboard);

    $('#pmBuy', box).addEventListener('click', () => { addToCart(p.id); closeModal('#productModal'); });
    $('#pmNotify', box)?.addEventListener('click', () => {
      closeModal('#productModal');
      openPreorder(p.id);
    });
    openModal('#productModal');
  }

  /* ---------- предзаказ: «Узнать о поступлении» ---------- */
  // Заявка на следующую партию: контакт вместо заказа — без оплаты и доставки.
  const pre = { id: '', color: '', busy: false };

  function openPreorder(id, color) {
    const p = product(id);
    if (!p || !p.preorderOffer) return;
    pre.id = id;
    pre.color = color || (p.options?.color?.[0] || '');
    pre.busy = false;
    renderPreorder();
    openModal('#preorderModal');
  }

  function renderPreorder() {
    const p = product(pre.id);
    const prof = loadProfile().customer || {};
    const colors = p.options?.color || [];
    $('#preorderBox').innerHTML = `<button class="modal__close" data-close>✕</button>
      <div class="ck">
        <div class="ck__head">
          <h3>Узнать о поступлении</h3>
          <p>${esc(p.name)} — следующая партия ${esc(p.preorderOffer.label)}</p>
        </div>
        <p class="hint" style="margin:-12px 0 18px">Оставьте контакты — напишем сразу,
          как палатки приедут на склад. Ничего оплачивать сейчас не нужно.</p>
        <div class="field"><label>Как к вам обращаться *</label>
          <input id="preName" value="${esc(prof.name || '')}" placeholder="Иван"></div>
        ${colors.length > 1 ? `
        <div class="field"><label>Цвет</label>
          <select id="preColor">${colors.map((c) =>
            `<option value="${esc(c)}" ${c === pre.color ? 'selected' : ''}>${esc(c)}</option>`).join('')}</select></div>` : ''}
        <div class="field"><label>Как с вами связаться *</label>
          <input id="preContact" placeholder="Телефон, Telegram или e-mail">
          <div class="hint">Как вам удобнее: +7 999 000-00-00, @nickname или you@example.com</div></div>
        <div class="field"><label>Адрес доставки — необязательно</label>
          <input id="preAddr" value="${esc(prof.address || '')}" placeholder="Город, улица, дом"></div>
        <div class="field"><label>Комментарий</label>
          <textarea id="preComment" placeholder="Необязательно"></textarea></div>
        <label class="ck__consent" style="display:flex;gap:9px;align-items:flex-start;margin:14px 0 4px;font-size:.85rem;color:var(--muted);cursor:pointer">
          <input type="checkbox" id="preConsent" style="margin-top:3px;flex:none">
          <span>Я согласен(на) на обработку персональных данных в соответствии с
            <a href="privacy.html" target="_blank" rel="noopener" style="color:var(--accent)">политикой конфиденциальности</a>.</span>
        </label>
        <div id="preErr"></div>
        <div class="ck__nav">
          <button class="btn btn--ghost" data-close>Отмена</button>
          <button class="btn btn--primary" id="preSubmit">Сообщите мне</button>
        </div>
      </div>`;
    bindPreorder();
  }

  function bindPreorder() {
    $('#preColor')?.addEventListener('change', (e) => { pre.color = e.target.value; });
    $('#preSubmit').addEventListener('click', submitPreorder);
  }

  async function submitPreorder() {
    if (pre.busy) return;
    const errBox = $('#preErr');
    const fail = (msg) => { errBox.innerHTML = `<div class="ck__err">${esc(msg)}</div>`; };
    errBox.innerHTML = '';

    const body = {
      productId: pre.id,
      color: pre.color,
      name: $('#preName').value.trim(),
      contact: $('#preContact').value.trim(),
      address: $('#preAddr').value.trim(),
      comment: $('#preComment').value.trim(),
      consent: $('#preConsent').checked,
    };
    if (body.name.length < 2) return fail('Укажите, как к вам обращаться');
    if (body.contact.length < 3) return fail('Укажите телефон, Telegram или e-mail');
    if (!body.consent) return fail('Подтвердите согласие на обработку персональных данных');

    pre.busy = true;
    const btn = $('#preSubmit');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';
    try {
      const { data: res } = await apiFetch('preorder', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
      if (!res) throw new Error('Пустой ответ сервера');
      if (res.errors) throw new Error(res.errors.join('. '));
      if (res.error) throw new Error(res.error);
      showPreorderDone(res.readyLabel);
    } catch (e) {
      fail(e.message);
      btn.disabled = false;
      btn.textContent = 'Сообщите мне';
    } finally {
      pre.busy = false;
    }
  }

  function showPreorderDone(label) {
    $('#preorderBox').innerHTML = `<button class="modal__close" data-close>✕</button>
      <div class="ck"><div class="ck__ok">
        <div class="big">🌲</div>
        <h3>Записали вас в лист ожидания</h3>
        <p style="color:var(--muted);margin-top:8px">Ждём партию${label ? ' ' + esc(label) : ''} —
          сообщим вам первыми, как только палатки приедут на склад.</p>
        <button class="btn btn--primary btn--block" data-close style="margin-top:20px">Готово</button>
      </div></div>`;
  }

  /* ---------- корзина ---------- */
  function addToCart(id, color) {
    const p = product(id);
    if (!p || p.soldOut) return;
    const col = color || (p.options?.color?.[0] || '');
    const line = cart.find((c) => c.id === id && c.color === col);
    if (line) line.qty = Math.min(10, line.qty + 1);
    else cart.push({ id, color: col, qty: 1 });
    saveCart();
    renderCart();
    toast(`«${p.name}» в корзине`);
    openCart();
  }

  function cartLines() {
    return cart.map((c) => {
      const p = product(c.id);
      return p ? { ...c, p } : null;
    }).filter(Boolean);
  }
  const cartTotal = () => cartLines().reduce((s, l) => s + l.p.price * l.qty, 0);
  const cartCount = () => cart.reduce((s, c) => s + c.qty, 0);

  function renderCart() {
    const lines = cartLines();
    const body = $('#cartItems');
    if (!lines.length) {
      body.innerHTML = '<div class="cart__empty">Корзина пуста.<br>Самое время выбрать палатку.</div>';
    } else {
      body.innerHTML = '';
      lines.forEach((l, idx) => {
        const row = document.createElement('div');
        row.className = 'cart-item';
        row.innerHTML = `
          <img class="cart-item__img" src="assets/img/${l.p.images[0]}" alt="">
          <div>
            <div class="cart-item__name">${l.p.name}</div>
            ${l.color ? `<div class="cart-item__opt">Цвет: ${l.color}</div>` : ''}
            <div class="cart-item__price">${money(l.p.price * l.qty)}</div>
            <div class="cart-item__qty">
              <button data-act="dec">−</button><span>${l.qty}</span><button data-act="inc">+</button>
            </div>
          </div>
          <button class="cart-item__del" data-act="del">Удалить</button>`;
        row.addEventListener('click', (e) => {
          const act = e.target.dataset.act;
          if (act === 'inc') cart[idx].qty = Math.min(10, cart[idx].qty + 1);
          else if (act === 'dec') cart[idx].qty = Math.max(1, cart[idx].qty - 1);
          else if (act === 'del') cart.splice(idx, 1);
          else return;
          saveCart(); renderCart();
        });
        body.appendChild(row);
      });
    }
    $('#cartTotal').textContent = money(cartTotal());
    const cnt = cartCount();
    const badge = $('#cartCount');
    badge.textContent = cnt;
    badge.classList.toggle('show', cnt > 0);
    $('#checkoutBtn').disabled = !lines.length;
  }

  const openCart = () => { $('#cart').classList.add('open'); $('#overlay').classList.add('show'); };
  const closeCart = () => { $('#cart').classList.remove('open'); $('#overlay').classList.remove('show'); };

  /* ---------- модалки ---------- */
  function openModal(sel) { $(sel).classList.add('open'); document.body.style.overflow = 'hidden'; }
  function closeModal(sel) {
    $(sel).classList.remove('open');
    if (!document.querySelector('.modal.open')) document.body.style.overflow = '';
  }

  /* ================= ОФОРМЛЕНИЕ ЗАКАЗА ================= */
  const ck = {
    step: 1,
    customer: { name: '', phone: '', email: '', comment: '', address: '' },
    city: null,
    deliveryType: 'pvz',
    pvz: null,
    points: [],
    delivery: null,
    payment: 'online',
    consent: false,
    busy: false,
  };

  function openCheckout() {
    if (!cartLines().length) return;
    // Подставляем ранее введённые данные.
    const prof = loadProfile();
    if (prof.customer) ck.customer = { ...ck.customer, ...prof.customer };
    if (prof.city) ck.city = prof.city;
    if (prof.deliveryType) ck.deliveryType = prof.deliveryType;
    ck.consent = false;
    ck.step = 1;
    closeCart();
    renderCheckout();
    openModal('#checkoutModal');
  }

  function renderCheckout() {
    const box = $('#checkoutBox');
    const steps = `<div class="ck__steps">
      <div class="${ck.step >= 1 ? 'done' : ''}"></div>
      <div class="${ck.step >= 2 ? 'done' : ''}"></div>
      <div class="${ck.step >= 3 ? 'done' : ''}"></div></div>`;
    let inner = '';
    if (ck.step === 1) inner = stepContacts();
    else if (ck.step === 2) inner = stepDelivery();
    else inner = stepPayment();
    box.innerHTML = `<button class="modal__close" data-close>✕</button>
      <div class="ck"><div class="ck__head">
        <h3>Оформление заказа</h3><p>Шаг ${ck.step} из 3</p></div>
        ${steps}${inner}</div>`;
    if (ck.step === 1) bindContacts();
    else if (ck.step === 2) bindDelivery();
    else bindPayment();
  }

  /* — шаг 1: контакты — */
  function stepContacts() {
    const c = ck.customer;
    return `
      <div class="field"><label>Имя и фамилия получателя *</label>
        <input id="ckName" value="${esc(c.name)}" placeholder="Иван Петров"></div>
      <div class="field--row">
        <div class="field"><label>Телефон *</label>
          <input id="ckPhone" value="${esc(c.phone)}" placeholder="+7 999 000-00-00"></div>
        <div class="field"><label>E-mail</label>
          <input id="ckEmail" value="${esc(c.email)}" placeholder="для чека и статуса"></div>
      </div>
      <div class="field"><label>Комментарий к заказу</label>
        <textarea id="ckComment" placeholder="Необязательно">${esc(c.comment)}</textarea></div>
      <div class="ck__nav">
        <button class="btn btn--ghost" data-close>Отмена</button>
        <button class="btn btn--primary" id="ckNext">Далее — доставка</button>
      </div>`;
  }
  function bindContacts() {
    $('#ckNext').addEventListener('click', () => {
      const c = ck.customer;
      c.name = $('#ckName').value.trim();
      c.phone = $('#ckPhone').value.trim();
      c.email = $('#ckEmail').value.trim();
      c.comment = $('#ckComment').value.trim();
      if (c.name.length < 2) return toast('Укажите имя получателя');
      if (!/^\+?[0-9\s\-()]{10,18}$/.test(c.phone)) return toast('Укажите корректный телефон');
      saveProfile();
      ck.step = 2;
      renderCheckout();
    });
  }

  /* — шаг 2: доставка — */
  function stepDelivery() {
    const c = ck.customer;
    return `
      <div class="field"><label>Город доставки *</label>
        <input id="ckCity" autocomplete="off" placeholder="Начните вводить город"
          value="${ck.city ? esc(ck.city.city) : ''}">
        <div class="ac-list" id="cityList"></div>
      </div>
      <div class="field"><label>Способ доставки</label>
        <div class="choice" id="dtChoice">
          <label class="${ck.deliveryType === 'pvz' ? 'sel' : ''}">
            <input type="radio" name="dt" value="pvz" ${ck.deliveryType === 'pvz' ? 'checked' : ''}>
            <span><span class="co-title">Пункт выдачи СДЭК</span>
            <span class="co-sub">Получить заказ в ближайшем ПВЗ — обычно дешевле</span></span></label>
          <label class="${ck.deliveryType === 'door' ? 'sel' : ''}">
            <input type="radio" name="dt" value="door" ${ck.deliveryType === 'door' ? 'checked' : ''}>
            <span><span class="co-title">Курьер до двери</span>
            <span class="co-sub">Курьер СДЭК привезёт заказ по адресу</span></span></label>
        </div>
      </div>
      <div class="field" id="pvzField" style="${ck.deliveryType === 'pvz' ? '' : 'display:none'}">
        <label>Пункт выдачи *</label>
        <select id="ckPvz"><option value="">${ck.city ? 'Выберите пункт' : 'Сначала выберите город'}</option></select>
      </div>
      <div class="field" id="addrField" style="${ck.deliveryType === 'door' ? '' : 'display:none'}">
        <label>Адрес доставки *</label>
        <input id="ckAddr" value="${esc(c.address)}" placeholder="Улица, дом, квартира">
      </div>
      <div id="ckDelivInfo"></div>
      <div class="ck__nav">
        <button class="btn btn--ghost" id="ckBack">Назад</button>
        <button class="btn btn--primary" id="ckNext">Далее — оплата</button>
      </div>`;
  }

  function bindDelivery() {
    const cityInput = $('#ckCity');
    const list = $('#cityList');

    const search = debounce(async () => {
      const q = cityInput.value.trim();
      if (q.length < 2) { list.classList.remove('show'); return; }
      list.innerHTML = '<button disabled style="color:var(--muted);cursor:default">Поиск…</button>';
      list.classList.add('show');
      const note = (msg) =>
        `<button disabled style="color:var(--muted);cursor:default">${esc(msg)}</button>`;
      try {
        const { res, data } = await apiFetch('cdek/cities?q=' + encodeURIComponent(q));
        if (!res.ok || (data && data.error)) {
          // В debug-режиме показываем настоящую ошибку СДЭК, иначе — общий текст.
          const detail = data && data.error ? data.error : 'HTTP ' + res.status;
          list.innerHTML = note(DEBUG ? 'Ошибка СДЭК: ' + detail
            : 'Сервис СДЭК недоступен — попробуйте позже');
          return;
        }
        const cities = Array.isArray(data) ? data : [];
        if (!cities.length) {
          list.innerHTML = note('Город не найден');
          return;
        }
        list.innerHTML = cities.map((c, i) =>
          `<button data-i="${i}">${esc(c.city)}<span style="color:var(--muted)"> — ${esc(c.region || '')}</span></button>`).join('');
        list._cities = cities;
      } catch (e) {
        list.innerHTML = note(DEBUG ? 'Ошибка запроса: ' + e.message
          : 'Ошибка СДЭК — попробуйте ещё раз');
      }
    }, 280);

    cityInput.addEventListener('input', () => { ck.city = null; ck.delivery = null; search(); });
    cityInput.addEventListener('blur', () => { setTimeout(() => list.classList.remove('show'), 200); });
    list.addEventListener('click', (e) => {
      const b = e.target.closest('button'); if (!b) return;
      ck.city = list._cities[b.dataset.i];
      cityInput.value = ck.city.city;
      list.classList.remove('show');
      saveProfile();
      loadPoints();
      recalcDelivery();
    });

    $('#dtChoice').addEventListener('change', (e) => {
      ck.deliveryType = e.target.value;
      $$('#dtChoice label').forEach((l) => l.classList.toggle('sel', l.querySelector('input').checked));
      $('#pvzField').style.display = ck.deliveryType === 'pvz' ? '' : 'none';
      $('#addrField').style.display = ck.deliveryType === 'door' ? '' : 'none';
      recalcDelivery();
    });

    const pvzSel = $('#ckPvz');
    pvzSel.addEventListener('change', () => {
      ck.pvz = ck.points.find((p) => p.code === pvzSel.value) || null;
    });

    // Восстановленный из профиля город — подгружаем ПВЗ и пересчитываем доставку.
    if (ck.city) { loadPoints(); recalcDelivery(); }

    $('#ckBack').addEventListener('click', () => { ck.step = 1; renderCheckout(); });
    $('#ckNext').addEventListener('click', () => {
      if (!ck.city) return toast('Выберите город из подсказки');
      if (ck.deliveryType === 'pvz' && !ck.pvz) return toast('Выберите пункт выдачи');
      if (ck.deliveryType === 'door') {
        ck.customer.address = $('#ckAddr').value.trim();
        if (ck.customer.address.length < 5) return toast('Укажите адрес доставки');
      }
      if (!ck.delivery) return toast('Дождитесь расчёта стоимости доставки');
      saveProfile();
      ck.step = 3;
      renderCheckout();
    });
  }

  async function loadPoints() {
    const sel = $('#ckPvz');
    if (!sel || !ck.city) return;
    sel.innerHTML = '<option value="">Загрузка пунктов…</option>';
    try {
      const { data } = await apiFetch('cdek/points?city_code=' + ck.city.code);
      if (data && data.error) {
        sel.innerHTML = `<option value="">${esc(DEBUG ? 'Ошибка: ' + data.error : 'Ошибка загрузки пунктов')}</option>`;
        return;
      }
      ck.points = Array.isArray(data) ? data : [];
      if (!ck.points.length) { sel.innerHTML = '<option value="">Нет ПВЗ в этом городе</option>'; return; }
      sel.innerHTML = '<option value="">Выберите пункт выдачи</option>' +
        ck.points.map((p) => `<option value="${p.code}">${p.name} — ${p.address || ''}</option>`).join('');
      if (ck.pvz) sel.value = ck.pvz.code;
    } catch (e) {
      sel.innerHTML = `<option value="">${esc(DEBUG ? 'Ошибка: ' + e.message : 'Ошибка загрузки пунктов')}</option>`;
    }
  }

  async function recalcDelivery() {
    const info = $('#ckDelivInfo');
    if (!ck.city || !info) return;
    ck.delivery = null;
    info.innerHTML = '<div class="hint">Считаем стоимость доставки…</div>';
    try {
      const { data: r } = await apiFetch('cdek/calculate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          deliveryType: ck.deliveryType,
          toCityCode: ck.city.code,
          items: cart.map((c) => ({ id: c.id, qty: c.qty })),
        }),
      });
      if (!r || r.error) throw new Error((r && r.error) || 'Сервис расчёта недоступен');
      ck.delivery = r;
      info.innerHTML = `<div class="ck__summary"><div class="row">
        <span>Доставка СДЭК в ${esc(ck.city.city)}</span><strong style="color:var(--text)">${money(r.cost)}</strong></div>
        <div class="row"><span>Срок</span><span>${r.periodMin}–${r.periodMax} дн.</span></div></div>`;
    } catch (e) {
      info.innerHTML = `<div class="ck__err">Не удалось рассчитать доставку: ${esc(e.message)}</div>`;
    }
  }

  /* — шаг 3: оплата — */
  function stepPayment() {
    const sub = cartTotal();
    const deliv = ck.delivery ? ck.delivery.cost : 0;
    const preorder = cartHasPreorder();
    const lines = cartLines().map((l) =>
      `<div class="row"><span>${l.p.name}${l.color ? ', ' + l.color : ''} × ${l.qty}</span>
       <span>${money(l.p.price * l.qty)}</span></div>`).join('');
    const submitLabel = preorder ? 'Оформить предзаказ'
      : (ck.payment === 'online' ? 'Перейти к оплате' : 'Подтвердить заказ');
    const preorderNote = preorder
      ? `<div class="hint" style="background:#fff7e8;border:1px solid #f0d9a8;color:#8a5a00;padding:10px 12px;border-radius:10px;margin-bottom:12px">
           Часть товаров сейчас нет в наличии — оформляем предзаказ. Мы свяжемся с вами,
           как только палатки поступят на склад (обычно 1–3 недели).</div>`
      : '';
    return preorderNote + `
      <div class="field"><label>Способ оплаты</label>
        <div class="choice" id="payChoice">
          <label class="${ck.payment === 'online' ? 'sel' : ''}">
            <input type="radio" name="pay" value="online" ${ck.payment === 'online' ? 'checked' : ''}>
            <span><span class="co-title">Картой онлайн — Т-Банк</span>
            <span class="co-sub">Безопасная оплата картой. Заказ уйдёт сразу после оплаты.</span></span></label>
          <label class="${ck.payment === 'cod' ? 'sel' : ''}">
            <input type="radio" name="pay" value="cod" ${ck.payment === 'cod' ? 'checked' : ''}>
            <span><span class="co-title">Оплата при получении</span>
            <span class="co-sub">Наложенный платёж СДЭК — оплатите в пункте выдачи или курьеру.</span></span></label>
        </div>
      </div>
      <div class="ck__summary">
        ${lines}
        <div class="row"><span>Доставка СДЭК</span><span>${money(deliv)}</span></div>
        <div class="row row--total"><span>Итого</span><span>${money(sub + deliv)}</span></div>
      </div>
      <label class="ck__consent" style="display:flex;gap:9px;align-items:flex-start;margin:14px 0 4px;font-size:.85rem;color:var(--muted);cursor:pointer">
        <input type="checkbox" id="ckConsent" ${ck.consent ? 'checked' : ''} style="margin-top:3px;flex:none">
        <span>Я согласен(на) на обработку персональных данных в соответствии с
          <a href="privacy.html" target="_blank" rel="noopener" style="color:var(--accent)">политикой конфиденциальности</a>.</span>
      </label>
      <div id="ckSubmitErr"></div>
      <div class="ck__nav">
        <button class="btn btn--ghost" id="ckBack">Назад</button>
        <button class="btn btn--primary" id="ckSubmit">${submitLabel}</button>
      </div>`;
  }

  function bindPayment() {
    $('#payChoice').addEventListener('change', (e) => {
      ck.payment = e.target.value;
      renderCheckout();
    });
    $('#ckConsent').addEventListener('change', (e) => { ck.consent = e.target.checked; });
    $('#ckBack').addEventListener('click', () => { ck.step = 2; renderCheckout(); });
    $('#ckSubmit').addEventListener('click', submitOrder);
  }

  async function submitOrder() {
    if (ck.busy) return;
    const errBox = $('#ckSubmitErr');
    errBox.innerHTML = '';
    if (!ck.consent) {
      errBox.innerHTML = `<div class="ck__err">Подтвердите согласие на обработку персональных данных</div>`;
      return;
    }
    ck.busy = true;
    const btn = $('#ckSubmit');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';
    try {
      const { data: res } = await apiFetch('orders', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          items: cart.map((c) => ({ id: c.id, qty: c.qty, color: c.color })),
          customer: ck.customer,
          delivery: {
            type: ck.deliveryType,
            cityCode: ck.city.code,
            cityName: ck.city.city,
            pvzCode: ck.pvz?.code || '',
            pvzName: ck.pvz?.name || '',
            cost: ck.delivery?.cost || 0,
          },
          paymentMethod: ck.payment,
          consent: ck.consent,
        }),
      });

      if (!res) throw new Error('Пустой ответ сервера');
      if (res.errors) throw new Error(res.errors.join('. '));
      if (res.error) throw new Error(res.error);

      cart = [];
      saveCart();
      renderCart();

      if (res.paymentUrl) { window.location.href = res.paymentUrl; return; }
      if (res.redirect) { window.location.href = res.redirect; return; }
      showOrderDone(res.orderId, res.preorder);
    } catch (e) {
      errBox.innerHTML = `<div class="ck__err">${esc(e.message)}</div>`;
      btn.disabled = false;
      btn.textContent = cartHasPreorder() ? 'Оформить предзаказ'
        : (ck.payment === 'online' ? 'Перейти к оплате' : 'Подтвердить заказ');
    } finally {
      ck.busy = false;
    }
  }

  function showOrderDone(id, preorder) {
    const title = preorder ? `Предзаказ ${esc(id || '')} оформлен` : `Заказ ${esc(id || '')} принят`;
    const text = preorder
      ? 'Спасибо! Мы свяжемся с вами, как только палатки поступят на склад. Письмо с деталями отправлено на ваш e-mail.'
      : 'Мы свяжемся с вами для подтверждения. Спасибо, что выбрали Skywood!';
    $('#checkoutBox').innerHTML = `<button class="modal__close" data-close>✕</button>
      <div class="ck"><div class="ck__ok">
        <div class="big">🌲</div>
        <h3>${title}</h3>
        <p style="color:var(--muted);margin-top:8px">${text}</p>
        <button class="btn btn--primary btn--block" data-close style="margin-top:20px">Готово</button>
      </div></div>`;
  }

  /* ---------- футер ---------- */
  function renderFooter() {
    $('#year').textContent = new Date().getFullYear();
    const c = COMPANY;
    $('#footerLegal').innerHTML = `<h4>Реквизиты</h4>
      <p>${esc(c.legalName || '')}<br>ИНН ${esc(c.inn || '')}<br>${esc(c.address || '')}</p>`;
  }

  /* ---------- утилиты ---------- */
  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (m) =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  }
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];

  /* ---------- debug: панель ошибок и обёртка над fetch ---------- */
  function debugLog(label, data) {
    if (!DEBUG) return;
    dbg.entries.push({ at: new Date().toLocaleTimeString('ru-RU'), label, data });
    renderDebug();
  }

  function debugText() {
    return dbg.entries.map((e) =>
      `[${e.at}] ${e.label}\n` +
      (typeof e.data === 'string' ? e.data : JSON.stringify(e.data, null, 2))
    ).join('\n\n');
  }

  function renderDebug() {
    const body = $('#swDebugBody');
    if (!body) return;
    $('#swDebugCount').textContent = dbg.entries.length + ' зап.';
    body.innerHTML = dbg.entries.map((e) =>
      `<div class="sw-debug__row"><b>[${e.at}] ${esc(e.label)}</b><pre>${esc(
        typeof e.data === 'string' ? e.data : JSON.stringify(e.data, null, 2)
      )}</pre></div>`).join('');
    body.scrollTop = body.scrollHeight;
  }

  function debugInit() {
    if (!DEBUG) return;
    const panel = document.createElement('div');
    panel.className = 'sw-debug';
    panel.innerHTML = `
      <div class="sw-debug__bar">
        <strong>DEBUG</strong>
        <span id="swDebugCount">0 зап.</span>
        <button type="button" id="swDebugDiag">Проверить СДЭК</button>
        <button type="button" id="swDebugCopy">Скопировать всё</button>
        <button type="button" id="swDebugClear">Очистить</button>
        <button type="button" id="swDebugMin">Свернуть</button>
      </div>
      <div class="sw-debug__body" id="swDebugBody"></div>`;
    document.body.appendChild(panel);
    $('#swDebugDiag').addEventListener('click', runDiag);
    $('#swDebugCopy').addEventListener('click', () => {
      navigator.clipboard.writeText(debugText()).then(
        () => toast('Лог скопирован — отправьте его разработчику'),
        () => toast('Не удалось скопировать'));
    });
    $('#swDebugClear').addEventListener('click', () => { dbg.entries = []; renderDebug(); });
    $('#swDebugMin').addEventListener('click', () => panel.classList.toggle('sw-debug--min'));
    window.addEventListener('error', (e) =>
      debugLog('JS-ОШИБКА', { message: e.message, source: `${e.filename}:${e.lineno}` }));
    window.addEventListener('unhandledrejection', (e) =>
      debugLog('PROMISE ОТКЛОНЁН', { reason: String(e.reason) }));
    debugLog('debug-режим включён', { url: location.href, ua: navigator.userAgent });
  }

  // Единая обёртка над fetch: парсит ответ и в debug-режиме пишет в панель
  // и сам запрос, и серверную трассу из заголовка X-Sw-Debug.
  async function apiFetch(path, opts) {
    const url = apiPath(path);
    let res;
    try {
      res = await fetch(url, opts);
    } catch (e) {
      debugLog('СЕТЕВАЯ ОШИБКА → ' + path, { error: String(e) });
      throw e;
    }
    const raw = await res.text();
    let data = null;
    try { data = raw ? JSON.parse(raw) : null; } catch { /* не JSON */ }
    if (DEBUG) {
      let trace = null;
      const h = res.headers.get('X-Sw-Debug');
      if (h) { try { trace = JSON.parse(h); } catch { /* игнор */ } }
      debugLog((res.ok ? 'OK ' : 'ОШИБКА ') + res.status + ' → ' + path, {
        status: res.status,
        response: data !== null ? data : raw,
        serverTrace: trace,
      });
    }
    return { res, data };
  }

  async function runDiag() {
    debugLog('Запуск самодиагностики СДЭК…', {});
    try {
      await apiFetch('cdek/diag');
    } catch (e) {
      debugLog('Диагностика не удалась', { error: String(e) });
    }
  }

  /* ---------- глобальные обработчики ---------- */
  function bindGlobal() {
    $('#cartBtn').addEventListener('click', openCart);
    $('#cartClose').addEventListener('click', closeCart);
    $('#overlay').addEventListener('click', closeCart);
    $('#checkoutBtn').addEventListener('click', openCheckout);

    document.addEventListener('click', (e) => {
      if (e.target.closest('[data-close]')) {
        closeModal('#productModal');
        closeModal('#checkoutModal');
        closeModal('#preorderModal');
      }
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        closeModal('#productModal');
        closeModal('#checkoutModal');
        closeModal('#preorderModal');
        closeCart();
      }
    });
  }

  document.addEventListener('DOMContentLoaded', init);
})();
