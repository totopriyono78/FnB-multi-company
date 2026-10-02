/*
 * Stress test lapisan web (tanpa login) — mengukur kapasitas mentah server PHP di Railway,
 * tanpa dibatasi rate limiter per user seperti API POS.
 *
 *   /up          : health check Laravel (paling ringan — batas atas throughput)
 *   /pos         : halaman aplikasi kasir (Blade, dibuka setiap kasir memulai hari)
 *   /admin/login : halaman login back-office (Filament, lebih berat)
 *
 * Pemakaian: k6 run -e PROFILE=stress web-stress.js   (smoke | load | stress | spike)
 */
import http from 'k6/http';
import { check, sleep, fail } from 'k6';

const BASE = (__ENV.BASE_URL || 'https://selarasposplus.up.railway.app').replace(/\/$/, '');
const PROFILE = (__ENV.PROFILE || 'smoke').toLowerCase();

const PROFILES = {
  smoke: { executor: 'constant-vus', vus: 2, duration: '30s' },
  load: { executor: 'constant-arrival-rate', rate: 20, timeUnit: '1s', duration: '3m', preAllocatedVUs: 50, maxVUs: 200 },
  // Laju permintaan naik terus sampai server tidak sanggup (open model: tidak menunggu server).
  stress: {
    executor: 'ramping-arrival-rate', startRate: 5, timeUnit: '1s', preAllocatedVUs: 50, maxVUs: 600,
    stages: [
      { duration: '1m', target: 20 },
      { duration: '2m', target: 50 },
      { duration: '2m', target: 100 },
      { duration: '2m', target: 150 },
      { duration: '2m', target: 200 },
      { duration: '1m', target: 0 },
    ],
  },
  spike: {
    executor: 'ramping-arrival-rate', startRate: 5, timeUnit: '1s', preAllocatedVUs: 50, maxVUs: 600,
    stages: [
      { duration: '30s', target: 10 },
      { duration: '10s', target: 200 },
      { duration: '1m', target: 200 },
      { duration: '10s', target: 10 },
      { duration: '30s', target: 10 },
    ],
  },
};
if (!PROFILES[PROFILE]) fail(`PROFILE tidak dikenal: ${PROFILE}`);

export const options = {
  scenarios: { web: Object.assign({ exec: 'web' }, PROFILES[PROFILE]) },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    'http_req_duration{name:up}': ['p(95)<500'],
    'http_req_duration{name:pos_page}': ['p(95)<1000'],
    'http_req_duration{name:admin_login}': ['p(95)<1500'],
  },
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

const TARGETS = [
  { name: 'up', path: '/up', weight: 0.3 },
  { name: 'pos_page', path: '/pos', weight: 0.5 },
  { name: 'admin_login', path: '/admin/login', weight: 0.2 },
];

export function web() {
  let r = Math.random();
  let t = TARGETS[TARGETS.length - 1];
  for (const x of TARGETS) { if (r < x.weight) { t = x; break; } r -= x.weight; }
  const res = http.get(BASE + t.path, { tags: { name: t.name }, timeout: '60s', redirects: 0 });
  check(res, { [`${t.name} 200`]: (x) => x.status === 200 });
  if (PROFILE === 'smoke') sleep(1);
}
