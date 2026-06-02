/* Skywood — уведомление о cookie (152-ФЗ / e-Privacy).
   Показывает баннер один раз, пока пользователь не нажмёт «Принять».
   Согласие хранится в localStorage и в cookie (1 год). */
(() => {
  'use strict';
  const KEY = 'sw_cookie_consent';
  try { if (localStorage.getItem(KEY) === '1') return; } catch { /* приватный режим */ }
  if (document.cookie.indexOf(KEY + '=1') !== -1) return;

  function accept() {
    try { localStorage.setItem(KEY, '1'); } catch { /* игнор */ }
    document.cookie = KEY + '=1; Max-Age=' + (60 * 60 * 24 * 365) + '; Path=/; SameSite=Lax';
    if (bar && bar.parentNode) bar.parentNode.removeChild(bar);
  }

  const bar = document.createElement('div');
  bar.className = 'cookie-bar';
  bar.setAttribute('role', 'dialog');
  bar.setAttribute('aria-label', 'Уведомление о cookie');
  bar.innerHTML =
    '<p>Мы используем cookie и сохраняем введённые вами данные, чтобы сайт работал' +
    ' и оформление заказа было удобнее. Оставаясь на сайте, вы соглашаетесь с' +
    ' <a href="privacy.html" target="_blank" rel="noopener">политикой конфиденциальности</a>.</p>' +
    '<button type="button" class="cookie-bar__btn">Принять</button>';

  const mount = () => {
    document.body.appendChild(bar);
    bar.querySelector('.cookie-bar__btn').addEventListener('click', accept);
  };
  if (document.body) mount();
  else document.addEventListener('DOMContentLoaded', mount);
})();
