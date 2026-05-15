import { Router } from 'express';
import { config } from '../config.js';
import { getProduct } from '../catalog.js';
import { createOrder, getOrder, updateOrder } from '../store.js';
import { createOrder as cdekCreateOrder, TARIFFS } from '../services/cdek.js';
import { initPayment } from '../services/tinkoff.js';

export const ordersRouter = Router();

const PHONE_RE = /^\+?[0-9\s\-()]{10,18}$/;

function validate(body) {
  const errors = [];
  const items = [];
  for (const r of Array.isArray(body?.items) ? body.items : []) {
    const p = getProduct(r.id);
    if (!p) continue;
    if (!p.available) {
      errors.push(`Товар «${p.name}» сейчас недоступен`);
      continue;
    }
    const qty = Math.max(1, Math.min(10, Number(r.qty) || 1));
    const color = p.options?.color?.length
      ? (p.options.color.includes(r.color) ? r.color : p.options.color[0])
      : '';
    items.push({
      id: p.id, name: p.name, price: p.price, qty, color,
      weightGrams: p.weightGrams, packLength: p.packLength,
      packWidth: p.packWidth, packHeight: p.packHeight,
    });
  }
  if (!items.length) errors.push('Корзина пуста');

  const c = body?.customer || {};
  const customer = {
    name: String(c.name || '').trim(),
    phone: String(c.phone || '').trim(),
    email: String(c.email || '').trim(),
    address: String(c.address || '').trim(),
    comment: String(c.comment || '').trim(),
  };
  if (customer.name.length < 2) errors.push('Укажите имя получателя');
  if (!PHONE_RE.test(customer.phone)) errors.push('Укажите корректный телефон');

  const d = body?.delivery || {};
  const deliveryType = d.type === 'door' ? 'door' : 'pvz';
  const toCityCode = Number(d.cityCode);
  if (!toCityCode) errors.push('Выберите город доставки');
  if (deliveryType === 'pvz' && !d.pvzCode) errors.push('Выберите пункт выдачи СДЭК');
  if (deliveryType === 'door' && customer.address.length < 5) errors.push('Укажите адрес доставки');

  const paymentMethod = body?.paymentMethod === 'cod' ? 'cod' : 'online';
  const deliveryCost = Math.max(0, Math.round(Number(d.cost) || 0));

  return {
    errors, items, customer, paymentMethod, deliveryCost,
    deliveryType, toCityCode,
    cityName: String(d.cityName || '').trim(),
    pvzCode: d.pvzCode ? String(d.pvzCode) : '',
    pvzName: String(d.pvzName || '').trim(),
    tariffCode: deliveryType === 'door' ? TARIFFS.door : TARIFFS.pvz,
  };
}

ordersRouter.post('/', async (req, res, next) => {
  const v = validate(req.body);
  if (v.errors.length) return res.status(400).json({ errors: v.errors });

  const subtotal = v.items.reduce((s, it) => s + it.price * it.qty, 0);
  const total = subtotal + v.deliveryCost;

  const order = createOrder({
    items: v.items,
    customer: v.customer,
    deliveryType: v.deliveryType,
    toCityCode: v.toCityCode,
    cityName: v.cityName,
    pvzCode: v.pvzCode,
    pvzName: v.pvzName,
    tariffCode: v.tariffCode,
    deliveryCost: v.deliveryCost,
    subtotal,
    total,
    paymentMethod: v.paymentMethod,
    payment: { status: v.paymentMethod === 'cod' ? 'cod_pending' : 'pending' },
    cdek: { status: 'pending' },
  });

  // Регистрация заказа в СДЭК (склад-склад / склад-дверь, при cod — наложенный платёж).
  let cdek;
  if (config.cdek.enabled) {
    try {
      const r = await cdekCreateOrder(order);
      cdek = { status: 'created', uuid: r.uuid };
    } catch (e) {
      cdek = { status: 'error', error: e.message };
    }
  } else {
    cdek = { status: 'demo' };
  }

  // Оплата при получении — заказ сразу подтверждён.
  if (v.paymentMethod === 'cod') {
    updateOrder(order.id, { cdek, status: 'confirmed' });
    return res.json({ ok: true, orderId: order.id, redirect: `/order/success?order=${order.id}` });
  }

  // Онлайн-оплата картой через Т-Банк.
  if (!config.tinkoff.enabled) {
    updateOrder(order.id, { cdek, payment: { status: 'demo' } });
    return res.json({ ok: true, orderId: order.id, redirect: `/order/success?order=${order.id}&demo=1` });
  }
  try {
    const pay = await initPayment(order);
    updateOrder(order.id, { cdek, payment: { status: 'pending', paymentId: pay.paymentId } });
    return res.json({ ok: true, orderId: order.id, paymentUrl: pay.paymentUrl });
  } catch (e) {
    updateOrder(order.id, { cdek });
    next(e);
  }
});

ordersRouter.get('/:id', (req, res) => {
  const order = getOrder(req.params.id);
  if (!order) return res.status(404).json({ error: 'Заказ не найден' });
  res.json({
    id: order.id,
    status: order.status,
    total: order.total,
    paymentMethod: order.paymentMethod,
    payment: order.payment,
  });
});
