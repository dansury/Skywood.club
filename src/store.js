import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const file = join(root, 'data', 'orders.json');

let orders = [];
if (existsSync(file)) {
  try {
    orders = JSON.parse(readFileSync(file, 'utf8'));
  } catch {
    orders = [];
  }
}

function persist() {
  writeFileSync(file, JSON.stringify(orders, null, 2));
}

// Человекочитаемый номер заказа: SW + дата + порядковый.
function nextId() {
  const d = new Date();
  const stamp = `${d.getFullYear()}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getDate()).padStart(2, '0')}`;
  const todayCount = orders.filter((o) => String(o.id).includes(stamp)).length + 1;
  return `SW${stamp}-${String(todayCount).padStart(3, '0')}`;
}

export function createOrder(data) {
  const order = {
    id: nextId(),
    createdAt: new Date().toISOString(),
    status: 'new',
    ...data,
  };
  orders.push(order);
  persist();
  return order;
}

export function getOrder(id) {
  return orders.find((o) => o.id === id) || null;
}

export function updateOrder(id, patch) {
  const order = getOrder(id);
  if (!order) return null;
  Object.assign(order, patch);
  persist();
  return order;
}
