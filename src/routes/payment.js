import { Router } from 'express';
import { verifyNotification } from '../services/tinkoff.js';
import { getOrder, updateOrder } from '../store.js';

export const paymentRouter = Router();

// Webhook Т-Банк: уведомление о смене статуса платежа.
paymentRouter.post('/notify', (req, res) => {
  const body = req.body || {};
  if (!verifyNotification(body)) {
    return res.status(400).send('BAD TOKEN');
  }
  const order = getOrder(body.OrderId);
  if (order) {
    const paid = body.Status === 'CONFIRMED' || body.Status === 'AUTHORIZED';
    updateOrder(order.id, {
      status: paid ? 'paid' : order.status,
      payment: {
        ...order.payment,
        status: body.Status,
        paymentId: body.PaymentId,
        updatedAt: new Date().toISOString(),
      },
    });
  }
  // Т-Банк ожидает ответ "OK" при успешной обработке.
  res.send('OK');
});
