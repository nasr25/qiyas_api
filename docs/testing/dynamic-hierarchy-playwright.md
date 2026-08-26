# Playwright — Browser Test Evidence

**Last verified: 2026-08-26.** Every figure below was read from actual test
output, not estimated.

| Project | Result |
|---|---|
| **Chromium** — full suite | **231 / 231 passed** · 0 failed · 0 skipped |
| **Firefox** — smoke suite | **4 / 4 passed** |
| **WebKit** | **Environment Blocked / Not Tested** — see below |

Reproduced across two consecutive clean runs after the timeout correction
described under *Reliability* below.

## WebKit — Environment Blocked / Not Tested

WebKit is **not** classified as passed, failed, or unsupported. It could not
be executed:

```
browserType.launch:
Host system is missing dependencies to run browsers.
    sudo apt-get install libmanette-0.2-0
```

- The WebKit **binary downloaded successfully** (`webkit-2311`).
- The missing item is an **OS library**: `ldconfig -p | grep manette` → 0 results.
- Installing it requires root; `sudo -n true` returns *"interactive
  authentication is required"* — no passwordless sudo in this environment.

This is an environment limitation, not a code or configuration defect. On a
host with root: `sudo npx playwright install-deps webkit`, then
`npx playwright test --project=webkit`.

## Isolated environment

Browser tests never touch the development database.

| | Dev | E2E |
|---|---|---|
| Database | `qiyas_db` | `qiyas_e2e_db` |
| API | `:8001` | `:8002` (`--env=e2e`) |
| Frontend | `:5180` | `:5181` |

```bash
php artisan migrate:fresh --seed --force --env=e2e
php artisan compliance:seed-test-fixtures --force --env=e2e
php artisan db:seed --class=DocumentationFixtureSeeder --force --env=e2e

APP_ENV=e2e php artisan serve --port=8002 --env=e2e
VITE_API_URL=http://localhost:8002/api/v1 npm run dev -- --port 5181

E2E_BASE_URL=http://localhost:5181 \
E2E_API_URL=http://localhost:8002 \
E2E_DB_NAME_HINT=qiyas_e2e_db \
npx playwright test --project=chromium
```

`.env.e2e` raises `LOGIN_RATE_LIMIT_PER_MINUTE` to 2000 — the suites sign in
far more often than a human would, and the production default of 10/min
throttled them. E2E-environment only; the dev `.env` is untouched.

## Suite breakdown (Chromium, 231 passed)

| Suite | Tests | Covers |
|---|---|---|
| `lifecycle` | 56 | Full journey, extensions, rejection/resubmission — **7 programs** |
| `dynamic-hierarchy` | 42 | Structure depth/semantics, permissions, active-cycle protection, security scope, smoke |
| `documentation` | 41 | Arabic illustrated-guide screenshot suite |
| `admin` | 28 | Branding, SMTP, email templates |
| `dynamic-reports` | 21 | Dimensions, grouping, cascading filters, XLSX contract |
| `cross-program` | 20 | Program isolation, multi-role, quad-role |
| `dynamic-dashboard` | 9 | Universal metrics, drill-down, breadcrumbs |
| `dynamic-xlsx` | 7 | Template depth, import/export, structure-version rejection |
| `permissions` | 5 | Department and role isolation |
| `qiyas` | 1 | Cross-browser smoke |
| `offline` | 1 | Offline asset operation |

## Program lifecycle coverage

`tests/e2e/lifecycle/full-journey.spec.ts` — one parameterised spec, seven
programs, no per-program branch.

| Program | Depth | Journey | Extensions | Rejection → correction → resubmission | Non-assignable guard |
|---|---|---|---|---|---|
| Qiyas | 5 | ✅ | ✅ | ✅ | ✅ |
| Sumoud | 3 | ✅ | ✅ | ✅ | ✅ |
| ECC | 5 | ✅ | ✅ | ✅ | ✅ |
| NDMO | 6 | ✅ | ✅ | ✅ | ✅ |
| TEST3 | 3 | ✅ | ✅ | ✅ | ✅ |
| TEST5 | 5 | ✅ | ✅ | ✅ | ✅ |
| TEST7 | 7 | ✅ | ✅ | ✅ | ✅ |

**Journey** = Program Manager assigns an assignable node → Employee sees it
with its full hierarchy path → uploads evidence → submits → Department
Manager approves → Auditor approves → Program Manager final approval →
`approved`, with the dashboard reflecting the approval.

**Extensions** = Employee requests → a Department Manager is refused (the
configured reviewer is the Auditor) → due date unchanged while pending →
Auditor approves and the date moves; separately, rejection requires a reason
and leaves the date unchanged.

**Rejection** = reason mandatory (422 without one) → returns to Employee →
resubmission restarts at **Department Manager**, never at the stage that
rejected it.

## Active-cycle structural protection

`tests/e2e/dynamic-hierarchy/active-cycle-protection.spec.ts`, 6 tests
against **TESTX** — a fixture reserved for mutation so these tests cannot
poison the shared depth fixtures.

| Attempt against a populated, active cycle | Result |
|---|---|
| Remove a populated level | `not_allowed`; activation refused **even with acknowledgement** |
| Reorder a populated hierarchy | `not_allowed`; refused |
| Insert a level mid-cycle | `requires_migration`; refused without explicit acknowledgement |
| Disable the assignable/assessable/evidence level | Validation errors; activation refused |
| Rename labels, change visibility | **Permitted** — non-blocking, no structural change |

Every case asserts the **backend** refuses via a direct API call, not that a
button is hidden.

## Isolation

| Check | Result |
|---|---|
| Program Manager → another program (structure, dashboard, reports, hierarchy) | **404** — existence not disclosed |
| Member who is not Program Manager → structure write | **403** |
| Program Manager elsewhere, employee here | **403** here, **201** on their own |
| Employee filtering to the root node | No widening |
| Crafted query parameters (duplicate `department_id`, `node_id=0`, `node_id=999999`) | No widening |
| Department Manager | Strictly fewer rows than the Program Manager |
| Auditor | Cross-department, **404** on another program |
| Executive Viewer | Reads; `can_manage=false`; **403** on writes |

## Reliability

The default `timeout: 30_000` was too tight for specs that legitimately
perform many sequential navigations (the documentation screenshot suite) or
file uploads (branding). Under full-suite load on a machine also running two
API servers and two Vite instances, those tests intermittently exceeded it —
they passed in isolation and failed only in the full run, and always as a
**timeout**, never an assertion failure.

The config timeout was raised to `60_000`. **No expectation was relaxed and
no test was skipped or disabled**; a flaky threshold was corrected. Two
consecutive clean full runs then reported 231/231.

## Fresh-fixture requirement

The documentation suite is **stateful by design**: its tests approve, reject
and mark-as-read, consuming what they act on. It is written to run against a
freshly seeded database. Re-running without reseeding can exhaust a review
queue — a property of a screenshot suite acting on real state, not a product
defect.

## Frontend unit tests

**This repository has no frontend unit-test suite.** None existed before this
work and none was added. Frontend verification is via Playwright and the
production build (`npm run build`). **No frontend unit-test coverage is
claimed.**
