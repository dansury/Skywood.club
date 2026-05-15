/* Skywood — параллакс, анимации появления, навигация */
(() => {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const $$ = (s) => [...document.querySelectorAll(s)];

  /* ---------- шапка: фон при прокрутке ---------- */
  const header = $('#header');
  const onScroll = () => header.classList.toggle('scrolled', window.scrollY > 40);
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });

  /* ---------- мобильное меню ---------- */
  const burger = $('#burger');
  const nav = $('#nav');
  const toggleNav = (open) => {
    nav.classList.toggle('open', open);
    burger.classList.toggle('open', open);
  };
  burger.addEventListener('click', () => toggleNav(!nav.classList.contains('open')));
  nav.addEventListener('click', (e) => { if (e.target.tagName === 'A') toggleNav(false); });

  /* ---------- плавная прокрутка с учётом шапки ---------- */
  $$('a[href^="#"]').forEach((a) => {
    a.addEventListener('click', (e) => {
      const id = a.getAttribute('href');
      if (id === '#' || id.length < 2) return;
      const t = document.querySelector(id);
      if (!t) return;
      e.preventDefault();
      const y = t.getBoundingClientRect().top + window.scrollY - 70;
      window.scrollTo({ top: y, behavior: 'smooth' });
    });
  });

  /* ---------- появление блоков ---------- */
  const reduce = window.matchMedia('(prefers-reduced-motion:reduce)').matches;
  if (reduce) {
    $$('.reveal').forEach((el) => el.classList.add('in'));
  } else {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((en) => {
        if (en.isIntersecting) {
          en.target.classList.add('in');
          io.unobserve(en.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

    const watch = () => $$('.reveal:not(.in)').forEach((el) => io.observe(el));
    watch();
    // карточки товаров рендерятся асинхронно — наблюдаем их после загрузки
    new MutationObserver(watch).observe($('#productGrid'), { childList: true });
  }

  /* ---------- параллакс героя ---------- */
  if (!reduce) {
    const layers = $$('[data-parallax]');
    let ticking = false;
    const apply = () => {
      const y = window.scrollY;
      layers.forEach((el) => {
        const k = parseFloat(el.dataset.parallax) || 0.2;
        el.style.transform = `translate3d(0, ${y * k}px, 0)`;
        el.style.opacity = String(Math.max(0, 1 - y / 620));
      });
      ticking = false;
    };
    window.addEventListener('scroll', () => {
      if (!ticking) { requestAnimationFrame(apply); ticking = true; }
    }, { passive: true });
    apply();
  }

  /* ---------- видео в шапке: подстраховка автозапуска ---------- */
  const video = $('.hero__video');
  if (video) {
    const tryPlay = () => {
      const pr = video.play();
      if (pr && pr.catch) pr.catch(() => {});
    };
    tryPlay();
    document.addEventListener('click', tryPlay, { once: true });
    // если файл видео отсутствует — постер уже показан, скрываем пустой <video>
    video.addEventListener('error', () => { video.style.display = 'none'; }, true);
  }
})();
