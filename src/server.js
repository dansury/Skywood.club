import express from 'express';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { config, demoMode } from './config.js';
import { getProducts } from './catalog.js';
import { cdekRouter } from './routes/cdek.js';
import { ordersRouter } from './routes/orders.js';
import { paymentRouter } from './routes/payment.js';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const publicDir = join(root, 'public');

const app = express();
app.use(express.json({ limit: '256kb' }));
app.use(express.urlencoded({ extended: true }));

// Каталог + публичная конфигурация для фронтенда.
app.get('/api/products', (_req, res) => {
  res.json({
    products: getProducts(),
    company: config.company,
    demo: demoMode,
  });
});

app.use('/api/cdek', cdekRouter);
app.use('/api/orders', ordersRouter);
app.use('/api/payment', paymentRouter);

app.use(express.static(publicDir, { extensions: ['html'] }));

// Страницы результата оплаты.
app.get(['/order/success', '/order/fail'], (_req, res) => {
  res.sendFile(join(publicDir, 'order.html'));
});

app.use((_req, res) => res.status(404).sendFile(join(publicDir, 'index.html')));

// eslint-disable-next-line no-unused-vars
app.use((err, _req, res, _next) => {
  console.error('[error]', err.message);
  res.status(500).json({ error: err.message || 'Внутренняя ошибка сервера' });
});

app.listen(config.port, () => {
  console.log(`Skywood.club → http://localhost:${config.port}`);
  if (demoMode) {
    console.log('Режим ДЕМО: укажите доступы Т-Банк и СДЭК в .env для боевой работы.');
  }
});
