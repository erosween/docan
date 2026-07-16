import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  scenarios: {
    active_cashiers: {
      executor: 'ramping-vus', startVUs: 0,
      stages: [
        { duration: __ENV.RAMP_DURATION || '30s', target: Number(__ENV.VUS || 100) },
        { duration: __ENV.DURATION || '3m', target: Number(__ENV.VUS || 100) },
        { duration: __ENV.RAMP_DURATION || '30s', target: 0 },
      ],
      gracefulRampDown: '15s',
    },
  },
  thresholds: { http_req_failed: ['rate<0.01'], http_req_duration: ['p(95)<1000', 'p(99)<2000'], checks: ['rate>0.99'] },
};

const baseUrl = (__ENV.BASE_URL || 'http://host.docker.internal:8080').replace(/\/$/, '');
const loginId = __ENV.LOGIN_ID || 'SDM-001';
const password = __ENV.PASSWORD || 'password';

export function setup() {
  const response = http.get(`${baseUrl}/up`);
  check(response, { 'application is alive': (r) => r.status === 200 });

  // PWA mempertahankan session setelah login. Login sekali di setup membuat
  // load test mengukur jalur kasir, bukan berulang kali menguji throttle login.
  const loginPage = http.get(`${baseUrl}/login`);
  const token = loginPage.html().find('input[name="_token"]').attr('value');
  check(loginPage, { 'login page loaded': (r) => r.status === 200, 'csrf token found': () => Boolean(token) });
  const login = http.post(`${baseUrl}/login`, { _token: token, login_id: loginId, password }, { redirects: 0 });
  check(login, { 'login accepted': (r) => r.status === 302 });

  return { cookies: http.cookieJar().cookiesForURL(baseUrl) };
}

export default function (data) {
  const jar = http.cookieJar();
  for (const [name, values] of Object.entries(data.cookies || {})) {
    if (values.length) jar.set(baseUrl, name, values[0]);
  }
  const cashier = http.get(`${baseUrl}/`);
  check(cashier, {
    'cashier loaded': (r) => r.status === 200,
    'cashier session authenticated': (r) => r.url === `${baseUrl}/` && r.body.includes('Pilih provider'),
  });
  sleep(Math.random() * 2 + 1);
}
