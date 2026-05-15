import { config } from '../config.js';

// Тарифы СДЭК — отправка со склада (ПВЗ) продавца.
export const TARIFFS = {
  pvz: 136, // Посылка склад-склад — выдача в ПВЗ
  door: 137, // Посылка склад-дверь — курьером до адреса
};

let tokenCache = { value: '', expiresAt: 0 };

async function getToken() {
  if (tokenCache.value && Date.now() < tokenCache.expiresAt) return tokenCache.value;
  const body = new URLSearchParams({
    grant_type: 'client_credentials',
    client_id: config.cdek.account,
    client_secret: config.cdek.securePassword,
  });
  const res = await fetch(`${config.cdek.api}/oauth/token`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body,
  });
  const data = await res.json();
  if (!data.access_token) throw new Error('CDEK: не удалось получить токен авторизации');
  tokenCache = {
    value: data.access_token,
    expiresAt: Date.now() + (Number(data.expires_in) || 3600) * 1000 - 60000,
  };
  return tokenCache.value;
}

async function api(path, { method = 'GET', query, body } = {}) {
  const token = await getToken();
  const url = new URL(`${config.cdek.api}${path}`);
  if (query) for (const [k, v] of Object.entries(query)) {
    if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
  }
  const res = await fetch(url, {
    method,
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
      ...(body ? { 'Content-Type': 'application/json' } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await res.json().catch(() => ({}));
  if (res.status >= 400) {
    const msg = data?.errors?.[0]?.message || data?.requests?.[0]?.errors?.[0]?.message || `CDEK ${res.status}`;
    const err = new Error(msg);
    err.cdek = data;
    throw err;
  }
  return data;
}

// Подсказка городов по названию.
export async function searchCities(query) {
  const list = await api('/location/cities', {
    query: { city: query, country_codes: 'RU', size: 12 },
  });
  return (Array.isArray(list) ? list : []).map((c) => ({
    code: c.code,
    city: c.city,
    region: c.region,
    fias: c.fias_guid,
    postalCode: c.postal_code,
  }));
}

// Пункты выдачи в городе.
export async function pickupPoints(cityCode) {
  const list = await api('/deliverypoints', {
    query: { city_code: cityCode, type: 'PVZ', country_code: 'RU' },
  });
  return (Array.isArray(list) ? list : []).map((p) => ({
    code: p.code,
    name: p.name,
    address: p.location?.address_full || p.location?.address,
    workTime: p.work_time,
    lat: p.location?.latitude,
    lon: p.location?.longitude,
  }));
}

function packagesFor(items) {
  let weight = 0;
  let maxL = 0, maxW = 0, sumH = 0;
  for (const it of items) {
    weight += (it.weightGrams || 1000) * it.qty;
    maxL = Math.max(maxL, it.packLength || 0);
    maxW = Math.max(maxW, it.packWidth || 0);
    sumH += (it.packHeight || 0) * it.qty;
  }
  return [{
    weight: weight || 1000,
    length: maxL || 50,
    width: maxW || 20,
    height: sumH || 20,
  }];
}

// Расчёт стоимости и срока доставки.
export async function calculate({ tariffCode, toCityCode, items }) {
  const data = await api('/calculator/tariff', {
    method: 'POST',
    body: {
      tariff_code: tariffCode,
      from_location: { code: config.cdek.senderCityCode || undefined, postal_code: config.cdek.senderPostalCode },
      to_location: { code: toCityCode },
      packages: packagesFor(items),
    },
  });
  return {
    cost: Math.round(Number(data.total_sum) || 0),
    periodMin: data.period_min,
    periodMax: data.period_max,
  };
}

// Создание заказа в СДЭК. Для наложенного платежа cod > 0 —
// СДЭК принимает эту сумму с получателя при выдаче.
export async function createOrder(order) {
  const cod = order.paymentMethod === 'cod';
  const codTotal = cod ? order.subtotal + order.deliveryCost : 0;

  const pkgItems = order.items.map((it) => ({
    name: it.name + (it.color ? `, ${it.color}` : ''),
    ware_key: `${it.id}${it.color ? '-' + it.color : ''}`.slice(0, 50),
    cost: it.price,
    payment: { value: cod ? it.price : 0 },
    weight: it.weightGrams || 1000,
    amount: it.qty,
  }));

  const pkg = packagesFor(order.items);
  const body = {
    tariff_code: order.tariffCode,
    shipment_point: config.cdek.shipmentPoint || undefined,
    delivery_recipient_cost: cod ? { value: order.deliveryCost } : undefined,
    recipient: {
      name: order.customer.name,
      phones: [{ number: order.customer.phone }],
      email: order.customer.email || undefined,
    },
    from_location: { code: config.cdek.senderCityCode || undefined, postal_code: config.cdek.senderPostalCode },
    packages: [{
      number: String(order.id),
      weight: pkg[0].weight,
      length: pkg[0].length,
      width: pkg[0].width,
      height: pkg[0].height,
      items: pkgItems,
    }],
  };

  if (order.deliveryType === 'pvz') {
    body.delivery_point = order.pvzCode;
  } else {
    body.to_location = {
      code: order.toCityCode,
      address: order.customer.address,
    };
  }
  // Сумма наложенного платежа на товары задаётся через items[].payment,
  // её сумма = codTotal без доставки; доставка добавляется delivery_recipient_cost.
  void codTotal;

  const data = await api('/orders', { method: 'POST', body });
  return {
    uuid: data?.entity?.uuid,
    raw: data,
  };
}
