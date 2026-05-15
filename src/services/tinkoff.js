import { createHash } from 'node:crypto';
import { config } from '../config.js';

// Подпись запроса по правилам Т-Банк (EACQ):
// берём корневые строковые/числовые параметры (без Receipt, DATA, Token),
// добавляем Password, сортируем по ключу, конкатенируем значения, SHA-256.
function makeToken(params) {
  const pairs = { ...params, Password: config.tinkoff.password };
  const value = Object.keys(pairs)
    .filter((k) => !['Receipt', 'DATA', 'Token', 'Shops'].includes(k))
    .filter((k) => pairs[k] !== undefined && pairs[k] !== null && typeof pairs[k] !== 'object')
    .sort()
    .map((k) => String(pairs[k]))
    .join('');
  return createHash('sha256').update(value).digest('hex');
}

async function call(method, payload) {
  const res = await fetch(`${config.tinkoff.api}/${method}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await res.json();
  if (!data.Success && data.ErrorCode && data.ErrorCode !== '0') {
    const err = new Error(data.Message || data.Details || `Tinkoff ${method} error ${data.ErrorCode}`);
    err.tinkoff = data;
    throw err;
  }
  return data;
}

// Чек 54-ФЗ: позиции заказа + доставка отдельной строкой.
function buildReceipt(order) {
  const items = order.items.map((it) => ({
    Name: it.name.slice(0, 128),
    Price: Math.round(it.price * 100),
    Quantity: it.qty,
    Amount: Math.round(it.price * 100) * it.qty,
    Tax: config.tinkoff.vat,
    PaymentMethod: 'full_prepayment',
    PaymentObject: 'commodity',
  }));
  if (order.deliveryCost > 0) {
    items.push({
      Name: 'Доставка СДЭК',
      Price: Math.round(order.deliveryCost * 100),
      Quantity: 1,
      Amount: Math.round(order.deliveryCost * 100),
      Tax: config.tinkoff.vat,
      PaymentMethod: 'full_prepayment',
      PaymentObject: 'service',
    });
  }
  const receipt = { Taxation: config.tinkoff.taxation, Items: items };
  if (order.customer.email) receipt.Email = order.customer.email;
  if (order.customer.phone) receipt.Phone = order.customer.phone;
  return receipt;
}

// Создание платежа. Возвращает { paymentId, paymentUrl }.
export async function initPayment(order) {
  const params = {
    TerminalKey: config.tinkoff.terminalKey,
    Amount: Math.round(order.total * 100),
    OrderId: order.id,
    Description: `Заказ №${order.id} — Skywood.club`,
    NotificationURL: `${config.baseUrl}/api/payment/notify`,
    SuccessURL: `${config.baseUrl}/order/success?order=${order.id}`,
    FailURL: `${config.baseUrl}/order/fail?order=${order.id}`,
  };
  const payload = {
    ...params,
    Token: makeToken(params),
    DATA: { Phone: order.customer.phone, Email: order.customer.email || '' },
    Receipt: buildReceipt(order),
  };
  const data = await call('Init', payload);
  return { paymentId: data.PaymentId, paymentUrl: data.PaymentURL, status: data.Status };
}

export async function getState(paymentId) {
  const params = { TerminalKey: config.tinkoff.terminalKey, PaymentId: paymentId };
  return call('GetState', { ...params, Token: makeToken(params) });
}

// Проверка подписи входящего уведомления от Т-Банк.
export function verifyNotification(body) {
  if (!body || typeof body !== 'object') return false;
  const { Token, ...rest } = body;
  return Boolean(Token) && makeToken(rest) === Token;
}
