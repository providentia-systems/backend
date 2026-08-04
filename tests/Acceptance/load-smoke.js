import http from 'k6/http';
import { check, sleep } from 'k6';

const baseUrl = (__ENV.BASE_URL || '').replace(/\/$/, '');
if (!/^https?:\/\/[^/?#]+$/.test(baseUrl) || baseUrl.includes('@')) {
  throw new Error('BASE_URL must be an origin without credentials, path, query, or fragment.');
}

export const options = {
  vus: Number.parseInt(__ENV.VUS || '10', 10),
  duration: __ENV.DURATION || '30s',
  thresholds: {
    checks: ['rate>0.99'],
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<500', 'p(99)<1000'],
  },
};

const endpoints = ['/health/live', '/health/ready', '/api/v1/system/info', '/'];

export default function () {
  const endpoint = endpoints[__ITER % endpoints.length];
  const response = http.get(`${baseUrl}${endpoint}`, {
    headers: { Accept: endpoint === '/' ? 'text/html' : 'application/json' },
    tags: { endpoint },
    timeout: '10s',
  });

  check(response, {
    'response is successful': (result) => result.status === 200,
    'server identity is suppressed': (result) => !result.headers.Server,
  });
  sleep(0.2);
}
