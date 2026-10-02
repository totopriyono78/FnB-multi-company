/*
 * Stress test fitur POS — SelarasPOS+ (Railway, Singapura)
 *
 * Alur yang ditiru persis seperti layar kasir web (/pos):
 *   setup  : owner login → buat/ambil perangkat uji "K6Sn" → kode pairing → /devices/pair
 *            → /pos/staff → /pos/auth/pin → tutup shift lama → buka shift baru → /pos/catalog
 *   per VU : (kadang) muat ulang katalog → 1-3x /pos/quotes (keranjang berubah) → /pos/orders (tunai)
 *            → (kadang) daftar order shift / shift berjalan
 *   teardown: tutup semua shift uji, logout PIN.
 *
 * PERHATIAN: skrip ini MENULIS transaksi sungguhan ke database demo Railway
 * (customer_name "K6 STRESS TEST"). Gunakan hanya di lingkungan demo/staging.
 *
 * Pemakaian (PowerShell):
 *   k6 run -e PROFILE=smoke  pos-stress.js
 *   k6 run -e PROFILE=load   pos-stress.js
 *   k6 run -e PROFILE=stress pos-stress.js
 *   k6 run -e PROFILE=spike  pos-stress.js
 *   k6 run -e PROFILE=soak   pos-stress.js
 */
import http from 'k6/http';
import { check, sleep, fail } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';

// ---------------------------------------------------------------- konfigurasi
const BASE = (__ENV.BASE_URL || 'https://selarasposplus.up.railway.app').replace(/\/$/, '');
const API = BASE + '/api/v1';
const OWNER_LOGIN = __ENV.OWNER_LOGIN || 'rina@gtgroup.test';
const OWNER_PASSWORD = __ENV.OWNER_PASSWORD || 'Rahasia123';
const PROFILE = (__ENV.PROFILE || 'smoke').toLowerCase();
const THINK = Number(__ENV.THINK || 3); // detik jeda rata-rata antar transaksi per VU
const CHANNEL = __ENV.CHANNEL || 'take_away';
const TAG = 'K6 STRESS TEST';

/*
 * Satu sesi = satu perangkat uji + satu staf ber-PIN. Rate limiter API (300 req/menit)
 * dihitung PER USER, jadi makin banyak staf berbeda, makin tinggi beban yang bisa dikirim
 * sebelum server menjawab 429. Data demo punya 9 staf ber-PIN.
 */
const ALL_SESSIONS = [
  { outlet: 'KLU', staff: 'Andi', pin: '7351' },
  { outlet: 'KLU', staff: 'Dewi', pin: '482915' },
  { outlet: 'KLU', staff: 'Rina', pin: '802614' },
  { outlet: 'PRW', staff: 'Putri', pin: '3867' },
  { outlet: 'PRW', staff: 'Yohanes', pin: '615283' },
  { outlet: 'SRT', staff: 'Yusuf', pin: '4719' },
  { outlet: 'SRT', staff: 'Aminah', pin: '260418' },
  { outlet: 'MGL', staff: 'Hendra', pin: '5172' },
  { outlet: 'MGL', staff: 'Rizky', pin: '5836' },
];
const MAX_SESSIONS = Number(__ENV.SESSIONS || ALL_SESSIONS.length);

const PROFILES = {
  // Cek alur berjalan benar, 2 VU, 1 menit.
  smoke: { executor: 'constant-vus', vus: 2, duration: '1m' },
  // Beban jam sibuk normal: 3 VU per sesi (≈ 27 kasir aktif sekaligus).
  load: {
    executor: 'ramping-vus', startVUs: 0, gracefulRampDown: '30s',
    stages: [
      { duration: '1m', target: 9 },
      { duration: '2m', target: 27 },
      { duration: '5m', target: 27 },
      { duration: '1m', target: 0 },
    ],
  },
  // Naik bertahap sampai server mulai melambat / gagal.
  stress: {
    executor: 'ramping-vus', startVUs: 0, gracefulRampDown: '30s',
    stages: [
      { duration: '2m', target: 20 },
      { duration: '3m', target: 40 },
      { duration: '3m', target: 60 },
      { duration: '3m', target: 90 },
      { duration: '3m', target: 120 },
      { duration: '2m', target: 0 },
    ],
  },
  // Lonjakan mendadak (mis. jam makan siang serentak).
  spike: {
    executor: 'ramping-vus', startVUs: 0, gracefulRampDown: '30s',
    stages: [
      { duration: '1m', target: 10 },
      { duration: '20s', target: 120 },
      { duration: '2m', target: 120 },
      { duration: '20s', target: 10 },
      { duration: '1m', target: 10 },
      { duration: '30s', target: 0 },
    ],
  },
  // Ketahanan: beban sedang, lama (cari kebocoran memori / koneksi DB).
  soak: { executor: 'constant-vus', vus: 27, duration: __ENV.SOAK_DURATION || '30m' },
};

if (!PROFILES[PROFILE]) fail(`PROFILE tidak dikenal: ${PROFILE}`);

export const options = {
  setupTimeout: '10m',
  teardownTimeout: '5m',
  scenarios: { kasir: Object.assign({ exec: 'kasir' }, PROFILES[PROFILE]) },
  thresholds: {
    // Kegagalan nyata (5xx, 4xx selain 429). 429 dihitung terpisah di metrik `throttled`.
    http_req_failed: ['rate<0.01'],
    tx_success: ['rate>0.98'],
    'http_req_duration{name:pos_quote}': ['p(95)<800'],
    'http_req_duration{name:pos_order}': ['p(95)<1500'],
    'http_req_duration{name:pos_catalog}': ['p(95)<2000'],
    'http_req_duration{name:pos_shift_orders}': ['p(95)<1500'],
    'http_req_duration{name:pos_shift_current}': ['p(95)<2000'],
    tx_duration: ['p(95)<4000'],
  },
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

// 429 bukan kegagalan server — ia rate limiter yang bekerja. Dicatat sendiri.
http.setResponseCallback(http.expectedStatuses({ min: 200, max: 299 }, 429));

// ---------------------------------------------------------------- metrik
const txSuccess = new Rate('tx_success');
const txDuration = new Trend('tx_duration', true);
const throttled = new Counter('throttled_429');
const serverErrors = new Counter('server_errors_5xx');
const bizErrors = new Counter('business_errors');
const ordersCreated = new Counter('orders_created');

// ---------------------------------------------------------------- utilitas
function uuid() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });
}
const pick = (arr) => arr[Math.floor(Math.random() * arr.length)];
const randInt = (a, b) => a + Math.floor(Math.random() * (b - a + 1));

function headers(token, extra) {
  const h = { Accept: 'application/json', 'Content-Type': 'application/json' };
  if (token) h.Authorization = 'Bearer ' + token;
  return Object.assign(h, extra || {});
}

function call(method, path, { token, body, name, extraHeaders } = {}) {
  const params = { headers: headers(token, extraHeaders), tags: { name: name || path }, timeout: '60s' };
  const payload = body === undefined ? null : JSON.stringify(body);
  const res = http.request(method, API + path, payload, params);
  if (res.status === 429) throttled.add(1, { name: params.tags.name });
  if (res.status >= 500 || res.status === 0) serverErrors.add(1, { name: params.tags.name });
  let json = null;
  try { json = res.json(); } catch (e) { /* bukan JSON */ }
  const ok = res.status >= 200 && res.status < 300 && json && json.success !== false;
  const err = json && json.errors && json.errors[0] ? json.errors[0] : {};
  return { res, ok, data: json ? json.data : null, code: err.code || null, message: err.message || null, status: res.status };
}

function must(r, what) {
  if (!r.ok) fail(`${what} gagal: HTTP ${r.status} ${r.code || ''} ${r.message || String(r.res.body || '').slice(0, 300)}`);
  return r.data;
}

/** Item yang bisa langsung masuk keranjang tanpa dialog (sama dengan pickItem() di layar kasir). */
function simpleItems(catalog) {
  const groups = {};
  (catalog.modifier_groups || []).forEach((g) => { groups[g.id] = g; });
  return (catalog.items || []).filter((i) =>
    !i.sold_out
    && i.type !== 'bundle'
    && !((i.variants || []).length > 1)
    && !(i.modifier_group_ids || []).some((g) => groups[g]));
}

// ---------------------------------------------------------------- setup
export function setup() {
  console.log(`Target ${BASE} · profil ${PROFILE}`);

  // 1. Owner login (rate limit login: 10/menit per IP — setup sengaja pelan).
  const login = must(call('POST', '/auth/login', {
    body: { login: OWNER_LOGIN, password: OWNER_PASSWORD, device_name: 'k6-stress' }, name: 'auth_login',
  }), 'Login owner');
  const ownerToken = login.token;
  const companies = (login.user && login.user.companies) || [];
  if (!companies.length) fail('Owner tidak punya company.');
  const companyId = __ENV.COMPANY_ID || companies[0].id;
  const bo = { token: ownerToken, extraHeaders: { 'X-Company-Id': companyId } };

  // 2. Outlet & perangkat yang sudah ada.
  const outlets = must(call('GET', '/outlets?per_page=100', Object.assign({ name: 'bo_outlets' }, bo)), 'Daftar outlet');
  const outletByCode = {};
  outlets.forEach((o) => { outletByCode[o.code] = o; });
  const devices = must(call('GET', '/devices?per_page=100', Object.assign({ name: 'bo_devices' }, bo)), 'Daftar perangkat');

  const plan = ALL_SESSIONS.slice(0, MAX_SESSIONS);
  const perOutletIdx = {};
  const sessions = [];

  plan.forEach((p, idx) => {
    const outlet = outletByCode[p.outlet];
    if (!outlet) { console.warn(`Outlet ${p.outlet} tidak ada — sesi ${p.staff} dilewati.`); return; }
    perOutletIdx[p.outlet] = (perOutletIdx[p.outlet] || 0) + 1;
    const code = 'K6S' + perOutletIdx[p.outlet];

    // 3. Perangkat uji: buat bila belum ada, lalu minta kode pairing baru.
    let device = devices.find((d) => d.code === code && (d.outlet_id === outlet.id || (d.outlet && d.outlet.id === outlet.id)));
    let pairCode;
    if (device && device.status === 'revoked') device = null;
    if (!device) {
      const created = must(call('POST', '/devices', Object.assign({
        name: 'devices_create',
        body: { outlet_id: outlet.id, code, name: `K6 Stress ${p.outlet} ${perOutletIdx[p.outlet]}`, type: 'pos' },
      }, bo)), `Buat perangkat ${p.outlet}/${code}`);
      device = created.device;
      pairCode = created.pairing.code;
    } else {
      pairCode = must(call('POST', `/devices/${device.id}/pairing-code`, Object.assign({ name: 'devices_pairing_code' }, bo)),
        `Kode pairing ${p.outlet}/${code}`).code;
    }

    // 4. Pairing (rate limit 10/menit per IP → jeda 7 detik).
    sleep(7);
    const paired = must(call('POST', '/devices/pair', {
      name: 'devices_pair', body: { code: pairCode, platform: 'web', app_version: 'k6' },
    }), `Pairing ${p.outlet}/${code}`);
    const devToken = paired.token;

    // 5. PIN login staf.
    const staff = must(call('GET', '/pos/staff', { token: devToken, name: 'pos_staff' }), 'Daftar staf');
    const member = staff.find((s) => s.name.toLowerCase().includes(p.staff.toLowerCase()));
    if (!member) { console.warn(`Staf ${p.staff} tidak tersedia di ${p.outlet} — dilewati.`); return; }
    const pin = call('POST', '/pos/auth/pin', { token: devToken, name: 'pos_pin', body: { staff_id: member.id, pin: p.pin } });
    if (!pin.ok) { console.warn(`PIN ${p.staff} ditolak (${pin.code} ${pin.message}) — dilewati.`); return; }
    const posToken = pin.data.token;
    const pos = { token: posToken };

    // 6. Shift bersih: tutup shift lama perangkat uji ini, buka yang baru.
    const cur = call('GET', '/pos/shifts/current', Object.assign({ name: 'pos_shift_current' }, pos)).data;
    if (cur && cur.id) {
      const expected = ((cur.report || {}).cash || {}).expected || '0';
      call('POST', `/pos/shifts/${cur.id}/close`, Object.assign({
        name: 'pos_shift_close', body: { id: uuid(), counted_cash: Number(expected).toFixed(2), variance_note: TAG },
      }, pos));
    }
    must(call('POST', '/pos/shifts', Object.assign({ name: 'pos_shift_open', body: { id: uuid(), opening_cash: '500000.00' } }, pos)),
      `Buka shift ${p.staff}`);
    const shift = must(call('GET', '/pos/shifts/current', Object.assign({ name: 'pos_shift_current' }, pos)), 'Shift berjalan');

    // 7. Katalog & item sederhana.
    const catalog = must(call('GET', '/pos/catalog', Object.assign({ name: 'pos_catalog' }, pos)), 'Katalog');
    const items = simpleItems(catalog).slice(0, 25).map((i) => {
      const v = (i.variants || []).find((x) => x.is_default) || (i.variants || [])[0];
      return { item_id: i.id, variant_id: v ? v.id : null, name: i.name };
    });
    if (!items.length) { console.warn(`Tidak ada item sederhana di ${p.outlet} — dilewati.`); return; }
    const channel = (catalog.channels || []).find((c) => c.code === CHANNEL) || (catalog.channels || [])[0];
    const pricing = Object.assign({}, catalog.outlet.pricing, { service_charge_applies: !!channel.service_charge_applies });

    sessions.push({
      label: `${p.outlet}/${code}/${member.name}`,
      outletCode: paired.outlet.code,
      deviceCode: paired.device.code,
      deviceId: device.id,
      devToken, posToken,
      shiftId: shift.id,
      businessDate: String(shift.business_date).slice(0, 10),
      baseSeq: Number((shift.report || {}).last_receipt_seq || 0),
      channel: channel.code,
      pricing, items,
    });
    console.log(`Sesi siap: ${p.outlet}/${code} · ${member.name} · ${items.length} item · shift ${shift.id}`);
  });

  if (!sessions.length) fail('Tidak ada sesi kasir yang siap.');
  console.log(`${sessions.length} sesi kasir siap.`);
  return { sessions, ownerToken, companyId };
}

// ---------------------------------------------------------------- skenario kasir
let seq = 0;

export function kasir(data) {
  const s = data.sessions[(__VU - 1) % data.sessions.length];
  const pos = { token: s.posToken };
  const t0 = Date.now();

  // Kasir sesekali memuat ulang katalog.
  if (Math.random() < 0.05) call('GET', '/pos/catalog', Object.assign({ name: 'pos_catalog' }, pos));

  // Keranjang: 1-4 item, harga dihitung ulang server setiap keranjang berubah.
  const lines = [];
  const nLines = randInt(1, 4);
  let quote = null;
  for (let i = 0; i < nLines; i++) {
    const it = pick(s.items);
    const line = { id: uuid(), item_id: it.item_id, qty: randInt(1, 3).toFixed(3) };
    if (it.variant_id) line.variant_id = it.variant_id;
    lines.push(line);
    // Quote dipanggil untuk tiap perubahan keranjang (maks 3x agar tidak berlebihan).
    if (i === nLines - 1 || i < 2) {
      const q = call('POST', '/pos/quotes', Object.assign({ name: 'pos_quote', body: { channel_code: s.channel, lines } }, pos));
      check(q, { 'quote 200': (r) => r.ok });
      if (!q.ok) { if (q.status !== 429) bizErrors.add(1, { code: q.code || String(q.status), step: 'quote' }); txSuccess.add(false); sleep(THINK); return; }
      quote = q.data;
    }
    sleep(Math.random() * 0.8); // waktu kasir menekan item berikutnya
  }

  // Bayar tunai & simpan order (body identik dengan layar kasir).
  const t = quote.totals;
  const now = new Date().toISOString();
  const orderLines = quote.lines.map((l) => ({
    id: l.id, item_id: l.item_id, variant_id: l.variant ? l.variant.id : null, name: l.name,
    qty: l.qty, unit_price: l.unit_price, note: null,
    modifiers: (l.modifiers || []).map((m) => ({ id: m.id, name: m.name, price: m.price, qty: m.qty })),
    bundle: (l.bundle || []).map((b) => ({ option_id: b.option_id, name: b.name || null, extra_price: b.extra_price || '0.00' })),
    discounts: (l.discounts || []).map((d) => ({ type: d.type, value: String(d.value), source: d.source, reason: d.reason || null })),
  }));
  const totals = {};
  ['subtotal', 'item_discount', 'order_discount', 'service_charge', 'tax', 'rounding', 'total'].forEach((k) => { totals[k] = t[k]; });

  if (seq === 0) seq = s.baseSeq + (__VU - 1) * 3000; // rentang nomor struk unik per VU
  const orderId = uuid();
  let saved = null;
  for (let attempt = 0; attempt < 3; attempt++) {
    seq += 1;
    const receiptNo = `${s.outletCode}-${s.deviceCode}-${s.businessDate.replace(/-/g, '').slice(2)}-${String(seq).padStart(4, '0')}`;
    const r = call('POST', '/pos/orders', Object.assign({
      name: 'pos_order',
      body: {
        id: orderId, shift_id: s.shiftId, receipt_no: receiptNo, channel_code: s.channel,
        table_label: null, open_bill_id: null, customer_name: TAG, queue_no: null, note: TAG,
        status: 'paid', created_at: now, completed_at: now, pricing: s.pricing,
        lines: orderLines, totals, order_discounts: (quote.order_discounts || []).map((d) => ({
          type: d.type, value: String(d.value), source: d.source, reason: d.reason || null })),
        payments: [{ id: uuid(), method: 'cash', amount: t.total, tendered: t.total, created_at: now }],
      },
    }, pos));
    if (r.ok) { saved = r; break; }
    if (r.code === 'DUPLICATE_RECEIPT_NO') { seq += 500; continue; }
    if (r.status !== 429) bizErrors.add(1, { code: r.code || String(r.status), step: 'order' });
    if (r.status !== 429 && __ENV.DEBUG) console.warn(`order gagal ${r.status} ${r.code} ${r.message}`);
    break;
  }
  check(saved, { 'order tersimpan': (r) => r !== null });
  txSuccess.add(saved !== null);
  if (saved) { ordersCreated.add(1); txDuration.add(Date.now() - t0); }

  // Kasir melihat riwayat / ringkasan shift sesekali.
  if (Math.random() < 0.15) call('GET', `/pos/shifts/${s.shiftId}/orders`, Object.assign({ name: 'pos_shift_orders' }, pos));
  if (Math.random() < 0.05) call('GET', '/pos/shifts/current', Object.assign({ name: 'pos_shift_current' }, pos));

  sleep(THINK * (0.5 + Math.random()));
}

// ---------------------------------------------------------------- teardown
export function teardown(data) {
  (data.sessions || []).forEach((s) => {
    const pos = { token: s.posToken };
    const cur = call('GET', '/pos/shifts/current', Object.assign({ name: 'pos_shift_current' }, pos)).data;
    if (cur && cur.id) {
      const expected = ((cur.report || {}).cash || {}).expected || '0';
      const r = call('POST', `/pos/shifts/${cur.id}/close`, Object.assign({
        name: 'pos_shift_close', body: { id: uuid(), counted_cash: Number(expected).toFixed(2), variance_note: TAG },
      }, pos));
      console.log(`Tutup shift ${s.label}: ${r.ok ? 'OK' : r.status + ' ' + r.code}` +
        ` · order ${((cur.report || {}).order_count) || 0}`);
    }
    call('POST', '/pos/auth/logout', Object.assign({ name: 'pos_logout' }, pos));
  });
  if (__ENV.CLEANUP === 'revoke') {
    const bo = { token: data.ownerToken, extraHeaders: { 'X-Company-Id': data.companyId } };
    (data.sessions || []).forEach((s) => call('POST', `/devices/${s.deviceId}/revoke`, Object.assign({ name: 'devices_revoke' }, bo)));
    console.log('Perangkat uji dinonaktifkan.');
  }
}
