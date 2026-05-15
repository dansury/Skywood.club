import { Router } from 'express';
import { config } from '../config.js';
import { getProduct } from '../catalog.js';
import { TARIFFS, searchCities, pickupPoints, calculate } from '../services/cdek.js';

export const cdekRouter = Router();

// Демо-данные для локального просмотра без доступов СДЭК.
const DEMO_CITIES = [
  { code: 44, city: 'Москва', region: 'Москва', postalCode: '101000' },
  { code: 137, city: 'Санкт-Петербург', region: 'Санкт-Петербург', postalCode: '190000' },
  { code: 270, city: 'Новосибирск', region: 'Новосибирская область', postalCode: '630000' },
  { code: 250, city: 'Екатеринбург', region: 'Свердловская область', postalCode: '620000' },
  { code: 428, city: 'Казань', region: 'Республика Татарстан', postalCode: '420000' },
  { code: 35, city: 'Краснодар', region: 'Краснодарский край', postalCode: '350000' },
];

function resolveItems(rawItems) {
  const items = [];
  for (const r of Array.isArray(rawItems) ? rawItems : []) {
    const p = getProduct(r.id);
    if (!p) continue;
    const qty = Math.max(1, Math.min(10, Number(r.qty) || 1));
    items.push({ ...p, qty });
  }
  return items;
}

cdekRouter.get('/cities', async (req, res, next) => {
  const q = String(req.query.q || '').trim();
  if (q.length < 2) return res.json([]);
  try {
    if (!config.cdek.enabled) {
      const ql = q.toLowerCase();
      return res.json(DEMO_CITIES.filter((c) => c.city.toLowerCase().startsWith(ql)));
    }
    res.json(await searchCities(q));
  } catch (e) {
    next(e);
  }
});

cdekRouter.get('/points', async (req, res, next) => {
  const cityCode = Number(req.query.city_code);
  if (!cityCode) return res.status(400).json({ error: 'city_code обязателен' });
  try {
    if (!config.cdek.enabled) {
      return res.json([
        { code: `DEMO-${cityCode}-1`, name: 'ПВЗ СДЭК на Центральной', address: 'ул. Центральная, 1', workTime: 'Пн-Пт 10:00-20:00, Сб 10:00-18:00' },
        { code: `DEMO-${cityCode}-2`, name: 'ПВЗ СДЭК в ТЦ «Маяк»', address: 'пр. Ленина, 42, 2 этаж', workTime: 'Ежедневно 10:00-21:00' },
      ]);
    }
    res.json(await pickupPoints(cityCode));
  } catch (e) {
    next(e);
  }
});

cdekRouter.post('/calculate', async (req, res, next) => {
  const { deliveryType, toCityCode } = req.body || {};
  const items = resolveItems(req.body?.items);
  if (!toCityCode || !items.length) {
    return res.status(400).json({ error: 'Укажите город и состав заказа' });
  }
  const tariffCode = deliveryType === 'door' ? TARIFFS.door : TARIFFS.pvz;
  try {
    if (!config.cdek.enabled) {
      const grams = items.reduce((s, it) => s + it.weightGrams * it.qty, 0);
      const cost = 250 + Math.ceil(grams / 1000) * 120 + (deliveryType === 'door' ? 150 : 0);
      return res.json({ cost, periodMin: 2, periodMax: 5, demo: true, tariffCode });
    }
    const r = await calculate({ tariffCode, toCityCode: Number(toCityCode), items });
    res.json({ ...r, tariffCode });
  } catch (e) {
    next(e);
  }
});
