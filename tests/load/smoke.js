import http from 'k6/http'
import { check, sleep } from 'k6'

/**
 * Smoke-scale load test (see docs/testing/load-testing.md). Playwright
 * is never used for load generation — this is the sole load-testing
 * tool, per the deterministic-tooling requirement. Targets the LOCAL
 * DEV backend only; the higher tiers (50/100/300/500/1000 concurrent
 * users) require an isolated, approved-capacity environment and
 * explicit stakeholder sign-off that this sandbox does not have — see
 * docs/testing/load-testing.md for what was and was not executed.
 *
 * Logs in once in setup() (not per-iteration) — real traffic logs in
 * once per session, not on every request, and the login endpoint is
 * deliberately rate-limited (see LOGIN_RATE_LIMIT_PER_MINUTE) as a
 * brute-force protection that a load test must not need to bypass.
 */
const BASE_URL = __ENV.LOAD_TEST_BASE_URL || 'http://localhost:8000'
const USERNAME = __ENV.LOAD_TEST_USERNAME || 'superadmin'

export const options = {
  vus: 10,
  duration: '30s',
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<800', 'p(99)<1500'],
  },
}

export function setup() {
  const loginRes = http.post(
    `${BASE_URL}/api/v1/auth/quick-login`,
    JSON.stringify({ username: USERNAME }),
    { headers: { 'Content-Type': 'application/json' } },
  )
  if (loginRes.status !== 200) {
    throw new Error(`setup: quick-login failed with status ${loginRes.status}: ${loginRes.body}`)
  }
  return { token: JSON.parse(loginRes.body).data.token }
}

export default function (data) {
  const health = http.get(`${BASE_URL}/up`)
  check(health, { 'health check is 200': (r) => r.status === 200 })

  const branding = http.get(`${BASE_URL}/api/v1/branding`)
  check(branding, { 'public branding endpoint is 200': (r) => r.status === 200 })

  const authHeaders = { headers: { Authorization: `Bearer ${data.token}` } }

  const programs = http.get(`${BASE_URL}/api/v1/programs`, authHeaders)
  check(programs, { 'programs list is 200': (r) => r.status === 200 })

  const settings = http.get(`${BASE_URL}/api/v1/admin/settings`, authHeaders)
  check(settings, { 'admin settings is 200': (r) => r.status === 200 })

  sleep(1)
}
