/* Skywood — каталог, корзина, оформление заказа */
(() => {
  'use strict';

  const $ = (s, r = document) => r.querySelector(s);
  const money = (n) => Math.round(n).toLocaleString('ru-RU') + ' ₽';
  const debounce = (fn, ms = 300) => {
    let t;
    return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
  };

  let PRODUCTS = [];
  let COMPANY = {};
  let DEMO = false;
  let cart = [];
  try { cart = JSON.parse(localStorage.getItem('sw_cart') || '[]'); } catch { cart = []; }

  const saveCart = () => localStorage.setItem('sw_cart', JSON.stringify(cart));
  const product = (id) => PRODUCTS.find((p) => p.id === id);

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
    try {
      const data = await fetch('api/products').then((r) => r.json());
      PRODUCTS = data.products || [];
      COMPANY = data.company || {};
      DEMO = !!data.demo;
    } catch {
      toast('Не удалось загрузить каталог');
      return;
    }
    renderProducts();
    renderFooter();
    renderCart();
    if (DEMO) showDemoBar();
    bindGlobal();
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
    art.innerHTML = `
      <div class="product__media">
        <img src="assets/img/${p.images[0]}" alt="${p.name}" loading="lazy">
        ${p.badge ? `<span class="product__badge ${p.oldPrice ? 'product__badge--sale' : ''}">${p.badge}</span>` : ''}
        ${p.available ? '' : '<div class="product__soldout">Под заказ</div>'}
        <div class="product__dots">${p.images.map((_, i) =>
          `<button data-i="${i}" class="${i === 0 ? 'active' : ''}" aria-label="Фото ${i + 1}"></button>`).join('')}</div>
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
          <button class="btn btn--primary btn--sm" data-act="buy">В корзину</button>
        </div>
      </div>`;
    const img = $('.product__media img', art);
    $('.product__dots', art).addEventListener('click', (e) => {
      const b = e.target.closest('button'); if (!b) return;
      img.src = `/assets/img/${p.images[b.dataset.i]}`;
      $('.product__dots .active', art)?.classList.remove('active');
      b.classList.add('active');
    });
    $('[data-act="details"]', art).addEventListener('click', () => openProduct(p.id));
    $('[data-act="buy"]', art).addEventListener('click', () => {
      addToCart(p.id);
    });
    return art;
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
          <button class="btn btn--primary btn--block" id="pmBuy">В корзину</button>
        </div>
      </div>`;
    const main = $('#pmMain', box);
    const mainImg = main.querySelector('img');
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
    main.addEventListener('touchstart', (e) => { touchStartX = e.touches[0].clientX; });
    main.addEventListener('touchend', (e) => {
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
    openModal('#productModal');
  }

  /* ---------- корзина ---------- */
  function addToCart(id, color) {
    const p = product(id);
    if (!p) return;
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
    busy: false,
  };

  function openCheckout() {
    if (!cartLines().length) return;
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
      try {
        const res = await fetch('api/cdek/cities?q=' + encodeURIComponent(q));
        const data = await res.json();
        if (!res.ok) {
          list.innerHTML = '<button disabled style="color:var(--muted);cursor:default">Сервис СДЭК недоступен — попробуйте позже</button>';
          return;
        }
        const cities = Array.isArray(data) ? data : [];
        if (!cities.length) {
          list.innerHTML = '<button disabled style="color:var(--muted);cursor:default">Город не найден</button>';
          return;
        }
        list.innerHTML = cities.map((c, i) =>
          `<button data-i="${i}">${c.city}<span style="color:var(--muted)"> — ${c.region || ''}</span></button>`).join('');
        list._cities = cities;
      } catch {
        list.innerHTML = '<button disabled style="color:var(--muted);cursor:default">Ошибка СДЭК — попробуйте ещё раз</button>';
      }
    }, 280);

    cityInput.addEventListener('input', () => { ck.city = null; ck.delivery = null; search(); });
    cityInput.addEventListener('blur', () => { setTimeout(() => list.classList.remove('show'), 200); });
    list.addEventListener('click', (e) => {
      const b = e.target.closest('button'); if (!b) return;
      ck.city = list._cities[b.dataset.i];
      cityInput.value = ck.city.city;
      list.classList.remove('show');
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

    if (ck.city) loadPoints();

    $('#ckBack').addEventListener('click', () => { ck.step = 1; renderCheckout(); });
    $('#ckNext').addEventListener('click', () => {
      if (!ck.city) return toast('Выберите город из подсказки');
      if (ck.deliveryType === 'pvz' && !ck.pvz) return toast('Выберите пункт выдачи');
      if (ck.deliveryType === 'door') {
        ck.customer.address = $('#ckAddr').value.trim();
        if (ck.customer.address.length < 5) return toast('Укажите адрес доставки');
      }
      if (!ck.delivery) return toast('Дождитесь расчёта стоимости доставки');
      ck.step = 3;
      renderCheckout();
    });
  }

  async function loadPoints() {
    const sel = $('#ckPvz');
    if (!sel || !ck.city) return;
    sel.innerHTML = '<option value="">Загрузка пунктов…</option>';
    try {
      ck.points = await fetch('api/cdek/points?city_code=' + ck.city.code).then((r) => r.json());
      if (!ck.points.length) { sel.innerHTML = '<option value="">Нет ПВЗ в этом городе</option>'; return; }
      sel.innerHTML = '<option value="">Выберите пункт выдачи</option>' +
        ck.points.map((p) => `<option value="${p.code}">${p.name} — ${p.address || ''}</option>`).join('');
      if (ck.pvz) sel.value = ck.pvz.code;
    } catch {
      sel.innerHTML = '<option value="">Ошибка загрузки пунктов</option>';
    }
  }

  async function recalcDelivery() {
    const info = $('#ckDelivInfo');
    if (!ck.city || !info) return;
    ck.delivery = null;
    info.innerHTML = '<div class="hint">Считаем стоимость доставки…</div>';
    try {
      const r = await fetch('api/cdek/calculate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          deliveryType: ck.deliveryType,
          toCityCode: ck.city.code,
          items: cart.map((c) => ({ id: c.id, qty: c.qty })),
        }),
      }).then((r) => r.json());
      if (r.error) throw new Error(r.error);
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
    const lines = cartLines().map((l) =>
      `<div class="row"><span>${l.p.name}${l.color ? ', ' + l.color : ''} × ${l.qty}</span>
       <span>${money(l.p.price * l.qty)}</span></div>`).join('');
    return `
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
      <div id="ckSubmitErr"></div>
      <div class="ck__nav">
        <button class="btn btn--ghost" id="ckBack">Назад</button>
        <button class="btn btn--primary" id="ckSubmit">${ck.payment === 'online' ? 'Перейти к оплате' : 'Подтвердить заказ'}</button>
      </div>`;
  }

  function bindPayment() {
    $('#payChoice').addEventListener('change', (e) => {
      ck.payment = e.target.value;
      renderCheckout();
    });
    $('#ckBack').addEventListener('click', () => { ck.step = 2; renderCheckout(); });
    $('#ckSubmit').addEventListener('click', submitOrder);
  }

  async function submitOrder() {
    if (ck.busy) return;
    ck.busy = true;
    const btn = $('#ckSubmit');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';
    const errBox = $('#ckSubmitErr');
    errBox.innerHTML = '';
    try {
      const res = await fetch('api/orders', {
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
        }),
      }).then((r) => r.json());

      if (res.errors) throw new Error(res.errors.join('. '));
      if (res.error) throw new Error(res.error);

      cart = [];
      saveCart();
      renderCart();

      if (res.paymentUrl) { window.location.href = res.paymentUrl; return; }
      if (res.redirect) { window.location.href = res.redirect; return; }
      showOrderDone(res.orderId);
    } catch (e) {
      errBox.innerHTML = `<div class="ck__err">${esc(e.message)}</div>`;
      btn.disabled = false;
      btn.textContent = ck.payment === 'online' ? 'Перейти к оплате' : 'Подтвердить заказ';
    } finally {
      ck.busy = false;
    }
  }

  function showOrderDone(id) {
    $('#checkoutBox').innerHTML = `<button class="modal__close" data-close>✕</button>
      <div class="ck"><div class="ck__ok">
        <div class="big">🌲</div>
        <h3>Заказ ${esc(id || '')} принят</h3>
        <p style="color:var(--muted);margin-top:8px">Мы свяжемся с вами для подтверждения. Спасибо, что выбрали Skywood!</p>
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
      }
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        closeModal('#productModal');
        closeModal('#checkoutModal');
        closeCart();
      }
    });
  }

  document.addEventListener('DOMContentLoaded', init);
})();
