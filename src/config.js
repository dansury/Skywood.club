import 'dotenv/config';

const env = process.env;
const port = Number(env.PORT) || 3000;

export const config = {
  port,
  baseUrl: (env.BASE_URL || `http://localhost:${port}`).replace(/\/$/, ''),

  tinkoff: {
    terminalKey: env.TINKOFF_TERMINAL_KEY || '',
    password: env.TINKOFF_PASSWORD || '',
    api: (env.TINKOFF_API || 'https://securepay.tinkoff.ru/v2').replace(/\/$/, ''),
    taxation: env.TINKOFF_TAXATION || 'usn_income',
    vat: env.TINKOFF_VAT || 'none',
    get enabled() {
      return Boolean(this.terminalKey && this.password);
    },
  },

  cdek: {
    account: env.CDEK_ACCOUNT || '',
    securePassword: env.CDEK_SECURE_PASSWORD || '',
    api: (env.CDEK_API || 'https://api.cdek.ru/v2').replace(/\/$/, ''),
    senderCityCode: Number(env.CDEK_SENDER_CITY_CODE) || 0,
    senderPostalCode: env.CDEK_SENDER_POSTAL_CODE || '143405',
    shipmentPoint: env.CDEK_SHIPMENT_POINT || '',
    get enabled() {
      return Boolean(this.account && this.securePassword);
    },
  },

  // Реквизиты продавца — выводятся в футере и в чеке.
  company: {
    legalName: 'ИП Сурков К.А.',
    inn: '773135420168',
    address: 'г. Красногорск, ул. Пришвина, д. 11',
    phone: '+7 977 508-45-85',
    phoneRaw: '+79775084585',
    email: 'info@skywood.club',
    telegram: 'https://t.me/skywood_club',
    whatsapp: 'https://wa.me/79775084585',
  },
};

// true — если ни один из платёжных/доставочных API не настроен.
// В этом режиме сайт работает с расчётными заглушками, чтобы его можно было смотреть локально.
export const demoMode = !config.tinkoff.enabled || !config.cdek.enabled;
