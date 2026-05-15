import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const file = join(root, 'data', 'products.json');

let products = [];

export function loadCatalog() {
  products = JSON.parse(readFileSync(file, 'utf8')).products;
  return products;
}

export function getProducts() {
  if (!products.length) loadCatalog();
  return products;
}

export function getProduct(id) {
  return getProducts().find((p) => p.id === id) || null;
}

loadCatalog();
